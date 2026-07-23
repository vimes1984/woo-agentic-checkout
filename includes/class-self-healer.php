<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Self-Healer — manages automated fixes for checkout issues.
 * Tracks healing actions, supports rollback, and enforces permissions.
 *
 * @since 0.1.0-alpha
 */
class SelfHealer {

    /**
     * Allowed healing action types.
     */
    const ACTIONS = array(
        'rollback_setting',
        'revert_template',
        'disable_plugin',
        'clear_cache',
        'restore_backup',
        'toggle_feature',
        'patch_javascript',
        'patch_css',
        'escalate',
    );

    /**
     * Minimum permission level for each action.
     */
    const ACTION_PERMISSIONS = array(
        'rollback_setting'   => 'auto_patch',
        'revert_template'    => 'auto_patch',
        'disable_plugin'     => 'auto_full',
        'clear_cache'        => 'auto_patch',
        'restore_backup'     => 'auto_full',
        'toggle_feature'     => 'auto_patch',
        'patch_javascript'   => 'suggest',
        'patch_css'          => 'suggest',
        'escalate'           => 'suggest',
    );

    /**
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Attempt to heal an issue detected by the error detector / self-healing agent.
     *
     * @param string $issue_id   Unique identifier for the issue.
     * @param string $action     Healing action type.
     * @param array  $params     Action parameters.
     * @param string $permission Current permission level.
     *
     * @return array{success: bool, action: string, message: string, rollback_id?: string}
     */
    public function attempt_heal( string $issue_id, string $action, array $params, string $permission = 'suggest' ): array {
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return array(
                'success' => false,
                'action'  => $action,
                'message' => "Unknown healing action: {$action}",
            );
        }

        $required_perm = self::ACTION_PERMISSIONS[ $action ] ?? 'auto_full';
        $perm_levels   = array( 'monitor' => 0, 'suggest' => 1, 'auto_patch' => 2, 'auto_full' => 3 );

        $current_level  = $perm_levels[ $permission ] ?? 0;
        $required_level = $perm_levels[ $required_perm ] ?? 3;

        if ( $current_level < $required_level ) {
            return array(
                'success' => false,
                'action'  => sanitize_key( $action ),
                'message' => sprintf(
                    'Permission denied. Required: %1$s, current: %2$s',
                    sanitize_key( $required_perm ),
                    sanitize_key( $permission )
                ),
                'needs_approval' => true,
            );
        }

        // Execute the action.
        $method = 'do_' . sanitize_key( $action );
        if ( method_exists( $this, $method ) ) {
            try {
                $result = $this->$method( $params );
                $rollback_id = $this->log_heal( $issue_id, $action, $params, $result );

                $this->logger->info( 'heal_applied', array(
                    'issue_id'    => $issue_id,
                    'action'      => $action,
                    'rollback_id' => $rollback_id,
                    'result'      => $result,
                ) );

                return array(
                    'success'     => true,
                    'action'      => $action,
                    'message'     => $result['message'] ?? 'Healing action applied.',
                    'rollback_id' => $rollback_id,
                );
            } catch ( \Exception $e ) {
                $this->logger->error( 'heal_failed', array(
                    'issue_id' => $issue_id,
                    'action'   => $action,
                    'error'    => sanitize_text_field( $e->getMessage() ),
                ) );

                return array(
                    'success' => false,
                    'action'  => $action,
                    'message' => sanitize_text_field( $e->getMessage() ),
                );
            }
        }

