# Swarm Batch 5: Agent Prompts & LLM Integration

**Date:** 2026-07-22
**Total Iterations:** 20
**Files Modified:** 8 core files across agents/, includes/

## Summary

Full audit and hardening of all 5 AI agents and their LLM integration pipeline.
Each agent now produces standardized JSON results, handles cold-start / no-data
gracefully, and the LLM client has robust error resilience.

## Improvements by Category

### 1. JSON Schema in Prompts (All 5 Agents)
- Every system prompt now specifies **exact JSON output schema** with example structures
- Prompts include `Output ONLY valid JSON matching this exact schema:` directive
- Schema covers verdicts, analyses, experiment proposals, heal plans, and suggestions

### 2. Cold Start / No-Data Handling
- `ConversionAnalyzer`: Returns `no_data` verdict when zero orders in 24h/7d
- `ABOptimizer`: Returns early with graceful message when no experiments exist
- `ErrorDetector`: Returns clean "no errors" result when zero errors detected
- `SuggestionGenerator / SuggestionEngine`: Skips LLM call when context shows zero orders + zero traffic + zero errors
- `SelfHealingAgent`: Returns early when all health checks pass and no recent errors

### 3. LLM Timeout & Error Resilience
- `LLMClient`: CURLOPT_TIMEOUT=30s with connect timeout=10s
- `LLMClient`: Retry once with simpler prompt on invalid JSON response
- `LLMClient`: `clean_json_response()` strips markdown fences and prefixes
- `LLMClient`: Specific 429 rate-limit handling with descriptive message
- All agents: try/catch on every LLM call with graceful array() fallback

