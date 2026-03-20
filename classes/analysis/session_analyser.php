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
 * Session analyser for local_lid.
 *
 * Orchestrates the complete pipeline from a queued job to a stored,
 * validated LID JSON result.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\analysis;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates the full analysis pipeline for a single queue item.
 *
 * Pipeline for a post-scope analysis:
 *
 *   1. Load the local_lid_analysis record and mark it 'processing'.
 *   2. Load the forum post content from the database.
 *   3. Build the assembled prompt via prompt_builder.
 *   4. Send the prompt to the LLM via the client.
 *   5. Validate the response via schema_validator.
 *      a. If truncated: retry once with doubled max_tokens before failing.
 *      b. If other fatal error: record error, increment attempts.
 *      c. If warnings only: store with warning metadata.
 *   6. On success: store the JSON, mark 'complete', invalidate aggregates.
 *   7. On failure after max attempts: mark 'error', surface in admin report.
 *
 * The analyser is called by task/process_queue.php for each item it claims
 * from the queue. It is also called directly by ajax.php when a teacher
 * manually triggers analysis (priority 1, bypasses normal queue drain order).
 *
 * Usage:
 *   $analyser = new \local_lid\analysis\session_analyser();
 *   $analyser->process_queue_item($queuerecord);
 */
class session_analyser {

    /** @var int Maximum LLM call attempts per queue item before permanent failure. */
    const MAX_ATTEMPTS = 3;

    /** @var int Multiplier applied to max_tokens on a truncation retry. */
    const TRUNCATION_TOKEN_MULTIPLIER = 2;

    /** @var \local_lid\llm\client */
    private \local_lid\llm\client $client;

    /** @var schema_validator */
    private schema_validator $validator;

    /**
     * Constructor.
     *
     * Instantiates the LLM client (which validates config) and the validator.
     * Throws \local_lid\exception\llm_config_exception if the LLM is not
     * configured — the task should catch this and abort the entire run rather
     * than attempting individual items.
     */
    public function __construct() {
        $this->client    = new \local_lid\llm\client();
        $this->validator = new schema_validator();
    }

