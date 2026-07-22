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

// Define plugin constants.
define( 'WAC_VERSION', '0.1.0-alpha' );
define( 'WAC_FILE', __FILE__ );
define( 'WAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WAC_URL', plugin_dir_url( __FILE__ ) );
define( 'WAC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * @param string $class Class name being loaded.
 */
function wac_autoload( $class ) {
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
        'Logger'            => 'includes/class-logger.php',
        'Schema'            => 'database/class-schema.php',
        'AdminUI'           => 'admin/class-admin-ui.php',
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
        require_once WAC_PATH . $file_map[ $relative_class ];
        return;
    }

    // Walk nested namespace segments.
    $segments = explode( '\\', $relative_class );
    $depth    = count( $segments );

    if ( $depth >= 2 ) {
        $ns_root = $segments[0] . '\\' . $segments[1] . '\\';
        if ( isset( $mappings[ $ns_root ] ) ) {
            $dir_parts = array_slice( $segments, 2 );
            $filename  = 'class-' . strtolower( implode( '-', $dir_parts ) ) . '.php';
            $path      = WAC_PATH . $mappings[ $ns_root ] . $filename;
            if ( file_exists( $path ) ) {
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
function wac_init() {
    // Check WooCommerce dependency.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e( 'Woo Agentic Checkout requires WooCommerce to be installed and activated.', 'woo-agentic-checkout' );
            echo '</p></div>';
        } );
        return;
    }

    // Instantiate core.
    $plugin = \WooAgenticCheckout\Core::get_instance();
    $plugin->init();

    // Start background agent cron if not already scheduled.
    if ( ! wp_next_scheduled( 'wac_agent_tick' ) ) {
        wp_schedule_event( time(), 'hourly', 'wac_agent_tick' );
    }
}

add_action( 'plugins_loaded', 'wac_init' );

/**
 * Deactivation cleanup.
 */
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wac_agent_tick' );
    wp_clear_scheduled_hook( 'wac_daily_agent_run' );
    wp_clear_scheduled_hook( 'wac_weekly_suggestion_run' );
} );
