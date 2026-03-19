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
 * Forum configuration AMD module for local_lid.
 *
 * Handles the per-forum LID enable/disable toggle and the optional
 * forum-level prompt override editor that appears in the forum settings
 * area (injected via the forum_config.mustache template, rendered into
 * the forum's mod_edit.php settings page or a dedicated settings tab).
 *
 * Features:
 *   Toggle switch   — checkbox or toggle that enables/disables LID for the forum.
 *                     State is saved immediately via AJAX on change.
 *   Status badge    — small badge next to the forum name in course listings
 *                     showing LID enabled/disabled state.
 *   Prompt override — if prompt is not locked, shows the prompt_editor
 *                     inline for forum-level customisation. The prompt_editor
 *                     AMD module handles the actual textarea interactions;
 *                     this module only controls whether the override section
 *                     is visible based on the toggle state.
 *   Confirmation    — when disabling an already-enabled forum that has
 *                     existing analyses, shows a warning that existing data
 *                     will not be deleted but no new analyses will run.
 *
 * Expected DOM structure (rendered by forum_config.mustache):
 *
 *   <div class="lid-forum-config"
 *        data-forumid="N"
 *        data-courseid="N"
 *        data-enabled="0|1"
 *        data-url="...ajax.php...">
 *
 *     <label class="lid-toggle-label">
 *       <input type="checkbox" class="lid-forum-enable-toggle" checked?>
 *       <span class="lid-toggle-track">...</span>
 *       Enable Learning Intelligence analysis for this forum
 *     </label>
 *
 *     <div class="lid-forum-config-status"></div>
 *
 *     <!-- Only present when prompt is not locked -->
 *     <div class="lid-forum-prompt-section lid-forum-prompt-hidden">
 *       ... prompt_editor markup ...
 *     </div>
 *
 *   </div>
 *
 * @module     local_lid/forum_config
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
define(['local_lid/prompt_editor'], function(PromptEditor) {

    'use strict';

    // =========================================================================
    // Public entry point
    // =========================================================================

    /**
     * Initialise all .lid-forum-config elements on the page.
     *
     * Called from forum_config.mustache via:
     *   require(['local_lid/forum_config'], function(ForumConfig) {
     *       ForumConfig.init();
     *   });
     */
    function init() {
        document.querySelectorAll('.lid-forum-config').forEach(function(wrapper) {
            initForumConfig(wrapper);
        });

        // Also initialise any prompt editors present on the page.
        PromptEditor.init();
    }

    // =========================================================================
    // Per-wrapper init
    // =========================================================================

    /**
     * Wire up a single .lid-forum-config wrapper.
     *
     * @param {HTMLElement} wrapper
     */
    function initForumConfig(wrapper) {
        var forumid  = parseInt(wrapper.dataset.forumid,  10);
        var courseid = parseInt(wrapper.dataset.courseid, 10);
        var ajaxUrl  = wrapper.dataset.url;
        var toggle   = wrapper.querySelector('.lid-forum-enable-toggle');
        var statusEl = wrapper.querySelector('.lid-forum-config-status');
        var promptSection = wrapper.querySelector('.lid-forum-prompt-section');

        if (!toggle || !forumid || !courseid) {
            return;
        }

        // Reflect initial state.
        updatePromptSectionVisibility(promptSection, toggle.checked);
        updateStatusBadge(statusEl, toggle.checked);

        // Wire up the toggle.
        toggle.addEventListener('change', function() {
            var enabling = toggle.checked;

            // When disabling: warn if analyses exist for this forum.
            if (!enabling) {
                var hasData = wrapper.dataset.hasData === '1';
                if (hasData) {
                    var confirmed = window.confirm(
                        'Disabling LID for this forum will stop new analyses from running. ' +
                        'Existing analysis data will not be deleted. Continue?'
                    );
                    if (!confirmed) {
                        // Revert the toggle.
                        toggle.checked = true;
                        return;
                    }
                }
            }

            saveForumConfig(forumid, courseid, enabling, ajaxUrl, toggle, statusEl,
                function(success) {
                    if (success) {
                        updatePromptSectionVisibility(promptSection, enabling);
                        updateStatusBadge(statusEl, enabling);
                        // Update the page-level LID nav tab visibility.
                        updateNavTabVisibility(enabling);
                    } else {
                        // Revert toggle on failure.
                        toggle.checked = !enabling;
                    }
                }
            );
        });
    }

    // =========================================================================
    // AJAX save
    // =========================================================================

    /**
     * Save the forum LID enabled/disabled state via ajax.php.
     *
     * @param {number}              forumid
     * @param {number}              courseid
     * @param {boolean}             enabled
     * @param {string}              ajaxUrl
     * @param {HTMLInputElement}    toggle   — disabled during request.
     * @param {HTMLElement|null}    statusEl — updated with saving indicator.
     * @param {Function}            onDone   — called with (success: bool).
     */
    function saveForumConfig(forumid, courseid, enabled, ajaxUrl, toggle, statusEl, onDone) {
        toggle.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Saving…';
            statusEl.style.color = '#5a7090';
        }

        var body = new URLSearchParams();
        body.set('action',   'forum_config');
        body.set('forumid',  forumid);
        body.set('courseid', courseid);
        body.set('enabled',  enabled ? '1' : '0');
        body.set('sesskey',  M.cfg.sesskey);

        fetch(ajaxUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    body.toString(),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            toggle.disabled = false;
            if (data.error) {
                showToast(data.message || 'Save failed.', 'error');
                if (statusEl) {
                    statusEl.textContent = 'Save failed.';
                    statusEl.style.color = '#ff3c3c';
                }
                onDone(false);
            } else {
                showToast(
                    enabled ? 'LID analysis enabled for this forum.' :
                              'LID analysis disabled for this forum.',
                    'success'
                );
                onDone(true);
            }
        })
        .catch(function() {
            toggle.disabled = false;
            showToast('Save failed. Check your connection.', 'error');
            if (statusEl) {
                statusEl.textContent = 'Save failed.';
                statusEl.style.color = '#ff3c3c';
            }
            onDone(false);
        });
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    /**
     * Show or hide the forum-level prompt override section.
     *
     * The prompt section is only relevant when LID is enabled, so we hide it
     * when the forum is disabled to reduce visual noise.
     *
     * @param {HTMLElement|null} section
     * @param {boolean}          enabled
     */
    function updatePromptSectionVisibility(section, enabled) {
        if (!section) {
            return;
        }
        if (enabled) {
            section.classList.remove('lid-forum-prompt-hidden');
        } else {
            section.classList.add('lid-forum-prompt-hidden');
        }
    }

    /**
     * Update the inline status badge text and colour.
     *
     * @param {HTMLElement|null} statusEl
     * @param {boolean}          enabled
     */
    function updateStatusBadge(statusEl, enabled) {
        if (!statusEl) {
            return;
        }
        if (enabled) {
            statusEl.textContent = 'LID enabled';
            statusEl.style.cssText = 'font-size:10px;font-family:"DM Mono",monospace;' +
                                     'color:#00ff9d;letter-spacing:1px;margin-top:4px;' +
                                     'text-transform:uppercase';
        } else {
            statusEl.textContent = 'LID disabled';
            statusEl.style.cssText = 'font-size:10px;font-family:"DM Mono",monospace;' +
                                     'color:#5a7090;letter-spacing:1px;margin-top:4px;' +
                                     'text-transform:uppercase';
        }
    }

    /**
     * Show or hide the LID navigation tab in the current forum page.
     *
     * When a teacher enables LID mid-session, the navigation tab injected by
     * lib.php won't appear until a page reload. We surface a prompt to reload
     * rather than trying to dynamically inject nav nodes, which would require
     * a full Moodle navigation rebuild.
     *
     * @param {boolean} enabled
     */
    function updateNavTabVisibility(enabled) {
        var lidNavItem = document.querySelector('a[href*="local/lid/forum_view.php"]');

        if (enabled && !lidNavItem) {
            // Tab doesn't exist yet — prompt reload.
            showToast(
                'LID enabled. Reload the page to see the Learning Intelligence tab.',
                'info'
            );
        }
    }

    /**
     * Show a toast notification.
     *
     * @param {string} message
     * @param {string} [type='info']
     */
    function showToast(message, type) {
        type = type || 'info';
        var colors = {
            info:    '#00e5ff',
            success: '#00ff9d',
            warn:    '#ff6b35',
            error:   '#ff3c3c',
        };
        var toast = document.createElement('div');
        toast.style.cssText = [
            'position:fixed',
            'bottom:24px',
            'right:24px',
            'z-index:9999',
            'background:#141d2e',
            'border:1px solid ' + (colors[type] || colors.info),
            'padding:12px 20px',
            'border-radius:6px',
            'font-family:\'DM Mono\',monospace',
            'font-size:11px',
            'color:' + (colors[type] || colors.info),
            'transform:translateY(80px)',
            'opacity:0',
            'transition:all 0.3s',
            'pointer-events:none',
            'max-width:360px',
            'line-height:1.4',
        ].join(';');
        toast.textContent = message;
        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.style.transform = 'translateY(0)';
                toast.style.opacity   = '1';
            });
        });

        setTimeout(function() {
            toast.style.transform = 'translateY(80px)';
            toast.style.opacity   = '0';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3500);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    return {
        init: init,
    };
});
