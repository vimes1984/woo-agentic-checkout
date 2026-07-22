# Woo Agentic Checkout — Launch & Build Plan

## Phase 0: Skeleton (Week 1)
- [x] Project scaffold (directory structure, autoloader, main plugin file)
- [x] Database schema (7 tables: logs, experiments, variants, events, beacon, suggestions, heal log)
- [x] Core plugin bootstrap + service wiring
- [x] All agent classes (Conversion Analyzer, AB Optimizer, Error Detector, Suggestion Generator, Self-Healing Agent)
- [x] LLM Client (OpenAI, Anthropic, Ollama, OpenRouter)
- [x] A/B Test Manager with Bayesian analysis
- [x] Signal Collector (WooCommerce orders + GA4 Measurement Protocol)
- [x] Self-Healer with permission levels
- [x] Suggestion Engine with auto-apply
- [x] Admin UI (6 tabs: Dashboard, Experiments, Suggestions, Agents, Settings, Logs)
- [x] Public JS beacon (checkout telemetry)
- [x] Checkout modifier (field reorder, remove, hide)

➡ **YOU ARE HERE**

## Phase 1: Core Functionality (✅ Completed — Swarm Batch 1 + 2026-07-22)
- [x] **LLM prompt tuning** — Structured JSON schema in all 5 agent prompts, `json_schema` mode for OpenAI/OpenRouter, `format: json` for Ollama
- [x] **AJAX endpoints** — All admin actions via `wp_ajax_wac_*` with nonce + capability checks
- [x] **GA4 Data API** — OAuth2 JWT bearer token flow for GA4 Data API, Measurement Protocol for real-time
- [x] **Error collection** — `set_error_handler()`, `register_shutdown_function()`, `set_exception_handler()` with recursive guard and file fallback
- [x] **Admin post handlers** — All `admin_post_wac_*` handlers registered with CSRF protection
- [x] **Uninstall routine** — Drops 7 tables, cleans transients/options, removes cron jobs

## Phase 2: Production Hardening (✅ Completed — Swarm Batches 2-6 + 2026-07-22)
- [x] **Rate limiting** — 60 LLM calls/hour cap, 3s AJAX cooldown, token budget estimation (120K max), `_transient`-tracked
- [x] **Caching** — LLM results cached via `set_transient()`, agent run deduplication (no concurrent runs)
- [x] **Notification system** — Email (`wp_mail`) + Slack webhook integration, admin dashboard notices with `wac_msg` param
- [x] **Multi-language support** — `__()`, `_e()`, `esc_html__()` etc with `woo-agentic-checkout` text domain
- [x] **Nonce verification** — All endpoints: `check_admin_referer`, `wp_verify_nonce`, `wp_nonce_field` on all forms
- [x] **Sanitization audit** — `sanitize_*()`, `intval()`, `absint()`, `wp_unslash()` on all inputs; `esc_*()` on all outputs
- [x] **Capability checks** — `current_user_can('manage_woocommerce')` on every admin route and AJAX handler

➡ **YOU ARE HERE**

## Phase 3: Agent Intelligence (Weeks 6-8)
- [ ] **Long-term memory** — Agent query history + response caching so LLM can reference past decisions
- [ ] **Multi-variate testing** — Beyond simple A/B to multi-armed bandit
- [ ] **Session recording analysis** — Optional integration with Hotjar/Clarity for UX heatmap data
- [ ] **Industry benchmarks** — LLM comparisons against WooCommerce store averages
- [ ] **Segmentation** — Convert by traffic source, device, returning vs new customer
- [ ] **Seasonal awareness** — Adjust for known seasonality in checkout behaviour

## Phase 4: Advanced Autonomy (Weeks 9-12)
- [ ] **Git-based rollback** — Track template/setting changes in version control
- [ ] **Confidence-based experiment acceleration** — Thompson sampling for faster winner identification
- [ ] **Cross-store learning** — Anonymized benchmarks across installations (opt-in)
- [ ] **A/B test scheduling** — Time-based experiment activation (holiday mode)
- [ ] **CLI commands** — `wp wac agent run`, `wp wac experiment list`, etc.
- [ ] **REST API** — Full CRUD for experiments and suggestions (headless WP support)

## Phase 5: Ecosystem (Weeks 13-16)
- [ ] **Plugin review** — WordPress.org plugin directory submission prep
- [ ] **Documentation site** — Full user docs with screenshots
- [ ] **Video tutorials** — Setup walkthrough + agent explanations
- [ ] **Premium tier** — Advanced LLM features, priority support, custom model training
- [ ] **Freemium model** — Suggest-only free tier, auto-patch paid tier

---

## Go-to-Market Strategy

### Pre-Launch (Weeks 1-8)
1. **GitHub repo** — Public under `vimes1984/woo-agentic-checkout` (done)
2. **PluginPal / WooCommerce showcase** — Register interest
3. **Beta testers** — Recruit from WooCommerce communities (Reddit r/woocommerce, WP Tavern)
4. **Landing page** — Simple one-pager with feature list + LLM-powered demo video
5. **Launch checklist** — Code review, security audit, performance benchmark

### Launch Day
1. **WordPress.org submission** (if GPL-compatible — yes, GPLv3)
2. **ProductHunt launch** — "The first AI agent for WooCommerce checkout"
3. **Reddit AMA / Show HN** — "I built an LLM agent that auto-optimises checkout flows"
4. **Email list** — Notify beta testers + WooCommerce newsletter subscribers

### Post-Launch
1. **Weekly changelog** — Transparent development blog
2. **Case studies** — Publish conversion improvement stories from beta testers
3. **Affiliate program** — 20% rev share for referrals
4. **Managed hosting partnership** — WP Engine, Kinsta, etc. (auto-install for customers)

---

## Architecture Decisions Log

| Decision | Rationale |
|----------|-----------|
| Bayesian over frequentist A/B testing | Better with small samples, intuitive probability outputs |
| Structured JSON from LLM | Reliable parsing, no regex matching on prose |
| Signal Collector abstraction | Swap GA4 for any analytics provider without touching agents |
| Permission-level healing | Let store owners start in "suggest" mode and upgrade gradually |
| Local Ollama support | Privacy-conscious stores, no ongoing API costs |
| Dedicated DB tables over post meta | Performance on high-volume WooCommerce stores |
| Singleton Core | Standard WordPress plugin pattern, avoids global state issues |

---

## Why This Wins

1. **It's agentic, not reactive.** Most optimisation plugins require manual setup. This watches, learns, and acts.
2. **LLM reasoning beats hardcoded rules.** A rule engine can't infer "maybe the payment fields are confusing because drop-off happens there." An LLM can.
3. **Permission safety.** Nobody gets auto-wrecked. Start in Suggest mode, upgrade to Auto-Patch once you trust it.
4. **Self-healing.** When something breaks at 3 AM during a flash sale, this fixes it before you wake up.

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| LLM API costs | Budget caps, local model fallback, request caching |
| Bad LLM suggestions | Confidence threshold (0.9+ for auto-apply), always suggest first |
| Wrong experiment declared winner | Bayesian probability, not point estimate; manual override always available |
| Performance overhead | Agent runs on cron (not real-time), DB queries indexed, minimal frontend impact |
| WooCommerce version compat | Tested against WC 8.x+, minimum version check on activation |
