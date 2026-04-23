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
 *   notification_*      — messaging/email notification strings
 *   discussion_model_*  — participation model selector strings
 *   competency_*        — competency integration strings
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
$string['settings_llm_model_desc']       = 'The model identifier passed in API requests. Example: <code>gemini-2.5-flash</code>';

$string['settings_llm_maxtokens']        = 'Max tokens';
$string['settings_llm_maxtokens_desc']   = 'Maximum output tokens requested per API call. This value affects the tokens used in a single API call to evaluate a single student. A lower value will result in more failed API calls; failed calls are automatically retried using double this value. Use models with a maximum output token ceiling of 65,000 or more. Token usage is influenced by the number of threads, posts, and students in the forum; post length; course competencies included in the prompt; and prompt length. Default: 16384.';

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
$string['settings_cron_batchsize_desc']    = 'Maximum number of analyses processed per scheduled task execution. Limits LLM API call volume per run. Default: 10.';

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
$string['forum_config_enabled_desc']  = 'When enabled, learner participation in this forum will be assessed by the Learning Intelligence system after the discussion closes. Disable to exclude this forum from all LID dashboards.';
$string['forum_config_prompt']        = 'Forum-level prompt override';
$string['forum_config_prompt_desc']   = 'Optionally override the session analyzer prompt for this forum only. Leave blank to use the course or site-level prompt. Only available when the site administrator has not locked the prompt. Note: the forum discussion analyzer prompt is not overridable — it is the fixed assessment instrument for closed discussions.';
$string['forum_config_saved']         = 'Forum LID settings saved.';
$string['forum_disabled_notice']      = 'LID analysis is disabled for this forum. No analysis will be performed and no dashboard will be displayed.';

// ---------------------------------------------------------------------------
// Forum configuration — discussion model selector
// ---------------------------------------------------------------------------

$string['forum_config_discussion_model']      = 'Discussion participation model';
$string['forum_config_discussion_model_desc'] = 'Select the participation model that best describes how this forum discussion is structured. This determines which Critical Discourse assessment rubric the LID system applies when evaluating learner contributions. Choose carefully — the wrong model will produce inaccurate scores.';

// Help button text — displayed in the Moodle form help popup.
// Required by lib.php: $mform->addHelpButton('local_lid_discussion_model',
//                          'forum_config_discussion_model', 'local_lid')
// Moodle looks up $string['forum_config_discussion_model_help'] for the popup body.
$string['forum_config_discussion_model_help'] = 'The discussion participation model tells the Learning Intelligence system how to interpret learner contributions in this forum.

<strong>Independent First</strong> — learners post their own response before seeing peers. The original contribution is weighted heavily. Use when the forum hides posts until the learner has submitted their own.

<strong>Open Engagement</strong> — learners can read all posts before contributing. Peer-directed discourse, synthesis, and constructive challenge are the primary expected behaviours. Use for standard discussion forums.

<strong>Structured Debate</strong> — learners argue assigned or chosen positions. Assessment focuses on advocacy, counterargument, evidence quality, and position defence. Use when the curriculum assigns debate roles or requires position papers.

Choosing the wrong model will produce inaccurate scores. If you are unsure, use Open Engagement.';

$string['discussion_model_independent_first']       = 'Independent First';
$string['discussion_model_independent_first_desc']  = 'Learners post their own original response before seeing peers. Assessment weights the original contribution heavily. Peer replies are engagement evidence but secondary to independent reasoning. Use when the forum is configured to hide posts until learners have submitted their own.';

$string['discussion_model_open_engagement']         = 'Open Engagement';
$string['discussion_model_open_engagement_desc']    = 'Learners can read all posts before contributing. Peer-directed critical discourse, synthesis, and constructive challenge are the primary expected behaviours. A learner who only responds to the instructor prompt without engaging peers is missing the instructional intent. Use for standard discussion forums.';

$string['discussion_model_structured_debate']       = 'Structured Debate';
$string['discussion_model_structured_debate_desc']  = 'Learners argue assigned or chosen positions. Assessment focuses on advocacy, counterargument, evidence quality, and position defence. Maintaining a well-reasoned position under challenge is a strength; revising a position where evidence genuinely warrants it is intellectual integrity. Use when the curriculum assigns debate roles or requires position papers.';

// ---------------------------------------------------------------------------
// Forum configuration — competency selector
// ---------------------------------------------------------------------------

