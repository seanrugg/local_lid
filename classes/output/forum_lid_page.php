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
 * Renderable for the Forum LID dashboard.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderable data object for the Forum LID dashboard.
 *
 * All analysis cards are pre-rendered to HTML strings and passed to the
 * Mustache template via {{{ }}} triple-brace syntax to prevent escaping.
 *
 * The student list is driven entirely by student_forum scope analysis rows,
 * not by post-scope records. Each student card shows:
 *   - Their student_forum analysis card (if complete)
 *   - A stale warning if their row has status = 'stale' (late post after analysis)
 *   - Top Bloom's level and top competency score derived from their student_forum JSON
 *   - Thread-level analysis cards for each discussion they participated in
 *
 * Access control:
 *   - Users with local/lid:viewpeeranalysis see all student tabs (full instructor view).
 *   - Users without viewpeeranalysis (typically students) see only the forum
 *     aggregate tab and their own student tab. No peer data is sent to the
 *     template — filtering is server-side to prevent data leakage.
 *
 * Data structure exported to template:
 *
 *   forumid                int
 *   forumname              string
 *   courseid               int
 *   cmid                   int
 *   lid_enabled            bool
 *   disabled_notice        string
 *   discussion_model       string   — e.g. 'open_engagement'
 *   discussion_model_label string   — full human-readable description
 *   discussion_models      array    — [{value, label, description, selected}]
 *                                     for the forum_config.mustache radio selector
 *   aggregate_html         string   — forum-scope aggregate card HTML (or '')
 *   has_aggregate          bool
 *   stale_notice           bool     — true if any student row is stale
 *   last_updated           string
 *   analysis_pending       bool     — true if any rows are pending/processing
 *   students               array    — filtered by viewpeeranalysis capability
 *     userid               int
 *     fullname             string
 *     userpic              string
 *     post_count           int      — total forum posts by this learner
 *     top_bloom            int
 *     top_bloom_label      string
 *     top_score            int
 *     cpi_score            int      — 0 if not available
 *     cpi_band             string
 *     student_html         string   — student_forum card HTML (or '')
 *     has_student_lid      bool
 *     is_stale             bool     — true if student_forum row is stale
 *     is_pending           bool     — true if student_forum row is pending/processing
 *     threads              array
 *       discussionid       int
 *       subject            string
 *       thread_html        string   — thread card HTML (or '')
 *       has_thread_lid     bool
 *       status             string
 *   can_trigger            bool
 *   can_configure          bool
 *   can_view_peers         bool     — true if user can see all student tabs
 *   owndata_notice         string   — notice shown when peer data is hidden (or '')
 *   trigger_url            string
 *   config_url             string
 */
class forum_lid_page implements \renderable, \templatable {

    /** @var \stdClass|\cm_info */
    private $cm;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_module */
    private \context_module $context;

    /**
     * @param \stdClass|\cm_info $cm      Course module record or cm_info object.
     * @param \stdClass          $course  Course record.
     * @param \context_module    $context Module context.
     */
    public function __construct($cm, \stdClass $course, \context_module $context) {
        $this->cm      = $cm;
        $this->course  = $course;
        $this->context = $context;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param  \renderer_base $output  Must be local_lid\output\renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB, $USER;

        /** @var renderer $output */
        $forumid      = (int) $this->cm->instance;
        $courseid     = (int) $this->course->id;
        $cmid         = (int) $this->cm->id;
        $cantrigger   = has_capability('local/lid:triggeranalysis', $this->context);
        $canconfigure = has_capability('local/lid:configureforum', $this->context);
        $canviewpeers = has_capability('local/lid:viewpeeranalysis', $this->context);

        $config = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);
        $forum  = $DB->get_record('forum', ['id' => $forumid], 'id, name');

        // Resolve effective enabled state.
        $forcedisabled = (bool) get_config('local_lid', 'lid_force_disabled');
        if ($forcedisabled) {
            $lidenabled = false;
        } elseif ($config !== false) {
            $lidenabled = (bool) $config->enabled;
        } else {
            $lidenabled = (bool) get_config('local_lid', 'lid_default_enabled');
        }

        // Resolve discussion model.
        $discussionmodel = $config
            ? ($config->discussion_model ?? \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT)
            : \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;

        // Validate — fall back to default if stored value is unrecognised.
        $validmodels = [
            \local_lid\llm\prompt_builder::MODEL_INDEPENDENT_FIRST,
            \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT,
            \local_lid\llm\prompt_builder::MODEL_STRUCTURED_DEBATE,
        ];
        if (!in_array($discussionmodel, $validmodels, true)) {
            $discussionmodel = \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;
        }

        // Build discussion_models array for forum_config.mustache radio selector.
        // Each entry: {value, label, description, selected (bool)}.
        $discussionmodels = $this->build_discussion_models($discussionmodel);

        // Full description string for display in the dashboard header.
        $modeldescriptions    = \local_lid\llm\prompt_builder::get_model_descriptions();
        $discussionmodellabel = $modeldescriptions[$discussionmodel]
            ?? $modeldescriptions[\local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT];

