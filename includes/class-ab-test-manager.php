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

    /**
     * Table name (with prefix).
     *
     * @var string
     */
    private $table_experiments;
    private $table_variants;
    private $table_events;

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

        $wpdb->insert(
            $this->table_experiments,
            array(
                'name'         => $name,
                'description'  => $description,
                'status'       => self::STATUS_ACTIVE,
                'traffic_pct'  => min( 100, max( 1, $traffic_pct ) ),
                'created_at'   => current_time( 'mysql' ),
                'control_key'  => $variants[0]['key'] ?? 'control',
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        $experiment_id = $wpdb->insert_id;

        foreach ( $variants as $i => $variant ) {
            $config = isset( $variant['config'] ) ? $variant['config'] : array();
            $wpdb->insert(
                $this->table_variants,
                array(
                    'experiment_id'    => $experiment_id,
                    'variant_key'      => $variant['key'],
                    'variant_name'     => $variant['name'],
                    'is_control'       => 0 === $i ? 1 : 0,
                    'traffic_percent'  => $variant['traffic_percent'] ?? floor( 100 / count( $variants ) ),
                    'config_snapshot'  => wp_json_encode( $config ),
                    'status'           => self::STATUS_ACTIVE,
                    'created_at'       => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
            );
        }

        return $experiment_id;
    }

    /**
     * Get active experiments.
     *
     * @return array
     */
    public function get_active_experiments(): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, 
                    (SELECT COUNT(*) FROM {$this->table_variants} v WHERE v.experiment_id = e.id) as variant_count
             FROM {$this->table_experiments} e
             WHERE e.status = %s
             ORDER BY e.created_at DESC",
            self::STATUS_ACTIVE
        ), ARRAY_A );
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

        $limit    = absint( $limit );
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
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.*,
                    (SELECT COUNT(*) FROM {$this->table_events} ev WHERE ev.variant_id = v.id AND ev.event_type = 'conversion') as conversions,
                    (SELECT COUNT(*) FROM {$this->table_events} ev WHERE ev.variant_id = v.id AND ev.event_type = 'impression') as impressions,
                    (SELECT COALESCE(SUM(CAST(ev.event_data->>'$.revenue' AS DECIMAL(10,2))), 0)
                     FROM {$this->table_events} ev WHERE ev.variant_id = v.id AND ev.event_type = 'conversion') as revenue
             FROM {$this->table_variants} v
             WHERE v.experiment_id = %d
             ORDER BY v.is_control DESC, v.id ASC",
            $experiment_id
        ), ARRAY_A );
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

        if ( $variant_key ) {
            foreach ( $variants as $v ) {
                if ( $v['variant_key'] === $variant_key ) {
                    $this->record_impression( $v['id'], $experiment['id'] );
                    return $v;
                }
            }
        }

        // Assign based on traffic_pct threshold and variant weighting.
        // Use multiple entropy sources to avoid bias when session/cookies are unavailable.
        $entropy  = $cookie_key . $this->get_session_id();
        $entropy .= $_SERVER['REMOTE_ADDR'] ?? '';
        $entropy .= $_SERVER['HTTP_USER_AGENT'] ?? '';
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
     * Record a conversion event for a variant.
     *
     * @param int    $variant_id
     * @param int    $experiment_id
     * @param float  $revenue
     * @param string $user_id
     */
    public function record_conversion( int $variant_id, int $experiment_id, float $revenue = 0.0, string $user_id = '' ) {
        global $wpdb;

        $wpdb->insert(
            $this->table_events,
            array(
                'variant_id'    => $variant_id,
                'experiment_id' => $experiment_id,
                'event_type'    => 'conversion',
                'event_data'    => wp_json_encode( array(
                    'revenue' => $revenue,
                    'user_id' => $user_id,
                ) ),
                'session_id'    => $this->get_session_id(),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Record an impression.
     *
     * @param int $variant_id
     * @param int $experiment_id
     */
    private function record_impression( int $variant_id, int $experiment_id ) {
        global $wpdb;

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
    }

    /**
     * Set A/B variant cookie.
     *
     * @param string $key
     * @param string $value
     */
    private function set_variant_cookie( string $key, string $value ) {
        if ( ! headers_sent() ) {
            setcookie( $key, $value, time() + DAY_IN_SECONDS * 30, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
    }

    /**
     * Declare a winner for an experiment.
     *
     * @param int    $experiment_id
     * @param string $variant_key
     */
    public function declare_winner( int $experiment_id, string $variant_key ) {
        global $wpdb;

        // Mark experiment as winner.
        $wpdb->update(
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

        // Mark winning variant.
        $wpdb->update(
            $this->table_variants,
            array( 'winner_flag' => 1 ),
            array(
                'experiment_id' => $experiment_id,
                'variant_key'   => $variant_key,
            ),
            array( '%d' ),
            array( '%d', '%s' )
        );

        // Mark all other variants as concluded.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_variants}
             SET status = 'concluded'
             WHERE experiment_id = %d AND variant_key != %s",
            $experiment_id,
            $variant_key
        ) );

        // Log the winner declaration.
        do_action( 'wac_ab_test_winner', $experiment_id, $variant_key );
    }

    /**
     * Compute simple Bayesian probability of each variant being best.
     * Winner is the variant with highest probability of being superior to control.
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

        $control_cr = $control['impressions'] > 0
            ? $control['conversions'] / $control['impressions']
            : 0;

        foreach ( $variants as $v ) {
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

            $results[] = array(
                'variant_key'   => $v['variant_key'],
                'variant_name'  => $v['variant_name'],
                'impressions'   => (int) $v['impressions'],
                'conversions'   => (int) $v['conversions'],
                'cr'            => round( $variant_cr * 100, 2 ),
                'control_cr'    => round( $control_cr * 100, 2 ),
                'lift'          => $control_cr > 0
                    ? round( ( ( $variant_cr - $control_cr ) / $control_cr ) * 100, 2 )
                    : 0,
                'prob_better'   => round( $prob_better * 100, 2 ),
                'revenue'       => (float) $v['revenue'],
            );
        }

        return $results;
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
        $samples  = 10000;
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
}
