<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class WC_Coupon_Users_Table extends WP_List_Table {
	
	function __construct() {
		global $status, $page;
		parent::__construct( array(
			'singular' => 'affiliateuser',
			'plural'   => 'affiliateusers',
			'ajax'     => false,
		) );
	}
	
	function get_columns() {

        $column['cb'] = '<input type="checkbox" />';
        $column['ID'] = esc_html__('ID', 'woo-coupon-usage');
        $column['Username'] = esc_html__('Username', 'woo-coupon-usage');
        
        $column['roles'] = esc_html__('Group / Role', 'woo-coupon-usage');

        $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
        $wcusage_field_hide_all_time = wcusage_get_setting_value('wcusage_field_hide_all_time', '0');
        if($wcusage_field_hide_all_time) {
            $all_stats = 0;
        }

        if($all_stats) {

            $column['usage'] = esc_html__( 'Total Referrals', 'woo-coupon-usage');

            $column['sales'] = esc_html__( 'Total Sales', 'woo-coupon-usage');

            $column['commission'] = esc_html__( 'Total Commission', 'woo-coupon-usage');

        } else {

            $column['usage'] = esc_html__( 'Total Coupon Usage', 'woo-coupon-usage');

        }

        if( wcu_fs()->can_use_premium_code() ) {
            $column['unpaidcommission'] = 'Unpaid Commission';
        }

        if( wcu_fs()->can_use_premium_code() ) {
            $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
            $system = wcusage_get_setting_value('wcusage_field_storecredit_system', 'default');
            $storecredit_users_col = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_users_col', 1);
            if($credit_enable && $storecredit_users_col && $system == "default") {
                $credit_label = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_only', esc_html__( 'Store Credit', 'woo-coupon-usage'));
                $column['affiliatestorecredit'] = $credit_label;
            }
        }

        $column['affiliateinfo'] = 'Affiliate Coupons';

        if( wcu_fs()->can_use_premium_code() ) {
            $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
            if($wcusage_field_mla_enable) {
                $column['mlacommission'] = 'Total MLA Commission';
                $column['affiliatemla'] = 'MLA Dashboard';
            }
        }

        return $column;

	}

    // Add dropdown for filtering by role
    function extra_tablenav( $which ) {
        if ( $which == "top" ) {
            $roles = get_editable_roles();
            
            $current_role = '';
            if(isset($_POST['filter_role'])) {
                $current_role = sanitize_text_field($_POST['role']);
            } else {
                if(isset($_GET['role'])) {
                    $current_role = $_GET['role'];
                }   
            }

            // Move all roles with "coupon_affiliate" prefix to the top of the list
            $roles = array_merge(
                array_filter($roles, function($role) {
                    return strpos($role, 'coupon_affiliate') === 0;
                }, ARRAY_FILTER_USE_KEY),
                array_filter($roles, function($role) {
                    return strpos($role, 'coupon_affiliate') !== 0;
                }, ARRAY_FILTER_USE_KEY)
            );

            // Add "(Group)" to the start of the name if role key starts with "coupon_affiliate"
            foreach ($roles as $role => $details) {
                if (strpos($role, 'coupon_affiliate') === 0) {
                    $roles[$role]['name'] = '(Group) ' . $details['name'];
                }
            }
            ?>
            <div class="alignleft actions">
                    <?php
                    // Retain other $_GET parameters in the form submission (like the page identifier)
                    foreach ($_GET as $key => $value) {
                        if ($key !== 'role' && $key !== 'filter_role') {
                            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                        }
                    }
                    ?>
                    <select name="role">
                        <option value=""><?php esc_html_e('All Groups & Roles', 'woo-coupon-usage'); ?></option>
                        <?php foreach ($roles as $role => $details) { ?>
                            <option value="<?php echo esc_attr($role); ?>" <?php selected($role, $current_role); ?>><?php echo esc_html($details['name']); ?></option>
                        <?php } ?>
                    </select>
                    <input type="submit" name="filter_role" id="post-query-submit" class="button" value="<?php esc_html_e('Filter', 'woo-coupon-usage'); ?>">
               
            </div>
            <?php
        }
    }
	
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="bulk-delete[]" value="%s" />', $item['ID']
        );    
    }

    function get_bulk_actions() {
        $affiliate_text = wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' ));
        $affiliates_text = wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' ), true);
        
        $actions = [
            'bulk-delete-users' => 'Delete ' . $affiliate_text . ' Users',
            'bulk-delete-all' => 'Delete ' . $affiliate_text . ' Users and Coupons',
            'bulk-unassign' => 'Unassign Coupons from ' . $affiliate_text . ' Users',
            'bulk-delete-coupons' => 'Delete Coupons',
        ];

        return $actions;
    }

	function prepare_items() {

        $this->_column_headers = array($this->get_columns(), array(), array());

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();
		$this->_column_headers = array( $columns, $hidden, $sortable );
		
		$per_page = 25;
		$current_page = $this->get_pagenum();

        $search_query = isset($_POST['s']) ? trim($_POST['s']) : '';
        $search_query = sanitize_text_field($search_query);

        $role = '';
        if(isset($_POST['filter_role'])) {
            $role = sanitize_text_field($_POST['role']);
        } else {
            if(isset($_GET['role'])) {
                $role = $_GET['role'];
            }
        }
        
        $users = $this->get_coupon_users( $search_query, $role );
    
        $total_items = count( $users );
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );
    
        $this->items = array_slice( $users, ( ( $current_page - 1 ) * $per_page ), $per_page );

        $this->process_bulk_action();

	}

    function process_bulk_action() {
        
        // Check nonce for security
        if ( ! isset( $_POST['_wcusage_bulk_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcusage_bulk_nonce'] ) ), 'wcusage_affiliates_bulk_action' ) ) {
            return;
        }

        // Check if the user has permission to perform the action
        if ( ! wcusage_check_admin_access() ) {
            return;
        }

        if ( 'bulk-delete-users' === $this->current_action() ) {
            $delete_ids = esc_sql( $_POST['bulk-delete'] );
            foreach ( $delete_ids as $id ) {
                if ( $id != get_current_user_id() ) {
                    wp_delete_user( $id );
                }
            }
        }

        if ( 'bulk-delete-all' === $this->current_action() ) {
            $delete_ids = esc_sql( $_POST['bulk-delete'] );
            foreach ( $delete_ids as $id ) {
                if ( $id != get_current_user_id() ) {
                    wp_delete_user( $id );
                }
                $coupons = wcusage_get_users_coupons_ids( $id );
                foreach ($coupons as $coupon) {
                    wp_delete_post( $coupon );
                }
            }
        }

        if ( 'bulk-unassign' === $this->current_action() ) {
            $delete_ids = esc_sql( $_POST['bulk-delete'] );
            foreach ( $delete_ids as $id ) {
                $coupons = wcusage_get_users_coupons_ids( $id );
                foreach ($coupons as $coupon) {
                    $coupon_id = $coupon;
                    $coupon = new WC_Coupon($coupon_id);
                    $coupon->update_meta_data('wcu_select_coupon_user', '');
                    $coupon->save();
                }
            }
        }

        if ( 'bulk-delete-coupons' === $this->current_action() ) {
            $delete_ids = esc_sql( $_POST['bulk-delete'] );
            foreach ( $delete_ids as $id ) {
                foreach ( $delete_ids as $id ) {
                    $coupons = wcusage_get_users_coupons_ids( $id );
                    foreach ($coupons as $coupon) {
                        wp_delete_post( $coupon );
                    }
                }
            }
        }

    }

    function get_coupon_users($search_query = '', $role = '') {
        return wcusage_get_coupon_users($search_query, $role);
    }
    
	function column_default( $item, $column_name ) {
        $user_id = $item['ID'];

        // Usage
        $coupons = wcusage_get_users_coupons_ids( $user_id );
        $total_referrals = 0;
        $usage = 0;
        foreach ($coupons as $coupon) {
            $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
            $wcusage_hide_all_time = wcusage_get_setting_value('wcusage_field_hide_all_time', '0');
            $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
            if($all_stats && !$wcusage_hide_all_time && isset($wcu_alltime_stats) && isset($wcu_alltime_stats['total_count'])) {
                $usage = $wcu_alltime_stats['total_count'];
            }
            if(!$usage) {
                global $woocommerce;
                $coupon_code = get_the_title($coupon);
                $c = new WC_Coupon($coupon_code);
                $usage = $c->get_usage_count();
            }
            if($usage) {
                $total_referrals += $usage;
            }
        }

        $qmessage = esc_html__('The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage');

        // Switch
        $coupons = wcusage_get_users_coupons_ids( $user_id );
		switch ( $column_name ) {
			case 'ID':
                return '<a href="' . esc_url(admin_url( 'user-edit.php?user_id=' . $user_id )) . '">#' . $item[ $column_name ] . '</a>';
            case 'Username':
                return wcusage_output_affiliate_tooltip_user_info($user_id);
            case 'roles':
                return ucwords( str_replace( '_', ' ', $item[ $column_name ] ) ); // Capitalize and separate with spaces
            case 'affiliateinfo':
                $theoutput = "";
                foreach ($coupons as $coupon) {
                    $theoutput .= wcusage_output_affiliate_tooltip_users($coupon);
                }
                return $theoutput;
            case 'unpaidcommission':
                $coupons = wcusage_get_users_coupons_ids( $user_id );
                $unpaid_commission = 0;
                foreach ($coupons as $coupon) {
                    $unpaid_commission += (float)get_post_meta($coupon, 'wcu_text_unpaid_commission', true);
                }
                return wcusage_format_price($unpaid_commission);
            case 'usage':
                return $total_referrals;
            case 'sales':
                $coupons = wcusage_get_users_coupons_ids( $user_id );
                $total_sales = 0;
                $sales = 0;
                if(!$coupons) {
                    return wcusage_format_price($sales);
                }
                foreach ($coupons as $coupon) {
                    $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
                    if($wcu_alltime_stats) {
                        if(isset($wcu_alltime_stats['total_orders'])) {
                            $sales = $wcu_alltime_stats['total_orders'];
                        }
                        if(isset($wcu_alltime_stats['full_discount'])) {
                            $discounts = $wcu_alltime_stats['full_discount'];
                            $sales = (float)$sales - (float)$discounts;
                        }
                    }
                    if($sales) {
                        $total_sales += (float)$sales;
                    }
                }
                if($total_referrals > 0 && !$total_sales) {
                    return "<span title='".$qmessage."'><strong><i class='fa-solid fa-ellipsis'></i></strong></span></a>";
                }
                return wcusage_format_price($sales);
            case 'commission':
                $theoutput = "";
                $coupons = wcusage_get_users_coupons_ids( $user_id );
                $total_commission = 0;
                $commission = 0;
                foreach ($coupons as $coupon) {
                    $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
                    if($wcu_alltime_stats && isset($wcu_alltime_stats['total_commission'])) {
                        $commission = $wcu_alltime_stats['total_commission'];
                        if($commission) {
                            $total_commission += $wcu_alltime_stats['total_commission'];
                        }
                    }
                }
                if($total_referrals > 0 && !$total_commission) {
                    return "<span title='".$qmessage."'><strong><i class='fa-solid fa-ellipsis'></i></strong></span></a>";
                }
                return wcusage_format_price($total_commission);
            case 'mlacommission':
                $total_commission = wcusage_mla_total_earnings($user_id);
                return wcusage_format_price($total_commission);
            case 'affiliatemla':
                if( wcu_fs()->can_use_premium_code() ) {
                    $theoutput = "";
                    $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
                    if($wcusage_field_mla_enable) {
                        $dash_page_id = wcusage_get_mla_shortcode_page_id();
                        $dash_page = get_page_link($dash_page_id);
                        $user_info = get_userdata($user_id);
                        $theoutput = '<a href="'.$dash_page.'?user='.$user_info->user_login.'" title="View MLA Dashboard" target="_blank">MLA <span class="dashicons dashicons-external"></span></a>';
                    }
                    return $theoutput;     
                }   
            case 'affiliatestorecredit':
                    if( wcu_fs()->can_use_premium_code() ) {
                    $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
                    if( $credit_enable && function_exists( 'wcusage_get_credit_users_balance' ) ) {
                        $balance = wcusage_format_price( wcusage_get_credit_users_balance( $user_id ) );
                        return $balance;
                    } else {
                        return "";
                    }
                }
			default:
				return print_r( $item, true );
		}
	}
}

