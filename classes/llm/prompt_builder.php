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
 * Prompt builder for local_lid.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\llm;

defined('MOODLE_INTERNAL') || die();

/**
 * Assembles the full LLM prompt for a given analysis context.
 *
 * Prompt resolution order for legacy post-scope analyses (most specific wins):
 *   1. Forum-level prompt_override in local_lid_forum_config
 *      (only if prompt_locked = 0 at site level)
 *   2. Course-level prompt_template in local_lid_settings (courseid > 0)
 *      (only if prompt_locked = 0 at site level)
 *   3. Site-level prompt_template in local_lid_settings (courseid = 0)
 *
 * For student_forum and thread scope analyses, prompts/forum-discussion-analyzer.md
 * is used directly and bypasses the resolution chain. This is the fixed assessment
 * instrument for closed discussions and must not be overridden at forum or course level.
 *
 * The discussion_model field from local_lid_forum_config is injected into the context
 * header so the LLM applies the correct Critical Discourse variant (Rubric 2).
 *
 * Exact word_count and character_count are computed in PHP from the assembled post
 * content and injected into the context header - the LLM reads them directly rather
 * than estimating them.
 *
 * For student_forum analyses, the prompt includes the full conversational context
 * from all threads the assessed learner participated in. Peer learners are
 * pseudonymised as Peer A, Peer B, etc. Non-student participants (instructors,
 * managers, facilitators) are labelled [INSTRUCTOR]. The assessed learner is
 * labelled [ASSESSED LEARNER]. Only the assessed learner's posts are scored;
 * all other posts provide conversational context for accurate evaluation of
 * peer-dependent rubrics (Critical Discourse, Peer Advancement, etc.).
 *
 * Course competency integration (v0.6.0):
 *
 *   When competencies_enabled = 1 at the course level and the Moodle competency
 *   subsystem is active, course competencies are resolved at prompt assembly time
 *   and injected as a structured block between the context header and the analyzer
 *   prompt. Forum-level competency_ids provides three-state filtering:
 *     null      = inherit course setting (all course competencies)
 *     '[]'      = explicitly exclude all competencies for this forum
 *     '[3,7]'   = evaluate only these specific competency IDs
 *
 *   Competencies are queried via core_competency\api::list_course_competencies()
 *   which returns an array of {competency, coursecompetency} pairs. Each competency
 *   has shortname, description, and idnumber.
 *
 * Final structure - student_forum / thread analyses:
 *
 *   [CONTEXT HEADER]          <- includes discussion_model, word_count, character_count
 *   ---
 *   [COMPETENCY BLOCK]        <- optional; only when competencies are resolved
 *   ---
 *   [FORUM DISCUSSION ANALYZER PROMPT]
 *   ---
 *   [ASSEMBLED POST CONTENT]  <- posts annotated with per-post word counts
 *
 * Final structure - legacy post-scope analyses:
 *
 *   [PREAMBLE]
 *   ---
 *   [ACTIVE PROMPT TEMPLATE]
 *   ---
 *   [POST CONTENT BLOCK]
 *
 * Valid discussion_model values (stored in local_lid_forum_config.discussion_model):
 *   independent_first  - learners post original response before seeing peers
 *   open_engagement    - learners can read all posts before contributing (default)
 *   structured_debate  - learners argue assigned or chosen positions
 *
 * Usage:
 *   $builder = new \local_lid\llm\prompt_builder($courseid, $forumid);
 *
 *   // Learner forum assessment (primary path):
 *   $prompt = $builder->build_for_student_forum($userid, $forumname);
 *
 *   // Thread assessment (instructor view):
 *   $prompt = $builder->build_for_thread_by_id($discussionid, $subject);
 *
 *   // Legacy single post:
 *   $prompt = $builder->build_for_post($postrecord);
 *
 *   $hash      = $builder->get_prompt_hash();
 *   $forumhash = $builder->get_forum_analyzer_hash();
 */
class prompt_builder {

    /** Minimum word count for a post to be considered substantive. */
    const SUBSTANTIVE_WORD_THRESHOLD = 40;

    /** Valid discussion model values. */
    const MODEL_INDEPENDENT_FIRST = 'independent_first';
    const MODEL_OPEN_ENGAGEMENT   = 'open_engagement';
    const MODEL_STRUCTURED_DEBATE = 'structured_debate';

    /**
     * Human-readable descriptions for each discussion model.
     * Displayed in the forum LID config UI so the instructor understands
     * which prompt variant will be applied to their forum.
     */
    const MODEL_DESCRIPTIONS = [
        self::MODEL_INDEPENDENT_FIRST =>
            'Independent First - Learners post their own original response before seeing peers. ' .
            'Assessment weights the original contribution heavily. Peer replies are engagement ' .
            'evidence but secondary to independent reasoning.',
        self::MODEL_OPEN_ENGAGEMENT   =>
            'Open Engagement - Learners can read all posts before contributing. Peer-directed ' .
            'critical discourse, synthesis, and constructive challenge are the primary expected behaviours.',
        self::MODEL_STRUCTURED_DEBATE =>
            'Structured Debate - Learners argue assigned or chosen positions. Assessment focuses ' .
            'on advocacy, counterargument, evidence quality, and position defence.',
    ];

    /** @var int Course id for prompt resolution. */
    private int $courseid;

