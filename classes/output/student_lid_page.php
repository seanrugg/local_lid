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
 * Renderable for the Student LID profile tab.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderable data object for the Student LID dashboard.
 *
 * All analysis cards are pre-rendered to HTML strings. The template outputs
 * them unescaped with {{{ }}}.
 *
 * Data structure exported to template:
 *
 *   userid               int
 *   fullname             string
 *   userpic              string   — HTML img tag
 *   courseid             int
 *   coursename           string
 *   has_data             bool
 *   nodata_notice        string
 *   aggregate_html       string   — cross-forum student aggregate card HTML (or '')
 *   has_aggregate        bool
 *   stale_notice         bool
 *   last_updated         string
 *   forums               array
 *     forumid            int
 *     forumname          string
 *     cmid               int
 *     forum_url          string
 *     student_html       string   — student_forum card HTML (or '')
 *     has_student_lid    bool
 *     post_count         int
 *     pending_count      int
 *     posts              array
 *       postid           int
 *       subject          string
 *       posted_date      string
 *       analysis_html    string   — compact post card HTML (or '')
 *       has_analysis     bool
 *       status           string
 *       status_html      string   — pre-rendered status badge HTML
 *   can_trigger          bool
 *   trigger_url          string
 */
class student_lid_page implements \renderable, \templatable {

    /** @var \stdClass */
    private \stdClass $student;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $context;

