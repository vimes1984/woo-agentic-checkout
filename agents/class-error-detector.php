<?php
namespace WooAgenticCheckout\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Error Detector Agent
 *
 * Continuously monitors for checkout errors, classifies them by severity,
 * and triggers the self-healing agent when persistent or critical issues are found.
 *
 * @since 0.1.0-alpha
 */
class ErrorDetector {

    /**
     * Agent revision for tracking prompt/behaviour changes.
     */
    const REVISION = 'batch5.12';

    /**
     * Critical error event patterns for severity classification.
     */
    const MIN_SAMPLES_FOR_LLM = 3;
    const FUNNEL_DROPOFF_THRESHOLD = 30;

    const CRITICAL_EVENTS = array(
        'checkout_fatal',
        'payment_gateway_failure',
        'database_error',
        'session_expired',
        'timeout',
    );

    /**
     * @var array Service dependencies.
     */
    private $services;

    /**
     * @param array $services
     */
    public function __construct( array $services ) {
        $this->services = $services;
    }

    /**
     * Agent label.
     *
     * @return string
     */
    public function get_label(): string {
        return 'Error Detector';
    }

    /**
     * Agent capabilities for introspection.
     *
     * @return array
     */
    public function get_capabilities(): array {
        return array(
            'id'             => 'error_detector',
            'label'          => $this->get_label(),
            'revision'       => self::REVISION,
            'llm_dependent'  => false,
            'schedule'       => 'continuous',
            'data_sources'   => array( 'errors', 'funnel', 'wc_health' ),
            'description'    => 'Monitors checkout errors, classifies severity, triggers self-healing for critical issues.',
        );
    }

