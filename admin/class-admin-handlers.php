<?php
namespace WooAgenticCheckout\Admin;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\Core;
use WooAgenticCheckout\Logger;

/**
 * Admin Handlers — processes admin-post actions (form submissions) and AJAX requests.
 * Wires the admin UI buttons to actual backend logic.
 *
 * All AJAX handlers return consistent JSON responses via wp_send_json_{success,error}.
 * Admin-post handlers redirect with wac_msg query params for toast notifications.
 *
 * @since 0.2.0
 */
class AdminHandlers {

    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Rate limiting — track request timestamps per action+user.
     *
     * @var array<string, int>
     */
    private static $rate_limit = array();

    /**
     * Constructor — registers all handlers.
     */
    public function __construct() {
        $this->logger = new Logger();

        $this->register_admin_post_actions();
        $this->register_ajax_actions();
    }

    /**
     * Check rate limit for a given action key.
     *
     * Allows no more than 1 request per 3 seconds per action+user.
     *
     * @param string $key The rate limit key (e.g., 'pause_exp_123').
     * @return bool True if allowed, false if rate limited.
     */
    private function check_rate_limit( $key ) {
        $user_id = get_current_user_id();
        $lk      = $user_id . ':' . $key;
        $now     = time();

        if ( isset( self::$rate_limit[ $lk ] ) && ( $now - self::$rate_limit[ $lk ] ) < 3 ) {
            return false;
        }

        self::$rate_limit[ $lk ] = $now;
        return true;
    }

    /**
     * Send a structured JSON success response with common fields.
     *
     * @param string $message  Success message.
     * @param array  $extra    Additional data to merge.
     */
    private function json_success( $message, $extra = array() ) {
        wp_send_json_success( array_merge( array(
            'message'  => $message,
            'success'  => true,
            'redirect' => '',
        ), $extra ) );
    }

    /**
     * Send a structured JSON error response with common fields.
     *
     * @param string $message  Error message.
     * @param array  $extra    Additional data to merge.
     */
    private function json_error( $message, $extra = array() ) {
        wp_send_json_error( array_merge( array(
            'message' => $message,
            'success' => false,
        ), $extra ) );
    }

    /**
     * Register admin_post_{action} hooks.
     */
    private function register_admin_post_actions() {
        $actions = array(
            'wac_manual_agent',
            'wac_create_experiment',
            'wac_apply_suggestion',
            'wac_reject_suggestion',
            'wac_save_settings_advanced',
        );

        foreach ( $actions as $action ) {
            add_action( "admin_post_{$action}", array( $this, "handle_{$action}" ) );
        }
    }

