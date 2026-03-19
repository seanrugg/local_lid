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
 *
 * When adding a new upgrade step:
 *   1. Increment $plugin->version in version.php (e.g. 2026032001).
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
    // Example upgrade step template — copy and adapt for future migrations.
    // --------------------------------------------------------------------
    // if ($oldversion < 2026040100) {
    //
    //     // Add a new column to local_lid_analysis.
    //     $table = new xmldb_table('local_lid_analysis');
    //     $field = new xmldb_field('newcolumn', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'timemodified');
    //
    //     if (!$dbman->field_exists($table, $field)) {
    //         $dbman->add_field($table, $field);
    //     }
    //
    //     upgrade_plugin_savepoint(true, 2026040100, 'local', 'lid');
    // }
    // --------------------------------------------------------------------

    return true;
}
