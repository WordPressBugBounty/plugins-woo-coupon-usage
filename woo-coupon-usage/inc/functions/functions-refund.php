<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( !function_exists( 'wcusage_order_refund_is_full' ) ) {
  function wcusage_order_refund_is_full( $order, $refund = 0, $args = array() ) {

    if( ! $order || ! is_a( $order, 'WC_Order' ) ) {
      return false;
    }

    if( $order->get_status() == "refunded" ) {
      return true;
    }

    $order_total = (float) $order->get_total();
    if( $order_total <= 0 ) {
      return false;
    }

    $current_refund_id = 0;
    $current_refund_amount = 0;

    if( is_object( $refund ) && method_exists( $refund, 'get_id' ) ) {
      $current_refund_id = absint( $refund->get_id() );
      if( method_exists( $refund, 'get_amount' ) ) {
        $current_refund_amount = abs( (float) $refund->get_amount() );
      }
    } elseif( $refund ) {
      $current_refund_id = absint( $refund );
      $refund_order = wc_get_order( $current_refund_id );
      if( $refund_order && method_exists( $refund_order, 'get_amount' ) ) {
        $current_refund_amount = abs( (float) $refund_order->get_amount() );
      }
    }

    if( ! $current_refund_amount && isset( $args['amount'] ) ) {
      $current_refund_amount = abs( (float) $args['amount'] );
    }

    $refunded_total = 0;
    foreach( $order->get_refunds() as $order_refund ) {
      if( $current_refund_id && $order_refund->get_id() == $current_refund_id ) {
        continue;
      }
      if( method_exists( $order_refund, 'get_amount' ) ) {
        $refunded_total += abs( (float) $order_refund->get_amount() );
      }
    }

    if( $current_refund_amount ) {
      $refunded_total += $current_refund_amount;
    }

    if( ! $refunded_total ) {
      $refunded_total = (float) $order->get_total_refunded();
    }

    $price_decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

    return round( $refunded_total, $price_decimals ) >= round( $order_total, $price_decimals );

  }
}

/**
 * Force refresh/update affiliate stats on order refunds change
 *
 * @param int $refund
 * @param array $args
 *
 */
if( !function_exists( 'wcusage_order_update_stats_refund' ) ) {
  function wcusage_order_update_stats_refund( $refund, $args ) {

    $order_id = isset( $args['order_id'] ) ? $args['order_id'] : 0;

    $wcusage_field_enable_order_commission_meta = wcusage_get_setting_value('wcusage_field_enable_order_commission_meta', '1');

    if($wcusage_field_enable_order_commission_meta) {

      $order = wc_get_order( $order_id );
      if($order) {

        $full_refund = wcusage_order_refund_is_full( $order, $refund, $args );
        $order_was_counted = wcusage_check_status_show( $order->get_status() ) || wcusage_order_meta( $order_id, 'wcusage_all_updated' );
        $update_alltime_stats = !$full_refund || $order_was_counted;
        $change_usage = ( $full_refund && $order_was_counted ) ? 1 : 0;
        
        $lifetimeaffiliate = wcusage_order_meta($order_id,'lifetime_affiliate_coupon_referrer');
        $affiliatereferrer = wcusage_order_meta($order_id,'wcusage_referrer_coupon');

        if($lifetimeaffiliate) {

          if($update_alltime_stats) {
            wcusage_update_all_stats_single($lifetimeaffiliate, $order_id, 0, $change_usage, 0);
          }

        } elseif($affiliatereferrer) {

          if($update_alltime_stats) {
            wcusage_update_all_stats_single($affiliatereferrer, $order_id, 0, $change_usage, 0);
          }

        } else {

          foreach( $order->get_coupon_codes() as $coupon_code ) {

            if($update_alltime_stats) {
              wcusage_update_all_stats_single($coupon_code, $order_id, 0, $change_usage, 0);
            }

          }

        }

        if($full_refund) {
          wcusage_delete_order_meta( $order_id, 'wcusage_all_updated' );
        }

      }

    }

  }
}
add_action( 'woocommerce_create_refund', 'wcusage_order_update_stats_refund', 5, 2);

/**
 * Refund deleted
 *
 */
function wcusage_order_update_stats_refund_delete($refund_id, $order_id) {

  wcusage_delete_order_meta($order_id, 'wcusage_stats');
  wcusage_delete_order_meta($order_id, 'wcusage_commission_summary');
  wcusage_delete_order_meta($order_id, 'wcusage_total_commission');
  wcusage_delete_order_meta($order_id, 'wcu_mla_commission');

}
add_action( 'woocommerce_refund_deleted', 'wcusage_order_update_stats_refund_delete', 5, 2 );

/**
 * Force refresh/update affiliate stats on order refunds change
 *
 * @param int $order_id
 * @param int $refund_id
 *
 */
function wcusage_order_update_stats_refund_complete( $order_id, $refund_id ) {

  $order = wc_get_order( $order_id );
  if( ! $order || ! is_a( $order, 'WC_Order' ) ) {
    return;
  }

  $full_refund = wcusage_order_refund_is_full( $order, $refund_id );

  $wcusage_field_enable_order_commission_meta = wcusage_get_setting_value('wcusage_field_enable_order_commission_meta', '1');

  if($wcusage_field_enable_order_commission_meta) {

    $order = wc_get_order( $order_id );
    if($order) {

      $lifetimeaffiliate = wcusage_order_meta($order_id,'lifetime_affiliate_coupon_referrer');
      $affiliatereferrer = wcusage_order_meta($order_id,'wcusage_referrer_coupon');

      if($lifetimeaffiliate) {
        
        if(!$full_refund) {
          wcusage_update_all_stats_single($lifetimeaffiliate, $order_id, 1, 0);
        }

        $calculateorder = wcusage_calculate_order_data( $order_id, $lifetimeaffiliate, 1, 0, 1 );

        $coupon_info = wcusage_get_coupon_info($lifetimeaffiliate);
        $coupon_id = $coupon_info[2];
        do_action('wcusage_hook_reset_order_stats_month', $order, $coupon_id);

      } elseif($affiliatereferrer) {

        if(!$full_refund) {
          wcusage_update_all_stats_single($affiliatereferrer, $order_id, 1, 0);
        }

        $calculateorder = wcusage_calculate_order_data( $order_id, $affiliatereferrer, 1, 0, 1 );

        $coupon_info = wcusage_get_coupon_info($affiliatereferrer);
        $coupon_id = $coupon_info[2];
        do_action('wcusage_hook_reset_order_stats_month', $order, $coupon_id);
        
      } else {

        foreach( $order->get_coupon_codes() as $coupon_code ) {

          if(!$full_refund) {
            wcusage_update_all_stats_single($coupon_code, $order_id, 1, 0);
          }

          $calculateorder = wcusage_calculate_order_data( $order_id, $coupon_code, 1, 0, 1 );

          $coupon_info = wcusage_get_coupon_info($coupon_code);
          $coupon_id = $coupon_info[2];
          do_action('wcusage_hook_reset_order_stats_month', $order, $coupon_id);

        }

      }

      if($full_refund) {
        wcusage_delete_order_meta( $order_id, 'wcusage_all_updated' );
      }

    }

  }

}
add_action( 'woocommerce_order_refunded', 'wcusage_order_update_stats_refund_complete', 5, 2);