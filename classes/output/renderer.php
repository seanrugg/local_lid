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
 * Renderer for local_lid.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer class for local_lid.
 *
 * Routes render() calls to the appropriate Mustache template by matching
 * the renderable class. All three dashboard surfaces share the same
 * render dispatch pattern — the output class provides the template data,
 * the template name is derived from the class name.
 *
 * Template name mapping:
 *   course_lid_page   → local_lid/course_lid
 *   forum_lid_page    → local_lid/forum_lid
 *   student_lid_page  → local_lid/student_lid
 *
 * Schema version handling:
 *   v1.0 / v1.1 — session analyzer output. ROI block, employer_value, portfolio.
 *   v1.2        — forum discussion analyzer output. discussion_value block (DCI,
 *                 participation_depth, retention_indicators), instructor_notes.
 *                 ROI, employer_value, and portfolio are absent.
 */
class renderer extends \plugin_renderer_base {

    // =========================================================================
    // Renderable dispatch
    // =========================================================================

    /**
     * Render the Course LID dashboard page.
     *
     * @param  course_lid_page $page
     * @return string HTML
     */
    public function render_course_lid_page(course_lid_page $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('local_lid/course_lid', $data);
    }

    /**
     * Render the Forum LID dashboard page.
     *
     * @param  forum_lid_page $page
     * @return string HTML
     */
    public function render_forum_lid_page(forum_lid_page $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('local_lid/forum_lid', $data);
    }

    /**
     * Render the Student LID dashboard page.
     *
     * @param  student_lid_page $page
     * @return string HTML
     */
    public function render_student_lid_page(student_lid_page $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('local_lid/student_lid', $data);
    }

    // =========================================================================
    // Shared panel helpers — called from entry point pages and AJAX responses
    // =========================================================================

    /**
     * Render a single LID analysis card from a JSON string.
     *
     * Supports Schema v1.0, v1.1 (session analyzer), and v1.2 (forum
     * discussion analyzer). Schema version is detected from the JSON and
     * the appropriate template fields are populated accordingly.
     *
     * Returns an empty string if the JSON is null or unparseable.
     *
     * @param  string|null $analysisjson  LID Schema JSON string.
     * @param  array       $options       Optional rendering options:
     *                                    'compact'          => bool (smaller card variant)
     *                                    'show_portfolio'   => bool (default true)
     *                                    'show_timeline'    => bool (default true)
     * @return string HTML
     */
    public function render_analysis_card(?string $analysisjson, array $options = []): string {
        if (empty($analysisjson)) {
            return '';
        }

        $data = json_decode($analysisjson, true);
        if (!is_array($data)) {
            return '';
        }

        $templatedata = $this->prepare_card_data($data, $options);
        return $this->render_from_template('local_lid/analysis_card', $templatedata);
    }

    /**
     * Render a status badge for an analysis record.
     *
     * @param  string $status  One of: pending, processing, complete, error.
     * @return string HTML
     */
    public function render_status_badge(string $status): string {
        $labels = [
            'pending'    => get_string('status_pending',    'local_lid'),
            'processing' => get_string('status_processing', 'local_lid'),
            'complete'   => get_string('status_complete',   'local_lid'),
            'error'      => get_string('status_error',      'local_lid'),
        ];

        return $this->render_from_template('local_lid/status_badge', [
            'status'        => $status,
            'status_label'  => $labels[$status] ?? $status,
            'is_pending'    => $status === 'pending',
            'is_processing' => $status === 'processing',
            'is_complete'   => $status === 'complete',
            'is_error'      => $status === 'error',
        ]);
    }

    // =========================================================================
    // Private — template data preparation
    // =========================================================================

