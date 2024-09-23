<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * On users list show message that filtered by coupon affiliates users only.
 *
 */
function wcusage_filter_users_by_affiliates_message($which) {

  if( !empty($_GET["page"]) && $_GET["page"] != "users" && $_GET["page"] != "wcusage_affiliates" ) {
    return;
  }

  if(isset($_POST['_wpnonce2'])) {
    if ( ! wp_verify_nonce( $_POST['_wpnonce2'], 'admin_unlink_affiliate' ) ) {
      wp_die( 'Security check' );
    }
    $couponid = $_POST['wcu-id'];
    wcusage_coupon_affiliate_unlink( $couponid );
  }

}
add_action('admin_head', 'wcusage_filter_users_by_affiliates_message');


add_action( 'load-users.php', function() {

   $screen = get_current_screen();

    // Only edit post screen:
   if( 'users' === $screen->id )
   {

      // Show Message if Coupon Affiliates role selected.
      if (isset($_GET["role"])) {
       if($_GET["role"] == "coupon_affiliate") {

         add_action( 'all_admin_notices', function(){
             echo '<p style="color: green; font-weight: bold; margin-bottom: 0;"><span class="dashicons dashicons-info-outline"></span>&nbsp; Filter: Only showing users with the Coupon Affiliate role.</p>';
         });

       }
      }

    }

});

/**
 * On users list "Add new Affiliate"
 *
 */
function wcusage_filter_users_custom_button($which) {
  ?>
    <script>
    jQuery(jQuery(".wrap .wcusage-settings-button")[0]).after('
    <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_add_affiliate')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Add New Affiliate</a><a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_affiliates')); ?>" class="wcusage-settings-button" id="wcu-admin-create-registration-link">Manage Affiliates</a>
    ');
    </script>
  <?php
}
add_action('admin_footer-users.php', 'wcusage_filter_users_custom_button');

