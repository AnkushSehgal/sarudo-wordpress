<?php
/**
 * Sarudo_Registry — command name → handler-callable map.
 *
 * Commands live in plugins/sarudo/commands/*.php and self-register on
 * `sarudo_register_commands` (fired during WordPress `init` priority 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sarudo_Registry {

    private static $handlers = array();

    public static function register( $command_name, $handler_callable, $meta = array() ) {
        self::$handlers[ $command_name ] = array(
            'handler'    => $handler_callable,
            'meta'       => $meta,
        );
    }

    public static function dispatch( $command_name, $args, $is_dry_run = false ) {
        if ( ! isset( self::$handlers[ $command_name ] ) ) {
            return array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'unknown_command',
                    'message' => "Command '$command_name' is not registered. " .
                                 "See GET /wp-json/sarudo/v1/capabilities for the available command list.",
                ),
            );
        }
        $entry = self::$handlers[ $command_name ];
        try {
            $result = call_user_func( $entry['handler'], $args, $is_dry_run );
            if ( ! is_array( $result ) ) {
                $result = array( 'ok' => true, 'value' => $result );
            }
            return $result;
        } catch ( Throwable $e ) {
            return array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'handler_exception',
                    'message' => $e->getMessage(),
                    'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
                ),
            );
        }
    }

    public static function list_commands() {
        $out = array();
        foreach ( self::$handlers as $name => $entry ) {
            $meta = $entry['meta'] ?? array();
            $out[ $name ] = array(
                'description' => $meta['description'] ?? '',
                'destructive' => ! empty( $meta['destructive'] ),
                'family'      => $meta['family'] ?? null,
            );
        }
        ksort( $out );
        return $out;
    }

    public static function is_destructive( $command_name ) {
        $meta = self::$handlers[ $command_name ]['meta'] ?? array();
        return ! empty( $meta['destructive'] );
    }

    public static function register_all() {
        // Each commands/*.php file calls self::register() in its own init hook.
        // This method exists as the single entry point hooked into WordPress init.
        do_action( 'sarudo_register_commands_now' );
    }
}