    public function __construct(\stdClass $student, \stdClass $course, \context_course $context) {
        $this->student = $student;
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
        $userid     = (int) $this->student->id;
        $courseid   = (int) $this->course->id;
        $cantrigger = has_capability('local/lid:triggeranalysis', $this->context);

        $userpic = $output->user_picture($this->student, [
            'size'     => 48,
            'courseid' => $courseid,
            'link'     => false,
        ]);

        $enabledforumids = $DB->get_fieldset_select(
            'local_lid_forum_config',
            'forumid',
            'courseid = :courseid AND enabled = 1',
            ['courseid' => $courseid]
        );

        if (empty($enabledforumids)) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        $forumidlist = implode(',', array_map('intval', $enabledforumids));

        $hasanypost = $DB->record_exists_sql(
            "SELECT 1 FROM {local_lid_analysis}
              WHERE scope = 'post' AND userid = :userid AND courseid = :courseid
                AND forumid IN ({$forumidlist})",
            ['userid' => $userid, 'courseid' => $courseid]
        );

        if (!$hasanypost) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        $forumsdata       = [];
        $stale            = false;
        $lastmod          = 0;
        $studentforumaggs = [];

        foreach ($enabledforumids as $forumid) {
            $forumid = (int) $forumid;

            if (!$DB->record_exists('local_lid_analysis', [
                'scope'    => 'post',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
            ])) {
                continue;
            }

            $forum = $DB->get_record('forum', ['id' => $forumid], 'id, name');
            if (!$forum) {
                continue;
            }

            $cm = get_coursemodule_from_instance('forum', $forumid, $courseid);
            if (!$cm) {
                continue;
            }

            $studentagg = $DB->get_record('local_lid_analysis', [
                'scope'    => 'student_forum',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            if ($studentagg) {
                $studentforumaggs[] = $studentagg;
                if ((int)$studentagg->timemodified > $lastmod) {
                    $lastmod = (int) $studentagg->timemodified;
                }
            }

            // Pre-render student_forum card.
            $studenthtml = $studentagg
                ? $output->render_analysis_card($studentagg->analysis_json)
                : '';

            $postanalyses = $DB->get_records(
                'local_lid_analysis',
                ['scope' => 'post', 'forumid' => $forumid, 'userid' => $userid],
                'timecreated ASC'
            );

            if (!$stale) {
                $currenthash = hash('sha256',
                    (new \local_lid\llm\prompt_builder($courseid, $forumid))->get_active_template()
                );
                foreach ($postanalyses as $pa) {
                    if ($pa->status === 'complete'
                        && $pa->prompt_hash
                        && $pa->prompt_hash !== $currenthash) {
                        $stale = true;
                        break;
                    }
                }
            }

            $postrows   = [];
            $completecnt = 0;
            $pendingcnt  = 0;

            foreach ($postanalyses as $pa) {
                $post = $DB->get_record('forum_posts',
                    ['id' => $pa->postid], 'id, subject, created, discussion');
                if (!$post) {
                    continue;
                }

                $subject = $post->subject ?? '';
                if (empty($subject)) {
                    $subject = $DB->get_field('forum_discussions', 'name',
                        ['id' => $post->discussion]) ?? '';
                }

                $status = $pa->status;
                if ($status === 'complete') {
                    $completecnt++;
                } elseif (in_array($status, ['pending', 'processing'])) {
                    $pendingcnt++;
                }

                if ((int)$pa->timemodified > $lastmod) {
                    $lastmod = (int) $pa->timemodified;
                }

                // Pre-render compact post card and status badge.
                $analysishtml = ($status === 'complete' && !empty($pa->analysis_json))
                    ? $output->render_analysis_card(
                        $pa->analysis_json,
                        ['compact' => true, 'show_portfolio' => false, 'show_timeline' => false]
                      )
                    : '';

                $postrows[] = [
                    'postid'        => (int) $pa->postid,
                    'subject'       => format_string($subject),
                    'posted_date'   => userdate($post->created),
                    'analysis_html' => $analysishtml,
                    'has_analysis'  => !empty($analysishtml),
                    'status'        => $status,
                    'status_html'   => $output->render_status_badge($status),
                ];
            }

            $forumsdata[] = [
                'forumid'        => $forumid,
                'forumname'      => format_string($forum->name),
                'cmid'           => (int) $cm->id,
                'forum_url'      => (new \moodle_url('/local/lid/forum_view.php', [
                    'cmid'     => $cm->id,
                    'courseid' => $courseid,
                ]))->out(false),
                'student_html'   => $studenthtml,
                'has_student_lid' => !empty($studentagg),
                'post_count'     => $completecnt,
                'pending_count'  => $pendingcnt,
                'posts'          => $postrows,
            ];
        }

        if (empty($forumsdata)) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        // Build cross-forum student aggregate.
        $aggregatehtml = $this->build_student_course_aggregate_html(
            $studentforumaggs, $courseid, $userid, $output
        );

        return [
            'userid'         => $userid,
            'fullname'       => fullname($this->student),
            'userpic'        => $userpic,
            'courseid'       => $courseid,
            'coursename'     => format_string($this->course->fullname),
            'has_data'       => true,
            'nodata_notice'  => '',
            'aggregate_html' => $aggregatehtml,
            'has_aggregate'  => !empty($aggregatehtml),
            'stale_notice'   => $stale,
            'last_updated'   => $lastmod ? userdate($lastmod) : '',
            'forums'         => $forumsdata,
            'can_trigger'    => $cantrigger,
            'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build and render the cross-forum student aggregate card HTML.
     *
     * Merges all student_forum aggregates via the aggregator, then renders
     * the result. Returns '' if there is nothing to merge.
     *
     * @param  \stdClass[] $studentforumaggs
     * @param  int         $courseid
     * @param  int         $userid
     * @param  renderer    $output
     * @return string  Pre-rendered HTML, or ''.
     */
    private function build_student_course_aggregate_html(
        array $studentforumaggs,
        int $courseid,
        int $userid,
        renderer $output
    ): string {

        if (empty($studentforumaggs)) {
            return '';
        }

        if (count($studentforumaggs) === 1) {
            return $output->render_analysis_card($studentforumaggs[0]->analysis_json ?? null);
        }

        $decoded = [];
        foreach ($studentforumaggs as $agg) {
            if (!empty($agg->analysis_json)) {
                $data = json_decode($agg->analysis_json, true);
                if (is_array($data)) {
                    $decoded[] = $data;
                }
            }
        }

        if (empty($decoded)) {
            return '';
        }

        $aggregator = new \local_lid\analysis\aggregator();
        $merged     = $aggregator->merge_decoded_posts($decoded, $courseid, null, $userid);

        if (!$merged) {
            return '';
        }

        return $output->render_analysis_card(
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Build the empty / no-data response array.
     *
     * @param  int    $userid
     * @param  string $userpic
     * @param  int    $courseid
     * @param  bool   $cantrigger
     * @return array
     */
    private function empty_response(
        int $userid,
        string $userpic,
        int $courseid,
        bool $cantrigger
    ): array {
        return [
            'userid'         => $userid,
            'fullname'       => fullname($this->student),
            'userpic'        => $userpic,
            'courseid'       => $courseid,
            'coursename'     => format_string($this->course->fullname),
            'has_data'       => false,
            'nodata_notice'  => get_string('dashboard_student_noposts', 'local_lid'),
            'aggregate_html' => '',
            'has_aggregate'  => false,
            'stale_notice'   => false,
            'last_updated'   => '',
            'forums'         => [],
            'can_trigger'    => $cantrigger,
            'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
        ];
    }
}
