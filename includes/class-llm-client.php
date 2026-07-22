<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * LLM Client — handles communication with upstream LLM providers.
 *
 * Supports OpenAI, Anthropic Claude, local Ollama, and OpenRouter.
 * All requests use structured JSON output for reliable parsing.
 *
 * @since 0.1.0-alpha
 */
class LLMClient {

    /**
     * Settings instance.
     *
     * @var Settings
     */
    private $settings;

    /**
     * Available providers.
     */
    const PROVIDERS = array(
        'openai'    => 'OpenAI',
        'anthropic' => 'Anthropic Claude',
        'ollama'    => 'Local Ollama',
        'openrouter' => 'OpenRouter',
    );

    /**
     * Max LLM calls per hour before rate-limiting.
     */
    const MAX_CALLS_PER_HOUR = 60;

    /**
     * Max tokens for combined prompt to avoid context window issues.
     */
    const MAX_PROMPT_TOKENS = 120000;

    /**
     * Rough token:char ratio for estimation.
     */
    const TOKEN_RATIO = 0.38;

    /**
     * Latency threshold in seconds before logging a slow-call warning.
     */
    const SLOW_CALL_THRESHOLD = 15.0;

    /**
     * @var int Hourly call counter (tracked via transient).
     */
    private $call_count = -1;

    /**
     * Running latency stats for the current request cycle.
     *
     * @var array{count: int, total_sec: float, max_sec: float}
     */
    private $latency_stats = array(
        'count'    => 0,
        'total_sec' => 0.0,
        'max_sec'   => 0.0,
    );

    /**
     * @param Settings $settings
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->call_count = (int) get_transient( 'wac_llm_calls_hourly' );
    }

    /**
     * Cache TTL in seconds (default 1 hour for analysis, can be overridden).
     *
     * @var int
     */
    private $cache_ttl = HOUR_IN_SECONDS;

    /**
     * Send a prompt to the configured LLM and get structured JSON response.
     * Results are cached by content hash to avoid duplicate API calls.
     * Includes: rate limiting, token budget estimation, retry with simpler prompt,
     * and json_last_error() validation with fallback.
     *
     * @param string $system_prompt   System-level instructions.
     * @param string $user_prompt     User message / data.
     * @param array  $response_schema Optional JSON schema specification.
     * @param int    $cache_ttl       Override cache TTL in seconds (0 = no cache).
     *
     * @return array Parsed JSON response.
     *
     * @throws \RuntimeException     On API failure, invalid response, rate limit exceeded.
     * @throws \InvalidArgumentException On missing required parameters.
     */
    public function analyze( string $system_prompt, string $user_prompt, array $response_schema = array(), int $cache_ttl = null ): array {
        // Input validation.
        if ( '' === trim( $system_prompt ) ) {
            throw new \InvalidArgumentException( 'System prompt cannot be empty.' );
        }
        if ( '' === trim( $user_prompt ) ) {
            throw new \InvalidArgumentException( 'User prompt cannot be empty.' );
        }

        $cache_ttl = $cache_ttl ?? $this->cache_ttl;
        $start_time = microtime( true );

        // ── Check cache first ───────────────────────────────────────
        if ( $cache_ttl > 0 ) {
            $cache_key = $this->build_cache_key( $system_prompt, $user_prompt, $response_schema );
            $cached    = get_transient( $cache_key );
            if ( false !== $cached ) {
                do_action( 'wac_llm_cache_hit', $cache_key );
                return $cached;
            }
        }

        $provider = $this->settings->get( 'llm_provider', 'openai' );
        $api_key  = $this->settings->get( 'llm_api_key', '' );
        $model    = $this->settings->get( 'llm_model', 'gpt-4o' );

        if ( empty( $api_key ) && 'ollama' !== $provider ) {
            throw new \RuntimeException( 'LLM API key not configured.' );
        }

        // ── Token budget estimation ─────────────────────────────────
        $combined   = $system_prompt . "\n" . $user_prompt;
        $est_tokens = $this->estimate_tokens( $combined );

        if ( $est_tokens > self::MAX_PROMPT_TOKENS ) {
            $trunc_msg = 'Combined prompt exceeds ' . self::MAX_PROMPT_TOKENS . ' estimated tokens (' . $est_tokens . '). Truncating user prompt.';
            do_action( 'wac_log_warning', 'llm_token_budget_exceeded', $trunc_msg );
            // Truncate user prompt to fit within budget (leave 4000 for system + overhead).
            $max_user_chars = (int) ( ( self::MAX_PROMPT_TOKENS - 4000 ) / self::TOKEN_RATIO );
            $user_prompt    = mb_substr( $user_prompt, 0, $max_user_chars );
            $est_tokens     = $this->estimate_tokens( $system_prompt . "\n" . $user_prompt );
        }

        do_action( 'wac_llm_token_estimate', $est_tokens, $provider, $model );

        // ── Rate limiting ───────────────────────────────────────────
        if ( ! $this->check_rate_limit() ) {
            throw new \RuntimeException(
                'LLM rate limit exceeded: more than ' . self::MAX_CALLS_PER_HOUR . ' calls in the last hour.'
            );
        }

        $method = "call_{$provider}";

        if ( ! method_exists( $this, $method ) ) {
            throw new \RuntimeException( "Unsupported LLM provider: {$provider}" );
        }

        // ── Primary call ────────────────────────────────────────────
        $response = $this->$method( $api_key, $model, $system_prompt, $user_prompt, $response_schema );

        // ── Validate JSON with json_last_error() ────────────────────
        // Try parsing raw first, then apply cleanup if it fails.
        $parsed = json_decode( $response, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            // Attempt cleanup of potential markdown-wrapped or prefixed JSON.
            $cleaned = $this->clean_json_response( $response );
            $parsed  = json_decode( $cleaned, true );
        }

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $err_msg = 'LLM returned invalid JSON: ' . json_last_error_msg();
            do_action( 'wac_log_warning', 'llm_json_parse_failed', $err_msg );

            // Retry once with a simpler prompt that demands valid JSON.
            $retry_prompt = $user_prompt . "\n\nIMPORTANT: You MUST return ONLY valid JSON. No markdown, no code fences, no extra text. Parse this request and respond with the exact schema requested.";
            $response = $this->$method( $api_key, $model, $system_prompt, $retry_prompt, $response_schema );

            // Clean retry response as well.
            $cleaned = $this->clean_json_response( $response );
            $parsed  = json_decode( $cleaned, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                throw new \RuntimeException(
                    'LLM returned invalid JSON after retry: ' . json_last_error_msg()
                );
            }
        }

