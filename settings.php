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
 * Admin settings page for local_lid.
 *
 * Registered under Site Administration → Plugins → Local plugins →
 * Learning Intelligence Dashboard.
 *
 * Settings are stored in the standard Moodle config_plugins table under
 * the 'local_lid' plugin name via get_config() / set_config(), EXCEPT for
 * the prompt_template which is stored in local_lid_settings (courseid = 0)
 * because it is a large text field and may be course-overridden.
 *
 * Section order:
 *   1. LID enablement defaults (force disable, default enabled, competencies default)
 *   2. LLM API connection
 *   3. Prompt template
 *   4. Analysis triggers and cron schedule
 *
 * @package    local_lid
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // -------------------------------------------------------------------------
    // Register the settings page in the admin tree.
    // -------------------------------------------------------------------------

    $settings = new admin_settingpage(
        'local_lid',
        new lang_string('pluginname', 'local_lid')
    );

    $ADMIN->add('localplugins', $settings);

    // =========================================================================
    // SECTION 1 — LID Enablement defaults
    // =========================================================================

    $settings->add(new admin_setting_heading(
        'local_lid/heading_enablement',
        new lang_string('settings_heading_enablement', 'local_lid'),
        ''
    ));

    // Force disable — hard stop, no teacher override.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/lid_force_disabled',
        new lang_string('settings_lid_force_disabled', 'local_lid'),
        new lang_string('settings_lid_force_disabled_desc', 'local_lid'),
        '0'
    ));

    // Global default for new forums.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/lid_default_enabled',
        new lang_string('settings_lid_default_enabled', 'local_lid'),
        new lang_string('settings_lid_default_enabled_desc', 'local_lid'),
        '0'
    ));

    // Competencies enabled by default for new courses.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/competencies_enabled_default',
        new lang_string('settings_competencies_enabled_default', 'local_lid'),
        new lang_string('settings_competencies_enabled_default_desc', 'local_lid'),
        '0'
    ));

    // =========================================================================
    // SECTION 2 — LLM API connection
    // =========================================================================

    $settings->add(new admin_setting_heading(
        'local_lid/heading_llm',
        new lang_string('settings_heading_llm', 'local_lid'),
        ''
    ));

    // LLM endpoint URL.
    $settings->add(new admin_setting_configtext(
        'local_lid/llm_endpoint',
        new lang_string('settings_llm_endpoint', 'local_lid'),
        new lang_string('settings_llm_endpoint_desc', 'local_lid'),
        '',           // Default: empty — admin must supply.
        PARAM_URL,
        60            // Input size (display width).
    ));

    // API key — stored as a password field so it is masked in the UI.
    // We do NOT use admin_setting_configpasswordunmask because we want to
    // encrypt the value at rest using Moodle's encrypt_to_db helper.
    // The actual encryption/decryption is handled in classes/llm/client.php.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_lid/llm_apikey',
        new lang_string('settings_llm_apikey', 'local_lid'),
        new lang_string('settings_llm_apikey_desc', 'local_lid'),
        ''            // Default: empty.
    ));

    // Model string.
    $settings->add(new admin_setting_configtext(
        'local_lid/llm_model',
        new lang_string('settings_llm_model', 'local_lid'),
        new lang_string('settings_llm_model_desc', 'local_lid'),
        'gemini-2.5-flash',
        PARAM_TEXT,
        40
    ));

    // Max tokens.
    // Default: 16384 — recommended baseline for peer discussion forum analysis.
    // Failed calls are automatically retried at double this value; ensure
    // llm_maxtokens × 2 does not exceed the model's output token ceiling.
    $settings->add(new admin_setting_configtext(
        'local_lid/llm_maxtokens',
        new lang_string('settings_llm_maxtokens', 'local_lid'),
        new lang_string('settings_llm_maxtokens_desc', 'local_lid'),
        '16384',
        PARAM_INT,
        10
    ));

    // HTTP timeout.
    $settings->add(new admin_setting_configtext(
        'local_lid/llm_timeout',
        new lang_string('settings_llm_timeout', 'local_lid'),
        new lang_string('settings_llm_timeout_desc', 'local_lid'),
        '60',
        PARAM_INT,
        6
    ));

    // =========================================================================
    // SECTION 3 — Prompt template
    // =========================================================================

    $settings->add(new admin_setting_heading(
        'local_lid/heading_prompt',
        new lang_string('settings_heading_prompt', 'local_lid'),
        ''
    ));

    // Prompt template — large textarea.
    // The default value is loaded from prompts/default-session-analyzer.md
    // at install time by the install hook in lib.php. Here we set an empty
    // default so Moodle's config system does not overwrite the DB value on
    // every admin page load.
    //
    // The frontend enhancement (file upload, character count, dirty flag) is
    // added by amd/src/prompt_editor.js which targets the textarea rendered
    // by this setting. The AMD module is loaded via lib.php page_init hook.
    $settings->add(new admin_setting_configtextarea(
        'local_lid/prompt_template',
        new lang_string('settings_prompt_template', 'local_lid'),
        new lang_string('settings_prompt_template_desc', 'local_lid'),
        '',           // Default empty; seeded from file on install.
        PARAM_RAW,    // Raw — the prompt may contain special characters, JSON, etc.
        80,           // Cols.
        20            // Rows.
    ));

    // Prompt lock — prevents course/forum level editing.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/prompt_locked',
        new lang_string('settings_prompt_locked', 'local_lid'),
        new lang_string('settings_prompt_locked_desc', 'local_lid'),
        '0'           // Default: unlocked.
    ));

    // =========================================================================
    // SECTION 4 — Analysis triggers and scheduling
    // =========================================================================

    $settings->add(new admin_setting_heading(
        'local_lid/heading_triggers',
        new lang_string('settings_heading_triggers', 'local_lid'),
        ''
    ));

    // Async trigger (queue on post submission).
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/trigger_async',
        new lang_string('settings_trigger_async', 'local_lid'),
        new lang_string('settings_trigger_async_desc', 'local_lid'),
        '1'           // Default: enabled.
    ));

    // Cron trigger.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/trigger_cron',
        new lang_string('settings_trigger_cron', 'local_lid'),
        new lang_string('settings_trigger_cron_desc', 'local_lid'),
        '1'           // Default: enabled.
    ));

    // Manual trigger.
    $settings->add(new admin_setting_configcheckbox(
        'local_lid/trigger_manual',
        new lang_string('settings_trigger_manual', 'local_lid'),
        new lang_string('settings_trigger_manual_desc', 'local_lid'),
        '1'           // Default: enabled.
    ));

    // Cron interval — updates the scheduled task frequency when saved.
    // The after_write callback is handled in lib.php local_lid_after_config_change().
    $settings->add(new admin_setting_configtext(
        'local_lid/cron_interval',
        new lang_string('settings_cron_interval', 'local_lid'),
        new lang_string('settings_cron_interval_desc', 'local_lid'),
        '5',
        PARAM_INT,
        6
    ));

    // Cron batch size.
    $settings->add(new admin_setting_configtext(
        'local_lid/cron_batchsize',
        new lang_string('settings_cron_batchsize', 'local_lid'),
        new lang_string('settings_cron_batchsize_desc', 'local_lid'),
        '10',
        PARAM_INT,
        6
    ));
}
