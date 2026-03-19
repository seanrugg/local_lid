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
 * LID Schema v1.0 validator for local_lid.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\analysis;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates a raw LLM response string against the LID Schema v1.0 structure.
 *
 * Validation is intentionally pragmatic rather than strict. The LLM will
 * sometimes return values outside the documented ranges (e.g. a score of
 * 101, or a missing optional field). The validator distinguishes between:
 *
 *   FATAL errors   — the JSON cannot be used at all. The analysis record
 *                    is marked as 'error' and re-queued for retry.
 *                    Examples: invalid JSON, missing required root keys,
 *                    completely empty competencies array.
 *
 *   WARNINGS       — the JSON is usable but imperfect. The analysis is
 *                    stored with a note. The dashboard renders gracefully
 *                    around missing optional fields.
 *                    Examples: score out of range (clamped on read),
 *                    missing optional sub-fields, wrong bloom_label for
 *                    a given bloom_level.
 *
 * The validator also detects truncated JSON — a common failure mode on
 * Comet/Perplexity and any endpoint with a tight output token limit —
 * and surfaces it as a distinct fatal error so the session_analyser can
 * increase max_tokens or split the request on retry.
 *
 * Usage:
 *   $validator = new \local_lid\analysis\schema_validator();
 *   $result    = $validator->validate($rawstring);
 *
 *   if ($result->is_valid()) {
 *       $json = $result->get_data(); // Decoded array, ready to store.
 *   } else {
 *       $errors = $result->get_errors(); // Array of error strings.
 *   }
 *
 *   $warnings = $result->get_warnings(); // Always check even when valid.
 */
class schema_validator {

    /** @var string The schema version this validator checks against. */
    const SCHEMA_VERSION = '1.0';

    /**
     * Required root-level keys. Every valid LID JSON object must have all of
     * these present and non-null.
     */
    private const REQUIRED_ROOT_KEYS = [
        'schema_version',
        'session',
        'scores',
        'competencies',
        'radar',
        'blooms_progression',
        'roi',
        'timeline',
        'employer_value',
        'portfolio',
        'meta',
    ];

    /**
     * Required keys within the session object.
     */
    private const REQUIRED_SESSION_KEYS = [
        'id',
        'date',
        'title',
        'source',
        'source_type',
        'duration_minutes',
        'topic_summary',
        'tags',
    ];

    /**
     * Required keys within the scores object.
     */
    private const REQUIRED_SCORES_KEYS = [
        'competency_domains_count',
        'cognitive_depth_score',
        'strategic_thinking_pct',
        'roi_awareness_pct',
        'engagement_score',
        'meta_cognition_score',
    ];

    /**
     * Required keys within the roi object.
     */
    private const REQUIRED_ROI_KEYS = [
        'knowledge_value_usd',
        'time_efficiency_multiplier',
        'engagement_score',
        'retention_probability_pct',
        'application_readiness',
        'employer_value_index',
        'lms_equivalent_hours',
        'session_hours',
    ];

    /**
     * Required keys within each competency object.
     */
    private const REQUIRED_COMPETENCY_KEYS = [
        'name',
        'score',
        'color',
        'bloom_level',
        'bloom_label',
    ];

    /**
     * Required keys within each blooms_progression object.
     */
    private const REQUIRED_BLOOMS_KEYS = [
        'level',
        'label',
        'title',
        'description',
        'dots_active',
    ];

    /**
     * Valid Bloom's labels mapped to their numeric levels.
     */
    private const BLOOMS_LEVEL_MAP = [
        1 => 'Remember',
        2 => 'Understand',
        3 => 'Apply',
        4 => 'Analyze',
        5 => 'Evaluate',
        6 => 'Create',
    ];

    /**
     * Valid application_readiness values.
     */
    private const VALID_READINESS = ['LOW', 'MEDIUM', 'HIGH', 'EXCEPTIONAL'];

    /**
     * Valid competency color values.
     */
    private const VALID_COLORS = ['cyan', 'green', 'orange', 'purple'];

