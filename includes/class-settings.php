<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Settings — manages all plugin configuration options.
 *
 * @since 0.1.0-alpha
 */
class Settings {

    /**
     * Option key prefix.
     */
    const PREFIX = 'wac_';

    /**
     * Default settings.
     *
     * @var array
     */
    private $defaults = array(
        // LLM Provider
        'llm_provider'            => 'openai',
        'llm_api_key'             => '',
        'llm_model'               => 'gpt-4o',
        'llm_ollama_url'          => 'http://localhost:11434',
        'llm_ollama_model'        => 'llama3',

        // GA4 / Signals
        'ga4_measurement_id'      => '',
        'ga4_api_secret'          => '',
        'ga4_property_id'         => '',
        'ga4_credentials_json'    => '',
        'signal_collection_enabled' => 'yes',

        // Agent Toggles
        'agent_conversion_analyzer_enabled' => 'yes',
        'agent_ab_optimizer_enabled'        => 'yes',
        'agent_error_detector_enabled'      => 'yes',
        'agent_suggestion_generator_enabled' => 'yes',
        'agent_self_healing_enabled'        => 'yes',

        // Self-Healing Permission Level
        'heal_permission_level'   => 'suggest', // monitor | suggest | auto_patch | auto_full

        // A/B Testing
        'ab_min_sample_size'      => 100,
        'ab_min_conversions'      => 30,
        'ab_confidence_threshold' => 0.95,
        'ab_max_concurrent'       => 3,

        // Notifications
        'slack_webhook'           => '',
        'notify_email'            => '',
        'notify_email_enabled'    => 'no',
        'notify_slack_enabled'    => 'no',

        // General
        'auto_suggest_enabled'    => 'yes',
        'debug_mode'              => 'no',
    );

    /**
     * Get a setting value.
     *
     * @param string $key     Setting key (without prefix).
     * @param mixed  $default Fallback if not set.
     *
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        $value = get_option( self::PREFIX . $key, null );

        if ( null === $value ) {
            $defaults = $this->get_defaults();
            return isset( $defaults[ $key ] ) ? $defaults[ $key ] : $default;
        }

        return $value;
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    public function set( string $key, $value ): bool {
        return update_option( self::PREFIX . $key, $value );
    }

    /**
     * Check if a specific agent is enabled.
     *
     * @param string $agent_key
     *
     * @return bool
     */
    public function is_agent_enabled( string $agent_key ): bool {
        $option_key = 'agent_' . str_replace( '-', '_', $agent_key ) . '_enabled';
        $option_key = str_replace( ' ', '_', $option_key );

        // Map agent keys to option keys.
        $map = array(
            'conversion_analyzer' => 'agent_conversion_analyzer_enabled',
            'ab_optimizer'        => 'agent_ab_optimizer_enabled',
            'error_detector'      => 'agent_error_detector_enabled',
            'suggestion_generator' => 'agent_suggestion_generator_enabled',
            'self_healing'        => 'agent_self_healing_enabled',
            'self_healing_agent'  => 'agent_self_healing_enabled',
        );

        $option_key = $map[ $agent_key ] ?? $option_key;
        return 'yes' === $this->get( $option_key, 'yes' );
    }

    /**
     * Get current permission level for self-healing.
     *
     * @return string
     */
    public function get_heal_permission(): string {
        return $this->get( 'heal_permission_level', 'suggest' );
    }

    /**
     * Get all settings as an array.
     *
     * @return array
     */
    public function get_all(): array {
        $settings = array();
        foreach ( array_keys( $this->defaults ) as $key ) {
            $settings[ $key ] = $this->get( $key );
        }
        return $settings;
    }

    /**
     * Get default settings.
     *
     * @return array
     */
    public function get_defaults(): array {
        return $this->defaults;
    }

    /**
     * Register settings with WordPress Settings API.
     */
    public function register_settings() {
        foreach ( $this->defaults as $key => $default ) {
            register_setting( 'wac_settings', self::PREFIX . $key, array(
                'default'           => $default,
                'sanitize_callback' => array( $this, 'sanitize' ),
                'show_in_rest'      => false,
            ) );
        }
    }

    /**
     * Sanitize setting values.
     *
     * Preserves float precision for settings like ab_confidence_threshold (0.95)
     * while still hardening against injection.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function sanitize( $value ) {
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'sanitize' ), $value );
        }
        if ( is_numeric( $value ) ) {
            // Preserve float values (e.g. ab_confidence_threshold = 0.95).
            if ( is_float( $value + 0 ) && false !== strpos( (string) $value, '.' ) ) {
                return floatval( $value );
            }
            return intval( $value );
        }
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }
        return sanitize_text_field( (string) $value );
    }
}
