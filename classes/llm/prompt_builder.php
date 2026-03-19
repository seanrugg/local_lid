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
 * Prompt resolution order (most specific wins):
 *   1. Forum-level prompt_override in local_lid_forum_config
 *      (only used when prompt_locked = 0 at site level)
 *   2. Course-level prompt_template in local_lid_settings (courseid > 0)
 *      (only used when prompt_locked = 0 at site level)
 *   3. Site-level prompt_template in local_lid_settings (courseid = 0)
 *
 * The forum-post preamble (prompts/forum-post-preamble.md) is always
 * prepended to calibrate the LLM for shorter forum content. This is not
 * user-editable — it is a fixed calibration layer applied on top of
 * whatever prompt template is active.
 *
 * Final prompt structure sent to the LLM:
 *
 *   [PREAMBLE]
 *   ---
 *   [ACTIVE PROMPT TEMPLATE]
 *   ---
 *   [POST CONTENT BLOCK]
 *
 * Usage:
 *   $builder = new \local_lid\llm\prompt_builder($courseid, $forumid);
 *   $prompt  = $builder->build_for_post($postrecord);
 *   $hash    = $builder->get_prompt_hash(); // SHA-256 of active template.
 */
class prompt_builder {

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

    /**
     * Constructor — resolves and caches the active prompt template.
     *
     * @param int      $courseid Course id.
     * @param int|null $forumid  Forum id (null for course-level analyses).
     */
    public function __construct(int $courseid, ?int $forumid = null) {
        $this->courseid = $courseid;
        $this->forumid  = $forumid;

        $this->preamble       = $this->load_preamble();
        $this->activetemplate = $this->resolve_template();
        $this->prompthash     = hash('sha256', $this->activetemplate);
    }

    /**
     * Build the complete prompt for a single forum post.
     *
     * Fetches the post and its parent discussion subject from the database,
     * formats them into a content block, and assembles the full prompt.
     *
     * @param  \stdClass $post  The forum_posts record (must have id, message, subject).
     * @return string           The complete prompt string ready to send to the LLM.
     */
    public function build_for_post(\stdClass $post): string {
        $content = $this->format_post_content($post);
        return $this->assemble($content);
    }

    /**
     * Build the complete prompt for a set of posts (e.g. a full discussion thread).
     *
     * Posts are presented in chronological order with author pseudonymisation:
     * students are identified as "Student A", "Student B" etc. to avoid the
     * LLM making assumptions based on names, while preserving threading context.
     *
     * @param  \stdClass[] $posts   Array of forum_posts records, ordered by timecreated ASC.
     * @param  string      $subject The discussion subject line.
     * @return string               The complete prompt string.
     */
    public function build_for_thread(array $posts, string $subject): string {
        $content = $this->format_thread_content($posts, $subject);
        return $this->assemble($content);
    }

    /**
     * Return the SHA-256 hash of the active prompt template.
     *
     * Stored in local_lid_analysis.prompt_hash to detect stale analyses
     * when the prompt is later changed.
     *
     * @return string 64-character hex string.
     */
    public function get_prompt_hash(): string {
        return $this->prompthash;
    }

    /**
     * Return the active prompt template (without preamble or content).
     * Used by the prompt editor to show what template is in effect.
     *
     * @return string
     */
    public function get_active_template(): string {
        return $this->activetemplate;
    }

    // -------------------------------------------------------------------------
    // Private — prompt resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the active prompt template using the priority chain.
     *
     * @return string The resolved template text.
     */
    private function resolve_template(): string {
        global $DB;

        $sitelocked = (bool) $this->get_site_setting('prompt_locked');

        // If the prompt is locked, skip forum and course overrides entirely.
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

        // Fall back to site-level template.
        $sitetemplate = $this->get_site_setting('prompt_template');
        if (!empty($sitetemplate)) {
            return trim($sitetemplate);
        }

        // Last resort — load from the shipped file (handles edge case where
        // the DB row was deleted but the file is intact).
        return $this->load_default_template_from_file();
    }

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
     * Load the default prompt template from the shipped .md file.
     *
     * @return string Template text, or empty string if the file is missing.
     */
    private function load_default_template_from_file(): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/lid/prompts/default-session-analyzer.md';
        return file_exists($path) ? trim(file_get_contents($path)) : '';
    }

    /**
     * Helper — get a value from the site-level local_lid_settings row.
     *
     * @param  string $field Column name.
     * @return mixed         Field value, or null if the row does not exist.
     */
    private function get_site_setting(string $field) {
        global $DB;
        return $DB->get_field('local_lid_settings', $field, ['courseid' => 0]);
    }

    // -------------------------------------------------------------------------
    // Private — content formatting
    // -------------------------------------------------------------------------

    /**
     * Format a single forum post into a content block for the LLM.
     *
     * Strips HTML tags from the message body (Moodle stores forum posts as
     * HTML). Includes the discussion subject for context.
     *
     * @param  \stdClass $post The forum_posts record.
     * @return string
     */
    private function format_post_content(\stdClass $post): string {
        global $DB;

        // Fetch discussion subject if not already on the record.
        $subject = $post->subject ?? '';
        if (empty($subject) && !empty($post->discussion)) {
            $subject = $DB->get_field('forum_discussions', 'name', ['id' => $post->discussion]);
        }

        $body = $this->clean_post_body($post->message ?? '');

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
     * Format an array of posts (a discussion thread) into a content block.
     *
     * Authors are pseudonymised to "Participant A", "Participant B" etc.
     * to prevent name-based bias in the LLM analysis. The mapping is
     * deterministic within a build call but not persisted.
     *
     * @param  \stdClass[] $posts   Posts ordered by timecreated ASC.
     * @param  string      $subject Discussion subject.
     * @return string
     */
    private function format_thread_content(array $posts, string $subject): string {
        $authormap = [];
        $letter    = 'A';

        $lines = [];
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
            $author = $authormap[$uid];
            $date   = userdate($post->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
            $body   = $this->clean_post_body($post->message ?? '');

            $lines[] = "--- {$author} ({$date}) ---";
            $lines[] = $body;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Strip HTML and clean up a forum post message body for LLM consumption.
     *
     * Converts block-level HTML elements to newlines before stripping tags
     * so the logical structure of the text is preserved.
     *
     * @param  string $html Raw HTML message body from forum_posts.message.
     * @return string       Plain text.
     */
    private function clean_post_body(string $html): string {
        // Convert common block elements to newlines.
        $html = preg_replace('/<\/?(p|br|div|li|tr|h[1-6])[^>]*>/i', "\n", $html);

        // Strip remaining tags.
        $text = strip_tags($html);

        // Decode HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise whitespace — collapse runs of blank lines to a single blank line.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    // -------------------------------------------------------------------------
    // Private — prompt assembly
    // -------------------------------------------------------------------------

    /**
     * Assemble the final prompt string from preamble + template + content.
     *
     * @param  string $content The formatted post/thread content block.
     * @return string          The complete prompt.
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
