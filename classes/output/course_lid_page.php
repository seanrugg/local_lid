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
 * Analysis cards are pre-rendered to HTML strings in export_for_template()
 * using renderer::render_analysis_card(). The Mustache template receives
 * ready-to-use HTML and outputs it unescaped with {{{ }}}. This avoids
 * Moodle's Mustache limitation where partials always receive the full page
 * context rather than a specific named variable.
 *
 * Data structure exported to template:
 *
 *   courseid             int
 *   coursename           string
 *   haslid               bool
 *   noforums_notice      string
 *   aggregate_html       string   -- pre-rendered analysis card HTML (or '')
 *   has_aggregate        bool
 *   stale_notice         bool
 *   last_updated         string
 *   forums               array
 *     forumid            int
 *     forumname          string
 *     cmid               int
 *     analysis_html      string   -- pre-rendered forum-scope card HTML (or '')
 *     has_analysis       bool
 *     post_count         int
 *     pending_count      int
 *     error_count        int
 *     view_url           string
 *   can_trigger          bool
 *   trigger_url          string
 *   use_native_theme     bool     -- true if native Moodle theme mode is enabled
 */
class course_lid_page implements \renderable, \templatable {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \context_course */
    private \context_course $context;

    public function __construct(\stdClass $course, \context_course $context) {
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
        $courseid   = (int) $this->course->id;
        $cantrigger = has_capability('local/lid:triggeranalysis', $this->context);

        // Resolve theme mode for conditional template includes.
        $usenativetheme = (bool) get_config('local_lid', 'lid_use_native_theme');

        $enabledconfigs = $DB->get_records(
            'local_lid_forum_config',
            ['courseid' => $courseid, 'enabled' => 1],
            'forumid ASC'
        );

        if (empty($enabledconfigs)) {
            return [
                'courseid'         => $courseid,
                'coursename'       => format_string($this->course->fullname),
                'haslid'           => false,
                'noforums_notice'  => get_string('dashboard_course_noforums', 'local_lid'),
                'aggregate_html'   => '',
                'has_aggregate'    => false,
                'stale_notice'     => false,
                'last_updated'     => '',
                'forums'           => [],
                'can_trigger'      => $cantrigger,
                'trigger_url'      => (new \moodle_url('/local/lid/ajax.php'))->out(false),
                'use_native_theme' => $usenativetheme,
            ];
        }

        // Course-scope aggregate.
        $courseagg = $DB->get_record('local_lid_analysis', [
            'scope'    => 'course',
            'courseid' => $courseid,
            'status'   => 'complete',
        ]);

        $hasaggregate  = !empty($courseagg);
        $aggregatehtml = $hasaggregate
            ? $output->render_analysis_card($courseagg->analysis_json)
            : '';

        $forums            = [];
        $stalenote         = false;
        $lastmod           = 0;
        $currentprompthash = $this->get_current_prompt_hash($courseid);

        foreach ($enabledconfigs as $config) {
            $forumid = (int) $config->forumid;

            $forum = $DB->get_record('forum', ['id' => $forumid], 'id, name, course');
            if (!$forum) {
                continue;
            }

            $cm = get_coursemodule_from_instance('forum', $forumid, $courseid);
            if (!$cm) {
                continue;
            }

            $forumanalysis = $DB->get_record('local_lid_analysis', [
                'scope'    => 'forum',
                'forumid'  => $forumid,
                'courseid' => $courseid,
                'status'   => 'complete',
            ]);

            $counts = $this->get_post_counts($forumid, $courseid);

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

            if ($forumanalysis && $forumanalysis->timemodified > $lastmod) {
                $lastmod = (int) $forumanalysis->timemodified;
            }

            // Pre-render the per-forum dashboard card.
            $analysishtml = $forumanalysis
                ? $output->render_analysis_card($forumanalysis->analysis_json)
                : '';

            $forums[] = [
                'forumid'       => $forumid,
                'forumname'     => format_string($forum->name),
                'cmid'          => (int) $cm->id,
                'analysis_html' => $analysishtml,
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
            'courseid'         => $courseid,
            'coursename'       => format_string($this->course->fullname),
            'haslid'           => true,
            'noforums_notice'  => '',
            'aggregate_html'   => $aggregatehtml,
            'has_aggregate'    => $hasaggregate,
            'stale_notice'     => $stalenote,
            'last_updated'     => $lastmod ? userdate($lastmod) : '',
            'forums'           => $forums,
            'can_trigger'      => $cantrigger,
            'trigger_url'      => (new \moodle_url('/local/lid/ajax.php'))->out(false),
            'use_native_theme' => $usenativetheme,
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
            $counts[$status === 'processing' ? 'pending' : $status] += $n;
        }

        return $counts;
    }

    /**
     * SHA-256 hash of the active prompt for stale detection.
     *
     * @param  int $courseid
     * @return string|null
     */
    private function get_current_prompt_hash(int $courseid): ?string {
        $builder  = new \local_lid\llm\prompt_builder($courseid);
        $template = $builder->get_active_template();
        return $template ? hash('sha256', $template) : null;
    }
}
