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
 * LID aggregate analyser for local_lid.
 *
 * Computes aggregate LID JSON from individual student_forum and thread
 * analyses using mathematical merging — no additional LLM calls are made.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\analysis;

defined('MOODLE_INTERNAL') || die();

/**
 * Rebuilds aggregate local_lid_analysis records by mathematically merging
 * all complete student_forum analyses within a given scope boundary.
 *
 * Schema version: The aggregator operates on Schema v1.2 records produced
 * by the forum discussion analyzer. Records produced by the legacy session
 * analyzer (Schema v1.0/v1.1) use a different field structure and are not
 * handled by this class — they are rendered directly from their stored JSON.
 *
 * Source scope for aggregation:
 *   student_forum rows are the source for forum and course aggregates.
 *   Post-scope rows no longer exist in the new pipeline — do not query for them.
 *
 * Two public methods:
 *
 *   recompute_forum_aggregate($forumid, $courseid)
 *     Merges all complete student_forum analyses in $forumid.
 *     Scope: 'forum'.
 *
 *   recompute_course_aggregate($courseid)
 *     Merges all complete forum-scope aggregates across all LID-enabled forums.
 *     Scope: 'course'.
 *
 * Aggregation rules (Schema v1.2):
 *
 *   scores.*                       Weighted average by session_hours.
 *   scores.critical_discourse_score Weighted average — new in v1.2.
 *   competencies[]                 Merged by name. Score = weighted average.
 *                                  bloom_level = maximum seen.
 *   radar.axes[]                   Matched by label. Value = weighted average.
 *   blooms_progression[]           Matched by level. dots_active = maximum seen.
 *   discussion_value.session_hours Sum of all analyses.
 *   discussion_value.word_count    Sum of all analyses.
 *   discussion_value.character_count Sum of all analyses.
 *   discussion_value.dci_components Weighted average per dimension.
 *   discussion_value.discussion_contribution_index Recomputed from merged dci_components.
 *   discussion_value.application_readiness Highest value seen.
 *   discussion_value.participation_depth   Highest value seen.
 *   discussion_value.retention_indicators  Union of present factors (deduplicated by name).
 *   instructor_notes               Not aggregated — omitted from aggregate rows.
 *                                  The instructor views individual student records for notes.
 *   timeline[]                     Chronological union, deduplicated by title.
 *   meta.confidence                Lowest confidence seen.
 *   meta.generated_by              Set to 'local_lid aggregator v1.0'.
 *
 * Called by process_queue after each successful batch:
 *   $agg = new \local_lid\analysis\aggregator();
 *   $agg->recompute_forum_aggregate($forumid, $courseid);
 *   $agg->recompute_course_aggregate($courseid);
 */
class aggregator {

    /** @var string Generator tag written into meta.generated_by. */
    const GENERATOR = 'local_lid aggregator v1.0';

    /** @var string Schema version written into aggregated output. */
    const SCHEMA_VERSION = '1.2';