        $triggerurl = (new \moodle_url('/local/lid/ajax.php'))->out(false);
        $configurl  = (new \moodle_url('/local/lid/ajax.php', [
            'action'  => 'forum_config',
            'forumid' => $forumid,
        ]))->out(false);

        if (!$lidenabled) {
            return [
                'forumid'               => $forumid,
                'forumname'             => $forum ? format_string($forum->name) : '',
                'courseid'              => $courseid,
                'cmid'                  => $cmid,
                'lid_enabled'           => false,
                'disabled_notice'       => get_string('forum_disabled_notice', 'local_lid'),
                'discussion_model'      => $discussionmodel,
                'discussion_model_label' => $discussionmodellabel,
                'discussion_models'     => $discussionmodels,
                'aggregate_html'        => '',
                'has_aggregate'         => false,
                'stale_notice'          => false,
                'last_updated'          => '',
                'analysis_pending'      => false,
                'students'              => [],
                'can_trigger'           => $cantrigger,
                'can_configure'         => $canconfigure,
                'can_view_peers'        => $canviewpeers,
                'owndata_notice'        => '',
                'trigger_url'           => $triggerurl,
                'config_url'            => $configurl,
            ];
        }

        // Forum-scope aggregate.
        $forumanalysis = $DB->get_record('local_lid_analysis', [
            'scope'   => 'forum',
            'forumid' => $forumid,
            'status'  => 'complete',
        ]);

        $aggregatehtml = $forumanalysis
            ? $output->render_analysis_card($forumanalysis->analysis_json)
            : '';

        $lastmod = $forumanalysis ? (int) $forumanalysis->timemodified : 0;

        // Fetch all student_forum analysis rows for this forum.
        $studentrows = $DB->get_records_select(
            'local_lid_analysis',
            "scope = 'student_forum' AND forumid = :forumid AND userid IS NOT NULL",
            ['forumid' => $forumid],
            'timemodified ASC'
        );

        // Fetch thread-scope analysis rows indexed by discussionid.
        $threadrows  = $DB->get_records_select(
            'local_lid_analysis',
            "scope = 'thread' AND forumid = :forumid",
            ['forumid' => $forumid]
        );
        $threadbydisc = [];
        foreach ($threadrows as $tr) {
            $threadbydisc[(int) $tr->discussionid] = $tr;
        }

        // Fetch all discussions in this forum for thread tab building.
        $discussions = $DB->get_records(
            'forum_discussions',
            ['forum' => $forumid],
            'timemodified DESC',
            'id, name'
        );

        $stalefound   = false;
        $pendingfound = false;
        $students     = [];

        // If the user cannot view peer data, filter student rows to only
        // include their own record. This is server-side filtering — no peer
        // data is ever sent to the template or rendered in HTML.
        $currentuserid = (int) $USER->id;
        if (!$canviewpeers) {
            $filteredrows = [];
            foreach ($studentrows as $key => $srow) {
                if ((int) $srow->userid === $currentuserid) {
                    $filteredrows[$key] = $srow;
                }
            }
            $studentrows = $filteredrows;
        }

        foreach ($studentrows as $srow) {
            $userid = (int) $srow->userid;
            $user   = $DB->get_record('user', ['id' => $userid]);
            if (!$user) {
                continue;
            }

            $isstale   = ($srow->status === 'stale');
            $ispending = in_array($srow->status, ['pending', 'processing'], true);

            if ($isstale)   { $stalefound   = true; }
            if ($ispending) { $pendingfound = true; }

            [$topbloom, $toplabel, $topscore, $cpiscore, $cpiband] =
                $this->derive_student_highlights($srow);

            $studenthtml = '';
            if ($srow->status === 'complete' && !empty($srow->analysis_json)) {
                $studenthtml = $output->render_analysis_card(
                    $srow->analysis_json,
                    ['compact' => true, 'show_portfolio' => false, 'show_timeline' => true]
                );
                if ((int) $srow->timemodified > $lastmod) {
                    $lastmod = (int) $srow->timemodified;
                }
            }

            $postcount = (int) $DB->count_records_sql(
                "SELECT COUNT(fp.id)
                   FROM {forum_posts} fp
                   JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  WHERE fd.forum = :forumid AND fp.userid = :userid",
                ['forumid' => $forumid, 'userid' => $userid]
            );

            $threadcards = [];
            foreach ($discussions as $disc) {
                $discid = (int) $disc->id;
                $trow   = $threadbydisc[$discid] ?? null;

                $threadhtml = '';
                if ($trow && $trow->status === 'complete' && !empty($trow->analysis_json)) {
                    $threadhtml = $output->render_analysis_card(
                        $trow->analysis_json,
                        ['compact' => true, 'show_portfolio' => false, 'show_timeline' => true]
                    );
                }

                $threadcards[] = [
                    'discussionid'   => $discid,
                    'subject'        => format_string($disc->name),
                    'thread_html'    => $threadhtml,
                    'has_thread_lid' => !empty($threadhtml),
                    'status'         => $trow ? $trow->status : 'pending',
                ];
            }

            $userpic = $output->user_picture($user, [
                'size'     => 32,
                'courseid' => $courseid,
                'link'     => false,
            ]);

            $students[] = [
                'userid'          => $userid,
                'fullname'        => fullname($user),
                'userpic'         => $userpic,
                'post_count'      => $postcount,
                'top_bloom'       => $topbloom,
                'top_bloom_label' => $toplabel,
                'top_score'       => $topscore,
                'cpi_score'       => $cpiscore,
                'cpi_band'        => $cpiband,
                'student_html'    => $studenthtml,
                'has_student_lid' => ($srow->status === 'complete' && !empty($studenthtml)),
                'is_stale'        => $isstale,
                'is_pending'      => $ispending,
                'threads'         => $threadcards,
                'student_url'     => (new \moodle_url('/local/lid/student_view.php', [
                    'userid'   => $userid,
                    'courseid' => $courseid,
                ]))->out(false),
            ];
        }

