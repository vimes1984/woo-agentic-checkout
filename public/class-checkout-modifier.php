<?php
namespace WooAgenticCheckout\Public;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\ABTestManager;

/**
 * Checkout Modifier — applies A/B test variants to the checkout page.
 * Handles field ordering, removals, CSS injection, and template overrides.
 *
 * @since 0.1.0-alpha
 */
class CheckoutModifier {

    /**
     * @var ABTestManager
     */
    private $ab;

    /**
     * Cached merged variant configs for the current session (across all experiments).
     *
     * @var array|null
     */
    private $session_config = null;

    /**
     * Track which experiments have already applied configs.
     *
     * @var array<string, bool>
     */
    private $applied_experiments = array();

    /**
     * @param ABTestManager $ab
     */
    public function __construct( ABTestManager $ab ) {
        $this->ab = $ab;
    }

    /**
     * Return the list of applied experiment names for this session.
     *
     * @return array<string, bool>
     */
    public function get_applied_experiments(): array {
        // Trigger config load.
        $this->get_session_variant_config();
        return $this->applied_experiments;
    }

    /**
     * Store removed fields so we can unhook their validation.
     *
     * @var array
     */
    private $removed_field_keys = array();

    /**
     * Modify checkout fields based on active experiment variant.
     * Also hooks into WooCommerce validation to skip removed fields.
     *
     * @param array $fields WooCommerce checkout fields.
     *
     * @return array
     */
    public function modify_fields( array $fields ): array {
        $config = $this->get_session_variant_config();

        if ( null === $config ) {
            return $fields;
        }

        // Apply field reordering.
        if ( isset( $config['field_order'] ) ) {
            $fields = $this->apply_field_order( $fields, $config['field_order'] );
        }

        // Apply field removals.
        if ( isset( $config['remove_fields'] ) ) {
            $fields = $this->apply_field_removals( $fields, $config['remove_fields'] );
            // Register filter to disable WooCommerce validation for removed fields.
            $this->unvalidate_removed_fields();
        }

        // Apply field labels/placeholders.
        if ( isset( $config['field_labels'] ) ) {
            $fields = $this->apply_field_labels( $fields, $config['field_labels'] );
        }

        // Apply field visibility.
        if ( isset( $config['hide_fields'] ) ) {
            $fields = $this->apply_hidden_fields( $fields, $config['hide_fields'] );
        }

        return $fields;
    }

    /**
     * Remove WooCommerce validation for fields that the variant removed.
     */
    private function unvalidate_removed_fields() {
        add_filter( 'woocommerce_checkout_posted_data', function ( $data ) {
            foreach ( $this->removed_field_keys as $key ) {
                // Handle both "billing_city" and "billing/city" formats.
                foreach ( array_keys( $data ) as $data_key ) {
                    if ( $data_key === $key || str_ends_with( $data_key, '_' . $key ) || str_contains( $data_key, $key ) ) {
                        unset( $data[ $data_key ] );
                    }
                }
            }
            return $data;
        }, 20 );

        add_filter( 'woocommerce_checkout_required_field_notice', function ( $message, $field_label ) {
            // If the field was removed by variant, suppress the required notice.
            foreach ( $this->removed_field_keys as $key ) {
                if ( false !== stripos( $field_label, $key ) ) {
                    return '';
                }
            }
            return $message;
        }, 20, 2 );

        // Filter out validation errors for removed fields.
        add_filter( 'woocommerce_checkout_process', function () {
            foreach ( $this->removed_field_keys as $key ) {
                add_filter( 'woocommerce_checkout_field_is_required', function ( $required, $field_key ) use ( $key ) {
                    if ( $field_key === $key || str_ends_with( $field_key, '_' . $key ) ) {
                        return false;
                    }
                    return $required;
                }, 20, 2 );
            }
        }, 5 );
    }

