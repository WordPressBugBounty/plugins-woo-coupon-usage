<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class wcusage_Referrals_Table extends WP_List_Table {
    public $orders = [];

    function __construct() {
        parent::__construct( array(
            'singular' => 'Referral',
            'plural'   => 'Referrals',
            'ajax'     => false,
        ) );
    }

    function get_columns() {
        return array(
            'cb'         => '<input type="checkbox" />',
            'order_id'   => 'ID',
            'name'       => esc_html__( 'Customer', 'woo-coupon-usage' ),
            'status'     => esc_html__( 'Order Status', 'woo-coupon-usage' ),
            'total'      => esc_html__( 'Order Total', 'woo-coupon-usage' ),
            'date'       => esc_html__( 'Order Date', 'woo-coupon-usage' ),
            'coupon'     => esc_html__( 'Coupon Code', 'woo-coupon-usage' ),
            'affiliate'  => esc_html__( 'Affiliate User', 'woo-coupon-usage' ),
            'commission' => esc_html__( 'Affiliate Commission', 'woo-coupon-usage' ),
        );
    }

    function prepare_items() {
        if ( isset( $_GET['bulk-delete'] ) ) {
            // Validate nonce first (CSRF)
            if ( !isset( $_GET['_wpnonce'] ) || !wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'bulk-referrals' ) ) {
                wp_die( esc_html__( 'Security check failed. Please try again.', 'woo-coupon-usage' ) );
            }
            if ( !wcusage_check_admin_access() ) {
                wp_die( esc_html__( 'You do not have sufficient permissions.', 'woo-coupon-usage' ) );
            }
            if ( 'trash' === $this->current_action() ) {
                // Loop over the array of record IDs and trash them
                foreach ( $_GET['bulk-delete'] as $id ) {
                    // Delete WooCommerce Orders
                    wp_trash_post( $id );
                }
            }
            if ( 'processing' === $this->current_action() ) {
                // Loop over the array of record IDs and update their status
                foreach ( $_GET['bulk-delete'] as $id ) {
                    $order = wc_get_order( $id );
                    if ( $order && $order instanceof WC_Order ) {
                        $order->update_status( 'processing' );
                    }
                }
            }
            if ( 'completed' === $this->current_action() ) {
                // Loop over the array of record IDs and update their status
                foreach ( $_GET['bulk-delete'] as $id ) {
                    $order = wc_get_order( $id );
                    if ( $order && $order instanceof WC_Order ) {
                        $order->update_status( 'completed' );
                    }
                }
            }
            if ( 'on-hold' === $this->current_action() ) {
                // Loop over the array of record IDs and update their status
                foreach ( $_GET['bulk-delete'] as $id ) {
                    $order = wc_get_order( $id );
                    if ( $order && $order instanceof WC_Order ) {
                        $order->update_status( 'on-hold' );
                    }
                }
            }
            if ( 'cancelled' === $this->current_action() ) {
                // Loop over the array of record IDs and update their status
                foreach ( $_GET['bulk-delete'] as $id ) {
                    $order = wc_get_order( $id );
                    if ( $order && $order instanceof WC_Order ) {
                        $order->update_status( 'cancelled' );
                    }
                }
            }
        }
        // Success message status change
        if ( 'trash' === $this->current_action() || 'processing' === $this->current_action() || 'completed' === $this->current_action() || 'on-hold' === $this->current_action() || 'cancelled' === $this->current_action() ) {
            $count = ( isset( $_GET['bulk-delete'] ) ? count( (array) $_GET['bulk-delete'] ) : 0 );
            if ( $count > 0 ) {
                echo '<div class="notice notice-success is-dismissible" style="margin-top: 25px;"><p>' . esc_html( $count ) . ' orders updated.</p></div>';
            }
        }
        // Now prepare the items for the table
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array(
            $columns,
            $hidden,
            $sortable,
            $this->get_default_primary_column_name()
        );
        $per_page = apply_filters( 'wcusage_admin_referrals_per_page', 20 );
        $current_page = $this->get_pagenum();
        // Fetch filtered orders and count total
        $result = get_wcusage_admin_table_orders( $current_page, $per_page );
        $this->orders = ( isset( $result['orders'] ) ? $result['orders'] : array() );
        $total_count = ( isset( $result['total'] ) ? intval( $result['total'] ) : 0 );
        // Set up table columns and headers
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array(
            $columns,
            $hidden,
            $sortable,
            $this->get_default_primary_column_name()
        );
        // Set pagination args based on filtered results
        $this->set_pagination_args( array(
            'total_items' => $total_count,
            'per_page'    => $per_page,
            'total_pages' => max( 1, ceil( $total_count / max( 1, $per_page ) ) ),
        ) );
        // Set items for the table
        $this->items = $this->orders;
    }

    function get_bulk_actions() {
        $actions = array(
            'trash'      => 'Move to Trash',
            'processing' => esc_html__( 'Change status to processing', 'woo-coupon-usage' ),
            'on-hold'    => esc_html__( 'Change status to on-hold', 'woo-coupon-usage' ),
            'completed'  => esc_html__( 'Change status to completed', 'woo-coupon-usage' ),
            'cancelled'  => esc_html__( 'Change status to cancelled', 'woo-coupon-usage' ),
        );
        return $actions;
    }

    /**
     * Add filters to the tablenav. Align to the right of bulk actions.
     */
    function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }
        // Current values
        $current_aff_user = ( isset( $_GET['affiliate_user'] ) ? sanitize_text_field( wp_unslash( $_GET['affiliate_user'] ) ) : '' );
        $current_coupon = ( isset( $_GET['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon_code'] ) ) : '' );
        $current_group = ( isset( $_GET['affiliate_group'] ) ? sanitize_text_field( wp_unslash( $_GET['affiliate_group'] ) ) : '' );
        $current_status = ( isset( $_GET['order_status'] ) ? sanitize_text_field( wp_unslash( $_GET['order_status'] ) ) : '' );
        $current_from = ( isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '' );
        $current_to = ( isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '' );
        $statuses = wc_get_order_statuses();
        // "Only assigned affiliates" toggle. Rendered here as a fallback, then relocated by JS to sit
        // right beside the bulk actions "Apply" button. Saved per-user, disabled by default.
        $only_assigned = wcusage_referrals_only_assigned_enabled();
        echo '<div class="alignleft actions wcusage-only-assigned-actions" style="display:flex; align-items:center;">';
        // Hidden marker so we can tell the filter form was submitted (persist the checkbox per-user).
        echo '<input type="hidden" name="wcu_ref_applied" value="1" />';
        echo '<label class="wcusage-only-assigned-filter' . (( $only_assigned ? ' is-active' : '' )) . '" title="' . esc_attr__( 'Only show orders where the coupon has an assigned affiliate user.', 'woo-coupon-usage' ) . '">' . '<input type="checkbox" id="wcu_only_assigned" name="wcu_only_assigned" value="1"' . checked( $only_assigned, 1, false ) . ' />' . '<span class="wcu-switch" aria-hidden="true"></span>' . '<span class="wcu-label-text">' . esc_html__( 'Only show orders with an affiliate user', 'woo-coupon-usage' ) . '</span>' . '</label>';
        echo '</div>';
        // Place filters on the left actions row so the pagination/navigation stays furthest right
        echo '<div class="alignleft actions wcusage-admin-title-filters" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">';
        // Affiliate user (label removed, placeholder used; preserve current value)
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<input type="text" class="wcu-autocomplete-user" name="affiliate_user" data-label="username" value="' . esc_attr( $current_aff_user ) . '" placeholder="' . esc_attr__( 'Username...', 'woo-coupon-usage' ) . '" style="min-width:140px;" />' . '</div>';
        // Group / Role
        $roles = get_editable_roles();
        // Move roles with key starting with coupon_affiliate (groups) to top
        $roles = array_merge( array_filter( $roles, function ( $details, $role_key ) {
            return strpos( $role_key, 'coupon_affiliate' ) === 0;
        }, ARRAY_FILTER_USE_BOTH ), array_filter( $roles, function ( $details, $role_key ) {
            return strpos( $role_key, 'coupon_affiliate' ) !== 0;
        }, ARRAY_FILTER_USE_BOTH ) );
        // Prefix group names with (Group)
        foreach ( $roles as $role_key => $details ) {
            if ( strpos( $role_key, 'coupon_affiliate' ) === 0 ) {
                $roles[$role_key]['name'] = '(Group) ' . $details['name'];
            }
        }
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<select name="affiliate_group" style="min-width:160px;">' . '<option value="">' . esc_html__( 'All Groups & Roles', 'woo-coupon-usage' ) . '</option>';
        foreach ( $roles as $role_key => $details ) {
            echo '<option value="' . esc_attr( $role_key ) . '"' . selected( $current_group, $role_key, false ) . '>' . esc_html( $details['name'] ) . '</option>';
        }
        echo '</select></div>';
        // Coupon
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<input type="text" name="coupon_code" value="' . esc_attr( $current_coupon ) . '" placeholder="' . esc_attr__( 'Coupon...', 'woo-coupon-usage' ) . '" style="min-width:110px;" />' . '</div>';
        // Status
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<select name="order_status">' . '<option value="">' . esc_html__( 'Any Status', 'woo-coupon-usage' ) . '</option>';
        foreach ( $statuses as $key => $label ) {
            $val = str_replace( 'wc-', '', $key );
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $current_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
        // Dates (add visible labels Start/End)
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<span>' . esc_html__( 'Start', 'woo-coupon-usage' ) . ':</span>' . '<input type="date" name="date_from" value="' . esc_attr( $current_from ) . '" aria-label="' . esc_attr__( 'Start', 'woo-coupon-usage' ) . '" placeholder="' . esc_attr__( 'Start', 'woo-coupon-usage' ) . '" />' . '</div>';
        echo '<div style="display:flex; align-items:center; gap:4px;">' . '<span>' . esc_html__( 'End', 'woo-coupon-usage' ) . ':</span>' . '<input type="date" name="date_to" value="' . esc_attr( $current_to ) . '" aria-label="' . esc_attr__( 'End', 'woo-coupon-usage' ) . '" placeholder="' . esc_attr__( 'End', 'woo-coupon-usage' ) . '" />' . '</div>';
        // Buttons
        echo '<button class="button" type="submit">' . esc_html__( 'Filter', 'woo-coupon-usage' ) . '</button>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_referrals' ) ) . '">' . esc_html__( 'Reset', 'woo-coupon-usage' ) . '</a>';
        echo '</div>';
    }

    function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="bulk-delete[]" value="%s" />', $item['order_id'] );
    }

    function column_default( $item, $column_name ) {
        $order_id = $item['order_id'];
        $order = wc_get_order( $order_id );
        $lifetimeaffiliate = wcusage_order_meta( $order_id, 'lifetime_affiliate_coupon_referrer' );
        $affiliatereferrer = wcusage_order_meta( $order_id, 'wcusage_referrer_coupon' );
        $coupons = '';
        $affiliate_ids = '';
        if ( $lifetimeaffiliate ) {
            $getinfo = wcusage_get_the_order_coupon_info( $lifetimeaffiliate, "", $order_id );
            $getcoupon = wcusage_get_coupon_info( $lifetimeaffiliate );
            $url = $getinfo['uniqueurl'];
            $url = sanitize_text_field( $url );
            $typeicon = "<span title='Lifetime Commission' style='font-size: 12px;'><i class='fa-solid fa-star'></i></span> ";
            $coupons .= '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $lifetimeaffiliate ) . '</a><br/>';
            if ( isset( $getcoupon[1] ) ) {
                $affiliate_id = $getcoupon[1];
                $affiliate_username = get_userdata( $affiliate_id )->user_login;
                $affiliate_ids .= '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . $affiliate_id ) ) . '">' . esc_html( $affiliate_username ) . '</a><br/>';
            }
        } elseif ( $affiliatereferrer ) {
            $getinfo = wcusage_get_the_order_coupon_info( $affiliatereferrer, "", $order_id );
            $getcoupon = wcusage_get_coupon_info( $affiliatereferrer );
            $url = $getinfo['uniqueurl'];
            $typeicon = "<span title='Custom / URL Referral' style='font-size: 12px;'><i class='fa-solid fa-link'></i></span> ";
            $coupons .= '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $affiliatereferrer ) . '</a><br/>';
            if ( isset( $getcoupon[1] ) ) {
                $affiliate_id = $getcoupon[1];
                if ( $affiliate_id ) {
                    $affiliate_username = get_userdata( $affiliate_id )->user_login;
                    $affiliate_ids .= '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . $affiliate_id ) ) . '">' . esc_html( $affiliate_username ) . '</a><br/>';
                }
            }
        } elseif ( !$lifetimeaffiliate && !$affiliatereferrer && class_exists( 'WooCommerce' ) ) {
            if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
                foreach ( $order->get_coupon_codes() as $coupon_code ) {
                    $getinfo = wcusage_get_the_order_coupon_info( $coupon_code, "", $order_id );
                    $getcoupon = wcusage_get_coupon_info( $coupon_code );
                    $url = sanitize_text_field( $getinfo['uniqueurl'] );
                    $coupons .= '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $coupon_code ) . '</a><br/>';
                    if ( isset( $getcoupon[1] ) && $getcoupon[1] != '' ) {
                        $affiliate_id = $getcoupon[1];
                        $affiliate_username = get_userdata( $affiliate_id )->user_login;
                        $affiliate_ids .= '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . $affiliate_id ) ) . '">' . esc_html( $affiliate_username ) . '</a><br/>';
                    } else {
                        $affiliate_ids .= '-<br/>';
                    }
                }
            }
        }
        switch ( $column_name ) {
            case 'order_id':
                return '<a href="' . esc_url( admin_url( 'post.php?post=' . $item[$column_name] . '&action=edit' ) ) . '"><span class="dashicons dashicons-edit" style="font-size: 15px; margin-top: 4px;"></span> #' . $item[$column_name] . '</a>';
            case 'status':
                $item[$column_name] = ucfirst( $item[$column_name] );
                $statusname = strtolower( $item[$column_name] );
                $status = '<mark class="order-status status-' . $statusname . ' tips"><span>' . $item[$column_name] . '</span></mark>';
                return $status;
            case 'name':
                $order_id = $item['order_id'];
                $order = wc_get_order( $order_id );
                $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                return $name;
            case 'total':
                $order_id = $item['order_id'];
                $order = wc_get_order( $order_id );
                $order_total = $order->get_total();
                $order_total = wcusage_convert_order_value_to_currency( $order, $order_total );
                $order_total = wc_price( $order_total );
                // Check order refunded total
                $order_refunded_total = $order->get_total_refunded();
                if ( $order_refunded_total > 0 ) {
                    $order_total = '<del aria-hidden="true">' . wc_price( $order_refunded_total ) . '</del> ';
                    $order_total .= wc_price( $order->get_total() - $order_refunded_total );
                }
                return $order_total;
            case 'date':
                return date( 'M j, Y (g:ia)', strtotime( $item[$column_name] ) );
            case 'coupon':
                return $coupons;
            case 'commission':
                $order_id = $item['order_id'];
                $order = wc_get_order( $order_id );
                // The full commission (percentage + any fixed per order/product amounts),
                // and 0.00 once an order is cancelled/refunded/failed - matching the
                // affiliate dashboard and the admin reports.
                $total_commission = wcusage_get_order_saved_commission( $order_id );
                $ispaid = wcusage_order_ispaid( $order_id );
                $wcu_select_coupon_user = wcusage_order_meta( $order_id, 'wcusage_affiliate_user' );
                if ( $wcu_select_coupon_user ) {
                    $total_commission = wcusage_convert_order_value_to_currency( $order, $total_commission );
                    $total_commission = wcusage_format_price( $total_commission );
                    return $total_commission . $ispaid;
                } else {
                    return "";
                }
            case 'affiliate':
                return $affiliate_ids;
            default:
                return $item[$column_name];
        }
    }

}

