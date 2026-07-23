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
     * Settings that store API keys — can be masked for display.
     */
    const API_KEY_SETTINGS = array(
        'llm_api_key',
        'ga4_api_secret',
        'ga4_credentials_json',
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
     * Set a setting value with bounds validation.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    public function set( string $key, $value ): bool {
        // Apply numeric bounds if configured.
        if ( is_numeric( $value ) && isset( self::NUMERIC_BOUNDS[ $key ] ) ) {
            $bounds = self::NUMERIC_BOUNDS[ $key ];
            $value  = max( $bounds['min'], min( $bounds['max'], $value ) );
        }
        return update_option( self::PREFIX . $key, $value );
    }

    /**
     * Allowed agent keys — must match this set exactly.
     */
    const ALLOWED_AGENT_KEYS = array(
        'conversion_analyzer',
        'ab_optimizer',
        'error_detector',
        'suggestion_generator',
        'self_healing',
        'self_healing_agent',
    );

    /**
     * Check if a specific agent is enabled.
     *
     * Only accepts keys from ALLOWED_AGENT_KEYS to prevent
     * option name injection through crafted agent identifiers.
     *
     * @param string $agent_key
     *
     * @return bool
     */
    public function is_agent_enabled( string $agent_key ): bool {
        if ( ! in_array( $agent_key, self::ALLOWED_AGENT_KEYS, true ) ) {
            return false;
        }

        // Map agent keys to option keys.
        $map = array(
            'conversion_analyzer'  => 'agent_conversion_analyzer_enabled',
            'ab_optimizer'        => 'agent_ab_optimizer_enabled',
            'error_detector'      => 'agent_error_detector_enabled',
            'suggestion_generator' => 'agent_suggestion_generator_enabled',
            'self_healing'        => 'agent_self_healing_enabled',
            'self_healing_agent'  => 'agent_self_healing_enabled',
        );

        $option_key = $map[ $agent_key ] ?? 'agent_' . $agent_key . '_enabled';
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
     * Numeric setting bounds — applied during sanitization.
     */
    const NUMERIC_BOUNDS = array(
        'ab_min_sample_size'      => array( 'min' => 10,   'max' => 100000 ),
        'ab_min_conversions'      => array( 'min' => 1,    'max' => 10000 ),
        'ab_confidence_threshold' => array( 'min' => 0.5,  'max' => 0.9999 ),
        'ab_max_concurrent'       => array( 'min' => 1,    'max' => 50 ),
    );

    /**
     * Sanitize setting values.
     *
     * Preserves float precision for settings like ab_confidence_threshold (0.95)
     * while still hardening against injection. Clamps numeric values to
     * configured bounds to prevent logically invalid configurations.
     *
     * WordPress calls this with ($value, $option_name). We use the option
     * name to apply per-key bounds defined in NUMERIC_BOUNDS.
     *
     * @param mixed  $value       The setting value.
     * @param string $option_name The option name (passed by WordPress).
     *
     * @return mixed
     */
    public function sanitize( $value, $option_name = '' ) {
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'sanitize' ), $value );
        }
        if ( is_numeric( $value ) ) {
            // Preserve float values (e.g. ab_confidence_threshold = 0.95).
            if ( is_float( $value + 0 ) && false !== strpos( (string) $value, '.' ) ) {
                $result = floatval( $value );
            } else {
                $result = intval( $value );
            }
            // Derive the short key (without 'wac_' prefix) for bounds lookup.
            $short_key = str_replace( self::PREFIX, '', $option_name );
            if ( isset( self::NUMERIC_BOUNDS[ $short_key ] ) ) {
                $bounds = self::NUMERIC_BOUNDS[ $short_key ];
                $result = max( $bounds['min'], min( $bounds['max'], $result ) );
            }
            return $result;
        }
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }
        return sanitize_text_field( (string) $value );
    }
}
