<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class wcusage_Referrals_Table extends WP_List_Table {

    public $orders = [];

    function __construct() {
        parent::__construct(array(
            'singular' => 'Referral',
            'plural'   => 'Referrals',
            'ajax'     => false
        ));
    }

    function get_columns() {
        return array(
            'cb'       => '<input type="checkbox" />',
            'order_id' => 'ID',
            'name'     => esc_html__('Customer', 'woo-coupon-usage'),
            'status'   => esc_html__('Order Status', 'woo-coupon-usage'),
            'total'    => esc_html__('Order Total', 'woo-coupon-usage'),
            'date'     => esc_html__('Order Date', 'woo-coupon-usage'),
            'coupon'   => esc_html__('Coupon Code', 'woo-coupon-usage'),
            'affiliate' => esc_html__('Affiliate User', 'woo-coupon-usage'),
            'commission' => esc_html__('Affiliate Commission', 'woo-coupon-usage'),
        );
    }

    function prepare_items() {
        // Detect when a bulk action is being triggered...
        if ('trash' === $this->current_action()) {
            // Loop over the array of record IDs and trash them
            foreach ($_GET['bulk-delete'] as $id) {
                wp_trash_post($id);
            }
        }
    
        if ('processing' === $this->current_action()) {
            // Loop over the array of record IDs and update their status
            foreach ($_GET['bulk-delete'] as $id) {
                $order = wc_get_order($id);
                if ($order && $order instanceof WC_Order) {
                    $order->update_status('processing');
                }
            }
        }
    
        if ('completed' === $this->current_action()) {
            // Loop over the array of record IDs and update their status
            foreach ($_GET['bulk-delete'] as $id) {
                $order = wc_get_order($id);
                if ($order && $order instanceof WC_Order) {
                    $order->update_status('completed');
                }
            }
        }

        if ('on-hold' === $this->current_action()) {
            // Loop over the array of record IDs and update their status
            foreach ($_GET['bulk-delete'] as $id) {
                $order = wc_get_order($id);
                if ($order && $order instanceof WC_Order) {
                    $order->update_status('on-hold');
                }
            }
        }

        if ('cancelled' === $this->current_action()) {
            // Loop over the array of record IDs and update their status
            foreach ($_GET['bulk-delete'] as $id) {
                $order = wc_get_order($id);
                if ($order && $order instanceof WC_Order) {
                    $order->update_status('cancelled');
                }
            }
        }

        // Success message status change
        if ('trash' === $this->current_action() || 'processing' === $this->current_action() || 'completed' === $this->current_action() || 'on-hold' === $this->current_action() || 'cancelled' === $this->current_action()) {
            $count = count($_GET['bulk-delete']);
            echo '<div class="notice notice-success is-dismissible" style="margin-top: 25px;"><p>'.esc_html($count).' orders updated.</p></div>';
        }
        
        // Now prepare the items for the table
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable, $this->get_default_primary_column_name());

        $per_page = 20;
        $current_page = $this->get_pagenum();
    
        // Fetch orders and count total
        $this->orders = get_wcusage_admin_table_orders($current_page, $per_page);
    
        // Set up table columns and headers
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable, $this->get_default_primary_column_name());
    
        // Set pagination args
        $total_count = 0;
        $order_statuses = array_keys( wc_get_order_statuses() );
        foreach ( $order_statuses as $status ) {
            $status = str_replace('wc-', '', $status);
            $total_count += wc_orders_count( $status );
        }

        $this->set_pagination_args(array(
            'total_items' => $total_count,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_count / $per_page),
        ));
    
        // Set items for the table
        $this->items = $this->orders;
        
    }

    function get_bulk_actions() {
        $actions = array(
            'trash'     => 'Move to Trash',
            'processing'=> esc_html__( 'Change status to processing', 'woocommerce' ),
            'on-hold'  => esc_html__( 'Change status to on-hold', 'woocommerce' ),
            'completed' => esc_html__( 'Change status to completed', 'woocommerce' ),
            'cancelled' => esc_html__( 'Change status to cancelled', 'woocommerce' ),
            // Add more status changes as needed
        );
        return $actions;
    }
    
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="bulk-delete[]" value="%s" />', $item['order_id']
        );    
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'order_id':
                return '<a href="' . esc_url(admin_url('post.php?post=' . $item[$column_name] . '&action=edit')) . '"><span class="dashicons dashicons-edit" style="font-size: 15px; margin-top: 4px;"></span> #' . $item[$column_name] . '</a>';
            case 'status':
                $item[$column_name] = ucfirst($item[$column_name]);
                $statusname = strtolower($item[$column_name]);
                $status = '<mark class="order-status status-' . $statusname . ' tips"><span>' . $item[$column_name] . '</span></mark>';
                return $status;
            case 'name':
                $order_id = $item['order_id'];
                $order = wc_get_order($order_id);
                $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                return $name;
            case 'total':
                $order_id = $item['order_id'];
                $order = wc_get_order($order_id);
                $order_total = $order->get_total();
                $order_total = wcusage_convert_order_value_to_currency($order, $order_total);
                $order_total = wc_price($order_total);
                // Check order refunded total
                $order_refunded_total = $order->get_total_refunded();
                if ($order_refunded_total > 0) {
                    $order_total = '<del aria-hidden="true">' . wc_price($order_refunded_total) . '</del> ';
                    $order_total .= wc_price($order->get_total() - $order_refunded_total);
                }
                return $order_total;
            case 'date':
                return date('M j, Y (g:ia)', strtotime($item[$column_name]));
            case 'coupon':
                $order_id = $item['order_id'];
                $order = wc_get_order($order_id);
                $lifetimeaffiliate = wcusage_order_meta($order_id, 'lifetime_affiliate_coupon_referrer');
                $affiliatereferrer = wcusage_order_meta($order_id, 'wcusage_referrer_coupon');
                $coupons = '';
                if ($lifetimeaffiliate) {
                    $getinfo = wcusage_get_the_order_coupon_info($lifetimeaffiliate, "", $order_id);
                    $url = $getinfo['uniqueurl'];
                    $url = sanitize_text_field( $url );
                    $typeicon = "<span title='Lifetime Commission' style='font-size: 12px;'><i class='fa-solid fa-star'></i></span> ";
                    return $typeicon . '<a href="' . $url . '" target="_blank">' . $lifetimeaffiliate . '</a>';
                } elseif ($affiliatereferrer) {
                    $getinfo = wcusage_get_the_order_coupon_info($affiliatereferrer, "", $order_id);
                    $url = $getinfo['uniqueurl'];
                    $typeicon = "<span title='Custom / URL Referral' style='font-size: 12px;'><i class='fa-solid fa-link'></i></span> ";
                    return $typeicon . '<a href="' . $url . '" target="_blank">' . $affiliatereferrer . '</a>';
                } elseif (!$lifetimeaffiliate && !$affiliatereferrer && class_exists('WooCommerce')) {
                    if (version_compare(WC_VERSION, 3.7, ">=")) {
                        foreach ($order->get_coupon_codes() as $coupon_code) {
                            $getinfo = wcusage_get_the_order_coupon_info($coupon_code, "", $order_id);
                            $url = $getinfo['uniqueurl'];
                            $coupons .= '<a href="' . $url . '" target="_blank">' . $coupon_code . '</a><br/>';
                        }
                    }
                }
                return $coupons;
            case 'commission':
                $order_id = $item['order_id'];
                $order = wc_get_order($order_id);
                $total_commission = wcusage_order_meta($order_id, 'wcusage_total_commission');
                $ispaid = wcusage_order_ispaid($order_id);
                $wcu_select_coupon_user = wcusage_order_meta($order_id, 'wcusage_affiliate_user');
                if($wcu_select_coupon_user) {
                    $total_commission = wcusage_convert_order_value_to_currency($order, $total_commission);
                    $total_commission = wcusage_format_price($total_commission);
                    return $total_commission . $ispaid;
                } else {
                    return "";
                }
            case 'affiliate':
                $order_id = $item['order_id'];
                $order = wc_get_order($order_id);
                $user_id = wcusage_order_meta($order_id, 'wcusage_affiliate_user');
                $user_info = get_userdata($user_id);
                if(!$user_info) {
                    return "";
                }
                $user_login = $user_info->user_login;
                return '<a href="' . esc_url(admin_url('user-edit.php?user_id=' . $user_id)) . '" target="_blank">' . esc_html($user_login) . '</a>';
            default:
                return $item[$column_name];
        }
    }
}