### 4. Invalid JSON Detection
- `json_last_error()` + `JSON_ERROR_NONE` check after every `json_decode()`
- Fallback: `clean_json_response()` strips ```json fences and text prefixes
- Retry path also uses cleanup before second parse
- `SuggestionEngine`: `json_last_error()` check on action_data decode in `apply_suggestion()`

### 5. Rate Limiting
- `LLMClient.MAX_CALLS_PER_HOUR = 60` (configurable via transient)
- Hourly counter tracked via `set_transient()` with 1-hour expiry
- `check_rate_limit()` before every LLM call
- `get_hourly_call_count()` and `get_remaining_calls()` for observability

### 6. Token Budget Estimation
- `estimate_tokens()` using 0.38 token:char ratio heuristic
- Combined prompt checked against `MAX_PROMPT_TOKENS = 120000`
- User prompt auto-truncated when over budget (leaves 4000 tokens for system)
- `wac_llm_token_estimate` action fired for monitoring

### 7. Retry Logic
- On JSON parse failure: retry once with appended `IMPORTANT: You MUST return ONLY valid JSON` instruction
- On HTTP 429: specific rate-limit error message
- All agent LLM calls wrapped in try/catch with empty/safe fallback

### 8. Duplicate Agent Runs Prevention
- `AgentManager.running_agents[]` flag per agent
- Returns standardized "already running" result instead of silent skip
- `finally` block always clears the flag

### 9. Standardized Agent Result Format
All agents now return `{success: bool, actions: int, errors: array, summary: string}`:
- `ConversionAnalyzer`: + funnel, analysis, conversion rates
- `ABOptimizer`: + experiments_analysed, winners_declared, recommendations
- `ErrorDetector`: + issues, critical_count, total_errors
- `SuggestionGenerator`: + suggestions, auto_applied, context keys
- `SelfHealingAgent`: + healed, failed, actions_taken, health_checks
- `AgentManager.normalize_result()` auto-converts legacy formats

### 10. Error Bubbling to AgentManager
- All agent exceptions caught in `AgentManager.run_agents()` with full context
- Error metadata: error_class, error_code, error_timestamp, consecutive_fails
- Unifies logging through `$services['logger']`

### 11. Consecutive Failure Alerts
- `AgentManager.failure_counts[]` persisted to wp_options
- `FAILURE_ALERT_THRESHOLD = 3` — fires `wac_agent_consecutive_failures` hook
- Critical log entry when threshold exceeded

### 12. Prompt Context Window
- Character-length check in `ConversionAnalyzer` (>80K chars triggers warning)
- `LLMClient` token estimation auto-truncates oversized prompts
- Agent REVISION constants for prompt version tracking

### 13. Structured Output Mode
- OpenAI: `json_schema` with `strict: true`
- Anthropic: tool_choice + tools with input_schema
- OpenRouter: `json_schema` (upgraded from `json_object`)
- Ollama: `format: json` + schema pass-through

### 14. Suggestion Engine Score Normalization
- `normalize_score()` handles percentage strings ("85%"), floats >1, null
- `min(1.0, max(0.0, score))` clamping
- Duplicate detection: skips if pending suggestion with same title + action_type exists

### 15. Missing Edge Cases
- Empty experiments list → graceful return with summary
- Zero traffic funnel → "cold start" annotation, not anomaly alert
- Null/malformed funnel → coerced to empty array
- Missing service dependencies → graceful failure with error message
- rollback/cooldown state → prevents re-application of rolled-back suggestions
- Heal cooldown → 5-minute window prevents thrashing on same issue

## Files Modified

| File | Key Changes |
|------|-------------|
| `agents/class-conversion-analyzer.php` | JSON schema in prompt, cold-start "no_data", standard result format, prompt size warning, service guards |
| `agents/class-ab-optimizer.php` | JSON schema in prompt, cold-start data guard, empty experiments guard, null settings guards, standard result format |
| `agents/class-error-detector.php` | JSON schema + cold-start in run(), funnel zero-traffic edge case, LLM failure logging, service guards |
| `agents/class-suggestion-generator.php` | Standard result format, cold-start context with \_meta, null guards on signals data |
| `agents/class-self-healing-agent.php` | JSON schema in both heal plan prompts, cold-start early return, heal cooldown, standard result format, service guards |
| `includes/class-llm-client.php` | Rate limiting, token budget estimation, retry logic, clean_json_response, 429 handling, latency stats, input validation, Ollama JSON format fix, OpenRouter json_schema |
| `includes/class-agent-manager.php` | Concurrent run guard, failure tracking with alert, error bubbling, result normalization, agent cooldown, capabilities in status |
| `includes/class-suggestion-engine.php` | Score normalization, duplicate detection, suggestion_exists() query, input validation, REVISION constant, \@throws annotations |

## Commit Log (20 iterations)

```
Batch5 iter 1:  JSON schema + no_data verdict in ConversionAnalyzer prompt
Batch5 iter 2:  Cold-start fallback + standard result format in ConversionAnalyzer
Batch5 iter 3:  Rate limiting, token budget, retry, timing metrics in LLMClient
Batch5 iter 4:  Concurrent guard, failure tracking, error bubbling in AgentManager
Batch5 iter 5:  JSON schema + cold start + standard format in ErrorDetector
Batch5 iter 6:  Standard format + cold-start context + score normalization in SuggestionEngine
Batch5 iter 7:  Standard format + cold start + JSON schema in SelfHealingAgent + ABOptimizer
Batch5 iter 8:  clean_json_response, OpenRouter json_schema, 429 handling
Batch5 iter 9:  Duplicate suggestion detection, prompt size warning, LLM failure logging
Batch5 iter 10: Empty experiments guard in ABOptimizer
Batch5 iter 11: Service dependency guards in all 5 agents
Batch5 iter 12: Latency tracking, slow-call alerts, input validation
Batch5 iter 13: REVISION constants, improved PHPDoc on all agents
Batch5 iter 14: Settings null guards, is_countable safety
Batch5 iter 15: Agent cooldown (60s) in AgentManager
Batch5 iter 16: Input validation, Ollama JSON format, @throws annotations
Batch5 iter 17: get_capabilities() on all agents, enriched AgentManager.get_status()
Batch5 iter 18: Heal cooldown (5min) in SelfHealingAgent
Batch5 iter 19: SuggestionEngine input validation + null guards in context
Batch5 iter 20: Funnel zero-traffic edge case + error metadata enrichment
```
