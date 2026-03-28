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
 * Course-level LID settings page — bulk enable/disable, competency toggle,
 * and read-only prompt display.
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
$PAGE->requires->js_call_amd('local_lid/prompt_editor', 'init', []);

$PAGE->navbar->add(
    get_string('pluginname', 'local_lid'),
    new moodle_url('/local/lid/report.php', ['courseid' => $courseid])
);
$PAGE->navbar->add(get_string('nav_coursesettings', 'local_lid'));

// =========================================================================
// Handle POST actions
// =========================================================================

$updated = 0;
if ($action && confirm_sesskey()) {
    $forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');

    if (!$forcedisabled && in_array($action, ['enable_all', 'disable_all'])) {
        $enabled = ($action === 'enable_all') ? 1 : 0;

        $forums = $DB->get_records_sql(
            "SELECT f.id AS forumid
               FROM {forum} f
              WHERE f.course = :courseid",
            ['courseid' => $courseid]
        );

        foreach ($forums as $forum) {
            $forumid  = (int) $forum->forumid;
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
            $updated++;
        }

        $successmsg = get_string('course_settings_saved', 'local_lid', $updated);
        redirect($url, $successmsg, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Handle competency toggle POST.
    if ($action === 'save_competencies') {
        $compenabled = required_param('competencies_enabled', PARAM_INT);
        $compenabled = (int) (bool) $compenabled;

        $existing = $DB->get_record('local_lid_settings', ['courseid' => $courseid]);

        if ($existing) {
            $DB->update_record('local_lid_settings', (object) [
                'id'                   => $existing->id,
                'competencies_enabled' => $compenabled,
                'timemodified'         => time(),
            ]);
        } else {
            $DB->insert_record('local_lid_settings', (object) [
                'courseid'             => $courseid,
                'competencies_enabled' => $compenabled,
                'prompt_locked'        => 0,
                'trigger_async'        => 1,
                'trigger_cron'         => 1,
                'trigger_manual'       => 1,
                'cron_interval'        => 5,
                'cron_batchsize'       => 10,
                'timecreated'          => time(),
                'timemodified'         => time(),
            ]);
        }

        $successmsg = get_string('competency_course_saved', 'local_lid');
        redirect($url, $successmsg, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// =========================================================================
// Build page data
// =========================================================================

$forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');

// Include discussion_model from forum config so the table can display it.
$forums = $DB->get_records_sql(
    "SELECT f.id, f.name,
            cm.id AS cmid,
            COALESCE(lfc.enabled, :sitedefault) AS lid_enabled,
            COALESCE(lfc.discussion_model, 'open_engagement') AS discussion_model
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

// Human-readable labels for the three discussion model values.
$modellabels = [
    'independent_first'  => get_string('discussion_model_independent_first',  'local_lid'),
    'open_engagement'    => get_string('discussion_model_open_engagement',     'local_lid'),
    'structured_debate'  => get_string('discussion_model_structured_debate',   'local_lid'),
];

$enabledcount = 0;
$forumrows    = [];
foreach ($forums as $forum) {
    $effective = $forcedisabled ? false : (bool) $forum->lid_enabled;
    if ($effective) {
        $enabledcount++;
    }
    $forumrows[] = [
        'forumid'          => (int) $forum->id,
        'forumname'        => format_string($forum->name),
        'cmid'             => (int) $forum->cmid,
        'enabled'          => $effective,
        'discussion_model' => $forum->discussion_model,
        'edit_url'         => (new moodle_url('/course/modedit.php', [
            'update' => $forum->cmid,
            'return' => 1,
        ]))->out(false),
    ];
}

// =========================================================================
// Competency state
// =========================================================================

$competencysubsystemenabled = false;
try {
    $competencysubsystemenabled = \core_competency\api::is_enabled();
} catch (\Throwable $e) {
    // Competency classes may not exist.
}

$coursecompetencies = [];
if ($competencysubsystemenabled) {
    try {
        $pairs = \core_competency\api::list_course_competencies($courseid);
        foreach ($pairs as $pair) {
            if (isset($pair['competency']) && $pair['competency'] instanceof \core_competency\competency) {
                $coursecompetencies[] = $pair['competency'];
            }
        }
    } catch (\Throwable $e) {
        // No framework linked, etc.
    }
}

// Current competencies_enabled state for this course.
$coursesettings    = $DB->get_record('local_lid_settings', ['courseid' => $courseid]);
$compenabled       = $coursesettings
    ? (bool) $coursesettings->competencies_enabled
    : (bool) get_config('local_lid', 'competencies_enabled_default');

// =========================================================================
// Prompt display (read-only)
// =========================================================================

$builder        = new \local_lid\llm\prompt_builder($courseid);
$activetemplate = $builder->get_active_template();
$forumanalyzer  = $builder->get_forum_analyzer_template();

// =========================================================================
// Render
// =========================================================================

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

    $enableurl  = new moodle_url($url, ['action' => 'enable_all',  'sesskey' => sesskey()]);
    $disableurl = new moodle_url($url, ['action' => 'disable_all', 'sesskey' => sesskey()]);

    echo html_writer::link($enableurl,
        get_string('course_settings_enable_all',  'local_lid'),
        ['class' => 'btn btn-primary']);

    echo html_writer::link($disableurl,
        get_string('course_settings_disable_all', 'local_lid'),
        ['class' => 'btn btn-secondary']);

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
    $table->head = [
        get_string('forum', 'forum'),
        get_string('forum_config_enabled',          'local_lid'),
        get_string('forum_config_discussion_model', 'local_lid'),
        get_string('edit'),
    ];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];

    foreach ($forumrows as $row) {
        $statusbadge = $row['enabled']
            ? html_writer::span(
                get_string('status_complete', 'local_lid'),
                'badge badge-success',
                ['style' => 'background:#00ff9d;color:#000;font-size:11px'])
            : html_writer::span(
                get_string('status_disabled', 'local_lid'),
                'badge badge-secondary',
                ['style' => 'background:#2a3d58;color:#5a7090;font-size:11px']);

        $modellabel = $row['enabled']
            ? html_writer::span(
                $modellabels[$row['discussion_model']] ?? $row['discussion_model'],
                'lid-mono',
                ['style' => 'font-size:11px;color:#5a7090'])
            : html_writer::span('—', '', ['style' => 'color:#2a3d58']);

        $editlink = html_writer::link(
            $row['edit_url'],
            get_string('edit'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );

        $table->data[] = [
            format_string($row['forumname']),
            $statusbadge,
            $modellabel,
            $editlink,
        ];
    }

    echo html_writer::table($table);
}

// =========================================================================
// Competency evaluation section
// =========================================================================

echo html_writer::tag('h3',
    get_string('competency_course_heading', 'local_lid'),
    ['style' => 'margin-top:32px;margin-bottom:16px']
);

if (!$competencysubsystemenabled) {
    // Moodle competencies not enabled at site level.
    echo $OUTPUT->notification(
        get_string('competency_course_site_disabled', 'local_lid'),
        \core\output\notification::NOTIFY_INFO
    );
} else if (empty($coursecompetencies)) {
    // Competencies enabled but none linked to this course.
    echo $OUTPUT->notification(
        get_string('competency_course_no_competencies', 'local_lid'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    // Show toggle and competency count.
    echo html_writer::div(
        get_string('competency_course_count', 'local_lid', count($coursecompetencies)),
        '',
        ['style' => 'margin-bottom:12px;color:#5a7090;font-size:13px']
    );

    // Competency toggle form.
    $toggleurl = new moodle_url($url, [
        'action'  => 'save_competencies',
        'sesskey' => sesskey(),
    ]);

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $toggleurl->out(false),
        'style'  => 'display:flex;align-items:center;gap:12px;margin-bottom:16px',
    ]);

    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'sesskey',
        'value' => sesskey(),
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'action',
        'value' => 'save_competencies',
    ]);

    echo html_writer::start_tag('label', [
        'style' => 'display:flex;align-items:center;gap:8px;cursor:pointer',
    ]);

    $checkedattr = $compenabled ? ['checked' => 'checked'] : [];
    echo html_writer::empty_tag('input', array_merge([
        'type'  => 'checkbox',
        'name'  => 'competencies_enabled',
        'value' => '1',
    ], $checkedattr));

    // Hidden field to ensure 0 is sent when checkbox unchecked.
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'competencies_enabled',
        'value' => '0',
    ]);

    echo get_string('competency_course_enabled', 'local_lid');
    echo html_writer::end_tag('label');

    echo html_writer::tag('button',
        get_string('savechanges'),
        ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']
    );

    echo html_writer::end_tag('form');

    echo html_writer::div(
        get_string('competency_course_enabled_desc', 'local_lid'),
        '',
        ['style' => 'color:#5a7090;font-size:12px;margin-bottom:16px;max-width:700px']
    );

    // List the competencies so the instructor knows what will be evaluated.
    if ($compenabled) {
        echo html_writer::start_tag('ul', [
            'style' => 'margin-bottom:16px;padding-left:20px;color:#5a7090;font-size:13px',
        ]);
        foreach ($coursecompetencies as $comp) {
            $name = format_string($comp->get('shortname'));
            $desc = format_string(strip_tags($comp->get('description')));
            $text = $name;
            if (!empty($desc)) {
                $text .= ' — ' . $desc;
            }
            echo html_writer::tag('li', $text);
        }
        echo html_writer::end_tag('ul');
    }
}

// =========================================================================
// Read-only prompt display
// =========================================================================

echo html_writer::tag('h3',
    get_string('course_settings_prompt_heading', 'local_lid'),
    ['style' => 'margin-top:32px;margin-bottom:8px']
);

echo html_writer::div(
    get_string('course_settings_prompt_readonly_desc', 'local_lid'),
    '',
    ['style' => 'color:#5a7090;font-size:12px;margin-bottom:16px;max-width:700px']
);

// Show the forum discussion analyzer prompt (the fixed assessment instrument).
if (!empty($forumanalyzer)) {
    echo html_writer::tag('h4', 'Forum Discussion Analyzer (fixed)', [
        'style' => 'font-size:14px;color:#5a7090;margin-bottom:8px',
    ]);
    echo html_writer::tag('textarea', htmlspecialchars($forumanalyzer), [
        'class'    => 'form-control',
        'rows'     => '12',
        'readonly' => 'readonly',
        'style'    => 'font-family:monospace;font-size:12px;background:#f8f9fa;'
            . 'color:#495057;resize:vertical;max-width:900px;margin-bottom:24px',
    ]);
}

echo $OUTPUT->footer();
