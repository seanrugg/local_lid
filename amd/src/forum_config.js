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
 * Handles the per-forum LID enable/disable toggle, discussion model selector,
 * competency selector, and the optional forum-level prompt override editor.
 *
 * Features:
 *   Toggle switch        — enables/disables LID for the forum. Saved via AJAX.
 *   Discussion model     — radio button group selecting the participation model.
 *                          Saved via a dedicated "Save assessment model" button.
 *                          Shown only when LID is enabled.
 *   Competency selector  — radio mode (inherit/exclude/specific) + checkbox list.
 *                          Saved via a dedicated "Save competency settings" button.
 *                          Shown only when LID is enabled and course competencies
 *                          are active.
 *   Prompt override      — if prompt is not locked, shows the prompt_editor
 *                          inline for forum-level customisation.
 *   Confirmation         — when disabling a forum that has existing analyses,
 *                          warns that data is preserved but analysis stops.
 *
 * Expected DOM structure (rendered by forum_config.mustache):
 *
 *   <div class="lid-forum-config"
 *        data-forumid="N"
 *        data-courseid="N"
 *        data-enabled="0|1"
 *        data-discussion-model="open_engagement|independent_first|structured_debate"
 *        data-competency-mode="inherit|exclude|specific"
 *        data-competency-ids="[3,7]"
 *        data-url="...ajax.php...">
 *
 *     <input type="checkbox" class="lid-forum-enable-toggle" ...>
 *     <div class="lid-forum-config-status"></div>
 *
 *     <div class="lid-forum-model-section [lid-forum-prompt-hidden]">
 *       <input type="radio" class="lid-forum-model-radio" ...>
 *       <button class="lid-model-save-btn">Save assessment model</button>
 *       <span class="lid-model-save-status"></span>
 *     </div>
 *
 *     <div class="lid-forum-competency-section [lid-forum-prompt-hidden]">
 *       <input type="radio" class="lid-competency-mode-radio" ...>
 *       <input type="checkbox" class="lid-competency-checkbox" ...>
 *       <button class="lid-competency-save-btn">Save competency settings</button>
 *       <span class="lid-competency-save-status"></span>
 *     </div>
 *
 *     <div class="lid-forum-prompt-section [lid-forum-prompt-hidden]">
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
     */
    function init() {
        document.querySelectorAll('.lid-forum-config').forEach(function(wrapper) {
            initForumConfig(wrapper);
        });
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
        var forumid            = parseInt(wrapper.dataset.forumid,  10);
        var courseid           = parseInt(wrapper.dataset.courseid, 10);
        var ajaxUrl            = wrapper.dataset.url;
        var toggle             = wrapper.querySelector('.lid-forum-enable-toggle');
        var statusEl           = wrapper.querySelector('.lid-forum-config-status');
        var promptSection      = wrapper.querySelector('.lid-forum-prompt-section');
        var modelSection       = wrapper.querySelector('.lid-forum-model-section');
        var competencySection  = wrapper.querySelector('.lid-forum-competency-section');
        var modelSaveBtn       = wrapper.querySelector('.lid-model-save-btn');
        var modelStatus        = wrapper.querySelector('.lid-model-save-status');

        if (!toggle || !forumid || !courseid) {
            return;
        }

        // Reflect initial visibility.
        updateSectionVisibility(promptSection, toggle.checked);
        updateSectionVisibility(modelSection,  toggle.checked);
        updateSectionVisibility(competencySection, toggle.checked);
        updateStatusBadge(statusEl, toggle.checked);

        // ---- Enable/disable toggle ----
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
                        toggle.checked = true;
                        return;
                    }
                }
            }

            saveForumConfig(
                forumid, courseid, enabling,
                getSelectedModel(wrapper),
                ajaxUrl, toggle, statusEl,
                function(success) {
                    if (success) {
                        updateSectionVisibility(promptSection, enabling);
                        updateSectionVisibility(modelSection,  enabling);
                        updateSectionVisibility(competencySection, enabling);
                        updateStatusBadge(statusEl, enabling);
                        updateNavTabVisibility(enabling);
                    } else {
                        toggle.checked = !enabling;
                    }
                }
            );
        });

        // ---- Discussion model save button ----
        if (modelSaveBtn) {
            modelSaveBtn.addEventListener('click', function() {
                var model = getSelectedModel(wrapper);

                // Visual feedback — disable button during save.
                modelSaveBtn.disabled = true;
                if (modelStatus) {
                    modelStatus.textContent = 'Saving…';
                    modelStatus.style.color = '#5a7090';
                }

                saveForumConfig(
                    forumid, courseid, toggle.checked,
                    model,
                    ajaxUrl, toggle, null,
                    function(success) {
                        modelSaveBtn.disabled = false;
                        if (success) {
                            // Update the wrapper's data attribute to reflect saved model.
                            wrapper.dataset.discussionModel = model;
                            updateModelOptionStyles(wrapper, model);
                            if (modelStatus) {
                                modelStatus.textContent = 'Assessment model saved.';
                                modelStatus.style.color = 'var(--lid-accent3)';
                                setTimeout(function() {
                                    modelStatus.textContent = '';
                                }, 3000);
                            }
                        } else {
                            if (modelStatus) {
                                modelStatus.textContent = 'Save failed.';
                                modelStatus.style.color = '#ff3c3c';
                            }
                        }
                    }
                );
            });
        }

        // ---- Radio button — update card styling on selection ----
        var radios = wrapper.querySelectorAll('.lid-forum-model-radio');
        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                updateModelOptionStyles(wrapper, radio.value);
            });
        });

        // ---- Competency mode radios — show/hide checklist ----
        initCompetencySelector(wrapper, forumid, courseid, ajaxUrl);
    }

    // =========================================================================
    // Competency selector
    // =========================================================================

    /**
     * Wire up competency mode radios, checklist, and save button.
     *
     * @param {HTMLElement} wrapper
     * @param {number}      forumid
     * @param {number}      courseid
     * @param {string}      ajaxUrl
     */
    function initCompetencySelector(wrapper, forumid, courseid, ajaxUrl) {
        var modeRadios    = wrapper.querySelectorAll('.lid-competency-mode-radio');
        var checklist     = wrapper.querySelector('.lid-competency-checklist');
        var saveBtn       = wrapper.querySelector('.lid-competency-save-btn');
        var statusEl      = wrapper.querySelector('.lid-competency-save-status');

        if (!modeRadios.length) {
            return; // No competency section on this page.
        }

        // Toggle checklist visibility when mode changes.
        modeRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                if (checklist) {
                    if (radio.value === 'specific') {
                        checklist.classList.remove('lid-forum-prompt-hidden');
                    } else {
                        checklist.classList.add('lid-forum-prompt-hidden');
                    }
                }
            });
        });

        // Save button.
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var mode = getSelectedCompetencyMode(wrapper);
                var ids  = getSelectedCompetencyIds(wrapper);

                saveBtn.disabled = true;
                if (statusEl) {
                    statusEl.textContent = 'Saving…';
                    statusEl.style.color = '#5a7090';
                }

                saveForumCompetencies(
                    forumid, courseid, mode, ids, ajaxUrl,
                    function(success) {
                        saveBtn.disabled = false;
                        if (success) {
                            wrapper.dataset.competencyMode = mode;
                            wrapper.dataset.competencyIds = JSON.stringify(ids);
                            if (statusEl) {
                                statusEl.textContent = 'Competency settings saved.';
                                statusEl.style.color = 'var(--lid-accent3)';
                                setTimeout(function() {
                                    statusEl.textContent = '';
                                }, 3000);
                            }
                        } else {
                            if (statusEl) {
                                statusEl.textContent = 'Save failed.';
                                statusEl.style.color = '#ff3c3c';
                            }
                        }
                    }
                );
            });
        }
    }

    /**
     * Get the currently selected competency mode.
     *
     * @param  {HTMLElement} wrapper
     * @return {string}      'inherit' | 'exclude' | 'specific'
     */
    function getSelectedCompetencyMode(wrapper) {
        var checked = wrapper.querySelector('.lid-competency-mode-radio:checked');
        return checked ? checked.value : 'inherit';
    }

    /**
     * Get the selected competency IDs from the checkbox list.
     *
     * @param  {HTMLElement} wrapper
     * @return {number[]}    Array of selected competency IDs.
     */
    function getSelectedCompetencyIds(wrapper) {
        var ids = [];
        wrapper.querySelectorAll('.lid-competency-checkbox:checked').forEach(function(cb) {
            ids.push(parseInt(cb.value, 10));
        });
        return ids;
    }

    // =========================================================================
    // AJAX — forum config save
    // =========================================================================

    /**
     * Save the forum LID config (enabled state + discussion model) via ajax.php.
     *
     * @param {number}           forumid
     * @param {number}           courseid
     * @param {boolean}          enabled
     * @param {string}           discussionModel
     * @param {string}           ajaxUrl
     * @param {HTMLInputElement} toggle      — disabled during request.
     * @param {HTMLElement|null} statusEl    — updated with saving indicator.
     * @param {Function}         onDone      — called with (success: bool).
     */
    function saveForumConfig(
        forumid, courseid, enabled, discussionModel,
        ajaxUrl, toggle, statusEl, onDone
    ) {
        toggle.disabled = true;
        if (statusEl) {
            statusEl.textContent = 'Saving…';
            statusEl.style.color = '#5a7090';
        }

        var body = new URLSearchParams();
        body.set('action',           'forum_config');
        body.set('forumid',          forumid);
        body.set('courseid',         courseid);
        body.set('enabled',          enabled ? '1' : '0');
        body.set('discussion_model', discussionModel || 'open_engagement');
        body.set('sesskey',          M.cfg.sesskey);

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
                    enabled
                        ? 'LID analysis enabled for this forum.'
                        : 'LID analysis disabled for this forum.',
                    'success'
                );
                if (statusEl) {
                    statusEl.textContent = '';
                }
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
    // AJAX — forum competency save
    // =========================================================================

    /**
     * Save the forum competency selection via ajax.php save_forum_competencies.
     *
     * @param {number}   forumid
     * @param {number}   courseid
     * @param {string}   mode     'inherit' | 'exclude' | 'specific'
     * @param {number[]} ids      Selected competency IDs (used when mode = 'specific').
     * @param {string}   ajaxUrl
     * @param {Function} onDone   Called with (success: bool).
     */
    function saveForumCompetencies(forumid, courseid, mode, ids, ajaxUrl, onDone) {
        var body = new URLSearchParams();
        body.set('action',          'save_forum_competencies');
        body.set('forumid',         forumid);
        body.set('courseid',        courseid);
        body.set('competency_mode', mode);
        body.set('competency_ids',  JSON.stringify(ids));
        body.set('sesskey',         M.cfg.sesskey);

        fetch(ajaxUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    body.toString(),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                showToast(data.message || 'Save failed.', 'error');
                onDone(false);
            } else {
                showToast(data.message || 'Competency settings saved.', 'success');
                onDone(true);
            }
        })
        .catch(function() {
            showToast('Save failed. Check your connection.', 'error');
            onDone(false);
        });
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    /**
     * Show or hide a section based on whether LID is enabled.
     *
     * @param {HTMLElement|null} section
     * @param {boolean}          enabled
     */
    function updateSectionVisibility(section, enabled) {
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
        statusEl.textContent = enabled ? 'Enabled' : 'Disabled';
        statusEl.style.color = enabled ? 'var(--lid-accent3)' : 'var(--lid-muted)';
        statusEl.style.fontSize = '10px';
        statusEl.style.fontFamily = "'DM Mono', monospace";
    }

    /**
     * Update the visual selection state of model option cards.
     *
     * @param {HTMLElement} wrapper
     * @param {string}      selectedValue
     */
    function updateModelOptionStyles(wrapper, selectedValue) {
        wrapper.querySelectorAll('.lid-forum-model-radio').forEach(function(radio) {
            var label = radio.closest('.lid-model-option');
            if (!label) {
                return;
            }
            if (radio.value === selectedValue) {
                label.classList.add('lid-model-option-selected');
                label.style.borderColor = 'var(--lid-accent)';
                label.style.background  = 'rgba(0,229,255,0.04)';
            } else {
                label.classList.remove('lid-model-option-selected');
                label.style.borderColor = 'var(--lid-border)';
                label.style.background  = 'var(--lid-surface2)';
            }
        });
    }

    /**
     * Return the currently selected discussion model value from the radio group.
     *
     * @param  {HTMLElement} wrapper
     * @return {string}      Model value, or 'open_engagement' as safe default.
     */
    function getSelectedModel(wrapper) {
        var checked = wrapper.querySelector('.lid-forum-model-radio:checked');
        return checked ? checked.value : 'open_engagement';
    }

    /**
     * Update the LID nav tab visibility in the activity navigation.
     *
     * @param {boolean} enabled
     */
    function updateNavTabVisibility(enabled) {
        var navItem = document.querySelector('a[data-key="local_lid_forum"]');
        if (navItem) {
            var li = navItem.closest('li');
            if (li) {
                li.style.display = enabled ? '' : 'none';
            }
        }
    }

    /**
     * Show a brief toast notification.
     *
     * @param {string} message
     * @param {string} type    'success' | 'error'
     */
    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = [
            'position:fixed',
            'bottom:24px',
            'right:24px',
            'padding:10px 16px',
            'border-radius:6px',
            'font-size:12px',
            'font-family:\'DM Mono\',monospace',
            'z-index:9999',
            'opacity:0',
            'transition:opacity 0.2s',
            type === 'success'
                ? 'background:#0e2a1a;color:#00e5a0;border:1px solid #00e5a0'
                : 'background:#2a0e0e;color:#ff3c3c;border:1px solid #ff3c3c',
        ].join(';');

        document.body.appendChild(toast);
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
        });
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 200);
        }, 3000);
    }

    return { init: init };
});