        usort($students, fn($a, $b) => $b['top_score'] <=> $a['top_score']);

        // Build the own-data notice for users who cannot see peer data.
        $owndatanotice = '';
        if (!$canviewpeers) {
            $owndatanotice = get_string('dashboard_forum_owndata_notice', 'local_lid');
        }

        return [
            'forumid'               => $forumid,
            'forumname'             => $forum ? format_string($forum->name) : '',
            'courseid'              => $courseid,
            'cmid'                  => $cmid,
            'lid_enabled'           => true,
            'disabled_notice'       => '',
            'discussion_model'      => $discussionmodel,
            'discussion_model_label' => $discussionmodellabel,
            'discussion_models'     => $discussionmodels,
            'aggregate_html'        => $aggregatehtml,
            'has_aggregate'         => !empty($forumanalysis),
            'stale_notice'          => $stalefound,
            'last_updated'          => $lastmod ? userdate($lastmod) : '',
            'analysis_pending'      => $pendingfound,
            'students'              => $students,
            'can_trigger'           => $cantrigger,
            'can_configure'         => $canconfigure,
            'can_view_peers'        => $canviewpeers,
            'owndata_notice'        => $owndatanotice,
            'trigger_url'           => $triggerurl,
            'config_url'            => $configurl,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the discussion_models array for forum_config.mustache.
     *
     * Returns an array of option objects for the radio button selector:
     *   value       — model constant string (e.g. 'open_engagement')
     *   label       — short display name from lang strings
     *   description — full description from lang strings
     *   selected    — bool, true for the currently active model
     *
     * @param  string $currentmodel  The currently saved discussion model value.
     * @return array
     */
    private function build_discussion_models(string $currentmodel): array {
        $models = [
            \local_lid\llm\prompt_builder::MODEL_INDEPENDENT_FIRST,
            \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT,
            \local_lid\llm\prompt_builder::MODEL_STRUCTURED_DEBATE,
        ];

        $result = [];
        foreach ($models as $value) {
            $result[] = [
                'value'       => $value,
                'label'       => get_string('discussion_model_' . $value, 'local_lid'),
                'description' => get_string('discussion_model_' . $value . '_desc', 'local_lid'),
                'selected'    => ($value === $currentmodel),
            ];
        }
        return $result;
    }

    /**
     * Derive display highlights from a student_forum analysis row.
     *
     * Returns top Bloom's level, label, top competency score, CPI score,
     * and CPI band from the stored analysis_json. Returns safe defaults
     * if the row is pending, stale, or has no JSON.
     *
     * @param  \stdClass $srow  local_lid_analysis record, scope = 'student_forum'.
     * @return array  [int $topbloom, string $toplabel, int $topscore, int $cpiscore, string $cpiband]
     */
    private function derive_student_highlights(\stdClass $srow): array {
        $bloomlabels = [
            1 => 'Remember', 2 => 'Understand', 3 => 'Apply',
            4 => 'Analyze',  5 => 'Evaluate',   6 => 'Create',
        ];

        $defaults = [0, '', 0, 0, ''];

        if (empty($srow->analysis_json)) {
            return $defaults;
        }

        $data = json_decode($srow->analysis_json, true);
        if (!is_array($data)) {
            return $defaults;
        }

        $topbloom = 0;
        $topscore = 0;

        foreach ($data['blooms_progression'] ?? [] as $b) {
            $level = (int) ($b['level']       ?? 0);
            $dots  = (int) ($b['dots_active'] ?? 0);
            if ($level > $topbloom && $dots > 0) {
                $topbloom = $level;
            }
        }

        foreach ($data['competencies'] ?? [] as $c) {
            $score = (int) ($c['score'] ?? 0);
            if ($score > $topscore) {
                $topscore = $score;
            }
        }

        $cpi      = $data['cognitive_performance_index'] ?? [];
        $cpiscore = (int) ($cpi['cpi_score'] ?? 0);
        $cpiband  = $cpi['cpi_band'] ?? '';

        return [
            $topbloom,
            $bloomlabels[$topbloom] ?? '',
            $topscore,
            $cpiscore,
            $cpiband,
        ];
    }
}
