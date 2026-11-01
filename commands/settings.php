<?php
/**
 * Sarudo settings.* + options.* + customizer.* + site_editor.* commands (Phase 4.3 + 4.4).
 *
 * Standard WP settings (blogname, timezone, permalinks, etc.), generic wp_options
 * read/write (with destructive-confirm gate for siteurl/home), Customizer theme
 * mods, and FSE block template editing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'settings.get', 'sarudo_cmd_settings_get',
        array( 'description' => 'Read WP General/Writing/Reading/Discussion/Media/Permalinks settings.', 'family' => 'settings' ) );
    Sarudo_Registry::register( 'settings.set', 'sarudo_cmd_settings_set',
        array( 'description' => 'Write WP settings (whitelisted keys only).', 'family' => 'settings' ) );
    Sarudo_Registry::register( 'options.get', 'sarudo_cmd_options_get',
        array( 'description' => 'Read any wp_options row.', 'family' => 'settings' ) );
    Sarudo_Registry::register( 'options.update', 'sarudo_cmd_options_update',
        array( 'description' => 'Write any wp_options row (gated on siteurl/home).', 'family' => 'settings' ) );
    Sarudo_Registry::register( 'customizer.list_settings', 'sarudo_cmd_customizer_list_settings',
        array( 'description' => 'Enumerate active theme\'s Customizer settings.', 'family' => 'customizer' ) );
    Sarudo_Registry::register( 'customizer.get', 'sarudo_cmd_customizer_get',
        array( 'description' => 'Read a Customizer setting / theme mod.', 'family' => 'customizer' ) );
    Sarudo_Registry::register( 'customizer.set', 'sarudo_cmd_customizer_set',
        array( 'description' => 'Write a Customizer setting / theme mod.', 'family' => 'customizer' ) );
    Sarudo_Registry::register( 'site_editor.get_template', 'sarudo_cmd_site_editor_get_template',
        array( 'description' => 'Get an FSE block template by slug.', 'family' => 'site_editor' ) );
    Sarudo_Registry::register( 'site_editor.set_template', 'sarudo_cmd_site_editor_set_template',
        array( 'description' => 'Write an FSE block template.', 'family' => 'site_editor' ) );
} );

// Whitelist of "safe" settings — `siteurl` and `home` require explicit confirm
const SARUDO_SETTINGS_WHITELIST = array(
    'blogname', 'blogdescription', 'admin_email', 'timezone_string', 'date_format', 'time_format', 'start_of_week',
    'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts',
    'default_comment_status', 'default_ping_status', 'comments_per_page', 'moderation_keys', 'comment_moderation',
    'comment_registration', 'comment_max_links', 'thread_comments', 'thread_comments_depth',
    'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
    'permalink_structure', 'category_base', 'tag_base',
    'blog_public', 'blog_charset', 'WPLANG',
);
const SARUDO_SETTINGS_PROTECTED = array( 'siteurl', 'home' );

function sarudo_cmd_settings_get( $args, $is_dry_run = false ) {
    $keys = is_array( $args['keys'] ?? null ) ? $args['keys'] : SARUDO_SETTINGS_WHITELIST;
    $out = array();
    foreach ( $keys as $k ) {
        if ( in_array( $k, SARUDO_SETTINGS_WHITELIST, true ) || in_array( $k, SARUDO_SETTINGS_PROTECTED, true ) ) {
            $out[ $k ] = get_option( $k );
        }
    }
    return array( 'ok' => true, 'result' => array( 'settings' => $out ) );
}

function sarudo_cmd_settings_set( $args, $is_dry_run = false ) {
    $updates = is_array( $args['settings'] ?? null ) ? $args['settings'] : array();
    if ( ! $updates ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_settings', 'message' => 'settings dict required' ) );
    $written = array();
    $rejected = array();
    foreach ( $updates as $k => $v ) {
        if ( in_array( $k, SARUDO_SETTINGS_PROTECTED, true ) && empty( $args['confirm_destructive'] ) ) {
            $rejected[ $k ] = 'protected — requires confirm_destructive=true';
            continue;
        }
        if ( ! in_array( $k, SARUDO_SETTINGS_WHITELIST, true ) && ! in_array( $k, SARUDO_SETTINGS_PROTECTED, true ) ) {
            $rejected[ $k ] = 'not in whitelist';
            continue;
        }
        if ( $is_dry_run ) {
            $written[ $k ] = "would set to " . wp_json_encode( $v );
            continue;
        }
        update_option( $k, $v );
        $written[ $k ] = $v;
    }
    return array( 'ok' => true, 'result' => array( 'written' => $written, 'rejected' => $rejected ) );
}

function sarudo_cmd_options_get( $args, $is_dry_run = false ) {
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    return array( 'ok' => true, 'result' => array( 'name' => $name, 'value' => get_option( $name, null ) ) );
}

function sarudo_cmd_options_update( $args, $is_dry_run = false ) {
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    if ( in_array( $name, SARUDO_SETTINGS_PROTECTED, true ) && empty( $args['confirm_destructive'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'protected_option', 'message' => "'$name' requires confirm_destructive=true" ) );
    }
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "update_option('$name')" );
    update_option( $name, $args['value'] ?? '' );
    return array( 'ok' => true, 'result' => array( 'name' => $name, 'value' => get_option( $name ) ) );
}

function sarudo_cmd_customizer_list_settings( $args, $is_dry_run = false ) {
    // Theme mods (the most common Customizer storage path)
    $mods = get_theme_mods() ?: array();
    return array( 'ok' => true, 'result' => array(
        'active_theme' => get_stylesheet(),
        'theme_mods' => $mods,
        'has_customizer' => current_theme_supports( 'customize' ),
        'is_block_theme' => wp_is_block_theme(),
    ) );
}

function sarudo_cmd_customizer_get( $args, $is_dry_run = false ) {
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    return array( 'ok' => true, 'result' => array( 'name' => $name, 'value' => get_theme_mod( $name ) ) );
}

function sarudo_cmd_customizer_set( $args, $is_dry_run = false ) {
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "set_theme_mod('$name')" );
    set_theme_mod( $name, $args['value'] ?? '' );
    return array( 'ok' => true, 'result' => array( 'name' => $name, 'value' => get_theme_mod( $name ) ) );
}

function sarudo_cmd_site_editor_get_template( $args, $is_dry_run = false ) {
    if ( ! wp_is_block_theme() ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_block_theme', 'message' => 'Active theme is not an FSE block theme' ) );
    }
    $slug = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    $template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
    if ( ! $template ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "template '$slug' not found" ) );
    return array( 'ok' => true, 'result' => array(
        'slug'    => $slug,
        'title'   => $template->title,
        'content' => $template->content,
        'source'  => $template->source,
    ) );
}

function sarudo_cmd_site_editor_set_template( $args, $is_dry_run = false ) {
    if ( ! wp_is_block_theme() ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_block_theme', 'message' => 'Active theme is not an FSE block theme' ) );
    }
    $slug = (string) ( $args['slug'] ?? '' );
    $content = (string) ( $args['content'] ?? '' );
    if ( ! $slug || ! $content ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'slug + content required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Write FSE template '$slug'" );

    // Templates persist as wp_template CPTs in WP 6.x+
    $existing = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
    if ( $existing && $existing->wp_id ) {
        wp_update_post( array( 'ID' => $existing->wp_id, 'post_content' => $content ) );
        return array( 'ok' => true, 'result' => array( 'slug' => $slug, 'updated' => true ) );
    }
    $post_id = wp_insert_post( array(
        'post_type'    => 'wp_template',
        'post_name'    => $slug,
        'post_title'   => sanitize_text_field( $args['title'] ?? $slug ),
        'post_content' => $content,
        'post_status'  => 'publish',
        'tax_input'    => array( 'wp_theme' => array( get_stylesheet() ) ),
    ) );
    if ( is_wp_error( $post_id ) ) return array( 'ok' => false, 'error' => array( 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'slug' => $slug, 'post_id' => $post_id, 'created' => true ) );
}
