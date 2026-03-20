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
 *   1 min  → every minute
 *   5 min  → every 5 minutes
 *   15 min → every 15 minutes
 *   60 min → top of every hour
 *   1440   → midnight daily
 *   other  → every N minutes where N = interval value
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
    $record->lid_default_enabled = 0;
    $record->lid_force_disabled  = 0;
    $record->timecreated     = time();
    $record->timemodified    = time();

    $DB->insert_record('local_lid_settings', $record);
}

// =============================================================================
// Forum Edit Settings integration — inject LID toggle
// =============================================================================

/**
 * Inject the LID enable/disable toggle into the forum activity's Edit Settings
 * form (mod_edit.php). Called by Moodle for every activity module — we bail
 * immediately for anything that isn't mod_forum.
 *
 * The toggle appears as a new section "Learning Intelligence Dashboard" at the
 * bottom of the forum settings form, above the Save buttons.
 *
 * @param moodleform_mod $formwrapper  The mod_edit form wrapper.
 * @param MoodleQuickForm $mform       The underlying HTML_QuickForm object.
 */
function local_lid_coursemodule_standard_elements($formwrapper, $mform): void {
    global $DB;

    // Only apply to forum modules.
    $current = $formwrapper->get_current();
    if (empty($current->modulename) || $current->modulename !== 'forum') {
        return;
    }

    $courseid = $formwrapper->get_course()->id;
    $cmid     = $current->coursemodule ?? 0;
    $forumid  = $current->instance    ?? 0;

    // Check capability — only users who can configure LID see this section.
    $context = $cmid
        ? context_module::instance($cmid)
        : context_course::instance($courseid);

    if (!has_capability('local/lid:configureforum', $context)) {
        return;
    }

    // Is LID force-disabled site-wide?
    $forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');

    // Resolve current enabled state for this forum.
    $enabled = false;
    if ($forumid) {
        $config = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);
        if ($config !== false) {
            $enabled = (bool) $config->enabled;
        } else {
            // No row yet — use site default.
            $enabled = (bool) get_config('local_lid', 'lid_default_enabled');
        }
    } else {
        // New forum being created — use site default.
        $enabled = (bool) get_config('local_lid', 'lid_default_enabled');
    }

    // Add form section.
    $mform->addElement('header', 'local_lid_section',
        get_string('forum_lid_section', 'local_lid'));

    if ($forcedisabled) {
        // Show a notice — no toggle when force-disabled.
        $mform->addElement('static', 'local_lid_force_notice', '',
            html_writer::div(
                get_string('forum_lid_force_disabled_notice', 'local_lid'),
                'alert alert-warning'
            )
        );
    } else {
        $mform->addElement('advcheckbox', 'local_lid_enabled',
            get_string('forum_lid_enabled_label', 'local_lid'),
            get_string('forum_lid_enabled_help', 'local_lid'),
            [], [0, 1]
        );
        $mform->setDefault('local_lid_enabled', (int) $enabled);
        $mform->addHelpButton('local_lid_enabled', 'forum_lid_enabled_label', 'local_lid');
    }
}

/**
 * Save the LID enable/disable state after a forum Edit Settings form is
 * submitted. Called by Moodle for every activity module — bail for non-forum.
 *
 * Creates or updates the local_lid_forum_config row for this forum.
 *
 * @param stdClass $data   The submitted form data object.
 * @param stdClass $course The course record.
 * @return stdClass        The (possibly modified) data object.
 */
function local_lid_coursemodule_edit_post_actions($data, $course): stdClass {
    global $DB;

    // Only act on forum modules.
    if (empty($data->modulename) || $data->modulename !== 'forum') {
        return $data;
    }

    // Force-disabled site-wide — do not save any forum-level state change.
    if ((bool) get_config('local_lid', 'lid_force_disabled')) {
        return $data;
    }

    $forumid  = (int) ($data->instance ?? 0);
    $courseid = (int) $course->id;

    if (!$forumid) {
        return $data;
    }

    $enabled = isset($data->local_lid_enabled) ? (int) (bool) $data->local_lid_enabled : 0;

    $existing = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);

    if ($existing) {
        $DB->update_record('local_lid_forum_config', (object) [
            'id'           => $existing->id,
            'enabled'      => $enabled,
            'timemodified' => time(),
        ]);
    } else {
        $DB->insert_record('local_lid_forum_config', (object) [
            'forumid'         => $forumid,
            'courseid'        => $courseid,
            'enabled'         => $enabled,
            'prompt_override' => null,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    return $data;
}

// =============================================================================
// Course administration navigation — course settings page
// =============================================================================

/**
 * Extend course navigation to add the LID dashboard link and course
 * settings link. Works with both classic themes (left-nav courseadmin block)
 * and Moodle 4.x/5.x Boost theme (secondary nav tabs / More menu).
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_lid_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {

    if (!has_capability('local/lid:viewcoursedashboard', $context)) {
        return;
    }

    // Course LID dashboard — add under Reports if that node exists,
    // otherwise fall back to the top-level navigation node.
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

    // Course LID settings (bulk enable/disable per forum).
    // Classic themes: add under the courseadmin node (left nav block).
    // Boost/modern themes: courseadmin doesn't exist — add directly to the
    // navigation node, which places it in the More menu tab.
    if (has_capability('local/lid:configureforum', $context)) {
        $settingsurl = new moodle_url('/local/lid/course_settings.php',
            ['courseid' => $course->id]);

        $adminnode = $navigation->find('courseadmin', navigation_node::TYPE_COURSE);
        $settingsparent = $adminnode ?: $navigation;

        $settingsparent->add(
            get_string('nav_coursesettings', 'local_lid'),
            $settingsurl,
            navigation_node::TYPE_SETTING,
            null,
            'local_lid_course_settings',
            new pix_icon('icon', get_string('pluginname', 'local_lid'), 'local_lid')
        );
    }
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
