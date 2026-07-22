/**
 * Woo Agentic Checkout — Checkout Tracker Beacon
 *
 * Captures UX telemetry: step completion times, errors, field interactions,
 * and sends them to the server for signal collection + A/B analysis.
 *
 * @version 0.1.0-alpha
 */
(function ($) {
    'use strict';

    var WACBeacon = {
        /** Session identifier */
        sessionId: wacBeacon.session || '',

        /** Active experiment variants */
        experiments: window._wacExperiments || [],

        /** Checkout step tracking */
        steps: {
            current: 'checkout_started',
            timings: {},
            errors: []
        },

        /** Rate limiter state */
        _lastEventTime: {},
        _eventQueue: [],
        _flushTimer: null,

        /**
         * Throttled send — max 1 event per event type per 200ms, batch heavy events.
         */
        _sendThrottled: function (event, data) {
            if (!this.sessionId) return;

            var now = Date.now();
            var key = event;
            var last = this._lastEventTime[key] || 0;

            // Skip duplicate event types within 200ms.
            if (now - last < 200) {
                return;
            }
            this._lastEventTime[key] = now;

            // Rate-limit field_interaction events to one per 2 seconds max.
            if (event === 'field_interaction') {
                if (now - (this._lastEventTime['_field_interaction_last'] || 0) < 2000) {
                    return;
                }
                this._lastEventTime['_field_interaction_last'] = now;
            }

            this._sendRaw(event, data);
        },

        /**
         * Batch low-priority events and flush periodically.
         */
        _enqueueBatched: function (event, data) {
            this._eventQueue.push({ event: event, data: data, time: Date.now() });

            if (this._eventQueue.length >= 5) {
                this._flushBatch();
                return;
            }

            if (!this._flushTimer) {
                var self = this;
                this._flushTimer = setTimeout(function () {
                    self._flushBatch();
                }, 3000);
            }
        },

        /**
         * Flush batched events in a single AJAX call.
         */
        _flushBatch: function () {
            if (this._flushTimer) {
                clearTimeout(this._flushTimer);
                this._flushTimer = null;
            }

            if (this._eventQueue.length === 0) return;

            var batch = this._eventQueue.splice(0, this._eventQueue.length);
            this._sendRaw('batch_events', { events: batch });
        },

        /**
         * Initialize beacon.
         */
        init: function () {
            if (typeof wacBeacon === 'undefined') return;

            this.trackStep('checkout_started');
            this.bindEvents();
            this.trackFieldInteractions();
        },

        /**
         * Bind to WooCommerce checkout events.
         */
        bindEvents: function () {
            var self = this;

            // Checkout step changes
            $(document.body).on('checkout_error', function () {
                self.trackError('checkout_error', 'Checkout process error');
            });

            $(document.body).on('updated_checkout', function () {
                self.trackStep('checkout_updated');
            });

            // Payment method selection
            $(document.body).on('payment_method_selected', function () {
                self.trackStep('payment_selected');
            });

            // Place order click
            $(document.body).on('checkout_place_order', function () {
                self.trackStep('place_order_clicked');
            });

            // Order success (redirect)
            if ($('.woocommerce-order').length) {
                self.trackStep('order_placed', {
                    orderId: $('.woocommerce-order .order').data('order-id') || 0
                });
            }

            // Track when billing/shipping sections are completed via validation
            $(document.body).on('checkout_process', function () {
                var billingOk = true,
                    shippingOk = true;

                $('#billing .validate-required').each(function () {
                    if (!$(this).find('input, select').val()) billingOk = false;
                });

                if (billingOk) {
                    self.trackStep('billing_completed');
                }

                if ($('#ship-to-different-address-checkbox').is(':checked')) {
                    $('#shipping .validate-required').each(function () {
                        if (!$(this).find('input, select').val()) shippingOk = false;
                    });
                    if (shippingOk) {
                        self.trackStep('shipping_completed');
                    }
                } else {
                    self.trackStep('shipping_completed'); // Same as billing
                }
            });

            // JS error tracking
            window.addEventListener('error', function (e) {
                self.trackError('js_error', e.message, {
                    file: e.filename,
                    line: e.lineno,
                    col: e.colno
                });
            });

            window.addEventListener('unhandledrejection', function (e) {
                self.trackError('unhandled_promise', e.reason ? e.reason.message : 'Unknown');
            });

            // Page visibility (tab switch during checkout = abandonment risk)
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') {
                    self.sendEvent('checkout_tab_hidden', {
                        step: self.steps.current,
                        timeOnPage: self.steps.timings[self.steps.current]
                    });
                }
            });
        },

        /**
         * Track field interactions (focus/blur timing).
         */
        trackFieldInteractions: function () {
            var self = this;

            $('.woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea').each(function () {
                var $field = $(this),
                    fieldName = $field.attr('name') || $field.attr('id') || 'unknown',
                    startTime;

                $field.on('focus', function () {
                    startTime = Date.now();
                });

                $field.on('blur', function () {
                    if (startTime) {
                        var elapsed = Date.now() - startTime;
                        if (elapsed > 500) {
                            self.sendEvent('field_interaction', {
                                field: fieldName,
                                timeMs: elapsed
                            });
                        }
                    }
                });
            });
        },

        /**
         * Track a checkout step.
         */
        trackStep: function (stepName, extraData) {
            this.steps.current = stepName;
            this.steps.timings[stepName] = Date.now();

            this.sendEvent(stepName, extraData);
        },

        /**
         * Track an error event.
         */
        trackError: function (type, message, extra) {
            this.steps.errors.push({
                type: type,
                message: message,
                time: Date.now()
            });

            this.sendEvent(type, $.extend({
                message: message
            }, extra));
        },

        /**
         * Send an event to the server via AJAX (unthrottled — use _sendThrottled or _enqueueBatched instead).
         */
        _sendRaw: function (event, data) {
            if (!this.sessionId) return;

            $.ajax({
                url: wacBeacon.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wac_beacon',
                    nonce: wacBeacon.nonce || '',
                    event: event,
                    session: this.sessionId,
                    data: JSON.stringify(data || {})
                },
                timeout: 3000,
                error: function (jqXHR, textStatus, errorThrown) {
                    // Silently log, don't disrupt checkout.
                    if (window.console && console.warn) {
                        console.warn('WAC beacon error:', textStatus, errorThrown);
                    }
                }
            });
        }
    };

    // Boot on DOM ready
    $(document).ready(function () {
        WACBeacon.init();
    });

})(jQuery);
