<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Notifier — sends alerts for critical issues, new suggestions, and agent events.
 * Supports email, Slack webhook, and a generic hook for custom integrations.
 *
 * @since 0.1.0-alpha
 */
class Notifier {

    /**
     * Send a notification.
     *
     * @param string $severity 'info', 'warning', 'critical'
     * @param string $title    Short event title.
     * @param string $message  Detailed message.
     * @param array  $context  Additional data for the notification.
     *
     * @return bool
     */
    public function notify( string $severity, string $title, string $message, array $context = array() ): bool {
        // Always fire a hook so custom integrations can listen.
        do_action( 'wac_notification', $severity, $title, $message, $context );

        $sent = false;

        // Email notification.
        if ( $this->is_channel_enabled( 'email' ) ) {
            $sent = $this->send_email( $severity, $title, $message, $context ) || $sent;
        }

        // Slack webhook.
        if ( $this->is_channel_enabled( 'slack' ) ) {
            $sent = $this->send_slack( $severity, $title, $message, $context ) || $sent;
        }

        return $sent;
    }

    /**
     * Notify about a critical error (convenience wrapper).
     *
     * @param string $title
     * @param string $message
     * @param array  $context
     *
     * @return bool
     */
    public function critical( string $title, string $message, array $context = array() ): bool {
        $safe_title = sanitize_text_field( $title );
        return $this->notify( 'critical', '[WAC CRITICAL] ' . $safe_title, $message, $context );
    }

    /**
     * Notify about a new suggestion (convenience wrapper).
     *
     * @param array $suggestion
     *
     * @return bool
     */
    public function new_suggestion( array $suggestion ): bool {
        return $this->notify(
            'info',
            "💡 New Checkout Suggestion: {$suggestion['title']}",
            sprintf(
                "Score: %.0f%%\nCategory: %s\nAction: %s\n\n%s",
                (float) ( $suggestion['score'] ?? 0 ) * 100,
                $suggestion['category'] ?? 'general',
                $suggestion['action_type'] ?? 'unknown',
                $suggestion['description'] ?? ''
            ),
            $suggestion
        );
    }

    /**
     * Notify about a healing action.
     *
     * @param array $heal_result
     *
     * @return bool
     */
    public function heal_applied( array $heal_result ): bool {
        $status   = $heal_result['success'] ? '✅' : '❌';
        $safe_action = isset( $heal_result['action'] ) ? sanitize_key( $heal_result['action'] ) : 'unknown';
        return $this->notify(
            $heal_result['success'] ? 'info' : 'warning',
            "{$status} Healing Action: {$safe_action}",
            $heal_result['message'] ?? '',
            $heal_result
        );
    }

    /**
     * Send an email notification.
     *
     * @param string $severity
     * @param string $title
     * @param string $message
     * @param array  $context
     *
     * @return bool
     */
    private function send_email( string $severity, string $title, string $message, array $context ): bool {
        $to = get_option( 'wac_notify_email', get_option( 'admin_email', '' ) );

        if ( empty( $to ) || ! is_email( $to ) ) {
            return false;
        }

        $subject = sprintf(
            '[%s] %s — %s',
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            sanitize_text_field( $title ),
            gmdate( 'Y-m-d H:i' )
        );

        $body = $this->build_email_body( $severity, $title, $message, $context );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );
        $admin_email = get_option( 'admin_email', '' );
        if ( is_email( $admin_email ) ) {
            $headers[] = 'From: Woo Agentic Checkout <' . $admin_email . '>';
        }

        return wp_mail( $to, $subject, $body, $headers );
    }

    /**
     * Build HTML email body.
     */
    private function build_email_body( string $severity, string $title, string $message, array $context ): string {
        $color = 'info' === $severity ? '#2271b1' : ( 'warning' === $severity ? '#dba617' : '#d63638' );

        $context_html = '';
        if ( ! empty( $context ) ) {
            $context_html = '<h3>Context</h3><pre style="background:#f0f0f1;padding:12px;border-radius:4px;overflow:auto;max-height:300px;font-size:12px;">'
                . esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
                . '</pre>';
        }

        $safe_title      = esc_html( $title );
        $safe_message    = esc_html( $message );
        $dashboard_url   = esc_url( $this->get_dashboard_url() );

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:20px;">
    <div style="border-left:4px solid {$color};padding:12px 20px;background:#f9f9f9;border-radius:4px;">
        <h2 style="margin:0 0 8px;font-size:18px;color:#1d2327;">{$safe_title}</h2>
        <p style="margin:0 0 16px;color:#50575e;font-size:14px;white-space:pre-wrap;">{$safe_message}</p>
        {$context_html}
        <p style="margin:16px 0 0;font-size:11px;color:#8c8f94;">
            Woo Agentic Checkout &mdash; <a href="{$dashboard_url}" style="color:#2271b1;">View Dashboard</a>
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send a Slack webhook notification.
     *
     * @param string $severity
     * @param string $title
     * @param string $message
     * @param array  $context
     *
     * @return bool
     */
    private function send_slack( string $severity, string $title, string $message, array $context ): bool {
        $webhook_url = get_option( 'wac_slack_webhook', '' );

        // Validate webhook URL to prevent SSRF.
        if ( ! empty( $webhook_url ) ) {
            $parsed = wp_parse_url( $webhook_url );
            if ( ! in_array( $parsed['scheme'] ?? '', array( 'https' ), true ) ) {
                $webhook_url = '';
            }
            $host = $parsed['host'] ?? '';
            if ( ! str_ends_with( $host, '.slack.com' ) ) {
                $webhook_url = '';
            }
        }

        if ( empty( $webhook_url ) ) {
            return false;
        }

        $color = 'info' === $severity ? '#2271b1' : ( 'warning' === $severity ? '#dba617' : '#d63638' );

        $safe_title   = esc_html( $title );
        $safe_message = esc_html( $message );

        $payload = array(
            'attachments' => array(
                array(
                    'color'      => $color,
                    'title'      => $safe_title,
                    'text'       => $safe_message,
                    'footer'     => 'Woo Agentic Checkout',
                    'ts'         => time(),
                    'fields'     => array(
                        array(
                            'title' => 'Dashboard',
                            'value' => $this->get_dashboard_url(),
                            'short' => true,
                        ),
                        array(
                            'title' => 'Severity',
                            'value' => $severity,
                            'short' => true,
                        ),
                    ),
                ),
            ),
        );

        $response = wp_safe_remote_post( $webhook_url, array(
            'body'    => wp_json_encode( $payload ),
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    /**
     * Check if a notification channel is enabled.
     *
     * @param string $channel 'email' or 'slack'.
     *
     * @return bool
     */
    private function is_channel_enabled( string $channel ): bool {
        return 'yes' === get_option( "wac_notify_{$channel}_enabled", 'no' );
    }

    /**
     * Get the dashboard URL.
     *
     * @return string
     */
    private function get_dashboard_url(): string {
        return admin_url( 'admin.php?page=wac-dashboard' );
    }
}
