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
 * LID Schema validator for local_lid.
 *
 * Supports Schema v1.0, v1.1 (portfolio/session analyses) and
 * Schema v1.2 (forum discussion analyses).
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\analysis;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates a raw LLM response string against LID Schema v1.0, v1.1, or v1.2.
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
 * Schema version compatibility:
 *   v1.0 — original schema; cognitive_performance_index absent (optional).
 *   v1.1 — adds cognitive_performance_index with CPI score, band, and
 *           component scores.
 *   v1.2 — forum discussion analyzer schema. Replaces roi with
 *           discussion_value, replaces employer_value+portfolio with
 *           instructor_notes, adds critical_discourse_score to scores.
 *
 * Validation branches on schema_version detected in the JSON:
 *   v1.0/v1.1 → portfolio/session validation path
 *   v1.2      → forum discussion validation path
 *
 * Usage:
 *   $validator = new \local_lid\analysis\schema_validator();
 *   $result    = $validator->validate($rawstring);
 *
 *   if ($result->is_valid()) {
 *       $json = $result->get_data();
 *   } else {
 *       $errors = $result->get_errors();
 *   }
 *
 *   $warnings = $result->get_warnings();
 */
class schema_validator {

    /** @var string Default/current schema version produced by the v1.2 prompt. */
    const SCHEMA_VERSION = '1.2';

    /** @var string[] All schema versions this validator accepts. */
    const SUPPORTED_VERSIONS = ['1.0', '1.1', '1.2'];

    // -------------------------------------------------------------------------
    // Required keys — v1.0 / v1.1 (portfolio/session path)
    // -------------------------------------------------------------------------

    private const REQUIRED_ROOT_KEYS_V1 = [
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

    private const REQUIRED_SCORES_KEYS_V1 = [
        'competency_domains_count',
        'cognitive_depth_score',
        'strategic_thinking_pct',
        'roi_awareness_pct',
        'engagement_score',
        'meta_cognition_score',
    ];

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

    // -------------------------------------------------------------------------
    // Required keys — v1.2 (forum discussion path)
    // -------------------------------------------------------------------------

    private const REQUIRED_ROOT_KEYS_V12 = [
        'schema_version',
        'session',
        'scores',
        'competencies',
        'radar',
        'blooms_progression',
        'discussion_value',
        'cognitive_performance_index',
        'timeline',
        'instructor_notes',
        'meta',
    ];

    private const REQUIRED_SCORES_KEYS_V12 = [
        'competency_domains_count',
        'cognitive_depth_score',
        'critical_discourse_score',
        'strategic_thinking_pct',
        'engagement_score',
        'meta_cognition_score',
    ];

    private const REQUIRED_DISCUSSION_VALUE_KEYS = [
        'application_readiness',
        'participation_depth',
        'session_hours',
        'discussion_contribution_index',
        'dci_components',
        'retention_indicators',
    ];

    private const REQUIRED_DCI_COMPONENT_KEYS = [
        'idea_originality',
        'reasoning_transparency',
        'peer_advancement',
        'critical_challenge',
        'knowledge_integration',
    ];

    private const REQUIRED_INSTRUCTOR_NOTES_KEYS = [
        'participation_summary',
        'standout_moments',
        'growth_indicators',
        'constructive_feedback',
    ];

    // -------------------------------------------------------------------------
    // Shared required keys
    // -------------------------------------------------------------------------

    private const REQUIRED_CPI_KEYS = [
        'cpi_score',
        'cpi_band',
        'cpi_band_description',
        'calculation_note',
    ];

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

    private const REQUIRED_COMPETENCY_KEYS = [
        'name',
        'score',
        'color',
        'bloom_level',
        'bloom_label',
    ];

    private const REQUIRED_BLOOMS_KEYS = [
        'level',
        'label',
        'title',
        'description',
        'dots_active',
    ];

    // -------------------------------------------------------------------------
    // Enum constants (shared)
    // -------------------------------------------------------------------------

    private const VALID_CPI_BANDS = [
        'Foundational',
        'Developing',
        'Proficient',
        'Advanced',
        'Exceptional',
    ];

    private const VALID_READINESS    = ['LOW', 'MEDIUM', 'HIGH', 'EXCEPTIONAL'];
    private const VALID_DEPTH        = ['LOW', 'MEDIUM', 'HIGH'];
    private const VALID_COLORS       = ['cyan', 'green', 'orange', 'purple'];
    private const VALID_DOT_COLORS   = ['cyan', 'green', 'orange', 'purple'];

    private const BLOOMS_LEVEL_MAP = [
        1 => 'Remember',
        2 => 'Understand',
        3 => 'Apply',
        4 => 'Analyze',
        5 => 'Evaluate',
        6 => 'Create',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate a raw LLM response string.
     *
     * Strips markdown fences, detects truncation, decodes JSON, checks
     * schema_version, then branches to the appropriate validation path.
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
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            return validation_result::fatal(
                [get_string('error_llm_schema_mismatch', 'local_lid', $version ?? 'missing')],
                $warnings,
                validation_result::REASON_SCHEMA_MISMATCH
            );
        }

        // Step 5 — branch on schema version.
        if ($version === '1.2') {
            return $this->validate_v12($data, $errors, $warnings);
        }

        return $this->validate_v1($data, $errors, $warnings);
    }

