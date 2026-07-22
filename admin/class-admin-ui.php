<?php
namespace WooAgenticCheckout\Admin;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\AgentManager;
use WooAgenticCheckout\ABTestManager;
use WooAgenticCheckout\Settings;
use WooAgenticCheckout\SuggestionEngine;

/**
 * Admin UI — renders the plugin dashboard, settings, experiment views, and
 * suggestion management interface.
 *
 * @since 0.1.0-alpha
 */
class AdminUI {

    /**
     * @var AgentManager
     */
    private $agents;

    /**
     * @var ABTestManager
     */
    private $ab;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var SuggestionEngine
     */
    private $suggest;

    /**
     * @param AgentManager     $agents
     * @param ABTestManager    $ab
     * @param Settings         $settings
     * @param SuggestionEngine $suggest
     */
    public function __construct( $agents, $ab, $settings, $suggest ) {
        $this->agents   = $agents;
        $this->ab       = $ab;
        $this->settings = $settings;
        $this->suggest  = $suggest;
    }

    /**
     * Render the main admin page.
     */
    public function render_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

        ?>
        <div class="wrap wac-admin">
            <h1>
                <?php esc_html_e( '🍌 Woo Agentic Checkout', 'woo-agentic-checkout' ); ?>
                <span class="wac-version">v<?php echo esc_html( WAC_VERSION ); ?></span>
            </h1>

            <nav class="nav-tab-wrapper wac-tabs">
                <a href="?page=wac-dashboard&tab=dashboard"
                   class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>">
                   📊 Dashboard
                </a>
                <a href="?page=wac-dashboard&tab=experiments"
                   class="nav-tab <?php echo 'experiments' === $tab ? 'nav-tab-active' : ''; ?>">
                   🧪 Experiments
                </a>
                <a href="?page=wac-dashboard&tab=suggestions"
                   class="nav-tab <?php echo 'suggestions' === $tab ? 'nav-tab-active' : ''; ?>">
                   💡 Suggestions
                </a>
                <a href="?page=wac-dashboard&tab=agents"
                   class="nav-tab <?php echo 'agents' === $tab ? 'nav-tab-active' : ''; ?>">
                   🤖 Agents
                </a>
                <a href="?page=wac-dashboard&tab=settings"
                   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
                   ⚙️ Settings
                </a>
                <a href="?page=wac-dashboard&tab=logs"
                   class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>">
                   📝 Logs
                </a>
            </nav>