    /**
     * Prepare a LID JSON data array for the analysis_card template.
     *
     * Branches on schema_version:
     *   v1.0 / v1.1 — populates ROI, employer_value, portfolio blocks.
     *                  Sets has_roi = true, has_discussion_value = false.
     *   v1.2        — populates discussion_value (DCI), instructor_notes.
     *                  Sets has_roi = false, has_discussion_value = true.
     *                  employer_value and portfolio are suppressed.
     *
     * @param  array $data     Decoded LID JSON array.
     * @param  array $options  Rendering options.
     * @return array           Template data.
     */
    private function prepare_card_data(array $data, array $options): array {
        $compact       = (bool) ($options['compact']        ?? false);
        $showportfolio = (bool) ($options['show_portfolio'] ?? true);
        $showtimeline  = (bool) ($options['show_timeline']  ?? true);

        $schemaversion = $data['schema_version'] ?? '1.0';
        $isv12         = ($schemaversion === '1.2');

        $session = $data['session'] ?? [];
        $scores  = $data['scores']  ?? [];
        $meta    = $data['meta']    ?? [];

        // Competencies — sorted by score descending, capped at 5 for compact.
        $competencies = $data['competencies'] ?? [];
        usort($competencies, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        if ($compact) {
            $competencies = array_slice($competencies, 0, 5);
        }

        // Radar axes.
        $radaraxes = $data['radar']['axes'] ?? [];

        // Bloom's progression — ensure all 6 levels present.
        $bloomsprogression = $this->prepare_blooms($data['blooms_progression'] ?? []);

        // ── Schema-branched blocks ────────────────────────────────────────────

        if ($isv12) {
            $roiblock           = $this->empty_roi_block();
            $discussionvalue    = $this->prepare_discussion_value($data['discussion_value'] ?? []);
            $employervalue      = [];
            $hasemployervalue   = false;
            $instructornotes    = $data['instructor_notes'] ?? '';
            $hasinstructornotes = !empty($instructornotes);
            $hasportfolio       = false;
            $portfoliodata      = $this->empty_portfolio_block();
        } else {
            $roi                = $data['roi'] ?? [];
            $roiblock           = $this->prepare_roi_block($roi);
            $discussionvalue    = $this->empty_discussion_value_block();
            $employervalue      = $data['employer_value'] ?? [];
            $hasemployervalue   = !empty($employervalue);
            $instructornotes    = '';
            $hasinstructornotes = false;
            $hasportfolio       = !empty($data['portfolio']['title']);
            $portfoliodata      = [
                'portfolio_title'   => format_string($data['portfolio']['title']    ?? ''),
                'portfolio_subtitle' => $data['portfolio']['subtitle']              ?? '',
                'portfolio_tags'    => $this->prepare_tags($data['portfolio']['primary_tags'] ?? []),
                'portfolio_formats' => $data['portfolio']['documentation_formats']  ?? [],
            ];
        }

        return [
            // Card metadata.
            'compact'           => $compact,
            'show_portfolio'    => $showportfolio && !$compact && !$isv12,
            'show_timeline'     => $showtimeline  && !$compact,
            'schema_version'    => $schemaversion,
            'is_v12'            => $isv12,

            // Session block.
            'session_title'       => format_string($session['title']         ?? ''),
            'session_date'        => $session['date']                        ?? '',
            'session_source'      => format_string($session['source']        ?? ''),
            'session_source_type' => $session['source_type']                 ?? '',
            'session_duration'    => (int) ($session['duration_minutes']     ?? 0),
            'session_summary'     => format_string($session['topic_summary'] ?? ''),
            'session_tags'        => $this->prepare_tags($session['tags']    ?? []),

            // Scores block.
            'cognitive_depth'     => (int) ($scores['cognitive_depth_score']   ?? 0),
            'strategic_thinking'  => (int) ($scores['strategic_thinking_pct']  ?? 0),
            'roi_awareness'       => (int) ($scores['roi_awareness_pct']        ?? 0),
            'engagement_score'    => (int) ($scores['engagement_score']         ?? 0),
            'meta_cognition'      => (int) ($scores['meta_cognition_score']     ?? 0),
            'domains_count'       => (int) ($scores['competency_domains_count'] ?? 0),

            // Competencies.
            'competencies'        => $this->prepare_competencies($competencies),

            // Radar.
            'radar_axes_json'     => json_encode($radaraxes),
            'has_radar'           => !empty($radaraxes),

            // Bloom's.
            'blooms_progression'  => $bloomsprogression,

            // Schema-branched blocks.
            'has_roi'                => !$isv12,
            'has_discussion_value'   => $isv12,
            'employer_value'         => $employervalue,
            'has_employer_value'     => $hasemployervalue,
            'instructor_notes'       => $instructornotes,
            'has_instructor_notes'   => $hasinstructornotes,
            'has_portfolio'          => $hasportfolio,

            // Timeline.
            'timeline'            => $data['timeline'] ?? [],
            'has_timeline'        => !empty($data['timeline']),

            // Meta.
            'meta_generated_by'   => $meta['generated_by'] ?? '',
            'meta_generated_at'   => $meta['generated_at'] ?? '',
            'meta_confidence'     => $meta['confidence']   ?? '',
            'meta_notes'          => $meta['notes']        ?? '',
            'has_meta_notes'      => !empty($meta['notes']),
            'confidence_high'     => ($meta['confidence'] ?? '') === 'HIGH',
            'confidence_medium'   => ($meta['confidence'] ?? '') === 'MEDIUM',
            'confidence_low'      => ($meta['confidence'] ?? '') === 'LOW',

        ] + $portfoliodata
          + $roiblock
          + $discussionvalue
          + $this->prepare_cpi_data($data['cognitive_performance_index'] ?? null, $isv12);
    }

    /**
     * Prepare ROI block fields for v1.0 / v1.1 cards.
     *
     * @param  array $roi
     * @return array
     */
    private function prepare_roi_block(array $roi): array {
        $readinesscolors = [
            'LOW'         => 'secondary',
            'MEDIUM'      => 'warning',
            'HIGH'        => 'success',
            'EXCEPTIONAL' => 'info',
        ];
        $readiness      = strtoupper($roi['application_readiness'] ?? 'LOW');
        $readinesscolor = $readinesscolors[$readiness] ?? 'secondary';

        return [
            'knowledge_value'       => number_format((int)   ($roi['knowledge_value_usd']          ?? 0)),
            'efficiency_mult'       => number_format((float) ($roi['time_efficiency_multiplier']    ?? 0), 1),
            'retention_pct'         => (int) ($roi['retention_probability_pct']                    ?? 0),
            'application_readiness' => $readiness,
            'readiness_color'       => $readinesscolor,
            'employer_value_index'  => number_format((float) ($roi['employer_value_index']          ?? 0), 1),
            'lms_hours'             => number_format((float) ($roi['lms_equivalent_hours']          ?? 0), 1),
            'session_hours'         => number_format((float) ($roi['session_hours']                 ?? 0), 1),
        ];
    }

    /**
     * Return safe empty ROI defaults for v1.2 cards.
     *
     * @return array
     */
    private function empty_roi_block(): array {
        return [
            'knowledge_value'       => '0',
            'efficiency_mult'       => '0.0',
            'retention_pct'         => 0,
            'application_readiness' => 'LOW',
            'readiness_color'       => 'secondary',
            'employer_value_index'  => '0.0',
            'lms_hours'             => '0.0',
            'session_hours'         => '0.0',
        ];
    }

    /**
     * Prepare discussion_value block fields for v1.2 cards.
     *
     * Schema v1.2 discussion_value structure:
     *   dci                  float  — Discussion Contribution Index (0–100)
     *   dci_band             string — 'Minimal'|'Emerging'|'Contributing'|'Leading'
     *   dci_description      string
     *   participation_depth  object
     *     volume             string — 'LOW'|'MEDIUM'|'HIGH'
     *     session_hours      float
     *     thread_breadth     string — 'LOW'|'MEDIUM'|'HIGH'
     *     depth_rating       string — 'LOW'|'MEDIUM'|'HIGH'
     *   retention_indicators array  — strings
     *
     * @param  array $dv  Decoded discussion_value block.
     * @return array
     */
    private function prepare_discussion_value(array $dv): array {
        $dci     = (float) ($dv['dci']         ?? 0);
        $band    = $dv['dci_band']              ?? '';
        $desc    = $dv['dci_description']       ?? '';
        $depth   = $dv['participation_depth']   ?? [];
        $indicators = $dv['retention_indicators'] ?? [];

        $bandcolors = [
            'Minimal'      => 'muted',
            'Emerging'     => 'orange',
            'Contributing' => 'cyan',
            'Leading'      => 'green',
        ];
        $dcicolor = $bandcolors[$band] ?? 'muted';

        // Map 0–100 DCI to a bar width, clamped.
        $dcibarwidth = max(0, min(100, (int) round($dci)));

        $depthlevels = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3];

        return [
            'dci'                     => number_format($dci, 1),
            'dci_band'                => $band,
            'dci_description'         => $desc,
            'dci_color'               => $dcicolor,
            'dci_bar_width'           => $dcibarwidth,
            'has_dci'                 => ($dci > 0 || !empty($band)),
            'depth_volume'            => strtoupper($depth['volume']        ?? ''),
            'depth_session_hours'     => number_format((float) ($depth['session_hours'] ?? 0), 1),
            'depth_thread_breadth'    => strtoupper($depth['thread_breadth'] ?? ''),
            'depth_rating'            => strtoupper($depth['depth_rating']  ?? ''),
            'depth_volume_level'      => $depthlevels[strtoupper($depth['volume']        ?? '')] ?? 0,
            'depth_breadth_level'     => $depthlevels[strtoupper($depth['thread_breadth'] ?? '')] ?? 0,
            'depth_rating_level'      => $depthlevels[strtoupper($depth['depth_rating']  ?? '')] ?? 0,
            'retention_indicators'    => array_map(fn($s) => ['indicator' => $s], $indicators),
            'has_retention_indicators' => !empty($indicators),
        ];
    }

