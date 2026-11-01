<?php
/**
 * Sarudo posts.* and pages.* command handlers.
 *
 * Accepts a BlogSection[] payload (the shape Sarudo's article writer produces) and
 * converts it to Gutenberg blocks. Featured image is uploaded to the media library
 * and set as `_thumbnail_id` properly, not rendered as an inline figure.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    // ---- POSTS ----
    Sarudo_Registry::register( 'posts.create', 'sarudo_cmd_posts_create',
        array( 'description' => 'Create a post; converts BlogSection[] to Gutenberg.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'posts.update', 'sarudo_cmd_posts_update',
        array( 'description' => 'Partial update by ID.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'posts.delete', 'sarudo_cmd_posts_delete',
        array( 'description' => 'Trash or permanent delete.', 'family' => 'content', 'destructive' => true ) );
    Sarudo_Registry::register( 'posts.trash', 'sarudo_cmd_posts_trash',
        array( 'description' => 'Move post to trash.', 'family' => 'content', 'destructive' => true ) );
    Sarudo_Registry::register( 'posts.restore', 'sarudo_cmd_posts_restore',
        array( 'description' => 'Restore from trash.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'posts.list', 'sarudo_cmd_posts_list',
        array( 'description' => 'List posts (filterable).', 'family' => 'content' ) );
    Sarudo_Registry::register( 'posts.get', 'sarudo_cmd_posts_get',
        array( 'description' => 'Get a single post by ID.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'posts.search', 'sarudo_cmd_posts_search',
        array( 'description' => 'Search posts by keyword.', 'family' => 'content' ) );

    // ---- PAGES ----
    Sarudo_Registry::register( 'pages.create', 'sarudo_cmd_pages_create',
        array( 'description' => 'Create a page.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'pages.update', 'sarudo_cmd_pages_update',
        array( 'description' => 'Update a page.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'pages.delete', 'sarudo_cmd_pages_delete',
        array( 'description' => 'Delete a page.', 'family' => 'content', 'destructive' => true ) );
    Sarudo_Registry::register( 'pages.list', 'sarudo_cmd_pages_list',
        array( 'description' => 'List pages.', 'family' => 'content' ) );

    // ---- REVISIONS ----
    Sarudo_Registry::register( 'revisions.list', 'sarudo_cmd_revisions_list',
        array( 'description' => 'List revisions for a post.', 'family' => 'content' ) );
    Sarudo_Registry::register( 'revisions.restore', 'sarudo_cmd_revisions_restore',
        array( 'description' => 'Restore a post to a prior revision.', 'family' => 'content' ) );
} );

/**
 * Convert BlogSection[] payload to Gutenberg block markup.
 *
 * Section shapes supported:
 *   {type:"paragraph", html|text}
 *   {type:"heading", level:2-6, text}
 *   {type:"list", style:"ul"|"ol", items:[...]}
 *   {type:"quote", text, cite}
 *   {type:"image", url|attachment_id, alt, caption}
 *   {type:"gif", url, alt}
 *   {type:"faq", qa:[{q,a},...]}
 *   {type:"cta", text, href}
 *
 * Plain HTML strings are also accepted and passed through as wp:html blocks.
 */
