<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Agent Manager — orchestrates all agent lifecycle, scheduling, and execution.
 *
 * @since 0.1.0-alpha
 */
class AgentManager {

    /**
     * Registered agent instances.
     *
     * @var array<string, object>
     */
    private $agents = array();

    /**
     * Service dependencies.
     *
     * @var array
     */
    private $services;

    /**
     * Tracks currently running agents to prevent concurrent runs.
     *
     * @var array<string, bool>
     */
    private $running_agents = array();

    /**
     * Consecutive failure counts per agent.
     *
     * @var array<string, int>
     */
    private $failure_counts = array();

    /**
     * Alert threshold for consecutive failures.
     */
    const FAILURE_ALERT_THRESHOLD = 3;

    /**
     * @param LLMClient         $llm
     * @param SignalCollector   $signals
     * @param ABTestManager     $ab
     * @param SelfHealer        $healer
     * @param SuggestionEngine  $suggest
     * @param Settings          $settings
     * @param Logger            $logger
     */
    public function __construct(
        $llm,
        $signals,
        $ab,
        $healer,
        $suggest,
        $settings,
        $logger
    ) {
        $this->services = compact( 'llm', 'signals', 'ab', 'healer', 'suggest', 'settings', 'logger' );
        $this->failure_counts = get_option( 'wac_agent_failure_counts', array() );
        $this->register_agents();
    }

    /**
     * Register all available agents.
     */
    private function register_agents() {
        $this->agents['conversion_analyzer'] = new \WooAgenticCheckout\Agents\ConversionAnalyzer( $this->services );
        $this->agents['ab_optimizer']        = new \WooAgenticCheckout\Agents\ABOptimizer( $this->services );
        $this->agents['error_detector']      = new \WooAgenticCheckout\Agents\ErrorDetector( $this->services );
        $this->agents['suggestion_generator'] = new \WooAgenticCheckout\Agents\SuggestionGenerator( $this->services );
        $this->agents['self_healing']        = new \WooAgenticCheckout\Agents\SelfHealingAgent( $this->services );
    }

    /**
     * Run one or more agents by key.
     * Includes: concurrent run guard, consecutive failure tracking + alert,
     * error bubbling with unified logging, and standardized result format.
     *
     * @param array $agent_keys List of agent keys to run.
     * @return array<string, array> Results keyed by agent key.
     */
    public function run_agents( array $agent_keys ) {
        $results = array();

        foreach ( $agent_keys as $key ) {
            if ( ! isset( $this->agents[ $key ] ) ) {
                $this->services['logger']->warning( 'agent_manager', "Unknown agent: {$key}" );
                $results[ $key ] = array(
                    'success' => false,
                    'actions' => 0,
                    'errors'  => array( "Unknown agent: {$key}" ),
                    'summary' => "Agent '{$key}' not found.",
                );
                continue;
            }

            // Check if agent is enabled in settings.
            if ( ! $this->services['settings']->is_agent_enabled( $key ) ) {
                continue;
            }

            // ── Concurrent run guard ────────────────────────────────
            if ( isset( $this->running_agents[ $key ] ) && true === $this->running_agents[ $key ] ) {
                $this->services['logger']->warning( 'agent_manager', "Agent {$key} is already running — skipping." );
                $results[ $key ] = array(
                    'success' => false,
                    'actions' => 0,
                    'errors'  => array( "Agent '{$key}' already running (concurrent run prevented)." ),
                    'summary' => "Skipped: {$key} is already running.",
                );
                continue;
            }

            $this->running_agents[ $key ] = true;

            try {
                $start  = microtime( true );
                $result = $this->agents[ $key ]->run();
                $elapsed = microtime( true ) - $start;

                // Normalize result to standardized format.
                $result = $this->normalize_result( $key, $result );

                $this->services['logger']->info( 'agent_run', array(
                    'agent'   => $key,
                    'elapsed' => round( $elapsed, 4 ),
                    'result'  => $result,
                ) );

                // Track success — reset failure count.
                $this->failure_counts[ $key ] = 0;
                $this->persist_failure_counts();

                $results[ $key ] = $result;

                /**
                 * Fires after an agent completes its run.
                 *
                 * @param string $key    Agent key.
                 * @param mixed  $result Agent return data.
                 */
                do_action( "wac_agent_{$key}_complete", $result );
            } catch ( \Exception $e ) {
                // ── Consecutive failure tracking ────────────────────
                $this->failure_counts[ $key ] = ( $this->failure_counts[ $key ] ?? 0 ) + 1;
                $this->persist_failure_counts();

                $fail_count = $this->failure_counts[ $key ];

                $this->services['logger']->error( 'agent_failed', array(
                    'agent'          => $key,
                    'error'          => $e->getMessage(),
                    'consecutive_fails' => $fail_count,
                    'trace'          => $e->getTraceAsString(),
                ) );

                // ── Alert if threshold exceeded ─────────────────────
                if ( $fail_count >= self::FAILURE_ALERT_THRESHOLD ) {
                    do_action( 'wac_agent_consecutive_failures', $key, $fail_count );
                    $this->services['logger']->critical( 'agent_consecutive_failures', array(
                        'agent'  => $key,
                        'count'  => $fail_count,
                        'action' => 'Manual intervention may be required.',
                    ) );
                }

                $results[ $key ] = array(
                    'success'          => false,
                    'actions'          => 0,
                    'errors'           => array( $e->getMessage() ),
                    'summary'          => "Agent '{$key}' failed after " . round( $elapsed ?? 0, 4 ) . 's: ' . $e->getMessage(),
                    'consecutive_fails' => $fail_count,
                );
            } finally {
                $this->running_agents[ $key ] = false;
            }
        }

        return $results;
    }