    // -------------------------------------------------------------------------
    // v1.0 / v1.1 validation path
    // -------------------------------------------------------------------------

    /**
     * Validate a decoded v1.0 or v1.1 JSON object.
     *
     * @param  array  $data
     * @param  array  $errors
     * @param  array  $warnings
     * @return validation_result
     */
    private function validate_v1(array $data, array $errors, array $warnings): validation_result {

        // Required root keys.
        foreach (self::REQUIRED_ROOT_KEYS_V1 as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                $errors[] = "Missing required root key: {$key}";
            }
        }

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_MISSING_KEYS);
        }

        // Sub-object validation.
        $this->validate_session($data['session'], $errors, $warnings);
        $this->validate_scores_v1($data['scores'], $errors, $warnings);
        $this->validate_competencies($data['competencies'], $errors, $warnings);
        $this->validate_radar($data['radar'], $errors, $warnings);
        $this->validate_blooms_progression($data['blooms_progression'], $errors, $warnings);
        $this->validate_roi($data['roi'], $errors, $warnings);
        $this->validate_timeline($data['timeline'], $warnings);
        $this->validate_employer_value($data['employer_value'], $warnings);
        $this->validate_portfolio($data['portfolio'], $warnings);
        $this->validate_meta($data['meta'], $warnings);

        // CPI is optional in v1.0; required in v1.1 but tolerated as absent for legacy data.
        if (isset($data['cognitive_performance_index'])) {
            $this->validate_cpi($data['cognitive_performance_index'], $errors, $warnings);
        }

        $data = $this->coerce_numerics_v1($data, $warnings);

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_INVALID_STRUCTURE);
        }

        return validation_result::ok($data, $warnings);
    }

    // -------------------------------------------------------------------------
    // v1.2 validation path
    // -------------------------------------------------------------------------

    /**
     * Validate a decoded v1.2 JSON object (forum discussion analyzer output).
     *
     * @param  array  $data
     * @param  array  $errors
     * @param  array  $warnings
     * @return validation_result
     */
    private function validate_v12(array $data, array $errors, array $warnings): validation_result {

        // Required root keys.
        foreach (self::REQUIRED_ROOT_KEYS_V12 as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                $errors[] = "Missing required root key: {$key}";
            }
        }

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_MISSING_KEYS);
        }

        // Sub-object validation.
        $this->validate_session($data['session'], $errors, $warnings);
        $this->validate_scores_v12($data['scores'], $errors, $warnings);
        $this->validate_competencies($data['competencies'], $errors, $warnings);
        $this->validate_radar($data['radar'], $errors, $warnings);
        $this->validate_blooms_progression($data['blooms_progression'], $errors, $warnings);
        $this->validate_discussion_value($data['discussion_value'], $errors, $warnings);
        $this->validate_cpi($data['cognitive_performance_index'], $errors, $warnings);
        $this->validate_timeline($data['timeline'], $warnings);
        $this->validate_instructor_notes($data['instructor_notes'], $warnings);
        $this->validate_meta($data['meta'], $warnings);

        $data = $this->coerce_numerics_v12($data, $warnings);

        if (!empty($errors)) {
            return validation_result::fatal($errors, $warnings, validation_result::REASON_INVALID_STRUCTURE);
        }

        return validation_result::ok($data, $warnings);
    }

    // -------------------------------------------------------------------------
    // Shared sub-object validators
    // -------------------------------------------------------------------------

    /**
     * Validate the session object.
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

        if (isset($session['tags']) && !is_array($session['tags'])) {
            $warnings[] = 'session.tags should be an array; received ' . gettype($session['tags']);
        }

        if (!empty($session['date']) && !$this->is_iso8601_date($session['date'])) {
            $warnings[] = 'session.date does not appear to be a valid ISO 8601 date: ' . $session['date'];
        }
    }

    /**
     * Validate the scores object for v1.0/v1.1.
     */
    private function validate_scores_v1($scores, array &$errors, array &$warnings): void {
        if (!is_array($scores)) {
            $errors[] = 'scores must be an object.';
            return;
        }

        foreach (self::REQUIRED_SCORES_KEYS_V1 as $key) {
            if (!array_key_exists($key, $scores)) {
                $errors[] = "Missing required scores key: {$key}";
                continue;
            }
            $val = $scores[$key];
            if (!is_numeric($val)) {
                $warnings[] = "scores.{$key} should be numeric; received: " . gettype($val);
            } elseif ((float)$val < 0 || (float)$val > 100) {
                $warnings[] = "scores.{$key} is out of range 0–100: {$val} (will be clamped on render)";
            }
        }
    }

    /**
     * Validate the scores object for v1.2.
     */
    private function validate_scores_v12($scores, array &$errors, array &$warnings): void {
        if (!is_array($scores)) {
            $errors[] = 'scores must be an object.';
            return;
        }

        foreach (self::REQUIRED_SCORES_KEYS_V12 as $key) {
            if (!array_key_exists($key, $scores)) {
                $errors[] = "Missing required scores key: {$key}";
                continue;
            }
            $val = $scores[$key];
            if (!is_numeric($val)) {
                $warnings[] = "scores.{$key} should be numeric; received: " . gettype($val);
            } elseif ((float)$val < 0 || (float)$val > 100) {
                $warnings[] = "scores.{$key} is out of range 0–100: {$val} (will be clamped on render)";
            }
        }
    }

    /**
     * Validate the competencies array.
     */
    private function validate_competencies($competencies, array &$errors, array &$warnings): void {
        if (!is_array($competencies)) {
            $errors[] = 'competencies must be an array.';
            return;
        }

        if (empty($competencies)) {
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

            if (isset($comp['score']) && is_numeric($comp['score'])) {
                if ((float)$comp['score'] < 0 || (float)$comp['score'] > 100) {
                    $warnings[] = "{$prefix}.score is out of range 0–100: {$comp['score']}";
                }
            }

            if (isset($comp['color']) && !in_array($comp['color'], self::VALID_COLORS, true)) {
                $warnings[] = "{$prefix}.color '{$comp['color']}' is not a recognised value.";
            }

            if (isset($comp['bloom_level'])) {
                $level = (int) $comp['bloom_level'];
                if ($level < 1 || $level > 6) {
                    $warnings[] = "{$prefix}.bloom_level {$level} is out of range 1–6.";
                }
            }

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

            if (isset($entry['level'])) {
                $level = (int) $entry['level'];
                if ($level < 1 || $level > 6) {
                    $warnings[] = "{$prefix}.level {$level} is out of range 1–6.";
                }
            }

            if (isset($entry['dots_active'])) {
                $dots = (int) $entry['dots_active'];
                if ($dots < 0 || $dots > 5) {
                    $warnings[] = "{$prefix}.dots_active {$dots} is out of range 0–5.";
                }
            }

            if (isset($entry['dot_color']) && !in_array($entry['dot_color'], self::VALID_DOT_COLORS, true)) {
                $warnings[] = "{$prefix}.dot_color '{$entry['dot_color']}' is not a recognised value.";
            }
        }
    }

    /**
     * Validate the roi object (v1.0/v1.1 only).
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

        if (isset($roi['application_readiness']) &&
            !in_array($roi['application_readiness'], self::VALID_READINESS, true)) {
            $warnings[] = "roi.application_readiness '{$roi['application_readiness']}' is not a " .
                "recognised value; expected: " . implode(', ', self::VALID_READINESS);
        }

        if (isset($roi['employer_value_index']) && is_numeric($roi['employer_value_index'])) {
            $val = (float) $roi['employer_value_index'];
            if ($val < 0.0 || $val > 10.0) {
                $warnings[] = "roi.employer_value_index {$val} is out of range 0.0–10.0.";
            }
        }

        foreach (['knowledge_value_usd', 'time_efficiency_multiplier', 'engagement_score',
                  'retention_probability_pct', 'lms_equivalent_hours', 'session_hours'] as $key) {
            if (isset($roi[$key]) && !is_numeric($roi[$key])) {
                $warnings[] = "roi.{$key} should be numeric; received: " . gettype($roi[$key]);
            }
        }
    }

    /**
     * Validate the discussion_value object (v1.2 only).
     */
    private function validate_discussion_value($dv, array &$errors, array &$warnings): void {
        if (!is_array($dv)) {
            $errors[] = 'discussion_value must be an object.';
            return;
        }

        foreach (self::REQUIRED_DISCUSSION_VALUE_KEYS as $key) {
            if (!array_key_exists($key, $dv) || $dv[$key] === null) {
                $errors[] = "Missing required discussion_value key: {$key}";
            }
        }

        if (isset($dv['application_readiness']) &&
            !in_array($dv['application_readiness'], self::VALID_READINESS, true)) {
            $warnings[] = "discussion_value.application_readiness '{$dv['application_readiness']}' " .
                "is not a recognised value.";
        }

        if (isset($dv['participation_depth']) &&
            !in_array($dv['participation_depth'], self::VALID_DEPTH, true)) {
            $warnings[] = "discussion_value.participation_depth '{$dv['participation_depth']}' " .
                "is not a recognised value; expected: LOW, MEDIUM, HIGH.";
        }

        if (isset($dv['discussion_contribution_index']) && is_numeric($dv['discussion_contribution_index'])) {
            $val = (float) $dv['discussion_contribution_index'];
            if ($val < 0.0 || $val > 10.0) {
                $warnings[] = "discussion_value.discussion_contribution_index {$val} is out of range 0.0–10.0.";
            }
        }

        // dci_components.
        if (isset($dv['dci_components'])) {
            if (!is_array($dv['dci_components'])) {
                $errors[] = 'discussion_value.dci_components must be an object.';
            } else {
                foreach (self::REQUIRED_DCI_COMPONENT_KEYS as $key) {
                    if (!array_key_exists($key, $dv['dci_components'])) {
                        $errors[] = "Missing required dci_components key: {$key}";
                    } elseif (is_numeric($dv['dci_components'][$key])) {
                        $val = (float) $dv['dci_components'][$key];
                        if ($val < 0.0 || $val > 2.0) {
                            $warnings[] = "dci_components.{$key} {$val} is out of range 0.0–2.0.";
                        }
                    }
                }
            }
        }

        // retention_indicators.
        if (isset($dv['retention_indicators'])) {
            if (!is_array($dv['retention_indicators'])) {
                $warnings[] = 'discussion_value.retention_indicators should be an object.';
            } else {
                if (!isset($dv['retention_indicators']['factors_present']) ||
                    !is_array($dv['retention_indicators']['factors_present'])) {
                    $warnings[] = 'discussion_value.retention_indicators.factors_present should be an array.';
                }
                if (!isset($dv['retention_indicators']['factors_absent']) ||
                    !is_array($dv['retention_indicators']['factors_absent'])) {
                    $warnings[] = 'discussion_value.retention_indicators.factors_absent should be an array.';
                }
            }
        }
    }

    /**
     * Validate the instructor_notes object (v1.2 only).
     */
    private function validate_instructor_notes($notes, array &$warnings): void {
        if (!is_array($notes)) {
            $warnings[] = 'instructor_notes should be an object.';
            return;
        }

        foreach (self::REQUIRED_INSTRUCTOR_NOTES_KEYS as $key) {
            if (empty($notes[$key])) {
                $warnings[] = "instructor_notes.{$key} is missing or empty.";
            }
        }

        if (isset($notes['standout_moments']) && is_array($notes['standout_moments'])) {
            foreach ($notes['standout_moments'] as $i => $moment) {
                if (!is_array($moment) || empty($moment['observation'])) {
                    $warnings[] = "instructor_notes.standout_moments[{$i}] is missing observation.";
                }
                if (isset($moment['type']) &&
                    !in_array($moment['type'], ['Strength', 'Growth Opportunity'], true)) {
                    $warnings[] = "instructor_notes.standout_moments[{$i}].type should be " .
                        "'Strength' or 'Growth Opportunity'.";
                }
            }
        }
    }

    /**
     * Validate the cognitive_performance_index object.
     */
    private function validate_cpi($cpi, array &$errors, array &$warnings): void {
        if (!is_array($cpi)) {
            $errors[] = 'cognitive_performance_index must be an object.';
            return;
        }

        foreach (self::REQUIRED_CPI_KEYS as $key) {
            if (!array_key_exists($key, $cpi) || $cpi[$key] === null || $cpi[$key] === '') {
                $errors[] = "Missing required cognitive_performance_index key: {$key}";
            }
        }

        if (isset($cpi['cpi_score'])) {
            $score = (int) $cpi['cpi_score'];
            if ($score < 70 || $score > 145) {
                $warnings[] = "cognitive_performance_index.cpi_score {$score} is out of range 70–145.";
            }
        }

        if (isset($cpi['cpi_band']) && !in_array($cpi['cpi_band'], self::VALID_CPI_BANDS, true)) {
            $warnings[] = "cognitive_performance_index.cpi_band '{$cpi['cpi_band']}' " .
                "is not a recognised value.";
        }

        if (isset($cpi['component_scores']) && is_array($cpi['component_scores'])) {
            foreach ($cpi['component_scores'] as $field => $val) {
                if (is_numeric($val) && ((float)$val < 0 || (float)$val > 100)) {
                    $warnings[] = "cognitive_performance_index.component_scores.{$field} " .
                        "is out of range 0–100: {$val}";
                }
            }
        }

        if (!empty($cpi['calculation_note'])) {
            $required = 'Not a measure of general intelligence';
            if (strpos($cpi['calculation_note'], $required) === false) {
                $warnings[] = 'cognitive_performance_index.calculation_note is missing the ' .
                    'required disclaimer about general intelligence.';
            }
        }
    }

    /**
     * Validate the timeline array.
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
     * Validate the employer_value array (v1.0/v1.1 only).
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
     * Validate the portfolio object (v1.0/v1.1 only).
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
     * Validate the meta object.
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
    // Numeric coercion — v1.0/v1.1
    // -------------------------------------------------------------------------

    /**
     * Coerce numeric string values to correct PHP types for v1.0/v1.1 data.
     */
    private function coerce_numerics_v1(array $data, array &$warnings): array {
        $coerced = false;

        if (isset($data['scores']) && is_array($data['scores'])) {
            foreach (self::REQUIRED_SCORES_KEYS_V1 as $key) {
                if (isset($data['scores'][$key]) && is_string($data['scores'][$key]) &&
                    is_numeric($data['scores'][$key])) {
                    $data['scores'][$key] = (int) $data['scores'][$key];
                    $coerced = true;
                }
            }
        }

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

        $data = $this->coerce_shared_numerics($data, $coerced);

        if ($coerced) {
            $warnings[] = 'One or more numeric fields were returned as strings by the LLM and have been coerced.';
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Numeric coercion — v1.2
    // -------------------------------------------------------------------------

    /**
     * Coerce numeric string values to correct PHP types for v1.2 data.
     */
    private function coerce_numerics_v12(array $data, array &$warnings): array {
        $coerced = false;

        if (isset($data['scores']) && is_array($data['scores'])) {
            foreach (self::REQUIRED_SCORES_KEYS_V12 as $key) {
                if (isset($data['scores'][$key]) && is_string($data['scores'][$key]) &&
                    is_numeric($data['scores'][$key])) {
                    $data['scores'][$key] = (int) $data['scores'][$key];
                    $coerced = true;
                }
            }
        }

        // discussion_value numerics.
        if (isset($data['discussion_value']) && is_array($data['discussion_value'])) {
            $dv = &$data['discussion_value'];

            if (isset($dv['session_hours']) && is_string($dv['session_hours']) &&
                is_numeric($dv['session_hours'])) {
                $dv['session_hours'] = (float) $dv['session_hours'];
                $coerced = true;
            }

            if (isset($dv['discussion_contribution_index']) &&
                is_string($dv['discussion_contribution_index']) &&
                is_numeric($dv['discussion_contribution_index'])) {
                $dv['discussion_contribution_index'] = (float) $dv['discussion_contribution_index'];
                $coerced = true;
            }

            if (isset($dv['dci_components']) && is_array($dv['dci_components'])) {
                foreach (self::REQUIRED_DCI_COMPONENT_KEYS as $key) {
                    if (isset($dv['dci_components'][$key]) &&
                        is_string($dv['dci_components'][$key]) &&
                        is_numeric($dv['dci_components'][$key])) {
                        $dv['dci_components'][$key] = (float) $dv['dci_components'][$key];
                        $coerced = true;
                    }
                }
            }

            unset($dv);
        }

        $data = $this->coerce_shared_numerics($data, $coerced);

        if ($coerced) {
            $warnings[] = 'One or more numeric fields were returned as strings by the LLM and have been coerced.';
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Shared numeric coercion (competencies, radar, blooms, CPI)
    // -------------------------------------------------------------------------

    /**
     * Coerce numeric strings in sections shared across all schema versions.
     */
    private function coerce_shared_numerics(array $data, bool &$coerced): array {

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

        // cognitive_performance_index numeric fields.
        if (isset($data['cognitive_performance_index']) &&
            is_array($data['cognitive_performance_index'])) {
            $cpi = &$data['cognitive_performance_index'];

            if (isset($cpi['cpi_score']) && is_string($cpi['cpi_score']) &&
                is_numeric($cpi['cpi_score'])) {
                $cpi['cpi_score'] = (int) $cpi['cpi_score'];
                $coerced = true;
            }
            if (isset($cpi['cpi_score'])) {
                $cpi['cpi_score'] = max(70, min(145, (int) $cpi['cpi_score']));
            }

            if (isset($cpi['component_scores']) && is_array($cpi['component_scores'])) {
                foreach ($cpi['component_scores'] as $field => $val) {
                    if (is_string($val) && is_numeric($val)) {
                        $cpi['component_scores'][$field] = (int) $val;
                        $coerced = true;
                    }
                }
            }

            unset($cpi);
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strip markdown code fences the LLM may have added.
     */
    private function strip_fences(string $raw): string {
        $trimmed = trim($raw);

        if (preg_match('/^```(?:json)?\s*\n?([\s\S]*?)\n?```$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^`([\s\S]*)`$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    /**
     * Detect a truncated JSON response.
     */
    private function is_truncated(string $text): bool {
        $trimmed = trim($text);

        if (empty($trimmed)) {
            return false;
        }

        if ($trimmed[0] !== '{') {
            return false;
        }

        $last = substr($trimmed, -1);

        if ($last !== '}') {
            return true;
        }

        json_decode($trimmed);
        if (json_last_error() === JSON_ERROR_CTRL_CHAR ||
            json_last_error() === JSON_ERROR_SYNTAX) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a string is a plausible ISO 8601 date or datetime.
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
 */
class validation_result {

    const REASON_TRUNCATED         = 'truncated';
    const REASON_INVALID_JSON      = 'invalid_json';
    const REASON_SCHEMA_MISMATCH   = 'schema_mismatch';
    const REASON_MISSING_KEYS      = 'missing_keys';
    const REASON_INVALID_STRUCTURE = 'invalid_structure';

    private bool $valid;
    private ?array $data;
    private array $errors;
    private array $warnings;
    private ?string $reason;

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

    public static function ok(array $data, array $warnings = []): self {
        return new self(true, $data, [], $warnings, null);
    }

    public static function fatal(array $errors, array $warnings = [], string $reason = self::REASON_INVALID_JSON): self {
        return new self(false, null, $errors, $warnings, $reason);
    }

    public function is_valid(): bool {
        return $this->valid;
    }

    public function get_data(): ?array {
        return $this->data;
    }

    public function get_errors(): array {
        return $this->errors;
    }

    public function get_warnings(): array {
        return $this->warnings;
    }

    public function get_reason(): ?string {
        return $this->reason;
    }

    public function is_truncated(): bool {
        return $this->reason === self::REASON_TRUNCATED;
    }

    public function get_error_summary(): string {
        return implode('; ', $this->errors);
    }
}