function sarudo_sections_to_gutenberg( $sections ) {
    if ( ! is_array( $sections ) ) {
        return is_string( $sections ) ? $sections : '';
    }
    $out = array();
    foreach ( $sections as $sec ) {
        if ( is_string( $sec ) ) {
            $out[] = '<!-- wp:html -->' . $sec . '<!-- /wp:html -->';
            continue;
        }
        $type = strtolower( (string) ( $sec['type'] ?? '' ) );
        switch ( $type ) {
            case 'paragraph':
            case 'p':
                $html = $sec['html'] ?? wp_kses_post( (string) ( $sec['text'] ?? '' ) );
                $out[] = '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
                break;

            case 'heading':
            case 'h':
                $level = max( 2, min( 6, (int) ( $sec['level'] ?? 2 ) ) );
                $text  = esc_html( (string) ( $sec['text'] ?? '' ) );
                $attrs = $level === 2 ? '' : ' {"level":' . $level . '}';
                $out[] = "<!-- wp:heading{$attrs} --><h{$level}>{$text}</h{$level}><!-- /wp:heading -->";
                break;

            case 'list':
            case 'ul':
            case 'ol':
                $style = $type === 'ol' || ( ( $sec['style'] ?? '' ) === 'ol' ) ? 'ol' : 'ul';
                $items = is_array( $sec['items'] ?? null ) ? $sec['items'] : array();
                $lis = '';
                foreach ( $items as $it ) {
                    $lis .= '<li>' . wp_kses_post( (string) $it ) . '</li>';
                }
                $attrs = $style === 'ol' ? ' {"ordered":true}' : '';
                $out[] = "<!-- wp:list{$attrs} --><{$style}>{$lis}</{$style}><!-- /wp:list -->";
                break;

            case 'quote':
                $text = wp_kses_post( (string) ( $sec['text'] ?? '' ) );
                $cite = isset( $sec['cite'] ) ? '<cite>' . esc_html( $sec['cite'] ) . '</cite>' : '';
                $out[] = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>' . $text . '</p>' . $cite . '</blockquote><!-- /wp:quote -->';
                break;

            case 'image':
            case 'gif':
                $attachment_id = (int) ( $sec['attachment_id'] ?? 0 );
                if ( ! $attachment_id && ! empty( $sec['url'] ) ) {
                    $attachment_id = sarudo_sideload_image( $sec['url'], (string) ( $sec['alt'] ?? '' ) );
                }
                if ( $attachment_id ) {
                    $src = wp_get_attachment_url( $attachment_id );
                    $alt = esc_attr( (string) ( $sec['alt'] ?? '' ) );
                    $cap = isset( $sec['caption'] ) ? '<figcaption>' . esc_html( $sec['caption'] ) . '</figcaption>' : '';
                    $attrs = '{"id":' . $attachment_id . ',"sizeSlug":"large"}';
                    $out[] = "<!-- wp:image {$attrs} --><figure class=\"wp-block-image size-large\"><img src=\"{$src}\" alt=\"{$alt}\"/>{$cap}</figure><!-- /wp:image -->";
                }
                break;

            case 'faq':
                $qa = is_array( $sec['qa'] ?? null ) ? $sec['qa'] : array();
                if ( sarudo_is_rankmath_active() ) {
                    // RankMath supports a native FAQ block — generate its shape
                    $items = array();
                    foreach ( $qa as $pair ) {
                        $items[] = array(
                            'title'   => (string) ( $pair['q'] ?? '' ),
                            'content' => (string) ( $pair['a'] ?? '' ),
                        );
                    }
                    $attrs = wp_json_encode( array( 'questions' => $items ) );
                    $out[] = "<!-- wp:rank-math/faq-block {$attrs} /-->";
                } else {
                    // Plain details/summary fallback
                    $html = '';
                    foreach ( $qa as $pair ) {
                        $q = esc_html( (string) ( $pair['q'] ?? '' ) );
                        $a = wp_kses_post( (string) ( $pair['a'] ?? '' ) );
                        $html .= "<details><summary>{$q}</summary><div>{$a}</div></details>";
                    }
                    $out[] = '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
                }
                break;

            case 'cta':
                $text = esc_html( (string) ( $sec['text'] ?? 'Learn more' ) );
                $href = esc_url( (string) ( $sec['href'] ?? '#' ) );
                $out[] = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="' . $href . '">' . $text . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
                break;

            default:
                // Unknown type → pass through as paragraph for graceful degradation
                if ( ! empty( $sec['text'] ) ) {
                    $out[] = '<!-- wp:paragraph --><p>' . wp_kses_post( (string) $sec['text'] ) . '</p><!-- /wp:paragraph -->';
                }
                break;
        }
    }
    return implode( "\n\n", $out );
}

function sarudo_is_rankmath_active() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active( 'seo-by-rank-math/rank-math.php' );
}

/**
 * Upload an image from a URL into the WP media library, return attachment_id.
 * Used for both featured images AND inline image sections, so a hero image is
 * never rendered as an inline figure.
 */
