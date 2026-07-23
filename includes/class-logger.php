<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Logger — structured event logging for agent runs, errors, and healing actions.
 *
 * @since 0.1.0-alpha
 */
class Logger {

    /**
     * Log levels.
     */
    const INFO     = 'info';
    const WARNING  = 'warning';
    const ERROR    = 'error';
    const DEBUG    = 'debug';

    /**
     * Log an info-level event.
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function info( string $event, $context = array() ): void {
        $this->log( self::INFO, $event, $context );
    }

    /**
     * Log a warning-level event.
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function warning( string $event, $context = array() ): void {
        $this->log( self::WARNING, $event, $context );
    }

    /**
     * Log an error-level event.
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function error( string $event, $context = array() ): void {
        $this->log( self::ERROR, $event, $context );
    }

    /**
     * Log a debug-level event (only when debug mode is on).
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function debug( string $event, $context = array() ): void {
        if ( 'yes' === get_option( 'wac_debug_mode', 'no' ) ) {
            $this->log( self::DEBUG, $event, $context );
        }
    }

    /**
     * Write a log entry to database.
     *
     * @param string $level   Log level.
     * @param string $event   Event name.
     * @param mixed  $context Context (will be JSON-encoded).
     */
    private function log( string $level, string $event, $context ): void {
        global $wpdb;

        // Validate event name length (DB column is VARCHAR(255)).
        if ( strlen( $event ) > 100 ) {
            $event = substr( $event, 0, 100 );
        }

        $valid_levels = array( 'info', 'warning', 'error', 'debug' );
        if ( ! in_array( $level, $valid_levels, true ) ) {
            $level = 'info';
        }

        // Cap context size to prevent log table bloat.
        if ( is_array( $context ) ) {
            // Flatten deeply nested arrays to prevent stack overflow during JSON encoding.
            $context = self::limit_array_depth( $context, 5 );
        }
        $context_data = is_string( $context ) ? $context : wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( false === $context_data ) {
            $context_data = '{}'; // Fallback if JSON encoding fails.
        }
        if ( is_string( $context_data ) && strlen( $context_data ) > 10000 ) {
            $context_data = mb_substr( $context_data, 0, 10000, 'UTF-8' );
        }

        $data = array(
            'level'      => $level,
            'event'      => $event,
            'context'    => $context_data,
            'created_at' => current_time( 'mysql' ),
        );

        $wpdb->insert(
            $wpdb->prefix . 'wac_logs',
            $data,
            array( '%s', '%s', '%s', '%s' )
        );

        // Also trigger action for real-time monitoring.
        do_action( 'wac_log', $level, $event, $context );

        // If WP_DEBUG is on, also error_log for CLI debugging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            error_log( "[WAC][{$level}] {$event}: " . wp_json_encode( $context ) );
        }
    }

    /**
     * Get log entries.
     *
     * @param array $args {
     *     Query arguments.
     *
     *     @type string $level     Filter by level.
     *     @type string $event     Filter by event name.
     *     @type int    $limit     Max results.
     *     @type int    $offset    Result offset.
     *     @type string $order     ASC or DESC.
     * }
     *
     * @return array
     */
    public function get_logs( array $args = array() ): array {
        global $wpdb;

        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['level'] ) ) {
            $valid_levels = array( 'info', 'warning', 'error', 'debug' );
            if ( ! in_array( $args['level'], $valid_levels, true ) ) {
                $args['level'] = 'info';
            }
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }

        if ( ! empty( $args['event'] ) ) {
            $where[] = 'event = %s';
            $params[] = sanitize_key( $args['event'] );
        }

        $limit  = min( 500, max( 1, absint( $args['limit'] ?? 100 ) ) );
        $offset = absint( max( 0, $args['offset'] ?? 0 ) );
        $order  = 'DESC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'DESC' : 'ASC';

        // Use keyset (cursor-based) pagination when an id_after filter is provided.
        $after = isset( $args['id_after'] ) ? max( 0, (int) $args['id_after'] ) : 0;
        if ( $after > 0 ) {
            $where[] = 'id ' . ( 'ASC' === $order ? '>' : '<' ) . ' %d';
            $params[] = $after;
        }

        $sql = "SELECT * FROM {$wpdb->prefix}wac_logs
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY id {$order}
                LIMIT %d
                OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        // Strict type casting for numeric fields.
        foreach ( $rows as &$row ) {
            if ( isset( $row["id"] ) ) {
                $row["id"] = (int) $row["id"];
            }
        }
        unset( $row );

        return $rows;
    }

    /**
     * Get the last run time for a specific agent.
     *
     * @param string $agent_key Agent identifier.
     *
     * @return string|null MySQL datetime or null.
     */
    public function get_last_run( string $agent_key ): ?string {
        global $wpdb;

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}wac_logs
             WHERE event = 'agent_run'
             AND context LIKE %s
             ORDER BY id DESC LIMIT 1",
            '%' . $wpdb->esc_like( $agent_key ) . '%'
        ) );
        return false !== $result ? $result : null;
    }

    /**
     * Limit array depth to prevent stack overflow during JSON encoding.
     * Replaces nested values beyond $max_depth with a marker string.
     *
     * @param array $array     Input array.
     * @param int   $max_depth Maximum allowed depth.
     * @param int   $depth     Current recursion depth.
     * @return array
     */
    private static function limit_array_depth( array $array, int $max_depth, int $depth = 0 ): array {
        if ( $depth >= $max_depth ) {
            return array( '__truncated__' => true );
        }
        $result = array();
        foreach ( $array as $key => $value ) {
            if ( is_array( $value ) ) {
                $result[ $key ] = self::limit_array_depth( $value, $max_depth, $depth + 1 );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }

    /**
     * Purge old logs.
     *
     * @param int $days Keep logs newer than this many days.
     *
     * @return int Number of deleted rows.
     */
    public function purge_old_logs( int $days = 30 ): int {
        global $wpdb;

        // Clamp days to safe range (1–365) to prevent accidental mass deletion.
        $days      = max( 1, min( 365, $days ) );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $table     = $wpdb->prefix . 'wac_logs';

        // Chunked delete: 5000 rows per iteration to avoid long table locks.
        $total = 0;
        do {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000",
                $threshold
            ) );
            $total += $deleted;
        } while ( $deleted > 0 );

        return $total;
    }
}
