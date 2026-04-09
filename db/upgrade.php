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
 * local_lid database upgrade steps.
 *
 * Version history:
 *   2026032000 — Initial release. Tables created by install.xml; no upgrade steps.
 *   2026032001 — LID Schema v1.1 support. Adds cognitive_performance_index to
 *                analysis output. No DB schema changes required — the new field
 *                is stored within the existing analysis_json TEXT column.
 *                Default prompt updated to v1.2 rubric prompt.
 *   2026032002 — Three-tier enable/disable hierarchy. Adds lid_default_enabled
 *                and lid_force_disabled columns to local_lid_settings.
 *                Adds course_settings.php UI surface and forum Edit Settings
 *                integration via coursemodule_standard_elements callback.
 *   2026032003 — Forum Discussion Analyzer v1.0 / Schema v1.2 support.
 *                Adds discussion_model to local_lid_forum_config to select
 *                the participation model (independent_first | open_engagement |
 *                structured_debate) that governs Critical Discourse scoring.
 *                Updates local_lid_analysis.schema_version default to 1.2.
 *                Adds scope_thread index on local_lid_analysis for thread-scope
 *                lookups. Updates queue priority comment (async tier removed).
 *                No data migration required — existing analyses are unaffected.
 *   2026032800 — v0.5.0 — Role-based access control. Adds viewpeeranalysis
 *                capability, student-only analysis queuing, full thread context
 *                with pseudonymised peers. No DB schema changes.
 *   2026032801 — v0.6.0 — Course Competency Integration.
 *                Adds competencies_enabled to local_lid_settings (course-level
 *                toggle for competency evaluation).
 *                Adds competency_ids to local_lid_forum_config (JSON array of
 *                specific competency IDs to evaluate; null=inherit/all,
 *                []=exclude all, [3,7]=specific).
 *                Adds site-level default via config_plugins
 *                (competencies_enabled_default).
 *   2026040800 — v0.7.0 — API Call Audit Log + Notification hardening.
 *                Adds local_lid_call_log table: append-only audit record of
 *                every LLM API call made by the scheduled task. Captures
 *                userid, forumid, courseid, scope, llm_model, prompt_hash,
 *                timeclaimed, timeprocessed, and succeeded flag. Enables
 *                full traceability from Moodle assessment records back to
 *                external API provider logs (e.g. Google AI Studio).
 *                process_queue.php updated to: embed assessment metadata
 *                block in LLM prompt (userid, forumid, analysisid,
 *                timestamp) for API-side audit correlation; write call log
 *                row on completion; fix notification failure propagation
 *                (email errors no longer cause completed jobs to re-queue).
 *
 * When adding a new upgrade step:
 *   1. Increment $plugin->version in version.php (e.g. 2026032801).
 *   2. Add an if ($oldversion < YYYYMMDDNN) block below.
 *   3. Make all schema changes via $dbman (never raw ALTER TABLE).
 *   4. Always end the block with upgrade_plugin_savepoint(true, YYYYMMDDNN, 'local', 'lid').
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_lid upgrade steps from $oldversion to current version.
 *
 * @param int $oldversion Version number being upgraded from.
 * @return bool True on success.
 */
