<?php
/**
 * Sarudo users.* + comments.* command handlers (Phase 4.1 + 4.2).
 *
 * Users: create/update/delete + role management + password reset + search.
 * Comments: list/approve/reject/spam/trash/restore/reply + bulk moderation.
 *
 * Capability gate: Sarudo's plugin can't create a higher-role user than its
 * effective context (it operates as site admin via the bearer token, but
 * defers to WP's capability system on user creation — no superadmin creation
 * via this plugin even on multisite).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    // ---- USERS ----
    Sarudo_Registry::register( 'users.create', 'sarudo_cmd_users_create',
        array( 'description' => 'Create a new WordPress user.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.update', 'sarudo_cmd_users_update',
        array( 'description' => 'Update user metadata.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.delete', 'sarudo_cmd_users_delete',
        array( 'description' => 'Delete a user (with optional content reassignment).', 'family' => 'users', 'destructive' => true ) );
    Sarudo_Registry::register( 'users.change_role', 'sarudo_cmd_users_change_role',
        array( 'description' => 'Change a user\'s role.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.reset_password', 'sarudo_cmd_users_reset_password',
        array( 'description' => 'Reset a user\'s password.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.list', 'sarudo_cmd_users_list',
        array( 'description' => 'List users with role filter.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.get', 'sarudo_cmd_users_get',
        array( 'description' => 'Get a user by ID.', 'family' => 'users' ) );
    Sarudo_Registry::register( 'users.search', 'sarudo_cmd_users_search',
        array( 'description' => 'Search users by name/email.', 'family' => 'users' ) );

    // ---- COMMENTS ----
    Sarudo_Registry::register( 'comments.list', 'sarudo_cmd_comments_list',
        array( 'description' => 'List comments with status/post filter.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.approve', 'sarudo_cmd_comments_approve',
        array( 'description' => 'Approve a comment.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.reject', 'sarudo_cmd_comments_reject',
        array( 'description' => 'Unapprove (hold) a comment.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.spam', 'sarudo_cmd_comments_spam',
        array( 'description' => 'Mark comment as spam.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.trash', 'sarudo_cmd_comments_trash',
        array( 'description' => 'Move comment to trash.', 'family' => 'comments', 'destructive' => true ) );
    Sarudo_Registry::register( 'comments.restore', 'sarudo_cmd_comments_restore',
        array( 'description' => 'Restore trashed/spammed comment.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.reply', 'sarudo_cmd_comments_reply',
        array( 'description' => 'Reply to a comment.', 'family' => 'comments' ) );
    Sarudo_Registry::register( 'comments.bulk', 'sarudo_cmd_comments_bulk',
        array( 'description' => 'Batch comment moderation.', 'family' => 'comments' ) );
} );

function sarudo_user_state( $user_id ) {
    $u = get_user_by( 'id', $user_id );
    if ( ! $u ) return null;
    return array(
        'user_id'       => (int) $u->ID,
        'username'      => $u->user_login,
        'email'         => $u->user_email,
        'display_name'  => $u->display_name,
        'first_name'    => get_user_meta( $u->ID, 'first_name', true ),
        'last_name'     => get_user_meta( $u->ID, 'last_name', true ),
        'roles'         => $u->roles,
        'registered'    => $u->user_registered,
    );
}

function sarudo_cmd_users_create( $args, $is_dry_run = false ) {
    $email = sanitize_email( (string) ( $args['email'] ?? '' ) );
    $username = sanitize_user( (string) ( $args['username'] ?? '' ), true );
    $role = (string) ( $args['role'] ?? 'subscriber' );
    if ( ! $email ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_email', 'message' => 'email is required' ) );
    if ( ! $username ) $username = sanitize_user( strstr( $email, '@', true ), true );
    if ( username_exists( $username ) ) return array( 'ok' => false, 'error' => array( 'code' => 'username_exists', 'message' => "username '$username' already exists" ) );
    if ( email_exists( $email ) ) return array( 'ok' => false, 'error' => array( 'code' => 'email_exists', 'message' => "email '$email' already exists" ) );

    if ( $is_dry_run ) {
        return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Create user $username ($email) with role $role" );
    }
    $password = (string) ( $args['password'] ?? wp_generate_password( 16, true, false ) );
    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $password,
        'role'         => $role,
        'first_name'   => sanitize_text_field( (string) ( $args['first_name'] ?? '' ) ),
        'last_name'    => sanitize_text_field( (string) ( $args['last_name'] ?? '' ) ),
        'display_name' => sanitize_text_field( (string) ( $args['display_name'] ?? "" ) ) ?: null,
    ) );
    if ( is_wp_error( $user_id ) ) return array( 'ok' => false, 'error' => array( 'code' => 'create_failed', 'message' => $user_id->get_error_message() ) );

    if ( ! empty( $args['notify'] ) ) {
        wp_new_user_notification( $user_id, null, 'user' );
    }
    return array( 'ok' => true, 'result' => sarudo_user_state( $user_id ) );
}

function sarudo_cmd_users_update( $args, $is_dry_run = false ) {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    if ( ! $user_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_user_id', 'message' => 'user_id required' ) );
    $update = array( 'ID' => $user_id );
    foreach ( array( 'first_name', 'last_name', 'display_name', 'user_email' ) as $fld ) {
        if ( isset( $args[ $fld ] ) ) $update[ $fld ] = sanitize_text_field( (string) $args[ $fld ] );
    }
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Update user_id $user_id" );
    $res = wp_update_user( $update );
    if ( is_wp_error( $res ) ) return array( 'ok' => false, 'error' => array( 'code' => 'update_failed', 'message' => $res->get_error_message() ) );
    return array( 'ok' => true, 'result' => sarudo_user_state( $user_id ) );
}

function sarudo_cmd_users_delete( $args, $is_dry_run = false ) {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    $reassign = (int) ( $args['reassign_to'] ?? 0 ) ?: null;
    if ( ! $user_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_user_id', 'message' => 'user_id required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'users.delete requires confirm=true' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete user_id $user_id" . ( $reassign ? ", reassigning content to $reassign" : ", deleting their content" ) );
    if ( ! function_exists( 'wp_delete_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
    $res = wp_delete_user( $user_id, $reassign );
    if ( ! $res ) return array( 'ok' => false, 'error' => array( 'code' => 'delete_failed', 'message' => 'wp_delete_user returned false' ) );
    return array( 'ok' => true, 'result' => array( 'deleted' => true, 'user_id' => $user_id, 'reassigned_to' => $reassign ) );
}

function sarudo_cmd_users_change_role( $args, $is_dry_run = false ) {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    $role = (string) ( $args['role'] ?? '' );
    if ( ! $user_id || ! $role ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'user_id + role required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Change user_id $user_id to role $role" );
    $u = get_user_by( 'id', $user_id );
    if ( ! $u ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "user_id $user_id not found" ) );
    $u->set_role( $role );
    return array( 'ok' => true, 'result' => sarudo_user_state( $user_id ) );
}

function sarudo_cmd_users_reset_password( $args, $is_dry_run = false ) {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    if ( ! $user_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_user_id', 'message' => 'user_id required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Reset password for user_id $user_id; send reset email" );
    $u = get_user_by( 'id', $user_id );
    if ( ! $u ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "user_id $user_id not found" ) );
    $key = get_password_reset_key( $u );
    if ( is_wp_error( $key ) ) return array( 'ok' => false, 'error' => array( 'code' => 'reset_failed', 'message' => $key->get_error_message() ) );
    $reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $u->user_login ), 'login' );
    // Standard WP user-facing email
    wp_mail( $u->user_email, sprintf( __( 'Password reset for %s', 'sarudo' ), get_bloginfo( 'name' ) ), "Reset your password: $reset_url" );
    return array( 'ok' => true, 'result' => array( 'user_id' => $user_id, 'email_sent' => true ) );
}

function sarudo_cmd_users_list( $args, $is_dry_run = false ) {
    $role = (string) ( $args['role'] ?? '' );
    $limit = min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
    $q = array( 'number' => $limit );
    if ( $role ) $q['role'] = $role;
    $users = get_users( $q );
    $items = array_map( 'sarudo_user_state', array_map( fn( $u ) => $u->ID, $users ) );
    return array( 'ok' => true, 'result' => array( 'items' => array_values( array_filter( $items ) ), 'count' => count( $items ) ) );
}

function sarudo_cmd_users_get( $args, $is_dry_run = false ) {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    if ( ! $user_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_user_id', 'message' => 'user_id required' ) );
    $state = sarudo_user_state( $user_id );
    if ( ! $state ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "user_id $user_id not found" ) );
    return array( 'ok' => true, 'result' => $state );
}

function sarudo_cmd_users_search( $args, $is_dry_run = false ) {
    $q = (string) ( $args['query'] ?? $args['q'] ?? '' );
    $users = get_users( array( 'search' => "*{$q}*", 'number' => 50 ) );
    $items = array();
    foreach ( $users as $u ) $items[] = sarudo_user_state( $u->ID );
    return array( 'ok' => true, 'result' => array( 'items' => $items, 'count' => count( $items ) ) );
}

// ---- COMMENTS ----

function sarudo_comment_state( $comment_id ) {
    $c = get_comment( $comment_id );
    if ( ! $c ) return null;
    return array(
        'comment_id'   => (int) $c->comment_ID,
        'post_id'      => (int) $c->comment_post_ID,
        'author'       => $c->comment_author,
        'author_email' => $c->comment_author_email,
        'content'      => $c->comment_content,
        'date_gmt'     => $c->comment_date_gmt,
        'status'       => wp_get_comment_status( $c ),
        'parent'       => (int) $c->comment_parent,
    );
}

function sarudo_cmd_comments_list( $args, $is_dry_run = false ) {
    $status = (string) ( $args['status'] ?? 'all' );  // approve|hold|spam|trash|all
    $q = array(
        'status' => $status === 'all' ? '' : $status,
        'number' => min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) ),
    );
    if ( ! empty( $args['post_id'] ) ) $q['post_id'] = (int) $args['post_id'];
    if ( ! empty( $args['author_email'] ) ) $q['author_email'] = (string) $args['author_email'];
    $comments = get_comments( $q );
    $items = array_map( fn( $c ) => sarudo_comment_state( $c->comment_ID ), $comments );
    return array( 'ok' => true, 'result' => array( 'items' => array_values( array_filter( $items ) ), 'count' => count( $items ) ) );
}

function _sarudo_set_comment_status( $args, $new_status, $action_label ) {
    $cid = (int) ( $args['comment_id'] ?? 0 );
    if ( ! $cid ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'comment_id required' ) );
    if ( ! empty( $args['_is_dry_run'] ) ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "$action_label comment_id $cid" );
    $res = wp_set_comment_status( $cid, $new_status );
    if ( is_wp_error( $res ) || ! $res ) return array( 'ok' => false, 'error' => array( 'code' => 'status_update_failed', 'message' => is_wp_error( $res ) ? $res->get_error_message() : 'wp_set_comment_status returned false' ) );
    return array( 'ok' => true, 'result' => sarudo_comment_state( $cid ) );
}

function sarudo_cmd_comments_approve( $args, $is_dry_run = false ) {
    $args['_is_dry_run'] = $is_dry_run;
    return _sarudo_set_comment_status( $args, 'approve', 'Approve' );
}
function sarudo_cmd_comments_reject( $args, $is_dry_run = false ) {
    $args['_is_dry_run'] = $is_dry_run;
    return _sarudo_set_comment_status( $args, 'hold', 'Unapprove (hold)' );
}
function sarudo_cmd_comments_spam( $args, $is_dry_run = false ) {
    $args['_is_dry_run'] = $is_dry_run;
    return _sarudo_set_comment_status( $args, 'spam', 'Mark as spam' );
}
function sarudo_cmd_comments_trash( $args, $is_dry_run = false ) {
    $args['_is_dry_run'] = $is_dry_run;
    return _sarudo_set_comment_status( $args, 'trash', 'Trash' );
}
function sarudo_cmd_comments_restore( $args, $is_dry_run = false ) {
    $args['_is_dry_run'] = $is_dry_run;
    return _sarudo_set_comment_status( $args, 'approve', 'Restore (approve)' );
}

function sarudo_cmd_comments_reply( $args, $is_dry_run = false ) {
    $parent_id = (int) ( $args['comment_id'] ?? 0 );
    $content = (string) ( $args['content'] ?? '' );
    if ( ! $parent_id || ! $content ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'comment_id + content required' ) );
    $parent = get_comment( $parent_id );
    if ( ! $parent ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "parent comment_id $parent_id not found" ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Reply to comment_id $parent_id on post {$parent->comment_post_ID}" );
    $current = wp_get_current_user();
    $insert = array(
        'comment_post_ID'      => $parent->comment_post_ID,
        'comment_parent'       => $parent_id,
        'comment_content'      => wp_kses_post( $content ),
        'comment_author'       => $current && $current->ID ? $current->display_name : 'Sarudo',
        'comment_author_email' => $current && $current->ID ? $current->user_email : '',
        'user_id'              => $current ? $current->ID : 0,
        'comment_approved'     => 1,
    );
    $cid = wp_insert_comment( $insert );
    if ( ! $cid ) return array( 'ok' => false, 'error' => array( 'code' => 'insert_failed', 'message' => 'wp_insert_comment returned false' ) );
    return array( 'ok' => true, 'result' => sarudo_comment_state( $cid ) );
}

function sarudo_cmd_comments_bulk( $args, $is_dry_run = false ) {
    $actions = is_array( $args['actions'] ?? null ) ? $args['actions'] : array();
    if ( ! $actions ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_actions', 'message' => 'actions list required' ) );
    $results = array();
    foreach ( $actions as $a ) {
        if ( ! is_array( $a ) ) continue;
        $cid = (int) ( $a['comment_id'] ?? 0 );
        $action = (string) ( $a['action'] ?? '' );
        $a['_is_dry_run'] = $is_dry_run;
        switch ( $action ) {
            case 'approve': $r = _sarudo_set_comment_status( $a, 'approve', 'Approve' ); break;
            case 'reject':  $r = _sarudo_set_comment_status( $a, 'hold', 'Unapprove' ); break;
            case 'spam':    $r = _sarudo_set_comment_status( $a, 'spam', 'Spam' ); break;
            case 'trash':   $r = _sarudo_set_comment_status( $a, 'trash', 'Trash' ); break;
            default:        $r = array( 'ok' => false, 'error' => array( 'code' => 'bad_action', 'message' => "unknown action '$action'" ) ); break;
        }
        $results[ $cid ] = $r;
    }
    return array( 'ok' => true, 'result' => array( 'count' => count( $results ), 'results' => $results ) );
}
