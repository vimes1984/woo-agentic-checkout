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
     * Execute agent run.
     *
     * @return array Analysis results.
     */
    public function run(): array {
        $signals = $this->services['signals'];
        $llm     = $this->services['llm'];
        $logger  = $this->services['logger'];

        // Gather data.
        $orders_24h  = $signals->get_recent_orders( 24 );
        $orders_7d   = $signals->get_recent_orders( 168 );
        $funnel      = $signals->get_funnel_data( 24 );
        $experiments = $this->services['ab']->get_active_experiments();

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

        try {
            $result = $llm->analyze( $system_prompt, $user_prompt, $schema );

            // Log analysis.
            $logger->info( 'conversion_analysis', array(
                'cr_24h'       => $orders_24h['conversion_rate'],
                'cr_7d'        => $orders_7d['conversion_rate'],
                'revenue_24h'  => $orders_24h['revenue'],
                'analysis'     => $result,
            ) );

            return array(
                'conversion_rate_24h' => $orders_24h['conversion_rate'],
                'conversion_rate_7d'  => $orders_7d['conversion_rate'],
                'revenue_24h'         => $orders_24h['revenue'],
                'analysis'            => $result,
                'funnel'              => $funnel,
            );
        } catch ( \Exception $e ) {
            $logger->error( 'conversion_analyzer_failed', array( 'error' => $e->getMessage() ) );
            return array(
                'error'   => $e->getMessage(),
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
     * JSON output schema.
     */
    private function get_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'verdict'         => array( 'type' => 'string', 'enum' => array( 'healthy', 'declining', 'critical' ) ),
                'cr_assessment'   => array( 'type' => 'string' ),
                'funnel_issues'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'likely_cause'    => array( 'type' => 'string' ),
                'recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
            ),
            'required'   => array( 'verdict', 'cr_assessment', 'recommendations' ),
        );
    }
}
