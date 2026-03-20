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
 * Scheduled task definitions for local_lid.
 *
 * The process_queue task is registered here with a default schedule of
 * every 5 minutes (* /5 * * * *). The site administrator can override this
 * at Site Administration → Server → Scheduled tasks, or the plugin's own
 * settings page updates the customised schedule programmatically based on
 * the cron_interval setting (range: 1–1440 minutes).
 *
 * Setting minute="every 1 minute" is the most aggressive supported
 * interval for high-volume deployments. The task self-limits via the
 * cron_batchsize setting in local_lid_settings regardless of run frequency.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_lid\task\process_queue',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
