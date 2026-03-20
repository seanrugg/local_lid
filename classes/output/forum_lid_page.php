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
 * Renderable for the Forum LID tab.
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
 * All analysis cards (forum aggregate, per-student compact cards, individual
 * post cards) are pre-rendered to HTML strings. The template slots them in
 * with {{{ }}} so Mustache does not escape the HTML.
 *
 * Data structure exported to template:
 *
 *   forumid              int
 *   forumname            string
 *   courseid             int
 *   cmid                 int
 *   lid_enabled          bool
 *   disabled_notice      string
 *   aggregate_html       string   — forum-scope aggregate card HTML (or '')
 *   has_aggregate        bool
 *   stale_notice         bool
 *   last_updated         string
 *   students             array
 *     userid             int
 *     fullname           string
 *     userpic            string   — HTML img tag
 *     post_count         int
 *     pending_count      int
 *     error_count        int
 *     top_bloom          int
 *     top_bloom_label    string
 *     top_score          int
 *     student_html       string   — compact student_forum card HTML (or '')
 *     has_student_lid    bool
 *     student_url        string
 *     posts              array
 *       postid           int
 *       subject          string
 *       posted_date      string
 *       analysis_html    string   — compact post card HTML (or '')
 *       has_analysis     bool
 *       status           string
 *       status_label     string
 *       status_html      string   — pre-rendered status badge HTML
 *       can_trigger      bool
 *       reanalyse_url    string
 *   can_trigger          bool
 *   can_configure        bool
 *   trigger_url          string
 *   config_url           string
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
        global $DB;

        /** @var renderer $output */
        $forumid      = (int) $this->cm->instance;
        $courseid     = (int) $this->course->id;
        $cmid         = (int) $this->cm->id;
        $cantrigger   = has_capability('local/lid:triggeranalysis', $this->context);
        $canconfigure = has_capability('local/lid:configureforum', $this->context);

        $config     = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);
        $lidenabled = $config && (bool) $config->enabled;
        $forum      = $DB->get_record('forum', ['id' => $forumid], 'id, name');

        $triggerurl = (new \moodle_url('/local/lid/ajax.php'))->out(false);
        $configurl  = (new \moodle_url('/local/lid/ajax.php', [
            'action'  => 'forum_config',
            'forumid' => $forumid,
        ]))->out(false);

        if (!$lidenabled) {
            return [
                'forumid'         => $forumid,
                'forumname'       => $forum ? format_string($forum->name) : '',
                'courseid'        => $courseid,
                'cmid'            => $cmid,
                'lid_enabled'     => false,
                'disabled_notice' => get_string('forum_disabled_notice', 'local_lid'),
                'aggregate_html'  => '',
                'has_aggregate'   => false,
                'stale_notice'    => false,
                'last_updated'    => '',
                'students'        => [],
                'can_trigger'     => $cantrigger,
                'can_configure'   => $canconfigure,
                'trigger_url'     => $triggerurl,
                'config_url'      => $configurl,
            ];
        }

        // Forum-scope aggregate.
        $forumanalysis = $DB->get_record('local_lid_analysis', [
            'scope'    => 'forum',
            'forumid'  => $forumid,
            'courseid' => $courseid,
            'status'   => 'complete',
        ]);

        $aggregatehtml = $forumanalysis
            ? $output->render_analysis_card($forumanalysis->analysis_json)
            : '';

        $currenthash = hash('sha256',
            (new \local_lid\llm\prompt_builder($courseid, $forumid))->get_active_template()
        );

        $stale   = false;
        $lastmod = $forumanalysis ? (int) $forumanalysis->timemodified : 0;

        // All userids with post-scope records in this forum.
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {local_lid_analysis}
              WHERE scope = 'post' AND forumid = :forumid AND userid IS NOT NULL
           ORDER BY userid ASC",
            ['forumid' => $forumid]
        );

        $students = [];

        foreach ($userids as $userid) {
            $userid = (int) $userid;
            $user   = $DB->get_record('user', ['id' => $userid]);
            if (!$user) {
                continue;
            }

            $postanalyses = $DB->get_records(
                'local_lid_analysis',
                ['scope' => 'post', 'forumid' => $forumid, 'userid' => $userid],
                'timecreated ASC'
            );

            $studentagg = $DB->get_record('local_lid_analysis', [
                'scope'    => 'student_forum',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            [$topbloom, $toplabel, $topscore] = $this->derive_student_highlights(
                $studentagg, $postanalyses
            );

            $counts = ['complete' => 0, 'pending' => 0, 'error' => 0];
            foreach ($postanalyses as $pa) {
                $s = ($pa->status === 'processing') ? 'pending' : $pa->status;
                if (isset($counts[$s])) {
                    $counts[$s]++;
                }
            }

            if (!$stale && $currenthash) {
                foreach ($postanalyses as $pa) {
                    if ($pa->status === 'complete'
                        && $pa->prompt_hash
                        && $pa->prompt_hash !== $currenthash) {
                        $stale = true;
                        break;
                    }
                }
            }

            // Pre-render compact student_forum card.
            $studenthtml = $studentagg
                ? $output->render_analysis_card(
                    $studentagg->analysis_json,
                    ['compact' => true, 'show_portfolio' => false, 'show_timeline' => false]
                  )
                : '';

            // Build post rows with pre-rendered cards and status badges.
            $postrows = [];
            foreach ($postanalyses as $pa) {
                $postrow = $this->build_post_row($pa, $cantrigger, $output);
                if ($postrow !== null) {
                    $postrows[] = $postrow;
                    if ((int)$pa->timemodified > $lastmod) {
                        $lastmod = (int) $pa->timemodified;
                    }
                }
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
                'post_count'      => $counts['complete'],
                'pending_count'   => $counts['pending'],
                'error_count'     => $counts['error'],
                'top_bloom'       => $topbloom,
                'top_bloom_label' => $toplabel,
                'top_score'       => $topscore,
                'student_html'    => $studenthtml,
                'has_student_lid' => !empty($studentagg),
                'student_url'     => (new \moodle_url('/local/lid/student_view.php', [
                    'userid'   => $userid,
                    'courseid' => $courseid,
                ]))->out(false),
                'posts'           => $postrows,
            ];
        }

        // Sort by top_score descending.
        usort($students, fn($a, $b) => $b['top_score'] <=> $a['top_score']);

        return [
            'forumid'         => $forumid,
            'forumname'       => $forum ? format_string($forum->name) : '',
            'courseid'        => $courseid,
            'cmid'            => $cmid,
            'lid_enabled'     => true,
            'disabled_notice' => '',
            'aggregate_html'  => $aggregatehtml,
            'has_aggregate'   => !empty($forumanalysis),
            'stale_notice'    => $stale,
            'last_updated'    => $lastmod ? userdate($lastmod) : '',
            'students'        => $students,
            'can_trigger'     => $cantrigger,
            'can_configure'   => $canconfigure,
            'trigger_url'     => $triggerurl,
            'config_url'      => $configurl,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Derive top Bloom's level and top competency score from a student's data.
     *
     * @param  \stdClass|false $studentagg
     * @param  \stdClass[]     $postanalyses
     * @return array  [int $topbloom, string $toplabel, int $topscore]
     */
    private function derive_student_highlights($studentagg, array $postanalyses): array {
        $bloomlabels = [
            1 => 'Remember', 2 => 'Understand', 3 => 'Apply',
            4 => 'Analyze',  5 => 'Evaluate',   6 => 'Create',
        ];

        $source = $studentagg && !empty($studentagg->analysis_json)
            ? [json_decode($studentagg->analysis_json, true)]
            : array_filter(array_map(
                fn($pa) => !empty($pa->analysis_json) ? json_decode($pa->analysis_json, true) : null,
                $postanalyses
              ));

        $topbloom = 0;
        $topscore = 0;

        foreach ($source as $data) {
            if (!is_array($data)) {
                continue;
            }
            foreach ($data['blooms_progression'] ?? [] as $b) {
                if ((int)($b['level'] ?? 0) > $topbloom && (int)($b['dots_active'] ?? 0) > 0) {
                    $topbloom = (int) $b['level'];
                }
            }
            foreach ($data['competencies'] ?? [] as $c) {
                if ((int)($c['score'] ?? 0) > $topscore) {
                    $topscore = (int) $c['score'];
                }
            }
        }

        return [$topbloom, $bloomlabels[$topbloom] ?? '', $topscore];
    }

    /**
     * Build a single post row for the student posts list.
     *
     * @param  \stdClass $pa
     * @param  bool      $cantrigger
     * @param  renderer  $output
     * @return array|null
     */
    private function build_post_row(\stdClass $pa, bool $cantrigger, renderer $output): ?array {
        global $DB;

        $post = $DB->get_record('forum_posts',
            ['id' => $pa->postid], 'id, subject, timecreated, discussion');
        if (!$post) {
            return null;
        }

        $subject = $post->subject ?? '';
        if (empty($subject)) {
            $subject = $DB->get_field('forum_discussions', 'name',
                ['id' => $post->discussion]) ?? '';
        }

        // Pre-render compact post card (only when complete).
        $analysishtml = ($pa->status === 'complete' && !empty($pa->analysis_json))
            ? $output->render_analysis_card(
                $pa->analysis_json,
                ['compact' => true, 'show_portfolio' => false, 'show_timeline' => false]
              )
            : '';

        // Pre-render status badge.
        $statushtml = $output->render_status_badge($pa->status);

        return [
            'postid'        => (int) $pa->postid,
            'subject'       => format_string($subject),
            'posted_date'   => userdate($post->timecreated),
            'analysis_html' => $analysishtml,
            'has_analysis'  => !empty($analysishtml),
            'status'        => $pa->status,
            'status_label'  => get_string('status_' . $pa->status, 'local_lid'),
            'status_html'   => $statushtml,
            'can_trigger'   => $cantrigger,
            'reanalyse_url' => $cantrigger
                ? (new \moodle_url('/local/lid/ajax.php', [
                    'action'     => 'trigger',
                    'analysisid' => $pa->id,
                ]))->out(false)
                : '',
        ];
    }
}
