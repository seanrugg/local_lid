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
 * Scheduled task: drain the local_lid analysis queue.
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_lid\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that claims and processes pending items from local_lid_queue.
 *
 * Run frequency is controlled by the cron_interval admin setting (1–1440
 * minutes), which updates the task's minute/hour fields in task_scheduled
 * via the local_lid_after_config_change() callback in lib.php.
 *
 * Each execution:
 *   1. Reads cron_batchsize from config (default 10).
 *   2. Claims up to that many unclaimed, visible, non-maxed queue items,
 *      ordered by priority ASC, timevisible ASC.
 *   3. Instantiates session_analyser once (validates LLM config once).
 *   4. Processes each claimed item. On llm_config_exception, aborts the
 *      entire run and logs — no point continuing if config is broken.
 *   5. Logs a summary line at the end of the run.
 *
 * Concurrency safety:
 *   Items are claimed by setting timeclaimed = NOW() in a single UPDATE
 *   with a WHERE timeclaimed IS NULL guard. This prevents two overlapping
 *   cron runs from processing the same item, even on multi-server setups.
 *   Moodle's task runner also has its own per-task locking, but the
 *   timeclaimed field provides a second layer specifically for the queue.
 */
class process_queue extends \core\task\scheduled_task {

    /**
     * Return the human-readable task name shown in the scheduled tasks UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_process_queue', 'local_lid');
    }

    /**
     * Execute the task.
     *
     * Claims a batch of queue items and processes each one via session_analyser.
     * Exceptions from the analyser that are not llm_config_exception are
     * caught per-item — one bad item does not abort the batch.
     */
    public function execute(): void {
        global $DB;

        $batchsize = max(1, (int) get_config('local_lid', 'cron_batchsize') ?: 10);
        $now       = time();

        // ----------------------------------------------------------------
        // Step 1 — Claim a batch of queue items atomically.
        // ----------------------------------------------------------------
        // Fetch candidates: unclaimed, visible, not yet at max attempts.
        // Order by priority ASC (1=manual first), then timevisible ASC
        // (oldest first within the same priority).
        $candidates = $DB->get_records_sql(
            "SELECT q.*
               FROM {local_lid_queue} q
              WHERE q.timeclaimed IS NULL
                AND q.timevisible <= :now
                AND q.attempts < :maxattempts
           ORDER BY q.priority ASC, q.timevisible ASC",
            [
                'now'         => $now,
                'maxattempts' => \local_lid\analysis\session_analyser::MAX_ATTEMPTS,
            ],
            0,
            $batchsize
        );

        if (empty($candidates)) {
            mtrace('local_lid process_queue: no items to process.');
            return;
        }

        // Claim all candidates atomically.
        $ids    = array_keys($candidates);
        $idlist = implode(',', array_map('intval', $ids));
        $DB->execute(
            "UPDATE {local_lid_queue}
                SET timeclaimed = :claimtime
              WHERE id IN ({$idlist})
                AND timeclaimed IS NULL",
            ['claimtime' => $now]
        );

        // Re-fetch to confirm which ones we actually claimed.
        $claimed = $DB->get_records_select(
            'local_lid_queue',
            "id IN ({$idlist}) AND timeclaimed = :claimed",
            ['claimed' => $now]
        );

        if (empty($claimed)) {
            mtrace('local_lid process_queue: all candidates were claimed by another process.');
            return;
        }

        mtrace('local_lid process_queue: claimed ' . count($claimed) . ' item(s). Processing...');

        // ----------------------------------------------------------------
        // Step 2 — Instantiate the analyser (validates LLM config once).
        // ----------------------------------------------------------------
        try {
            $analyser = new \local_lid\analysis\session_analyser();
        } catch (\local_lid\exception\llm_config_exception $e) {
            // LLM is not configured — release all claimed items and abort.
            $this->release_claimed($ids, $now);
            mtrace('local_lid process_queue: LLM not configured — aborting run. ' . $e->getMessage());
            return;
        }

        // ----------------------------------------------------------------
        // Step 3 — Process each claimed item.
        // ----------------------------------------------------------------
        $succeeded = 0;
        $failed    = 0;

        foreach ($claimed as $item) {
            try {
                $ok = $analyser->process_queue_item($item);
                $ok ? $succeeded++ : $failed++;
            } catch (\local_lid\exception\llm_config_exception $e) {
                // Config error mid-run (shouldn't happen, but catch defensively).
                // Release remaining unclaimed items and stop.
                $remaining = array_filter($claimed, fn($c) => $c->id !== $item->id);
                $this->release_claimed(array_keys($remaining), $now);
                mtrace('local_lid process_queue: LLM config error mid-run — stopping. ' . $e->getMessage());
                $failed++;
                break;
            } catch (\Throwable $e) {
                // Unexpected exception from a single item — log and continue.
                mtrace("local_lid process_queue: unexpected error on queue item {$item->id}: " .
                    $e->getMessage());
                $failed++;
            }
        }

        // ----------------------------------------------------------------
        // Step 4 — Summary.
        // ----------------------------------------------------------------
        mtrace("local_lid process_queue: run complete. " .
            "Succeeded: {$succeeded}, Failed: {$failed}, " .
            "Total claimed: " . count($claimed) . ".");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Release claimed queue items back to the pool by clearing timeclaimed.
     *
     * Used when the task aborts early (config error) to avoid stranding items
     * in a permanently claimed state.
     *
     * @param  int[] $ids   Queue item IDs to release.
     * @param  int   $claimedtime The timeclaimed value used to claim them,
     *                            used as an extra WHERE guard.
     */
    private function release_claimed(array $ids, int $claimedtime): void {
        global $DB;

        if (empty($ids)) {
            return;
        }

        $idlist = implode(',', array_map('intval', $ids));
        $DB->execute(
            "UPDATE {local_lid_queue}
                SET timeclaimed = NULL
              WHERE id IN ({$idlist})
                AND timeclaimed = :claimed",
            ['claimed' => $claimedtime]
        );
    }
}