/*
* Create coupon users page
*/
function wcusage_coupon_users_page() {

    // Post Submit Add Registration Form
    if(isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        if( wp_verify_nonce( $nonce, 'admin_add_registration_form' ) && wcusage_check_admin_access() ) {
            echo wp_kses_post(wcusage_post_submit_application(1));
        }
    }

    // If GET success = 1, show success message
    if(isset($_GET['success']) && $_GET['success'] == 1) {
        if(isset($_GET['user'])) {
            $username = sanitize_text_field($_GET['user']);
            echo '<div class="notice notice-success is-dismissible"><p>'
            . sprintf(esc_html__('The %s user %s has been successfully added.', 'woo-coupon-usage'), wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' )), $username)
            . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>'
            . sprintf(esc_html__('The %s user has been successfully added.', 'woo-coupon-usage'), wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' )))
            . '</p></div>';
        }
    }

    $coupon_users_table = new WC_Coupon_Users_Table();
    $coupon_users_table->process_bulk_action();
	$coupon_users_table->prepare_items();
	?>
    
    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

    <style>@media screen and (min-width: 782px) { .wcusage_users_page_desc { margin-bottom: -40px; } }</style>
	<div class="wrap wcusage_users_page_header">

        <?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

		<h2 class="wp-heading-inline wcusage-admin-title">
        <?php echo sprintf(esc_html__('Coupon %s Users', 'woo-coupon-usage'), wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' ))); ?>
        <span class="wcusage-admin-title-buttons">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_add_affiliate')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add New <?php echo wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' )); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage-bulk-coupon-creator')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Bulk Create <?php echo wcusage_get_affiliate_text(__( 'Affiliates', 'woo-coupon-usage' ), true); ?></a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wcusage_affiliates&action=export_csv'), 'wcusage_export_users_csv')); ?>" class="wcusage-settings-button" id="wcu-admin-export-csv" style="float: right;">
                <?php echo sprintf(esc_html__('Export %s Users', 'woo-coupon-usage'), wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' ))); ?> <span class="fa-solid fa-download"></span>
            </a>
            <p style="display: block;" class="wcusage_users_page_desc"><?php echo sprintf(esc_html__('This page displays all the users that are assigned to an %s coupon.', 'woo-coupon-usage'), wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' ))); ?></p>
            <br/>
        </span>
        </h2>
        <form method="post">
            <?php wp_nonce_field( 'wcusage_coupon_users_bulk_action', '_wcusage_bulk_nonce' ); ?>
            <input type="hidden" name="page" value="<?php echo esc_html($_REQUEST['page']); ?>" />
            <?php $coupon_users_table->search_box('Search Users', 'user_search'); ?>
            <?php $coupon_users_table->display(); ?>
        </form>
	</div>
    <style>
    .wp-list-table .column-cb {
        width: 40px !important;
    }
    .wp-list-table .column-cb input, .check-column input {
        margin-top: 1px !important;
        margin-left: 0px !important;
    }
    .wp-list-table .column-ID {
        width: 50px;
    }
    .wp-list-table .column-email {
        width: 50px;
    }
    .wp-list-table .column-affiliatemla {
        width: 100px;
    }
    </style>
    <script>
    jQuery(document).ready(function($) {
        $('#doaction, #doaction2').click(function(e) {
            var actionSelected = $(this).siblings('select').val();
            var actionText = '';
            switch (actionSelected) {
                case 'bulk-delete-users':
                    actionText = 'Are you sure you want to delete selected <?php echo wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' )); ?> users?\n\nThis will NOT delete the coupons assigned to them.';
                    break;
                case 'bulk-delete-all':
                    actionText = 'Are you sure you want to delete the selected <?php echo wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' )); ?> users, and delete all the coupons they are assigned to?';
                    break;
                case 'bulk-unassign':
                    actionText = 'Are you sure you want to unassign the selected users from their coupons?\n\nThis will essentially remove their access to the <?php echo wcusage_get_affiliate_text(__( 'affiliate', 'woo-coupon-usage' )); ?> dashboard and commission earnings.\n\nThe users and coupons will NOT be deleted.';
                    break;
                case 'bulk-delete-coupons':
                    actionText = 'Are you sure you want to delete the coupons assigned to the selected users?\n\nThe users will NOT be deleted.';
                    break;
                default:
                    return;
            }

            if (!window.confirm(actionText)) {
                e.preventDefault();
                return false;
            }
        });
    });
    </script>
	<?php
}

