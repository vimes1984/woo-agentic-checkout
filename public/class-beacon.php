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
     * Includes experiment metadata for JS-side analysis.
     */
    public function inject_tracker() {
        $experiments = $this->get_experiment_data();

        // Cap experiment data sent to JS to prevent oversized inline scripts.
        if ( count( $experiments ) > 20 ) {
            $experiments = array_slice( $experiments, 0, 20 );
        }

        if ( empty( $experiments ) ) {
            return;
        }

        ?>
        <script>
        // WAC Experiment Tracker — injected by server
        window._wacExperiments = <?php echo wp_json_encode( $experiments ); ?>;
        window._wacNonce = '<?php echo esc_js( wp_create_nonce( 'wac_beacon' ) ); ?>';

        // localStorage bridge for cookie-disabled users.
        (function() {
            try {
                var ls = window.localStorage;
                if (!ls) return;
                var cid = ls.getItem('wac_client_id');
                // Validate existing client ID format to prevent injection.
                if (cid && !/^wac_[a-zA-Z0-9_]+$/.test(cid)) {
                    cid = '';
                }
                if (!cid) {
                    cid = 'wac_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    ls.setItem('wac_client_id', cid);
                }
                // Sync to a cookie so PHP can read it.
                var secureFlag = window.location.protocol === 'https:' ? '; secure' : '';
                document.cookie = 'wac_client_id=' + encodeURIComponent(cid) +
                    '; path=/; max-age=2592000; samesite=strict' + secureFlag + ';';
            } catch (e) {
                // localStorage disabled or quota exceeded — fall back to server-side cookies.
            }
        })();
        </script>
        <?php
    }

    /**
     * Cached experiment data for this session.
     *
     * @var array|null
     */
    private $cached_experiment_data = null;

    /**
     * Get experiment assignment data for the current session.
     *
     * @return array
     */
    private function get_experiment_data(): array {
        if ( null !== $this->cached_experiment_data ) {
            return $this->cached_experiment_data;
        }

        $ab_manager = new \WooAgenticCheckout\ABTestManager();
        $variants   = $ab_manager->get_session_variants();

        if ( empty( $variants ) ) {
            $this->cached_experiment_data = array();
            return array();
        }

        $data = array();
        foreach ( $variants as $exp_name => $variant_key ) {
            $data[] = array(
                'experiment'  => $exp_name,
                'variant'     => $variant_key,
            );
        }

        $this->cached_experiment_data = $data;
        return $data;
    }

    /**
     * AJAX handler for wac_beacon endpoint.
     * Verifies nonce, stores event data.
     */
    public function handle_ajax() {
        // Set a reasonable time limit to prevent resource exhaustion on AJAX calls.
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 15 );
        }

        // Verify nonce.
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wac_beacon' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
        }

        $event   = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
        $session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
        $raw_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

        // Limit raw data to 10KB to prevent oversized payload storage.
        if ( is_string( $raw_data ) && strlen( $raw_data ) > 10240 ) {
            $raw_data = substr( $raw_data, 0, 10240 );
        }

        $data    = json_decode( $raw_data, true, 10 );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        // Recursively sanitize data values to prevent stored XSS (max depth 50).
        $depth = 0;
        $sanitize_deep = function ( &$item, $key ) use ( &$sanitize_deep, &$depth ) {
            if ( $depth > 50 ) {
                $item = '';
                return;
            }
            if ( is_string( $item ) ) {
                $item = sanitize_textarea_field( $item );
            } elseif ( is_array( $item ) ) {
                $depth++;
                array_walk( $item, $sanitize_deep );
                $depth--;
            }
        };
        array_walk( \$data, \$sanitize_deep );

        if ( empty( $event ) || empty( $session ) ) {
            wp_send_json_error( array( 'message' => 'Missing required fields.' ), 400 );
        }

        if ( strlen( $event ) > 100 || strlen( $session ) > 64 ) {
            wp_send_json_error( array( 'message' => 'Invalid field length.' ), 400 );
        }

        // Validate event name format: alphanumeric, underscore, hyphen only.
        if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]+$/', $event ) ) {
            wp_send_json_error( array( 'message' => 'Invalid event name format.' ), 400 );
        }

        // Validate session format: alphanumeric, underscore, hyphen only.
        if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]+$/', $session ) ) {
            wp_send_json_error( array( 'message' => 'Invalid session format.' ), 400 );
        }

        /**
         * Fires when a beacon event is received.
         *
         * @param string $event   Event type.
         * @param string $session Session identifier.
         * @param array  $data    Event payload.
         */
        do_action( 'wac_beacon_event', $event, $session, $data );

        wp_send_json_success( array( 'logged' => true ) );
    }
}
