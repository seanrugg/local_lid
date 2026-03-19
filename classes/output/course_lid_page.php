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
 * Renderable for the Course LID report page.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderable data object for the Course LID dashboard.
 *
 * Loaded by report.php and passed to the course_lid Mustache template.
 *
 * Data structure exported to template:
 *
 *   courseid         int
 *   coursename       string
 *   haslid           bool    — false when no enabled forums exist
 *   noforums_notice  string  — localised notice when haslid = false
 *   aggregate_json   string  — JSON-encoded course-scope LID data (or null)
 *   has_aggregate    bool
 *   stale_notice     bool    — true if any analysis used a different prompt hash
 *   last_updated     string  — human-readable timestamp of newest analysis
 *   forums           array   — one entry per LID-enabled forum:
 *     forumid        int
 *     forumname      string
 *     cmid           int
 *     analysis_json  string  — JSON-encoded forum-scope LID data (or null)
 *     has_analysis   bool
 *     post_count     int     — number of complete post-scope analyses
 *     pending_count  int     — number of pending/processing posts
 *     error_count    int     — number of error posts
 *     view_url       string  — URL to the Forum LID page for this forum
 *   can_trigger      bool    — user has local/lid:triggeranalysis
 *   trigger_url      string  — AJAX endpoint for manual trigger
 */
class course_lid_page implements \renderable, \templatable {

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $context;

    /**
     * @param \stdClass      $course
     * @param \context_course $context
     */
    public function __construct(\stdClass $course, \context_course $context) {
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
        global $DB, $USER;

        $courseid   = $this->course->id;
        $cantrigger = has_capability('local/lid:triggeranalysis', $this->context);

        // Load all LID-enabled forums in this course.
        $enabledconfigs = $DB->get_records(
            'local_lid_forum_config',
            ['courseid' => $courseid, 'enabled' => 1],
            'forumid ASC'
        );

        if (empty($enabledconfigs)) {
            return [
                'courseid'       => $courseid,
                'coursename'     => format_string($this->course->fullname),
                'haslid'         => false,
                'noforums_notice' => get_string('dashboard_course_noforums', 'local_lid'),
                'aggregate_json' => null,
                'has_aggregate'  => false,
                'stale_notice'   => false,
                'last_updated'   => '',
                'forums'         => [],
                'can_trigger'    => $cantrigger,
                'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
            ];
        }

        // Load the course-scope aggregate.
        $courseaggregate = $DB->get_record('local_lid_analysis', [
            'scope'    => 'course',
            'courseid' => $courseid,
            'status'   => 'complete',
        ]);

        $aggregatejson = $courseaggregate ? $courseaggregate->analysis_json : null;
        $hasaggregate  = !empty($aggregatejson);

        // Load forum details and per-forum analyses.
        $forums    = [];
        $stalenote = false;
        $lastmod   = 0;

        // Get current prompt hash for stale detection.
        $currentprompthash = $this->get_current_prompt_hash($courseid);

        foreach ($enabledconfigs as $config) {
            $forumid = (int) $config->forumid;

            // Get forum name and cmid.
            $forum = $DB->get_record('forum', ['id' => $forumid], 'id, name, course');
            if (!$forum) {
                continue;
            }

            $cm = get_coursemodule_from_instance('forum', $forumid, $courseid);
            if (!$cm) {
                continue;
            }

            // Forum-scope aggregate.
            $forumanalysis = $DB->get_record('local_lid_analysis', [
                'scope'    => 'forum',
                'forumid'  => $forumid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            // Post-scope counts.
            $counts = $this->get_post_counts($forumid, $courseid);

            // Stale detection — any post analysed with a different prompt hash.
            if ($currentprompthash && !$stalenote) {
                $stalecount = $DB->count_records_select(
                    'local_lid_analysis',
                    "scope = 'post' AND forumid = :forumid AND status = 'complete'
                     AND prompt_hash != :hash AND prompt_hash IS NOT NULL",
                    ['forumid' => $forumid, 'hash' => $currentprompthash]
                );
                if ($stalecount > 0) {
                    $stalenote = true;
                }
            }

            // Track latest modification time.
            if ($forumanalysis && $forumanalysis->timemodified > $lastmod) {
                $lastmod = $forumanalysis->timemodified;
            }

            $forums[] = [
                'forumid'       => $forumid,
                'forumname'     => format_string($forum->name),
                'cmid'          => (int) $cm->id,
                'analysis_json' => $forumanalysis ? $forumanalysis->analysis_json : null,
                'has_analysis'  => !empty($forumanalysis),
                'post_count'    => $counts['complete'],
                'pending_count' => $counts['pending'],
                'error_count'   => $counts['error'],
                'view_url'      => (new \moodle_url('/local/lid/forum_view.php', [
                    'cmid'     => $cm->id,
                    'courseid' => $courseid,
                ]))->out(false),
            ];
        }

        return [
            'courseid'       => $courseid,
            'coursename'     => format_string($this->course->fullname),
            'haslid'         => true,
            'noforums_notice' => '',
            'aggregate_json' => $aggregatejson,
            'has_aggregate'  => $hasaggregate,
            'stale_notice'   => $stalenote,
            'last_updated'   => $lastmod ? userdate($lastmod) : '',
            'forums'         => $forums,
            'can_trigger'    => $cantrigger,
            'trigger_url'    => (new \moodle_url('/local/lid/ajax.php'))->out(false),
        ];
    }

    /**
     * Get counts of post-scope analyses by status for a forum.
     *
     * @param  int $forumid
     * @param  int $courseid
     * @return array  Keys: complete, pending, error.
     */
    private function get_post_counts(int $forumid, int $courseid): array {
        global $DB;

        $statuses = ['complete', 'pending', 'processing', 'error'];
        $counts   = ['complete' => 0, 'pending' => 0, 'error' => 0];

        foreach ($statuses as $status) {
            $n = $DB->count_records('local_lid_analysis', [
                'scope'    => 'post',
                'forumid'  => $forumid,
                'courseid' => $courseid,
                'status'   => $status,
            ]);
            if ($status === 'processing') {
                $counts['pending'] += $n; // Surface processing as pending to the UI.
            } else {
                $counts[$status] = $n;
            }
        }

        return $counts;
    }

    /**
     * Get the SHA-256 hash of the currently active prompt for this course,
     * for use in stale-analysis detection.
     *
     * @param  int $courseid
     * @return string|null
     */
    private function get_current_prompt_hash(int $courseid): ?string {
        $builder = new \local_lid\llm\prompt_builder($courseid);
        $template = $builder->get_active_template();
        return $template ? hash('sha256', $template) : null;
    }
}
