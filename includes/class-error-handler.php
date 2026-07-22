<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Error Handler — captures PHP errors relevant to WooCommerce checkout
 * without crashing the site.
 *
 * Features:
 * - Recursive guard: if logging causes an error, we bail immediately
 * - Scoped relevance: only captures errors from this plugin + WooCommerce
 * - Table-safe logging: checks wac_logs table exists before inserting
 * - File fallback: if DB is unavailable, writes to wp-content/wac-errors.log
 * - Exceptions bubble if irrelevant (no silent swallowing)
 *
 * @since 0.1.0-alpha
 */
class ErrorHandler {

    /**
     * Is the handler active?
     *
     * @var bool
     */
    private static $active = false;

    /**
     * Are we currently inside handle_error?
     * Prevents infinite recursion when logging triggers an error.
     *
     * @var bool
     */
    private static $handling = false;

    /**
     * Previous error handler (for chaining).
     *
     * @var callable|null
     */
    private static $previous_handler = null;

    /**
     * Logger instance.
     *
     * @var Logger|null
     */
    private static $logger = null;

    /**
     * Does the wac_logs table exist?
     * Checked once to avoid hammering $wpdb on every error.
     *
     * @var bool|null
     */
    private static $table_checked = null;

    /**
     * Register the error handler.
     * Safe to call multiple times — only registers once.
     */
    public static function register() {
        if ( self::$active ) {
            return;
        }

        // Only capture errors from our plugin + WooCommerce.
        self::$previous_handler = set_error_handler( array( __CLASS__, 'handle_error' ), E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

        register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
        set_exception_handler( array( __CLASS__, 'handle_exception' ) );

        self::$active = true;
    }

    /**
     * Unregister the error handler.
     */
    public static function unregister() {
        if ( ! self::$active ) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();
        self::$active = false;
    }

    /**
     * Handle a PHP error with full recursive-loop protection.
     *
     * @param int    $errno   Error level.
     * @param string $errstr  Error message.
     * @param string $errfile File where error occurred.
     * @param int    $errline Line number.
     *
     * @return bool
     */
    public static function handle_error( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
        // 🚫 RECURSIVE GUARD: if logging itself caused this error, bail immediately.
        if ( self::$handling ) {
            // Still let the previous handler run so WordPress functions normally.
            if ( self::$previous_handler ) {
                return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
            }
            return false;
        }

        // Only capture errors from our plugin + WooCommerce core.
        if ( ! self::is_relevant_path( $errfile ) ) {
            if ( self::$previous_handler ) {
                return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
            }
            return false;
        }

        // Suppress E_NOTICE / E_WARNING from non-critical sources (noise).
        if ( $errno <= E_WARNING && false === self::is_critical_checkout_error( $errstr, $errfile ) ) {
            if ( self::$previous_handler ) {
                return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
            }
            return false;
        }

        self::$handling = true;

        $level = self::php_error_level_to_string( $errno );
        $event = 'php_error';

        if ( false !== strpos( $errstr, 'payment' ) || false !== strpos( $errfile, 'gateway' ) ) {
            $event = 'payment_gateway_error';
        } elseif ( false !== strpos( $errstr, 'session' ) ) {
            $event = 'session_error';
        } elseif ( false !== strpos( $errfile, 'checkout' ) ) {
            $event = 'checkout_error';
        }

        self::safe_log( $event, array(
            'type'    => $level,
            'message' => substr( $errstr, 0, 500 ),
            'file'    => self::short_path( $errfile ),
            'line'    => $errline,
        ) );

        self::$handling = false;

        // Chain to previous handler.
        if ( self::$previous_handler ) {
            return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
        }

        return false;
    }

    /**
     * Handle fatal errors on shutdown.
     */
    public static function handle_shutdown() {
        $error = error_get_last();

        if ( null === $error ) {
            return;
        }

        if ( ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
            return;
        }

        if ( ! self::is_relevant_path( $error['file'] ?? '' ) ) {
            return;
        }

        // Recursion guard: if safe_log triggers another fatal during shutdown, bail.
        if ( self::$handling ) {
            return;
        }
        self::$handling = true;

        self::safe_log( 'fatal_error', array(
            'type'    => 'fatal',
            'message' => $error['message'] ?? '',
            'file'    => self::short_path( $error['file'] ?? '' ),
            'line'    => $error['line'] ?? 0,
        ) );

        self::$handling = false;
    }

    /**
     * Handle uncaught exceptions.
     * Irrelevant exceptions are re-thrown so WordPress handles them normally.
     *
     * @param \Throwable $exception
     */
    public static function handle_exception( $exception ) {
        // Not our concern? Let WordPress handle it properly.
        if ( ! self::is_relevant_path( $exception->getFile() ) ) {
            // Restore default handler so WP shows its own error page.
            restore_exception_handler();
            throw $exception;
        }

        if ( self::$handling ) {
            return; // Prevent loop.
        }

        self::$handling = true;

        self::safe_log( 'uncaught_exception', array(
            'type'    => 'exception',
            'message' => substr( $exception->getMessage(), 0, 500 ),
            'file'    => self::short_path( $exception->getFile() ),
            'line'    => $exception->getLine(),
        ) );

        self::$handling = false;

        // Let WP's own handler do its thing.
        restore_exception_handler();
        throw $exception;
    }

    // ─── Safe Logging ───────────────────────────────────────────

    /**
     * Log safely — checks table exists, falls back to file, then error_log.
     *
     * @param string $event
     * @param array  $data
     */
    private static function safe_log( string $event, array $data ) {
        // Try DB logging first.
        if ( self::can_db_log() ) {
            self::ensure_logger();
            if ( self::$logger ) {
                try {
                    self::$logger->error( $event, $data );
                    return;
                } catch ( \Throwable $e ) {
                    // DB logging failed — fall through to file log.
                    self::$table_checked = false;
                }
            }
        }

        // First fallback: file log.
        if ( self::file_log( $event, $data ) ) {
            return;
        }

        // Second fallback: PHP error_log.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(
            sprintf(
                '[WAC] [%s] %s: %s',
                strtoupper( $event ),
                $data['type'] ?? 'unknown',
                $data['message'] ?? 'No message'
            )
        );
    }