/**
 * Add Custom Columns to Users List
 *
 */
 function wcusage_new_modify_user_table( $column ) {

     if( wcu_fs()->can_use_premium_code() ) {
       $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
       $system = wcusage_get_setting_value('wcusage_field_storecredit_system', 'default');
       $storecredit_users_col = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_users_col', 1);
       if($credit_enable && $storecredit_users_col && $system == "default") {
         $credit_label = wcusage_get_setting_value('wcusage_field_tr_payouts_storecredit_only', 'Store Credit');
         $column['affiliatestorecredit'] = $credit_label;
       }
     }

     $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
     if($wcusage_field_mla_enable) {
       $column['affiliatemla'] = 'MLA';
     }

     $column['affiliateinfo'] = 'Affiliate Coupons';

     return $column;
 }
 add_filter( 'manage_users_columns', 'wcusage_new_modify_user_table' );

 function wcusage_new_modify_user_table_row( $val, $column_name, $user_id ) {
     $coupons = wcusage_get_users_coupons_ids( $user_id );
     switch ($column_name) {
         case 'affiliateinfo':
             $theoutput = "";
             foreach ($coupons as $coupon) {
               $theoutput .= wcusage_output_affiliate_tooltip_users($coupon);
            }
            return $theoutput;
         case 'affiliatemla':
            $wcusage_field_show_mla_private = wcusage_get_setting_value('wcusage_field_show_mla_private', '0');
            $access = get_user_meta($user_id, 'wcu_ml_access', true);
            if($wcusage_field_show_mla_private && !$access) {
              return "";
            }
            $theoutput = "";
            $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
            if($wcusage_field_mla_enable) {
              $dash_page_id = wcusage_get_mla_shortcode_page_id();
              $dash_page = get_page_link($dash_page_id);
              $user_info = get_userdata($user_id);
              $theoutput = '<a href="'.$dash_page.'?user='.$user_info->user_login.'" title="View MLA Dashboard" target="_blank">MLA <span class="dashicons dashicons-external"></span></a>';
            }
            return $theoutput;
         case 'affiliatestorecredit':
            $credit_enable = wcusage_get_setting_value('wcusage_field_storecredit_enable', 0);
            if( $credit_enable && function_exists( 'wcusage_get_credit_users_balance' ) ) {
              $balance = wcusage_format_price( wcusage_get_credit_users_balance( $user_id ) );
              return $balance;
            } else {
              return "";
            }
         default:
     }
     return $val;
 }
 add_filter( 'manage_users_custom_column', 'wcusage_new_modify_user_table_row', 10, 3 );

 /**
  * Set users page as WooCommerce screen to load tooltip
  *
  */
  add_filter('woocommerce_screen_ids','wcusage_set_uses_wc_screen' );
  function wcusage_set_uses_wc_screen( $screen ){
        $screen[] = 'users';
        return $screen;
  }

 /**
  * Get Coupon Tooltip
  *
  */
 function wcusage_output_affiliate_tooltip_users($couponid) {

  $coupon_info = wcusage_get_coupon_info_by_id($couponid);
  $user_id = $coupon_info[1];
  $user_info = get_userdata($user_id);

  $coupon_code = $coupon_info[3];
 	$unpaid_commission = wcusage_format_price($coupon_info[2]);

  $wcusage_field_urls_enable = wcusage_get_setting_value('wcusage_field_urls_enable', 1);
  $dashboard_url = $coupon_info[4];
  $wcusage_urls_prefix = wcusage_get_setting_value('wcusage_field_urls_prefix', 'coupon');

  $home_page = get_home_url();
  $link = $home_page.'?' . $wcusage_urls_prefix . '='.esc_html($coupon_code);

  $wcusage_tracking_enable = wcusage_get_setting_value('wcusage_field_tracking_enable', 1);
  if( $wcusage_tracking_enable && wcu_fs()->can_use_premium_code() ) {
    $commission_message = "<strong>" . esc_html__( 'Unpaid Commission', 'woo-coupon-usage' ) . "</strong>: " . $unpaid_commission . "<br/>";
  } else {
    $commission_message = "";
  }

  if($user_info) {
    $unlink_message = '<form method="post" id="unlink_affiliate" style="display: inline;">'
    . wp_nonce_field( 'admin_unlink_affiliate', '_wpnonce2' )
    . '<input type="text" id="wcu-id" name="wcu-id" value="'.$couponid.'" style="display: none;">
    <button href="#!" onClick="'."return confirm('Unassign affiliate user &#8220;".$user_info->user_login."&#8220; from the coupon code &#8220;".$coupon_code."&#8220;? This will not delete the coupon or user, it will simply remove them from the coupon, so they can no longer gain commission or view the affiliate dashboard for it.');".'"
    type="submit" name="unlink_the_affiliate" class="wcu-affiliate-tooltip-unlink-button">Unassign
    </button>
    </form>';
  } else {
    $unlink_message = "";
  }

  $coupon_code_linked = "<span class='wcusage-users-affiliate-column'>"
  ."<div class='custom-tooltip'><a href='javascript:void(0);' style='pointer-events:visible;cursor:pointer;color:darkblue;'>".$coupon_code."</a> <span class='dashicons dashicons-info'
  style='color: green;font-size: 15px;margin-top: 4px;margin-left: -4px;'></span>
      <div class='tooltip-content'>
      <span style='font-size: 12px;'>"
      . $commission_message
      . "<a href='".$dashboard_url."' target='_blank' class='wcu-affiliate-tooltip-dashboard-button' style='text-decoration: underline;'>"
      . esc_html__( 'View Affiliate Dashboard', 'woo-coupon-usage' ) . "<span class='dashicons dashicons-external' style='text-decoration: none;'></span>"
      . "</a>";
      if($wcusage_field_urls_enable) {
        $coupon_code_linked .= '<div class="wcusage-copyable-link" style="margin: 10px 0;"><strong>' . esc_html__( 'Default Referral Link', 'woo-coupon-usage' ) . ':</strong><br/>'
        . '<input type="text" id="wcusageLink'.$coupon_code.'" value="'.$link.'"
        style="max-width: 125px;width: 75%;max-height: 24px;min-height: 24px;font-size: 10px;" readonly>'
        . '<button type="button" class="wcusage-copy-link-button" style="max-height: 24px;min-height: 24px;"
        title="'.esc_html__( 'Copy Link', 'woo-coupon-usage' ).'"><i class="fa-regular fa-copy"></i></button>'
        . '</div>';
      } else {
        $coupon_code_linked .= '<br/>';
      }
      $coupon_code_linked .= "<a href='".get_admin_url()."post.php?post=".$couponid."&action=edit'
      target='_blank' class='wcu-affiliate-tooltip-edit-button' style='text-decoration: underline;'>" . esc_html__( 'Edit Coupon', 'woo-coupon-usage' ) . "</a> - "
      . $unlink_message
      . "</span>
      </div>
  </div>";

 	return $coupon_code_linked;

 }
 add_action('wcusage_hook_output_affiliate_tooltip_users', 'wcusage_output_affiliate_tooltip_users');

 /**
  * Get Coupon Tooltip
  *
  */
  function wcusage_output_affiliate_tooltip_user_info($user_id) {

    $user = get_userdata($user_id);
    
    $user_info = array();
    
    $username = $user->user_login;
    $user_info['Username'] = $username;

    $user_info['Email'] = $user->user_email;

    if($user->first_name) {
      $user_info['First Name'] = $user->first_name;
    }

    if($user->last_name) {
      $user_info['Last Name'] = $user->last_name;
    }

    if($user->user_url) {
      $user_info['Website'] = $user->user_url;
    }

    $wcu_promote = get_user_meta( $user_id, 'wcu_promote', true );
    $user_info['Promote'] = $wcu_promote;

    $wcu_referrer = get_user_meta( $user_id, 'wcu_referrer', true );
    $user_info['Referrer'] = $wcu_referrer;

    $wcu_info = get_user_meta( $user_id, 'wcu_info', true );
    $wcu_info = json_decode($wcu_info, true);
    if(!$wcu_info) {
      $wcu_info = array();
    }
    foreach ($wcu_info as $key => $value) {
      $user_info[$key] = $value;
    }

    $info = "<span class='wcusage-users-affiliate-column'>"
    ."<div class='custom-tooltip'><a href='" . admin_url( 'user-edit.php?user_id=' . $user_id ) . "'
    style='pointer-events:visible;cursor:pointer;color:darkblue;'>".$username."</a> <span class='dashicons dashicons-info'
    style='color: green;font-size: 15px;margin-top: 4px;margin-left: -4px;'></span>
        <div class='tooltip-content' style='width: auto;max-width: 250px;min-width:125px;'>";

        if ( $user_info ) {
            foreach ( $user_info as $key => $value ) {
                if(!$value) { continue; }
                // If email make it a mailto link
                if($key == "Email") {
                  $value = '<a href="mailto:'.$value.'" style="text-decoration: underline; color: inherit;">'.$value.'</a>';
                }
                // If website, remove http:// or https://
                if($key == "Website") {
                  $value = str_replace('http://', '', $value);
                  $value = str_replace('https://', '', $value);
                }
                $info .= '<strong style="color: #b7dbdb;">' . esc_html( $key ) . ':</strong><br/>' . wp_kses_post( $value ) . '<br/>';
            }
            // Remove last <br/>
            $info = substr($info, 0, -5);
        }

    $info .= "</div>
    </div>";
  
     return $info;
  
   }
   add_action('wcusage_hook_output_affiliate_tooltip_users', 'wcusage_output_affiliate_tooltip_users');

/**
 * Add Coupon Affiliates & Commission tab to coupons
 *
 */
if( !function_exists( 'add_wcusage_coupon_data_tab' ) ) {
  function add_wcusage_coupon_data_tab( $product_data_tabs ) {
      $product_data_tabs['coupon-affiliates'] = array(
        'label' => esc_html__( 'Coupon Affiliates & Commission', 'woo-coupon-usage' ),
        'target' => 'wcusage_coupon_data',
        'order' => 0,
        'class' => '',
      );
      return $product_data_tabs;
  }
}
add_filter( 'woocommerce_coupon_data_tabs', 'add_wcusage_coupon_data_tab', 99 , 1 );
