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
 * The renderer also exposes helper methods used directly by templates
 * via {{#renderhelper}} calls — specifically the dashboard panel renderer
 * which converts a LID JSON string into the HTML panel markup.
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
     * Used by forum_lid and student_lid templates to render individual
     * post analysis cards inline. Returns an empty string if the JSON
     * is null or unparseable — the template handles the null state itself.
     *
     * @param  string|null $analysisjson  LID Schema v1.0 JSON string.
     * @param  array       $options       Optional rendering options:
     *                                    'compact' => bool (smaller card variant)
     *                                    'show_portfolio' => bool (default true)
     *                                    'show_timeline' => bool (default true)
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
            'status'       => $status,
            'status_label' => $labels[$status] ?? $status,
            'is_pending'   => $status === 'pending',
            'is_processing' => $status === 'processing',
            'is_complete'  => $status === 'complete',
            'is_error'     => $status === 'error',
        ]);
    }

    // =========================================================================
    // Private — template data preparation
    // =========================================================================

    /**
     * Prepare a LID JSON data array for the analysis_card template.
     *
     * Flattens and augments the decoded LID JSON into a template-friendly
     * structure. All numeric values are cast to ensure Mustache renders
     * them correctly (Mustache treats 0 as falsy in some engines).
     *
     * @param  array $data     Decoded LID JSON array.
     * @param  array $options  Rendering options.
     * @return array           Template data.
     */
    private function prepare_card_data(array $data, array $options): array {
        $compact        = (bool) ($options['compact']         ?? false);
        $showportfolio  = (bool) ($options['show_portfolio']  ?? true);
        $showtimeline   = (bool) ($options['show_timeline']   ?? true);

        $session = $data['session'] ?? [];
        $scores  = $data['scores']  ?? [];
        $roi     = $data['roi']     ?? [];
        $meta    = $data['meta']    ?? [];

        // Prepare competency bars — sorted by score descending, capped at 8
        // for the compact view to keep the card readable.
        $competencies = $data['competencies'] ?? [];
        usort($competencies, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        if ($compact) {
            $competencies = array_slice($competencies, 0, 5);
        }

        // Prepare radar axes — already sorted by aggregator.
        $radaraxes = $data['radar']['axes'] ?? [];

        // Prepare Bloom's progression — ensure all 6 levels are present,
        // filling gaps with inactive entries so the grid always renders fully.
        $bloomsmap = [];
        foreach ($data['blooms_progression'] ?? [] as $entry) {
            $level = (int) ($entry['level'] ?? 0);
            if ($level >= 1 && $level <= 6) {
                $bloomsmap[$level] = $entry;
            }
        }
        $bloomdefaults = [
            1 => ['label' => 'Remember',   'icon' => '📖'],
            2 => ['label' => 'Understand',  'icon' => '💡'],
            3 => ['label' => 'Apply',       'icon' => '🔧'],
            4 => ['label' => 'Analyze',     'icon' => '🔍'],
            5 => ['label' => 'Evaluate',    'icon' => '⚖️'],
            6 => ['label' => 'Create',      'icon' => '🏗️'],
        ];
        $bloomsprogression = [];
        for ($l = 1; $l <= 6; $l++) {
            $entry = $bloomsmap[$l] ?? [];
            $bloomsprogression[] = [
                'level'       => $l,
                'label'       => $entry['label']       ?? $bloomdefaults[$l]['label'],
                'icon'        => $entry['icon']        ?? $bloomdefaults[$l]['icon'],
                'title'       => $entry['title']       ?? '',
                'description' => $entry['description'] ?? '',
                'dots_active' => (int) ($entry['dots_active'] ?? 0),
                'dot_color'   => $entry['dot_color']   ?? 'cyan',
                'is_active'   => isset($bloomsmap[$l]),
                // Dot booleans for Mustache (no logic in templates).
                'dot1' => (int) ($entry['dots_active'] ?? 0) >= 1,
                'dot2' => (int) ($entry['dots_active'] ?? 0) >= 2,
                'dot3' => (int) ($entry['dots_active'] ?? 0) >= 3,
                'dot4' => (int) ($entry['dots_active'] ?? 0) >= 4,
                'dot5' => (int) ($entry['dots_active'] ?? 0) >= 5,
            ];
        }

        // ROI display values.
        $readinesscolors = [
            'LOW'         => 'secondary',
            'MEDIUM'      => 'warning',
            'HIGH'        => 'success',
            'EXCEPTIONAL' => 'info',
        ];
        $readiness      = strtoupper($roi['application_readiness'] ?? 'LOW');
        $readinesscolor = $readinesscolors[$readiness] ?? 'secondary';

        return [
            // Card metadata.
            'compact'          => $compact,
            'show_portfolio'   => $showportfolio && !$compact,
            'show_timeline'    => $showtimeline  && !$compact,
            'schema_version'   => $data['schema_version'] ?? '1.0',

            // Session block.
            'session_title'        => format_string($session['title']         ?? ''),
            'session_date'         => $session['date']                        ?? '',
            'session_source'       => format_string($session['source']        ?? ''),
            'session_source_type'  => $session['source_type']                 ?? '',
            'session_duration'     => (int) ($session['duration_minutes']     ?? 0),
            'session_summary'      => format_string($session['topic_summary'] ?? ''),
            'session_tags'         => $this->prepare_tags($session['tags']    ?? []),

            // Scores block.
            'cognitive_depth'      => (int) ($scores['cognitive_depth_score']   ?? 0),
            'strategic_thinking'   => (int) ($scores['strategic_thinking_pct']  ?? 0),
            'roi_awareness'        => (int) ($scores['roi_awareness_pct']        ?? 0),
            'engagement_score'     => (int) ($scores['engagement_score']         ?? 0),
            'meta_cognition'       => (int) ($scores['meta_cognition_score']     ?? 0),
            'domains_count'        => (int) ($scores['competency_domains_count'] ?? 0),

            // Competencies.
            'competencies'         => $this->prepare_competencies($competencies),

            // Radar.
            'radar_axes_json'      => json_encode($radaraxes),
            'has_radar'            => !empty($radaraxes),

            // Bloom's.
            'blooms_progression'   => $bloomsprogression,

            // ROI.
            'knowledge_value'      => number_format((int) ($roi['knowledge_value_usd']        ?? 0)),
            'efficiency_mult'      => number_format((float) ($roi['time_efficiency_multiplier'] ?? 0), 1),
            'retention_pct'        => (int) ($roi['retention_probability_pct']                ?? 0),
            'application_readiness' => $readiness,
            'readiness_color'      => $readinesscolor,
            'employer_value_index' => number_format((float) ($roi['employer_value_index']     ?? 0), 1),
            'lms_hours'            => number_format((float) ($roi['lms_equivalent_hours']     ?? 0), 1),
            'session_hours'        => number_format((float) ($roi['session_hours']            ?? 0), 1),

            // Employer value.
            'employer_value'       => $data['employer_value'] ?? [],
            'has_employer_value'   => !empty($data['employer_value']),

            // Timeline.
            'timeline'             => $data['timeline'] ?? [],
            'has_timeline'         => !empty($data['timeline']),

            // Portfolio.
            'portfolio_title'      => format_string($data['portfolio']['title']    ?? ''),
            'portfolio_subtitle'   => $data['portfolio']['subtitle']               ?? '',
            'portfolio_tags'       => $this->prepare_tags($data['portfolio']['primary_tags'] ?? []),
            'portfolio_formats'    => $data['portfolio']['documentation_formats']  ?? [],
            'has_portfolio'        => !empty($data['portfolio']['title']),

            // Meta.
            'meta_generated_by'    => $meta['generated_by'] ?? '',
            'meta_generated_at'    => $meta['generated_at'] ?? '',
            'meta_confidence'      => $meta['confidence']   ?? '',
            'meta_notes'           => $meta['notes']        ?? '',
            'has_meta_notes'       => !empty($meta['notes']),
            'confidence_high'      => ($meta['confidence'] ?? '') === 'HIGH',
            'confidence_medium'    => ($meta['confidence'] ?? '') === 'MEDIUM',
            'confidence_low'       => ($meta['confidence'] ?? '') === 'LOW',
        ];
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
     * Adds computed fields: bar width percentage, color CSS class,
     * bloom label string.
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

            $result[] = [
                'name'        => format_string($comp['name'] ?? ''),
                'score'       => $score,
                'bar_width'   => $score,   // CSS width percentage.
                'color'       => $color,
                'color_class' => $colorclasses[$color] ?? 'lid-comp-cyan',
                'bloom_level' => (int) ($comp['bloom_level'] ?? 1),
                'bloom_label' => $comp['bloom_label'] ?? '',
                'frameworks'  => $this->prepare_tags($comp['frameworks'] ?? []),
                'tags'        => $this->prepare_tags($comp['tags'] ?? []),
                'has_frameworks' => !empty($comp['frameworks']),
            ];
        }

        return $result;
    }
}
