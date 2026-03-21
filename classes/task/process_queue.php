<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Scheduled task: drain the local_lid analysis queue.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that drives the full LID analysis pipeline.
 *
 * Each execution performs three phases in order:
 *
 * PHASE 1 — Close detection (cron-driven triggers)
 *   Scans LID-enabled forums for two automatic close conditions that do not
 *   produce Moodle events:
 *     a) Cut-off date has passed (forum.cutoffdate > 0 AND <= now).
 *     b) All discussions in the forum have been locked by the inactivity
 *        timer (forum.lockdiscussionafter > 0 and last post in every
 *        discussion is older than the lock threshold).
 *   For each newly-closed forum detected, calls observer::queue_forum_analysis()
 *   to create analysis rows and queue them. A forum is only queued once —
 *   detection is skipped if any student_forum row for the forum already has
 *   status 'pending', 'processing', or 'complete'.
 *
 * PHASE 2 — Queue drain
 *   Claims a batch of queue items (student_forum and thread scope) and
 *   processes each via the LLM. Success is determined by whether analysis_json
 *   was written — aggregation errors are caught separately and do not cause
 *   a success to be reported as failure.
 *
 * PHASE 3 — Aggregation + notification
 *   After the batch completes, triggers forum and course aggregate recomputation
 *   for any forum that had at least one successful analysis this run.
 *   Sends completion notifications to instructors for those forums if the
 *   forum's full analysis set is now complete (no remaining pending rows).
 *
 * Concurrency safety:
 *   Queue items are claimed by setting timeclaimed = NOW() in a single UPDATE
 *   with a WHERE timeclaimed IS NULL guard. Two overlapping cron runs cannot
 *   process the same item.
 *
 * Success vs failure reporting:
 *   An item is counted as SUCCEEDED if analysis_json is non-null after
 *   processing, regardless of whether the downstream aggregation step threw
 *   an exception. Aggregation errors are logged separately. This prevents
 *   the previously observed "Succeeded: 0, Failed: N" misreporting when
 *   JSON was written successfully but aggregation threw.
 */
class process_queue extends \core\task\scheduled_task {

    /** Maximum LLM call attempts per queue item before marking as error. */
    const MAX_ATTEMPTS = 3;

