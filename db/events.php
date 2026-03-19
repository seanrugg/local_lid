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
 *     Fires when a student or teacher submits a new forum post.
 *     Handler: \local_lid\observer::post_created()
 *     Checks whether the forum has LID enabled and, if so, creates a
 *     local_lid_analysis record (status = pending) and enqueues a job
 *     in local_lid_queue according to the active trigger mode.
 *
 *   \mod_forum\event\post_updated
 *     Fires when an existing post is edited.
 *     Handler: \local_lid\observer::post_updated()
 *     If a completed analysis exists for this post, marks it stale and
 *     re-enqueues it so the analysis reflects the latest post content.
 *     Respects the same trigger-mode logic as post_created.
 *
 * Note on Moodle 4.5 vs 5.x compatibility:
 *   The \mod_forum\event\post_created event has been present since Moodle 3.x
 *   and is available on both 4.5 LTS and 5.1. The Moodle 5.x hooks API
 *   (\core\hook\*) is an additional mechanism — the observer pattern used here
 *   remains fully supported. No version branching is required for these events.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // -------------------------------------------------------------------------
    // Forum post created
    // -------------------------------------------------------------------------
    [
        'eventname'    => '\mod_forum\event\post_created',
        'callback'     => '\local_lid\observer::post_created',
        'includefile'  => null,   // Class is autoloaded from classes/observer.php.
        'internal'     => false,  // false = runs even inside a transaction (safe here
                                  // because we only write to local_lid_* tables, not
                                  // forum tables).
        'priority'     => 200,    // Lower number = earlier execution. 200 is a safe
                                  // default that runs after core forum processing.
    ],

    // -------------------------------------------------------------------------
    // Forum post updated (edited)
    // -------------------------------------------------------------------------
    [
        'eventname'    => '\mod_forum\event\post_updated',
        'callback'     => '\local_lid\observer::post_updated',
        'includefile'  => null,
        'internal'     => false,
        'priority'     => 200,
    ],

];