            <div class="wac-tab-content">
                <?php
                switch ( $tab ) {
                    case 'experiments':
                        $this->render_experiments_tab();
                        break;
                    case 'suggestions':
                        $this->render_suggestions_tab();
                        break;
                    case 'agents':
                        $this->render_agents_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Dashboard tab — high-level overview.
     */
    private function render_dashboard_tab() {
        $status = $this->agents->get_status();
        $experiments = $this->ab->get_active_experiments();
        $suggestions = $this->suggest->get_pending_count();
        ?>
        <div class="wac-dashboard-grid">
            <div class="wac-card">
                <h3>🤖 Agent Status</h3>
                <table class="widefat striped">
                    <thead><tr><th>Agent</th><th>Status</th><th>Last Run</th></tr></thead>
                    <tbody>
                        <?php foreach ( $status as $key => $agent ) : ?>
                            <tr>
                                <td><?php echo esc_html( $agent['label'] ); ?></td>
                                <td>
                                    <?php if ( $agent['enabled'] ) : ?>
                                        <span class="wac-badge wac-badge-active">● Active</span>
                                    <?php else : ?>
                                        <span class="wac-badge wac-badge-inactive">○ Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $agent['lastRun'] ?? 'Never' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="wac-card">
                <h3>🧪 Active Experiments</h3>
                <?php if ( empty( $experiments ) ) : ?>
                    <p class="wac-empty">No active experiments. <a href="?page=wac-dashboard&tab=experiments">Create one →</a></p>
                <?php else : ?>
                    <ul class="wac-experiment-list">
                        <?php foreach ( $experiments as $exp ) : ?>
                            <li>
                                <strong><?php echo esc_html( $exp['name'] ); ?></strong>
                                <span class="wac-count"><?php echo esc_html( $exp['variant_count'] ); ?> variants</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="wac-card">
                <h3>💡 Pending Suggestions</h3>
                <?php if ( $suggestions > 0 ) : ?>
                    <p class="wac-number"><?php echo esc_html( $suggestions ); ?></p>
                    <a href="?page=wac-dashboard&tab=suggestions" class="button">Review →</a>
                <?php else : ?>
                    <p class="wac-empty wac-number">0</p>
                    <p>No pending suggestions. Next weekly run will generate new ones.</p>
                <?php endif; ?>
            </div>

            <div class="wac-card">
                <h3>⚡ Actions</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                    <input type="hidden" name="action" value="wac_manual_agent">
                    <select name="agent_key">
                        <?php foreach ( $this->agents->get_agent_keys() as $key ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>">
                                <?php echo esc_html( $status[ $key ]['label'] ?? $key ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( '▶ Run Agent Now', 'secondary', 'submit', false ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Experiments tab — manage A/B tests.
     */
    private function render_experiments_tab() {
        $all_experiments = $this->ab->get_experiments( '', 50 );
        ?>
        <h2>🧪 A/B Experiments</h2>

        <?php if ( empty( $all_experiments ) ) : ?>
            <div class="wac-card">
                <p>No experiments yet. Create your first one to start optimising checkout!</p>
                <button class="button button-primary" id="wac-create-experiment">Create Experiment</button>
            </div>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Variants</th>
                        <th>Traffic</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_experiments as $exp ) : ?>
                        <tr>
                            <td><?php echo esc_html( $exp['name'] ); ?>
                                <?php if ( $exp['winner_key'] ) : ?>
                                    <span class="wac-badge wac-badge-winner">🏆</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="wac-badge wac-badge-<?php echo esc_attr( $exp['status'] ); ?>">
                                    <?php echo esc_html( $exp['status'] ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $exp['variant_count'] ); ?></td>
                            <td><?php echo esc_html( $exp['traffic_pct'] ); ?>%</td>
                            <td><?php echo esc_html( $exp['created_at'] ); ?></td>
                            <td>
                                <a href="#" class="wac-view-exp" data-id="<?php echo esc_attr( $exp['id'] ); ?>">View</a>
                                <?php if ( 'active' === $exp['status'] ) : ?>
                                    | <a href="#" class="wac-pause-exp" data-id="<?php echo esc_attr( $exp['id'] ); ?>">Pause</a>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Variant details inline -->
                        <?php if ( ! empty( $exp['variants'] ) && is_array( $exp['variants'] ) ) : ?>
                            <?php foreach ( $exp['variants'] as $variant ) : ?>
                                <tr class="wac-variant-row">
                                    <td></td>
                                    <td colspan="5">
                                        <div class="wac-variant-detail">
                                            <strong><?php echo esc_html( $variant['variant_name'] ); ?></strong>
                                            <?php if ( $variant['is_control'] ) : ?>
                                                <span class="wac-badge">Control</span>
                                            <?php endif; ?>
                                            — Impressions: <?php echo esc_html( $variant['impressions'] ?? 0 ); ?>
                                            | Conversions: <?php echo esc_html( $variant['conversions'] ?? 0 ); ?>
                                            | CR: <strong>
                                                <?php
                                                $cr = $variant['impressions'] > 0
                                                    ? round( ( $variant['conversions'] / $variant['impressions'] ) * 100, 2 )
                                                    : 0;
                                                echo esc_html( $cr ); ?>%
                                            </strong>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Suggestions tab.
     */
    private function render_suggestions_tab() {
        $pending = $this->suggest->get_pending( 50 );
        $all     = $this->suggest->get_suggestions( '', 50 );
        ?>
        <h2>💡 AI Suggestions</h2>

        <?php if ( empty( $pending ) ) : ?>
            <div class="wac-card">
                <p>No pending suggestions. The weekly agent run will generate new ones based on checkout data.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                    <input type="hidden" name="action" value="wac_manual_agent">
                    <input type="hidden" name="agent_key" value="suggestion_generator">
                    <?php submit_button( 'Generate Suggestions Now', 'secondary', 'submit', false ); ?>
                </form>
            </div>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Action</th>
                        <th>Score</th>
                        <th>Expected Lift</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $pending as $s ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s['title'] ); ?></strong></td>
                            <td><span class="wac-badge"><?php echo esc_html( $s['category'] ); ?></span></td>
                            <td><?php echo esc_html( $s['action_type'] ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $s['score'] * 100, 0 ) ); ?>%</td>
                            <td><?php echo esc_html( $s['expected_lift'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $s['created_at'] ); ?></td>
                            <td>
                                <button class="button wac-apply-suggestion" data-id="<?php echo esc_attr( $s['id'] ); ?>">Apply</button>
                                <button class="button wac-reject-suggestion" data-id="<?php echo esc_attr( $s['id'] ); ?>">✕</button>
                            </td>
                        </tr>
                        <?php if ( ! empty( $s['description'] ) ) : ?>
                            <tr><td colspan="7" class="wac-desc"><?php echo esc_html( $s['description'] ); ?></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( ! empty( $all ) ) : ?>
            <h3>History</h3>
            <table class="widefat striped">
                <thead><tr><th>Title</th><th>Status</th><th>Score</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ( $all as $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $s['title'] ); ?></td>
                            <td><span class="wac-badge wac-badge-<?php echo esc_attr( $s['status'] ); ?>"><?php echo esc_html( $s['status'] ); ?></span></td>
                            <td><?php echo esc_html( number_format( (float) $s['score'] * 100, 0 ) ); ?>%</td>
                            <td><?php echo esc_html( $s['created_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Agents tab — status and manual control.
     */
    private function render_agents_tab() {
        $status = $this->agents->get_status();
        ?>
        <h2>🤖 Agents</h2>
        <p>Each agent runs autonomously on its schedule. Toggle them on/off in Settings.</p>

        <table class="widefat striped">
            <thead><tr><th>Agent</th><th>Status</th><th>Last Run</th><th>Run Now</th></tr></thead>
            <tbody>
                <?php foreach ( $status as $key => $agent ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $agent['label'] ); ?></strong>
                            <code style="margin-left:8px;color:#666;"><?php echo esc_html( $key ); ?></code>
                        </td>
                        <td>
                            <?php if ( $agent['enabled'] ) : ?>
                                <span class="wac-badge wac-badge-active">● Enabled</span>
                            <?php else : ?>
                                <span class="wac-badge wac-badge-inactive">○ Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $agent['lastRun'] ?? 'Never run' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                                <input type="hidden" name="action" value="wac_manual_agent">
                                <input type="hidden" name="agent_key" value="<?php echo esc_attr( $key ); ?>">
                                <?php submit_button( '▶ Run', 'small', 'submit', false ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="wac-card" style="margin-top:20px;">
            <h3>Agent Schedules</h3>
            <ul>
                <li>⏰ Error Detector + Self-Healing: Every hour</li>
                <li>⏰ Conversion Analyzer + AB Optimizer: Daily</li>
                <li>⏰ Suggestion Generator: Weekly</li>
            </ul>
        </div>
        <?php
    }

    /**
     * Settings tab.
     */
    private function render_settings_tab() {
        ?>
        <h2>⚙️ Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'wac_settings' ); ?>

            <div class="wac-card">
                <h3>🤖 LLM Provider</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wac_llm_provider">Provider</label></th>
                        <td>
                            <select id="wac_llm_provider" name="wac_llm_provider">
                                <option value="openai" <?php selected( get_option( 'wac_llm_provider' ), 'openai' ); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected( get_option( 'wac_llm_provider' ), 'anthropic' ); ?>>Anthropic Claude</option>
                                <option value="ollama" <?php selected( get_option( 'wac_llm_provider' ), 'ollama' ); ?>>Local Ollama</option>
                                <option value="openrouter" <?php selected( get_option( 'wac_llm_provider' ), 'openrouter' ); ?>>OpenRouter</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wac_llm_api_key">API Key</label></th>
                        <td>
                            <input type="password" id="wac_llm_api_key" name="wac_llm_api_key"
                                   value="<?php echo esc_attr( get_option( 'wac_llm_api_key', '' ) ); ?>"
                                   class="regular-text" />
                            <p class="description">Required for OpenAI/Anthropic/OpenRouter. Leave blank for local Ollama.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wac_llm_model">Model</label></th>
                        <td>
                            <input type="text" id="wac_llm_model" name="wac_llm_model"
                                   value="<?php echo esc_attr( get_option( 'wac_llm_model', 'gpt-4o' ) ); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🛡️ Self-Healing</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wac_heal_permission_level">Permission Level</label></th>
                        <td>
                            <select id="wac_heal_permission_level" name="wac_heal_permission_level">
                                <option value="monitor" <?php selected( get_option( 'wac_heal_permission_level' ), 'monitor' ); ?>>Monitor — Log only, no actions</option>
                                <option value="suggest" <?php selected( get_option( 'wac_heal_permission_level' ), 'suggest' ); ?>>Suggest — Recommend fixes, require approval</option>
                                <option value="auto_patch" <?php selected( get_option( 'wac_heal_permission_level' ), 'auto_patch' ); ?>>Auto-Patch — Safe CSS/JS/template fixes</option>
                                <option value="auto_full" <?php selected( get_option( 'wac_heal_permission_level' ), 'auto_full' ); ?>>Auto-Full — Rollback settings, disable plugins</option>
                            </select>
                            <p class="description"><strong>Suggest</strong> is recommended for initial use. Upgrade to auto when trusted.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>📊 GA4 / Signals</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wac_ga4_measurement_id">GA4 Measurement ID</label></th>
                        <td><input type="text" id="wac_ga4_measurement_id" name="wac_ga4_measurement_id"
                                   value="<?php echo esc_attr( get_option( 'wac_ga4_measurement_id', '' ) ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="wac_ga4_api_secret">GA4 API Secret</label></th>
                        <td><input type="password" id="wac_ga4_api_secret" name="wac_ga4_api_secret"
                                   value="<?php echo esc_attr( get_option( 'wac_ga4_api_secret', '' ) ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🧪 A/B Testing</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wac_ab_min_sample_size">Min Sample Size</label></th>
                        <td><input type="number" id="wac_ab_min_sample_size" name="wac_ab_min_sample_size"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_min_sample_size', 100 ) ); ?>" min="10" /> per variant</td>
                    </tr>
                    <tr>
                        <th><label for="wac_ab_confidence_threshold">Confidence Threshold</label></th>
                        <td>
                            <input type="number" id="wac_ab_confidence_threshold" name="wac_ab_confidence_threshold"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_confidence_threshold', 0.95 ) ); ?>"
                                   min="0.8" max="0.99" step="0.01" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wac_ab_max_concurrent">Max Concurrent Tests</label></th>
                        <td>
                            <input type="number" id="wac_ab_max_concurrent" name="wac_ab_max_concurrent"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_max_concurrent', 3 ) ); ?>" min="1" max="10" />
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <?php
    }

    /**
     * Logs tab.
     */
    private function render_logs_tab() {
        $logger = new \WooAgenticCheckout\Logger();
        $level  = isset( $_GET['log_level'] ) ? sanitize_key( wp_unslash( $_GET['log_level'] ) ) : '';
        $logs   = $logger->get_logs( array( 'level' => $level, 'limit' => 200 ) );

        ?>
        <h2>📝 Event Log</h2>

        <div class="wac-filter-bar">
            <a href="?page=wac-dashboard&tab=logs" class="button <?php echo empty( $level ) ? 'button-primary' : ''; ?>">All</a>
            <a href="?page=wac-dashboard&tab=logs&log_level=error" class="button <?php echo 'error' === $level ? 'button-primary' : ''; ?>">Errors</a>
            <a href="?page=wac-dashboard&tab=logs&log_level=warning" class="button <?php echo 'warning' === $level ? 'button-primary' : ''; ?>">Warnings</a>
            <a href="?page=wac-dashboard&tab=logs&log_level=info" class="button <?php echo 'info' === $level ? 'button-primary' : ''; ?>">Info</a>
        </div>

        <table class="widefat striped">
            <thead><tr><th>Level</th><th>Event</th><th>Context</th><th>Time</th></tr></thead>
            <tbody>
                <?php foreach ( $logs as $log ) : ?>
                    <tr class="wac-log-<?php echo esc_attr( $log['level'] ); ?>">
                        <td><span class="wac-badge wac-badge-<?php echo esc_attr( $log['level'] ); ?>"><?php echo esc_html( $log['level'] ); ?></span></td>
                        <td><code><?php echo esc_html( $log['event'] ); ?></code></td>
                        <td class="wac-log-context"><pre><?php echo esc_html( substr( $log['context'], 0, 200 ) ); ?></pre></td>
                        <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="4" class="wac-empty">No log entries found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