    /**
     * Maybe override the checkout template with a variant's custom template path.
     */
    public function maybe_override_template() {
        $config = $this->get_session_variant_config();

        if ( null === $config || ! isset( $config['template'] ) ) {
            return;
        }

        $template_key  = sanitize_key( $config['template'] );
        $custom_path   = get_option( "wac_template_path_{$template_key}", '' );
        $template_file = get_option( "wac_template_file_{$template_key}", '' );

        // Cap template path lengths to prevent buffer overflow in file operations.
        if ( strlen( $custom_path ) > 500 ) {
            $custom_path = substr( $custom_path, 0, 500 );
        }
        // Also validate template path does not contain parent directory traversal.
        if ( false !== strpos( $custom_path, '..' ) ) {
            $custom_path = '';
        }
        if ( false !== strpos( $template_file, '..' ) ) {
            $template_file = '';
        }
        if ( strlen( $template_file ) > 200 ) {
            $template_file = substr( $template_file, 0, 200 );
        }

        if ( empty( $custom_path ) && empty( $template_file ) ) {
            return;
        }

        // Validate template paths: ensure they resolve within the WP theme or plugin directory.
        // Cap template directory depth to prevent directory traversal.
        $wp_content_dir = WP_CONTENT_DIR;
        $template_dir    = get_template_directory();
        $stylesheet_dir  = get_stylesheet_directory();
        $safe_paths      = array( realpath( $wp_content_dir ), realpath( $template_dir ), realpath( $stylesheet_dir ), realpath( ABSPATH . 'wp-content' ) );

        add_filter( 'woocommerce_locate_template', function ( $template, $template_name, $template_path ) use ( $custom_path, $template_file, $template_key, $safe_paths ) {
            // Override checkout template if custom template specified.
            if ( ! empty( $custom_path ) && file_exists( $custom_path ) ) {
                $resolved = realpath( $custom_path );
                if ( false === $resolved ) {
                    return $template;
                }
                foreach ( $safe_paths as $allowed ) {
                    $allowed_resolved = realpath( $allowed );
                    if ( false !== $allowed_resolved && 0 === strpos( $resolved, $allowed_resolved ) ) {
                        return $custom_path;
                    }
                }
                return $template;
            }
            if ( ! empty( $template_file ) && $template_name === $template_file && file_exists( $template_file ) ) {
                $resolved = realpath( $template_file );
                if ( false === $resolved ) {
                    return $template;
                }
                foreach ( $safe_paths as $allowed ) {
                    $allowed_resolved = realpath( $allowed );
                    if ( false !== $allowed_resolved && 0 === strpos( $resolved, $allowed_resolved ) ) {
                        return $template_file;
                    }
                }
                return $template;
            }
            return $template;
        }, 10, 3 );
    }

    /**
     * Get merged variant config for current session across all active experiments.
     *
     * @return array|null
     */
    private function get_session_variant_config(): ?array {
        if ( null !== $this->session_config ) {
            return $this->session_config;
        }

        $variants = $this->ab ? $this->ab->get_session_variants() : array();

        if ( empty( $variants ) ) {
            $this->session_config = array();
            return null;
        }

        // Get the full variant config from AB test manager.
        $experiments = $this->ab ? $this->ab->get_active_experiments() : array();
        $merged      = array();
        $found_any   = false;

        foreach ( $experiments as $exp ) {
            $exp_name     = $exp['name'];
            $assigned_key = $variants[ $exp_name ] ?? null;
            if ( ! $assigned_key ) {
                continue;
            }

            foreach ( $exp['variants'] as $variant ) {
                if ( $variant['variant_key'] === $assigned_key ) {
                    $decoded = json_decode( $variant['config_snapshot'], true );
                    if ( is_array( $decoded ) ) {
                        // Merge config arrays (experiment-scoped keys like field_order, remove_fields merge).
                        foreach ( $decoded as $config_key => $config_value ) {
                            if ( isset( $merged[ $config_key ] ) && is_array( $merged[ $config_key ] ) && is_array( $config_value ) ) {
                                // Deep-merge arrays for cumulative configs.
                                $merged[ $config_key ] = array_merge( $merged[ $config_key ], $config_value );
                            } else {
                                $merged[ $config_key ] = $config_value;
                            }
                        }
                        $this->applied_experiments[ $exp_name ] = true;
                        $found_any = true;
                    }
                    break;
                }
            }
        }

        if ( ! $found_any ) {
            $this->session_config = array();
            return null;
        }

        $this->session_config = $merged;
        return $this->session_config;
    }

