<?php
/**
 * et-sentry — minimal Sentry PHP client for etechnologie plugins.
 * Installed automatically by ms-reports / spoti-course-creator.
 * Do not edit manually — this file is overwritten on plugin update.
 *
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'ET_SENTRY_LOADED' ) ) return;
define( 'ET_SENTRY_LOADED', '1.0.0' );

/**
 * Sends PHP exceptions and fatal errors to Sentry via wp_remote_post.
 * No Composer / external SDK required.
 */
final class ET_Sentry {

    /** @var array<string,mixed> */
    private static array $cfg = [];

    /** @var callable|null */
    private static $prev_handler = null;

    // ------------------------------------------------------------------

    /**
     * @param array{dsn:string,environment?:string,release?:string,before_send?:callable} $opts
     */
    public static function init( array $opts ): void {
        if ( empty( $opts['dsn'] ) ) {
            return;
        }
        self::$cfg          = $opts;
        self::$cfg['_tags'] = [];
        self::$prev_handler = set_exception_handler( [ __CLASS__, '_exceptionHandler' ] );
        register_shutdown_function( [ __CLASS__, '_shutdownHandler' ] );
    }

    public static function setTag( string $key, string $value ): void {
        self::$cfg['_tags'][ $key ] = $value;
    }

    // ------------------------------------------------------------------

    /** @internal */
    public static function _exceptionHandler( \Throwable $e ): void {
        self::_captureThrowable( $e );
        if ( is_callable( self::$prev_handler ) ) {
            ( self::$prev_handler )( $e );
        }
    }

    /** @internal */
    public static function _shutdownHandler(): void {
        $err = error_get_last();
        if ( ! $err ) {
            return;
        }
        if ( ! ( $err['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
            return;
        }
        self::_dispatch( [
            'exception' => [
                'values' => [ [
                    'type'       => 'PHP Fatal Error',
                    'value'      => $err['message'],
                    'stacktrace' => [ 'frames' => [ [
                        'abs_path' => $err['file'],
                        'lineno'   => $err['line'],
                    ] ] ],
                ] ],
            ],
            'level' => 'fatal',
        ] );
    }

    // ------------------------------------------------------------------

    private static function _captureThrowable( \Throwable $e ): void {
        $trace = array_merge(
            [ [ 'file' => $e->getFile(), 'line' => $e->getLine() ] ],
            $e->getTrace()
        );
        self::_dispatch( [
            'exception' => [
                'values' => [ [
                    'type'       => get_class( $e ),
                    'value'      => $e->getMessage(),
                    'stacktrace' => [ 'frames' => self::_buildFrames( $trace ) ],
                ] ],
            ],
            'level' => 'error',
        ] );
    }

    /**
     * @param array<int,array<string,mixed>> $trace
     * @return array<int,array<string,mixed>>
     */
    private static function _buildFrames( array $trace ): array {
        $frames = [];
        foreach ( array_reverse( $trace ) as $f ) {
            $frame = [
                'abs_path' => $f['file'] ?? '',
                'lineno'   => $f['line'] ?? 0,
            ];
            if ( ! empty( $f['function'] ) ) {
                $frame['function'] = ( $f['class'] ?? '' ) . ( $f['type'] ?? '' ) . $f['function'];
            }
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * Apply before_send (receives plain array, returns array|null), then POST to Sentry.
     *
     * @param array<string,mixed> $event
     */
    private static function _dispatch( array $event ): void {
        if ( empty( self::$cfg['dsn'] ) ) {
            return;
        }
        if ( isset( self::$cfg['before_send'] ) && is_callable( self::$cfg['before_send'] ) ) {
            $event = ( self::$cfg['before_send'] )( $event );
            if ( $event === null ) {
                return;
            }
        }
        $parts   = wp_parse_url( self::$cfg['dsn'] );
        $key     = $parts['user'] ?? '';
        $host    = $parts['host'] ?? '';
        $project = ltrim( $parts['path'] ?? '', '/' );
        if ( ! $key || ! $host || ! $project ) {
            return;
        }
        $url     = 'https://' . $host . '/api/' . $project . '/store/';
        $payload = array_merge( $event, [
            'timestamp'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'platform'    => 'php',
            'environment' => self::$cfg['environment'] ?? 'production',
            'server_name' => (string) gethostname(),
        ] );
        if ( ! empty( self::$cfg['release'] ) ) {
            $payload['release'] = self::$cfg['release'];
        }
        if ( ! empty( self::$cfg['_tags'] ) ) {
            $payload['tags'] = self::$cfg['_tags'];
        }
        if ( ! function_exists( 'wp_remote_post' ) ) {
            return;
        }
        wp_remote_post( $url, [
            'headers'  => [
                'Content-Type'  => 'application/json',
                'X-Sentry-Auth' => sprintf(
                    'Sentry sentry_version=7, sentry_client=et-sentry/1.0, sentry_timestamp=%d, sentry_key=%s',
                    time(),
                    $key
                ),
            ],
            'body'     => (string) json_encode( $payload ),
            'timeout'  => 2,
            'blocking' => false,
        ] );
    }
}