    /** @var int|null Forum id for prompt resolution. */
    private ?int $forumid;

    /** @var string The resolved active prompt template (before preamble injection). */
    private string $activetemplate = '';

    /** @var string SHA-256 hash of the active template. */
    private string $prompthash = '';

    /** @var string The forum-post preamble loaded from file. */
    private string $preamble = '';

    /** @var string The forum discussion analyzer prompt loaded from file. */
    private string $forumanalyzer = '';

    /** @var string The discussion model resolved from local_lid_forum_config. */
    private string $discussionmodel = self::MODEL_OPEN_ENGAGEMENT;

    /** @var string The resolved competency block for prompt injection (empty if none). */
    private string $competencyblock = '';

    /**
     * Constructor - resolves and caches the active prompt template, discussion model,
     * and competency block.
     *
     * @param int      $courseid Course id.
     * @param int|null $forumid  Forum id (null for course-level analyses).
     */
    public function __construct(int $courseid, ?int $forumid = null) {
        $this->courseid        = $courseid;
        $this->forumid         = $forumid;
        $this->preamble        = $this->load_preamble();
        $this->forumanalyzer   = $this->load_forum_analyzer();
        $this->activetemplate  = $this->resolve_template();
        $this->prompthash      = hash('sha256', $this->activetemplate);
        $this->discussionmodel = $this->resolve_discussion_model();
        $this->competencyblock = $this->resolve_competency_block();
    }

    // -------------------------------------------------------------------------
    // Public - build methods
    // -------------------------------------------------------------------------

    /**
     * Build the complete prompt for a learner's full participation in a forum.
     *
     * Fetches all posts from all threads the assessed learner participated in,
     * providing full conversational context. The assessed learner is labelled
     * [ASSESSED LEARNER], peer students are pseudonymised as Peer A, Peer B,
     * etc., and non-student participants (instructors, managers) are labelled
     * [INSTRUCTOR]. Only the assessed learner's posts are scored; all other
     * posts provide context for accurate peer-dependent rubric evaluation.
     *
     * Post composition stats (word_count, character_count) in the context
     * header reflect only the assessed learner's posts.
     *
     * Uses the forum discussion analyzer prompt - not the session analyzer
     * or any course/forum-level prompt override.
     *
     * @param  int    $userid    Moodle user id of the learner.
     * @param  string $forumname Human-readable forum name for the context header.
     * @return string            Complete prompt ready to send to the LLM.
     *                           Empty string if the user has no posts in this forum.
     */
    public function build_for_student_forum(int $userid, string $forumname): string {
        global $DB;

        if (!$this->forumid) {
            return '';
        }

        // Step 1: Find all discussion IDs the assessed learner participated in.
        $sql = "SELECT DISTINCT fp.discussion
                  FROM {forum_posts} fp
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE fd.forum = :forumid
                   AND fp.userid = :userid";

        $discussionids = $DB->get_fieldset_sql($sql, [
            'forumid' => $this->forumid,
            'userid'  => $userid,
        ]);

        if (empty($discussionids)) {
            return '';
        }

        // Step 2: Fetch ALL posts from those discussions (full thread context).
        list($insql, $params) = $DB->get_in_or_equal($discussionids, SQL_PARAMS_NAMED);
        $sql = "SELECT fp.id, fp.discussion, fp.userid, fp.message,
                       fp.subject, fp.created,
                       fd.name AS discussion_name
                  FROM {forum_posts} fp
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE fp.discussion {$insql}
              ORDER BY fp.discussion ASC, fp.created ASC";

        $allposts = $DB->get_records_sql($sql, $params);

        if (empty($allposts)) {
            return '';
        }

        $allpostsarr = array_values($allposts);

        // Step 3: Separate the assessed learner's posts for stats computation.
        $learnerposts = array_values(array_filter($allpostsarr, function($post) use ($userid) {
            return (int) $post->userid === $userid;
        }));

        if (empty($learnerposts)) {
            return '';
        }

        // Step 4: Resolve roles for all unique authors in the threads.
        $authorids = array_unique(array_map(function($post) {
            return (int) $post->userid;
        }, $allpostsarr));
        $rolemap = $this->resolve_author_roles($authorids, $userid);

        // Step 5: Compute stats on the assessed learner's posts only.
        $stats = $this->compute_post_stats($learnerposts);

        // Step 6: Count context participants.
        $peercount      = 0;
        $instructorcount = 0;
        foreach ($rolemap as $uid => $role) {
            if ($uid === $userid) {
                continue;
            }
            if ($role === 'instructor') {
                $instructorcount++;
            } else {
                $peercount++;
            }
        }

        // Step 7: Build header, format content, assemble.
        $header  = $this->build_student_forum_header(
            $forumname, $stats, $peercount, $instructorcount
        );
        $content = $this->format_student_forum_content_with_context(
            $allpostsarr, $forumname, $userid, $rolemap
        );

        return $this->assemble_with_context($header, $this->forumanalyzer, $content);
    }

