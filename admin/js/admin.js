/**
 * Woo Agentic Checkout — Admin Interface JS
 *
 * Manages suggestion apply/reject, experiment controls, agent runs,
 * toast notifications, loading states, and table sorting.
 *
 * @version 0.2.0
 */
(function ($, window, undefined) {
    'use strict';

    var WACAdmin = {
        /** Cached selectors */
        $body: null,
        toastTimeout: null,

        /**
         * Initialise all admin UI features.
         */
        init: function () {
            this.$body = $(document.body);
            this.ensureToastContainer();
            this.bindSuggestionActions();
            this.bindExperimentActions();
            this.bindAgentRun();
            this.bindCreateExperiment();
            this.bindTableSorting();
            this.bindFilterInputs();
            this.bindRefreshButtons();
            this.bindDismissibleErrors();

            // Show notifications from query string (after redirect).
            this.showNotificationFromQuery();
        },

        // ─── Toast / Notification System ────────────────────────

        /**
         * Ensure the toast container exists in the DOM.
         */
        ensureToastContainer: function () {
            if ($('.wac-toast-container').length === 0) {
                $('body').append('<div class="wac-toast-container" aria-live="polite"></div>');
            }
        },

        /**
         * Show a toast notification.
         *
         * @param {string} message  - The message text.
         * @param {string} type     - 'success', 'error', or 'warning'.
         * @param {number} duration - Auto-dismiss after ms (0 = no auto-dismiss).
         */
        showToast: function (message, type, duration) {
            type = type || 'info';
            duration = (typeof duration === 'number') ? duration : 4000;

            var icons = {
                success: '✓',
                error:   '✕',
                warning: '⚠',
                info:    'ℹ'
            };

            var $container = $('.wac-toast-container');
            if ($container.length === 0) {
                this.ensureToastContainer();
                $container = $('.wac-toast-container');
            }

            var $toast = $(
                '<div class="wac-toast wac-toast--' + type + '" role="alert">' +
                    '<span class="wac-toast__icon">' + (icons[type] || 'ℹ') + '</span>' +
                    '<span class="wac-toast__body">' + this.escapeHtml(message) + '</span>' +
                    '<button class="wac-toast__dismiss" aria-label="Dismiss">&times;</button>' +
                '</div>'
            );

            $container.append($toast);

            // Bind dismiss
            $toast.find('.wac-toast__dismiss').on('click', function () {
                WACAdmin.dismissToast($toast);
            });

            // Auto-dismiss
            if (duration > 0) {
                setTimeout(function () {
                    WACAdmin.dismissToast($toast);
                }, duration);
            }
        },

        /**
         * Animate and remove a toast.
         */
        dismissToast: function ($toast) {
            if ($toast.hasClass('wac-toast--removing')) {
                return;
            }
            $toast.addClass('wac-toast--removing');
            setTimeout(function () {
                $toast.remove();
            }, 300);
        },

        /**
         * Escape HTML entities for safe insertion.
         */
        escapeHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        // ─── Utilities ──────────────────────────────────────────

        /**
         * Show loading state on an element.
         */
        showLoading: function ($el) {
            $el.addClass('wac-loading');
            if ($el.is('button, input[type="submit"]')) {
                $el.data('wac-original-text', $el.val ? $el.val() : $el.text());
                if ($el.is('button')) {
                    $el.text('Loading…');
                } else {
                    $el.val('Loading…');
                }
                $el.prop('disabled', true);
            }
        },

        /**
         * Remove loading state from an element.
         */
        hideLoading: function ($el) {
            $el.removeClass('wac-loading');
            if ($el.is('button, input[type="submit"]')) {
                var original = $el.data('wac-original-text');
                if (original) {
                    if ($el.is('button')) {
                        $el.text(original);
                    } else {
                        $el.val(original);
                    }
                }
                $el.prop('disabled', false);
            }
        },

        /**
         * Debounce a function call.
         */
        debounce: function (fn, delay) {
            var timer = null;
            return function () {
                var context = this;
                var args = arguments;
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fn.apply(context, args);
                }, delay || 250);
            };
        },

        /**
         * Show a confirmation dialog (inline or native).
         */
        confirmAction: function (message, callback) {
            if (window.confirm(message)) {
                callback();
            }
        },

        /**
         * Show notification from ?wac_msg= query parameter.
         */
        showNotificationFromQuery: function () {
            var msg = this.getQueryParam('wac_msg');
            if (!msg) {
                return;
            }

            var messages = {
                success:         { text: 'Action completed successfully.', type: 'success' },
                applied:         { text: 'Suggestion applied successfully.', type: 'success' },
                rejected:        { text: 'Suggestion rejected.', type: 'info' },
                error:           { text: 'Action failed. Please try again.', type: 'error' },
                no_agent:        { text: 'No agent selected.', type: 'warning' },
                exp_placeholder: { text: 'Experiment creation wizard coming in a future update!', type: 'info' },
                service_unavailable: { text: 'Service unavailable. Please try again.', type: 'error' }
            };

            var notice = messages[msg] || { text: msg, type: 'info' };
            this.showToast(notice.text, notice.type, 5000);

            // Clean the URL without reload.
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('wac_msg');
                url.searchParams.delete('wac_agent');
                window.history.replaceState({}, '', url.toString());
            }
        },

        /**
         * Get a query parameter value.
         */
        getQueryParam: function (name) {
            var params = new URLSearchParams(window.location.search);
            return params.get(name) || '';
        },

        // ─── Suggestion Actions ─────────────────────────────────

        /**
         * Apply / reject suggestion buttons.
         */
        bindSuggestionActions: function () {
            var self = this;

            // Apply suggestion
            $(document).on('click', '.wac-apply-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('id');
                var $row = $btn.closest('tr');

                self.confirmAction('Apply this suggestion to your checkout?', function () {
                    self.showLoading($btn);
                    $btn.closest('td').find('.wac-spinner').removeClass('wac-hidden');

                    $.ajax({
                        url: wacData.restUrl + '/suggestions/' + id + '/apply',
                        type: 'POST',
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', wacData.nonce);
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast('Suggestion applied successfully!', 'success');
                                if ($row.length) {
                                    $row.fadeOut(300, function () { $(this).remove(); });
                                } else {
                                    $btn.closest('.wac-suggestion-card').fadeOut(300);
                                }
                            } else {
                                self.showToast('Failed to apply: ' + (response.message || 'Unknown error'), 'error');
                                self.hideLoading($btn);
                            }
                        },
                        error: function (jqXHR) {
                            var msg = 'Could not apply suggestion.';
                            try {
                                var resp = JSON.parse(jqXHR.responseText);
                                msg = resp.message || msg;
                            } catch (e) {}
                            self.showToast(msg, 'error');
                            self.hideLoading($btn);
                        }
                    });
                });
            });

            // Reject suggestion
            $(document).on('click', '.wac-reject-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('id');
                var $row = $btn.closest('tr');

                self.confirmAction('Reject this suggestion? It will be dismissed permanently.', function () {
                    var reason = prompt('Reason for rejection (optional):') || '';
                    self.showLoading($btn);

                    $.ajax({
                        url: wacData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wac_reject_suggestion',
                            nonce: wacData.nonce,
                            id: id,
                            reason: reason
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast('Suggestion rejected.', 'info');
                                if ($row.length) {
                                    $row.fadeOut(300, function () { $(this).remove(); });
                                } else {
                                    $btn.closest('.wac-suggestion-card').fadeOut(300);
                                }
                            } else {
                                self.showToast('Failed to reject suggestion.', 'error');
                                self.hideLoading($btn);
                            }
                        },
                        error: function () {
                            self.showToast('Network error rejecting suggestion.', 'error');
                            self.hideLoading($btn);
                        }
                    });
                });
            });
        },

        // ─── Experiment Actions ─────────────────────────────────

        /**
         * View / pause / resume experiments.
         */
        bindExperimentActions: function () {
            var self = this;

            // View experiment details (toggle variant rows)
            $(document).on('click', '.wac-view-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');
                var $rows = $link.closest('tr').nextUntil('tr:not(.wac-variant-row)');
                $rows.toggleClass('wac-hidden');

                var isVisible = $rows.first().is(':visible');
                $link.text(isVisible ? 'Hide Details' : 'View Details');
                $link.attr('aria-expanded', isVisible ? 'true' : 'false');
            });

            // Pause experiment
            $(document).on('click', '.wac-pause-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');

                self.confirmAction('Pause this experiment? Visitors will see the control variant.', function () {
                    self.showLoading($link);

                    $.ajax({
                        url: wacData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wac_pause_experiment',
                            nonce: wacData.nonce,
                            id: id
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast('Experiment paused.', 'success');
                                var $badge = $link.closest('tr').find('.wac-badge-active');
                                $badge
                                    .removeClass('wac-badge-active')
                                    .addClass('wac-badge-paused')
                                    .text('paused');
                                $link.replaceWith(
                                    '<a href="#" class="wac-resume-exp" data-id="' + id + '">Resume</a>'
                                );
                            } else {
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Failed to pause.', 'error');
                                self.hideLoading($link);
                            }
                        },
                        error: function () {
                            self.showToast('Network error pausing experiment.', 'error');
                            self.hideLoading($link);
                        }
                    });
                });
            });

            // Resume experiment
            $(document).on('click', '.wac-resume-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');

                self.confirmAction('Resume this experiment?', function () {
                    self.showLoading($link);

                    $.ajax({
                        url: wacData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wac_resume_experiment',
                            nonce: wacData.nonce,
                            id: id
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast('Experiment resumed.', 'success');
                                var $badge = $link.closest('tr').find('.wac-badge-paused');
                                $badge
                                    .removeClass('wac-badge-paused')
                                    .addClass('wac-badge-active')
                                    .text('active');
                                $link.replaceWith(
                                    '<a href="#" class="wac-pause-exp" data-id="' + id + '">Pause</a>'
                                );
                            } else {
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Failed to resume.', 'error');
                                self.hideLoading($link);
                            }
                        },
                        error: function () {
                            self.showToast('Network error resuming experiment.', 'error');
                            self.hideLoading($link);
                        }
                    });
                });
            });
        },

        // ─── Agent Run ──────────────────────────────────────────

        /**
         * Manual agent run with loading state and toast.
         */
        bindAgentRun: function () {
            var self = this;

            $(document).on('submit', 'form[action*="admin-post"][name]', function () {
                var $form = $(this);
                self.showLoading($form.find(':submit'));
                self.showToast('Running agent...', 'info', 0);

                setTimeout(function () {
                    self.hideLoading($form.find(':submit'));
                }, 60000);
            });

            // AJAX agent run (future use)
            $(document).on('click', '.wac-run-agent-ajax', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var agentKey = $btn.data('agent-key');

                self.confirmAction('Run "' + agentKey + '" agent now?', function () {
                    self.showLoading($btn);

                    $.ajax({
                        url: wacData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wac_run_agent',
                            nonce: wacData.nonce,
                            agent_key: agentKey
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast("Agent '" + agentKey + "' completed successfully.", 'success');
                            } else {
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Agent run failed.', 'error');
                            }
                            self.hideLoading($btn);
                        },
                        error: function () {
                            self.showToast('Network error running agent.', 'error');
                            self.hideLoading($btn);
                        }
                    });
                });
            });
        },

        // ─── Create Experiment ──────────────────────────────────

        /**
         * Create experiment button handler.
         */
        bindCreateExperiment: function () {
            var self = this;

            $(document).on('click', '#wac-create-experiment', function (e) {
                e.preventDefault();
                self.showToast(
                    'Experiment creation wizard coming soon! The AB Optimizer agent will auto-create experiments based on data.',
                    'info',
                    6000
                );
            });
        },

        // ─── Table Sorting ──────────────────────────────────────

        /**
         * Client-side table sorting via data attributes.
         */
        bindTableSorting: function () {
            $(document).on('click', '.wac-sortable', function () {
                var $th = $(this);
                var $table = $th.closest('table');
                var colIndex = $th.index();
                var $rows = $table.find('tbody tr').not('.wac-variant-row').get();
                var isAsc = $th.hasClass('sorted-asc');

                // Reset all headers.
                $table.find('.wac-sortable').removeClass('sorted-asc sorted-desc');

                $th.addClass(isAsc ? 'sorted-desc' : 'sorted-asc');

                $rows.sort(function (a, b) {
                    var aVal = $(a).find('td').eq(colIndex).text().trim();
                    var bVal = $(b).find('td').eq(colIndex).text().trim();

                    // Numeric detection.
                    var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                    var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        return isAsc ? bNum - aNum : aNum - bNum;
                    }

                    return isAsc
                        ? bVal.localeCompare(aVal)
                        : aVal.localeCompare(bVal);
                });

                $.each($rows, function (i, row) {
                    $table.find('tbody').append(row);
                    // Also move any variant detail rows that follow.
                    var $next = $(row).next();
                    while ($next.length && $next.hasClass('wac-variant-row')) {
                        $table.find('tbody').append($next);
                        $next = $(row).next();
                    }
                });
            });
        },

        // ─── Filter / Search Inputs ─────────────────────────────

        /**
         * Debounced quick-filter for tables.
         */
        bindFilterInputs: function () {
            var self = this;
            var debouncedFilter = this.debounce(function () {
                var $input = $(this);
                var query = $input.val().toLowerCase();
                var $table = $input.closest('.wac-tab-content, .wac-card').find('table.widefat');

                if (!$table.length) {
                    $table = $(this).closest('table');
                }

                $table.find('tbody tr').each(function () {
                    var $row = $(this);
                    if ($row.hasClass('wac-variant-row')) {
                        return;
                    }
                    var text = $row.text().toLowerCase();
                    $row.toggleClass('wac-hidden', text.indexOf(query) === -1);
                });
            }, 300);

            $(document).on('keyup', '.wac-table-filter', debouncedFilter);
        },

        // ─── Refresh Buttons ────────────────────────────────────

        /**
         * Refresh button with spinner animation.
         */
        bindRefreshButtons: function () {
            var self = this;

            $(document).on('click', '.wac-refresh-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);

                if ($btn.hasClass('wac-refresh-btn--spinning')) {
                    return;
                }

                $btn.addClass('wac-refresh-btn--spinning');
                window.location.reload();
            });
        },

        // ─── Dismissible Error Blocks ───────────────────────────

        /**
         * Dismiss .wac-error blocks.
         */
        bindDismissibleErrors: function () {
            $(document).on('click', '.wac-error__dismiss', function () {
                $(this).closest('.wac-error').fadeOut(300, function () {
                    $(this).remove();
                });
            });

            // Auto-dismiss success-ish errors after 8s
            $(document).on('mouseenter', '.wac-error', function () {
                $(this).data('wac-hover', true);
            }).on('mouseleave', '.wac-error', function () {
                $(this).data('wac-hover', false);
            });
        },

        // ─── Keyboard Navigation ────────────────────────────────

        /**
         * Enable Enter key on action buttons styled as links.
         */
        bindKeyboardNav: function () {
            $(document).on('keydown', '.wac-action-link, .wac-view-exp, .wac-pause-exp, .wac-resume-exp', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });
        }
    };

    // ─── Boot on DOM ready ──────────────────────────────────────

    $(document).ready(function () {
        WACAdmin.init();
    });

    // Expose globally for debugging.
    window.WACAdmin = WACAdmin;

})(jQuery, window);
