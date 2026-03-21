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
 *     Manually enqueue a forum for (re-)analysis.
 *     Required params: forumid (int) + courseid (int), OR analysisid (int)
 *     Required capability: local/lid:triggeranalysis (module context)
 *     Response: { success: true, queued: N }
 *
 *   status
 *     Poll the analysis status for an analysis record.
 *     Required params: analysisid (int)
 *     Required capability: local/lid:viewforumdashboard (module context)
 *     Response: { status: string, analysis_json: string|null }
 *
 *   forum_config
 *     Save the LID enabled/disabled state, discussion model, and optional
 *     prompt override for a forum.
 *     Required params: forumid (int), courseid (int), enabled (0|1)
 *     Optional params: discussion_model (string), prompt_override (string)
 *     Required capability: local/lid:configureforum (module context)
 *     Response: { success: true, enabled: bool, discussion_model: string }
 *
 *   save_prompt
 *     Save a course-level prompt override.
 *     Required params: courseid (int), prompt (string)
 *     Required capability: local/lid:editprompt (course context)
 *     Blocked when prompt_locked = 1.
 *     Response: { success: true }
 *
 *   reset_prompt_default
 *     Return the plugin's shipped default prompt text.
 *     Required capability: local/lid:managesitesettings (system context)
 *     Response: { success: true, prompt: string }
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
 * Handle 'trigger' action — manually queue analysis for a forum.
 *
 * Three modes:
 *   - Single analysis row:  analysisid param provided.
 *   - Whole forum:          forumid + courseid params provided.
 *   - Course-wide:          courseid only (no forumid, no analysisid).
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
        $cm = get_coursemodule_from_instance('forum', $forumid, $courseid,
            false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('local/lid:triggeranalysis', $context);

        $queued = \local_lid\observer::queue_forum_analysis(
            $courseid,
            $forumid,
            \local_lid\observer::PRIORITY_MANUAL
        );
        return ['success' => true, 'queued' => $queued];
    }

    if ($courseid && !$forumid && !$analysisid) {
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
            $queued += \local_lid\observer::queue_forum_analysis(
                $courseid,
                (int) $config->forumid,
                \local_lid\observer::PRIORITY_MANUAL
            );
        }

        return ['success' => true, 'queued' => $queued];
    }

    throw new \moodle_exception('missingparam', '', '', 'analysisid or forumid+courseid or courseid');
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

    if ($analysis->status === 'processing') {
        return false;
    }

    $DB->update_record('local_lid_analysis', (object) [
        'id'           => $analysis->id,
        'status'       => 'pending',
        'timemodified' => time(),
    ]);

    $DB->delete_records('local_lid_queue', ['analysisid' => $analysis->id]);

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
 * @return array JSON-serialisable response.
 */
function handle_status(): array {
    global $DB;

    $analysisid = required_param('analysisid', PARAM_INT);

    $analysis = $DB->get_record('local_lid_analysis',
        ['id' => $analysisid], '*', MUST_EXIST);

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
 * Handle 'forum_config' action — save LID enable/disable, discussion model,
 * and optional prompt override for a forum.
 *
 * Accepted params:
 *   forumid          int     Required.
 *   courseid         int     Required.
 *   enabled          int     Required. 0 or 1.
 *   discussion_model string  Optional. One of: independent_first | open_engagement |
 *                            structured_debate. Defaults to 'open_engagement' if
 *                            absent or not one of the three recognised values.
 *   prompt_override  string  Optional. Only accepted when prompt is not locked
 *                            and user has local/lid:editprompt.
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

    // Validate discussion_model — reject unknown values, default to open_engagement.
    $validmodels = [
        \local_lid\llm\prompt_builder::MODEL_INDEPENDENT_FIRST,
        \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT,
        \local_lid\llm\prompt_builder::MODEL_STRUCTURED_DEBATE,
    ];
    $rawmodel        = optional_param('discussion_model', '', PARAM_ALPHANUMEXT);
    $discussionmodel = in_array($rawmodel, $validmodels, true)
        ? $rawmodel
        : \local_lid\llm\prompt_builder::MODEL_OPEN_ENGAGEMENT;

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
            'id'               => $existing->id,
            'enabled'          => (int) (bool) $enabled,
            'discussion_model' => $discussionmodel,
            'timemodified'     => time(),
        ];
        if (!$promptlocked) {
            $update->prompt_override = $promptoverride;
        }
        $DB->update_record('local_lid_forum_config', $update);
    } else {
        $DB->insert_record('local_lid_forum_config', (object) [
            'forumid'          => $forumid,
            'courseid'         => $courseid,
            'enabled'          => (int) (bool) $enabled,
            'discussion_model' => $discussionmodel,
            'prompt_override'  => $promptoverride,
            'timecreated'      => time(),
            'timemodified'     => time(),
        ]);
    }

    return [
        'success'          => true,
        'enabled'          => (bool) $enabled,
        'discussion_model' => $discussionmodel,
    ];
}

/**
 * Handle 'reset_prompt_default' action — return the plugin's shipped default
 * prompt text so the admin settings page JS can load it into the textarea.
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

/**
 * Handle 'save_prompt' action — save a course-level prompt override.
 *
 * @return array JSON-serialisable response.
 */
function handle_save_prompt(): array {
    global $DB;

    $courseid = required_param('courseid', PARAM_INT);
    $prompt   = required_param('prompt',   PARAM_RAW);

    $context = context_course::instance($courseid);
    require_capability('local/lid:editprompt', $context);

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

    // Sentinel value from reset-to-site-default button — deletes course override.
    if ($prompt === '__RESET_TO_SITE_DEFAULT__') {
        $DB->delete_records('local_lid_settings', ['courseid' => $courseid]);
        return [
            'success' => true,
            'message' => get_string('prompt_saved', 'local_lid'),
        ];
    }

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
