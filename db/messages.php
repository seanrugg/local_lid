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
 * Message type definitions for local_lid.
 *
 * Registers the message providers used by this plugin with Moodle's
 * core messaging system. This enables message_send() to route notifications
 * to each user's preferred channel (Moodle inbox, email, or both) based
 * on their personal notification preferences.
 *
 * Moodle reads this file during plugin installation and on admin notification
 * type reset (Site Administration → Messaging → Notification preferences).
 *
 * Message providers defined here:
 *
 *   analysis_complete
 *     Sent to instructors (users with local/lid:viewforumdashboard) when
 *     LID analysis for a forum completes and all learner analyses are ready
 *     to view. Routed via message_send() in process_queue::maybe_notify_completion().
 *
 *     Default preferences:
 *       loggedin  = MESSAGE_PERMITTED (enabled when logged in)
 *       loggedoff = MESSAGE_PERMITTED (enabled when logged out / email)
 *     Both default to permitted so instructors receive notifications out of
 *     the box. Individual users can disable them in their notification prefs.
 *
 * String keys for UI labels (defined in lang/en/local_lid.php):
 *   messageprovider:analysis_complete — shown in the notification prefs UI
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [

    // -------------------------------------------------------------------------
    // analysis_complete
    // Sent to instructors when all learner analyses for a forum are ready.
    // -------------------------------------------------------------------------
    'analysis_complete' => [
        'defaults' => [
            MESSAGE_DEFAULT_LOGGEDIN  => MESSAGE_PERMITTED,
            MESSAGE_DEFAULT_LOGGEDOFF => MESSAGE_PERMITTED,
        ],
        'capability' => 'local/lid:viewforumdashboard',
    ],

];
