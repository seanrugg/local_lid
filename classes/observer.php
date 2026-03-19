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
 * Event observer for local_lid.
 *
 * Handles forum post events and enqueues LID analysis jobs.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class — static handlers called by the Moodle events system.
 *
 * Each handler follows the same pattern:
 *   1. Guard: is LID enabled for this forum? If not, return immediately.
 *   2. Guard: is the relevant trigger mode active? If not, return.
 *   3. Create or update a local_lid_analysis record (scope = 'post').
 *   4. Enqueue a job in local_lid_queue with appropriate priority and
 *      timevisible value.
 */
class observer {

    // -------------------------------------------------------------------------
    // Constants — queue priority values (must match install.xml comments).
    // -------------------------------------------------------------------------

    /** @var int Manual teacher request — highest priority. */
    const PRIORITY_MANUAL = 1;

    /** @var int Async trigger — queued immediately on post submission. */
    const PRIORITY_ASYNC = 2;

    /** @var int Cron batch — lowest priority. */
    const PRIORITY_CRON = 3;

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    /**
     * Handle \mod_forum\event\post_created.
     *
     * Creates a new analysis record and enqueues it. If neither async nor cron
     * trigger is enabled (manual-only mode), no queue entry is created here;
     * the teacher must trigger analysis explicitly via the UI.
     *
     * @param \mod_forum\event\post_created $event The event object.
     */
    public static function post_created(\mod_forum\event\post_created $event): void {
        global $DB;

        $forumid      = self::get_forumid_from_event($event);
        $courseid     = $event->courseid;
        $discussionid = $event->other['discussionid'] ?? null;
        $postid       = $event->objectid;
        $userid       = $event->userid;

        if (!$forumid || !self::is_forum_enabled($forumid)) {
            return;
        }

        // Create the analysis record.
        $analysisid = self::create_analysis_record(
            $courseid,
            $forumid,
            $discussionid,
            $postid,
            $userid
        );

        if (!$analysisid) {
            return;
        }

        // Enqueue according to active trigger mode.
        self::enqueue($analysisid, $courseid);
    }