    /**
     * Build the complete prompt for all posts in a single discussion thread.
     *
     * @deprecated Since v0.8.0. Thread scope analysis has been deprecated in favor
     *             of student_forum scope. This method is retained for one release
     *             cycle for backward compatibility but is no longer called by the
     *             queue processor. Will be removed in v0.9.0.
     *
     * Fetches all posts in the given discussion, pseudonymises authors as
     * Participant A, B, etc., annotates word counts, and assembles a context
     * header including the discussion_model, exact word_count, and character_count.
     *
     * @param  int    $discussionid  forum_discussions.id to analyse.
     * @param  string $subject       Discussion subject line.
     * @return string                Complete prompt. Empty if no posts exist.
     */
    public function build_for_thread_by_id(int $discussionid, string $subject): string {
        debugging(
            'build_for_thread_by_id() is deprecated and will be removed in v0.9.0. ' .
            'Use build_for_student_forum() instead.',
            DEBUG_DEVELOPER
        );

        global $DB;

        $sql = "SELECT fp.id, fp.discussion, fp.userid, fp.message,
                       fp.subject, fp.created
                  FROM {forum_posts} fp
                 WHERE fp.discussion = :discussionid
              ORDER BY fp.created ASC";

        $posts = $DB->get_records_sql($sql, ['discussionid' => $discussionid]);

        if (empty($posts)) {
            return '';
        }

        $postsarr          = array_values($posts);
        $stats             = $this->compute_post_stats($postsarr);
        $participant_count = count(array_unique(array_column($postsarr, 'userid')));
        $header            = $this->build_thread_header($subject, $stats, $participant_count);
        $content           = $this->format_thread_content_annotated($postsarr, $subject);

        return $this->assemble_with_context($header, $this->forumanalyzer, $content);
    }

    /**
     * Build the complete prompt for a single forum post (legacy).
     *
     * Kept for compatibility. New pipelines should use build_for_student_forum()
     * or build_for_thread_by_id().
     *
     * @param  \stdClass $post  forum_posts record (must have id, message, subject).
     * @return string           Complete prompt string.
     */
    public function build_for_post(\stdClass $post): string {
        $content = $this->format_post_content($post);
        return $this->assemble($content);
    }

    /**
     * Build the complete prompt for an array of posts (legacy thread variant).
     *
     * Kept for compatibility. Prefer build_for_thread_by_id() for new code.
     *
     * @param  \stdClass[] $posts   Posts ordered by created ASC.
     * @param  string      $subject Discussion subject line.
     * @return string               Complete prompt string.
     */
    public function build_for_thread(array $posts, string $subject): string {
        $content = $this->format_thread_content($posts, $subject);
        return $this->assemble($content);
    }

    /**
     * Return the SHA-256 hash of the active session analyzer prompt template.
     *
     * @return string 64-character hex string.
     */
    public function get_prompt_hash(): string {
        return $this->prompthash;
    }

    /**
     * Return the SHA-256 hash of the forum discussion analyzer prompt.
     *
     * Stored as prompt_hash for student_forum and thread analyses since those
     * bypass the normal prompt resolution chain.
     *
     * @return string 64-character hex string.
     */
    public function get_forum_analyzer_hash(): string {
        return hash('sha256', $this->forumanalyzer);
    }

    /**
     * Return the active session analyzer template text.
     * Used by the prompt editor to show what template is in effect.
     *
     * @return string
     */
    public function get_active_template(): string {
        return $this->activetemplate;
    }

    /**
     * Return the forum discussion analyzer prompt text.
     * Used by the forum LID config UI to show the active assessment instrument.
     *
     * @return string
     */
    public function get_forum_analyzer_template(): string {
        return $this->forumanalyzer;
    }

    /**
     * Return the resolved discussion model for this forum.
     *
     * @return string One of the MODEL_* constants.
     */
    public function get_discussion_model(): string {
        return $this->discussionmodel;
    }

    /**
     * Return human-readable descriptions for all discussion models.
     * Used to populate the forum LID config UI selector with help text.
     *
     * @return array Keyed by model constant value.
     */
    public static function get_model_descriptions(): array {
        return self::MODEL_DESCRIPTIONS;
    }

    /**
     * Compute post statistics for a set of posts and return them.
     *
     * Exposed as public so process_queue and other callers can access stats
     * without re-fetching posts (e.g. for logging or meta.notes generation).
     *
     * @param  \stdClass[] $posts Array of post records (must have message, discussion).
     * @return array              See compute_post_stats() return doc.
     */
    public function get_post_stats(array $posts): array {
        return $this->compute_post_stats($posts);
    }

    /**
     * Return the resolved competency block text.
     *
     * Exposed for testing and logging. Empty string if no competencies apply.
     *
     * @return string
     */
    public function get_competency_block(): string {
        return $this->competencyblock;
    }

    // -------------------------------------------------------------------------
    // Private - prompt resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the active prompt template using the priority chain.
     *
     * @return string The resolved template text.
     */
    private function resolve_template(): string {
        global $DB;

        $sitelocked = (bool) $this->get_site_setting('prompt_locked');

        if (!$sitelocked && $this->forumid) {
            $forumoverride = $DB->get_field(
                'local_lid_forum_config',
                'prompt_override',
                ['forumid' => $this->forumid]
            );
            if (!empty($forumoverride)) {
                return trim($forumoverride);
            }
        }

        if (!$sitelocked) {
            $coursesettings = $DB->get_record(
                'local_lid_settings',
                ['courseid' => $this->courseid],
                'prompt_template'
            );
            if ($coursesettings && !empty($coursesettings->prompt_template)) {
                return trim($coursesettings->prompt_template);
            }
        }

        $sitetemplate = $this->get_site_setting('prompt_template');
        if (!empty($sitetemplate)) {
            return trim($sitetemplate);
        }

        return $this->load_default_template_from_file();
    }

