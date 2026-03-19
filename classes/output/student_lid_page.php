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
 * Loaded by student_view.php and passed to the student_lid Mustache template.
 *
 * Data structure exported to template:
 *
 *   userid           int
 *   fullname         string
 *   userpic          string    — HTML for user picture
 *   courseid         int
 *   coursename       string
 *   has_data         bool      — false when no analysed posts exist
 *   nodata_notice    string
 *   aggregate_json   string    — JSON-encoded course-scope student aggregate (or null)
 *                                (computed by merging all student_forum aggregates
 *                                 for this student in this course)
 *   has_aggregate    bool
 *   stale_notice     bool
 *   last_updated     string
 *   forums           array     — one entry per forum with analysed posts:
 *     forumid        int
 *     forumname      string
 *     cmid           int
 *     forum_url      string    — URL to the Forum LID page
 *     student_json   string    — JSON-encoded student_forum-scope LID (or null)
 *     has_student_lid bool
 *     post_count     int
 *     pending_count  int
 *     posts          array     — individual post analyses:
 *       postid       int
 *       subject      string
 *       posted_date  string
 *       analysis_json string
 *       has_analysis  bool
 *       status       string
 *       status_label string
 *   can_trigger      bool
 *   trigger_url      string
 */
class student_lid_page implements \renderable, \templatable {

    /** @var \stdClass The student user record. */
    private \stdClass $student;

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $context;

