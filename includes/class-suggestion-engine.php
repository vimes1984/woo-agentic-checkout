<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion Engine — manages LLM-generated checkout improvements.
 * Supports auto-apply (with permission checks) and manual admin approval.
 *
 * @since 0.1.0-alpha
 */
class SuggestionEngine {

    /**
     * Suggestion statuses.
     */
    const STATUS_PENDING  = 'pending';
    const STATUS_APPLIED  = 'applied';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ROLLED_BACK = 'rolled_back';

    /**
     * LLM client instance.
     *
     * @var LLMClient
     */
    private $llm;

    /**
     * Engine revision for tracking behaviour changes.
     */
    const REVISION = 'batch5.18';

    /**
     * @param LLMClient $llm
     *
     * @throws \InvalidArgumentException If $llm is null.
     */
    public function __construct( LLMClient $llm ) {
        if ( ! $llm ) {
            throw new \InvalidArgumentException( 'SuggestionEngine requires a valid LLMClient instance.' );
        }
        $this->llm = $llm;
    }

    /**
     * Generate suggestions using LLM based on collected signals.
     *
     * @param array $context Context data (signals, experiments, errors, etc.)
     *
     * @return array Generated suggestions.
     * @throws \RuntimeException If LLM communication fails.
     */
    public function generate_suggestions( array $context ): array {
        $system_prompt = $this->build_system_prompt();
        $user_prompt   = $this->build_user_prompt( $context );
        $schema        = $this->get_suggestion_schema();

        // Cold start / no-data guard — if context shows no orders and no traffic.
        $meta = $context['_meta'] ?? array();
        if ( ! empty( $meta['is_cold_start'] ) ) {
            do_action( 'wac_log_info', 'suggestion_cold_start', 'No data available — skipping LLM generation.' );
            return array();
        }

        try {
            $response = $this->llm->analyze( $system_prompt, $user_prompt, $schema );
            $suggestions = $response['suggestions'] ?? array();

            $saved = array();
            foreach ( $suggestions as $suggestion ) {
                // Normalize score to 0–1 range.
                $suggestion['score'] = $this->normalize_score( $suggestion['score'] ?? 0.5 );

                // Deduplicate: skip if a pending suggestion with same title + action_type already exists.
                if ( $this->suggestion_exists( $suggestion['title'] ?? '', $suggestion['action_type'] ?? '' ) ) {
                    continue;
                }

                $saved[] = $this->save_suggestion( $suggestion );
            }

            return $saved;
        } catch ( \Exception $e ) {
            do_action( 'wac_log_error', 'suggestion_generation_failed', $e->getMessage() );
            return array();
        }
    }

