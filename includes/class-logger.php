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
    public function info( string $event, $context = array() ) {
        $this->log( self::INFO, $event, $context );
    }

    /**
     * Log a warning-level event.
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function warning( string $event, $context = array() ) {
        $this->log( self::WARNING, $event, $context );
    }

    /**
     * Log an error-level event.
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function error( string $event, $context = array() ) {
        $this->log( self::ERROR, $event, $context );
    }

    /**
     * Log a debug-level event (only when debug mode is on).
     *
     * @param string $event Event name.
     * @param mixed  $context Associated data.
     */
    public function debug( string $event, $context = array() ) {
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
    private function log( string $level, string $event, $context ) {
        global $wpdb;

        $data = array(
            'level'      => $level,
            'event'      => $event,
            'context'    => is_string( $context ) ? $context : wp_json_encode( $context ),
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
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }

        if ( ! empty( $args['event'] ) ) {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }

        $limit  = min( 500, max( 1, $args['limit'] ?? 100 ) );
        $offset = max( 0, $args['offset'] ?? 0 );
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
                LIMIT %d";

        $params[] = $limit;

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

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}wac_logs
             WHERE event = 'agent_run'
             AND context LIKE %s
             ORDER BY id DESC LIMIT 1",
            '%' . $wpdb->esc_like( $agent_key ) . '%'
        ) );
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