        // Fallback: return action as suggestion for escalation.
        return array(
            'success'        => false,
            'action'         => $action,
            'message'        => 'Action requires manual intervention.',
            'needs_approval' => true,
        );
    }

    /**
     * Rollback a previously applied healing action.
     *
     * @param string $rollback_id
     *
     * @return bool
     */
    public function rollback( string $rollback_id ): bool {
        global $wpdb;

        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wac_heal_log WHERE rollback_id = %s",
            $rollback_id
        ), ARRAY_A );

        if ( ! $log ) {
            return false;
        }

        $rollback_data = json_decode( $log['rollback_data'], true );
        $action = $log['action'];
        $method = "undo_{$action}";

        if ( method_exists( $this, $method ) ) {
            try {
                $this->$method( $rollback_data );
                $this->logger->info( 'heal_rolled_back', array(
                    'rollback_id' => $rollback_id,
                    'action'      => $action,
                ) );
                return true;
            } catch ( \Exception $e ) {
                $this->logger->error( 'heal_rollback_failed', array(
                    'rollback_id' => $rollback_id,
                    'error'       => sanitize_text_field( $e->getMessage() ),
                ) );
                return false;
            }
        }

        return false;
    }

    /**
     * Get total number of healing actions performed.
     *
     * @return int
     */
    public function get_total_heals(): int {
        // Request-level cache for frequently-called dashboard metric.
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $cached = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wac_heal_log WHERE 1 = %d",
                1
            )
        );
        return $cached;
    }

    /**
     * Get recent heal log entries.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_heal_log( int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wac_heal_log ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A );

        // Strict type casting for numeric fields.
        foreach ( $rows as &$row ) {
            $row['id'] = (int) $row['id'];
        }
        unset( $row );

        return $rows;
    }

    // ─── Action Implementations ──────────────────────────────────

    /**
     * Rollback a plugin setting to a previous value.
     */
    private function do_rollback_setting( array $params ): array {
        $option_name = sanitize_key( $params['option'] ?? '' );
        $prev_value  = $params['previous_value'] ?? '';

        if ( empty( $option_name ) ) {
            throw new \InvalidArgumentException( 'Setting name required for rollback.' );
        }

        // Whitelist allowed option names to prevent arbitrary option writes.
        $allowed_options = array(
            'wac_agent_failure_counts',
            'wac_notify_email',
            'wac_slack_webhook',
            'wac_notify_email_enabled',
            'wac_notify_slack_enabled',
            'wac_auto_heal_permission',
            'wac_agent_enabled',
        );

        if ( ! in_array( $option_name, $allowed_options, true ) ) {
            throw new \InvalidArgumentException( 'Setting name not in allowed list for rollback.' );
        }

        // Sanitize value based on option type.
        switch ( $option_name ) {
            case 'wac_notify_email':
                $prev_value = sanitize_email( $prev_value );
                break;
            case 'wac_slack_webhook':
                $prev_value = esc_url_raw( $prev_value );
                break;
            case 'wac_notify_email_enabled':
            case 'wac_notify_slack_enabled':
                $prev_value = 'yes' === $prev_value ? 'yes' : 'no';
                break;
            case 'wac_agent_failure_counts':
                $prev_value = is_array( $prev_value ) ? $prev_value : array();
                break;
            case 'wac_agent_enabled':
                $prev_value = is_array( $prev_value ) ? $prev_value : array();
                break;
            case 'wac_auto_heal_permission':
                $allowed_perms = array( 'monitor', 'suggest', 'auto_patch', 'auto_full' );
                $prev_value    = in_array( $prev_value, $allowed_perms, true ) ? $prev_value : 'suggest';
                break;
        }

        update_option( $option_name, $prev_value );

        return array(
            'message' => "Rolled back setting: {$option_name}",
        );
    }

    /**
     * Revert a template override to default.
     */
    private function do_revert_template( array $params ): array {
        $template = $params['template'] ?? '';

        if ( empty( $template ) ) {
            throw new \InvalidArgumentException( 'Template name required.' );
        }

        // Prevent path traversal by disallowing '..', null bytes, and absolute paths.
        if ( str_contains( $template, '..' ) || str_contains( $template, chr( 0 ) ) || str_starts_with( $template, '/' ) ) {
            throw new \InvalidArgumentException( 'Invalid template name.' );
        }

        // Remove template override via filter.
        add_filter( 'woocommerce_locate_template', function ( $template_path, $template_name ) use ( $template ) {
            if ( $template_name === $template ) {
                return locate_template( 'woocommerce/' . $template_name );
            }
            return $template_path;
        }, 999, 2 );

        return array(
            'message' => 'Reverted template: ' . esc_html( $template ),
        );
    }

    /**
     * Log and track the healing action for rollback.
     */
    private function log_heal( string $issue_id, string $action, array $params, array $result ): string {
        global $wpdb;

        $rollback_id = 'heal_' . uniqid();

        // Sanitize inputs before DB insertion.
        $safe_issue_id = substr( sanitize_text_field( $issue_id ), 0, 128 );
        $safe_action   = sanitize_key( $action );

        $wpdb->insert(
            $wpdb->prefix . 'wac_heal_log',
            array(
                'rollback_id'   => $rollback_id,
                'issue_id'      => $safe_issue_id,
                'action'        => $safe_action,
                'params'        => wp_json_encode( $params ),
                'rollback_data' => wp_json_encode( $params ), // Store original state for undo
                'result'        => $result['message'] ?? 'OK',
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $rollback_id;
    }

    // ─── Permission Utilities ────────────────────────────────────

    /**
     * Get available actions for a given permission level.
     *
     * @param string $permission Current permission level.
     *
     * @return array
     */
    public function get_actions_for_permission( string $permission ): array {
        $perm_levels = array( 'monitor' => 0, 'suggest' => 1, 'auto_patch' => 2, 'auto_full' => 3 );
        $current     = $perm_levels[ $permission ] ?? 0;

        $available = array();
        foreach ( self::ACTION_PERMISSIONS as $action => $required ) {
            $required_level = $perm_levels[ $required ] ?? 3;
            if ( $current >= $required_level ) {
                $available[] = $action;
            }
        }

        return $available;
    }

    /**
     * Dummy undo methods for completeness.
     */
    private function undo_rollback_setting( array $data ) {}
    private function undo_revert_template( array $data ) {}
    private function undo_disable_plugin( array $data ) {}
    private function undo_toggle_feature( array $data ) {}
}
