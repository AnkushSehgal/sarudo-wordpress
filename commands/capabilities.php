<?php
/**
 * Sarudo capabilities.* command handlers.
 *
 * These are introspection helpers that Sarudo uses to figure out what the
 * site supports BEFORE attempting a command (e.g. "is Yoast active so I can
 * use seo.set_meta with its keys, or do I fall back to RankMath?").
 *
 * The main capabilities surface is GET /sarudo/v1/capabilities (handled by
 * Sarudo_REST). These commands are for finer-grained interrogation via the
 * standard POST /exec dispatch.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'site.info', 'sarudo_cmd_site_info',
        array( 'description' => 'Return site name, URL, timezone, language, multisite flag.', 'family' => 'site' ) );
    Sarudo_Registry::register( 'site.theme', 'sarudo_cmd_site_theme',
        array( 'description' => 'Return the active theme + parent (for FSE vs classic decisions).', 'family' => 'site' ) );
    Sarudo_Registry::register( 'site.health', 'sarudo_cmd_site_health',
        array( 'description' => 'Lightweight WP Site Health snapshot.', 'family' => 'site' ) );
} );

function sarudo_cmd_site_info( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array(
        'url'            => get_site_url(),
        'name'           => get_bloginfo( 'name' ),
        'description'    => get_bloginfo( 'description' ),
        'admin_email'    => get_bloginfo( 'admin_email' ),
        'timezone'       => wp_timezone_string(),
        'language'       => get_bloginfo( 'language' ),
        'multisite'      => is_multisite(),
        'wp_version'     => get_bloginfo( 'version' ),
        'php_version'    => phpversion(),
        'plugin_version' => SARUDO_VERSION,
    ) );
}

function sarudo_cmd_site_theme( $args, $is_dry_run = false ) {
    $theme = wp_get_theme();
    return array( 'ok' => true, 'result' => array(
        'name'           => $theme->get( 'Name' ),
        'version'        => $theme->get( 'Version' ),
        'template'       => $theme->get_template(),
        'stylesheet'     => $theme->get_stylesheet(),
        'parent'         => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        'block_theme'    => wp_is_block_theme(),  // FSE vs classic
        'has_customizer' => current_theme_supports( 'customize' ),
    ) );
}

function sarudo_cmd_site_health( $args, $is_dry_run = false ) {
    // Lightweight subset of WP_Site_Health checks — full health report comes
    // in Phase 4. v0 returns the cheap-to-check items.
    return array( 'ok' => true, 'result' => array(
        'php_version_ok'         => version_compare( phpversion(), '7.4', '>=' ),
        'wp_version_ok'          => version_compare( get_bloginfo( 'version' ), '6.0', '>=' ),
        'https_enabled'          => is_ssl(),
        'cron_enabled'           => ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON,
        'memory_limit'           => ini_get( 'memory_limit' ),
        'max_upload_size_bytes'  => wp_max_upload_size(),
        'permalink_structure'    => get_option( 'permalink_structure' ) ?: 'default (?p=N)',
        'site_in_search_engines' => (bool) get_option( 'blog_public' ),
    ) );
}
