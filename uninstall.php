<?php
/**
 * Woo Agentic Checkout — Uninstall
 *
 * Removes all plugin data when the plugin is deleted from WordPress.
 *
 * @package WooAgenticCheckout
 * @version 0.1.0-alpha
 */

// Exit if called outside WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verify the current user has plugin activation capability.
if ( ! current_user_can( 'activate_plugins' ) ) {
    exit;
}

// Clean up options.
$options = array(
    'wac_llm_provider',
    'wac_llm_api_key',
    'wac_llm_model',
    'wac_llm_ollama_url',
    'wac_llm_ollama_model',
    'wac_ga4_measurement_id',
    'wac_ga4_api_secret',
    'wac_ga4_property_id',
    'wac_ga4_credentials_json',
    'wac_signal_collection_enabled',
    'wac_agent_conversion_analyzer_enabled',
    'wac_agent_ab_optimizer_enabled',
    'wac_agent_error_detector_enabled',
    'wac_agent_suggestion_generator_enabled',
    'wac_agent_self_healing_enabled',
    'wac_heal_permission_level',
    'wac_ab_min_sample_size',
    'wac_ab_min_conversions',
    'wac_ab_confidence_threshold',
    'wac_ab_max_concurrent',
    'wac_auto_suggest_enabled',
    'wac_debug_mode',
    'wac_db_version',
    'wac_css_patches',
    'wac_js_patches',
    'wac_field_order',
    'wac_removed_fields',
    'wac_setting_rollbacks',
    'wac_agent_failure_counts',
    'wac_llm_calls_hourly',
    'wac_slack_webhook',
    'wac_notify_email',
    'wac_notify_email_enabled',
    'wac_notify_slack_enabled',
    'wac_template_path_checkout',
    'wac_template_path_thankyou',
    'wac_tracked_events',
    'wac_schema_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
    // Also clean up multisite network options.
    if ( is_multisite() ) {
        delete_site_option( $option );
    }
}

global $wpdb;

// Drop custom tables via Schema class for consistent table management.
if ( class_exists( '\WooAgenticCheckout\Schema' ) ) {
    $schema = new \WooAgenticCheckout\Schema();
    $schema->drop_tables();
} else {
    // Fallback: direct table drops if Schema unavailable.
    $tables = array(
        'wac_logs', 'wac_ab_experiments', 'wac_ab_variants',
        'wac_ab_events', 'wac_beacon_events', 'wac_suggestions', 'wac_heal_log',
    );
    foreach ( $tables as $table ) {
        $prefix   = sanitize_key( $wpdb->prefix );
        $table_name = sanitize_key( $prefix . $table );
        $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore
    }
}

// Clear cron hooks.
wp_clear_scheduled_hook( 'wac_agent_tick' );
wp_clear_scheduled_hook( 'wac_daily_agent_run' );
wp_clear_scheduled_hook( 'wac_weekly_suggestion_run' );

// Delete plugin transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wac_%' OR option_name LIKE '_transient_timeout_wac_%'" ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Clean up user meta for all users.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( 'wac_beacon_session', 'wac_ab_variants', 'wac_ignored_notice' )" ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Flush any remaining cache.
wp_cache_flush();
wp_clear_scheduled_hook( 'wac_hourly_cleanup' );

// Clear any pending cache.
wp_cache_flush();