    /**
     * Return safe empty discussion_value defaults for v1.0/v1.1 cards.
     *
     * @return array
     */
    private function empty_discussion_value_block(): array {
        return [
            'dci'                      => '0.0',
            'dci_band'                 => '',
            'dci_description'          => '',
            'dci_color'                => 'muted',
            'dci_bar_width'            => 0,
            'has_dci'                  => false,
            'depth_volume'             => '',
            'depth_session_hours'      => '0.0',
            'depth_thread_breadth'     => '',
            'depth_rating'             => '',
            'depth_volume_level'       => 0,
            'depth_breadth_level'      => 0,
            'depth_rating_level'       => 0,
            'retention_indicators'     => [],
            'has_retention_indicators' => false,
        ];
    }

    /**
     * Return safe empty portfolio defaults for v1.2 cards.
     *
     * @return array
     */
    private function empty_portfolio_block(): array {
        return [
            'portfolio_title'    => '',
            'portfolio_subtitle' => '',
            'portfolio_tags'     => [],
            'portfolio_formats'  => [],
        ];
    }

    /**
     * Prepare Cognitive Performance Index data for the template.
     *
     * Supports both v1.1 (session analyzer) and v1.2 (forum discussion analyzer)
     * CPI component weight sets. The $isv12 flag switches the default weights
     * when component_weights is absent from the JSON.
     *
     * v1.1 default component weights:
     *   cognitive_depth 0.35 / meta_cognition 0.25 / strategic_thinking 0.20
     *   engagement 0.15 / roi_awareness 0.05
     *
     * v1.2 default component weights (critical_discourse replaces roi_awareness):
     *   cognitive_depth 0.35 / critical_discourse 0.25 / strategic_thinking 0.20
     *   engagement 0.15 / meta_cognition 0.05
     *
     * Band colors map to the dashboard accent palette:
     *   Foundational → muted   (gray)
     *   Developing   → orange
     *   Proficient   → cyan
     *   Advanced     → purple
     *   Exceptional  → green
     *
     * @param  array|null $cpi    Decoded cognitive_performance_index block, or null.
     * @param  bool       $isv12  True if rendering a v1.2 record.
     * @return array
     */
    private function prepare_cpi_data(?array $cpi, bool $isv12 = false): array {
        if (empty($cpi)) {
            return [
                'has_cpi'              => false,
                'cpi_score'            => 0,
                'cpi_band'             => '',
                'cpi_band_description' => '',
                'cpi_calculation_note' => '',
                'cpi_bar_width'        => 0,
                'cpi_color'            => 'muted',
                'cpi_is_exceptional'   => false,
                'cpi_is_advanced'      => false,
                'cpi_is_proficient'    => false,
                'cpi_is_developing'    => false,
                'cpi_is_foundational'  => false,
                'cpi_components'       => [],
                'has_cpi_components'   => false,
            ];
        }

        $score = (int) ($cpi['cpi_score'] ?? 70);
        $band  = $cpi['cpi_band'] ?? '';

        // Map score 70–145 to a 0–100 bar width.
        $barwidth = (int) round(($score - 70) / 75 * 100);
        $barwidth = max(0, min(100, $barwidth));

        $bandcolors = [
            'Foundational' => 'muted',
            'Developing'   => 'orange',
            'Proficient'   => 'cyan',
            'Advanced'     => 'purple',
            'Exceptional'  => 'green',
        ];
        $color = $bandcolors[$band] ?? 'muted';

        // Component labels and default weights differ between v1.1 and v1.2.
        if ($isv12) {
            $componentlabels = [
                'cognitive_depth'    => 'Cognitive depth',
                'critical_discourse' => 'Critical discourse',
                'strategic_thinking' => 'Strategic thinking',
                'engagement'         => 'Engagement',
                'meta_cognition'     => 'Meta-cognition',
            ];
            $defaultweights = [
                'cognitive_depth'    => 0.35,
                'critical_discourse' => 0.25,
                'strategic_thinking' => 0.20,
                'engagement'         => 0.15,
                'meta_cognition'     => 0.05,
            ];
        } else {
            $componentlabels = [
                'cognitive_depth'    => 'Cognitive depth',
                'meta_cognition'     => 'Meta-cognition',
                'strategic_thinking' => 'Strategic thinking',
                'engagement'         => 'Engagement',
                'roi_awareness'      => 'ROI awareness',
            ];
            $defaultweights = [
                'cognitive_depth'    => 0.35,
                'meta_cognition'     => 0.25,
                'strategic_thinking' => 0.20,
                'engagement'         => 0.15,
                'roi_awareness'      => 0.05,
            ];
        }

        $componentweights = $cpi['component_weights'] ?? $defaultweights;
        $componentscores  = $cpi['component_scores']  ?? [];

        $components = [];
        foreach ($componentlabels as $key => $label) {
            $val    = (int) ($componentscores[$key] ?? 0);
            $weight = (float) ($componentweights[$key] ?? 0);
            $components[] = [
                'label'        => $label,
                'score'        => $val,
                'weight_pct'   => (int) round($weight * 100),
                'bar_width'    => $val,
                'contribution' => number_format($val * $weight, 1),
            ];
        }

        return [
            'has_cpi'              => true,
            'cpi_score'            => $score,
            'cpi_band'             => $band,
            'cpi_band_description' => $cpi['cpi_band_description'] ?? '',
            'cpi_calculation_note' => $cpi['calculation_note']     ?? '',
            'cpi_bar_width'        => $barwidth,
            'cpi_color'            => $color,
            'cpi_is_exceptional'   => $band === 'Exceptional',
            'cpi_is_advanced'      => $band === 'Advanced',
            'cpi_is_proficient'    => $band === 'Proficient',
            'cpi_is_developing'    => $band === 'Developing',
            'cpi_is_foundational'  => $band === 'Foundational',
            'cpi_components'       => $components,
            'has_cpi_components'   => !empty($components),
        ];
    }

