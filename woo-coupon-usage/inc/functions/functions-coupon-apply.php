<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Function that runs when coupon is applied to check if it is allowed
 *
 */
if( !function_exists( 'wcusage_applied_coupon_check_allow_coupons' ) ) {
  function wcusage_applied_coupon_check_allow_coupons() {

    $current_coupons = 0;
    $coupon = "";

    foreach ( WC()->cart->get_coupons() as $code => $coupon ) {

      // Check if template coupon is applied and remove it
      $wcusage_field_registration_coupon_template = wcusage_get_setting_value('wcusage_field_registration_coupon_template', '');
      if($wcusage_field_registration_coupon_template) {
        if($coupon->get_code() == $wcusage_field_registration_coupon_template) {
          WC()->cart->remove_coupon( $coupon->get_code() );
          wc_clear_notices();
          if(current_user_can('manage_options')) {
            wc_add_notice( esc_html__( "Admin notice: The 'template coupon code' can not be applied to any cart.", "woo-coupon-usage" ), "error" );
          }
        }
      }

      // Check if coupon is expired
      $coupon_user_id = get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true );
      if($coupon_user_id) {

        $current_coupons++;

        /***** Checks if current user is assigned to the coupon *****/

        $wcusage_field_allow_assigned_user = wcusage_get_setting_value('wcusage_field_allow_assigned_user', 1);
        if(!$wcusage_field_allow_assigned_user) {

            $current_user_id = get_current_user_id();

            $iscouponusers = wcusage_iscouponusers( $coupon->get_code(), $current_user_id );

            if($iscouponusers) {

              WC()->cart->remove_coupon( $coupon->get_code() );

              wc_clear_notices();

              wc_add_notice( esc_html__( "Sorry, you can't use your own affiliate coupon code.", "woo-coupon-usage" ), "error" );

            }

        }

      }

    }

    /***** Checks if other affiliate coupons already used *****/

    $wcusage_field_allow_multiple_coupons = wcusage_get_setting_value('wcusage_field_allow_multiple_coupons', 0);
    if(!$wcusage_field_allow_multiple_coupons) {

      if($current_coupons > 1) {

        foreach ( WC()->cart->get_coupons() as $code => $coupon ) {

          WC()->cart->remove_coupon( $coupon->get_code() );

          wc_clear_notices();

        }

        // get wcusage_referral or wcusage_referral_code cookie and set that as coupon
        $referral_code = "";
        if(isset($_COOKIE['wcusage_referral_code'])) {
          $referral_code = $_COOKIE['wcusage_referral_code'];
        } else if(isset($_COOKIE['wcusage_referral'])) {
          $referral_code = $_COOKIE['wcusage_referral'];
        }
        if($referral_code) {
          WC()->cart->add_discount( $referral_code );
        }

        wc_add_notice( esc_html__( "Sorry, you can only use one affiliate coupon per order.", "woo-coupon-usage" ), "error" );

      }

    }

  }
}
add_action( 'woocommerce_applied_coupon', 'wcusage_applied_coupon_check_allow_coupons', 10, 0 );
add_action( 'woocommerce_before_cart', 'wcusage_applied_coupon_check_allow_coupons', 10, 0 );
add_action( 'woocommerce_before_checkout_form', 'wcusage_applied_coupon_check_allow_coupons', 10, 0 );

/**
 * Function that checks if customer is allowed to use the applied coupons at all stages.
 *
 */
