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
 *     Called when the cron_interval admin setting is saved via set_updatedcallback.
 *     Reads the submitted value directly from $_POST to avoid the timing issue
 *     where get_config() still returns the old value at callback execution time.
 *     Updates the scheduled task cron expression in mdl_task_scheduled.
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
 * Called by Moodle when the cron_interval admin setting is saved.
 *
 * Wired via set_updatedcallback() on the cron_interval admin_setting_configtext
 * in settings.php. Moodle's set_updatedcallback fires BEFORE the new value is
 * committed to config_plugins, so get_config() would return the old value.
 *
 * To avoid this timing issue, the new value is read directly from the raw POST
 * data (key: 's_local_lid_cron_interval'), which holds the submitted value.
 * Falls back to get_config() only when called outside an HTTP POST context
 * (e.g. CLI, tests).
 *
 * Cron expression mapping:
 *   interval = 1     → minute = '*'    (every minute)
 *   interval = 5     → minute = '*/5'  (every 5 minutes)
 *   interval = 60    → minute = '0'    (top of every hour)
 *   interval = 1440  → minute = '0', hour = '0'  (once per day at midnight)
 *   other            → minute = '*/N'  (every N minutes)
 *
 * Sets customised = 1 so Moodle does not reset the schedule on upgrade.
 */
function local_lid_after_config_change(): void {
    global $DB;

    // Read the submitted value from POST if available (avoids get_config timing issue).
    // The admin settings POST key format is: s_<pluginname>_<settingname>
    if (isset($_POST['s_local_lid_cron_interval'])) {
        $interval = (int) $_POST['s_local_lid_cron_interval'];
    } else {
        // Fallback for non-HTTP contexts (CLI, upgrade scripts, tests).
        $interval = (int) get_config('local_lid', 'cron_interval');
    }

    // Clamp to valid range.
    if ($interval < 1) {
        $interval = 1;
    }
    if ($interval > 1440) {
        $interval = 1440;
    }

    // Build cron expressions.
    if ($interval === 1) {
        $minute = '*';
        $hour   = '*';
    } elseif ($interval === 60) {
        $minute = '0';
        $hour   = '*';
    } elseif ($interval === 1440) {
        $minute = '0';
        $hour   = '0';
    } else {
        $minute = '*/' . $interval;
        $hour   = '*';
    }

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

    // Mark as customised so Moodle does not reset the schedule on upgrade.
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
 * xmldb_local_lid_install() in db/install.php.
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
    $record->courseid            = 0;
    $record->llm_endpoint        = '';
    $record->llm_apikey          = '';
    $record->llm_model           = 'gemini-2.5-flash';
    $record->llm_maxtokens       = 16384;
    $record->llm_timeout         = 60;
    $record->prompt_template     = $prompttext;
    $record->prompt_locked       = 0;
    $record->trigger_async       = 1;
    $record->trigger_cron        = 1;
    $record->trigger_manual      = 1;
    $record->cron_interval       = 5;
    $record->cron_batchsize      = 10;
    $record->lid_default_enabled = 0;
    $record->lid_force_disabled  = 0;
    $record->timecreated         = time();
    $record->timemodified        = time();

    $DB->insert_record('local_lid_settings', $record);
}

// =============================================================================
// Forum Edit Settings integration — inject LID toggle + discussion model
// =============================================================================

