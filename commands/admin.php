<?php
/**
 * Sarudo files.* + database.* + maintenance.* + cron.* + site_health.* + updates.*
 * command handlers (Phase 4.10 + 4.11 + 4.12).
 *
 * The "site admin" surface: file ops scoped to wp-content/uploads by default,
 * raw SQL with destructive-confirm gating, maintenance toggle, WP-Cron CRUD,
 * Site Health snapshot, available-updates query.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    // FILES
    Sarudo_Registry::register( 'files.list', 'sarudo_cmd_files_list',
        array( 'description' => 'List files (scoped to uploads dir by default).', 'family' => 'files' ) );
    Sarudo_Registry::register( 'files.read', 'sarudo_cmd_files_read',
        array( 'description' => 'Read a text file.', 'family' => 'files' ) );
    Sarudo_Registry::register( 'files.write', 'sarudo_cmd_files_write',
        array( 'description' => 'Write a text file.', 'family' => 'files' ) );
    Sarudo_Registry::register( 'files.delete', 'sarudo_cmd_files_delete',
        array( 'description' => 'Delete a file.', 'family' => 'files', 'destructive' => true ) );

    // DATABASE
    Sarudo_Registry::register( 'database.query', 'sarudo_cmd_database_query',
        array( 'description' => 'Raw SQL (destructive ops require confirm_destructive=true).', 'family' => 'database', 'destructive' => true ) );

    // MAINTENANCE
    Sarudo_Registry::register( 'maintenance.enable', 'sarudo_cmd_maintenance_enable',
        array( 'description' => 'Put site in maintenance mode.', 'family' => 'maintenance' ) );
    Sarudo_Registry::register( 'maintenance.disable', 'sarudo_cmd_maintenance_disable',
        array( 'description' => 'Take site out of maintenance.', 'family' => 'maintenance' ) );

    // CRON
    Sarudo_Registry::register( 'cron.list', 'sarudo_cmd_cron_list',
        array( 'description' => 'List WP-Cron scheduled hooks.', 'family' => 'cron' ) );
    Sarudo_Registry::register( 'cron.run', 'sarudo_cmd_cron_run',
        array( 'description' => 'Trigger a scheduled hook immediately.', 'family' => 'cron' ) );
    Sarudo_Registry::register( 'cron.unschedule', 'sarudo_cmd_cron_unschedule',
        array( 'description' => 'Remove a scheduled hook.', 'family' => 'cron', 'destructive' => true ) );

    // SITE HEALTH + UPDATES
    Sarudo_Registry::register( 'site_health.report', 'sarudo_cmd_site_health_report',
        array( 'description' => 'Run a lightweight WP Site Health check.', 'family' => 'site_health' ) );
    Sarudo_Registry::register( 'updates.list', 'sarudo_cmd_updates_list',
        array( 'description' => 'List available core/plugin/theme updates.', 'family' => 'updates' ) );
} );

function _sarudo_resolve_safe_path( $path, $allow_unrestricted = false ) {
    // Path traversal guard
    if ( strpos( $path, '..' ) !== false ) return null;
    $uploads = wp_upload_dir();
    $base = trailingslashit( $uploads['basedir'] );
    if ( $allow_unrestricted ) {
        $base = WP_CONTENT_DIR . '/';
    }
    $resolved = realpath( $base . ltrim( $path, '/' ) );
    if ( $resolved === false ) {
        $resolved = $base . ltrim( $path, '/' );
    }
    if ( strpos( $resolved, realpath( $base ) ?: $base ) !== 0 ) {
        return null;  // escaped sandbox
    }
    return $resolved;
}

function sarudo_cmd_files_list( $args, $is_dry_run = false ) {
    $path = (string) ( $args['path'] ?? '' );
    $unrestricted = ! empty( $args['unrestricted'] );
    $abs = _sarudo_resolve_safe_path( $path, $unrestricted );
    if ( $abs === null ) return array( 'ok' => false, 'error' => array( 'code' => 'invalid_path', 'message' => 'path escapes sandbox' ) );
    if ( ! is_dir( $abs ) ) return array( 'ok' => false, 'error' => array( 'code' => 'not_dir', 'message' => "$path is not a directory" ) );
    $items = array();
    foreach ( scandir( $abs ) as $entry ) {
        if ( $entry === '.' || $entry === '..' ) continue;
        $full = $abs . '/' . $entry;
        $items[] = array(
            'name'   => $entry,
            'type'   => is_dir( $full ) ? 'dir' : 'file',
            'size'   => is_file( $full ) ? filesize( $full ) : null,
            'mtime'  => filemtime( $full ),
        );
    }
    return array( 'ok' => true, 'result' => array( 'path' => $path, 'items' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_files_read( $args, $is_dry_run = false ) {
    $path = (string) ( $args['path'] ?? '' );
    $unrestricted = ! empty( $args['unrestricted'] );
    $abs = _sarudo_resolve_safe_path( $path, $unrestricted );
    if ( $abs === null ) return array( 'ok' => false, 'error' => array( 'code' => 'invalid_path', 'message' => 'path escapes sandbox' ) );
    if ( ! is_file( $abs ) ) return array( 'ok' => false, 'error' => array( 'code' => 'not_file', 'message' => "$path is not a file" ) );
    $max = 5 * 1024 * 1024;  // 5MB cap
    if ( filesize( $abs ) > $max ) return array( 'ok' => false, 'error' => array( 'code' => 'file_too_large', 'message' => "file exceeds 5MB read cap" ) );
    $content = file_get_contents( $abs );
    return array( 'ok' => true, 'result' => array( 'path' => $path, 'size' => strlen( $content ), 'content' => $content ) );
}

function sarudo_cmd_files_write( $args, $is_dry_run = false ) {
    $path = (string) ( $args['path'] ?? '' );
    $content = (string) ( $args['content'] ?? '' );
    $unrestricted = ! empty( $args['unrestricted'] );
    $abs = _sarudo_resolve_safe_path( $path, $unrestricted );
    if ( $abs === null ) return array( 'ok' => false, 'error' => array( 'code' => 'invalid_path', 'message' => 'path escapes sandbox' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Write " . strlen( $content ) . " bytes to $path" );
    wp_mkdir_p( dirname( $abs ) );
    $bytes = file_put_contents( $abs, $content );
    if ( $bytes === false ) return array( 'ok' => false, 'error' => array( 'code' => 'write_failed', 'message' => "failed to write $path" ) );
    return array( 'ok' => true, 'result' => array( 'path' => $path, 'bytes_written' => $bytes ) );
}

function sarudo_cmd_files_delete( $args, $is_dry_run = false ) {
    $path = (string) ( $args['path'] ?? '' );
    $unrestricted = ! empty( $args['unrestricted'] );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'files.delete requires confirm=true' ) );
    $abs = _sarudo_resolve_safe_path( $path, $unrestricted );
    if ( $abs === null ) return array( 'ok' => false, 'error' => array( 'code' => 'invalid_path', 'message' => 'path escapes sandbox' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete $path" );
    if ( ! is_file( $abs ) ) return array( 'ok' => false, 'error' => array( 'code' => 'not_file', 'message' => "$path not a file" ) );
    $ok = unlink( $abs );
    return array( 'ok' => $ok, 'result' => array( 'deleted' => $ok, 'path' => $path ) );
}

// ---- DATABASE ----

function sarudo_cmd_database_query( $args, $is_dry_run = false ) {
    global $wpdb;
    $sql = (string) ( $args['sql'] ?? '' );
    if ( ! $sql ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_sql', 'message' => 'sql required' ) );

    // Destructive-op safety gate
    $destructive = preg_match( '/\b(DROP|TRUNCATE|ALTER\s+TABLE\s+\w+\s+DROP|DELETE\s+FROM(?!\s+.+?\s+WHERE))\b/i', $sql );
    if ( $destructive && empty( $args['confirm_destructive'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'destructive_blocked', 'message' => 'destructive SQL requires confirm_destructive=true' ) );
    }

    if ( $is_dry_run ) {
        // Return query plan instead of executing
        if ( stripos( ltrim( $sql ), 'SELECT' ) === 0 ) {
            $plan = $wpdb->get_results( "EXPLAIN " . $sql, ARRAY_A );
            return array( 'ok' => true, 'dry_run' => true, 'would_do' => 'execute SQL', 'plan' => $plan );
        }
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "execute SQL (no plan for non-SELECT)" );
    }

    // Execute
    if ( stripos( ltrim( $sql ), 'SELECT' ) === 0 ) {
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( $wpdb->last_error ) return array( 'ok' => false, 'error' => array( 'code' => 'sql_error', 'message' => $wpdb->last_error ) );
        // 10K row cap
        $rows = array_slice( $rows ?: array(), 0, 10000 );
        return array( 'ok' => true, 'result' => array( 'rows' => $rows, 'row_count' => count( $rows ) ) );
    }
    $affected = $wpdb->query( $sql );
    if ( $affected === false ) return array( 'ok' => false, 'error' => array( 'code' => 'sql_error', 'message' => $wpdb->last_error ) );
    return array( 'ok' => true, 'result' => array( 'affected_rows' => (int) $affected ) );
}

// ---- MAINTENANCE ----

function sarudo_cmd_maintenance_enable( $args, $is_dry_run = false ) {
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => 'Enable maintenance mode' );
    $message = sanitize_text_field( (string) ( $args['message'] ?? '' ) );
    $maintenance_file = ABSPATH . '.maintenance';
    file_put_contents( $maintenance_file, "<?php\n\$upgrading = time();\n" . ( $message ? "// $message" : "" ) );
    return array( 'ok' => true, 'result' => array( 'maintenance_active' => true ) );
}

function sarudo_cmd_maintenance_disable( $args, $is_dry_run = false ) {
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => 'Disable maintenance mode' );
    $maintenance_file = ABSPATH . '.maintenance';
    if ( file_exists( $maintenance_file ) ) {
        unlink( $maintenance_file );
    }
    return array( 'ok' => true, 'result' => array( 'maintenance_active' => false ) );
}

// ---- CRON ----

function sarudo_cmd_cron_list( $args, $is_dry_run = false ) {
    $cron = _get_cron_array();
    $items = array();
    foreach ( $cron as $ts => $hooks ) {
        foreach ( $hooks as $hook => $events ) {
            foreach ( $events as $sig => $event ) {
                $items[] = array(
                    'hook'      => $hook,
                    'timestamp' => (int) $ts,
                    'date'      => gmdate( 'Y-m-d H:i:s', (int) $ts ),
                    'schedule'  => $event['schedule'] ?? null,
                    'args'      => $event['args'] ?? array(),
                );
            }
        }
    }
    return array( 'ok' => true, 'result' => array( 'events' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_cron_run( $args, $is_dry_run = false ) {
    $hook = (string) ( $args['hook'] ?? '' );
    if ( ! $hook ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_hook', 'message' => 'hook required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Trigger hook '$hook'" );
    do_action_ref_array( $hook, is_array( $args['args'] ?? null ) ? $args['args'] : array() );
    return array( 'ok' => true, 'result' => array( 'hook' => $hook, 'triggered' => true ) );
}

function sarudo_cmd_cron_unschedule( $args, $is_dry_run = false ) {
    $hook = (string) ( $args['hook'] ?? '' );
    if ( ! $hook ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_hook', 'message' => 'hook required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Unschedule all instances of '$hook'" );
    wp_clear_scheduled_hook( $hook );
    return array( 'ok' => true, 'result' => array( 'hook' => $hook, 'unscheduled' => true ) );
}

// ---- SITE HEALTH ----

function sarudo_cmd_site_health_report( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array(
        'php_version'          => phpversion(),
        'php_version_ok'       => version_compare( phpversion(), '7.4', '>=' ),
        'wp_version'           => get_bloginfo( 'version' ),
        'wp_version_ok'        => version_compare( get_bloginfo( 'version' ), '6.0', '>=' ),
        'https_enabled'        => is_ssl(),
        'wp_cron_enabled'      => ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON,
        'memory_limit'         => ini_get( 'memory_limit' ),
        'max_upload_size'      => wp_max_upload_size(),
        'permalink_structure'  => get_option( 'permalink_structure' ) ?: 'default (?p=N)',
        'site_in_search'       => (bool) get_option( 'blog_public' ),
        'debug_mode'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'active_theme'         => get_stylesheet(),
        'plugin_count_active'  => count( (array) get_option( 'active_plugins', array() ) ),
        'multisite'            => is_multisite(),
    ) );
}

function sarudo_cmd_updates_list( $args, $is_dry_run = false ) {
    if ( ! function_exists( 'get_plugin_updates' ) ) require_once ABSPATH . 'wp-admin/includes/update.php';
    $core = get_site_transient( 'update_core' );
    $core_update = null;
    if ( isset( $core->updates[0] ) && $core->updates[0]->response === 'upgrade' ) {
        $core_update = array(
            'current' => get_bloginfo( 'version' ),
            'available' => $core->updates[0]->version ?? null,
        );
    }
    $plugin_updates = get_plugin_updates();
    $theme_updates = get_theme_updates();
    return array( 'ok' => true, 'result' => array(
        'core_update' => $core_update,
        'plugin_updates' => array_keys( $plugin_updates ),
        'plugin_update_count' => count( $plugin_updates ),
        'theme_updates' => array_keys( $theme_updates ),
        'theme_update_count' => count( $theme_updates ),
    ) );
}