    /**
     * Handle \mod_forum\event\post_updated.
     *
     * If a completed analysis exists for this post, marks it as pending
     * and re-enqueues it so the dashboard reflects the edited content.
     * If no analysis record exists yet (post was never analysed), creates
     * one and enqueues it — handles the edge case of editing a post that
     * was submitted while LID was disabled then later enabled.
     *
     * @param \mod_forum\event\post_updated $event The event object.
     */
    public static function post_updated(\mod_forum\event\post_updated $event): void {
        global $DB;

        $forumid      = self::get_forumid_from_event($event);
        $courseid     = $event->courseid;
        $discussionid = $event->other['discussionid'] ?? null;
        $postid       = $event->objectid;
        $userid       = $event->userid;

        if (!$forumid || !self::is_forum_enabled($forumid)) {
            return;
        }

        // Check for an existing analysis record for this post.
        $existing = $DB->get_record('local_lid_analysis', [
            'scope'  => 'post',
            'postid' => $postid,
        ]);

        if ($existing) {
            // Reset to pending so the queue task re-analyses with updated content.
            $DB->update_record('local_lid_analysis', (object) [
                'id'           => $existing->id,
                'status'       => 'pending',
                'timemodified' => time(),
            ]);
            $analysisid = $existing->id;

            // Remove any existing queue entry for this analysis to avoid duplicates.
            $DB->delete_records('local_lid_queue', ['analysisid' => $analysisid]);
        } else {
            // No prior record — create fresh.
            $analysisid = self::create_analysis_record(
                $courseid,
                $forumid,
                $discussionid,
                $postid,
                $userid
            );

            if (!$analysisid) {
                return;
            }
        }

        self::enqueue($analysisid, $courseid);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the forum instance id from an event object.
     *
     * Forum events carry the cm id in $event->contextinstanceid (module context).
     * We retrieve the forum instance id via the course_modules table.
     *
     * @param \core\event\base $event
     * @return int|null Forum id, or null if it cannot be resolved.
     */
    private static function get_forumid_from_event(\core\event\base $event): ?int {
        global $DB;

        $cmid = $event->contextinstanceid;
        if (!$cmid) {
            return null;
        }

        $forumid = $DB->get_field_sql(
            'SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND m.name = :modname',
            ['cmid' => $cmid, 'modname' => 'forum']
        );

        return $forumid ? (int) $forumid : null;
    }

    /**
     * Return true if LID analysis is enabled for the given forum.
     *
     * @param int $forumid
     * @return bool
     */
    private static function is_forum_enabled(int $forumid): bool {
        global $DB;

        return (bool) $DB->get_field(
            'local_lid_forum_config',
            'enabled',
            ['forumid' => $forumid]
        );
    }

    /**
     * Insert a new local_lid_analysis record with status = 'pending'.
     *
     * Returns the new record id, or null on failure.
     *
     * @param int      $courseid
     * @param int      $forumid
     * @param int|null $discussionid
     * @param int      $postid
     * @param int      $userid
     * @return int|null
     */
    private static function create_analysis_record(
        int $courseid,
        int $forumid,
        ?int $discussionid,
        int $postid,
        int $userid
    ): ?int {
        global $DB;

        // Do not create a duplicate for the same post.
        if ($DB->record_exists('local_lid_analysis', ['scope' => 'post', 'postid' => $postid])) {
            return null;
        }

        $record = new \stdClass();
        $record->scope         = 'post';
        $record->courseid      = $courseid;
        $record->forumid       = $forumid;
        $record->discussionid  = $discussionid;
        $record->postid        = $postid;
        $record->userid        = $userid;
        $record->analysis_json = null;
        $record->schema_version = '1.0';
        $record->status        = 'pending';
        $record->error_message = null;
        $record->llm_model     = null;
        $record->prompt_hash   = null;
        $record->timecreated   = time();
        $record->timemodified  = time();

        try {
            return $DB->insert_record('local_lid_analysis', $record);
        } catch (\dml_exception $e) {
            debugging('local_lid observer: failed to create analysis record: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Enqueue an analysis job in local_lid_queue.
     *
     * Priority and timevisible are determined by the site-level trigger
     * settings. If no automatic trigger (async or cron) is active, no
     * queue record is created — the job must be triggered manually.
     *
     * @param int $analysisid  The local_lid_analysis.id to queue.
     * @param int $courseid    Used to resolve course-level config if needed.
     */
    private static function enqueue(int $analysisid, int $courseid): void {
        global $DB;

        $asyncenabled = (bool) get_config('local_lid', 'trigger_async');
        $cronenabled  = (bool) get_config('local_lid', 'trigger_cron');

        if (!$asyncenabled && !$cronenabled) {
            // Manual-only mode — no automatic queue entry.
            return;
        }

        // Determine priority and timevisible.
        if ($asyncenabled) {
            $priority    = self::PRIORITY_ASYNC;
            $timevisible = time(); // Pick up at the very next task run.
        } else {
            // Cron-only: calculate next scheduled batch time.
            $interval    = max(1, (int) get_config('local_lid', 'cron_interval'));
            $priority    = self::PRIORITY_CRON;
            $timevisible = time() + ($interval * 60);
        }

        $queuerecord = new \stdClass();
        $queuerecord->analysisid  = $analysisid;
        $queuerecord->priority    = $priority;
        $queuerecord->attempts    = 0;
        $queuerecord->timecreated = time();
        $queuerecord->timevisible = $timevisible;
        $queuerecord->timeclaimed = null;

        try {
            $DB->insert_record('local_lid_queue', $queuerecord);
        } catch (\dml_exception $e) {
            debugging('local_lid observer: failed to enqueue analysis job: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
