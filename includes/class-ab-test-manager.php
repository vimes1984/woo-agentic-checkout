<?php
namespace WooAgenticCheckout;








defined( 'ABSPATH' ) || exit;

/**
 * A/B Test Manager — creates, manages, and analyses checkout experiments.
 *
 * Supports traffic splitting, variant configuration snapshots,
 * Bayesian analysis, and automatic winner promotion.
 *
 * @since 0.1.0-alpha
 */
class ABTestManager {

    /**
     * Experiment status constants.
     */
    const STATUS_DRAFT     = 'draft';
    const STATUS_ACTIVE    = 'active';
    const STATUS_PAUSED    = 'paused';
    const STATUS_WINNER    = 'winner';
    const STATUS_CONCLUDED = 'concluded';
    const STATUS_ARCHIVED  = 'archived';

    /**
     * Table name (with prefix).
     *
     * @var string
     */
    private $table_experiments;
    private $table_variants;
    private $table_events;

    /**
     * Request-level cache for frequently-called queries.
     *
     * @var array
     */
    private static $cache = array();

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table_experiments = $wpdb->prefix . 'wac_ab_experiments';
        $this->table_variants    = $wpdb->prefix . 'wac_ab_variants';
        $this->table_events      = $wpdb->prefix . 'wac_ab_events';

