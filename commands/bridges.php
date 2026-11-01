<?php
/**
 * Sarudo cache.* + backup.* + forms.* bridge commands (Phase 4.7 + 4.8 + 4.9).
 *
 * All three families follow the same "auto-detect active plugin + route to its API"
 * pattern. Returns the detected plugin name so the agent can decide whether
 * additional plugin-specific config is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'cache.detect_plugin', 'sarudo_cmd_cache_detect',
        array( 'description' => 'Identify which cache plugin is active.', 'family' => 'cache' ) );
    Sarudo_Registry::register( 'cache.purge', 'sarudo_cmd_cache_purge',
        array( 'description' => 'Purge the site\'s page cache.', 'family' => 'cache' ) );
    Sarudo_Registry::register( 'cache.purge_url', 'sarudo_cmd_cache_purge_url',
        array( 'description' => 'Purge cache for a specific URL.', 'family' => 'cache' ) );

    Sarudo_Registry::register( 'backup.detect_plugin', 'sarudo_cmd_backup_detect',
        array( 'description' => 'Identify which backup plugin is active.', 'family' => 'backup' ) );
    Sarudo_Registry::register( 'backup.create', 'sarudo_cmd_backup_create',
        array( 'description' => 'Trigger a backup via the detected backup plugin.', 'family' => 'backup' ) );
    Sarudo_Registry::register( 'backup.list', 'sarudo_cmd_backup_list',
        array( 'description' => 'List recent backups.', 'family' => 'backup' ) );
    Sarudo_Registry::register( 'backup.restore', 'sarudo_cmd_backup_restore',
        array( 'description' => 'Restore from a backup.', 'family' => 'backup', 'destructive' => true ) );

    Sarudo_Registry::register( 'forms.detect_plugin', 'sarudo_cmd_forms_detect',
        array( 'description' => 'Identify which forms plugin is active.', 'family' => 'forms' ) );
    Sarudo_Registry::register( 'forms.list', 'sarudo_cmd_forms_list',
        array( 'description' => 'List forms.', 'family' => 'forms' ) );
    Sarudo_Registry::register( 'forms.responses', 'sarudo_cmd_forms_responses',
        array( 'description' => 'Get submissions for a form.', 'family' => 'forms' ) );
} );

function _sarudo_active( $plugin_path ) {
    if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active( $plugin_path );
}

// ---- CACHE ----

function _sarudo_detect_cache_plugin() {
    if ( _sarudo_active( 'wp-rocket/wp-rocket.php' ) || function_exists( 'rocket_clean_domain' ) ) return 'wp_rocket';
    if ( _sarudo_active( 'w3-total-cache/w3-total-cache.php' ) || function_exists( 'w3tc_flush_all' ) ) return 'w3_total_cache';
    if ( _sarudo_active( 'wp-super-cache/wp-cache.php' ) || function_exists( 'wp_cache_clear_cache' ) ) return 'wp_super_cache';
    if ( _sarudo_active( 'litespeed-cache/litespeed-cache.php' ) || class_exists( 'LiteSpeed_Cache_API' ) ) return 'litespeed_cache';
    if ( _sarudo_active( 'autoptimize/autoptimize.php' ) ) return 'autoptimize';
    if ( _sarudo_active( 'sg-cachepress/sg-cachepress.php' ) ) return 'siteground_optimizer';
    if ( function_exists( 'wp_cache_flush' ) ) return 'wp_object_cache';
    return 'none';
}

function sarudo_cmd_cache_detect( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array( 'active' => _sarudo_detect_cache_plugin() ) );
}

function sarudo_cmd_cache_purge( $args, $is_dry_run = false ) {
    $cache = _sarudo_detect_cache_plugin();
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Purge $cache" );
    $purged = false;
    switch ( $cache ) {
        case 'wp_rocket':
            if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $purged = true; }
            break;
        case 'w3_total_cache':
            if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $purged = true; }
            break;
        case 'wp_super_cache':
            if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); $purged = true; }
            break;
        case 'litespeed_cache':
            if ( class_exists( 'LiteSpeed_Cache_API' ) ) { LiteSpeed_Cache_API::purge_all(); $purged = true; }
            break;
        case 'wp_object_cache':
            wp_cache_flush(); $purged = true;
            break;
    }
    return array( 'ok' => true, 'result' => array( 'cache' => $cache, 'purged' => $purged ) );
}

function sarudo_cmd_cache_purge_url( $args, $is_dry_run = false ) {
    $url = (string) ( $args['url'] ?? '' );
    if ( ! $url ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_url', 'message' => 'url required' ) );
    $cache = _sarudo_detect_cache_plugin();
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Purge $url via $cache" );
    $purged = false;
    switch ( $cache ) {
        case 'wp_rocket':
            if ( function_exists( 'rocket_clean_files' ) ) { rocket_clean_files( $url ); $purged = true; }
            break;
        case 'w3_total_cache':
            if ( function_exists( 'w3tc_pgcache_flush_url' ) ) { w3tc_pgcache_flush_url( $url ); $purged = true; }
            break;
        default:
            // Fallback: full purge if no per-URL purge available
            return sarudo_cmd_cache_purge( array(), false );
    }
    return array( 'ok' => true, 'result' => array( 'cache' => $cache, 'url' => $url, 'purged' => $purged ) );
}

// ---- BACKUP ----

function _sarudo_detect_backup_plugin() {
    if ( _sarudo_active( 'updraftplus/updraftplus.php' ) ) return 'updraftplus';
    if ( _sarudo_active( 'backwpup/backwpup.php' ) ) return 'backwpup';
    if ( _sarudo_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) ) return 'wpvivid';
    if ( _sarudo_active( 'blogvault-real-time-backup/blogvault.php' ) ) return 'blogvault';
    return 'none';
}

function sarudo_cmd_backup_detect( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array( 'active' => _sarudo_detect_backup_plugin() ) );
}

function sarudo_cmd_backup_create( $args, $is_dry_run = false ) {
    $plugin = _sarudo_detect_backup_plugin();
    if ( $plugin === 'none' ) return array( 'ok' => false, 'error' => array( 'code' => 'no_backup_plugin', 'message' => 'No supported backup plugin active. Install UpdraftPlus, BackWPup, WPvivid, or BlogVault.' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Trigger backup via $plugin" );

    $triggered = false;
    switch ( $plugin ) {
        case 'updraftplus':
            if ( class_exists( 'UpdraftPlus_Options' ) && function_exists( 'wp_clear_scheduled_hook' ) ) {
                do_action( 'updraft_backupnow_options' );
                wp_schedule_single_event( time() + 5, 'updraft_backup' );
                $triggered = true;
            }
            break;
        case 'wpvivid':
            if ( class_exists( 'WPvivid_Backup' ) ) {
                do_action( 'wpvivid_backup_now', array( 'backup_files' => 1, 'backup_database' => 1 ) );
                $triggered = true;
            }
            break;
        // backwpup + blogvault have their own scheduling mechanics; surface a clean message
        default:
            return array( 'ok' => false, 'error' => array(
                'code' => 'manual_trigger_required',
                'message' => "Backup trigger via $plugin needs operator action — backup plugins don't all expose programmatic triggers. Visit wp-admin → $plugin to run a backup manually."
            ) );
    }
    return array( 'ok' => true, 'result' => array( 'plugin' => $plugin, 'triggered' => $triggered ) );
}

function sarudo_cmd_backup_list( $args, $is_dry_run = false ) {
    $plugin = _sarudo_detect_backup_plugin();
    if ( $plugin === 'updraftplus' && class_exists( 'UpdraftPlus_Options' ) ) {
        $history = UpdraftPlus_Options::get_updraft_option( 'updraft_backup_history' );
        $items = array();
        if ( is_array( $history ) ) {
            foreach ( array_slice( $history, -20, 20, true ) as $ts => $bak ) {
                $items[] = array(
                    'timestamp' => (int) $ts,
                    'date'      => gmdate( 'Y-m-d H:i:s', (int) $ts ),
                    'nonce'     => $bak['nonce'] ?? null,
                );
            }
        }
        return array( 'ok' => true, 'result' => array( 'plugin' => $plugin, 'backups' => $items ) );
    }
    return array( 'ok' => true, 'result' => array(
        'plugin' => $plugin,
        'message' => 'Backup listing for this plugin not yet implemented; visit wp-admin to inspect.',
    ) );
}

function sarudo_cmd_backup_restore( $args, $is_dry_run = false ) {
    if ( empty( $args['confirm'] ) || empty( $args['backup_id'] ) ) {
        return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'backup.restore requires confirm=true + backup_id' ) );
    }
    return array( 'ok' => false, 'error' => array(
        'code' => 'manual_restore_required',
        'message' => 'Backup restore is destructive and not yet programmatically triggered — operator must run restore via the backup plugin\'s admin UI for safety.'
    ) );
}

// ---- FORMS ----

function _sarudo_detect_forms_plugin() {
    if ( _sarudo_active( 'wpforms-lite/wpforms.php' ) || _sarudo_active( 'wpforms/wpforms.php' ) ) return 'wpforms';
    if ( _sarudo_active( 'contact-form-7/wp-contact-form-7.php' ) ) return 'contact_form_7';
    if ( _sarudo_active( 'gravityforms/gravityforms.php' ) ) return 'gravity_forms';
    if ( _sarudo_active( 'ninja-forms/ninja-forms.php' ) ) return 'ninja_forms';
    if ( _sarudo_active( 'formidable/formidable.php' ) ) return 'formidable_forms';
    return 'none';
}

function sarudo_cmd_forms_detect( $args, $is_dry_run = false ) {
    return array( 'ok' => true, 'result' => array( 'active' => _sarudo_detect_forms_plugin() ) );
}

function sarudo_cmd_forms_list( $args, $is_dry_run = false ) {
    $plugin = _sarudo_detect_forms_plugin();
    if ( $plugin === 'none' ) return array( 'ok' => true, 'result' => array( 'plugin' => 'none', 'forms' => array() ) );
    $forms = array();
    switch ( $plugin ) {
        case 'contact_form_7':
            $items = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => 50 ) );
            foreach ( $items as $i ) $forms[] = array( 'form_id' => $i->ID, 'title' => $i->post_title );
            break;
        case 'wpforms':
            $items = get_posts( array( 'post_type' => 'wpforms', 'posts_per_page' => 50 ) );
            foreach ( $items as $i ) $forms[] = array( 'form_id' => $i->ID, 'title' => $i->post_title );
            break;
        case 'gravity_forms':
            if ( class_exists( 'GFAPI' ) ) {
                foreach ( GFAPI::get_forms() as $f ) $forms[] = array( 'form_id' => $f['id'], 'title' => $f['title'] );
            }
            break;
    }
    return array( 'ok' => true, 'result' => array( 'plugin' => $plugin, 'forms' => $forms ) );
}

function sarudo_cmd_forms_responses( $args, $is_dry_run = false ) {
    $form_id = (int) ( $args['form_id'] ?? 0 );
    $limit = min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
    if ( ! $form_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_form_id', 'message' => 'form_id required' ) );
    $plugin = _sarudo_detect_forms_plugin();

    if ( $plugin === 'gravity_forms' && class_exists( 'GFAPI' ) ) {
        $entries = GFAPI::get_entries( $form_id, array(), null, array( 'page_size' => $limit ) );
        return array( 'ok' => true, 'result' => array( 'plugin' => $plugin, 'form_id' => $form_id, 'count' => count( $entries ), 'entries' => $entries ) );
    }
    if ( $plugin === 'wpforms' && function_exists( 'wpforms' ) ) {
        $entries = wpforms()->get( 'entry' )->get_entries( array( 'form_id' => $form_id, 'number' => $limit ) );
        return array( 'ok' => true, 'result' => array( 'plugin' => $plugin, 'form_id' => $form_id, 'count' => count( $entries ?: array() ), 'entries' => $entries ) );
    }
    return array( 'ok' => true, 'result' => array(
        'plugin' => $plugin,
        'form_id' => $form_id,
        'message' => "Submissions inspection for $plugin not yet implemented — visit wp-admin to view.",
    ) );
}
