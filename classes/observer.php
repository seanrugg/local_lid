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
 * Handles forum events and manages LID analysis job queuing.
 *
 * Design principles:
 *
 *   - LID analysis is a retrospective, post-discussion assessment. Analysis
 *     is never triggered while a discussion is in progress.
 *
 *   - The primary unit of analysis is student_forum scope (all posts by one
 *     learner across all threads in a forum). No per-post LLM calls are made.
 *
 *   - Only users who hold a student-archetype role in the course context are
 *     eligible for student_forum analysis. Posts by instructors, managers, or
 *     other non-student roles are excluded from analysis queuing. Instructor
 *     posts still appear as conversational context in the LLM prompt so the
 *     assessed learner's replies can be understood in their full discourse
 *     context — they are simply not scored or given analysis rows.
 *
 *   - When a learner posts during an active discussion, their student_forum
 *     analysis row is created (status = 'pending') or flagged stale if a
 *     completed analysis already exists. No queue entry is created at post time.
 *
 *   - Analysis is queued when the forum is reliably closed. Three mechanisms:
 *
 *       1. discussion_locked event — instructor locked a thread manually.
 *          Observer checks whether ALL threads in the forum are now locked.
 *          If yes, the forum is considered closed and analysis is queued.
 *
 *       2. Cut-off date detection — handled by the cron task (process_queue).
 *          Moodle does not fire a reliable event when a forum cut-off date passes.
 *
 *       3. Inactivity lock detection — also handled by cron. The forum setting
 *          "Lock discussions after period of inactivity" is stored on the forum
 *          record; cron detects when the threshold has been crossed.
 *
 *       4. Manual trigger — instructor clicks "Run LID Analysis" in the Forum
 *          LID dashboard. Calls queue_forum_analysis() with PRIORITY_MANUAL
 *          and bypasses all close-state checks.
 *
 *   - Show/hide of the forum activity is NOT a trigger. A hidden forum may be
 *     hidden for reasons unrelated to discussion closure. The Forum LID config
 *     UI displays guidance that analysis requires the forum to be closed by
 *     one of the three mechanisms above.
 *
 *   - Late post handling: if a learner posts after analysis has already run
 *     (status = 'complete'), their student_forum row is marked 'stale' and
 *     a warning indicator appears in the Forum LID dashboard. Re-analysis
 *     only occurs on the next manual trigger.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class — static handlers called by the Moodle events system.
 */
class observer {

    // -------------------------------------------------------------------------
    // Queue priority constants (must match install.xml and process_queue).
    // -------------------------------------------------------------------------

    /** @var int Manual teacher request — highest priority. */
    const PRIORITY_MANUAL = 1;

    /** @var int Event/cron triggered — standard priority. */
    const PRIORITY_CRON = 2;

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    /**
     * Handle \mod_forum\event\post_created.
     *
     * Does NOT queue analysis. Instead:
     *   - Checks whether the poster holds a student-archetype role in the course
     *     context. If not, the post is ignored for LID analysis purposes (the
     *     content will still appear as conversational context when student analyses
     *     are built by the prompt builder).
     *   - Creates a student_forum analysis row for this learner+forum if one
     *     does not already exist (status = 'pending').
     *   - If a 'complete' row exists (analysis ran before this post was made),
     *     marks it 'stale' so the Forum LID dashboard shows a late-post warning.
     *   - 'pending', 'stale', or 'processing' rows are left untouched.
     *   - 'error' rows are reset to 'pending' so they can be retried.
     *
     * @param \mod_forum\event\post_created $event
     */
    public static function post_created(\mod_forum\event\post_created $event): void {
        $forumid  = self::get_forumid_from_event($event);
        $courseid = $event->courseid;
        $userid   = $event->userid;

        if (!$forumid || !self::is_forum_enabled($forumid)) {
            return;
        }

        // Only create analysis rows for users with a student-archetype role.
        if (!self::user_has_student_role($userid, $courseid)) {
            return;
        }

        self::upsert_student_forum_row($courseid, $forumid, $userid);
    }

    /**
     * Handle \mod_forum\event\discussion_locked.
     *
     * Fired when an instructor manually locks a discussion thread.
     * Checks whether ALL discussions in the forum are now locked.
     * If yes, queues student_forum and thread analysis for the forum.
     *
     * @param \mod_forum\event\discussion_locked $event
     */
    public static function discussion_locked(\mod_forum\event\discussion_locked $event): void {
        $forumid  = self::get_forumid_from_event($event);
        $courseid = $event->courseid;

        if (!$forumid || !self::is_forum_enabled($forumid)) {
            return;
        }

        if (!self::all_discussions_locked($forumid)) {
            return; // Not all threads locked yet — wait.
        }

        self::queue_forum_analysis($courseid, $forumid, self::PRIORITY_CRON);
    }

    // -------------------------------------------------------------------------
    // Public — trigger interface (event handler + manual + cron)
    // -------------------------------------------------------------------------

