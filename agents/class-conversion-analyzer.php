<?php
namespace WooAgenticCheckout\Agents;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\LLMClient;
use WooAgenticCheckout\Logger;

/**
 * Conversion Analyzer Agent
 *
 * Analyses conversion rate changes and attributes them to experiments,
 * external factors, or regressions. Runs daily.
 *
 * @since 0.1.0-alpha
 */
class ConversionAnalyzer {

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
        return 'Conversion Analyzer';
    }

    /**
     * Agent capabilities for introspection.
     *
     * @return array{id: string, label: string, revision: string, llm_dependent: bool, schedule: string, data_sources: string[]}
     */
    public function get_capabilities(): array {
        return array(
            'id'             => 'conversion_analyzer',
            'label'          => $this->get_label(),
            'revision'       => self::REVISION,
            'llm_dependent'  => true,
            'schedule'       => 'daily',
            'data_sources'   => array( 'orders', 'funnel', 'experiments' ),
            'description'    => 'Analyses conversion rate changes and attributes them to experiments, external factors, or regressions.',
        );
    }

    /**
     * Execute agent run.
     *
     * @return array Analysis results with standardised format (success, actions, errors, summary).
     * @throws \Exception If required services are missing or LLM fails catastrophically.
     */
    public function run(): array {
        // Process lock: prevent concurrent runs.
        $lock_key = 'wac_conversion_analyzer_lock';
        if ( get_transient( $lock_key ) ) {
            return array(
                'success' => true,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'Conversion Analyzer is already running (process lock active).',
            );
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );
        $release = function () use ( $lock_key ) {
            delete_transient( $lock_key );
        };

        $signals = $this->services['signals'] ?? null;
        $llm     = $this->services['llm'] ?? null;
        $logger  = $this->services['logger'] ?? null;

        try {
            // Guard: missing required services.
            if ( ! $signals || ! $llm ) {
                $msg = 'Missing required services: signals or LLM.';
                if ( $logger ) {
                    $logger->error( 'conversion_analyzer_missing_services', array( 'note' => $msg ) );
                }
                $release();
                return array(
                    'success' => false,
                    'actions' => 0,
                    'errors'  => array( $msg ),
                    'summary' => $msg,
                );
            }

            // Gather data.
            $orders_24h  = $signals->get_recent_orders( 24 );
            $orders_7d   = $signals->get_recent_orders( 168 );
            $funnel      = $signals->get_funnel_data( 24 );
            $experiments = $this->services['ab']->get_active_experiments();

            // Cold start / no-data guard.
            $empty_order = static function ( $orders ): bool {
                return empty( $orders ) || ! isset( $orders['total_orders'] ) || (int) $orders['total_orders'] < 1;
            };

            $is_cold_start = $empty_order( $orders_24h ) && $empty_order( $orders_7d );

            if ( $is_cold_start ) {
                $logger->info( 'conversion_analysis_cold_start', array(
                    'note' => 'No order data available yet — returning baseline.',
                ) );
                $release();
                return array(
                    'success'             => true,
                    'actions'             => 0,
                    'errors'              => array(),
                    'summary'             => 'No order data available yet. This is normal during cold start (e.g., first 24h after install).',
                    'conversion_rate_24h' => 0,
                    'conversion_rate_7d'  => 0,
                    'revenue_24h'         => 0,
                    'analysis'            => array(
                        'verdict'         => 'no_data',
                        'cr_assessment'   => 'No orders recorded in the analysis window.',
                        'funnel_issues'   => array(),
                        'likely_cause'    => 'Insufficient data — plugin may be newly installed or no traffic.',
                        'recommendations' => array( 'Wait for data to accumulate before acting on this analysis.' ),
                    ),
                    'funnel'              => $funnel,
                );
            }

            $analysis_data = array(
                'last_24h' => $orders_24h,
                'last_7d'  => $orders_7d,
                'funnel'   => $funnel,
                'active_experiments' => count( $experiments ),
            );

            // Send to LLM for analysis.
            $system_prompt = $this->build_system_prompt();
            $user_prompt   = $this->build_user_prompt( $analysis_data );
            $schema        = $this->get_output_schema();

            // Token budget check: warn if prompt is approaching context window limits.
            $combined_len = strlen( $system_prompt ) + strlen( $user_prompt );
            if ( $combined_len > 80000 ) {
                $logger->warning( 'conversion_analyzer_large_prompt', array(
                    'char_length' => $combined_len,
                    'note'        => 'Prompt is large; may approach context window limit.',
                ) );
            }

            $result = $llm->analyze( $system_prompt, $user_prompt, $schema );

            // Guard: ensure result is an array before accessing keys.
            if ( ! is_array( $result ) ) {
                $result = array( 'cr_assessment' => 'Analysis returned unexpected format.' );
            }

            // Log analysis.
            $logger->info( 'conversion_analysis', array(
                'cr_24h'       => $orders_24h['conversion_rate'],
                'cr_7d'        => $orders_7d['conversion_rate'],
                'revenue_24h'  => $orders_24h['revenue'],
                'analysis'     => $result,
            ) );

            $release();
            return array(
                'success'             => true,
                'actions'             => 1,
                'errors'              => array(),
                'summary'             => $result['cr_assessment'] ?? 'Conversion analysis completed.',
                'conversion_rate_24h' => $orders_24h['conversion_rate'],
                'conversion_rate_7d'  => $orders_7d['conversion_rate'],
                'revenue_24h'         => $orders_24h['revenue'],
                'analysis'            => $result,
                'funnel'              => $funnel,
            );
        } catch ( \Exception $e ) {
            $release();
            // Sanitize error message to prevent information disclosure (file paths, API keys, tokens).
            $raw_msg   = $e->getMessage();
            $sanitized = sanitize_text_field( substr( $raw_msg, 0, 500 ) );
            $logger->error( 'conversion_analyzer_failed', array(
                'error_hash' => md5( $raw_msg ),
                'sanitized'  => $sanitized,
            ) );
            return array(
                'success' => false,
                'actions' => 0,
                'errors'  => array( $sanitized ),
                'summary' => 'Conversion analysis failed: ' . $sanitized,
                'fallback' => array(
                    'conversion_rate_24h' => $orders_24h['conversion_rate'],
                    'conversion_rate_7d'  => $orders_7d['conversion_rate'],
                    'revenue_24h'         => $orders_24h['revenue'],
                ),
            );
        }
    }

    /**
     * Build the system prompt for the LLM.
     *
     * @return string System instructions with explicit JSON schema.
     */
    private function build_system_prompt(): string {
        return <<<'PROMPT'
You are a WooCommerce conversion analyst. You are given checkout data and must
determine whether the current conversion rate is healthy, declining, or improving.

Analyse:
1. Is the conversion rate within normal range for WooCommerce stores (1-5%)?
2. Is the trend improving or declining compared to 7d ago?
3. Are there funnel step drop-offs that indicate specific problems?
4. Could a currently active experiment explain the change?
5. If data is empty or shows zero orders, respond with a 'no_data' verdict.

Output ONLY valid JSON matching this exact schema:
{
  "verdict": "healthy" | "declining" | "critical" | "no_data",
  "cr_assessment": "string describing rate assessment",
  "funnel_issues": ["string", "..."],
  "likely_cause": "string with most likely cause",
  "recommendations": ["string", "..."]
}

If there is no order data or the conversion rate is exactly 0 for 24h with no apparent cause, set verdict to "no_data" and explain why in cr_assessment.

Be concise and actionable. Focus on data-backed conclusions.
PROMPT;
    }

    /**
     * Build the user prompt with current data.
     */
    private function build_user_prompt( array $data ): string {
        return "Conversion data:\n" . wp_json_encode( $data, JSON_PRETTY_PRINT );
    }

    /**
     * JSON output schema for structured LLM response.
     */
    private function get_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'verdict'         => array(
                    'type' => 'string',
                    'enum' => array( 'healthy', 'declining', 'critical', 'no_data' ),
                ),
                'cr_assessment'   => array( 'type' => 'string' ),
                'funnel_issues'   => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
                'likely_cause'    => array( 'type' => 'string' ),
                'recommendations' => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
            ),
            'required'   => array( 'verdict', 'cr_assessment', 'recommendations' ),
            'additionalProperties' => false,
        );
    }
}
