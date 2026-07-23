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
    const GA4_RATE_LIMIT_SECONDS = 2;
    const GA4_REPORT_RATE_LIMIT_SECONDS = 60;
    const MAX_ERROR_SAMPLES = 500;
    const MAX_LOOKBACK_HOURS = 168;
    const MIN_LOOKBACK_HOURS = 1;
    const MAX_FUNNEL_RESULTS = 50;
    const MAX_RESPONSE_BYTES = 500000;
    const JWT_EXPIRY_SECONDS = 3600;
    const JWT_CACHE_TTL = 3300;
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const ANALYTICS_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /**
     * Send a checkout event to GA4 Measurement Protocol.
     *
     * @param string $event_name Event name (e.g., 'checkout_step_completed').
     * @param array  $params     Event parameters.
     *
     * @return bool
     */
    public function send_ga4_event( string $event_name, array $params = array() ): bool {
        // Rate limit: max 1 GA4 event per 2 seconds per session.
        $rate_key = 'wac_ga4_rate_' . md5( session_id() ?: 'cli' );
        $last_send = get_transient( $rate_key );
        if ( $last_send && ( time() - $last_send ) < 2 ) {
            return false;
        }
        set_transient( $rate_key, time(), 60 );

        $measurement_id = $this->get_setting( 'ga4_measurement_id' );
        $api_secret     = $this->get_setting( 'ga4_api_secret' );

        // Validate GA4 event name: must start with alpha, alphanumeric + underscores, max 40 chars (Google spec).
        $event_name = sanitize_key( $event_name );
        if ( strlen( $event_name ) > 40 || empty( $event_name ) || ! preg_match( '/^[a-zA-Z]/', $event_name ) ) {
            return false;
        }

        if ( empty( $measurement_id ) || empty( $api_secret ) ) {
            return false;
        }

        // Validate measurement ID format (G-XXXXXXXXXX).
        if ( ! preg_match( '/^G-[A-Z0-9]+$/i', $measurement_id ) ) {
            return false;
        }

        // Validate API secret (alphanumeric only).
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $api_secret ) ) {
            return false;
        }

        // Sanitize GA4 parameter values: only strings and numbers allowed, max 100 chars per value.
        $sanitized_params = array();
        $defaults = array(
            'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'plugin'   => 'woo-agentic-checkout',
        );
        $merged = wp_parse_args( $params, $defaults );
        foreach ( $merged as $key => $value ) {
            $sanitized_key = sanitize_key( $key );
            if ( is_scalar( $value ) ) {
                $sanitized_params[ $sanitized_key ] = substr( (string) $value, 0, 100 );
            } elseif ( is_array( $value ) || is_object( $value ) ) {
                // Skip non-scalar values — GA4 Measurement Protocol only accepts scalars.
                continue;
            }
        }

        $payload = array(
            'client_id' => md5( ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) ? md5( session_id() ) : md5( uniqid( 'ga4_', true ) ) ),
            'events'    => array(
                array(
                    'name'   => $event_name,
                    'params' => $sanitized_params,
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
        // Rate limit: max 1 GA4 report fetch per 60 seconds.
        $rate_key = 'wac_ga4_report_rate';
        $last_fetch = get_transient( $rate_key );
        if ( $last_fetch && ( time() - $last_fetch ) < 60 ) {
            return array( 'error' => 'Rate limited: GA4 report can be fetched at most once per 60 seconds.' );
        }
        set_transient( $rate_key, time(), 120 );

        // Validate date format (YYYY-MM-DD).
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
            return array( 'error' => 'Invalid date format. Use YYYY-MM-DD.' );
        }

        $property_id = $this->get_setting( 'ga4_property_id' );
        $credentials = $this->get_setting( 'ga4_credentials_json' );

        if ( empty( $property_id ) || empty( $credentials ) ) {
            return array();
        }

        // Validate property ID to prevent URL injection.
        $property_id = sanitize_text_field( $property_id );
        if ( ! preg_match( '/^[a-zA-Z0-9_\/]+$/', $property_id ) ) {
            return array( 'error' => 'Invalid GA4 property ID format.' );
        }

        $credentials_array = json_decode( $credentials, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $credentials_array['client_email'] ) ) {
            return array( 'error' => 'Invalid GA4 credentials JSON format.' );
        }

        // Use JWT OAuth2 to get an access token from service account.
        $access_token = $this->get_ga4_access_token( $credentials_array );
        if ( ! $access_token ) {
            return array( 'error' => 'Failed to obtain GA4 access token.' );
        }

        // Build the report request.
        $body = array(
            'dateRanges' => array(
                array( 'startDate' => $start_date, 'endDate' => $end_date ),
            ),
            'metrics' => array(
                array( 'name' => 'conversions' ),
                array( 'name' => 'totalRevenue' ),
                array( 'name' => 'conversionRate' ),
                array( 'name' => 'sessions' ),
                array( 'name' => 'averagePurchaseRevenue' ),
            ),
            'dimensions' => array(
                array( 'name' => 'date' ),
                array( 'name' => 'sessionDefaultChannelGroup' ),
            ),
        );

        $url = sprintf( self::GA4_DATA_API, $property_id );
        $response = wp_remote_post( $url, array(
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return array(
                'error' => "GA4 API returned {$code}: " . substr( wp_remote_retrieve_body( $response ), 0, 500 ),
            );
        }

        $body = wp_remote_retrieve_body( $response );
        // Guard: prevent oversized response from consuming memory.
        if ( strlen( $body ) > 500000 ) {
            return array( 'error' => 'GA4 API response exceeded 500KB limit.' );
        }
        $data = json_decode( $body, true );

        // Calculate summary metrics from rows.
        $total_conversions = 0;
        $total_revenue     = 0.0;
        $total_sessions    = 0;

        if ( isset( $data['rows'] ) ) {
            foreach ( $data['rows'] as $row ) {
                $total_conversions += (int) ( $row['metricValues'][0]['value'] ?? 0 );
                $total_revenue     += (float) ( $row['metricValues'][1]['value'] ?? 0 );
                $total_sessions    += (int) ( $row['metricValues'][3]['value'] ?? 0 );
            }
        }

        return array(
            'source'        => 'ga4',
            'property_id'   => substr( $property_id, 0, 20 ),
            'date_range'    => array( $start_date, $end_date ),
            'total_conversions' => $total_conversions,
            'total_revenue'     => round( $total_revenue, 2 ),
            'total_sessions'    => $total_sessions,
            'conversion_rate'   => $total_sessions > 0
                ? round( ( $total_conversions / $total_sessions ) * 100, 2 )
                : 0,
            'rows'          => $data['rows'] ?? array(),
            'row_count'     => count( $data['rows'] ?? array() ),
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
        $hours     = min( 168, max( 1, $hours ) );
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

        $hours     = min( 168, max( 1, $hours ) );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        // Query beacon events for funnel analysis with a safety limit.
        $threshold = substr( $threshold, 0, 19 );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT event, COUNT(DISTINCT session_id) as sessions
             FROM {$wpdb->prefix}wac_beacon_events
             WHERE created_at >= %s
             GROUP BY event
             ORDER BY FIELD(event, 'checkout_started','billing_completed','shipping_completed',
                            'payment_selected','place_order_clicked','order_placed','order_failed')
             LIMIT 50",
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
        return isset( $data['conversion_rate'] ) ? (float) $data['conversion_rate'] : 0.0;
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

        $hours     = min( 168, max( 1, $hours ) );
        $limit     = min( 500, max( 1, $limit ) );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event, context, created_at
             FROM {$wpdb->prefix}wac_logs
             WHERE level = 'error'
             AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT %d",
            $threshold,
            $limit
        ), ARRAY_A );

        // Sanitize context field to prevent serialized-object injection and limit size.
        foreach ( $rows as &$row ) {
            if ( isset( $row['context'] ) && is_string( $row['context'] ) ) {
                // Decode and re-encode JSON to strip any non-scalar structures.
                $ctx = json_decode( $row['context'], true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $ctx ) ) {
                    // Only keep scalar values (no nested objects/arrays).
                    $flat = array();
                    foreach ( $ctx as $k => $v ) {
                        if ( is_scalar( $v ) ) {
                            $flat[ sanitize_key( $k ) ] = substr( (string) $v, 0, 500 );
                        }
                    }
                    $row['context'] = wp_json_encode( $flat );
                } else {
                    // Non-JSON context: treat as plain text, truncate.
                    $row['context'] = substr( sanitize_text_field( $row['context'] ), 0, 2000 );
                }
            }
        }
        unset( $row );

        return $rows;
    }

    /**
     * Get a Google OAuth2 access token using JWT + service account credentials.
     *
     * Uses the OAuth 2.0 JWT Bearer flow (RFC 7523) for server-to-server
     * interaction with the Google Analytics Data API.
     *
     * @param array $credentials Service account credentials array.
     *
     * @return string|null Access token or null on failure.
     */
    private function get_ga4_access_token( array $credentials ): ?string {
        if ( ! isset( $credentials['client_email'], $credentials['private_key'] ) ) {
            return null;
        }

        // Check cache.
        $cached = get_transient( 'wac_ga4_token' );
        if ( false !== $cached ) {
            return $cached;
        }

        // Build JWT header + claim set.
        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $credentials['private_key_id'] ?? '',
        );

        $now = time();
        $claims = array(
            'iss'   => $credentials['client_email'],
            'scope' => self::ANALYTICS_SCOPE,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        );

        // Encode header + claims.
        $base64_header  = $this->base64url_encode( wp_json_encode( $header ) );
        $base64_claims  = $this->base64url_encode( wp_json_encode( $claims ) );
        $signature_input = $base64_header . '.' . $base64_claims;

        // Sign with private key.
        $private_key = $credentials['private_key'];

        // Validate private key format before signing.
        if ( ! str_contains( $private_key, '-----BEGIN' ) ) {
            return null;
        }

        $signature   = '';

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $success = @openssl_sign( $signature_input, $signature, $private_key, 'sha256WithRSAEncryption' );
        if ( ! $success || strlen( $signature ) < 10 ) {
            return null;
        }

        $jwt = $signature_input . '.' . $this->base64url_encode( $signature );

        // Exchange JWT for access token.
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['access_token'] ) ) {
            return null;
        }

        // Cache for 55 minutes (tokens expire after 1 hour).
        set_transient( 'wac_ga4_token', $body['access_token'], 55 * MINUTE_IN_SECONDS );

        return $body['access_token'];
    }

    /**
     * Base64 URL-safe encode (RFC 4648 section 5).
     *
     * @param string $data
     *
     * @return string
     */
    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
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
