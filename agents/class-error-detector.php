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
     * Execute agent run. Checks recent errors and evaluates severity.
     *
     * @return array Detected issues.
     */
    public function run(): array {
        $signals = $this->services['signals'];
        $logger  = $this->services['logger'];
        $llm     = $this->services['llm'];

        $issues = array();

        // 1. Check PHP/JS errors from last hour.
        $errors = $signals->get_recent_errors( 1, 50 );

        if ( ! empty( $errors ) ) {
            // Group by type.
            $by_type = $this->group_errors( $errors );

            foreach ( $by_type as $event => $grouped ) {
                $count = count( $grouped );
                $severity = $this->classify_severity( $grouped );

                $issues[] = array(
                    'event'     => $event,
                    'count'     => $count,
                    'severity'  => $severity,
                    'samples'   => array_slice( $grouped, 0, 3 ),
                    'first_seen' => $grouped[0]['created_at'] ?? '',
                    'last_seen'  => end( $grouped )['created_at'] ?? '',
                );
            }
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
            $analysis = $this->llm_root_cause_analysis( $critical, $llm );

            foreach ( $analysis as $analysed ) {
                $logger->info( 'error_root_cause', $analysed );
            }

            // Trigger self-healing for critical issues.
            $healer = $this->services['healer'];
            foreach ( $analysis as $issue ) {
                if ( isset( $issue['heal_action'] ) ) {
                    $healer->attempt_heal(
                        $issue['issue_id'] ?? uniqid( 'err_' ),
                        $issue['heal_action'],
                        $issue['heal_params'] ?? array(),
                        $this->services['settings']->get_heal_permission()
                    );
                }
            }
        }

        // 5. Log what's happening.
        $logger->info( 'error_detector_run', array(
            'total_errors' => count( $errors ),
            'issues_found' => count( $issues ),
            'critical'     => count( $critical ),
        ) );

        return array(
            'issues'  => $issues,
            'critical_count' => count( $critical ),
            'total_errors'   => count( $errors ),
        );
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
        foreach ( $steps as $i => $step ) {
            $current = isset( $funnel[ $step ] ) ? (int) $funnel[ $step ] : 0;

            if ( $i > 0 && $prev > 0 && $current > 0 ) {
                $dropoff = ( ( $prev - $current ) / $prev ) * 100;

                if ( $dropoff > 30 ) {
                    $issues[] = array(
                        'event'    => 'funnel_anomaly',
                        'severity' => 'warning',
                        'count'    => 1,
                        'details'  => "High drop-off ({$dropoff}%) at step: {$step}",
                        'samples'  => array(),
                    );
                }
            }
            $prev = $current;
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
    private function llm_root_cause_analysis( array $critical, $llm ): array {
        $system = <<<'PROMPT'
You are a WooCommerce troubleshooting expert. Given the following error reports,
determine the most likely root cause and suggest a concrete healing action.

For each issue output:
- issue_id: Unique identifier
- root_cause: Most likely cause
- severity: confirmed critical
- heal_action: One of: rollback_setting, revert_template, disable_plugin, clear_cache, toggle_feature, patch_javascript, patch_css, escalate
- heal_params: Parameters needed for the heal action
- reasoning: Brief explanation

Output JSON with an array of analyses.
PROMPT;

        try {
            $result = $llm->analyze(
                $system,
                wp_json_encode( array( 'critical_issues' => $critical ), JSON_PRETTY_PRINT ),
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
                )
            );

            return $result['analyses'] ?? array();
        } catch ( \Exception $e ) {
            return array();
        }
    }
}
