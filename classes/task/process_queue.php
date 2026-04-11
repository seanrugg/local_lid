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
 * PHASE 0 - Orphaned claim recovery
 *   Before doing any work, releases queue items whose timeclaimed value is
 *   older than CLAIM_STALE_SECONDS. This self-heals runs that were
 *   interrupted by LLM timeouts, 503 errors, or PHP process termination.
 *   The release_claimed() helper only releases rows from the *current* run,
 *   so without this step, rows claimed by a crashed prior run are permanently
 *   stuck with timeclaimed IS NOT NULL and never re-enter the candidate pool.
 *
 *   IMPORTANT: timeclaimed is refreshed per-item inside the worker loop (see
 *   Phase 2). The staleness threshold therefore measures "how long has this
 *   single item been actively held by a worker" and not "how long ago was the
 *   batch first claimed". This prevents long-running batches from having their
 *   own in-flight items re-claimed by an overlapping cron run.
 *
 * PHASE 1 - Close detection (cron-driven triggers)
 *   Scans LID-enabled forums for two automatic close conditions that do not
 *   produce Moodle events:
 *     a) Cut-off date has passed (forum.cutoffdate > 0 AND <= now).
 *     b) All discussions in the forum have been locked by the inactivity
 *        timer (forum.lockdiscussionafter > 0 and last post in every
 *        discussion is older than the lock threshold).
 *   For each newly-closed forum detected, calls observer::queue_forum_analysis()
 *   to create analysis rows and queue them. A forum is only queued once -
 *   detection is skipped if any student_forum row for the forum already has
 *   status 'pending', 'processing', or 'complete'.
 *
 * PHASE 2 - Queue drain
 *   Claims a batch of queue items (student_forum and thread scope) and
 *   processes each via the LLM. Success is determined by whether analysis_json
 *   was written - aggregation errors are caught separately and do not cause
 *   a success to be reported as failure.
 *
 *   Each item's timeclaimed is refreshed to time() immediately before its
 *   LLM call begins. This ensures the Phase 0 staleness window applies
 *   per-item rather than per-batch, so processing 15 items at 60 seconds
 *   each does not cause the first items in the batch to appear orphaned
 *   relative to the last items.
 *
 *   Each LLM prompt includes a structured assessment metadata block containing
 *   userid, forumid, analysisid, scope, and a UTC timestamp. This metadata
 *   is embedded in the prompt payload and appears in external API provider
 *   logs (e.g. Google AI Studio), enabling full audit correlation between
 *   Moodle records and external call logs without relying on token fingerprinting.
 *
 * PHASE 3 - Aggregation + notification
 *   After the batch completes, triggers forum and course aggregate recomputation
 *   for any forum that had at least one successful analysis this run.
 *   Sends completion notifications to instructors for those forums if the
 *   forum's full analysis set is now complete (no remaining pending rows).
 *   Notification failures (including invalid email addresses) are caught and
 *   logged as warnings - they never cause a completed analysis job to be
 *   re-queued or counted as failed.
 *
 * Audit trail:
 *   A row is written to local_lid_call_log for every processed queue item
 *   immediately after the LLM call completes and before the queue item is
 *   deleted. The timeclaimed value recorded is the per-item worker start
 *   timestamp (not the original batch claim time), which gives a tighter
 *   correlation window against external API provider logs.
 *
 * Concurrency safety:
 *   Queue items are claimed by setting timeclaimed = NOW() in a single UPDATE
 *   with a WHERE timeclaimed IS NULL guard. Two overlapping cron runs cannot
 *   process the same item at initial claim time. The per-item timeclaimed
 *   refresh in the worker loop additionally prevents an overlapping run's
 *   Phase 0 from re-releasing items that are still being actively processed.
 *
 *   A future enhancement (Bug 1 backlog Option C) will wrap execute() in a
 *   Moodle cron lock to prevent overlap entirely.
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
     * Number of seconds after which a claimed-but-unfinished queue item is
     * considered orphaned and released back to the candidate pool.
     *
     * Because timeclaimed is refreshed per-item at the start of each item's
     * processing (see Phase 2 worker loop), this threshold measures the
     * time a single item has been actively held by a worker, not the total
     * batch duration. 600 seconds (10 minutes) is well above the maximum
     * expected single LLM call duration (~60 seconds for a full
     * student_forum prompt) but short enough to recover within a few cron
     * cycles after a crash or 503.
     */
    const CLAIM_STALE_SECONDS = 600;

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
        // PHASE 0 - Orphaned claim recovery.
        //
        // release_claimed() only clears rows claimed at the exact timestamp
        // of the current run. Rows orphaned by a previous crashed or
        // timed-out run (e.g. a 503 from the LLM provider) are never
        // released by that helper and become permanently stuck.
        //
        // This step runs unconditionally at the start of every execution
        // to self-heal those orphaned rows before the candidate query runs.
        //
        // The per-item timeclaimed refresh in the worker loop ensures this
        // query will not release items that are still being actively
        // processed by a long-running concurrent batch.
        // ----------------------------------------------------------------
        $stale_threshold = $now - self::CLAIM_STALE_SECONDS;
        $stale_released  = $DB->execute(
            "UPDATE {local_lid_queue}
                SET timeclaimed = NULL
              WHERE timeclaimed IS NOT NULL
                AND timeclaimed < :stale",
            ['stale' => $stale_threshold]
        );
        if ($stale_released) {
            mtrace('local_lid process_queue: released orphaned claimed items older than ' .
                self::CLAIM_STALE_SECONDS . ' seconds.');
        }

        // ----------------------------------------------------------------
        // PHASE 1 - Cron-driven close detection.
        // ----------------------------------------------------------------
        $this->detect_closed_forums($now);

        // ----------------------------------------------------------------
        // PHASE 2 - Claim and process a batch of queue items.
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
            mtrace('local_lid process_queue: LLM not configured - aborting. ' . $e->getMessage());
            return;
        }

        $succeeded      = 0;
        $failed         = 0;
        $forums_updated = []; // forumid => courseid, for Phase 3.

        foreach ($claimed as $item) {
            $analysisrow = $DB->get_record('local_lid_analysis', ['id' => $item->analysisid]);
            if (!$analysisrow) {
                mtrace("local_lid process_queue: analysis row {$item->analysisid} not found - skipping.");
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

            // ------------------------------------------------------------
            // Refresh the claim timestamp to "now" for THIS item.
            //
            // CLAIM_STALE_SECONDS measures how long a single item has been
            // actively held by a worker, not how long ago the batch was
            // claimed. Without this refresh, items processed late in a long
            // batch can exceed the staleness threshold while still being
            // legitimately worked, causing the next cron run's Phase 0
            // orphan release to re-queue them and produce duplicate API
            // calls. See Bug 1 in handoff 2026-04-11.
            //
            // The audit log row written below uses $itemclaimtime (not the
            // original batch claim time) so the call log timestamp gives a
            // tighter correlation window against external API provider logs.
            // ------------------------------------------------------------
            $itemclaimtime = time();
            $DB->set_field('local_lid_queue', 'timeclaimed', $itemclaimtime, ['id' => $item->id]);

            $json_written  = false;
            $timeprocessed = null;

            try {
                [$json_written, $timeprocessed] = $this->process_analysis_row(
                    $analyser,
                    $analysisrow,
                    $itemclaimtime
                );
            } catch (\local_lid\exception\llm_config_exception $e) {
                // Config broken mid-run - release remaining and abort.
                $remaining = array_filter($claimed, fn($c) => $c->id !== $item->id);
                $this->release_claimed(array_keys($remaining), $now);
                mtrace('local_lid process_queue: LLM config error mid-run - stopping. ' . $e->getMessage());
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
                // SUCCESS - determined by JSON write, not by whether aggregation succeeded.
                $succeeded++;

                // Write audit log row before deleting the queue item.
                $this->write_call_log(
                    $analysisrow,
                    $itemclaimtime,
                    $timeprocessed ?? time(),
                    true
                );

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
                // Write audit log row for failed call before marking error.
                $this->write_call_log(
                    $analysisrow,
                    $itemclaimtime,
                    $timeprocessed ?? time(),
                    false
                );

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
        // PHASE 3 - Completion notifications.
        // ----------------------------------------------------------------
        foreach ($forums_updated as $forumid => $courseid) {
            $this->maybe_notify_completion($forumid, $courseid);
        }
    }

    // -------------------------------------------------------------------------
    // Phase 1 - Close detection
    // -------------------------------------------------------------------------

    /**
     * Scan LID-enabled forums for cron-detectable close conditions.
     *
     * @param int $now Current Unix timestamp.
     */
    private function detect_closed_forums(int $now): void {
        global $DB;

        if ((bool) get_config('local_lid', 'lid_force_disabled')) {
            return;
        }

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
            if ($this->forum_already_queued_or_complete((int) $forum->id)) {
                continue;
            }

            $should_queue = false;

            if (!empty($forum->cutoffdate) && (int) $forum->cutoffdate <= $now) {
                $should_queue = true;
                mtrace("local_lid process_queue: forum {$forum->id} cut-off date passed - queuing analysis.");
            }

            if (!$should_queue && !empty($forum->lockdiscussionafter)) {
                $threshold = $now - (int) $forum->lockdiscussionafter;
                if ($this->all_discussions_past_inactivity_threshold((int) $forum->id, $threshold)) {
                    $should_queue = true;
                    mtrace("local_lid process_queue: forum {$forum->id} inactivity lock threshold crossed - queuing analysis.");
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
     * processing, or complete.
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
     * @param  int $forumid
     * @param  int $threshold
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
     * @return int[]
     */
    private function get_enabled_forum_ids(): array {
        global $DB;

        $sitedefault = (bool) get_config('local_lid', 'lid_default_enabled');

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
            $allforums = $DB->get_records('forum', null, '', 'id');
            $allids    = array_map(fn($r) => (int) $r->id, $allforums);
            return array_values(array_diff($allids, $explicit_disabled));
        } else {
            return $explicit_enabled;
        }
    }

    // -------------------------------------------------------------------------
    // Phase 2 - Item processing
    // -------------------------------------------------------------------------

    /**
     * Process a single analysis row by building the prompt and calling the LLM.
     *
     * Handles student_forum and thread scope. Injects a structured assessment
     * metadata block into the prompt before sending, embedding userid, forumid,
     * analysisid, scope, and a UTC timestamp. This metadata appears in external
     * API provider logs and enables audit correlation without token fingerprinting.
     *
     * Validates the returned JSON, strips any markdown fences via the validator,
     * and writes clean re-encoded JSON to local_lid_analysis on success.
     *
     * @param  \local_lid\analysis\session_analyser $analyser
     * @param  \stdClass                            $analysisrow
     * @param  int                                  $timeclaimed  Per-item worker start timestamp for audit log.
     * @return array{bool, int}  [json_written, timeprocessed]
     */
    private function process_analysis_row(
        \local_lid\analysis\session_analyser $analyser,
        \stdClass $analysisrow,
        int $timeclaimed
    ): array {
        global $DB;

        $builder = new \local_lid\llm\prompt_builder(
            (int) $analysisrow->courseid,
            (int) $analysisrow->forumid
        );

        // Build the prompt based on scope.
        if ($analysisrow->scope === 'student_forum') {
            $forumname = $DB->get_field('forum', 'name', ['id' => $analysisrow->forumid]) ?? '';
            $prompt    = $builder->build_for_student_forum((int) $analysisrow->userid, $forumname);
            $promptHash = $builder->get_forum_analyzer_hash();
        } else if ($analysisrow->scope === 'thread') {
            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => $analysisrow->discussionid],
                'id, name'
            );
            if (!$discussion) {
                mtrace("local_lid process_queue: discussion {$analysisrow->discussionid} not found - skipping.");
                return [false, time()];
            }
            $prompt     = $builder->build_for_thread_by_id(
                (int) $analysisrow->discussionid,
                $discussion->name
            );
            $promptHash = $builder->get_forum_analyzer_hash();
        } else {
            mtrace("local_lid process_queue: unsupported scope '{$analysisrow->scope}' - skipping.");
            return [false, time()];
        }

        if (empty($prompt)) {
            mtrace("local_lid process_queue: empty prompt for analysis row {$analysisrow->id} - no posts found.");
            return [false, time()];
        }

        // ----------------------------------------------------------------
        // Inject structured assessment metadata block.
        //
        // This block is prepended to the prompt and appears verbatim in
        // the API provider's input log, enabling direct correlation of
        // external call records back to specific Moodle users and analysis
        // rows without relying on post content fingerprinting.
        //
        // userid is null for thread-scope calls - the block records 'null'
        // explicitly so the absence is visible in the API log rather than
        // silently absent.
        // ----------------------------------------------------------------
        $useridlabel = ($analysisrow->scope === 'student_forum' && !empty($analysisrow->userid))
            ? (int) $analysisrow->userid
            : 'null';

        $metadatablock = implode("\n", [
            '## LID Assessment Metadata',
            '<!-- This block is used for audit trail correlation only. -->',
            '- lid_analysisid: ' . (int) $analysisrow->id,
            '- lid_userid: '     . $useridlabel,
            '- lid_forumid: '    . (int) $analysisrow->forumid,
            '- lid_courseid: '   . (int) $analysisrow->courseid,
            '- lid_scope: '      . $analysisrow->scope,
            '- lid_timestamp: '  . gmdate('Y-m-d\TH:i:s\Z', $timeclaimed),
            '',
        ]);

        $prompt = $metadatablock . $prompt;

        // Call the LLM.
        $rawjson = $analyser->call_llm($prompt);

        $timeprocessed = time();

        if (empty($rawjson)) {
            return [false, $timeprocessed];
        }

        // Validate the returned JSON.
        // validate_json() strips markdown fences and returns the decoded array,
        // or null if validation fails. We re-encode to store clean JSON.
        $data = $analyser->validate_json($rawjson);
        if ($data === null) {
            return [false, $timeprocessed];
        }

        $schemaversion = $data['schema_version'] ?? '1.2';
        $llmmodel      = $analyser->get_last_model_used();

        // Re-encode the validated, fence-stripped data as clean JSON for storage.
        $cleanjson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($cleanjson === false) {
            mtrace("local_lid process_queue: JSON re-encode failed for analysis row {$analysisrow->id}.");
            return [false, $timeprocessed];
        }

        // Write to the analysis row.
        $DB->update_record('local_lid_analysis', (object) [
            'id'             => $analysisrow->id,
            'analysis_json'  => $cleanjson,
            'schema_version' => $schemaversion,
            'status'         => 'complete',
            'error_message'  => null,
            'llm_model'      => $llmmodel,
            'prompt_hash'    => $promptHash,
            'timemodified'   => time(),
        ]);

        return [true, $timeprocessed];
    }

    // -------------------------------------------------------------------------
    // Phase 3 - Aggregation + notification
    // -------------------------------------------------------------------------

    /**
     * Trigger forum and course aggregate recomputation for a forum.
     *
     * @param int $forumid
     * @param int $courseid
     */
    private function trigger_aggregation(int $forumid, int $courseid): void {
        $aggregator = new \local_lid\analysis\aggregator();
        $aggregator->recompute_forum_aggregate($forumid, $courseid);
        $aggregator->recompute_course_aggregate($courseid);
    }

    /**
     * Send a completion notification to instructors if the forum's full
     * analysis set is now complete.
     *
     * Notification is a courtesy - it must never cause a completed analysis
     * job to be re-queued or counted as failed. All notification failures,
     * including invalid email addresses, missing user properties, and
     * message delivery errors, are caught and logged as warnings only.
     *
     * Each instructor's user record is fetched in full via core_user::get_user()
     * to satisfy Moodle's messaging system requirement for a complete userto
     * object. A pre-flight email validation guard skips delivery for users
     * with absent or malformed email addresses rather than attempting delivery
     * and relying on the mail layer to throw.
     *
     * Skips silently if the local_lid message provider is not registered
     * in Moodle's messaging system to avoid debug noise during development.
     *
     * @param int $forumid
     * @param int $courseid
     */
    private function maybe_notify_completion(int $forumid, int $courseid): void {
        global $DB;

        // Guard: skip if the message provider is not registered.
        // Prevents debug noise when the messages.php provider definition
        // has not yet been activated in Moodle's messaging configuration.
        $providerexists = $DB->record_exists('message_providers', [
            'component' => 'local_lid',
            'name'      => 'analysis_complete',
        ]);
        if (!$providerexists) {
            return;
        }

        // Check for any remaining incomplete rows for this forum.
        $pending = $DB->count_records_select(
            'local_lid_analysis',
            "forumid = :forumid
             AND scope = 'student_forum'
             AND status IN ('pending', 'stale', 'processing')",
            ['forumid' => $forumid]
        );

        if ($pending > 0) {
            return;
        }

        $complete = $DB->count_records('local_lid_analysis', [
            'forumid' => $forumid,
            'scope'   => 'student_forum',
            'status'  => 'complete',
        ]);

        if ($complete === 0) {
            return;
        }

        // Find instructors with view capability for this forum.
        $context     = \context_course::instance($courseid);
        $instructors = get_users_by_capability(
            $context,
            'local/lid:viewforumdashboard',
            'u.id'
        );

        if (empty($instructors)) {
            return;
        }

        $forum   = $DB->get_record('forum', ['id' => $forumid], 'id, name, course');
        $course  = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
        $dashurl = new \moodle_url('/local/lid/forum_lid.php', [
            'forumid'  => $forumid,
            'courseid' => $courseid,
        ]);

        foreach ($instructors as $instructor) {
            try {
                // Fetch the full user record. get_users_by_capability() returns
                // a minimal object that is missing fields required by message_send()
                // (mailformat, maildisplay, etc.). Fetching the full record here
                // prevents the "Necessary properties missing in userto object" debug
                // notice that would otherwise bubble up and poison the job status.
                $fulluser = \core_user::get_user($instructor->id);
                if (!$fulluser) {
                    mtrace("local_lid process_queue: notification skipped for user {$instructor->id} - user record not found.");
                    continue;
                }

                // Pre-flight email validation guard.
                // Validate before attempting delivery so that invalid addresses
                // (malformed domains, empty fields, legacy SIS data) are handled
                // explicitly rather than relying on the mail layer to throw.
                // This is a production concern: invalid emails occur in real
                // environments fed by HR systems, SIS integrations, and manual
                // imports - not only in development with dummy data.
                if (empty($fulluser->email) || !validate_email($fulluser->email)) {
                    mtrace("local_lid process_queue: notification skipped for user {$fulluser->id} - invalid or missing email address.");
                    continue;
                }

                $message                    = new \core\message\message();
                $message->component         = 'local_lid';
                $message->name              = 'analysis_complete';
                $message->userfrom          = \core_user::get_noreply_user();
                $message->userto            = $fulluser;
                $message->subject           = get_string(
                    'notification_complete_subject',
                    'local_lid',
                    ['forum' => $forum->name, 'course' => $course->shortname]
                );
                $message->fullmessage       = get_string(
                    'notification_complete_body',
                    'local_lid',
                    [
                        'forum'  => $forum->name,
                        'course' => $course->fullname,
                        'count'  => $complete,
                        'url'    => $dashurl->out(false),
                    ]
                );
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml   = get_string(
                    'notification_complete_body_html',
                    'local_lid',
                    [
                        'forum'  => format_string($forum->name),
                        'course' => format_string($course->fullname),
                        'count'  => $complete,
                        'url'    => $dashurl->out(false),
                    ]
                );
                $message->smallmessage      = get_string(
                    'notification_complete_small',
                    'local_lid',
                    ['forum' => $forum->name, 'count' => $complete]
                );
                $message->notification      = 1;
                $message->contexturl        = $dashurl->out(false);
                $message->contexturlname    = get_string('notification_complete_urlname', 'local_lid');

                message_send($message);

            } catch (\Throwable $e) {
                // Notification failure must never propagate. Log as warning only.
                // The analysis is complete and available regardless of whether
                // the notification was delivered successfully.
                mtrace(
                    "local_lid process_queue: notification warning for user {$instructor->id}: " .
                    $e->getMessage()
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    /**
     * Write an audit log row to local_lid_call_log.
     *
     * Called immediately after a queue item is processed (success or failure)
     * and before the queue item is deleted. Provides a permanent, append-only
     * record that can be correlated with external API provider logs using the
     * timeclaimed / timeprocessed window and userid as anchors.
     *
     * The timeclaimed value passed in is the per-item worker start timestamp
     * (from the in-loop refresh), not the original batch claim time. This
     * tighter window correlates more reliably against external provider logs.
     *
     * Failures are caught and logged as warnings - a call log write failure
     * must never cause a successfully-processed analysis to be re-queued.
     *
     * @param \stdClass $analysisrow   The analysis row that was processed.
     * @param int       $timeclaimed   When the worker started this specific item.
     * @param int       $timeprocessed When the LLM call completed.
     * @param bool      $succeeded     Whether analysis_json was written.
     */
    private function write_call_log(
        \stdClass $analysisrow,
        int $timeclaimed,
        int $timeprocessed,
        bool $succeeded
    ): void {
        global $DB;

        try {
            $DB->insert_record('local_lid_call_log', (object) [
                'analysisid'    => (int) $analysisrow->id,
                'userid'        => !empty($analysisrow->userid) ? (int) $analysisrow->userid : null,
                'forumid'       => (int) $analysisrow->forumid,
                'courseid'      => (int) $analysisrow->courseid,
                'scope'         => $analysisrow->scope,
                'llm_model'     => $analysisrow->llm_model ?? null,
                'prompt_hash'   => $analysisrow->prompt_hash ?? null,
                'timeclaimed'   => $timeclaimed,
                'timeprocessed' => $timeprocessed,
                'succeeded'     => $succeeded ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            mtrace(
                "local_lid process_queue: call log write failed for analysis row {$analysisrow->id}: " .
                $e->getMessage()
            );
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
     * Note: this helper only releases rows claimed at the exact timestamp
     * passed in ($claimedtime). It is used to release items claimed by the
     * *current* run when processing is aborted mid-batch. Rows orphaned by
     * previous crashed runs are handled by the Phase 0 stale-claim cleanup
     * at the top of execute().
     *
     * @param int[] $ids
     * @param int   $claimedtime
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