/*
* Show referral orders page
*/
function wcusage_orders_page() {
    wp_enqueue_style('woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION);
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
    </style>
    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">
    <?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>
    <div class="wrap">
    <h2 class="wcusage-admin-title" style="margin-bottom: -15px;">
    <?php echo esc_html__('Affiliate Orders (Referrals)', 'woo-coupon-usage'); ?>
    <span class="wcusage-admin-title-buttons">
        <a href="<?php echo esc_url(('post-new.php?post_type=shop_order')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add New Order</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage-bulk-assign-coupons')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Assign Orders to Affiliates</a>
    </span>
    </h2>
    <br/>
    <?php
    echo '<form id="referrals-table" method="GET">';
    echo '<input type="hidden" name="page" value="' . esc_html($_REQUEST['page']) . '" />';
    $table->prepare_items();
    $table->display();
    echo '</form>';
    ?>
    </div>
    <?php
}

/*
* Get all orders table data
*/
function get_wcusage_admin_table_orders($current_page = 1, $per_page = "-1") {
    $orders = [];

    if($current_page && $per_page) {
        $offset = ($current_page - 1) * $per_page;
    } else {
        $offset = 0;
    }

    // Modify the arguments to limit the orders to the current page where only meta key "wcusage_affiliate_user" exists
    $args = array(
        'offset' => $offset,
        'limit' => $per_page,
        'meta_key' => 'wcusage_affiliate_user',
        'meta_compare' => 'EXISTS',        
    );
    $wc_orders = wc_get_orders($args);

    $affiliate_only = isset($_GET['affiliate_only']) ? $_GET['affiliate_only'] : false;

    foreach ($wc_orders as $order) {

        $orders[] = array(
            'order_id' => $order->get_id(),
            'status'   => $order->get_status(), 'status'   => $order->get_status(),
            'name'     => '',
            'total'    => $order->get_id(),
            'date'     => $order->get_date_created()->date('Y-m-d H:i:s'),
            'coupon'   => '',
            'commission' => '',
            'affiliate' => '',
        );

    }

    return $orders;
}

// Hide .displaying-num on the referral orders page
add_action('admin_head', 'wcusage_hide_displaying_num');
function wcusage_hide_displaying_num() {
    if (isset($_GET['page']) && $_GET['page'] == 'wcusage_referrals') {
        echo '<style>.displaying-num, .tablenav-paging-text, .last-page.button { display: none !important; }</style>';
    }
}