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
 * AJAX endpoint for local_lid.
 *
 * All requests must:
 *   - Be POST requests (except 'status' which accepts GET for polling).
 *   - Include a valid Moodle sesskey (CSRF protection).
 *   - Be from a logged-in user with the appropriate capability.
 *
 * Actions:
 *
 *   trigger
 *     Manually enqueue a single post or entire forum for (re-)analysis.
 *     Required params: analysisid (int) OR forumid (int) + courseid (int)
 *     Required capability: local/lid:triggeranalysis (module context)
 *     Response: { success: true, queued: N }
 *
 *   status
 *     Poll the analysis status for a queue item or analysis record.
 *     Required params: analysisid (int)
 *     Required capability: local/lid:viewforumdashboard (module context)
 *     Response: { status: string, analysis_json: string|null }
 *
 *   forum_config
 *     Save the LID enabled/disabled state for a forum, and optionally
 *     save a prompt override.
 *     Required params: forumid (int), courseid (int), enabled (0|1)
 *     Optional params: prompt_override (string)
 *     Required capability: local/lid:configureforum (module context)
 *     Response: { success: true }
 *
 *   save_prompt
 *     Save a course-level prompt override.
 *     Required params: courseid (int), prompt (string)
 *     Required capability: local/lid:editprompt (course context)
 *     Blocked when prompt_locked = 1.
 *     Response: { success: true }
 *
 * All error responses follow: { error: true, message: string }
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);  // Prevent PHP/Moodle debug output corrupting JSON.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lid/lib.php');

// Buffer all output — any stray PHP warnings must not corrupt the JSON response.
ob_start();

require_login();

// All responses are JSON. Discard any buffered debug output first.
ob_end_clean();
header('Content-Type: application/json');

// Route by action.
$action = required_param('action', PARAM_ALPHANUMEXT);

try {
    switch ($action) {

        case 'trigger':
            echo json_encode(handle_trigger());
            break;

        case 'status':
            echo json_encode(handle_status());
            break;

        case 'forum_config':
            confirm_sesskey();
            echo json_encode(handle_forum_config());
            break;

        case 'save_prompt':
            confirm_sesskey();
            echo json_encode(handle_save_prompt());
            break;

        case 'reset_prompt_default':
            confirm_sesskey();
            echo json_encode(handle_reset_prompt_default());
            break;

        default:
            ob_end_clean();
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => true, 'message' => 'Unknown action.']);
            break;
    }

} catch (\required_capability_exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => get_string('error_nopermission', 'local_lid')]);

} catch (\moodle_exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);

} catch (\Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
    ]);
}

// =============================================================================
// Action handlers
// =============================================================================

/**
 * Handle 'trigger' action — manually enqueue one post or all posts in a forum.
 *
 * Two modes:
 *   - Single post:  analysisid param provided.
 *   - Whole forum:  forumid + courseid params provided (no analysisid).
 *
 * @return array JSON-serialisable response.
 */