    /**
     * Return the human-readable task name shown in the scheduled tasks UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_process_queue', 'local_lid');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $now = time();

        // ----------------------------------------------------------------
        // PHASE 1 — Cron-driven close detection.
        // ----------------------------------------------------------------
        $this->detect_closed_forums($now);

        // ----------------------------------------------------------------
        // PHASE 2 — Claim and process a batch of queue items.
        // ----------------------------------------------------------------
        $batchsize = max(1, (int)(get_config('local_lid', 'cron_batchsize') ?: 10));

        $candidates = $DB->get_records_sql(
            "SELECT q.*
               FROM {local_lid_queue} q
               JOIN {local_lid_analysis} a ON a.id = q.analysisid
              WHERE q.timeclaimed IS NULL
                AND q.timevisible <= :now
                AND q.attempts < :maxattempts
                AND a.scope IN ('student_forum', 'thread')
           ORDER BY q.priority ASC, q.timevisible ASC",
            [
                'now'         => $now,
                'maxattempts' => self::MAX_ATTEMPTS,
            ],
            0,
            $batchsize
        );

        if (empty($candidates)) {
            mtrace('local_lid process_queue: no items to process.');
            return;
        }

        // Claim all candidates atomically.
        $ids    = array_keys($candidates);
        $idlist = implode(',', array_map('intval', $ids));

        $DB->execute(
            "UPDATE {local_lid_queue}
                SET timeclaimed = :claimtime
              WHERE id IN ({$idlist})
                AND timeclaimed IS NULL",
            ['claimtime' => $now]
        );

        // Re-fetch to confirm which were actually claimed by this run.
        $claimed = $DB->get_records_select(
            'local_lid_queue',
            "id IN ({$idlist}) AND timeclaimed = :claimed",
            ['claimed' => $now]
        );

        if (empty($claimed)) {
            mtrace('local_lid process_queue: all candidates claimed by another process.');
            return;
        }

        mtrace('local_lid process_queue: claimed ' . count($claimed) . ' item(s). Processing...');

        // Validate LLM config once before processing the batch.
        try {
            $analyser = new \local_lid\analysis\session_analyser();
        } catch (\local_lid\exception\llm_config_exception $e) {
            $this->release_claimed($ids, $now);
            mtrace('local_lid process_queue: LLM not configured — aborting. ' . $e->getMessage());
            return;
        }

        $succeeded      = 0;
        $failed         = 0;
        $forums_updated = []; // forumid → courseid, for Phase 3.

        foreach ($claimed as $item) {
            $analysisrow = $DB->get_record('local_lid_analysis', ['id' => $item->analysisid]);
            if (!$analysisrow) {
                mtrace("local_lid process_queue: analysis row {$item->analysisid} not found — skipping.");
                $this->delete_queue_item($item->id);
                continue;
            }

            // Increment attempt counter on the queue item.
            $DB->update_record('local_lid_queue', (object) [
                'id'       => $item->id,
                'attempts' => $item->attempts + 1,
            ]);

            // Mark analysis row as processing.
            $DB->update_record('local_lid_analysis', (object) [
                'id'           => $analysisrow->id,
                'status'       => 'processing',
                'timemodified' => time(),
            ]);

            $json_written = false;

            try {
                $json_written = $this->process_analysis_row($analyser, $analysisrow);
            } catch (\local_lid\exception\llm_config_exception $e) {
                // Config broken mid-run — release remaining and abort.
                $remaining = array_filter($claimed, fn($c) => $c->id !== $item->id);
                $this->release_claimed(array_keys($remaining), $now);
                mtrace('local_lid process_queue: LLM config error mid-run — stopping. ' . $e->getMessage());
                $this->mark_analysis_error($analysisrow->id, $e->getMessage());
                $failed++;
                break;
            } catch (\Throwable $e) {
                mtrace("local_lid process_queue: error on queue item {$item->id}: " . $e->getMessage());
                $this->mark_analysis_error($analysisrow->id, $e->getMessage());
                $failed++;

                if ($item->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $this->delete_queue_item($item->id);
                }
                continue;
            }

            if ($json_written) {
                // SUCCESS — determined by JSON write, not by whether aggregation succeeded.
                $succeeded++;
                $this->delete_queue_item($item->id);
                $forums_updated[$analysisrow->forumid] = $analysisrow->courseid;

                // Run aggregation in its own try/catch so aggregation errors
                // do not cause a successfully-written analysis to be counted as failed.
                try {
                    $this->trigger_aggregation($analysisrow->forumid, $analysisrow->courseid);
                } catch (\Throwable $e) {
                    mtrace(
                        "local_lid process_queue: aggregation error for forum {$analysisrow->forumid}: " .
                        $e->getMessage() . ' (analysis JSON was written successfully)'
                    );
                }
            } else {
                $failed++;
                $this->mark_analysis_error($analysisrow->id, 'LLM returned empty or invalid JSON.');

                if ($item->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $this->delete_queue_item($item->id);
                }
            }
        }

        mtrace("local_lid process_queue: run complete. " .
            "Succeeded: {$succeeded}, Failed: {$failed}, " .
            "Total claimed: " . count($claimed) . ".");

        // ----------------------------------------------------------------
        // PHASE 3 — Completion notifications.
        // ----------------------------------------------------------------
        foreach ($forums_updated as $forumid => $courseid) {
            $this->maybe_notify_completion($forumid, $courseid);
        }
    }

    // -------------------------------------------------------------------------
    // Phase 1 — Close detection
    // -------------------------------------------------------------------------

    /**
     * Scan LID-enabled forums for cron-detectable close conditions.
     *
     * Two conditions trigger queuing:
     *   a) forum.cutoffdate > 0 AND forum.cutoffdate <= $now
     *   b) forum.lockdiscussionafter > 0 AND all discussions' last post
     *      is older than (now - lockdiscussionafter seconds)
     *
     * A forum is skipped if it already has student_forum analysis rows with
     * status 'pending', 'processing', or 'complete' — it has already been
     * detected and queued (or completed) in a previous run.
     *
     * @param int $now Current Unix timestamp.
     */
    private function detect_closed_forums(int $now): void {
        global $DB;

        // Fetch all LID-enabled forums that have not been force-disabled.
        if ((bool) get_config('local_lid', 'lid_force_disabled')) {
            return;
        }

        // Build list of enabled forum ids.
        $enabledforums = $this->get_enabled_forum_ids();
        if (empty($enabledforums)) {
            return;
        }

        $idlist = implode(',', array_map('intval', $enabledforums));

        $forums = $DB->get_records_sql(
            "SELECT f.id, f.course, f.cutoffdate, f.lockdiscussionafter
               FROM {forum} f
              WHERE f.id IN ({$idlist})"
        );

        foreach ($forums as $forum) {
            // Skip forums that are already queued or completed.
            if ($this->forum_already_queued_or_complete((int) $forum->id)) {
                continue;
            }

            $should_queue = false;

            // Condition a — cut-off date has passed.
            if (!empty($forum->cutoffdate) && (int) $forum->cutoffdate <= $now) {
                $should_queue = true;
                mtrace("local_lid process_queue: forum {$forum->id} cut-off date passed — queuing analysis.");
            }

            // Condition b — inactivity lock threshold crossed for all discussions.
            if (!$should_queue && !empty($forum->lockdiscussionafter)) {
                $threshold = $now - (int) $forum->lockdiscussionafter;
                if ($this->all_discussions_past_inactivity_threshold((int) $forum->id, $threshold)) {
                    $should_queue = true;
                    mtrace("local_lid process_queue: forum {$forum->id} inactivity lock threshold crossed — queuing analysis.");
                }
            }

            if ($should_queue) {
                $queued = \local_lid\observer::queue_forum_analysis(
                    (int) $forum->course,
                    (int) $forum->id,
                    \local_lid\observer::PRIORITY_CRON
                );
                mtrace("local_lid process_queue: queued {$queued} analysis row(s) for forum {$forum->id}.");
            }
        }
    }