        // ── Track rate limit ────────────────────────────────────────
        $this->increment_rate_limit();

        // ── Log timing metrics ──────────────────────────────────────
        $elapsed = microtime( true ) - $start_time;
        $this->latency_stats['count']++;
        $this->latency_stats['total_sec'] += $elapsed;
        $this->latency_stats['max_sec']    = max( $this->latency_stats['max_sec'], $elapsed );

        // Warn if call was unusually slow.
        if ( $elapsed > self::SLOW_CALL_THRESHOLD ) {
            do_action( 'wac_llm_slow_call', array(
                'provider'   => $provider,
                'model'      => $model,
                'elapsed'    => round( $elapsed, 4 ),
                'est_tokens' => $est_tokens,
            ) );
        }

        do_action( 'wac_llm_timing', array(
            'provider'   => $provider,
            'model'      => $model,
            'elapsed'    => round( $elapsed, 4 ),
            'est_tokens' => $est_tokens,
            'cache_ttl'  => $cache_ttl,
        ) );

        // ── Store in cache ──────────────────────────────────────────
        if ( $cache_ttl > 0 && isset( $cache_key ) ) {
            set_transient( $cache_key, $parsed, $cache_ttl );
        }

        return $parsed;
    }

    /**
     * Call OpenAI API.
     */
    private function call_openai( string $api_key, string $model, string $system, string $user, array $schema ): string {
        $body = array(
            'model'       => $model,
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => $user ),
            ),
            'temperature' => 0.3,
        );

        if ( ! empty( $schema ) ) {
            $body['response_format'] = array(
                'type' => 'json_schema',
                'json_schema' => array(
                    'name'   => 'structured_response',
                    'schema' => $schema,
                    'strict' => true,
                ),
            );
        }

        return $this->http_post(
            'https://api.openai.com/v1/chat/completions',
            $body,
            array( 'Authorization: Bearer ' . $api_key )
        );
    }

    /**
     * Call Anthropic Claude API.
     */
    private function call_anthropic( string $api_key, string $model, string $system, string $user, array $schema ): string {
        $body = array(
            'model'      => $model,
            'system'     => $system,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $user ),
            ),
            'max_tokens' => 4096,
            'temperature' => 0.3,
        );

        if ( ! empty( $schema ) ) {
            $body['tool_choice'] = array( 'type' => 'tool', 'name' => 'respond' );
            $body['tools'] = array(
                array(
                    'name'        => 'respond',
                    'description' => 'Structured response',
                    'input_schema' => $schema,
                ),
            );
        }

        $response = $this->http_post(
            'https://api.anthropic.com/v1/messages',
            $body,
            array(
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
            )
        );

        // Anthropic returns tool use in a different format. Extract content.
        $decoded = json_decode( $response, true );

        if ( ! empty( $schema ) && isset( $decoded['content'] ) ) {
            foreach ( $decoded['content'] as $block ) {
                if ( 'tool_use' === $block['type'] && 'respond' === $block['name'] ) {
                    return wp_json_encode( $block['input'] );
                }
            }
        }

        // Fallback: standard text content.
        $text = '';
        if ( isset( $decoded['content'] ) ) {
            foreach ( $decoded['content'] as $block ) {
                if ( 'text' === $block['type'] ) {
                    $text .= $block['text'];
                }
            }
        }
        return $this->clean_json_response( $text ?: $response );
    }

    /**
     * Call local Ollama.
     */
    private function call_ollama( string $api_key, string $model, string $system, string $user, array $schema ): string {
        $base_url = $this->settings->get( 'llm_ollama_url', 'http://localhost:11434' );

        $body = array(
            'model'    => $model,
            'system'   => $system . "\n\nIMPORTANT: Respond ONLY with valid JSON. No markdown, no code fences, no extra text.",
            'prompt'   => $user,
            'stream'   => false,
            'format'   => 'json',
            'options'  => array(
                'temperature' => 0.3,
                'num_predict' => 4096,
            ),
        );

        if ( ! empty( $schema ) ) {
            $body['format'] = $schema;
        }

        return $this->http_post(
            "{$base_url}/api/generate",
            $body,
            array()
        );
    }

    /**
     * Call OpenRouter (multi-provider routing).
     */
    private function call_openrouter( string $api_key, string $model, string $system, string $user, array $schema ): string {
        $body = array(
            'model'       => $model,
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => $user ),
            ),
            'temperature' => 0.3,
        );

        if ( ! empty( $schema ) ) {
            // OpenRouter supports json_schema via OpenAI-compatible route.
            $body['response_format'] = array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'structured_response',
                    'schema' => $schema,
                    'strict' => true,
                ),
            );
        } else {
            $body['response_format'] = array(
                'type' => 'json_object',
            );
        }

        return $this->http_post(
            'https://openrouter.ai/api/v1/chat/completions',
            $body,
            array(
                'Authorization: Bearer ' . $api_key,
                'HTTP-Referer: ' . home_url(),
            )
        );
    }

    /**
     * Clean raw LLM response — strip markdown code fences and leading/trailing whitespace
     * so json_decode can succeed.
     *
     * @param string $raw
     * @return string
     */
    private function clean_json_response( string $raw ): string {
        $raw = trim( $raw );
        // Strip ```json ... ``` fences.
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = preg_replace( '/\s*```$/', '', $raw );
        // Strip any leading non-{ characters (e.g. "Here is the JSON:").
        $first_brace = strpos( $raw, '{' );
        if ( false !== $first_brace && $first_brace > 0 ) {
            $raw = substr( $raw, $first_brace );
        }
        $last_brace = strrpos( $raw, '}' );
        if ( false !== $last_brace && $last_brace < strlen( $raw ) - 1 ) {
            $raw = substr( $raw, 0, $last_brace + 1 );
        }
        return trim( $raw );
    }

    /**
     * Perform HTTP POST request using WordPress HTTP API for proxy/SSL support.
     *
     * @param string $url     API endpoint.
     * @param array  $body    JSON-serialisable request body.
     * @param array  $headers Additional HTTP headers as flat string array (e.g. ["Authorization: Bearer x"]).
     *
     * @return string Raw response body.
     *
     * @throws \RuntimeException On transport failure.
     */
    private function http_post( string $url, array $body, array $headers = array() ): string {
        $default_headers = array(
            'Content-Type' => 'application/json',
        );

        // Convert flat "Key: Value" array entries to associative format for wp_remote_post.
        foreach ( $headers as $header_line ) {
            if ( is_string( $header_line ) && false !== strpos( $header_line, ':' ) ) {
                $parts = explode( ':', $header_line, 2 );
                $key   = trim( $parts[0] );
                $value = trim( $parts[1] );
                $default_headers[ $key ] = $value;
            }
        }

        $args = array(
            'method'      => 'POST',
            'body'        => wp_json_encode( $body ),
            'headers'     => $default_headers,
            'timeout'     => 30,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking'    => true,
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            do_action( 'wac_llm_request_error', $url, $error_message );
            throw new \RuntimeException( "LLM request failed: {$error_message}" );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );

        if ( $http_code >= 400 ) {
            // Rate-limit handling: 429 means we should back off.
            if ( 429 === $http_code ) {
                do_action( 'wac_llm_rate_limited', $url );
                throw new \RuntimeException(
                    'LLM API rate limited (429). Retry after ' . gmdate( 'Y-m-d H:i:s', time() + 60 )
                );
            }
            throw new \RuntimeException(
                "LLM API returned {$http_code}: " . substr( $body_raw, 0, 500 )
            );
        }

        $decoded = json_decode( $body_raw, true );

        // Extract content from standard chat completion format.
        if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
            $content = $decoded['choices'][0]['message']['content'];
            // If content is already JSON wrapped in backticks, clean it.
            if ( null !== json_decode( $content ) ) {
                return $content;
            }
            return $this->clean_json_response( $content );
        }

        if ( isset( $decoded['response'] ) ) {
            return $this->clean_json_response( $decoded['response'] );
        }

        // Fallback: return raw (Anthropic or other) after cleaning.
        return $this->clean_json_response( $body_raw );
    }

    /**
     * Build a cache key from the prompt content.
     *
     * @param string $system_prompt
     * @param string $user_prompt
     * @param array  $schema
     *
     * @return string Transient key.
     */
    private function build_cache_key( string $system_prompt, string $user_prompt, array $schema ): string {
        $raw = $system_prompt . '|' . $user_prompt . '|' . wp_json_encode( $schema );
        return 'wac_llm_' . md5( $raw );
    }

    /**
     * Clear all cached LLM responses.
     */
    public function clear_cache() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_wac_llm_' ) . '%'
            )
        );
    }

    /**
     * Test connection to the configured LLM provider.
     *
     * @return array{success: bool, message: string}
     */
    public function test_connection(): array {
        try {
            $result = $this->analyze(
                'You are a connection tester. Reply with JSON: {"status":"ok"}.',
                'Respond with JSON only.'
            );
            return array(
                'success' => true,
                'message' => 'Connected successfully.',
            );
        } catch ( \Exception $e ) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    // ─── Token Estimation ──────────────────────────────────────────────

    /**
     * Estimate token count from a string.
     * Uses character-based heuristic: ~0.38 tokens per character for English.
     *
     * @param string $text
     * @return int Estimated token count.
     */
    private function estimate_tokens( string $text ): int {
        return (int) ceil( mb_strlen( $text ) * self::TOKEN_RATIO );
    }

    // ─── Rate Limiting ─────────────────────────────────────────────────

    /**
     * Check whether the current call is within the hourly rate limit.
     *
     * @return bool True if allowed, false if over limit.
     */
    private function check_rate_limit(): bool {
        $count = $this->call_count;
        if ( $count < 0 ) {
            // Not initialized yet — fetch from transient.
            $count = (int) get_transient( 'wac_llm_calls_hourly' );
            $this->call_count = $count;
        }
        return $count < self::MAX_CALLS_PER_HOUR;
    }

    /**
     * Increment the rate limit counter.
     */
    private function increment_rate_limit(): void {
        if ( $this->call_count < 0 ) {
            $this->call_count = 0;
        }
        $this->call_count++;
        // Transient expires after 1 hour, auto-resetting the counter.
        set_transient( 'wac_llm_calls_hourly', $this->call_count, HOUR_IN_SECONDS );
    }

    /**
     * Get current hourly call count.
     *
     * @return int
     */
    public function get_hourly_call_count(): int {
        return max( 0, $this->call_count );
    }

    /**
     * Get the remaining calls allowed this hour.
     *
     * @return int
     */
    public function get_remaining_calls(): int {
        return max( 0, self::MAX_CALLS_PER_HOUR - $this->get_hourly_call_count() );
    }

    /**
     * Get latency statistics for the current request cycle.
     *
     * @return array{count: int, avg_sec: float, max_sec: float, total_sec: float}
     */
    public function get_latency_stats(): array {
        $count = $this->latency_stats['count'];
        return array(
            'count'     => $count,
            'avg_sec'   => $count > 0 ? round( $this->latency_stats['total_sec'] / $count, 4 ) : 0.0,
            'max_sec'   => round( $this->latency_stats['max_sec'], 4 ),
            'total_sec' => round( $this->latency_stats['total_sec'], 4 ),
        );
    }
}