    /**
     * Prepare Bloom's progression — ensures all 6 levels present.
     *
     * @param  array $raw  Raw blooms_progression array from JSON.
     * @return array
     */
    private function prepare_blooms(array $raw): array {
        $bloomsmap = [];
        foreach ($raw as $entry) {
            $level = (int) ($entry['level'] ?? 0);
            if ($level >= 1 && $level <= 6) {
                $bloomsmap[$level] = $entry;
            }
        }
        $bloomdefaults = [
            1 => ['label' => 'Remember',  'icon' => '📖'],
            2 => ['label' => 'Understand', 'icon' => '💡'],
            3 => ['label' => 'Apply',      'icon' => '🔧'],
            4 => ['label' => 'Analyze',    'icon' => '🔍'],
            5 => ['label' => 'Evaluate',   'icon' => '⚖️'],
            6 => ['label' => 'Create',     'icon' => '🏗️'],
        ];
        $result = [];
        for ($l = 1; $l <= 6; $l++) {
            $entry = $bloomsmap[$l] ?? [];
            $active = (int) ($entry['dots_active'] ?? 0);
            $result[] = [
                'level'       => $l,
                'label'       => $entry['label']       ?? $bloomdefaults[$l]['label'],
                'icon'        => $entry['icon']        ?? $bloomdefaults[$l]['icon'],
                'title'       => $entry['title']       ?? '',
                'description' => $entry['description'] ?? '',
                'dots_active' => $active,
                'dot_color'   => $entry['dot_color']   ?? 'cyan',
                'is_active'   => isset($bloomsmap[$l]),
                'dot1'        => $active >= 1,
                'dot2'        => $active >= 2,
                'dot3'        => $active >= 3,
                'dot4'        => $active >= 4,
                'dot5'        => $active >= 5,
            ];
        }
        return $result;
    }

