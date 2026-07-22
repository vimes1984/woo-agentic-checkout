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
     * Constructor — registers all handlers.
     */
    public function __construct() {
        $this->logger = new Logger();

        $this->register_admin_post_actions();
        $this->register_ajax_actions();
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

    // ─── AJAX Handlers ───────────────────────────────────────────

    /**
     * AJAX: Pause an experiment.
     */
    public function ajax_wac_pause_experiment() {
        $this->check_ajax_permissions();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            $this->logger->error( 'ajax_ab_service_unavailable', array( 'action' => 'pause' ) );
            wp_send_json_error( array( 'message' => __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) ) );
        }

        try {
            $result = $ab->pause_experiment( $id );
            $this->logger->info( 'experiment_paused', array( 'id' => $id ) );
            wp_send_json_success( array(
                'message' => __( 'Experiment paused. Visitors will see the control variant.', 'woo-agentic-checkout' ),
                'id'      => $id,
            ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_pause_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX: Resume an experiment.
     */
    public function ajax_wac_resume_experiment() {
        $this->check_ajax_permissions();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            $this->logger->error( 'ajax_ab_service_unavailable', array( 'action' => 'resume' ) );
            wp_send_json_error( array( 'message' => __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) ) );
        }

        try {
            $ab->resume_experiment( $id );
            wp_send_json_success( array(
                'message' => __( 'Experiment resumed.', 'woo-agentic-checkout' ),
                'id'      => $id,
            ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_resume_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX: Reject a suggestion (by JS).
     */
    public function ajax_wac_reject_suggestion() {
        $this->check_ajax_permissions();

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( $id < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid suggestion ID.', 'woo-agentic-checkout' ) ) );
        }

        $core   = Core::get_instance();
        $suggest = $core->get_service( 'suggest' );

        if ( ! $suggest ) {
            $this->logger->error( 'ajax_suggest_service_unavailable', array( 'action' => 'reject' ) );
            wp_send_json_error( array( 'message' => __( 'Suggestion engine unavailable.', 'woo-agentic-checkout' ) ) );
        }

        $suggest->reject_suggestion( $id, $reason );
        $this->logger->info( 'suggestion_rejected_ajax', array( 'id' => $id, 'reason' => $reason ) );
        wp_send_json_success( array(
            'message' => __( 'Suggestion rejected.', 'woo-agentic-checkout' ),
            'id'      => $id,
        ) );
    }

    /**
     * AJAX: Run an agent manually.
     */
    public function ajax_wac_run_agent() {
        $this->check_ajax_permissions();

        $agent_key = isset( $_POST['agent_key'] ) ? sanitize_key( wp_unslash( $_POST['agent_key'] ) ) : '';

        if ( empty( $agent_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Agent key is required.', 'woo-agentic-checkout' ) ) );
        }

        $core          = Core::get_instance();
        $agent_manager = $core->get_service( 'agents' );

        if ( ! $agent_manager ) {
            $this->logger->error( 'ajax_agents_service_unavailable', array( 'agent' => $agent_key ) );
            wp_send_json_error( array( 'message' => __( 'Agent manager unavailable.', 'woo-agentic-checkout' ) ) );
        }

        try {
            $result = $agent_manager->manual_run( $agent_key );
            $this->logger->info( 'ajax_agent_run', array( 'agent' => $agent_key ) );

            if ( isset( $result['error'] ) ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: %1$s: agent name, %2$s: error message */
                        __( "Agent '%1\$s' failed: %2\$s", 'woo-agentic-checkout' ),
                        $agent_key,
                        $result['error']
                    ),
                ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %s: agent name */
                    __( "Agent '%s' completed successfully.", 'woo-agentic-checkout' ),
                    $agent_key
                ),
                'result'  => $result,
            ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_agent_run_exception', array(
                'agent' => $agent_key,
                'error' => $e->getMessage(),
            ) );
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Agent run failed: %s', 'woo-agentic-checkout' ),
                    $e->getMessage()
                ),
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
            wp_send_json_error( array( 'message' => __( 'Invalid experiment ID.', 'woo-agentic-checkout' ) ) );
        }

        $core = Core::get_instance();
        $ab   = $core->get_service( 'ab' );

        if ( ! $ab ) {
            wp_send_json_error( array( 'message' => __( 'A/B testing service unavailable.', 'woo-agentic-checkout' ) ) );
        }

        try {
            $experiment = $ab->get_experiment( $id );
            if ( empty( $experiment ) ) {
                wp_send_json_error( array( 'message' => __( 'Experiment not found.', 'woo-agentic-checkout' ) ) );
            }

            $bayesian = $ab->bayesian_analysis( $id );

            wp_send_json_success( array(
                'experiment' => $experiment,
                'bayesian'   => $bayesian,
            ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_experiment_detail_failed', array(
                'id'    => $id,
                'error' => $e->getMessage(),
            ) );
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
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
                wp_send_json_success( array( 'logs' => array(), 'message' => __( 'No log entries found.', 'woo-agentic-checkout' ) ) );
            }

            wp_send_json_success( array( 'logs' => $logs, 'count' => count( $logs ) ) );
        } catch ( \Exception $e ) {
            $this->logger->error( 'ajax_logs_failed', array( 'error' => $e->getMessage() ) );
            wp_send_json_error( array( 'message' => __( 'Failed to retrieve logs.', 'woo-agentic-checkout' ) ) );
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
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'woo-agentic-checkout' ) ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woo-agentic-checkout' ) ) );
        }
    }
}