    /**
     * Check if a suggestion with similar title + action_type already exists (pending state).
     *
     * @param string $title
     * @param string $action_type
     * @return bool
     */
    private function suggestion_exists( string $title, string $action_type ): bool {
        if ( empty( $title ) || empty( $action_type ) ) {
            return false;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wac_suggestions
             WHERE status = 'pending'
             AND action_type = %s
             AND title = %s",
            $action_type,
            $title
        ) );
        return $count > 0;
    }

    /**
     * Get pending suggestions.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_pending( int $limit = 20 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wac_suggestions WHERE status = %s ORDER BY score DESC LIMIT %d",
            self::STATUS_PENDING,
            $limit
        ), ARRAY_A );

        // Strict type casting for numeric fields.
        foreach ( $rows as &\$row ) {
            \$row["id"] = (int) \$row["id"];
            \$row["score"] = (float) \$row["score"];
        }
        unset( \$row );

        return \$rows;
    }

    /**
     * Get pending count.
     *
     * @return int
     */
    public function get_pending_count(): int {
        // Request-level cache — pending count queried multiple times per page load.
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $cached = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wac_suggestions WHERE status = %s",
            self::STATUS_PENDING
        ) );
        return $cached;
    }

    /**
     * Apply a suggestion by ID.
     *
     * @param int $id
     *
     * @return bool|\WP_Error
     */
    public function apply_suggestion( int $id ) {
        global $wpdb;

        if ( $id < 1 ) {
            return new \WP_Error( 'invalid_id', 'Suggestion ID must be a positive integer.' );
        }

        $suggestion = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wac_suggestions WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $suggestion ) {
            return new \WP_Error( 'not_found', 'Suggestion not found.' );
        }

        if ( self::STATUS_APPLIED === $suggestion['status'] ) {
            return new \WP_Error( 'already_applied', 'Suggestion already applied.' );
        }

        if ( self::STATUS_ROLLED_BACK === $suggestion['status'] ) {
            return new \WP_Error( 'rolled_back', 'Suggestion has been rolled back and cannot be applied again.' );
        }

        $action_data = json_decode( $suggestion['action_data'], true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new \WP_Error( 'invalid_action_data', 'Action data is not valid JSON: ' . json_last_error_msg() );
        }

        // Execute the action.
        $success = $this->execute_action( $suggestion['action_type'], $action_data );

        if ( $success ) {
            $wpdb->update(
                $wpdb->prefix . 'wac_suggestions',
                array(
                    'status'     => self::STATUS_APPLIED,
                    'applied_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            do_action( 'wac_suggestion_applied', $id, $suggestion['action_type'] );

            return true;
        }

        return new \WP_Error( 'apply_failed', 'Failed to apply suggestion.' );
    }

    /**
     * Auto-apply a suggestion if confidence and permission levels allow.
     *
     * @param array $suggestion
     * @param string $permission Current permission mode.
     *
     * @return bool
     */
    public function auto_apply_if_allowed( array $suggestion, string $permission = 'suggest' ): bool {
        $confidence = $suggestion['score'] ?? 0;

        if ( $confidence < 0.9 ) {
            return false;
        }

        if ( 'auto_patch' !== $permission && 'auto_full' !== $permission ) {
            return false;
        }

        $result = $this->apply_suggestion( $suggestion['id'] );
        return ! is_wp_error( $result );
    }

    /**
     * Reject a suggestion.
     *
     * @param int    $id
     * @param string $reason Optional rejection reason.
     *
     * @return bool True if rejection succeeded.
     */
    public function reject_suggestion( int $id, string $reason = '' ): bool {
        global $wpdb;

        if ( $id < 1 ) {
            return false;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'wac_suggestions',
            array(
                'status'        => self::STATUS_REJECTED,
                'reject_reason' => sanitize_text_field( $reason ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated ) {
            do_action( 'wac_suggestion_rejected', $id, $reason );
        }

        return (bool) $updated;
    }

    /**
     * Save a generated suggestion to the database.
     *
     * @param array $suggestion
     *
     * @return array
     */
    /**
     * Normalize a suggestion score to the 0–1 range.
     * Handles percentage strings ("85%"), raw floats >1, and null values.
     *
     * @param mixed $score Raw score from LLM.
     * @return float Normalized 0.0–1.0.
     */
    private function normalize_score( $score ): float {
        if ( is_string( $score ) && strpos( $score, '%' ) !== false ) {
            $score = (float) str_replace( '%', '', $score ) / 100;
        }
        $score = (float) $score;
        if ( $score > 1.0 ) {
            $score = $score / 100; // Assume it's a percentage.
        }
        return max( 0.0, min( 1.0, $score ) );
    }

    /**
     * Save a generated suggestion to the database.
     *
     * @param array $suggestion
     *
     * @return array
     */
    private function save_suggestion( array $suggestion ): array {
        global $wpdb;

        $title = $suggestion['title'] ?? 'Untitled Suggestion';
        $description = $suggestion['description'] ?? '';

        // Validate max lengths to match DB schema limits.
        if ( strlen( $title ) > 255 ) {
            $title = substr( $title, 0, 255 );
        }
        if ( strlen( $description ) > 10000 ) {
            $description = substr( $description, 0, 10000 );
        }

        $score = $this->normalize_score( $suggestion['score'] ?? 0.5 );

        $data = array(
            'title'       => sanitize_text_field( $title ),
            'description' => sanitize_textarea_field( $suggestion['description'] ?? '' ),
            'action_type' => sanitize_key( $suggestion['action_type'] ?? 'css' ),
            'action_data' => wp_json_encode( $suggestion['action_data'] ?? array() ),
            'score'       => $score,
            'expected_lift' => isset( $suggestion['expected_lift'] ) ? min( 100.0, max( -100.0, (float) $suggestion['expected_lift'] ) ) : null,
            'category'    => sanitize_key( $suggestion['category'] ?? 'general' ),
            'status'      => self::STATUS_PENDING,
            'created_at'  => current_time( 'mysql' ),
        );

        $wpdb->insert( $wpdb->prefix . 'wac_suggestions', $data );

        $data['id'] = $wpdb->insert_id;
        return $data;
    }

    /**
     * Execute a suggestion action.
     *
     * @param string $action_type
     * @param array  $action_data
     *
     * @return bool
     */
    private function execute_action( string $action_type, array $action_data ): bool {
        switch ( $action_type ) {
            case 'css':
                return $this->apply_css_patch( $action_data );

            case 'javascript':
                return $this->apply_js_patch( $action_data );

            case 'field_reorder':
                return $this->reorder_fields( $action_data );

            case 'field_remove':
                return $this->remove_field( $action_data );

            case 'template_override':
                return $this->set_template_override( $action_data );

            case 'setting_change':
                return $this->change_setting( $action_data );

            case 'experiment':
                return $this->create_experiment_from_suggestion( $action_data );

            default:
                return false;
        }
    }

    /**
     * Apply a CSS patch.
     */
    private function apply_css_patch( array $data ): bool {
        // Sanitize CSS: strip any HTML tags, allow only safe CSS characters.
        $css = $data['css'] ?? '';
        $css = wp_strip_all_tags( $css );
        if ( empty( $css ) || strlen( $css ) > 50000 ) {
            return false;
        }

        $patches = get_option( 'wac_css_patches', array() );
        $patches[] = array(
            'css'       => $css,
            'selector'  => sanitize_text_field( $data['selector'] ?? '' ),
            'added_at'  => current_time( 'mysql' ),
        );
        update_option( 'wac_css_patches', $patches );
        return true;
    }

    /**
     * Apply a JavaScript patch.
     */
    private function apply_js_patch( array $data ): bool {
        $js = $data['javascript'] ?? '';
        if ( empty( $js ) || strlen( $js ) > 50000 ) {
            return false;
        }

        $patches = get_option( 'wac_js_patches', array() );
        $patches[] = array(
            'js'       => $js,
            'added_at' => current_time( 'mysql' ),
        );
        update_option( 'wac_js_patches', $patches );
        return true;
    }

    /**
     * Reorder checkout fields.
     */
    private function reorder_fields( array $data ): bool {
        $field_order = $data['field_order'] ?? array();
        if ( empty( $field_order ) ) {
            return false;
        }

        // Sanitize each field key in the order array.
        $field_order = array_map( 'sanitize_key', $field_order );
        $field_order = array_values( array_filter( $field_order ) );

        update_option( 'wac_field_order', $field_order );
        return true;
    }

    /**
     * Remove a checkout field.
     */
    private function remove_field( array $data ): bool {
        $field = isset( $data['field'] ) ? sanitize_key( $data['field'] ) : '';
        if ( empty( $field ) ) {
            return false;
        }

        $removed = get_option( 'wac_removed_fields', array() );
        if ( ! is_array( $removed ) ) {
            $removed = array();
        }
        if ( ! in_array( $field, $removed, true ) ) {
            $removed[] = $field;
            update_option( 'wac_removed_fields', $removed );
        }
        return true;
    }

    /**
     * Set a template override.
     */
    private function set_template_override( array $data ): bool {
        $template = isset( $data['template'] ) ? sanitize_key( $data['template'] ) : '';
        $content  = isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '';
        if ( empty( $template ) || empty( $content ) ) {
            return false;
        }

        update_option( "wac_template_{$template}", $content );
        return true;
    }

    /**
     * Change a plugin/WooCommerce setting.
     */
    private function change_setting( array $data ): bool {
        $option = isset( $data['option'] ) ? sanitize_key( $data['option'] ) : '';
        $value  = isset( $data['value'] ) ? sanitize_text_field( $data['value'] ) : '';
        if ( empty( $option ) ) {
            return false;
        }

        // Only allow changes to wac_* prefixed options for security.
        if ( ! str_starts_with( $option, 'wac_' ) ) {
            return false;
        }

        // Save previous value for rollback.
        $rollbacks = get_option( 'wac_setting_rollbacks', array() );
        $rollbacks[ $option ] = get_option( $option, '' );
        update_option( 'wac_setting_rollbacks', $rollbacks );

        update_option( $option, $value );
        return true;
    }

    /**
     * Create an A/B experiment from a suggestion.
     */
    private function create_experiment_from_suggestion( array $data ): bool {
        $ab_manager = new ABTestManager();

        $experiment_id = $ab_manager->create_experiment(
            $data['name'] ?? 'Auto-generated experiment',
            $data['description'] ?? 'Suggested by Agentic Checkout AI',
            $data['variants'] ?? array(),
            $data['traffic_pct'] ?? 50
        );

        return $experiment_id > 0;
    }

    // ─── Prompt Builders ─────────────────────────────────────────

    /**
     * Build the system prompt for suggestion generation.
     */
    private function build_system_prompt(): string {
        return <<<PROMPT
You are a WooCommerce conversion optimisation expert. Analyse the provided checkout
signals and suggest concrete improvements. Each suggestion must be actionable —
something that can be automatically applied.

Output ONLY valid JSON matching this exact schema:
{
  "suggestions": [
    {
      "title": "Short descriptive title",
      "description": "Why this will help (with data reference)",
      "action_type": "css|javascript|field_reorder|field_remove|template_override|setting_change|experiment",
      "action_data": {},
      "score": 0.85,
      "expected_lift": "+2.5%",
      "category": "layout|fields|performance|trust|accessibility"
    }
  ]
}

Focus on high-impact, low-effort changes first. Consider mobile responsiveness,
field reduction, trust signals, loading speed, and checkout flow simplicity.

If the context shows zero orders, zero traffic, and zero errors (cold start),
return an empty suggestions array.
PROMPT;
    }

    /**
     * Build the user prompt with current context data.
     */
    private function build_user_prompt( array $context ): string {
        $json = wp_json_encode( $context, JSON_PRETTY_PRINT );
        return "Current store context:\n\n{$json}\n\nGenerate 3-5 checkout improvement suggestions.";
    }

    /**
     * JSON schema for structured output.
     */
    private function get_suggestion_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'suggestions' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'title'         => array( 'type' => 'string' ),
                            'description'   => array( 'type' => 'string' ),
                            'action_type'   => array( 'type' => 'string' ),
                            'action_data'   => array( 'type' => 'object' ),
                            'score'         => array( 'type' => 'number' ),
                            'expected_lift' => array( 'type' => 'string' ),
                            'category'      => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'title', 'description', 'action_type', 'action_data', 'score' ),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required'   => array( 'suggestions' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get all suggestions (with optional status filter).
     *
     * @param string $status Status filter.
     * @param int    $limit  Max results.
     *
     * @return array
     */
    public function get_suggestions( string $status = '', int $limit = 50 ): array {
        global $wpdb;

        $sql  = "SELECT * FROM {$wpdb->prefix}wac_suggestions";
        $args = array();

        if ( ! empty( $status ) ) {
            $sql  .= ' WHERE status = %s';
            $args[] = $status;
        }

        $sql  .= ' ORDER BY score DESC, created_at DESC LIMIT %d';
        $args[] = absint( $limit );

        return $wpdb->get_results(
            $wpdb->prepare( $sql, $args ),
            ARRAY_A
        );
    }
}