$string['forum_config_competencies']              = 'Course competencies';
$string['forum_config_competencies_heading']      = 'Competency evaluation';
$string['forum_config_competencies_desc']         = 'Select which course competencies the LLM should evaluate learners against in this forum. When none are selected, all course competencies are used (inherited from the course setting). To explicitly exclude all competencies for this forum, check "Exclude all competencies".';
$string['forum_config_competencies_exclude_all']  = 'Exclude all competencies for this forum';
$string['forum_config_competencies_exclude_all_desc'] = 'When checked, no course competencies will be included in the LLM prompt for this forum, even if competency evaluation is enabled at the course level.';
$string['forum_config_competencies_inherit']      = 'Inherit from course (all competencies)';
$string['forum_config_competencies_specific']     = 'Evaluate specific competencies only';
$string['forum_config_competencies_none_in_course'] = 'No competencies are linked to this course. Add competencies to the course via the Competencies tab before enabling competency evaluation.';
$string['forum_config_competencies_disabled']     = 'Competency evaluation is disabled at the course level. Enable it in the course LID settings to configure per-forum competency selection.';
$string['forum_config_competencies_site_disabled'] = 'Competencies are not enabled on this Moodle site. A site administrator must enable competencies in Site Administration → Competencies before this feature can be used.';
$string['forum_config_competencies_saved']        = 'Forum competency settings saved.';

// ---------------------------------------------------------------------------
// Forum LID dashboard — analysis trigger and status
// ---------------------------------------------------------------------------

$string['dashboard_forum_title']              = 'Forum Learning Intelligence Dashboard';
$string['dashboard_forum_aggregate']          = 'Forum aggregate';
$string['dashboard_forum_learners']           = 'Learner contributions';
$string['dashboard_forum_noposts']            = 'No analysis data found for this forum. Analysis runs automatically when the forum discussion closes.';
$string['dashboard_forum_viewlearner']        = 'View learner LID';
$string['dashboard_forum_reanalyse']          = 'Re-analyse';
$string['dashboard_forum_reanalyse_all']      = 'Run LID Analysis';
$string['dashboard_forum_discussion_model']   = 'Assessment model: {$a}';

$string['dashboard_forum_analysis_pending']   = 'Analysis is currently running. Refresh this page in a few minutes to see results.';
$string['dashboard_forum_stale_notice']       = 'One or more learners have posted since the last analysis ran. Re-run LID Analysis to include their latest contributions.';
$string['dashboard_forum_lid_guidance']       = 'LID analysis runs automatically when the forum closes. A forum is considered closed when: the cut-off date passes, discussions are locked after a period of inactivity, or all discussions are manually locked. Show/hide of the forum activity does not trigger analysis.';

$string['dashboard_forum_thread_heading']     = 'Discussion threads';
$string['dashboard_forum_thread_pending']     = 'Thread analysis pending.';

$string['dashboard_forum_owndata_notice']     = 'You are viewing the forum aggregate and your own Learning Intelligence analysis. Individual results for other learners are not visible to you.';

// ---------------------------------------------------------------------------
// Dashboard — shared labels
// ---------------------------------------------------------------------------

$string['dashboard_competencies']           = 'Competencies';
$string['dashboard_blooms']                 = 'Bloom\'s progression';
$string['dashboard_roi']                    = 'Return on investment';
$string['dashboard_discussion_value']       = 'Discussion value';
$string['dashboard_dci']                    = 'Discussion Contribution Index';
$string['dashboard_retention_indicators']   = 'Retention indicators';
$string['dashboard_instructor_notes']       = 'Assessment notes';
$string['dashboard_employer_value']         = 'Employer value';
$string['dashboard_portfolio']              = 'Portfolio documentation';
$string['dashboard_timeline']               = 'Participation timeline';
$string['dashboard_radar']                  = 'Competency radar';
$string['dashboard_nodata']                 = 'No analysis data available yet.';
$string['dashboard_stale_notice']           = 'One or more analyses were produced with a different prompt version. Consider re-running analysis for up-to-date results.';
$string['dashboard_last_updated']           = 'Last updated: {$a}';

// ---------------------------------------------------------------------------
// Dashboard — Course LID
// ---------------------------------------------------------------------------

$string['dashboard_course_title']        = 'Course Learning Intelligence Dashboard';
$string['dashboard_course_allfora']      = 'All forums (aggregate)';
$string['dashboard_course_noforums']     = 'No LID-enabled forums found in this course. Enable LID analysis on one or more forum activities to see data here.';

// ---------------------------------------------------------------------------
// Dashboard — Student LID
// ---------------------------------------------------------------------------

$string['dashboard_student_title']       = 'Learner Learning Intelligence Dashboard';
$string['dashboard_student_allfora']     = 'All forums (aggregate)';
$string['dashboard_student_noposts']     = 'No analysed contributions found for this learner in LID-enabled forums.';
$string['dashboard_student_viewpost']    = 'View analysis';

