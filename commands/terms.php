<?php
/**
 * Sarudo terms.* command handlers (categories + tags + custom taxonomies).
 *
 * Before these handlers, `posts.create` with category slugs
 * that don't exist used to silently skip them. With these handlers + the
 * `sarudo_resolve_or_create_term` helper used by posts.create, missing terms
 * are auto-created.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'terms.create', 'sarudo_cmd_terms_create',
        array( 'description' => 'Create a term in a taxonomy.', 'family' => 'terms' ) );
    Sarudo_Registry::register( 'terms.list', 'sarudo_cmd_terms_list',
        array( 'description' => 'List terms in a taxonomy.', 'family' => 'terms' ) );
    Sarudo_Registry::register( 'terms.get', 'sarudo_cmd_terms_get',
        array( 'description' => 'Get a term by ID.', 'family' => 'terms' ) );
    Sarudo_Registry::register( 'terms.get_by_slug', 'sarudo_cmd_terms_get_by_slug',
        array( 'description' => 'Get a term by slug.', 'family' => 'terms' ) );
    Sarudo_Registry::register( 'terms.update', 'sarudo_cmd_terms_update',
        array( 'description' => 'Update a term name/slug/description.', 'family' => 'terms' ) );
    Sarudo_Registry::register( 'terms.delete', 'sarudo_cmd_terms_delete',
        array( 'description' => 'Delete a term.', 'family' => 'terms', 'destructive' => true ) );
} );

function sarudo_term_state( $term ) {
    if ( is_int( $term ) || is_string( $term ) ) {
        $term = get_term( $term );
    }
    if ( ! $term || is_wp_error( $term ) ) {
        return null;
    }
    return array(
        'term_id'     => (int) $term->term_id,
        'taxonomy'    => $term->taxonomy,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'count'       => (int) $term->count,
        'parent'      => (int) $term->parent,
    );
}

function sarudo_cmd_terms_create( $args, $is_dry_run = false ) {
    $taxonomy = (string) ( $args['taxonomy'] ?? 'category' );
    $name     = (string) ( $args['name'] ?? '' );
    if ( ! $name ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name is required' ) );
    }
    if ( ! taxonomy_exists( $taxonomy ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'unknown_taxonomy', 'message' => "taxonomy $taxonomy is not registered" ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Create term '$name' in taxonomy '$taxonomy'" );
    }
    $insert_args = array();
    if ( isset( $args['slug'] ) )        $insert_args['slug']        = sanitize_title( (string) $args['slug'] );
    if ( isset( $args['description'] ) ) $insert_args['description'] = sanitize_textarea_field( (string) $args['description'] );
    if ( isset( $args['parent_id'] ) )   $insert_args['parent']      = (int) $args['parent_id'];

    // Idempotent: if a term with the same slug exists, return it instead of erroring
    $slug = $insert_args['slug'] ?? sanitize_title( $name );
    $existing = get_term_by( 'slug', $slug, $taxonomy );
    if ( $existing ) {
        return array( 'ok' => true, 'result' => array(
            'idempotent_hit' => true,
            'state'          => sarudo_term_state( $existing ),
        ) );
    }

    $res = wp_insert_term( $name, $taxonomy, $insert_args );
    if ( is_wp_error( $res ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'insert_failed', 'message' => $res->get_error_message() ) );
    }
    return array( 'ok' => true, 'result' => sarudo_term_state( (int) $res['term_id'] ) );
}

function sarudo_cmd_terms_list( $args, $is_dry_run = false ) {
    $taxonomy = (string) ( $args['taxonomy'] ?? 'category' );
    if ( ! taxonomy_exists( $taxonomy ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'unknown_taxonomy', 'message' => "taxonomy $taxonomy is not registered" ) );
    }
    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => min( 500, max( 1, (int) ( $args['limit'] ?? 100 ) ) ),
    ) );
    if ( is_wp_error( $terms ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'list_failed', 'message' => $terms->get_error_message() ) );
    }
    $items = array_map( 'sarudo_term_state', $terms );
    return array( 'ok' => true, 'result' => array( 'taxonomy' => $taxonomy, 'items' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_terms_get( $args, $is_dry_run = false ) {
    $id = (int) ( $args['term_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'term_id required' ) );
    }
    $state = sarudo_term_state( $id );
    if ( ! $state ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "term_id $id not found" ) );
    }
    return array( 'ok' => true, 'result' => $state );
}

function sarudo_cmd_terms_get_by_slug( $args, $is_dry_run = false ) {
    $taxonomy = (string) ( $args['taxonomy'] ?? 'category' );
    $slug     = (string) ( $args['slug'] ?? '' );
    if ( ! $slug ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_slug', 'message' => 'slug required' ) );
    }
    $term = get_term_by( 'slug', $slug, $taxonomy );
    if ( ! $term ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "no term with slug '$slug' in '$taxonomy'" ) );
    }
    return array( 'ok' => true, 'result' => sarudo_term_state( $term ) );
}

function sarudo_cmd_terms_update( $args, $is_dry_run = false ) {
    $id = (int) ( $args['term_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'term_id required' ) );
    }
    $term = get_term( $id );
    if ( ! $term || is_wp_error( $term ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "term_id $id not found" ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Update term_id $id" );
    }
    $update = array();
    if ( isset( $args['name'] ) )        $update['name']        = sanitize_text_field( (string) $args['name'] );
    if ( isset( $args['slug'] ) )        $update['slug']        = sanitize_title( (string) $args['slug'] );
    if ( isset( $args['description'] ) ) $update['description'] = sanitize_textarea_field( (string) $args['description'] );
    if ( isset( $args['parent_id'] ) )   $update['parent']      = (int) $args['parent_id'];

    $res = wp_update_term( $id, $term->taxonomy, $update );
    if ( is_wp_error( $res ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'update_failed', 'message' => $res->get_error_message() ) );
    }
    return array( 'ok' => true, 'result' => sarudo_term_state( (int) $res['term_id'] ) );
}

function sarudo_cmd_terms_delete( $args, $is_dry_run = false ) {
    $id = (int) ( $args['term_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'term_id required' ) );
    }
    if ( empty( $args['confirm'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'terms.delete requires confirm=true' ) );
    }
    $term = get_term( $id );
    if ( ! $term || is_wp_error( $term ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "term_id $id not found" ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete term_id $id from taxonomy '$term->taxonomy'" );
    }
    $res = wp_delete_term( $id, $term->taxonomy );
    if ( is_wp_error( $res ) || ! $res ) {
        $msg = is_wp_error( $res ) ? $res->get_error_message() : 'wp_delete_term returned false';
        return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => $msg ) );
    }
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'term_id' => $id ) );
}