    /**
     * Resolve the discussion model for this forum from local_lid_forum_config.
     *
     * Falls back to open_engagement if no config row exists or if the stored
     * value is not one of the three recognised model constants.
     *
     * @return string One of the MODEL_* constants.
     */
    private function resolve_discussion_model(): string {
        global $DB;

        if (!$this->forumid) {
            return self::MODEL_OPEN_ENGAGEMENT;
        }

        $model = $DB->get_field(
            'local_lid_forum_config',
            'discussion_model',
            ['forumid' => $this->forumid]
        );

        $valid = [
            self::MODEL_INDEPENDENT_FIRST,
            self::MODEL_OPEN_ENGAGEMENT,
            self::MODEL_STRUCTURED_DEBATE,
        ];

        return in_array($model, $valid, true) ? $model : self::MODEL_OPEN_ENGAGEMENT;
    }

    // -------------------------------------------------------------------------
    // Private - competency resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the competency block to inject into the prompt.
     *
     * Resolution logic:
     *   1. Check if Moodle's competency subsystem is enabled site-wide.
     *      If not -> return '' (no block).
     *   2. Check if competencies_enabled = 1 at the course level.
     *      If not -> return '' (no block).
     *   3. If a forum is set, check competency_ids on local_lid_forum_config:
     *      - null  -> use all course competencies (inherit).
     *      - '[]'  -> exclude all competencies for this forum -> return ''.
     *      - '[3,7]' -> filter to only these competency IDs.
     *   4. Query core_competency\api::list_course_competencies() for the course.
     *   5. Apply forum-level filter if specific IDs were set.
     *   6. Format the surviving competencies into a prompt block.
     *
     * @return string Formatted competency block, or '' if none apply.
     */
    private function resolve_competency_block(): string {
        // Step 1: Is the Moodle competency subsystem enabled?
        if (!$this->is_competency_subsystem_enabled()) {
            return '';
        }

        // Step 2: Is competency evaluation enabled for this course?
        if (!$this->is_course_competencies_enabled()) {
            return '';
        }

        // Step 3: Resolve forum-level competency_ids filter.
        $forumfilter = $this->resolve_forum_competency_filter();
        // $forumfilter is one of:
        //   null   -> inherit (use all)
        //   []     -> exclude all
        //   [3,7]  -> specific IDs only
        if (is_array($forumfilter) && empty($forumfilter)) {
            // Explicitly excluded - empty array means "no competencies for this forum".
            return '';
        }

        // Step 4: Query course competencies from Moodle's competency API.
        $competencies = $this->fetch_course_competencies();
        if (empty($competencies)) {
            return '';
        }

        // Step 5: Apply forum-level filter if specific IDs were set.
        if (is_array($forumfilter) && !empty($forumfilter)) {
            $allowedids = array_flip($forumfilter);
            $competencies = array_filter($competencies, function($comp) use ($allowedids) {
                return isset($allowedids[$comp->get('id')]);
            });
            if (empty($competencies)) {
                return '';
            }
        }

        // Step 6: Format into a prompt block.
        return $this->format_competency_block($competencies);
    }

    /**
     * Check if the Moodle competency subsystem is enabled at site level.
     *
     * @return bool
     */
    private function is_competency_subsystem_enabled(): bool {
        try {
            return \core_competency\api::is_enabled();
        } catch (\Throwable $e) {
            // Competency classes may not exist on very old Moodle builds.
            return false;
        }
    }

    /**
     * Check if competency evaluation is enabled for this course.
     *
     * Checks the course-level local_lid_settings row first. If no row exists,
     * falls back to the site-level default in config_plugins.
     *
     * @return bool
     */
    private function is_course_competencies_enabled(): bool {
        global $DB;

        // Check course-level settings row.
        $coursesettings = $DB->get_record(
            'local_lid_settings',
            ['courseid' => $this->courseid],
            'competencies_enabled'
        );

        if ($coursesettings) {
            return (bool) $coursesettings->competencies_enabled;
        }

        // No course-level row - fall back to site default.
        return (bool) get_config('local_lid', 'competencies_enabled_default');
    }

    /**
     * Resolve the forum-level competency_ids filter.
     *
     * @return array|null  null = inherit (all), [] = exclude all,
     *                     [int, int, ...] = specific competency IDs.
     */
    private function resolve_forum_competency_filter(): ?array {
        global $DB;

        if (!$this->forumid) {
            return null; // No forum context -> inherit all.
        }

        $raw = $DB->get_field(
            'local_lid_forum_config',
            'competency_ids',
            ['forumid' => $this->forumid]
        );

        // null or false (no row / null column) -> inherit.
        if ($raw === null || $raw === false) {
            return null;
        }

        // Decode JSON.
        $decoded = json_decode($raw, true);

        // Invalid JSON or non-array -> treat as inherit.
        if (!is_array($decoded)) {
            return null;
        }

        // Empty array -> exclude all. Populated array -> specific IDs.
        return array_map('intval', $decoded);
    }

