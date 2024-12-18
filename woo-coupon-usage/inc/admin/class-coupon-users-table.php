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
        $actions = [
            'bulk-delete-users' => 'Delete Affiliate Users',
            'bulk-delete-all' => 'Delete Affiliate Users and Coupons',
            'bulk-unassign' => 'Unassign Coupons from Affiliate Users',
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
		$total_items = count( $this->get_coupon_users() );

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
            if($all_stats && isset($wcu_alltime_stats) && !$wcusage_hide_all_time) {
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
                return '<a href="' . esc_url(admin_url( 'user-edit.php?user_id=' . $user_id )) . '"><span class="dashicons dashicons-edit" style="font-size: 15px; margin-top: 4px;"></span> ' . $item[ $column_name ] . '</a>';
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

    $coupon_users_table = new WC_Coupon_Users_Table();
    $coupon_users_table->process_bulk_action();
	$coupon_users_table->prepare_items();
	?>
    
    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

    <?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

    <style>@media screen and (min-width: 782px) { .wcusage_users_page_desc { margin-bottom: -40px; } }</style>
	<div class="wrap wcusage_users_page_header">
		<h2 class="wcusage-admin-title">
        <?php echo esc_html__('Coupon Affiliate Users', 'woo-coupon-usage'); ?>
        <span class="wcusage-admin-title-buttons">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_add_affiliate')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add New Affiliate</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage-bulk-coupon-creator')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Bulk Create Affiliates</a>
            <p style="display: block;" class="wcusage_users_page_desc"><?php echo esc_html__('This page displays all the users that are assigned to an affiliate coupon.', 'woo-coupon-usage'); ?></p>
            <br/>
        </span>
        </h2>
        <form method="post">
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
                    actionText = 'Are you sure you want to delete selected affiliate users?\n\nThis will NOT delete the coupons assigned to them.';
                    break;
                case 'bulk-delete-all':
                    actionText = 'Are you sure you want to delete the selected affiliate users, and delete all the coupons they are assigned to?';
                    break;
                case 'bulk-unassign':
                    actionText = 'Are you sure you want to unassign the selected users from their coupons?\n\nThis will essentially remove their access to the affiliate dashboard and commission earnings.\n\nThe users and coupons will NOT be deleted.';
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