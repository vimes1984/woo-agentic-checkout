<?php
namespace WooAgenticCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Error Handler — hooks into PHP's error system to capture
 * checkout-related errors for the Error Detector agent.
 *
 * Registers set_error_handler() + register_shutdown_function()
 * to catch everything from PHP notices to fatal errors.
 *
 * @since 0.1.0-alpha
 */
class ErrorHandler {

    /**
     * Is the handler currently active?
     *
     * @var bool
     */
    private static $active = false;

    /**
     * Previous error handler (for restoration).
     *
     * @var callable|null
     */
    private static $previous_handler = null;

    /**
     * Logger instance.
     *
     * @var Logger
     */
    private static $logger = null;

    /**
     * Register the error handler.
     */
    public static function register() {
        if ( self::$active ) {
            return;
        }

        self::$logger = new Logger();

        // Capture PHP errors (notices, warnings, etc.)
        self::$previous_handler = set_error_handler( array( __CLASS__, 'handle_error' ), E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

        // Capture fatal errors on shutdown.
        register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );

        // Capture uncaught exceptions.
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
     * Handle a PHP error.
     *
     * @param int    $errno   Error level.
     * @param string $errstr  Error message.
     * @param string $errfile File where error occurred.
     * @param int    $errline Line number.
     *
     * @return bool
     */
    public static function handle_error( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
        // Only capture errors from WooCommerce or our plugin.
        if ( ! self::is_relevant_error( $errfile ) ) {
            if ( self::$previous_handler ) {
                return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
            }
            return false;
        }

        $level = self::php_error_level_to_string( $errno );
        $event = 'php_error';

        // Map to known event types.
        if ( false !== strpos( $errstr, 'WooCommerce' ) || false !== strpos( $errstr, 'woocommerce' ) ) {
            $event = 'checkout_php_error';
        }

        if ( false !== strpos( $errstr, 'session' ) ) {
            $event = 'session_error';
        }

        if ( false !== strpos( $errfile, 'gateway' ) || false !== strpos( $errstr, 'payment' ) ) {
            $event = 'payment_gateway_error';
        }

        self::$logger->error( $event, array(
            'type'    => $level,
            'message' => $errstr,
            'file'    => self::short_path( $errfile ),
            'line'    => $errline,
            'trace'   => self::get_trace_summary(),
        ) );

        // Continue to the previous handler for normal PHP error handling.
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

        // Only care about fatal errors.
        if ( ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
            return;
        }

        // Only capture relevant errors.
        if ( ! self::is_relevant_error( $error['file'] ?? '' ) ) {
            return;
        }

        self::$logger->error( 'fatal_error', array(
            'type'    => 'fatal',
            'message' => $error['message'],
            'file'    => self::short_path( $error['file'] ?? '' ),
            'line'    => $error['line'] ?? 0,
        ) );
    }

    /**
     * Handle uncaught exceptions.
     *
     * @param \Throwable $exception
     */
    public static function handle_exception( $exception ) {
        if ( ! self::is_relevant_error( $exception->getFile() ) ) {
            return;
        }

        self::$logger->error( 'uncaught_exception', array(
            'type'    => 'exception',
            'message' => $exception->getMessage(),
            'file'    => self::short_path( $exception->getFile() ),
            'line'    => $exception->getLine(),
        ) );

        // Re-throw if possible (WordPress handles it from here).
        if ( ! headers_sent() ) {
            wp_die(
                esc_html( $exception->getMessage() ),
                esc_html__( 'WooCommerce Checkout Error', 'woo-agentic-checkout' ),
                array( 'response' => 500 )
            );
        }
    }

    /**
     * Check if an error occurred in a relevant file.
     *
     * @param string $file Full file path.
     *
     * @return bool
     */
    private static function is_relevant_error( string $file ): bool {
        if ( empty( $file ) ) {
            return true; // Include errors with unknown files.
        }

        $relevant_patterns = array(
            'woocommerce',
            'woo-agentic-checkout',
            'wp-content/plugins',
            'wp-includes/class-wc-',
            'themes' . DIRECTORY_SEPARATOR,
        );

        foreach ( $relevant_patterns as $pattern ) {
            if ( false !== strpos( $file, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert PHP error level integer to string.
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
     * @param string $path Full path.
     *
     * @return string
     */
    private static function short_path( string $path ): string {
        $abspath = defined( 'ABSPATH' ) ? ABSPATH : '';
        if ( ! empty( $abspath ) && 0 === strpos( $path, $abspath ) ) {
            return substr( $path, strlen( $abspath ) );
        }

        return basename( dirname( $path ) ) . '/' . basename( $path );
    }

    /**
     * Get a summary of the current backtrace (top 5 frames only).
     *
     * @return string
     */
    private static function get_trace_summary(): string {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );

        $summary = array();
        foreach ( $trace as $i => $frame ) {
            if ( $i < 2 ) {
                continue; // Skip this handler.
            }
            $file = isset( $frame['file'] ) ? self::short_path( $frame['file'] ) : 'unknown';
            $line = $frame['line'] ?? 0;
            $fn   = $frame['function'] ?? 'unknown';
            $summary[] = "{$file}:{$line} {$fn}()";
        }

        return implode( ' ← ', array_slice( $summary, 0, 5 ) );
    }
}
