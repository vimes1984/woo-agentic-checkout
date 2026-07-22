<?php
namespace WooAgenticCheckout\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * AB Optimizer Agent
 *
 * Analyses running A/B experiments, computes Bayesian probabilities,
 * suggests winning variants, and proposes new experiments.
 * Runs every 6 hours or when triggered.
 *
 * @since 0.1.0-alpha
 */
class ABOptimizer {

    /**
     * Agent revision for tracking prompt/behaviour changes.
     */
    const REVISION = 'batch5.12';

    /**
     * @var array Service dependencies.
     */
    private $services;

    /**
     * @param array $services
     */
    public function __construct( array $services ) {
        $this->services = $services;
    }

    /**
     * Agent label.
     *
     * @return string
     */
    public function get_label(): string {
        return 'A/B Optimizer';
    }

    /**
     * Agent capabilities for introspection.
     *
     * @return array
     */
    public function get_capabilities(): array {
        return array(
            'id'             => 'ab_optimizer',
            'label'          => $this->get_label(),
            'revision'       => self::REVISION,
            'llm_dependent'  => true,
            'schedule'       => 'every_6_hours',
            'data_sources'   => array( 'experiments', 'orders', 'funnel' ),
            'description'    => 'Analyses running A/B experiments, suggests winning variants, and proposes new experiments.',
        );
    }

