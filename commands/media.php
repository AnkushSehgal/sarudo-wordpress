<?php
/**
 * Sarudo media.* command handlers.
 *
 * `media.upload_from_url` is the common case: download an image (Unsplash hero,
 * Giphy reaction GIF, etc.) into the WP media library and return attachment_id.
 * Used by posts.create with `featured_image_url` to set hero images as
 * `_thumbnail_id` properly (not as an inline figure).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'media.upload_from_url', 'sarudo_cmd_media_upload_from_url',
        array( 'description' => 'Download an image from URL into media library.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.upload', 'sarudo_cmd_media_upload',
        array( 'description' => 'Upload base64-encoded image data.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.list', 'sarudo_cmd_media_list',
        array( 'description' => 'List media library items.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.get', 'sarudo_cmd_media_get',
        array( 'description' => 'Get media item by attachment_id.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.delete', 'sarudo_cmd_media_delete',
        array( 'description' => 'Delete media item.', 'family' => 'media', 'destructive' => true ) );
    Sarudo_Registry::register( 'media.set_alt', 'sarudo_cmd_media_set_alt',
        array( 'description' => 'Set alt text on a media item.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.regenerate_thumbnails', 'sarudo_cmd_media_regenerate_thumbnails',
        array( 'description' => 'Regenerate thumbnails for an attachment.', 'family' => 'media' ) );
    Sarudo_Registry::register( 'media.search', 'sarudo_cmd_media_search',
        array( 'description' => 'Search media library by filename/title.', 'family' => 'media' ) );
} );

function sarudo_media_state( $attachment_id ) {
    $post = get_post( $attachment_id );
    if ( ! $post || $post->post_type !== 'attachment' ) {
        return null;
    }
    $meta = wp_get_attachment_metadata( $attachment_id ) ?: array();
    return array(
        'attachment_id' => (int) $attachment_id,
        'url'           => wp_get_attachment_url( $attachment_id ),
        'mime_type'     => $post->post_mime_type,
        'title'         => $post->post_title,
        'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
        'caption'       => $post->post_excerpt,
        'description'   => $post->post_content,
        'width'         => $meta['width'] ?? null,
        'height'        => $meta['height'] ?? null,
        'filesize'      => $meta['filesize'] ?? null,
        'date_gmt'      => $post->post_date_gmt,
    );
}

function sarudo_cmd_media_upload_from_url( $args, $is_dry_run = false ) {
    $url = (string) ( $args['url'] ?? '' );
    if ( ! $url ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_url', 'message' => 'url is required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Download $url into media library" );
    }
    $attachment_id = sarudo_sideload_image( $url, (string) ( $args['alt'] ?? '' ) );
    if ( ! $attachment_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'sideload_failed', 'message' => "Failed to sideload $url" ) );
    }
    // Optional metadata
    if ( isset( $args['title'] ) || isset( $args['caption'] ) || isset( $args['description'] ) ) {
        $update = array( 'ID' => $attachment_id );
        if ( isset( $args['title'] ) )       $update['post_title']   = sanitize_text_field( (string) $args['title'] );
        if ( isset( $args['caption'] ) )     $update['post_excerpt'] = sanitize_textarea_field( (string) $args['caption'] );
        if ( isset( $args['description'] ) ) $update['post_content'] = wp_kses_post( (string) $args['description'] );
        wp_update_post( $update );
    }
    return array( 'ok' => true, 'result' => sarudo_media_state( $attachment_id ) );
}

function sarudo_cmd_media_upload( $args, $is_dry_run = false ) {
    $b64 = (string) ( $args['data_base64'] ?? '' );
    $filename = sanitize_file_name( (string) ( $args['filename'] ?? 'sarudo-upload.bin' ) );
    if ( ! $b64 ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_data', 'message' => 'data_base64 is required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Upload $filename (" . strlen( $b64 ) . " base64 bytes)" );
    }
    $bytes = base64_decode( $b64, true );
    if ( $bytes === false ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'bad_base64', 'message' => 'data_base64 could not be decoded' ) );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $upload = wp_upload_bits( $filename, null, $bytes );
    if ( $upload['error'] ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'upload_failed', 'message' => $upload['error'] ) );
    }
    $attachment = array(
        'post_mime_type' => wp_check_filetype( $filename )['type'] ?? 'application/octet-stream',
        'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
    if ( is_wp_error( $attachment_id ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'insert_attachment_failed', 'message' => $attachment_id->get_error_message() ) );
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
    wp_update_attachment_metadata( $attachment_id, $meta );

    if ( ! empty( $args['alt'] ) ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
    }
    return array( 'ok' => true, 'result' => sarudo_media_state( $attachment_id ) );
}

function sarudo_cmd_media_list( $args, $is_dry_run = false ) {
    $limit = min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) );
    $page  = max( 1, (int) ( $args['page'] ?? 1 ) );
    $query = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $limit,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    $items = array();
    foreach ( $query->posts as $p ) {
        $items[] = sarudo_media_state( $p->ID );
    }
    return array( 'ok' => true, 'result' => array(
        'items'       => $items,
        'total'       => (int) $query->found_posts,
        'page'        => $page,
        'per_page'    => $limit,
        'total_pages' => (int) $query->max_num_pages,
    ) );
}

function sarudo_cmd_media_get( $args, $is_dry_run = false ) {
    $id = (int) ( $args['attachment_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'attachment_id required' ) );
    }
    $state = sarudo_media_state( $id );
    if ( ! $state ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "attachment_id $id not found" ) );
    }
    return array( 'ok' => true, 'result' => $state );
}

function sarudo_cmd_media_delete( $args, $is_dry_run = false ) {
    $id = (int) ( $args['attachment_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'attachment_id required' ) );
    }
    if ( empty( $args['confirm'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'media.delete requires confirm=true' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Permanently delete attachment_id $id" );
    }
    $res = wp_delete_attachment( $id, true );
    if ( ! $res ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => 'wp_delete_attachment returned false' ) );
    }
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'attachment_id' => $id ) );
}

function sarudo_cmd_media_set_alt( $args, $is_dry_run = false ) {
    $id = (int) ( $args['attachment_id'] ?? 0 );
    $alt = (string) ( $args['alt'] ?? '' );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'attachment_id required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Set alt='$alt' on attachment_id $id" );
    }
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    return array( 'ok' => true, 'result' => sarudo_media_state( $id ) );
}

function sarudo_cmd_media_regenerate_thumbnails( $args, $is_dry_run = false ) {
    $id = (int) ( $args['attachment_id'] ?? 0 );
    if ( ! $id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'attachment_id required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Regenerate thumbnails for attachment_id $id" );
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $file = get_attached_file( $id );
    if ( ! $file ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'file_missing', 'message' => "Attached file for $id not found on disk" ) );
    }
    $meta = wp_generate_attachment_metadata( $id, $file );
    wp_update_attachment_metadata( $id, $meta );
    return array( 'ok' => true, 'result' => sarudo_media_state( $id ) );
}

function sarudo_cmd_media_search( $args, $is_dry_run = false ) {
    $args['s'] = (string) ( $args['query'] ?? $args['q'] ?? '' );
    $query = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        's'              => $args['s'],
        'posts_per_page' => min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) ),
        'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
    ) );
    $items = array();
    foreach ( $query->posts as $p ) {
        $items[] = sarudo_media_state( $p->ID );
    }
    return array( 'ok' => true, 'result' => array( 'items' => $items, 'total' => (int) $query->found_posts ) );
}
