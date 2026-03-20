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
 * English language strings for local_lid.
 *
 * String naming convention:
 *   pluginname          — required by Moodle; plugin display name
 *   settings_*          — admin settings page labels and descriptions
 *   nav_*               — navigation link labels
 *   dashboard_*         — dashboard UI labels
 *   prompt_*            — prompt editor UI labels
 *   forum_*             — forum config UI labels
 *   status_*            — analysis status labels
 *   error_*             — error messages
 *   privacy_*           — GDPR privacy provider strings
 *   task_*              — scheduled task strings
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ---------------------------------------------------------------------------
// Plugin identity
// ---------------------------------------------------------------------------

$string['pluginname'] = 'Learning Intelligence Dashboard';

// ---------------------------------------------------------------------------
// Navigation labels
// ---------------------------------------------------------------------------

$string['nav_coursedashboard']  = 'Learning Intelligence';
$string['nav_forumdashboard']   = 'Learning Intelligence';
$string['nav_studentdashboard'] = 'Learning Intelligence';

// ---------------------------------------------------------------------------
// Admin settings page — section headings
// ---------------------------------------------------------------------------

$string['settings_heading_llm']      = 'LLM API connection';
$string['settings_heading_prompt']   = 'Prompt template';
$string['settings_heading_triggers'] = 'Analysis triggers';

// ---------------------------------------------------------------------------
// Admin settings — LLM connection
// ---------------------------------------------------------------------------

$string['settings_llm_endpoint']         = 'LLM API endpoint';
$string['settings_llm_endpoint_desc']    = 'The full URL of the LLM API endpoint. Example: <code>https://api.anthropic.com/v1/messages</code>';

$string['settings_llm_apikey']           = 'API key';
$string['settings_llm_apikey_desc']      = 'Your LLM provider API key. Stored encrypted in the Moodle database. Leave blank to keep the existing key.';

$string['settings_llm_model']            = 'Model';
$string['settings_llm_model_desc']       = 'The model identifier passed in API requests. Example: <code>claude-sonnet-4-6</code>';

$string['settings_llm_maxtokens']        = 'Max tokens';
$string['settings_llm_maxtokens_desc']   = 'Maximum tokens requested in each LLM API call. Default: 4096. Increase if analyses are being truncated.';

$string['settings_llm_timeout']          = 'Request timeout (seconds)';
$string['settings_llm_timeout_desc']     = 'HTTP timeout for LLM API requests in seconds. Default: 60. Increase for slow endpoints or large forum threads.';

// ---------------------------------------------------------------------------
// Admin settings — prompt template
// ---------------------------------------------------------------------------

$string['settings_prompt_template']         = 'Prompt template';
$string['settings_prompt_template_desc']    = 'The session analyzer prompt sent to the LLM. This is pre-populated with the LID v1.0 default prompt. You can edit it directly, paste new content, or upload a <code>.md</code> file. Changes here become the site-level default for all courses unless overridden.';

$string['settings_prompt_locked']           = 'Lock prompt';
$string['settings_prompt_locked_desc']      = 'When enabled, teachers, course creators, and managers can view the prompt but cannot edit it at course or forum level. The prompt editor will be read-only for all roles below Site Administrator.';

$string['settings_prompt_reset_default']    = 'Reset to plugin default';
$string['settings_prompt_reset_default_desc'] = 'Restore the site-level prompt to the version shipped with this plugin (LID Session Analyzer v1.0). This cannot be undone.';

// ---------------------------------------------------------------------------
// Admin settings — trigger modes
// ---------------------------------------------------------------------------

$string['settings_trigger_heading']        = 'When to run analysis';

$string['settings_trigger_async']          = 'Immediate (async)';
$string['settings_trigger_async_desc']     = 'Queue a post for analysis as soon as it is submitted. The analysis runs at the next scheduled task execution.';

$string['settings_trigger_cron']           = 'Scheduled (cron)';
$string['settings_trigger_cron_desc']      = 'Process pending analyses on a regular schedule. At least one trigger mode must be enabled.';

$string['settings_trigger_manual']         = 'Manual (teacher request)';
$string['settings_trigger_manual_desc']    = 'Allow teachers, managers, and course creators to manually trigger analysis from the forum or dashboard UI.';