    /**
     * Fetch course competencies from Moodle's competency API.
     *
     * Returns an array of \core_competency\competency persistent objects.
     * Returns empty array if no competencies are linked to the course or
     * if the API call fails.
     *
     * @return \core_competency\competency[]
     */
    private function fetch_course_competencies(): array {
        try {
            // list_course_competencies returns array of
            // {competency: competency, coursecompetency: course_competency} pairs.
            $pairs = \core_competency\api::list_course_competencies($this->courseid);
        } catch (\Throwable $e) {
            // API call failed - course may not exist, no framework linked, etc.
            return [];
        }

        if (empty($pairs)) {
            return [];
        }

        // Extract the competency persistent from each pair.
        $competencies = [];
        foreach ($pairs as $pair) {
            if (isset($pair['competency']) && $pair['competency'] instanceof \core_competency\competency) {
                $competencies[] = $pair['competency'];
            }
        }

        return $competencies;
    }

    /**
     * Format an array of competency persistents into a prompt injection block.
     *
     * Uses the lang string competency_prompt_header as the block title.
     * Each competency is numbered and includes its shortname and description.
     * The idnumber is included if present (useful for cross-referencing).
     *
     * @param  \core_competency\competency[] $competencies
     * @return string Formatted block ready for prompt injection.
     */
    private function format_competency_block(array $competencies): string {
        $header = get_string('competency_prompt_header', 'local_lid');

        $lines = [];
        $lines[] = $header;

        $num = 1;
        foreach ($competencies as $comp) {
            $shortname   = trim($comp->get('shortname'));
            $description = trim(strip_tags($comp->get('description')));
            $idnumber    = trim($comp->get('idnumber'));

            $entry = '  ' . $num . '. ' . $shortname;

            if (!empty($idnumber)) {
                $entry .= ' [' . $idnumber . ']';
            }

            if (!empty($description)) {
                $entry .= ' - ' . $description;
            }

            $lines[] = $entry;
            $num++;
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private - file loaders
    // -------------------------------------------------------------------------

    /**
     * Load the forum-post preamble from prompts/forum-post-preamble.md.
     *
     * @return string Preamble text, or empty string if the file is missing.
     */
    private function load_preamble(): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/lid/prompts/forum-post-preamble.md';
        return file_exists($path) ? trim(file_get_contents($path)) : '';
    }

    /**
     * Load the forum discussion analyzer from prompts/forum-discussion-analyzer.md.
     *
     * Fixed assessment instrument for student_forum and thread analyses.
     * Not user-editable; bypasses the prompt resolution chain entirely.
     *
     * @return string Analyzer text, or empty string if the file is missing.
     */
    private function load_forum_analyzer(): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/lid/prompts/forum-discussion-analyzer.md';
        return file_exists($path) ? trim(file_get_contents($path)) : '';
    }