function sarudo_sideload_image( $url, $alt = '' ) {
    if ( ! function_exists( 'media_sideload_image' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $url = (string) $url;
    if ( ! $url ) {
        return 0;
    }
    $tmp = download_url( $url );
    if ( is_wp_error( $tmp ) ) {
        return 0;
    }
    $file = array(
        'name'     => sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ?: 'sarudo-upload.jpg' ) ),
        'tmp_name' => $tmp,
    );
    $attachment_id = media_handle_sideload( $file, 0 );
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        return 0;
    }
    if ( $alt ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    }
    return (int) $attachment_id;
}

/**
 * Resolve a category/tag slug to a term_id, auto-creating if missing.
 */
function sarudo_resolve_or_create_term( $taxonomy, $slug_or_name ) {
    $slug = sanitize_title( $slug_or_name );
    $term = get_term_by( 'slug', $slug, $taxonomy );
    if ( $term ) {
        return (int) $term->term_id;
    }
    $term = get_term_by( 'name', $slug_or_name, $taxonomy );
    if ( $term ) {
        return (int) $term->term_id;
    }
    // Auto-create
    $inserted = wp_insert_term( $slug_or_name, $taxonomy, array( 'slug' => $slug ) );
    if ( is_wp_error( $inserted ) ) {
        return 0;
    }
    return (int) $inserted['term_id'];
}

/**
 * Build the post-state response object used by verifier companions.
 */
function sarudo_post_state( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return null;
    }
    $featured_id = (int) get_post_thumbnail_id( $post_id );
    return array(
        'post_id'           => (int) $post_id,
        'post_url'          => get_permalink( $post_id ),
        'post_modified'     => $post->post_modified_gmt,
        'status'            => $post->post_status,
        'slug'              => $post->post_name,
        'title'             => $post->post_title,
        'post_type'         => $post->post_type,
        'featured_media_id' => $featured_id ?: null,
        'categories'        => wp_get_post_terms( $post_id, 'category', array( 'fields' => 'slugs' ) ),
        'tags'              => wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'slugs' ) ),
        'author_id'         => (int) $post->post_author,
        'date_gmt'          => $post->post_date_gmt,
    );
}

// =========================================================================
// posts.* / pages.* handlers
// =========================================================================

function sarudo_cmd_posts_create( $args, $is_dry_run = false ) {
    return sarudo_create_post( $args, 'post', $is_dry_run );
}

function sarudo_cmd_pages_create( $args, $is_dry_run = false ) {
    return sarudo_create_post( $args, 'page', $is_dry_run );
}

function sarudo_create_post( $args, $post_type, $is_dry_run ) {
    $title   = (string) ( $args['title'] ?? '' );
    $slug    = (string) ( $args['slug'] ?? sanitize_title( $title ) );
    $status  = (string) ( $args['status'] ?? 'publish' );
    $content_in = $args['content'] ?? '';
    $excerpt = (string) ( $args['excerpt'] ?? '' );
    $author_id = (int) ( $args['author_id'] ?? get_current_user_id() ?: 1 );

    if ( ! $title ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_title', 'message' => 'title is required' ) );
    }

    if ( $is_dry_run ) {
        return array(
            'ok'      => true,
            'dry_run' => true,
            'would_do' => "Create a $post_type titled \"$title\" with status=$status and slug=$slug",
            'preview' => array( 'title' => $title, 'slug' => $slug, 'status' => $status, 'post_type' => $post_type ),
        );
    }

    // Idempotency: if a post with the same slug + post_type already exists, return it instead of creating a duplicate
    if ( $slug ) {
        $existing = get_page_by_path( $slug, OBJECT, $post_type );
        if ( $existing ) {
            return array(
                'ok'     => true,
                'result' => array(
                    'idempotent_hit' => true,
                    'state'          => sarudo_post_state( $existing->ID ),
                ),
            );
        }
    }

    // Convert sections-based content to Gutenberg if it's an array
    $content_html = is_array( $content_in ) ? sarudo_sections_to_gutenberg( $content_in ) : (string) $content_in;

    $post_data = array(
        'post_title'   => sanitize_text_field( $title ),
        'post_name'    => $slug,
        'post_content' => $content_html,
        'post_excerpt' => sanitize_textarea_field( $excerpt ),
        'post_status'  => $status,
        'post_type'    => $post_type,
        'post_author'  => $author_id,
    );

    if ( ! empty( $args['date_gmt'] ) ) {
        $post_data['post_date_gmt'] = $args['date_gmt'];
        $post_data['post_date']     = get_date_from_gmt( $args['date_gmt'] );
    }

    $post_id = wp_insert_post( $post_data, true );
    if ( is_wp_error( $post_id ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ) );
    }

    // Featured image (set as _thumbnail_id, not an inline figure)
    if ( ! empty( $args['featured_image_url'] ) ) {
        $att_id = sarudo_sideload_image( $args['featured_image_url'], (string) ( $args['featured_image_alt'] ?? '' ) );
        if ( $att_id ) {
            set_post_thumbnail( $post_id, $att_id );
        }
    } elseif ( ! empty( $args['featured_media_id'] ) ) {
        set_post_thumbnail( $post_id, (int) $args['featured_media_id'] );
    }

    // Categories (auto-create by slug when missing)
    if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
        $cat_ids = array();
        foreach ( $args['categories'] as $cat ) {
            $cid = sarudo_resolve_or_create_term( 'category', $cat );
            if ( $cid ) {
                $cat_ids[] = $cid;
            }
        }
        if ( $cat_ids ) {
            wp_set_post_categories( $post_id, $cat_ids );
        }
    }

    // Tags
    if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
        $tag_ids = array();
        foreach ( $args['tags'] as $t ) {
            $tid = sarudo_resolve_or_create_term( 'post_tag', $t );
            if ( $tid ) {
                $tag_ids[] = $tid;
            }
        }
        if ( $tag_ids ) {
            wp_set_post_tags( $post_id, $args['tags'] );
        }
    }

    return array( 'ok' => true, 'result' => sarudo_post_state( $post_id ) );
}