    /**
     * Register wp_ajax_ hooks for JS-invoked actions.
     */
    private function register_ajax_actions() {
        $ajax_actions = array(
            'wac_pause_experiment',
            'wac_resume_experiment',
            'wac_reject_suggestion',
            'wac_run_agent',
            'wac_get_experiment_detail',
            'wac_get_logs',
        );

        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_{$action}", array( $this, "ajax_{$action}" ) );
        }
    }

    // ─── Admin-Post Handlers ─────────────────────────────────────

    /**
     * Handle manual agent run from the Agents / Dashboard tab.
     */
    public function handle_wac_manual_agent() {
        // Nonce check.
        if ( ! isset( $_POST['wac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wac_nonce'] ) ), 'wac_manual_agent' ) ) {
            wp_die( 'Security check failed.' );
        }

        // Capability check.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $agent_key = isset( $_POST['agent_key'] ) ? sanitize_key( wp_unslash( $_POST['agent_key'] ) ) : '';

        if ( empty( $agent_key ) ) {
            wp_safe_redirect( add_query_arg( 'wac_msg', 'no_agent', wp_get_referer() ) );
            exit;
        }

        $core = Core::get_instance();
        $agent_manager = $core->get_service( 'agents' );

        if ( ! $agent_manager ) {
            $this->logger->warning( 'manual_agent_service_unavailable', array( 'agent' => $agent_key ) );
            wp_safe_redirect( add_query_arg( 'wac_msg', 'service_unavailable', wp_get_referer() ) );
            exit;
        }

        $result = $agent_manager->manual_run( $agent_key );

        $this->logger->info( 'manual_agent_run', array(
            'agent'  => $agent_key,
            'result' => is_array( $result ) ? ( $result['error'] ?? 'success' ) : 'unknown',
        ) );

        $status = isset( $result['error'] ) ? 'error' : 'success';
        wp_safe_redirect( add_query_arg( array( 'wac_msg' => $status, 'wac_agent' => $agent_key ), wp_get_referer() ) );
        exit;
    }

    /**
     * Apply a suggestion (admin-post version).
     */
    public function handle_wac_apply_suggestion() {
        if ( ! isset( $_POST['wac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wac_nonce'] ) ), 'wac_apply_suggestion' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $suggestion_id = isset( $_POST['suggestion_id'] ) ? intval( $_POST['suggestion_id'] ) : 0;

        if ( $suggestion_id > 0 ) {
            $core   = Core::get_instance();
            $suggest = $core->get_service( 'suggest' );
            if ( $suggest ) {
                $result = $suggest->apply_suggestion( $suggestion_id );
                if ( is_wp_error( $result ) ) {
                    $this->logger->error( 'apply_suggestion_failed', array(
                        'id'    => $suggestion_id,
                        'error' => $result->get_error_message(),
                    ) );
                    wp_safe_redirect( add_query_arg( 'wac_msg', 'error', wp_get_referer() ) );
                    exit;
                }
                $this->logger->info( 'suggestion_applied', array( 'id' => $suggestion_id ) );
                wp_safe_redirect( add_query_arg( 'wac_msg', 'applied', wp_get_referer() ) );
                exit;
            }
        }

        wp_safe_redirect( add_query_arg( 'wac_msg', 'error', wp_get_referer() ) );
        exit;
    }

    /**
     * Reject a suggestion (admin-post version).
     */
    public function handle_wac_reject_suggestion() {
        if ( ! isset( $_POST['wac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wac_nonce'] ) ), 'wac_reject_suggestion' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $suggestion_id = isset( $_POST['suggestion_id'] ) ? intval( $_POST['suggestion_id'] ) : 0;
        $reason        = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( $suggestion_id > 0 ) {
            $core   = Core::get_instance();
            $suggest = $core->get_service( 'suggest' );
            if ( $suggest ) {
                $suggest->reject_suggestion( $suggestion_id, $reason );
                $this->logger->info( 'suggestion_rejected', array(
                    'id'     => $suggestion_id,
                    'reason' => $reason,
                ) );
            }
        }

        wp_safe_redirect( add_query_arg( 'wac_msg', 'rejected', wp_get_referer() ) );
        exit;
    }

    /**
     * Create experiment (placeholder — extended in later phases).
     */
    public function handle_wac_create_experiment() {
        if ( ! isset( $_POST['wac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wac_nonce'] ) ), 'wac_create_experiment' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // Placeholder — full experiment creation wizard coming in Phase 3.
        wp_safe_redirect( add_query_arg( 'wac_msg', 'exp_placeholder', wp_get_referer() ) );
        exit;
    }

    /**
     * Handle advanced settings save (placeholder).
     */
    public function handle_wac_save_settings_advanced() {
        if ( ! isset( $_POST['wac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wac_nonce'] ) ), 'wac_save_settings_advanced' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        wp_safe_redirect( add_query_arg( 'wac_msg', 'success', wp_get_referer() ) );
        exit;
    }

    // ─── AJAX Handlers ───────────────────────────────────────────

    /**
     * AJAX: Pause an experiment.
     */
    public function ajax_wac_pause_experiment() {
        $this->check_ajax_permissions();

        // Rate limit: max 1 pause per 3 seconds.
        if ( ! $this->check_rate_limit( 'pause_exp' ) ) {
            $this->logger->warning( 'rate_limit_exceeded', array( 'action' => 'pause_experiment' ) );
            $this->json_error( __( 'Please wait a moment before trying again.', 'woo-agentic-checkout' ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id < 1 ) {
            $this->json_error( __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            $this->logger->error( 'ajax_ab_service_unavailable', array( 'action' => 'pause' ) );
            $this->json_error( __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) );
        }

        try {
            $result = $ab->pause_experiment( $id );
            $this->logger->info( 'experiment_paused', array( 'id' => $id ) );
            $this->json_success( __( 'Experiment paused. Visitors will see the control variant.', 'woo-agentic-checkout' ), array( 'id' => $id ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_pause_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            $this->json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Resume an experiment.
     */
    public function ajax_wac_resume_experiment() {
        $this->check_ajax_permissions();

        // Rate limit: max 1 resume per 3 seconds.
        if ( ! $this->check_rate_limit( 'resume_exp' ) ) {
            $this->logger->warning( 'rate_limit_exceeded', array( 'action' => 'resume_experiment' ) );
            $this->json_error( __( 'Please wait a moment before trying again.', 'woo-agentic-checkout' ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id < 1 ) {
            $this->json_error( __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            $this->logger->error( 'ajax_ab_service_unavailable', array( 'action' => 'resume' ) );
            $this->json_error( __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) );
        }

        try {
            $ab->resume_experiment( $id );
            $this->json_success( __( 'Experiment resumed.', 'woo-agentic-checkout' ), array( 'id' => $id ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_resume_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            $this->json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Reject a suggestion (by JS).
     */
    public function ajax_wac_reject_suggestion() {
        $this->check_ajax_permissions();

        // Rate limit: max 1 rejection per 3 seconds.
        if ( ! $this->check_rate_limit( 'reject_sugg' ) ) {
            $this->logger->warning( 'rate_limit_exceeded', array( 'action' => 'reject_suggestion' ) );
            $this->json_error( __( 'Please wait a moment before trying again.', 'woo-agentic-checkout' ) );
        }

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( $id < 1 ) {
            $this->json_error( __( 'Invalid suggestion ID.', 'woo-agentic-checkout' ) );
        }

        $core   = Core::get_instance();
        $suggest = $core->get_service( 'suggest' );

        if ( ! $suggest ) {
            $this->logger->error( 'ajax_suggest_service_unavailable', array( 'action' => 'reject' ) );
            $this->json_error( __( 'Suggestion engine unavailable.', 'woo-agentic-checkout' ) );
        }

        $suggest->reject_suggestion( $id, $reason );
        $this->logger->info( 'suggestion_rejected_ajax', array( 'id' => $id, 'reason' => $reason ) );
        $this->json_success( __( 'Suggestion rejected.', 'woo-agentic-checkout' ), array( 'id' => $id ) );
    }

    /**
     * AJAX: Run an agent manually.
     */
    public function ajax_wac_run_agent() {
        $this->check_ajax_permissions();

        // Rate limit: max 1 agent run per 10 seconds (slower — agents do real work).
        if ( ! $this->check_rate_limit( 'run_agent' ) ) {
            $this->logger->warning( 'rate_limit_exceeded', array( 'action' => 'run_agent' ) );
            $this->json_error( __( 'Agent already running. Please wait a moment.', 'woo-agentic-checkout' ) );
        }

        $agent_key = isset( $_POST['agent_key'] ) ? sanitize_key( wp_unslash( $_POST['agent_key'] ) ) : '';

        if ( empty( $agent_key ) ) {
            $this->json_error( __( 'Agent key is required.', 'woo-agentic-checkout' ) );
        }

        $core          = Core::get_instance();
        $agent_manager = $core->get_service( 'agents' );

        if ( ! $agent_manager ) {
            $this->logger->error( 'ajax_agents_service_unavailable', array( 'agent' => $agent_key ) );
            $this->json_error( __( 'Agent manager unavailable.', 'woo-agentic-checkout' ) );
        }

        try {
            $result = $agent_manager->manual_run( $agent_key );
            $this->logger->info( 'ajax_agent_run', array( 'agent' => $agent_key ) );

            if ( isset( $result['error'] ) ) {
                $this->json_error( sprintf(
                    /* translators: %1$s: agent name, %2$s: error message */
                    __( "Agent '%1\$s' failed: %2\$s", 'woo-agentic-checkout' ),
                    $agent_key,
                    $result['error']
                ) );
            }

            $this->json_success( sprintf(
                /* translators: %s: agent name */
                __( "Agent '%s' completed successfully.", 'woo-agentic-checkout' ),
                $agent_key
            ), array( 'result' => $result ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_agent_run_exception', array(
                'agent' => $agent_key,
                'error' => $e->getMessage(),
            ) );
            $this->json_error( sprintf(
                /* translators: %s: error message */
                __( 'Agent run failed: %s', 'woo-agentic-checkout' ),
                $e->getMessage()
            ) );
        }
    }

    /**
     * AJAX: Get experiment detail including Bayesian analysis.
     */
    public function ajax_wac_get_experiment_detail() {
        $this->check_ajax_permissions();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id < 1 ) {
            $this->json_error( __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            $this->json_error( __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) );
        }

        try {
            $experiment = $ab->get_experiment( $id );
            if ( empty( $experiment ) ) {
                $this->json_error( __( 'Experiment not found.', 'woo-agentic-checkout' ) );
            }

            $bayesian = $ab->bayesian_analysis( $id );

            $this->json_success( '', array(
                'experiment' => $experiment,
                'bayesian'   => $bayesian,
            ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_experiment_detail_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            $this->json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Get recent logs.
     */
    public function ajax_wac_get_logs() {
        $this->check_ajax_permissions();

        $level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
        $limit = isset( $_POST['limit'] ) ? min( 500, intval( $_POST['limit'] ) ) : 100;

        try {
            $logger = new Logger();
            $logs   = $logger->get_logs( array(
                'level' => $level,
                'limit' => $limit,
            ) );

            if ( empty( $logs ) ) {
                $this->json_success( __( 'No log entries found.', 'woo-agentic-checkout' ), array( 'logs' => array(), 'count' => 0 ) );
            }

            $this->json_success( '', array( 'logs' => $logs, 'count' => count( $logs ) ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_logs_failed', array( 'error' => $e->getMessage() ) );
            $this->json_error( __( 'Failed to retrieve logs.', 'woo-agentic-checkout' ) );
        }
    }

    // ─── Security ───────────────────────────────────────────────

    /**
     * Verify AJAX nonce and capabilities.
     *
     * Sends a JSON error response and exits if checks fail.
     */
    private function check_ajax_permissions() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'wac_admin' ) ) {
            $this->json_error( __( 'Security check failed. Please refresh the page and try again.', 'woo-agentic-checkout' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            $this->json_error( __( 'Insufficient permissions.', 'woo-agentic-checkout' ) );
        }
    }
}
