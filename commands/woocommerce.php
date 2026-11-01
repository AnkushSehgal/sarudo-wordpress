<?php
/**
 * Sarudo WooCommerce commands (Phase 6.1 of CLIENT-INTEGRATIONS-PLAN.md v1.5).
 *
 * Activates when WooCommerce is detected on the site (capabilities endpoint
 * advertises woocommerce:true). All commands no-op gracefully if WC is not active,
 * returning a clean error instead of fatal-ing.
 *
 * Coverage: products / orders / coupons / customers / reports.
 * Surface follows WC's REST API conventions (wc/v3) for consistency, but uses
 * direct WC PHP API calls for performance + auth simplicity (no double-token).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'sarudo_register_commands_now', function () {
    Sarudo_Registry::register( 'wc.status_check', 'sarudo_cmd_wc_status_check',
        array( 'description' => 'Check whether WooCommerce is active + capabilities snapshot.', 'family' => 'woocommerce' ) );

    // PRODUCTS
    Sarudo_Registry::register( 'wc.products.create', 'sarudo_cmd_wc_products_create',
        array( 'description' => 'Create a product.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.products.update', 'sarudo_cmd_wc_products_update',
        array( 'description' => 'Update a product.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.products.delete', 'sarudo_cmd_wc_products_delete',
        array( 'description' => 'Delete a product.', 'family' => 'woocommerce', 'destructive' => true ) );
    Sarudo_Registry::register( 'wc.products.list', 'sarudo_cmd_wc_products_list',
        array( 'description' => 'List products with filter.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.products.get', 'sarudo_cmd_wc_products_get',
        array( 'description' => 'Get a single product.', 'family' => 'woocommerce' ) );

    // ORDERS
    Sarudo_Registry::register( 'wc.orders.list', 'sarudo_cmd_wc_orders_list',
        array( 'description' => 'List orders with status/date filter.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.orders.get', 'sarudo_cmd_wc_orders_get',
        array( 'description' => 'Get a single order.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.orders.update_status', 'sarudo_cmd_wc_orders_update_status',
        array( 'description' => 'Change an order\'s status.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.orders.refund', 'sarudo_cmd_wc_orders_refund',
        array( 'description' => 'Refund an order (partial or full).', 'family' => 'woocommerce', 'destructive' => true ) );
    Sarudo_Registry::register( 'wc.orders.note', 'sarudo_cmd_wc_orders_note',
        array( 'description' => 'Add a customer or private note to an order.', 'family' => 'woocommerce' ) );

    // CUSTOMERS
    Sarudo_Registry::register( 'wc.customers.list', 'sarudo_cmd_wc_customers_list',
        array( 'description' => 'List customers.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.customers.get', 'sarudo_cmd_wc_customers_get',
        array( 'description' => 'Get a single customer.', 'family' => 'woocommerce' ) );

    // COUPONS
    Sarudo_Registry::register( 'wc.coupons.list', 'sarudo_cmd_wc_coupons_list',
        array( 'description' => 'List discount coupons.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.coupons.create', 'sarudo_cmd_wc_coupons_create',
        array( 'description' => 'Create a coupon.', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.coupons.delete', 'sarudo_cmd_wc_coupons_delete',
        array( 'description' => 'Delete a coupon.', 'family' => 'woocommerce', 'destructive' => true ) );

    // REPORTS
    Sarudo_Registry::register( 'wc.reports.sales', 'sarudo_cmd_wc_reports_sales',
        array( 'description' => 'Sales report (date range).', 'family' => 'woocommerce' ) );
    Sarudo_Registry::register( 'wc.reports.top_sellers', 'sarudo_cmd_wc_reports_top_sellers',
        array( 'description' => 'Top-selling products.', 'family' => 'woocommerce' ) );
} );

function _sarudo_wc_active() {
    return class_exists( 'WooCommerce' );
}

function _sarudo_wc_error_not_active() {
    return array( 'ok' => false, 'error' => array(
        'code' => 'woocommerce_not_active',
        'message' => 'WooCommerce is not active on this site. Install + activate WooCommerce first via plugins.install({slug:"woocommerce"}) → plugins.activate.',
    ) );
}

function sarudo_cmd_wc_status_check( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) {
        return array( 'ok' => true, 'result' => array( 'active' => false, 'reason' => 'WooCommerce plugin not active' ) );
    }
    $version = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
    return array( 'ok' => true, 'result' => array(
        'active'           => true,
        'wc_version'       => $version,
        'product_count'    => (int) wp_count_posts( 'product' )->publish,
        'order_count_30d'  => count( wc_get_orders( array( 'date_created' => '>' . ( time() - 30 * 86400 ), 'limit' => -1, 'return' => 'ids' ) ) ),
        'currency'         => get_woocommerce_currency(),
        'currency_symbol'  => get_woocommerce_currency_symbol(),
    ) );
}

function sarudo_wc_product_state( $product_id ) {
    $p = wc_get_product( $product_id );
    if ( ! $p ) return null;
    return array(
        'product_id'    => $p->get_id(),
        'name'          => $p->get_name(),
        'slug'          => $p->get_slug(),
        'sku'           => $p->get_sku(),
        'price'         => $p->get_price(),
        'regular_price' => $p->get_regular_price(),
        'sale_price'    => $p->get_sale_price(),
        'stock_status'  => $p->get_stock_status(),
        'stock_quantity' => $p->get_stock_quantity(),
        'status'        => $p->get_status(),
        'type'          => $p->get_type(),
        'url'           => $p->get_permalink(),
    );
}

function sarudo_cmd_wc_products_create( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $name = (string) ( $args['name'] ?? '' );
    if ( ! $name ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_name', 'message' => 'name required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Create product '$name'" );

    $type = (string) ( $args['type'] ?? 'simple' );
    $class = "WC_Product_" . ucfirst( $type );
    if ( ! class_exists( $class ) ) $class = 'WC_Product_Simple';
    $product = new $class();
    $product->set_name( $name );
    if ( ! empty( $args['regular_price'] ) ) $product->set_regular_price( (string) $args['regular_price'] );
    if ( isset( $args['sale_price'] ) )      $product->set_sale_price( (string) $args['sale_price'] );
    if ( isset( $args['description'] ) )     $product->set_description( wp_kses_post( $args['description'] ) );
    if ( isset( $args['short_description'] ) ) $product->set_short_description( wp_kses_post( $args['short_description'] ) );
    if ( isset( $args['sku'] ) )             $product->set_sku( (string) $args['sku'] );
    if ( isset( $args['manage_stock'] ) )    $product->set_manage_stock( (bool) $args['manage_stock'] );
    if ( isset( $args['stock_quantity'] ) )  $product->set_stock_quantity( (int) $args['stock_quantity'] );
    if ( isset( $args['status'] ) )          $product->set_status( (string) $args['status'] );
    $product->save();
    return array( 'ok' => true, 'result' => sarudo_wc_product_state( $product->get_id() ) );
}

function sarudo_cmd_wc_products_update( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $product_id = (int) ( $args['product_id'] ?? 0 );
    if ( ! $product_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'product_id required' ) );
    $product = wc_get_product( $product_id );
    if ( ! $product ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "product_id $product_id not found" ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Update product_id $product_id" );
    if ( isset( $args['name'] ) )            $product->set_name( (string) $args['name'] );
    if ( isset( $args['regular_price'] ) )   $product->set_regular_price( (string) $args['regular_price'] );
    if ( isset( $args['sale_price'] ) )      $product->set_sale_price( (string) $args['sale_price'] );
    if ( isset( $args['stock_quantity'] ) )  $product->set_stock_quantity( (int) $args['stock_quantity'] );
    if ( isset( $args['stock_status'] ) )    $product->set_stock_status( (string) $args['stock_status'] );
    if ( isset( $args['description'] ) )     $product->set_description( wp_kses_post( $args['description'] ) );
    if ( isset( $args['status'] ) )          $product->set_status( (string) $args['status'] );
    $product->save();
    return array( 'ok' => true, 'result' => sarudo_wc_product_state( $product_id ) );
}

function sarudo_cmd_wc_products_delete( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $product_id = (int) ( $args['product_id'] ?? 0 );
    if ( ! $product_id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'product_id required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'wc.products.delete requires confirm=true' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete product_id $product_id" );
    $force = ! empty( $args['force'] );
    $product = wc_get_product( $product_id );
    if ( ! $product ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "product_id $product_id not found" ) );
    $ok = $product->delete( $force );
    return array( 'ok' => (bool) $ok, 'result' => array( 'product_id' => $product_id, 'deleted' => (bool) $ok, 'forced' => $force ) );
}

function sarudo_cmd_wc_products_list( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $q = array(
        'limit'  => min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) ),
        'page'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
        'status' => $args['status'] ?? 'any',
    );
    if ( ! empty( $args['category'] ) ) $q['category'] = $args['category'];
    $products = wc_get_products( $q );
    $items = array_map( fn( $p ) => sarudo_wc_product_state( $p->get_id() ), $products );
    return array( 'ok' => true, 'result' => array( 'items' => array_values( array_filter( $items ) ), 'count' => count( $items ) ) );
}

function sarudo_cmd_wc_products_get( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['product_id'] ?? 0 );
    if ( ! $id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'product_id required' ) );
    $state = sarudo_wc_product_state( $id );
    if ( ! $state ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "product_id $id not found" ) );
    return array( 'ok' => true, 'result' => $state );
}

// ---- ORDERS ----

function sarudo_wc_order_state( $order ) {
    if ( is_int( $order ) ) $order = wc_get_order( $order );
    if ( ! $order ) return null;
    return array(
        'order_id'      => $order->get_id(),
        'order_number'  => $order->get_order_number(),
        'status'        => $order->get_status(),
        'total'         => $order->get_total(),
        'currency'      => $order->get_currency(),
        'customer_email' => $order->get_billing_email(),
        'customer_id'   => $order->get_customer_id(),
        'created_at'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
        'paid_at'       => $order->get_date_paid() ? $order->get_date_paid()->format( 'c' ) : null,
        'item_count'    => $order->get_item_count(),
    );
}

function sarudo_cmd_wc_orders_list( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $q = array(
        'limit'  => min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) ),
        'page'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
        'status' => $args['status'] ?? 'any',
    );
    if ( ! empty( $args['customer_email'] ) ) $q['customer'] = $args['customer_email'];
    if ( ! empty( $args['since'] ) ) $q['date_created'] = '>' . (int) strtotime( $args['since'] );
    $orders = wc_get_orders( $q );
    $items = array_map( 'sarudo_wc_order_state', $orders );
    return array( 'ok' => true, 'result' => array( 'items' => array_values( array_filter( $items ) ), 'count' => count( $items ) ) );
}

function sarudo_cmd_wc_orders_get( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['order_id'] ?? 0 );
    if ( ! $id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'order_id required' ) );
    $state = sarudo_wc_order_state( $id );
    if ( ! $state ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "order_id $id not found" ) );
    if ( ! empty( $args['include_items'] ) ) {
        $order = wc_get_order( $id );
        $line_items = array();
        foreach ( $order->get_items() as $item ) {
            $line_items[] = array(
                'name'     => $item->get_name(),
                'product_id' => $item->get_product_id(),
                'quantity' => $item->get_quantity(),
                'total'    => $item->get_total(),
            );
        }
        $state['line_items'] = $line_items;
    }
    return array( 'ok' => true, 'result' => $state );
}

function sarudo_cmd_wc_orders_update_status( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['order_id'] ?? 0 );
    $status = (string) ( $args['status'] ?? '' );
    if ( ! $id || ! $status ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'order_id + status required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Set order_id $id status to '$status'" );
    $order = wc_get_order( $id );
    if ( ! $order ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "order_id $id not found" ) );
    $order->update_status( $status, (string) ( $args['note'] ?? '' ) );
    return array( 'ok' => true, 'result' => sarudo_wc_order_state( $id ) );
}

function sarudo_cmd_wc_orders_refund( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['order_id'] ?? 0 );
    if ( ! $id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'order_id required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'wc.orders.refund requires confirm=true' ) );
    $order = wc_get_order( $id );
    if ( ! $order ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "order_id $id not found" ) );
    $amount = isset( $args['amount'] ) ? (float) $args['amount'] : (float) $order->get_total();
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Refund $amount on order_id $id" );
    $reason = (string) ( $args['reason'] ?? 'Sarudo-initiated refund' );
    $refund = wc_create_refund( array( 'order_id' => $id, 'amount' => $amount, 'reason' => $reason ) );
    if ( is_wp_error( $refund ) ) return array( 'ok' => false, 'error' => array( 'code' => 'refund_failed', 'message' => $refund->get_error_message() ) );
    return array( 'ok' => true, 'result' => array(
        'order_id'  => $id,
        'refund_id' => $refund->get_id(),
        'amount'    => $amount,
        'reason'    => $reason,
    ) );
}

function sarudo_cmd_wc_orders_note( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['order_id'] ?? 0 );
    $note = (string) ( $args['note'] ?? '' );
    if ( ! $id || ! $note ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'order_id + note required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Add note to order_id $id" );
    $order = wc_get_order( $id );
    if ( ! $order ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "order_id $id not found" ) );
    $is_customer_note = ! empty( $args['customer_note'] );
    $note_id = $order->add_order_note( $note, $is_customer_note );
    return array( 'ok' => true, 'result' => array( 'order_id' => $id, 'note_id' => $note_id, 'is_customer_note' => $is_customer_note ) );
}

// ---- CUSTOMERS ----

function sarudo_cmd_wc_customers_list( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $users = get_users( array( 'role' => 'customer', 'number' => min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) ) ) );
    $items = array();
    foreach ( $users as $u ) {
        $items[] = array(
            'customer_id' => $u->ID,
            'email'       => $u->user_email,
            'first_name'  => get_user_meta( $u->ID, 'first_name', true ),
            'last_name'   => get_user_meta( $u->ID, 'last_name', true ),
            'registered'  => $u->user_registered,
        );
    }
    return array( 'ok' => true, 'result' => array( 'items' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_wc_customers_get( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['customer_id'] ?? 0 );
    if ( ! $id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'customer_id required' ) );
    $customer = new WC_Customer( $id );
    if ( ! $customer->get_id() ) return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => "customer_id $id not found" ) );
    return array( 'ok' => true, 'result' => array(
        'customer_id'    => $customer->get_id(),
        'email'          => $customer->get_email(),
        'first_name'     => $customer->get_first_name(),
        'last_name'      => $customer->get_last_name(),
        'billing_address' => $customer->get_billing(),
        'total_spent'    => $customer->get_total_spent(),
        'order_count'    => $customer->get_order_count(),
    ) );
}

// ---- COUPONS ----

function sarudo_cmd_wc_coupons_list( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $coupons = get_posts( array( 'post_type' => 'shop_coupon', 'posts_per_page' => 50, 'post_status' => 'publish' ) );
    $items = array();
    foreach ( $coupons as $c ) {
        $coupon = new WC_Coupon( $c->ID );
        $items[] = array(
            'coupon_id'        => $c->ID,
            'code'             => $coupon->get_code(),
            'discount_type'    => $coupon->get_discount_type(),
            'amount'           => $coupon->get_amount(),
            'usage_count'      => $coupon->get_usage_count(),
            'usage_limit'      => $coupon->get_usage_limit(),
            'date_expires'     => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'c' ) : null,
        );
    }
    return array( 'ok' => true, 'result' => array( 'items' => $items, 'count' => count( $items ) ) );
}

function sarudo_cmd_wc_coupons_create( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $code = (string) ( $args['code'] ?? '' );
    $amount = (float) ( $args['amount'] ?? 0 );
    if ( ! $code || $amount <= 0 ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_args', 'message' => 'code + amount > 0 required' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Create coupon '$code' for $amount" );
    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( (string) ( $args['discount_type'] ?? 'percent' ) );  // percent / fixed_cart / fixed_product
    $coupon->set_amount( $amount );
    if ( isset( $args['usage_limit'] ) ) $coupon->set_usage_limit( (int) $args['usage_limit'] );
    if ( isset( $args['date_expires'] ) ) $coupon->set_date_expires( $args['date_expires'] );
    if ( isset( $args['description'] ) ) $coupon->set_description( (string) $args['description'] );
    $coupon->save();
    return array( 'ok' => true, 'result' => array( 'coupon_id' => $coupon->get_id(), 'code' => $coupon->get_code() ) );
}

function sarudo_cmd_wc_coupons_delete( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $id = (int) ( $args['coupon_id'] ?? 0 );
    if ( ! $id ) return array( 'ok' => false, 'error' => array( 'code' => 'missing_id', 'message' => 'coupon_id required' ) );
    if ( empty( $args['confirm'] ) ) return array( 'ok' => false, 'error' => array( 'code' => 'confirm_required', 'message' => 'wc.coupons.delete requires confirm=true' ) );
    if ( $is_dry_run ) return array( 'ok' => true, 'dry_run' => true, 'would_do' => "Delete coupon_id $id" );
    $ok = wp_delete_post( $id, true );
    return array( 'ok' => (bool) $ok, 'result' => array( 'coupon_id' => $id, 'deleted' => (bool) $ok ) );
}

// ---- REPORTS ----

function sarudo_cmd_wc_reports_sales( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $days = max( 1, (int) ( $args['days'] ?? 30 ) );
    $orders = wc_get_orders( array(
        'date_created' => '>' . ( time() - $days * 86400 ),
        'status'       => array( 'completed', 'processing' ),
        'limit'        => -1,
    ) );
    $total = 0;
    $count = count( $orders );
    foreach ( $orders as $o ) $total += (float) $o->get_total();
    $currency = get_woocommerce_currency();
    return array( 'ok' => true, 'result' => array(
        'days'        => $days,
        'order_count' => $count,
        'gross_total' => $total,
        'currency'    => $currency,
        'avg_order_value' => $count > 0 ? round( $total / $count, 2 ) : 0,
    ) );
}

function sarudo_cmd_wc_reports_top_sellers( $args, $is_dry_run = false ) {
    if ( ! _sarudo_wc_active() ) return _sarudo_wc_error_not_active();
    $days = max( 1, (int) ( $args['days'] ?? 30 ) );
    $orders = wc_get_orders( array(
        'date_created' => '>' . ( time() - $days * 86400 ),
        'status'       => array( 'completed', 'processing' ),
        'limit'        => -1,
    ) );
    $counts = array();
    foreach ( $orders as $o ) {
        foreach ( $o->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( ! isset( $counts[ $pid ] ) ) $counts[ $pid ] = array( 'product_id' => $pid, 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0 );
            $counts[ $pid ]['qty'] += $item->get_quantity();
            $counts[ $pid ]['revenue'] += (float) $item->get_total();
        }
    }
    usort( $counts, fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
    return array( 'ok' => true, 'result' => array(
        'days'        => $days,
        'top_sellers' => array_slice( array_values( $counts ), 0, 10 ),
    ) );
}
