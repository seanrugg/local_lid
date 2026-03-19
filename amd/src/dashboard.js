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
 * Learning Intelligence Dashboard — AMD module.
 *
 * Handles all client-side interactivity for the three LID dashboard surfaces:
 *
 *   Tab switching       — course/forum/student tab bars
 *   Posts accordion     — expand/collapse student post lists
 *   Radar charts        — SVG radar drawn from data-axes JSON on each canvas
 *   Competency bars     — CSS width animation triggered on visibility
 *   Engagement bars     — ROI panel bar chart built from data-score attribute
 *   Manual trigger      — re-analyse button → AJAX → status polling loop
 *   Re-analyse post     — per-post re-analyse button with inline status update
 *
 * Entry points (called from Mustache templates via require()):
 *   Dashboard.initCoursePage(opts)   — course_lid.mustache
 *   Dashboard.initForumPage(opts)    — forum_lid.mustache
 *   Dashboard.initStudentPage(opts)  — student_lid.mustache
 *
 * All three call the shared init() function which wires up every component
 * found in the page. The page-specific inits attach any surface-specific
 * listeners on top.
 *
 * @module     local_lid/dashboard
 * @copyright  2026 Learning Intelligence Dashboard Project Contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {

    'use strict';

    // =========================================================================
    // Constants
    // =========================================================================

    /** Poll interval when waiting for an analysis to complete (ms). */
    const POLL_INTERVAL_MS = 3000;

    /** Maximum poll attempts before giving up and showing an error. */
    const POLL_MAX_ATTEMPTS = 60; // 3 min at 3s intervals.

    /** CSS variable names matching lid_styles.mustache. */
    const CSS = {
        ACCENT:  '#00e5ff',
        ACCENT2: '#7b61ff',
        ACCENT3: '#00ff9d',
        WARN:    '#ff6b35',
        BORDER:  '#1e2d44',
        DIM:     '#2a3d58',
        MUTED:   '#5a7090',
    };

    // =========================================================================
    // Public entry points
    // =========================================================================

    /**
     * Initialise the Course LID page.
     *
     * @param {Object} opts
     * @param {number} opts.courseid
     * @param {string} opts.triggerUrl  AJAX endpoint URL.
     */
    function initCoursePage(opts) {
        init();
        // Re-analyse all button.
        document.querySelectorAll('.lid-js-trigger-course').forEach(function(btn) {
            btn.addEventListener('click', function() {
                triggerCourse(opts.courseid, opts.triggerUrl, btn);
            });
        });
    }

    /**
     * Initialise the Forum LID page.
     *
     * @param {Object} opts
     * @param {number} opts.forumid
     * @param {number} opts.courseid
     * @param {string} opts.triggerUrl
     */
    function initForumPage(opts) {
        init();
        document.querySelectorAll('.lid-js-trigger-forum').forEach(function(btn) {
            btn.addEventListener('click', function() {
                triggerForum(opts.forumid, opts.courseid, opts.triggerUrl, btn);
            });
        });
        document.querySelectorAll('.lid-js-reanalyse').forEach(function(btn) {
            btn.addEventListener('click', function() {
                reanalysePost(btn);
            });
        });
    }

    /**
     * Initialise the Student LID page.
     *
     * @param {Object} opts
     * @param {number} opts.userid
     * @param {number} opts.courseid
     */
    function initStudentPage(opts) {
        init();
        // No student-specific triggers beyond shared init.
    }

    // =========================================================================
    // Shared init — wires up everything found in the current page
    // =========================================================================

    /**
     * Wire up all interactive components in the page.
     * Safe to call multiple times — uses event delegation where possible.
     */
    function init() {
        initTabs();
        initPostsAccordions();
        initRadarCharts();
        initCompetencyBars();
        initEngagementBars();
    }

    // =========================================================================
    // Tab switching
    // =========================================================================

    /**
     * Wire up all .lid-forum-tab elements as accessible tab controls.
     *
     * Tabs use aria-controls to point at their panel id. Clicking a tab:
     *   - Adds lid-forum-tab-active to the clicked tab.
     *   - Removes lid-tab-panel-hidden from the target panel.
     *   - Hides sibling panels.
     *   - Updates aria-selected on all tabs in the group.
     */
    function initTabs() {
        document.querySelectorAll('[role="tablist"]').forEach(function(tablist) {
            tablist.addEventListener('click', function(e) {
                var tab = e.target.closest('[role="tab"]');
                if (!tab) {
                    return;
                }
                var targetId = tab.dataset.target;
                if (!targetId) {
                    return;
                }

                // Deactivate all tabs in this tablist.
                tablist.querySelectorAll('[role="tab"]').forEach(function(t) {
                    t.classList.remove('lid-forum-tab-active');
                    t.setAttribute('aria-selected', 'false');
                });

                // Activate the clicked tab.
                tab.classList.add('lid-forum-tab-active');
                tab.setAttribute('aria-selected', 'true');

                // Find all sibling panels — they share the same nearest
                // .lid-page ancestor.
                var page = tablist.closest('.lid-page');
                if (!page) {
                    return;
                }
                page.querySelectorAll('[role="tabpanel"]').forEach(function(panel) {
                    panel.classList.add('lid-tab-panel-hidden');
                });

                var target = document.getElementById(targetId);
                if (target) {
                    target.classList.remove('lid-tab-panel-hidden');
                    // Initialise any charts that became visible.
                    initRadarCharts(target);
                    initCompetencyBars(target);
                    initEngagementBars(target);
                }
            });
        });
    }

    // =========================================================================
    // Posts accordion
    // =========================================================================

    /**
     * Wire up all .lid-js-toggle-posts buttons as accordion toggles.
     *
     * Toggling a button shows/hides the element identified by its
     * aria-controls / data-target attribute and updates aria-expanded.
     */
    function initPostsAccordions() {
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.lid-js-toggle-posts');
            if (!btn) {
                return;
            }

            var targetId  = btn.dataset.target || btn.getAttribute('aria-controls');
            var expanded  = btn.getAttribute('aria-expanded') === 'true';
            var target    = targetId ? document.getElementById(targetId) : null;

            if (!target) {
                return;
            }

            if (expanded) {
                target.classList.add('lid-student-posts-hidden');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = btn.textContent.replace('▲', '▼');
            } else {
                target.classList.remove('lid-student-posts-hidden');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = btn.textContent.replace('▼', '▲');
                // Initialise charts that just became visible.
                initRadarCharts(target);
                initCompetencyBars(target);
                initEngagementBars(target);
            }
        });
    }

    // =========================================================================
    // Radar charts
    // =========================================================================

    /**
     * Find all .lid-radar-canvas elements within scope and draw SVG radars.
     *
     * Each canvas element carries a data-axes attribute containing a JSON
     * array of { label, value, description } objects. The SVG is inserted
     * as innerHTML of the canvas's parent container.
     *
     * Skips canvases that have already been initialised (marked with
     * data-lid-drawn="1").
     *
     * @param {Element} [scope=document]  Root element to search within.
     */
    function initRadarCharts(scope) {
        scope = scope || document;
        scope.querySelectorAll('.lid-radar-canvas:not([data-lid-drawn])').forEach(function(canvas) {
            var axesJson = canvas.dataset.axes;
            if (!axesJson) {
                return;
            }

            var axes;
            try {
                axes = JSON.parse(axesJson);
            } catch (e) {
                return;
            }

            var svg = buildRadarSVG(axes);
            var container = canvas.closest('.lid-radar-container');
            if (container) {
                container.innerHTML = svg;
            }

            canvas.dataset.lidDrawn = '1';
        });
    }

    /**
     * Build an SVG radar chart from an axes array.
     *
     * Ported directly from the standalone learning-intelligence-dashboard-v2.html
     * buildRadar() function, adapted to use the lid_ CSS variable namespace.
     *
     * @param  {Array}  axes  Array of { label, value } objects.
     * @return {string}       SVG markup string.
     */
    function buildRadarSVG(axes) {
        if (!axes || axes.length < 3) {
            return '<p style="color:' + CSS.MUTED + ';font-size:11px;text-align:center">' +
                   'Insufficient data</p>';
        }

        var n  = axes.length;
        var R  = 110;
        var cx = 0;
        var cy = 0;

        function angle(i) {
            return (Math.PI * 2 * i / n) - Math.PI / 2;
        }

        function pt(i, r) {
            return [
                +(cx + r * Math.cos(angle(i))).toFixed(2),
                +(cy + r * Math.sin(angle(i))).toFixed(2),
            ];
        }

        // Concentric ring polygons.
        var rings = [0.25, 0.5, 0.75, 1.0].map(function(frac) {
            var pts = Array.from({length: n}, function(_, i) {
                return pt(i, R * frac).join(',');
            }).join(' ');
            return '<polygon points="' + pts + '" fill="none" stroke="' +
                   CSS.BORDER + '" stroke-width="1" opacity="' +
                   (0.6 - frac * 0.2).toFixed(2) + '"/>';
        }).join('');

        // Axis lines from centre.
        var gridLines = Array.from({length: n}, function(_, i) {
            var p = pt(i, R);
            return '<line x1="0" y1="0" x2="' + p[0] + '" y2="' + p[1] +
                   '" stroke="' + CSS.DIM + '" stroke-width="1"/>';
        }).join('');

        // Data polygon.
        var dataPoints = axes.map(function(a, i) {
            return pt(i, R * Math.min(100, Math.max(0, a.value)) / 100).join(',');
        }).join(' ');

        // Dots at each data point.
        var dots = axes.map(function(a, i) {
            var p = pt(i, R * Math.min(100, Math.max(0, a.value)) / 100);
            return '<circle cx="' + p[0] + '" cy="' + p[1] +
                   '" r="4" fill="' + CSS.ACCENT + '"/>';
        }).join('');

        // Axis labels.
        var labels = axes.map(function(a, i) {
            var p      = pt(i, R * 1.2);
            var anchor = p[0] > 5 ? 'start' : p[0] < -5 ? 'end' : 'middle';
            var label  = a.label.length > 14 ? a.label.substring(0, 13) + '…' : a.label;
            return '<text x="' + p[0] + '" y="' + p[1] + '"' +
                   ' text-anchor="' + anchor + '"' +
                   ' font-family="\'DM Mono\',monospace"' +
                   ' font-size="8" fill="' + CSS.MUTED + '">' +
                   escapeHtml(label) + '</text>';
        }).join('');

        // Score labels next to each dot.
        var scores = axes.map(function(a, i) {
            var p = pt(i, R * Math.min(100, Math.max(0, a.value)) / 100 + 0.15);
            return '<text x="' + (p[0] + 4) + '" y="' + p[1] + '"' +
                   ' font-family="\'DM Mono\',monospace"' +
                   ' font-size="8" fill="' + CSS.ACCENT + '">' +
                   a.value + '%</text>';
        }).join('');

        return '<svg width="280" height="280" viewBox="-140 -140 280 280"' +
               ' style="overflow:visible">' +
               rings + gridLines +
               '<polygon points="' + dataPoints + '"' +
               ' fill="rgba(0,229,255,0.08)"' +
               ' stroke="rgba(0,229,255,0.6)" stroke-width="1.5"/>' +
               dots + labels + scores +
               '</svg>';
    }

    // =========================================================================
    // Competency bar animations
    // =========================================================================

    /**
     * Animate competency bar fills from 0 to their target width.
     *
     * Each .lid-comp-bar-fill carries its target width as a data-width
     * attribute (set by the PHP renderer). The bar starts at width:0 in CSS
     * and is animated to its target once it enters the viewport.
     *
     * Uses IntersectionObserver if available, falls back to immediate set.
     *
     * @param {Element} [scope=document]
     */
    function initCompetencyBars(scope) {
        scope = scope || document;
        var bars = Array.from(
            scope.querySelectorAll('.lid-comp-bar-fill:not([data-lid-animated])')
        );

        if (!bars.length) {
            return;
        }

        function animateBar(bar) {
            var target = bar.dataset.width || '0';
            bar.style.width = '0';
            // Double rAF ensures the browser has painted width:0 before animating.
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    bar.style.width = target + '%';
                    bar.dataset.lidAnimated = '1';
                });
            });
        }

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        animateBar(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, {threshold: 0.1});

            bars.forEach(function(bar) {
                observer.observe(bar);
            });
        } else {
            // Fallback — animate immediately.
            bars.forEach(animateBar);
        }
    }

    // =========================================================================
    // Engagement bar charts (ROI panel)
    // =========================================================================

    /**
     * Build and inject the engagement bar chart into .lid-roi-bars elements.
     *
     * Each .lid-roi-bars element carries data-score (0–100). The chart is six
     * vertical bars of increasing height, filled based on the score threshold.
     *
     * Ported from buildEngagementBars() in the standalone dashboard.
     *
     * @param {Element} [scope=document]
     */
    function initEngagementBars(scope) {
        scope = scope || document;
        scope.querySelectorAll('.lid-roi-bars:not([data-lid-drawn])').forEach(function(el) {
            var score = parseInt(el.dataset.score, 10) || 0;
            el.innerHTML = buildEngagementBarsHTML(score);
            el.dataset.lidDrawn = '1';
        });
    }

    /**
     * Build engagement bar HTML for a given score (0–100).
     *
     * @param  {number} score
     * @return {string} HTML string.
     */
    function buildEngagementBarsHTML(score) {
        var steps = [0.45, 0.60, 0.72, 0.84, 0.92, 1.0];
        return steps.map(function(h, i) {
            var active = (i + 1) / steps.length <= score / 100;
            var bg;
            if (active) {
                bg = i >= 4 ? CSS.ACCENT : 'rgba(0,229,255,' + (0.3 + i * 0.1).toFixed(2) + ')';
            } else {
                bg = CSS.BORDER;
            }
            return '<div style="flex:1;height:' + (h * 100).toFixed(0) + '%;' +
                   'background:' + bg + ';' +
                   'border-radius:2px 2px 0 0;' +
                   'transition:background 0.3s ' + (i * 0.05).toFixed(2) + 's"></div>';
        }).join('');
    }

    // =========================================================================
    // Manual trigger — re-analyse entire course
    // =========================================================================

    /**
     * Trigger re-analysis of all posts in all LID-enabled forums in a course.
     *
     * @param {number} courseid
     * @param {string} triggerUrl
     * @param {Element} btn       The button element (disabled during request).
     */
    function triggerCourse(courseid, triggerUrl, btn) {
        setButtonWorking(btn, true);

        fetch(triggerUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    'action=trigger&courseid=' + courseid + '&sesskey=' + M.cfg.sesskey,
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            setButtonWorking(btn, false);
            if (data.error) {
                showToast(data.message || 'Error queuing analyses.', 'error');
            } else {
                showToast('Queued ' + (data.queued || 0) + ' post(s) for analysis.', 'success');
            }
        })
        .catch(function(err) {
            setButtonWorking(btn, false);
            showToast('Request failed. Check your connection.', 'error');
        });
    }

    // =========================================================================
    // Manual trigger — re-analyse entire forum
    // =========================================================================

    /**
     * Trigger re-analysis of all posts in a specific forum.
     *
     * @param {number} forumid
     * @param {number} courseid
     * @param {string} triggerUrl
     * @param {Element} btn
     */
    function triggerForum(forumid, courseid, triggerUrl, btn) {
        setButtonWorking(btn, true);

        fetch(triggerUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    'action=trigger' +
                     '&forumid='  + forumid  +
                     '&courseid=' + courseid +
                     '&sesskey='  + M.cfg.sesskey,
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            setButtonWorking(btn, false);
            if (data.error) {
                showToast(data.message || 'Error queuing analyses.', 'error');
            } else {
                showToast('Queued ' + (data.queued || 0) + ' post(s) for analysis.', 'success');
                // Update status badges for all posts in this forum.
                updateAllPostStatusBadges('pending');
            }
        })
        .catch(function() {
            setButtonWorking(btn, false);
            showToast('Request failed. Check your connection.', 'error');
        });
    }

    // =========================================================================
    // Re-analyse single post
    // =========================================================================

    /**
     * Trigger re-analysis of a single post and poll for completion.
     *
     * When analysis completes, reloads the page so the new card renders.
     * A full reload is the simplest and most reliable approach — the
     * dashboard HTML is server-rendered, so we cannot patch it in-place
     * without duplicating the renderer. At this scale a reload is fast.
     *
     * @param {Element} btn  The re-analyse button, must have data-url.
     */
    function reanalysePost(btn) {
        var url       = btn.dataset.url;
        var postRow   = btn.closest('.lid-post-row');
        var statusEl  = postRow ? postRow.querySelector('.lid-status-badge') : null;

        if (!url) {
            return;
        }

        setButtonWorking(btn, true);
        if (statusEl) {
            setStatusBadge(statusEl, 'processing');
        }

        // The reanalyse URL already contains action=trigger&analysisid=N.
        // We just need to POST with sesskey added.
        var params = new URLSearchParams(url.split('?')[1] || '');
        params.set('sesskey', M.cfg.sesskey);

        fetch(url.split('?')[0], {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    params.toString(),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                setButtonWorking(btn, false);
                if (statusEl) {
                    setStatusBadge(statusEl, 'error');
                }
                showToast(data.message || 'Error queuing analysis.', 'error');
            } else {
                // Extract analysisid from the URL params for polling.
                var analysisid = params.get('analysisid');
                if (analysisid) {
                    var baseUrl = url.split('?')[0];
                    pollStatus(parseInt(analysisid, 10), baseUrl, btn, statusEl);
                } else {
                    setButtonWorking(btn, false);
                    showToast('Queued. Refresh when complete.', 'success');
                }
            }
        })
        .catch(function() {
            setButtonWorking(btn, false);
            if (statusEl) {
                setStatusBadge(statusEl, 'error');
            }
            showToast('Request failed.', 'error');
        });
    }

    // =========================================================================
    // Status polling
    // =========================================================================

    /**
     * Poll the AJAX status endpoint until the analysis is complete or errors.
     *
     * On completion, reloads the page to show the new analysis card.
     * On error or timeout, shows a toast and restores the button.
     *
     * @param {number}  analysisid
     * @param {string}  baseUrl     AJAX endpoint without query string.
     * @param {Element} btn         Button to restore on terminal state.
     * @param {Element} statusEl    Status badge to update during polling.
     * @param {number}  [attempt=0] Current attempt count.
     */
    function pollStatus(analysisid, baseUrl, btn, statusEl, attempt) {
        attempt = attempt || 0;

        if (attempt >= POLL_MAX_ATTEMPTS) {
            setButtonWorking(btn, false);
            if (statusEl) {
                setStatusBadge(statusEl, 'error');
            }
            showToast('Analysis is taking too long. It will complete in the background.', 'warn');
            return;
        }

        setTimeout(function() {
            fetch(baseUrl + '?action=status&analysisid=' + analysisid +
                  '&sesskey=' + M.cfg.sesskey)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    setButtonWorking(btn, false);
                    if (statusEl) {
                        setStatusBadge(statusEl, 'error');
                    }
                    showToast(data.message || 'Analysis failed.', 'error');
                    return;
                }

                var status = data.status;

                if (statusEl) {
                    setStatusBadge(statusEl, status);
                }

                if (status === 'complete') {
                    showToast('Analysis complete. Refreshing…', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else if (status === 'error') {
                    setButtonWorking(btn, false);
                    showToast('Analysis failed: ' + (data.error_message || 'unknown error'), 'error');
                } else {
                    // Still pending or processing — keep polling.
                    pollStatus(analysisid, baseUrl, btn, statusEl, attempt + 1);
                }
            })
            .catch(function() {
                // Network blip — keep trying.
                pollStatus(analysisid, baseUrl, btn, statusEl, attempt + 1);
            });
        }, POLL_INTERVAL_MS);
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    /**
     * Set a button into working (disabled + spinner) or normal state.
     *
     * @param {Element} btn
     * @param {boolean} working
     */
    function setButtonWorking(btn, working) {
        if (!btn) {
            return;
        }
        btn.disabled = working;
        if (working) {
            btn.dataset.originalText = btn.textContent;
            btn.textContent = '⏳ Working…';
        } else {
            btn.textContent = btn.dataset.originalText || btn.textContent;
        }
    }

    /**
     * Update a .lid-status-badge element to reflect a new status.
     *
     * Replaces the status class and updates the visible text in-place.
     * String keys match the lang strings in local_lid.php.
     *
     * @param {Element} el
     * @param {string}  status  One of: pending, processing, complete, error.
     */
    function setStatusBadge(el, status) {
        if (!el) {
            return;
        }

        var labels = {
            pending:    'Pending',
            processing: 'Processing',
            complete:   'Complete',
            error:      'Error',
        };

        ['pending', 'processing', 'complete', 'error'].forEach(function(s) {
            el.classList.remove('lid-status-' + s);
        });
        el.classList.add('lid-status-' + status);

        // Preserve the spinner span for processing state.
        var spinner = el.querySelector('.lid-status-spinner');
        if (status === 'processing') {
            if (!spinner) {
                spinner = document.createElement('span');
                spinner.className = 'lid-status-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                el.prepend(spinner);
            }
        } else if (spinner) {
            spinner.remove();
        }

        // Update the text node (last child after optional spinner).
        var text = labels[status] || status;
        var textNode = el.lastChild;
        if (textNode && textNode.nodeType === Node.TEXT_NODE) {
            textNode.textContent = text;
        } else {
            el.appendChild(document.createTextNode(text));
        }
    }

    /**
     * Update all .lid-status-badge elements on the page to a given status.
     * Used after a forum-wide trigger to mark all posts as pending.
     *
     * @param {string} status
     */
    function updateAllPostStatusBadges(status) {
        document.querySelectorAll('.lid-status-badge').forEach(function(el) {
            setStatusBadge(el, status);
        });
    }

    /**
     * Show a temporary toast notification at the bottom of the page.
     *
     * Creates and removes the element programmatically so no static HTML
     * is needed in the templates.
     *
     * @param {string} message
     * @param {string} [type='info']  One of: info, success, warn, error.
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

        // Animate in.
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.style.transform = 'translateY(0)';
                toast.style.opacity   = '1';
            });
        });

        // Animate out and remove.
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

    /**
     * Escape HTML special characters for safe SVG text content.
     *
     * @param  {string} str
     * @return {string}
     */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;');
    }

    // =========================================================================
    // Return public API
    // =========================================================================

    return {
        initCoursePage:  initCoursePage,
        initForumPage:   initForumPage,
        initStudentPage: initStudentPage,
        // Exposed for testing.
        buildRadarSVG:           buildRadarSVG,
        buildEngagementBarsHTML: buildEngagementBarsHTML,
    };
});
