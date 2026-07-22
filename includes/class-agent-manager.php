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
     *
     * @param array $agent_keys List of agent keys to run.
     * @return array<string, array> Results keyed by agent key.
     */
    public function run_agents( array $agent_keys ) {
        $results = array();

        foreach ( $agent_keys as $key ) {
            if ( ! isset( $this->agents[ $key ] ) ) {
                $this->services['logger']->warning( 'agent_manager', "Unknown agent: {$key}" );
                continue;
            }

            // Check if agent is enabled in settings.
            if ( ! $this->services['settings']->is_agent_enabled( $key ) ) {
                continue;
            }

            try {
                $start = microtime( true );
                $result = $this->agents[ $key ]->run();
                $elapsed = microtime( true ) - $start;

                $this->services['logger']->info( 'agent_run', array(
                    'agent'   => $key,
                    'elapsed' => round( $elapsed, 4 ),
                    'result'  => $result,
                ) );

                $results[ $key ] = $result;

                /**
                 * Fires after an agent completes its run.
                 *
                 * @param string $key    Agent key.
                 * @param mixed  $result Agent return data.
                 */
                do_action( "wac_agent_{$key}_complete", $result );
            } catch ( \Exception $e ) {
                $this->services['logger']->error( 'agent_failed', array(
                    'agent'  => $key,
                    'error'  => $e->getMessage(),
                    'trace'  => $e->getTraceAsString(),
                ) );
                $results[ $key ] = array( 'error' => $e->getMessage() );
            }
        }

        return $results;
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
            return array( 'error' => "Unknown agent: {$key}" );
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
