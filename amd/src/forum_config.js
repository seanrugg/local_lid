/**
 * Forum configuration AMD module for local_lid.
 *
 * Handles the per-forum LID enable/disable toggle, discussion model selector,
 * and the optional forum-level prompt override editor.
 *
 * Features:
 *   Toggle switch        — enables/disables LID for the forum. Saved via AJAX.
 *   Discussion model     — radio button group selecting the participation model.
 *                          Saved via a dedicated "Save assessment model" button.
 *                          Shown only when LID is enabled.
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
 *        data-url="...ajax.php...">
 *
 *     <input type="checkbox" class="lid-forum-enable-toggle" ...>
 *     <div class="lid-forum-config-status"></div>
 *
 *     <div class="lid-forum-model-section [lid-forum-prompt-hidden]">
 *       <input type="radio" class="lid-forum-model-radio" name="lid_discussion_model_N" value="...">
 *       ...
 *       <button class="lid-model-save-btn">Save assessment model</button>
 *       <span class="lid-model-save-status"></span>
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
        var forumid       = parseInt(wrapper.dataset.forumid,  10);
        var courseid      = parseInt(wrapper.dataset.courseid, 10);
        var ajaxUrl       = wrapper.dataset.url;
        var toggle        = wrapper.querySelector('.lid-forum-enable-toggle');
        var statusEl      = wrapper.querySelector('.lid-forum-config-status');
        var promptSection = wrapper.querySelector('.lid-forum-prompt-section');
        var modelSection  = wrapper.querySelector('.lid-forum-model-section');
        var modelSaveBtn  = wrapper.querySelector('.lid-model-save-btn');
        var modelStatus   = wrapper.querySelector('.lid-model-save-status');

        if (!toggle || !forumid || !courseid) {
            return;
        }

        // Reflect initial visibility.
        updateSectionVisibility(promptSection, toggle.checked);
        updateSectionVisibility(modelSection,  toggle.checked);
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
                // Update visual selection state immediately on click.
                // The actual save happens when the save button is clicked.
                updateModelOptionStyles(wrapper, radio.value);
            });
        });
    }

    // =========================================================================
    // AJAX save
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
    // UI helpers
    // =========================================================================

    /**
     * Show or hide a section (model selector or prompt section) based on
     * whether LID is enabled. Hidden sections are visually removed to
     * reduce noise when LID is disabled for the forum.
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
     * Iterates all radio buttons in the wrapper and applies/removes the
     * selected styling class on their parent label.
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
     * When LID is enabled, the nav tab should appear; when disabled, it
     * should be hidden. This avoids a full page reload to reflect the change.
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