if( !function_exists( 'wcusage_applied_coupon_check_allow_customer' ) ) {
  function wcusage_applied_coupon_check_allow_customer() {

    foreach ( WC()->cart->get_coupons() as $code => $coupon ) {

      if($coupon && !empty($coupon->get_id())) {

        $coupon_user_id = get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true );
        if(!$coupon_user_id) {
          continue;
        }

        /***** Check existing customer. *****/

        $allow_all_customers = wcusage_get_setting_value('wcusage_field_allow_all_customers', 1);
        $first_order_only = get_post_meta( $coupon->get_id(), 'wcu_enable_first_order_only', true );
        if( $first_order_only == "yes" || !$allow_all_customers ) {
          $checkout_email = WC()->checkout()->get_value( 'billing_email' );
          if(wcusage_is_existing_customer($checkout_email)) {
            wc_clear_notices();
            WC()->cart->remove_coupon( $coupon->get_code() );
            wc_add_notice( esc_html__( "Sorry, only new customers can use this coupon code.", "woo-coupon-usage" ), "error" );
          }
        }

        /***** Check if visitor is blacklisted *****/

        if( wcusage_is_customer_blacklisted() ) {
            wc_clear_notices();
            WC()->cart->remove_coupon( $coupon->get_code() );
            wc_add_notice( esc_html__( "Sorry, you can't use this coupon code or it has expired.", "woo-coupon-usage"), "error" );
        }

        /***** Check if referrer domain is blacklisted *****/

        $block_domains_manual = wcusage_get_setting_value('wcusage_field_fraud_block_domains_manual', '0');
        if( wcusage_is_domain_blacklisted() && $block_domains_manual ) {
            wc_clear_notices();
            WC()->cart->remove_coupon( $coupon->get_code() );
            wc_add_notice( esc_html__( "Sorry, you can't use this coupon code or it has expired.", "woo-coupon-usage"), "error" );
        }

      }

    }

  }
}
add_action( 'woocommerce_before_checkout_form', 'wcusage_applied_coupon_check_allow_customer', 10, 0 );
add_action( 'woocommerce_before_cart', 'wcusage_applied_coupon_check_allow_customer', 10, 0 );
add_action( 'woocommerce_applied_coupon', 'wcusage_applied_coupon_check_allow_customer', 10, 0 );

/**
 * Revalidate coupons when the email address is updated on checkout.
 *
 * @param array $fields Checkout fields.
 * @param WP_Error $errors Validation errors.
 */
function wcusage_validate_checkout_email_on_edit( $fields, $errors ) {
  if ( isset( $fields['billing_email'] ) ) {
      wcusage_applied_coupon_check_allow_customer();
  }
}
add_action( 'woocommerce_after_checkout_validation', 'wcusage_validate_checkout_email_on_edit', 10, 2 );

/**
 * Wrapper function for the woocommerce_update_cart_action_cart_updated action.
 *
 * @param bool $cart_updated Whether the cart was updated.
 * 
 * @return bool
 */
function wcusage_applied_coupon_check_allow_customer_with_param( $cart_updated ) {
  wcusage_applied_coupon_check_allow_customer();
  return $cart_updated;
}
add_action( 'woocommerce_update_cart_action_cart_updated', 'wcusage_applied_coupon_check_allow_customer_with_param', 10, 1 );

/**
 * Function that checks if user is a new customer
 *
 */
if( !function_exists( 'wcusage_is_existing_customer' ) ) {
  function wcusage_is_existing_customer( $email = "" ) {

      // Get current user id
      $user_id = get_current_user_id();

      if( $user_id ) {
        // Args for wc_get_orders()
        $args = array(
            'status' => array('wc-completed', 'wc-processing', 'wc-pending'), // Only orders with "completed" status
            'customer_id' => $user_id, // Set current user id
            'limit' => 1, // Only need to check if at least one order exists
            'return' => 'ids', // Return Ids
        );
        // Get all customer orders
        $customer_orders = wc_get_orders( $args );
      } else {
        if($email) {
          $customer_orders = wc_get_orders( array(
            'status' => array('wc-completed', 'wc-processing', 'wc-pending'), // Only orders with "completed" status
            'email' => $email, // Set current user id
            'limit' => 1, // Only need to check if at least one order exists
            'return' => 'ids', // Return Ids
          ) );
        } else {
          return false;
        }
      }

      // Return "true" when customer has already at least one order (false if not)
      return count($customer_orders) > 0 ? true : false;
  }
}

/**
 * Function that checks if visitor is blacklisted
 *
 */
