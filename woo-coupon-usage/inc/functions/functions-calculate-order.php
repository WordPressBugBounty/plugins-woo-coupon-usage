<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
use Automattic\WooCommerce\Utilities\OrderUtil;
/**
 * Gets the order meta data
 *
 * @param int $orderid
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_order_meta' ) ) {
    function wcusage_order_meta(  $order_id, $item = '', $single = false  ) {
        if ( $order_id && $item ) {
            $order = wc_get_order( $order_id );
            // if order exists
            if ( $order && is_a( $order, 'WC_Order' ) ) {
                $meta_data = $order->get_meta( $item );
                // if $meta_data is a string and a valid JSON
                if ( is_string( $meta_data ) ) {
                    $json_decoded_data = json_decode( sanitize_text_field( $meta_data ), true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        return $json_decoded_data;
                    }
                }
                // return $meta_data if it's not a valid JSON string
                return sanitize_text_field( $meta_data );
            } else {
                return "";
            }
        } else {
            return "";
        }
    }

}
/*
* Edit Meta
*
* @param int $orderid
* @param string $item
* @param string $value
*/
if ( !function_exists( 'wcusage_edit_order_meta' ) ) {
    function wcusage_edit_order_meta(  $order_id, $meta_key = '', $meta_value = ''  ) {
        // Check if empty
        if ( empty( $order_id ) || empty( $meta_key ) ) {
            return;
        }
        // Get current value
        $current_value = get_post_meta( $order_id, $meta_key, true );
        if ( $current_value === $meta_value ) {
            return;
        }
        // If Array
        if ( is_array( $meta_value ) ) {
            $meta_value = json_encode( $meta_value );
        }
        // Make lowercase if certain meta
        $meta_value = wcusage_order_meta_lowercase( $meta_key, $meta_value );
        // Update meta
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( $meta_key, $meta_value );
            $order->save_meta_data();
        }
    }

}
/**
 * Updates the order meta data
 *
 * @param int $orderid
 */
if ( !function_exists( 'wcusage_update_order_meta' ) ) {
    function wcusage_update_order_meta(  $order_id, $item = '', $value = ''  ) {
        $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
        if ( $never_update_commission_meta ) {
            if ( $item == "wcusage_commission_summary" || $item == "wcusage_total_commission" || $item == "wcusage_product_commission" || $item == "wcusage_currency_conversion" ) {
                if ( wcusage_order_meta( $order_id, $item ) ) {
                    return;
                }
            }
        }
        $order = wc_get_order( $order_id );
        if ( $order && is_a( $order, 'WC_Order' ) ) {
            wcusage_edit_order_meta( $order_id, $item, $value );
        }
    }

}
/**
 * Updates the order meta data for multiple items
 *
 * @param int $orderid
 */
function wcusage_update_order_meta_bulk(  $order_id, $meta_data = []  ) {
    $order = wc_get_order( $order_id );
    $update = 0;
    if ( $order && is_a( $order, 'WC_Order' ) ) {
        foreach ( $meta_data as $key => $value ) {
            $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
            if ( $never_update_commission_meta ) {
                if ( $key == "wcusage_commission_summary" || $key == "wcusage_total_commission" || $key == "wcusage_product_commission" || $key == "wcusage_currency_conversion" ) {
                    if ( wcusage_order_meta( $order_id, $key ) ) {
                        continue;
                    }
                }
            }
            wcusage_edit_order_meta( $order_id, $key, $value );
        }
    }
}

/*
 * Make order meta lower case if certain meta
 *
 * @param int $orderid
 *
 * @return mixed
 *
 */
function wcusage_order_meta_lowercase(  $key, $value  ) {
    if ( $key == "wcusage_referrer_coupon" || $key == "lifetime_affiliate_coupon_referrer" ) {
        if ( $value ) {
            $value = strtolower( $value );
        }
    }
    return sanitize_text_field( $value );
}

/**
 * Deletes the order meta data
 *
 * @param int $orderid
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_delete_order_meta' ) ) {
    function wcusage_delete_order_meta(  $order_id, $item = ''  ) {
        if ( !empty( wcusage_order_meta( $order_id, $item ) ) ) {
            $order = wc_get_order( $order_id );
            $order->delete_meta_data( $item );
            $order->save();
        }
    }

}
/**
 * Check if commission disabled for coupon id
 *
 * @param int $coupon_id
 *
 * @return mixed
 *
 */
