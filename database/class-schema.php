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
    const DB_VERSION = '0.1.0';

    /**
     * Create all plugin tables.
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

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
            INDEX idx_created (created_at)
        ) {$charset_collate};";

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
            INDEX idx_created (created_at)
        ) {$charset_collate};";

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
            INDEX idx_status (status)
        ) {$charset_collate};";

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
            INDEX idx_created (created_at)
        ) {$charset_collate};";

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
            INDEX idx_created (created_at)
        ) {$charset_collate};";

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
            INDEX idx_score (score DESC)
        ) {$charset_collate};";

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
        ) {$charset_collate};";

        // ─── Execute all CREATE TABLE queries ─────────────────
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_logs );
        dbDelta( $sql_experiments );
        dbDelta( $sql_variants );
        dbDelta( $sql_events );
        dbDelta( $sql_beacon );
        dbDelta( $sql_suggestions );
        dbDelta( $sql_heal );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    /**
     * Drop all plugin tables (for uninstall).
     */
    public function drop_tables() {
        global $wpdb;

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
            $table_name = sanitize_key( $wpdb->prefix . $table );
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
        }

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
            'wac_logs',
            'wac_ab_experiments',
            'wac_ab_variants',
            'wac_ab_events',
            'wac_beacon_events',
            'wac_suggestions',
            'wac_heal_log',
        );

        $info = array();
        foreach ( $tables as $table ) {
            $full_name = $wpdb->prefix . $table;
            $exists    = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $full_name
            ) );

            if ( $exists ) {
                $safe_name = sanitize_key( $full_name );
                $info[ $table ] = array(
                    'exists' => true,
                    'rows'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_name}`" ),
                );
            } else {
                $info[ $table ] = array(
                    'exists' => false,
                    'rows'   => 0,
                );
            }
        }

        return $info;
    }
}