function sarudo_cmd_posts_update( $args, $is_dry_run = false ) {
    return sarudo_update_post( $args, $is_dry_run );
}

function sarudo_cmd_pages_update( $args, $is_dry_run = false ) {
    return sarudo_update_post( $args, $is_dry_run );
}

function sarudo_update_post( $args, $is_dry_run ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id is required' ) );
    }
    if ( ! get_post( $post_id ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "post_id $post_id not found" ) );
    }

    $update = array( 'ID' => $post_id );
    if ( isset( $args['title'] ) )   $update['post_title']   = sanitize_text_field( (string) $args['title'] );
    if ( isset( $args['excerpt'] ) ) $update['post_excerpt'] = sanitize_textarea_field( (string) $args['excerpt'] );
    if ( isset( $args['status'] ) )  $update['post_status']  = (string) $args['status'];
    if ( isset( $args['slug'] ) )    $update['post_name']    = (string) $args['slug'];
    if ( isset( $args['content'] ) ) {
        $update['post_content'] = is_array( $args['content'] )
            ? sarudo_sections_to_gutenberg( $args['content'] )
            : (string) $args['content'];
    }

    if ( $is_dry_run ) {
        return array(
            'ok'      => true,
            'dry_run' => true,
            'would_do' => "Update post_id $post_id with " . count( $update ) - 1 . " field(s)",
            'preview' => $update,
        );
    }

    $res = wp_update_post( $update, true );
    if ( is_wp_error( $res ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'update_failed', 'message' => $res->get_error_message() ) );
    }

    if ( isset( $args['featured_image_url'] ) ) {
        $att_id = sarudo_sideload_image( $args['featured_image_url'], (string) ( $args['featured_image_alt'] ?? '' ) );
        if ( $att_id ) {
            set_post_thumbnail( $post_id, $att_id );
        }
    }

    if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
        $cat_ids = array();
        foreach ( $args['categories'] as $cat ) {
            $cid = sarudo_resolve_or_create_term( 'category', $cat );
            if ( $cid ) $cat_ids[] = $cid;
        }
        wp_set_post_categories( $post_id, $cat_ids );
    }
    if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
        wp_set_post_tags( $post_id, $args['tags'] );
    }

    return array( 'ok' => true, 'result' => sarudo_post_state( $post_id ) );
}

