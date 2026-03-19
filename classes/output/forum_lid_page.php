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
 * Loaded by forum_view.php and passed to the forum_lid Mustache template.
 *
 * Data structure exported to template:
 *
 *   forumid          int
 *   forumname        string
 *   courseid         int
 *   cmid             int
 *   lid_enabled      bool
 *   disabled_notice  string    — shown when lid_enabled = false
 *   aggregate_json   string    — JSON-encoded forum-scope LID data (or null)
 *   has_aggregate    bool
 *   stale_notice     bool
 *   last_updated     string
 *   students         array     — one entry per student with at least one post:
 *     userid         int
 *     fullname       string
 *     userpic        string    — HTML for user picture
 *     post_count     int       — complete post analyses for this student
 *     pending_count  int
 *     error_count    int
 *     top_bloom      int       — highest bloom_level seen across their posts
 *     top_bloom_label string
 *     top_score      int       — highest competency score across their posts
 *     student_json   string    — JSON-encoded student_forum-scope LID (or null)
 *     has_student_lid bool
 *     student_url    string    — URL to Student LID page for this student
 *     posts          array     — individual post analyses for this student:
 *       postid       int
 *       subject      string
 *       posted_date  string
 *       analysis_json string
 *       has_analysis  bool
 *       status       string
 *       status_label string
 *       reanalyse_url string
 *   can_trigger      bool
 *   can_configure    bool
 *   trigger_url      string
 *   config_url       string
 */
class forum_lid_page implements \renderable, \templatable {

    /** @var \stdClass Course module record. */
    private \stdClass $cm;

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \context_module */
    private \context_module $context;

    /**
     * @param \stdClass       $cm
     * @param \stdClass       $course
     * @param \context_module $context
     */
    public function __construct(\stdClass $cm, \stdClass $course, \context_module $context) {
        $this->cm      = $cm;
        $this->course  = $course;
        $this->context = $context;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param  \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB, $USER, $PAGE;

        $forumid    = (int) $this->cm->instance;
        $courseid   = (int) $this->course->id;
        $cmid       = (int) $this->cm->id;
        $cantrigger = has_capability('local/lid:triggeranalysis', $this->context);
        $canconfigure = has_capability('local/lid:configureforum', $this->context);

        // Check LID enabled for this forum.
        $config = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);
        $lidenabled = $config && (bool) $config->enabled;

        if (!$lidenabled) {
            $forum = $DB->get_record('forum', ['id' => $forumid], 'name');
            return [
                'forumid'        => $forumid,
                'forumname'      => $forum ? format_string($forum->name) : '',
                'courseid'       => $courseid,
                'cmid'           => $cmid,
                'lid_enabled'    => false,
                'disabled_notice' => get_string('forum_disabled_notice', 'local_lid'),
                'aggregate_json' => null,
                'has_aggregate'  => false,
                'stale_notice'   => false,
                'last_updated'   => '',
                'students'       => [],
                'can_trigger'    => $cantrigger,
                'can_configure'  => $canconfigure,
                'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
                'config_url'     => (new \moodle_url('/local/lid/ajax.php',
                    ['action' => 'forum_config', 'forumid' => $forumid]))->out(false),
            ];
        }

        $forum = $DB->get_record('forum', ['id' => $forumid], 'id, name');

        // Forum-scope aggregate.
        $forumanalysis = $DB->get_record('local_lid_analysis', [
            'scope'    => 'forum',
            'forumid'  => $forumid,
            'courseid' => $courseid,
            'status'   => 'complete',
        ]);

        // Current prompt hash for stale detection.
        $currenthash = hash('sha256',
            (new \local_lid\llm\prompt_builder($courseid, $forumid))->get_active_template()
        );

        $stale   = false;
        $lastmod = $forumanalysis ? (int) $forumanalysis->timemodified : 0;

        // Build student list — get all userids with post-scope records in this forum.
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {local_lid_analysis}
              WHERE scope = 'post'
                AND forumid = :forumid
                AND userid IS NOT NULL
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

            // Student's post analyses for this forum.
            $postanalyses = $DB->get_records(
                'local_lid_analysis',
                ['scope' => 'post', 'forumid' => $forumid, 'userid' => $userid],
                'timecreated ASC'
            );