    /**
     * Process a single queue item end-to-end.
     *
     * This is the main entry point called by process_queue.php.
     * Handles all exceptions internally — never throws to the caller.
     * Returns true if the analysis completed successfully, false otherwise.
     *
     * @param  \stdClass $queueitem  A local_lid_queue record.
     * @return bool                  True on success, false on failure.
     */
    public function process_queue_item(\stdClass $queueitem): bool {
        global $DB;

        // Load the analysis record.
        $analysis = $DB->get_record('local_lid_analysis', ['id' => $queueitem->analysisid]);
        if (!$analysis) {
            $this->release_queue_item($queueitem->id, 'Analysis record not found.');
            return false;
        }

        // Guard: skip if somehow already complete (duplicate queue entry edge case).
        if ($analysis->status === 'complete') {
            $DB->delete_records('local_lid_queue', ['id' => $queueitem->id]);
            return true;
        }

        // Mark analysis as processing.
        $this->set_analysis_status($analysis->id, 'processing');

        // Dispatch by scope.
        try {
            switch ($analysis->scope) {
                case 'post':
                    $success = $this->process_post($analysis, $queueitem);
                    break;

                default:
                    // Aggregate scopes (forum, student_forum, course) are not
                    // processed via LLM — they are computed by the aggregator.
                    // If one ends up in the queue it is a bug; log and discard.
                    debugging(
                        "local_lid session_analyser: unexpected scope '{$analysis->scope}' " .
                        "in queue item {$queueitem->id}. Aggregate analyses are not queued.",
                        DEBUG_DEVELOPER
                    );
                    $DB->delete_records('local_lid_queue', ['id' => $queueitem->id]);
                    $this->set_analysis_status($analysis->id, 'error',
                        "Unexpected scope '{$analysis->scope}' in queue.");
                    return false;
            }
        } catch (\local_lid\exception\llm_config_exception $e) {
            // Config error — abort and re-throw so the task stops processing.
            $this->set_analysis_status($analysis->id, 'error', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            // Unexpected exception — record error and release queue item.
            $this->handle_failure($analysis, $queueitem, $e->getMessage());
            return false;
        }

        return $success;
    }

    // -------------------------------------------------------------------------
    // Scope handlers
    // -------------------------------------------------------------------------

    /**
     * Process a post-scope analysis.
     *
     * Loads the post, builds the prompt, calls the LLM, validates, stores.
     *
     * @param  \stdClass $analysis   local_lid_analysis record.
     * @param  \stdClass $queueitem  local_lid_queue record.
     * @return bool
     */
    private function process_post(\stdClass $analysis, \stdClass $queueitem): bool {
        global $DB;

        // Load the forum post.
        $post = $DB->get_record('forum_posts', ['id' => $analysis->postid]);
        if (!$post) {
            $this->handle_failure(
                $analysis, $queueitem,
                "Forum post {$analysis->postid} not found — it may have been deleted."
            );
            return false;
        }

        // Build the prompt.
        $builder = new \local_lid\llm\prompt_builder($analysis->courseid, $analysis->forumid);
        $prompt  = $builder->build_for_post($post);
        $hash    = $builder->get_prompt_hash();

        // Attempt LLM call with retry on truncation.
        $rawresponse = $this->call_with_truncation_retry($prompt, $analysis, $queueitem);
        if ($rawresponse === null) {
            // Failure already recorded inside call_with_truncation_retry.
            return false;
        }

        // Validate.
        $result = $this->validator->validate($rawresponse);

        if (!$result->is_valid()) {
            $this->handle_failure($analysis, $queueitem, $result->get_error_summary());
            return false;
        }

        // Store the result.
        $this->store_success($analysis, $queueitem, $result, $hash);

        // Rebuild aggregate analyses for this post's parent scopes.
        $this->invalidate_and_rebuild_aggregates(
            $analysis->courseid,
            $analysis->forumid,
            $analysis->userid
        );

        return true;
    }

    // -------------------------------------------------------------------------
    // LLM call helpers
    // -------------------------------------------------------------------------

    /**
     * Call the LLM and retry once with doubled max_tokens if the response is
     * truncated. Returns the raw response string on success, or null on
     * failure (with failure already recorded on the analysis/queue records).
     *
     * @param  string    $prompt
     * @param  \stdClass $analysis
     * @param  \stdClass $queueitem
     * @return string|null
     */
    private function call_with_truncation_retry(
        string $prompt,
        \stdClass $analysis,
        \stdClass $queueitem
    ): ?string {

        // First attempt.
        try {
            $raw = $this->client->complete($prompt);
        } catch (\local_lid\exception\llm_request_exception $e) {
            $this->handle_failure($analysis, $queueitem, $e->getMessage());
            return null;
        }

        // Quick truncation pre-check before full validation.
        $quickresult = $this->validator->validate($raw);
        if (!$quickresult->is_truncated()) {
            return $raw; // Not truncated — pass through for full validation.
        }

        // Truncated — retry with doubled max_tokens if we have attempts left.
        $attempts = (int) $queueitem->attempts + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->handle_failure(
                $analysis, $queueitem,
                get_string('error_llm_truncated', 'local_lid')
            );
            return null;
        }

        // Temporarily increase max_tokens for the retry.
        $originaltokens = (int) get_config('local_lid', 'llm_maxtokens') ?: 4096;
        $retrytokens    = $originaltokens * self::TRUNCATION_TOKEN_MULTIPLIER;

        // Re-instantiate client is not needed — we rebuild with overridden tokens
        // by subclassing or by direct override. Since client reads config at
        // construction time, we use a workaround: temporarily set config,
        // build a new client, then restore. This is safe within a single
        // synchronous cron task execution.
        set_config('llm_maxtokens', $retrytokens, 'local_lid');
        try {
            $retryclient = new \local_lid\llm\client();
            $raw         = $retryclient->complete($prompt);
        } catch (\local_lid\exception\llm_request_exception $e) {
            set_config('llm_maxtokens', $originaltokens, 'local_lid');
            $this->handle_failure($analysis, $queueitem, $e->getMessage());
            return null;
        } finally {
            set_config('llm_maxtokens', $originaltokens, 'local_lid');
        }

        // Increment attempts on the queue record for the retry.
        $this->increment_attempts($queueitem->id);

        return $raw;
    }

    // -------------------------------------------------------------------------
    // Result storage
    // -------------------------------------------------------------------------