function sarudo_cmd_posts_delete( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    $force   = ! empty( $args['force'] );

    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id is required' ) );
    }
    if ( $force && empty( $args['confirm'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'force=true requires confirm=true' ) );
    }
    if ( $is_dry_run ) {
        return array(
            'ok'       => true,
            'dry_run'  => true,
            'would_do' => $force ? "Permanently delete post_id $post_id" : "Trash post_id $post_id",
        );
    }
    $res = wp_delete_post( $post_id, $force );
    if ( ! $res ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => 'wp_delete_post returned false' ) );
    }
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'forced' => $force, 'post_id' => $post_id ) );
}

function sarudo_cmd_pages_delete( $args, $is_dry_run = false ) {
    return sarudo_cmd_posts_delete( $args, $is_dry_run );
}

function sarudo_cmd_posts_trash( $args, $is_dry_run = false ) {
    $args['force'] = false;
    return sarudo_cmd_posts_delete( $args, $is_dry_run );
}

function sarudo_cmd_posts_restore( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Restore post_id $post_id from trash" );
    }
    $res = wp_untrash_post( $post_id );
    if ( ! $res ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'restore_failed', 'message' => 'wp_untrash_post returned false' ) );
    }
    return array( 'ok' => true, 'result' => sarudo_post_state( $post_id ) );
}

function sarudo_cmd_posts_list( $args, $is_dry_run = false ) {
    return sarudo_list_posts( $args, 'post' );
}
function sarudo_cmd_pages_list( $args, $is_dry_run = false ) {
    return sarudo_list_posts( $args, 'page' );
}
function sarudo_list_posts( $args, $post_type ) {
    $q = array(
        'post_type'      => $post_type,
        'post_status'    => $args['status'] ?? 'any',
        'posts_per_page' => min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) ),
        'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
        'orderby'        => $args['orderby'] ?? 'date',
        'order'          => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ),
    );
    if ( ! empty( $args['category'] ) ) {
        $q['category_name'] = $args['category'];
    }
    if ( ! empty( $args['tag'] ) ) {
        $q['tag'] = $args['tag'];
    }
    $query = new WP_Query( $q );
    $items = array();
    foreach ( $query->posts as $p ) {
        $items[] = sarudo_post_state( $p->ID );
    }
    return array(
        'ok'     => true,
        'result' => array(
            'items'       => $items,
            'total'       => (int) $query->found_posts,
            'page'        => $q['paged'],
            'per_page'    => $q['posts_per_page'],
            'total_pages' => (int) $query->max_num_pages,
        ),
    );
}

function sarudo_cmd_posts_get( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id required' ) );
    }
    $state = sarudo_post_state( $post_id );
    if ( ! $state ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "post_id $post_id not found" ) );
    }
    if ( ! empty( $args['include_content'] ) ) {
        $post = get_post( $post_id );
        $state['content'] = $post->post_content;
        $state['excerpt'] = $post->post_excerpt;
    }
    return array( 'ok' => true, 'result' => $state );
}

function sarudo_cmd_posts_search( $args, $is_dry_run = false ) {
    $args['s'] = (string) ( $args['query'] ?? $args['q'] ?? '' );
    return sarudo_list_posts( $args, 'post' );
}

function sarudo_cmd_revisions_list( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id required' ) );
    }
    $revisions = wp_get_post_revisions( $post_id );
    $items = array();
    foreach ( $revisions as $rev ) {
        $items[] = array(
            'revision_id' => $rev->ID,
            'date_gmt'    => $rev->post_modified_gmt,
            'author_id'   => (int) $rev->post_author,
        );
    }
    return array( 'ok' => true, 'result' => array( 'post_id' => $post_id, 'revisions' => $items ) );
}

function sarudo_cmd_revisions_restore( $args, $is_dry_run = false ) {
    $revision_id = (int) ( $args['revision_id'] ?? 0 );
    if ( ! $revision_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_revision_id', 'message' => 'revision_id required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Restore post to revision_id $revision_id" );
    }
    $res = wp_restore_post_revision( $revision_id );
    if ( ! $res || is_wp_error( $res ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'restore_failed', 'message' => is_wp_error( $res ) ? $res->get_error_message() : 'wp_restore_post_revision returned false' ) );
    }
    return array( 'ok' => true, 'result' => sarudo_post_state( (int) $res ) );
}
