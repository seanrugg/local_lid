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
 * Student LID profile tab page.
 *
 * Entry point: Course → Participants → [Student] → Learning Intelligence tab
 * Injected by local_lid_extend_navigation_user_settings() in lib.php.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lid/lib.php');

// Parameters.
$userid   = required_param('userid',   PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Load course and context.
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Load student.
$student = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

// Authentication and capability checks.
require_login($course);
require_capability('local/lid:viewstudentdashboard', $context);

// Prevent teachers from using this URL to view another teacher's profile.
// The student must be enrolled in the course in a non-editing role, or the
// viewer must have the capability regardless (managers, admins).
if (!is_enrolled($context, $student, '', true)) {
    // User is not actively enrolled — only allow if viewer has manage capability.
    if (!has_capability('moodle/course:manageactivities', $context)) {
        throw new moodle_exception('notenrolled', 'local_lid');
    }
}

// Set up PAGE.
$url = new moodle_url('/local/lid/student_view.php', [
    'userid'   => $userid,
    'courseid' => $courseid,
]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('dashboard_student_title', 'local_lid'));
$PAGE->set_heading(format_string($course->fullname));

// Breadcrumbs.
$PAGE->navbar->add(
    get_string('participants'),
    new moodle_url('/user/index.php', ['id' => $courseid])
);
$PAGE->navbar->add(
    fullname($student),
    new moodle_url('/user/view.php', ['id' => $userid, 'course' => $courseid])
);
$PAGE->navbar->add(get_string('nav_studentdashboard', 'local_lid'));

// Load AMD module with page-specific init.
$PAGE->requires->js_call_amd('local_lid/dashboard', 'initStudentPage', [[
    'userid'   => (int) $userid,
    'courseid' => (int) $courseid,
]]);

// Build renderable.
$renderable = new \local_lid\output\student_lid_page($student, $course, $context);
$renderer   = $PAGE->get_renderer('local_lid');

// Output.
echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