        $this->maybe_start_session();
    }

    /**
     * Log a database error if wpdb encountered one.
     *
     * @param string $context Description of the operation.
     */
    private function log_db_error( string $context ) {
        global $wpdb;
        if ( ! empty( $wpdb->last_error ) ) {
            // Translators: %1$s is the operation context, %2$s is the database error.
            error_log( sprintf( '[WAC ABTest] DB error in %1$s: %2$s', $context, $wpdb->last_error ) );
        }
    }

    /**
     * Ensure a PHP session is started so session_id() is reliable.
     */
    private function maybe_start_session() {
        if ( '' !== session_id() ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }
        if ( PHP_SESSION_NONE === session_status() ) {
            session_start();
        }
    }

    /**
     * Get a reliable session identifier, generating one if none exists.
     *
     * @return string
     */
    private function get_session_id(): string {
        static $sid = null;
        if ( null !== $sid ) {
            return $sid;
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            $sid = WC()->session->get_customer_id();
            if ( ! empty( $sid ) ) {
                return $sid;
            }
        }
        $sid = session_id();
        if ( ! empty( $sid ) ) {
            return $sid;
        }
        if ( ! headers_sent() ) {
            $this->maybe_start_session();
            $sid = session_id();
        }
        if ( empty( $sid ) ) {
            $sid = uniqid( 'wac_', true );
        }
        return $sid;
    }

    /**
     * Create a new A/B experiment.
     *
     * @param string $name        Human-readable name.
     * @param string $description Description / hypothesis.
     * @param array  $variants    Array of variant configs.
     * @param int    $traffic_pct Percentage of traffic to include (1-100).
     *
     * @return int Experiment ID.
     */
    public function create_experiment( string $name, string $description, array $variants, int $traffic_pct = 50 ): int {
        global $wpdb;

        if ( empty( $variants ) ) {
            return 0;
        }

        // Validate experiment name max length (DB column is VARCHAR(255)).
        if ( mb_strlen( $name ) > 255 ) {
            return 0;
        }
        // Validate experiment description max length (free-form, limit to 10,000).
        if ( mb_strlen( $description ) > 10000 ) {
            return 0;
        }

        // Validate each variant has required fields.
        foreach ( $variants as $i => $variant ) {
            if ( empty( $variant['key'] ) || empty( $variant['name'] ) ) {
                return 0;
            }
            // Validate variant key max length (DB column is VARCHAR(64)).
            if ( mb_strlen( $variant['key'] ) > 64 ) {
                return 0;
            }
            // Validate variant name max length (DB column is VARCHAR(255)).
            if ( mb_strlen( $variant['name'] ) > 255 ) {
                return 0;
            }
            // Validate config_snapshot is valid JSON if provided.
            if ( isset( $variant['config'] ) && ! is_array( $variant['config'] ) ) {
                return 0;
            }
            // Ensure traffic_percent is valid.
            if ( isset( $variant['traffic_percent'] ) && ( $variant['traffic_percent'] < 0 || $variant['traffic_percent'] > 100 ) ) {
                return 0;
            }
        }

        // Use transaction to ensure experiment + variants are inserted atomically.
        $wpdb->query( 'START TRANSACTION' );

        $inserted = $wpdb->insert(
            $this->table_experiments,
            array(
                'name'         => sanitize_text_field( $name ),
                'description'  => sanitize_textarea_field( $description ),
                'status'       => self::STATUS_ACTIVE,
                'traffic_pct'  => min( 100, max( 1, $traffic_pct ) ),
                'created_at'   => current_time( 'mysql' ),
                'control_key'  => sanitize_key( $variants[0]['key'] ?? 'control' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return 0;
        }

        $experiment_id = $wpdb->insert_id;

        foreach ( $variants as $i => $variant ) {
            $config = isset( $variant['config'] ) ? $variant['config'] : array();
            $inserted = $wpdb->insert(
                $this->table_variants,
                array(
                    'experiment_id'    => $experiment_id,
                    'variant_key'      => sanitize_key( $variant['key'] ),
                    'variant_name'     => sanitize_text_field( $variant['name'] ),
                    'is_control'       => 0 === $i ? 1 : 0,
                    'traffic_percent'  => $variant['traffic_percent'] ?? floor( 100 / count( $variants ) ),
                    'config_snapshot'  => wp_json_encode( $config ),
                    'status'           => self::STATUS_ACTIVE,
                    'created_at'       => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
            );

            if ( ! $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return 0;
            }
        }

        $wpdb->query( 'COMMIT' );

        return $experiment_id;
    }

    /**
     * Get active experiments.
     *
     * @return array
     */
    public function get_active_experiments(): array {
        if ( isset( self::$cache['active_experiments'] ) ) {
            return self::$cache['active_experiments'];
        }

        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, 
                    (SELECT COUNT(*) FROM {$this->table_variants} v WHERE v.experiment_id = e.id) as variant_count
             FROM {$this->table_experiments} e
             WHERE e.status = %s
             ORDER BY e.created_at DESC",
            self::STATUS_ACTIVE
        ), ARRAY_A );
        self::$cache['active_experiments'] = $results;
        return $results;
    }

    /**
     * Get all experiments with summary stats.
     *
     * @param string $status Optional status filter.
     * @param int    $limit  Max results.
     *
     * @return array
     */
    public function get_experiments( string $status = '', int $limit = 20 ): array {
        global $wpdb;

        $params = array();
        $where  = '';
        if ( ! empty( $status ) ) {
            $where    = 'WHERE e.status = %s';
            $params[] = $status;
        }

        $limit    = min( 200, absint( $limit ) );
        $params[] = $limit;

        $sql = "SELECT e.*,
                    (SELECT COUNT(*) FROM {$this->table_variants} v WHERE v.experiment_id = e.id) as variant_count,
                    (SELECT COUNT(*) FROM {$this->table_events} ev WHERE ev.experiment_id = e.id AND ev.event_type = 'impression') as total_impressions
             FROM {$this->table_experiments} e
             {$where}
             ORDER BY e.created_at DESC
             LIMIT %d";

        $results = $wpdb->get_results(
            $wpdb->prepare( $sql, $params ),
            ARRAY_A
        );

        // Fetch variants per experiment.
        foreach ( $results as &$exp ) {
            $exp['variants'] = $this->get_variants( $exp['id'] );
        }

        return $results;
    }

    /**
     * Get variants for an experiment.
     *
     * @param int $experiment_id
     *
     * @return array
     */
    public function get_variants( int $experiment_id ): array {
        // Return cached result if available (request-level).
        $cache_key = 'variants_' . $experiment_id;
        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        global $wpdb;

        // LEFT JOIN with pre-aggregated subqueries avoids 3 correlated subqueries per row.
        $sql = "SELECT v.*,
                       COALESCE(conv.conversions, 0)      AS conversions,
                       COALESCE(imp.impressions, 0)       AS impressions,
                       COALESCE(conv.revenue, 0)          AS revenue
                FROM {$this->table_variants} v
                LEFT JOIN (
                    SELECT variant_id,
                           COUNT(*)                                                         AS conversions,
                           COALESCE(SUM(CAST(JSON_EXTRACT(event_data, '$.revenue') AS DECIMAL(10,2))), 0) AS revenue
                    FROM {$this->table_events}
                    WHERE event_type = 'conversion'
                    GROUP BY variant_id
                ) conv ON conv.variant_id = v.id
                LEFT JOIN (
                    SELECT variant_id,
                           COUNT(*) AS impressions
                    FROM {$this->table_events}
                    WHERE event_type = 'impression'
                    GROUP BY variant_id
                ) imp ON imp.variant_id = v.id
                WHERE v.experiment_id = %d
                ORDER BY v.is_control DESC, v.id ASC";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $experiment_id ), ARRAY_A );

        // Strict type casting for numeric fields.
        foreach ( $results as &$row ) {
            $row["id"]             = (int) $row["id"];
            $row["experiment_id"]  = (int) $row["experiment_id"];
            $row["is_control"]     = (int) $row["is_control"];
            $row["traffic_percent"] = (int) $row["traffic_percent"];
            $row["winner_flag"]    = (int) $row["winner_flag"];
            $row["conversions"]    = (int) $row["conversions"];
            $row["impressions"]    = (int) $row["impressions"];
            $row["revenue"]        = (float) $row["revenue"];
        }
        unset( $row );

        self::$cache[ $cache_key ] = $results;
        return $results;
    }

    /**
     * Get a single experiment with variants.
     *
     * @param int $id
     *
     * @return array|null
     */
    public function get_experiment( int $id ): ?array {
        global $wpdb;

        $experiment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_experiments} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $experiment ) {
            return null;
        }

        $experiment['variants'] = $this->get_variants( $id );
        $experiment['runtime_days'] = $this->get_runtime_days( $id );
        $experiment['is_running'] = self::STATUS_ACTIVE === $experiment['status'];
        return $experiment;
    }

    /**
     * Assign variant(s) to current session.
     *
     * @return array<string, string> Variant key per experiment.
     */
    public function get_session_variants(): array {
        $assignments = array();
        $experiments = $this->get_active_experiments();

        foreach ( $experiments as $exp ) {
            $variant = $this->assign_variant( $exp );
            if ( $variant ) {
                $assignments[ $exp['name'] ] = $variant['variant_key'];
            }
        }

        return $assignments;
    }

    /**
     * Assign a user to a variant for an experiment (deterministic by session cookie).
     *
     * @param array $experiment Experiment row.
     *
     * @return array|null Variant row or null.
     */
    private function assign_variant( array $experiment ): ?array {
        $variants = $this->get_variants( $experiment['id'] );

        if ( empty( $variants ) ) {
            return null;
        }

        // Session hash for consistent assignment.
        $cookie_key = 'wac_exp_' . $experiment['id'];
        $variant_key = isset( $_COOKIE[ $cookie_key ] )
            ? sanitize_key( wp_unslash( $_COOKIE[ $cookie_key ] ) )
            : null;

        // Fallback: check for client-generated UUID (from localStorage JS polyfill).
        if ( null === $variant_key && ! empty( $_COOKIE['wac_client_id'] ) ) {
            $variant_key = isset( $_COOKIE[ $cookie_key . '_ls' ] )
                ? sanitize_key( wp_unslash( $_COOKIE[ $cookie_key . '_ls' ] ) )
                : null;
        }

        if ( $variant_key ) {
            foreach ( $variants as $v ) {
                if ( $v['variant_key'] === $variant_key ) {
                    // Refresh cookie TTL on each visit to extend session.
                    $this->refresh_variant_cookie( $cookie_key, $variant_key );
                    $this->record_impression( $v['id'], $experiment['id'] );
                    return $v;
                }
            }
        }

        // Assign based on traffic_pct threshold and variant weighting.
        // Use multiple entropy sources to avoid bias when session/cookies are unavailable.
        $entropy  = $cookie_key . $this->get_session_id();
        $entropy .= isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $entropy .= isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $hash = crc32( $entropy );
        $mod  = abs( $hash ) % 100;

        // If outside experiment traffic percentage, show control.
        if ( $mod >= $experiment['traffic_pct'] ) {
            foreach ( $variants as $v ) {
                if ( $v['is_control'] ) {
                    $this->record_impression( $v['id'], $experiment['id'] );
                    return $v;
                }
            }
        }

        // Weighted random assignment among active variants.
        $cumulative = 0;
        foreach ( $variants as $v ) {
            $cumulative += (int) $v['traffic_percent'];
            if ( $mod < $cumulative ) {
                $this->set_variant_cookie( $cookie_key, $v['variant_key'] );
                $this->record_impression( $v['id'], $experiment['id'] );
                return $v;
            }
        }

        // Fallback to control.
        foreach ( $variants as $v ) {
            if ( $v['is_control'] ) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Deduplication window in seconds.
     */
    const DEDUP_WINDOW = 1;

    /**
     * Check for duplicate event within the dedup window.
     *
     * @param int    $variant_id
     * @param int    $experiment_id
     * @param string $event_type
     *
     * @return bool True if duplicate.
     */
    private function is_duplicate_event( int $variant_id, int $experiment_id, string $event_type ): bool {
        global $wpdb;

        // Validate event_type against allowed values to prevent injection into DB query and cache key.
        $allowed_event_types = array( 'impression', 'conversion' );
        if ( ! in_array( $event_type, $allowed_event_types, true ) ) {
            return false;
        }

        $cache_key = 'wac_dedup_' . $variant_id . '_' . $experiment_id . '_' . $event_type . '_' . $this->get_session_id();
        $last_time = get_transient( $cache_key );

        if ( false !== $last_time ) {
            return true;
        }

        // Also check DB for any event within the dedup window.
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_events}
             WHERE variant_id = %d
               AND experiment_id = %d
               AND event_type = %s
               AND session_id = %s
               AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $variant_id,
            $experiment_id,
            $event_type,
            $this->get_session_id(),
            self::DEDUP_WINDOW
        ) );

        if ( (int) $recent > 0 ) {
            return true;
        }

        // Set transient to block rapid-fire repeats.
        set_transient( $cache_key, time(), self::DEDUP_WINDOW );

        return false;
    }

    /**
     * Record a conversion event for a variant.
     *
     * @param int    $variant_id
     * @param int    $experiment_id
     * @param float  $revenue
     * @param string $user_id
     */
    public function record_conversion( int $variant_id, int $experiment_id, float $revenue = 0.0, string $user_id = '' ) {
        global $wpdb;

        if ( $this->is_duplicate_event( $variant_id, $experiment_id, 'conversion' ) ) {
            return;
        }

        $wpdb->insert(
            $this->table_events,
            array(
                'variant_id'    => $variant_id,
                'experiment_id' => $experiment_id,
                'event_type'    => 'conversion',
                'event_data'    => wp_json_encode( array(
                    'revenue' => $revenue,
                    'user_id' => sanitize_text_field( $user_id ),
                ) ),
                'session_id'    => $this->get_session_id(),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );
        $this->log_db_error( 'record_conversion' );
    }

    /**
     * Record an impression.
     *
     * @param int $variant_id
     * @param int $experiment_id
     */
    private function record_impression( int $variant_id, int $experiment_id ) {
        global $wpdb;

        if ( $this->is_duplicate_event( $variant_id, $experiment_id, 'impression' ) ) {
            return;
        }

        $wpdb->insert(
            $this->table_events,
            array(
                'variant_id'    => $variant_id,
                'experiment_id' => $experiment_id,
                'event_type'    => 'impression',
                'event_data'    => '{}',
                'session_id'    => $this->get_session_id(),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );
        $this->log_db_error( 'record_impression' );
    }

    /**
     * Set A/B variant cookie.
     *
     * @param string $key
     * @param string $value
     */
    /**
     * Cookie lifetime in seconds (30 days).
     */
    const COOKIE_TTL = 2592000; // 30 * 24 * 60 * 60

    /**
     * Set A/B variant cookie, mirroring to localStorage-bridge cookie if client has one.
     */
    private function set_variant_cookie( string $key, string $value ) {
        if ( ! headers_sent() ) {
            $options = array(
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            );
            setcookie( $key, $value, $options );

            // Mirror to a JS-readable cookie as localStorage bridge.
            if ( ! empty( $_COOKIE['wac_client_id'] ) ) {
                $ls_key             = $key . '_ls';
                $options['httponly'] = false;
                setcookie( $ls_key, $value, $options );
            }
        }
    }

    /**
     * Refresh the variant assignment cookie TTL on repeat visits.
     */
    private function refresh_variant_cookie( string $key, string $value ) {
        if ( headers_sent() ) {
            return;
        }
        $this->set_variant_cookie( $key, $value );
    }

    /**
     * Minimum impressions required per variant before a winner can be declared.
     */
    const MIN_SAMPLE_SIZE = 100;

    /**
     * Minimum duration in seconds before a winner can be declared (7 days).
     * Prevents peeking at results before the experiment has had time to stabilize.
     */
    const MIN_DURATION = 604800; // 7 days.

    /**
     * Declare a winner for an experiment.
     *
     * @param int    $experiment_id
     * @param string $variant_key
     *
     * @throws \RuntimeException If minimum sample size not met or experiment too young.
     */
    public function declare_winner( int $experiment_id, string $variant_key ) {
        global $wpdb;

        $experiment = $this->get_experiment( $experiment_id );
        if ( ! $experiment ) {
            throw new \RuntimeException( 'Experiment not found.' );
        }

        // Enforce minimum sample size.
        $min_ok = $this->check_minimum_sample_size( $experiment );
        if ( ! $min_ok ) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot declare winner: minimum sample size of %d impressions per variant not met.',
                    self::MIN_SAMPLE_SIZE
                )
            );
        }

        // Enforce minimum duration (anti-peeking guard).
        $created_at = strtotime( $experiment['created_at'] );
        if ( $created_at && ( time() - $created_at ) < self::MIN_DURATION ) {
            $remaining = human_time_diff( time(), $created_at + self::MIN_DURATION );
            throw new \RuntimeException(
                sprintf(
                    'Cannot declare winner: experiment must run for at least %d days. %s remaining.',
                    self::MIN_DURATION / DAY_IN_SECONDS,
                    $remaining
                )
            );
        }
        if ( ! $min_ok ) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot declare winner: minimum sample size of %d impressions per variant not met.',
                    self::MIN_SAMPLE_SIZE
                )
            );
        }

        // Wrap all mutations in a transaction for atomicity.
        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->update(
            $this->table_experiments,
            array(
                'status'    => self::STATUS_WINNER,
                'winner_key' => $variant_key,
                'ended_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $experiment_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        // Mark winning variant.
        $updated = $wpdb->update(
            $this->table_variants,
            array( 'winner_flag' => 1 ),
            array(
                'experiment_id' => $experiment_id,
                'variant_key'   => $variant_key,
            ),
            array( '%d' ),
            array( '%d', '%s' )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        // Mark all other variants as concluded.
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_variants}
             SET status = 'concluded'
             WHERE experiment_id = %d AND variant_key != %s",
            $experiment_id,
            $variant_key
        ) );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        // Log the winner declaration.
        do_action( 'wac_ab_test_winner', $experiment_id, $variant_key );
    }

    /**
     * Check that all variants meet the minimum sample size threshold.
     *
     * @param array $experiment
     *
     * @return bool
     */
    private function check_minimum_sample_size( array $experiment ): bool {
        $variants = $experiment['variants'] ?? $this->get_variants( $experiment['id'] );
        foreach ( $variants as $v ) {
            if ( (int) $v['impressions'] < self::MIN_SAMPLE_SIZE ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Number of non-control variants (cached for correction).
     *
     * @var int|null
     */
    private $non_control_count = null;

    /**
     * Compute simple Bayesian probability of each variant being best.
     * Winner is the variant with highest probability of being superior to control.
     * Applies Bonferroni correction when more than 2 variants exist.
     *
     * @param int $experiment_id
     *
     * @return array Variant probabilities.
     */
    public function bayesian_analysis( int $experiment_id ): array {
        $variants = $this->get_variants( $experiment_id );
        $results  = array();

        if ( empty( $variants ) ) {
            return $results;
        }

        // Find control.
        $control = null;
        foreach ( $variants as $v ) {
            if ( $v['is_control'] ) {
                $control = $v;
                break;
            }
        }

        if ( ! $control || ! $control['impressions'] ) {
            return $results;
        }

        $non_control = 0;
        foreach ( $variants as $v ) {
            if ( ! $v['is_control'] ) {
                $non_control++;
            }
        }
        $this->non_control_count = $non_control;
        $bonferroni_alpha = $non_control > 1 ? ( 0.05 / $non_control ) : 0.05;

        $control_cr = $control['impressions'] > 0
            ? $control['conversions'] / $control['impressions']
            : 0;

        // Include control variant as baseline for reference.
        $results[] = array(
            'variant_key'      => $control['variant_key'],
            'variant_name'     => $control['variant_name'] . ' (control)',
            'impressions'      => (int) $control['impressions'],
            'conversions'      => (int) $control['conversions'],
            'cr'               => round( $control_cr * 100, 2 ),
            'control_cr'       => round( $control_cr * 100, 2 ),
            'lift'             => 0.0,
            'prob_better'      => 50.0,
            'prob_threshold'   => 0.0,
            'bonferroni_pass'  => true,
            'revenue'          => (float) $control['revenue'],
            'is_significant'   => false,
            'is_control'       => true,
        );

        foreach ( $variants as $v ) {
            // Skip control in the comparison list (it always has 50% prob vs itself).
            if ( $v['is_control'] ) {
                continue;
            }

            $variant_cr = $v['impressions'] > 0
                ? $v['conversions'] / $v['impressions']
                : 0;

            // Simple Bayesian probability using Beta distribution approximation.
            $prob_better = $this->probability_beat_control(
                $v['conversions'] + 1,      // Alpha + prior
                $v['impressions'] - $v['conversions'] + 1, // Beta + prior
                $control['conversions'] + 1,
                $control['impressions'] - $control['conversions'] + 1
            );

            // Bayes Factor (BF10): evidence strength for variant > control.
            $bf10 = $this->compute_bayes_factor( $prob_better );

            // Adjusted threshold: Bonferroni correction for multiple comparisons.
            $prob_threshold = $bonferroni_alpha * 100;

            $results[] = array(
                'variant_key'      => $v['variant_key'],
                'variant_name'     => $v['variant_name'],
                'impressions'      => (int) $v['impressions'],
                'conversions'      => (int) $v['conversions'],
                'cr'               => round( $variant_cr * 100, 2 ),
                'control_cr'       => round( $control_cr * 100, 2 ),
                'lift'             => $control_cr > 0
                    ? round( ( ( $variant_cr - $control_cr ) / $control_cr ) * 100, 2 )
                    : 0,
                'prob_better'      => round( $prob_better * 100, 2 ),
                'bf10'             => $bf10,
                'bf10_label'       => $bf10 >= 10 ? 'strong' : ( $bf10 >= 3 ? 'moderate' : ( $bf10 >= 1 ? 'anecdotal' : 'none' ) ),
                'prob_threshold'   => round( $prob_threshold, 2 ),
                'bonferroni_pass'  => ( $prob_better * 100 ) >= $prob_threshold,
                'revenue'          => (float) $v['revenue'],
                'is_significant'   => ( $prob_better * 100 ) >= 95.0,
                'is_control'       => false,
            );
        }

        return $results;
    }

    /**
     * Number of Monte Carlo samples to draw for Bayesian inference.
     *
     * @var int
     */
    private $mc_samples = 10000;

    /**
     * Set the number of Monte Carlo samples for Bayesian inference.
     *
     * @param int $samples
     */
    public function set_mc_samples( int $samples ) {
        $this->mc_samples = max( 100, min( 1000000, $samples ) );
    }

    /**
     * Compute the Bayes Factor (BF10) for variant vs control using the Savage-Dickey ratio.
     * BF10 > 3 indicates moderate evidence, > 10 strong evidence.
     *
     * @param float $prob_better P(variant > control).
     *
     * @return float Bayes Factor.
     */
    private function compute_bayes_factor( float $prob_better ): float {
        // BF10 = P(variant > control) / (1 - P(variant > control)).
        // A flat Beta(1,1) prior gives equal weight; the BF expresses evidence update.
        $odds = $prob_better / max( 1e-10, ( 1.0 - $prob_better ) );
        return round( $odds, 4 );
    }

    /**
     * Monte Carlo approximation of P(variant > control) using Beta distributions.
     *
     * @param int $a1 Variant alpha.
     * @param int $b1 Variant beta.
     * @param int $a2 Control alpha.
     * @param int $b2 Control beta.
     *
     * @return float Probability (0-1).
     */
    private function probability_beat_control( int $a1, int $b1, int $a2, int $b2 ): float {
        $samples  = $this->mc_samples;
        $wins     = 0;
        $mt_state = wp_rand( 0, PHP_INT_MAX );

        for ( $i = 0; $i < $samples; $i++ ) {
            $x = $this->beta_sample( $a1, $b1, $mt_state + $i );
            $y = $this->beta_sample( $a2, $b2, $mt_state + $i + 10000 );

            if ( $x > $y ) {
                $wins++;
            }
        }

        return $wins / $samples;
    }

    /**
     * Generate a Beta-distributed random sample using the Gamma approximation.
     *
     * @param int   $alpha
     * @param int   $beta
     * @param int   $seed
     *
     * @return float
     */
    private function beta_sample( int $alpha, int $beta, int $seed = 0 ): float {
        $x = $this->gamma_sample( $alpha, 1, $seed );
        $y = $this->gamma_sample( $beta, 1, $seed + 9999 );
        return $x / ( $x + $y );
    }

    /**
     * Marsaglia-Tsang method for Gamma-distributed sample.
     *
     * @param int   $shape
     * @param int   $scale
     * @param int   $seed
     *
     * @return float
     */
    private function gamma_sample( int $shape, int $scale, int $seed = 0 ): float {
        mt_srand( $seed );
        $d  = $shape - 1.0 / 3.0;
        $c  = 1.0 / sqrt( 9.0 * $d );

        while ( true ) {
            $v = 0.0;
            for ( $i = 0; $i < 12; $i++ ) {
                $v += mt_rand() / mt_getrandmax();
            }
            $x = ( $v - 6.0 ) / $c;
            $v = 1.0 + $c * $x;

            if ( $v <= 0 ) {
                continue;
            }

            $v = $v * $v * $v;
            $u = mt_rand() / mt_getrandmax();

            if ( $u < 1.0 - 0.0331 * ( $x * $x ) * ( $x * $x ) ) {
                return $d * $v / $scale;
            }

            if ( log( $u ) < 0.5 * $x * $x + $d * ( 1.0 - $v + log( $v ) ) ) {
                return $d * $v / $scale;
            }
        }
    }

    /**
     * Pause an experiment.
     *
     * @param int $experiment_id
     */
    public function pause_experiment( int $experiment_id ) {
        global $wpdb;
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp || ! $this->is_valid_transition( $exp['status'], self::STATUS_PAUSED ) ) {
            return;
        }
        $wpdb->update(
            $this->table_experiments,
            array( 'status' => self::STATUS_PAUSED ),
            array( 'id' => $experiment_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Resume a paused experiment.
     *
     * @param int $experiment_id
     */
    public function resume_experiment( int $experiment_id ) {
        global $wpdb;
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp || ! $this->is_valid_transition( $exp['status'], self::STATUS_ACTIVE ) ) {
            return;
        }
        $wpdb->update(
            $this->table_experiments,
            array( 'status' => self::STATUS_ACTIVE ),
            array( 'id' => $experiment_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Conclude an experiment without declaring a winner.
     *
     * @param int $experiment_id
     */
    public function conclude_experiment( int $experiment_id ) {
        global $wpdb;
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp || ! $this->is_valid_transition( $exp['status'], self::STATUS_CONCLUDED ) ) {
            return;
        }
        $wpdb->update(
            $this->table_experiments,
            array( 'status' => self::STATUS_CONCLUDED, 'ended_at' => current_time( 'mysql' ) ),
            array( 'id' => $experiment_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get conversion stats for an experiment variant.
     *
     * @param int $variant_id
     *
     * @return array
     */
    public function get_variant_stats( int $variant_id ): array {
        global $wpdb;

        $impressions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_events} WHERE variant_id = %d AND event_type = 'impression'",
            $variant_id
        ) );

        $conversions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_events} WHERE variant_id = %d AND event_type = 'conversion'",
            $variant_id
        ) );

        $revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(JSON_EXTRACT(event_data, '$.revenue') AS DECIMAL(10,2))), 0)
             FROM {$this->table_events}
             WHERE variant_id = %d AND event_type = 'conversion'",
            $variant_id
        ) );

        return array(
            'impressions' => $impressions,
            'conversions' => $conversions,
            'revenue'     => $revenue,
            'cr'          => $impressions > 0 ? round( ( $conversions / $impressions ) * 100, 2 ) : 0,
        );
    }

    /**
     * Detect overlapping experiments — warn if a visitor is in multiple experiments simultaneously.
     *
     * @return array List of active experiment names the current visitor is assigned to.
     */
    public function get_active_overlaps(): array {
        $experiments = $this->get_active_experiments();
        $overlaps    = array();

        if ( count( $experiments ) < 2 ) {
            return $overlaps;
        }

        $assigned = $this->get_session_variants();
        foreach ( $assigned as $exp_name => $variant_key ) {
            $overlaps[] = array(
                'experiment' => $exp_name,
                'variant'    => $variant_key,
            );
        }

        return $overlaps;
    }

    /**
     * Compute the expected loss of choosing a variant over control (E[Loss]).
     *
     * @param int $experiment_id
     *
     * @return array Variant key => expected loss.
     */
    public function get_expected_loss( int $experiment_id ): array {
        $analysis = $this->bayesian_analysis( $experiment_id );

        // Guard: ensure analysis is an array before iterating.
        if ( ! is_array( $analysis ) || empty( $analysis ) ) {
            return array();
        }

        $losses   = array();

        $best_prob = 0.0;
        $best_key  = '';
        foreach ( $analysis as $r ) {
            if ( ! $r['is_control'] && $r['prob_better'] > $best_prob ) {
                $best_prob = $r['prob_better'];
                $best_key  = $r['variant_key'];
            }
        }

        foreach ( $analysis as $r ) {
            if ( $r['is_control'] ) {
                continue;
            }
            // Expected loss = (1 - P(being best)) * potential upside.
            $prob_being_best = $r['prob_better'] / 100.0;
            $expected_loss   = ( 1.0 - $prob_being_best ) * ( $best_prob / 100.0 );
            $losses[ $r['variant_key'] ] = round( $expected_loss, 4 );
        }

        return $losses;
    }

    /**
     * Compute a 95% credible interval for each variant's conversion rate.
     *
     * @param int $experiment_id
     *
     * @return array Variant key => { cr_lower, cr_upper }.
     */
    public function get_credible_intervals( int $experiment_id ): array {
        $variants = $this->get_variants( $experiment_id );
        $intervals = array();

        foreach ( $variants as $v ) {
            $alpha = $v['conversions'] + 1;
            $beta  = $v['impressions'] - $v['conversions'] + 1;

            // Use approximation: mean ± 2 * std for Beta distribution.
            $mean   = $alpha / ( $alpha + $beta );
            $std    = sqrt( ( $alpha * $beta ) / ( ( $alpha + $beta ) ** 2 * ( $alpha + $beta + 1 ) ) );
            $lower  = max( 0, ( $mean - 2 * $std ) * 100 );
            $upper  = min( 100, ( $mean + 2 * $std ) * 100 );

            $intervals[ $v['variant_key'] ] = array(
                'cr_lower' => round( $lower, 2 ),
                'cr_upper' => round( $upper, 2 ),
            );
        }

        return $intervals;
    }

    /**
     * Detect Sample Ratio Mismatch (SRM) — checks if observed traffic split matches expected.
     *
     * @param int $experiment_id
     *
     * @return array{ has_srm: bool, p_value: float }
     */
    public function detect_srm( int $experiment_id ): array {
        $variants = $this->get_variants( $experiment_id );
        if ( empty( $variants ) ) {
            return array( 'has_srm' => false, 'p_value' => 1.0 );
        }

        $total_impressions = array_sum( array_column( $variants, 'impressions' ) );
        if ( $total_impressions < 1 ) {
            return array( 'has_srm' => false, 'p_value' => 1.0 );
        }

        $chi2 = 0.0;
        foreach ( $variants as $v ) {
            $expected = $total_impressions * ( $v['traffic_percent'] / 100.0 );
            $observed = (int) $v['impressions'];
            if ( $expected > 0 ) {
                $chi2 += ( ( $observed - $expected ) ** 2 ) / $expected;
            }
        }

        // Chi-squared with df = k-1. Approximate p-value using simple threshold.
        $df      = count( $variants ) - 1;
        $has_srm = $chi2 > ( $df > 0 ? 3.84 * $df : 3.84 ); // Approximate critical value.

        return array(
            'has_srm'  => $has_srm,
            'chi2'     => round( $chi2, 4 ),
            'p_value'  => round( exp( -$chi2 / 2 ), 4 ), // Rough approximation.
        );
    }

    /**
     * Export experiment data as an array suitable for JSON/CSV.
     *
     * @param int $experiment_id
     *
     * @return array
     */
    public function export_experiment( int $experiment_id ): array {
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp ) {
            return array();
        }

        $data = array(
            'experiment' => array(
                'id'          => $exp['id'],
                'name'        => $exp['name'],
                'description' => $exp['description'],
                'status'      => $exp['status'],
                'created_at'  => $exp['created_at'],
                'ended_at'    => $exp['ended_at'] ?? '',
                'winner_key'  => $exp['winner_key'] ?? '',
            ),
            'variants' => array(),
        );

        foreach ( $exp['variants'] as $v ) {
            $data['variants'][] = array(
                'key'           => $v['variant_key'],
                'name'          => $v['variant_name'],
                'is_control'    => (bool) $v['is_control'],
                'impressions'   => (int) $v['impressions'],
                'conversions'   => (int) $v['conversions'],
                'cr'            => $v['impressions'] > 0
                    ? round( $v['conversions'] / $v['impressions'] * 100, 4 )
                    : 0,
                'revenue'       => (float) $v['revenue'],
                'traffic_pct'   => (int) $v['traffic_percent'],
                'config'        => json_decode( $v['config_snapshot'], true ),
            );
        }

        return $data;
    }

    /**
     * Get day-of-week conversion analysis for an experiment.
     *
     * @param int $experiment_id
     *
     * @return array
     */
    public function get_dow_breakdown( int $experiment_id ): array {
        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DAYOFWEEK(ev.created_at) as dow,
                ev.variant_id,
                v.variant_key,
                v.variant_name,
                COUNT(*) as events,
                SUM(CASE WHEN ev.event_type = 'conversion' THEN 1 ELSE 0 END) as conversions
             FROM {$this->table_events} ev
             INNER JOIN {$this->table_variants} v ON v.id = ev.variant_id
             WHERE ev.experiment_id = %d
             GROUP BY DAYOFWEEK(ev.created_at), ev.variant_id, v.variant_key, v.variant_name
             ORDER BY dow, v.variant_key",
            $experiment_id
        ), ARRAY_A );

        $this->log_db_error( 'get_dow_breakdown' );

        return $results ?: array();
    }

    /**
     * Get cumulative conversion data over time for charting.
     *
     * @param int    $experiment_id
     * @param string $granularity 'hour'|'day'|'week'
     *
     * @return array
     */
    public function get_cumulative_data( int $experiment_id, string $granularity = 'day' ): array {
        global $wpdb;

        // Validate granularity to prevent SQL injection via DATE_FORMAT string.
        $allowed_granularities = array( 'hour', 'day', 'week' );
        if ( ! in_array( $granularity, $allowed_granularities, true ) ) {
            return array();
        }

        $date_format = ( 'hour' === $granularity ) ? '%Y-%m-%d %H:00:00' : ( 'week' === $granularity ? '%Y-%u' : '%Y-%m-%d' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE_FORMAT(ev.created_at, '{$date_format}') as period,
                ev.variant_id,
                v.variant_key,
                COUNT(*) as total_events,
                SUM(CASE WHEN ev.event_type = 'conversion' THEN 1 ELSE 0 END) as conversions,
                SUM(CASE WHEN ev.event_type = 'impression' THEN 1 ELSE 0 END) as impressions
             FROM {$this->table_events} ev
             INNER JOIN {$this->table_variants} v ON v.id = ev.variant_id
             WHERE ev.experiment_id = %d
             GROUP BY DATE_FORMAT(ev.created_at, '{$date_format}'), ev.variant_id, v.variant_key
             ORDER BY period ASC, v.variant_key",
            $experiment_id
        ), ARRAY_A );

        $this->log_db_error( 'get_cumulative_data' );

        return $results ?: array();
    }

    /**
     * Archive (soft-delete) an experiment.
     *
     * @param int $experiment_id
     */
    public function archive_experiment( int $experiment_id ) {
        global $wpdb;
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp || ! $this->is_valid_transition( $exp['status'], self::STATUS_ARCHIVED ) ) {
            return;
        }
        $wpdb->update(
            $this->table_experiments,
            array( 'status' => self::STATUS_ARCHIVED ),
            array( 'id' => $experiment_id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->log_db_error( 'archive_experiment' );
    }

    /**
     * Estimate required sample size for given parameters.
     *
     * @param float $baseline_cr      Expected control conversion rate (0-1).
     * @param float $minimum_effect   Minimum detectable effect (relative, 0-1).
     * @param float $alpha            Significance level.
     * @param float $power            Statistical power.
     *
     * @return int Estimated samples per variant.
     */
    public function estimate_sample_size( float $baseline_cr, float $minimum_effect = 0.10, float $alpha = 0.05, float $power = 0.80 ): int {
        if ( $baseline_cr <= 0 || $baseline_cr >= 1 ) {
            return 0;
        }

        $z_alpha = 1.96; // z for alpha=0.05
        $z_beta  = 0.84;  // z for power=0.80

        if ( 0.01 === $alpha ) {
            $z_alpha = 2.576;
        } elseif ( 0.10 === $alpha ) {
            $z_alpha = 1.645;
        }

        if ( 0.90 === $power ) {
            $z_beta = 1.282;
        } elseif ( 0.95 === $power ) {
            $z_beta = 1.645;
        }

        $p1 = $baseline_cr;
        $p2 = $p1 * ( 1 + $minimum_effect );

        if ( $p2 >= 1.0 ) {
            return 0;
        }

        $p_avg = ( $p1 + $p2 ) / 2.0;
        $num   = ( $z_alpha * sqrt( 2 * $p_avg * ( 1 - $p_avg ) ) + $z_beta * sqrt( $p1 * ( 1 - $p1 ) + $p2 * ( 1 - $p2 ) ) ) ** 2;
        $den   = ( $p2 - $p1 ) ** 2;

        $n = (int) ceil( $num / max( 0.0001, $den ) );

        return max( 10, $n );
    }

    /**
     * Get a list of recommended database indexes for the events table.
     *
     * @return array
     */
    public function get_recommended_indexes(): array {
        return array(
            'CREATE INDEX idx_wac_events_exp_var ON ' . $this->table_events . ' (experiment_id, variant_id);',
            'CREATE INDEX idx_wac_events_session ON ' . $this->table_events . ' (session_id, event_type, created_at);',
            'CREATE INDEX idx_wac_events_type_time ON ' . $this->table_events . ' (event_type, created_at);',
        );
    }

    /**
     * Get a summary of all experiments suitable for dashboard display.
     *
     * @return array
     */
    public function get_dashboard_summary(): array {
        $experiments = $this->get_experiments();
        $summary     = array(
            'total_experiments' => count( $experiments ),
            'active_count'      => 0,
            'winner_count'      => 0,
            'total_impressions' => 0,
            'total_conversions' => 0,
            'total_revenue'     => 0.0,
            'active'            => array(),
        );

        foreach ( $experiments as $exp ) {
            if ( 'active' === $exp['status'] ) {
                $summary['active_count']++;
            }
            if ( 'winner' === $exp['status'] ) {
                $summary['winner_count']++;
            }

            $impressions  = 0;
            $conversions  = 0;
            $revenue      = 0.0;
            foreach ( $exp['variants'] as $v ) {
                $impressions += (int) $v['impressions'];
                $conversions += (int) $v['conversions'];
                $revenue     += (float) $v['revenue'];
            }

            $summary['total_impressions'] += $impressions;
            $summary['total_conversions'] += $conversions;
            $summary['total_revenue']     += $revenue;

            $summary['active'][] = array(
                'id'          => $exp['id'],
                'name'        => $exp['name'],
                'status'      => $exp['status'],
                'impressions' => $impressions,
                'conversions' => $conversions,
                'revenue'     => $revenue,
                'winner'      => $exp['winner_key'] ?? '',
            );
        }

        return $summary;
    }

    /**
     * Get the list of all possible events stored as CSV-friendly output.
     *
     * @param int    $experiment_id
     * @param int    $limit
     * @param int    $offset
     *
     * @return array
     */
    public function get_raw_events( int $experiment_id, int $limit = 100, int $offset = 0 ): array {
        global $wpdb;

        $limit  = min( 1000, max( 1, $limit ) );
        $offset = max( 0, $offset );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ev.*, v.variant_key, v.variant_name
             FROM {$this->table_events} ev
             INNER JOIN {$this->table_variants} v ON v.id = ev.variant_id
             WHERE ev.experiment_id = %d
             ORDER BY ev.created_at DESC
             LIMIT %d OFFSET %d",
            $experiment_id,
            $limit,
            $offset
        ), ARRAY_A );
    }

    /**
     * Detect novelty effect — check if conversion rate changes significantly between early and late periods.
     *
     * @param int $experiment_id
     *
     * @return array{ has_novelty: bool, early_cr: float, late_cr: float }
     */
    public function detect_novelty_effect( int $experiment_id ): array {
        global $wpdb;

        $cutoff = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM (
                SELECT created_at, @rownum := @rownum + 1 AS rn
                FROM {$this->table_events}
                WHERE experiment_id = %d
            ) AS e, (SELECT @rownum := 0) AS r
            WHERE rn = (SELECT FLOOR(COUNT(*)/2) FROM {$this->table_events} WHERE experiment_id = %d)",
            $experiment_id,
            $experiment_id
        ) );

        if ( ! $cutoff ) {
            return array( 'has_novelty' => false, 'early_cr' => 0, 'late_cr' => 0 );
        }

        $stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                CASE WHEN created_at < %s THEN 'early' ELSE 'late' END as period,
                v.variant_key,
                COUNT(*) as events,
                SUM(CASE WHEN ev.event_type = 'conversion' THEN 1 ELSE 0 END) as conversions
             FROM {$this->table_events} ev
             INNER JOIN {$this->table_variants} v ON v.id = ev.variant_id
             WHERE ev.experiment_id = %d
             GROUP BY period, v.variant_key",
            $cutoff,
            $experiment_id
        ), ARRAY_A );

        $this->log_db_error( 'detect_novelty_effect' );

        $early_total = 0;
        $early_conv  = 0;
        $late_total  = 0;
        $late_conv   = 0;

        foreach ( $stats ?: array() as $row ) {
            if ( 'early' === $row['period'] ) {
                $early_total += (int) $row['events'];
                $early_conv  += (int) $row['conversions'];
            } else {
                $late_total += (int) $row['events'];
                $late_conv  += (int) $row['conversions'];
            }
        }

        $early_cr = $early_total > 0 ? $early_conv / $early_total : 0;
        $late_cr  = $late_total > 0 ? $late_conv / $late_total : 0;

        return array(
            'has_novelty' => abs( $early_cr - $late_cr ) > 0.02,
            'early_cr'    => round( $early_cr * 100, 2 ),
            'late_cr'     => round( $late_cr * 100, 2 ),
            'difference'  => round( ( $early_cr - $late_cr ) * 100, 2 ),
        );
    }

    /**
     * Detect outliers in conversion data using IQR method.
     *
     * @param int $experiment_id
     *
     * @return array
     */
    public function detect_outliers( int $experiment_id ): array {
        global $wpdb;

        $revenues = $wpdb->get_col( $wpdb->prepare(
            "SELECT CAST(JSON_EXTRACT(event_data, '$.revenue') AS DECIMAL(10,2))
             FROM {$this->table_events}
             WHERE experiment_id = %d AND event_type = 'conversion' AND JSON_EXTRACT(event_data, '$.revenue') > 0",
            $experiment_id
        ) );

        if ( count( $revenues ) < 4 ) {
            return array( 'has_outliers' => false, 'count' => 0, 'outliers' => array() );
        }

        sort( $revenues );
        $q1 = $revenues[ (int) floor( count( $revenues ) * 0.25 ) ];
        $q3 = $revenues[ (int) floor( count( $revenues ) * 0.75 ) ];
        $iqr = $q3 - $q1;
        $lower = $q1 - 1.5 * $iqr;
        $upper = $q3 + 1.5 * $iqr;

        $outliers = array();
        foreach ( $revenues as $r ) {
            if ( $r < $lower || $r > $upper ) {
                $outliers[] = $r;
            }
        }

        return array(
            'has_outliers' => count( $outliers ) > 0,
            'count'        => count( $outliers ),
            'outliers'     => $outliers,
            'q1'           => $q1,
            'q3'           => $q3,
            'iqr'          => $iqr,
        );
    }

    /**
     * Get conversion rate broken down by hour of day for each variant.
     *
     * @param int $experiment_id
     *
     * @return array
     */
    public function get_hourly_breakdown( int $experiment_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                HOUR(ev.created_at) as hour,
                v.variant_key,
                COUNT(*) as events,
                SUM(CASE WHEN ev.event_type = 'conversion' THEN 1 ELSE 0 END) as conversions
             FROM {$this->table_events} ev
             INNER JOIN {$this->table_variants} v ON v.id = ev.variant_id
             WHERE ev.experiment_id = %d
             GROUP BY HOUR(ev.created_at), v.variant_key
             ORDER BY hour, v.variant_key",
            $experiment_id
        ), ARRAY_A ) ?: array();
    }

    /**
     * Simulate what a variant looks like from the config perspective.
     *
     * @param int $variant_id
     *
     * @return array|null
     */
    public function preview_variant( int $variant_id ): ?array {
        global $wpdb;

        $variant = $wpdb->get_row( $wpdb->prepare(
            "SELECT v.*, e.name as experiment_name
             FROM {$this->table_variants} v
             INNER JOIN {$this->table_experiments} e ON e.id = v.experiment_id
             WHERE v.id = %d",
            $variant_id
        ), ARRAY_A );

        if ( ! $variant ) {
            return null;
        }

        $config = json_decode( $variant['config_snapshot'], true );

        return array(
            'experiment' => $variant['experiment_name'],
            'variant'    => $variant['variant_key'],
            'name'       => $variant['variant_name'],
            'config'     => is_array( $config ) ? $config : array(),
            'effects'    => array(
                'removes_fields' => ! empty( $config['remove_fields'] ) ? $config['remove_fields'] : array(),
                'hides_fields'   => ! empty( $config['hide_fields'] ) ? $config['hide_fields'] : array(),
                'reorders'       => ! empty( $config['field_order'] ),
                'changes_labels' => ! empty( $config['field_labels'] ),
                'uses_template'  => ! empty( $config['template'] ),
            ),
        );
    }

    /**
     * Run a quick A/A test — check if all variants have identical configs.
     *
     * @param int $experiment_id
     *
     * @return array{ is_aa: bool, unique_configs: int }
     */
    public function detect_aa_test( int $experiment_id ): array {
        $variants = $this->get_variants( $experiment_id );
        $configs  = array();

        foreach ( $variants as $v ) {
            $config = json_decode( $v['config_snapshot'], true );
            $configs[ md5( wp_json_encode( $config ) ) ] = true;
        }

        $unique = count( $configs );

        return array(
            'is_aa'          => $unique <= 1 && count( $variants ) > 1,
            'unique_configs' => $unique,
        );
    }

    /**
     * Get a composite score for variant performance (weighted combination of metrics).
     *
     * @param int    $experiment_id
     * @param array  $weights      e.g. ['cr_weight' => 0.5, 'revenue_weight' => 0.3, 'significance_weight' => 0.2]
     *
     * @return array
     */
    public function get_composite_scores( int $experiment_id, array $weights = array() ): array {
        $defaults = array(
            'cr_weight'          => 0.5,
            'revenue_weight'     => 0.3,
            'significance_weight' => 0.2,
        );
        $weights  = array_merge( $defaults, $weights );
        // Clamp weight values to valid range (0-1) to prevent negative/overflow manipulation.
        foreach ( $weights as &$w ) {
            $w = max( 0.0, min( 1.0, (float) $w ) );
        }
        unset( $w );
        $analysis = $this->bayesian_analysis( $experiment_id );

        // Guard: ensure analysis is an array before iterating.
        if ( ! is_array( $analysis ) || empty( $analysis ) ) {
            return array();
        }

        $scores   = array();

        foreach ( $analysis as $r ) {
            if ( $r['is_control'] ) {
                continue;
            }

            $cr_score        = ( $r['lift'] + 100 ) / 200; // Normalize -100%..inf to 0..1.
            $revenue_score   = min( 1.0, $r['revenue'] / max( 1, $analysis[0]['revenue'] ) );
            $significance    = $r['prob_better'] / 100.0;

            $composite = ( $cr_score * $weights['cr_weight'] )
                       + ( $revenue_score * $weights['revenue_weight'] )
                       + ( $significance * $weights['significance_weight'] );

            $scores[ $r['variant_key'] ] = array(
                'score'      => round( $composite * 100, 2 ),
                'components' => array(
                    'cr_lift'     => round( $cr_score * 100, 2 ),
                    'revenue'     => round( $revenue_score * 100, 2 ),
                    'significance' => round( $significance * 100, 2 ),
                ),
            );
        }

        return $scores;
    }

    /**
     * Compare two experiments side-by-side (useful for A/A validation or sequential tests).
     *
     * @param int $exp_a_id
     * @param int $exp_b_id
     *
     * @return array
     */
    public function compare_experiments( int $exp_a_id, int $exp_b_id ): array {
        $a = $this->export_experiment( $exp_a_id );
        $b = $this->export_experiment( $exp_b_id );

        $impressions_a = array_sum( array_column( $a['variants'] ?? array(), 'impressions' ) );
        $impressions_b = array_sum( array_column( $b['variants'] ?? array(), 'impressions' ) );

        return array(
            'experiment_a' => $a,
            'experiment_b' => $b,
            'differences'  => array(
                'impressions_diff'  => absint( $impressions_a - $impressions_b ),
            ),
        );
    }

    /**
     * Get an experiment by its name.
     *
     * @param string $name
     *
     * @return array|null
     */
    public function get_experiment_by_name( string $name ): ?array {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table_experiments} WHERE name = %s",
            $name
        ) );
        if ( ! $id ) {
            return null;
        }
        return $this->get_experiment( (int) $id );
    }

    /**
     * Check if an experiment is currently running.
     *
     * @param int $experiment_id
     *
     * @return bool
     */
    public function is_running( int $experiment_id ): bool {
        $exp = $this->get_experiment( $experiment_id );
        return $exp && self::STATUS_ACTIVE === $exp['status'];
    }

    /**
     * Get runtime in days for an experiment.
     *
     * @param int $experiment_id
     *
     * @return float
     */
    public function get_runtime_days( int $experiment_id ): float {
        $exp = $this->get_experiment( $experiment_id );
        if ( ! $exp ) {
            return 0.0;
        }
        $start = strtotime( $exp['created_at'] );
        $end   = ! empty( $exp['ended_at'] ) ? strtotime( $exp['ended_at'] ) : time();
        return round( ( $end - $start ) / DAY_IN_SECONDS, 2 );
    }

    /**
     * Update the traffic percentage for an experiment.
     *
     * @param int $experiment_id
     * @param int $traffic_pct
     */
    public function update_traffic_pct( int $experiment_id, int $traffic_pct ) {
        global $wpdb;
        $wpdb->update(
            $this->table_experiments,
            array( 'traffic_pct' => min( 100, max( 1, $traffic_pct ) ) ),
            array( 'id' => $experiment_id ),
            array( '%d' ),
            array( '%d' )
        );
        $this->log_db_error( 'update_traffic_pct' );
    }

    /**
     * Pause all active experiments.
     *
     * @return int Number of experiments paused.
     */
    public function pause_all(): int {
        global $wpdb;
        $count = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_experiments} SET status = %s WHERE status = %s",
            self::STATUS_PAUSED,
            self::STATUS_ACTIVE
        ) );
        $this->log_db_error( 'pause_all' );
        return (int) $count;
    }

    /**
     * Clean up events older than a given number of days.
     *
     * @param int $days Max age in days.
     *
     * @return int Number of events deleted.
     */
    public function cleanup_old_events( int $days = 90 ): int {
        global $wpdb;
        $count = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table_events} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        $this->log_db_error( 'cleanup_old_events' );
        return (int) $count;
    }

    /**
     * Get top-performing variants across all concluded/winner experiments.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_top_performers( int $limit = 10 ): array {
        global $wpdb;
        $limit = min( 100, max( 1, $limit ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.*, e.name as experiment_name
             FROM {$this->table_variants} v
             INNER JOIN {$this->table_experiments} e ON e.id = v.experiment_id
             WHERE v.winner_flag = 1 AND e.status = 'winner'
             ORDER BY (SELECT COUNT(*) FROM {$this->table_events} ev WHERE ev.variant_id = v.id AND ev.event_type = 'conversion') DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Get experiments in a date range.
     *
     * @param string $from Start date (Y-m-d).
     * @param string $to   End date (Y-m-d).
     *
     * @return array
     */
    public function get_experiments_by_date( string $from, string $to ): array {
        // Validate date format (Y-m-d) to prevent unnecessary DB load from arbitrary strings.
        $date_regex = '/^\\d{4}-\\d{2}-\\d{2}$/';
        if ( ! preg_match( $date_regex, $from ) || ! preg_match( $date_regex, $to ) ) {
            return array();
        }
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM {$this->table_variants} v WHERE v.experiment_id = e.id) as variant_count
             FROM {$this->table_experiments} e
             WHERE DATE(e.created_at) BETWEEN %s AND %s
             ORDER BY e.created_at DESC",
            $from,
            $to
        ), ARRAY_A );
    }

    /**
     * Update the description of an experiment.
     *
     * @param int    $experiment_id
     * @param string $description
     */
    public function update_description( int $experiment_id, string $description ) {
        global $wpdb;
        $safe_description = mb_substr( sanitize_textarea_field( $description ), 0, 10000 );
        $wpdb->update(
            $this->table_experiments,
            array( 'description' => $safe_description ),
            array( 'id' => $experiment_id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->log_db_error( 'update_description' );
    }

    /**
     * Validate that a status transition is allowed.
     *
     * @param string $from Current status.
     * @param string $to   Desired status.
     *
     * @return bool
     */
    public function is_valid_transition( string $from, string $to ): bool {
        $allowed = array(
            self::STATUS_DRAFT     => array( self::STATUS_ACTIVE ),
            self::STATUS_ACTIVE    => array( self::STATUS_PAUSED, self::STATUS_WINNER, self::STATUS_CONCLUDED ),
            self::STATUS_PAUSED    => array( self::STATUS_ACTIVE, self::STATUS_CONCLUDED ),
            self::STATUS_WINNER    => array( self::STATUS_ARCHIVED ),
            self::STATUS_CONCLUDED => array( self::STATUS_ARCHIVED ),
            self::STATUS_ARCHIVED  => array(),
        );
        return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
    }
}