$string['settings_cron_interval']          = 'Cron interval (minutes)';
$string['settings_cron_interval_desc']     = 'How often the analysis queue is drained, in minutes. Minimum: 1 (every minute, for high-volume environments). Maximum: 1440 (once per day). Default: 5. This directly sets the scheduled task frequency.';

$string['settings_cron_batchsize']         = 'Max items per cron run';
$string['settings_cron_batchsize_desc']    = 'Maximum number of posts analysed per scheduled task execution. Limits LLM API call volume per run. Default: 10.';

// ---------------------------------------------------------------------------
// Prompt editor UI
// ---------------------------------------------------------------------------

$string['prompt_editor_label']        = 'Prompt template';
$string['prompt_editor_placeholder']  = 'Paste your prompt here, or upload a .md file below...';
$string['prompt_upload_label']        = 'Upload prompt file (.md)';
$string['prompt_upload_desc']         = 'Upload a Markdown file to replace the prompt above. The file contents will be loaded into the editor. Only .md files are accepted.';
$string['prompt_locked_notice']       = 'The prompt template has been locked by the site administrator. You can view it here but cannot make changes.';
$string['prompt_reset_course']        = 'Reset to site default';
$string['prompt_reset_plugin']        = 'Reset to plugin default';
$string['prompt_reset_confirm']       = 'Are you sure? This will replace the current prompt and cannot be undone.';
$string['prompt_saved']               = 'Prompt saved successfully.';
$string['prompt_chars']               = '{$a} characters';

// ---------------------------------------------------------------------------
// Forum configuration UI
// ---------------------------------------------------------------------------

$string['forum_config_heading']       = 'Learning Intelligence Dashboard';
$string['forum_config_enabled']       = 'Enable LID analysis';
$string['forum_config_enabled_desc']  = 'When enabled, posts in this forum will be queued for Learning Intelligence analysis. Disable to exclude this forum from all LID dashboards.';
$string['forum_config_prompt']        = 'Forum-level prompt override';
$string['forum_config_prompt_desc']   = 'Optionally override the prompt for this forum only. Leave blank to use the course or site-level prompt. Only available when the site administrator has not locked the prompt.';
$string['forum_config_saved']         = 'Forum LID settings saved.';
$string['forum_disabled_notice']      = 'LID analysis is disabled for this forum. No analysis will be performed and no dashboard will be displayed.';

// ---------------------------------------------------------------------------
// Dashboard — shared labels
// ---------------------------------------------------------------------------

$string['dashboard_competencies']        = 'Competencies';
$string['dashboard_blooms']              = 'Bloom\'s progression';
$string['dashboard_roi']                 = 'ROI & value';
$string['dashboard_employer_value']      = 'Employer value';
$string['dashboard_timeline']            = 'Learning timeline';
$string['dashboard_portfolio']           = 'Portfolio documentation';
$string['dashboard_radar']               = 'Competency radar';
$string['dashboard_nodata']              = 'No analysis data available yet.';
$string['dashboard_stale_notice']        = 'One or more analyses were produced with a different prompt version. Consider re-running analysis for up-to-date results.';
$string['dashboard_last_updated']        = 'Last updated: {$a}';

// ---------------------------------------------------------------------------
// Dashboard — Course LID
// ---------------------------------------------------------------------------

$string['dashboard_course_title']        = 'Course Learning Intelligence Dashboard';
$string['dashboard_course_allfora']      = 'All forums (aggregate)';
$string['dashboard_course_noforums']     = 'No LID-enabled forums found in this course. Enable LID analysis on one or more forum activities to see data here.';

// ---------------------------------------------------------------------------
// Dashboard — Forum LID
// ---------------------------------------------------------------------------

$string['dashboard_forum_title']         = 'Forum Learning Intelligence Dashboard';
$string['dashboard_forum_aggregate']     = 'Forum aggregate';
$string['dashboard_forum_students']      = 'Student contributions';
$string['dashboard_forum_noposts']       = 'No analysed posts found for this forum.';
$string['dashboard_forum_viewstudent']   = 'View student LID';
$string['dashboard_forum_reanalyse']     = 'Re-analyse';
$string['dashboard_forum_reanalyse_all'] = 'Re-analyse all posts';