function xmldb_local_lid_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // --------------------------------------------------------------------
    // v0.3.0 — Three-tier enable/disable hierarchy
    // Adds lid_default_enabled and lid_force_disabled to local_lid_settings.
    // --------------------------------------------------------------------
    if ($oldversion < 2026032002) {

        $table = new xmldb_table('local_lid_settings');

        // lid_default_enabled — global default for new forums.
        $field = new xmldb_field(
            'lid_default_enabled', XMLDB_TYPE_INTEGER, '1',
            null, XMLDB_NOTNULL, null, '0', 'cron_batchsize'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // lid_force_disabled — site-wide override, no teacher can bypass.
        $field = new xmldb_field(
            'lid_force_disabled', XMLDB_TYPE_INTEGER, '1',
            null, XMLDB_NOTNULL, null, '0', 'lid_default_enabled'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026032002, 'local', 'lid');
    }

    // --------------------------------------------------------------------
    // v0.4.0 — Forum Discussion Analyzer / Schema v1.2
    //
    // 1. Add discussion_model to local_lid_forum_config.
    //    Stores the participation model that governs which Critical Discourse
    //    rubric variant the LLM applies when assessing forum discussions.
    //    Default: 'open_engagement' (safest assumption for existing forums).
    //
    // 2. Add scope_thread index to local_lid_analysis for efficient
    //    thread-scope row lookups by discussionid.
    //
    // 3. Update schema_version default on local_lid_analysis from '1.0'
    //    to '1.2' so newly inserted rows carry the correct version tag.
    //    Existing rows are not modified — their schema_version is correct
    //    for the JSON they already contain.
    // --------------------------------------------------------------------
    if ($oldversion < 2026032003) {

        // ---- 1. discussion_model on local_lid_forum_config ----
        $forumconfigtable = new xmldb_table('local_lid_forum_config');

        $discussionmodelfield = new xmldb_field(
            'discussion_model',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'open_engagement',
            'enabled'           // Insert after the 'enabled' column.
        );

        if (!$dbman->field_exists($forumconfigtable, $discussionmodelfield)) {
            $dbman->add_field($forumconfigtable, $discussionmodelfield);
        }

        // ---- 2. scope_thread index on local_lid_analysis ----
        $analysistable = new xmldb_table('local_lid_analysis');

        $threadindex = new xmldb_index(
            'scope_thread',
            XMLDB_INDEX_NOTUNIQUE,
            ['scope', 'discussionid']
        );

        if (!$dbman->index_exists($analysistable, $threadindex)) {
            $dbman->add_index($analysistable, $threadindex);
        }

        // ---- 3. Update schema_version default to '1.2' ----
        // XMLDB requires changing the field definition to update the default.
        // Existing rows keep their current schema_version value unchanged.
        $schemaversionfield = new xmldb_field(
            'schema_version',
            XMLDB_TYPE_CHAR,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '1.2',
            'analysis_json'
        );

        if ($dbman->field_exists($analysistable, $schemaversionfield)) {
            $dbman->change_field_default($analysistable, $schemaversionfield);
        }

        upgrade_plugin_savepoint(true, 2026032003, 'local', 'lid');
    }

    // --------------------------------------------------------------------
    // v0.6.0 — Course Competency Integration
    //
    // 1. Add competencies_enabled to local_lid_settings.
    //    Course-level toggle that enables evaluation against Moodle
    //    course competencies. Default 0 (disabled). Site-level default
    //    is stored in config_plugins (not in this table) so courses can
    //    individually override it.
    //
    // 2. Add competency_ids to local_lid_forum_config.
    //    JSON-encoded array of competency IDs to evaluate for this forum.
    //    Three-state semantics:
    //      null  = inherit course setting (all course competencies)
    //      '[]'  = explicitly exclude all competencies for this forum
    //      '[3,7,12]' = evaluate only these specific competency IDs
    //    Nullable TEXT field; null is the default (inherit).
    // --------------------------------------------------------------------
    if ($oldversion < 2026032801) {

        // ---- 1. competencies_enabled on local_lid_settings ----
        $settingstable = new xmldb_table('local_lid_settings');

        $compenabledfield = new xmldb_field(
            'competencies_enabled',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'lid_force_disabled'  // Insert after lid_force_disabled.
        );

        if (!$dbman->field_exists($settingstable, $compenabledfield)) {
            $dbman->add_field($settingstable, $compenabledfield);
        }

        // ---- 2. competency_ids on local_lid_forum_config ----
        $forumconfigtable = new xmldb_table('local_lid_forum_config');

        $compidsfield = new xmldb_field(
            'competency_ids',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,           // NOTNULL = false (nullable).
            null,
            null,           // Default null.
            'discussion_model'  // Insert after discussion_model.
        );

        if (!$dbman->field_exists($forumconfigtable, $compidsfield)) {
            $dbman->add_field($forumconfigtable, $compidsfield);
        }

        upgrade_plugin_savepoint(true, 2026032801, 'local', 'lid');
    }

    // --------------------------------------------------------------------
    // v0.7.0 — API Call Audit Log + Notification hardening
    //
    // Creates local_lid_call_log: an append-only audit table that records
    // every LLM API call made by the scheduled task. One row is written
    // per processed queue item immediately after the call completes and
    // before the queue item is deleted.
    //
    // Fields captured:
    //   analysisid    — links to the analysis row updated by this call
    //   userid        — assessed learner (null for thread-scope calls)
    //   forumid       — forum context
    //   courseid      — course context
    //   scope         — student_forum | thread
    //   llm_model     — model used as reported by the API response
    //   prompt_hash   — SHA-256 of the prompt sent; preserves prompt
    //                   version at call time even if analysis row is
    //                   later overwritten by a re-assessment
    //   timeclaimed   — when the queue item was claimed (lower bound of
    //                   the API call window for external log correlation)
    //   timeprocessed — when JSON was written (upper bound)
    //   succeeded     — 1 if analysis_json was written; 0 if LLM
    //                   returned empty or invalid JSON
    //
    // Note: the fk_analysisid foreign key automatically generates a
    // backing index on analysisid — no explicit analysisid index is added.
    //
    // This table is not automatically pruned. Retention policy to be
    // determined by site administrators.
    // --------------------------------------------------------------------
    if ($oldversion < 2026040800) {

        $callogtable = new xmldb_table('local_lid_call_log');

        // Only create if it doesn't already exist (safe re-run).
        if (!$dbman->table_exists($callogtable)) {

            $callogtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $callogtable->add_field('analysisid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $callogtable->add_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('llm_model', XMLDB_TYPE_CHAR, '128', null, null, null, null);
            $callogtable->add_field('prompt_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $callogtable->add_field('timeclaimed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('timeprocessed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $callogtable->add_field('succeeded', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

            $callogtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $callogtable->add_key('fk_analysisid', XMLDB_KEY_FOREIGN, ['analysisid'], 'local_lid_analysis', ['id']);

            $callogtable->add_index('userid_forumid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'forumid']);
            $callogtable->add_index('forumid_timeclaimed', XMLDB_INDEX_NOTUNIQUE, ['forumid', 'timeclaimed']);

            $dbman->create_table($callogtable);
        }

        upgrade_plugin_savepoint(true, 2026040800, 'local', 'lid');
    }

    return true;
}
