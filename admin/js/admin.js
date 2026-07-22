/**
 * Woo Agentic Checkout — Admin Interface JS
 *
 * Handles suggestion apply/reject, experiment management, and manual agent runs.
 *
 * @version 0.1.0-alpha
 */
(function ($) {
    'use strict';

    var WACAdmin = {

        /**
         * Initialize admin features.
         */
        init: function () {
            this.bindSuggestionActions();
            this.bindExperimentActions();
            this.bindAgentRun();
            this.bindCreateExperiment();
        },

        /**
         * Apply / reject suggestion buttons.
         */
        bindSuggestionActions: function () {
            var self = this;

            // Apply suggestion
            $(document).on('click', '.wac-apply-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this),
                    id = $btn.data('id');

                $btn.prop('disabled', true).text('Applying...');

                $.ajax({
                    url: wacData.restUrl + '/suggestions/' + id + '/apply',
                    type: 'POST',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', wacData.nonce);
                    },
                    success: function (response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(300, function () {
                                $(this).remove();
                            });
                        } else {
                            alert('Failed to apply: ' + (response.message || 'Unknown error'));
                            $btn.prop('disabled', false).text('Apply');
                        }
                    },
                    error: function () {
                        alert('AJAX error applying suggestion.');
                        $btn.prop('disabled', false).text('Apply');
                    }
                });
            });

            // Reject suggestion
            $(document).on('click', '.wac-reject-suggestion', function (e) {
                e.preventDefault();
                var $btn = $(this),
                    id = $btn.data('id'),
                    reason = prompt('Reason for rejection (optional):') || '';

                $btn.prop('disabled', true).text('Rejecting...');

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
                            $btn.closest('tr').fadeOut(300);
                        } else {
                            alert('Failed to reject.');
                            $btn.prop('disabled', false).text('✕');
                        }
                    },
                    error: function () {
                        alert('AJAX error.');
                        $btn.prop('disabled', false).text('✕');
                    }
                });
            });
        },

        /**
         * View / pause experiments.
         */
        bindExperimentActions: function () {
            // View experiment details
            $(document).on('click', '.wac-view-exp', function (e) {
                e.preventDefault();
                var id = $(this).data('id');
                // Toggle variant rows inline
                $(this).closest('tr').nextUntil('tr:not(.wac-variant-row)').toggle();
            });

            // Pause experiment
            $(document).on('click', '.wac-pause-exp', function (e) {
                e.preventDefault();
                var $link = $(this),
                    id = $link.data('id');

                if (!confirm('Pause this experiment? Visitors will see the control variant.')) {
                    return;
                }

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
                            $link.closest('tr').find('.wac-badge-active')
                                .removeClass('wac-badge-active').addClass('wac-badge-paused')
                                .text('paused');
                            $link.remove();
                        }
                    }
                });
            });
        },

        /**
         * Manual agent run.
         */
        bindAgentRun: function () {
            $(document).on('submit', 'form[action*="admin-post"][name]', function () {
                var $form = $(this);
                $form.find(':submit').prop('disabled', true).val('Running...');
                // Re-enable after 30s timeout
                setTimeout(function () {
                    $form.find(':submit').prop('disabled', false).val('▶ Run');
                }, 30000);
            });
        },

        /**
         * Create experiment modal / form.
         */
        bindCreateExperiment: function () {
            $(document).on('click', '#wac-create-experiment', function (e) {
                e.preventDefault();
                alert('Experiment creation wizard coming soon! For now, the AB Optimizer agent will auto-create experiments based on data.');
            });
        }
    };

    // Boot
    $(document).ready(function () {
        WACAdmin.init();
    });

})(jQuery);