    /**
     * Valid dot_color values for blooms_progression entries.
     */
    private const VALID_DOT_COLORS = ['cyan', 'green', 'orange', 'purple'];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate a raw LLM response string.
     *
     * Strips any accidental markdown fences the LLM may have added despite
     * being instructed not to, then attempts JSON decode and structural
     * validation.
     *
     * @param  string $raw The raw string returned by the LLM client.
     * @return validation_result
     */
    public function validate(string $raw): validation_result {
        $errors   = [];
        $warnings = [];

        // Step 1 — strip markdown fences if present.
        $cleaned = $this->strip_fences($raw);

        // Step 2 — detect truncation before attempting decode.
        if ($this->is_truncated($cleaned)) {
            return validation_result::fatal(
                [get_string('error_llm_truncated', 'local_lid')],
                $warnings,
                validation_result::REASON_TRUNCATED
            );
        }

        // Step 3 — JSON decode.
        $data = json_decode($cleaned, true);

        if (!is_array($data)) {
            return validation_result::fatal(
                [get_string('error_llm_invalid_json', 'local_lid')],
                $warnings,
                validation_result::REASON_INVALID_JSON
            );
        }

        // Step 4 — schema_version check.
        $version = $data['schema_version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            // Wrong version is fatal — we cannot safely render an unknown schema.
            return validation_result::fatal(
                [get_string('error_llm_schema_mismatch', 'local_lid', $version ?? 'missing')],
                $warnings,
                validation_result::REASON_SCHEMA_MISMATCH
            );
        }

        // Step 5 — required root keys.
        foreach (self::REQUIRED_ROOT_KEYS as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                $errors[] = "Missing required root key: {$key}";
            }
        }

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_MISSING_KEYS);
        }

        // Step 6 — validate sub-objects.
        $this->validate_session($data['session'], $errors, $warnings);
        $this->validate_scores($data['scores'], $errors, $warnings);
        $this->validate_competencies($data['competencies'], $errors, $warnings);
        $this->validate_radar($data['radar'], $errors, $warnings);
        $this->validate_blooms_progression($data['blooms_progression'], $errors, $warnings);
        $this->validate_roi($data['roi'], $errors, $warnings);
        $this->validate_timeline($data['timeline'], $warnings);
        $this->validate_employer_value($data['employer_value'], $warnings);
        $this->validate_portfolio($data['portfolio'], $warnings);
        $this->validate_meta($data['meta'], $warnings);

        // Step 7 — coerce numeric strings to numbers throughout.
        // The LLM sometimes returns scores as strings ("75") rather than
        // integers (75). We coerce silently and log a warning.
        $data = $this->coerce_numerics($data, $warnings);

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_INVALID_STRUCTURE);
        }

        return validation_result::ok($data, $warnings);
    }

    // -------------------------------------------------------------------------
    // Private — sub-object validators
    // -------------------------------------------------------------------------

    /**
     * Validate the session object.
     *
     * @param mixed $session
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_session($session, array &$errors, array &$warnings): void {
        if (!is_array($session)) {
            $errors[] = 'session must be an object.';
            return;
        }

        foreach (self::REQUIRED_SESSION_KEYS as $key) {
            if (!array_key_exists($key, $session) || $session[$key] === null || $session[$key] === '') {
                $errors[] = "Missing required session key: {$key}";
            }
        }

        // tags must be an array.
        if (isset($session['tags']) && !is_array($session['tags'])) {
            $warnings[] = 'session.tags should be an array; received ' . gettype($session['tags']);
        }

        // date should be ISO 8601.
        if (!empty($session['date']) && !$this->is_iso8601_date($session['date'])) {
            $warnings[] = 'session.date does not appear to be a valid ISO 8601 date: ' . $session['date'];
        }
    }

    /**
     * Validate the scores object.
     *
     * @param mixed $scores
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_scores($scores, array &$errors, array &$warnings): void {
        if (!is_array($scores)) {
            $errors[] = 'scores must be an object.';
            return;
        }

        foreach (self::REQUIRED_SCORES_KEYS as $key) {
            if (!array_key_exists($key, $scores)) {
                $errors[] = "Missing required scores key: {$key}";
                continue;
            }
            $val = $scores[$key];
            // Allow numeric strings — they will be coerced later.
            if (!is_numeric($val)) {
                $warnings[] = "scores.{$key} should be numeric; received: " . gettype($val);
            } elseif ((float)$val < 0 || (float)$val > 100) {
                $warnings[] = "scores.{$key} is out of range 0–100: {$val} (will be clamped on render)";
            }
        }
    }

    /**
     * Validate the competencies array.
     *
     * @param mixed $competencies
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_competencies($competencies, array &$errors, array &$warnings): void {
        if (!is_array($competencies)) {
            $errors[] = 'competencies must be an array.';
            return;
        }

        if (empty($competencies)) {
            // An empty competencies array means the LLM found nothing to analyse.
            // This is a fatal error — the dashboard cannot render without at least one.
            $errors[] = 'competencies array is empty; at least one competency is required.';
            return;
        }

        foreach ($competencies as $i => $comp) {
            $prefix = "competencies[{$i}]";
            if (!is_array($comp)) {
                $errors[] = "{$prefix} must be an object.";
                continue;
            }

            foreach (self::REQUIRED_COMPETENCY_KEYS as $key) {
                if (!array_key_exists($key, $comp) || $comp[$key] === null || $comp[$key] === '') {
                    $errors[] = "Missing required key {$prefix}.{$key}";
                }
            }

            // score range.
            if (isset($comp['score']) && is_numeric($comp['score'])) {
                if ((float)$comp['score'] < 0 || (float)$comp['score'] > 100) {
                    $warnings[] = "{$prefix}.score is out of range 0–100: {$comp['score']}";
                }
            }

            // color value.
            if (isset($comp['color']) && !in_array($comp['color'], self::VALID_COLORS, true)) {
                $warnings[] = "{$prefix}.color '{$comp['color']}' is not a recognised value; expected: " .
                    implode(', ', self::VALID_COLORS);
            }

            // bloom_level range.
            if (isset($comp['bloom_level'])) {
                $level = (int) $comp['bloom_level'];
                if ($level < 1 || $level > 6) {
                    $warnings[] = "{$prefix}.bloom_level {$level} is out of range 1–6.";
                }
            }

            // bloom_label consistency with bloom_level.
            if (isset($comp['bloom_level'], $comp['bloom_label'])) {
                $level    = (int) $comp['bloom_level'];
                $expected = self::BLOOMS_LEVEL_MAP[$level] ?? null;
                if ($expected && $comp['bloom_label'] !== $expected) {
                    $warnings[] = "{$prefix}.bloom_label '{$comp['bloom_label']}' does not match " .
                        "bloom_level {$level} (expected '{$expected}').";
                }
            }
        }
    }

    /**
     * Validate the radar object.
     *
     * @param mixed $radar
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_radar($radar, array &$errors, array &$warnings): void {
        if (!is_array($radar) || !isset($radar['axes']) || !is_array($radar['axes'])) {
            $errors[] = 'radar must be an object with an axes array.';
            return;
        }

        if (empty($radar['axes'])) {
            $errors[] = 'radar.axes must contain at least one entry.';
            return;
        }

        foreach ($radar['axes'] as $i => $axis) {
            $prefix = "radar.axes[{$i}]";
            if (!is_array($axis)) {
                $errors[] = "{$prefix} must be an object.";
                continue;
            }
            if (empty($axis['label'])) {
                $warnings[] = "{$prefix} is missing a label.";
            }
            if (!isset($axis['value']) || !is_numeric($axis['value'])) {
                $warnings[] = "{$prefix}.value should be numeric.";
            } elseif ((float)$axis['value'] < 0 || (float)$axis['value'] > 100) {
                $warnings[] = "{$prefix}.value is out of range 0–100: {$axis['value']}";
            }
        }
    }

    /**
     * Validate the blooms_progression array.
     *
     * @param mixed $blooms
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_blooms_progression($blooms, array &$errors, array &$warnings): void {
        if (!is_array($blooms)) {
            $errors[] = 'blooms_progression must be an array.';
            return;
        }

        if (empty($blooms)) {
            $errors[] = 'blooms_progression array is empty; at least one entry is required.';
            return;
        }

        foreach ($blooms as $i => $entry) {
            $prefix = "blooms_progression[{$i}]";
            if (!is_array($entry)) {
                $errors[] = "{$prefix} must be an object.";
                continue;
            }

            foreach (self::REQUIRED_BLOOMS_KEYS as $key) {
                if (!array_key_exists($key, $entry) || $entry[$key] === null || $entry[$key] === '') {
                    $errors[] = "Missing required key {$prefix}.{$key}";
                }
            }

            // level range.
            if (isset($entry['level'])) {
                $level = (int) $entry['level'];
                if ($level < 1 || $level > 6) {
                    $warnings[] = "{$prefix}.level {$level} is out of range 1–6.";
                }
            }

            // dots_active range.
            if (isset($entry['dots_active'])) {
                $dots = (int) $entry['dots_active'];
                if ($dots < 0 || $dots > 5) {
                    $warnings[] = "{$prefix}.dots_active {$dots} is out of range 0–5.";
                }
            }

            // dot_color value.
            if (isset($entry['dot_color']) && !in_array($entry['dot_color'], self::VALID_DOT_COLORS, true)) {
                $warnings[] = "{$prefix}.dot_color '{$entry['dot_color']}' is not a recognised value.";
            }
        }
    }

    /**
     * Validate the roi object.
     *
     * @param mixed $roi
     * @param array &$errors
     * @param array &$warnings
     */
    private function validate_roi($roi, array &$errors, array &$warnings): void {
        if (!is_array($roi)) {
            $errors[] = 'roi must be an object.';
            return;
        }

        foreach (self::REQUIRED_ROI_KEYS as $key) {
            if (!array_key_exists($key, $roi)) {
                $errors[] = "Missing required roi key: {$key}";
            }
        }

        // application_readiness enum.
        if (isset($roi['application_readiness']) &&
            !in_array($roi['application_readiness'], self::VALID_READINESS, true)) {
            $warnings[] = "roi.application_readiness '{$roi['application_readiness']}' is not a " .
                "recognised value; expected: " . implode(', ', self::VALID_READINESS);
        }

        // employer_value_index range.
        if (isset($roi['employer_value_index']) && is_numeric($roi['employer_value_index'])) {
            $val = (float) $roi['employer_value_index'];
            if ($val < 0.0 || $val > 10.0) {
                $warnings[] = "roi.employer_value_index {$val} is out of range 0.0–10.0.";
            }
        }

        // Numeric fields.
        foreach (['knowledge_value_usd', 'time_efficiency_multiplier', 'engagement_score',
                  'retention_probability_pct', 'lms_equivalent_hours', 'session_hours'] as $key) {
            if (isset($roi[$key]) && !is_numeric($roi[$key])) {
                $warnings[] = "roi.{$key} should be numeric; received: " . gettype($roi[$key]);
            }
        }
    }

    /**
     * Validate the timeline array (warnings only — optional content).
     *
     * @param mixed $timeline
     * @param array &$warnings
     */
    private function validate_timeline($timeline, array &$warnings): void {
        if (!is_array($timeline)) {
            $warnings[] = 'timeline should be an array.';
            return;
        }
        foreach ($timeline as $i => $entry) {
            if (!is_array($entry) || empty($entry['title'])) {
                $warnings[] = "timeline[{$i}] is missing a title.";
            }
        }
    }

    /**
     * Validate the employer_value array (warnings only — optional content).
     *
     * @param mixed $employervalue
     * @param array &$warnings
     */
    private function validate_employer_value($employervalue, array &$warnings): void {
        if (!is_array($employervalue)) {
            $warnings[] = 'employer_value should be an array.';
            return;
        }
        foreach ($employervalue as $i => $entry) {
            if (!is_array($entry) || empty($entry['title'])) {
                $warnings[] = "employer_value[{$i}] is missing a title.";
            }
        }
    }

    /**
     * Validate the portfolio object (warnings only — optional content).
     *
     * @param mixed $portfolio
     * @param array &$warnings
     */
    private function validate_portfolio($portfolio, array &$warnings): void {
        if (!is_array($portfolio)) {
            $warnings[] = 'portfolio should be an object.';
            return;
        }
        if (empty($portfolio['title'])) {
            $warnings[] = 'portfolio.title is missing.';
        }
    }

    /**
     * Validate the meta object (warnings only).
     *
     * @param mixed $meta
     * @param array &$warnings
     */
    private function validate_meta($meta, array &$warnings): void {
        if (!is_array($meta)) {
            $warnings[] = 'meta should be an object.';
            return;
        }
        if (empty($meta['generated_by'])) {
            $warnings[] = 'meta.generated_by is missing.';
        }
        if (isset($meta['confidence']) &&
            !in_array($meta['confidence'], ['LOW', 'MEDIUM', 'HIGH'], true)) {
            $warnings[] = "meta.confidence '{$meta['confidence']}' is not a recognised value.";
        }
    }

    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    /**
     * Strip markdown code fences that the LLM may have wrapped around the JSON
     * despite instructions to the contrary.
     *
     * Handles:
     *   ```json\n{...}\n```
     *   ```\n{...}\n```
     *   `{...}`
     *
     * @param  string $raw
     * @return string Cleaned string.
     */
    private function strip_fences(string $raw): string {
        $trimmed = trim($raw);

        // Multi-line code fence with optional language tag.
        if (preg_match('/^```(?:json)?\s*\n?([\s\S]*?)\n?```$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        // Single backtick wrapping.
        if (preg_match('/^`([\s\S]*)`$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    /**
     * Detect a truncated JSON response.
     *
     * A response is considered truncated if it starts with '{' but does not
     * end with '}' after trimming whitespace. This is the signature of a
     * response that hit the max_tokens limit mid-generation.
     *
     * Also catches the case where the LLM started generating but produced
     * clearly incomplete JSON (e.g. ends with a comma or a partial string).
     *
     * @param  string $text
     * @return bool
     */
    private function is_truncated(string $text): bool {
        $trimmed = trim($text);

        if (empty($trimmed)) {
            return false; // Empty string is invalid JSON, not truncated.
        }

        if ($trimmed[0] !== '{') {
            return false; // Does not look like a JSON object at all.
        }

        $last = substr($trimmed, -1);

        // Definitively truncated if it doesn't end with '}'.
        if ($last !== '}') {
            return true;
        }

        // Secondary check: attempt decode and see if json_last_error indicates
        // a syntax error that could be caused by truncation mid-string.
        json_decode($trimmed);
        if (json_last_error() === JSON_ERROR_CTRL_CHAR ||
            json_last_error() === JSON_ERROR_SYNTAX) {
            return true;
        }

        return false;
    }

    /**
     * Coerce numeric string values to their proper PHP types throughout the
     * decoded data array.
     *
     * The LLM frequently returns scores as strings ("75") instead of integers
     * (75). This is a schema violation but not a fatal one. We coerce silently
     * and add a single summary warning rather than one warning per field.
     *
     * Operates on: scores.*, roi.* (numeric fields), competencies[*].score,
     * competencies[*].bloom_level, radar.axes[*].value,
     * blooms_progression[*].level, blooms_progression[*].dots_active.
     *
     * @param  array  $data     Decoded JSON data.
     * @param  array  &$warnings
     * @return array            Data with numerics coerced.
     */
    private function coerce_numerics(array $data, array &$warnings): array {
        $coerced = false;

        // scores.*
        if (isset($data['scores']) && is_array($data['scores'])) {
            foreach (self::REQUIRED_SCORES_KEYS as $key) {
                if (isset($data['scores'][$key]) && is_string($data['scores'][$key]) &&
                    is_numeric($data['scores'][$key])) {
                    $data['scores'][$key] = (int) $data['scores'][$key];
                    $coerced = true;
                }
            }
        }

        // roi.* numeric fields.
        $roinumeric = [
            'knowledge_value_usd'        => 'int',
            'time_efficiency_multiplier' => 'float',
            'engagement_score'           => 'int',
            'retention_probability_pct'  => 'int',
            'employer_value_index'       => 'float',
            'lms_equivalent_hours'       => 'float',
            'session_hours'              => 'float',
        ];
        if (isset($data['roi']) && is_array($data['roi'])) {
            foreach ($roinumeric as $key => $type) {
                if (isset($data['roi'][$key]) && is_string($data['roi'][$key]) &&
                    is_numeric($data['roi'][$key])) {
                    $data['roi'][$key] = ($type === 'float')
                        ? (float) $data['roi'][$key]
                        : (int)   $data['roi'][$key];
                    $coerced = true;
                }
            }
        }

        // competencies[*].score and bloom_level.
        if (isset($data['competencies']) && is_array($data['competencies'])) {
            foreach ($data['competencies'] as &$comp) {
                if (is_array($comp)) {
                    foreach (['score', 'bloom_level'] as $key) {
                        if (isset($comp[$key]) && is_string($comp[$key]) &&
                            is_numeric($comp[$key])) {
                            $comp[$key] = (int) $comp[$key];
                            $coerced = true;
                        }
                    }
                }
            }
            unset($comp);
        }

        // radar.axes[*].value.
        if (isset($data['radar']['axes']) && is_array($data['radar']['axes'])) {
            foreach ($data['radar']['axes'] as &$axis) {
                if (is_array($axis) && isset($axis['value']) &&
                    is_string($axis['value']) && is_numeric($axis['value'])) {
                    $axis['value'] = (int) $axis['value'];
                    $coerced = true;
                }
            }
            unset($axis);
        }

        // blooms_progression[*].level and dots_active.
        if (isset($data['blooms_progression']) && is_array($data['blooms_progression'])) {
            foreach ($data['blooms_progression'] as &$entry) {
                if (is_array($entry)) {
                    foreach (['level', 'dots_active'] as $key) {
                        if (isset($entry[$key]) && is_string($entry[$key]) &&
                            is_numeric($entry[$key])) {
                            $entry[$key] = (int) $entry[$key];
                            $coerced = true;
                        }
                    }
                }
            }
            unset($entry);
        }

        if ($coerced) {
            $warnings[] = 'One or more numeric fields were returned as strings by the LLM and have been coerced to their correct types.';
        }

        return $data;
    }

    /**
     * Check whether a string is a plausible ISO 8601 date or datetime.
     *
     * Accepts YYYY-MM-DD and YYYY-MM-DDTHH:MM:SSZ formats. Not exhaustive —
     * just catches obviously wrong values like plain text.
     *
     * @param  string $value
     * @return bool
     */
    private function is_iso8601_date(string $value): bool {
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/',
            $value
        );
    }
}


/**
 * Value object returned by schema_validator::validate().
 *
 * Callers check is_valid() first, then use get_data() or get_errors()
 * accordingly. Warnings are always available regardless of validity.
 */
class validation_result {

    /** Reason codes for fatal validation failures. */
    const REASON_TRUNCATED         = 'truncated';
    const REASON_INVALID_JSON      = 'invalid_json';
    const REASON_SCHEMA_MISMATCH   = 'schema_mismatch';
    const REASON_MISSING_KEYS      = 'missing_keys';
    const REASON_INVALID_STRUCTURE = 'invalid_structure';

    /** @var bool */
    private bool $valid;

    /** @var array|null Decoded, coerced data array. Null if not valid. */
    private ?array $data;

    /** @var string[] Fatal error messages. */
    private array $errors;

    /** @var string[] Non-fatal warning messages. */
    private array $warnings;

    /** @var string|null Reason code for fatal failures. */
    private ?string $reason;

    /**
     * Private constructor — use static factories.
     */
    private function __construct(
        bool $valid,
        ?array $data,
        array $errors,
        array $warnings,
        ?string $reason
    ) {
        $this->valid    = $valid;
        $this->data     = $data;
        $this->errors   = $errors;
        $this->warnings = $warnings;
        $this->reason   = $reason;
    }

    /**
     * Create a successful validation result.
     *
     * @param array  $data     Validated, coerced data.
     * @param array  $warnings Non-fatal warnings.
     * @return self
     */
    public static function ok(array $data, array $warnings = []): self {
        return new self(true, $data, [], $warnings, null);
    }

    /**
     * Create a fatal validation result.
     *
     * @param array  $errors   Fatal error messages.
     * @param array  $warnings Non-fatal warnings accumulated before failure.
     * @param string $reason   One of the REASON_* constants.
     * @return self
     */
    public static function fatal(array $errors, array $warnings = [], string $reason = self::REASON_INVALID_JSON): self {
        return new self(false, null, $errors, $warnings, $reason);
    }

    /** @return bool */
    public function is_valid(): bool {
        return $this->valid;
    }

    /**
     * Return the validated data array.
     * Only meaningful when is_valid() === true.
     *
     * @return array|null
     */
    public function get_data(): ?array {
        return $this->data;
    }

    /** @return string[] */
    public function get_errors(): array {
        return $this->errors;
    }

    /** @return string[] */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /** @return string|null */
    public function get_reason(): ?string {
        return $this->reason;
    }

    /**
     * Return true if the failure was caused by response truncation.
     * Used by session_analyser to decide whether to retry with higher max_tokens.
     *
     * @return bool
     */
    public function is_truncated(): bool {
        return $this->reason === self::REASON_TRUNCATED;
    }

    /**
     * Return a single string summarising all errors, for logging.
     *
     * @return string
     */
    public function get_error_summary(): string {
        return implode('; ', $this->errors);
    }
}
