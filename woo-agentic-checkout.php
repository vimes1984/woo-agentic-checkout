<?php
/**
 * Plugin Name:     Woo Agentic Checkout
 * Plugin URI:      https://github.com/vimes1984/woo-agentic-checkout
 * Description:     LLM-powered agentic checkout optimisation. Self-healing, auto A/B testing,
 *                  signal-driven improvements via GA4 & sales data.
 * Version:         0.1.0-alpha
 * Author:          Kevin the Minion 🍌
 * Author URI:      https://github.com/vimes1984
 * License:         GPL-3.0+
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     woo-agentic-checkout
 * Domain Path:     /languages
 * Requires PHP:    8.0
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package WooAgenticCheckout
 * @version 0.1.0-alpha
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants — guard against redefinition if loaded multiple times.
defined( 'WAC_VERSION' ) || define( 'WAC_VERSION', '0.1.0-alpha' );
defined( 'WAC_FILE' )    || define( 'WAC_FILE', __FILE__ );
defined( 'WAC_PATH' )    || define( 'WAC_PATH', plugin_dir_path( __FILE__ ) );
defined( 'WAC_URL' )     || define( 'WAC_URL', plugin_dir_url( __FILE__ ) );
defined( 'WAC_BASENAME' )|| define( 'WAC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * @param string $class Class name being loaded.
 */
function wac_autoload( string $class ): void {
    static $autoloading = false;
    if ( $autoloading ) {
        return; // Prevent recursion if require_once triggers another autoload.
    }
    $autoloading = true;
    try {
        wac_autoload_execute( $class );
    } finally {
        $autoloading = false;
    }
}

/**
 * Internal autoload logic — separated so recursion guard can use try/finally.
 *
 * @param string $class Class name being loaded.
 */
function wac_autoload_execute( string $class ): void {
    $prefix = 'WooAgenticCheckout\\';
    $len    = strlen( $prefix );

    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file_map = array(
        'Core'              => 'includes/class-core.php',
        'AgentManager'       => 'includes/class-agent-manager.php',
        'LLMClient'          => 'includes/class-llm-client.php',
        'ABTestManager'     => 'includes/class-ab-test-manager.php',
        'SignalCollector'   => 'includes/class-signal-collector.php',
        'SelfHealer'        => 'includes/class-self-healer.php',
        'SuggestionEngine'  => 'includes/class-suggestion-engine.php',
        'Settings'          => 'includes/class-settings.php',
        'ErrorHandler'      => 'includes/class-error-handler.php',
        'Notifier'          => 'includes/class-notifier.php',
        'Logger'            => 'includes/class-logger.php',
        'Schema'            => 'database/class-schema.php',
        'AdminUI'           => 'admin/class-admin-ui.php',
        'AdminHandlers'     => 'admin/class-admin-handlers.php',
        'CheckoutModifier'  => 'public/class-checkout-modifier.php',
        'Beacon'            => 'public/class-beacon.php',
        // Agents
        'ConversionAnalyzer' => 'agents/class-conversion-analyzer.php',
        'ABOptimizer'        => 'agents/class-ab-optimizer.php',
        'ErrorDetector'      => 'agents/class-error-detector.php',
        'SuggestionGenerator' => 'agents/class-suggestion-generator.php',
        'SelfHealingAgent'   => 'agents/class-self-healing-agent.php',
    );

    // Namespace-to-file mapping.
    $mappings = array(
        'Admin\\Views\\'       => 'admin/views/',
    );

    // Check flat class names.
    if ( isset( $file_map[ $relative_class ] ) ) {
        $file_path = WAC_PATH . $file_map[ $relative_class ];
        // Guard against LFI: verify the resolved path is within the plugin directory.
        $real_base = realpath( WAC_PATH );
        $real_file = realpath( $file_path );
        if ( false !== $real_file && false !== $real_base && str_starts_with( $real_file, $real_base ) && file_exists( $file_path ) ) {
            require_once $file_path;
        }
        return;
    }

    // Walk nested namespace segments.
    $segments = explode( '\\', $relative_class );
    $depth    = count( $segments );

    if ( $depth >= 2 ) {
        $ns_root = $segments[0] . '\\' . $segments[1] . '\\';
        if ( isset( $mappings[ $ns_root ] ) ) {
            $dir_parts = array_slice( $segments, 2 );
            // Sanitize each segment to prevent path traversal via crafted namespace.
            $safe_parts = array();
            foreach ( $dir_parts as $part ) {
                // Only allow alphanumeric and hyphens in namespace segments.
                $sanitized = preg_replace( '/[^a-zA-Z0-9\-]/', '', $part );
                if ( '' === $sanitized ) {
                    return; // Invalid segment, bail.
                }
                $safe_parts[] = $sanitized;
            }
            $filename  = 'class-' . strtolower( implode( '-', $safe_parts ) ) . '.php';
            $path      = WAC_PATH . $mappings[ $ns_root ] . $filename;
            // Verify resolved path stays within plugin directory to prevent LFI.
            $real_base = realpath( WAC_PATH );
            $real_file = realpath( $path );
            if ( false !== $real_file && false !== $real_base && str_starts_with( $real_file, $real_base ) && file_exists( $path ) ) {
                require_once $path;
                return;
            }
        }
    }
}

