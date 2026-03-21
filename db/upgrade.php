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
 *
 * When adding a new upgrade step:
 *   1. Increment $plugin->version in version.php (e.g. 2026032003).
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

    return true;
}
