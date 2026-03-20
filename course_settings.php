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
 * Course-level LID settings page — bulk enable/disable for all forums.
 *
 * Entry point: Course administration → Learning Intelligence settings
 * Injected into course admin navigation by local_lid_extend_navigation_course()
 * in lib.php.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lid/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHANUMEXT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/lid:configureforum', $context);

$url = new moodle_url('/local/lid/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('course_settings_title', 'local_lid'));
$PAGE->set_heading(format_string($course->fullname));

$PAGE->navbar->add(
    get_string('pluginname', 'local_lid'),
    new moodle_url('/local/lid/report.php', ['courseid' => $courseid])
);
$PAGE->navbar->add(get_string('nav_coursesettings', 'local_lid'));

// Handle bulk enable/disable POST actions.
$updated = 0;
if ($action && confirm_sesskey()) {
    $forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');

    if (!$forcedisabled && in_array($action, ['enable_all', 'disable_all'])) {
        $enabled = ($action === 'enable_all') ? 1 : 0;

        // Get all forum course modules in this course.
        $forums = $DB->get_records_sql(
            "SELECT f.id AS forumid
               FROM {forum} f
              WHERE f.course = :courseid",
            ['courseid' => $courseid]
        );

        foreach ($forums as $forum) {
            $forumid = (int) $forum->forumid;
            $existing = $DB->get_record('local_lid_forum_config',
                ['forumid' => $forumid]);

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
            $updated++;
        }

        $successmsg = get_string('course_settings_saved', 'local_lid', $updated);
        redirect($url, $successmsg, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Build forum status table for display.
$forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');

$forums = $DB->get_records_sql(
    "SELECT f.id, f.name,
            cm.id AS cmid,
            COALESCE(lfc.enabled, :sitedefault) AS lid_enabled
       FROM {forum} f
       JOIN {course_modules} cm ON cm.instance = f.id
       JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
  LEFT JOIN {local_lid_forum_config} lfc ON lfc.forumid = f.id
      WHERE f.course = :courseid
   ORDER BY f.name ASC",
    [
        'courseid'    => $courseid,
        'sitedefault' => (int) (bool) get_config('local_lid', 'lid_default_enabled'),
    ]
);

$enabledcount = 0;
$forumrows    = [];
foreach ($forums as $forum) {
    $effective = $forcedisabled ? false : (bool) $forum->lid_enabled;
    if ($effective) {
        $enabledcount++;
    }
    $forumrows[] = [
        'forumid'    => (int) $forum->id,
        'forumname'  => format_string($forum->name),
        'cmid'       => (int) $forum->cmid,
        'enabled'    => $effective,
        'edit_url'   => (new moodle_url('/course/modedit.php', [
            'update'  => $forum->cmid,
            'return'  => 1,
        ]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('course_settings_heading', 'local_lid'));

// Force-disabled notice.
if ($forcedisabled) {
    echo $OUTPUT->notification(
        get_string('course_settings_force_disabled', 'local_lid'),
        \core\output\notification::NOTIFY_WARNING
    );
}

// Summary counts.
echo html_writer::div(
    get_string('course_settings_forums_enabled', 'local_lid', $enabledcount) .
    ' ' .
    get_string('course_settings_forums_total', 'local_lid', count($forumrows)),
    'lid-course-settings-summary',
    ['style' => 'margin-bottom:16px;color:#5a7090;font-size:13px']
);

// Bulk action buttons.
if (!$forcedisabled && has_capability('local/lid:configureforum', $context)) {
    echo html_writer::start_div('lid-course-bulk-actions',
        ['style' => 'display:flex;gap:10px;margin-bottom:24px']);

    // Enable all button.
    $enableurl = new moodle_url($url, ['action' => 'enable_all', 'sesskey' => sesskey()]);
    echo html_writer::link(
        $enableurl,
        get_string('course_settings_enable_all', 'local_lid'),
        ['class' => 'btn btn-primary']
    );

    // Disable all button.
    $disableurl = new moodle_url($url, ['action' => 'disable_all', 'sesskey' => sesskey()]);
    echo html_writer::link(
        $disableurl,
        get_string('course_settings_disable_all', 'local_lid'),
        ['class' => 'btn btn-secondary']
    );

    echo html_writer::end_div();
}

// Forum status table.
if (empty($forumrows)) {
    echo $OUTPUT->notification(
        get_string('dashboard_course_noforums', 'local_lid'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $table = new html_table();
    $table->head = ['Forum', 'LID Status', 'Edit Settings'];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];

    foreach ($forumrows as $row) {
        $statusbadge = $row['enabled']
            ? html_writer::span('Enabled',
                'badge badge-success',
                ['style' => 'background:#00ff9d;color:#000;font-size:11px'])
            : html_writer::span('Disabled',
                'badge badge-secondary',
                ['style' => 'background:#2a3d58;color:#5a7090;font-size:11px']);

        $editlink = html_writer::link(
            $row['edit_url'],
            get_string('edit'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );

        $table->data[] = [
            format_string($row['forumname']),
            $statusbadge,
            $editlink,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
