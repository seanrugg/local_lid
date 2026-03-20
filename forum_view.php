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
 * Forum LID tab page.
 *
 * Entry point: Forum activity → Learning Intelligence tab
 * Injected into forum navigation by local_lid_extend_navigation_module() in lib.php.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lid/lib.php');

// Parameters.
$cmid     = required_param('cmid',     PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Load course module and context.
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'forum');
$context = context_module::instance($cmid);

// Verify the cm belongs to the expected course.
if ((int) $course->id !== (int) $courseid) {
    throw new moodle_exception('invalidcourseid');
}

// Authentication and capability check.
require_login($course, true, $cm);
require_capability('local/lid:viewforumdashboard', $context);

// Set up PAGE.
$url = new moodle_url('/local/lid/forum_view.php', [
    'cmid'     => $cmid,
    'courseid' => $courseid,
]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('dashboard_forum_title', 'local_lid'));
$PAGE->set_heading(format_string($course->fullname));

// Breadcrumbs — mirror the forum's own breadcrumbs then add our tab.
$PAGE->navbar->add(
    format_string($cm->name),
    new moodle_url('/mod/forum/view.php', ['id' => $cmid])
);
$PAGE->navbar->add(get_string('nav_forumdashboard', 'local_lid'));

// Load AMD module with page-specific init.
$PAGE->requires->js_call_amd('local_lid/dashboard', 'initForumPage', [[
    'forumid'    => (int) $cm->instance,
    'courseid'   => (int) $course->id,
    'triggerUrl' => (new moodle_url('/local/lid/ajax.php'))->out(false),
]]);

// Build renderable.
$renderable = new \local_lid\output\forum_lid_page($cm, $course, $context);
$renderer   = $PAGE->get_renderer('local_lid');

// Output.
echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
