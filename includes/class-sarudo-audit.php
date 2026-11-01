<?php
/**
 * Sarudo_Audit — append-only log of every command Sarudo executes on this site.
 *
 * Every dispatch writes one row BEFORE returning the response. The audit_log_id
 * is included in the response envelope so Sarudo can cross-reference its own
 * verifier-companion records to entries in this log. Operator views the log via
 * the plugin's settings page; retention defaults to 90 days, configurable.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sarudo_Audit {

    public static function write( $command, $args, $result, $success, $duration_ms ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sarudo_audit_log';

        // Args sanitization: never log the raw bearer token if it sneaks into args
        $safe_args = self::redact( $args );
        $safe_result = self::redact( $result );

        $wpdb->insert(
            $table,
            array(
                'command'     => substr( (string) $command, 0, 100 ),
                'args_json'   => wp_json_encode( $safe_args ),
                'result_json' => substr( wp_json_encode( $safe_result ), 0, 65535 ),
                'success'     => $success ? 1 : 0,
                'source_ip'   => Sarudo_Auth::get_remote_ip(),
                'token_label' => 'primary',  // future: per-token labels
                'duration_ms' => (int) $duration_ms,
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Recursively redact common sensitive keys before persistence.
     */
    private static function redact( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $lk = strtolower( (string) $k );
                if ( in_array( $lk, array( 'token', 'password', 'secret', 'api_key', 'authorization', 'bearer' ), true ) ) {
                    $out[ $k ] = '[REDACTED]';
                } else {
                    $out[ $k ] = self::redact( $v );
                }
            }
            return $out;
        }
        return $value;
    }

    /**
     * Read recent audit entries (for admin UI).
     */
    public static function recent( $limit = 100 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sarudo_audit_log';
        $limit = max( 1, min( 1000, (int) $limit ) );
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT $limit", ARRAY_A );
    }
}