// ---------------------------------------------------------------------------
// Dashboard — Student LID
// ---------------------------------------------------------------------------

$string['dashboard_student_title']       = 'Student Learning Intelligence Dashboard';
$string['dashboard_student_allfora']     = 'All forums (aggregate)';
$string['dashboard_student_noposts']     = 'No analysed posts found for this student in LID-enabled forums.';
$string['dashboard_student_viewpost']    = 'View post analysis';

// ---------------------------------------------------------------------------
// Analysis status labels
// ---------------------------------------------------------------------------

$string['status_pending']    = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_complete']   = 'Complete';
$string['status_error']      = 'Error';
$string['status_disabled']   = 'Disabled';

// ---------------------------------------------------------------------------
// Manual trigger UI
// ---------------------------------------------------------------------------

$string['trigger_analyse_post']    = 'Analyse this post';
$string['trigger_analyse_forum']   = 'Analyse this forum';
$string['trigger_queued']          = 'Analysis queued. Results will appear shortly.';
$string['trigger_already_queued']  = 'This item is already queued for analysis.';

// ---------------------------------------------------------------------------
// Error messages
// ---------------------------------------------------------------------------

$string['error_llm_endpoint_missing']  = 'LLM API endpoint is not configured. Please contact your site administrator.';
$string['error_llm_apikey_missing']    = 'LLM API key is not configured. Please contact your site administrator.';
$string['error_llm_request_failed']    = 'LLM API request failed: {$a}';
$string['error_llm_invalid_json']      = 'The LLM returned a response that could not be parsed as valid LID JSON. The raw response has been logged.';
$string['error_llm_schema_mismatch']   = 'The LLM response did not conform to LID Schema v{$a}. Required fields may be missing or malformed.';
$string['error_llm_truncated']         = 'The LLM response appears to have been truncated before the JSON was complete. Try increasing the max tokens setting.';
$string['error_forum_not_found']       = 'Forum not found or you do not have permission to access it.';
$string['error_nopermission']          = 'You do not have permission to perform this action.';
$string['error_upload_invalid_type']   = 'Only .md files are accepted for prompt upload.';

// ---------------------------------------------------------------------------
// Scheduled task
// ---------------------------------------------------------------------------

$string['task_process_queue'] = 'Process Learning Intelligence analysis queue';

// ---------------------------------------------------------------------------
// Privacy / GDPR
// ---------------------------------------------------------------------------

$string['privacy_metadata_local_lid_analysis']             = 'Stores LID analysis results derived from forum post content submitted by the user.';
$string['privacy_metadata_local_lid_analysis_userid']      = 'The Moodle user ID of the post author whose content was analysed.';
$string['privacy_metadata_local_lid_analysis_json']        = 'The structured LID JSON output produced by the LLM from the user\'s post content.';
$string['privacy_metadata_local_lid_analysis_postid']      = 'The ID of the forum post that was analysed.';
$string['privacy_metadata_local_lid_analysis_timecreated'] = 'The time the analysis record was created.';
$string['privacy_metadata_llm_api']                        = 'Post content is sent to a third-party LLM API for analysis. The data sent includes the text of forum posts authored by the user. No data is retained by this plugin beyond the structured JSON output. Institutions are responsible for the data processing terms of their chosen LLM provider.';

// ---------------------------------------------------------------------------
// Capability strings — required by Moodle's permissions/roles UI
// Key format: 'local/lid:capabilityname' → $string['lid:capabilityname']
// ---------------------------------------------------------------------------

$string['lid:managesitesettings']    = 'Manage Learning Intelligence Dashboard site settings';
$string['lid:viewcoursedashboard']   = 'View the course-level Learning Intelligence Dashboard';
$string['lid:viewforumdashboard']    = 'View the forum-level Learning Intelligence Dashboard';
$string['lid:viewstudentdashboard']  = 'View the student-level Learning Intelligence Dashboard';
$string['lid:configureforum']        = 'Enable or disable LID analysis for a forum';
$string['lid:editprompt']            = 'Edit the LID session analyzer prompt template';
$string['lid:triggeranalysis']       = 'Manually trigger LID analysis for posts or forums';
// ---------------------------------------------------------------------------

