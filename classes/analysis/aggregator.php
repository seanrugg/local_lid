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
 * Computes aggregate LID JSON from individual post analyses using
 * mathematical merging — no additional LLM calls are made.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\analysis;

defined('MOODLE_INTERNAL') || die();

/**
 * Rebuilds aggregate local_lid_analysis records by mathematically merging
 * all complete post-scope analyses within a given scope boundary.
 *
 * Three public methods correspond to the three aggregate scopes:
 *
 *   rebuild_student_forum($userid, $forumid, $courseid)
 *     Merges all complete posts by $userid in $forumid.
 *     Scope: 'student_forum'.
 *
 *   rebuild_forum($forumid, $courseid)
 *     Merges all complete posts across all students in $forumid.
 *     Scope: 'forum'.
 *
 *   rebuild_course($courseid)
 *     Merges all complete posts across all LID-enabled forums in $courseid.
 *     Scope: 'course'.
 *
 * Aggregation rules (applied consistently across all scopes):
 *
 *   scores.*                 Weighted average by session_hours (equal weight if absent).
 *   competencies[]           Merged by name (case-insensitive). Score = weighted average.
 *                            bloom_level = maximum seen. frameworks/tags = union.
 *   radar.axes[]             Matched by label. Value = weighted average.
 *   blooms_progression[]     Matched by level. dots_active = maximum seen.
 *                            description = most recent non-empty.
 *   roi.lms_equivalent_hours Sum of all posts.
 *   roi.session_hours        Sum of all posts.
 *   roi.knowledge_value_usd  Sum of all posts.
 *   roi.*  (other numerics)  Weighted average by session_hours.
 *   timeline[]               Chronological union, deduplicated by title similarity.
 *   employer_value[]         Union, deduplicated by title.
 *   portfolio.primary_tags   Union of all primary_tags.
 *   portfolio.secondary_tags Union of all secondary_tags.
 *   portfolio.title          From the most recent post's analysis.
 *   meta.confidence          Lowest confidence seen (aggregate is never more
 *                            confident than its weakest component).
 *   meta.generated_by        Set to 'local_lid aggregator v1.0'.
 *   session.source           Set to 'Moodle Forum'.
 *   session.source_type      Set to 'other'.
 *   session.duration_minutes Sum of all posts.
 *
 * Usage (called by session_analyser after each successful post analysis):
 *   $agg = new \local_lid\analysis\aggregator();
 *   $agg->rebuild_student_forum($userid, $forumid, $courseid);
 *   $agg->rebuild_forum($forumid, $courseid);
 *   $agg->rebuild_course($courseid);
 */
class aggregator {

    /** @var string Generator tag written into meta.generated_by. */
    const GENERATOR = 'local_lid aggregator v1.0';

    /** @var string Schema version written into aggregated output. */
    const SCHEMA_VERSION = '1.0';

