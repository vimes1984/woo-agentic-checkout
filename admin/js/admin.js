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
            unsavedWarning: 'You have unsaved settings changes.',
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

            // Guard against missing wacData before binding AJAX.
            this.checkDependencies();
            this._hasData = (typeof wacData !== 'undefined' && wacData && wacData.ajaxUrl);

            // Merge localised strings if available.
            if (window.wacData && window.wacData.strings) {
                $.extend(this._strings, window.wacData.strings);
            }

            this.ensureToastContainer();

            // Safe bindings — only bind AJAX actions if wacData is available.
            if (this._hasData) {
                this.bindSuggestionActions();
                this.bindExperimentActions();
                this.bindAgentRun();
            } else {
                if (window.console && window.console.warn) {
                    window.console.warn('WAC Admin: wacData missing — AJAX actions disabled.');
                }
            }

            this.bindCreateExperiment();
            this.bindTableSorting();
            this.bindFilterInputs();
            this.bindRefreshButtons();
            this.bindDismissibleErrors();
            this.bindKeyboardNav();
            this.bindFormValidation();
            this.bindBatchDismiss();
            this.bindTabFocus();
            this.bindUnsavedChangesWarning();
            this.bindResponsiveResize();
            this.addAriaLiveRegion();

            // Auto-focus the first filter input on page load for quicker keyboard nav.
            this.autoFocusFilter();

            // Show notifications from query string (after redirect).
            this.showNotificationFromQuery();

            // Start auto-refresh if on dashboard.
            this.startAutoRefresh();

            // Pause refresh when tab goes background.
            this.handleVisibilityChange();

            // Clean up on page unload to prevent orphaned timers.
            var self = this;
            $(window).on('beforeunload', function () {
                self.stopAutoRefresh();
            });

            // Observe dynamic content for event re-binding.
            this.observeDynamicContent();

            // Mark body as ready for CSS targeting.
            $(document.body).addClass('wac-ready');

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
                    '<div class="wac-toast-container" aria-live="polite" aria-relevant="additions" style="position:fixed;z-index:999999;"></div>'
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
         * @param {number} duration - Auto-dismiss after ms (0 = manual, 0 = also for errors/warnings).
         */
        showToast: function (message, type, duration) {
            type = type || 'info';

            // Default durations: errors/warnings manual dismiss, others auto.
            if (typeof duration !== 'number') {
                duration = (type === 'error' || type === 'warning') ? 0 : 4000;
            }

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

            // Scroll to error toasts so they're visible.
            if (type === 'error') {
                this.smoothScroll($toast, 80);
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
         * Focus management — move focus to the first focusable element
         * inside a container after an AJAX update.
         *
         * @param {jQuery} $container - The updated container.
         */
        focusFirstFocusable: function ($container) {
            if (!$container || !$container.length) {
                return;
            }
            var $focusable = $container.find(
                'a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])'
            ).first();
            if ($focusable.length) {
                $focusable.focus();
            }
        },

        /**
         * Smooth-scroll to an element.
         *
         * @param {jQuery} $el    - Target element.
         * @param {number} offset - Extra offset from top (default: 40).
         */
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
            if (window.confirm(String(message))) {
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

            // Ignore unknown messages — prevents phishing via arbitrary URL param values.
            if ( ! messages[ msg ] ) {
                return;
            }
            var notice = messages[ msg ];

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

        // ─── Keyboard Shortcuts (desktop) ──────────────────────
        //
        // Alt+Shift+F  Focus filter input on current tab
        // Alt+Shift+R  Refresh dashboard data
        // Escape      Close active toast / clear filter field
        // Tab         Navigate cards and action buttons
        // Enter/Space Activate the focused button or link
        //
        // ─── Suggestion Actions ─────────────────────────────────

        /**
         * Apply / reject suggestion buttons.
         */
        bindSuggestionActions: function () {
            var self = this;

            // Debounced apply to prevent double-clicks.
            var applyDebounced = this.debounce(function ($btn, id, $card, $row) {
                self.confirmAction(self.__('confirmApply'), function () {
                    self.showLoading($btn);
                    $btn.closest('.wac-suggestion-card__actions, td')
                        .find('.wac-spinner').removeClass('wac-hidden');

                    $.ajax({
                        url: wacData.restUrl + '/suggestions/' + encodeURIComponent(id) + '/apply',
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
                                var errMsg = response.data && response.data.message
                                    ? response.data.message
                                    : self.__('errorGeneric');
                                self.showToast(errMsg, 'error');
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
            }, 500);

            // Apply suggestion
            $(document).on('click', '.wac-apply-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('id');
                var $card = $btn.closest('.wac-suggestion-card');
                var $row = $btn.closest('tr');

                applyDebounced($btn, id, $card, $row);
            });

            // Debounced reject to prevent double-clicks.
            var rejectDebounced = this.debounce(function ($btn, id, $card, $row) {
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
                                var errMsg = response.data && response.data.message
                                    ? response.data.message
                                    : self.__('errorGeneric');
                                self.showToast(errMsg, 'error');
                                self.hideLoading($btn);
                            }
                        },
                        error: function () {
                            self.showToast(self.__('errorNetwork'), 'error');
                            self.hideLoading($btn);
                        }
                    });
                });
            }, 500);

            // Reject suggestion
            $(document).on('click', '.wac-reject-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('id');
                var $card = $btn.closest('.wac-suggestion-card');
                var $row = $btn.closest('tr');

                rejectDebounced($btn, id, $card, $row);
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

            // Click on experiment table row to toggle details.
            $(document).on('click', '#wac-experiments-table tbody tr[data-exp-id]', function (e) {
                // Don't toggle if clicking a button or link inside the row.
                if ($(e.target).is('button, a, .wac-badge, .wac-action-link')) {
                    return;
                }
                var $row = $(this);
                var $viewBtn = $row.find('.wac-view-exp');
                if ($viewBtn.length) {
                    $viewBtn.trigger('click');
                }
            });

            // Debounced pause
            var pauseDebounced = this.debounce(function ($link, id) {
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
                                    '<button class="wac-action-link wac-resume-exp" data-id="' + self.escHtml(String(id)) + '" title="Resume this experiment">Resume</button>'
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
            }, 500);

            // Pause experiment
            $(document).on('click', '.wac-pause-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');
                pauseDebounced($link, id);
            });

            // Debounced resume
            var resumeDebounced = this.debounce(function ($link, id) {
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
                                    '<button class="wac-action-link wac-pause-exp" data-id="' + self.escHtml(String(id)) + '" title="Pause this experiment">Pause</button>'
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
            }, 500);

            // Resume experiment
            $(document).on('click', '.wac-resume-exp', function (e) {
                e.preventDefault();
                var $link = $(this);
                var id = $link.data('id');
                resumeDebounced($link, id);
            });
        },

        // ─── Agent Run ──────────────────────────────────────────

        /**
         * Manual agent run with loading state and toast.
         */
        bindAgentRun: function () {
            var self = this;

            // Admin-post form submission — confirm and show inline spinners.
            $(document).on('submit', 'form.wac-quick-action-form, form.wac-agent-run-form', function (e) {
                var $form = $(this);
                var agentLabel = $form.find('input[name="agent_label"]').val();
                if (!agentLabel) {
                    var $select = $form.find('select[name="agent_key"]');
                    agentLabel = $select.length ? $select.find('option:selected').text() : 'agent';
                }

                // Confirmation for agent run.
                if (!window.confirm('Run "' + agentLabel + '" now?')) {
                    e.preventDefault();
                    return;
                }

                var $submitBtn = $form.find(':submit');
                self.showLoading($submitBtn);
                // Show any inline spinners.
                $form.find('.wac-spinner').show();
                self.showToast('Running ' + agentLabel + '...', 'info', 0);

                setTimeout(function () {
                    self.hideLoading($submitBtn);
                    $form.find('.wac-spinner').hide();
                }, 60000);
            });

            // Settings form submission — show loading on save button.
            $(document).on('submit', '.wac-settings-form', function () {
                var $form = $(this);
                var $submitBtn = $form.find(':submit');
                self.showLoading($submitBtn);
                // Timeout to restore after normal form submission reload.
                setTimeout(function () {
                    self.hideLoading($submitBtn);
                }, 30000);
            });

            // AJAX agent run.
            $(document).on('click', '.wac-run-agent-ajax', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var agentKey = String($btn.data('agent-key') || '').trim();

                if (!agentKey) {
                    self.showToast(self.__('noAgentNotice') || 'No agent selected.', 'warning');
                    return;
                }

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

        // ─── Form Validation ──────────────────────────────────

        /**
         * Client-side validation for settings forms.
         * Highlights invalid fields and shows messages.
         */
        bindFormValidation: function () {
            var self = this;

            $(document).on('submit', '.wac-settings-form', function (e) {
                var $form = $(this);
                var isValid = true;

                // Remove existing error states.
                $form.find('.wac-field-error, .wac-field-valid').removeClass('wac-field-error wac-field-valid');
                $form.find('.wac-field-error-message').remove();

                // Validate required fields.
                $form.find('[required]').each(function () {
                    var $field = $(this);
                    if (!$field.val() || $field.val().trim() === '') {
                        self.markFieldError($field, 'This field is required.');
                        isValid = false;
                    } else {
                        self.markFieldValid($field);
                    }
                });

                // Validate pattern fields.
                $form.find('[pattern]').each(function () {
                    var $field = $(this);
                    var pattern = $field.attr('pattern');
                    if ($field.val() && $field.val().trim() !== '') {
                        var re = new RegExp('^' + pattern + '$');
                        if (!re.test($field.val())) {
                            var title = $field.attr('title') || 'Invalid format.';
                            self.markFieldError($field, title);
                            isValid = false;
                        } else {
                            self.markFieldValid($field);
                        }
                    }
                });

                // Validate email fields.
                $form.find('input[type="email"]').each(function () {
                    var $field = $(this);
                    var val = $field.val();
                    if (val && val.trim() !== '') {
                        var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRe.test(val)) {
                            self.markFieldError($field, 'Please enter a valid email address.');
                            isValid = false;
                        } else {
                            self.markFieldValid($field);
                        }
                    }
                });

                // Check conditional validation (API key required if provider not Ollama).
                $form.find('[data-wac-validate="required-if-provider-not-ollama"]').each(function () {
                    var $field = $(this);
                    var providerFieldName = $field.data('wac-provider-field');
                    var $provider = $form.find('[name="' + providerFieldName + '"]');
                    var provider = $provider.val();
                    if (provider !== 'ollama' && (!$field.val() || $field.val().trim() === '')) {
                        self.markFieldError($field, 'API Key is required for this provider.');
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    var $firstError = $form.find('.wac-field-error').first();
                    if ($firstError.length) {
                        $firstError.focus();
                    }
                    self.showToast('Please fix the highlighted fields before saving.', 'error', 4000);
                    // Scroll to first error.
                    $('html, body').animate({
                        scrollTop: $firstError.offset().top - 100
                    }, 200);
                }
            });

            // Real-time validation on blur.
            $(document).on('blur', '.wac-settings-form [required], .wac-settings-form [pattern]', function () {
                var $field = $(this);
                $field.removeClass('wac-field-error wac-field-valid');
                $field.closest('td').find('.wac-field-error-message').remove();

                if ($field.attr('required') && (!$field.val() || $field.val().trim() === '')) {
                    self.markFieldError($field, 'This field is required.');
                } else if ($field.attr('pattern') && $field.val() && $field.val().trim() !== '') {
                    var re = new RegExp('^' + $field.attr('pattern') + '$');
                    if (!re.test($field.val())) {
                        self.markFieldError($field, $field.attr('title') || 'Invalid format.');
                    } else {
                        self.markFieldValid($field);
                    }
                } else if ($field.val() && $field.val().trim() !== '') {
                    self.markFieldValid($field);
                }
            });
        },

        /**
         * Mark a field as having an error.
         */
        markFieldError: function ($field, message) {
            $field.addClass('wac-field-error');
            var $td = $field.closest('td');
            $td.find('.wac-field-error-message').remove();
            $td.append('<p class="wac-field-error-message">\u26A0 ' + this.escHtml(message) + '</p>');
        },

        /**
         * Mark a field as valid.
         */
        markFieldValid: function ($field) {
            $field.addClass('wac-field-valid');
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

                // Persist filter state in sessionStorage.
                var filterKey = 'wac_filter_' + ($input.attr('id') || 'default');
                if (query) {
                    sessionStorage.setItem(filterKey, query);
                } else {
                    sessionStorage.removeItem(filterKey);
                }

                // Show visible count.
                var $countEl = $input.closest('.wac-filter-row').find('.wac-filter-count');
                if (!$countEl.length) {
                    $countEl = $('<span class="wac-filter-count"></span>');
                    $input.closest('.wac-filter-row').append($countEl);
                }
                $countEl.text(visibleCount + ' visible');

                // Announce filter results for screen readers.
                self.announce(
                    'Filtered to ' + visibleCount + ' visible ' + (visibleCount === 1 ? 'row' : 'rows'),
                    'polite'
                );
            }, 300);

            $(document).on('keyup', '.wac-table-filter', debouncedFilter);

            // Restore saved filter queries from sessionStorage.
            $('.wac-table-filter').each(function () {
                var $input = $(this);
                var filterKey = 'wac_filter_' + ($input.attr('id') || 'default');
                var saved = sessionStorage.getItem(filterKey);
                if (saved) {
                    $input.val(saved);
                    $input.trigger('keyup');
                }
            });

            // Clear filter on Escape key.
            $(document).on('keydown', '.wac-table-filter', function (e) {
                if (e.key === 'Escape') {
                    var $input = $(this);
                    $input.val('').trigger('keyup');
                    $input.blur();
                }
            });
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
         * Tab switch focus management — announce tab change to screen readers.
         */
        bindTabFocus: function () {
            var self = this;
            $(document).on('click', '.wac-tabs .nav-tab', function () {
                var $tab = $(this);
                var tabName = $tab.text().trim();
                self.announce('Switched to ' + tabName + ' tab', 'polite');
            });
        },

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
            var self = this;

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

            // Escape key dismisses all toasts.
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') {
                    var $toasts = $('.wac-toast');
                    if ($toasts.length) {
                        $toasts.each(function () {
                            self.dismissToast($(this));
                        });
                        self.announce('Notifications dismissed', 'polite');
                    }
                }
            });

            // Arrow key navigation between tabs.
            $(document).on('keydown', '.wac-tabs', function (e) {
                var $tabs = $(this).find('.nav-tab');
                var $current = $tabs.filter('.nav-tab-active');
                var index = $tabs.index($current);

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    var nextIndex = Math.min(index + 1, $tabs.length - 1);
                    $tabs.eq(nextIndex).focus();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var prevIndex = Math.max(index - 1, 0);
                    $tabs.eq(prevIndex).focus();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    $tabs.first().focus();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    $tabs.last().focus();
                }
            });
        },

        // ─── Auto-Refresh / Polling ──────────────────────────

        /**
         * Handle page visibility changes — pause auto-refresh when tab is hidden.
         */
        handleVisibilityChange: function () {
            var self = this;
            $(document).on('visibilitychange', function () {
                if (document.hidden) {
                    self.stopAutoRefresh();
                } else {
                    self.startAutoRefresh();
                    self.announce('Dashboard resumed', 'polite');
                }
            });
        },

        /**
         * Start a polling interval to auto-refresh dashboard stats.
         */
        startAutoRefresh: function () {
            var self = this;

            // Only on the dashboard tab.
            if ($('.wac-dashboard-grid').length === 0) {
                return;
            }

            this._refreshInterval = setInterval(function () {
                var href = window.location.href;
                // Remove timestamp param if present to avoid cache.
                var url = href.split('?')[0];
                var params = new URLSearchParams(window.location.search);
                params.set('wac_ts', Date.now());
                url = url + '?' + params.toString();

                $.get(url, function (html) {
                    var $newContent = $(html).find('.wac-dashboard-grid').first();
                    var $oldContent = $('.wac-dashboard-grid');
                    if ($newContent.length && $oldContent.length) {
                        $oldContent.replaceWith($newContent);
                        self.announce('Dashboard refreshed', 'polite');
                    }
                }).fail(function () {
                    // Silent fail — refresh will retry.
                });
            }, 120000); // Every 2 minutes.
        },

        /**
         * Stop the auto-refresh interval.
         */
        stopAutoRefresh: function () {
            if (this._refreshInterval) {
                clearInterval(this._refreshInterval);
                this._refreshInterval = null;
            }
        },

        /**
         * MutationObserver to re-bind event handlers on dynamically
         * loaded content (e.g., after AJAX table refresh).
         */
        observeDynamicContent: function () {
            var self = this;
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length) {
                        // Re-bind sortable headers if new tables were added.
                        $(mutation.addedNodes).find('.wac-sortable').off('click.wacSort');
                        $(mutation.addedNodes).find('.wac-error__dismiss').off('click.wacDismiss');
                    }
                });
            });

            observer.observe(document.querySelector('.wac-tab-content') || document.body, {
                childList: true,
                subtree: true
            });
        },

        /**
         * Debounced window resize handler for responsive adjustments.
         */
        bindResponsiveResize: function () {
            var resizeTimer;
            $(window).on('resize.wacResponsive', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    // Close tooltips / popovers that may be out of position.
                    $('.wac-tooltip').remove();
                }, 250);
            });
        },

        /**
         * Auto-focus the first visible filter input for keyboard users.
         * Only activates on desktop (>= 782px) to avoid virtual keyboard issues.
         */
        autoFocusFilter: function () {
            var $filter = $('.wac-table-filter:visible').first();
            if ($filter.length && window.innerWidth > 782) {
                $filter.focus();
            }
        },

        /**
         * Add batch dismiss-all toasts button.
         */
        bindBatchDismiss: function () {
            var self = this;

            // Double-click on the toast container dismisses all.
            $(document).on('dblclick', '.wac-toast-container', function () {
                $('.wac-toast').each(function () {
                    self.dismissToast($(this));
                });
                self.announce('All notifications dismissed', 'polite');
            });

            // On mobile, add a "Dismiss All" link when 3+ toasts are visible.
            var observer = new MutationObserver(function () {
                var $container = $('.wac-toast-container');
                var count = $container.find('.wac-toast').length;
                var $dismissAll = $container.find('.wac-toast-dismiss-all');

                if (count >= 3 && $dismissAll.length === 0) {
                    $container.append(
                        '<button class="wac-toast-dismiss-all" style="pointer-events:auto;font-size:11px;color:var(--wac-text-muted);background:none;border:none;cursor:pointer;text-align:right;padding:4px 0;">Dismiss All</button>'
                    );
                    $container.find('.wac-toast-dismiss-all').on('click', function () {
                        $('.wac-toast').each(function () {
                            self.dismissToast($(this));
                        });
                        $(this).remove();
                    });
                } else if (count < 3) {
                    $dismissAll.remove();
                }
            });

            var container = document.querySelector('.wac-toast-container');
            if (container) {
                observer.observe(container, { childList: true, subtree: true });
            }
        },

        /**
         * Warn user before leaving settings with unsaved changes.
         */
        bindUnsavedChangesWarning: function () {
            var self = this;
            var formDirty = false;

            $(document).on('change', '.wac-settings-form input, .wac-settings-form select, .wac-settings-form textarea', function () {
                formDirty = true;
            });

            $(document).on('submit', '.wac-settings-form', function () {
                formDirty = false;
            });

            $(window).on('beforeunload', function () {
                if (formDirty) {
                    return self.__('unsavedWarning') || 'You have unsaved changes.';
                }
            });
        },

        /**
         * Focus an element and optionally set caret position.
         */
        focusAndCaret: function ($el) {
            if ($el && $el.length) {
                $el.focus();
                if ($el.is('input, textarea')) {
                    var len = $el.val().length;
                    $el[0].setSelectionRange(len, len);
                }
            }
        },

        /**
         * Scroll a target element into view with smooth animation.
         * Accepts a jQuery object or CSS selector string.
         */
        smoothScroll: function (selector, offset) {
            offset = offset || 40;
            var $el = $(selector);
            if ($el.length) {
                $('html, body').animate({
                    scrollTop: $el.offset().top - offset
                }, 250);
            }
        },

        /**
         * Log AJAX errors with detailed info to console for debugging.
         */
        logAjaxError: function (action, jqXHR, textStatus) {
            if (window.console && window.console.warn) {
                window.console.warn('WAC AJAX Error [' + action + ']:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText ? jqXHR.responseText.substring(0, 200) : '',
                    textStatus: textStatus
                });
            }
        },

        /**
         * Check that critical dependencies are loaded.
         */
        checkDependencies: function () {
            if (typeof wacData === 'undefined') {
                if (window.console && window.console.warn) {
                    window.console.warn('WAC Admin: wacData is undefined. AJAX actions will fail.');
                }
                return;
            }

            if (typeof wacData.ajaxUrl === 'undefined' || !wacData.ajaxUrl) {
                if (window.console && window.console.warn) {
                    window.console.warn('WAC Admin: wacData.ajaxUrl is missing.');
                }
            }

            if (typeof wacData.nonce === 'undefined' || !wacData.nonce) {
                if (window.console && window.console.warn) {
                    window.console.warn('WAC Admin: wacData.nonce is missing. AJAX security will fail.');
                }
            }

            if (typeof jQuery === 'undefined') {
                if (window.console && window.console.error) {
                    window.console.error('WAC Admin: jQuery is required.');
                }
            }
        }
    };

    // ─── Boot on DOM ready ──────────────────────────────────────

    $(document).ready(function () {
        WACAdmin.init();
    });

    // Expose globally for debugging and extensibility.
    window.WACAdmin = WACAdmin;

})(jQuery, window);