/**
 * Handle CSV export request
 */
add_action('admin_init', 'wcusage_handle_export_csv');
function wcusage_handle_export_csv() {
    // Check if we're on the correct page and action
    if (isset($_GET['page']) && 
        (($_GET['page'] === 'wcusage_coupon_users') || ($_GET['page'] === 'wcusage_affiliates')) && 
        isset($_GET['action']) && $_GET['action'] === 'export_csv' && 
        isset($_GET['_wpnonce'])) {
        
        // Verify nonce and permissions
        if (wp_verify_nonce($_GET['_wpnonce'], 'wcusage_export_users_csv')) {
            // Double-check admin access
            if (!current_user_can('manage_options') && !current_user_can('administrator')) {
                wp_die(__('Sorry, you are not allowed to access this page.', 'woo-coupon-usage'));
            }
            
            wcusage_export_coupon_users_csv();
            exit;
        }
    }
}

/**
 * Export coupon users to CSV
 */
function wcusage_export_coupon_users_csv() {
    // Get all users without pagination
    $users = wcusage_get_coupon_users();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="coupon-affiliate-users-' . date('Y-m-d-H-i-s') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Prepare column headers
    $headers = array(
        'User ID',
        'Username',
        'Display Name',
        'Email',
        'Group / Role',
        'Total Referrals',
        'Total Sales',
        'Total Commission',
        'Unpaid Commission',
        wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' )) . ' Coupons'
    );
    
    // Add conditional headers
    if (wcu_fs()->can_use_premium_code()) {
        $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
        $system = wcusage_get_setting_value('wcusage_field_storecredit_system', 'default');
        $storecredit_users_col = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_users_col', 1);
        if ($credit_enable && $storecredit_users_col && $system == "default") {
            $credit_label = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_only', 'Store Credit');
            $headers[] = $credit_label;
        }
        
        $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
        if ($wcusage_field_mla_enable) {
            $headers[] = 'Total MLA Commission';
        }
    }
    
    // Write headers
    fputcsv($output, $headers);
    
    // Process each user
    foreach ($users as $user) {
        $user_id = $user['ID'];
        $user_data = get_userdata($user_id);
        
        // Get coupons for this user
        $coupons = wcusage_get_users_coupons_ids($user_id);
        
        // Calculate totals
        $total_referrals = 0;
        $total_sales = 0;
        $total_commission = 0;
        $unpaid_commission = 0;
        $coupon_codes = array();
        
        foreach ($coupons as $coupon) {
            $coupon_code = get_the_title($coupon);
            $coupon_codes[] = $coupon_code;
            
            // Get usage count
            $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
            $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
            $wcusage_hide_all_time = wcusage_get_setting_value('wcusage_field_hide_all_time', '0');
            
            if ($all_stats && $wcu_alltime_stats && !$wcusage_hide_all_time) {
                if (isset($wcu_alltime_stats['total_count'])) {
                    $total_referrals += $wcu_alltime_stats['total_count'];
                }
                if (isset($wcu_alltime_stats['total_orders'])) {
                    $sales = $wcu_alltime_stats['total_orders'];
                    if (isset($wcu_alltime_stats['full_discount'])) {
                        $sales -= $wcu_alltime_stats['full_discount'];
                    }
                    $total_sales += $sales;
                }
                if (isset($wcu_alltime_stats['total_commission'])) {
                    $total_commission += $wcu_alltime_stats['total_commission'];
                }
            } else {
                $c = new WC_Coupon($coupon_code);
                $usage = $c->get_usage_count();
                $total_referrals += $usage;
            }
            
            // Get unpaid commission
            $unpaid_commission += (float)get_post_meta($coupon, 'wcu_text_unpaid_commission', true);
        }
        
        // Prepare row data
        $row = array(
            $user_id,
            $user_data->user_login,
            $user_data->display_name,
            $user_data->user_email,
            ucwords(str_replace('_', ' ', implode(', ', $user_data->roles))),
            $total_referrals,
            number_format($total_sales, 2, '.', ''),
            number_format($total_commission, 2, '.', ''),
            number_format($unpaid_commission, 2, '.', ''),
            implode(', ', $coupon_codes)
        );
        
        // Add conditional data
        if (wcu_fs()->can_use_premium_code()) {
            $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
            $system = wcusage_get_setting_value('wcusage_field_storecredit_system', 'default');
            $storecredit_users_col = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_users_col', 1);
            if ($credit_enable && $storecredit_users_col && $system == "default" && function_exists('wcusage_get_credit_users_balance')) {
                $row[] = number_format(wcusage_get_credit_users_balance($user_id), 2, '.', '');
            }
            
            $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
            if ($wcusage_field_mla_enable) {
                $mla_commission = wcusage_mla_total_earnings($user_id);
                $row[] = number_format($mla_commission, 2, '.', '');
            }
        }
        
        // Write row
        fputcsv($output, $row);
    }
    
    fclose($output);
}