    /**
     * Execute agent run. Checks recent errors and evaluates severity.
     *
     * @return array Standardised result (success, actions, errors, summary, issues, critical_count).
     */
    public function run(): array {
        // Process lock: prevent concurrent runs.
        $lock_key = 'wac_error_detector_lock';
        if ( get_transient( $lock_key ) ) {
            return array(
                'success' => true,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'Error Detector is already running (process lock active).',
            );
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );
        $release = function () use ( $lock_key ) {
            delete_transient( $lock_key );
        };

        $signals  = $this->services['signals'] ?? null;
        $logger   = $this->services['logger'] ?? null;
        $llm      = $this->services['llm'] ?? null;
        $notifier = new \WooAgenticCheckout\Notifier();

        $notifier = null;
        if ( class_exists( '\\WooAgenticCheckout\\Notifier' ) ) {
            $notifier = new \WooAgenticCheckout\Notifier();
        }

        try {
            if ( ! $signals || ! $logger ) {
                if ( $notifier ) {
                    $notifier = null; // Clean up.
                }
                $release();
                return array(
                    'success' => false,
                    'actions' => 0,
                    'errors'  => array( 'Missing required services: signals or logger.' ),
                    'summary' => 'Error detector could not initialise due to missing service dependencies.',
                );
            }

        $issues = array();

        // 1. Check PHP/JS errors from last hour.
        $errors = $signals->get_recent_errors( 1, 50 );

        // Cold start: no errors at all is healthy — skip heavy processing.
        if ( empty( $errors ) ) {
            $funnel    = $signals->get_funnel_data( 24 );
            $wc_issues = $this->check_wc_health();

            if ( $logger ) {
            \$logger->info( 'error_detector_run', array(
                'total_errors' => 0,
                'issues_found' => count( $wc_issues ),
                'critical'     => 0,
                'note'         => 'Zero errors in the last hour (cold start or clean state).',
                ) );
            }

            $release();
            return array(
                'success'        => true,
                'actions'        => 0,
                'errors'         => array(),
                'summary'        => 'No errors detected in the last hour.',
                'issues'         => $wc_issues,
                'critical_count' => 0,
                'total_errors'   => 0,
            );
        }

        if ( ! empty( $errors ) ) {
            // Group by type.
            $by_type = $this->group_errors( $errors );

            foreach ( $by_type as $event => $grouped ) {
                // Cap group size to prevent oversized analysis.
                $grouped = array_slice( $grouped, 0, 50 );
                $count = count( $grouped );
                $severity = $this->classify_severity( $grouped );

                $issues[] = array(
                    'event'     => $event,
                    'count'     => $count,
                    'severity'  => $severity,
                    'samples'   => array_slice( $grouped, 0, 5 ),
                    'first_seen' => $grouped[0]['created_at'] ?? '',
                    'last_seen'  => end( $grouped )['created_at'] ?? '',
                );
            }
        }

        // Cap total issues to prevent oversized analysis.
        if ( count( $issues ) > 20 ) {
            $issues = array_slice( $issues, 0, 20 );
        }

        // 2. Check checkout funnel anomalies.
        $funnel = $signals->get_funnel_data( 24 );
        $funnel_issues = $this->detect_funnel_anomalies( $funnel );
        $issues = array_merge( $issues, $funnel_issues );

        // 3. Check WooCommerce health.
        $wc_issues = $this->check_wc_health();
        $issues = array_merge( $issues, $wc_issues );

        // 4. Send critical+ issues to LLM for root cause analysis.
        $critical = array_filter( $issues, function ( $i ) {
            return in_array( $i['severity'] ?? '', array( 'critical', 'persistent' ), true );
        } );

        if ( ! empty( $critical ) ) {
            // Redact sensitive fields from samples before sending to LLM.
            $critical = $this->redact_samples( $critical );
            $analysis = $this->llm_root_cause_analysis( $critical, $llm );

            foreach ( $analysis as $analysed ) {
                if ( $logger ) {
                \$logger->info( 'error_root_cause', \$analysed );
            }
            }

            // Trigger self-healing for critical issues (with null service guards).
            $healer   = $this->services['healer'] ?? null;
            $settings = $this->services['settings'] ?? null;
            if ( $healer && $settings ) {
                foreach ( $analysis as $issue ) {
                    if ( isset( $issue['heal_action'] ) ) {
                        // Validate heal action against whitelist (defense in depth).
                        $allowed_actions = array( 'rollback_setting', 'revert_template', 'disable_plugin', 'clear_cache', 'toggle_feature', 'patch_javascript', 'patch_css', 'escalate' );
                        $safe_action = in_array( $issue['heal_action'], $allowed_actions, true ) ? $issue['heal_action'] : 'escalate';
                        $healer->attempt_heal(
                            $issue['issue_id'] ?? uniqid( 'err_' ),
                            $safe_action,
                            $issue['heal_params'] ?? array(),
                            $settings->get_heal_permission()
                        );
                    }
                }
            }
        }

        // 5. Send notifications for critical issues (with data truncation for safety).
        foreach ( $critical as $issue ) {
            $safe_event   = sanitize_text_field( substr( $issue['event'] ?? 'Checkout Error Detected', 0, 100 ) );
            $safe_details = sanitize_text_field( substr( $issue['details'] ?? $issue['event'] ?? 'Unknown critical issue', 0, 500 ) );
            // Truncate sample messages to prevent oversized payloads containing stack traces.
            $safe_issue = $issue;
            if ( isset( $safe_issue['samples'] ) && is_array( $safe_issue['samples'] ) ) {
                foreach ( $safe_issue['samples'] as $idx => $sample ) {
                    if ( isset( $sample['message'] ) ) {
                        $safe_issue['samples'][ $idx ]['message'] = substr( $sample['message'], 0, 500 );
                    }
                    if ( isset( $sample['context'] ) ) {
                        unset( $safe_issue['samples'][ $idx ]['context'] );
                    }
                }
            }
            $notifier->critical( $safe_event, $safe_details, $safe_issue );
        }

        // Logging moved to return block.

        $logger->info( 'error_detector_run', array(
            'total_errors' => count( $errors ),
            'issues_found' => count( $issues ),
            'critical'     => count( $critical ),
        ) );

        $release();
        return array(
            'success'        => count( $critical ) === 0,
            'actions'        => count( $critical ),
            'errors'         => array_column( $critical, 'event' ),
            'summary'        => ! empty( $critical )
                ? count( $critical ) . ' critical issues found, root cause analysis performed, self-healing triggered.'
                : 'No critical issues detected.',
            'issues'         => $issues,
            'critical_count' => count( $critical ),
            'total_errors'   => count( $errors ),
        );
        } catch ( \Exception $e ) {
            $release();
            if ( $logger ) {
                $logger->error( 'error_detector_run_failed', array(
                    'error' => sanitize_text_field( substr( $e->getMessage(), 0, 500 ) ),
                ) );
            }
            return array(
                'success'        => false,
                'actions'        => 0,
                'errors'         => array( sanitize_text_field( substr( $e->getMessage(), 0, 500 ) ) ),
                'summary'        => 'Error detector encountered an exception.',
                'issues'         => array(),
                'critical_count' => 0,
                'total_errors'   => 0,
            );
        }
    }

