<?php
/**
 * Plugin Name:       Sarudo
 * Plugin URI:        https://sarudo.com/wordpress
 * Description:       Connects this WordPress site to your Sarudo AI employee. After install, paste the connection token below and your Sarudo can publish posts, manage media, configure SEO, and operate this site on your behalf.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sarudo
 * Author URI:        https://sarudo.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sarudo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SARUDO_VERSION', '0.1.0' );
define( 'SARUDO_PLUGIN_FILE', __FILE__ );
define( 'SARUDO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SARUDO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SARUDO_DB_VERSION', '1' );

// ----- Load core classes + command handlers ---------------------------------
require_once SARUDO_PLUGIN_DIR . 'includes/class-sarudo-auth.php';
require_once SARUDO_PLUGIN_DIR . 'includes/class-sarudo-audit.php';
require_once SARUDO_PLUGIN_DIR . 'includes/class-sarudo-registry.php';
require_once SARUDO_PLUGIN_DIR . 'includes/class-sarudo-rest.php';

require_once SARUDO_PLUGIN_DIR . 'commands/posts.php';
require_once SARUDO_PLUGIN_DIR . 'commands/media.php';
require_once SARUDO_PLUGIN_DIR . 'commands/terms.php';
require_once SARUDO_PLUGIN_DIR . 'commands/seo.php';
require_once SARUDO_PLUGIN_DIR . 'commands/capabilities.php';
require_once SARUDO_PLUGIN_DIR . 'commands/users.php';
require_once SARUDO_PLUGIN_DIR . 'commands/settings.php';
require_once SARUDO_PLUGIN_DIR . 'commands/menus.php';
require_once SARUDO_PLUGIN_DIR . 'commands/plugins.php';
require_once SARUDO_PLUGIN_DIR . 'commands/bridges.php';
require_once SARUDO_PLUGIN_DIR . 'commands/admin.php';
require_once SARUDO_PLUGIN_DIR . 'commands/woocommerce.php';

if ( is_admin() ) {
    require_once SARUDO_PLUGIN_DIR . 'admin/settings-page.php';
}

// ----- Activation: create audit log table + mint initial token -------------
register_activation_hook( __FILE__, 'sarudo_activate' );
function sarudo_activate() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'sarudo_audit_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        command VARCHAR(100) NOT NULL,
        args_json LONGTEXT NULL,
        result_json LONGTEXT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        source_ip VARCHAR(45) NULL,
        token_label VARCHAR(100) NULL,
        duration_ms INT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY idx_ts (ts),
        KEY idx_command (command),
        KEY idx_success (success)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'sarudo_db_version', SARUDO_DB_VERSION );

    // Mint the initial connection token (only if one doesn't exist)
    if ( ! get_option( 'sarudo_secret_hash' ) ) {
        $plain_token = Sarudo_Auth::generate_token();
        update_option( 'sarudo_secret_hash', Sarudo_Auth::hash_token( $plain_token ) );
        update_option( 'sarudo_initial_token_plaintext', $plain_token );  // Cleared by admin UI on first view
        update_option( 'sarudo_token_created_at', current_time( 'mysql' ) );
    }

    // Default options
    if ( get_option( 'sarudo_ip_allowlist', null ) === null ) {
        update_option( 'sarudo_ip_allowlist', '' );  // Empty = allow all
    }
    if ( get_option( 'sarudo_rate_limit_per_min', null ) === null ) {
        update_option( 'sarudo_rate_limit_per_min', 60 );
    }
    if ( get_option( 'sarudo_audit_retention_days', null ) === null ) {
        update_option( 'sarudo_audit_retention_days', 90 );
    }
}

// ----- Deactivation: optional purge based on operator setting --------------
register_deactivation_hook( __FILE__, 'sarudo_deactivate' );
function sarudo_deactivate() {
    // Token + audit log preserved by default — operator can re-activate without re-onboarding Sarudo.
    // The "Purge on deactivate" setting in the admin UI controls whether to wipe.
    if ( get_option( 'sarudo_purge_on_deactivate', false ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sarudo_audit_log';
        $wpdb->query( "TRUNCATE TABLE $table_name" );
        delete_option( 'sarudo_secret_hash' );
        delete_option( 'sarudo_initial_token_plaintext' );
        delete_option( 'sarudo_token_created_at' );
    }
}

// ----- Register the REST endpoint ------------------------------------------
add_action( 'rest_api_init', array( 'Sarudo_REST', 'register_routes' ) );

// ----- Register commands into the registry --------------------------------
add_action( 'init', 'sarudo_register_commands', 5 );
function sarudo_register_commands() {
    Sarudo_Registry::register_all();
}

// ----- Audit log retention cron --------------------------------------------
add_action( 'sarudo_audit_log_prune', 'sarudo_prune_audit_log' );
function sarudo_prune_audit_log() {
    global $wpdb;
    $days  = (int) get_option( 'sarudo_audit_retention_days', 90 );
    if ( $days < 1 ) {
        return;
    }
    $table = $wpdb->prefix . 'sarudo_audit_log';
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $table WHERE ts < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ) );
}
if ( ! wp_next_scheduled( 'sarudo_audit_log_prune' ) ) {
    wp_schedule_event( time(), 'daily', 'sarudo_audit_log_prune' );
}
