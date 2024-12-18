<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class wcusage_Coupons_Table extends WP_List_Table {

    public $coupons = [];

    function __construct() {
        parent::__construct(array(
            'singular' => 'Affiliate Coupon',
            'plural'   => 'Affiliate Coupons',
            'ajax'     => false
        ));
    }

    function get_columns() {
        
        $columns['ID'] = esc_html__('ID', 'woo-coupon-usage');

        $columns['post_title'] = esc_html__('Coupon Code', 'woo-coupon-usage');

        $columns['coupon_type'] = esc_html__('Coupon Type', 'woo-coupon-usage');

        $columns['usage'] = esc_html__('Total Usage', 'woo-coupon-usage');

        $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
        if($all_stats) {

            $columns['sales'] = esc_html__('Total Sales', 'woo-coupon-usage');

            $columns['commission'] = esc_html__('Total Commission', 'woo-coupon-usage');

        }

        if( wcu_fs()->can_use_premium_code() ) {
            $columns['unpaid_commission'] = esc_html__('Unpaid Commission', 'woo-coupon-usage');
        }

        $columns['affiliate'] = esc_html__('Affiliate User', 'woo-coupon-usage');

        $columns['dashboard_link'] = esc_html__('Dashboard Link', 'woo-coupon-usage')
        . wcusage_admin_tooltip(esc_html__('This link will take you to the affiliate dashboard for this specific coupon. This allows admins to easily view stats for all your affiliates coupons.', 'woo-coupon-usage'));

        $wcusage_field_urls_enable = wcusage_get_setting_value('wcusage_field_urls_enable', 1);
        if($wcusage_field_urls_enable) {
            $columns['referral_link'] = esc_html__('Referral Link', 'woo-coupon-usage')
            . wcusage_admin_tooltip(esc_html__('This is the default referral link your affiliates can share. If clicked it will track statistics, and auto-apply the coupon to the customers cart.', 'woo-coupon-usage')
            . "<br><br>"
            . esc_html__('An advanced link generator is also available for your affiliates to use on the affiliate dashboard.', 'woo-coupon-usage'));
        }

        $columns['the-actions'] = esc_html__('Actions', 'woo-coupon-usage');

        return $columns;

    }

    function prepare_items() {

        $columns = $this->get_columns();
        $this->_column_headers = array($columns, array(), array());
    
        $search = (isset($_GET['s'])) ? $_GET['s'] : false;
        $search = sanitize_text_field($search);

        // Check if "Show Affiliate Coupons Only" toggle is enabled
        $affiliate_only = isset($_GET['affiliate_only']) && $_GET['affiliate_only'] === 'true';

        // Fetch coupons data based on the toggle
        if ($affiliate_only) {
            $this->coupons = $this->get_affiliate_coupons($search);
        } else {
            $this->coupons = $this->get_all_coupons($search);
        }

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $this->coupons ? count($this->coupons) : 0;

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ));

        // Only filter the items if there are coupons
        if ($total_items > 0) {
            $this->items = array_slice($this->coupons, (($current_page - 1) * $per_page), $per_page);
        }
    }

    function column_default($item, $column_name) {

        if (!is_object($item) || !property_exists($item, 'ID')) { return ""; }

        $coupon = $item->ID;
        if(!$coupon) { return ""; }

        $coupon_code = $item->post_title;
        if(!$coupon_code || empty($coupon_code)) { return ""; }

        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        if (!$coupon_id) {
            return "";
        }
        
        $disable_commission = wcusage_coupon_disable_commission($coupon);

        global $woocommerce;

        $c = new WC_Coupon($coupon_code);
        if(!$c) { return ""; }

        $qmessage = esc_html__('The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage');
        $coupon_info = wcusage_get_coupon_info_by_id($item->ID);
        $coupon_user_id = $coupon_info[1];
        $user_info = get_userdata($coupon_user_id);
        $wcusage_urls_prefix = wcusage_get_setting_value('wcusage_field_urls_prefix', 'coupon');

        $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);

        $usage = 0;
        if($wcu_alltime_stats && isset($wcu_alltime_stats['total_count'])) {
            $usage = $wcu_alltime_stats['total_count'];
        }
        if(!$usage) {
            if(method_exists($c, 'get_usage_count')) {
                $usage = $c->get_usage_count();
            }
        }

        switch ($column_name) {
            case 'ID':
                $coupon_id = $item->ID;
                return '<a href="' . esc_url(admin_url('post.php?post=' . $coupon_id . '&action=edit')) . '"><span class="dashicons dashicons-edit" style="font-size: 15px; margin-top: 4px;"></span> ' . esc_html($coupon_id) . '</a>';                
            case 'post_title':
                $coupon_id = $item->ID;
                return '<a href="' . esc_url(admin_url('post.php?post=' . $coupon_id . '&action=edit')) . '">' . esc_html($coupon_code) . '</a>';
            case 'coupon_type':
                $coupon_type = get_post_meta($item->ID, 'discount_type', true);
                if(!$coupon_type) {
                    $coupon_type = $c->get_discount_type();

                }
                $coupon_amount = get_post_meta($item->ID, 'coupon_amount', true);
                if(!$coupon_amount) {
                    $coupon_amount = $c->get_amount();
                }
                if($coupon_type == 'percent') {
                    if($coupon_amount) {
                        return esc_html__('Percentage Discount', 'woo-coupon-usage') . ' (' . esc_html($coupon_amount) . '%)';
                    } else {
                        return esc_html__('Percentage Discount', 'woo-coupon-usage');
                    }
                } elseif($coupon_type == 'fixed_cart') {
                    if($coupon_amount) {
                        return esc_html__('Fixed Cart Discount', 'woo-coupon-usage') . ' (' . wc_price(esc_html($coupon_amount)) . ')';
                    } else {
                        return esc_html__('Fixed Cart Discount', 'woo-coupon-usage');
                    }
                } elseif($coupon_type == 'fixed_product') {
                    if($coupon_amount) {
                        return esc_html__('Fixed Product Discount', 'woo-coupon-usage') . ' (' . wc_price(esc_html($coupon_amount)) . ')';
                    } else {
                        return esc_html__('Fixed Product Discount', 'woo-coupon-usage');
                    }
                } elseif($coupon_type == 'percent_product') {
                    if($coupon_amount) {
                        return esc_html__('Percentage Product Discount', 'woo-coupon-usage') . ' (' . esc_html($coupon_amount) . '%)';
                    } else {
                        return esc_html__('Percentage Product Discount', 'woo-coupon-usage');
                    }
                }
                if($coupon_amount) {
                    return $coupon_type . ' (' . esc_html($coupon_amount) . ')';
                } else {
                    return $coupon_type;
                }
            case 'usage':
                $theoutput = "";
                return $usage;
            case 'sales':
                $theoutput = "";
                $sales = 0;
                $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
                if($all_stats && $wcu_alltime_stats) {
                    if(isset($wcu_alltime_stats['total_orders'])) {
                        $sales = $wcu_alltime_stats['total_orders'];
                    }
                    if(isset($wcu_alltime_stats['total_discount'])) {
                        $discounts = $wcu_alltime_stats['total_discount'];
                        $sales = (float)$sales - (float)$discounts;
                    }
                }
                if($usage > 0 && !$sales) {
                    return "<span title='".esc_html($qmessage)."'><strong><i class='fa-solid fa-ellipsis'></i></strong></span></a>";
                }
                return wcusage_format_price($sales);
            case 'commission':
                $disable_non_affiliate = wcusage_get_setting_value('wcusage_field_commission_disable_non_affiliate', '0');
                if($disable_commission && $disable_non_affiliate) {
                    return '-';
                }
                $theoutput = "";
                $commission = 0;
                $total_commission = 0;
                if($wcu_alltime_stats && isset($wcu_alltime_stats['total_commission'])) {
                    $commission = $wcu_alltime_stats['total_commission'];
                    if($commission) {
                        $total_commission += $wcu_alltime_stats['total_commission'];
                    }
                }
                if($usage > 0 && !$total_commission) {
                    return "<span title='".esc_html($qmessage)."'><strong><i class='fa-solid fa-ellipsis'></i></strong></span></a>";
                }
                return wcusage_format_price($total_commission);
            case 'unpaid_commission':
                if($disable_commission) {
                    return '-';
                }
                $unpaid_commission = get_post_meta($item->ID, 'wcu_text_unpaid_commission', true);
                $unpaid_commission = wcusage_format_price($unpaid_commission);
                return $unpaid_commission;
            case 'Total Sales':
                $total_sales = get_post_meta($item->ID, 'wcu_text_total_sales', true);
                $total_sales = wcusage_format_price($total_sales);
                return $total_sales;
            case 'affiliate':
                $usernametext = '';
                if($user_info) {
                    $username = $user_info->user_login;
                    $userlink = get_edit_user_link($coupon_user_id);
                    $usernametext = '<a href="'.$userlink.'" target="_blank">' . esc_html($username) . '</a>';
                } else {
                    $usernametext = "-";
                }
                return $usernametext;
            case 'dashboard_link':
                $coupon_info = wcusage_get_coupon_info_by_id($item->ID);
                $dashboard_link = $coupon_info[4];
                return '<a href="' . esc_url($dashboard_link) . '" target="_blank">'.esc_html__('View Dashboard', 'woo-coupon-usage').' <span class="dashicons dashicons-external"></span></a>';
            case 'referral_link':
                $home_page = get_home_url();
                $user_info = get_userdata($coupon_user_id);
                $link = $home_page.'?' . $wcusage_urls_prefix . '='.esc_html($coupon_code);
                return '<div class="wcusage-copyable-link">'
                . '<input type="text" id="wcusageLink'.esc_attr($coupon_code).'" class="wcusage-copy-link-text" value="'.esc_url($link).'" title="'.esc_url($link).'"
                style="max-width: 100px;width: 75%;max-height: 24px;min-height: 24px;font-size: 10px;" readonly>'
                . '<button type="button" class="wcusage-copy-link-button" style="max-height: 20px;min-height: 20px;background: none;border: none;"
                title="'.esc_html__( 'Copy Link', 'woo-coupon-usage' ).'"><i class="fa-regular fa-copy"></i></button>'
                . '</div>';
            case 'the-actions':
                // Delete
                $actions = array(
                    'edit' => sprintf('<a href="%s">%s</a>', admin_url('post.php?post=' . $item->ID . '&action=edit'), esc_html__('Edit', 'woo-coupon-usage')),
                    'delete' => sprintf('<a href="%s" style="color: #7a0707;"
                    onclick="return confirm(\"%s\");">%s</a>',
                    wp_nonce_url(admin_url('admin.php?page=wcusage_coupons&delete_coupon=' . $item->ID),
                    'delete_coupon'),
                    esc_html__('Are you sure you want to delete this coupon?', 'woo-coupon-usage'),
                    esc_html__('Delete', 'woo-coupon-usage'))
                );
                foreach ($actions as $key => $action) {
                    $actions[$key] = '<span class="' . esc_attr($key) . '">' . wp_kses_post($action) . '</span>';
                }
                return implode(' | ', $actions);
            default:
                return $item->$column_name; // Use object property directly
        }

    }
    
    function get_affiliate_coupons($search = '') {
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
            's' => $search,
            'meta_query'     => array(
                array(
                    'key'     => 'wcu_select_coupon_user',
                    'value'   => array(''),
                    'compare' => 'NOT IN'
                )
            )
        );

        // check if wcu_select_coupon_user for each is valid user id, if not then remove from 
        $coupons = get_posts($args);
        $valid_coupons = [];
        foreach($coupons as $coupon) {
            $coupon_user_id = get_post_meta($coupon->ID, 'wcu_select_coupon_user', true);
            if($coupon_user_id && get_userdata($coupon_user_id)) {
                $valid_coupons[] = $coupon;
            }
        }
        return $valid_coupons;

    }

    function get_all_coupons($search = '') {
        // Fetch and return all coupons
        $args = array(
            'post_type'      => 'shop_coupon',
            's' => $search,             
            'posts_per_page' => -1,
        );
        
        $coupons_query = new WP_Query($args);
        return $coupons_query->posts;
    }
            
}