spl_autoload_register( 'wac_autoload' );

/**
 * Main plugin bootstrap.
 */
function wac_init(): void {
    // Load plugin text domain for i18n.
    load_plugin_textdomain( 'woo-agentic-checkout', false, dirname( WAC_BASENAME ) . '/languages' );

    // Check WordPress version.
    global $wp_version;
    if ( version_compare( $wp_version, '6.0', '<' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(
                sprintf(
                    /* translators: 1: current WP version, 2: minimum required version */
                    __( 'Woo Agentic Checkout requires WordPress %2$s or later. Current version: %1$s', 'woo-agentic-checkout' ),
                    $GLOBALS['wp_version'],
                    '6.0'
                )
            );
            echo '</p></div>';
        } );
        return;
    }

    // Check WooCommerce dependency — deactivate gracefully if missing.
    if ( ! class_exists( 'WooCommerce' ) ) {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( WAC_BASENAME, true );
        add_action( 'admin_notices', function () {
            unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Woo Agentic Checkout requires WooCommerce to be installed and activated. Plugin has been deactivated.', 'woo-agentic-checkout' );
            echo '</p></div>';
        } );
        return;
    }

    // Instantiate core.
    $plugin = \WooAgenticCheckout\Core::get_instance();
    $plugin->init();

    // Start background agent cron if not already scheduled.
    if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wac_agent_tick' ) ) {
        wp_schedule_event( time(), 'hourly', 'wac_agent_tick' );
    }
}

add_action( 'plugins_loaded', 'wac_init' );

/**
 * Register the error handler early (fires on plugins_loaded, priority 1).
 */
function wac_register_error_handler(): void {
    \WooAgenticCheckout\ErrorHandler::register();
}
add_action( 'plugins_loaded', 'wac_register_error_handler', 1 );

/**
 * Uninstall cleanup -- drop all plugin tables and remove options.
 */
register_uninstall_hook( __FILE__, 'wac_uninstall' );
function wac_uninstall(): void {
    if ( class_exists( 'WooAgenticCheckout\\Schema' ) ) {
        $schema = new \WooAgenticCheckout\Schema();
        $schema->drop_tables();
    }

    // Remove all plugin options.
    delete_option( 'wac_db_version' );
    delete_option( 'wac_settings' );
    delete_option( 'wac_llm_calls_hourly' );

    // Remove network-only options if this is multisite.
    if ( is_multisite() ) {
        delete_site_option( 'wac_db_version' );
        delete_site_option( 'wac_settings' );
    }

    // Clear all scheduled cron events.
    wp_clear_scheduled_hook( 'wac_agent_tick' );
    wp_clear_scheduled_hook( 'wac_daily_agent_run' );
    wp_clear_scheduled_hook( 'wac_weekly_suggestion_run' );
}

/**
 * Deactivation cleanup.
 */
register_deactivation_hook( __FILE__, function () {
    // Clear all scheduled cron events.
    wp_clear_scheduled_hook( 'wac_agent_tick' );
    wp_clear_scheduled_hook( 'wac_daily_agent_run' );
    wp_clear_scheduled_hook( 'wac_weekly_suggestion_run' );

    // Unregister the error handler.
    if ( class_exists( 'WooAgenticCheckout\\ErrorHandler' ) ) {
        \WooAgenticCheckout\ErrorHandler::unregister();
    }
} );