// ---------------------------------------------------------------------------
// Analysis status labels
// ---------------------------------------------------------------------------

$string['status_pending']    = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_complete']   = 'Complete';
$string['status_error']      = 'Error';
$string['status_stale']      = 'New posts since last analysis';
$string['status_disabled']   = 'Disabled';

// ---------------------------------------------------------------------------
// Manual trigger UI
// ---------------------------------------------------------------------------

$string['trigger_analyse_forum']   = 'Run LID Analysis';
$string['trigger_queued']          = 'Analysis queued. Results will appear when the forum is fully assessed.';
$string['trigger_already_queued']  = 'Analysis is already queued for this forum.';
$string['trigger_no_posts']        = 'No learner posts found in this forum. There is nothing to analyse.';

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
// Completion notifications (sent via Moodle messaging — message_send())
// Requires db/messages.php to register the 'analysis_complete' message type.
//
// Placeholders:
//   {$a->forum}   — forum name
//   {$a->course}  — course full name
//   {$a->shortname} — course short name (used in subject only)
//   {$a->count}   — number of learners analysed
//   {$a->url}     — URL to the Forum LID dashboard
// ---------------------------------------------------------------------------

$string['notification_complete_subject'] = 'LID analysis complete — {$a->forum} ({$a->shortname})';

$string['notification_complete_body']    = 'Learning Intelligence analysis has completed for the forum "{$a->forum}" in {$a->course}.

{$a->count} learner(s) have been assessed. You can view the results in the Forum LID dashboard:

{$a->url}

This notification was sent because you have the "View forum Learning Intelligence Dashboard" permission for this course. You can adjust your notification preferences in your Moodle profile.';

$string['notification_complete_body_html'] = '<p>Learning Intelligence analysis has completed for the forum <strong>{$a->forum}</strong> in <em>{$a->course}</em>.</p>
<p><strong>{$a->count}</strong> learner(s) have been assessed.</p>
<p><a href="{$a->url}">View the Forum LID Dashboard</a></p>
<p style="color:#666;font-size:12px;">This notification was sent because you have the "View forum Learning Intelligence Dashboard" permission for this course. You can adjust your notification preferences in your Moodle profile.</p>';

$string['notification_complete_small']   = 'LID analysis complete for {$a->forum} — {$a->count} learner(s) assessed.';

$string['notification_complete_urlname'] = 'Forum LID Dashboard';

// ---------------------------------------------------------------------------
// Privacy / GDPR
// ---------------------------------------------------------------------------

$string['privacy_metadata_local_lid_analysis']             = 'Stores LID analysis results derived from forum participation by the learner.';
$string['privacy_metadata_local_lid_analysis_userid']      = 'The Moodle user ID of the learner whose forum participation was analysed.';
$string['privacy_metadata_local_lid_analysis_json']        = 'The structured LID JSON output produced by the LLM from the learner\'s forum posts.';
$string['privacy_metadata_local_lid_analysis_postid']      = 'The ID of the forum post that was analysed (legacy post-scope rows only).';
$string['privacy_metadata_local_lid_analysis_timecreated'] = 'The time the analysis record was created.';
$string['privacy_metadata_llm_api']                        = 'Forum post content is sent to a third-party LLM API for analysis. The data sent includes the text of forum posts authored by the learner. No data is retained by this plugin beyond the structured JSON output. Institutions are responsible for the data processing terms of their chosen LLM provider.';

// ---------------------------------------------------------------------------
// Capability strings — required by Moodle's permissions/roles UI
// Key format: 'local/lid:capabilityname' → $string['lid:capabilityname']
// ---------------------------------------------------------------------------

$string['lid:managesitesettings']    = 'Manage Learning Intelligence Dashboard site settings';
$string['lid:viewcoursedashboard']   = 'View the course-level Learning Intelligence Dashboard';
$string['lid:viewforumdashboard']    = 'View the forum-level Learning Intelligence Dashboard';
$string['lid:viewpeeranalysis']      = 'View other learners\' individual LID analysis on the forum dashboard';
$string['lid:viewstudentdashboard']  = 'View the learner-level Learning Intelligence Dashboard';
$string['lid:configureforum']        = 'Enable or disable LID analysis for a forum';
$string['lid:editprompt']            = 'Edit the LID session analyzer prompt template';
$string['lid:triggeranalysis']       = 'Manually trigger LID analysis for a forum';

// ---------------------------------------------------------------------------
// Three-tier enable/disable hierarchy — site level
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
$string['course_settings_saved']               = 'Course LID settings saved. {$a} forum(s) updated.';
$string['course_settings_force_disabled']      = 'LID is currently force-disabled at the site level. Contact your site administrator to enable it.';
$string['nav_coursesettings']                  = 'Learning Intelligence settings';