$string['settings_heading_enablement']         = 'LID enablement defaults';
$string['settings_lid_default_enabled']        = 'Enable LID by default for new forums';
$string['settings_lid_default_enabled_desc']   = 'When enabled, LID analysis will be active for all newly created forums by default. Teachers can override this per forum. When disabled (default), teachers must explicitly enable LID on each forum they want to analyse.';
$string['settings_lid_force_disabled']         = 'Force disable LID site-wide';
$string['settings_lid_force_disabled_desc']    = 'When enabled, LID analysis is disabled across the entire site regardless of course or forum settings. No teacher can enable LID while this is on. Use for cost control, security review periods, or scheduled maintenance. Existing analysis data is preserved and will reappear when this is turned off.';
$string['settings_lid_force_disabled_warning'] = 'LID analysis is currently force-disabled by the site administrator. No analyses will run until this is lifted.';

// ---------------------------------------------------------------------------
// Three-tier enable/disable hierarchy — course level
// ---------------------------------------------------------------------------

$string['course_settings_title']               = 'Learning Intelligence — Course Settings';
$string['course_settings_heading']             = 'Course-level LID configuration';
$string['course_settings_enable_all']          = 'Enable LID for all forums in this course';
$string['course_settings_disable_all']         = 'Disable LID for all forums in this course';
$string['course_settings_enable_all_desc']     = 'Immediately enables LID analysis for every forum in this course. Individual forums can still be disabled afterwards via their own settings.';
$string['course_settings_disable_all_desc']    = 'Immediately disables LID analysis for every forum in this course. Existing analysis data is preserved and will reappear if LID is re-enabled.';
$string['course_settings_status']              = 'Current status';
$string['course_settings_forums_enabled']      = '{$a} forum(s) currently have LID enabled in this course.';
$string['course_settings_forums_total']        = '{$a} forum(s) total in this course.';
$string['course_settings_saved']              = 'Course LID settings saved. {$a} forum(s) updated.';
$string['course_settings_force_disabled']      = 'LID is currently force-disabled at the site level. Contact your site administrator to enable it.';
$string['nav_coursesettings']                  = 'Learning Intelligence settings';

// ---------------------------------------------------------------------------
// Three-tier enable/disable hierarchy — forum level (Edit Settings injection)
// ---------------------------------------------------------------------------

$string['forum_lid_section']                   = 'Learning Intelligence Dashboard';
$string['forum_lid_enabled_label']             = 'Enable LID analysis';
$string['forum_lid_enabled_label_help']        = 'When enabled, student posts in this forum will be analysed by the Learning Intelligence system. Scores, competency maps, and Bloom\'s Taxonomy progression will be available to instructors in the LID dashboard. Disable for announcement forums, community forums, or any forum where structured academic analysis is not appropriate.';
$string['forum_lid_enabled_help']              = 'When enabled, student posts in this forum will be analysed by the Learning Intelligence system. Scores, competency maps, and Bloom\'s Taxonomy progression will be available to instructors in the LID dashboard. Disable for announcement forums, community forums, or any forum where structured academic analysis is not appropriate.';
$string['forum_lid_force_disabled_notice']     = 'LID analysis is currently disabled site-wide by your administrator and cannot be enabled here.';
$string['forum_lid_saved']                     = 'Forum LID setting saved.';

// ---------------------------------------------------------------------------
// Visibility notices (data preserved but hidden)
// ---------------------------------------------------------------------------

$string['lid_hidden_force_disabled']           = 'Learning Intelligence analysis is currently disabled by the site administrator. Existing data is preserved and will reappear when analysis is re-enabled.';
$string['lid_hidden_course_disabled']          = 'Learning Intelligence analysis is currently disabled for this course. Existing data is preserved and will reappear if re-enabled.';
$string['lid_hidden_forum_disabled']           = 'Learning Intelligence analysis is disabled for this forum. Enable it in the forum settings to view the dashboard.';