    /**
     * Group errors by event name.
     *
     * @param array $errors
     *
     * @return array
     */
    private function group_errors( array $errors ): array {
        $grouped = array();
        foreach ( $errors as $e ) {
            $event = $e['event'] ?? 'unknown';
            if ( ! isset( $grouped[ $event ] ) ) {
                $grouped[ $event ] = array();
            }
            $grouped[ $event ][] = $e;
        }
        return $grouped;
    }

    /**
     * Classify error severity based on frequency and type.
     *
     * @param array $errors
     *
     * @return string
     */
    private function classify_severity( array $errors ): string {
        $count = count( $errors );
        $event = $errors[0]['event'] ?? '';

        // Critical patterns.
        $critical_events = array(
            'checkout_fatal',
            'payment_gateway_failure',
            'database_error',
            'session_expired',
            'timeout',
        );

        foreach ( $critical_events as $ce ) {
            if ( false !== stripos( $event, $ce ) ) {
                return 'critical';
            }
        }

        if ( $count > 20 ) {
            return 'persistent';
        }

        if ( $count > 5 ) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * Detect anomalies in checkout funnel.
     *
     * @param array $funnel
     *
     * @return array
     */
    private function detect_funnel_anomalies( array $funnel ): array {
        $issues = array();

        if ( empty( $funnel ) ) {
            return $issues;
        }

        $steps = array(
            'checkout_started',
            'billing_completed',
            'shipping_completed',
            'payment_selected',
            'place_order_clicked',
            'order_placed',
        );

        $prev = 0;
        $any_positive = false;
        foreach ( $steps as $i => $step ) {
            $current = isset( $funnel[ $step ] ) ? absint( $funnel[ $step ] ) : 0;

            if ( $current > 0 ) {
                $any_positive = true;
            }

            if ( $i > 0 && $prev > 0 && $current > 0 ) {
                $dropoff = ( ( $prev - $current ) / $prev ) * 100;

                if ( $dropoff > 30 ) {
                    $issues[] = array(
                        'event'    => 'funnel_anomaly',
                        'severity' => 'warning',
                        'count'    => 1,
                        'details'  => 'High drop-off (' . round( $dropoff, 2 ) . '%) at step: ' . sanitize_key( $step ),
                        'samples'  => array(),
                    );
                }
            }
            $prev = $current;
        }

        // Edge case: funnel data exists but all zero → not an anomaly, just cold start.
        if ( ! $any_positive ) {
            $issues[] = array(
                'event'    => 'funnel_no_traffic',
                'severity' => 'info',
                'count'    => 1,
                'details'  => 'Funnel data exists but all steps show zero traffic (cold start or GA4 delay).',
                'samples'  => array(),
            );
        }

        return $issues;
    }

    /**
     * Check WooCommerce health for known issues.
     *
     * @return array
     */
    private function check_wc_health(): array {
        $issues = array();

        // Check if WooCommerce is active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            $issues[] = array(
                'event'    => 'woocommerce_inactive',
                'severity' => 'critical',
                'count'    => 1,
                'details'  => 'WooCommerce is not active.',
                'samples'  => array(),
            );
            return $issues;
        }