// ---------------------------------------------------------------------------
// Three-tier enable/disable hierarchy — forum level (Edit Settings injection)
// ---------------------------------------------------------------------------

$string['forum_lid_section']                   = 'Learning Intelligence Dashboard';
$string['forum_lid_enabled_label']             = 'Enable LID analysis';
$string['forum_lid_enabled_label_help']        = 'When enabled, learner participation in this forum will be assessed by the Learning Intelligence system after the discussion closes. Disable for announcement forums, community forums, or any forum where structured academic analysis is not appropriate.';
$string['forum_lid_enabled_help']              = 'When enabled, learner participation in this forum will be assessed by the Learning Intelligence system after the discussion closes. Disable for announcement forums, community forums, or any forum where structured academic analysis is not appropriate.';
$string['forum_lid_force_disabled_notice']     = 'LID analysis is currently disabled site-wide by your administrator and cannot be enabled here.';
$string['forum_lid_saved']                     = 'Forum LID setting saved.';

// ---------------------------------------------------------------------------
// Visibility notices (data preserved but hidden)
// ---------------------------------------------------------------------------

$string['lid_hidden_force_disabled']           = 'Learning Intelligence analysis is currently disabled by the site administrator. Existing data is preserved and will reappear when analysis is re-enabled.';
$string['lid_hidden_course_disabled']          = 'Learning Intelligence analysis is currently disabled for this course. Existing data is preserved and will reappear if re-enabled.';
$string['lid_hidden_forum_disabled']           = 'Learning Intelligence analysis is disabled for this forum. Enable it in the forum settings to view the dashboard.';

// ---------------------------------------------------------------------------
// Competency integration — site level
// ---------------------------------------------------------------------------

$string['settings_competencies_enabled_default']      = 'Enable competency evaluation by default';
$string['settings_competencies_enabled_default_desc'] = 'When enabled, competency evaluation against Moodle course competencies will be active by default for new courses. Instructors can override this per course. Requires the Moodle competency subsystem to be enabled at the site level (Site Administration → Competencies). When disabled (default), instructors must explicitly enable competency evaluation in their course LID settings.';

// ---------------------------------------------------------------------------
// Competency integration — course level
// ---------------------------------------------------------------------------

$string['competency_course_heading']              = 'Competency evaluation';
$string['competency_course_enabled']              = 'Evaluate against course competencies';
$string['competency_course_enabled_desc']         = 'When enabled, the LLM will evaluate learner forum participation against the competencies linked to this course, in addition to the standard LID rubrics. Competencies are injected into the assessment prompt so the LLM can identify evidence of each competency in the learner\'s posts.';
$string['competency_course_no_competencies']      = 'No competencies are linked to this course. Add competencies via the course Competencies tab before enabling this feature.';
$string['competency_course_site_disabled']        = 'The Moodle competency subsystem is not enabled on this site. A site administrator must enable it in Site Administration → Competencies before competency evaluation can be used.';
$string['competency_course_saved']                = 'Course competency settings saved.';
$string['competency_course_count']                = '{$a} competency/competencies linked to this course.';

// ---------------------------------------------------------------------------
// Competency integration — prompt context
// ---------------------------------------------------------------------------

$string['competency_prompt_header']               = 'COURSE COMPETENCIES (evaluate the learner against these in addition to standard rubrics):';

// ---------------------------------------------------------------------------
// Course settings — read-only prompt display
// ---------------------------------------------------------------------------

$string['course_settings_prompt_heading']         = 'Assessment prompt';
$string['course_settings_prompt_readonly_desc']   = 'This is the site-level assessment prompt that the LLM uses to evaluate learner contributions. It is set by the site administrator and cannot be modified at the course level. It is shown here for instructor awareness.';

// ---------------------------------------------------------------------------
// Message provider labels
// Required by Moodle's messaging system — displayed in each user's
// notification preferences UI (Profile → Preferences → Notification preferences).
// Key format must be exactly: messageprovider:<providername>
// where <providername> matches the key defined in db/messages.php.
// ---------------------------------------------------------------------------

$string['messageprovider:analysis_complete'] = 'LID analysis completion notifications';

// ---------------------------------------------------------------------------
// Admin settings - Native theme toggle
// ---------------------------------------------------------------------------

$string['setting_use_native_theme'] = 'Use Moodle native theme for dashboards';
$string['setting_use_native_theme_desc'] = 'When enabled, LID dashboards will render using your site\'s active Moodle theme (Bootstrap classes). When disabled, LID uses its custom futuristic styling with dark theme and accent colors. Default: disabled (futuristic mode).';