    /**
     * Apply custom field ordering.
     */
    private function apply_field_order( array $fields, array $order ): array {
        $ordered = array();

        // Validate section keys against known WooCommerce checkout sections.
        $known_sections = array( 'billing', 'shipping', 'account', 'order' );

        foreach ( $order as $section => $field_keys ) {
            if ( ! in_array( $section, $known_sections, true ) || ! isset( $fields[ $section ] ) ) {
                continue;
            }

            $ordered[ $section ] = array();
            $remaining = $fields[ $section ];

            foreach ( $field_keys as $key ) {
                if ( isset( $remaining[ $key ] ) ) {
                    $ordered[ $section ][ $key ] = $remaining[ $key ];
                    unset( $remaining[ $key ] );
                }
            }

            // Append any fields not in the custom order.
            foreach ( $remaining as $key => $field ) {
                $ordered[ $section ][ $key ] = $field;
            }
        }

        // Ensure all sections are present.
        foreach ( $fields as $section => $section_fields ) {
            if ( ! isset( $ordered[ $section ] ) ) {
                $ordered[ $section ] = $section_fields;
            }
        }

        return $ordered;
    }

    /**
     * Remove specified fields from checkout.
     */
    private function apply_field_removals( array $fields, array $remove_keys ): array {
        $this->removed_field_keys = array();

        // Validate keys: only alphanumeric, underscore, colon allowed.
        $remove_keys = array_filter( $remove_keys, function ( $key ) {
            return is_string( $key ) && 1 === preg_match( '/^[a-zA-Z0-9_:]+$/', $key );
        } );

        foreach ( $remove_keys as $key ) {
            // Key format could be "billing:city" or just "city" or "billing_city".
            foreach ( $fields as $section => &$section_fields ) {
                if ( isset( $section_fields[ $key ] ) ) {
                    unset( $section_fields[ $key ] );
                    $this->removed_field_keys[] = $key;
                }

                // Also check prefixed versions.
                $prefixed = $section . '_' . $key;
                if ( isset( $section_fields[ $prefixed ] ) ) {
                    unset( $section_fields[ $prefixed ] );
                    $this->removed_field_keys[] = $prefixed;
                }
            }
        }

        return $fields;
    }

    /**
     * Apply custom field labels.
     */
    private function apply_field_labels( array $fields, array $labels ): array {
        // Cap label modifications to prevent excessive field processing.
        $labels = array_slice( $labels, 0, 50 );
        foreach ( $labels as $field_key => $label ) {
            $safe_label = sanitize_text_field( $label );
            foreach ( $fields as $section => &$section_fields ) {
                if ( isset( $section_fields[ $field_key ] ) ) {
                    $section_fields[ $field_key ]['label'] = $safe_label;
                }
                $prefixed = $section . '_' . $field_key;
                if ( isset( $section_fields[ $prefixed ] ) ) {
                    $section_fields[ $prefixed ]['label'] = $safe_label;
                }
            }
        }

        return $fields;
    }

    /**
     * Hide fields (keep in DOM but hide with CSS).
     * Uses wp_add_inline_style if available for late-loading safety.
     */
    private function apply_hidden_fields( array $fields, array $hide_keys ): array {
        // Cap hidden fields at 20 to prevent excessive CSS generation.
        $hide_keys = array_slice( $hide_keys, 0, 20 );
        $css_rules = array();
        foreach ( $hide_keys as $key ) {
            $safe_key    = sanitize_html_class( $key );
            $css_rules[] = "#{$safe_key}_field { display: none !important; }";
        }

        if ( ! empty( $css_rules ) ) {
            $css = implode( "\n", $css_rules );

            // Prefer inline style enqueue (safe even if wp_head already fired).
            if ( function_exists( 'wp_add_inline_style' ) && wp_style_is( 'woocommerce-general', 'registered' ) ) {
                wp_add_inline_style( 'woocommerce-general', $css );
            } else {
                // Fallback: inject via wp_head.
                add_action( 'wp_head', function () use ( $css ) {
                    echo '<style id="wac-variant-hide">' . esc_html( $css ) . '</style>';
                }, 100 );
            }
        }

        return $fields;
    }
}