    /**
     * Execute agent run.
     *
     * @return array Standardised result (success, actions, errors, summary).
     */
    public function run(): array {
        // Process lock: prevent concurrent runs to avoid race conditions.
        $lock_key = 'wac_ab_optimizer_lock';
        if ( get_transient( $lock_key ) ) {
            return array(
                'success' => true,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'AB Optimizer is already running (process lock active).',
            );
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );
        register_shutdown_function( function () use ( $lock_key ) {
            delete_transient( $lock_key );
        } );

        $ab      = $this->services['ab'] ?? null;
        $llm     = $this->services['llm'] ?? null;
        $logger  = $this->services['logger'] ?? null;
        $signals = $this->services['signals'] ?? null;

        if ( ! $ab || ! $logger ) {
            delete_transient( $lock_key );
            return array(
                'success' => false,
                'actions' => 0,
                'errors'  => array( 'Missing required services: AB or Logger.' ),
                'summary' => 'Missing required services.',
            );
        }

        $results = array(
            'experiments_analysed' => 0,
            'winners_declared'     => 0,
            'new_experiments_proposed' => 0,
            'recommendations'      => array(),
        );

        // Get active experiments.
        $experiments = $ab->get_active_experiments();

        // Guard: ensure we always have a settings reference (may be null from cold instantiation).
        $settings = $this->services['settings'] ?? null;

        // Guard: if no experiments, log and return gracefully.
        if ( empty( $experiments ) || ! is_array( $experiments ) ) {
            $logger->info( 'ab_optimizer_no_experiments', array(
                'note' => 'No active experiments to analyse.',
            ) );
            return array(
                'success'    => true,
                'actions'    => 0,
                'errors'     => array(),
                'summary'    => 'No active experiments to analyse.',
                'experiments_analysed' => 0,
                'winners_declared'     => 0,
                'new_experiments_proposed' => 0,
                'recommendations'      => array(),
            );
        }

        foreach ( $experiments as $exp ) {
            // Validate experiment structure.
            if ( ! isset( $exp['id'] ) ) {
                $logger->warning( 'ab_optimizer_missing_exp_id', array( 'exp' => $exp ) );
                continue;
            }

            $variants = $ab->get_variants( $exp['id'] );
            $bayesian = $ab->bayesian_analysis( $exp['id'] );

            // Guard: validate variants + bayesian return type.
            $variants_ok = is_array( $variants ) && ! empty( $variants );
            $bayesian_ok = is_array( $bayesian );

            if ( ! $variants_ok ) {
                $logger->info( 'ab_optimizer_no_variants', array(
                    'exp_id' => $exp['id'],
                ) );
                continue;
            }

            if ( ! $bayesian_ok ) {
                $logger->warning( 'ab_optimizer_invalid_bayesian', array(
                    'exp_id' => $exp['id'],
                ) );
                continue;
            }

            $results['experiments_analysed']++;

            // Check if any variant has enough data for a decision.
            foreach ( $bayesian as $b ) {
                $confidence_threshold = $settings ? (float) $settings->get( 'ab_confidence_threshold', 0.95 ) : 0.95;
                $min_conversions      = $settings ? (int) $settings->get( 'ab_min_conversions', 30 ) : 30;

                if ( $b['prob_better'] >= ( $confidence_threshold * 100 ) ) {

                    if ( $b['conversions'] >= $min_conversions ) {
                        $ab->declare_winner( $exp['id'], $b['variant_key'] );
                        $results['winners_declared']++;
                        $results['recommendations'][] = array(
                            'experiment' => $exp['name'],
                            'action'     => 'declared_winner',
                            'variant'    => $b['variant_name'],
                            'confidence' => $b['prob_better'],
                            'lift'       => $b['lift'],
                        );
                        continue 2; // Move to next experiment.
                    }
                }
            }

            // Check if experiment should be concluded (no significant difference after enough data).
            $max_impressions = 0;
            foreach ( $variants as $v ) {
                $max_impressions = max( $max_impressions, (int) $v['impressions'] );
            }

            $min_sample = $settings ? (int) $settings->get( 'ab_min_sample_size', 100 ) : 100;
            if ( $max_impressions >= $min_sample * count( $variants ) ) {
                // Check if all variants have similar conversion rates (within 5% relative).
                $crs = array_filter( array_column( $bayesian, 'cr' ), function ( $v ) {
                    return is_numeric( $v );
                } );
                // Guard against empty/zero CRs (needs at least 2 variants with data).
                if ( count( $crs ) < 2 ) {
                    continue;
                }
                $max_cr = max( $crs );
                $min_cr = min( $crs );

                if ( $max_cr > 0 && ( ( $max_cr - $min_cr ) / $max_cr ) < 0.05 ) {
                    // No clear winner — propose conclusion or new hypothesis.
                    $ab->conclude_experiment( $exp['id'] );
                    $results['recommendations'][] = array(
                        'experiment' => $exp['name'],
                        'action'     => 'concluded_no_winner',
                        'note'       => 'No statistically significant difference found.',
                    );
                }
            }
        }

        // Propose new experiment if space available.
        $max_concurrent = $settings ? (int) $settings->get( 'ab_max_concurrent', 3 ) : 3;
        $active_count   = is_countable( $experiments ) ? count( $experiments ) : 0;

        if ( $active_count < $max_concurrent ) {
            $new_exp = $this->propose_next_experiment();
            if ( $new_exp ) {
                $results['new_experiments_proposed'] = 1;
                $results['recommendations'][] = $new_exp;

                // If auto_patch or higher, auto-create the experiment.
                $permission = $settings->get_heal_permission();
                if ( in_array( $permission, array( 'auto_patch', 'auto_full' ), true ) ) {
                    // Sanitize LLM output before creating experiment (prompt injection defense).
                    $exp_name        = sanitize_text_field( $new_exp['name'] );
                    $exp_description = sanitize_textarea_field( $new_exp['description'] );
                    $exp_traffic     = min( 100, max( 10, absint( $new_exp['traffic_pct'] ?? 50 ) ) );
                    $exp_variants    = array();
                    foreach ( $new_exp['variants'] as $v ) {
                        $exp_variants[] = array(
                            'key'             => sanitize_key( $v['key'] ?? '' ),
                            'name'            => sanitize_text_field( $v['name'] ?? '' ),
                            'traffic_percent' => min( 100, max( 0, absint( $v['traffic_percent'] ?? 50 ) ) ),
                            'config'          => $v['config'] ?? array(),
                        );
                    }
                    $ab->create_experiment(
                        $exp_name,
                        $exp_description,
                        $exp_variants,
                        $exp_traffic
                    );
                }
            }
        }

        $logger->info( 'ab_optimizer_run', $results );

        // Normalize to standardized result format.
        return array(
            'success'    => true,
            'actions'    => $results['experiments_analysed'] + $results['new_experiments_proposed'],
            'errors'     => array(),
            'summary'    => $results['experiments_analysed'] . ' experiments analysed, '
                          . $results['winners_declared'] . ' winners declared, '
                          . $results['new_experiments_proposed'] . ' new experiments proposed.',
        ) + $results;
    }

