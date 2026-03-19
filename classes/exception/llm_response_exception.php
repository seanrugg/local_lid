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
 * Exception thrown when the LLM API returns a 2xx response but the body
 * cannot be parsed or does not match the expected structure.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Thrown when the LLM API response body is unparseable or structurally
 * unexpected (e.g. missing content array, error object in response).
 */
class llm_response_exception extends \moodle_exception {

    /**
     * @param string          $message   Human-readable error detail.
     * @param \Throwable|null $previous  Previous exception for chaining.
     */
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct('error_llm_invalid_json', 'local_lid', '', null, $message);
    }
}