    /**
     * Queue LID analysis for all learners and threads in a forum.
     *
     * Called from:
     *   - discussion_locked handler (when all threads are locked)
     *   - process_queue cron (cut-off date / inactivity lock detection)
     *   - Forum LID dashboard "Run LID Analysis" button (manual trigger)
     *
     * Ensures student_forum rows exist for all learners who have posted
     * and hold a student-archetype role in the course context. Non-student
     * posters (instructors, managers, etc.) are excluded — their posts
     * appear as conversational context in the prompt but are not scored.
     *
     * Thread rows exist for all discussions. Then queues all rows
     * with status 'pending' or 'stale'. Rows already 'processing' are skipped.
     *
     * @param  int $courseid
     * @param  int $forumid
     * @param  int $priority  PRIORITY_MANUAL or PRIORITY_CRON.
     * @return int             Number of analysis rows queued.
     */
    public static function queue_forum_analysis(
        int $courseid,
        int $forumid,
        int $priority = self::PRIORITY_MANUAL
    ): int {
        global $DB;

        // Get all user ids who posted in this forum AND hold a student role.
        $allposters = self::get_forum_posters($forumid);
        $studentids = self::get_student_userids($courseid);
        $studentposters = array_intersect($allposters, $studentids);

        // Ensure student_forum rows exist only for student-role posters.
        foreach ($studentposters as $userid) {
            self::upsert_student_forum_row($courseid, $forumid, $userid);
        }

        // Ensure thread rows exist for all discussions.
        self::upsert_thread_rows($courseid, $forumid);

        // Fetch all student_forum and thread rows that need processing.
        $rows = $DB->get_records_select(
            'local_lid_analysis',
            "forumid = :forumid
             AND scope IN ('student_forum', 'thread')
             AND status IN ('pending', 'stale')",
            ['forumid' => $forumid]
        );

        if (empty($rows)) {
            return 0;
        }

        $queued = 0;
        $now    = time();

        foreach ($rows as $row) {
            // Skip if already in queue to prevent duplicates.
            if ($DB->record_exists('local_lid_queue', ['analysisid' => $row->id])) {
                continue;
            }

            $queuerecord              = new \stdClass();
            $queuerecord->analysisid  = $row->id;
            $queuerecord->priority    = $priority;
            $queuerecord->attempts    = 0;
            $queuerecord->timecreated = $now;
            $queuerecord->timevisible = $now;
            $queuerecord->timeclaimed = null;

            try {
                $DB->insert_record('local_lid_queue', $queuerecord);

                // Normalise status to 'pending' so the queue drain picks it up.
                $DB->update_record('local_lid_analysis', (object) [
                    'id'           => $row->id,
                    'status'       => 'pending',
                    'timemodified' => $now,
                ]);

                $queued++;
            } catch (\dml_exception $e) {
                debugging(
                    'local_lid observer: failed to queue analysis row ' . $row->id .
                    ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $queued;
    }

    // -------------------------------------------------------------------------
    // Private — analysis row management
    // -------------------------------------------------------------------------

    /**
     * Upsert a student_forum analysis row for a given learner and forum.
     *
     * Status transitions:
     *   No row       → insert with status 'pending'
     *   'complete'   → update to 'stale'  (late post after analysis ran)
     *   'error'      → update to 'pending' (allow retry)
     *   'pending'    → no change
     *   'stale'      → no change
     *   'processing' → no change
     *
     * @param int $courseid
     * @param int $forumid
     * @param int $userid
     */
    private static function upsert_student_forum_row(
        int $courseid,
        int $forumid,
        int $userid
    ): void {
        global $DB;

        $existing = $DB->get_record('local_lid_analysis', [
            'scope'   => 'student_forum',
            'forumid' => $forumid,
            'userid'  => $userid,
        ]);

        $now = time();

        if (!$existing) {
            $record                 = new \stdClass();
            $record->scope          = 'student_forum';
            $record->courseid       = $courseid;
            $record->forumid        = $forumid;
            $record->discussionid   = null;
            $record->postid         = null;
            $record->userid         = $userid;
            $record->analysis_json  = null;
            $record->schema_version = '1.2';
            $record->status         = 'pending';
            $record->error_message  = null;
            $record->llm_model      = null;
            $record->prompt_hash    = null;
            $record->timecreated    = $now;
            $record->timemodified   = $now;

            try {
                $DB->insert_record('local_lid_analysis', $record);
            } catch (\dml_exception $e) {
                debugging(
                    'local_lid observer: failed to create student_forum row for ' .
                    "forum {$forumid} user {$userid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
            return;
        }

        $newstatus = null;
        if ($existing->status === 'complete') {
            $newstatus = 'stale';
        } else if ($existing->status === 'error') {
            $newstatus = 'pending';
        }

        if ($newstatus !== null) {
            $DB->update_record('local_lid_analysis', (object) [
                'id'           => $existing->id,
                'status'       => $newstatus,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Ensure a thread-scope analysis row exists for every discussion in the forum.
     *
     * Creates 'pending' rows for discussions that have no row yet.
     * Does not modify existing rows.
     *
     * @param int $courseid
     * @param int $forumid
     */
    private static function upsert_thread_rows(int $courseid, int $forumid): void {
        global $DB;

        $discussions = $DB->get_records('forum_discussions', ['forum' => $forumid], '', 'id, name');
        $now         = time();

        foreach ($discussions as $discussion) {
            if ($DB->record_exists('local_lid_analysis', [
                'scope'        => 'thread',
                'forumid'      => $forumid,
                'discussionid' => $discussion->id,
            ])) {
                continue;
            }

            $record                 = new \stdClass();
            $record->scope          = 'thread';
            $record->courseid       = $courseid;
            $record->forumid        = $forumid;
            $record->discussionid   = $discussion->id;
            $record->postid         = null;
            $record->userid         = null;
            $record->analysis_json  = null;
            $record->schema_version = '1.2';
            $record->status         = 'pending';
            $record->error_message  = null;
            $record->llm_model      = null;
            $record->prompt_hash    = null;
            $record->timecreated    = $now;
            $record->timemodified   = $now;

            try {
                $DB->insert_record('local_lid_analysis', $record);
            } catch (\dml_exception $e) {
                debugging(
                    'local_lid observer: failed to create thread row for discussion ' .
                    $discussion->id . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private — forum close detection helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if ALL discussions in the forum are locked.
     *
     * A discussion is locked when forum_discussions.locked = 1.
     * Returns false for forums with no discussions.
     *
     * @param  int $forumid
     * @return bool
     */
    private static function all_discussions_locked(int $forumid): bool {
        global $DB;

        $total = $DB->count_records('forum_discussions', ['forum' => $forumid]);
        if ($total === 0) {
            return false;
        }

        $locked = $DB->count_records('forum_discussions', [
            'forum'  => $forumid,
            'locked' => 1,
        ]);

        return $locked === $total;
    }

    /**
     * Return array of distinct user ids who have posted in the forum.
     *
     * @param  int   $forumid
     * @return int[]
     */
    private static function get_forum_posters(int $forumid): array {
        global $DB;

        $sql = "SELECT DISTINCT fp.userid
                  FROM {forum_posts} fp
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE fd.forum = :forumid";

        $records = $DB->get_records_sql($sql, ['forumid' => $forumid]);
        return array_map('intval', array_keys($records));
    }

    // -------------------------------------------------------------------------
    // Private — role filtering helpers
    // -------------------------------------------------------------------------

    /**
     * Return array of user ids who hold a student-archetype role in the course.
     *
     * Uses get_archetype_roles('student') to resolve role ids portably —
     * the student role id is not fixed across Moodle installations.
     *
     * @param  int   $courseid
     * @return int[]
     */
    private static function get_student_userids(int $courseid): array {
        $studentroles = get_archetype_roles('student');
        if (empty($studentroles)) {
            return [];
        }

        $context = \context_course::instance($courseid);
        $userids = [];

        foreach ($studentroles as $role) {
            $users = get_role_users($role->id, $context, false, 'u.id', 'u.id');
            foreach ($users as $user) {
                $userids[(int) $user->id] = true;
            }
        }

        return array_keys($userids);
    }

    /**
     * Check whether a specific user holds a student-archetype role in the course.
     *
     * Lightweight single-user check used by the post_created event handler
     * to avoid loading all student ids for every post event.
     *
     * @param  int  $userid
     * @param  int  $courseid
     * @return bool
     */
    private static function user_has_student_role(int $userid, int $courseid): bool {
        global $DB;

        $studentroles = get_archetype_roles('student');
        if (empty($studentroles)) {
            return false;
        }

        $roleids = array_map(function($role) {
            return (int) $role->id;
        }, $studentroles);

        $context = \context_course::instance($courseid);

        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params['userid']    = $userid;
        $params['contextid'] = $context->id;

        return $DB->record_exists_select(
            'role_assignments',
            "roleid {$insql} AND userid = :userid AND contextid = :contextid",
            $params
        );
    }

    // -------------------------------------------------------------------------
    // Private — forum enabled check + event helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the forum instance id from a Moodle event.
     *
     * Forum events carry the course module id in contextinstanceid.
     *
     * @param  \core\event\base $event
     * @return int|null Forum id, or null on failure.
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
     * Return true if LID analysis is effectively enabled for the forum.
     *
     * Resolution order:
     *   1. Site force-disable → always false.
     *   2. Forum-level config row → use its enabled value if the row exists.
     *   3. Site default → fall back to lid_default_enabled.
     *
     * @param  int $forumid
     * @return bool
     */
    private static function is_forum_enabled(int $forumid): bool {
        global $DB;

        if ((bool) get_config('local_lid', 'lid_force_disabled')) {
            return false;
        }

        $config = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);
        if ($config !== false) {
            return (bool) $config->enabled;
        }

        return (bool) get_config('local_lid', 'lid_default_enabled');
    }
}
