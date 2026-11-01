<?php
/**
 * Sarudo_Auth — site-level connection token management.
 *
 * Tokens are stored as bcrypt hashes in wp_options.sarudo_secret_hash.
 * Plaintext is shown ONCE on activation (or rotation) and never persisted
 * after the admin has had a chance to view it. Bearer-token validation
 * uses constant-time comparison via hash_equals (verified via password_verify).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sarudo_Auth {

    /**
     * Mint a new cryptographically-strong token.
     * Format: 48-char URL-safe base64 (~288 bits of entropy).
     */
    public static function generate_token() {
        return rtrim( strtr( base64_encode( random_bytes( 36 ) ), '+/', '-_' ), '=' );
    }

    /**
     * Hash a plaintext token for storage.
     */
    public static function hash_token( $plaintext ) {
        return password_hash( $plaintext, PASSWORD_BCRYPT );
    }

    /**
     * Constant-time verification of a bearer token against the stored hash.
     */
    public static function verify_token( $plaintext ) {
        if ( ! is_string( $plaintext ) || strlen( $plaintext ) < 16 ) {
            return false;
        }
        $hash = get_option( 'sarudo_secret_hash' );
        if ( ! $hash ) {
            return false;
        }
        return password_verify( $plaintext, $hash );
    }

    /**
     * Extract the bearer token from the Authorization header.
     */
    public static function extract_bearer_from_request( $request ) {
        $auth_header = '';
        if ( method_exists( $request, 'get_header' ) ) {
            $auth_header = $request->get_header( 'authorization' ) ?: '';
        }
        if ( ! $auth_header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ( stripos( $auth_header, 'Bearer ' ) === 0 ) {
            return trim( substr( $auth_header, 7 ) );
        }
        return '';
    }

    /**
     * IP allowlist check. Empty allowlist = allow all (default).
     */
    public static function ip_allowed( $remote_ip ) {
        $allow_csv = trim( (string) get_option( 'sarudo_ip_allowlist', '' ) );
        if ( ! $allow_csv ) {
            return true;
        }
        $allowed = array_filter( array_map( 'trim', explode( ',', $allow_csv ) ) );
        return in_array( $remote_ip, $allowed, true );
    }

    /**
     * Per-minute rate limit. Uses a transient counter keyed by the bearer hash
     * prefix (so we don't store plaintext in transients).
     */
    public static function rate_limit_check( $bearer_plaintext ) {
        $limit = (int) get_option( 'sarudo_rate_limit_per_min', 60 );
        if ( $limit < 1 ) {
            return true;
        }
        // 16-char prefix of sha256 — non-reversible, sufficient to keyspace
        $key = 'sarudo_rl_' . substr( hash( 'sha256', $bearer_plaintext ), 0, 16 );
        $window_start = floor( time() / 60 );
        $current_key = $key . '_' . $window_start;

        $count = get_transient( $current_key );
        if ( $count === false ) {
            set_transient( $current_key, 1, 75 );  // 75s TTL so it expires after the minute window
            return true;
        }
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $current_key, (int) $count + 1, 75 );
        return true;
    }

    /**
     * Get the requester's IP address.
     *
     * X-Forwarded-For is client-controllable, so it is ONLY honored when the
     * direct peer (REMOTE_ADDR) is a configured trusted proxy/CDN. Otherwise an
     * attacker holding a leaked bearer token could send
     * `X-Forwarded-For: <allowed-ip>` and defeat the IP allowlist. With no trusted proxies configured (the default), the raw
     * REMOTE_ADDR is authoritative. Set `sarudo_trusted_proxies` (CSV) only if
     * the site genuinely sits behind a reverse proxy/CDN.
     */
    public static function get_remote_ip() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted_csv = trim( (string) get_option( 'sarudo_trusted_proxies', '' ) );
        if ( $trusted_csv && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $trusted = array_filter( array_map( 'trim', explode( ',', $trusted_csv ) ) );
            if ( in_array( $remote, $trusted, true ) ) {
                // XFF is "client, proxy1, proxy2, ..." (leftmost is the origin, each
                // proxy appends the peer it saw). Walk RIGHT→LEFT skipping our own
                // trusted proxies; the first untrusted hop is the real client. This
                // defeats spoofing (an attacker-injected leftmost entry is never
                // reached once a genuine untrusted hop appears to its right) and,
                // unlike end(), returns the client rather than the last proxy.
                $forwarded = array_values( array_filter( array_map( 'trim', explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
                for ( $i = count( $forwarded ) - 1; $i >= 0; $i-- ) {
                    if ( ! in_array( $forwarded[ $i ], $trusted, true ) ) {
                        return $forwarded[ $i ];
                    }
                }
                // All hops were trusted proxies — use the leftmost (origin).
                if ( ! empty( $forwarded ) ) {
                    return $forwarded[0];
                }
            }
        }
        return $remote;
    }
}
