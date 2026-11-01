<?php
/**
 * Sarudo seo.* command handlers.
 *
 * Auto-detects the active SEO plugin (Yoast / RankMath / AIOSEO) and routes
 * `seo.set_meta` to the right plugin's storage location, so Sarudo populates
 * SEO metadata instead of leaving it blank.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'seo.detect_active_plugin', 'sarudo_cmd_seo_detect_active_plugin',
        array( 'description' => 'Return yoast/rankmath/aioseo/none based on which is active.', 'family' => 'seo' ) );
    Sarudo_Registry::register( 'seo.set_meta', 'sarudo_cmd_seo_set_meta',
        array( 'description' => 'Set SEO metadata on a post via the active SEO plugin.', 'family' => 'seo' ) );
    Sarudo_Registry::register( 'seo.get_meta', 'sarudo_cmd_seo_get_meta',
        array( 'description' => 'Read SEO metadata for a post.', 'family' => 'seo' ) );
    Sarudo_Registry::register( 'seo.set_redirect', 'sarudo_cmd_seo_set_redirect',
        array( 'description' => 'Add a 301 redirect via the active SEO/redirect plugin.', 'family' => 'seo' ) );
    Sarudo_Registry::register( 'seo.list_redirects', 'sarudo_cmd_seo_list_redirects',
        array( 'description' => 'List configured redirects.', 'family' => 'seo' ) );
    Sarudo_Registry::register( 'seo.delete_redirect', 'sarudo_cmd_seo_delete_redirect',
        array( 'description' => 'Remove a configured redirect.', 'family' => 'seo', 'destructive' => true ) );
} );

function sarudo_detect_seo_plugin() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) return 'yoast';
    if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) return 'rankmath';
    if ( is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) return 'aioseo';
    return 'none';
}

function sarudo_cmd_seo_detect_active_plugin( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array( 'active' => sarudo_detect_seo_plugin() ) );
}

function sarudo_cmd_seo_set_meta( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id required' ) );
    }
    if ( ! get_post( $post_id ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "post_id $post_id not found" ) );
    }

    $plugin = sarudo_detect_seo_plugin();
    $title          = isset( $args['title'] )          ? (string) $args['title']          : null;
    $description    = isset( $args['description'] )    ? (string) $args['description']    : null;
    $focus_keyword  = isset( $args['focus_keyword'] )  ? (string) $args['focus_keyword']  : null;
    $og_image       = isset( $args['og_image'] )       ? (string) $args['og_image']       : null;
    $canonical      = isset( $args['canonical'] )      ? (string) $args['canonical']      : null;

    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Set SEO meta on post_id $post_id via $plugin plugin" );
    }

    $written = array();

    switch ( $plugin ) {
        case 'yoast':
            if ( $title !== null )         { update_post_meta( $post_id, '_yoast_wpseo_title', $title );          $written[] = '_yoast_wpseo_title'; }
            if ( $description !== null )   { update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description ); $written[] = '_yoast_wpseo_metadesc'; }
            if ( $focus_keyword !== null ) { update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword ); $written[] = '_yoast_wpseo_focuskw'; }
            if ( $canonical !== null )     { update_post_meta( $post_id, '_yoast_wpseo_canonical', $canonical );   $written[] = '_yoast_wpseo_canonical'; }
            if ( $og_image !== null )      { update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $og_image ); $written[] = '_yoast_wpseo_opengraph-image'; }
            break;

        case 'rankmath':
            if ( $title !== null )         { update_post_meta( $post_id, 'rank_math_title', $title );                 $written[] = 'rank_math_title'; }
            if ( $description !== null )   { update_post_meta( $post_id, 'rank_math_description', $description );     $written[] = 'rank_math_description'; }
            if ( $focus_keyword !== null ) { update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword ); $written[] = 'rank_math_focus_keyword'; }
            if ( $canonical !== null )     { update_post_meta( $post_id, 'rank_math_canonical_url', $canonical );     $written[] = 'rank_math_canonical_url'; }
            if ( $og_image !== null )      { update_post_meta( $post_id, 'rank_math_facebook_image', $og_image );     $written[] = 'rank_math_facebook_image'; }
            break;

        case 'aioseo':
            // AIOSEO v4+ uses a custom table (aioseo_posts), not post meta. Best path
            // is its own filter API. v0 fallback: write to legacy meta keys still read by aioseo for compatibility.
            if ( $title !== null )         { update_post_meta( $post_id, '_aioseo_title', $title );                $written[] = '_aioseo_title'; }
            if ( $description !== null )   { update_post_meta( $post_id, '_aioseo_description', $description );    $written[] = '_aioseo_description'; }
            if ( $focus_keyword !== null ) { update_post_meta( $post_id, '_aioseo_keywords', $focus_keyword );     $written[] = '_aioseo_keywords'; }
            if ( $canonical !== null )     { update_post_meta( $post_id, '_aioseo_canonical_url', $canonical );    $written[] = '_aioseo_canonical_url'; }
            break;

        case 'none':
        default:
            // Write to generic meta keys + the standard rel=canonical filter — at least
            // we don't lose the data; if the client installs Yoast later, it'll be picked up.
            if ( $title !== null )         { update_post_meta( $post_id, '_sarudo_seo_title', $title ); $written[] = '_sarudo_seo_title'; }
            if ( $description !== null )   { update_post_meta( $post_id, '_sarudo_seo_description', $description ); $written[] = '_sarudo_seo_description'; }
            if ( $focus_keyword !== null ) { update_post_meta( $post_id, '_sarudo_seo_focus_keyword', $focus_keyword ); $written[] = '_sarudo_seo_focus_keyword'; }
            if ( $canonical !== null )     { update_post_meta( $post_id, '_sarudo_seo_canonical', $canonical ); $written[] = '_sarudo_seo_canonical'; }
            if ( $og_image !== null )      { update_post_meta( $post_id, '_sarudo_seo_og_image', $og_image ); $written[] = '_sarudo_seo_og_image'; }
            break;
    }

    return array( 'ok' => true, 'result' => array(
        'post_id'         => $post_id,
        'seo_plugin'      => $plugin,
        'fields_written'  => $written,
    ) );
}

function sarudo_cmd_seo_get_meta( $args, $is_dry_run = false ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_post_id', 'message' => 'post_id required' ) );
    }
    $plugin = sarudo_detect_seo_plugin();
    $meta = array();
    switch ( $plugin ) {
        case 'yoast':
            $meta['title']         = get_post_meta( $post_id, '_yoast_wpseo_title', true );
            $meta['description']   = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            $meta['focus_keyword'] = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
            $meta['canonical']     = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
            $meta['og_image']      = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
            break;
        case 'rankmath':
            $meta['title']         = get_post_meta( $post_id, 'rank_math_title', true );
            $meta['description']   = get_post_meta( $post_id, 'rank_math_description', true );
            $meta['focus_keyword'] = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
            $meta['canonical']     = get_post_meta( $post_id, 'rank_math_canonical_url', true );
            break;
        case 'aioseo':
            $meta['title']         = get_post_meta( $post_id, '_aioseo_title', true );
            $meta['description']   = get_post_meta( $post_id, '_aioseo_description', true );
            $meta['focus_keyword'] = get_post_meta( $post_id, '_aioseo_keywords', true );
            $meta['canonical']     = get_post_meta( $post_id, '_aioseo_canonical_url', true );
            break;
        default:
            $meta['title']         = get_post_meta( $post_id, '_sarudo_seo_title', true );
            $meta['description']   = get_post_meta( $post_id, '_sarudo_seo_description', true );
            $meta['focus_keyword'] = get_post_meta( $post_id, '_sarudo_seo_focus_keyword', true );
            $meta['canonical']     = get_post_meta( $post_id, '_sarudo_seo_canonical', true );
            break;
    }
    return array( 'ok' => true, 'result' => array( 'post_id' => $post_id, 'seo_plugin' => $plugin, 'meta' => $meta ) );
}

/**
 * Redirects — implemented via a custom Sarudo table since redirect plugins
 * (Redirection, RankMath Pro, Yoast Premium) each have a different API.
 * The Sarudo redirect handler runs on `template_redirect` if no other plugin
 * caught the URL first.
 */