function handle_trigger(): array {
    global $DB;

    confirm_sesskey();

    $analysisid = optional_param('analysisid', 0, PARAM_INT);
    $forumid    = optional_param('forumid',    0, PARAM_INT);
    $courseid   = optional_param('courseid',   0, PARAM_INT);

    if ($analysisid) {
        // Single post mode — load the analysis record to resolve context.
        $analysis = $DB->get_record('local_lid_analysis',
            ['id' => $analysisid], '*', MUST_EXIST);

        $cm = get_coursemodule_from_instance('forum', $analysis->forumid,
            $analysis->courseid, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('local/lid:triggeranalysis', $context);

        $queued = enqueue_single_analysis($analysis);
        return ['success' => true, 'queued' => $queued ? 1 : 0];
    }

    if ($forumid && $courseid) {
        // Whole-forum mode — backfill and enqueue all posts in this forum.
        $cm = get_coursemodule_from_instance('forum', $forumid, $courseid,
            false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('local/lid:triggeranalysis', $context);

        $queued = backfill_and_enqueue_forum($forumid, $courseid);
        return ['success' => true, 'queued' => $queued];
    }

    if ($courseid && !$forumid && !$analysisid) {
        // Course-wide mode — backfill and enqueue all posts across all
        // LID-enabled forums in this course.
        $context = context_course::instance($courseid);
        require_capability('local/lid:triggeranalysis', $context);

        $enabledforums = $DB->get_records(
            'local_lid_forum_config',
            ['courseid' => $courseid, 'enabled' => 1],
            '',
            'forumid'
        );

        $queued = 0;
        foreach ($enabledforums as $config) {
            $queued += backfill_and_enqueue_forum((int) $config->forumid, $courseid);
        }

        return ['success' => true, 'queued' => $queued];
    }

    throw new \moodle_exception('missingparam', '', '', 'analysisid or forumid+courseid or courseid');
}

/**
 * Backfill missing analysis records for all posts in a forum, then enqueue
 * all pending/error records for processing.
 *
 * Backfill covers posts submitted before LID was enabled on the forum
 * (the observer never fired for them so no analysis row exists).
 *
 * @param  int $forumid
 * @param  int $courseid
 * @return int Number of items enqueued.
 */
function backfill_and_enqueue_forum(int $forumid, int $courseid): int {
    global $DB;

    // Get all post IDs that already have analysis records.
    $existingpostids = $DB->get_fieldset_select(
        'local_lid_analysis',
        'postid',
        "scope = 'post' AND forumid = :forumid",
        ['forumid' => $forumid]
    );
    $existingpostids = array_map('intval', $existingpostids);

    // Get all real forum posts (exclude userid=0 system posts).
    $allposts = $DB->get_records_sql(
        "SELECT p.id AS postid, p.userid, d.forum AS forumid,
                d.course AS courseid, p.discussion AS discussionid
           FROM {forum_posts} p
           JOIN {forum_discussions} d ON d.id = p.discussion
          WHERE d.forum = :forumid
            AND p.userid > 0",
        ['forumid' => $forumid]
    );

    // Create analysis records for any posts that don't have one.
    foreach ($allposts as $post) {
        if (!in_array((int) $post->postid, $existingpostids, true)) {
            $DB->insert_record('local_lid_analysis', (object) [
                'scope'        => 'post',
                'postid'       => (int) $post->postid,
                'userid'       => (int) $post->userid,
                'forumid'      => $forumid,
                'courseid'     => $courseid,
                'discussionid' => (int) $post->discussionid,
                'status'       => 'pending',
                'prompt_hash'  => null,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }
    }

    // Enqueue all pending/error post-scope analyses for this forum.
    $analyses = $DB->get_records_select(
        'local_lid_analysis',
        "scope = 'post' AND forumid = :forumid AND status != 'processing'",
        ['forumid' => $forumid]
    );

    $queued = 0;
    foreach ($analyses as $analysis) {
        if (enqueue_single_analysis($analysis)) {
            $queued++;
        }
    }

    return $queued;
}

/**
 * Enqueue a single analysis record for manual (priority 1) processing.
 *
 * Resets the analysis to 'pending', removes any existing queue entry,
 * and inserts a new high-priority queue item with timevisible = now.
 *
 * @param  \stdClass $analysis
 * @return bool  True if enqueued, false if already processing.
 */
function enqueue_single_analysis(\stdClass $analysis): bool {
    global $DB;

    // Don't re-enqueue something currently being processed.
    if ($analysis->status === 'processing') {
        return false;
    }

    // Reset status to pending.
    $DB->update_record('local_lid_analysis', (object) [
        'id'           => $analysis->id,
        'status'       => 'pending',
        'timemodified' => time(),
    ]);

    // Remove any existing queue entry.
    $DB->delete_records('local_lid_queue', ['analysisid' => $analysis->id]);

    // Insert high-priority queue item.
    $DB->insert_record('local_lid_queue', (object) [
        'analysisid'  => $analysis->id,
        'priority'    => \local_lid\observer::PRIORITY_MANUAL,
        'attempts'    => 0,
        'timecreated' => time(),
        'timevisible' => time(),
        'timeclaimed' => null,
    ]);

    return true;
}

/**
 * Handle 'status' action — poll current status of an analysis record.
 *
 * Returns the status string and, when complete, the analysis JSON so the
 * AMD module can update the dashboard panel without a page reload.
 *
 * @return array JSON-serialisable response.
 */
function handle_status(): array {
    global $DB;

    $analysisid = required_param('analysisid', PARAM_INT);

    $analysis = $DB->get_record('local_lid_analysis',
        ['id' => $analysisid], '*', MUST_EXIST);

    // Resolve context for capability check.
    if ($analysis->forumid) {
        $cm = get_coursemodule_from_instance('forum', $analysis->forumid,
            $analysis->courseid, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('local/lid:viewforumdashboard', $context);
    } else {
        $context = context_course::instance($analysis->courseid);
        require_capability('local/lid:viewcoursedashboard', $context);
    }

    return [
        'success'       => true,
        'status'        => $analysis->status,
        'analysis_json' => ($analysis->status === 'complete')
            ? $analysis->analysis_json
            : null,
        'error_message' => ($analysis->status === 'error')
            ? $analysis->error_message
            : null,
    ];
}

/**
 * Handle 'forum_config' action — save LID enable/disable and optional prompt
 * override for a forum.
 *
 * @return array JSON-serialisable response.
 */
function handle_forum_config(): array {
    global $DB;

    $forumid  = required_param('forumid',  PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $enabled  = required_param('enabled',  PARAM_INT);

    $cm = get_coursemodule_from_instance('forum', $forumid, $courseid,
        false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    require_capability('local/lid:configureforum', $context);

    // Prompt override — only accepted if prompt is not locked.
    $promptoverride = null;
    $promptlocked   = (bool) get_config('local_lid', 'prompt_locked');

    if (!$promptlocked && has_capability('local/lid:editprompt',
            context_course::instance($courseid))) {
        $promptoverride = optional_param('prompt_override', null, PARAM_RAW);
        if ($promptoverride !== null) {
            $promptoverride = trim($promptoverride) ?: null;
        }
    }

    // Upsert forum config record.
    $existing = $DB->get_record('local_lid_forum_config', ['forumid' => $forumid]);

    if ($existing) {
        $update = (object) [
            'id'           => $existing->id,
            'enabled'      => (int) (bool) $enabled,
            'timemodified' => time(),
        ];
        if (!$promptlocked) {
            $update->prompt_override = $promptoverride;
        }
        $DB->update_record('local_lid_forum_config', $update);
    } else {
        $DB->insert_record('local_lid_forum_config', (object) [
            'forumid'         => $forumid,
            'courseid'        => $courseid,
            'enabled'         => (int) (bool) $enabled,
            'prompt_override' => $promptoverride,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    return ['success' => true, 'enabled' => (bool) $enabled];
}

/**
 * Handle 'reset_prompt_default' action — return the plugin's shipped default
 * prompt text so the admin settings page JS can load it into the textarea.
 *
 * The admin still needs to submit the settings form to persist the value.
 * This action only reads the file; it does not write to the database.
 *
 * @return array JSON-serialisable response.
 */
function handle_reset_prompt_default(): array {
    global $CFG;

    $context = context_system::instance();
    require_capability('local/lid:managesitesettings', $context);

    $filepath = $CFG->dirroot . '/local/lid/prompts/default-session-analyzer.md';

    if (!file_exists($filepath)) {
        throw new \moodle_exception('filenotfound', 'error', '', 'default-session-analyzer.md');
    }

    $prompt = file_get_contents($filepath);
    if ($prompt === false || trim($prompt) === '') {
        throw new \moodle_exception('invaliddata', 'error', '', 'Default prompt file is empty.');
    }

    return [
        'success' => true,
        'prompt'  => trim($prompt),
    ];
}

 *
 * @return array JSON-serialisable response.
 */
function handle_save_prompt(): array {
    global $DB;

    $courseid = required_param('courseid', PARAM_INT);
    $prompt   = required_param('prompt',   PARAM_RAW);

    $context = context_course::instance($courseid);
    require_capability('local/lid:editprompt', $context);

    // Block if prompt is locked at site level.
    if ((bool) get_config('local_lid', 'prompt_locked')) {
        throw new \required_capability_exception(
            $context,
            'local/lid:editprompt',
            'nopermissions',
            ''
        );
    }

    $prompt = trim($prompt);
    if (empty($prompt)) {
        throw new \moodle_exception('invaliddata', 'error', '', 'Prompt cannot be empty.');
    }

    // Sentinel value sent by prompt_editor.js reset-to-site-default button.
    // Deletes the course-level override row so the course falls back to the site prompt.
    if ($prompt === '__RESET_TO_SITE_DEFAULT__') {
        $DB->delete_records('local_lid_settings', ['courseid' => $courseid]);
        return [
            'success' => true,
            'message' => get_string('prompt_saved', 'local_lid'),
        ];
    }

    // Upsert course-level settings row.
    $existing = $DB->get_record('local_lid_settings', ['courseid' => $courseid]);

    if ($existing) {
        $DB->update_record('local_lid_settings', (object) [
            'id'              => $existing->id,
            'prompt_template' => $prompt,
            'timemodified'    => time(),
        ]);
    } else {
        $DB->insert_record('local_lid_settings', (object) [
            'courseid'        => $courseid,
            'prompt_template' => $prompt,
            'prompt_locked'   => 0,
            'trigger_async'   => 1,
            'trigger_cron'    => 1,
            'trigger_manual'  => 1,
            'cron_interval'   => 5,
            'cron_batchsize'  => 10,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    return [
        'success'     => true,
        'prompt_hash' => hash('sha256', $prompt),
        'message'     => get_string('prompt_saved', 'local_lid'),
    ];
}
