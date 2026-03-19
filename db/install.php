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
 * Post-install hook for local_lid.
 *
 * Moodle calls xmldb_local_lid_install() immediately after executing
 * db/install.xml (table creation) on a fresh plugin install. It is NOT
 * called on upgrades — migration logic lives in db/upgrade.php instead.
 *
 * What this does:
 *   1. Seeds the site-level settings row in local_lid_settings (courseid = 0)
 *      with the default prompt from prompts/default-session-analyzer.md and
 *      sensible defaults for all other columns.
 *
 * If this function throws an exception, Moodle will roll back the install
 * and report the error to the administrator.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute post-install actions for local_lid.
 *
 * @return bool True on success.
 */
function xmldb_local_lid_install(): bool {

    // lib.php is not auto-included during install, so require it explicitly.
    global $CFG;
    require_once($CFG->dirroot . '/local/lid/lib.php');

    local_lid_seed_default_prompt();

    return true;
}