function sarudo_cmd_seo_set_redirect( $args, $is_dry_run = false ) {
    global $wpdb;
    $source = (string) ( $args['from'] ?? $args['source'] ?? '' );
    $target = (string) ( $args['to']   ?? $args['target'] ?? '' );
    $code   = (int) ( $args['status_code'] ?? 301 );
    if ( ! $source || ! $target ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'from + to are required' ) );
    }
    if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'invalid_status', 'message' => 'status_code must be 301/302/307/308' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Add $code redirect: $source → $target" );
    }
    $table = $wpdb->prefix . 'sarudo_redirects';
    sarudo_ensure_redirects_table();
    $wpdb->replace(
        $table,
        array( 'source' => $source, 'target' => $target, 'status_code' => $code, 'created_at' => current_time( 'mysql' ) ),
        array( '%s', '%s', '%d', '%s' )
    );
    return array( 'ok' => true, 'result' => array( 'from' => $source, 'to' => $target, 'status_code' => $code ) );
}

function sarudo_cmd_seo_list_redirects( $args, $is_dry_run = false ) {
    global $wpdb;
    sarudo_ensure_redirects_table();
    $table = $wpdb->prefix . 'sarudo_redirects';
    $rows = $wpdb->get_results( "SELECT id, source, target, status_code, created_at FROM $table ORDER BY id DESC LIMIT 500", ARRAY_A );
    return array( 'ok' => true, 'result' => array( 'redirects' => $rows ?: array() ) );
}

function sarudo_cmd_seo_delete_redirect( $args, $is_dry_run = false ) {
    global $wpdb;
    $id = (int) ( $args['id'] ?? 0 );
    $source = (string) ( $args['from'] ?? '' );
    if ( ! $id && ! $source ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'id or from required' ) );
    }
    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete redirect by " . ( $id ? "id=$id" : "from=$source" ) );
    }
    $table = $wpdb->prefix . 'sarudo_redirects';
    if ( $id ) {
        $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
    } else {
        $deleted = $wpdb->delete( $table, array( 'source' => $source ), array( '%s' ) );
    }
    return array( 'ok' => true, 'result' => array( 'deleted' => (int) $deleted ) );
}

function sarudo_ensure_redirects_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'sarudo_redirects';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(500) NOT NULL,
        target VARCHAR(500) NOT NULL,
        status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_source (source(255))
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// Apply Sarudo-configured redirects on the front-end
add_action( 'template_redirect', function () {
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'sarudo_redirects';
    // Don't query if the table doesn't exist yet (pre-activation state)
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return;
    }
    $request_path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT target, status_code FROM $table WHERE source = %s LIMIT 1",
        $request_path
    ), ARRAY_A );
    if ( $row && ! empty( $row['target'] ) ) {
        wp_redirect( $row['target'], (int) $row['status_code'] );
        exit;
    }
}, 1 );