            // Student_forum aggregate.
            $studentagg = $DB->get_record('local_lid_analysis', [
                'scope'    => 'student_forum',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            // Derive top bloom and top score from student aggregate or post analyses.
            [$topbloom, $toplabel, $topscore] = $this->derive_student_highlights(
                $studentagg, $postanalyses
            );

            // Count statuses.
            $counts = ['complete' => 0, 'pending' => 0, 'error' => 0];
            foreach ($postanalyses as $pa) {
                $s = ($pa->status === 'processing') ? 'pending' : $pa->status;
                if (isset($counts[$s])) {
                    $counts[$s]++;
                }
            }

            // Check for stale analyses.
            if (!$stale && $currenthash) {
                foreach ($postanalyses as $pa) {
                    if ($pa->status === 'complete' && $pa->prompt_hash
                        && $pa->prompt_hash !== $currenthash) {
                        $stale = true;
                        break;
                    }
                }
            }

            // Build individual post rows.
            $postrows = [];
            foreach ($postanalyses as $pa) {
                $postrow = $this->build_post_row($pa, $cantrigger);
                if ($postrow) {
                    $postrows[] = $postrow;
                    if ($pa->timemodified > $lastmod) {
                        $lastmod = (int) $pa->timemodified;
                    }
                }
            }

            $userpic = $output->user_picture($user, [
                'size'    => 32,
                'courseid' => $courseid,
                'link'    => false,
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
                'student_json'    => $studentagg ? $studentagg->analysis_json : null,
                'has_student_lid' => !empty($studentagg),
                'student_url'     => (new \moodle_url('/local/lid/student_view.php', [
                    'userid'   => $userid,
                    'courseid' => $courseid,
                ]))->out(false),
                'posts'           => $postrows,
            ];
        }

        // Sort students by top_score descending.
        usort($students, fn($a, $b) => $b['top_score'] <=> $a['top_score']);

        return [
            'forumid'        => $forumid,
            'forumname'      => $forum ? format_string($forum->name) : '',
            'courseid'       => $courseid,
            'cmid'           => $cmid,
            'lid_enabled'    => true,
            'disabled_notice' => '',
            'aggregate_json' => $forumanalysis ? $forumanalysis->analysis_json : null,
            'has_aggregate'  => !empty($forumanalysis),
            'stale_notice'   => $stale,
            'last_updated'   => $lastmod ? userdate($lastmod) : '',
            'students'       => $students,
            'can_trigger'    => $cantrigger,
            'can_configure'  => $canconfigure,
            'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
            'config_url'     => (new \moodle_url('/local/lid/ajax.php',
                ['action' => 'forum_config', 'forumid' => $forumid]))->out(false),
        ];
    }

    /**
     * Derive top Bloom's level and top competency score from a student's data.
     *
     * Prefers the student_forum aggregate if available, otherwise scans
     * individual post analyses.
     *
     * @param  \stdClass|false  $studentagg
     * @param  \stdClass[]      $postanalyses
     * @return array  [int $topbloom, string $toplabel, int $topscore]
     */
    private function derive_student_highlights($studentagg, array $postanalyses): array {
        $bloomlabels = [
            1 => 'Remember', 2 => 'Understand', 3 => 'Apply',
            4 => 'Analyze',  5 => 'Evaluate',   6 => 'Create',
        ];

        if ($studentagg && !empty($studentagg->analysis_json)) {
            $data = json_decode($studentagg->analysis_json, true);
            if ($data) {
                $topbloom  = 0;
                $topscore  = 0;
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
                return [$topbloom, $bloomlabels[$topbloom] ?? '', $topscore];
            }
        }

        // Fall back to scanning post analyses.
        $topbloom = 0;
        $topscore = 0;
        foreach ($postanalyses as $pa) {
            if (empty($pa->analysis_json)) {
                continue;
            }
            $data = json_decode($pa->analysis_json, true);
            if (!$data) {
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
     * Build a single post row array for the student posts list.
     *
     * @param  \stdClass $pa          local_lid_analysis record (post scope).
     * @param  bool      $cantrigger
     * @return array|null             Null if the post record no longer exists.
     */
    private function build_post_row(\stdClass $pa, bool $cantrigger): ?array {
        global $DB;

        $post = $DB->get_record('forum_posts', ['id' => $pa->postid], 'id, subject, timecreated, discussion');
        if (!$post) {
            return null;
        }

        // Get subject from the discussion if not on the post.
        $subject = $post->subject ?? '';
        if (empty($subject)) {
            $subject = $DB->get_field('forum_discussions', 'name', ['id' => $post->discussion]) ?? '';
        }

        $statuslabels = [
            'complete'   => get_string('status_complete',   'local_lid'),
            'pending'    => get_string('status_pending',    'local_lid'),
            'processing' => get_string('status_processing', 'local_lid'),
            'error'      => get_string('status_error',      'local_lid'),
        ];

        return [
            'postid'        => (int) $pa->postid,
            'subject'       => format_string($subject),
            'posted_date'   => userdate($post->timecreated),
            'analysis_json' => $pa->analysis_json ?? null,
            'has_analysis'  => $pa->status === 'complete' && !empty($pa->analysis_json),
            'status'        => $pa->status,
            'status_label'  => $statuslabels[$pa->status] ?? $pa->status,
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