    /**
     * Propose a new experiment based on current data.
     *
     * @return array|null Experiment proposal or null.
     */
    private function propose_next_experiment(): ?array {
        $llm    = $this->services['llm'];
        $signals = $this->services['signals'];

        $recent_orders = $signals->get_recent_orders( 168 );
        $funnel        = $signals->get_funnel_data( 24 );

        // Cold start guard — no data to base an experiment on.
        $has_data = false;
        if ( is_array( $recent_orders ) && isset( $recent_orders['total_orders'] ) && (int) $recent_orders['total_orders'] >= 5 ) {
            $has_data = true;
        }
        if ( ! $has_data && is_array( $funnel ) && ! empty( $funnel ) ) {
            foreach ( $funnel as $step => $count ) {
                if ( (int) $count > 0 ) {
                    $has_data = true;
                    break;
                }
            }
        }
        if ( ! $has_data ) {
            $this->services['logger']->info( 'ab_no_data_for_experiment', array(
                'note' => 'Insufficient data to propose experiment (cold start).',
            ) );
            return null;
        }

        $system = <<<'PROMPT'
You are an A/B testing expert for WooCommerce. Based on the current checkout data,
propose ONE new experiment that has the highest potential to improve conversion rate.

Focus on:
1. The biggest funnel drop-off point
2. Industry best practices for checkout optimisation
3. Changes that can be implemented as a checkout field/variant experiment

Provide: experiment name, hypothesis, description, and 2-3 variants (including control)
with specific config snapshots for each.

Output ONLY valid JSON matching this exact schema:
{
  "name": "Experiment title",
  "hypothesis": "If we do X, then Y will happen because Z",
  "description": "Detailed experiment description",
  "traffic_pct": 50,
  "variants": [
    {
      "key": "control",
      "name": "Current Checkout",
      "traffic_percent": 50,
      "config": {}
    },
    {
      "key": "variant_a",
      "name": "Simplified Checkout",
      "traffic_percent": 50,
      "config": {
        "field_order": ["billing_first_name", "billing_email", ...]
      }
    }
  ]
}

If the checkout data shows zero orders or empty funnel, return name "insufficient_data" and hypothesis "Not enough data to propose a meaningful experiment yet."
PROMPT;

        try {
            $result = $llm->analyze( $system, wp_json_encode( array(
                'orders_7d'  => $recent_orders,
                'funnel'     => $funnel,
                'currency'   => get_woocommerce_currency(),
            ) ), $this->get_experiment_schema() );

            // Validate the result has required fields.
            if ( ! isset( $result['name'], $result['variants'] ) || count( $result['variants'] ) < 2 ) {
                return null;
            }

            return $result;
        } catch ( \Exception $e ) {
            return null;
        }
    }

    private function get_experiment_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'        => array( 'type' => 'string' ),
                'hypothesis'  => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'traffic_pct' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 100 ),
                'variants'    => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'key'             => array( 'type' => 'string' ),
                            'name'            => array( 'type' => 'string' ),
                            'traffic_percent' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 100 ),
                            'config'          => array( 'type' => 'object' ),
                        ),
                        'required'   => array( 'key', 'name', 'traffic_percent', 'config' ),
                        'additionalProperties' => false,
                    ),
                    'minItems' => 2,
                ),
            ),
            'required'   => array( 'name', 'hypothesis', 'description', 'variants' ),
            'additionalProperties' => false,
        );
    }
}
