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
 * Capability definitions for the local_lid plugin.
 *
 * Capability hierarchy:
 *
 *   local/lid:managesitesettings   — Site Administrator only.
 *                                    Configure LLM endpoint, API key, default prompt, prompt lock.
 *
 *   local/lid:viewcoursedashboard  — Teacher / Manager / Course Creator.
 *                                    View the Course LID in the Reports tab.
 *
 *   local/lid:viewforumdashboard   — Teacher / Manager / Course Creator / Student.
 *                                    View the Forum LID tab on a forum activity.
 *                                    Students see the forum aggregate and their own analysis
 *                                    tab only — peer analysis tabs require viewpeeranalysis.
 *
 *   local/lid:viewpeeranalysis     — Teacher / Manager / Course Creator.
 *                                    View other students' individual LID analysis tabs on
 *                                    the forum dashboard. Without this capability a user can
 *                                    only see the forum aggregate and their own analysis tab.
 *                                    Checked at CONTEXT_MODULE so it can be overridden per
 *                                    forum activity via Moodle's standard permissions UI.
 *
 *   local/lid:viewstudentdashboard — Teacher / Manager / Course Creator.
 *                                    View the Student LID tab on a student's course profile.
 *
 *   local/lid:configureforum       — Teacher / Manager / Course Creator.
 *                                    Enable or disable LID analysis per forum activity.
 *
 *   local/lid:editprompt           — Teacher / Manager / Course Creator.
 *                                    Edit the prompt template at course or forum level.
 *                                    This capability is functionally overridden when
 *                                    prompt_locked = 1 in local_lid_settings — in that
 *                                    case the prompt editor renders read-only regardless
 *                                    of this capability.
 *
 *   local/lid:triggeranalysis      — Teacher / Manager.
 *                                    Manually trigger or re-trigger LLM analysis for a
 *                                    forum activity or individual post.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // -------------------------------------------------------------------------
    // Site administration
    // -------------------------------------------------------------------------

    'local/lid:managesitesettings' => [
        'captype'       => 'write',
        'contextlevel'  => CONTEXT_SYSTEM,
        'archetypes'    => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // -------------------------------------------------------------------------
    // Dashboard viewing
    // -------------------------------------------------------------------------

    'local/lid:viewcoursedashboard' => [
        'captype'       => 'read',
        'contextlevel'  => CONTEXT_COURSE,
        'archetypes'    => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/lid:viewforumdashboard' => [
        'captype'       => 'read',
        'contextlevel'  => CONTEXT_MODULE,
        'archetypes'    => [
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/lid:viewpeeranalysis' => [
        'captype'       => 'read',
        'contextlevel'  => CONTEXT_MODULE,
        'archetypes'    => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/lid:viewstudentdashboard' => [
        'captype'       => 'read',
        'contextlevel'  => CONTEXT_COURSE,
        'archetypes'    => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    'local/lid:configureforum' => [
        'captype'       => 'write',
        'contextlevel'  => CONTEXT_MODULE,
        'archetypes'    => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/lid:editprompt' => [
        'captype'       => 'write',
        'contextlevel'  => CONTEXT_COURSE,
        'archetypes'    => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        // Note: non-editing teachers intentionally excluded — prompt editing is
        // a configuration action, not a routine teaching action.
    ],

    // -------------------------------------------------------------------------
    // Analysis control
    // -------------------------------------------------------------------------

    'local/lid:triggeranalysis' => [
        'captype'       => 'write',
        'contextlevel'  => CONTEXT_MODULE,
        'archetypes'    => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

];
