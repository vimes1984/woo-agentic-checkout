<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin class — lifecycle, hook registration, and service wiring.
 *
 * @since 0.1.0-alpha
 */
class Core {

    /**
     * Singleton instance.
     *
     * @var self
     */
    private static $instance = null;

    /**
     * Registered service instances.
     *
     * @var array<string, object>
     */
    private $services = array();

    /**
     * @return self
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — use get_instance().
     */
    private function __construct() {}

    /**
     * Boot the plugin.
     */
    public function init() {
        $this->load_dependencies();
        $this->register_hooks();
        $this->init_services();
    }

    /**
     * Load all includes.
     */
    private function load_dependencies() {
        // Files are autoloaded — nothing else needed.
        // Schema is loaded explicitly for activation.
        require_once WAC_PATH . 'database/class-schema.php';
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks() {
        // Priority list to avoid conflict with 3rd-party plugins.
        $late  = 99;
        $early = 1;

        // Activation: create DB tables.
        register_activation_hook( WAC_FILE, array( $this, 'activate' ) );

        // Admin hooks.
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ), $late );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        // Public hooks.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

        // AJAX endpoints.
        add_action( 'wp_ajax_wac_beacon', array( $this, 'ajax_beacon' ) );
        add_action( 'wp_ajax_nopriv_wac_beacon', array( $this, 'ajax_beacon' ) );

        // Cron / agent hooks.
        add_action( 'wac_agent_tick', array( $this, 'agent_tick' ) );
        add_action( 'wac_daily_agent_run', array( $this, 'daily_agent_run' ) );
        add_action( 'wac_weekly_suggestion_run', array( $this, 'weekly_suggestion_run' ) );

        // REST API endpoints.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Checkout modification hooks.
        add_filter( 'woocommerce_checkout_fields', array( $this, 'maybe_modify_checkout_fields' ), 100 );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_inject_checkout_template' ), 10 );
        add_action( 'woocommerce_after_checkout_form', array( $this, 'inject_experiment_tracker' ), 10 );

        // Error monitoring.
        add_action( 'woocommerce_checkout_process', array( $this, 'capture_checkout_errors' ), 999 );

        // Settings link on plugins page.
        add_filter( 'plugin_action_links_' . WAC_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Initialize all service instances.
     */
    private function init_services() {
        $this->services['logger']    = new Logger();
        $this->services['settings']  = new Settings();
        $this->services['llm']       = new LLMClient( $this->services['settings'] );
        $this->services['signals']   = new SignalCollector();
        $this->services['ab']        = new ABTestManager();
        $this->services['healer']    = new SelfHealer();
        $this->services['suggest']   = new SuggestionEngine( $this->services['llm'] );
        $this->services['agents']    = new AgentManager(
            $this->services['llm'],
            $this->services['signals'],
            $this->services['ab'],
            $this->services['healer'],
            $this->services['suggest'],
            $this->services['settings'],
            $this->services['logger']
        );

        if ( is_admin() ) {
            $this->services['admin'] = new AdminUI(
                $this->services['agents'],
                $this->services['ab'],
                $this->services['settings'],
                $this->services['suggest']
            );
            $this->services['handlers'] = new \WooAgenticCheckout\Admin\AdminHandlers();
        }

        $this->services['modifier'] = new CheckoutModifier( $this->services['ab'] );
        $this->services['beacon']   = new Beacon();
    }

    /**
     * Activation: create/upgrade DB tables.
     */
    public function activate() {
        // Check WooCommerce dependency at activation time.
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( WAC_BASENAME, true );
            wp_die(
                esc_html__( 'Woo Agentic Checkout requires WooCommerce to be installed and activated.', 'woo-agentic-checkout' ),
                esc_html__( 'Plugin Activation Error', 'woo-agentic-checkout' ),
                array( 'back_link' => true )
            );
        }

        // Verify the user actually triggered activation (defense-in-depth).
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        $schema = new Schema();
        $schema->create_tables();

        // Schedule daily and weekly runs.
        if ( ! wp_next_scheduled( 'wac_daily_agent_run' ) ) {
            wp_schedule_event( time(), 'daily', 'wac_daily_agent_run' );
        }
        if ( ! wp_next_scheduled( 'wac_weekly_suggestion_run' ) ) {
            wp_schedule_event( time(), 'weekly', 'wac_weekly_suggestion_run' );
        }
    }

