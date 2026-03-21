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
 * Event observer definitions for local_lid.
 *
 * Moodle reads this file once on cache rebuild (admin/cli/purge_caches.php
 * or Site Administration → Development → Purge all caches) and registers
 * the observers in the events system.
 *
 * Observers defined here:
 *
 *   \mod_forum\event\post_created
 *     Fires when a learner submits a new forum post.
 *     Handler: \local_lid\observer::post_created()
 *     Does NOT queue analysis. Instead:
 *       - Creates a student_forum analysis row for this learner+forum if
 *         one does not already exist (status = 'pending').
 *       - If a completed analysis exists, marks it 'stale' to indicate that
 *         new posts arrived after analysis ran (late-post warning in UI).
 *     Analysis is never triggered during an active discussion.
 *
 *   \mod_forum\event\discussion_locked
 *     Fires when an instructor manually locks a discussion thread.
 *     Handler: \local_lid\observer::discussion_locked()
 *     Checks whether ALL discussions in the forum are now locked.
 *     If yes, the forum is considered closed and queues student_forum and
 *     thread analysis rows for the forum.
 *     If not all threads are locked yet, takes no action — waiting for the
 *     instructor to lock the remaining threads.
 *
 * Events deliberately NOT observed:
 *
 *   \mod_forum\event\post_updated
 *     Removed in v0.4.0. Analysis is retrospective and runs after the
 *     discussion closes. Post edits during an active discussion are captured
 *     at analysis time since the full current post content is fetched then.
 *
 *   \core\event\course_module_updated (show/hide)
 *     Not observed. A hidden forum may be hidden for many reasons unrelated
 *     to discussion closure. Show/hide is not a reliable trigger for analysis.
 *     Instructors are informed via the Forum LID config UI that analysis
 *     requires the forum to be closed by cut-off date, inactivity lock, or
 *     manual discussion lock.
 *
 * Cron-detected triggers (handled in process_queue, not here):
 *   - Forum cut-off date has passed (forum.cutoffdate > 0 AND <= now)
 *   - Inactivity lock threshold crossed for all discussions
 *     (forum.lockdiscussionafter > 0 and last post older than threshold)
 *
 * Note on Moodle 4.5 vs 5.x compatibility:
 *   Both events used here (\mod_forum\event\post_created and
 *   \mod_forum\event\discussion_locked) have been available since Moodle
 *   3.x and are present on both 4.5 LTS and 5.1. The observer pattern
 *   remains fully supported. No version branching is required.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // -------------------------------------------------------------------------
    // Forum post created — stale-flag and student_forum row management.
    // Does NOT queue analysis. See handler docblock for detail.
    // -------------------------------------------------------------------------
    [
        'eventname'   => '\mod_forum\event\post_created',
        'callback'    => '\local_lid\observer::post_created',
        'includefile' => null,   // Class autoloaded from classes/observer.php.
        'internal'    => false,  // Runs even inside a transaction — safe because
                                 // we only write to local_lid_* tables.
        'priority'    => 200,    // Runs after core forum processing.
    ],

    // -------------------------------------------------------------------------
    // Discussion locked — queues analysis when ALL threads are locked.
    // -------------------------------------------------------------------------
    [
        'eventname'   => '\mod_forum\event\discussion_locked',
        'callback'    => '\local_lid\observer::discussion_locked',
        'includefile' => null,
        'internal'    => false,
        'priority'    => 200,
    ],

];
