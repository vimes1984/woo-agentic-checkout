# Woo Agentic Checkout — Architecture

## Vision

An LLM-powered, agentic WooCommerce plugin that autonomously optimises the checkout
experience by learning from real sales signals (GA4 goals, conversion events, revenue),
running A/B experiments, and either auto-applying or suggesting improvements.

---

## Core Concepts

```
┌──────────────────────────────────────────────┐
│              AGENT ORCHESTRATOR               │
│  (class-agent-manager.php)                    │
│                                               │
│  ┌──────────┐ ┌──────────┐ ┌──────────────┐  │
│  │Conversion │ │AB Optimi │ │Error Detector│  │
│  │Analyzer   │ │zer       │ │              │  │
│  └──────────┘ └──────────┘ └──────────────┘  │
│  ┌──────────┐ ┌──────────┐                    │
│  │Suggestion│ │Self-Heal │                    │
│  │Generator │ │ing Agent │                    │
│  └──────────┘ └──────────┘                    │
└──────────┬───────────────────────────┬────────┘
           │                           │
           ▼                           ▼
┌──────────────────┐     ┌──────────────────────┐
│  SIGNAL COLLECTOR │     │   A/B TEST MANAGER   │
│  GA4 + Sales + UX  │     │  Experiment Registry │
│  + Error Logs      │     │  Traffic Splitting   │
└──────────────────┘     └──────────────────────┘
           │                           │
           ▼                           ▼
┌──────────────────────────────────────────────┐
│              LLM CLIENT                       │
│  OpenAI / Anthropic / local LLM              │
│  Prompt templates per agent                  │
│  Structured JSON output parsing              │
└──────────────────────────────────────────────┘
           │
           ▼
┌──────────────────────────────────────────────┐
│           SUGGESTION ENGINE                   │
│  Auto-apply (permissions) or queue           │
│  for admin review                            │
└──────────────────────────────────────────────┘
```

## Agent Descriptions

### 1. Conversion Analyzer
- **Schedule**: Daily
- **Input**: GA4 goal completions, WooCommerce order data, funnel drop-off rates
- **Output**: Conversion delta report, attributing changes to experiments or regressions
- **LLM prompt**: Given conversion rate before/after + experiment assignment, determine statistical significance and recommend action

### 2. AB Optimizer
- **Schedule**: Every 6 hours (or on sufficient traffic)
- **Input**: Current active experiments with per-variant conversion data
- **Output**: Winner/loser declarations, variant rotation, new experiment proposals
- **LLM prompt**: Analyse Bayesian probability of each variant being best; suggest next experiment hypothesis

### 3. Error Detector
- **Schedule**: Continuous (on error event) + every 15 min health check
- **Input**: JS console errors, PHP error logs, AJAX failures, timeout logs, abandoned cart telemetry
- **Output**: Error classification (transient / persistent / critical), root cause guess
- **LLM prompt**: Given error stack + context, classify severity and hypothesise root cause

### 4. Self-Healing Agent
- **Schedule**: On Error Detector alert, or hourly passive scan
- **Input**: Error classification from Error Detector, current plugin state
- **Output**: Healing action (rollback setting, revert template, disable conflicting plugin) or escalation
- **LLM prompt**: You are a WooCommerce expert. Given the error and current config, what single change has highest probability of fixing? Apply if permission level allows.

### 5. Suggestion Generator
- **Schedule**: Weekly
- **Input**: All experiment results, conversion trends, user session recordings (if available), industry best-practices
- **Output**: Ranked list of checkout improvements with expected conversion impact estimate
- **LLM prompt**: Based on all collected data, suggest 3–5 checkout improvements with estimated lift.

## Signal Sources

| Source | Method | Data Collected |
|--------|--------|----------------|
| GA4 (Google Analytics) | Measurement Protocol + REST API | Goal completions, events, user segments |
| WooCommerce Orders | `wc_get_orders()` | Revenue, conversion rate, AOV, products |
| Checkout Abandonment | Local JS beacon + AJAX | Step drop-off, field errors, time-per-step |
| PHP Error Log | `error_get_last()`, custom handler | Fatal errors, warnings, deprecations |
| JS Console | `window.onerror` + `window.addEventListener('unhandledrejection')` | JS errors, script loading failures |
| WP Cron / Hooks | Hook watcher | Hook execution time, conflicts, timeouts |

## A/B Testing Model

```
AB_TEST_VARIANTS TABLE
┌─────────────────────────────────────┐
│ id | experiment_key | variant_name  │
│     | traffic_pct    | status       │
│     | config_snapshot | created_at  │
│     | ended_at       | winner_flag  │
├─────────────────────────────────────┤
│ AB_TEST_EVENTS TABLE               │
│ id | variant_id | event_type       │
│     | event_data (JSON) | user_id   │
│     | session_id | created_at      │
├─────────────────────────────────────┤
│ AB_TEST_RESULTS TABLE              │
│ id | variant_id | conversions      │
│     | impressions | revenue         │
│     | cr | lift_pct | confidence   │
│     | computed_at                   │
└─────────────────────────────────────┘
```

- Traffic split via randomised cookie + URL param
- Bayesian / frequentist stats computed by AB Optimizer agent
- Minimum sample size enforcement (configurable)
- Winner auto-promotion after 95% confidence with minimum 100 conversions per variant

## Self-Healing Levels

| Level | Permission | Actions |
|-------|-----------|---------|
| `monitor` | None | Log only, alert admin |
| `suggest` | Low | Suggest fix, require approval |
| `auto_patch` | Medium | Auto-apply safe fixes (CSS, JS, template overrides) |
| `auto_full` | High | Auto-rollback settings, disable plugins, restore DB |

## Suggestion Engine Pipeline

```
Collect signals → Agent analysis → LLM synthesis → Ranked suggestions →
  └→ [auto_apply?] → Apply or queue for review
  └→ [confidence > 0.9 AND permission >= auto_patch] → Auto-apply
```

## WordPress Integration

- **Hooks**: All checkout filters (`woocommerce_checkout_fields`, `woocommerce_before_checkout_form`, etc.)
- **Template overrides**: Child-theme-safe, per-variant
- **Settings page**: Under WooCommerce → Settings → Agentic Checkout
- **Dashboard widget**: At-a-glance conversion health, active experiments, pending suggestions

## LLM Provider Strategy

1. **OpenAI** (default, most reliable for structured JSON)
2. **Anthropic Claude** (fallback, better at reasoning about complex UX)
3. **Local LLM** (via Ollama, for privacy-sensitive stores)
4. **OpenRouter** (multi-provider routing)

Structured output via function-calling or JSON mode. Prompt templates stored in `agents/promets/` (intentionally misspelled to avoid WPCS namespace conflicts with actual `prompts` directory scanning).

## Security Boundaries

- All agent actions are logged with before/after snapshots
- DB credentials never passed to LLM (aggregate metrics only)
- Customer PII never sent to LLM (hash-based session IDs)
- Rollback capability for every auto-applied change
- Rate-limited API calls to LLM provider
