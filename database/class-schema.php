<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Schema — manages plugin database table creation / migration.
 *
 * @since 0.1.0-alpha
 */
class Schema {

    /**
     * Database version option key.
     */
    const DB_VERSION_KEY = 'wac_db_version';

    /**
     * Current schema version.
     */
    const DB_VERSION = '0.2.0';

    /**
     * Create all plugin tables only when schema version is outdated.
     */
    public function create_tables() {
        // Skip if the database is already at the current schema version.
        $installed_version = get_option( self::DB_VERSION_KEY, '' );
        // Safely compare versions: ensure string type for PHP 8.0+ strict typing.
        if ( ! is_string( $installed_version ) ) {
            $installed_version = '';
        }
        // Trim whitespace and limit version string length to prevent processing issues.
        $installed_version = substr( trim( $installed_version ), 0, 50 );
        if ( version_compare( $installed_version, self::DB_VERSION, '>=' ) ) {
            return;
        }

        global $wpdb;

        $wpdb->suppress_errors( true );

        $charset_collate = $wpdb->get_charset_collate();
        // Ensure utf8mb4 is used for full Unicode support and emoji compatibility.
        if ( false === strpos( $charset_collate, 'utf8mb4' ) ) {
            $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        // ─── Logs ──────────────────────────────────────────────
        $table_logs = $wpdb->prefix . 'wac_logs';
        $sql_logs   = "CREATE TABLE IF NOT EXISTS {$table_logs} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            event VARCHAR(100) NOT NULL,
            context LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_level (level),
            INDEX idx_event (event),
            INDEX idx_created (created_at),
            INDEX idx_level_created (level, created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── A/B Experiments ──────────────────────────────────
        $table_experiments = $wpdb->prefix . 'wac_ab_experiments';
        $sql_experiments   = "CREATE TABLE IF NOT EXISTS {$table_experiments} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            traffic_pct TINYINT UNSIGNED NOT NULL DEFAULT 50,
            control_key VARCHAR(100) DEFAULT NULL,
            winner_key VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at),
            INDEX idx_name_status (name(50), status)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── A/B Variants ─────────────────────────────────────
        $table_variants = $wpdb->prefix . 'wac_ab_variants';
        $sql_variants   = "CREATE TABLE IF NOT EXISTS {$table_variants} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            experiment_id BIGINT UNSIGNED NOT NULL,
            variant_key VARCHAR(100) NOT NULL,
            variant_name VARCHAR(255) NOT NULL,
            is_control TINYINT(1) NOT NULL DEFAULT 0,
            traffic_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
            config_snapshot JSON,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            winner_flag TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_experiment (experiment_id),
            INDEX idx_status (status),
            INDEX idx_experiment_status (experiment_id, status)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── A/B Events ───────────────────────────────────────
        $table_events = $wpdb->prefix . 'wac_ab_events';
        $sql_events   = "CREATE TABLE IF NOT EXISTS {$table_events} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            variant_id BIGINT UNSIGNED NOT NULL,
            experiment_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_data JSON,
            session_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_variant (variant_id),
            INDEX idx_experiment (experiment_id),
            INDEX idx_type (event_type),
            INDEX idx_session (session_id),
            INDEX idx_created (created_at),
            INDEX idx_session_event (session_id, event_type),
            INDEX idx_session_created (session_id, created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── Beacon Events (checkout telemetry) ──────────────
        $table_beacon = $wpdb->prefix . 'wac_beacon_events';
        $sql_beacon   = "CREATE TABLE IF NOT EXISTS {$table_beacon} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            event VARCHAR(100) NOT NULL,
            event_data JSON,
            page_url TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_event (event),
            INDEX idx_created (created_at),
            INDEX idx_beacon_session_event (session_id, event(50)),
            INDEX idx_event_created (event, created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── Suggestions ──────────────────────────────────────
        $table_suggestions = $wpdb->prefix . 'wac_suggestions';
        $sql_suggestions   = "CREATE TABLE IF NOT EXISTS {$table_suggestions} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            action_type VARCHAR(50) NOT NULL,
            action_data JSON,
            score DECIMAL(4,3) NOT NULL DEFAULT 0.500,
            expected_lift VARCHAR(50) DEFAULT NULL,
            category VARCHAR(100) DEFAULT 'general',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reject_reason TEXT DEFAULT NULL,
            applied_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_category (category),
            INDEX idx_score (score DESC),
            INDEX idx_status_score (status, score DESC)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── Healing Log ──────────────────────────────────────
        $table_heal = $wpdb->prefix . 'wac_heal_log';
        $sql_heal   = "CREATE TABLE IF NOT EXISTS {$table_heal} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rollback_id VARCHAR(64) NOT NULL UNIQUE,
            issue_id VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            params JSON,
            rollback_data JSON,
            result TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_issue (issue_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        // ─── Execute all CREATE TABLE queries ─────────────────
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_logs );
        dbDelta( $sql_experiments );
        dbDelta( $sql_variants );
        dbDelta( $sql_events );
        dbDelta( $sql_beacon );
        dbDelta( $sql_suggestions );
        dbDelta( $sql_heal );

        $wpdb->suppress_errors( false );
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }


    /**
     * Schedule the daily log purge cron job (runs on plugin activation).
     */
    public function schedule_purge_cron() {
        if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wac_daily_log_purge' ) ) {
            wp_schedule_event( time(), 'daily', 'wac_daily_log_purge' );
        }
    }

    /**
     * Unschedule the daily log purge cron job (runs on plugin deactivation).
     */
    public function unschedule_purge_cron() {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        $timestamp = wp_next_scheduled( 'wac_daily_log_purge' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wac_daily_log_purge' );
        }
    }

    /**
     * Handler for wac_daily_log_purge cron hook.
     * Purges log entries older than 30 days in chunks to avoid long-running queries.
     */
    public static function handle_purge_cron() {
        global $wpdb;

        $table = $wpdb->prefix . 'wac_logs';
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

        // Chunked delete: 1000 rows at a time to avoid table locks on large datasets.
        // Cap total deletions per cron run to prevent excessive query time.
        $max_total = 50000;
        $total = 0;
        do {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000",
                $threshold
            ) );
            $total += $deleted;
        } while ( $deleted > 0 && $total < $max_total );

        // Also purge stale beacon events (> 90 days).
        $beacon_table = $wpdb->prefix . 'wac_beacon_events';
        $beacon_threshold = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
        $beacon_total = 0;
        do {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$beacon_table} WHERE created_at < %s LIMIT 1000",
                $beacon_threshold
            ) );
            $beacon_total += $deleted;
        } while ( $deleted > 0 && $beacon_total < $max_total );
    }

    /**
     * Drop all plugin tables (for uninstall).
     */
    public function drop_tables() {
        global $wpdb;

        $wpdb->suppress_errors( true );

        $tables = array(
            'wac_logs',
            'wac_ab_experiments',
            'wac_ab_variants',
            'wac_ab_events',
            'wac_beacon_events',
            'wac_suggestions',
            'wac_heal_log',
        );

        foreach ( $tables as $table ) {
            // Verify table_prefix is safe before constructing the drop query.
            $prefix = sanitize_key( $wpdb->prefix );
            $table_name = sanitize_key( $prefix . $table );
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        // Also drop events table if present.
        $events_table = sanitize_key( $prefix . 'wac_beacon_events' );
        $wpdb->query( "DROP TABLE IF EXISTS `{$events_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $wpdb->suppress_errors( false );
        delete_option( self::DB_VERSION_KEY );
    }

    /**
     * Get table status info.
     *
     * @return array
     */
    public function get_table_info(): array {
        global $wpdb;

        $tables = array(
            'wac_logs'         => $wpdb->prefix . 'wac_logs',
            'wac_ab_experiments' => $wpdb->prefix . 'wac_ab_experiments',
            'wac_ab_variants'    => $wpdb->prefix . 'wac_ab_variants',
            'wac_ab_events'      => $wpdb->prefix . 'wac_ab_events',
            'wac_beacon_events'  => $wpdb->prefix . 'wac_beacon_events',
            'wac_suggestions'    => $wpdb->prefix . 'wac_suggestions',
            'wac_heal_log'       => $wpdb->prefix . 'wac_heal_log',
        );

        $info = array();
        $full_names = array_values( $tables );

        // Single query: get all table stats at once.
        $placeholders = implode( ',', array_fill( 0, count( $full_names ), '%s' ) );
        $results      = $wpdb->get_results( $wpdb->prepare(
            "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE,
                    AUTO_INCREMENT, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders})",
            array_merge( array( DB_NAME ), $full_names )
        ), OBJECT_K );

        foreach ( $tables as $short => $full_name ) {
            if ( isset( $results[ $full_name ] ) ) {
                $t = $results[ $full_name ];
                $info[ $short ] = array(
                    'exists'          => true,
                    'rows'            => (int) $t->TABLE_ROWS,
                    'data_size'       => (int) $t->DATA_LENGTH,
                    'index_size'      => (int) $t->INDEX_LENGTH,
                    'total_size'      => (int) $t->DATA_LENGTH + (int) $t->INDEX_LENGTH,
                    'engine'          => $t->ENGINE,
                    'auto_increment'  => (int) $t->AUTO_INCREMENT,
                    'collation'       => $t->TABLE_COLLATION,
                );
            } else {
                $info[ $short ] = array(
                    'exists'          => false,
                    'rows'            => 0,
                    'data_size'       => 0,
                    'index_size'      => 0,
                    'total_size'      => 0,
                    'engine'          => null,
                    'auto_increment'  => 0,
                    'collation'       => null,
                );
            }
        }

        return $info;
    }
}
