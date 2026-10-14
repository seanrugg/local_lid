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
 * Resolves LLM API credentials from Moodle's site-wide AI provider instances.
 *
 * When the admin enables "Use site AI provider instances" in the local_lid
 * settings, this class queries the core_ai subsystem (public/ai/) for configured
 * provider instances and extracts the endpoint URL and API key from the first
 * valid one.
 *
 * If no AI provider instances are available, or if resolution fails, the
 * credentials fall back to the manual configuration (llm_endpoint, llm_apikey)
 * stored in the local_lid plugin settings.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\llm;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves LLM API credentials from Moodle's site-wide AI provider instances.
 */
class provider_resolver {

    /**
     * Resolve LLM API credentials (endpoint + API key).
     *
     * Priority:
     *   1. If llm_use_ai_provider = 1: query core_ai manager for configured instances
     *   2. If no instances found or resolution fails: fall back to manual config
     *   3. If manual config also empty: return empty strings (caller validates)
     *
     * @return array {
     *   'endpoint' => string,
     *   'apikey' => string,
     *   'source' => string (one of: 'ai_provider', 'manual')
     * }
     */
    public static function resolve_credentials(): array {
        $result = [
            'endpoint' => '',
            'apikey' => '',
            'source' => 'manual',
        ];

        // Check if AI provider inheritance is enabled.
        if (get_config('local_lid', 'llm_use_ai_provider') !== '1') {
            // Inheritance disabled — use manual config.
            $result['endpoint'] = (string) get_config('local_lid', 'llm_endpoint');
            $result['apikey'] = (string) get_config('local_lid', 'llm_apikey');
            return $result;
        }

        // Inheritance enabled — try to resolve from AI provider instances.
        $resolved = self::resolve_from_ai_providers();

        if ($resolved !== null) {
            return $resolved;
        }

        // Fallback to manual config if AI provider resolution failed.
        $result['endpoint'] = (string) get_config('local_lid', 'llm_endpoint');
        $result['apikey'] = (string) get_config('local_lid', 'llm_apikey');
        return $result;
    }

    /**
     * Attempt to resolve credentials from Moodle's AI provider instances.
     *
     * Queries the core_ai manager for configured provider instances and
     * extracts the endpoint URL and API key from the first valid one.
     *
     * The core_ai subsystem (public/ai/) is Moodle's built-in AI provider
     * management system. Providers are configured in Site Administration
     * → Plugins → AI providers.
     *
     * @return array|null Resolved credentials with 'source' => 'ai_provider',
     *                    or null if no valid provider instances found.
     */
    private static function resolve_from_ai_providers(): ?array {
        try {
            // Check if the core_ai manager class exists (requires Moodle 5.0+).
            if (!class_exists('\\core_ai\\manager')) {
                return null;
            }

            // Get the core_ai manager instance.
            $manager = \core_ai\manager::instance();

            // Get all enabled provider instances.
            $providers = $manager->get_provider_instances();

            if (empty($providers) || !is_array($providers)) {
                return null;
            }

            // Iterate through providers to find one with valid credentials.
            foreach ($providers as $provider) {
                $endpoint = self::extract_endpoint($provider);
                $apikey = self::extract_apikey($provider);

                if (!empty($endpoint) && !empty($apikey)) {
                    return [
                        'endpoint' => $endpoint,
                        'apikey' => $apikey,
                        'source' => 'ai_provider',
                    ];
                }
            }

            // No valid provider found.
            return null;

        } catch (\Exception $e) {
            // Provider helper unavailable or query failed.
            return null;
        }
    }

    /**
     * Extract the API endpoint URL from a provider instance.
     *
     * Moodle core_ai provider instances store configuration in the $config
     * array (JSON-decoded from the database). The endpoint URL is typically
     * derived from the provider type, but custom endpoints may be configured.
     *
     * @param  object $provider Provider instance (core_ai\provider subclass).
     * @return string           The endpoint URL, or empty string.
     */
    private static function extract_endpoint($provider): string {
        // Check config array for custom base URL.
        $config = $provider->config ?? [];
        if (!empty($config['baseurl'])) {
            return rtrim($config['baseurl'], '/');
        }

        // Check for api_base_url in config.
        if (!empty($config['api_base_url'])) {
            return rtrim($config['api_base_url'], '/');
        }

        // Derive endpoint from provider type.
        $type = strtolower($provider->provider ?? '');

        // Known provider endpoint mappings.
        $known_endpoints = [
            'aiprovider_anthropic' => 'https://api.anthropic.com/v1/messages',
            'anthropic' => 'https://api.anthropic.com/v1/messages',
            'aiprovider_gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'aiprovider_openai' => 'https://api.openai.com/v1',
            'openai' => 'https://api.openai.com/v1',
            'aiprovider_ollama' => 'http://localhost:11434/v1',
            'ollama' => 'http://localhost:11434/v1',
        ];

        if (isset($known_endpoints[$type])) {
            return $known_endpoints[$type];
        }

        return '';
    }

    /**
     * Extract the API key from a provider instance.
     *
     * Moodle core_ai provider instances store the API key in the $config array
     * (JSON-decoded from the database). The field name varies by provider type.
     *
     * @param  object $provider Provider instance (core_ai\provider subclass).
     * @return string           The API key, or empty string.
     */
    private static function extract_apikey($provider): string {
        $config = $provider->config ?? [];

        // Check common field names for API key.
        $apikeyfields = ['apikey', 'api_key', 'apiKey', 'secret', 'token'];

        foreach ($apikeyfields as $field) {
            if (!empty($config[$field])) {
                return $config[$field];
            }
        }

        return '';
    }
}