/*
* Show referral orders page
*/
function wcusage_orders_page() {
    wp_enqueue_style(
        'woocommerce_admin_styles',
        WC()->plugin_url() . '/assets/css/admin.css',
        array(),
        WC_VERSION
    );
    // For username autocomplete in filters
    wp_enqueue_script( 'jquery-ui-autocomplete' );
    // Enqueue external script for this page (moved inline JS)
    $wcusage_referrals_js_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'js/admin-referrals.js';
    $wcusage_referrals_js_ver = ( file_exists( $wcusage_referrals_js_path ) ? filemtime( $wcusage_referrals_js_path ) : WCUSAGE_VERSION );
    wp_enqueue_script(
        'wcusage-admin-referrals',
        WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-referrals.js',
        array('jquery', 'jquery-ui-autocomplete'),
        $wcusage_referrals_js_ver,
        true
    );
    wp_localize_script( 'wcusage-admin-referrals', 'wcusage_referrals_vars', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wcusage_coupon_nonce' ),
        'texts'    => array(
            'please_select_at_least_one_order' => esc_html__( 'Please select at least one order.', 'woo-coupon-usage' ),
            'update_unpaid_confirm_header'     => esc_html__( 'Update unpaid commission for the selected orders?', 'woo-coupon-usage' ),
            'update_unpaid_confirm_line'       => esc_html__( 'This will grant unpaid commission to affiliates, for COMPLETED orders, that have not already granted any unpaid commission.', 'woo-coupon-usage' ),
            'trash_confirm'                    => esc_html__( 'Are you sure you want to move the selected WooCommerce orders to the trash?', 'woo-coupon-usage' ),
            'status_confirm_template'          => esc_html__( 'Are you sure you want to apply "%s" to the selected orders?', 'woo-coupon-usage' ),
            'status_confirm_line'              => esc_html__( 'Changing order statuses may affect the affiliate commissions for these orders.', 'woo-coupon-usage' ),
            'selected_orders'                  => esc_html__( 'Selected orders:', 'woo-coupon-usage' ),
        ),
    ) );
    $table = new wcusage_Referrals_Table();
    ?>
    <style>
    @media screen and (min-width: 1200px) {
        .check-column {
            padding-top: 15px !important;
            text-align: left !important;
        }
        .column-cb {
            padding-top: 5px !important;
            width: 32px !important;
        }
        .column-order_id {
            width: 100px !important;
        }
        .column-name {
            width: 200px !important;
        }
        .column-status {
            width: 150px !important;
        }
    }
    .checkbox-disabled {
        opacity: 0.5;
        pointer-events: none;
    }
    /* "Only assigned affiliates" toggle (sits beside the bulk actions Apply button) */
    .wcusage-only-assigned-filter {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: 8px;
        padding: 0 12px;
        height: 30px;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        background: #fff;
        color: #2c3338;
        font-size: 13px;
        line-height: 1;
        vertical-align: middle;
        white-space: nowrap;
        cursor: pointer;
        user-select: none;
        transition: border-color .15s ease, box-shadow .15s ease, color .15s ease;
    }
    .wcusage-only-assigned-filter:hover { border-color: #8c8f94; }
    .wcusage-only-assigned-filter:focus-within {
        border-color: #07bbe3;
        box-shadow: 0 0 0 1px #07bbe3;
        outline: 2px solid transparent;
    }
    .wcusage-only-assigned-filter input[type="checkbox"] {
        position: absolute;
        width: 1px; height: 1px;
        margin: 0;
        opacity: 0;
        pointer-events: none;
    }
    .wcusage-only-assigned-filter .wcu-switch {
        position: relative;
        flex: 0 0 auto;
        width: 34px;
        height: 18px;
        border-radius: 999px;
        background: #c3c4c7;
        transition: background-color .15s ease;
    }
    .wcusage-only-assigned-filter .wcu-switch::after {
        content: "";
        position: absolute;
        top: 2px; left: 2px;
        width: 14px; height: 14px;
        border-radius: 50%;
        background: #fff;
        box-shadow: 0 1px 2px rgba(0,0,0,0.25);
        transition: transform .15s ease;
    }
    .wcusage-only-assigned-filter .wcu-label-text { font-weight: 500; }
    .wcusage-only-assigned-filter.is-active { border-color: #07bbe3; color: #0a7ea3; }
    .wcusage-only-assigned-filter.is-active .wcu-switch { background: #07bbe3; }
    .wcusage-only-assigned-filter.is-active .wcu-switch::after { transform: translateX(16px); }
    </style>
    <link rel="stylesheet" href="<?php 
    echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL ) . 'fonts/font-awesome/css/all.min.css';
    ?>" crossorigin="anonymous">
    <div class="wrap wcusage-admin-page">
    <?php 
    do_action( 'wcusage_hook_dashboard_page_header', '' );
    ?>
    <h1 class="wcusage-admin-title" style="margin-bottom: -15px;">
    <?php 
    echo sprintf( esc_html__( '%s Orders', 'woo-coupon-usage' ), esc_html( wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) ) ) );
    ?>
    <span class="wcusage-admin-title-buttons">
        <a href="<?php 
    echo esc_url( 'post-new.php?post_type=shop_order' );
    ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link"><?php 
    echo esc_html__( 'Add New Order', 'woo-coupon-usage' );
    ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
        <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=wcusage-bulk-assign-coupons' ) );
    ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link"><?php 
    echo sprintf( esc_html__( 'Assign Orders to %s', 'woo-coupon-usage' ), esc_html( wcusage_get_affiliate_text( __( 'Affiliates', 'woo-coupon-usage' ), true ) ) );
    ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
        <?php 
    ?>
            <span class="wcusage-settings-button" id="wcu-admin-export-csv" style="float: right; opacity: 0.6; cursor: not-allowed;" title="<?php 
    echo esc_attr__( 'Available in PRO', 'woo-coupon-usage' );
    ?>">
                <?php 
    echo esc_html__( 'Export Orders (PRO)', 'woo-coupon-usage' );
    ?> <span class="fa-solid fa-download"></span>
            </span>
        <?php 
    ?>
    </span>
    </h1>
    <br/>
    <?php 
    echo '<form id="referrals-table" method="GET">';
    echo '<input type="hidden" name="page" value="' . (( isset( $_REQUEST['page'] ) ? esc_html( $_REQUEST['page'] ) : '' )) . '" />';
    wp_nonce_field( 'bulk-referrals', '_wpnonce', false );
    $table->prepare_items();
    $table->display();
    echo '</form>';
    ?>
    
    
    
    </div>
    <?php 
}

