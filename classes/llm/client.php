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
 * LLM API client for local_lid.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\llm;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client that sends prompts to the configured LLM API endpoint and
 * returns the raw text response.
 *
 * Designed to be provider-agnostic. The request body follows the Anthropic
 * Messages API format by default. Providers using an OpenAI-compatible
 * endpoint will work without modification because both use the same
 * { model, max_tokens, messages: [{role, content}] } structure.
 *
 * Usage:
 *   $client   = new \local_lid\llm\client();
 *   $response = $client->complete($prompttext);
 *   // $response is a raw string (expected to be JSON from the LLM).
 *
 * Exceptions:
 *   \local_lid\exception\llm_request_exception  — HTTP or cURL error.
 *   \local_lid\exception\llm_response_exception — Unexpected response structure.
 */
class client {

    /** @var string LLM API endpoint URL. */
    private string $endpoint;

    /** @var string API key (decrypted at construction time). */
    private string $apikey;

    /** @var string Model identifier string. */
    private string $model;

    /** @var int Maximum tokens to request. */
    private int $maxtokens;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor — loads configuration from Moodle config_plugins or from
     * the local_lid_settings site-level row (courseid = 0).
     *
     * Config resolution order:
     *   1. local_lid_settings row (courseid = 0) — set by the admin settings page
     *      and stored in the DB for the prompt and API details.
     *   2. get_config('local_lid', ...) — fallback for values stored via the
     *      standard Moodle config_plugins table (trigger flags, cron settings).
     *
     * For the API key, get_config() returns the value saved by the admin
     * settings page. Moodle's admin_setting_configpasswordunmask stores the
     * value as plain text in config_plugins — we rely on Moodle's own DB
     * encryption at rest rather than adding a second layer here.
     *
     * @throws \local_lid\exception\llm_config_exception If required config is missing.
     */
    public function __construct() {
        $this->endpoint  = (string) get_config('local_lid', 'llm_endpoint');
        $this->apikey    = (string) get_config('local_lid', 'llm_apikey');
        $this->model     = (string) get_config('local_lid', 'llm_model');
        $this->maxtokens = (int)    get_config('local_lid', 'llm_maxtokens') ?: 4096;
        $this->timeout   = (int)    get_config('local_lid', 'llm_timeout')   ?: 60;

        if (empty($this->endpoint)) {
            throw new \local_lid\exception\llm_config_exception(
                get_string('error_llm_endpoint_missing', 'local_lid')
            );
        }

        if (empty($this->apikey)) {
            throw new \local_lid\exception\llm_config_exception(
                get_string('error_llm_apikey_missing', 'local_lid')
            );
        }

        if (empty($this->model)) {
            $this->model = 'claude-sonnet-4-6';
        }
    }

    /**
     * Send a prompt to the LLM and return the text response.
     *
     * The full prompt (assembled by prompt_builder) is sent as a single
     * user message. The LLM is expected to return only a raw JSON object
     * with no preamble — the prompt instructs it to do so.
     *
     * @param  string $prompt The complete assembled prompt including post content.
     * @return string         Raw text response from the LLM.
     *
     * @throws \local_lid\exception\llm_request_exception  On HTTP / cURL failure.
     * @throws \local_lid\exception\llm_response_exception On unexpected API response.
     */
    public function complete(string $prompt): string {

        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->maxtokens,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \local_lid\exception\llm_request_exception(
                'Failed to JSON-encode request body: ' . json_last_error_msg()
            );
        }

        $curl = $this->make_curl_handle($body);

        $rawresponse = curl_exec($curl);
        $curlerrno   = curl_errno($curl);
        $curlerror   = curl_error($curl);
        $httpcode    = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curlerrno !== 0) {
            throw new \local_lid\exception\llm_request_exception(
                get_string('error_llm_request_failed', 'local_lid',
                    "cURL error {$curlerrno}: {$curlerror}")
            );
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \local_lid\exception\llm_request_exception(
                get_string('error_llm_request_failed', 'local_lid',
                    "HTTP {$httpcode}: " . substr($rawresponse, 0, 500))
            );
        }

        return $this->extract_text($rawresponse);
    }

    /**
     * Extract the text content from the LLM API response envelope.
     *
     * Handles the Anthropic Messages API response shape:
     *   { "content": [ { "type": "text", "text": "..." } ] }
     *
     * If the response does not match this shape (e.g. an OpenAI-compatible
     * endpoint using { "choices": [ { "message": { "content": "..." } } ] }),
     * falls back to the OpenAI shape before throwing.
     *
     * @param  string $rawresponse Raw JSON string from the API.
     * @return string              The extracted text content.
     *
     * @throws \local_lid\exception\llm_response_exception If no text can be extracted.
     */
    private function extract_text(string $rawresponse): string {

        $decoded = json_decode($rawresponse, true);

        if (!is_array($decoded)) {
            throw new \local_lid\exception\llm_response_exception(
                get_string('error_llm_invalid_json', 'local_lid')
            );
        }

        // Anthropic Messages API shape.
        if (isset($decoded['content'][0]['text'])) {
            return trim($decoded['content'][0]['text']);
        }

        // OpenAI-compatible shape (chat completions).
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim($decoded['choices'][0]['message']['content']);
        }

        // API returned an error object.
        if (isset($decoded['error'])) {
            $errormsg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            throw new \local_lid\exception\llm_response_exception(
                get_string('error_llm_request_failed', 'local_lid', $errormsg)
            );
        }

        throw new \local_lid\exception\llm_response_exception(
            get_string('error_llm_response_exception', 'local_lid')
        );
    }

    /**
     * Build and configure a cURL handle for the API request.
     *
     * Uses Moodle's curl class where available for proxy support, falling
     * back to a raw curl handle. The Anthropic API requires an
     * x-api-key header; OpenAI-compatible endpoints use Authorization: Bearer.
     * We send both so the client works with either provider without
     * configuration changes.
     *
     * @param  string   $body JSON-encoded request body.
     * @return resource        Configured cURL handle.
     */
    private function make_curl_handle(string $body) {

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: '       . $this->apikey,         // Anthropic.
                'Authorization: Bearer ' . $this->apikey,    // OpenAI-compatible.
                'anthropic-version: 2023-06-01',              // Required by Anthropic API.
                'User-Agent: MoodleLocalLID/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $curl;
    }

    /**
     * Return the model string currently configured.
     * Useful for storing the snapshot model in local_lid_analysis.llm_model.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->model;
    }
}