    /**
     * Register admin menu pages.
     */
    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Agentic Checkout', 'woo-agentic-checkout' ),
            __( 'Agentic Checkout 🍌', 'woo-agentic-checkout' ),
            'manage_woocommerce',
            'wac-dashboard',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Show admin notices for wac_msg query params.
     *
     * Notices are is-dismissible and rendered only on the wac-dashboard page.
     * These are PHP fallback notices — the JS toast system also interprets
     * the wac_msg param, so we use a suppression flag to avoid double-rendering
     * when JS already handled it.
     */
    public function admin_notices() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Check nonce for any GET action that may have triggered a notice.
        // We permit notices without nonce since they're read-only display.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['wac_msg'] ) || ! isset( $_GET['page'] ) || 'wac-dashboard' !== $_GET['page'] ) {
            return;
        }

        // Suppress PHP notices if the JS flag is present — JS will show toasts instead.
        if ( isset( $_GET['wac_js'] ) && '1' === $_GET['wac_js'] ) {
            return;
        }

        $msg    = sanitize_key( wp_unslash( $_GET['wac_msg'] ) );
        $agent  = isset( $_GET['wac_agent'] ) ? sanitize_key( wp_unslash( $_GET['wac_agent'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $notices = array(
            'success'              => array( 'type' => 'success', 'text' => $agent
                ? sprintf(
                    /* translators: %s: agent name */
                    __( "Agent '%s' ran successfully.", 'woo-agentic-checkout' ),
                    $agent
                )
                : __( 'Action completed successfully.', 'woo-agentic-checkout' )
            ),
            'error'                => array( 'type' => 'error',   'text' => $agent
                ? sprintf(
                    /* translators: %s: agent name */
                    __( "Agent '%s' encountered an error. Check the logs for details.", 'woo-agentic-checkout' ),
                    $agent
                )
                : __( 'Action failed. Please try again or check the logs.', 'woo-agentic-checkout' )
            ),
            'no_agent'             => array( 'type' => 'warning', 'text' => __( 'No agent selected.', 'woo-agentic-checkout' ) ),
            'applied'              => array( 'type' => 'success', 'text' => __( 'Suggestion applied successfully.', 'woo-agentic-checkout' ) ),
            'rejected'             => array( 'type' => 'info',    'text' => __( 'Suggestion rejected.', 'woo-agentic-checkout' ) ),
            'exp_placeholder'      => array( 'type' => 'info',    'text' => __( 'Experiment creation wizard coming in a future update!', 'woo-agentic-checkout' ) ),
            'service_unavailable'  => array( 'type' => 'error', 'text'  => __( 'Service unavailable. Please refresh and try again.', 'woo-agentic-checkout' ) ),
        );

        if ( isset( $notices[ $msg ] ) ) {
            $n = $notices[ $msg ];
            printf(
                '<div class="notice notice-%s is-dismissible wac-notice" data-wac-msg="%s"><p>%s</p></div>',
                esc_attr( $n['type'] ),
                esc_attr( $msg ),
                esc_html( $n['text'] )
            );

            // Output inline script to auto-dismiss the PHP notice after JS replaces it.
            ?>
            <script>
            (function() {
                var notice = document.querySelector('.wac-notice[data-wac-msg="<?php echo esc_js( $msg ); ?>"]');
                if (notice && typeof window.WACAdmin !== 'undefined') {
                    setTimeout(function() {
                        if (notice && notice.parentNode) {
                            notice.style.transition = 'opacity 0.3s';
                            notice.style.opacity = '0';
                            setTimeout(function() {
                                if (notice && notice.parentNode) {
                                    notice.parentNode.removeChild(notice);
                                }
                            }, 300);
                        }
                    }, 2000);
                }
            })();
            </script>
            <?php
        }
    }

    /**
     * Render admin page shell.
     *
     * Defense-in-depth: verify capability even though add_submenu_page
     * enforces manage_woocommerce at the menu-registration layer.
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die(
                esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-agentic-checkout' ),
                esc_html__( 'Access Denied', 'woo-agentic-checkout' ),
                array( 'response' => 403 )
            );
        }
        if ( isset( $this->services['admin'] ) ) {
            $this->services['admin']->render_page();
        }
    }

    /**
     * Enqueue admin CSS/JS.
     *
     * Uses filemtime for asset versioning to bust caches after updates
     * without requiring a plugin version bump.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! is_string( $hook ) || ! str_contains( $hook, 'wac-' ) ) {
            return;
        }

        // Only load assets for users who can access the admin page.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $css_file = WAC_PATH . 'admin/css/admin.css';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : WAC_VERSION;
        wp_enqueue_style( 'wac-admin', WAC_URL . 'admin/css/admin.css', array(), $css_ver );

        $js_file = WAC_PATH . 'admin/js/admin.js';
        $js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : WAC_VERSION;
        wp_enqueue_script( 'wac-admin', WAC_URL . 'admin/js/admin.js', array( 'jquery', 'wp-util' ), $js_ver, true );

        $ab_service     = $this->get_service( 'ab' );
        $active_tests   = $ab_service ? $ab_service->get_active_experiments() : array();
        $total_heals    = 0;
        $healer_service = $this->get_service( 'healer' );
        if ( $healer_service && method_exists( $healer_service, 'get_total_heals' ) ) {
            $total_heals = $healer_service->get_total_heals();
        }

        wp_localize_script( 'wac-admin', 'wacData', array(
            'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'wac_admin' ),
            'restUrl'             => rest_url( 'wac/v1' ),
            'version'             => WAC_VERSION,
            'activeExperiments'   => count( $active_tests ),
            'totalSelfHeals'      => (int) $total_heals,
            'pluginUrl'           => WAC_URL,
            'wacJsFlag'           => '1',
            'strings'             => array(
                'confirmPause'    => __( 'Pause this experiment? Visitors will see the control variant.', 'woo-agentic-checkout' ),
                'confirmResume'   => __( 'Resume this experiment?', 'woo-agentic-checkout' ),
                'confirmApply'    => __( 'Apply this suggestion to your checkout?', 'woo-agentic-checkout' ),
                'confirmReject'   => __( 'Reject this suggestion? It will be dismissed permanently.', 'woo-agentic-checkout' ),
                'rejectReason'    => __( 'Reason for rejection (optional):', 'woo-agentic-checkout' ),
                'loading'         => __( 'Loading…', 'woo-agentic-checkout' ),
                'applying'        => __( 'Applying…', 'woo-agentic-checkout' ),
                'rejecting'       => __( 'Rejecting…', 'woo-agentic-checkout' ),
                'errorGeneric'    => __( 'Something went wrong. Please try again.', 'woo-agentic-checkout' ),
                'errorNetwork'    => __( 'Network error. Please check your connection.', 'woo-agentic-checkout' ),
                'successNotice'   => __( 'Action completed successfully.', 'woo-agentic-checkout' ),
                'appliedNotice'   => __( 'Suggestion applied successfully!', 'woo-agentic-checkout' ),
                'rejectedNotice'  => __( 'Suggestion rejected.', 'woo-agentic-checkout' ),
                'errorNotice'     => __( 'Action failed. Please try again.', 'woo-agentic-checkout' ),
                'noAgentNotice'   => __( 'No agent selected.', 'woo-agentic-checkout' ),
                'expPlaceholder'  => __( 'Experiment creation wizard coming soon!', 'woo-agentic-checkout' ),
                'serviceUnavailable' => __( 'Service unavailable. Please refresh and try again.', 'woo-agentic-checkout' ),
                'agentSuccess'    => __( "Agent '%s' completed successfully.", 'woo-agentic-checkout' ),
                'agentError'      => __( "Agent '%s' encountered an error.", 'woo-agentic-checkout' ),
            ),
        ) );
    }

    /**
     * Enqueue public (checkout) assets.
     */
    public function enqueue_public_assets() {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_script( 'wac-beacon', WAC_URL . 'public/js/checkout-tracker.js', array( 'jquery' ), WAC_VERSION, true );
        wp_localize_script( 'wac-beacon', 'wacBeacon', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wac_beacon' ),
            'session' => md5( session_id() ?: uniqid( 'wac_', true ) ),
            'variants' => $this->get_active_variant_assignments(),
        ) );
    }

    // ─── Agent Ticks ──────────────────────────────────────────────

    /**
     * Allowed agent keys — single source of truth for cron runs.
     */
    const ALLOWED_AGENTS = array(
        'error_detector',
        'self_healing',
        'conversion_analyzer',
        'ab_optimizer',
        'suggestion_generator',
    );

    /**
     * Hourly tick — Error Detector + Self-Healer run.
     */
    public function agent_tick() {
        if ( ! isset( $this->services['agents'] ) ) {
            return;
        }
        $this->run_agent_list( array( 'error_detector', 'self_healing' ) );
    }

    /**
     * Daily run — Conversion Analyzer + AB Optimizer.
     */
    public function daily_agent_run() {
        if ( ! isset( $this->services['agents'] ) ) {
            return;
        }
        $this->run_agent_list( array( 'conversion_analyzer', 'ab_optimizer' ) );
    }

    /**
     * Weekly run — Suggestion Generator.
     */
    public function weekly_suggestion_run() {
        if ( ! isset( $this->services['agents'] ) ) {
            return;
        }
        $this->run_agent_list( array( 'suggestion_generator' ) );
    }

    /**
     * Run a list of agents with allowlist validation.
     * Ensures no arbitrary agent keys can be passed via cron or action hooks.
     *
     * @param array $agent_keys Agent keys to run.
     */
    private function run_agent_list( array $agent_keys ) {
        $valid = array_intersect( $agent_keys, self::ALLOWED_AGENTS );
        if ( ! empty( $valid ) ) {
            $this->services['agents']->run_agents( array_values( $valid ) );
        }
    }

    // ─── AJAX / REST ──────────────────────────────────────────────

    /**
     * Handle beacon AJAX (anonymous telemetry).
     */
    public function ajax_beacon() {
        check_ajax_referer( 'wac_beacon', 'nonce' );

        $event    = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
        $raw_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

        // Use mb_strlen for multi-byte safety on event session validation.
        if ( mb_strlen( $event ) > 100 ) {
            $event = mb_substr( $event, 0, 100 );
        }

        // Limit raw data to 10KB for storage safety. Sanitize the raw input.
        if ( is_string( $raw_data ) ) {
            $raw_data = sanitize_textarea_field( $raw_data );
            if ( mb_strlen( $raw_data ) > 10240 ) {
                $raw_data = mb_substr( $raw_data, 0, 10240 );
            }
        }

        $data    = is_string( $raw_data ) ? json_decode( $raw_data, true ) : array();
        $data    = is_array( $data ) ? $data : array();
        $session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';

        if ( mb_strlen( $session ) > 64 ) {
            $session = mb_substr( $session, 0, 64 );
        }

        do_action( 'wac_beacon_event', $event, $data, $session );

        wp_send_json_success();
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        register_rest_route( 'wac/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_status' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_woocommerce' );
            },
        ) );

        register_rest_route( 'wac/v1', '/suggestions', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_suggestions' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_woocommerce' );
            },
        ) );

        register_rest_route( 'wac/v1', '/suggestions/(?P<id>\d+)/apply', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_apply_suggestion' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_woocommerce' );
            },
        ) );
    }

    /**
     * GET /wac/v1/status
     */
    public function rest_status( \WP_REST_Request $request ) {
        return rest_ensure_response( array(
            'version'       => WAC_VERSION,
            'activeAgents'  => isset( $this->services['agents'] ) ? $this->services['agents']->get_status() : array(),
            'activeTests'   => isset( $this->services['ab'] ) ? $this->services['ab']->get_active_experiments() : array(),
            'conversion24h' => isset( $this->services['signals'] ) ? $this->services['signals']->get_recent_conversion_rate( DAY_IN_SECONDS ) : 0.0,
            'healCount'     => isset( $this->services['healer'] ) ? $this->services['healer']->get_total_heals() : 0,
            'pendingSuggestions' => isset( $this->services['suggest'] ) ? $this->services['suggest']->get_pending_count() : 0,
        ) );
    }

    /**
     * GET /wac/v1/suggestions
     */
    public function rest_suggestions() {
        if ( ! isset( $this->services['suggest'] ) ) {
            return new \WP_Error( 'service_unavailable', 'Suggestion engine not initialized.', array( 'status' => 503 ) );
        }
        $pending = $this->services['suggest']->get_pending();
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        return rest_ensure_response( $pending );
    }

    /**
     * POST /wac/v1/suggestions/{id}/apply
     */
    public function rest_apply_suggestion( \WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'id' ) );
        if ( $id < 1 ) {
            return new \WP_Error( 'invalid_id', __( 'Invalid suggestion ID.', 'woo-agentic-checkout' ), array( 'status' => 400 ) );
        }
        if ( ! isset( $this->services['suggest'] ) ) {
            return new \WP_Error( 'service_unavailable', __( 'Suggestion engine not initialized.', 'woo-agentic-checkout' ), array( 'status' => 503 ) );
        }
        $result = $this->services['suggest']->apply_suggestion( $id );
        if ( is_wp_error( $result ) ) {
            // Return sanitized error message to avoid leaking internal details.
            return rest_ensure_response( new \WP_Error(
                $result->get_error_code(),
                __( 'Failed to apply suggestion.', 'woo-agentic-checkout' ),
                array( 'status' => 500 )
            ) );
        }
        return rest_ensure_response( array( 'success' => true, 'message' => __( 'Suggestion applied.', 'woo-agentic-checkout' ) ) );
    }

    // ─── Checkout Modification ───────────────────────────────────

    /**
     * Get variant assignments for current session.
     *
     * @return array<string, string>
     */
    private function get_active_variant_assignments(): array {
        if ( ! isset( $this->services['ab'] ) ) {
            return array();
        }
        $variants = $this->services['ab']->get_session_variants();
        return is_array( $variants ) ? $variants : array();
    }

    /**
     * Modify checkout fields per active experiment variant.
     *
     * @param array $fields WooCommerce checkout fields.
     * @return array
     */
    public function maybe_modify_checkout_fields( $fields ) {
        if ( isset( $this->services['modifier'] ) ) {
            return $this->services['modifier']->modify_fields( $fields );
        }
        return $fields;
    }

    /**
     * Inject alternative checkout template if experiment variant requires it.
     */
    public function maybe_inject_checkout_template() {
        if ( isset( $this->services['modifier'] ) ) {
            $this->services['modifier']->maybe_override_template();
        }
    }

    /**
     * Inject A/B test experiment tracking data into checkout.
     */
    public function inject_experiment_tracker() {
        if ( isset( $this->services['beacon'] ) ) {
            $this->services['beacon']->inject_tracker();
        }
    }

    /**
     * Capture and log checkout processing errors.
     *
     * Sanitizes session-stored error data before logging to prevent
     * stored XSS in log viewer and filter injections.
     */
    public function capture_checkout_errors() {
        if ( ! function_exists( 'wc' ) ) {
            return;
        }
        $woocommerce = wc();
        $errors = ( $woocommerce && isset( $woocommerce->session ) ) ? $woocommerce->session->get( 'wac_checkout_errors', array() ) : array();
        if ( ! empty( $errors ) && isset( $this->services['logger'] ) ) {
            foreach ( $errors as $error ) {
                $safe_error = $this->sanitize_log_context( $error );
                $this->services['logger']->error( 'checkout_validation_error', $safe_error );
            }
        }
    }

    /**
     * Sanitize log context data from external sources.
     * Strips HTML and limits nesting to prevent stored XSS and memory exhaustion.
     *
     * @param mixed $data Raw context data.
     * @return mixed Sanitized context data.
     */
    private function sanitize_log_context( $data, int $depth = 0 ) {
        if ( $depth > 5 ) {
            return '[max_depth]';
        }
        if ( is_string( $data ) ) {
            // Strip HTML tags to prevent XSS in log viewers.
            return wp_kses( $data, array() );
        }
        if ( is_array( $data ) ) {
            $result = array();
            foreach ( $data as $key => $value ) {
                $safe_key             = sanitize_key( $key );
                $result[ $safe_key ]  = $this->sanitize_log_context( $value, $depth + 1 );
            }
            return $result;
        }
        if ( is_numeric( $data ) ) {
            return $data;
        }
        if ( is_bool( $data ) ) {
            return $data ? 'true' : 'false';
        }
        return sanitize_text_field( (string) $data );
    }

    // ─── Utilities ────────────────────────────────────────────────

    /**
     * Add settings link on plugins list.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wac-dashboard' ),
            esc_html__( 'Dashboard', 'woo-agentic-checkout' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Get a registered service by key.
     *
     * @param string $key Service identifier.
     * @return object|null
     */
    public function get_service( $key ) {
        return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
    }
}
