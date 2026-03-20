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
 * Course LID report page.
 *
 * Entry point: Course → Reports → Learning Intelligence Dashboard
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lid/lib.php');

// Parameters.
$courseid = required_param('courseid', PARAM_INT);

// Load course and context.
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Authentication and capability check.
require_login($course);
require_capability('local/lid:viewcoursedashboard', $context);

// Set up PAGE.
$url = new moodle_url('/local/lid/report.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('dashboard_course_title', 'local_lid'));
$PAGE->set_heading(format_string($course->fullname));

// Breadcrumbs.
$PAGE->navbar->add(
    get_string('reports'),
    new moodle_url('/course/report.php', ['id' => $courseid])
);
$PAGE->navbar->add(get_string('pluginname', 'local_lid'));

// Load AMD module with page-specific init.
$PAGE->requires->js_call_amd('local_lid/dashboard', 'initCoursePage', [[
    'courseid'   => (int) $courseid,
    'triggerUrl' => (new moodle_url('/local/lid/ajax.php'))->out(false),
]]);

// Build renderable.
$renderable = new \local_lid\output\course_lid_page($course, $context);
$renderer   = $PAGE->get_renderer('local_lid');

// Output.
echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
