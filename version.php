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
 * Plugin version and compatibility information.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_lid';        // Full component name: type_name.
$plugin->version   = 2026032800;         // YYYYMMDDNN — increment NN for same-day releases.
$plugin->requires  = 2024042200;         // Minimum Moodle version: 4.5 LTS (2024042200).
$plugin->maturity  = MATURITY_BETA;      // MATURITY_ALPHA | MATURITY_BETA | MATURITY_RC | MATURITY_STABLE.
$plugin->release   = '0.5.0';            // Human-readable version string.

// No dependencies on other plugins at this stage.
// When Moodle forum dependency is formalised, add:
// $plugin->dependencies = ['mod_forum' => ANY_VERSION];
