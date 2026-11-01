<?php
/**
 * Sarudo plugin admin settings page.
 *
 * Renders the WordPress admin UI under Settings → Sarudo. Shows the
 * connection token (ONCE on first view after activation), then lets the
 * operator rotate it, manage the IP allowlist, view the audit log, and
 * configure retention.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_options_page(
        __( 'Sarudo', 'sarudo' ),
        __( 'Sarudo', 'sarudo' ),
        'manage_options',
        'sarudo-settings',
        'sarudo_render_settings_page'
    );
} );

add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Rotate token
    if ( isset( $_POST['sarudo_action'] ) && $_POST['sarudo_action'] === 'rotate_token' ) {
        check_admin_referer( 'sarudo_rotate_token' );
        $new_token = Sarudo_Auth::generate_token();
        update_option( 'sarudo_secret_hash', Sarudo_Auth::hash_token( $new_token ) );
        update_option( 'sarudo_initial_token_plaintext', $new_token );
        update_option( 'sarudo_token_created_at', current_time( 'mysql' ) );
        add_settings_error( 'sarudo', 'token_rotated', __( 'New connection token generated. Copy it below — it will not be shown again after this page reload.', 'sarudo' ), 'updated' );
    }

    // Acknowledge token (clear plaintext from DB after operator views it)
    if ( isset( $_POST['sarudo_action'] ) && $_POST['sarudo_action'] === 'ack_token' ) {
        check_admin_referer( 'sarudo_ack_token' );
        delete_option( 'sarudo_initial_token_plaintext' );
        add_settings_error( 'sarudo', 'token_acked', __( 'Token acknowledged. Plaintext cleared from the database — store the copy you saved.', 'sarudo' ), 'updated' );
    }

    // Save settings
    if ( isset( $_POST['sarudo_action'] ) && $_POST['sarudo_action'] === 'save_settings' ) {
        check_admin_referer( 'sarudo_save_settings' );
        update_option( 'sarudo_ip_allowlist', sanitize_text_field( $_POST['sarudo_ip_allowlist'] ?? '' ) );
        update_option( 'sarudo_rate_limit_per_min', max( 1, (int) ( $_POST['sarudo_rate_limit_per_min'] ?? 60 ) ) );
        update_option( 'sarudo_audit_retention_days', max( 1, (int) ( $_POST['sarudo_audit_retention_days'] ?? 90 ) ) );
        update_option( 'sarudo_purge_on_deactivate', ! empty( $_POST['sarudo_purge_on_deactivate'] ) );
        add_settings_error( 'sarudo', 'settings_saved', __( 'Settings saved.', 'sarudo' ), 'updated' );
    }
} );

function sarudo_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorized', 'sarudo' ) );
    }

    settings_errors( 'sarudo' );

    $plaintext_token       = get_option( 'sarudo_initial_token_plaintext', '' );
    $token_created_at      = get_option( 'sarudo_token_created_at', '' );
    $ip_allowlist          = (string) get_option( 'sarudo_ip_allowlist', '' );
    $rate_limit            = (int) get_option( 'sarudo_rate_limit_per_min', 60 );
    $audit_retention_days  = (int) get_option( 'sarudo_audit_retention_days', 90 );
    $purge_on_deactivate   = (bool) get_option( 'sarudo_purge_on_deactivate', false );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Sarudo Connection', 'sarudo' ); ?></h1>

        <p><?php esc_html_e( 'This plugin connects your WordPress site to your Sarudo AI employee. Paste the connection token below into your Sarudo chat to authorize it.', 'sarudo' ); ?></p>

        <h2><?php esc_html_e( 'Connection Token', 'sarudo' ); ?></h2>

        <?php if ( $plaintext_token ) : ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e( 'Copy this token now — it will not be shown again.', 'sarudo' ); ?></strong></p>
                <p><code style="font-size: 14px; padding: 8px; background: #f0f0f1; display: inline-block; user-select: all;"><?php echo esc_html( $plaintext_token ); ?></code></p>
                <p><?php esc_html_e( 'Paste this token in your Sarudo chat when prompted during the website connection setup.', 'sarudo' ); ?></p>
                <form method="post" style="margin-top: 12px;">
                    <?php wp_nonce_field( 'sarudo_ack_token' ); ?>
                    <input type="hidden" name="sarudo_action" value="ack_token">
                    <button type="submit" class="button"><?php esc_html_e( "I've saved it — clear the plaintext from the database", 'sarudo' ); ?></button>
                </form>
            </div>
        <?php else : ?>
            <p><?php esc_html_e( 'A token is currently active.', 'sarudo' ); ?>
               <?php if ( $token_created_at ) : ?>
                   <?php printf( esc_html__( 'Created: %s.', 'sarudo' ), esc_html( $token_created_at ) ); ?>
               <?php endif; ?>
               <?php esc_html_e( 'Rotating generates a new token and invalidates the old one.', 'sarudo' ); ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sarudo_rotate_token' ); ?>
                <input type="hidden" name="sarudo_action" value="rotate_token">
                <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'This will invalidate the current token and break Sarudo\'s connection until you paste the new one. Continue?', 'sarudo' ) ); ?>');">
                    <?php esc_html_e( 'Rotate Token', 'sarudo' ); ?>
                </button>
            </form>
        <?php endif; ?>

        <h2 style="margin-top: 32px;"><?php esc_html_e( 'Settings', 'sarudo' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'sarudo_save_settings' ); ?>
            <input type="hidden" name="sarudo_action" value="save_settings">

            <table class="form-table">
                <tr>
                    <th><label for="sarudo_ip_allowlist"><?php esc_html_e( 'IP Allowlist', 'sarudo' ); ?></label></th>
                    <td>
                        <input type="text" id="sarudo_ip_allowlist" name="sarudo_ip_allowlist"
                               value="<?php echo esc_attr( $ip_allowlist ); ?>" class="regular-text"
                               placeholder="e.g. 203.0.113.10,2001:db8::10">
                        <p class="description">
                            <?php esc_html_e( 'Comma-separated IPv4/IPv6 addresses allowed to call the API. Leave empty to allow all (default).', 'sarudo' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sarudo_rate_limit_per_min"><?php esc_html_e( 'Rate Limit (per minute)', 'sarudo' ); ?></label></th>
                    <td>
                        <input type="number" id="sarudo_rate_limit_per_min" name="sarudo_rate_limit_per_min"
                               value="<?php echo esc_attr( $rate_limit ); ?>" min="1" max="10000" class="small-text">
                        <p class="description"><?php esc_html_e( 'Max API calls per minute. Default: 60.', 'sarudo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sarudo_audit_retention_days"><?php esc_html_e( 'Audit Log Retention (days)', 'sarudo' ); ?></label></th>
                    <td>
                        <input type="number" id="sarudo_audit_retention_days" name="sarudo_audit_retention_days"
                               value="<?php echo esc_attr( $audit_retention_days ); ?>" min="1" max="3650" class="small-text">
                        <p class="description"><?php esc_html_e( 'Audit log entries older than this are auto-pruned daily. Default: 90.', 'sarudo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sarudo_purge_on_deactivate"><?php esc_html_e( 'Purge on Deactivate', 'sarudo' ); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="sarudo_purge_on_deactivate" name="sarudo_purge_on_deactivate"
                                   value="1" <?php checked( $purge_on_deactivate ); ?>>
                            <?php esc_html_e( 'Wipe audit log + token when this plugin is deactivated.', 'sarudo' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Off by default — preserves data so re-activation does not require Sarudo re-onboarding.', 'sarudo' ); ?></p>
                    </td>
                </tr>
            </table>

            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'sarudo' ); ?></button></p>
        </form>

        <h2 style="margin-top: 32px;"><?php esc_html_e( 'Recent Activity (Audit Log)', 'sarudo' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Every command Sarudo runs on this site is logged here. Use this to audit what was changed, when, and by which token.', 'sarudo' ); ?>
        </p>
        <?php sarudo_render_audit_log_table(); ?>
    </div>
    <?php
}

function sarudo_render_audit_log_table() {
    $rows = Sarudo_Audit::recent( 50 );
    if ( ! $rows ) {
        echo '<p>' . esc_html__( 'No audit entries yet.', 'sarudo' ) . '</p>';
        return;
    }
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time', 'sarudo' ); ?></th>
                <th><?php esc_html_e( 'Command', 'sarudo' ); ?></th>
                <th><?php esc_html_e( 'Result', 'sarudo' ); ?></th>
                <th><?php esc_html_e( 'Duration', 'sarudo' ); ?></th>
                <th><?php esc_html_e( 'IP', 'sarudo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['ts'] ); ?></td>
                    <td><code><?php echo esc_html( $row['command'] ); ?></code></td>
                    <td>
                        <?php if ( $row['success'] ) : ?>
                            <span style="color: #00a32a;">✓ <?php esc_html_e( 'success', 'sarudo' ); ?></span>
                        <?php else : ?>
                            <span style="color: #d63638;">✗ <?php esc_html_e( 'failed', 'sarudo' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $row['duration_ms'] ); ?>ms</td>
                    <td><?php echo esc_html( $row['source_ip'] ?: '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
