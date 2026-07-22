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
     * Execute agent run.
     *
     * @return array Generated suggestions.
     */
    public function run(): array {
        $suggest_engine = $this->services['suggest'];
        $signals        = $this->services['signals'];
        $logger         = $this->services['logger'];
        $settings       = $this->services['settings'];
        $notifier       = new \WooAgenticCheckout\Notifier();

        if ( 'yes' !== $settings->get( 'auto_suggest_enabled', 'yes' ) ) {
            $logger->info( 'suggestion_generator_skipped', array( 'reason' => 'auto_suggest_disabled' ) );
            return array( 'skipped' => true, 'reason' => 'Auto-suggest disabled' );
        }

        // Build rich context for the LLM.
        $context = $this->build_context();

        // Generate suggestions.
        $suggestions = $suggest_engine->generate_suggestions( $context );

        // Auto-apply high-confidence suggestions if permissions allow.
        $permission = $settings->get_heal_permission();
        $auto_applied = 0;

        foreach ( $suggestions as $suggestion ) {
            $applied = $suggest_engine->auto_apply_if_allowed( $suggestion, $permission );
            if ( $applied ) {
                $auto_applied++;
            }
        }

        // Notify about new suggestions.
        foreach ( $suggestions as $suggestion ) {
            $notifier->new_suggestion( $suggestion );
        }

        $logger->info( 'suggestion_generator_run', array(
            'generated'    => count( $suggestions ),
            'auto_applied' => $auto_applied,
            'permission'   => $permission,
        ) );

        return array(
            'suggestions'  => $suggestions,
            'auto_applied' => $auto_applied,
            'context'      => array_keys( $context ),
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

        $context = array(
            'orders_24h'      => $signals->get_recent_orders( 24 ),
            'orders_7d'       => $signals->get_recent_orders( 168 ),
            'funnel'          => $signals->get_funnel_data( 168 ),
            'experiments'     => $ab->get_experiments( '', 10 ),
            'recent_errors'   => $signals->get_recent_errors( 24, 20 ),
            'plugin_version'  => WAC_VERSION,
            'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'wp_version'      => get_bloginfo( 'version' ),
            'currency'        => get_woocommerce_currency(),
            'country'         => get_option( 'woocommerce_default_country', '' ),
            'site_url'        => home_url(),
        );

        return $context;
    }
}
