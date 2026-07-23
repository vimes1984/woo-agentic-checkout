<?php
namespace WooAgenticCheckout\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion Generator Agent
 *
 * Collects all signals, experiment results, and conversion data,
 * then uses the LLM to generate concrete, prioritised checkout improvements.
 * Runs weekly.
 *
 * @since 0.1.0-alpha
 */
class SuggestionGenerator {

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
        return 'Suggestion Generator';
    }

    /**
     * Agent capabilities for introspection.
     *
     * @return array
     */
    public function get_capabilities(): array {
        return array(
            'id'             => 'suggestion_generator',
            'label'          => $this->get_label(),
            'revision'       => self::REVISION,
            'llm_dependent'  => true,
            'schedule'       => 'weekly',
            'data_sources'   => array( 'orders', 'funnel', 'experiments', 'errors' ),
            'description'    => 'Generates concrete, prioritised checkout improvements using LLM analysis of all signals.',
        );
    }

    /**
     * Execute agent run.
     *
     * @return array Standardised result (success, actions, errors, summary, suggestions).
     */
    public function run(): array {
        $suggest_engine = $this->services['suggest'];
        $signals        = $this->services['signals'];
        $logger         = $this->services['logger'];
        $settings       = $this->services['settings'];
        $notifier       = new \WooAgenticCheckout\Notifier();

        if ( 'yes' !== $settings->get( 'auto_suggest_enabled', 'yes' ) ) {
            $logger->info( 'suggestion_generator_skipped', array( 'reason' => 'auto_suggest_disabled' ) );
            return array(
                'success' => false,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'Auto-suggest disabled in settings.',
                'skipped' => true,
                'reason'  => 'Auto-suggest disabled',
            );
        }

        // Build rich context for the LLM.
        $context = $this->build_context();

        // Generate suggestions.
        $suggestions = $suggest_engine->generate_suggestions( $context );

        // Guard: ensure suggestions is an array before iterating.
        if ( ! is_array( $suggestions ) ) {
            $suggestions = array();
        }

        // Cap suggestions to prevent runaway LLM output.
        $max_suggestions = 20;
        if ( count( $suggestions ) > $max_suggestions ) {
            $suggestions = array_slice( $suggestions, 0, $max_suggestions );
        }

        // Auto-apply high-confidence suggestions if permissions allow.
        $permission = $settings->get_heal_permission();
        $auto_applied = 0;
        $max_auto_apply = 5;

        foreach ( $suggestions as $suggestion ) {
            if ( $auto_applied >= $max_auto_apply ) {
                break;
            }
            $applied = $suggest_engine->auto_apply_if_allowed( $suggestion, $permission );
            if ( $applied ) {
                $auto_applied++;
            }
        }

        // Notify about new suggestions.
        foreach ( $suggestions as $suggestion ) {
            $notifier->new_suggestion( $suggestion );
        }

        $summary = count( $suggestions ) . ' suggestions generated';
        if ( $auto_applied > 0 ) {
            $summary .= ', ' . $auto_applied . ' auto-applied.';
        } else {
            $summary .= '.';
        }

        $logger->info( 'suggestion_generator_run', array(
            'generated'    => count( $suggestions ),
            'auto_applied' => $auto_applied,
            'permission'   => $permission,
        ) );

        return array(
            'success'      => true,
            'actions'      => count( $suggestions ),
            'errors'       => array(),
            'summary'      => $summary,
            'suggestions'  => $suggestions,
            'auto_applied' => $auto_applied,
            'context_keys' => is_array( $context ) ? array_keys( $context ) : array(),
        );
    }

    /**
     * Build context data from all available sources.
     *
     * @return array
     */
    private function build_context(): array {
        $signals = $this->services['signals'];
        $ab      = $this->services['ab'];

        $orders_24h    = $signals->get_recent_orders( 24 );
        $orders_7d     = $signals->get_recent_orders( 168 );
        $funnel        = $signals->get_funnel_data( 168 );
        $experiments   = $ab->get_experiments( '', 10 );
        $recent_errors = $signals->get_recent_errors( 24, 20 );

        // Guard: ensure expected types for downstream consumers.
        $funnel      = is_array( $funnel ) ? $funnel : array();
        $experiments = is_array( $experiments ) ? $experiments : array();
        $recent_errors = is_array( $recent_errors ) ? $recent_errors : array();

        // Annotate cold-start / no-data state.
        $has_orders = is_array( $orders_7d ) && isset( $orders_7d['total_orders'] ) && (int) $orders_7d['total_orders'] > 0;
        $has_errors = ! empty( $recent_errors );
        $has_traffic = false;
        if ( is_array( $funnel ) && ! empty( $funnel ) ) {
            foreach ( $funnel as $v ) {
                if ( (int) $v > 0 ) {
                    $has_traffic = true;
                    break;
                }
            }
        }

        // Strip potentially sensitive data from error context before sending to LLM.
        $safe_errors = array_map( function ( $err ) {
            return array(
                'id'         => $err['id'] ?? 0,
                'event'      => substr( sanitize_text_field( $err['event'] ?? '' ), 0, 100 ),
                'level'      => in_array( $err['level'] ?? '', array( 'info', 'warning', 'error', 'critical' ), true ) ? $err['level'] : 'info',
                'created_at' => $err['created_at'] ?? '',
            );
        }, $recent_errors );

        // Sanitize experiment data before passing to LLM context.
        $safe_experiments = array();
        foreach ( $experiments as $exp ) {
            $safe_experiments[] = array(
                'id'     => absint( $exp['id'] ?? 0 ),
                'name'   => substr( sanitize_text_field( $exp['name'] ?? '' ), 0, 255 ),
                'status' => sanitize_key( $exp['status'] ?? '' ),
            );
        }

        $context = array(
            'orders_24h'            => $orders_24h,
            'orders_7d'             => $orders_7d,
            'funnel'                => $funnel,
            'experiments'           => $safe_experiments,
            'recent_errors'         => $safe_errors,
            'plugin_version'        => WAC_VERSION,
            'wc_version'            => apply_filters( 'wac_context_wc_version', defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' ),
            'wp_version'            => get_bloginfo( 'version' ),
            'currency'              => get_woocommerce_currency(),
            'country'               => get_option( 'woocommerce_default_country', '' ),
            'site_url'              => apply_filters( 'wac_context_site_url', home_url() ),
            '_meta'                 => array(
                'has_orders'  => $has_orders,
                'has_errors'  => $has_errors,
                'has_traffic' => $has_traffic,
                'is_cold_start' => ! $has_orders && ! $has_errors && ! $has_traffic,
            ),
        );

        /**
         * Filter the context data sent to the LLM for suggestion generation.
         * Use this to redact or add additional data before it reaches the AI provider.
         *
         * @param array $context The context array.
         */
        $context = apply_filters( 'wac_suggestion_context', $context );

        return $context;
    }
}
