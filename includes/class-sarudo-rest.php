<?php
/**
 * Sarudo_REST — registers /wp-json/sarudo/v1/* endpoints.
 *
 * Two endpoints:
 *   POST /sarudo/v1/exec         — execute a registered command
 *   GET  /sarudo/v1/capabilities — list every registered command + detected integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sarudo_REST {

    const NAMESPACE_V1 = 'sarudo/v1';

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/exec', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_exec' ),
            'permission_callback' => '__return_true',  // We do our own bearer-token auth
        ) );

        register_rest_route( self::NAMESPACE_V1, '/capabilities', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_capabilities' ),
            'permission_callback' => '__return_true',  // Auth still required, but listing is bearer-gated
        ) );
    }

    public static function handle_exec( $request ) {
        $started = microtime( true );

        // ----- Auth -----
        $bearer = Sarudo_Auth::extract_bearer_from_request( $request );
        if ( ! $bearer ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'missing_authorization',
                    'message' => 'Provide a Bearer token in the Authorization header.',
                ),
            ), 401 );
        }
        if ( ! Sarudo_Auth::verify_token( $bearer ) ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'invalid_token',
                    'message' => 'Bearer token did not match.',
                ),
            ), 401 );
        }
        $remote_ip = Sarudo_Auth::get_remote_ip();
        if ( ! Sarudo_Auth::ip_allowed( $remote_ip ) ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'ip_not_allowed',
                    'message' => "IP $remote_ip not on the allowlist.",
                ),
            ), 403 );
        }
        if ( ! Sarudo_Auth::rate_limit_check( $bearer ) ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'rate_limit_exceeded',
                    'message' => 'Per-minute rate limit hit. Wait a minute and retry.',
                ),
            ), 429 );
        }

        // ----- Parse request body -----
        $body = $request->get_json_params();
        if ( ! is_array( $body ) || empty( $body['command'] ) ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'malformed_request',
                    'message' => 'Request body must be JSON with a `command` field. Optional: `args` (object).',
                ),
            ), 400 );
        }

        $command = (string) $body['command'];
        $args    = isset( $body['args'] ) && is_array( $body['args'] ) ? $body['args'] : array();

        // ----- Dry-run mode -----
        $dry_run_header = $request->get_header( 'x-sarudo-dry-run' );
        $is_dry_run = in_array( strtolower( trim( (string) $dry_run_header ) ), array( 'true', '1', 'yes', 'on' ), true );

        // ----- Dispatch (wrapped in fatal-error capture) -----
        try {
            $result = Sarudo_Registry::dispatch( $command, $args, $is_dry_run );
        } catch ( Throwable $e ) {
            $result = array(
                'ok'    => false,
                'error' => array(
                    'code'    => 'dispatch_fatal',
                    'message' => $e->getMessage(),
                    'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
                ),
            );
        }

        $duration_ms = (int) ( ( microtime( true ) - $started ) * 1000 );
        $success     = ! empty( $result['ok'] );

        // ----- Audit log -----
        $audit_id = Sarudo_Audit::write( $command, $args, $result, $success, $duration_ms );

        // ----- Standard envelope -----
        $envelope = array(
            'ok'           => $success,
            'command'      => $command,
            'dry_run'      => $is_dry_run,
            'result'       => $result['ok'] ? ( $result['result'] ?? $result ) : null,
            'error'        => $result['ok'] ? null : ( $result['error'] ?? null ),
            'audit_log_id' => $audit_id,
            'duration_ms'  => $duration_ms,
        );

        $status_code = $success ? 200 : ( $result['error']['http_status'] ?? 400 );
        return new WP_REST_Response( $envelope, $status_code );
    }

    public static function handle_capabilities( $request ) {
        $bearer = Sarudo_Auth::extract_bearer_from_request( $request );
        if ( ! Sarudo_Auth::verify_token( $bearer ) ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => array( 'code' => 'invalid_token', 'message' => 'Bearer token required.' ),
            ), 401 );
        }

        $integrations = self::detect_integrations();

        return new WP_REST_Response( array(
            'ok'             => true,
            'plugin_version' => SARUDO_VERSION,
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
            'commands'       => Sarudo_Registry::list_commands(),
            'integrations'   => $integrations,
            'site'           => array(
                'url'      => get_site_url(),
                'name'     => get_bloginfo( 'name' ),
                'timezone' => wp_timezone_string(),
                'language' => get_bloginfo( 'language' ),
                'multisite' => is_multisite(),
            ),
        ), 200 );
    }

    /**
     * Detect which optional integrations are present on this site.
     */
    private static function detect_integrations() {
        $out = array(
            'yoast_seo'   => self::is_plugin_active( 'wordpress-seo/wp-seo.php' ),
            'rank_math'   => self::is_plugin_active( 'seo-by-rank-math/rank-math.php' ),
            'aioseo'      => self::is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
            'elementor'   => self::is_plugin_active( 'elementor/elementor.php' ),
            'divi'        => function_exists( 'et_setup_theme' ),
            'woocommerce' => self::is_plugin_active( 'woocommerce/woocommerce.php' ),
            'wpforms'     => self::is_plugin_active( 'wpforms-lite/wpforms.php' ) || self::is_plugin_active( 'wpforms/wpforms.php' ),
            'contact_form_7' => self::is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ),
            'gravity_forms'  => self::is_plugin_active( 'gravityforms/gravityforms.php' ),
            'wp_rocket'      => self::is_plugin_active( 'wp-rocket/wp-rocket.php' ),
            'updraftplus'    => self::is_plugin_active( 'updraftplus/updraftplus.php' ),
        );
        return $out;
    }

    private static function is_plugin_active( $plugin_path ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( $plugin_path );
    }
}