/**
 * Determine whether the "only assigned affiliate" referrals filter is enabled for the current user.
 *
 * The choice is saved per-user: when the filter form is submitted the current checkbox state is
 * stored in user meta; on a normal page load the saved preference is used. Disabled by default.
 *
 * @return int 1 if enabled, 0 otherwise.
 */
function wcusage_referrals_only_assigned_enabled() {
    static $enabled = null;
    if ( null !== $enabled ) {
        return $enabled;
    }
    $user_id = get_current_user_id();
    if ( isset( $_GET['wcu_ref_applied'] ) ) {
        // The filter form was submitted: checkbox present = on, absent = off.
        $enabled = ( isset( $_GET['wcu_only_assigned'] ) && $_GET['wcu_only_assigned'] ? 1 : 0 );
        if ( $user_id ) {
            update_user_meta( $user_id, 'wcusage_referrals_only_assigned', $enabled );
        }
    } else {
        // Normal page load: use the saved preference (disabled by default).
        $enabled = ( $user_id ? (int) get_user_meta( $user_id, 'wcusage_referrals_only_assigned', true ) : 0 );
    }
    return $enabled;
}

/**
 * Get the IDs of all orders that have an assigned affiliate user.
 *
 * This mirrors the "Affiliate User" column logic (lifetime referrer, then custom/URL referrer,
 * then the order's coupon codes). An order counts as "assigned" when the relevant coupon has a
 * user set in its `wcu_select_coupon_user` meta. Data is gathered in bulk (no per-order loads).
 *
 * @return array Order IDs (ints) that display an assigned affiliate user.
 */
