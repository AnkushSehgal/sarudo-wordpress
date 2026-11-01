<?php
/**
 * Sarudo menus.* + widgets.* command handlers (Phase 4.5).
 *
 * Navigation menus (nav_menu CPT) + widgets (both legacy widget areas and
 * block-based widget areas in modern WP).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    // MENUS
    Sarudo_Registry::register( 'menus.list', 'sarudo_cmd_menus_list',
        array( 'description' => 'List all navigation menus.', 'family' => 'menus' ) );
    Sarudo_Registry::register( 'menus.create', 'sarudo_cmd_menus_create',
        array( 'description' => 'Create a new navigation menu.', 'family' => 'menus' ) );
    Sarudo_Registry::register( 'menus.update', 'sarudo_cmd_menus_update',
        array( 'description' => 'Rename a menu.', 'family' => 'menus' ) );
    Sarudo_Registry::register( 'menus.delete', 'sarudo_cmd_menus_delete',
        array( 'description' => 'Delete a menu.', 'family' => 'menus', 'destructive' => true ) );
    Sarudo_Registry::register( 'menus.add_item', 'sarudo_cmd_menus_add_item',
        array( 'description' => 'Add an item to a menu.', 'family' => 'menus' ) );
    Sarudo_Registry::register( 'menus.remove_item', 'sarudo_cmd_menus_remove_item',
        array( 'description' => 'Remove an item from a menu.', 'family' => 'menus' ) );
    Sarudo_Registry::register( 'menus.set_location', 'sarudo_cmd_menus_set_location',
        array( 'description' => 'Assign a menu to a theme location.', 'family' => 'menus' ) );

    // WIDGETS
    Sarudo_Registry::register( 'widgets.list', 'sarudo_cmd_widgets_list',
        array( 'description' => 'List widget areas + their widgets.', 'family' => 'widgets' ) );
    Sarudo_Registry::register( 'widgets.add', 'sarudo_cmd_widgets_add',
        array( 'description' => 'Add a widget to an area.', 'family' => 'widgets' ) );
    Sarudo_Registry::register( 'widgets.remove', 'sarudo_cmd_widgets_remove',
        array( 'description' => 'Remove a widget from an area.', 'family' => 'widgets', 'destructive' => true ) );
} );

function sarudo_cmd_menus_list( $args, $is_dry_run = false ) {
    $menus = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    $items = array();
    foreach ( $menus as $m ) {
        $items[] = array(
            'menu_id'    => (int) $m->term_id,
            'name'       => $m->name,
            'slug'       => $m->slug,
            'item_count' => (int) $m->count,
            'locations'  => array_keys( $locations, $m->term_id, true ),
        );
    }
    return array( 'ok' => true, 'result' => array(
        'menus'              => $items,
        'theme_locations'    => get_registered_nav_menus(),
        'location_to_menu'   => $locations,
    ) );
}

function sarudo_cmd_menus_create( $args, $is_dry_run = false ) {
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Create menu '$name'" );
    $menu_id = wp_create_nav_menu( $name );
    if ( is_wp_error( $menu_id ) ) return array( 'ok' => false, 'error' => array( 'code' => 'create_failed', 'message' => $menu_id->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'menu_id' => $menu_id, 'name' => $name ) );
}

function sarudo_cmd_menus_update( $args, $is_dry_run = false ) {
    $menu_id = (int) ( $args['menu_id'] ?? 0 );
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $menu_id || ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'menu_id + name required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Rename menu_id $menu_id to '$name'" );
    $res = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
    if ( is_wp_error( $res ) ) return array( 'ok' => false, 'error' => array( 'code' => 'update_failed', 'message' => $res->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'menu_id' => $menu_id, 'name' => $name ) );
}

function sarudo_cmd_menus_delete( $args, $is_dry_run = false ) {
    $menu_id = (int) ( $args['menu_id'] ?? 0 );
    if ( ! $menu_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'menu_id required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'menus.delete requires confirm=true' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete menu_id $menu_id" );
    $res = wp_delete_nav_menu( $menu_id );
    if ( ! $res || is_wp_error( $res ) ) return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => is_wp_error( $res ) ? $res->get_error_message() : 'wp_delete_nav_menu returned false' ) );
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'menu_id' => $menu_id ) );
}

function sarudo_cmd_menus_add_item( $args, $is_dry_run = false ) {
    $menu_id = (int) ( $args['menu_id'] ?? 0 );
    if ( ! $menu_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_menu_id', 'message' => 'menu_id required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Add item to menu_id $menu_id" );

    $item_data = array(
        'menu-item-title'    => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
        'menu-item-url'      => esc_url_raw( (string) ( $args['url'] ?? '' ) ),
        'menu-item-status'   => 'publish',
        'menu-item-type'     => (string) ( $args['type'] ?? 'custom' ),
        'menu-item-object'   => (string) ( $args['object'] ?? '' ),
        'menu-item-object-id' => (int) ( $args['object_id'] ?? 0 ),
        'menu-item-parent-id' => (int) ( $args['parent_id'] ?? 0 ),
        'menu-item-position'  => (int) ( $args['position'] ?? 0 ),
    );
    $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );
    if ( is_wp_error( $item_id ) ) return array( 'ok' => false, 'error' => array( 'code' => 'add_failed', 'message' => $item_id->get_error_message() ) );
    return array( 'ok' => true, 'result' => array( 'menu_id' => $menu_id, 'item_id' => $item_id ) );
}

function sarudo_cmd_menus_remove_item( $args, $is_dry_run = false ) {
    $item_id = (int) ( $args['item_id'] ?? 0 );
    if ( ! $item_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'item_id required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Remove menu item_id $item_id" );
    $res = wp_delete_post( $item_id, true );
    return array( 'ok' => (bool) $res, 'result' => array( 'item_id' => $item_id, 'removed' => (bool) $res ) );
}

function sarudo_cmd_menus_set_location( $args, $is_dry_run = false ) {
    $menu_id = (int) ( $args['menu_id'] ?? 0 );
    $location = (string) ( $args['location'] ?? '' );
    if ( ! $menu_id || ! $location ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'menu_id + location required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Assign menu_id $menu_id to location '$location'" );
    $locations = get_theme_mod( 'nav_menu_locations', array() );
    $locations[ $location ] = $menu_id;
    set_theme_mod( 'nav_menu_locations', $locations );
    return array( 'ok' => true, 'result' => array( 'menu_id' => $menu_id, 'location' => $location ) );
}

// ---- WIDGETS ----

function sarudo_cmd_widgets_list( $args, $is_dry_run = false ) {
    global $wp_registered_sidebars;
    $sidebars_widgets = wp_get_sidebars_widgets();
    $out = array();
    foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
        if ( $sidebar_id === 'wp_inactive_widgets' ) continue;
        $sidebar_info = $wp_registered_sidebars[ $sidebar_id ] ?? array( 'name' => $sidebar_id );
        $out[ $sidebar_id ] = array(
            'name'       => $sidebar_info['name'] ?? $sidebar_id,
            'widget_ids' => is_array( $widget_ids ) ? $widget_ids : array(),
            'count'      => is_array( $widget_ids ) ? count( $widget_ids ) : 0,
        );
    }
    return array( 'ok' => true, 'result' => array( 'sidebars' => $out ) );
}

function sarudo_cmd_widgets_add( $args, $is_dry_run = false ) {
    $sidebar_id = (string) ( $args['sidebar_id'] ?? '' );
    $widget_type = (string) ( $args['widget_type'] ?? '' );
    if ( ! $sidebar_id || ! $widget_type ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'sidebar_id + widget_type required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Add widget '$widget_type' to '$sidebar_id'" );

    // Generate a new instance number
    $widget_instances = get_option( "widget_$widget_type", array() );
    $next_num = empty( $widget_instances ) ? 2 : ( max( array_keys( array_filter( $widget_instances, 'is_numeric', ARRAY_FILTER_USE_KEY ) ) ?: array( 1 ) ) + 1 );
    $widget_instances[ $next_num ] = is_array( $args['settings'] ?? null ) ? $args['settings'] : array();
    update_option( "widget_$widget_type", $widget_instances );

    $sidebars_widgets = wp_get_sidebars_widgets();
    $sidebars_widgets[ $sidebar_id ] = $sidebars_widgets[ $sidebar_id ] ?? array();
    $sidebars_widgets[ $sidebar_id ][] = "$widget_type-$next_num";
    wp_set_sidebars_widgets( $sidebars_widgets );

    return array( 'ok' => true, 'result' => array(
        'sidebar_id' => $sidebar_id,
        'widget_id'  => "$widget_type-$next_num",
    ) );
}

function sarudo_cmd_widgets_remove( $args, $is_dry_run = false ) {
    $sidebar_id = (string) ( $args['sidebar_id'] ?? '' );
    $widget_id = (string) ( $args['widget_id'] ?? '' );
    if ( ! $sidebar_id || ! $widget_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'sidebar_id + widget_id required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Remove widget '$widget_id' from '$sidebar_id'" );

    $sidebars_widgets = wp_get_sidebars_widgets();
    if ( ! isset( $sidebars_widgets[ $sidebar_id ] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'sidebar_not_found', 'message' => "sidebar '$sidebar_id' not found" ) );
    }
    $sidebars_widgets[ $sidebar_id ] = array_values( array_diff( $sidebars_widgets[ $sidebar_id ], array( $widget_id ) ) );
    wp_set_sidebars_widgets( $sidebars_widgets );
    return array( 'ok' => true, 'result' => array( 'sidebar_id' => $sidebar_id, 'widget_id' => $widget_id, 'removed' => true ) );
}