    /** @var array Confidence ranking for min-confidence aggregation. */
    const CONFIDENCE_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];

    // =========================================================================
    // Public — scope rebuilders
    // =========================================================================

    /**
     * Rebuild the student_forum aggregate for one student in one forum.
     *
     * @param int $userid
     * @param int $forumid
     * @param int $courseid
     */
    public function rebuild_student_forum(int $userid, int $forumid, int $courseid): void {
        global $DB;

        $posts = $this->load_complete_posts([
            'scope'   => 'post',
            'userid'  => $userid,
            'forumid' => $forumid,
        ]);

        if (empty($posts)) {
            return;
        }

        $aggregated = $this->merge($posts, $courseid, $forumid, $userid);

        $this->upsert_aggregate([
            'scope'    => 'student_forum',
            'courseid' => $courseid,
            'forumid'  => $forumid,
            'userid'   => $userid,
        ], $aggregated);
    }

    /**
     * Rebuild the forum aggregate across all students in one forum.
     *
     * @param int $forumid
     * @param int $courseid
     */
    public function rebuild_forum(int $forumid, int $courseid): void {
        $posts = $this->load_complete_posts([
            'scope'   => 'post',
            'forumid' => $forumid,
        ]);

        if (empty($posts)) {
            return;
        }

        $aggregated = $this->merge($posts, $courseid, $forumid, null);

        $this->upsert_aggregate([
            'scope'    => 'forum',
            'courseid' => $courseid,
            'forumid'  => $forumid,
            'userid'   => null,
        ], $aggregated);
    }

    /**
     * Rebuild the course aggregate across all LID-enabled forums.
     *
     * @param int $courseid
     */
    public function rebuild_course(int $courseid): void {
        global $DB;

        // Only include posts from forums where LID is enabled.
        $enabledforums = $DB->get_fieldset_select(
            'local_lid_forum_config',
            'forumid',
            'courseid = :courseid AND enabled = 1',
            ['courseid' => $courseid]
        );

        if (empty($enabledforums)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($enabledforums);
        $where = "scope = 'post' AND courseid = :courseid AND status = 'complete' AND forumid {$insql}";
        $params = array_merge(['courseid' => $courseid], $inparams);

        $records = $DB->get_records_select('local_lid_analysis', $where, $params,
            'timecreated ASC');

        if (empty($records)) {
            return;
        }

        $posts = $this->decode_records($records);
        $aggregated = $this->merge($posts, $courseid, null, null);

        $this->upsert_aggregate([
            'scope'    => 'course',
            'courseid' => $courseid,
            'forumid'  => null,
            'userid'   => null,
        ], $aggregated);
    }

    // =========================================================================
    // Private — core merge engine
    // =========================================================================

    /**
     * Merge an array of decoded LID JSON objects into one aggregate.
     *
     * @param  array    $posts     Array of decoded LID data arrays, ordered by timecreated ASC.
     * @param  int      $courseid
     * @param  int|null $forumid   Null for course-scope aggregates.
     * @param  int|null $userid    Null for forum/course-scope aggregates.
     * @return array               Merged LID JSON array.
     */
    private function merge(
        array $posts,
        int $courseid,
        ?int $forumid,
        ?int $userid
    ): array {

        if (count($posts) === 1) {
            // Single post — return a copy with updated meta rather than merging.
            $single = reset($posts);
            return $this->stamp_aggregate_meta($single, 1);
        }

        // Extract session_hours weights for weighted averaging.
        $weights = array_map(function ($p) {
            $h = (float) ($p['roi']['session_hours'] ?? 0);
            return $h > 0 ? $h : 1.0; // Fall back to equal weight.
        }, $posts);

        $totalweight = array_sum($weights);

        $out = [];
        $out['schema_version'] = self::SCHEMA_VERSION;

        // Session block.
        $out['session'] = $this->merge_session($posts, $weights);

        // Scores block — weighted average.
        $out['scores'] = $this->merge_scores($posts, $weights, $totalweight);

        // Competencies — merge by name.
        $out['competencies'] = $this->merge_competencies($posts, $weights, $totalweight);

        // Radar — merge by label.
        $out['radar'] = $this->merge_radar($posts, $weights, $totalweight);

        // Bloom's progression — merge by level.
        $out['blooms_progression'] = $this->merge_blooms($posts);

        // ROI block.
        $out['roi'] = $this->merge_roi($posts, $weights, $totalweight);

        // Timeline — union ordered by post date.
        $out['timeline'] = $this->merge_timeline($posts);

        // Employer value — union deduplicated by title.
        $out['employer_value'] = $this->merge_employer_value($posts);

        // Portfolio — union of tags, title from most recent.
        $out['portfolio'] = $this->merge_portfolio($posts);

        // Meta.
        $out['meta'] = $this->merge_meta($posts, count($posts));

        return $out;
    }

    // =========================================================================
    // Private — section mergers
    // =========================================================================

    /**
     * Build the merged session block.
     *
     * @param  array $posts
     * @param  float[] $weights
     * @return array
     */
    private function merge_session(array $posts, array $weights): array {
        // Use the most recent post's session block as the base.
        $latest = end($posts);
        reset($posts);

        // Collect all unique tags.
        $tags = [];
        foreach ($posts as $p) {
            if (!empty($p['session']['tags']) && is_array($p['session']['tags'])) {
                $tags = array_merge($tags, $p['session']['tags']);
            }
        }
        $tags = array_values(array_unique($tags));

        // Sum durations.
        $totalduration = 0;
        foreach ($posts as $p) {
            $totalduration += (int) ($p['session']['duration_minutes'] ?? 0);
        }

        return [
            'id'               => $latest['session']['id'] ?? '',
            'date'             => $latest['session']['date'] ?? date('Y-m-d'),
            'title'            => $latest['session']['title'] ?? '',
            'source'           => 'Moodle Forum',
            'source_type'      => 'other',
            'duration_minutes' => $totalduration,
            'topic_summary'    => $latest['session']['topic_summary'] ?? '',
            'tags'             => $tags,
        ];
    }

    /**
     * Weighted average of all scores.* fields.
     *
     * @param  array   $posts
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_scores(array $posts, array $weights, float $totalweight): array {
        $fields = [
            'competency_domains_count',
            'cognitive_depth_score',
            'strategic_thinking_pct',
            'roi_awareness_pct',
            'engagement_score',
            'meta_cognition_score',
        ];

        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $this->weighted_average($posts, $weights, $totalweight,
                fn($p) => (float) ($p['scores'][$field] ?? 0));
        }

        // competency_domains_count: use max rather than average (more meaningful).
        $result['competency_domains_count'] = (int) max(array_map(
            fn($p) => (int) ($p['scores']['competency_domains_count'] ?? 0),
            $posts
        ));

        return $result;
    }

    /**
     * Merge competency arrays by name (case-insensitive key).
     *
     * For each unique competency name:
     *   - score: weighted average across posts that include it
     *   - bloom_level: maximum seen
     *   - bloom_label: label corresponding to the max bloom_level
     *   - color: from the first occurrence
     *   - frameworks: union
     *   - tags: union
     *
     * @param  array   $posts
     * @param  float[] $weights  Indexed same as $posts.
     * @param  float   $totalweight
     * @return array
     */
    private function merge_competencies(array $posts, array $weights, float $totalweight): array {
        // Build a keyed map: normalised_name => [ occurrences with their weights ].
        $map = [];
        $postlist = array_values($posts);
        $weightlist = array_values($weights);

        foreach ($postlist as $i => $post) {
            if (empty($post['competencies']) || !is_array($post['competencies'])) {
                continue;
            }
            foreach ($post['competencies'] as $comp) {
                if (empty($comp['name'])) {
                    continue;
                }
                $key = strtolower(trim($comp['name']));
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'name'       => $comp['name'],
                        'scores'     => [],
                        'weights'    => [],
                        'bloom_max'  => (int) ($comp['bloom_level'] ?? 1),
                        'color'      => $comp['color'] ?? 'cyan',
                        'frameworks' => $comp['frameworks'] ?? [],
                        'tags'       => $comp['tags'] ?? [],
                    ];
                } else {
                    // Update max bloom.
                    $level = (int) ($comp['bloom_level'] ?? 1);
                    if ($level > $map[$key]['bloom_max']) {
                        $map[$key]['bloom_max'] = $level;
                    }
                    // Union frameworks and tags.
                    $map[$key]['frameworks'] = array_values(array_unique(
                        array_merge($map[$key]['frameworks'], $comp['frameworks'] ?? [])
                    ));
                    $map[$key]['tags'] = array_values(array_unique(
                        array_merge($map[$key]['tags'], $comp['tags'] ?? [])
                    ));
                }
                $map[$key]['scores'][]  = (float) ($comp['score'] ?? 0);
                $map[$key]['weights'][] = $weightlist[$i];
            }
        }

        $bloomlabels = [
            1 => 'Remember', 2 => 'Understand', 3 => 'Apply',
            4 => 'Analyze',  5 => 'Evaluate',   6 => 'Create',
        ];

        $result = [];
        foreach ($map as $comp) {
            $localweight = array_sum($comp['weights']);
            $score = $localweight > 0
                ? array_sum(array_map(fn($s, $w) => $s * $w, $comp['scores'], $comp['weights'])) / $localweight
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

        // Sort by score descending for consistent display ordering.
        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);

        return $result;
    }

    /**
     * Merge radar axes by label — weighted average of values.
     *
     * @param  array   $posts
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_radar(array $posts, array $weights, float $totalweight): array {
        $axismap = [];
        $postlist  = array_values($posts);
        $weightlist = array_values($weights);

        foreach ($postlist as $i => $post) {
            if (empty($post['radar']['axes']) || !is_array($post['radar']['axes'])) {
                continue;
            }
            foreach ($post['radar']['axes'] as $axis) {
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
                $axismap[$label]['weights'][] = $weightlist[$i];
                // Keep the most recently seen description.
                if (!empty($axis['description'])) {
                    $axismap[$label]['description'] = $axis['description'];
                }
            }
        }

        $axes = [];
        foreach ($axismap as $axis) {
            $localweight = array_sum($axis['weights']);
            $value = $localweight > 0
                ? array_sum(array_map(fn($v, $w) => $v * $w, $axis['values'], $axis['weights'])) / $localweight
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
     * dots_active = maximum across all posts for that level.
     * description = from the post with the highest dots_active for that level.
     * dot_color   = from the post with the highest dots_active.
     * icon        = from the first post that has it.
     *
     * @param  array $posts
     * @return array
     */
    private function merge_blooms(array $posts): array {
        $levelmap = [];

        foreach ($posts as $post) {
            if (empty($post['blooms_progression']) || !is_array($post['blooms_progression'])) {
                continue;
            }
            foreach ($post['blooms_progression'] as $entry) {
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
                        $levelmap[$level]['description'] = $entry['description'] ?? $levelmap[$level]['description'];
                        $levelmap[$level]['dot_color']   = $entry['dot_color']   ?? $levelmap[$level]['dot_color'];
                        $levelmap[$level]['title']       = $entry['title']       ?? $levelmap[$level]['title'];
                    }
                    // Always fill in icon if we don't have one yet.
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
     * Merge ROI fields.
     *
     * Summed fields: lms_equivalent_hours, session_hours, knowledge_value_usd.
     * Weighted average: all other numeric fields.
     * application_readiness: highest value seen (EXCEPTIONAL > HIGH > MEDIUM > LOW).
     *
     * @param  array   $posts
     * @param  float[] $weights
     * @param  float   $totalweight
     * @return array
     */
    private function merge_roi(array $posts, array $weights, float $totalweight): array {
        $readinessrank = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'EXCEPTIONAL' => 3];
        $readinessvals = ['LOW', 'MEDIUM', 'HIGH', 'EXCEPTIONAL'];

        $maxreadinessrank = -1;

        $sumlms      = 0.0;
        $sumsession  = 0.0;
        $sumknowledge = 0.0;

        foreach ($posts as $p) {
            $roi = $p['roi'] ?? [];
            $sumlms       += (float) ($roi['lms_equivalent_hours'] ?? 0);
            $sumsession   += (float) ($roi['session_hours'] ?? 0);
            $sumknowledge += (float) ($roi['knowledge_value_usd'] ?? 0);

            $readiness = strtoupper(trim($roi['application_readiness'] ?? 'LOW'));
            $rank = $readinessrank[$readiness] ?? 0;
            if ($rank > $maxreadinessrank) {
                $maxreadinessrank = $rank;
            }
        }

        $wavg = fn($field) => $this->weighted_average(
            $posts, $weights, $totalweight,
            fn($p) => (float) ($p['roi'][$field] ?? 0)
        );

        return [
            'knowledge_value_usd'        => (int) round($sumknowledge),
            'time_efficiency_multiplier' => round($wavg('time_efficiency_multiplier'), 1),
            'engagement_score'           => (int) round($wavg('engagement_score')),
            'retention_probability_pct'  => (int) round($wavg('retention_probability_pct')),
            'application_readiness'      => $readinessvals[max(0, $maxreadinessrank)],
            'employer_value_index'       => round($wavg('employer_value_index'), 1),
            'lms_equivalent_hours'       => round($sumlms, 1),
            'session_hours'              => round($sumsession, 1),
        ];
    }

    /**
     * Union timeline entries across all posts, ordered chronologically.
     * Deduplicates by exact title match.
     *
     * @param  array $posts
     * @return array
     */
    private function merge_timeline(array $posts): array {
        $seen   = [];
        $result = [];

        foreach ($posts as $post) {
            if (empty($post['timeline']) || !is_array($post['timeline'])) {
                continue;
            }
            foreach ($post['timeline'] as $entry) {
                $title = trim($entry['title'] ?? '');
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $result[] = [
                    'title'       => $title,
                    'description' => $entry['description'] ?? '',
                ];
            }
        }

        return $result;
    }

    /**
     * Union employer_value entries, deduplicated by title.
     *
     * @param  array $posts
     * @return array
     */
    private function merge_employer_value(array $posts): array {
        $seen   = [];
        $result = [];

        foreach ($posts as $post) {
            if (empty($post['employer_value']) || !is_array($post['employer_value'])) {
                continue;
            }
            foreach ($post['employer_value'] as $entry) {
                $title = trim($entry['title'] ?? '');
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $result[] = [
                    'icon'        => $entry['icon']        ?? '',
                    'title'       => $title,
                    'description' => $entry['description'] ?? '',
                ];
            }
        }

        return $result;
    }

    /**
     * Merge portfolio blocks — union of tags, title from most recent post.
     *
     * @param  array $posts
     * @return array
     */
    private function merge_portfolio(array $posts): array {
        $latest      = end($posts);
        reset($posts);

        $primarytags   = [];
        $secondarytags = [];
        $docformats    = [];
        $seenformats   = [];

        foreach ($posts as $post) {
            $portfolio = $post['portfolio'] ?? [];
            if (!empty($portfolio['primary_tags']) && is_array($portfolio['primary_tags'])) {
                $primarytags = array_values(array_unique(
                    array_merge($primarytags, $portfolio['primary_tags'])
                ));
            }
            if (!empty($portfolio['secondary_tags']) && is_array($portfolio['secondary_tags'])) {
                $secondarytags = array_values(array_unique(
                    array_merge($secondarytags, $portfolio['secondary_tags'])
                ));
            }
            if (!empty($portfolio['documentation_formats']) && is_array($portfolio['documentation_formats'])) {
                foreach ($portfolio['documentation_formats'] as $fmt) {
                    $label = $fmt['label'] ?? '';
                    if ($label !== '' && !isset($seenformats[$label])) {
                        $seenformats[$label] = true;
                        $docformats[] = $fmt;
                    }
                }
            }
        }

        $latestportfolio = $latest['portfolio'] ?? [];

        return [
            'title'                 => $latestportfolio['title']            ?? '',
            'subtitle'              => $latestportfolio['subtitle']          ?? '',
            'primary_tags'          => $primarytags,
            'secondary_title'       => $latestportfolio['secondary_title']   ?? '',
            'secondary_subtitle'    => $latestportfolio['secondary_subtitle'] ?? '',
            'secondary_tags'        => $secondarytags,
            'documentation_formats' => $docformats,
        ];
    }

    /**
     * Build the aggregate meta block.
     *
     * confidence = lowest (most conservative) confidence across all posts.
     *
     * @param  array $posts
     * @param  int   $postcount
     * @return array
     */
    private function merge_meta(array $posts, int $postcount): array {
        $minrank = 2; // Start at HIGH and only go down.

        foreach ($posts as $post) {
            $conf = strtoupper(trim($post['meta']['confidence'] ?? 'HIGH'));
            $rank = self::CONFIDENCE_RANK[$conf] ?? 2;
            if ($rank < $minrank) {
                $minrank = $rank;
            }
        }

        $confidencemap = array_flip(self::CONFIDENCE_RANK);
        $confidence    = $confidencemap[$minrank] ?? 'LOW';

        return [
            'generated_by' => self::GENERATOR,
            'generated_at' => date('c'),
            'confidence'   => $confidence,
            'notes'        => "Aggregated from {$postcount} post analysis(es).",
        ];
    }

    /**
     * Stamp aggregate meta fields onto a single-post result being returned
     * as an aggregate (no merging needed, just update provenance fields).
     *
     * @param  array $data
     * @param  int   $postcount
     * @return array
     */
    private function stamp_aggregate_meta(array $data, int $postcount): array {
        $data['meta']['generated_by'] = self::GENERATOR;
        $data['meta']['generated_at'] = date('c');
        $data['meta']['notes']        = "Aggregated from {$postcount} post analysis(es). " .
            ($data['meta']['notes'] ?? '');
        $data['session']['source']      = 'Moodle Forum';
        $data['session']['source_type'] = 'other';
        return $data;
    }

    // =========================================================================
    // Private — database helpers
    // =========================================================================

    /**
     * Load and decode all complete post-scope analysis records matching $where.
     *
     * @param  array $conditions  Field => value conditions for get_records().
     * @return array              Array of decoded LID data arrays, ordered by timecreated ASC.
     */
    private function load_complete_posts(array $conditions): array {
        global $DB;

        $conditions['status'] = 'complete';
        $records = $DB->get_records('local_lid_analysis', $conditions, 'timecreated ASC');

        return $this->decode_records($records);
    }

    /**
     * Decode an array of local_lid_analysis records into LID data arrays.
     * Skips records with null or unparseable analysis_json.
     *
     * @param  \stdClass[] $records
     * @return array
     */
    private function decode_records(array $records): array {
        $posts = [];
        foreach ($records as $record) {
            if (empty($record->analysis_json)) {
                continue;
            }
            $data = json_decode($record->analysis_json, true);
            if (is_array($data)) {
                $posts[] = $data;
            }
        }
        return $posts;
    }

    /**
     * Insert or update an aggregate local_lid_analysis record.
     *
     * Matches on scope + courseid + forumid + userid (nulls included in match).
     * Updates the existing row if found; inserts a new row if not.
     *
     * @param  array $scope      Associative array: scope, courseid, forumid, userid.
     * @param  array $aggregated The merged LID data array to store.
     */
    private function upsert_aggregate(array $scope, array $aggregated): void {
        global $DB;

        // Build the WHERE clause respecting NULL values.
        $conditions = ['scope' => $scope['scope'], 'courseid' => $scope['courseid']];

        if ($scope['forumid'] === null) {
            $nullclause = 'forumid IS NULL';
        } else {
            $conditions['forumid'] = $scope['forumid'];
            $nullclause = null;
        }

        if ($scope['userid'] === null) {
            $nullclause2 = 'userid IS NULL';
        } else {
            $conditions['userid'] = $scope['userid'];
            $nullclause2 = null;
        }

        // Moodle's get_records() doesn't support IS NULL — use get_records_sql.
        $whereclauses = [];
        $params       = [];
        foreach ($conditions as $col => $val) {
            $whereclauses[] = "{$col} = :{$col}";
            $params[$col]   = $val;
        }
        if ($nullclause) {
            $whereclauses[] = $nullclause;
        }
        if ($nullclause2) {
            $whereclauses[] = $nullclause2;
        }

        $where   = implode(' AND ', $whereclauses);
        $existingrecords = $DB->get_records_sql(
            "SELECT id FROM {local_lid_analysis} WHERE {$where}",
            $params
        );
        $existing = !empty($existingrecords) ? reset($existingrecords) : null;

        $json = json_encode($aggregated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($existing) {
            $DB->update_record('local_lid_analysis', (object) [
                'id'             => $existing->id,
                'analysis_json'  => $json,
                'schema_version' => self::SCHEMA_VERSION,
                'status'         => 'complete',
                'error_message'  => null,
                'llm_model'      => self::GENERATOR,
                'timemodified'   => time(),
            ]);
        } else {
            $record = new \stdClass();
            $record->scope          = $scope['scope'];
            $record->courseid       = $scope['courseid'];
            $record->forumid        = $scope['forumid'] ?? null;
            $record->discussionid   = null;
            $record->postid         = null;
            $record->userid         = $scope['userid'] ?? null;
            $record->analysis_json  = $json;
            $record->schema_version = self::SCHEMA_VERSION;
            $record->status         = 'complete';
            $record->error_message  = null;
            $record->llm_model      = self::GENERATOR;
            $record->prompt_hash    = null;
            $record->timecreated    = time();
            $record->timemodified   = time();
            $DB->insert_record('local_lid_analysis', $record);
        }
    }

    // =========================================================================
    // Private — math helpers
    // =========================================================================

    /**
     * Compute a weighted average of a numeric field extracted from each post.
     *
     * @param  array    $posts
     * @param  float[]  $weights       One weight per post, same order.
     * @param  float    $totalweight   Sum of $weights.
     * @param  callable $extractor     fn($post) => float — extracts the value.
     * @return float                   Weighted average, or 0 if totalweight is 0.
     */
    private function weighted_average(
        array $posts,
        array $weights,
        float $totalweight,
        callable $extractor
    ): float {
        if ($totalweight <= 0) {
            return 0.0;
        }

        $postlist   = array_values($posts);
        $weightlist = array_values($weights);
        $sum        = 0.0;

        foreach ($postlist as $i => $post) {
            $sum += $extractor($post) * $weightlist[$i];
        }

        return $sum / $totalweight;
    }
}
