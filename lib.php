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
 * lib.php — core plugin callbacks for local_lid.
 *
 * Functions in this file are called by Moodle's plugin framework directly.
 * They must follow the naming convention local_lid_<hookname>().
 *
 * Callbacks implemented here:
 *
 *   local_lid_extend_navigation_course()
 *     Adds the "Learning Intelligence" link to the course Reports navigation
 *     node for users with local/lid:viewcoursedashboard.
 *
 *   local_lid_extend_navigation_module()
 *     Adds the "Learning Intelligence" tab to forum activity navigation for
 *     users with local/lid:viewforumdashboard. Only fires for mod_forum.
 *
 *   local_lid_extend_navigation_user_settings()
 *     Adds the "Learning Intelligence" tab to a student's course profile for
 *     users with local/lid:viewstudentdashboard.
 *
 *   local_lid_after_config_change()
 *     Called when any local_lid setting is saved. Updates the scheduled task
 *     cron expression when cron_interval changes.
 *
 *   local_lid_pluginfile()
 *     Required stub — this plugin does not serve files but Moodle expects
 *     the function to exist.
 *
 * Install-time seeding:
 *   local_lid_seed_default_prompt() is called from the install/upgrade path
 *   in db/upgrade.php on first install (version 2026032000) to write the
 *   default prompt from prompts/default-session-analyzer.md into
 *   local_lid_settings (courseid = 0).
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// =============================================================================
// Navigation — Course Reports tab
// =============================================================================

/**
 * Extend course navigation with a Learning Intelligence link in the Reports
 * section.
 *
 * Fires for every course page load; bails early if the current user does not
 * hold local/lid:viewcoursedashboard in this course context.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass        $course     The current course record.
 * @param context_course  $context    The course context.
 */