if( !function_exists( 'wcusage_is_customer_blacklisted' ) ) {
  function wcusage_is_customer_blacklisted($ip_address = "") {

    $block_ips = wcusage_get_setting_value('wcusage_field_fraud_block_ips', '');

    if($block_ips) {

      $block_ips = preg_split("/\r\n|\n|\r/", $block_ips);

      $referral_id = "";
      if(!$ip_address) {
        $ip_address = $_SERVER['REMOTE_ADDR'];
        if(isset($_COOKIE['wcusage_referral_id'])) {
          $referral_id = $_COOKIE['wcusage_referral_id'];
        }
      }

      if( ( $ip_address && in_array($ip_address, $block_ips) ) || ( $referral_id && in_array($referral_id, $block_ips) ) ) {
        return true;
      }

    }

    return false;

  }
}

/**
 * Function that checks if referrer domain is blacklisted
 *
 */
if( !function_exists( 'wcusage_is_domain_blacklisted' ) ) {
  function wcusage_is_domain_blacklisted($referral_domain = "") {

    $block_domains = wcusage_get_setting_value('wcusage_field_fraud_block_domains', '');
    $referral_domain = "";

    if($block_domains) {

      $block_domains = preg_split("/\r\n|\n|\r/", $block_domains);
      $block_domains = str_replace("http://", "", $block_domains);
      $block_domains = str_replace("https://", "", $block_domains);
      $block_domains = preg_replace('/^www\./i', '', $block_domains);

      if(!$referral_domain && isset($_COOKIE['wcusage_referral_domain'])) {
        $referral_domain = $_COOKIE['wcusage_referral_domain'];
        $referral_domain = preg_replace('/^www\./i', '', $referral_domain);
      }

      if( $referral_domain && in_array($referral_domain, $block_domains) ) {
        return true;
      }

    }

    return false;

  }
}

/*
* Function that changes the coupon label on the cart page to "Referral code"
*
*/
add_filter( 'woocommerce_cart_totals_coupon_label', 'wcusage_custom_woocommerce_coupon_label', 10, 2 );
function wcusage_custom_woocommerce_coupon_label( $label, $coupon ) {

  $wcusage_field_coupon_custom_text = wcusage_get_setting_value('wcusage_field_coupon_custom_text', '');
  if(!$wcusage_field_coupon_custom_text) {
    return $label;
  }

  if ( WC()->cart->get_coupon_discount_amount( $coupon->get_code(), true ) == 0 ) {
    $coupon_user_id = get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true );
    if ( $coupon_user_id == get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true ) ) {
      return str_replace( 'Coupon:', $wcusage_field_coupon_custom_text . ":", $label );
    } else {
      return $label;
    }
  } else {
    return $label;
  }

}

/*
* Function that hides the £0.00 value of a coupon on the cart page
*
*/
add_filter( 'woocommerce_cart_totals_coupon_html', 'wcusage_custom_woocommerce_coupon_html', 10, 2 );
function wcusage_custom_woocommerce_coupon_html( $discount_html, $coupon ) {
  
    // Check if the setting is enabled
    $wcusage_field_coupon_hide_zero = wcusage_get_setting_value('wcusage_field_coupon_hide_zero', 0);
    if(!$wcusage_field_coupon_hide_zero) {
        return $discount_html;
    }

    // Get the user ID of the coupon
    $coupon_user_id = get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true );
    if( ! $coupon_user_id = get_post_meta( $coupon->get_id(), 'wcu_select_coupon_user', true ) ) {
        return $discount_html;
    }
    // Check if the discount amount is £0.00
    if ( WC()->cart->get_coupon_discount_amount( $coupon->get_code(), true ) == 0 ) {
        // Hide the £0.00 value but keep the Remove link
        $discount_html = '<a href="' . esc_url( add_query_arg( 'remove_coupon', $coupon->get_code(), wc_get_cart_url() ) ) . '" class="woocommerce-remove-coupon" aria-label="' . esc_attr__( 'Remove this item', 'woocommerce' ) . '">' . esc_html__( '[Remove]', 'woocommerce' ) . '</a>';
    }

    return $discount_html;
}