    /**
     * @param \stdClass       $student
     * @param \stdClass       $course
     * @param \context_course $context
     */
    public function __construct(\stdClass $student, \stdClass $course, \context_course $context) {
        $this->student = $student;
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
        global $DB;

        $userid    = (int) $this->student->id;
        $courseid  = (int) $this->course->id;
        $cantrigger = has_capability('local/lid:triggeranalysis', $this->context);

        $userpic = $output->user_picture($this->student, [
            'size'     => 48,
            'courseid' => $courseid,
            'link'     => false,
        ]);

        // Get all LID-enabled forums in this course where this student has posts.
        $enabledforumids = $DB->get_fieldset_select(
            'local_lid_forum_config',
            'forumid',
            'courseid = :courseid AND enabled = 1',
            ['courseid' => $courseid]
        );

        if (empty($enabledforumids)) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        [$insql, $inparams] = $DB->get_in_or_equal($enabledforumids);

        // Check whether this student has any post analyses at all.
        $hasanypost = $DB->record_exists_sql(
            "SELECT 1 FROM {local_lid_analysis}
              WHERE scope = 'post' AND userid = :userid AND courseid = :courseid
                AND forumid {$insql}",
            array_merge(['userid' => $userid, 'courseid' => $courseid], $inparams)
        );

        if (!$hasanypost) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        // Build per-forum data.
        $forumsdata = [];
        $stale      = false;
        $lastmod    = 0;
        $studentforumaggs = []; // Collected to build cross-forum aggregate.

        foreach ($enabledforumids as $forumid) {
            $forumid = (int) $forumid;

            // Does this student have any posts in this forum?
            $hasposts = $DB->record_exists('local_lid_analysis', [
                'scope'    => 'post',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
            ]);

            if (!$hasposts) {
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

            // Student_forum aggregate for this forum.
            $studentagg = $DB->get_record('local_lid_analysis', [
                'scope'    => 'student_forum',
                'forumid'  => $forumid,
                'userid'   => $userid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            if ($studentagg) {
                $studentforumaggs[] = $studentagg;
                if ($studentagg->timemodified > $lastmod) {
                    $lastmod = (int) $studentagg->timemodified;
                }
            }

            // Individual post analyses.
            $postanalyses = $DB->get_records(
                'local_lid_analysis',
                ['scope' => 'post', 'forumid' => $forumid, 'userid' => $userid],
                'timecreated ASC'
            );

            // Current prompt hash for stale detection.
            if (!$stale) {
                $currenthash = hash('sha256',
                    (new \local_lid\llm\prompt_builder($courseid, $forumid))->get_active_template()
                );
                foreach ($postanalyses as $pa) {
                    if ($pa->status === 'complete' && $pa->prompt_hash
                        && $pa->prompt_hash !== $currenthash) {
                        $stale = true;
                        break;
                    }
                }
            }

            // Build post rows.
            $postrows     = [];
            $completecnt  = 0;
            $pendingcnt   = 0;

            $statuslabels = [
                'complete'   => get_string('status_complete',   'local_lid'),
                'pending'    => get_string('status_pending',    'local_lid'),
                'processing' => get_string('status_processing', 'local_lid'),
                'error'      => get_string('status_error',      'local_lid'),
            ];

            foreach ($postanalyses as $pa) {
                $post = $DB->get_record('forum_posts',
                    ['id' => $pa->postid], 'id, subject, timecreated, discussion');
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

                if ($pa->timemodified > $lastmod) {
                    $lastmod = (int) $pa->timemodified;
                }

                $postrows[] = [
                    'postid'        => (int) $pa->postid,
                    'subject'       => format_string($subject),
                    'posted_date'   => userdate($post->timecreated),
                    'analysis_json' => $pa->analysis_json ?? null,
                    'has_analysis'  => $status === 'complete' && !empty($pa->analysis_json),
                    'status'        => $status,
                    'status_label'  => $statuslabels[$status] ?? $status,
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
                'student_json'   => $studentagg ? $studentagg->analysis_json : null,
                'has_student_lid' => !empty($studentagg),
                'post_count'     => $completecnt,
                'pending_count'  => $pendingcnt,
                'posts'          => $postrows,
            ];
        }

        if (empty($forumsdata)) {
            return $this->empty_response($userid, $userpic, $courseid, $cantrigger);
        }

        // Build or load the cross-forum student aggregate for this course.
        $aggregatejson = $this->get_or_build_student_course_aggregate(
            $userid, $courseid, $studentforumaggs
        );

        return [
            'userid'         => $userid,
            'fullname'       => fullname($this->student),
            'userpic'        => $userpic,
            'courseid'       => $courseid,
            'coursename'     => format_string($this->course->fullname),
            'has_data'       => true,
            'nodata_notice'  => '',
            'aggregate_json' => $aggregatejson,
            'has_aggregate'  => !empty($aggregatejson),
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
     * Build (or load from DB) a cross-forum student aggregate for this course.
     *
     * There is no 'student_course' scope in the DB — we compute it on demand
     * by running the aggregator across all student_forum records for this
     * student in this course. The result is not persisted (too granular to
     * cache usefully at this level), but the aggregator is fast since it
     * works on already-computed student_forum JSON rather than raw posts.
     *
     * @param  int          $userid
     * @param  int          $courseid
     * @param  \stdClass[]  $studentforumaggs  student_forum analysis records.
     * @return string|null  JSON string, or null if no data.
     */
    private function get_or_build_student_course_aggregate(
        int $userid,
        int $courseid,
        array $studentforumaggs
    ): ?string {

        if (empty($studentforumaggs)) {
            return null;
        }

        if (count($studentforumaggs) === 1) {
            // Only one forum — return its aggregate directly.
            return $studentforumaggs[0]->analysis_json ?? null;
        }

        // Decode all student_forum JSON and merge via aggregator.
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
            return null;
        }

        // Use the aggregator's internal merge via a lightweight facade.
        // We can't call private merge() directly, so we use rebuild methods
        // with a temporary scope. Since there's no student_course scope in the
        // DB, we build a throwaway aggregated result instead.
        //
        // The cleanest approach: call a public method on aggregator that
        // accepts pre-decoded post arrays. We add get_merged_json() for this.
        $aggregator = new \local_lid\analysis\aggregator();
        $merged     = $aggregator->merge_decoded_posts($decoded, $courseid, null, $userid);

        return $merged
            ? json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }

    /**
     * Build the empty/no-data response array.
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
            'aggregate_json' => null,
            'has_aggregate'  => false,
            'stale_notice'   => false,
            'last_updated'   => '',
            'forums'         => [],
            'can_trigger'    => $cantrigger,
            'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
        ];
    }
}