/**
 * Get array of user IDs that have been assigned to coupons
 */
if( !function_exists( 'wcusage_get_coupon_users' ) ) {
    function wcusage_get_coupon_users($search_query = '', $role = '') {
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
        );

        $coupons = get_posts($args);
        $user_ids = array();

        foreach ($coupons as $coupon) {
            $user_id = get_post_meta($coupon->ID, 'wcu_select_coupon_user', true);
            if ($user_id) {
                if (!is_numeric($user_id) && is_string($user_id)) {
                    // If it's a username (legacy data), convert to ID
                    $user = get_user_by('login', $user_id);
                    if ($user) {
                        $user_id = $user->ID;
                        update_post_meta($coupon->ID, 'wcu_select_coupon_user', $user_id);
                    } else {
                        $user_id = '';
                    }
                }
                if(!is_numeric($user_id)) {
                    $user_id = '';
                }
                if ($user_id) {
                    $user_ids[] = $user_id;
                }
            }
        }


        if (empty($user_ids) || !is_array($user_ids)) {
            return array();
        }

        $filtered_user_ids = array_filter($user_ids, function($item) {
            return !empty($item);
        });

        $users_array = array_unique($filtered_user_ids);
        $users = array();

        foreach ($users_array as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $user_roles = implode(', ', $user->roles);
                if ($search_query && stripos($user->user_login, $search_query) === false && stripos($user->display_name, $search_query) === false) {
                    continue;
                }
                if ($role && !in_array($role, $user->roles)) {
                    continue;
                }
                $users[] = array(
                    'ID'       => $user->ID,
                    'Username' => $user->user_login,
                    'roles'    => implode(', ', $user->roles),
                    'name'     => $user->display_name,
                    'email'    => $user->user_email,
                    'action'   => ''
                );
            }
        }

        return $users;
    }
}