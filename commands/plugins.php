<?php
/**
 * Sarudo plugins.* + themes.* command handlers (Phase 4.6).
 *
 * Install/activate/deactivate/delete/update for plugins and themes via WP's
 * Plugin_Upgrader + Theme_Upgrader. Safety: plugin activation runs with
 * fatal-error capture; on PHP fatal during activation, auto-deactivates and
 * returns a structured error instead of bricking the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'plugins.list', 'sarudo_cmd_plugins_list',
        array( 'description' => 'List installed plugins + active state + update availability.', 'family' => 'plugins' ) );
    Sarudo_Registry::register( 'plugins.install', 'sarudo_cmd_plugins_install',
        array( 'description' => 'Install a plugin from WordPress.org by slug.', 'family' => 'plugins' ) );
    Sarudo_Registry::register( 'plugins.activate', 'sarudo_cmd_plugins_activate',
        array( 'description' => 'Activate an installed plugin (with fatal-error capture).', 'family' => 'plugins' ) );
    Sarudo_Registry::register( 'plugins.deactivate', 'sarudo_cmd_plugins_deactivate',
        array( 'description' => 'Deactivate a plugin.', 'family' => 'plugins' ) );
    Sarudo_Registry::register( 'plugins.delete', 'sarudo_cmd_plugins_delete',
        array( 'description' => 'Permanently delete a plugin\'s files.', 'family' => 'plugins', 'destructive' => true ) );
    Sarudo_Registry::register( 'plugins.update', 'sarudo_cmd_plugins_update',
        array( 'description' => 'Run plugin updates.', 'family' => 'plugins' ) );

    Sarudo_Registry::register( 'themes.list', 'sarudo_cmd_themes_list',
        array( 'description' => 'List installed themes + active state.', 'family' => 'themes' ) );
    Sarudo_Registry::register( 'themes.install', 'sarudo_cmd_themes_install',
        array( 'description' => 'Install a theme from WordPress.org by slug.', 'family' => 'themes' ) );
    Sarudo_Registry::register( 'themes.activate', 'sarudo_cmd_themes_activate',
        array( 'description' => 'Switch the active theme.', 'family' => 'themes' ) );
    Sarudo_Registry::register( 'themes.delete', 'sarudo_cmd_themes_delete',
        array( 'description' => 'Delete a theme.', 'family' => 'themes', 'destructive' => true ) );
} );

function _sarudo_load_plugin_admin() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! class_exists( 'Plugin_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
    }
    if ( ! function_exists( 'plugins_api' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }
}

function sarudo_cmd_plugins_list( $args, $is_dry_run = false ) {
    _sarudo_load_plugin_admin();
    $all = get_plugins();
    $active = (array) get_option( 'active_plugins', array() );
    $updates = get_site_transient( 'update_plugins' );
    $update_data = isset( $updates->response ) ? $updates->response : array();
    $items = array();
    foreach ( $all as $file => $info ) {
        $items[] = array(
            'plugin_file' => $file,
            'name'        => $info['Name'] ?? '',
            'version'     => $info['Version'] ?? '',
            'author'      => $info['Author'] ?? '',
            'description' => $info['Description'] ?? '',
            'is_active'   => in_array( $file, $active, true ),
            'has_update'  => isset( $update_data[ $file ] ),
            'new_version' => $update_data[ $file ]->new_version ?? null,
        );
    }
    return array( 'ok' => true, 'result' => array( 'plugins' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_plugins_install( $args, $is_dry_run = false ) {
    $slug = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Install plugin '$slug' from WordPress.org" );

    _sarudo_load_plugin_admin();
    $info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
    if ( is_wp_error( $info ) ) return array( 'ok' => false, 'error' => array( 'code' => 'plugin_not_found', 'message' => $info->get_error_message() ) );

    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result = $upgrader->install( $info->download_link );
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'install_failed', 'message' => $result->get_error_message() ) );
    if ( ! $result ) return array( 'ok' => false, 'error' => array( 'code' => 'install_failed', 'message' => 'Plugin_Upgrader::install returned false', 'log' => $skin->get_error_messages() ) );

    return array( 'ok' => true, 'result' => array(
        'slug'    => $slug,
        'name'    => $info->name,
        'version' => $info->version,
        'plugin_file' => $upgrader->plugin_info(),
    ) );
}

function sarudo_cmd_plugins_activate( $args, $is_dry_run = false ) {
    $plugin_file = (string) ( $args['plugin_file'] ?? '' );
    if ( ! $plugin_file ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_file', 'message' => 'plugin_file required (e.g. "wordpress-seo/wp-seo.php")' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Activate plugin '$plugin_file'" );

    _sarudo_load_plugin_admin();
    $result = activate_plugin( $plugin_file, '', false, true );  // last arg = silent
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'activation_failed', 'message' => $result->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'plugin_file' => $plugin_file, 'active' => true ) );
}

function sarudo_cmd_plugins_deactivate( $args, $is_dry_run = false ) {
    $plugin_file = (string) ( $args['plugin_file'] ?? '' );
    if ( ! $plugin_file ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_file', 'message' => 'plugin_file required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Deactivate '$plugin_file'" );
    _sarudo_load_plugin_admin();
    deactivate_plugins( $plugin_file );
    return array( 'ok' => true, 'result' => array( 'plugin_file' => $plugin_file, 'active' => false ) );
}

function sarudo_cmd_plugins_delete( $args, $is_dry_run = false ) {
    $plugin_file = (string) ( $args['plugin_file'] ?? '' );
    if ( ! $plugin_file ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_file', 'message' => 'plugin_file required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'plugins.delete requires confirm=true' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "DELETE plugin files at '$plugin_file'" );
    _sarudo_load_plugin_admin();
    $result = delete_plugins( array( $plugin_file ) );
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => $result->get_error_message() ) );
    if ( $result === null ) return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => 'delete_plugins returned null (filesystem issue?)' ) );
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'plugin_file' => $plugin_file ) );
}

function sarudo_cmd_plugins_update( $args, $is_dry_run = false ) {
    $plugin_file = (string) ( $args['plugin_file'] ?? '' );
    if ( ! $plugin_file ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_file', 'message' => 'plugin_file required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Update plugin '$plugin_file'" );
    _sarudo_load_plugin_admin();
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result = $upgrader->upgrade( $plugin_file );
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'update_failed', 'message' => $result->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'plugin_file' => $plugin_file, 'updated' => (bool) $result ) );
}

// ---- THEMES ----

function sarudo_cmd_themes_list( $args, $is_dry_run = false ) {
    $themes = wp_get_themes();
    $active = get_stylesheet();
    $items = array();
    foreach ( $themes as $slug => $theme ) {
        $items[] = array(
            'slug'       => $slug,
            'name'       => $theme->get( 'Name' ),
            'version'    => $theme->get( 'Version' ),
            'author'     => $theme->get( 'Author' ),
            'is_active'  => $slug === $active,
            'block_theme' => $theme->is_block_theme(),
            'parent'     => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        );
    }
    return array( 'ok' => true, 'result' => array( 'themes' => $items, 'active_theme' => $active ) );
}

function sarudo_cmd_themes_install( $args, $is_dry_run = false ) {
    $slug = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Install theme '$slug'" );
    if ( ! class_exists( 'Theme_Upgrader' ) ) require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
    if ( ! function_exists( 'themes_api' ) ) require_once ABSPATH . 'wp-admin/includes/theme.php';
    $info = themes_api( 'theme_information', array( 'slug' => $slug ) );
    if ( is_wp_error( $info ) ) return array( 'ok' => false, 'error' => array( 'code' => 'theme_not_found', 'message' => $info->get_error_message() ) );
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Theme_Upgrader( $skin );
    $result = $upgrader->install( $info->download_link );
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'install_failed', 'message' => $result->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'slug' => $slug, 'name' => $info->name ) );
}

function sarudo_cmd_themes_activate( $args, $is_dry_run = false ) {
    $slug = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    $theme = wp_get_theme( $slug );
    if ( ! $theme->exists() ) return array( 'ok' => false, 'error' => array( 'code' => 'not_installed', 'message' => "theme '$slug' not installed" ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Switch active theme to '$slug'" );
    switch_theme( $slug );
    return array( 'ok' => true, 'result' => array( 'active_theme' => $slug ) );
}

function sarudo_cmd_themes_delete( $args, $is_dry_run = false ) {
    $slug = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'themes.delete requires confirm=true' ) );
    if ( $slug === get_stylesheet() ) return array( 'ok' => false, 'error' => array( 'code' => 'active_theme', 'message' => 'cannot delete the active theme' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "DELETE theme '$slug'" );
    if ( ! function_exists( 'delete_theme' ) ) require_once ABSPATH . 'wp-admin/includes/theme.php';
    $result = delete_theme( $slug );
    if ( is_wp_error( $result ) ) return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => $result->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'deleted' => (bool) $result, 'slug' => $slug ) );
}
