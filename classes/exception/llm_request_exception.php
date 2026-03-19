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
 * Exception thrown when the LLM API HTTP request fails (cURL error,
 * non-2xx HTTP status code, timeout).
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Thrown on HTTP-level failures communicating with the LLM API.
 */
class llm_request_exception extends \moodle_exception {

    /**
     * @param string          $message   Human-readable error detail.
     * @param \Throwable|null $previous  Previous exception for chaining.
     */
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct('error_llm_request_failed', 'local_lid', '', $message, $message);
    }
}