    /**
     * Check if the wac_logs table exists (cached).
     *
     * @return bool
     */
    private static function can_db_log(): bool {
        if ( null !== self::$table_checked ) {
            return self::$table_checked;
        }

        global $wpdb;

        if ( ! $wpdb ) {
            self::$table_checked = false;
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->prefix . 'wac_logs'
            )
        );

        self::$table_checked = ! empty( $table );
        return self::$table_checked;
    }

    /**
     * Ensure Logger is instantiated.
     */
    private static function ensure_logger() {
        if ( null === self::$logger ) {
            try {
                self::$logger = new Logger();
            } catch ( \Throwable $e ) {
                self::$logger = null;
            }
        }
    }

    /**
     * Fallback file logger — writes to wp-content/wac-errors.log.
     * Always safe, never triggers additional errors.
     *
     * @param string $event
     * @param array  $data
     *
     * @return bool True on successful write, false on failure.
     */
    private static function file_log( string $event, array $data ): bool {
        $log_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( defined( 'ABSPATH' ) ? ABSPATH . 'wp-content' : sys_get_temp_dir() );
        $log_dir = rtrim( $log_dir, '/' );

        // Bail early if open_basedir restricts access to the target directory.
        $open_basedir = ini_get( 'open_basedir' );
        if ( ! empty( $open_basedir ) ) {
            $allowed_dirs = explode( PATH_SEPARATOR, $open_basedir );
            $is_allowed   = false;
            foreach ( $allowed_dirs as $allowed ) {
                if ( str_starts_with( $log_dir, rtrim( $allowed, '/' ) ) ) {
                    $is_allowed = true;
                    break;
                }
            }
            if ( ! $is_allowed ) {
                return false; // open_basedir prevents writing to this directory.
            }
        }

        // Ensure the log directory exists and is writable.
        if ( ! is_dir( $log_dir ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @mkdir( $log_dir, 0755, true );
        }
        if ( ! is_writable( $log_dir ) ) {
            return false; // Cannot write logs, bail silently.
        }

        $log_file = $log_dir . '/wac-errors.log';

        $context = array();
        if ( isset( $data['file'] ) ) {
            $context['file'] = $data['file'];
        }
        if ( isset( $data['line'] ) ) {
            $context['line'] = $data['line'];
        }
        $context_json = '';
        if ( ! empty( $context ) ) {
            // Use native json_encode for maximum compatibility (WP may not be loaded yet).
            $encoded = @json_encode( $context, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.json_encode_json_encode
            if ( false !== $encoded ) {
                $context_json = ' | ' . $encoded;
            }
        }

        $line = sprintf(
            "[%s] [%s] %s: %s%s\n",
            gmdate( 'Y-m-d\TH:i:s\Z' ),
            strtoupper( $event ),
            $data['type'] ?? 'unknown',
            $data['message'] ?? 'No message',
            $context_json
        );

        // Suppress any PHP warnings from file writes.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return (bool) @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    }

    // ─── Path Relevance ─────────────────────────────────────────

    /**
     * Check if an error came from a path we should monitor.
     * Strictly scoped — only our plugin + WooCommerce.
     * Uses DIRECTORY_SEPARATOR to prevent matching paths like
     * "woocommerce-malicious" or "woo-agentic-checkout-backdoor".
     *
     * @param string $file
     *
     * @return bool
     */
    private static function is_relevant_path( string $file ): bool {
        if ( empty( $file ) ) {
            return false; // Don't capture unknown-origin errors.
        }

        $ds = DIRECTORY_SEPARATOR;

        // Normalize to platform directory separator for matching.
        $normalized = str_replace( array( '/', '\\' ), $ds, $file );

        // Check for our plugin — exact plugin slug boundary.
        if ( false !== strpos( $normalized, $ds . 'woo-agentic-checkout' . $ds ) ) {
            return true;
        }
        // Also match the plugin root file itself (no trailing slash).
        if ( false !== strpos( $normalized, $ds . 'woo-agentic-checkout.php' ) ) {
            return true;
        }

        // Check for WooCommerce core — exact plugin slug boundary.
        if ( false !== strpos( $normalized, $ds . 'plugins' . $ds . 'woocommerce' . $ds ) ) {
            return true;
        }

        // Check for WooCommerce includes.
        if ( false !== strpos( $normalized, $ds . 'wp-includes' . $ds . 'class-wc-' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if an error is a critical checkout/payment error (not just a notice).
     *
     * @param string $message Error message.
     * @param string $file    Error file path.
     *
     * @return bool
     */
    private static function is_critical_checkout_error( string $message, string $file ): bool {
        $critical_keywords = array(
            'checkout',
            'payment',
            'gateway',
            'fatal',
            'session',
            'sql',
            'database',
            'cart',
            'order',
            'subscription',
            'refund',
            'capture',
            'authorize',
        );

        foreach ( $critical_keywords as $keyword ) {
            if ( false !== stripos( $message, $keyword ) ) {
                return true;
            }
            if ( false !== stripos( $file, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    // ─── Utilities ──────────────────────────────────────────────

    /**
     * Convert PHP error level to string.
     *
     * @param int $level
     *
     * @return string
     */
    private static function php_error_level_to_string( int $level ): string {
        $levels = array(
            E_ERROR             => 'error',
            E_WARNING           => 'warning',
            E_PARSE             => 'parse',
            E_NOTICE            => 'notice',
            E_CORE_ERROR        => 'core_error',
            E_CORE_WARNING      => 'core_warning',
            E_COMPILE_ERROR     => 'compile_error',
            E_COMPILE_WARNING   => 'compile_warning',
            E_USER_ERROR        => 'user_error',
            E_USER_WARNING      => 'user_warning',
            E_USER_NOTICE       => 'user_notice',
            E_STRICT            => 'strict',
            E_RECOVERABLE_ERROR => 'recoverable_error',
        );

        return $levels[ $level ] ?? 'unknown';
    }

    /**
     * Shorten file path for readability.
     *
     * @param string $path
     *
     * @return string
     */
    private static function short_path( string $path ): string {
        $abspath = defined( 'ABSPATH' ) ? ABSPATH : '';

        if ( ! empty( $abspath ) && str_starts_with( $path, $abspath ) ) {
            return substr( $path, strlen( $abspath ) );
        }

        return basename( dirname( $path ) ) . '/' . basename( $path );
    }
}