    /** @var array Confidence ranking for min-confidence aggregation. */
    const CONFIDENCE_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];

    /** @var array Application readiness ranking. */
    const READINESS_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'EXCEPTIONAL' => 3];

    /** @var array Participation depth ranking. */
    const DEPTH_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];

    // =========================================================================
    // Public — scope rebuilders
    // =========================================================================

    /**
     * Rebuild the forum aggregate across all student_forum analyses in a forum.
     *
     * Source: scope = 'student_forum', forumid = $forumid, status = 'complete'.
     *
     * @param int $forumid
     * @param int $courseid
     */
    public function recompute_forum_aggregate(int $forumid, int $courseid): void {
        $analyses = $this->load_complete_student_forum($forumid);

        if (empty($analyses)) {
            return;
        }

        $aggregated = $this->merge($analyses, $courseid, $forumid, null);

        $this->upsert_aggregate([
            'scope'    => 'forum',
            'courseid' => $courseid,
            'forumid'  => $forumid,
            'userid'   => null,
        ], $aggregated);
    }

    /**
     * Rebuild the course aggregate across all LID-enabled forums in a course.
     *
     * Source: scope = 'forum' aggregate rows (already computed per-forum).
     * This avoids re-scanning all student_forum rows and ensures the course
     * aggregate reflects the current state of each forum aggregate.
     *
     * @param int $courseid
     */
    public function recompute_course_aggregate(int $courseid): void {
        global $DB;

        $enabledforums = $this->get_enabled_forum_ids($courseid);
        if (empty($enabledforums)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($enabledforums, SQL_PARAMS_NAMED, 'fid');
        $params = array_merge(['courseid' => $courseid], $inparams);

        $records = $DB->get_records_sql(
            "SELECT *
               FROM {local_lid_analysis}
              WHERE scope = 'forum'
                AND courseid = :courseid
                AND status = 'complete'
                AND forumid {$insql}
           ORDER BY timemodified ASC",
            $params
        );

        if (empty($records)) {
            return;
        }

        $analyses = $this->decode_records(array_values($records));
        if (empty($analyses)) {
            return;
        }

        $aggregated = $this->merge($analyses, $courseid, null, null);

        $this->upsert_aggregate([
            'scope'    => 'course',
            'courseid' => $courseid,
            'forumid'  => null,
            'userid'   => null,
        ], $aggregated);
    }

    /**
     * Merge an array of already-decoded LID JSON arrays without persisting.
     *
     * Used by student_lid_page to build a cross-forum student aggregate.
     *
     * @param  array    $analyses  Decoded LID data arrays.
     * @param  int      $courseid
     * @param  int|null $forumid
     * @param  int|null $userid
     * @return array|null
     */
    public function merge_decoded(
        array $analyses,
        int $courseid,
        ?int $forumid,
        ?int $userid
    ): ?array {
        if (empty($analyses)) {
            return null;
        }
        return $this->merge($analyses, $courseid, $forumid, $userid);
    }

    // =========================================================================
    // Private — core merge engine
    // =========================================================================

    /**
     * Merge an array of decoded Schema v1.2 LID JSON objects into one aggregate.
     *
     * @param  array    $analyses  Decoded LID data arrays.
     * @param  int      $courseid
     * @param  int|null $forumid
     * @param  int|null $userid
     * @return array
     */
    private function merge(
        array $analyses,
        int $courseid,
        ?int $forumid,
        ?int $userid
    ): array {

        if (count($analyses) === 1) {
            $single = reset($analyses);
            return $this->stamp_aggregate_meta($single, 1);
        }

        // Weights: use session_hours from discussion_value block.
        $weights = array_map(function ($a) {
            $h = (float) ($a['discussion_value']['session_hours'] ?? 0);
            return $h > 0 ? $h : 1.0;
        }, $analyses);

        $totalweight = array_sum($weights);

        $out = [];
        $out['schema_version']   = self::SCHEMA_VERSION;
        $out['session']          = $this->merge_session($analyses);
        $out['scores']           = $this->merge_scores($analyses, $weights, $totalweight);
        $out['competencies']     = $this->merge_competencies($analyses, $weights, $totalweight);
        $out['radar']            = $this->merge_radar($analyses, $weights, $totalweight);
        $out['blooms_progression'] = $this->merge_blooms($analyses);
        $out['discussion_value'] = $this->merge_discussion_value($analyses, $weights, $totalweight);
        $out['timeline']         = $this->merge_timeline($analyses);

        // instructor_notes is intentionally omitted from aggregates —
        // instructors view individual student records for per-learner notes.

        // CPI: merge if present in source analyses.
        $cpis = array_values(array_filter(
            array_map(fn($a) => $a['cognitive_performance_index'] ?? null, $analyses)
        ));
        if (!empty($cpis)) {
            $out['cognitive_performance_index'] = $this->merge_cpi($cpis);
        }

        $out['meta'] = $this->merge_meta($analyses, count($analyses));

        return $out;
    }

    // =========================================================================
    // Private — section mergers
    // =========================================================================

    /**
     * Build the merged session block.
     *
     * duration_minutes, word_count, character_count are summed.
     * Tags are unioned. Title and topic_summary from the most recent analysis.
     *
     * @param  array $analyses
     * @return array
     */
    private function merge_session(array $analyses): array {
        $latest = end($analyses);
        reset($analyses);

        $tags             = [];
        $totalduration    = 0;
        $totalwords       = 0;
        $totalchars       = 0;

        foreach ($analyses as $a) {
            $session = $a['session'] ?? [];
            $totalduration += (int) ($session['duration_minutes'] ?? 0);
            $totalwords    += (int) ($session['word_count']       ?? 0);
            $totalchars    += (int) ($session['character_count']  ?? 0);
            if (!empty($session['tags']) && is_array($session['tags'])) {
                $tags = array_values(array_unique(array_merge($tags, $session['tags'])));
            }
        }

        $ls = $latest['session'] ?? [];
        return [
            'id'               => $ls['id']            ?? '',
            'date'             => $ls['date']           ?? date('Y-m-d'),
            'title'            => $ls['title']          ?? '',
            'source'           => 'Moodle Forum',
            'source_type'      => 'other',
            'duration_minutes' => $totalduration,
            'word_count'       => $totalwords,
            'character_count'  => $totalchars,
            'topic_summary'    => $ls['topic_summary']  ?? '',
            'tags'             => $tags,
        ];
    }

    /**
     * Weighted average of all scores.* fields.
     *
     * Schema v1.2 scores: cognitive_depth_score, critical_discourse_score,
     * strategic_thinking_pct, engagement_score, meta_cognition_score,
     * competency_domains_count (max, not average).
     *
     * @param  array   $analyses
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_scores(array $analyses, array $weights, float $totalweight): array {
        $numericfields = [
            'cognitive_depth_score',
            'critical_discourse_score',
            'strategic_thinking_pct',
            'engagement_score',
            'meta_cognition_score',
        ];

        $result = [];
        foreach ($numericfields as $field) {
            $result[$field] = (int) round($this->weighted_avg(
                $analyses, $weights, $totalweight,
                fn($a) => (float) ($a['scores'][$field] ?? 0)
            ));
        }

        // competency_domains_count — max is more meaningful than average.
        $result['competency_domains_count'] = (int) max(array_map(
            fn($a) => (int) ($a['scores']['competency_domains_count'] ?? 0),
            $analyses
        ));

        return $result;
    }

    /**
     * Merge competency arrays by name (case-insensitive).
     *
     * score       = weighted average across analyses containing this competency.
     * bloom_level = maximum seen.
     * color       = from first occurrence.
     * frameworks  = union.
     * tags        = union.
     *
     * @param  array   $analyses
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_competencies(
        array $analyses,
        array $weights,
        float $totalweight
    ): array {
        $map       = [];
        $alist     = array_values($analyses);
        $wlist     = array_values($weights);

        foreach ($alist as $i => $analysis) {
            if (empty($analysis['competencies']) || !is_array($analysis['competencies'])) {
                continue;
            }
            foreach ($analysis['competencies'] as $comp) {
                if (empty($comp['name'])) {
                    continue;
                }
                $key = strtolower(trim($comp['name']));
                $level = (int) ($comp['bloom_level'] ?? 1);

                if (!isset($map[$key])) {
                    $map[$key] = [
                        'name'       => $comp['name'],
                        'scores'     => [],
                        'weights'    => [],
                        'bloom_max'  => $level,
                        'color'      => $comp['color']      ?? 'cyan',
                        'frameworks' => $comp['frameworks'] ?? [],
                        'tags'       => $comp['tags']       ?? [],
                    ];
                } else {
                    if ($level > $map[$key]['bloom_max']) {
                        $map[$key]['bloom_max'] = $level;
                    }
                    $map[$key]['frameworks'] = array_values(array_unique(
                        array_merge($map[$key]['frameworks'], $comp['frameworks'] ?? [])
                    ));
                    $map[$key]['tags'] = array_values(array_unique(
                        array_merge($map[$key]['tags'], $comp['tags'] ?? [])
                    ));
                }

                $map[$key]['scores'][]  = (float) ($comp['score'] ?? 0);
                $map[$key]['weights'][] = $wlist[$i];
            }
        }

        $bloomlabels = [
            1 => 'Remember', 2 => 'Understand', 3 => 'Apply',
            4 => 'Analyze',  5 => 'Evaluate',   6 => 'Create',
        ];

        $result = [];
        foreach ($map as $comp) {
            $lw    = array_sum($comp['weights']);
            $score = $lw > 0
                ? array_sum(array_map(
                    fn($s, $w) => $s * $w,
                    $comp['scores'],
                    $comp['weights']
                  )) / $lw
                : 0;

            $result[] = [
                'name'        => $comp['name'],
                'score'       => (int) round($score),
                'color'       => $comp['color'],
                'frameworks'  => $comp['frameworks'],
                'bloom_level' => $comp['bloom_max'],
                'bloom_label' => $bloomlabels[$comp['bloom_max']] ?? 'Remember',
                'tags'        => $comp['tags'],
            ];
        }

        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);
        return $result;
    }

    /**
     * Merge radar axes by label — weighted average values.
     *
     * @param  array   $analyses
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_radar(
        array $analyses,
        array $weights,
        float $totalweight
    ): array {
        $axismap = [];
        $alist   = array_values($analyses);
        $wlist   = array_values($weights);

        foreach ($alist as $i => $analysis) {
            if (empty($analysis['radar']['axes']) || !is_array($analysis['radar']['axes'])) {
                continue;
            }
            foreach ($analysis['radar']['axes'] as $axis) {
                $label = trim($axis['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                if (!isset($axismap[$label])) {
                    $axismap[$label] = [
                        'label'       => $axis['label'],
                        'values'      => [],
                        'weights'     => [],
                        'description' => $axis['description'] ?? '',
                    ];
                }
                $axismap[$label]['values'][]  = (float) ($axis['value'] ?? 0);
                $axismap[$label]['weights'][] = $wlist[$i];
                if (!empty($axis['description'])) {
                    $axismap[$label]['description'] = $axis['description'];
                }
            }
        }

        $axes = [];
        foreach ($axismap as $axis) {
            $lw    = array_sum($axis['weights']);
            $value = $lw > 0
                ? array_sum(array_map(
                    fn($v, $w) => $v * $w,
                    $axis['values'],
                    $axis['weights']
                  )) / $lw
                : 0;

            $axes[] = [
                'label'       => $axis['label'],
                'value'       => (int) round($value),
                'description' => $axis['description'],
            ];
        }

        return ['axes' => $axes];
    }

    /**
     * Merge blooms_progression by level.
     *
     * dots_active = maximum across all analyses for that level.
     * description = from the analysis with the highest dots_active.
     *
     * @param  array $analyses
     * @return array
     */
    private function merge_blooms(array $analyses): array {
        $levelmap = [];

        foreach ($analyses as $analysis) {
            if (empty($analysis['blooms_progression']) ||
                !is_array($analysis['blooms_progression'])) {
                continue;
            }
            foreach ($analysis['blooms_progression'] as $entry) {
                $level = (int) ($entry['level'] ?? 0);
                if ($level < 1 || $level > 6) {
                    continue;
                }

                $dots = (int) ($entry['dots_active'] ?? 0);

                if (!isset($levelmap[$level])) {
                    $levelmap[$level] = [
                        'level'       => $level,
                        'label'       => $entry['label']       ?? '',
                        'icon'        => $entry['icon']        ?? '',
                        'title'       => $entry['title']       ?? '',
                        'description' => $entry['description'] ?? '',
                        'dots_active' => $dots,
                        'dot_color'   => $entry['dot_color']   ?? 'cyan',
                    ];
                } else {
                    if ($dots > $levelmap[$level]['dots_active']) {
                        $levelmap[$level]['dots_active'] = $dots;
                        if (!empty($entry['description'])) {
                            $levelmap[$level]['description'] = $entry['description'];
                        }
                        if (!empty($entry['dot_color'])) {
                            $levelmap[$level]['dot_color'] = $entry['dot_color'];
                        }
                        if (!empty($entry['title'])) {
                            $levelmap[$level]['title'] = $entry['title'];
                        }
                    }
                    if (empty($levelmap[$level]['icon']) && !empty($entry['icon'])) {
                        $levelmap[$level]['icon'] = $entry['icon'];
                    }
                }
            }
        }

        ksort($levelmap);
        return array_values($levelmap);
    }

    /**
     * Merge discussion_value blocks (Schema v1.2).
     *
     * session_hours, word_count, character_count — summed.
     * dci_components.*                           — weighted average.
     * discussion_contribution_index              — recomputed from merged dci_components.
     * application_readiness                      — highest value seen.
     * participation_depth                        — highest value seen.
     * retention_indicators                       — union of factors_present, deduped by factor name.
     *
     * @param  array   $analyses
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_discussion_value(
        array $analyses,
        array $weights,
        float $totalweight
    ): array {
        $sumsession  = 0.0;
        $sumwords    = 0;
        $sumchars    = 0;
        $maxreadiness = -1;
        $maxdepth    = -1;
        $readinessvals = array_flip(self::READINESS_RANK);
        $depthvals     = array_flip(self::DEPTH_RANK);

        // DCI component accumulation.
        $dcifields    = [
            'idea_originality', 'reasoning_transparency',
            'peer_advancement', 'critical_challenge', 'knowledge_integration',
        ];
        $dciaccum     = array_fill_keys($dcifields, 0.0);
        $dciweighttot = 0.0;

        // Retention indicators union.
        $factorsseen  = [];
        $factorspresent = [];

        foreach ($analyses as $dv) {
            $dv = $dv['discussion_value'] ?? [];

            $sumsession += (float) ($dv['session_hours']    ?? 0);
            $sumwords   += (int)   ($dv['word_count']       ??
                // Fall back to session word_count for analyses that stored it there.
                ($analyses[array_search($dv, array_column($analyses, 'discussion_value'))]
                    ['session']['word_count'] ?? 0)
            );
            $sumchars   += (int)   ($dv['character_count']  ?? 0);

            $readiness = strtoupper(trim($dv['application_readiness'] ?? 'LOW'));
            $rank      = self::READINESS_RANK[$readiness] ?? 0;
            if ($rank > $maxreadiness) {
                $maxreadiness = $rank;
            }

            $depth     = strtoupper(trim($dv['participation_depth'] ?? 'LOW'));
            $drank     = self::DEPTH_RANK[$depth] ?? 0;
            if ($drank > $maxdepth) {
                $maxdepth = $drank;
            }

            // DCI components.
            $dcicomp = $dv['dci_components'] ?? [];
            foreach ($dcifields as $field) {
                $val = (float) ($dcicomp[$field] ?? 0);
                $dciaccum[$field] += $val;
            }
            $dciweighttot += 1.0; // Equal weight per analysis for DCI.

            // Retention indicators — union of present factors.
            $ri = $dv['retention_indicators'] ?? [];
            foreach ($ri['factors_present'] ?? [] as $fp) {
                $fname = trim($fp['factor'] ?? '');
                if ($fname !== '' && !isset($factorsseen[$fname])) {
                    $factorsseen[$fname] = true;
                    $factorspresent[] = [
                        'factor'   => $fname,
                        'evidence' => $fp['evidence'] ?? '',
                    ];
                }
            }
        }

        // Compute merged DCI components.
        $mergeddci = [];
        foreach ($dcifields as $field) {
            $mergeddci[$field] = $dciweighttot > 0
                ? round($dciaccum[$field] / $dciweighttot, 1)
                : 0.0;
        }

        // Recompute DCI total from merged components (sum of 5 dims, max 10.0).
        $dcivalue = round(array_sum($mergeddci), 1);

        // All factors not in present list are absent.
        $allfactors = [
            'Active Generation', 'Contextual Grounding', 'Iterative Refinement',
            'Peer Dialogue', 'Prior Knowledge Activation', 'Application Intent',
            'Meta-Cognitive Awareness',
        ];
        $factorsabsent = array_values(array_filter(
            $allfactors,
            fn($f) => !isset($factorsseen[$f])
        ));

        return [
            'application_readiness'          => $readinessvals[max(0, $maxreadiness)] ?? 'LOW',
            'participation_depth'            => $depthvals[max(0, $maxdepth)]         ?? 'LOW',
            'session_hours'                  => round($sumsession, 1),
            'word_count'                     => $sumwords,
            'character_count'                => $sumchars,
            'discussion_contribution_index'  => $dcivalue,
            'dci_components'                 => $mergeddci,
            'retention_indicators'           => [
                'label'           => 'Discussion Engagement Indicators',
                'factors_present' => $factorspresent,
                'factors_absent'  => $factorsabsent,
            ],
        ];
    }

    /**
     * Union timeline entries across all analyses, deduplicated by title.
     *
     * @param  array $analyses
     * @return array
     */
    private function merge_timeline(array $analyses): array {
        $seen   = [];
        $result = [];

        foreach ($analyses as $analysis) {
            if (empty($analysis['timeline']) || !is_array($analysis['timeline'])) {
                continue;
            }
            foreach ($analysis['timeline'] as $entry) {
                $title = trim($entry['title'] ?? '');
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $result[]     = [
                    'title'       => $title,
                    'description' => $entry['description'] ?? '',
                ];
            }
        }

        return $result;
    }

    /**
     * Merge CPI blocks from multiple analyses.
     *
     * Component scores are averaged (equal weight per analysis).
     * CPI is recomputed from merged components using the v1.2 formula.
     * Weights: cognitive_depth 0.35, critical_discourse 0.25,
     *          strategic_thinking 0.20, engagement 0.15, meta_cognition 0.05.
     *
     * @param  array $cpis  Array of cognitive_performance_index blocks.
     * @return array
     */
    private function merge_cpi(array $cpis): array {
        $componentweights = [
            'cognitive_depth'    => 0.35,
            'critical_discourse' => 0.25,
            'strategic_thinking' => 0.20,
            'engagement'         => 0.15,
            'meta_cognition'     => 0.05,
        ];

        $n = count($cpis);
        $mergedcomponents = [];

        foreach (array_keys($componentweights) as $field) {
            $sum = 0.0;
            $c   = 0;
            foreach ($cpis as $cpi) {
                $val = $cpi['component_scores'][$field] ?? null;
                if ($val !== null && is_numeric($val)) {
                    $sum += (float) $val;
                    $c++;
                }
            }
            $mergedcomponents[$field] = $c > 0 ? (int) round($sum / $c) : 0;
        }

        // Recompute CPI.
        $raw = 0.0;
        foreach ($componentweights as $field => $w) {
            $raw += ($mergedcomponents[$field] ?? 0) * $w;
        }
        $cpiscore = max(70, min(145, (int) round(70 + ($raw / 100) * 75)));
        $band     = $this->cpi_band($cpiscore);

        return [
            'cpi_score'            => $cpiscore,
            'cpi_band'             => $band,
            'cpi_band_description' => "Aggregated CPI from {$n} analysis(es). " .
                "Component scores are averages; CPI recomputed via LI Forum Discussion " .
                "Analyzer v1.0 formula from merged components.",
            'component_weights'    => $componentweights,
            'component_scores'     => $mergedcomponents,
            'calculation_note'     => "Discussion-specific behavioral composite scored via " .
                "LI Forum Discussion Analyzer v1.0 rubrics. Not a measure of general " .
                "intelligence or a psychometric IQ equivalent. This entry represents an " .
                "aggregated result across multiple learner analyses.",
        ];
    }

    /**
     * Map a CPI score (70–145) to its band label.
     *
     * @param  int    $score
     * @return string
     */
    private function cpi_band(int $score): string {
        if ($score >= 130) { return 'Exceptional'; }
        if ($score >= 115) { return 'Advanced'; }
        if ($score >= 100) { return 'Proficient'; }
        if ($score >= 85)  { return 'Developing'; }
        return 'Foundational';
    }

    /**
     * Build the aggregate meta block.
     *
     * confidence = lowest confidence seen across all source analyses.
     *
     * @param  array $analyses
     * @param  int   $count
     * @return array
     */
    private function merge_meta(array $analyses, int $count): array {
        $minrank = 2;

        foreach ($analyses as $a) {
            $conf = strtoupper(trim($a['meta']['confidence'] ?? 'HIGH'));
            $rank = self::CONFIDENCE_RANK[$conf] ?? 2;
            if ($rank < $minrank) {
                $minrank = $rank;
            }
        }

        $confidencemap = array_flip(self::CONFIDENCE_RANK);

        return [
            'generated_by' => self::GENERATOR,
            'generated_at' => date('c'),
            'confidence'   => $confidencemap[$minrank] ?? 'LOW',
            'notes'        => "Aggregated from {$count} student_forum analysis(es).",
        ];
    }

    /**
     * Stamp aggregate meta onto a single-analysis result.
     *
     * @param  array $data
     * @param  int   $count
     * @return array
     */
    private function stamp_aggregate_meta(array $data, int $count): array {
        $data['schema_version']             = self::SCHEMA_VERSION;
        $data['meta']['generated_by']       = self::GENERATOR;
        $data['meta']['generated_at']       = date('c');
        $data['meta']['notes']              = "Aggregated from {$count} analysis(es). " .
            ($data['meta']['notes'] ?? '');
        $data['session']['source']          = 'Moodle Forum';
        $data['session']['source_type']     = 'other';
        // instructor_notes is intentionally stripped from aggregate rows.
        unset($data['instructor_notes']);
        return $data;
    }

    // =========================================================================
    // Private — database helpers
    // =========================================================================

    /**
     * Load and decode all complete student_forum analyses for a forum.
     *
     * @param  int   $forumid
     * @return array Decoded LID data arrays, ordered by timemodified ASC.
     */
    private function load_complete_student_forum(int $forumid): array {
        global $DB;

        $records = $DB->get_records('local_lid_analysis', [
            'scope'   => 'student_forum',
            'forumid' => $forumid,
            'status'  => 'complete',
        ], 'timemodified ASC');

        return $this->decode_records(array_values($records));
    }

    /**
     * Decode an array of local_lid_analysis records into LID data arrays.
     *
     * @param  \stdClass[] $records
     * @return array
     */
    private function decode_records(array $records): array {
        $out = [];
        foreach ($records as $record) {
            if (empty($record->analysis_json)) {
                continue;
            }
            $data = json_decode($record->analysis_json, true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }

    /**
     * Return array of forum ids with LID enabled in the given course.
     *
     * @param  int   $courseid
     * @return int[]
     */
    private function get_enabled_forum_ids(int $courseid): array {
        global $DB;

        $sitedefault = (bool) get_config('local_lid', 'lid_default_enabled');
        $configrows  = $DB->get_records(
            'local_lid_forum_config',
            ['courseid' => $courseid],
            '',
            'forumid, enabled'
        );

        $explicitenabled  = [];
        $explicitdisabled = [];
        foreach ($configrows as $row) {
            if ((bool) $row->enabled) {
                $explicitenabled[] = (int) $row->forumid;
            } else {
                $explicitdisabled[] = (int) $row->forumid;
            }
        }

        if ($sitedefault) {
            $allids = array_map(
                fn($r) => (int) $r->id,
                $DB->get_records('forum', ['course' => $courseid], '', 'id')
            );
            return array_values(array_diff($allids, $explicitdisabled));
        }

        return $explicitenabled;
    }

    /**
     * Insert or update an aggregate local_lid_analysis record.
     *
     * Matches on scope + courseid + forumid (NULL-safe) + userid (NULL-safe).
     *
     * @param  array $scope      Keys: scope, courseid, forumid, userid.
     * @param  array $aggregated The merged LID data array to store.
     */
    private function upsert_aggregate(array $scope, array $aggregated): void {
        global $DB;

        // Build a NULL-safe WHERE clause.
        $whereclauses = [
            'scope = :scope',
            'courseid = :courseid',
        ];
        $params = [
            'scope'    => $scope['scope'],
            'courseid' => $scope['courseid'],
        ];

        if ($scope['forumid'] === null) {
            $whereclauses[] = 'forumid IS NULL';
        } else {
            $whereclauses[] = 'forumid = :forumid';
            $params['forumid'] = $scope['forumid'];
        }

        if ($scope['userid'] === null) {
            $whereclauses[] = 'userid IS NULL';
        } else {
            $whereclauses[] = 'userid = :userid';
            $params['userid'] = $scope['userid'];
        }

        $where    = implode(' AND ', $whereclauses);
        $existing = $DB->get_record_sql(
            "SELECT id FROM {local_lid_analysis} WHERE {$where}",
            $params
        );

        $json = json_encode($aggregated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now  = time();

        if ($existing) {
            $DB->update_record('local_lid_analysis', (object) [
                'id'             => $existing->id,
                'analysis_json'  => $json,
                'schema_version' => self::SCHEMA_VERSION,
                'status'         => 'complete',
                'error_message'  => null,
                'llm_model'      => self::GENERATOR,
                'timemodified'   => $now,
            ]);
        } else {
            $DB->insert_record('local_lid_analysis', (object) [
                'scope'          => $scope['scope'],
                'courseid'       => $scope['courseid'],
                'forumid'        => $scope['forumid'] ?? null,
                'discussionid'   => null,
                'postid'         => null,
                'userid'         => $scope['userid'] ?? null,
                'analysis_json'  => $json,
                'schema_version' => self::SCHEMA_VERSION,
                'status'         => 'complete',
                'error_message'  => null,
                'llm_model'      => self::GENERATOR,
                'prompt_hash'    => null,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ]);
        }
    }

    // =========================================================================
    // Private — math helpers
    // =========================================================================

    /**
     * Compute a weighted average of a numeric field extracted from each analysis.
     *
     * @param  array    $analyses
     * @param  float[]  $weights
     * @param  float    $totalweight
     * @param  callable $extractor   fn($analysis) => float
     * @return float
     */
    private function weighted_avg(
        array $analyses,
        array $weights,
        float $totalweight,
        callable $extractor
    ): float {
        if ($totalweight <= 0) {
            return 0.0;
        }

        $alist  = array_values($analyses);
        $wlist  = array_values($weights);
        $sum    = 0.0;

        foreach ($alist as $i => $a) {
            $sum += $extractor($a) * $wlist[$i];
        }

        return $sum / $totalweight;
    }
}