function wcusage_coupon_disable_commission(  $coupon_id  ) {
    if ( $coupon_id == 0 ) {
        return false;
    }
    $wcusage_field_show_commission = wcusage_get_setting_value( 'wcusage_field_show_commission', '1' );
    if ( !$wcusage_field_show_commission ) {
        return true;
    }
    $disable_non_affiliate = wcusage_get_setting_value( 'wcusage_field_commission_disable_non_affiliate', '0' );
    if ( $disable_non_affiliate ) {
        $wcu_select_coupon_user = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
        $user = get_userdata( $wcu_select_coupon_user );
        if ( !$user ) {
            return true;
        }
        if ( !$wcu_select_coupon_user ) {
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

/**
 * Gets the order totals based on settings for an order ID
 *
 * @param int $orderid
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_get_order_totals' ) ) {
    function wcusage_get_order_totals(  $orderid  ) {
        // Check if order ID is valid
        if ( !is_numeric( $orderid ) ) {
            return [
                'error' => 'Invalid order ID',
            ];
        }
        $order = wc_get_order( $orderid );
        // Check if order object is valid
        if ( !$order instanceof WC_Order ) {
            return [
                'error' => 'Invalid order object',
            ];
        }
        // Retrieve settings
        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '' );
        $commission_include_shipping = wcusage_get_setting_value( 'wcusage_field_commission_include_shipping', '0' );
        // Get order items
        $items = $order->get_items();
        // Get order totals
        $get_total = $order->get_total();
        $get_total_tax = $order->get_total_tax();
        // Get order discounts
        $total_discount = $order->get_total_discount();
        // Get order tax
        $total_tax = ( $wcusage_show_tax ? 0 : $get_total_tax );
        $remove_tax = wcusage_get_tax_to_remove( $orderid );
        // Get order refunds
        $order_refunds = $order->get_refunds();
        $refunded_quantity = 0;
        foreach ( $items as $item_id => $item ) {
            $line_subtotal = $item->get_subtotal();
            $line_total = $item->get_total();
            $line_subtotal_tax = $item->get_subtotal_tax();
            $line_total_tax = $item->get_total_tax();
            $line_discount = $line_total - $line_subtotal;
            // (Negative number)
            $line_discount_per_item = $line_discount / $item->get_quantity();
            $line_discount_tax = $line_total_tax - $line_subtotal_tax;
            // (Negative number)
            // Get Refunded Quantity
            $refunded_quantity = 0;
            foreach ( $order_refunds as $refund ) {
                foreach ( $refund->get_items() as $item_id => $item2 ) {
                    if ( $item2->get_product_id() == $item['product_id'] ) {
                        $refunded_quantity += abs( $item2->get_quantity() );
                        // Get Refund Qty
                    }
                }
            }
            $refunded_discount = $line_discount_per_item * $refunded_quantity;
            $refunded_discount_tax = $line_discount_tax * $refunded_quantity;
            // How many of this item in order
            $line_items = $item->get_quantity();
            $refunded_tax = 0;
            if ( $refunded_quantity > 0 ) {
                if ( $line_items > 1 ) {
                    $refunded_tax = $line_total_tax / $line_items;
                    $total_discount += $refunded_discount / $line_items;
                } else {
                    $refunded_tax = $line_total_tax;
                    $total_discount += $refunded_discount;
                }
                $refunded_tax = $refunded_tax * $refunded_quantity;
            } else {
                $refunded_tax = 0;
            }
            if ( !$wcusage_show_tax ) {
                $total_tax -= $refunded_tax;
            }
        }
        // Get order shipping
        $shipping = ( $commission_include_shipping ? 0 : $order->get_total_shipping() );
        // Check if tax is included in the order total
        if ( $wcusage_show_tax == 1 ) {
            $total_discount += $order->get_discount_tax();
        }
        // Calculate tax percentage and refunds
        $taxpercent = ( $get_total > 0 ? (float) $get_total_tax / (float) $get_total : 0 );
        $ordertotalrefunded = $order->get_total_refunded();
        // Calculate final order total
        $ordertotal = (float) $get_total + (float) $total_discount - (float) $shipping - (float) $total_tax - (float) $remove_tax - (float) $ordertotalrefunded;
        $ordertotaldiscounted = $ordertotal - (float) $total_discount;
        // Format totals
        $ordertotal = number_format(
            (float) $ordertotal,
            2,
            '.',
            ''
        );
        $ordertotaldiscounted = number_format(
            (float) $ordertotaldiscounted,
            2,
            '.',
            ''
        );
        // Return totals
        return [
            'total_discount'       => $total_discount,
            'ordertotal'           => $ordertotal,
            'ordertotaldiscounted' => $ordertotaldiscounted,
            'total_shipping'       => $shipping,
        ];
    }

}
/**
 * Calculates all the order data for a order ID, including commission, based on settings.
 *
 * @param int $orderid
 * @param string $coupon_code
 * @param bool $refresh
 * @param bool $use_saved
 * @param bool $force_update
 *
 * @return array
 *
 */
if ( !function_exists( 'wcusage_calculate_order_data' ) ) {
    function wcusage_calculate_order_data(
        $orderid,
        $coupon_code,
        $refresh = "1",
        $use_saved = "0",
        $force_update = "0"
    ) {
        $getcoupon = wcusage_get_coupon_info( $coupon_code );
        $couponuser = $getcoupon[1];
        $order = wc_get_order( $orderid );
        // if is order
        if ( $order instanceof WC_Order ) {
            $save_order_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_order_commission_meta', '1' );
            $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
            if ( !$save_order_commission_meta && !$never_update_commission_meta ) {
                wcusage_delete_order_meta( $orderid, 'wcusage_commission_summary' );
                wcusage_delete_order_meta( $orderid, 'wcusage_stats' );
            }
            $get_affstats = wcusage_order_meta( $orderid, 'wcusage_stats', true );
            if ( is_array( $get_affstats ) && !empty( $get_affstats ) && !empty( $get_affstats['order'] ) && $get_affstats['order'] > 0 && !empty( $get_affstats['commission'] ) && $get_affstats['commission'] > 0 && !$refresh && !$force_update && $use_saved && $save_order_commission_meta ) {
                $commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
                $totalorders = ( $get_affstats['order'] ?: 0 );
                $totalordersexcl = ( $get_affstats['orderexcl'] ?: 0 );
                $totaldiscounts = ( $get_affstats['discount'] ?: 0 );
                $totalcommission = ( $get_affstats['commission'] ?: 0 );
                // Return Values
                $return_array = [
                    'totalcommission'    => $totalcommission,
                    'totalorders'        => $totalorders,
                    'totalordersexcl'    => $totalordersexcl,
                    'totaldiscounts'     => $totaldiscounts,
                    'commission_summary' => $commission_summary,
                ];
            } else {
                if ( $refresh != "0" ) {
                    $refresh = 1;
                }
                $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '' );
                $wcusage_show_tax_fixed = wcusage_get_setting_value( 'wcusage_field_show_tax_fixed', '0' );
                $totalorders = 0;
                $totaldiscounts = 0;
                $wcusage_get_order_calculate_data = wcusage_get_order_calculate_data(
                    $orderid,
                    $coupon_code,
                    'orders',
                    $refresh,
                    $force_update
                );
                if ( $order instanceof WC_Order && !empty( $order->get_refunds() ) && is_numeric( $order->get_refunds() ) && sizeof( $order->get_refunds() ) > 0 ) {
                    $wcusage_get_order_calculate_data_refunds = wcusage_get_order_calculate_data(
                        $orderid,
                        $coupon_code,
                        'refunds',
                        $refresh,
                        $force_update
                    );
                } else {
                    $wcusage_get_order_calculate_data_refunds = array();
                }
                $wcusage_get_order_totals = wcusage_get_order_totals( $orderid );
                if ( isset( $wcusage_get_order_totals['total_discount'] ) ) {
                    $total_discount = $wcusage_get_order_totals['total_discount'];
                } else {
                    $total_discount = 0;
                }
                // Get Any Fees (Positives) Added
                $wcusage_field_commission_include_fees = wcusage_get_setting_value( 'wcusage_field_commission_include_fees', '0' );
                $fee_total_added = 0;
                if ( !$wcusage_field_commission_include_fees ) {
                    $total_fees = wcusage_get_total_fees( $orderid );
                    if ( isset( $total_fees['fee_total_add'] ) ) {
                        $fee_total_added = $total_fees['fee_total_add'];
                    } else {
                        $fee_total_added = 0;
                    }
                }
                // Get Discount Fees (Negatives) if Enabled
                $wcusage_field_commission_before_discount_custom = wcusage_get_setting_value( 'wcusage_field_commission_before_discount_custom', '0' );
                $fee_total_removed = 0;
                if ( $wcusage_field_commission_before_discount_custom ) {
                    $total_fees = wcusage_get_total_fees( $orderid );
                    $fee_total_removed_tax = 0;
                    if ( $wcusage_show_tax ) {
                        $fee_total_removed_tax = (float) $total_fees['fee_total_remove'] * (float) wcusage_get_order_tax_percent( $orderid );
                    }
                    $fee_total_removed = (float) $total_fees['fee_total_remove'] + (float) $fee_total_removed_tax;
                }
                // Get The Totals
                $ordertotal = 0;
                if ( isset( $wcusage_get_order_totals['ordertotal'] ) ) {
                    $ordertotal = (float) $wcusage_get_order_totals['ordertotal'] - (float) $fee_total_added + (float) $fee_total_removed;
                }
                if ( !$ordertotal || $ordertotal < 0 ) {
                    $ordertotal = 0;
                }
                $ordertotaldiscounted = 0;
                if ( isset( $wcusage_get_order_totals['ordertotaldiscounted'] ) ) {
                    $ordertotaldiscounted = (float) $wcusage_get_order_totals['ordertotaldiscounted'] - (float) $fee_total_added + (float) $fee_total_removed;
                }
                if ( !$ordertotaldiscounted || $ordertotaldiscounted < 0 ) {
                    $ordertotaldiscounted = 0;
                }
                $option_coupon_orders = wcusage_get_setting_value( 'wcusage_field_orders', '10' );
                $affiliate_commission_amount = $wcusage_get_order_calculate_data['affiliate_commission_amount'];
                if ( !$affiliate_commission_amount ) {
                    $affiliate_commission_amount = 0;
                }
                $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
                $current_commission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
                if ( !$never_update_commission_meta || !$current_commission ) {
                    $affiliatecommission = (float) $ordertotal * (float) $affiliate_commission_amount;
                } else {
                    $affiliatecommission = $current_commission;
                }
                $affiliatecommission = number_format(
                    (float) $affiliatecommission,
                    2,
                    '.',
                    ''
                );
                $totalorders += $ordertotal;
                $totalorders = number_format(
                    (float) $totalorders,
                    2,
                    '.',
                    ''
                );
                $totaldiscounts += $total_discount;
                $totaldiscounts = number_format(
                    (float) $totaldiscounts,
                    2,
                    '.',
                    ''
                );
                $totalordersexcl = $totalorders - $totaldiscounts;
                $totalordersexcl = number_format(
                    (float) $totalordersexcl,
                    2,
                    '.',
                    ''
                );
                $fixed_product_commission_total = 0;
                $totalcommission = 0;
                // Get Refund Totals
                if ( isset( $wcusage_get_order_calculate_data_refunds['totalcommission'] ) ) {
                    $refunds_totalcommission = $wcusage_get_order_calculate_data_refunds['totalcommission'];
                } else {
                    $refunds_totalcommission = 0;
                }
                if ( isset( $wcusage_get_order_calculate_data_refunds['fixed_product_commission_total'] ) ) {
                    $refunds_fixed_product_commission_total = $wcusage_get_order_calculate_data_refunds['fixed_product_commission_total'];
                } else {
                    $refunds_fixed_product_commission_total = 0;
                }
                if ( isset( $wcusage_get_order_calculate_data_refunds['refunded_qty'] ) ) {
                    $refunded_qty = $wcusage_get_order_calculate_data_refunds['refunded_qty'];
                } else {
                    $refunded_qty = "";
                }
                $totalrefundedcommission = (float) $refunds_totalcommission + (float) $refunds_fixed_product_commission_total;
                // Get Totals
                $fixed_order_commission = 0;
                $fixed_product_commission_total = 0;
                if ( $wcusage_get_order_calculate_data['totalcommission'] ) {
                    $totalcommission = $wcusage_get_order_calculate_data['totalcommission'];
                }
                if ( $wcusage_get_order_calculate_data['fixed_order_commission'] ) {
                    $fixed_order_commission = $wcusage_get_order_calculate_data['fixed_order_commission'];
                }
                if ( $wcusage_get_order_calculate_data['fixed_product_commission_total'] ) {
                    $fixed_product_commission_total = $wcusage_get_order_calculate_data['fixed_product_commission_total'];
                }
                // Deduct custom percent
                $deduct_percent_show = wcusage_get_setting_value( 'wcusage_field_affiliate_deduct_percent_show', '0' );
                $deduct_percent = wcusage_get_setting_value( 'wcusage_field_affiliate_deduct_percent', '0' );
                $deduct_percent = (100 - $deduct_percent) / 100;
                if ( $deduct_percent && $deduct_percent_show ) {
                    $ordertotal = $ordertotal * $deduct_percent;
                    $ordertotaldiscounted = $ordertotaldiscounted * $deduct_percent;
                    $totalorders = $totalorders * $deduct_percent;
                    $totaldiscounts = $totaldiscounts * $deduct_percent;
                    $totalordersexcl = $totalordersexcl * $deduct_percent;
                }
                // Currency Conversion
                $enablecurrency = wcusage_get_setting_value( 'wcusage_field_enable_currency', '0' );
                if ( $enablecurrency ) {
                    $wcusage_currency_conversion = wcusage_order_meta( $orderid, 'wcusage_currency_conversion', true );
                    $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
                    if ( !$wcusage_currency_conversion || !$enable_save_rate ) {
                        $wcusage_currency_conversion = "";
                    }
                    if ( !empty( $order->get_currency() ) ) {
                        $currencycode = $order->get_currency();
                        $ordertotal = wcusage_calculate_currency( $currencycode, $ordertotal, $wcusage_currency_conversion );
                        $ordertotaldiscounted = wcusage_calculate_currency( $currencycode, $ordertotaldiscounted, $wcusage_currency_conversion );
                        $affiliatecommission = wcusage_calculate_currency( $currencycode, $affiliatecommission, $wcusage_currency_conversion );
                        //$totalrefunds = wcusage_calculate_currency($currencycode, $totalrefunds, $wcusage_currency_conversion);
                        $totalorders = wcusage_calculate_currency( $currencycode, $totalorders, $wcusage_currency_conversion );
                        $totaldiscounts = wcusage_calculate_currency( $currencycode, $totaldiscounts, $wcusage_currency_conversion );
                        $totalordersexcl = wcusage_calculate_currency( $currencycode, $totalordersexcl, $wcusage_currency_conversion );
                        $totalcommission = wcusage_calculate_currency( $currencycode, $totalcommission, $wcusage_currency_conversion );
                        $fixed_order_commission = wcusage_calculate_currency( $currencycode, $fixed_order_commission, $wcusage_currency_conversion );
                    }
                }
                $allstats = [];
                $allstats['order'] = number_format(
                    (float) $ordertotal,
                    2,
                    '.',
                    ''
                );
                $allstats['discount'] = number_format(
                    (float) $totaldiscounts,
                    2,
                    '.',
                    ''
                );
                $all_commission = (float) $totalcommission + (float) $fixed_order_commission + (float) $fixed_product_commission_total;
                $max_commission = wcusage_get_setting_value( 'wcusage_field_order_max_commission', '' );
                if ( $max_commission ) {
                    if ( $all_commission > $max_commission ) {
                        $all_commission = $max_commission;
                    }
                }
                $allstats['commission'] = number_format(
                    (float) $all_commission,
                    2,
                    '.',
                    ''
                );
                $all_orderexcl = (float) $ordertotal - (float) $totaldiscounts;
                $allstats['orderexcl'] = number_format(
                    (float) $all_orderexcl,
                    2,
                    '.',
                    ''
                );
                if ( $save_order_commission_meta ) {
                    wcusage_update_order_meta( $orderid, 'wcusage_stats', $allstats );
                }
                $commission_summary = $wcusage_get_order_calculate_data['commission_summary'];
                $get_commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
                if ( !$get_commission_summary && $save_order_commission_meta ) {
                    wcusage_update_order_meta( $orderid, 'wcusage_commission_summary', $commission_summary );
                }
                // Return Values
                $return_array = [];
                $return_array['ordertotal'] = $ordertotal;
                $return_array['orderdiscount'] = $total_discount;
                $return_array['ordertotaldiscounted'] = $ordertotaldiscounted;
                $return_array['affiliatecommission'] = $affiliatecommission;
                $return_array['totalorders'] = $totalorders;
                $return_array['totaldiscounts'] = $totaldiscounts;
                $return_array['totalordersexcl'] = $totalordersexcl;
                $return_array['totalcommission'] = $all_commission;
                $return_array['commissionpercentage'] = $wcusage_get_order_calculate_data['option_affiliate'];
                $return_array['affiliate_commission_amount'] = $affiliate_commission_amount;
                $return_array['refund_commission_total'] = $refunds_totalcommission;
                $return_array['refund_commission_product'] = $refunds_fixed_product_commission_total;
                $return_array['refunded_qty'] = $refunded_qty;
                $return_array['fixed_order_commission'] = $fixed_order_commission;
                $return_array['commission_summary'] = $commission_summary;
            }
            // If order status is refunded return 0 values
            $order_status = "";
            if ( !empty( $order->get_status() ) ) {
                $order_status = $order->get_status();
            }
            if ( isset( $order_status ) && $order_status == "refunded" ) {
                $return_array = [];
                $return_array['ordertotal'] = 0;
                $return_array['orderdiscount'] = 0;
                $return_array['ordertotaldiscounted'] = 0;
                $return_array['affiliatecommission'] = 0;
                $return_array['totalorders'] = 0;
                $return_array['totaldiscounts'] = 0;
                $return_array['totalordersexcl'] = 0;
                $return_array['totalcommission'] = 0;
                $return_array['commissionpercentage'] = 0;
                $return_array['affiliate_commission_amount'] = 0;
                $return_array['refund_commission_total'] = 0;
                $return_array['refund_commission_product'] = 0;
                $return_array['refunded_qty'] = 0;
                $return_array['fixed_order_commission'] = 0;
                $return_array['commission_summary'] = [];
            }
            $return_array = apply_filters(
                'wcusage_get_calculate_order_data',
                $return_array,
                $orderid,
                $coupon_code
            );
            return $return_array;
        } else {
            $return_array = [];
            return $return_array;
        }
    }

}
/**
 * Loops through all orders for coupon and calculates order data including commission. Will only loop through data if $refresh true otherwise uses saved meta data.
 *
 * @param int $orderid
 * @param string $coupon_code
 * @param string $type
 * @param bool $refresh
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_get_order_calculate_data' ) ) {
    function wcusage_get_order_calculate_data(
        $orderid,
        $coupon_code,
        $type,
        $refresh,
        $force_update = "0"
    ) {
        if ( $refresh != "0" ) {
            $refresh = true;
        }
        $order = wc_get_order( $orderid );
        if ( !$order instanceof WC_Order ) {
            return array();
        }
        $options = get_option( 'wcusage_options' );
        $totalcommission = 0;
        $fixed_product_commission_total = 0;
        $totalrefunds = 0;
        $refunded_quantity = 0;
        $itemcount = 0;
        $this_quantity2 = 0;
        $fixed_order_commission = 0;
        $affiliate_commission_amount = 0;
        $this_line_total = 0;
        $this_line_subtotal = 0;
        $this_line_total_tax = 0;
        $return_array = [];
        $affiliatedone = false;
        $affiliatedone2 = false;
        $meta_data = [];
        $getcoupon = wcusage_get_coupon_info( $coupon_code );
        $user = get_userdata( $getcoupon[1] );
        $wcusage_show_commission_before_discount = wcusage_get_setting_value( 'wcusage_field_commission_before_discount', '0' );
        $wcusage_field_commission_before_discount_custom = wcusage_get_setting_value( 'wcusage_field_commission_before_discount_custom', '0' );
        $wcusage_field_commission_include_fees = wcusage_get_setting_value( 'wcusage_field_commission_include_fees', '0' );
        $save_order_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_order_commission_meta', '1' );
        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '' );
        $wcusage_show_tax_fixed = wcusage_get_setting_value( 'wcusage_field_show_tax_fixed', '0' );
        $taxpercent = wcusage_get_order_tax_percent( $orderid );
        $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
        // check if order status refunded
        $order_status = "";
        if ( !empty( $order->get_status() ) ) {
            $order_status = $order->get_status();
        }
        if ( $order_status == "refunded" && $force_update ) {
            // When order is refunded it needs to update commission amounts.
            $never_update_commission_meta = 0;
        }
        $affiliate_per_user = wcusage_get_setting_value( 'wcusage_field_affiliate_per_user', '0' );
        // ***** Get Affiliate Fixed Per Order Amount ***** //
        $fixed_order_commission = wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_order', '0' );
        // Overwrite with custom coupon amount
        $wcu_text_coupon_commission_fixed_order = get_post_meta( $getcoupon[2], 'wcu_text_coupon_commission_fixed_order', true );
        if ( $wcu_text_coupon_commission_fixed_order != "" && $wcu_text_coupon_commission_fixed_order >= 0 ) {
            $fixed_order_commission = $wcu_text_coupon_commission_fixed_order;
        }
        // Get Default Commission %
        $option_affiliate = wcusage_get_setting_value( 'wcusage_field_affiliate', '0' );
        $wcu_text_coupon_commission = get_post_meta( $getcoupon[2], 'wcu_text_coupon_commission', true );
        if ( $wcu_text_coupon_commission != "" && $wcu_text_coupon_commission >= 0 ) {
            $option_affiliate = $wcu_text_coupon_commission;
        }
        // Save Currency Conversion Rate?
        $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
        $priority_commission = wcusage_get_setting_value( 'wcusage_field_priority_commission', '' );
        // Check product priority
        $productpriority = false;
        if ( $priority_commission == "product" ) {
            $productpriority = true;
        } else {
            $productpriority = false;
        }
        // If order data type is refund
        if ( $order instanceof WC_Order ) {
            if ( $type == "refunds" ) {
                $order_type = $order->get_refunds();
            } else {
                $order_type = $order->get_items();
            }
        } else {
            $order_type = array();
        }
        if ( $type == "refunds" ) {
            foreach ( $order_type as $refund ) {
                foreach ( $refund->get_items() as $item_id => $item ) {
                    $refunded_quantity = $item->get_quantity();
                    // returns negative number e.g. -1
                    $refunded_quantity = substr( $refunded_quantity, 1 );
                    // trim the negative "-" from the string
                    if ( is_numeric( $refunded_quantity ) ) {
                        $totalrefunds += (float) $refunded_quantity;
                    }
                }
            }
            $refunded_quantity = substr( $refunded_quantity, 1 );
        }
        // Get Order Commission Meta
        $meta_total_commission = "";
        $meta_product_commission = "";
        if ( $type != "refunds" ) {
            $meta_total_commission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
            $meta_product_commission = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
        }
        $commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
        if ( empty( $commission_summary ) ) {
            $commission_summary = array();
        }
        if ( $type != "refunds" ) {
            $wcusage_field_enable_order_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_order_commission_meta', '1' );
            if ( $refresh || !isset( $commission_summary ) || empty( $commission_summary ) || $meta_total_commission == "" || $meta_product_commission == "" || $wcusage_field_enable_order_commission_meta == 0 ) {
                // ***** ORDER ITEMS LOOP - START ***** //
                foreach ( $order_type as $item_key => $item_values ) {
                    $item_data = $item_values->get_data();
                    if ( $item_key && $item_data ) {
                        $refunded_line_subtotal = 0;
                        $this_refunded_quantity = 0;
                        $this_total_refunded_quantity = 0;
                        $this_line_total_commission = 0;
                        if ( isset( $item_data['product_id'] ) ) {
                            $this_id = $item_data['product_id'];
                        } else {
                            $this_id = 0;
                        }
                        // Count this products refunds
                        foreach ( $order->get_refunds() as $refund ) {
                            foreach ( $refund->get_items() as $item_id => $item ) {
                                $this_refund_item_data = $item->get_data();
                                $this_refund_id = $this_refund_item_data['product_id'];
                                if ( $this_id == $this_refund_id ) {
                                    $this_refunded_total_tax = $this_refund_item_data['total_tax'];
                                    $this_refunded_total_tax = abs( $this_refunded_total_tax );
                                    $this_refunded_quantity = $this_refund_item_data['quantity'];
                                    $this_refunded_quantity = abs( $this_refunded_quantity );
                                    $this_total_refunded_quantity += $this_refunded_quantity;
                                    $refunded_line_subtotal += abs( $this_refund_item_data['total'] );
                                }
                            }
                        }
                        $this_total_refunded_quantity = $this_total_refunded_quantity;
                        if ( isset( $item_data['quantity'] ) ) {
                            $this_quantity2 = $item_data['quantity'] - $this_total_refunded_quantity;
                        }
                        //$this_quantity2 = $this_quantity2 - $this_total_refunded_quantity;
                        if ( isset( $item_data['subtotal_tax'] ) ) {
                            $item_subtotal_tax = $item_data['subtotal_tax'];
                        } else {
                            $item_subtotal_tax = "";
                        }
                        if ( isset( $item_data['total_tax'] ) ) {
                            $item_total_tax = $item_data['total_tax'];
                        } else {
                            $item_total_tax = "";
                        }
                        if ( isset( $item_data['total'] ) ) {
                            if ( $wcusage_show_tax == 1 ) {
                                $this_line_total = $item_data['total'] + $item_total_tax;
                            } else {
                                $this_line_total = $item_data['total'];
                            }
                        }
                        if ( isset( $item_data['subtotal'] ) ) {
                            if ( $wcusage_show_tax == 1 ) {
                                $this_line_subtotal = $item_data['subtotal'] + $item_subtotal_tax;
                            } else {
                                $this_line_subtotal = $item_data['subtotal'];
                            }
                        }
                        if ( $type == "refunds" && isset( $item_data['total_tax'] ) ) {
                            $this_line_total_tax = $item_data['total_tax'];
                        } else {
                            $this_line_total_tax = 0;
                        }
                        $wcu_text_coupon_commission_fixed_product = get_post_meta( $getcoupon[2], 'wcu_text_coupon_commission_fixed_product', true );
                        // Get Coupon Fixed Per Product
                        // Default Per Product Rates
                        $product_percent = get_post_meta( $this_id, 'wcu_product_commission_percent', true );
                        $fixed_product_commission = get_post_meta( $this_id, 'wcu_product_commission_fixed', true );
                        // Per Affiliate Product Rates
                        $product_per_user_rates = get_post_meta( $this_id, 'wcu_product_per_user_rates', true );
                        // check if $product_per_user_rates is array
                        if ( is_array( $product_per_user_rates ) ) {
                            foreach ( $product_per_user_rates as $product_per_user_rate ) {
                                if ( isset( $product_per_user_rate['type'] ) ) {
                                    $product_per_user_rates_type = $product_per_user_rate['type'];
                                } else {
                                    $product_per_user_rates_type = "coupon";
                                }
                                if ( !$product_per_user_rates_type || $product_per_user_rates_type == "coupon" ) {
                                    if ( $product_per_user_rate['affiliate'] == $coupon_code ) {
                                        if ( $product_per_user_rate['commission_percent'] != "" ) {
                                            $product_percent = $product_per_user_rate['commission_percent'];
                                        }
                                        if ( $product_per_user_rate['commission_fixed'] != "" ) {
                                            $fixed_product_commission = $product_per_user_rate['commission_fixed'];
                                        }
                                    }
                                }
                                if ( $product_per_user_rates_type == "user" ) {
                                    $username = $user->user_login;
                                    if ( $product_per_user_rate['affiliate'] == $username ) {
                                        if ( $product_per_user_rate['commission_percent'] != "" ) {
                                            $product_percent = $product_per_user_rate['commission_percent'];
                                        }
                                        if ( $product_per_user_rate['commission_fixed'] != "" ) {
                                            $fixed_product_commission = $product_per_user_rate['commission_fixed'];
                                        }
                                    }
                                }
                                if ( $product_per_user_rates_type == "role" ) {
                                    if ( $user && isset( $user->roles ) ) {
                                        $user_roles = $user->roles;
                                        if ( is_array( $user_roles ) || is_object( $user_roles ) ) {
                                            foreach ( $user_roles as $role ) {
                                                if ( $product_per_user_rate['affiliate'] == $role ) {
                                                    if ( $product_per_user_rate['commission_percent'] != "" ) {
                                                        $product_percent = $product_per_user_rate['commission_percent'];
                                                    }
                                                    if ( $product_per_user_rate['commission_fixed'] != "" ) {
                                                        $fixed_product_commission = $product_per_user_rate['commission_fixed'];
                                                    }
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        // Deduct custom percent
                        $deduct_percent = wcusage_get_setting_value( 'wcusage_field_affiliate_deduct_percent', '0' );
                        $deduct_percent = (100 - $deduct_percent) / 100;
                        // Get Commission Amount Percentage (Decimal)
                        if ( is_numeric( $option_affiliate ) ) {
                            $affiliate_commission_amount = $option_affiliate / 100;
                        } else {
                            $affiliate_commission_amount = 0;
                        }
                        // Get Line Totals
                        if ( !$this_line_total ) {
                            $this_line_total = 0;
                        }
                        if ( !$this_line_subtotal ) {
                            $this_line_subtotal = 0;
                        }
                        if ( !$refunded_line_subtotal ) {
                            $refunded_line_subtotal = 0;
                        }
                        $this_line_total = $this_line_total - $refunded_line_subtotal;
                        $this_line_subtotal = $this_line_subtotal - $refunded_line_subtotal;
                        // ***** Get Commission Percentage Amount ***** //
                        if ( $product_percent != "" && $product_percent >= 0 && $productpriority || $product_percent > 0 && $option_affiliate == "" ) {
                            // If product priority or product commission set but no other commmission options available.
                            $product_percent = (int) $product_percent;
                            $product_commission_amount = $product_percent / 100;
                            if ( $wcusage_show_commission_before_discount ) {
                                $this_line_total_commission += $this_line_subtotal * $deduct_percent * $product_commission_amount;
                            } else {
                                $this_line_total_commission += $this_line_total * $deduct_percent * $product_commission_amount;
                            }
                        } else {
                            // If per coupon commission priority
                            if ( $option_affiliate == "" ) {
                                $option_affiliate == 0;
                            }
                            if ( $wcusage_show_commission_before_discount ) {
                                if ( $this_line_subtotal ) {
                                    $this_line_total_commission += $this_line_subtotal * $deduct_percent * $affiliate_commission_amount;
                                }
                            } else {
                                if ( $this_line_total ) {
                                    $this_line_total_commission += $this_line_total * $deduct_percent * $affiliate_commission_amount;
                                }
                            }
                        }
                        $totalcommission += $this_line_total_commission;
                        $affiliate_commission_amount = 0;
                        // Reset Value
                        //  ***** Get Per Product Commission ***** //
                        if ( $fixed_product_commission != "" && $fixed_product_commission >= 0 && $productpriority || $fixed_product_commission > 0 && $wcu_text_coupon_commission_fixed_product == "" ) {
                            if ( $deduct_percent ) {
                                $fixed_product_commission = $fixed_product_commission * $deduct_percent;
                            }
                            $fixed_product_commission_total += $fixed_product_commission * $this_quantity2;
                        } else {
                            // Get default Fixed Per Product
                            $fixed_product_commission = wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_product', '0' );
                            // Overwrite with custom coupon amount
                            if ( $wcu_text_coupon_commission_fixed_product != "" && $wcu_text_coupon_commission_fixed_product >= 0 ) {
                                $fixed_product_commission = $wcu_text_coupon_commission_fixed_product;
                            }
                            // Update Total
                            if ( is_numeric( $fixed_product_commission ) && is_numeric( $this_quantity2 ) ) {
                                if ( $deduct_percent ) {
                                    $fixed_product_commission = $fixed_product_commission * $deduct_percent;
                                }
                                $fixed_product_commission_total += $fixed_product_commission * $this_quantity2;
                            }
                            $iscommissionproduct = true;
                        }
                        // Add tax to fixed commission amounts
                        if ( $wcusage_show_tax_fixed ) {
                            $fixed_product_commission_total_tax = $fixed_product_commission_total * wcusage_get_order_tax_percent( $orderid );
                            $fixed_product_commission_total += $fixed_product_commission_total_tax;
                            $fixed_order_commission_tax = $fixed_order_commission * wcusage_get_order_tax_percent( $orderid );
                            $fixed_order_commission += $fixed_order_commission_tax;
                        }
                        // Count Items
                        if ( is_numeric( $this_quantity2 ) ) {
                            $itemcount += $this_quantity2;
                        }
                    }
                    // Update Commission Summary
                    if ( $type != "refunds" ) {
                        $this_product_id = $item_data['product_id'];
                        if ( $this_quantity2 > 0 ) {
                            $this_product_total_combined_commission = $this_line_total_commission + $fixed_product_commission * $this_quantity2;
                            if ( $wcusage_show_tax_fixed ) {
                                $this_product_total_combined_commission = $this_product_total_combined_commission * wcusage_get_order_tax_percent( $orderid );
                            }
                            if ( !is_array( $commission_summary ) || empty( $commission_summary ) ) {
                                $commission_summary = array();
                            }
                            $commission_summary[$this_product_id]['subtotal'] = number_format(
                                (float) $this_line_subtotal,
                                4,
                                '.',
                                ''
                            );
                            $commission_summary[$this_product_id]['total'] = number_format(
                                (float) $this_line_total,
                                4,
                                '.',
                                ''
                            );
                            $this_line_discount = $this_line_subtotal - $this_line_total;
                            $commission_summary[$this_product_id]['discount'] = number_format(
                                (float) $this_line_discount,
                                4,
                                '.',
                                ''
                            );
                            if ( !isset( $commission_summary[$this_product_id]['commission'] ) || !$never_update_commission_meta ) {
                                $commission_summary[$this_product_id]['commission'] = number_format(
                                    (float) $this_product_total_combined_commission,
                                    4,
                                    '.',
                                    ''
                                );
                            }
                            $commission_summary[$this_product_id]['number'] = $this_quantity2;
                            $commission_summary[$this_product_id]['id'] = $item_data['product_id'];
                        }
                    }
                }
                // ***** Deduct Custom Discounts Commission ***** //
                $total_fees = wcusage_get_total_fees( $orderid );
                if ( is_numeric( $option_affiliate ) ) {
                    $affiliate_commission_amount = $option_affiliate / 100;
                } else {
                    $affiliate_commission_amount = 0;
                }
                // ***** Add Fees Commission ***** //
                $wcusage_field_commission_include_fees = wcusage_get_setting_value( 'wcusage_field_commission_include_fees', '0' );
                if ( $wcusage_field_commission_include_fees ) {
                    $fee_total_added = 0;
                    $fee_total_added = $total_fees['fee_total_add'];
                    $total_fees_add_commission = $fee_total_added * $affiliate_commission_amount;
                    $totalcommission = $totalcommission + $total_fees_add_commission;
                    if ( $wcusage_show_tax ) {
                        $total_fees_add_tax = $fee_total_added * wcusage_get_order_tax_percent( $orderid );
                        $total_fees_add_tax_commission = $total_fees_add_tax * $affiliate_commission_amount;
                        if ( $total_fees_add_tax_commission > 0 ) {
                            $totalcommission = $totalcommission + $total_fees_add_tax_commission;
                        }
                    }
                    // Update Commission Summary
                    if ( is_array( $commission_summary ) && $total_fees_add_commission > 0 ) {
                        $commission_summary['Fees']['commission'] = number_format(
                            (float) $total_fees_add_tax_commission,
                            4,
                            '.',
                            ''
                        );
                    } else {
                        $commission_summary['Fees']['commission'] = "0";
                    }
                }
                // ***** Remove custom discounts from total for commission calculations (if disabled) ***** //
                $total_fees_remove = $total_fees['fee_total_remove'];
                if ( !$wcusage_field_commission_before_discount_custom ) {
                    $total_fees_remove_commission = $total_fees_remove * $affiliate_commission_amount;
                    if ( $wcusage_show_tax ) {
                        $total_fees_remove_commission_tax = $total_fees_remove_commission * wcusage_get_order_tax_percent( $orderid );
                        $total_fees_remove_commission += $total_fees_remove_commission_tax;
                    }
                    $totalcommission = $totalcommission - $total_fees_remove_commission;
                    // Update Commission Summary
                    if ( is_array( $commission_summary ) ) {
                        $commission_summary['Custom Discounts']['commission'] = "-" . number_format(
                            (float) $total_fees_remove_commission,
                            4,
                            '.',
                            ''
                        );
                    }
                }
                // ***** Include Shipping In Commission If Enabled ***** //
                $commission_include_shipping = wcusage_get_setting_value( 'wcusage_field_commission_include_shipping', '0' );
                if ( $commission_include_shipping && $order->get_total_shipping() ) {
                    $include_shipping_tax = 0;
                    if ( $wcusage_show_tax ) {
                        $include_shipping_tax = $order->get_total_shipping() * wcusage_get_order_tax_percent( $orderid );
                    }
                    $included_shipping = $order->get_total_shipping() + $include_shipping_tax;
                    $included_shipping_commission = $included_shipping * $affiliate_commission_amount;
                    $totalcommission = $totalcommission + $included_shipping_commission;
                    // Update Commission Summary
                    if ( is_array( $commission_summary ) ) {
                        $commission_summary['Shipping']['commission'] = "-" . $included_shipping_commission;
                    }
                }
                // ***** Currency Convert ***** //
                if ( $order instanceof WC_Order ) {
                    $currencycode = $order->get_currency();
                    $wcusage_currency_conversion = wcusage_order_meta( $orderid, 'wcusage_currency_conversion', true );
                    if ( !$wcusage_currency_conversion || !$enable_save_rate ) {
                        $wcusage_currency_conversion = "";
                    }
                    $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
                    //$totalcommission = wcusage_calculate_currency($currencycode, $totalcommission, $wcusage_currency_conversion);
                    //$fixed_product_commission_total = wcusage_calculate_currency($currencycode, $fixed_product_commission_total, $wcusage_currency_conversion);
                    if ( $enable_save_rate && !$wcusage_currency_conversion ) {
                        $currency_rate = wcusage_get_currency_rate( $currencycode );
                        $meta_data['wcusage_currency_conversion'] = $currency_rate;
                    }
                }
                // ***** Check Max Commission ***** //
                $max_commission = wcusage_get_setting_value( 'wcusage_field_order_max_commission', '' );
                if ( $max_commission ) {
                    if ( $totalcommission > $max_commission ) {
                        $totalcommission = $max_commission;
                    }
                }
                // ***** Update Meta ***** //
                $meta_total_commission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
                $meta_product_commission = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
                $wcusage_field_enable_order_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_order_commission_meta', '1' );
                if ( $wcusage_field_enable_order_commission_meta ) {
                    if ( $type != "refunds" ) {
                        if ( $totalcommission || $meta_total_commission ) {
                            if ( $meta_total_commission == "" || !$never_update_commission_meta ) {
                                if ( $save_order_commission_meta ) {
                                    $meta_data['wcusage_total_commission'] = number_format(
                                        (float) $totalcommission,
                                        2,
                                        '.',
                                        ''
                                    );
                                }
                            }
                        }
                        if ( $fixed_product_commission_total || $meta_product_commission ) {
                            if ( $meta_product_commission == "" || !$never_update_commission_meta ) {
                                if ( $save_order_commission_meta ) {
                                    $meta_data['wcusage_product_commission'] = $fixed_product_commission_total;
                                }
                            }
                        }
                        if ( $save_order_commission_meta ) {
                            $meta_data['wcusage_commission_summary'] = $commission_summary;
                        }
                        $fixed_product_commission_total = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
                        if ( !$fixed_product_commission_total ) {
                            $fixed_product_commission_total = "0";
                        }
                    }
                } else {
                    if ( $meta_total_commission && !$never_update_commission_meta ) {
                        wcusage_delete_order_meta( $orderid, 'wcusage_total_commission' );
                    }
                    if ( $meta_product_commission && !$never_update_commission_meta ) {
                        wcusage_delete_order_meta( $orderid, 'wcusage_product_commission' );
                    }
                }
            } else {
                $totalcommission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
                $fixed_product_commission_total = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
                $commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
            }
            if ( $orderid ) {
                $wcusage_affiliate_user = wcusage_order_meta( $orderid, 'wcusage_affiliate_user', true );
                if ( $getcoupon[1] ) {
                    if ( $wcusage_affiliate_user != $getcoupon[1] ) {
                        $meta_data['wcusage_affiliate_user'] = $getcoupon[1];
                    }
                }
            }
        }
        if ( !empty( $meta_data ) ) {
            wcusage_update_order_meta_bulk( $orderid, $meta_data );
        }
        // Ensure we get current commission, no matter what, if never update meta enabled.
        $never_update_commission_meta = wcusage_get_setting_value( 'wcusage_field_enable_never_update_commission_meta', '0' );
        $current_commission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
        if ( $never_update_commission_meta && $current_commission ) {
            $current_totalcommission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
            $current_fixed_product_commission_total = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
            $current_commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
            $affiliate_commission_amount = wcusage_order_meta( $orderid, 'wcusage_affiliate_commission_amount', true );
            if ( $current_totalcommission ) {
                $totalcommission = wcusage_order_meta( $orderid, 'wcusage_total_commission', true );
            }
            if ( $current_fixed_product_commission_total ) {
                $fixed_product_commission_total = wcusage_order_meta( $orderid, 'wcusage_product_commission', true );
            }
            if ( $current_commission_summary ) {
                $commission_summary = wcusage_order_meta( $orderid, 'wcusage_commission_summary', true );
            }
        }
        $totalcommission = number_format(
            (float) $totalcommission,
            2,
            '.',
            ''
        );
        // Return Values
        $return_array['totalcommission'] = $totalcommission;
        $return_array['fixed_product_commission_total'] = $fixed_product_commission_total;
        $return_array['affiliate_commission_amount'] = $affiliate_commission_amount;
        // Commission Decimal %
        $return_array['refunded_qty'] = $totalrefunds;
        $return_array['fixed_order_commission'] = $fixed_order_commission;
        $return_array['option_affiliate'] = $option_affiliate;
        $return_array['commission_summary'] = $commission_summary;
        // If order status is refunded, return 0 values
        if ( $order_status == "refunded" ) {
            $return_array['totalcommission'] = 0;
            $return_array['fixed_product_commission_total'] = 0;
            $return_array['affiliate_commission_amount'] = 0;
            $return_array['fixed_order_commission'] = 0;
            $return_array['commission_summary'] = array();
        }
        return $return_array;
    }

}
/**
 * Calculates and converts the totals to base currency, based on order currency
 *
 * @param string $currency
 * @param string $coupon_code
 * @param string $type
 * @param bool $refresh
 *
 * @return int
 *
 */
if ( !function_exists( 'wcusage_calculate_currency' ) ) {
    function wcusage_calculate_currency(  $currency, $amount, $default  ) {
        // Calculate the new total for an amount based on currency code
        $options = get_option( 'wcusage_options' );
        $currencynumber = wcusage_get_setting_value( 'wcusage_field_currency_number', '5' );
        if ( !$default ) {
            for ($i = 1; $i <= $currencynumber; $i++) {
                $get_default_currency_settings = wcusage_get_default_currency_settings( $i );
                $wcusage_field_currency_name = $get_default_currency_settings['wcusage_field_currency_name'];
                $wcusage_field_currency_rate = $get_default_currency_settings['wcusage_field_currency_rate'];
                if ( $wcusage_field_currency_name == $currency && $wcusage_field_currency_rate ) {
                    $amount = (float) $amount * $wcusage_field_currency_rate;
                }
            }
        } else {
            $amount = (float) $amount * $default;
        }
        return $amount;
    }

}
/**
 * Gets the currency rate for currency code
 *
 * @param string $currency
 *
 * @return int
 *
 */
if ( !function_exists( 'wcusage_get_currency_rate' ) ) {
    function wcusage_get_currency_rate(  $currency  ) {
        // Get the set rates for currency code
        $options = get_option( 'wcusage_options' );
        $currencynumber = wcusage_get_setting_value( 'wcusage_field_currency_number', '5' );
        $rate = "";
        for ($i = 0; $i <= $currencynumber; $i++) {
            $get_default_currency_settings = wcusage_get_default_currency_settings( $i );
            $wcusage_field_currency_name = $get_default_currency_settings['wcusage_field_currency_name'];
            $wcusage_field_currency_rate = $get_default_currency_settings['wcusage_field_currency_rate'];
            if ( $wcusage_field_currency_name == $currency && $wcusage_field_currency_rate ) {
                $rate = $wcusage_field_currency_rate;
            }
        }
        return $rate;
    }

}
/**
 * Gets the default settings for each currency code, if not settings set.
 *
 * @param int $i
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_get_default_currency_settings' ) ) {
    function wcusage_get_default_currency_settings(  $i  ) {
        // Get the default settings for currency
        $return_array = [];
        $options = get_option( 'wcusage_options' );
        $defaultcurrency = get_woocommerce_currency();
        if ( isset( $options['wcusage_field_currencies'][$i]['name'] ) ) {
            $wcusage_field_currency_name = $options['wcusage_field_currencies'][$i]['name'];
        } else {
            $wcusage_field_currency_name = "";
        }
        if ( isset( $options['wcusage_field_currencies'][$i]['rate'] ) ) {
            $wcusage_field_currency_rate = $options['wcusage_field_currencies'][$i]['rate'];
        } else {
            $wcusage_field_currency_rate = "";
        }
        $defaultnum = 1;
        if ( $i == $defaultnum && !$wcusage_field_currency_name ) {
            $wcusage_field_currency_name = "USD";
        }
        if ( $wcusage_field_currency_name == "USD" && $defaultcurrency == "USD" && !$wcusage_field_currency_rate ) {
            $wcusage_field_currency_rate = 1;
        }
        $defaultnum++;
        if ( $i == $defaultnum && !$wcusage_field_currency_name ) {
            $wcusage_field_currency_name = "GBP";
        }
        if ( $wcusage_field_currency_name == "GBP" && $defaultcurrency == "GBP" && !$wcusage_field_currency_rate ) {
            $wcusage_field_currency_rate = 1;
        }
        $defaultnum++;
        if ( $i == $defaultnum && !$wcusage_field_currency_name ) {
            $wcusage_field_currency_name = "EUR";
        }
        if ( $wcusage_field_currency_name == "EUR" && $defaultcurrency == "EUR" && !$wcusage_field_currency_rate ) {
            $wcusage_field_currency_rate = 1;
        }
        $defaultnum++;
        if ( $i == $defaultnum && !$wcusage_field_currency_name ) {
            $wcusage_field_currency_name = "AUD";
        }
        if ( $wcusage_field_currency_name == "AUD" && $defaultcurrency == "AUD" && !$wcusage_field_currency_rate ) {
            $wcusage_field_currency_rate = 1;
        }
        $defaultnum++;
        if ( $i == $defaultnum && !$wcusage_field_currency_name ) {
            $wcusage_field_currency_name = "JPY";
        }
        if ( $wcusage_field_currency_name == "JPY" && $defaultcurrency == "JPY" && !$wcusage_field_currency_rate ) {
            $wcusage_field_currency_rate = 1;
        }
        $defaultnum++;
        $return_array['wcusage_field_currency_name'] = $wcusage_field_currency_name;
        $return_array['wcusage_field_currency_rate'] = $wcusage_field_currency_rate;
        return $return_array;
    }

}
/**
 * Gets the base currency symbol for the store
 *
 * @return string
 *
 */
if ( !function_exists( 'wcusage_get_base_currency_symbol' ) ) {
    function wcusage_get_base_currency_symbol() {
        // Gets the base store currency symbol
        $enablecurrency = wcusage_get_setting_value( 'wcusage_field_enable_currency', '0' );
        if ( $enablecurrency && class_exists( 'NumberFormatter' ) ) {
            $currency1 = get_option( 'woocommerce_currency' );
            $locale = get_locale();
            $formatter = new \NumberFormatter($locale . '@currency=' . $currency1, \NumberFormatter::CURRENCY);
            return $formatter->getSymbol( \NumberFormatter::CURRENCY_SYMBOL );
        } else {
            $currency1 = get_option( 'woocommerce_currency' );
            return get_woocommerce_currency_symbol( $currency1 );
        }
    }

}
/**
 * Formats the price properly based on WooCommerce settings
 * function code copied and modified version of wc_price() to only format in base store currency code
 *
 * @return string
 *
 */
if ( !function_exists( 'wcusage_format_price' ) ) {
    function wcusage_format_price(  $price, $args = array()  ) {
        $args = apply_filters( 'wc_price_args', wp_parse_args( $args, array(
            'ex_tax_label'       => false,
            'currency'           => get_option( 'woocommerce_currency' ),
            'decimal_separator'  => wc_get_price_decimal_separator(),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimals'           => wc_get_price_decimals(),
            'price_format'       => get_woocommerce_price_format(),
        ) ) );
        // Convert to float to avoid issues on PHP 8.
        $price = (float) $price;
        // if $price is int or float
        if ( $price ) {
            $price = round( $price, 2 );
        }
        $original_price = $price;
        $unformatted_price = $price;
        $negative = $price < 0;
        $price = apply_filters( 'raw_woocommerce_price', ( $negative ? $price * -1 : $price ), $original_price );
        $price = apply_filters(
            'formatted_woocommerce_price',
            number_format(
                (float) $price,
                $args['decimals'],
                $args['decimal_separator'],
                $args['thousand_separator']
            ),
            $price,
            $args['decimals'],
            $args['decimal_separator'],
            $args['thousand_separator'],
            $original_price
        );
        if ( apply_filters( 'woocommerce_price_trim_zeros', false ) && $args['decimals'] > 0 ) {
            $price = wc_trim_zeros( $price );
        }
        $formatted_price = (( $negative ? '-' : '' )) . sprintf( $args['price_format'], '<span class="woocommerce-Price-currencySymbol">' . wcusage_get_base_currency_symbol() . '</span>', $price );
        $return = '<span>' . $formatted_price . '</span>';
        if ( $args['ex_tax_label'] && wc_tax_enabled() ) {
            $return .= ' <small class="woocommerce-Price-taxLabel tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
        }
        return apply_filters(
            'wc_price',
            $return,
            $price,
            $args,
            $unformatted_price,
            $original_price
        );
    }

}
/**
 * Formats the price with on HTML
 *
 *
 */
function wcusage_format_price_plain(  $price  ) {
    $price = html_entity_decode( strip_tags( wc_price( $price ) ) );
    return $price;
}

/**
 * Gets  the total fees for order to add to affiliate dashboard and calculations, if certain certains enabled/disabled.
 *
 * @return array
 *
 */
if ( !function_exists( 'wcusage_get_order_tax_percent' ) ) {
    function wcusage_get_order_tax_percent(  $orderid  ) {
        $order = wc_get_order( $orderid );
        if ( $order ) {
            $theordertotal = $order->get_total();
            $theordertotaltax = $order->get_total_tax();
            if ( $theordertotaltax > 0 && $theordertotal > $theordertotaltax ) {
                $taxpercent = $theordertotaltax / ($theordertotal - $theordertotaltax);
            } else {
                $taxpercent = 0;
            }
        } else {
            $taxpercent = 0;
        }
        return $taxpercent;
    }

}
/**
 * Gets the total fees for order to add to affiliate dashboard and calculations, if certain certains enabled/disabled.
 *
 * @return array
 *
 */
if ( !function_exists( 'wcusage_get_total_fees' ) ) {
    function wcusage_get_total_fees(  $orderid  ) {
        $order = wc_get_order( $orderid );
        $taxpercent = wcusage_get_order_tax_percent( $orderid );
        $fee_total_remove = 0;
        $fee_total_add = 0;
        if ( $order instanceof WC_Order ) {
            foreach ( $order->get_items( 'fee' ) as $item_id => $item_fee ) {
                // Negative Fees (Extra Discounts)
                if ( $item_fee->get_total() <= 0 ) {
                    $fee_total_remove += $item_fee->get_total();
                }
                // Positive Fees
                if ( $item_fee->get_total() >= 0 ) {
                    $fee_total_add += $item_fee->get_total();
                }
            }
        }
        $fee_total_remove = abs( $fee_total_remove );
        $fee_total_add = abs( $fee_total_add );
        $return_array = [];
        $return_array['fee_total_remove'] = $fee_total_remove;
        $return_array['fee_total_add'] = $fee_total_add;
        return $return_array;
    }

}
/**
 * Gets the total tax that should be removed from order total in statistics (due to calculation statistics settings).
 *
 * @return array
 *
 */
if ( !function_exists( 'wcusage_get_tax_to_remove' ) ) {
    function wcusage_get_tax_to_remove(  $orderid  ) {
        $order = wc_get_order( $orderid );
        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '0' );
        if ( $wcusage_show_tax ) {
            // Get Tax To Remove
            $wcusage_field_commission_include_shipping = wcusage_get_setting_value( 'wcusage_field_commission_include_shipping', '0' );
            $shipping_tax = 0;
            if ( !$wcusage_field_commission_include_shipping ) {
                $shipping = $order->get_total_shipping();
                $shipping_tax = $shipping * wcusage_get_order_tax_percent( $orderid );
            }
            $wcusage_field_commission_include_fees = wcusage_get_setting_value( 'wcusage_field_commission_include_fees', '0' );
            $fees_tax = 0;
            if ( !$wcusage_field_commission_include_fees ) {
                $total_fees = wcusage_get_total_fees( $orderid );
                $fee_total_added = $total_fees['fee_total_add'];
                $fees_tax = $fee_total_added * wcusage_get_order_tax_percent( $orderid );
            }
            $remove_tax = $shipping_tax + $fees_tax;
        } else {
            $remove_tax = 0;
        }
        return $remove_tax;
    }

}