function wcusage_get_assigned_affiliate_order_ids() {
    global $wpdb;
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }
    $hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $meta_table = ( $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta );
    $id_col = ( $hpos ? 'order_id' : 'post_id' );
    $items_table = $wpdb->prefix . 'woocommerce_order_items';
    // Bulk: lifetime + custom/URL referrer coupon per order.
    $lifetime = array();
    $referrer = array();
    $meta_rows = $wpdb->get_results( "SELECT {$id_col} AS oid, meta_key, meta_value FROM {$meta_table}\r\n         WHERE meta_key IN ('lifetime_affiliate_coupon_referrer','wcusage_referrer_coupon') AND meta_value <> ''" );
    foreach ( $meta_rows as $row ) {
        if ( 'lifetime_affiliate_coupon_referrer' === $row->meta_key ) {
            $lifetime[(int) $row->oid] = $row->meta_value;
        } else {
            $referrer[(int) $row->oid] = $row->meta_value;
        }
    }
    // Bulk: coupon codes used on each order.
    $order_codes = array();
    $item_rows = $wpdb->get_results( "SELECT order_id AS oid, order_item_name AS code FROM {$items_table} WHERE order_item_type = 'coupon'" );
    foreach ( $item_rows as $row ) {
        $order_codes[(int) $row->oid][] = $row->code;
    }
    // A coupon "has an affiliate" when its wcu_select_coupon_user is set (cached per code).
    $has_affiliate = function ( $code ) {
        if ( '' === $code || null === $code ) {
            return false;
        }
        $info = wcusage_get_coupon_info( $code );
        return isset( $info[1] ) && '' !== $info[1];
    };
    $candidates = array_unique( array_merge( array_keys( $lifetime ), array_keys( $referrer ), array_keys( $order_codes ) ) );
    $assigned = array();
    foreach ( $candidates as $oid ) {
        $ok = false;
        if ( isset( $lifetime[$oid] ) ) {
            $ok = $has_affiliate( $lifetime[$oid] );
        } elseif ( isset( $referrer[$oid] ) ) {
            $ok = $has_affiliate( $referrer[$oid] );
        } elseif ( isset( $order_codes[$oid] ) ) {
            foreach ( $order_codes[$oid] as $code ) {
                if ( $has_affiliate( $code ) ) {
                    $ok = true;
                    break;
                }
            }
        }
        if ( $ok ) {
            $assigned[] = (int) $oid;
        }
    }
    $cache = $assigned;
    return $cache;
}