/*
* Show coupon usage page
*/
function wcusage_coupons_page() {

    // Post Submit Add Registration Form
    if(isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        if( wp_verify_nonce( $nonce, 'admin_add_registration_form' ) && wcusage_check_admin_access() ) {
            echo wp_kses_post(wcusage_post_submit_application(1));
        }
    }

    // Delete Coupon
    if(isset($_GET['delete_coupon'])) {
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        if( wp_verify_nonce( $nonce, 'delete_coupon' ) && wcusage_check_admin_access() ) {
            $coupon_id = sanitize_text_field($_GET['delete_coupon']);
            $coupon = get_post($coupon_id);
            if($coupon) {
                $coupon_name = $coupon->post_title;
                wp_delete_post($coupon_id);
                $message = esc_html__('Coupon "'.$coupon_name.'" deleted successfully.', 'woo-coupon-usage');
                echo '<p class="notice notice-success is-dismissible"
                style="font-weight: bold; color: green;">' . esc_html($message) . '</p>';
            }
        }
    }

    // Delete Registration
    wp_enqueue_style('woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION);
    $table = new wcusage_Coupons_Table();
    $affiliate_only = isset($_GET['affiliate_only']) && $_GET['affiliate_only'] === 'true';
    // Get the current page URL and remove the 'affiliate_only' parameter from the URL.
    $page_url = admin_url('admin.php?page=wcusage-coupons');
    $page_url_without_affiliate_only = remove_query_arg('affiliate_only', $page_url);
    ?>
    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">
    <?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>
    <div class="wrap">
        <form method="get">
            <h1 class="wcusage-admin-title wcusage-admin-title-coupons">
                <?php echo esc_html__('Coupons', 'woo-coupon-usage'); ?>
                <span class="wcusage-admin-title-buttons">
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=shop_coupon')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add Coupon</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_add_affiliate')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add Affiliate Coupon</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage-bulk-coupon-creator')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Bulk Create Coupons</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage-bulk-edit-coupon')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Bulk Edit Coupons</a>
                </span>
                <br/>
                <span class="wcusage-admin-title-filters">
                    <input type="hidden" name="page" value="<?php echo esc_html($_REQUEST['page']); ?>" />
                    <input type="checkbox" name="affiliate_only" value="true" <?php echo $affiliate_only ? 'checked' : ''; ?> onchange="this.form.submit();">
                    <?php echo esc_html__('Show Affiliate Coupons Only', 'woo-coupon-usage'); ?>
                </span>
            </h1>
            <input type="hidden" name="page" value="<?php echo esc_html($_REQUEST['page']); ?>" />
            <?php
            $table->prepare_items();
            $table->search_box('Search Coupons', 'search_id');
            $table->display();
            ?>
        </form>
    </div>
    <style>
    .wp-list-table .column-ID {
        width: 75px;
    }
    </style>
    <?php
}
