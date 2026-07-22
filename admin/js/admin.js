/**
 * Woo Agentic Checkout — Admin Interface JS
 *
 * Manages suggestion apply/reject, experiment controls, agent runs,
 * toast notifications, loading states, table sorting, and accessibility.
 *
 * @version 0.3.0
 */
(function ($, window, undefined) {
    'use strict';

    var WACAdmin = {
        /** Cached selectors */
        $body: null,

        /**
         * Localised strings fallback.
         */
        _strings: {
            confirmPause:  'Pause this experiment? Visitors will see the control variant.',
            confirmResume: 'Resume this experiment?',
            confirmApply:  'Apply this suggestion to your checkout?',
            confirmReject: 'Reject this suggestion? It will be dismissed permanently.',
            rejectReason:  'Reason for rejection (optional):',
            loading:       'Loading\u2026',
            applying:      'Applying\u2026',
            rejecting:     'Rejecting\u2026',
            errorGeneric:  'Something went wrong. Please try again.',
            errorNetwork:  'Network error. Please check your connection.'
        },

        /**
         * Initialise all admin UI features.
         */
        init: function () {
            this.$body = $(document.body);

            // Merge localised strings if available.
            if (window.wacData && window.wacData.strings) {
                $.extend(this._strings, window.wacData.strings);
            }

            this.ensureToastContainer();
            this.bindSuggestionActions();
            this.bindExperimentActions();
            this.bindAgentRun();
            this.bindCreateExperiment();
            this.bindTableSorting();
            this.bindFilterInputs();
            this.bindRefreshButtons();
            this.bindDismissibleErrors();
            this.bindKeyboardNav();
            this.addAriaLiveRegion();

            // Show notifications from query string (after redirect).
            this.showNotificationFromQuery();

            // Announce page loaded for screen readers.
            this.announce('Admin UI loaded', 'polite');
        },

        // ─── Localised String Helper ───────────────────────────

        /**
         * Get a localised string with optional sprintf-style replacement.
         *
         * @param {string} key  - String key.
         * @param {...*}   args - Replacement values for %s placeholders.
         * @return {string}
         */
        __: function (key) {
            var msg = this._strings[key] || key;
            if (arguments.length > 1) {
                var args = Array.prototype.slice.call(arguments, 1);
                args.forEach(function (val) {
                    msg = msg.replace('%s', val);
                });
            }
            return msg;
        },

        // ─── Toast / Notification System ────────────────────────

        /**
         * Ensure the toast container exists.
         */
        ensureToastContainer: function () {
            if ($('.wac-toast-container').length === 0) {
                $('body').append(
                    '<div class="wac-toast-container" aria-live="polite" aria-relevant="additions"></div>'
                );
            }
        },

        /**
         * Ensure an aria-live region exists for screen reader announcements.
         */
        addAriaLiveRegion: function () {
            if ($('#wac-aria-live').length === 0) {
                $('body').append(
                    '<div id="wac-aria-live" class="screen-reader-text" aria-live="assertive" aria-relevant="additions text"></div>'
                );
            }
        },

        /**
         * Announce a message to screen readers.
         *
         * @param {string} message - The announcement text.
         * @param {string} polite  - 'polite' or 'assertive'.
         */
        announce: function (message, polite) {
            var $el = $('#wac-aria-live');
            if ($el.length === 0) {
                return;
            }
            // Clear first to re-trigger announcement.
            $el.empty();
            setTimeout(function () {
                $el.text(message);
            }, 50);
        },

        /**
         * Show a toast notification.
         *
         * @param {string} message  - Message text.
         * @param {string} type     - 'success', 'error', 'warning', 'info'.
         * @param {number} duration - Auto-dismiss after ms (0 = manual).
         */
        showToast: function (message, type, duration) {
            type = type || 'info';
            duration = (typeof duration === 'number') ? duration : 4000;

            var icons = {
                success: '\u2713',
                error:   '\u2715',
                warning: '\u26A0',
                info:    '\u2139'
            };

            var icon = icons[type] || '\u2139';

            var $container = $('.wac-toast-container');
            if ($container.length === 0) {
                this.ensureToastContainer();
                $container = $('.wac-toast-container');
            }

            var $toast = $(
                '<div class="wac-toast wac-toast--' + type + '" role="alert">' +
                    '<span class="wac-toast__icon" aria-hidden="true">' + icon + '</span>' +
                    '<span class="wac-toast__body">' + this.escHtml(message) + '</span>' +
                    '<button class="wac-toast__dismiss" aria-label="Dismiss">&times;</button>' +
                '</div>'
            );

            $container.append($toast);

            var self = this;
            $toast.find('.wac-toast__dismiss').on('click', function () {
                self.dismissToast($toast);
            });

            if (duration > 0) {
                setTimeout(function () {
                    self.dismissToast($toast);
                }, duration);
            }

            // Announce to screen readers.
            this.announce(message, 'polite');
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
         * Escape HTML entities.
         */
        escHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        // ─── Utilities ──────────────────────────────────────────

        /**
         * Show loading state on a button/element.
         */
        showLoading: function ($el) {
            $el.addClass('wac-loading');
            if ($el.is('button, input[type="submit"]')) {
                $el.data('wac-original-text', $el.val ? $el.val() : $el.text());
                var loadingText = this.__('loading');
                if ($el.is('button')) {
                    $el.text(loadingText);
                } else {
                    $el.val(loadingText);
                }
                $el.prop('disabled', true);
                $el.attr('aria-busy', 'true');
            }
        },

        /**
         * Remove loading state.
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
                $el.removeAttr('aria-busy');
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
         * Show a native confirmation dialog.
         *
         * @param {string}   message  - Confirmation text.
         * @param {Function} callback - Invoked if confirmed.
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
                success:            { text: this.__('successNotice'),      type: 'success' },
                applied:            { text: this.__('appliedNotice'),      type: 'success' },
                rejected:           { text: this.__('rejectedNotice'),     type: 'info' },
                error:              { text: this.__('errorNotice'),        type: 'error' },
                no_agent:           { text: this.__('noAgentNotice'),      type: 'warning' },
                exp_placeholder:    { text: this.__('expPlaceholder'),     type: 'info' },
                service_unavailable: { text: this.__('serviceUnavailable'), type: 'error' }
            };

            // Provide defaults for notices if strings not localised.
            messages.success.text            = messages.success.text || 'Action completed successfully.';
            messages.applied.text            = messages.applied.text || 'Suggestion applied successfully.';
            messages.rejected.text           = messages.rejected.text || 'Suggestion rejected.';
            messages.error.text              = messages.error.text || 'Action failed. Please try again.';
            messages.no_agent.text           = messages.no_agent.text || 'No agent selected.';
            messages.exp_placeholder.text    = messages.exp_placeholder.text || 'Experiment creation wizard coming soon!';
            messages.service_unavailable.text = messages.service_unavailable.text || 'Service unavailable. Please refresh and try again.';

            var notice = messages[msg] || { text: msg, type: 'info' };

            // Override success with agent name if present.
            var agent = this.getQueryParam('wac_agent');
            if (msg === 'success' && agent) {
                notice.text = this.__('agentSuccess', agent);
            }
            if (msg === 'error' && agent) {
                notice.text = this.__('agentError', agent);
            }

            this.showToast(notice.text, notice.type, 5000);

            // Clean the URL without page reload.
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
                var $card = $btn.closest('.wac-suggestion-card');
                var $row = $btn.closest('tr');

                self.confirmAction(self.__('confirmApply'), function () {
                    self.showLoading($btn);
                    $btn.closest('.wac-suggestion-card__actions, td')
                        .find('.wac-spinner').removeClass('wac-hidden');

                    $.ajax({
                        url: wacData.restUrl + '/suggestions/' + id + '/apply',
                        type: 'POST',
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', wacData.nonce);
                        },
                        success: function (response) {
                            if (response.success) {
                                self.showToast(self.__('appliedNotice') || 'Suggestion applied successfully!', 'success');
                                if ($card.length) {
                                    $card.fadeOut(300, function () { $(this).remove(); });
                                } else if ($row.length) {
                                    $row.fadeOut(300, function () { $(this).remove(); });
                                }
                            } else {
                                self.showToast(response.message || self.__('errorGeneric'), 'error');
                                self.hideLoading($btn);
                            }
                        },
                        error: function (jqXHR) {
                            var msg = self.__('errorNetwork');
                            try {
                                var resp = JSON.parse(jqXHR.responseText);
                                msg = resp.message || msg;
                            } catch (e) { /* use default */ }
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
                var $card = $btn.closest('.wac-suggestion-card');
                var $row = $btn.closest('tr');

                self.confirmAction(self.__('confirmReject'), function () {
                    var reason = prompt(self.__('rejectReason')) || '';
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
                                self.showToast(self.__('rejectedNotice') || 'Suggestion rejected.', 'info');
                                if ($card.length) {
                                    $card.fadeOut(300, function () { $(this).remove(); });
                                } else if ($row.length) {
                                    $row.fadeOut(300, function () { $(this).remove(); });
                                }
                            } else {
                                self.showToast(response.message || self.__('errorGeneric'), 'error');
                                self.hideLoading($btn);
                            }
                        },
                        error: function () {
                            self.showToast(self.__('errorNetwork'), 'error');
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

            // View experiment details (toggle variant rows).
            $(document).on('click', '.wac-view-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');
                var $rows = $link.closest('tr').nextUntil('tr:not(.wac-variant-row)');
                $rows.toggleClass('wac-hidden');

                var isVisible = $rows.first().is(':visible');
                $link.text(isVisible ? 'Hide Details' : 'View');
                $link.attr('aria-expanded', isVisible ? 'true' : 'false');
            });

            // Pause experiment
            $(document).on('click', '.wac-pause-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');

                self.confirmAction(self.__('confirmPause'), function () {
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
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Experiment paused.', 'success');
                                var $badge = $link.closest('tr').find('.wac-badge-active');
                                $badge
                                    .removeClass('wac-badge-active')
                                    .addClass('wac-badge-paused')
                                    .text('paused');
                                $link.replaceWith(
                                    '<button class="wac-action-link wac-resume-exp" data-id="' + id + '" title="Resume this experiment">Resume</button>'
                                );
                            } else {
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Failed to pause.', 'error');
                                self.hideLoading($link);
                            }
                        },
                        error: function () {
                            self.showToast(self.__('errorNetwork'), 'error');
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

                self.confirmAction(self.__('confirmResume'), function () {
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
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Experiment resumed.', 'success');
                                var $badge = $link.closest('tr').find('.wac-badge-paused');
                                $badge
                                    .removeClass('wac-badge-paused')
                                    .addClass('wac-badge-active')
                                    .text('active');
                                $link.replaceWith(
                                    '<button class="wac-action-link wac-pause-exp" data-id="' + id + '" title="Pause this experiment">Pause</button>'
                                );
                            } else {
                                self.showToast(response.data && response.data.message
                                    ? response.data.message : 'Failed to resume.', 'error');
                                self.hideLoading($link);
                            }
                        },
                        error: function () {
                            self.showToast(self.__('errorNetwork'), 'error');
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

            // Admin-post form submission.
            $(document).on('submit', 'form[action*="admin-post"][name]', function () {
                var $form = $(this);
                self.showLoading($form.find(':submit'));
                self.showToast('Running agent...', 'info', 0);

                setTimeout(function () {
                    self.hideLoading($form.find(':submit'));
                }, 60000);
            });

            // AJAX agent run.
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
                                self.showToast(
                                    self.__('agentSuccess', agentKey) ||
                                        "Agent '" + agentKey + "' completed successfully.",
                                    'success'
                                );
                            } else {
                                self.showToast(
                                    response.data && response.data.message
                                        ? response.data.message
                                        : 'Agent run failed.',
                                    'error'
                                );
                            }
                            self.hideLoading($btn);
                        },
                        error: function () {
                            self.showToast(self.__('errorNetwork'), 'error');
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
                    self.__('expPlaceholder') || 'Experiment creation wizard coming soon!',
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
                    var aNum = parseFloat(aVal.replace(/[^0-9.\-]/g, ''));
                    var bNum = parseFloat(bVal.replace(/[^0-9.\-]/g, ''));
                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        return isAsc ? bNum - aNum : aNum - bNum;
                    }

                    return isAsc
                        ? bVal.localeCompare(aVal)
                        : aVal.localeCompare(bVal);
                });

                $.each($rows, function (i, row) {
                    $table.find('tbody').append(row);
                    // Also move variant detail rows that follow.
                    var $next = $(row).next();
                    while ($next.length && $next.hasClass('wac-variant-row')) {
                        $table.find('tbody').append($next);
                        $next = $(row).next();
                    }
                });

                // Announce sort change.
                var direction = isAsc ? 'descending' : 'ascending';
                var colLabel = $th.text().trim().replace(/[\u2191\u2193\u2195]/, '').trim();
                WACAdmin.announce('Sorted by ' + colLabel + ' ' + direction, 'polite');
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

                var visibleCount = 0;
                $table.find('tbody tr').each(function () {
                    var $row = $(this);
                    if ($row.hasClass('wac-variant-row')) {
                        return;
                    }
                    var text = $row.text().toLowerCase();
                    var match = text.indexOf(query) === -1;
                    $row.toggleClass('wac-hidden', match);
                    if (!match) {
                        visibleCount++;
                    }
                });

                // Announce filter results for screen readers.
                self.announce(
                    'Filtered to ' + visibleCount + ' visible ' + (visibleCount === 1 ? 'row' : 'rows'),
                    'polite'
                );
            }, 300);

            $(document).on('keyup', '.wac-table-filter', debouncedFilter);
        },

        // ─── Refresh Buttons ────────────────────────────────────

        /**
         * Refresh button with spinner animation.
         */
        bindRefreshButtons: function () {
            $(document).on('click', '.wac-refresh-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);

                if ($btn.hasClass('wac-refresh-btn--spinning')) {
                    return;
                }

                $btn.addClass('wac-refresh-btn--spinning');
                WACAdmin.announce('Refreshing dashboard', 'polite');
                window.location.reload();
            });
        },

        // ─── Dismissible Error Blocks ───────────────────────────

        /**
         * Dismiss .wac-error blocks.
         */
        bindDismissibleErrors: function () {
            $(document).on('click', '.wac-error__dismiss', function () {
                var $error = $(this).closest('.wac-error');
                $error.fadeOut(300, function () {
                    $error.remove();
                });
            });
        },

        // ─── Keyboard Navigation ────────────────────────────────

        /**
         * Enable Enter/Space keys on action buttons styled as links.
         */
        bindKeyboardNav: function () {
            $(document).on('keydown', '.wac-action-link, button.wac-view-exp, button.wac-pause-exp, button.wac-resume-exp, .wac-refresh-btn:not(.wac-refresh-btn--spinning)', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });

            // Tab trapping inside toast container (focus management).
            $(document).on('keydown', '.wac-toast:last-child .wac-toast__dismiss', function (e) {
                if (e.key === 'Tab' && !e.shiftKey) {
                    e.preventDefault();
                    // Focus first toast or first focusable in the container.
                    var $toasts = $('.wac-toast');
                    if ($toasts.length > 1) {
                        $toasts.first().find('.wac-toast__dismiss').focus();
                    }
                }
            });
        }
    };

    // ─── Boot on DOM ready ──────────────────────────────────────

    $(document).ready(function () {
        WACAdmin.init();
    });

    // Expose globally for debugging and extensibility.
    window.WACAdmin = WACAdmin;

})(jQuery, window);
