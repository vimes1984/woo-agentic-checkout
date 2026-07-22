<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Signal Collector — aggregates data from GA4, WooCommerce orders, checkout telemetry,
 * and error logs to feed the agent analysis pipeline.
 *
 * @since 0.1.0-alpha
 */
class SignalCollector {

    /**
     * GA4 Measurement Protocol URL.
     */
    const GA4_MP_URL = 'https://www.google-analytics.com/mp/collect';

    /**
     * GA4 Data API URL template.
     */
    const GA4_DATA_API = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';

    /**
     * Send a checkout event to GA4 Measurement Protocol.
     *
     * @param string $event_name Event name (e.g., 'checkout_step_completed').
     * @param array  $params     Event parameters.
     *
     * @return bool
     */
    public function send_ga4_event( string $event_name, array $params = array() ): bool {
        $measurement_id = $this->get_setting( 'ga4_measurement_id' );
        $api_secret     = $this->get_setting( 'ga4_api_secret' );

        if ( empty( $measurement_id ) || empty( $api_secret ) ) {
            return false;
        }

        $payload = array(
            'client_id' => md5( session_id() ?: uniqid( 'ga4_', true ) ),
            'events'    => array(
                array(
                    'name'   => $event_name,
                    'params' => wp_parse_args( $params, array(
                        'currency' => get_woocommerce_currency(),
                        'plugin'   => 'woo-agentic-checkout',
                    )),
                ),
            ),
        );

        $url = add_query_arg( array(
            'measurement_id' => $measurement_id,
            'api_secret'     => $api_secret,
        ), self::GA4_MP_URL );

        $response = wp_remote_post( $url, array(
            'body'    => wp_json_encode( $payload ),
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );

        return ! is_wp_error( $response ) && 204 === wp_remote_retrieve_response_code( $response );
    }

    /**
     * Fetch GA4 report via Data API.
     *
     * @param string $start_date YYYY-MM-DD.
     * @param string $end_date   YYYY-MM-DD.
     *
     * @return array Raw report data or empty array on failure.
     */
    public function fetch_ga4_report( string $start_date, string $end_date ): array {
        $property_id = $this->get_setting( 'ga4_property_id' );
        $credentials = $this->get_setting( 'ga4_credentials_json' );

        if ( empty( $property_id ) || empty( $credentials ) ) {
            return array();
        }

        // This would use OAuth2 + Google Client library in production.
        // For now, we return a placeholder structure.
        return array(
            'source'      => 'ga4',
            'property_id' => $property_id,
            'date_range'  => array( $start_date, $end_date ),
            'metrics'     => array( 'conversions', 'totalRevenue', 'conversionRate' ),
            'note'        => 'GA4 Data API integration requires Google Client library. ' .
                             'Install via: composer require google/apiclient',
        );
    }

    /**
     * Get recent WooCommerce order data for analysis.
     *
     * @param int $hours Number of hours to look back.
     *
     * @return array{orders: int, revenue: float, conversion_rate: float, aov: float}
     */
    public function get_recent_orders( int $hours = 24 ): array {
        $threshold = time() - ( $hours * HOUR_IN_SECONDS );

        $orders = wc_get_orders( array(
            'date_created' => '>' . gmdate( 'Y-m-d H:i:s', $threshold ),
            'limit'        => -1,
            'return'       => 'ids',
        ) );

        $count   = count( $orders );
        $revenue = 0.0;

        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $revenue += (float) $order->get_total();
            }
        }

        $sessions = $this->get_checkout_sessions_count( $hours );

        return array(
            'orders'          => $count,
            'revenue'         => $revenue,
            'aov'             => $count > 0 ? round( $revenue / $count, 2 ) : 0,
            'conversion_rate' => $sessions > 0 ? round( ( $count / $sessions ) * 100, 2 ) : 0,
            'sessions'        => $sessions,
        );
    }

    /**
     * Get checkout funnel step drop-off data.
     *
     * @param int $hours Look-back period.
     *
     * @return array Step-by-step funnel data.
     */
    public function get_funnel_data( int $hours = 24 ): array {
        global $wpdb;

        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        // Query beacon events for funnel analysis.
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT event, COUNT(DISTINCT session_id) as sessions
             FROM {$wpdb->prefix}wac_beacon_events
             WHERE created_at >= %s
             GROUP BY event
             ORDER BY FIELD(event, 'checkout_started','billing_completed','shipping_completed',
                            'payment_selected','place_order_clicked','order_placed','order_failed')",
            $threshold
        ) );

        $funnel = array();
        foreach ( $results as $row ) {
            $funnel[ $row->event ] = (int) $row->sessions;
        }

        return $funnel;
    }

    /**
     * Get recent conversion rate (1h/24h/7d).
     *
     * @param int $period Seconds to look back.
     *
     * @return float Percentage.
     */
    public function get_recent_conversion_rate( int $period = DAY_IN_SECONDS ): float {
        $data = $this->get_recent_orders( $period / HOUR_IN_SECONDS );
        return $data['conversion_rate'];
    }

    /**
     * Get total checkout sessions in a period.
     *
     * @param int $hours Look-back hours.
     *
     * @return int
     */
    private function get_checkout_sessions_count( int $hours ): int {
        global $wpdb;

        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id)
             FROM {$wpdb->prefix}wac_beacon_events
             WHERE event = 'checkout_started'
             AND created_at >= %s",
            $threshold
        ) );
    }

    /**
     * Get recently logged errors for the error detector.
     *
     * @param int $hours Look-back hours.
     * @param int $limit Max rows.
     *
     * @return array
     */
    public function get_recent_errors( int $hours = 1, int $limit = 50 ): array {
        global $wpdb;

        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event, message, context, created_at
             FROM {$wpdb->prefix}wac_logs
             WHERE level = 'error'
             AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT %d",
            $threshold,
            $limit
        ), ARRAY_A );
    }

    /**
     * Get a plugin setting.
     *
     * @param string $key Setting key.
     *
     * @return mixed
     */
    private function get_setting( string $key ) {
        return get_option( "wac_{$key}", '' );
    }
}
