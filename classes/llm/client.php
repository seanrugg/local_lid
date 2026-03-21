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
 * Supports three provider shapes auto-detected from the endpoint URL:
 *   - Anthropic Messages API  (api.anthropic.com)
 *   - Google Gemini API       (generativelanguage.googleapis.com)
 *   - OpenAI-compatible API   (everything else, including Ollama)
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
 */
class client {

    /** Provider constants. */
    const PROVIDER_ANTHROPIC = 'anthropic';
    const PROVIDER_GEMINI    = 'gemini';
    const PROVIDER_OPENAI    = 'openai';

    /** @var string API endpoint URL. */
    private string $endpoint;

    /** @var string API key. */
    private string $apikey;

    /** @var string Model identifier. */
    private string $model;

    /** @var int Max tokens for completion. */
    private int $maxtokens;

    /** @var int cURL timeout in seconds. */
    private int $timeout;

    /** @var string Detected provider. */
    private string $provider;

    /**
     * Constructor. Reads configuration and validates required settings.
     *
     * @throws \local_lid\exception\llm_config_exception If endpoint or API key not set.
     */
    public function __construct() {
        $this->endpoint  = (string) get_config('local_lid', 'llm_endpoint');
        $this->apikey    = (string) get_config('local_lid', 'llm_apikey');
        $this->model     = (string) get_config('local_lid', 'llm_model');
        $this->maxtokens = (int)    get_config('local_lid', 'llm_maxtokens') ?: 4096;
        $this->timeout   = 120;

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
            $this->model = 'gemini-2.5-flash';
        }

        $this->provider = $this->detect_provider($this->endpoint);
    }

    /**
     * Detect which provider API format to use based on the endpoint URL.
     *
     * @param  string $endpoint
     * @return string One of the PROVIDER_* constants.
     */
    private function detect_provider(string $endpoint): string {
        if (strpos($endpoint, 'generativelanguage.googleapis.com') !== false) {
            return self::PROVIDER_GEMINI;
        }
        if (strpos($endpoint, 'api.anthropic.com') !== false) {
            return self::PROVIDER_ANTHROPIC;
        }
        return self::PROVIDER_OPENAI;
    }

    /**
     * Send a prompt to the LLM and return the text response.
     *
     * @param  string $prompt The complete assembled prompt.
     * @return string         Raw text response from the LLM.
     *
     * @throws \local_lid\exception\llm_request_exception  On HTTP / cURL failure.
     * @throws \local_lid\exception\llm_response_exception On unexpected API response.
     */
    public function complete(string $prompt): string {

        $body = $this->build_request_body($prompt);

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
     * Build the JSON request body for the detected provider.
     *
     * @param  string $prompt
     * @return string|false JSON-encoded body, or false on encode failure.
     */
    private function build_request_body(string $prompt) {

        if ($this->provider === self::PROVIDER_GEMINI) {
            // Gemini generateContent format.
            $data = [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $this->maxtokens,
                    'temperature'     => 0.2,
                ],
            ];
        } else if ($this->provider === self::PROVIDER_ANTHROPIC) {
            // Anthropic Messages API format.
            $data = [
                'model'      => $this->model,
                'max_tokens' => $this->maxtokens,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
        } else {
            // OpenAI-compatible (Ollama, OpenAI, etc.).
            $data = [
                'model'      => $this->model,
                'max_tokens' => $this->maxtokens,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract the text content from the LLM API response envelope.
     *
     * Handles three provider shapes:
     *   Anthropic: { "content": [ { "type": "text", "text": "..." } ] }
     *   Gemini:    { "candidates": [ { "content": { "parts": [ { "text": "..." } ] } } ] }
     *   OpenAI:    { "choices": [ { "message": { "content": "..." } } ] }
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

        // API error object — check before provider shapes.
        if (isset($decoded['error'])) {
            $errormsg = $decoded['error']['message']
                ?? (is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']));
            throw new \local_lid\exception\llm_response_exception(
                get_string('error_llm_request_failed', 'local_lid', $errormsg)
            );
        }

        // Gemini generateContent response shape.
        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim($decoded['candidates'][0]['content']['parts'][0]['text']);
            if ($text !== '') {
                return $text;
            }
        }

        // Gemini safety block — finishReason = SAFETY or other non-STOP.
        if (isset($decoded['candidates'][0]['finishReason'])
            && $decoded['candidates'][0]['finishReason'] !== 'STOP'
            && $decoded['candidates'][0]['finishReason'] !== 'MAX_TOKENS') {
            $reason = $decoded['candidates'][0]['finishReason'];
            throw new \local_lid\exception\llm_response_exception(
                get_string('error_llm_request_failed', 'local_lid',
                    "Gemini blocked the response (finishReason: {$reason})")
            );
        }

        // Anthropic Messages API shape.
        if (isset($decoded['content'][0]['text'])) {
            return trim($decoded['content'][0]['text']);
        }

        // OpenAI-compatible chat completions shape.
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim($decoded['choices'][0]['message']['content']);
        }

        // Unknown shape — log the first 500 chars for debugging.
        debugging(
            'local_lid client: unrecognised API response shape. First 500 chars: '
            . substr($rawresponse, 0, 500),
            DEBUG_DEVELOPER
        );

        throw new \local_lid\exception\llm_response_exception(
            get_string('error_llm_response_exception', 'local_lid')
        );
    }

    /**
     * Build and configure a cURL handle for the API request.
     *
     * Headers are provider-specific:
     *   Gemini:    x-goog-api-key (key appended to URL as ?key= is also supported
     *              but header is cleaner)
     *   Anthropic: x-api-key + anthropic-version
     *   OpenAI:    Authorization: Bearer
     *
     * @param  string $body JSON-encoded request body.
     * @return resource     Configured cURL handle.
     */
    private function make_curl_handle(string $body) {

        // Build provider-specific headers.
        $headers = ['Content-Type: application/json', 'User-Agent: MoodleLocalLID/1.0'];

        if ($this->provider === self::PROVIDER_GEMINI) {
            $headers[] = 'x-goog-api-key: ' . $this->apikey;
        } else if ($this->provider === self::PROVIDER_ANTHROPIC) {
            $headers[] = 'x-api-key: ' . $this->apikey;
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
            $headers[] = 'anthropic-version: 2023-06-01';
        } else {
            // OpenAI-compatible (Ollama, etc.).
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $curl;
    }

    /**
     * Return the model string currently configured.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Return the detected provider string.
     * Useful for logging and diagnostics.
     *
     * @return string
     */
    public function get_provider(): string {
        return $this->provider;
    }
}