function local_lid_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {

    if (!has_capability('local/lid:viewcoursedashboard', $context)) {
        return;
    }

    // Find the Reports node; if missing (some themes remove it) append to course root.
    $reportsnode = $navigation->find('coursereports', navigation_node::TYPE_CONTAINER);
    $parent = $reportsnode ?: $navigation;

    $url = new moodle_url('/local/lid/report.php', ['courseid' => $course->id]);

    $parent->add(
        get_string('nav_coursedashboard', 'local_lid'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_lid_course',
        new pix_icon('icon', get_string('pluginname', 'local_lid'), 'local_lid')
    );
}

// =============================================================================
// Navigation — Forum activity tab
// =============================================================================

/**
 * Extend module navigation with a Learning Intelligence tab on forum activities.
 *
 * Only adds the tab when:
 *   - The current module is mod_forum.
 *   - The user holds local/lid:viewforumdashboard in this module context.
 *   - LID is enabled for this specific forum (local_lid_forum_config.enabled = 1).
 *
 * @param navigation_node  $navigation The module navigation node.
 * @param stdClass         $course     The current course record.
 * @param stdClass         $cm         The course module record.
 * @param context_module   $context    The module context.
 */
function local_lid_extend_navigation_module(
    navigation_node $navigation,
    stdClass $course,
    stdClass $cm,
    context_module $context
): void {
    global $DB;

    // Only apply to forum modules.
    if ($cm->modname !== 'forum') {
        return;
    }

    if (!has_capability('local/lid:viewforumdashboard', $context)) {
        return;
    }

    // Check LID is enabled for this specific forum.
    $enabled = $DB->get_field(
        'local_lid_forum_config',
        'enabled',
        ['forumid' => $cm->instance]
    );

    if (!$enabled) {
        return;
    }

    $url = new moodle_url('/local/lid/forum_view.php', [
        'cmid'     => $cm->id,
        'courseid' => $course->id,
    ]);

    $navigation->add(
        get_string('nav_forumdashboard', 'local_lid'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_lid_forum',
        new pix_icon('icon', get_string('pluginname', 'local_lid'), 'local_lid')
    );
}

// =============================================================================
// Navigation — Student profile tab (course context)
// =============================================================================

/**
 * Extend user settings navigation with a Learning Intelligence tab when
 * viewing a student's profile in a course context.
 *
 * Only adds the tab when the viewing user holds
 * local/lid:viewstudentdashboard in the current course context. The tab
 * is not shown to students viewing their own profile.
 *
 * @param navigation_node $navigation The user settings navigation node.
 * @param stdClass        $user       The user whose profile is being viewed.
 * @param context_course  $context    The course context.
 * @param stdClass        $course     The current course record.
 */
function local_lid_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    context $context,
    stdClass $course
): void {
    global $USER;

    // Only act in course context.
    if (!($context instanceof context_course)) {
        return;
    }

    // Do not show to students viewing their own profile.
    if ($user->id === $USER->id) {
        return;
    }

    if (!has_capability('local/lid:viewstudentdashboard', $context)) {
        return;
    }

    $url = new moodle_url('/local/lid/student_view.php', [
        'userid'   => $user->id,
        'courseid' => $course->id,
    ]);

    $navigation->add(
        get_string('nav_studentdashboard', 'local_lid'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_lid_student',
        new pix_icon('icon', get_string('pluginname', 'local_lid'), 'local_lid')
    );
}

// =============================================================================
// Config change callback — update scheduled task cron expression
// =============================================================================

/**
 * Called by Moodle after any local_lid plugin setting is saved via the
 * admin settings page.
 *
 * When cron_interval changes, recalculates the cron expression for the
 * \local_lid\task\process_queue scheduled task and writes it to the
 * task_scheduled table so the new interval takes effect without manual
 * admin intervention.
 *
 * Interval mapping:
 *   1 min  → "* * * * *"   (every minute)
 *   5 min  → "*/5 * * * *"
 *   15 min → "*/15 * * * *"
 *   60 min → "0 * * * *"   (top of every hour)
 *   1440   → "0 0 * * *"   (midnight daily)
 *   other  → "*/N * * * *" where N = interval value
 */
function local_lid_after_config_change(): void {
    global $DB;

    $interval = (int) get_config('local_lid', 'cron_interval');

    if ($interval < 1) {
        $interval = 1;
    }
    if ($interval > 1440) {
        $interval = 1440;
    }

    // Build cron minute expression.
    if ($interval === 1) {
        $minute = '*';
    } elseif ($interval === 60) {
        $minute = '0';
    } elseif ($interval === 1440) {
        $minute = '0';
    } else {
        $minute = '*/' . $interval;
    }

    $hour = ($interval === 1440) ? '0' : '*';

    $DB->set_field(
        'task_scheduled',
        'minute',
        $minute,
        ['classname' => '\local_lid\task\process_queue']
    );

    $DB->set_field(
        'task_scheduled',
        'hour',
        $hour,
        ['classname' => '\local_lid\task\process_queue']
    );

    // Mark the task as customised so Moodle does not reset it on upgrade.
    $DB->set_field(
        'task_scheduled',
        'customised',
        1,
        ['classname' => '\local_lid\task\process_queue']
    );
}

// =============================================================================
// Install-time prompt seeding
// =============================================================================

/**
 * Seed the default prompt template into local_lid_settings on first install.
 *
 * Reads prompts/default-session-analyzer.md from the plugin directory and
 * writes it to the site-level settings row (courseid = 0). Called once from
 * xmldb_local_lid_install() which is defined in db/install.php (to be created).
 *
 * Safe to call multiple times — checks for the existence of the site-level
 * row before inserting.
 */
function local_lid_seed_default_prompt(): void {
    global $DB;

    // If a site-level row already exists, do not overwrite it.
    if ($DB->record_exists('local_lid_settings', ['courseid' => 0])) {
        return;
    }

    $promptfile = __DIR__ . '/prompts/default-session-analyzer.md';
    $prompttext = file_exists($promptfile) ? file_get_contents($promptfile) : '';

    $record = new stdClass();
    $record->courseid        = 0;
    $record->llm_endpoint    = '';
    $record->llm_apikey      = '';
    $record->llm_model       = 'claude-sonnet-4-6';
    $record->llm_maxtokens   = 4096;
    $record->llm_timeout     = 60;
    $record->prompt_template = $prompttext;
    $record->prompt_locked   = 0;
    $record->trigger_async   = 1;
    $record->trigger_cron    = 1;
    $record->trigger_manual  = 1;
    $record->cron_interval   = 5;
    $record->cron_batchsize  = 10;
    $record->timecreated     = time();
    $record->timemodified    = time();

    $DB->insert_record('local_lid_settings', $record);
}

// =============================================================================
// Pluginfile stub
// =============================================================================

/**
 * Serve plugin files.
 *
 * local_lid does not currently serve any user-uploaded files, but Moodle
 * requires this function to exist. Returns false for all requests.
 *
 * @param stdClass $course        Course object.
 * @param stdClass $cm            Course module object (null if not module context).
 * @param context  $context       Context object.
 * @param string   $filearea      File area name.
 * @param array    $args          Extra arguments.
 * @param bool     $forcedownload Force download flag.
 * @param array    $options       Additional options.
 * @return false
 */
function local_lid_pluginfile(
    stdClass $course,
    ?stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    return false;
}