    /**
     * Store a successful analysis result.
     *
     * Updates the local_lid_analysis record with the validated JSON,
     * records the model and prompt hash, sets status to 'complete',
     * and removes the queue item.
     *
     * If there are validation warnings, appends them to meta.notes in the
     * stored JSON so they are visible in the dashboard.
     *
     * @param  \stdClass          $analysis
     * @param  \stdClass          $queueitem
     * @param  validation_result  $result
     * @param  string             $prompthash SHA-256 of the prompt used.
     */
    private function store_success(
        \stdClass $analysis,
        \stdClass $queueitem,
        validation_result $result,
        string $prompthash
    ): void {
        global $DB;

        $data = $result->get_data();

        // Append validation warnings to meta.notes so the dashboard can surface them.
        $warnings = $result->get_warnings();
        if (!empty($warnings)) {
            $existingnotes = $data['meta']['notes'] ?? '';
            $warningtext   = 'Validation warnings: ' . implode(' | ', $warnings);
            $data['meta']['notes'] = $existingnotes
                ? $existingnotes . "\n" . $warningtext
                : $warningtext;
        }

        $DB->update_record('local_lid_analysis', (object) [
            'id'            => $analysis->id,
            'analysis_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'schema_version' => $data['schema_version'] ?? '1.0',
            'status'        => 'complete',
            'error_message' => null,
            'llm_model'     => $this->client->get_model(),
            'prompt_hash'   => $prompthash,
            'timemodified'  => time(),
        ]);

        // Remove from queue — processing complete.
        $DB->delete_records('local_lid_queue', ['id' => $queueitem->id]);
    }

    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    /**
     * Handle a processing failure.
     *
     * Increments the attempt counter. If attempts < MAX_ATTEMPTS, releases
     * the queue item (sets timeclaimed = null, timevisible = future) so it
     * will be retried on the next task run. If attempts >= MAX_ATTEMPTS,
     * marks the analysis as permanently failed and removes the queue item.
     *
     * @param  \stdClass $analysis
     * @param  \stdClass $queueitem
     * @param  string    $errormessage
     */
    private function handle_failure(
        \stdClass $analysis,
        \stdClass $queueitem,
        string $errormessage
    ): void {
        global $DB;

        $attempts = $this->increment_attempts($queueitem->id);

        if ($attempts < self::MAX_ATTEMPTS) {
            // Release for retry — back off by 5 minutes per attempt.
            $backoffseconds = $attempts * 5 * 60;
            $DB->update_record('local_lid_queue', (object) [
                'id'          => $queueitem->id,
                'timeclaimed' => null,
                'timevisible' => time() + $backoffseconds,
            ]);

            $this->set_analysis_status($analysis->id, 'pending',
                "Attempt {$attempts} failed: {$errormessage}. Will retry.");

        } else {
            // Max attempts reached — permanent failure.
            $DB->delete_records('local_lid_queue', ['id' => $queueitem->id]);
            $this->set_analysis_status(
                $analysis->id,
                'error',
                "Failed after " . self::MAX_ATTEMPTS . " attempts. Last error: {$errormessage}"
            );
        }
    }

    /**
     * Release a queue item without incrementing attempts.
     * Used when the analysis record itself is missing or structurally invalid.
     *
     * @param  int    $queueid
     * @param  string $reason  Logged via debugging().
     */
    private function release_queue_item(int $queueid, string $reason): void {
        global $DB;
        debugging("local_lid session_analyser: releasing queue item {$queueid}: {$reason}", DEBUG_DEVELOPER);
        $DB->delete_records('local_lid_queue', ['id' => $queueid]);
    }

    // -------------------------------------------------------------------------
    // Aggregate invalidation
    // -------------------------------------------------------------------------

    /**
     * Invalidate and rebuild aggregate analysis records that depend on the
     * newly completed post analysis.
     *
     * Three aggregate scopes are affected by any new post-scope completion:
     *   - student_forum: this student's aggregate for this forum
     *   - forum:         the whole-forum aggregate
     *   - course:        the whole-course aggregate
     *
     * Aggregates are rebuilt synchronously here (they are fast — pure PHP
     * arithmetic on existing DB rows with no LLM calls). For very large
     * forums (100+ posts), this could be moved to a separate low-priority
     * queue job in a future version.
     *
     * @param  int $courseid
     * @param  int $forumid
     * @param  int $userid
     */
    private function invalidate_and_rebuild_aggregates(
        int $courseid,
        int $forumid,
        int $userid
    ): void {
        $aggregator = new aggregator();

        // Student-forum aggregate.
        $aggregator->rebuild_student_forum($userid, $forumid, $courseid);

        // Forum aggregate.
        $aggregator->rebuild_forum($forumid, $courseid);

        // Course aggregate.
        $aggregator->rebuild_course($courseid);
    }

    // -------------------------------------------------------------------------
    // Database helpers
    // -------------------------------------------------------------------------

    /**
     * Set the status (and optional error message) on a local_lid_analysis record.
     *
     * @param  int         $analysisid
     * @param  string      $status       One of: pending, processing, complete, error.
     * @param  string|null $errormessage Null to clear any existing error message.
     */
    private function set_analysis_status(
        int $analysisid,
        string $status,
        ?string $errormessage = null
    ): void {
        global $DB;

        $DB->update_record('local_lid_analysis', (object) [
            'id'            => $analysisid,
            'status'        => $status,
            'error_message' => $errormessage,
            'timemodified'  => time(),
        ]);
    }

    /**
     * Increment the attempts counter on a queue item and return the new value.
     *
     * @param  int $queueid
     * @return int New attempts count.
     */
    private function increment_attempts(int $queueid): int {
        global $DB;

        $current = (int) $DB->get_field('local_lid_queue', 'attempts', ['id' => $queueid]);
        $new     = $current + 1;

        $DB->update_record('local_lid_queue', (object) [
            'id'       => $queueid,
            'attempts' => $new,
        ]);

        return $new;
    }
}
