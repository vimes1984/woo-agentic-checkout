<?php
namespace WooAgenticCheckout\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Self-Healing Agent
 *
 * Responds to error detector alerts and performs autonomous healing actions
 * within configured permission boundaries. Also does passive health scans.
 *
 * @since 0.1.0-alpha
 */
class SelfHealingAgent {

    /**
     * Agent revision for tracking prompt/behaviour changes.
     */
    const REVISION = 'batch5.12';

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
        return 'Self-Healing Agent';
    }

    /**
     * Agent capabilities for introspection.
     *
     * @return array
     */
    public function get_capabilities(): array {
        return array(
            'id'             => 'self_healing',
            'label'          => $this->get_label(),
            'revision'       => self::REVISION,
            'llm_dependent'  => true,
            'schedule'       => 'continuous',
            'data_sources'   => array( 'errors', 'wc_health' ),
            'description'    => 'Performs autonomous healing actions within configured permission boundaries, runs passive health scans.',
        );
    }

    /**
     * Execute agent run — health scan + try to fix anything broken.
     *
     * @return array Standardised result (success, actions, errors, summary, healed, failed).
     */
    public function run(): array {
        $healer    = $this->services['healer'] ?? null;
        $signals   = $this->services['signals'] ?? null;
        $logger    = $this->services['logger'] ?? null;
        $settings  = $this->services['settings'] ?? null;
        $llm       = $this->services['llm'] ?? null;

        if ( ! $healer || ! $signals || ! $settings || ! $logger ) {
            return array(
                'success' => false,
                'actions' => 0,
                'errors'  => array( 'Missing required dependencies: healer, signals, settings, or logger.' ),
                'summary' => 'Self-healing agent could not initialise.',
            );
        }

        $permission = $settings->get_heal_permission();
        $notifier   = new \WooAgenticCheckout\Notifier();

        if ( 'monitor' === $permission ) {
            $logger->info( 'self_heal_monitor_only', array() );
            return array(
                'success' => true,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'Monitor-only mode — no healing actions taken.',
                'mode'    => 'monitor',
                'healed'  => 0,
            );
        }

        $results = array(
            'success'        => true,
            'actions'        => 0,
            'errors'         => array(),
            'summary'        => 'Self-healing check completed.',
            'healed'         => 0,
            'failed'         => 0,
            'actions_taken'  => array(),
            'health_checks'  => array(),
        );

        // ─── Health Checks ─────────────────────────────────────

        $health_checks = $this->run_health_checks();
        $results['health_checks'] = $health_checks;

        $failing = array_filter( $health_checks, function ( $check ) {
            return ! $check['passed'];
        } );

        // Cold start: everything healthy, no errors → skip LLM-heavy processing.
        $recent_errors = $signals->get_recent_errors( 1, 5 );
        if ( empty( $failing ) && empty( $recent_errors ) ) {
            $logger->info( 'self_heal_cold_start', array(
                'health_checked' => count( $health_checks ),
                'note'           => 'All health checks pass, zero recent errors — no healing needed.',
            ) );
            $results['summary'] = 'All health checks pass, no errors detected.';
            return $results;
        }

        if ( ! empty( $failing ) ) {
            $logger->warning( 'health_checks_failing', array(
                'count'  => count( $failing ),
                'checks' => array_keys( $failing ),
            ) );

            // Notify about failing health checks.
            foreach ( $failing as $check_key => $check ) {
                $notifier->warning(
                    "Health Check Failed: {$check_key}",
                    $check['detail'] ?? 'No details',
                    array( 'check' => $check )
                );
            }

            // Try LLM-assisted healing for failing checks.
            $heal_plan = $this->build_heal_plan( $failing, $llm );

            foreach ( $heal_plan as $plan ) {
                $result = $healer->attempt_heal(
                    $plan['issue_id'] ?? uniqid( 'heal_' ),
                    $plan['action'],
                    $plan['params'] ?? array(),
                    $permission
                );

                $results['actions'][] = $result;
                $notifier->heal_applied( $result );
            }
        }

        // ─── Recent Error Response ─────────────────────────────
        // Use broader query for errors section (already fetched early for cold-start).
        $heal_errors = $signals->get_recent_errors( 1, 10 );

        if ( ! empty( $heal_errors ) && empty( $failing ) ) {
            $heal_plan = $this->build_heal_plan_from_errors( $heal_errors, $llm );

            foreach ( $heal_plan as $plan ) {
                $result = $healer->attempt_heal(
                    $plan['issue_id'] ?? uniqid( 'heal_' ),
                    $plan['action'],
                    $plan['params'] ?? array(),
                    $permission
                );

                if ( $result['success'] ) {
                    $results['healed']++;
                } else {
                    $results['failed']++;
                }

                $results['actions_taken'][] = $result;
            }
        }

        $results['actions'] = $results['healed'] + $results['failed'];
        $results['summary'] = $results['healed'] . ' issues healed, ' . $results['failed'] . ' failures.';

        $logger->info( 'self_heal_run', array(
            'health_checked' => count( $health_checks ),
            'health_passing' => count( $health_checks ) - count( $failing ),
            'healed'         => $results['healed'],
            'failed'         => $results['failed'],
            'permission'     => $permission,
        ) );

        return $results;
    }

    /**
     * Run internal health checks.
     *
     * @return array<string, array{passed: bool, detail: string}>
     */
    private function run_health_checks(): array {
        $checks = array();

        // 1. Checkout page exists and is published.
        $checkout_id = (int) get_option( 'woocommerce_checkout_page_id', 0 );
        $checks['checkout_page'] = array(
            'passed' => $checkout_id > 0 && 'publish' === get_post_status( $checkout_id ),
            'detail' => $checkout_id ? "Checkout page ID: {$checkout_id}" : 'No checkout page set',
        );

        // 2. WooCommerce session handler works.
        $checks['wc_session'] = array(
            'passed' => null !== WC()->session,
            'detail' => null !== WC()->session ? 'Session handler active' : 'Session handler missing',
        );

        // 3. Cart is accessible.
        $checks['wc_cart'] = array(
            'passed' => function_exists( 'WC' ) && null !== WC()->cart,
            'detail' => null !== WC()->cart ? 'Cart active' : 'Cart unavailable',
        );

        // 4. Database tables exist.
        global $wpdb;
        $tables = array(
            $wpdb->prefix . 'wac_logs',
            $wpdb->prefix . 'wac_ab_experiments',
            $wpdb->prefix . 'wac_suggestions',
        );

        foreach ( $tables as $table ) {
            $short = str_replace( $wpdb->prefix, '', $table );
            $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            ) );
            $checks["db_table_{$short}"] = array(
                'passed' => $exists,
                'detail' => "Table {$short} " . ( $exists ? 'exists' : 'missing' ),
            );
        }

        // 5. LLM connection is configured.
        $llm_provider = get_option( 'wac_llm_provider', 'openai' );
        $llm_key      = get_option( 'wac_llm_api_key', '' );

        $checks['llm_config'] = array(
            'passed' => ! empty( $llm_key ) || 'ollama' === $llm_provider,
            'detail' => empty( $llm_key ) && 'ollama' !== $llm_provider
                ? 'LLM API key not configured'
                : "Provider: {$llm_provider}",
        );

        // 6. Memory / no OOM.
        $mem_limit = $this->get_php_memory_limit();
        $checks['php_memory'] = array(
            'passed' => $mem_limit >= 128 * 1024 * 1024,
            'detail' => "PHP memory limit: " . size_format( $mem_limit ),
        );

        // 7. WP-Cron scheduled jobs.
        $next_tick = wp_next_scheduled( 'wac_agent_tick' );
        $checks['cron_jobs'] = array(
            'passed' => false !== $next_tick,
            'detail' => $next_tick
                ? 'Agent tick scheduled at ' . gmdate( 'Y-m-d H:i:s', $next_tick )
                : 'Agent tick NOT scheduled (check deactivation hook)',
        );

        return $checks;
    }

    /**
     * Build a healing plan from failing health checks using LLM.
     *
     * @param array      $failing
     * @param LLMClient  $llm
     *
     * @return array
     */
    private function build_heal_plan( array $failing, $llm ): array {
        if ( count( $failing ) > 1 ) {
            $system = <<<'PROMPT'
You are a WooCommerce site reliability agent. Several health checks are failing.
For each failing check, recommend the single most effective action to restore health.

Possible actions: rollback_setting, revert_template, disable_plugin, clear_cache,
toggle_feature, patch_javascript, patch_css, escalate.

Output ONLY valid JSON matching this exact schema:
{
  "actions": [
    {
      "issue_id": "hc_checkout_page",
      "action": "rollback_setting",
      "params": {"option": "woocommerce_checkout_page_id"},
      "reasoning": "Checkout page is missing, likely deleted accidentally."
    }
  ]
}
PROMPT;

            try {
                $result = $llm->analyze(
                    $system,
                    wp_json_encode( array( 'failing_checks' => $failing ), JSON_PRETTY_PRINT ),
                    array(
                        'type'       => 'object',
                        'properties' => array(
                            'actions' => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'issue_id'  => array( 'type' => 'string' ),
                                        'action'    => array( 'type' => 'string' ),
                                        'params'    => array( 'type' => 'object' ),
                                        'reasoning' => array( 'type' => 'string' ),
                                    ),
                                    'required' => array( 'issue_id', 'action' ),
                                ),
                            ),
                        ),
                        'required' => array( 'actions' ),
                    )
                );

                return $result['actions'] ?? array();
            } catch ( \Exception $e ) {
                // Fallback: predefined actions per check type.
                return $this->fallback_heal_plan( $failing );
            }
        }

        return $this->fallback_heal_plan( $failing );
    }

    /**
     * Fallback healing plan when LLM is unavailable.
     *
     * @param array $failing
     *
     * @return array
     */
    private function fallback_heal_plan( array $failing ): array {
        $plan = array();

        foreach ( $failing as $key => $check ) {
            $action = 'escalate';
            $params = array( 'check' => $key, 'detail' => $check['detail'] ?? '' );

            // Known fixes for common issues.
            if ( false !== strpos( $key, 'db_table' ) ) {
                $action = 'toggle_feature';
                $params = array( 'action' => 'repair_tables' );
            }

            if ( 'checkout_page' === $key ) {
                $action = 'rollback_setting';
                $params = array(
                    'option' => 'woocommerce_checkout_page_id',
                    'note'   => 'Checkout page missing, may need manual recreation',
                );
            }

            if ( 'cron_jobs' === $key ) {
                $action = 'toggle_feature';
                $params = array( 'action' => 'reschedule_cron' );
            }

            $plan[] = array(
                'issue_id' => 'hc_' . $key,
                'action'   => $action,
                'params'   => $params,
            );
        }

        return $plan;
    }

    /**
     * Build a healing plan from recent errors using LLM.
     */
    private function build_heal_plan_from_errors( array $errors, $llm ): array {
        // Cold start guard: if no errors, skip LLM call.
        if ( empty( $errors ) ) {
            return array();
        }

        $system = <<<'PROMPT'
You are a WooCommerce self-healing agent. Recent checkout errors have been detected.
For each distinct error, recommend the single most likely fix.

Prioritise non-disruptive actions first. Use 'escalate' only for unknown errors.

Output ONLY valid JSON matching this exact schema:
{
  "actions": [
    {
      "issue_id": "err_gateway_timeout_abc",
      "action": "clear_cache",
      "params": {"cache_group": "api_responses"},
      "reasoning": "Payment gateway timeout suggests stale API cache."
    }
  ]
}
PROMPT;

        try {
            $result = $llm->analyze(
                $system,
                wp_json_encode( array( 'recent_errors' => $errors ), JSON_PRETTY_PRINT ),
                array(
                    'type'       => 'object',
                    'properties' => array(
                        'actions' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'issue_id'  => array( 'type' => 'string' ),
                                    'action'    => array( 'type' => 'string' ),
                                    'params'    => array( 'type' => 'object' ),
                                    'reasoning' => array( 'type' => 'string' ),
                                ),
                                'required' => array( 'issue_id', 'action' ),
                            ),
                        ),
                    ),
                    'required' => array( 'actions' ),
                )
            );

            return $result['actions'] ?? array();
        } catch ( \Exception $e ) {
            return array();
        }
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int
     */
    private function get_php_memory_limit(): int {
        $limit = ini_get( 'memory_limit' );
        if ( '-1' === $limit ) {
            return PHP_INT_MAX;
        }
        $unit = strtolower( substr( $limit, -1 ) );
        $val  = (int) $limit;
        switch ( $unit ) {
            case 'g':
                return $val * 1024 * 1024 * 1024;
            case 'm':
                return $val * 1024 * 1024;
            case 'k':
                return $val * 1024;
            default:
                return $val;
        }
    }
}