        // Check checkout page exists.
        $checkout_id = (int) get_option( 'woocommerce_checkout_page_id', 0 );
        if ( 0 === $checkout_id || 'publish' !== get_post_status( $checkout_id ) ) {
            $issues[] = array(
                'event'    => 'checkout_page_missing',
                'severity' => 'critical',
                'count'    => 1,
                'details'  => 'Checkout page is missing or not published.',
                'samples'  => array(),
            );
        }

        // Check session handler.
        if ( ! WC()->session ) {
            $issues[] = array(
                'event'    => 'session_handler_missing',
                'severity' => 'critical',
                'count'    => 1,
                'details'  => 'WooCommerce session handler not available.',
                'samples'  => array(),
            );
        }

        return $issues;
    }

    /**
     * Send critical issues to LLM for root cause analysis.
     *
     * @param array      $critical
     * @param LLMClient  $llm
     *
     * @return array
     */
    /**
     * Redact sensitive context fields from error samples before sending to LLM.
     *
     * @param array $critical Critical issues list.
     * @return array Sanitized issues with only safe fields in samples.
     */
    private function redact_samples( array $critical ): array {
        $safe_fields = array( 'id', 'event', 'message', 'level', 'created_at' );

        return array_map( function ( $issue ) use ( $safe_fields ) {
            if ( ! empty( $issue['samples'] ) && is_array( $issue['samples'] ) ) {
                $issue['samples'] = array_map( function ( $sample ) use ( $safe_fields ) {
                    return array_intersect_key( $sample, array_flip( $safe_fields ) );
                }, $issue['samples'] );
            }
            return $issue;
        }, $critical );
    }

    private function llm_root_cause_analysis( array $critical, $llm ): array {
        // Cold start guard — if critical issues array is empty, skip LLM call entirely.
        if ( empty( $critical ) ) {
            return array();
        }

        $system = <<<'PROMPT'
You are a WooCommerce troubleshooting expert. Given the following error reports,
determine the most likely root cause and suggest a concrete healing action.

For each issue output:
- issue_id: Unique identifier (use the existing event name or a hash)
- root_cause: Most likely cause
- severity: "critical"
- heal_action: One of: rollback_setting, revert_template, disable_plugin, clear_cache, toggle_feature, patch_javascript, patch_css, escalate
- heal_params: Parameters needed for the heal action (object)
- reasoning: Brief explanation

Output ONLY valid JSON matching this exact schema:
{
  "analyses": [
    {
      "issue_id": "err_checkout_fatal_001",
      "root_cause": "Payment gateway API timeout",
      "severity": "critical",
      "heal_action": "disable_plugin",
      "heal_params": {"plugin_slug": "some-payment-gateway"},
      "reasoning": "Gateway X has timed out 15 times in the last hour, likely a server-side issue."
    }
  ]
}
PROMPT;

        try {
            $result = $llm->analyze(
                $system,
                wp_json_encode( array( 'critical_issues' => $this->sanitize_context_for_llm( $critical ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                array(
                    'type'       => 'object',
                    'properties' => array(
                        'analyses' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'issue_id'    => array( 'type' => 'string' ),
                                    'root_cause'  => array( 'type' => 'string' ),
                                    'severity'    => array( 'type' => 'string' ),
                                    'heal_action' => array( 'type' => 'string' ),
                                    'heal_params' => array( 'type' => 'object' ),
                                    'reasoning'   => array( 'type' => 'string' ),
                                ),
                                'required' => array( 'issue_id', 'root_cause', 'heal_action' ),
                            ),
                        ),
                    ),
                    'required' => array( 'analyses' ),
                    'additionalProperties' => false,
                ),
            ),
            'additionalProperties' => false,
        );

            return $result['analyses'] ?? array();
        } catch ( \Exception $e ) {
            // Log the failure so we can track consecutive LLM failures.
            do_action( 'wac_log_warning', 'error_detector_llm_failed', substr( $e->getMessage(), 0, 500 ) );
            return array();
        }
    }
}