    /**
     * Return true if the forum already has analysis rows that are pending,
     * processing, or complete — meaning close detection has already fired
     * for this forum and we should not re-queue.
     *
     * @param  int $forumid
     * @return bool
     */
    private function forum_already_queued_or_complete(int $forumid): bool {
        global $DB;

        return $DB->record_exists_select(
            'local_lid_analysis',
            "forumid = :forumid
             AND scope = 'student_forum'
             AND status IN ('pending', 'processing', 'complete')",
            ['forumid' => $forumid]
        );
    }

    /**
     * Return true if all discussions in the forum have had no activity
     * since before the given threshold timestamp.
     *
     * A discussion is considered inactive if its most recent post's
     * timecreated is <= $threshold.
     * Returns false for forums with no discussions.
     *
     * @param  int $forumid
     * @param  int $threshold Unix timestamp — posts must be older than this.
     * @return bool
     */
    private function all_discussions_past_inactivity_threshold(
        int $forumid,
        int $threshold
    ): bool {
        global $DB;

        $total = $DB->count_records('forum_discussions', ['forum' => $forumid]);
        if ($total === 0) {
            return false;
        }

        // Count discussions where the most recent post is still within the
        // activity window (i.e. NOT yet past the threshold).
        $still_active = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {forum_discussions} fd
              WHERE fd.forum = :forumid
                AND (
                    SELECT MAX(fp.timecreated)
                      FROM {forum_posts} fp
                     WHERE fp.discussion = fd.id
                ) > :threshold",
            ['forumid' => $forumid, 'threshold' => $threshold]
        );