/*
* Get all orders table data
*/
function get_wcusage_admin_table_orders(  $current_page = 1, $per_page = 20  ) {
    global $wpdb;
    $orders = array();
    // Read filters
    $only_assigned = wcusage_referrals_only_assigned_enabled();
    $aff_user = ( isset( $_GET['affiliate_user'] ) ? sanitize_text_field( wp_unslash( $_GET['affiliate_user'] ) ) : '' );
    $aff_group = ( isset( $_GET['affiliate_group'] ) ? sanitize_text_field( wp_unslash( $_GET['affiliate_group'] ) ) : '' );
    $coupon = ( isset( $_GET['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon_code'] ) ) : '' );
    $status = ( isset( $_GET['order_status'] ) ? sanitize_text_field( wp_unslash( $_GET['order_status'] ) ) : '' );
    $date_from = ( isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '' );
    $date_to = ( isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '' );
    // Base meta query: ensure affiliate meta exists
    $meta_query = array(array(
        'key'     => 'wcusage_affiliate_user',
        'compare' => 'EXISTS',
    ));
    // Affiliate user filter -> resolve to user ID
    if ( $aff_user !== '' ) {
        $aff_user_id = '';
        $user = get_user_by( 'login', $aff_user );
        if ( !$user && is_numeric( $aff_user ) ) {
            $user = get_user_by( 'id', intval( $aff_user ) );
        }
        if ( $user ) {
            $aff_user_id = $user->ID;
            $meta_query[] = array(
                'key'   => 'wcusage_affiliate_user',
                'value' => strval( $aff_user_id ),
            );
        } else {
            return array(
                'orders' => array(),
                'total'  => 0,
            );
        }
    }
    // Affiliate Group Filter (Optimized)
    if ( $aff_group !== '' ) {
        // Get all users in this group (role)
        $users_in_group = get_users( array(
            'role'   => $aff_group,
            'fields' => 'ID',
        ) );
        if ( !empty( $users_in_group ) ) {
            $meta_query[] = array(
                'key'     => 'wcusage_affiliate_user',
                'value'   => $users_in_group,
                'compare' => 'IN',
            );
        } else {
            return array(
                'orders' => array(),
                'total'  => 0,
            );
        }
    }
    $args = array(
        'type'       => 'shop_order',
        'meta_query' => $meta_query,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'paginate'   => true,
        'limit'      => $per_page,
        'paged'      => $current_page,
    );
    if ( $status !== '' ) {
        $args['status'] = str_replace( 'wc-', '', $status );
    }
    // Date filtering in query
    if ( $date_from || $date_to ) {
        if ( $date_from && $date_to ) {
            $args['date_created'] = $date_from . '...' . $date_to;
        } elseif ( $date_from ) {
            $args['date_created'] = '>=' . $date_from;
        } elseif ( $date_to ) {
            $args['date_created'] = '<=' . $date_to;
        }
    }
    // Coupon Filter (Optimized)
    if ( $coupon !== '' ) {
        $coupon_lc = strtolower( $coupon );
        // 1. Find orders with this coupon in meta (lifetime or referrer)
        $meta_sql = $wpdb->prepare( "\r\n            SELECT post_id FROM {$wpdb->postmeta} \r\n            WHERE (meta_key = 'lifetime_affiliate_coupon_referrer' AND meta_value = %s)\r\n            OR (meta_key = 'wcusage_referrer_coupon' AND meta_value = %s)\r\n        ", $coupon_lc, $coupon_lc );
        $ids_from_meta = $wpdb->get_col( $meta_sql );
        // 2. Find orders with this coupon in order items
        $items_sql = $wpdb->prepare( "\r\n            SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items \r\n            WHERE order_item_type = 'coupon' AND order_item_name = %s\r\n        ", $coupon_lc );
        $ids_from_items = $wpdb->get_col( $items_sql );
        $coupon_order_ids = array_unique( array_merge( $ids_from_meta, $ids_from_items ) );
        if ( empty( $coupon_order_ids ) ) {
            return array(
                'orders' => array(),
                'total'  => 0,
            );
        }
        $args['post__in'] = $coupon_order_ids;
    }
    // Restrict to orders whose coupon currently has an assigned affiliate user. Applied when the
    // "Only assigned affiliates" toggle is on, and always when filtering by a group/role (so those
    // results never include orders whose coupon no longer has an affiliate - the "-" rows).
    if ( $only_assigned || $aff_group !== '' ) {
        $assigned_ids = wcusage_get_assigned_affiliate_order_ids();
        if ( empty( $assigned_ids ) ) {
            return array(
                'orders' => array(),
                'total'  => 0,
            );
        }
        if ( isset( $args['post__in'] ) ) {
            $args['post__in'] = array_values( array_intersect( $args['post__in'], $assigned_ids ) );
            if ( empty( $args['post__in'] ) ) {
                return array(
                    'orders' => array(),
                    'total'  => 0,
                );
            }
        } else {
            $args['post__in'] = $assigned_ids;
        }
    }
    // Execute Query
    $results = wc_get_orders( $args );
    $orders_data = $results->orders;
    $total = $results->total;
    foreach ( $orders_data as $order ) {
        $order_id = $order->get_id();
        $orders[] = array(
            'order_id'   => $order_id,
            'status'     => $order->get_status(),
            'name'       => '',
            'total'      => $order_id,
            'date'       => ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '' ),
            'coupon'     => '',
            'commission' => '',
            'affiliate'  => '',
        );
    }
    return array(
        'orders' => $orders,
        'total'  => $total,
    );
}
