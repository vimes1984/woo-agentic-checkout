<?php
namespace WooAgenticCheckout\Admin;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\Core;
use WooAgenticCheckout\Logger;

/**
 * Admin Handlers — processes admin-post actions (form submissions) and AJAX requests.
 * Wires the admin UI buttons to actual backend logic.
 *
 * @since 0.1.0-alpha
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

        // Admin-post (form submission) actions.
        $this->register_admin_post_actions();

        // AJAX actions (from admin JS).
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
            wp_safe_redirect( add_query_arg( 'wac_msg', 'service_unavailable', wp_get_referer() ) );
            exit;
        }

        $result = $agent_manager->manual_run( $agent_key );

        $this->logger->info( 'manual_agent_run', array(
            'agent'  => $agent_key,
            'result' => $result,
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
            $core = Core::get_instance();
            $suggest = $core->get_service( 'suggest' );
            if ( $suggest ) {
                $suggest->apply_suggestion( $suggestion_id );
            }
        }

        wp_safe_redirect( add_query_arg( 'wac_msg', 'applied', wp_get_referer() ) );
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
            $core = Core::get_instance();
            $suggest = $core->get_service( 'suggest' );
            if ( $suggest ) {
                $suggest->reject_suggestion( $suggestion_id, $reason );
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

        if ( $id > 0 ) {
            $core = Core::get_instance();
            $ab = $core->get_service( 'ab' );
            if ( $ab ) {
                $ab->pause_experiment( $id );
                wp_send_json_success( array( 'message' => 'Experiment paused.' ) );
            }
        }

        wp_send_json_error( array( 'message' => 'Invalid experiment ID.' ) );
    }

    /**
     * AJAX: Resume an experiment.
     */
    public function ajax_wac_resume_experiment() {
        $this->check_ajax_permissions();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id > 0 ) {
            $core = Core::get_instance();
            $ab = $core->get_service( 'ab' );
            if ( $ab ) {
                $ab->resume_experiment( $id );
                wp_send_json_success( array( 'message' => 'Experiment resumed.' ) );
            }
        }

        wp_send_json_error( array( 'message' => 'Invalid experiment ID.' ) );
    }

    /**
     * AJAX: Reject a suggestion (by JS).
     */
    public function ajax_wac_reject_suggestion() {
        $this->check_ajax_permissions();

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( $id > 0 ) {
            $core = Core::get_instance();
            $suggest = $core->get_service( 'suggest' );
            if ( $suggest ) {
                $suggest->reject_suggestion( $id, $reason );
                wp_send_json_success( array( 'message' => 'Suggestion rejected.' ) );
            }
        }

        wp_send_json_error( array( 'message' => 'Invalid suggestion ID.' ) );
    }

    /**
     * AJAX: Run an agent manually.
     */
    public function ajax_wac_run_agent() {
        $this->check_ajax_permissions();

        $agent_key = isset( $_POST['agent_key'] ) ? sanitize_key( wp_unslash( $_POST['agent_key'] ) ) : '';

        if ( empty( $agent_key ) ) {
            wp_send_json_error( array( 'message' => 'Agent key required.' ) );
        }

        $core = Core::get_instance();
        $agent_manager = $core->get_service( 'agents' );

        if ( ! $agent_manager ) {
            wp_send_json_error( array( 'message' => 'Agent manager unavailable.' ) );
        }

        $result = $agent_manager->manual_run( $agent_key );

        wp_send_json_success( array(
            'message' => "Agent '{$agent_key}' completed.",
            'result'  => $result,
        ) );
    }

    /**
     * AJAX: Get experiment detail including Bayesian analysis.
     */
    public function ajax_wac_get_experiment_detail() {
        $this->check_ajax_permissions();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $id > 0 ) {
            $core = Core::get_instance();
            $ab = $core->get_service( 'ab' );
            if ( $ab ) {
                $experiment = $ab->get_experiment( $id );
                $bayesian   = $ab->bayesian_analysis( $id );

                wp_send_json_success( array(
                    'experiment' => $experiment,
                    'bayesian'   => $bayesian,
                ) );
            }
        }

        wp_send_json_error( array( 'message' => 'Invalid experiment ID.' ) );
    }

    /**
     * AJAX: Get recent logs.
     */
    public function ajax_wac_get_logs() {
        $this->check_ajax_permissions();

        $level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
        $limit = isset( $_POST['limit'] ) ? min( 500, intval( $_POST['limit'] ) ) : 100;

        $logger = new Logger();
        $logs   = $logger->get_logs( array(
            'level' => $level,
            'limit' => $limit,
        ) );

        wp_send_json_success( array( 'logs' => $logs ) );
    }

    // ─── Security ───────────────────────────────────────────────

    /**
     * Verify AJAX nonce and capabilities.
     */
    private function check_ajax_permissions() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'wac_admin' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }
    }
}
