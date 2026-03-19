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
 * Privacy provider for local_lid.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_lid.
 *
 * Data this plugin stores about users:
 *
 *   local_lid_analysis (post-scope rows)
 *     - userid: the Moodle user ID of the post author
 *     - analysis_json: LID JSON derived from the user's forum post content
 *     - postid, forumid, courseid, discussionid: context identifiers
 *     - timecreated, timemodified
 *
 *   local_lid_queue
 *     - Transient job queue. Rows reference analysis records but contain no
 *       personal data directly. They are ephemeral and cleared on completion
 *       or failure. We do not export or delete queue rows — they clean
 *       themselves up through normal processing.
 *
 * Data sent to external systems:
 *   Forum post content is sent to a third-party LLM API during analysis.
 *   The plugin does not store post content itself — only the derived JSON
 *   output. Institutions are responsible for ensuring their LLM provider's
 *   data processing agreements cover this transfer.
 *
 * Aggregate-scope rows (forum, student_forum, course) reference userids only
 * indirectly through the post-scope rows they are derived from. Deleting
 * a user's post-scope rows triggers aggregate rebuilds that exclude the
 * deleted user's contributions.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    // =========================================================================
    // Metadata
    // =========================================================================

    /**
     * Describe the data this plugin stores and the external systems it uses.
     *
     * @param  collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        // local_lid_analysis table.
        $collection->add_database_table(
            'local_lid_analysis',
            [
                'userid'        => 'privacy:metadata:local_lid_analysis:userid',
                'analysis_json' => 'privacy:metadata:local_lid_analysis:analysis_json',
                'postid'        => 'privacy:metadata:local_lid_analysis:postid',
                'timecreated'   => 'privacy:metadata:local_lid_analysis:timecreated',
            ],
            'privacy:metadata:local_lid_analysis'
        );

        // External LLM API.
        $collection->add_external_location_link(
            'llm_api',
            [
                'post_content' => 'privacy:metadata:llm_api',
            ],
            'privacy:metadata:llm_api'
        );

        return $collection;
    }

    // =========================================================================
    // Context discovery
    // =========================================================================

    /**
     * Return the contexts that contain personal data for the given user.
     *
     * We return course contexts for every course in which the user has a
     * post-scope analysis record. The dashboard surfaces data at course
     * context level (reports tab, student profile tab).
     *
     * @param  int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // Find all course contexts where this user has post-scope analyses.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_lid_analysis} a ON a.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND a.userid = :userid
                   AND a.scope = 'post'";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Return the users who have data within the given contexts.
     *
     * @param  userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!($context instanceof \context_course)) {
            return;
        }

        $sql = "SELECT DISTINCT a.userid
                  FROM {local_lid_analysis} a
                 WHERE a.courseid = :courseid
                   AND a.scope = 'post'
                   AND a.userid IS NOT NULL";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    // =========================================================================
    // Data export
    // =========================================================================

    /**
     * Export all personal data for the user in the given contexts.
     *
     * Exports each post-scope analysis record as a JSON file under the
     * course context path. The analysis_json field is decoded and exported
     * as a structured object so it is human-readable in the data export ZIP.
     *
     * @param  approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_course)) {
                continue;
            }

            $courseid = $context->instanceid;

            $records = $DB->get_records_select(
                'local_lid_analysis',
                "userid = :userid AND courseid = :courseid AND scope = 'post'",
                ['userid' => $userid, 'courseid' => $courseid],
                'timecreated ASC'
            );

            if (empty($records)) {
                continue;
            }

            $exportdata = [];
            foreach ($records as $record) {
                $analysisdata = !empty($record->analysis_json)
                    ? json_decode($record->analysis_json)
                    : null;

                $exportdata[] = (object) [
                    'post_id'      => $record->postid,
                    'forum_id'     => $record->forumid,
                    'discussion_id' => $record->discussionid,
                    'status'       => $record->status,
                    'schema_version' => $record->schema_version,
                    'llm_model'    => $record->llm_model,
                    'timecreated'  => transform::datetime($record->timecreated),
                    'timemodified' => transform::datetime($record->timemodified),
                    'analysis'     => $analysisdata,
                ];
            }

            // Write under: [course context] / Learning Intelligence Dashboard / analyses.json
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_lid')],
                (object) ['analyses' => $exportdata]
            );
        }
    }

    // =========================================================================
    // Data deletion
    // =========================================================================

    /**
     * Delete all personal data for all users in the given context.
     *
     * Deletes all post-scope analysis records in the course, then triggers
     * aggregate rebuilds to remove the deleted data from aggregate records.
     * Also removes any stale queue items for the deleted analysis records.
     *
     * @param  \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!($context instanceof \context_course)) {
            return;
        }

        $courseid = $context->instanceid;

        // Get analysis IDs before deletion so we can clean the queue.
        $analysisids = $DB->get_fieldset_select(
            'local_lid_analysis',
            'id',
            "courseid = :courseid AND scope = 'post'",
            ['courseid' => $courseid]
        );

        if (!empty($analysisids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($analysisids);
            $DB->delete_records_select('local_lid_queue', "analysisid {$insql}", $inparams);
        }

        // Delete all post-scope records for this course.
        $DB->delete_records_select(
            'local_lid_analysis',
            "courseid = :courseid AND scope = 'post'",
            ['courseid' => $courseid]
        );

        // Delete aggregate records for this course (they are now invalid).
        $DB->delete_records_select(
            'local_lid_analysis',
            "courseid = :courseid AND scope != 'post'",
            ['courseid' => $courseid]
        );
    }

    /**
     * Delete personal data for a specific user in the given contexts.
     *
     * Deletes only post-scope records belonging to this user, then
     * rebuilds aggregates to reflect their removal.
     *
     * @param  approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_course)) {
                continue;
            }

            $courseid = $context->instanceid;

            // Clean queue entries for this user's analyses.
            $analysisids = $DB->get_fieldset_select(
                'local_lid_analysis',
                'id',
                "userid = :userid AND courseid = :courseid AND scope = 'post'",
                ['userid' => $userid, 'courseid' => $courseid]
            );

            if (!empty($analysisids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($analysisids);
                $DB->delete_records_select('local_lid_queue', "analysisid {$insql}", $inparams);
            }

            // Delete this user's post-scope records.
            $DB->delete_records(
                'local_lid_analysis',
                ['userid' => $userid, 'courseid' => $courseid, 'scope' => 'post']
            );

            // Delete this user's student_forum aggregate records.
            $DB->delete_records(
                'local_lid_analysis',
                ['userid' => $userid, 'courseid' => $courseid, 'scope' => 'student_forum']
            );

            // Rebuild forum and course aggregates to exclude the deleted user.
            // We get the affected forum IDs from the now-deleted records' context
            // by rebuilding all enabled forums in the course.
            self::rebuild_aggregates_for_course($courseid);
        }
    }

    /**
     * Delete personal data for multiple users in the given context.
     *
     * @param  approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!($context instanceof \context_course)) {
            return;
        }

        $courseid = $context->instanceid;
        $userids  = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids);

        // Clean queue entries.
        $analysisids = $DB->get_fieldset_sql(
            "SELECT id FROM {local_lid_analysis}
              WHERE courseid = :courseid AND scope = 'post' AND userid {$usersql}",
            array_merge(['courseid' => $courseid], $userparams)
        );

        if (!empty($analysisids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($analysisids);
            $DB->delete_records_select('local_lid_queue', "analysisid {$insql}", $inparams);
        }

        // Delete post-scope records.
        $DB->execute(
            "DELETE FROM {local_lid_analysis}
              WHERE courseid = :courseid AND scope = 'post' AND userid {$usersql}",
            array_merge(['courseid' => $courseid], $userparams)
        );

        // Delete student_forum aggregates.
        $DB->execute(
            "DELETE FROM {local_lid_analysis}
              WHERE courseid = :courseid AND scope = 'student_forum' AND userid {$usersql}",
            array_merge(['courseid' => $courseid], $userparams)
        );

        // Rebuild forum and course aggregates.
        self::rebuild_aggregates_for_course($courseid);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Rebuild all forum and course aggregates for a course after user
     * deletion, so the aggregated dashboards no longer reflect deleted data.
     *
     * @param  int $courseid
     */
    private static function rebuild_aggregates_for_course(int $courseid): void {
        global $DB;

        $aggregator = new \local_lid\analysis\aggregator();

        // Rebuild each enabled forum's aggregate.
        $enabledforums = $DB->get_fieldset_select(
            'local_lid_forum_config',
            'forumid',
            'courseid = :courseid AND enabled = 1',
            ['courseid' => $courseid]
        );

        foreach ($enabledforums as $forumid) {
            $aggregator->rebuild_forum((int) $forumid, $courseid);
        }

        // Rebuild course aggregate.
        $aggregator->rebuild_course($courseid);
    }
}