    /**
     * Prepare a tags array into template-friendly format.
     *
     * @param  array $tags
     * @return array  Array of ['tag' => string] objects.
     */
    private function prepare_tags(array $tags): array {
        return array_map(fn($t) => ['tag' => format_string((string) $t)], $tags);
    }

    /**
     * Prepare competencies array for template rendering.
     *
     * @param  array $competencies
     * @return array
     */
    private function prepare_competencies(array $competencies): array {
        $colorclasses = [
            'cyan'   => 'lid-comp-cyan',
            'green'  => 'lid-comp-green',
            'orange' => 'lid-comp-orange',
            'purple' => 'lid-comp-purple',
        ];

        $result = [];
        foreach ($competencies as $comp) {
            $score = min(100, max(0, (int) ($comp['score'] ?? 0)));
            $color = $comp['color'] ?? 'cyan';

            $name = html_entity_decode($comp['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $name = clean_param($name, PARAM_TEXT);

            $result[] = [
                'name'           => $name,
                'score'          => $score,
                'bar_width'      => $score,
                'color'          => $color,
                'color_class'    => $colorclasses[$color] ?? 'lid-comp-cyan',
                'bloom_level'    => (int) ($comp['bloom_level'] ?? 1),
                'bloom_label'    => $comp['bloom_label'] ?? '',
                'frameworks'     => $this->prepare_tags($comp['frameworks'] ?? []),
                'tags'           => $this->prepare_tags($comp['tags'] ?? []),
                'has_frameworks' => !empty($comp['frameworks']),
            ];
        }

        return $result;
    }
}
