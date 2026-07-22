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
     * Cached variant config for the current session.
     *
     * @var array|null
     */
    private $session_config = null;

    /**
     * @param ABTestManager $ab
     */
    public function __construct( ABTestManager $ab ) {
        $this->ab = $ab;
    }

    /**
     * Modify checkout fields based on active experiment variant.
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
     * Maybe override the checkout template.
     */
    public function maybe_override_template() {
        $config = $this->get_session_variant_config();

        if ( null === $config || ! isset( $config['template'] ) ) {
            return;
        }

        $template_name = $config['template'];
        $custom_template = get_option( "wac_template_{$template_name}", '' );

        if ( ! empty( $custom_template ) ) {
            add_filter( 'woocommerce_locate_template', function ( $template, $template_name, $template_path ) use ( $custom_template ) {
                // Not overriding core WooCommerce templates, just our custom ones.
                return $template;
            }, 10, 3 );
        }
    }

    /**
     * Get variant config for current session.
     *
     * @return array|null
     */
    private function get_session_variant_config(): ?array {
        if ( null !== $this->session_config ) {
            return $this->session_config;
        }

        $variants = $this->ab->get_session_variants();

        if ( empty( $variants ) ) {
            $this->session_config = array();
            return null;
        }

        // Get the full variant config from AB test manager.
        $experiments = $this->ab->get_active_experiments();

        foreach ( $experiments as $exp ) {
            $assigned_key = $variants[ $exp['name'] ] ?? null;
            if ( $assigned_key ) {
                foreach ( $exp['variants'] as $variant ) {
                    if ( $variant['variant_key'] === $assigned_key ) {
                        $decoded = json_decode( $variant['config_snapshot'], true );
                        if ( is_array( $decoded ) ) {
                            $this->session_config = $decoded;
                            return $this->session_config;
                        }
                    }
                }
            }
        }

        $this->session_config = array();
        return null;
    }

    /**
     * Apply custom field ordering.
     */
    private function apply_field_order( array $fields, array $order ): array {
        $ordered = array();

        foreach ( $order as $section => $field_keys ) {
            if ( ! isset( $fields[ $section ] ) ) {
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
        foreach ( $remove_keys as $key ) {
            // Key format could be "billing:city" or just "city" or "billing_city".
            foreach ( $fields as $section => &$section_fields ) {
                if ( isset( $section_fields[ $key ] ) ) {
                    unset( $section_fields[ $key ] );
                }

                // Also check prefixed versions.
                $prefixed = $section . '_' . $key;
                if ( isset( $section_fields[ $prefixed ] ) ) {
                    unset( $section_fields[ $prefixed ] );
                }
            }
        }

        return $fields;
    }

    /**
     * Apply custom field labels.
     */
    private function apply_field_labels( array $fields, array $labels ): array {
        foreach ( $labels as $field_key => $label ) {
            foreach ( $fields as $section => &$section_fields ) {
                if ( isset( $section_fields[ $field_key ] ) ) {
                    $section_fields[ $field_key ]['label'] = $label;
                }
                $prefixed = $section . '_' . $field_key;
                if ( isset( $section_fields[ $prefixed ] ) ) {
                    $section_fields[ $prefixed ]['label'] = $label;
                }
            }
        }

        return $fields;
    }

    /**
     * Hide fields (keep in DOM but hide with CSS).
     */
    private function apply_hidden_fields( array $fields, array $hide_keys ): array {
        $css_rules = array();
        foreach ( $hide_keys as $key ) {
            $css_rules[] = "#{$key}_field { display: none !important; }";
        }

        if ( ! empty( $css_rules ) ) {
            add_action( 'wp_head', function () use ( $css_rules ) {
                echo '<style id="wac-variant-hide">' . implode( "\n", $css_rules ) . '</style>';
            }, 100 );
        }

        return $fields;
    }
}