        return (int) $still_active === 0;
    }

    /**
     * Return an array of forum ids that have LID enabled.
     *
     * Respects the three-tier enable hierarchy:
     *   1. Site force-disable (checked by caller before this is called)
     *   2. Per-forum config rows
     *   3. Site default (lid_default_enabled)
     *
     * @return int[]
     */
    private function get_enabled_forum_ids(): array {
        global $DB;

        $sitedefault = (bool) get_config('local_lid', 'lid_default_enabled');

        // Forums with explicit config rows.
        $configrows = $DB->get_records('local_lid_forum_config', null, '', 'forumid, enabled');

        $explicit_enabled  = [];
        $explicit_disabled = [];
        foreach ($configrows as $row) {
            if ((bool) $row->enabled) {
                $explicit_enabled[] = (int) $row->forumid;
            } else {
                $explicit_disabled[] = (int) $row->forumid;
            }
        }

        if ($sitedefault) {
            // Default on — all forums are enabled except those explicitly disabled.
            $allforums = $DB->get_records('forum', null, '', 'id');
            $allids    = array_map(fn($r) => (int) $r->id, $allforums);
            return array_values(array_diff($allids, $explicit_disabled));
        } else {
            // Default off — only explicitly enabled forums.
            return $explicit_enabled;
        }
    }

    // -------------------------------------------------------------------------
    // Phase 2 — Item processing
    // -------------------------------------------------------------------------

    /**
     * Process a single analysis row by building the prompt and calling the LLM.
     *
     * Handles student_forum and thread scope. Validates the returned JSON
     * and writes it to local_lid_analysis on success.
     *
     * @param  \local_lid\analysis\session_analyser $analyser
     * @param  \stdClass                            $analysisrow
     * @return bool True if analysis_json was successfully written.
     */
    private function process_analysis_row(
        \local_lid\analysis\session_analyser $analyser,
        \stdClass $analysisrow
    ): bool {
        global $DB;

        $builder = new \local_lid\llm\prompt_builder(
            (int) $analysisrow->courseid,
            (int) $analysisrow->forumid
        );

        // Build the prompt based on scope.
        if ($analysisrow->scope === 'student_forum') {
            $forumname = $DB->get_field('forum', 'name', ['id' => $analysisrow->forumid]) ?? '';
            $prompt    = $builder->build_for_student_forum((int) $analysisrow->userid, $forumname);
            $prompHash = $builder->get_forum_analyzer_hash();
        } else if ($analysisrow->scope === 'thread') {
            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => $analysisrow->discussionid],
                'id, name'
            );
            if (!$discussion) {
                mtrace("local_lid process_queue: discussion {$analysisrow->discussionid} not found — skipping.");
                return false;
            }
            $prompt    = $builder->build_for_thread_by_id(
                (int) $analysisrow->discussionid,
                $discussion->name
            );
            $prompHash = $builder->get_forum_analyzer_hash();
        } else {
            mtrace("local_lid process_queue: unsupported scope '{$analysisrow->scope}' — skipping.");
            return false;
        }

        if (empty($prompt)) {
            mtrace("local_lid process_queue: empty prompt for analysis row {$analysisrow->id} — no posts found.");
            return false;
        }

        // Call the LLM.
        $rawjson = $analyser->call_llm($prompt);

        if (empty($rawjson)) {
            return false;
        }

        // Validate the returned JSON.
        $data = $analyser->validate_json($rawjson);
        if ($data === null) {
            return false;
        }

        $schemaversion = $data['schema_version'] ?? '1.2';
        $llmmodel      = $analyser->get_last_model_used();

        // Write to the analysis row.
        $DB->update_record('local_lid_analysis', (object) [
            'id'            => $analysisrow->id,
            'analysis_json' => $rawjson,
            'schema_version' => $schemaversion,
            'status'        => 'complete',
            'error_message' => null,
            'llm_model'     => $llmmodel,
            'prompt_hash'   => $prompHash,
            'timemodified'  => time(),
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Phase 3 — Aggregation + notification
    // -------------------------------------------------------------------------

    /**
     * Trigger forum and course aggregate recomputation for a forum.
     *
     * Called after each successful analysis. Wrapped in a try/catch by the
     * caller so that aggregation errors do not affect success reporting.
     *
     * @param int $forumid
     * @param int $courseid
     */
    private function trigger_aggregation(int $forumid, int $courseid): void {
        $aggregator = new \local_lid\analysis\aggregator();
        $aggregator->recompute_forum_aggregate($forumid);
        $aggregator->recompute_course_aggregate($courseid);
    }

    /**
     * Send a completion notification to instructors if the forum's full
     * analysis set is now complete (no remaining pending/stale/processing rows).
     *
     * Uses Moodle's core messaging API (message_send()) which routes to email
     * or inbox notification based on each user's notification preferences.
     *
     * @param int $forumid
     * @param int $courseid
     */
    private function maybe_notify_completion(int $forumid, int $courseid): void {
        global $DB;

        // Check for any remaining incomplete rows for this forum.
        $pending = $DB->count_records_select(
            'local_lid_analysis',
            "forumid = :forumid
             AND scope = 'student_forum'
             AND status IN ('pending', 'stale', 'processing')",
            ['forumid' => $forumid]
        );

        if ($pending > 0) {
            return; // Not yet fully complete.
        }

        $complete = $DB->count_records('local_lid_analysis', [
            'forumid' => $forumid,
            'scope'   => 'student_forum',
            'status'  => 'complete',
        ]);

        if ($complete === 0) {
            return; // Nothing completed — nothing to notify about.
        }

        // Find instructors with view capability for this forum.
        $context     = \context_course::instance($courseid);
        $instructors = get_users_by_capability(
            $context,
            'local/lid:viewforumdashboard',
            'u.id, u.firstname, u.lastname, u.email, u.mailformat'
        );

        if (empty($instructors)) {
            return;
        }

        $forum    = $DB->get_record('forum', ['id' => $forumid], 'id, name, course');
        $course   = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
        $dashurl  = new \moodle_url('/local/lid/forum_lid.php', [
            'forumid'  => $forumid,
            'courseid' => $courseid,
        ]);

        foreach ($instructors as $instructor) {
            $message                     = new \core\message\message();
            $message->component          = 'local_lid';
            $message->name               = 'analysis_complete';
            $message->userfrom           = \core_user::get_noreply_user();
            $message->userto             = $instructor;
            $message->subject            = get_string(
                'notification_complete_subject',
                'local_lid',
                ['forum' => $forum->name, 'course' => $course->shortname]
            );
            $message->fullmessage        = get_string(
                'notification_complete_body',
                'local_lid',
                [
                    'forum'    => $forum->name,
                    'course'   => $course->fullname,
                    'count'    => $complete,
                    'url'      => $dashurl->out(false),
                ]
            );
            $message->fullmessageformat  = FORMAT_PLAIN;
            $message->fullmessagehtml    = get_string(
                'notification_complete_body_html',
                'local_lid',
                [
                    'forum'    => format_string($forum->name),
                    'course'   => format_string($course->fullname),
                    'count'    => $complete,
                    'url'      => $dashurl->out(false),
                ]
            );
            $message->smallmessage       = get_string(
                'notification_complete_small',
                'local_lid',
                ['forum' => $forum->name, 'count' => $complete]
            );
            $message->notification       = 1;
            $message->contexturl         = $dashurl->out(false);
            $message->contexturlname     = get_string('notification_complete_urlname', 'local_lid');

            try {
                message_send($message);
            } catch (\Throwable $e) {
                mtrace(
                    "local_lid process_queue: notification failed for user {$instructor->id}: " .
                    $e->getMessage()
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Mark an analysis row as error with a message.
     *
     * @param int    $analysisid
     * @param string $message
     */
    private function mark_analysis_error(int $analysisid, string $message): void {
        global $DB;

        $DB->update_record('local_lid_analysis', (object) [
            'id'            => $analysisid,
            'status'        => 'error',
            'error_message' => substr($message, 0, 1024),
            'timemodified'  => time(),
        ]);
    }

    /**
     * Delete a queue item after successful processing or max attempts reached.
     *
     * @param int $queueid
     */
    private function delete_queue_item(int $queueid): void {
        global $DB;
        $DB->delete_records('local_lid_queue', ['id' => $queueid]);
    }

    /**
     * Release claimed queue items back to the pool by clearing timeclaimed.
     *
     * Used when the task aborts early to avoid stranding items permanently.
     *
     * @param int[] $ids         Queue item IDs to release.
     * @param int   $claimedtime The timeclaimed value used when claiming.
     */
    private function release_claimed(array $ids, int $claimedtime): void {
        global $DB;

        if (empty($ids)) {
            return;
        }

        $idlist = implode(',', array_map('intval', $ids));
        $DB->execute(
            "UPDATE {local_lid_queue}
                SET timeclaimed = NULL
              WHERE id IN ({$idlist})
                AND timeclaimed = :claimed",
            ['claimed' => $claimedtime]
        );
    }
}
