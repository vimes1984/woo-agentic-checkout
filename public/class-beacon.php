<?php
namespace WooAgenticCheckout\Public;

defined( 'ABSPATH' ) || exit;

/**
 * Beacon — injects the JS tracker and handles server-side telemetry storage.
 *
 * @since 0.1.0-alpha
 */
class Beacon {

    /**
     * Inject A/B test experiment tracker into checkout page.
     */
    public function inject_tracker() {
        $experiments = $this->get_experiment_data();

        if ( empty( $experiments ) ) {
            return;
        }

        ?>
        <script>
        // WAC Experiment Tracker — injected by server
        window._wacExperiments = <?php echo wp_json_encode( $experiments ); ?>;
        </script>
        <?php
    }

    /**
     * Get experiment assignment data for the current session.
     *
     * @return array
     */
    private function get_experiment_data(): array {
        $ab_manager = new \WooAgenticCheckout\ABTestManager();
        $variants   = $ab_manager->get_session_variants();

        if ( empty( $variants ) ) {
            return array();
        }

        $data = array();
        foreach ( $variants as $exp_name => $variant_key ) {
            $data[] = array(
                'experiment'  => $exp_name,
                'variant'     => $variant_key,
            );
        }

        return $data;
    }
}
