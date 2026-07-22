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

        /** Nonce from PHP */
        _nonce: window._wacNonce || wacBeacon.nonce || '',

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
        /** Per-field focus times (shared across function scopes) */
        _fieldTimes: {},
        /** Max retries for failed beacon sends */
        _maxRetries: 2,

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
        /** Page load timestamp */
        _pageLoadTime: Date.now(),

        init: function () {
            if (typeof wacBeacon === 'undefined') return;
            // Clear stale _fieldTimes to prevent memory leaks from abandoned fields.
            this._fieldTimes = {};

            this._pageLoadTime = Date.now();
            this.trackStep('checkout_started');
            this.bindEvents();
            this.trackFieldInteractions();
            this._trackSessionDuration();
        },

        /** Report session duration after 30s to measure engagement */
        _trackSessionDuration: function () {
            var self = this;
            setTimeout(function () {
                self._sendThrottled('session_active_30s', {
                    duration: Date.now() - self._pageLoadTime
                });
            }, 30000);
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

            // Coupon applied
            $(document.body).on('applied_coupon', function (e, coupon) {
                self.trackStep('coupon_applied', { coupon: coupon });
            });
            $(document.body).on('removed_coupon', function (e, coupon) {
                self.trackStep('coupon_removed', { coupon: coupon });
            });

            // Shipping method changed
            $(document.body).on('updated_shipping_method', function () {
                self.trackStep('shipping_method_changed');
            });

            // Place order click
            $(document.body).on('checkout_place_order', function () {
                self.trackStep('place_order_clicked');
            });

            // Order submission time (from click to success/error).
            var placeOrderTime = null;
            $(document.body).on('checkout_place_order', function () {
                placeOrderTime = Date.now();
            });
            $(document.body).on('checkout_error', function () {
                if (placeOrderTime) {
                    self._sendThrottled('checkout_submit_duration', {
                        result: 'error',
                        durationMs: Date.now() - placeOrderTime
                    });
                    placeOrderTime = null;
                }
            });
            $(document.body).on('checkout_place_order_success checkout_processed', function () {
                if (placeOrderTime) {
                    self._sendThrottled('checkout_submit_duration', {
                        result: 'success',
                        durationMs: Date.now() - placeOrderTime
                    });
                    placeOrderTime = null;
                }
            });

            // Order success (redirect) — also detect via URL hash for SPAs.
            if ($('.woocommerce-order').length) {
                self.trackStep('order_placed', {
                    orderId: $('.woocommerce-order .order').data('order-id') || 0
                });
            } else if (window.location.href.indexOf('order-received') > -1) {
                var params = new URLSearchParams(window.location.search);
                self.trackStep('order_placed', {
                    orderId: params.get('order_id') || 0
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
                    self._sendThrottled('checkout_tab_hidden', {
                        step: self.steps.current,
                        timeOnPage: self.steps.timings[self.steps.current]
                    });
                }
            });

            // Page unload — send final event via sendBeacon.
            window.addEventListener('beforeunload', function () {
                self._sendThrottled('checkout_unloaded', {
                    step: self.steps.current,
                    duration: Date.now() - self._pageLoadTime
                });
            });
        },

        /**
         * Track field interactions (focus/blur timing).
         * Re-binds on 'updated_checkout' to catch AJAX-replaced DOM elements.
         */
        trackFieldInteractions: function () {
            this._bindFieldEvents();

            // Re-bind after WooCommerce AJAX updates the checkout fragments.
            var self = this;
            $(document.body).on('updated_checkout', function () {
                self._bindFieldEvents();
            });
        },

        /**
         * Bind focus/blur handlers to checkout fields (event delegation via body).
         */
        _bindFieldEvents: function () {
            var self = this;
            var selector = '.woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea';

            // Use delegation on document.body so AJAX-replaced fields are covered.
            $(document.body).off('focus.wacBeacon blur.wacBeacon', selector)
                .on('focus.wacBeacon', selector, function () {
                    var $field = $(this);
                    var fieldName = $field.attr('name') || $field.attr('id') || 'unknown';
                    self._fieldTimes[fieldName] = Date.now();
                })
                .on('blur.wacBeacon', selector, function () {
                    var $field = $(this);
                    var fieldName = $field.attr('name') || $field.attr('id') || 'unknown';
                    var startTime = self._fieldTimes[fieldName];
                    if (startTime) {
                        var elapsed = Date.now() - startTime;
                        if (elapsed > 500) {
                            self._sendThrottled('field_interaction', {
                                field: fieldName,
                                timeMs: elapsed
                            });
                        }
                        delete self._fieldTimes[fieldName];
                    }
                });
        },

        /**
         * Track a checkout step.
         */
        trackStep: function (stepName, extraData) {
            this.steps.current = stepName;
            this.steps.timings[stepName] = Date.now();

            // Critical checkpoint events fire immediately with throttling.
            this._sendThrottled(stepName, extraData);
        },

        /** Maximum errors stored in memory */
        _maxErrors: 50,

        /**
         * Track an error event.
         */
        trackError: function (type, message, extra) {
            this.steps.errors.push({
                type: type,
                message: message,
                time: Date.now()
            });

            // Cap error list to prevent memory bloat.
            if (this.steps.errors.length > this._maxErrors) {
                this.steps.errors.shift();
            }

            this._sendThrottled(type, $.extend({
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
                    nonce: this._nonce,
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

        // Watch for dynamically-added checkout fields.
        var checkoutEl = document.querySelector('.woocommerce-checkout');
        if (checkoutEl && window.MutationObserver) {
            (new MutationObserver(function () {
                WACBeacon._bindFieldEvents();
            })).observe(checkoutEl, { childList: true, subtree: true });
        }
    });

})(jQuery);