    /**
     * Normalize agent result to standardized format with success/actions/errors/summary.
     *
     * @param string $key    Agent key.
     * @param mixed  $result Raw result from agent.
     * @return array Normalized result.
     */
    private function normalize_result( string $key, $result ): array {
        if ( ! is_array( $result ) ) {
            return array(
                'success' => true,
                'actions' => 0,
                'errors'  => array(),
                'summary' => 'Agent completed with non-array result.',
                'raw'     => $result,
            );
        }

        if ( isset( $result['success'], $result['actions'], $result['errors'], $result['summary'] ) ) {
            return $result; // Already normalized.
        }

        $normalized = $result;
        $normalized['success'] = $result['success'] ?? ! isset( $result['error'] );
        $normalized['actions'] = $result['actions'] ?? 0;
        $normalized['errors']  = $result['errors'] ?? array();
        if ( isset( $result['error'] ) ) {
            $normalized['errors'][] = $result['error'];
            unset( $normalized['error'] );
        }
        $normalized['summary'] = $result['summary'] ?? ($normalized['success'] ? "Agent '{$key}' completed." : "Agent '{$key}' encountered errors.");

        return $normalized;
    }

    /**
     * Persist failure counts to options table.
     */
    private function persist_failure_counts(): void {
        update_option( 'wac_agent_failure_counts', $this->failure_counts, false );
    }

    /**
     * Get consecutive failure count for a specific agent.
     *
     * @param string $key Agent key.
     * @return int
     */
    public function get_failure_count( string $key ): int {
        return $this->failure_counts[ $key ] ?? 0;
    }

    /**
     * Reset failure count for an agent.
     *
     * @param string $key Agent key.
     */
    public function reset_failure_count( string $key ): void {
        unset( $this->failure_counts[ $key ] );
        $this->persist_failure_counts();
    }

    /**
     * Run a single agent by key.
     *
     * @param string $key Agent key.
     * @return mixed
     */
    public function run_agent( $key ) {
        $results = $this->run_agents( array( $key ) );
        return isset( $results[ $key ] ) ? $results[ $key ] : null;
    }

    /**
     * Get status summary for all agents.
     *
     * @return array<string, array>
     */
    public function get_status() {
        $status = array();
        foreach ( $this->agents as $key => $agent ) {
            $status[ $key ] = array(
                'enabled' => $this->services['settings']->is_agent_enabled( $key ),
                'lastRun' => $this->services['logger']->get_last_run( $key ),
                'label'   => $agent->get_label(),
            );
        }
        return $status;
    }

    /**
     * Manually trigger a specific agent (admin action).
     *
     * @param string $key Agent key.
     * @return array
     */
    public function manual_run( $key ) {
        if ( ! isset( $this->agents[ $key ] ) ) {
            $safe_key = is_string( $key ) ? sanitize_key( $key ) : 'invalid';
            return array( 'error' => "Unknown agent: {$safe_key}" );
        }
        return $this->run_agents( array( $key ) );
    }

    /**
     * Get all available agent keys.
     *
     * @return array
     */
    public function get_agent_keys() {
        return array_keys( $this->agents );
    }
}