    /**
     * Load the default session analyzer template from the shipped .md file.
     *
     * @return string Template text, or empty string if the file is missing.
     */
    private function load_default_template_from_file(): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/lid/prompts/default-session-analyzer.md';
        return file_exists($path) ? trim(file_get_contents($path)) : '';
    }

    /**
     * Helper - get a value from the site-level local_lid_settings row.
     *
     * @param  string $field Column name.
     * @return mixed         Field value, or null if the row does not exist.
     */
    private function get_site_setting(string $field) {
        global $DB;
        return $DB->get_field('local_lid_settings', $field, ['courseid' => 0]);
    }

    // -------------------------------------------------------------------------
    // Private - role resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the role label for each author in a set of posts.
     *
     * Returns a map of userid => role label string:
     *   - The assessed learner's userid maps to 'assessed'
     *   - Users with a student-archetype role in the course map to 'peer'
     *   - All other users (instructors, managers, etc.) map to 'instructor'
     *
     * Uses get_archetype_roles('student') for portable role ID resolution.
     *
     * @param  int[] $authorids      All unique author user IDs from the posts.
     * @param  int   $assesseduserid The user ID of the learner being assessed.
     * @return array                 Map of userid (int) => 'assessed'|'peer'|'instructor'.
     */
    private function resolve_author_roles(array $authorids, int $assesseduserid): array {
        global $DB;

        $rolemap = [];

        // The assessed learner is always labelled 'assessed'.
        $rolemap[$assesseduserid] = 'assessed';

        // Get student archetype role IDs.
        $studentroles = get_archetype_roles('student');
        $studentroleids = [];
        if (!empty($studentroles)) {
            $studentroleids = array_map(function($role) {
                return (int) $role->id;
            }, $studentroles);
        }

        // Get the course context for role assignment lookups.
        $context = \context_course::instance($this->courseid);

        // Resolve each author's role (skip the assessed learner, already set).
        $otheruserids = array_filter($authorids, function($uid) use ($assesseduserid) {
            return (int) $uid !== $assesseduserid;
        });

        if (empty($otheruserids) || empty($studentroleids)) {
            // If no student roles are defined, treat all others as instructors.
            foreach ($otheruserids as $uid) {
                $rolemap[(int) $uid] = empty($studentroleids) ? 'instructor' : 'peer';
            }
            return $rolemap;
        }

        // Batch-check which of the other authors hold a student-archetype role.
        list($useridinsql, $useridparams) = $DB->get_in_or_equal(
            array_map('intval', $otheruserids), SQL_PARAMS_NAMED, 'uid'
        );
        list($roleinsql, $roleparams) = $DB->get_in_or_equal(
            $studentroleids, SQL_PARAMS_NAMED, 'rid'
        );

        $params = array_merge($useridparams, $roleparams, [
            'contextid' => $context->id,
        ]);

        $sql = "SELECT DISTINCT userid
                  FROM {role_assignments}
                 WHERE userid {$useridinsql}
                   AND roleid {$roleinsql}
                   AND contextid = :contextid";

        $studentuserids = $DB->get_fieldset_sql($sql, $params);
        $studentset = array_flip(array_map('intval', $studentuserids));

        foreach ($otheruserids as $uid) {
            $uid = (int) $uid;
            $rolemap[$uid] = isset($studentset[$uid]) ? 'peer' : 'instructor';
        }

        return $rolemap;
    }

    // -------------------------------------------------------------------------
    // Private - context header builders
    // -------------------------------------------------------------------------

    /**
     * Build the context header for a student_forum analysis.
     *
     * Includes exact word_count and character_count (assessed learner's posts
     * only) so the LLM reads them directly rather than estimating. Includes
     * discussion_model so the LLM applies the correct Critical Discourse
     * variant. Includes explicit instructions that only [ASSESSED LEARNER]
     * posts should be scored.
     *
     * @param  string $forumname        Human-readable forum name.
     * @param  array  $stats            Output of compute_post_stats() on learner's posts only.
     * @param  int    $peercount        Number of distinct peer learners in the threads.
     * @param  int    $instructorcount  Number of distinct instructors/facilitators in the threads.
     * @return string
     */
    private function build_student_forum_header(
        string $forumname,
        array $stats,
        int $peercount,
        int $instructorcount
    ): string {
        $modeldesc = self::MODEL_DESCRIPTIONS[$this->discussionmodel]
            ?? self::MODEL_DESCRIPTIONS[self::MODEL_OPEN_ENGAGEMENT];

        $lines = [
            '## ANALYSIS CONTEXT',
            '',
            'Scope:              Student Forum Assessment (student_forum)',
            'Forum:              ' . $forumname,
            'discussion_model:   ' . $this->discussionmodel,
            'Model description:  ' . $modeldesc,
            '',
            'Assessed learner\'s post composition:',
            '  Total posts:      ' . $stats['total_posts'],
            '  Threads:          ' . $stats['thread_count'] . ' discussion thread(s) contributed to',
            '  Substantive (>='   . self::SUBSTANTIVE_WORD_THRESHOLD . ' words): '
                . $stats['substantive_count']
                . ($stats['substantive_count'] > 0
                    ? ' (avg ' . $stats['substantive_avg_words'] . ' words)' : ''),
            '  Short (<'         . self::SUBSTANTIVE_WORD_THRESHOLD . ' words):        '
                . $stats['short_count']
                . ($stats['short_count'] > 0
                    ? ' (avg ' . $stats['short_avg_words'] . ' words)' : ''),
            '',
            'Exact totals (PHP-calculated - use these values directly):',
            '  word_count:       ' . $stats['total_words'],
            '  character_count:  ' . $stats['total_characters'],
            '',
            'Conversational context:',
            '  Peer learners:    ' . $peercount,
            '  Instructors:      ' . $instructorcount,
            '',
            'IMPORTANT - Scoring scope:',
            'The content below includes the FULL conversational context from all threads',
            'the assessed learner participated in. Posts are labelled by author role:',
            '  [ASSESSED LEARNER] - the learner being evaluated. Score ONLY these posts.',
            '  Peer A, Peer B, etc. - other student participants. Context only; do NOT score.',
            '  [INSTRUCTOR] - instructor/facilitator posts. Context only; do NOT score.',
            '',
            'Peer and instructor posts are provided so you can accurately evaluate how the',
            'assessed learner engaged with others\' ideas, responded to challenges, built on',
            'peer contributions, and participated in the broader discourse. Without this',
            'context, peer-dependent rubrics cannot be scored accurately.',
            '',
            'This is a retrospective assessment of the learner\'s complete participation'
                . ' in a closed forum discussion. Do not infer activity beyond what is present below.',
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * Build the context header for a thread analysis.
     *
     * @param  string $subject           Discussion subject line.
     * @param  array  $stats             Output of compute_post_stats().
     * @param  int    $participant_count Number of distinct authors.
     * @return string
     */
    private function build_thread_header(string $subject, array $stats, int $participant_count): string {
        $modeldesc = self::MODEL_DESCRIPTIONS[$this->discussionmodel]
            ?? self::MODEL_DESCRIPTIONS[self::MODEL_OPEN_ENGAGEMENT];

        $lines = [
            '## ANALYSIS CONTEXT',
            '',
            'Scope:              Discussion Thread Assessment (thread)',
            'Thread:             ' . $subject,
            'discussion_model:   ' . $this->discussionmodel,
            'Model description:  ' . $modeldesc,
            'Participants:       ' . $participant_count . ' learner(s)',
            '',
            'Post composition:',
            '  Total posts:      ' . $stats['total_posts'],
            '  Substantive (>='   . self::SUBSTANTIVE_WORD_THRESHOLD . ' words): '
                . $stats['substantive_count']
                . ($stats['substantive_count'] > 0
                    ? ' (avg ' . $stats['substantive_avg_words'] . ' words)' : ''),
            '  Short (<'         . self::SUBSTANTIVE_WORD_THRESHOLD . ' words):        '
                . $stats['short_count']
                . ($stats['short_count'] > 0
                    ? ' (avg ' . $stats['short_avg_words'] . ' words)' : ''),
            '',
            'Exact totals (PHP-calculated - use these values directly):',
            '  word_count:       ' . $stats['total_words'],
            '  character_count:  ' . $stats['total_characters'],
            '',
            'Authors are pseudonymised as Participant A, Participant B, etc.'
                . ' Retrospective assessment of the complete thread after discussion closed.',
            '',
        ];

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private - content formatting
    // -------------------------------------------------------------------------

    /**
     * Format the full conversational context for a student_forum analysis.
     *
     * Includes all posts from all threads the assessed learner participated in.
     * Posts are grouped by discussion thread, presented chronologically within
     * each thread. Each post is annotated with:
     *   - Author role label: [ASSESSED LEARNER], Peer A/B/C, or [INSTRUCTOR]
     *   - Word count and substantive/short classification
     *
     * @param  \stdClass[] $allposts   All posts from participated threads, ordered
     *                                 by discussion ASC, created ASC.
     * @param  string      $forumname  Forum name (used in section header).
     * @param  int         $userid     The assessed learner's user ID.
     * @param  array       $rolemap    Map of userid => 'assessed'|'peer'|'instructor'
     *                                 from resolve_author_roles().
     * @return string
     */
    private function format_student_forum_content_with_context(
        array $allposts,
        string $forumname,
        int $userid,
        array $rolemap
    ): string {
        // Group posts by discussion.
        $threads = [];
        foreach ($allposts as $post) {
            $did = $post->discussion;
            if (!isset($threads[$did])) {
                $threads[$did] = [
                    'name'  => $post->discussion_name ?? ('Thread ' . $did),
                    'posts' => [],
                ];
            }
            $threads[$did]['posts'][] = $post;
        }

        // Build pseudonym map for peers (Peer A, Peer B, etc.).
        $peermap = [];
        $peerletter = 'A';

        $lines     = [];
        $lines[]   = '## LEARNER FORUM PARTICIPATION';
        $lines[]   = 'Forum: ' . $forumname;
        $lines[]   = '';
        $threadnum = 1;

        foreach ($threads as $thread) {
            $lines[] = '### Thread ' . $threadnum . ': ' . $thread['name'];
            $lines[] = '';
            $threadnum++;

            foreach ($thread['posts'] as $post) {
                $uid  = (int) ($post->userid ?? 0);
                $role = $rolemap[$uid] ?? 'peer';

                // Determine author label.
                if ($role === 'assessed') {
                    $author = '[ASSESSED LEARNER]';
                } else if ($role === 'instructor') {
                    $author = '[INSTRUCTOR]';
                } else {
                    // Peer - assign a consistent pseudonym.
                    if (!isset($peermap[$uid])) {
                        $peermap[$uid] = 'Peer ' . $peerletter;
                        $peerletter++;
                    }
                    $author = $peermap[$uid];
                }

                $body      = $this->clean_post_body($post->message ?? '');
                $wordcount = $this->count_words($body);
                $date      = userdate($post->created,
                    get_string('strftimedatetimeshort', 'langconfig'));
                $label     = $wordcount >= self::SUBSTANTIVE_WORD_THRESHOLD
                    ? '[' . $wordcount . ' words - substantive]'
                    : '[' . $wordcount . ' words - short]';

                $lines[] = '--- ' . $author . ' - ' . $date . ' - ' . $label . ' ---';
                $lines[] = $body;
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format all posts in a thread with pseudonymisation and word-count annotation.
     *
     * Used by build_for_thread_by_id(). Authors mapped to Participant A, B, etc.
     *
     * @param  \stdClass[] $posts   All posts in the thread, ordered by created ASC.
     * @param  string      $subject Discussion subject.
     * @return string
     */
    private function format_thread_content_annotated(array $posts, string $subject): string {
        $authormap = [];
        $letter    = 'A';

        $lines   = [];
        $lines[] = '## DISCUSSION THREAD';
        $lines[] = 'Topic: ' . $subject;
        $lines[] = '';

        foreach ($posts as $post) {
            $uid = $post->userid ?? 0;
            if (!isset($authormap[$uid])) {
                $authormap[$uid] = 'Participant ' . $letter;
                $letter++;
            }
            $author    = $authormap[$uid];
            $date      = userdate($post->created,
                get_string('strftimedatetimeshort', 'langconfig'));
            $body      = $this->clean_post_body($post->message ?? '');
            $wordcount = $this->count_words($body);
            $label     = $wordcount >= self::SUBSTANTIVE_WORD_THRESHOLD
                ? '[' . $wordcount . ' words - substantive]'
                : '[' . $wordcount . ' words - short]';

            $lines[] = '--- ' . $author . ' - ' . $date . ' - ' . $label . ' ---';
            $lines[] = $body;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format a single post into a content block (legacy).
     *
     * @param  \stdClass $post forum_posts record.
     * @return string
     */
    private function format_post_content(\stdClass $post): string {
        global $DB;

        $subject = $post->subject ?? '';
        if (empty($subject) && !empty($post->discussion)) {
            $subject = $DB->get_field('forum_discussions', 'name', ['id' => $post->discussion]);
        }

        $body  = $this->clean_post_body($post->message ?? '');
        $lines = [];

        if (!empty($subject)) {
            $lines[] = 'Discussion topic: ' . trim($subject);
            $lines[] = '';
        }
        $lines[] = 'Post content:';
        $lines[] = $body;

        return implode("\n", $lines);
    }

    /**
     * Format an array of posts (legacy thread variant, no word-count annotation).
     *
     * @param  \stdClass[] $posts   Posts ordered by created ASC.
     * @param  string      $subject Discussion subject.
     * @return string
     */
    private function format_thread_content(array $posts, string $subject): string {
        $authormap = [];
        $letter    = 'A';
        $lines     = [];

        if (!empty($subject)) {
            $lines[] = 'Discussion topic: ' . trim($subject);
            $lines[] = '';
        }

        foreach ($posts as $post) {
            $uid = $post->userid ?? 0;
            if (!isset($authormap[$uid])) {
                $authormap[$uid] = 'Participant ' . $letter;
                $letter++;
            }
            $date    = userdate($post->created,
                get_string('strftimedatetimeshort', 'langconfig'));
            $body    = $this->clean_post_body($post->message ?? '');
            $lines[] = "--- {$authormap[$uid]} ({$date}) ---";
            $lines[] = $body;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private - text utilities
    // -------------------------------------------------------------------------

    /**
     * Strip HTML and clean a forum post body for LLM consumption.
     *
     * @param  string $html Raw HTML from forum_posts.message.
     * @return string       Plain text.
     */
    private function clean_post_body(string $html): string {
        $html = preg_replace('/<\/?(p|br|div|li|tr|h[1-6])[^>]*>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Count words in a plain-text string.
     *
     * @param  string $text Plain text after clean_post_body.
     * @return int          Word count.
     */
    private function count_words(string $text): int {
        return empty(trim($text)) ? 0 : str_word_count(trim($text));
    }

    /**
     * Compute post composition statistics for a set of posts.
     *
     * Word count and character count are exact PHP calculations injected into
     * the context header so the LLM reads them directly. All other stats are
     * used for context header composition and meta.notes generation.
     *
     * @param  \stdClass[] $posts Array of post records (must have message, discussion).
     * @return array {
     *   total_posts           int,
     *   substantive_count     int,
     *   short_count           int,
     *   substantive_avg_words int,
     *   short_avg_words       int,
     *   total_words           int   - exact total word count across all posts,
     *   total_characters      int   - exact total character count across all posts,
     *   thread_count          int,
     * }
     */
    private function compute_post_stats(array $posts): array {
        $substantive_words = [];
        $short_words       = [];
        $threads           = [];
        $total_chars       = 0;

        foreach ($posts as $post) {
            $body  = $this->clean_post_body($post->message ?? '');
            $words = $this->count_words($body);
            $chars = mb_strlen($body, 'UTF-8');

            $total_chars += $chars;

            if ($words >= self::SUBSTANTIVE_WORD_THRESHOLD) {
                $substantive_words[] = $words;
            } else {
                $short_words[] = $words;
            }

            if (!empty($post->discussion)) {
                $threads[$post->discussion] = true;
            }
        }

        $sub_count   = count($substantive_words);
        $short_count = count($short_words);

        return [
            'total_posts'           => count($posts),
            'substantive_count'     => $sub_count,
            'short_count'           => $short_count,
            'substantive_avg_words' => $sub_count > 0
                ? (int) round(array_sum($substantive_words) / $sub_count) : 0,
            'short_avg_words'       => $short_count > 0
                ? (int) round(array_sum($short_words) / $short_count) : 0,
            'total_words'           => array_sum($substantive_words) + array_sum($short_words),
            'total_characters'      => $total_chars,
            'thread_count'          => count($threads),
        ];
    }

    // -------------------------------------------------------------------------
    // Private - prompt assembly
    // -------------------------------------------------------------------------

    /**
     * Assemble a prompt with context header, optional competency block,
     * prompt template, and content.
     *
     * Used for student_forum and thread analyses.
     *
     * Structure:
     *   [CONTEXT HEADER]
     *   ---
     *   [COMPETENCY BLOCK]        <- only if competencies are resolved
     *   ---
     *   [PROMPT TEMPLATE]
     *   ---
     *   [CONTENT]
     *
     * @param  string $header   Context header block.
     * @param  string $template Prompt template (forum analyzer).
     * @param  string $content  Formatted post content.
     * @return string           Complete prompt.
     */
    private function assemble_with_context(string $header, string $template, string $content): string {
        $parts = [
            $header,
            '---',
            '',
        ];

        // Inject competency block if present.
        if (!empty($this->competencyblock)) {
            $parts[] = $this->competencyblock;
            $parts[] = '---';
            $parts[] = '';
        }

        $parts[] = $template;
        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = $content;

        return implode("\n", $parts);
    }

    /**
     * Assemble the final prompt from preamble + template + content (legacy path).
     *
     * Used by build_for_post() and build_for_thread().
     *
     * @param  string $content Formatted post content.
     * @return string          Complete prompt.
     */
    private function assemble(string $content): string {
        $parts = [];

        if (!empty($this->preamble)) {
            $parts[] = $this->preamble;
            $parts[] = '';
        }

        $parts[] = $this->activetemplate;
        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = $content;

        return implode("\n", $parts);
    }
}
