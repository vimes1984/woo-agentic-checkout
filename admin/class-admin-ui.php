<?php
namespace WooAgenticCheckout\Admin;

defined( 'ABSPATH' ) || exit;

use WooAgenticCheckout\AgentManager;
use WooAgenticCheckout\ABTestManager;
use WooAgenticCheckout\Settings;
use WooAgenticCheckout\SuggestionEngine;

/**
 * Admin UI — renders the plugin dashboard, settings, experiment views, and
 * suggestion management interface with loading/empty/error states.
 *
 * @since 0.2.0
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

        // Whitelist: only known tabs are allowed.
        $allowed_tabs = array( 'dashboard', 'experiments', 'suggestions', 'agents', 'settings', 'logs' );
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'dashboard';
        }

        ?>
        <div class="wrap wac-admin" data-wac-tab="<?php echo esc_attr( $tab ); ?>">
            <h1>
                <?php esc_html_e( '🍌 Woo Agentic Checkout', 'woo-agentic-checkout' ); ?>
                <span class="wac-version">v<?php echo esc_html( WAC_VERSION ); ?></span>
                <button class="wac-refresh-btn" title="Refresh dashboard data" aria-label="Refresh dashboard">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span> Refresh
                </button>
            </h1>

            <nav class="nav-tab-wrapper wac-tabs" role="tablist" aria-label="Plugin tabs">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=dashboard' ) ); ?>"
                   class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'dashboard' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'dashboard' === $tab ? 'page' : 'false'; ?>">
                   📊 Dashboard
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=experiments' ) ); ?>"
                   class="nav-tab <?php echo 'experiments' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'experiments' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'experiments' === $tab ? 'page' : 'false'; ?>">
                   <?php
                   $exp_count = is_array( $this->ab->get_active_experiments() ) ? count( $this->ab->get_active_experiments() ) : 0;
                   ?>
                   🧪 Experiments<?php if ( $exp_count > 0 ) : ?> <span class="wac-tab-count"><?php echo esc_html( $exp_count ); ?></span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=suggestions' ) ); ?>"
                   class="nav-tab <?php echo 'suggestions' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'suggestions' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'suggestions' === $tab ? 'page' : 'false'; ?>">
                   <?php $sugg_count = is_array( $this->suggest->get_pending_count() ) ? count( $this->suggest->get_pending_count() ) : (int) $this->suggest->get_pending_count(); ?>
                   💡 Suggestions<?php if ( $sugg_count > 0 ) : ?> <span class="wac-tab-count"><?php echo esc_html( $sugg_count ); ?></span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=agents' ) ); ?>"
                   class="nav-tab <?php echo 'agents' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'agents' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'agents' === $tab ? 'page' : 'false'; ?>">
                   🤖 Agents
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'settings' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'settings' === $tab ? 'page' : 'false'; ?>">
                   ⚙️ Settings
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wac-dashboard&tab=logs' ) ); ?>"
                   class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'logs' === $tab ? 'true' : 'false'; ?>"
                   aria-current="<?php echo 'logs' === $tab ? 'page' : 'false'; ?>">
                   📝 Logs
                </a>
            </nav>

            <?php $this->render_status_summary_bar(); ?>

            <div class="wac-tab-content" role="tabpanel">
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

            <div class="wac-footer">
                <?php
                /* translators: %1$s: plugin version, %2$s: WordPress version */
                echo esc_html( sprintf( __( 'Woo Agentic Checkout v%s — WordPress %s', 'woo-agentic-checkout' ), WAC_VERSION, get_bloginfo( 'version' ) ) );
                ?>
                &middot;
                <a href="https://github.com/vimes1984/woo-agentic-checkout" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'GitHub', 'woo-agentic-checkout' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render an empty state component.
     *
     * @param string $icon     Emoji or icon character.
     * @param string $title    Title text.
     * @param string $text     Description.
     * @param string $button   Optional button label.
     * @param string $button_url Optional button URL.
     */
    private function render_empty_state( $icon, $title, $text, $button = '', $button_url = '' ) {
        ?>
        <div class="wac-empty-state">
            <span class="wac-empty-state__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
            <h3 class="wac-empty-state__title"><?php echo esc_html( $title ); ?></h3>
            <p class="wac-empty-state__text"><?php echo esc_html( $text ); ?></p>
            <?php if ( $button && $button_url ) : ?>
                <a href="<?php echo esc_url( $button_url ); ?>" class="button button-primary">
                    <?php echo esc_html( $button ); ?>
                </a>
            <?php elseif ( $button ) : ?>
                <button class="button button-primary" id="wac-create-experiment"><?php echo esc_html( $button ); ?></button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render an error state component.
     *
     * @param string $text Error description.
     */
    private function render_error( $text ) {
        ?>
        <div class="wac-error" role="alert">
            <span><?php echo esc_html( $text ); ?></span>
            <button class="wac-error__dismiss" aria-label="Dismiss">&times;</button>
        </div>
        <?php
    }

    /**
     * Render a badge with appropriate colour.
     *
     * @param string $status Badge status key.
     * @param string $label  Display text.
     * @param bool   $large  Use large badge variant.
     * @return string HTML.
     */
    private function badge( $status, $label, $large = false ) {
        $class = 'wac-badge wac-badge-' . esc_attr( $status );
        if ( $large ) {
            $class .= ' wac-badge--lg';
        }
        return sprintf(
            '<span class="%s">%s</span>',
            $class,
            esc_html( $label )
        );
    }

    /**
     * Render a progress bar for scores.
     *
     * @param float  $score  Value 0-100.
     * @param string $label  Optional label beside bar.
     * @param string $size   sm|lg
     * @return string HTML.
     */
    private function progress_bar( $score, $label = '', $size = '' ) {
        $score = max( 0, min( 100, (float) $score ) );
        $mod   = 'success';
        if ( $score < 40 ) {
            $mod = 'error';
        } elseif ( $score < 70 ) {
            $mod = 'warning';
        }

        $bar_class = 'wac-progress-bar';
        if ( $size ) {
            $bar_class .= ' wac-progress-bar--' . $size;
        }

        $html = '<span class="wac-progress-label">';
        $html .= sprintf(
            '<span class="%s" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100">',
            esc_attr( $bar_class ),
            (int) $score
        );
        $html .= sprintf(
            '<span class="wac-progress-bar__fill wac-progress-bar--%s" style="--wac-progress: %s%%;"></span>',
            esc_attr( $mod ),
            esc_attr( $score )
        );
        $html .= '</span>';
        if ( $label ) {
            $html .= '<span class="wac-progress-label__text">' . esc_html( $label ) . '</span>';
        }
        $html .= '</span>';
        return $html;
    }

    // ─── Status Summary Bar ─────────────────────────────────────

    /**
     * Render a thin status bar at the top of the admin page showing
     * quick stats: active agents, pending suggestions, active experiments.
     */
    private function render_status_summary_bar() {
        $experiments = $this->ab->get_active_experiments();
        $suggestions = $this->suggest->get_pending_count();
        $status      = $this->agents->get_status();
        $total_heals = 0;
        $healer      = new \WooAgenticCheckout\SelfHealer();
        if ( method_exists( $healer, 'get_total_heals' ) ) {
            $total_heals = $healer->get_total_heals();
        }

        $active_agents = 0;
        if ( ! empty( $status ) ) {
            foreach ( $status as $agent ) {
                if ( ! empty( $agent['enabled'] ) ) {
                    $active_agents++;
                }
            }
        }
        ?>
        <div class="wac-status-bar" role="status" aria-label="Dashboard summary">
            <span class="wac-status-bar__item">
                <span class="wac-status-dot wac-status-dot--active" aria-hidden="true"></span>
                <?php echo esc_html( sprintf( __( '%d agents active', 'woo-agentic-checkout' ), $active_agents ) ); ?>
            </span>
            <span class="wac-status-bar__item">
                <span role="img" aria-label="Experiments">🧪</span>
                <?php echo esc_html( sprintf( __( '%d active tests', 'woo-agentic-checkout' ), count( $experiments ) ) ); ?>
            </span>
            <span class="wac-status-bar__item">
                <span role="img" aria-label="Suggestions">💡</span>
                <?php echo esc_html( sprintf( __( '%d pending suggestions', 'woo-agentic-checkout' ), $suggestions ) ); ?>
            </span>
            <?php if ( $total_heals > 0 ) : ?>
                <span class="wac-status-bar__item">
                    <span role="img" aria-label="Heals">🩹</span>
                    <?php echo esc_html( sprintf( __( '%d self-heals', 'woo-agentic-checkout' ), $total_heals ) ); ?>
                </span>
            <?php endif; ?>
            <span class="wac-status-bar__item wac-status-bar__time" title="<?php echo esc_attr( current_time( 'mysql' ) ); ?>">
                <?php
                /* translators: %s: current date/time */
                echo esc_html( sprintf( __( 'Updated: %s', 'woo-agentic-checkout' ), current_time( 'M j, Y H:i' ) ) );
                ?>
            </span>
        </div>
        <?php
    }

    // ─── Dashboard Tab ───────────────────────────────────────────

    /**
     * Dashboard tab — high-level overview with loading/empty states.
     */
    private function render_dashboard_tab() {
        $status      = $this->agents->get_status();
        $experiments = $this->ab->get_active_experiments();
        $suggestions = $this->suggest->get_pending_count();
        ?>
        <div class="wac-welcome-card wac-card">
            <div class="wac-welcome-card__content">
                <h3 class="wac-welcome-card__title">
                    <span role="img" aria-label="Wave">👋</span>
                    <?php esc_html_e( 'Welcome to Woo Agentic Checkout', 'woo-agentic-checkout' ); ?>
                </h3>
                <p class="wac-welcome-card__text">
                    <?php esc_html_e( 'This plugin uses AI agents to optimise your WooCommerce checkout. Agents run autonomously on schedules — detect errors, analyse conversion data, generate suggestions, and heal issues automatically.', 'woo-agentic-checkout' ); ?>
                </p>
                <div class="wac-welcome-card__links">
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&amp;tab=agents" ) ); ?>" class="button button-secondary">
                        <span role="img" aria-label="Agents">🤖</span> <?php esc_html_e( 'View Agents', 'woo-agentic-checkout' ) ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&amp;tab=suggestions" ) ); ?>" class="button button-secondary">
                        <span role="img" aria-label="Suggestions">💡</span> <?php esc_html_e( 'Review Suggestions', 'woo-agentic-checkout' ) ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&amp;tab=settings" ) ); ?>" class="button button-secondary">
                        <span role="img" aria-label="Settings">⚙️</span> <?php esc_html_e( 'Configure', 'woo-agentic-checkout' ) ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="wac-dashboard-grid">
            <div class="wac-card">
                <h3><span role="img" aria-label="Agent">🤖</span> Agent Status <button class="wac-refresh-btn" data-target="wac-status" title="Refresh agent status" aria-label="Refresh agent status"><span class="dashicons dashicons-update" aria-hidden="true"></span></button></h3>
                <?php if ( empty( $status ) ) : ?>
                    <div class="wac-empty-state" style="padding:20px;">
                        <span class="wac-empty-state__icon" aria-hidden="true">🤖</span>
                        <p class="wac-empty-state__text">No agents registered yet.</p>
                    </div>
                <?php else : ?>
                    <table class="widefat striped" aria-label="<?php esc_attr_e( 'Agent status table', 'woo-agentic-checkout' ); ?>">
                        <thead><tr><th scope="col"><?php esc_html_e( 'Agent', 'woo-agentic-checkout' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'woo-agentic-checkout' ); ?></th><th scope="col"><?php esc_html_e( 'Last Run', 'woo-agentic-checkout' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $status as $key => $agent ) : ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html( $agent['label'] ?? $key ); ?>
                                        <code style="margin-left:4px;font-size:10px;color:#888;"><?php echo esc_html( $key ); ?></code>
                                    </td>
                                    <td>
                                        <?php
                                        $agent_enabled = ! empty( $agent['enabled'] );
                                        if ( $agent_enabled ) {
                                            echo '<span class="wac-status-dot wac-status-dot--active" aria-hidden="true"></span>';
                                            echo $this->badge( 'active', __( 'Active', 'woo-agentic-checkout' ), true );
                                        } else {
                                            echo '<span class="wac-status-dot wac-status-dot--inactive" aria-hidden="true"></span>';
                                            echo $this->badge( 'inactive', __( 'Disabled', 'woo-agentic-checkout' ), true );
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html( $agent['lastRun'] ?? __( 'Never', 'woo-agentic-checkout' ) ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p style="margin:12px 0 0;">
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&amp;tab=agents" ) ); ?>" class="button button-secondary">Manage Agents →</a>
                </p>
            </div>

            <div class="wac-card">
                <h3><span role="img" aria-label="Experiments">🧪</span> Active Experiments</h3>
                <?php if ( empty( $experiments ) ) : ?>
                    <div class="wac-empty-state" style="padding:20px;">
                        <span class="wac-empty-state__icon" aria-hidden="true">🧪</span>
                        <p class="wac-empty-state__text">No active experiments. The AB Optimizer will auto-create them, or you can create one manually.</p>
                        <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&tab=experiments" ) ); ?>" class="button">Go to Experiments →</a>
                    </div>
                <?php else : ?>
                    <ul class="wac-experiment-list">
                        <?php foreach ( $experiments as $exp ) : ?>
                            <li>
                                <span>
                                    <strong><?php echo esc_html( $exp['name'] ); ?></strong>
                                    <?php
                                    echo $this->badge( $exp['status'], $exp['status'] );
                                    ?>
                                </span>
                                <span class="wac-count"><?php echo esc_html( $exp['variant_count'] ); ?> variants</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin:12px 0 0;">
                        <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&tab=experiments" ) ); ?>" class="button button-secondary">View All →</a>
                    </p>
                <?php endif; ?>
            </div>

            <div class="wac-card">
                <h3><span role="img" aria-label="Suggestions">💡</span> Pending Suggestions</h3>
                <p class="wac-number" aria-label="<?php echo esc_attr( sprintf( __( '%d pending suggestions', 'woo-agentic-checkout' ), $suggestions ) ); ?>">
                    <?php echo esc_html( $suggestions ); ?>
                </p>
                <?php if ( $suggestions > 0 ) : ?>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=wac-dashboard&tab=suggestions" ) ); ?>" class="button">Review Suggestions →</a>
                <?php else : ?>
                    <p><?php esc_html_e( 'No pending suggestions. Next weekly run will generate new ones.', 'woo-agentic-checkout' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" class="wac-agent-run-form">
                        <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                        <input type="hidden" name="action" value="wac_manual_agent">
                        <input type="hidden" name="agent_key" value="suggestion_generator">
                        <input type="hidden" name="agent_label" value="Suggestion Generator">
                        <button type="submit" class="button button-secondary run-agent-btn">
                            <span class="wac-spinner wac-spinner--sm" style="display:none;" aria-hidden="true"></span>
                            <?php esc_html_e( 'Generate Now', 'woo-agentic-checkout' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="wac-card">
                <h3><span role="img" aria-label="Actions">⚡</span> Quick Actions</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wac-quick-action-form" novalidate>
                    <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                    <input type="hidden" name="action" value="wac_manual_agent">
                    <p>
                        <label for="wac-quick-agent-select"><?php esc_html_e( 'Run agent:', 'woo-agentic-checkout' ); ?></label>
                    </p>
                    <p>
                        <select id="wac-quick-agent-select" name="agent_key" aria-label="<?php esc_attr_e( 'Select agent to run', 'woo-agentic-checkout' ); ?>">
                            <?php $agent_keys = is_array( $this->agents->get_agent_keys() ) ? $this->agents->get_agent_keys() : array(); ?><?php foreach ( $agent_keys as $key ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>">
                                    <?php echo esc_html( $status[ $key ]['label'] ?? $key ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary run-agent-btn" title="Run selected agent now">
                            <span class="wac-spinner wac-spinner--sm" style="display:none;" aria-hidden="true"></span>
                            <span role="img" aria-label="Run">▶</span> <?php esc_html_e( 'Run Agent Now', 'woo-agentic-checkout' ); ?>
                        </button>
                    </p>
                </form>
                <hr>
                <p><?php esc_html_e( 'Agents run autonomously on their schedules.', 'woo-agentic-checkout' ); ?></p>
                <ul style="font-size:12px;color:#666;">
                    <li>⏰ Error Detector + Self-Healing: Every hour</li>
                    <li>⏰ Conversion Analyzer + AB Optimizer: Daily</li>
                    <li>⏰ Suggestion Generator: Weekly</li>
                </ul>
            </div>
        </div>
        <?php
    }

    // ─── Experiments Tab ─────────────────────────────────────────

    /**
     * Experiments tab — manage A/B tests with sortable columns and empty states.
     */
    private function render_experiments_tab() {
        $all_experiments = $this->ab->get_experiments( '', 50 );
        ?>
        <div class="wac-page-intro">
            <h2><span role="img" aria-label="Experiments">🧪</span> A/B Experiments</h2>
            <p class="wac-page-intro__text"><?php esc_html_e( 'Manage and monitor A/B experiments. Variants are shown to a portion of traffic and automatically analysed with Bayesian methods to pick a winner.', 'woo-agentic-checkout' ); ?></p>
        </div>

        <?php if ( ! empty( $all_experiments ) ) : ?>
            <div class="wac-filter-row">
                <input type="text" class="wac-table-filter" id="wac-filter-experiments" placeholder="<?php esc_attr_e( 'Filter experiments…', 'woo-agentic-checkout' ); ?>" aria-label="<?php esc_attr_e( 'Filter experiments', 'woo-agentic-checkout' ); ?>">
            </div>
        <?php endif; ?>

        <?php if ( empty( $all_experiments ) ) : ?>
            <?php $this->render_empty_state(
                '🧪',
                __( 'No experiments yet', 'woo-agentic-checkout' ),
                __( 'Create your first A/B test to start optimising the checkout experience. Variants run concurrently, and we pick a winner using Bayesian analysis.', 'woo-agentic-checkout' ),
                __( 'Create Experiment', 'woo-agentic-checkout' )
            ); ?>
        <?php else : ?>
            <div class="wac-spinner-wrap">
                <table class="widefat striped" id="wac-experiments-table">
                    <thead>
                        <tr>
                            <th class="wac-sortable" data-col="name" aria-sort="none"><?php esc_html_e( 'Name', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable" data-col="status" aria-sort="none"><?php esc_html_e( 'Status', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable" data-col="variants" aria-sort="none"><?php esc_html_e( 'Variants', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable" data-col="traffic" aria-sort="none"><?php esc_html_e( 'Traffic', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable" data-col="created" aria-sort="none"><?php esc_html_e( 'Created', 'woo-agentic-checkout' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Actions', 'woo-agentic-checkout' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_experiments as $exp ) : ?>
                            <tr data-exp-id="<?php echo esc_attr( $exp['id'] ); ?>" data-status="<?php echo esc_attr( $exp['status'] ); ?>" data-name="<?php echo esc_attr( $exp['name'] ); ?>">
                                <td>
                                    <strong><?php echo esc_html( $exp['name'] ); ?></strong>
                                    <?php if ( ! empty( $exp['winner_key'] ) ) : ?>
                                        <?php echo $this->badge( 'winner', __( '🏆 Winner', 'woo-agentic-checkout' ), true ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $this->badge( $exp['status'], $exp['status'], true ); ?></td>
                                <td><?php echo esc_html( $exp['variant_count'] ); ?></td>
                                <td><?php echo esc_html( $exp['traffic_pct'] ); ?>%</td>
                                <td title="<?php echo esc_attr( $exp['created_at'] ); ?>"><?php echo esc_html( $this->format_date( $exp['created_at'] ) ); ?></td>
                                <td class="wac-actions-cell">
                                    <button class="wac-action-link wac-view-exp" data-id="<?php echo esc_attr( $exp['id'] ); ?>" aria-expanded="false" title="View experiment details">
                                        View
                                    </button>
                                    <?php if ( 'active' === $exp['status'] ) : ?>
                                        <span aria-hidden="true"> | </span>
                                        <button class="wac-action-link wac-pause-exp" data-id="<?php echo esc_attr( $exp['id'] ); ?>" title="Pause this experiment">
                                            Pause
                                        </button>
                                    <?php endif; ?>
                                    <?php if ( 'paused' === $exp['status'] ) : ?>
                                        <span aria-hidden="true"> | </span>
                                        <button class="wac-action-link wac-resume-exp" data-id="<?php echo esc_attr( $exp['id'] ); ?>" title="Resume this experiment">
                                            Resume
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ( isset( $exp['variants'] ) && is_array( $exp['variants'] ) && ! empty( $exp['variants'] ) ) : ?>
                                <?php foreach ( $exp['variants'] as $variant ) : ?>
                                    <tr class="wac-variant-row wac-hidden" data-exp-id="<?php echo esc_attr( $exp['id'] ); ?>">
                                        <td></td>
                                        <td colspan="5">
                                            <div class="wac-variant-detail">
                                                <strong><?php echo esc_html( $variant['variant_name'] ); ?></strong>
                                                <?php if ( ! empty( $variant['is_control'] ) ) : ?>
                                                    <?php echo $this->badge( 'info', __( 'Control', 'woo-agentic-checkout' ) ); ?>
                                                <?php endif; ?>
                                                <span title="Impressions">👁 <?php echo esc_html( number_format( $variant['impressions'] ?? 0 ) ); ?></span>
                                                <span title="Conversions">✅ <?php echo esc_html( number_format( $variant['conversions'] ?? 0 ) ); ?></span>
                                                <span title="Conversion Rate">
                                                    📈 <strong>
                                                    <?php
                                                    $cr = ( $variant['impressions'] ?? 0 ) > 0
                                                        ? round( ( ( $variant['conversions'] ?? 0 ) / $variant['impressions'] ) * 100, 2 )
                                                        : 0;
                                                    echo esc_html( $cr ); ?>%
                                                    </strong>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr class="wac-variant-row wac-hidden" data-exp-id="<?php echo esc_attr( $exp['id'] ); ?>">
                                    <td></td>
                                    <td colspan="5">
                                        <div class="wac-variant-detail">
                                            <span class="wac-empty" style="font-style:italic;color:var(--wac-text-muted);">
                                                <?php esc_html_e( 'No variant data available yet. Data appears after visitors are bucketed.', 'woo-agentic-checkout' ); ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php
    }

    // ─── Suggestions Tab ─────────────────────────────────────────

    /**
     * Suggestions tab — shows pending suggestions as cards with progress bars.
     */
    private function render_suggestions_tab() {
        $pending = $this->suggest->get_pending( 50 );
        $all     = $this->suggest->get_suggestions( '', 50 );
        ?>
        <h2>
            <span role="img" aria-label="Suggestions">💡</span> AI Suggestions
            <?php if ( ! empty( $pending ) ) : ?>
                <?php echo $this->badge( 'pending', sprintf( __( '%d pending', 'woo-agentic-checkout' ), count( $pending ) ), true ); ?>
            <?php endif; ?>
        </h2>

        <?php if ( empty( $pending ) ) : ?>
            <?php $this->render_empty_state(
                '💡',
                __( 'No pending suggestions', 'woo-agentic-checkout' ),
                __( 'The weekly suggestion generator will analyse your checkout data and propose improvements. You can also trigger a manual generation below.', 'woo-agentic-checkout' ),
                __( 'Generate Suggestions Now', 'woo-agentic-checkout' )
            ); ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-top:12px;" class="wac-agent-run-form">
                <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                <input type="hidden" name="action" value="wac_manual_agent">
                <input type="hidden" name="agent_key" value="suggestion_generator">
                <input type="hidden" name="agent_label" value="Suggestion Generator">
                <button type="submit" class="button button-secondary run-agent-btn">
                    <span class="wac-spinner wac-spinner--sm" style="display:none;" aria-hidden="true"></span>
                    <?php esc_html_e( 'Generate Suggestions Now', 'woo-agentic-checkout' ); ?>
                </button>
            </form>
        <?php else : ?>
            <p><?php printf( esc_html__( 'Showing %d pending suggestions.', 'woo-agentic-checkout' ), count( $pending ) ); ?></p>
            <div class="wac-filter-row">
                <input type="text" class="wac-table-filter" id="wac-filter-suggestions" placeholder="<?php esc_attr_e( 'Filter suggestions…', 'woo-agentic-checkout' ); ?>" aria-label="<?php esc_attr_e( 'Filter suggestions', 'woo-agentic-checkout' ); ?>">
            </div>

            <div class="wac-suggestions-grid" role="list">
            <?php foreach ( $pending as $s ) : ?>
                <div class="wac-suggestion-card" data-suggestion-id="<?php echo esc_attr( $s['id'] ); ?>" role="listitem">
                    <div class="wac-suggestion-card__header">
                        <h4 class="wac-suggestion-card__title"><?php echo esc_html( $s['title'] ); ?></h4>
                        <div>
                            <?php echo $this->badge( $s['category'], $s['category'] ); ?>
                            <?php echo $this->badge( $s['action_type'], $s['action_type'] ); ?>
                        </div>
                    </div>

                    <div class="wac-suggestion-card__meta">
                        <?php echo $this->progress_bar( (float) $s['score'] * 100, number_format( (float) $s['score'] * 100, 0 ) . '% confidence', 'sm' ); ?>
                        <?php if ( ! empty( $s['expected_lift'] ) ) : ?>
                            <span title="Expected lift">🚀 <?php echo esc_html( $s['expected_lift'] ); ?></span>
                        <?php endif; ?>
                        <span title="Date">📅 <?php echo esc_html( $this->format_date( $s['created_at'] ) ); ?></span>
                    </div>

                    <?php if ( ! empty( $s['description'] ) ) : ?>
                        <div class="wac-suggestion-card__desc"><?php echo esc_html( $s['description'] ); ?></div>
                    <?php endif; ?>

                    <div class="wac-suggestion-card__actions">
                        <button class="button button-primary wac-apply-suggestion" data-id="<?php echo esc_attr( $s['id'] ); ?>" title="<?php esc_attr_e( 'Apply this suggestion to your checkout', 'woo-agentic-checkout' ); ?>">
                            <?php esc_html_e( 'Apply', 'woo-agentic-checkout' ); ?>
                        </button>
                        <button class="button wac-reject-suggestion" data-id="<?php echo esc_attr( $s['id'] ); ?>" title="<?php esc_attr_e( 'Reject and dismiss this suggestion', 'woo-agentic-checkout' ); ?>">
                            ✕ <?php esc_html_e( 'Reject', 'woo-agentic-checkout' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $all ) ) : ?>
            <details style="margin-top:24px;" class="wac-card" role="group">
                <summary style="cursor:pointer;font-weight:600;padding:8px 0;">
                    <?php printf( esc_html__( '📋 History (%d total)', 'woo-agentic-checkout' ), count( $all ) ); ?>
                </summary>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead><tr><th class="wac-sortable"><?php esc_html_e( 'Title', 'woo-agentic-checkout' ); ?></th><th class="wac-sortable"><?php esc_html_e( 'Status', 'woo-agentic-checkout' ); ?></th><th class="wac-sortable"><?php esc_html_e( 'Score', 'woo-agentic-checkout' ); ?></th><th class="wac-sortable"><?php esc_html_e( 'Date', 'woo-agentic-checkout' ); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ( $all as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( $s['title'] ); ?></td>
                                <td><?php echo $this->badge( $s['status'], $s['status'] ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $s['score'] * 100, 0 ) ); ?>%</td>
                                <td title="<?php echo esc_attr( $s['created_at'] ); ?>"><?php echo esc_html( $this->format_date( $s['created_at'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
        <?php
    }

    // ─── Agents Tab ──────────────────────────────────────────────

    /**
     * Agents tab — status and manual control.
     */
    private function render_agents_tab() {
        $status = $this->agents->get_status();
        ?>
        <div class="wac-page-intro">
            <h2><span role="img" aria-label="Agents">🤖</span> Agents</h2>
            <p class="wac-page-intro__text"><?php esc_html_e( 'Each agent runs autonomously on its schedule. Toggle them on/off in Settings. Agents detect errors, analyse conversion data, optimise A/B tests, generate suggestions, and heal checkout issues.', 'woo-agentic-checkout' ); ?></p>
        </div>

        <?php if ( empty( $status ) ) : ?>
            <?php $this->render_empty_state(
                '🤖',
                __( 'No agents available', 'woo-agentic-checkout' ),
                __( 'Agents will be registered when the plugin is fully initialised. Check that all dependencies are loaded.', 'woo-agentic-checkout' )
            ); ?>
        <?php else : ?>
            <div class="wac-spinner-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th class="wac-sortable"><?php esc_html_e( 'Agent', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable"><?php esc_html_e( 'Status', 'woo-agentic-checkout' ); ?></th>
                            <th class="wac-sortable"><?php esc_html_e( 'Last Run', 'woo-agentic-checkout' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Run Now', 'woo-agentic-checkout' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $status as $key => $agent ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $agent['label'] ?? $key ); ?></strong>
                                    <code style="margin-left:8px;color:#888;font-size:10px;"><?php echo esc_html( $key ); ?></code>
                                </td>
                                <td>
                                    <?php
                                    if ( ! empty( $agent['enabled'] ) ) {
                                        echo '<span class="wac-status-dot wac-status-dot--active" aria-hidden="true"></span>';
                                        echo $this->badge( 'active', __( 'Enabled', 'woo-agentic-checkout' ), true );
                                    } else {
                                        echo '<span class="wac-status-dot wac-status-dot--inactive" aria-hidden="true"></span>';
                                        echo $this->badge( 'inactive', __( 'Disabled', 'woo-agentic-checkout' ), true );
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html( $agent['lastRun'] ?? __( 'Never run', 'woo-agentic-checkout' ) ); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" class="wac-agent-run-form">
                                        <?php wp_nonce_field( 'wac_manual_agent', 'wac_nonce' ); ?>
                                        <input type="hidden" name="action" value="wac_manual_agent">
                                        <input type="hidden" name="agent_key" value="<?php echo esc_attr( $key ); ?>">
                                        <input type="hidden" name="agent_label" value="<?php echo esc_attr( $agent['label'] ?? $key ); ?>">
                                        <button type="submit" class="button button-small run-agent-btn" title="<?php esc_attr_e( 'Run this agent now', 'woo-agentic-checkout' ); ?>">
                                            <span class="wac-spinner wac-spinner--sm" style="display:none;" aria-hidden="true"></span>
                                            <span role="img" aria-label="Run">▶</span> <?php esc_html_e( 'Run', 'woo-agentic-checkout' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="wac-card" style="margin-top:20px;">
            <h3>⏰ Agent Schedules</h3>
            <ul style="margin:8px 0;">
                <li><strong>Error Detector + Self-Healing:</strong> Every hour</li>
                <li><strong>Conversion Analyzer + AB Optimizer:</strong> Daily</li>
                <li><strong>Suggestion Generator:</strong> Weekly</li>
            </ul>

            <?php
            // Find the most recent lastRun across all agents.
            $latest_run = '';
            foreach ( $status as $agent ) {
                if ( ! empty( $agent['lastRun'] ) && 'Never' !== $agent['lastRun'] && 'Never run' !== $agent['lastRun'] ) {
                    if ( $agent['lastRun'] > $latest_run ) {
                        $latest_run = $agent['lastRun'];
                    }
                }
            }
            if ( ! empty( $latest_run ) ) :
                ?>
                <p class="description" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--wac-border-light);">
                    <?php
                    /* translators: %s: last agent run time */
                    echo esc_html( sprintf( __( 'Last agent run: %s ago', 'woo-agentic-checkout' ), human_time_diff( strtotime( $latest_run ) ) ) );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Settings Tab ────────────────────────────────────────────

    /**
     * Settings tab — with client-side validation attributes.
     */
    private function render_settings_tab() {
        ?>
        <div class="wac-page-intro">
            <h2><span role="img" aria-label="Settings">⚙️</span> Settings</h2>
            <p class="wac-page-intro__text"><?php esc_html_e( 'Configure LLM provider, self-healing permissions, analytics integration, and A/B testing parameters.', 'woo-agentic-checkout' ); ?></p>
        </div>
        <form method="post" action="options.php" class="wac-settings-form" novalidate>
            <?php settings_fields( 'wac_settings' ); ?>

            <div class="wac-card">
                <h3>🤖 LLM Provider</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wac_llm_provider"><?php esc_html_e( 'Provider', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <select id="wac_llm_provider" name="wac_llm_provider" required>
                                <option value="">— <?php esc_html_e( 'Select Provider', 'woo-agentic-checkout' ); ?> —</option>
                                <option value="openai" <?php selected( get_option( 'wac_llm_provider' ), 'openai' ); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected( get_option( 'wac_llm_provider' ), 'anthropic' ); ?>>Anthropic Claude</option>
                                <option value="ollama" <?php selected( get_option( 'wac_llm_provider' ), 'ollama' ); ?>>Local Ollama</option>
                                <option value="openrouter" <?php selected( get_option( 'wac_llm_provider' ), 'openrouter' ); ?>>OpenRouter</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_llm_api_key"><?php esc_html_e( 'API Key', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="password" id="wac_llm_api_key" name="wac_llm_api_key"
                                   value="<?php echo esc_attr( get_option( 'wac_llm_api_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="off"
                                   aria-describedby="wac-api-key-desc"
                                   data-wac-validate="required-if-provider-not-ollama"
                                   data-wac-provider-field="wac_llm_provider" />
                            <p class="description" id="wac-api-key-desc"><?php esc_html_e( 'Required for OpenAI/Anthropic/OpenRouter. Leave blank for local Ollama.', 'woo-agentic-checkout' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_llm_model"><?php esc_html_e( 'Model', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="text" id="wac_llm_model" name="wac_llm_model"
                                   value="<?php echo esc_attr( get_option( 'wac_llm_model', 'gpt-4o' ) ); ?>"
                                   class="regular-text" required
                                   placeholder="gpt-4o, claude-3-opus, etc." />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🛡️ Self-Healing</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wac_heal_permission_level"><?php esc_html_e( 'Permission Level', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <select id="wac_heal_permission_level" name="wac_heal_permission_level" aria-describedby="wac-heal-desc">
                                <option value="monitor" <?php selected( get_option( 'wac_heal_permission_level' ), 'monitor' ); ?>><?php esc_html_e( 'Monitor — Log only, no actions', 'woo-agentic-checkout' ); ?></option>
                                <option value="suggest" <?php selected( get_option( 'wac_heal_permission_level' ), 'suggest' ); ?>><?php esc_html_e( 'Suggest — Recommend fixes, require approval', 'woo-agentic-checkout' ); ?></option>
                                <option value="auto_patch" <?php selected( get_option( 'wac_heal_permission_level' ), 'auto_patch' ); ?>><?php esc_html_e( 'Auto-Patch — Safe CSS/JS/template fixes', 'woo-agentic-checkout' ); ?></option>
                                <option value="auto_full" <?php selected( get_option( 'wac_heal_permission_level' ), 'auto_full' ); ?>><?php esc_html_e( 'Auto-Full — Rollback settings, disable plugins', 'woo-agentic-checkout' ); ?></option>
                            </select>
                            <p class="description" id="wac-heal-desc"><strong><?php esc_html_e( 'Suggest', 'woo-agentic-checkout' ); ?></strong> <?php esc_html_e( 'is recommended for initial use. Upgrade to auto when trusted.', 'woo-agentic-checkout' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>📊 GA4 / Signals</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wac_ga4_measurement_id"><?php esc_html_e( 'GA4 Measurement ID', 'woo-agentic-checkout' ); ?></label></th>
                        <td><input type="text" id="wac_ga4_measurement_id" name="wac_ga4_measurement_id"
                                   value="<?php echo esc_attr( get_option( 'wac_ga4_measurement_id', '' ) ); ?>"
                                   class="regular-text" pattern="^G-[A-Z0-9]+$" title="<?php esc_attr_e( 'Format: G-XXXXXXXX', 'woo-agentic-checkout' ); ?>"
                                   placeholder="G-XXXXXXXX" aria-describedby="wac-ga4-desc" />
                                   <p class="description" id="wac-ga4-desc"><?php esc_html_e( 'Found in Google Analytics Admin → Data Streams → your web stream.', 'woo-agentic-checkout' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_ga4_api_secret"><?php esc_html_e( 'GA4 API Secret', 'woo-agentic-checkout' ); ?></label></th>
                        <td><input type="password" id="wac_ga4_api_secret" name="wac_ga4_api_secret"
                                   value="<?php echo esc_attr( get_option( 'wac_ga4_api_secret', '' ) ); ?>"
                                   class="regular-text" autocomplete="off" /></td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🧪 A/B Testing</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wac_ab_min_sample_size"><?php esc_html_e( 'Min Sample Size', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="number" id="wac_ab_min_sample_size" name="wac_ab_min_sample_size"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_min_sample_size', 100 ) ); ?>"
                                   min="10" max="100000" required />
                            <span class="description"><?php esc_html_e( 'per variant', 'woo-agentic-checkout' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_ab_confidence_threshold"><?php esc_html_e( 'Confidence Threshold', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="number" id="wac_ab_confidence_threshold" name="wac_ab_confidence_threshold"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_confidence_threshold', 0.95 ) ); ?>"
                                   min="0.8" max="0.99" step="0.01" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_ab_max_concurrent"><?php esc_html_e( 'Max Concurrent Tests', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="number" id="wac_ab_max_concurrent" name="wac_ab_max_concurrent"
                                   value="<?php echo esc_attr( get_option( 'wac_ab_max_concurrent', 3 ) ); ?>"
                                   min="1" max="10" required />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🔔 Notifications</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wac_notify_email_enabled"><?php esc_html_e( 'Email Notifications', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wac_notify_email_enabled" name="wac_notify_email_enabled" value="yes"
                                    <?php checked( get_option( 'wac_notify_email_enabled' ), 'yes' ); ?> />
                                <?php esc_html_e( 'Send email alerts for critical errors and suggestions', 'woo-agentic-checkout' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_notify_email"><?php esc_html_e( 'Notification Email', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="email" id="wac_notify_email" name="wac_notify_email"
                                   value="<?php echo esc_attr( get_option( 'wac_notify_email', get_option( 'admin_email' ) ) ); ?>"
                                   class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                                   aria-describedby="wac-notify-email-desc" />
                            <p class="description" id="wac-notify-email-desc"><?php esc_html_e( 'Leave blank to use the WordPress admin email.', 'woo-agentic-checkout' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wac_slack_webhook"><?php esc_html_e( 'Slack Webhook URL', 'woo-agentic-checkout' ); ?></label></th>
                        <td>
                            <input type="url" id="wac_slack_webhook" name="wac_slack_webhook"
                                   value="<?php echo esc_attr( get_option( 'wac_slack_webhook', '' ) ); ?>"
                                   class="regular-text" placeholder="https://hooks.slack.com/services/..."
                                   aria-describedby="wac-slack-desc" />
                            <p class="description" id="wac-slack-desc">
                                <?php esc_html_e( 'Create a webhook in Slack → Apps → Incoming Webhooks.', 'woo-agentic-checkout' ); ?>
                                <a href="https://api.slack.com/messaging/webhooks" target="_blank"><?php esc_html_e( 'Docs', 'woo-agentic-checkout' ); ?></a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Slack Notifications', 'woo-agentic-checkout' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wac_notify_slack_enabled" name="wac_notify_slack_enabled" value="yes"
                                    <?php checked( get_option( 'wac_notify_slack_enabled' ), 'yes' ); ?> />
                                <?php esc_html_e( 'Send Slack alerts for critical issues', 'woo-agentic-checkout' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wac-card">
                <h3>🔧 Advanced</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Data Retention', 'woo-agentic-checkout' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Logs and experiment data are retained automatically. Use the options below to clear data.', 'woo-agentic-checkout' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( __( 'Save Settings', 'woo-agentic-checkout' ) ); ?>
        </form>
        <?php
    }

    // ─── Logs Tab ────────────────────────────────────────────────

    /**
     * Logs tab — filterable log viewer with empty states.
     */
    private function render_logs_tab() {
        $logger = new \WooAgenticCheckout\Logger();
        $level  = isset( $_GET['log_level'] ) ? sanitize_key( wp_unslash( $_GET['log_level'] ) ) : '';

        // Whitelist: only known log levels are passed to the logger.
        $valid_levels = array( 'error', 'warning', 'info', 'debug' );
        if ( ! empty( $level ) && ! in_array( $level, $valid_levels, true ) ) {
            $level = '';
        }

        $logs   = $logger->get_logs( array( 'level' => $level, 'limit' => 200 ) );
        ?>
        <div class="wac-page-intro">
            <h2><span role="img" aria-label="Logs">📝</span> Event Log</h2>
            <p class="wac-page-intro__text"><?php esc_html_e( 'View plugin activity and diagnostic events. Filter by severity level to find errors, warnings, or information entries.', 'woo-agentic-checkout' ); ?></p>
        </div>

        <div class="wac-filter-bar" role="group" aria-label="Log level filter">
            <a href="<?php echo esc_url( admin_url( "?page=wac-dashboard&tab=logs" ) ); ?>" class="button <?php echo empty( $level ) ? 'button-primary' : ''; ?>" role="button" aria-pressed="<?php echo empty( $level ) ? 'true' : 'false'; ?>"><?php esc_html_e( 'All', 'woo-agentic-checkout' ); ?></a>
            <a href="<?php echo esc_url( admin_url( "?page=wac-dashboard&tab=logs&log_level=error" ) ); ?>" class="button <?php echo 'error' === $level ? 'button-primary' : ''; ?>" role="button" aria-pressed="<?php echo 'error' === $level ? 'true' : 'false'; ?>"><?php esc_html_e( 'Errors', 'woo-agentic-checkout' ); ?></a>
            <a href="<?php echo esc_url( admin_url( "?page=wac-dashboard&tab=logs&log_level=warning" ) ); ?>" class="button <?php echo 'warning' === $level ? 'button-primary' : ''; ?>" role="button" aria-pressed="<?php echo 'warning' === $level ? 'true' : 'false'; ?>"><?php esc_html_e( 'Warnings', 'woo-agentic-checkout' ); ?></a>
            <a href="<?php echo esc_url( admin_url( "?page=wac-dashboard&tab=logs&log_level=info" ) ); ?>" class="button <?php echo 'info' === $level ? 'button-primary' : ''; ?>" role="button" aria-pressed="<?php echo 'info' === $level ? 'true' : 'false'; ?>"><?php esc_html_e( 'Info', 'woo-agentic-checkout' ); ?></a>
        </div>

        <?php if ( empty( $logs ) ) : ?>
            <?php $this->render_empty_state(
                '📝',
                __( 'No log entries', 'woo-agentic-checkout' ),
                __( 'No events have been recorded yet. Events appear as agents run and actions occur.', 'woo-agentic-checkout' )
            ); ?>
        <?php else : ?>
            <div class="wac-filter-row">
                <input type="text" class="wac-table-filter" id="wac-filter-logs" placeholder="<?php esc_attr_e( 'Filter logs…', 'woo-agentic-checkout' ); ?>" aria-label="<?php esc_attr_e( 'Filter log entries', 'woo-agentic-checkout' ); ?>">
            </div>
            <table class="widefat striped" id="wac-logs-table">
                <thead>
                    <tr>
                        <th class="wac-sortable"><?php esc_html_e( 'Level', 'woo-agentic-checkout' ); ?></th>
                        <th class="wac-sortable"><?php esc_html_e( 'Event', 'woo-agentic-checkout' ); ?></th>
                        <th class="wac-sortable"><?php esc_html_e( 'Context', 'woo-agentic-checkout' ); ?></th>
                        <th class="wac-sortable"><?php esc_html_e( 'Time', 'woo-agentic-checkout' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) : ?>
                        <tr class="wac-log-<?php echo esc_attr( $log['level'] ); ?>">
                            <td><?php echo $this->badge( $log['level'], $log['level'] ); ?></td>
                            <td><code><?php echo esc_html( $log['event'] ); ?></code></td>
                            <td class="wac-log-context">
                                <pre title="<?php echo esc_attr( $log['context'] ); ?>"><?php echo esc_html( substr( $log['context'], 0, 200 ) ); ?></pre>
                            </td>
                            <td title="<?php echo esc_attr( $log['created_at'] ); ?>"><?php echo esc_html( $this->format_date( $log['created_at'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Format a date/time string for display.
     *
     * @param string $date_str
     * @return string
     */
    private function format_date( $date_str ) {
        if ( empty( $date_str ) || '0000-00-00 00:00:00' === $date_str ) {
            return '—';
        }
        $timestamp = strtotime( $date_str );
        if ( false === $timestamp ) {
            return $date_str;
        }
        /* translators: %s: human-readable time difference */
        return sprintf( __( '%s ago', 'woo-agentic-checkout' ), human_time_diff( $timestamp ) );
    }
}