/**
 * Inject LID settings into the forum activity's Edit Settings form.
 *
 * Adds a "Learning Intelligence Dashboard" section with:
 *   1. Enable/disable toggle (advcheckbox)
 *   2. Discussion model selector (select) — controls which Critical Discourse
 *      rubric variant the LLM applies when assessing forum discussions.
 *
 * Called by Moodle for every activity module — bails immediately for
 * anything that isn't mod_forum.
 *
 * @param moodleform_mod  $formwrapper The mod_edit form wrapper.
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

    // Resolve current config for this forum.
    $config  = $forumid ? $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]) : false;
    $enabled = false;
    $currentmodel = \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;

    if ($config !== false) {
        $enabled      = (bool) $config->enabled;
        $currentmodel = $config->discussion_model
            ?? \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;
    } else {
        // New forum or no config row yet — use site defaults.
        $enabled = (bool) get_config('local_lid', 'lid_default_enabled');
    }

    // Add form section.
    $mform->addElement('header', 'local_lid_section',
        get_string('forum_lid_section', 'local_lid'));

    if ($forcedisabled) {
        // Show a notice — no controls when force-disabled.
        $mform->addElement('static', 'local_lid_force_notice', '',
            html_writer::div(
                get_string('forum_lid_force_disabled_notice', 'local_lid'),
                'alert alert-warning'
            )
        );
    } else {
        // ---- Enable/disable toggle ----
        $mform->addElement('advcheckbox', 'local_lid_enabled',
            get_string('forum_lid_enabled_label', 'local_lid'),
            get_string('forum_lid_enabled_help', 'local_lid'),
            [], [0, 1]
        );
        $mform->setDefault('local_lid_enabled', (int) $enabled);
        $mform->addHelpButton('local_lid_enabled', 'forum_lid_enabled_label', 'local_lid');

        // ---- Discussion model selector ----
        // Build options array from prompt_builder constants.
        $modeldescriptions = \local_lid\llm\prompt_builder::get_model_descriptions();
        $modeloptions      = [];
        foreach ($modeldescriptions as $value => $description) {
            // Use the short label (first sentence before the em-dash).
            $label = get_string('discussion_model_' . $value, 'local_lid');
            $modeloptions[$value] = $label;
        }

        $mform->addElement('select', 'local_lid_discussion_model',
            get_string('forum_config_discussion_model', 'local_lid'),
            $modeloptions
        );
        $mform->setDefault('local_lid_discussion_model', $currentmodel);
        $mform->addHelpButton('local_lid_discussion_model',
            'forum_config_discussion_model', 'local_lid');

        // Add a description paragraph below the selector.
        $mform->addElement('static', 'local_lid_model_desc', '',
            html_writer::tag('p',
                get_string('forum_config_discussion_model_desc', 'local_lid'),
                ['class' => 'form-text text-muted', 'style' => 'font-size:12px']
            )
        );

        // Add the LID guidance notice about when analysis triggers.
        $mform->addElement('static', 'local_lid_trigger_notice', '',
            html_writer::div(
                get_string('dashboard_forum_lid_guidance', 'local_lid'),
                'alert alert-info',
                ['style' => 'font-size:12px;margin-top:8px']
            )
        );
    }

    // Load the forum_config AMD module.
    global $PAGE;
    $PAGE->requires->js_call_amd('local_lid/forum_config', 'init', []);
}

/**
 * Save LID settings after the forum Edit Settings form is submitted.
 *
 * Saves:
 *   - local_lid_enabled     (int 0|1)   → local_lid_forum_config.enabled
 *   - local_lid_discussion_model (string) → local_lid_forum_config.discussion_model
 *
 * Called by Moodle for every activity module — bails for non-forum.
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

    $enabled = isset($data->local_lid_enabled)
        ? (int) (bool) $data->local_lid_enabled
        : 0;

    // Validate discussion_model — reject unknown values, fall back to default.
    $validmodels = [
        \local_lid\llm\prompt_builder::MODEL_INDEPENDENT_FIRST,
        \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT,
        \local_lid\llm\prompt_builder::MODEL_STRUCTURED_DEBATE,
    ];
    $model = isset($data->local_lid_discussion_model)
        && in_array($data->local_lid_discussion_model, $validmodels, true)
        ? $data->local_lid_discussion_model
        : \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;

    $existing = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);

    if ($existing) {
        $DB->update_record('local_lid_forum_config', (object) [
            'id'               => $existing->id,
            'enabled'          => $enabled,
            'discussion_model' => $model,
            'timemodified'     => time(),
        ]);
    } else {
        $DB->insert_record('local_lid_forum_config', (object) [
            'forumid'          => $forumid,
            'courseid'         => $courseid,
            'enabled'          => $enabled,
            'discussion_model' => $model,
            'prompt_override'  => null,
            'timecreated'      => time(),
            'timemodified'     => time(),
        ]);
    }

    return $data;
}

// =============================================================================
// Course administration navigation — course settings page
// =============================================================================

/**
 * Extend course navigation to add the LID dashboard link and course
 * settings link.
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

    // Course LID dashboard.
    $reportsnode = $navigation->find('coursereports', navigation_node::TYPE_CONTAINER);
    $parent      = $reportsnode ?: $navigation;

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
    if (has_capability('local/lid:configureforum', $context)) {
        $settingsurl  = new moodle_url('/local/lid/course_settings.php',
            ['courseid' => $course->id]);
        $adminnode    = $navigation->find('courseadmin', navigation_node::TYPE_COURSE);
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
 * @param stdClass $course
 * @param stdClass $cm
 * @param context  $context
 * @param string   $filearea
 * @param array    $args
 * @param bool     $forcedownload
 * @param array    $options
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
