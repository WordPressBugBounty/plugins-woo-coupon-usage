<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Forces all stats to be refreshed
 *
 * @param string $coupon_code
 *
 */
if ( !function_exists( 'wcusage_update_all_stats' ) ) {
    function wcusage_update_all_stats(  $coupon_code, $force = 0  ) {
        $wcusage_field_enable_coupon_all_stats_meta = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
        if ( $wcusage_field_enable_coupon_all_stats_meta ) {
            $fullorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                "",
                date( "Y-m-d" ),
                '',
                1,
                1,
                1
            );
        } else {
            $fullorders = "";
        }
        return $fullorders;
    }

}
add_action(
    'wcusage_hook_update_all_stats',
    'wcusage_update_all_stats',
    10,
    2
);
/**
 * Updates all stats for a coupon by adding/removing values from a single order
 *
 * @param string $coupon_code
 * @param int $order_id
 * @param bool $type - If add or remove order from stats
 * @param bool $change - If the usage should be changed.
 *
 */
if ( !function_exists( 'wcusage_update_all_stats_single' ) ) {
    function wcusage_update_all_stats_single(
        $coupon_code,
        $order_id,
        $type,
        $change,
        $update = 1
    ) {
        $order = wc_get_order( $order_id );
        $coupon_code = strtolower( $coupon_code );
        $couponinfo = wcusage_get_coupon_info( $coupon_code );
        $wcu_alltime_stats = get_post_meta( $couponinfo[2], 'wcu_alltime_stats', true );
        if ( !$wcu_alltime_stats ) {
            // On first order, set alltime stats to 0 so it can be updated
            global $woocommerce;
            $c = new WC_Coupon($coupon_code);
            $usage = $c->get_usage_count();
            if ( $usage <= 1 ) {
                $wcu_alltime_stats = array();
                $wcu_alltime_stats['total_orders'] = 0;
                $wcu_alltime_stats['full_discount'] = 0;
                $wcu_alltime_stats['total_commission'] = 0;
                $wcu_alltime_stats['total_shipping'] = 0;
                $wcu_alltime_stats['total_count'] = 0;
                $wcu_alltime_stats['commission_summary'] = array();
                update_post_meta( $couponinfo[2], 'wcu_alltime_stats', $wcu_alltime_stats );
            }
        }
        if ( $wcu_alltime_stats ) {
            // Get Current Values
            $total_orders = 0;
            if ( isset( $wcu_alltime_stats['total_orders'] ) ) {
                $total_orders = $wcu_alltime_stats['total_orders'];
            }
            $total_discount = 0;
            if ( isset( $wcu_alltime_stats['full_discount'] ) ) {
                $total_discount = $wcu_alltime_stats['full_discount'];
            }
            $total_commission = 0;
            if ( isset( $wcu_alltime_stats['total_commission'] ) ) {
                $total_commission = $wcu_alltime_stats['total_commission'];
            }
            $total_count = 0;
            if ( isset( $wcu_alltime_stats['total_count'] ) ) {
                $total_count = $wcu_alltime_stats['total_count'];
            }
            // Get Order Values
            if ( $update ) {
                $order_data = wcusage_calculate_order_data(
                    $order_id,
                    $coupon_code,
                    1,
                    0,
                    1
                );
            } else {
                $order_data = wcusage_calculate_order_data(
                    $order_id,
                    $coupon_code,
                    0,
                    1,
                    0
                );
            }
            $order_total = ( isset( $order_data['totalorders'] ) ? $order_data['totalorders'] : 0 );
            $order_discounts = ( isset( $order_data['totaldiscounts'] ) ? $order_data['totaldiscounts'] : 0 );
            $order_commission = ( isset( $order_data['totalcommission'] ) ? $order_data['totalcommission'] : 0 );
            // By the time an order is removed from the stats its status has usually already
            // changed to cancelled/refunded/failed, and wcusage_calculate_order_data() returns
            // zero for those statuses - so there was nothing to subtract, and the order's sales
            // and commission stayed in the all-time totals for good while only the usage count
            // came back down. Fall back to the order's own saved stats, which hold the amounts
            // that were added in the first place.
            //
            // Gated on 'wcusage_all_updated', which marks an order as currently counted. The
            // matching $type=1 branch has nothing to fall back to - it must keep adding zero
            // for a status that does not count - so without this gate the two directions are
            // asymmetric, and any remove-then-add on an order that is already cancelled or
            // refunded (changing the affiliate on the order edit screen, or the Bulk Assign
            // Orders tool) would subtract amounts that are no longer in the totals and add
            // nothing back. The flag is deliberately cleared only after the remove has run -
            // see wcusage_new_order_update_stats() and wcusage_order_update_stats_refund().
            if ( !$type && !(float) $order_total && !(float) $order_commission && wcusage_order_meta( $order_id, 'wcusage_all_updated' ) ) {
                $saved_stats = wcusage_order_meta( $order_id, 'wcusage_stats', true );
                if ( is_array( $saved_stats ) ) {
                    if ( isset( $saved_stats['order'] ) ) {
                        $order_total = $saved_stats['order'];
                    }
                    if ( isset( $saved_stats['discount'] ) ) {
                        $order_discounts = $saved_stats['discount'];
                    }
                    if ( isset( $saved_stats['commission'] ) ) {
                        $order_commission = $saved_stats['commission'];
                    }
                }
            }
            // Update
            $allstats = array();
            if ( $type ) {
                $allstats['total_orders'] = $total_orders + $order_total;
                $allstats['full_discount'] = $total_discount + $order_discounts;
                $allstats['total_commission'] = $total_commission + $order_commission;
                if ( $change ) {
                    $allstats['total_count'] = $total_count + 1;
                } else {
                    $allstats['total_count'] = $total_count;
                }
            } else {
                // Floored at zero, the same as the usage count below - an all-time total can
                // never legitimately be negative, and rounding differences between what was
                // added and what is subtracted could otherwise leave a small minus figure.
                $allstats['total_orders'] = max( 0, (float) $total_orders - (float) $order_total );
                $allstats['full_discount'] = max( 0, (float) $total_discount - (float) $order_discounts );
                $allstats['total_commission'] = max( 0, (float) $total_commission - (float) $order_commission );
                if ( $change ) {
                    $allstats['total_count'] = max( 0, $total_count - 1 );
                } else {
                    $allstats['total_count'] = $total_count;
                }
            }
            update_post_meta( $couponinfo[2], 'wcu_alltime_stats', $allstats );
            do_action( 'wcusage_hook_after_update_stats_single', $order, $couponinfo[2] );
        }
        // Reset Monthly Summary Data For This Orders Month
        do_action( 'wcusage_hook_reset_order_stats_month', $order, $couponinfo[2] );
    }

}
add_action(
    'wcusage_hook_update_all_stats_single',
    'wcusage_update_all_stats_single',
    10,
    4
);
/*
* Run wcusage_hook_reset_order_stats_month on order completed
*
* @param int $order_id
*
*/
function wcusage_reset_order_stats_month_on_order_completed(  $order_id  ) {
    $order = wc_get_order( $order_id );
    $coupons = $order->get_items( 'coupon' );
    if ( $coupons ) {
        foreach ( $coupons as $coupon ) {
            $coupon_code = $coupon->get_code();
            $couponinfo = wcusage_get_coupon_info( $coupon_code );
            do_action( 'wcusage_hook_reset_order_stats_month', $order, $couponinfo[2] );
        }
    }
}

/*
* Updates the monthly stats for a coupon based on order
*
* @param string $coupon_code
* @param int $order_id
*
*/
function wcusage_reset_order_stats_month(  $order, $coupon_id  ) {
    // Check valid order
    if ( !$order ) {
        return;
    }
    // Check valid coupon
    if ( !$coupon_id ) {
        return;
    }
    // Reset Monthly Summary Data For This Orders Month
    $wcusage_field_order_sort = wcusage_get_setting_value( 'wcusage_field_order_sort', 'paiddate' );
    if ( $wcusage_field_order_sort == "paiddate" ) {
        $order_date = $order->get_date_created();
    } else {
        $order_date = $order->get_date_completed();
    }
    $order_date = date( 'Y-m-01', strtotime( $order_date ) );
    $wcusage_monthly_summary_data = get_post_meta( $coupon_id, 'wcusage_monthly_summary_data', true );
    if ( !empty( $wcusage_monthly_summary_data ) ) {
        $wcusage_monthly_summary_data[strtotime( $order_date )] = "";
        update_post_meta( $coupon_id, 'wcusage_monthly_summary_data', $wcusage_monthly_summary_data );
    }
    $wcusage_monthly_summary_data_orders = get_post_meta( $coupon_id, 'wcusage_monthly_summary_data_orders', true );
    if ( !empty( $wcusage_monthly_summary_data_orders ) ) {
        $wcusage_monthly_summary_data_orders[strtotime( $order_date )] = "";
        update_post_meta( $coupon_id, 'wcusage_monthly_summary_data_orders', $wcusage_monthly_summary_data_orders );
    }
    // Clear the current month cache TTL so statistics tab re-queries fresh data
    delete_post_meta( $coupon_id, 'wcusage_monthly_cache_time_current' );
}

add_action(
    'wcusage_hook_reset_order_stats_month',
    'wcusage_reset_order_stats_month',
    10,
    2
);
/**
 * Sanitize a set of all-time statistics received from a request.
 *
 * @param mixed $stats
 *
 * @return array
 *
 */
function wcusage_sanitize_alltime_stats(  $stats  ) {
    if ( !is_array( $stats ) ) {
        $stats = array();
    }
    $clean = array(
        'total_orders'       => ( isset( $stats['total_orders'] ) ? floatval( $stats['total_orders'] ) : 0 ),
        'full_discount'      => ( isset( $stats['full_discount'] ) ? floatval( $stats['full_discount'] ) : 0 ),
        'total_commission'   => ( isset( $stats['total_commission'] ) ? floatval( $stats['total_commission'] ) : 0 ),
        'total_shipping'     => ( isset( $stats['total_shipping'] ) ? floatval( $stats['total_shipping'] ) : 0 ),
        'total_count'        => ( isset( $stats['total_count'] ) ? floatval( $stats['total_count'] ) : 0 ),
        'commission_summary' => array(),
    );
    if ( isset( $stats['commission_summary'] ) && is_array( $stats['commission_summary'] ) ) {
        foreach ( $stats['commission_summary'] as $key => $value ) {
            if ( !is_array( $value ) && !is_object( $value ) ) {
                continue;
            }
            $value = (array) $value;
            $clean['commission_summary'][sanitize_text_field( $key )] = array(
                'total'      => ( isset( $value['total'] ) ? floatval( $value['total'] ) : 0 ),
                'commission' => ( isset( $value['commission'] ) ? floatval( $value['commission'] ) : 0 ),
                'number'     => ( isset( $value['number'] ) ? intval( $value['number'] ) : 0 ),
            );
        }
    }
    return $clean;
}

/**
 * Add two sets of all-time statistics together.
 *
 * @param mixed $a
 * @param mixed $b
 *
 * @return array
 *
 */
function wcusage_merge_alltime_stats(  $a, $b  ) {
    $a = wcusage_sanitize_alltime_stats( $a );
    $b = wcusage_sanitize_alltime_stats( $b );
    $merged = array(
        'total_orders'       => $a['total_orders'] + $b['total_orders'],
        'full_discount'      => $a['full_discount'] + $b['full_discount'],
        'total_commission'   => $a['total_commission'] + $b['total_commission'],
        'total_shipping'     => $a['total_shipping'] + $b['total_shipping'],
        'total_count'        => $a['total_count'] + $b['total_count'],
        'commission_summary' => $a['commission_summary'],
    );
    foreach ( $b['commission_summary'] as $key => $value ) {
        if ( isset( $merged['commission_summary'][$key] ) ) {
            $merged['commission_summary'][$key]['total'] += $value['total'];
            $merged['commission_summary'][$key]['commission'] += $value['commission'];
            $merged['commission_summary'][$key]['number'] += $value['number'];
        } else {
            $merged['commission_summary'][$key] = $value;
        }
    }
    return $merged;
}

/**
 * Whether a full refresh should recalculate the "processing" commission total.
 *
 * Only true when the pending commission feature is available and switched on.
 * Otherwise nothing accumulates during a refresh and the stored value (which
 * can be set by hand in the admin) must be left exactly as it is.
 *
 * @return bool
 *
 */
function wcusage_refresh_recalculates_pending() {
    if ( !function_exists( 'wcusage_check_and_add_pending_commission' ) ) {
        return false;
    }
    return (bool) wcusage_get_setting_value( 'wcusage_field_payout_pending_enable', '1' );
}

/**
 * Start recalculating the "processing" commission total for a refresh run.
 *
 * The total is rebuilt in a temporary meta key so the value the affiliate sees
 * keeps working while the run is going. If the run never finishes, nothing has
 * been lost.
 *
 * @param int $coupon_id
 *
 * @return void
 *
 */
function wcusage_begin_refresh_pending(  $coupon_id  ) {
    if ( !$coupon_id || !wcusage_refresh_recalculates_pending() ) {
        return;
    }
    update_post_meta( $coupon_id, 'wcu_text_pending_order_commission_refresh', 0 );
}

/**
 * Swap the recalculated "processing" commission total into place.
 *
 * Called once a refresh run has completed. When no run is in progress there is
 * no temporary total and the live value is left untouched.
 *
 * @param int $coupon_id
 *
 * @return void
 *
 */
function wcusage_commit_refresh_pending(  $coupon_id  ) {
    if ( !$coupon_id || !wcusage_refresh_recalculates_pending() ) {
        return;
    }
    $pending = get_post_meta( $coupon_id, 'wcu_text_pending_order_commission_refresh', true );
    if ( $pending !== '' && $pending !== null ) {
        update_post_meta( $coupon_id, 'wcu_text_pending_order_commission', round( (float) $pending, 2 ) );
    }
    delete_post_meta( $coupon_id, 'wcu_text_pending_order_commission_refresh' );
}

/**
 * Persist a completed set of all-time statistics for a coupon.
 *
 * This is the only place a refresh run writes its result, so the previous
 * statistics stay in place until a run has actually finished.
 *
 * @param int   $coupon_id
 * @param mixed $stats
 *
 * @return array The saved statistics.
 *
 */
function wcusage_save_alltime_stats(  $coupon_id, $stats  ) {
    $allstats = wcusage_sanitize_alltime_stats( $stats );
    if ( !$coupon_id ) {
        return $allstats;
    }
    update_post_meta( $coupon_id, 'wcu_alltime_stats', $allstats );
    update_post_meta( $coupon_id, 'wcu_last_refreshed', time() );
    // The run is done, so the saved resume point is no longer needed.
    delete_post_meta( $coupon_id, 'wcu_alltime_stats_progress' );
    // Move the recalculated processing commission into place.
    wcusage_commit_refresh_pending( $coupon_id );
    delete_post_meta( $coupon_id, 'wcusage_monthly_summary_data' );
    delete_post_meta( $coupon_id, 'wcusage_monthly_summary_data_orders' );
    delete_post_meta( $coupon_id, 'wcusage_monthly_cache_time_current' );
    return $allstats;
}

/**
 * Store the running totals of an in-progress refresh.
 *
 * The browser sends the totals it has accumulated so far together with the
 * index of the window it just asked for. Saving them means a run that is
 * interrupted (a timeout, or the affiliate closing the tab) carries on from
 * the same point next time instead of recalculating every order again.
 *
 * @param int   $coupon_id
 * @param mixed $batch_stats Stats for the window that just completed.
 *
 * @return void
 *
 */
function wcusage_save_refresh_progress(  $coupon_id, $batch_stats  ) {
    if ( !$coupon_id ) {
        return;
    }
    $index = ( isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1 );
    $signature = ( isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '' );
    if ( $index < 0 || !$signature ) {
        return;
    }
    $running = ( isset( $_POST['stats'] ) && is_array( $_POST['stats'] ) ? wp_unslash( $_POST['stats'] ) : array() );
    update_post_meta( $coupon_id, 'wcu_alltime_stats_progress', array(
        'signature' => $signature,
        'index'     => $index + 1,
        'stats'     => wcusage_merge_alltime_stats( $running, $batch_stats ),
        'time'      => time(),
    ) );
}

/**
 * Updates all stats for a coupon on specific day.
 */
function wcusage_get_orders_by_coupon_ajax() {
    check_ajax_referer( 'wcusage_update_stats_nonce', 'security' );
    // Require logged-in user
    if ( !is_user_logged_in() ) {
        wp_send_json_error( esc_html__( 'You must be logged in.', 'woo-coupon-usage' ) );
    }
    $coupon_code = ( isset( $_POST['coupon_code'] ) ? sanitize_text_field( $_POST['coupon_code'] ) : '' );
    $startdate = ( isset( $_POST['start'] ) ? sanitize_text_field( $_POST['start'] ) : '' );
    $enddate = ( isset( $_POST['end'] ) ? sanitize_text_field( $_POST['end'] ) : '' );
    // Check access: the coupon must belong to the current user (or an MLA parent / admin)
    $coupon = wcusage_get_coupon_info( $coupon_code );
    $coupon_user_id = intval( $coupon[1] );
    $currentuserid = get_current_user_id();
    $sub_affiliate = false;
    // Check access (strict comparison to prevent type juggling)
    if ( $coupon_user_id !== $currentuserid && !$sub_affiliate && !wcusage_check_admin_access() ) {
        wp_send_json_error( esc_html__( 'You do not have permission to access this data.', 'woo-coupon-usage' ) );
        wp_die();
    }
    // Build the processing commission total in a temporary key while the run is
    // going, so the live value is only replaced once every window has completed.
    $recalculate_pending = wcusage_refresh_recalculates_pending();
    if ( $recalculate_pending ) {
        $GLOBALS['wcusage_pending_refresh_key'] = 'wcu_text_pending_order_commission_refresh';
    }
    $fullorders = wcusage_wh_getOrderbyCouponCode(
        $coupon_code,
        $startdate,
        $enddate,
        '',
        1,
        1,
        1
    );
    if ( $recalculate_pending ) {
        unset($GLOBALS['wcusage_pending_refresh_key']);
    }
    $allstats = ( isset( $fullorders['allstats'] ) && is_array( $fullorders['allstats'] ) ? $fullorders['allstats'] : array() );
    // Remember how far the run has got so it can be resumed if interrupted.
    wcusage_save_refresh_progress( $coupon[2], $allstats );
    echo json_encode( $allstats );
    wp_die();
}

add_action( 'wp_ajax_wcusage_get_orders_by_coupon_ajax', 'wcusage_get_orders_by_coupon_ajax' );
/**
 * Updates all stats for a coupon
 */
function wcusage_update_all_stats_data() {
    check_ajax_referer( 'wcusage_update_stats_nonce', 'security' );
    // Require logged-in user
    if ( !is_user_logged_in() ) {
        wp_send_json_error( esc_html__( 'You must be logged in.', 'woo-coupon-usage' ) );
    }
    $options = get_option( 'wcusage_options' );
    $stats = ( isset( $_POST['stats'] ) && is_array( $_POST['stats'] ) ? wp_unslash( $_POST['stats'] ) : array() );
    $coupon_code = ( isset( $_POST['coupon_code'] ) ? sanitize_text_field( $_POST['coupon_code'] ) : '' );
    $coupon = wcusage_get_coupon_info( $coupon_code );
    $coupon_user_id = intval( $coupon[1] );
    $coupon_id = $coupon[2];
    $currentuserid = get_current_user_id();
    // Check MLA sub-affiliate
    $sub_affiliate = false;
    // Check access (strict comparison to prevent type juggling)
    if ( $coupon_user_id !== $currentuserid && !$sub_affiliate && !wcusage_check_admin_access() ) {
        wp_send_json_error( esc_html__( 'You do not have permission to access this data.', 'woo-coupon-usage' ) );
        wp_die();
    }
    // Save the completed run (this is the only point the stored statistics for
    // the coupon are replaced).
    $allstats = wcusage_save_alltime_stats( $coupon_id, $stats );
    echo json_encode( $allstats );
    wp_die();
}

add_action( 'wp_ajax_wcusage_update_all_stats_data', 'wcusage_update_all_stats_data' );
/**
 * Build the list of date windows a full statistics refresh needs to walk.
 *
 * Windows are sized by ORDER COUNT rather than by a fixed number of days, so
 * the number of requests scales with how many orders a coupon actually has
 * instead of how long its history is. Days with no orders are skipped, and a
 * single day is never split across two windows, so no order can be missed or
 * counted twice.
 *
 * The order statuses and the date column match wcusage_wh_getOrderbyCouponCode()
 * exactly, so every window that is generated returns orders when it is queried.
 *
 * @param string $coupon_code
 *
 * @return array List of array( 'start' => 'Y-m-d', 'end' => 'Y-m-d', 'orders' => int )
 *
 */
function wcusage_get_refresh_date_windows(  $coupon_code  ) {
    global $wpdb;
    $coupon_code = strtolower( sanitize_text_field( $coupon_code ) );
    $coupon_info = wcusage_get_coupon_info( $coupon_code );
    // The same statuses wcusage_wh_getOrderbyCouponCode() counts. Using a wider
    // set here would create windows that return nothing when they are queried.
    $wcusage_field_order_type_custom = wcusage_get_setting_value( 'wcusage_field_order_type_custom', '' );
    if ( !$wcusage_field_order_type_custom ) {
        $wcusage_field_order_type = wcusage_get_setting_value( 'wcusage_field_order_type', '' );
        if ( $wcusage_field_order_type == 'completed' ) {
            $statuses = array(
                'wc-completed' => 'Completed',
            );
        } else {
            $statuses = array(
                'wc-completed'  => 'Completed',
                'wc-processing' => 'Processing',
            );
        }
    } else {
        $statuses = $wcusage_field_order_type_custom;
    }
    if ( empty( $statuses ) ) {
        return array();
    }
    // Custom Orders Table or Posts Table
    $order_util_class = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
    if ( class_exists( $order_util_class ) && method_exists( $order_util_class, 'custom_orders_table_usage_is_enabled' ) && call_user_func( array($order_util_class, 'custom_orders_table_usage_is_enabled') ) ) {
        $id = "id";
        $posts = "wc_orders";
        $postmeta = "wc_orders_meta";
        $post_date = "date_created_gmt";
        $post_status = "status";
        $post_id = "order_id";
    } else {
        $id = "ID";
        $posts = "posts";
        $postmeta = "postmeta";
        $post_date = "post_date_gmt";
        $post_status = "post_status";
        $post_id = "post_id";
    }
    // Query to get orders
    $query = $wpdb->prepare(
        "SELECT DISTINCT p." . $id . " AS order_id, p." . $post_date . " AS order_date\r\n      FROM {$wpdb->prefix}" . $posts . " AS p\r\n      LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS woi\r\n        ON p." . $id . " = woi.order_id AND woi.order_item_type = 'coupon' AND woi.order_item_name = %s\r\n      LEFT JOIN {$wpdb->prefix}" . $postmeta . " AS woi2\r\n        ON p." . $id . " = woi2." . $post_id . " AND (\r\n          (woi2.meta_key = 'lifetime_affiliate_coupon_referrer' AND woi2.meta_value = %s) OR\r\n          (woi2.meta_key = 'wcusage_referrer_coupon' AND woi2.meta_value = %s)\r\n        )\r\n      WHERE p." . $post_status . " IN ('" . implode( "','", array_keys( $statuses ) ) . "')\r\n      AND (woi.order_id IS NOT NULL OR woi2.meta_value = %s AND woi2.meta_key IS NOT NULL)",
        $coupon_code,
        $coupon_code,
        $coupon_code,
        $coupon_code
    );
    // Count the orders per day. The stored dates are GMT while the batches are
    // given local dates (wcusage_convert_date_to_gmt() converts them back with
    // the same fixed offset), so shift by that offset to group by local day.
    $offset_seconds = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
    $day_query = "SELECT DATE(sub.order_date + INTERVAL " . $offset_seconds . " SECOND) AS order_day, COUNT(*) AS orders\r\n      FROM (" . $query . ") AS sub\r\n      GROUP BY order_day\r\n      ORDER BY order_day ASC";
    $days = $wpdb->get_results( $day_query );
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL
    if ( empty( $days ) ) {
        return array();
    }
    // Orders before the coupon start date are excluded by the batch query, so
    // skip those days instead of spending a request on them.
    $min_day = '';
    $wcu_text_coupon_start_date = get_post_meta( $coupon_info[2], 'wcu_text_coupon_start_date', true );
    if ( $wcu_text_coupon_start_date ) {
        $min_day = gmdate( 'Y-m-d', strtotime( $wcu_text_coupon_start_date ) );
    }
    // Orders per request (wcusage_field_enable_coupon_all_stats_batch_amount)
    $batch_amount = intval( wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_batch_amount', '50' ) );
    if ( $batch_amount < 1 ) {
        $batch_amount = 50;
    }
    $windows = array();
    $current = null;
    foreach ( $days as $day ) {
        if ( empty( $day->order_day ) ) {
            continue;
        }
        if ( $min_day && $day->order_day < $min_day ) {
            continue;
        }
        if ( $current === null ) {
            $current = array(
                'start'  => $day->order_day,
                'end'    => $day->order_day,
                'orders' => 0,
            );
        }
        $current['end'] = $day->order_day;
        $current['orders'] += intval( $day->orders );
        if ( $current['orders'] >= $batch_amount ) {
            $windows[] = $current;
            $current = null;
        }
    }
    if ( $current !== null ) {
        $windows[] = $current;
    }
    // When the all-time stats are hidden there is no point walking the whole
    // history, so only the most recent orders are calculated.
    $wcusage_hide_all_time = wcusage_get_setting_value( 'wcusage_field_hide_all_time', '0' );
    if ( $wcusage_hide_all_time && count( $windows ) > 1 ) {
        $windows = array(end( $windows ));
    }
    return $windows;
}

/**
 * Updates all stats for a coupon in batches via ajax
 */
function wcusage_update_all_stats_batch_ajax(  $coupon_code, $the_coupon_usage  ) {
    $coupon_code = sanitize_text_field( $coupon_code );
    $ajaxerrormessage = wcusage_ajax_error();
    $coupon_info = wcusage_get_coupon_info( $coupon_code );
    $post_id = $coupon_info[2];
    // The work to do, split into windows of roughly equal order counts.
    $windows = wcusage_get_refresh_date_windows( $coupon_code );
    // Identifies this exact set of work. If anything changes the windows (a new
    // order, a changed setting), a part-finished run is no longer resumable.
    $signature = md5( wp_json_encode( $windows ) );
    // Pick up where an interrupted run stopped, when it was for the same work.
    $start_index = 0;
    $start_stats = array();
    $progress = get_post_meta( $post_id, 'wcu_alltime_stats_progress', true );
    if ( is_array( $progress ) && isset( $progress['signature'], $progress['index'], $progress['time'] ) && $progress['signature'] === $signature && intval( $progress['index'] ) > 0 && intval( $progress['index'] ) <= count( $windows ) && time() - intval( $progress['time'] ) < HOUR_IN_SECONDS ) {
        $start_index = intval( $progress['index'] );
        $start_stats = ( isset( $progress['stats'] ) ? $progress['stats'] : array() );
    }
    $start_stats = wcusage_sanitize_alltime_stats( $start_stats );
    // Start a fresh run. Note that the existing statistics are deliberately NOT
    // deleted here — they stay in place until a run completes, so an
    // interrupted refresh can never leave the coupon with no statistics at all.
    if ( !$start_index && !empty( $windows ) ) {
        wcusage_begin_refresh_pending( $post_id );
    }
    // Only the dates are needed in the browser.
    $windows_js = array();
    foreach ( $windows as $window ) {
        $windows_js[] = array($window['start'], $window['end']);
    }
    // Force an object so the commission summary keys survive the round trip.
    $start_stats_js = $start_stats;
    $start_stats_js['commission_summary'] = (object) $start_stats['commission_summary'];
    ?>

    <script>
    var wcuWindows = <?php 
    echo wp_json_encode( $windows_js );
    ?>;
    var wcuIndex = <?php 
    echo intval( $start_index );
    ?>;
    var wcuSignature = <?php 
    echo wp_json_encode( $signature );
    ?>;
    var wcuCouponCode = <?php 
    echo wp_json_encode( $coupon_code );
    ?>;
    var wcuAjaxUrl = <?php 
    echo wp_json_encode( admin_url( 'admin-ajax.php' ) );
    ?>;
    var wcuRetries = 0;
    var wcuMaxRetries = 2;
    var the_coupon_usage = <?php 
    echo intval( $the_coupon_usage );
    ?>;
    var allstats = <?php 
    echo wp_json_encode( $start_stats_js );
    ?>;
    var updateStatsNonce = <?php 
    echo wp_json_encode( wp_create_nonce( 'wcusage_update_stats_nonce' ) );
    ?>;
    var ajaxErrorMessage = <?php 
    echo wp_json_encode( $ajaxerrormessage );
    ?>;

    function wcusageShowBatchRefreshError(message, details) {
      if(details) {
        console.log('Coupon Affiliates batch refresh error:', details);
      }
      jQuery('.wcu-loading-loader').hide();
      jQuery('.stuck-loading-message').show();
      jQuery('.wcu-progress-bar-fill').css('background', '#d63638');
      jQuery('#updated_total').html(message);
      jQuery('.wcutablinks').css('opacity', '1');
      jQuery('.wcutablinks').css('pointer-events', 'auto');
    }

    function wcusageGetAjaxError(jqXHR, textStatus, errorThrown) {
      if(errorThrown) {
        return errorThrown;
      }
      if(jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data) {
        if(jqXHR.responseJSON.data.message) {
          return jqXHR.responseJSON.data.message;
        }
        return jqXHR.responseJSON.data;
      }
      return textStatus || 'AJAX error.';
    }

    function wcusageParseStatsResponse(response) {
      var responseData = response;
      if(typeof responseData === 'string') {
        responseData = JSON.parse(responseData);
      }
      if(responseData && responseData.success === false) {
        throw new Error(responseData.data && responseData.data.message ? responseData.data.message : 'Statistics request failed.');
      }
      if(responseData && responseData.data && typeof responseData.data === 'object') {
        responseData = responseData.data;
      }
      if(!responseData || typeof responseData !== 'object') {
        throw new Error('Invalid statistics response.');
      }
      responseData.commission_summary = responseData.commission_summary || {};
      return responseData;
    }

    function getOrders() {

    /* Every window has been calculated, so the totals can now be saved. */
    if (wcuIndex >= wcuWindows.length) {
      updateAllStats(allstats);
      return;
    }

    var currentWindow = wcuWindows[wcuIndex];

    jQuery.ajax({
      url: wcuAjaxUrl,
      type: 'POST',
      data: {
      'action': 'wcusage_get_orders_by_coupon_ajax',
      'start': currentWindow[0],
      'end': currentWindow[1],
      'coupon_code': wcuCouponCode,
      'index': wcuIndex,
      'signature': wcuSignature,
      'stats': allstats,
      'security': updateStatsNonce
      },
      success: function(response) {
        try {
          var responseData = wcusageParseStatsResponse(response);
          allstats.total_count += Number(responseData.total_count) || 0;
          allstats.total_orders += Number(responseData.total_orders) || 0;
          allstats.full_discount += Number(responseData.full_discount) || 0;
          allstats.total_commission += Number(responseData.total_commission) || 0;
          allstats.total_shipping += Number(responseData.total_shipping) || 0;
          for (var key in responseData.commission_summary) {
            if (!Object.prototype.hasOwnProperty.call(responseData.commission_summary, key)) { continue; }
            if (allstats.commission_summary[key]) {
            allstats.commission_summary[key].total += Number(responseData.commission_summary[key].total) || 0;
            allstats.commission_summary[key].commission += Number(responseData.commission_summary[key].commission) || 0;
            allstats.commission_summary[key].number += Number(responseData.commission_summary[key].number) || 0;
            } else {
            allstats.commission_summary[key] = {
              total: Number(responseData.commission_summary[key].total) || 0,
              commission: Number(responseData.commission_summary[key].commission) || 0,
              number: Number(responseData.commission_summary[key].number) || 0
            };
            }
          }
          wcuRetries = 0;
          wcuIndex++;
          updateProgressBar(Math.floor((wcuIndex / wcuWindows.length) * 100));
          getOrders();
        } catch(error) {
          wcusageShowBatchRefreshError(ajaxErrorMessage + '<br/><br/>' + error.message, response);
        }
      },
      error: function(jqXHR, textStatus, errorThrown) {
        /* A single dropped request (a timeout, or a brief server hiccup) should
           not abandon the whole run - retry the same window before giving up. */
        if (wcuRetries < wcuMaxRetries) {
          wcuRetries++;
          setTimeout(getOrders, 1500 * wcuRetries);
          return;
        }
        wcusageShowBatchRefreshError(ajaxErrorMessage + '<br/><br/>' + wcusageGetAjaxError(jqXHR, textStatus, errorThrown), jqXHR);
      }
    });
    }

    function updateAllStats(allstats) {
    jQuery.ajax({
      url: wcuAjaxUrl,
      type: 'POST',
      data: {
      'action': 'wcusage_update_all_stats_data',
      'stats': allstats,
      'coupon_code': wcuCouponCode,
      'security': updateStatsNonce
      },
      success: function(response) {
        try {
          if(response && typeof response === 'string') {
            response = JSON.parse(response);
          }
          if(response && response.success === false) {
            throw new Error(response.data && response.data.message ? response.data.message : 'Statistics update failed.');
          }
          if(!response || typeof response !== 'object') {
            throw new Error('Invalid statistics update response.');
          }
        } catch(error) {
          wcusageShowBatchRefreshError(ajaxErrorMessage + '<br/><br/>' + error.message, response);
          return;
        }
        updateProgressBar(100);
        jQuery('#updated_total').html("Complete! Reloading...");
        location.reload();
      },
      error: function(jqXHR, textStatus, errorThrown) {
        wcusageShowBatchRefreshError(ajaxErrorMessage + '<br/><br/>' + wcusageGetAjaxError(jqXHR, textStatus, errorThrown), jqXHR);
      }
    });
    }

    function updateProgressBar(progress) {
      if(!isFinite(progress) || progress < 0) {
        progress = 0;
      }
      if(progress > 100) {
        progress = 100;
      }
      var progressBarFill = document.querySelector('.wcu-progress-bar-fill');
      if(progressBarFill) {
        progressBarFill.style.width = progress + '%';
      }
      var updatedTotal = document.getElementById('updated_total');
      if(updatedTotal) {
        updatedTotal.textContent = '<?php 
    echo esc_html__( "Calculating statistics", "woo-coupon-usage" );
    ?>... ' + Math.round(progress) + '%';
      }
    }

    jQuery(document).ready(function() {
      if (wcuWindows.length) {
        updateProgressBar(Math.floor((wcuIndex / wcuWindows.length) * 100));
      }
      getOrders();
    });
  </script>

  <style>
  .wcu-progress-bar {
    max-width: 500px;
    height: 8px;
    background-color: rgba(0, 0, 0, 0.06);
    border-radius: 10px;
    overflow: hidden;
    margin: 16px auto 0 auto;
  }
  .wcu-progress-bar-fill {
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, #3498db, #2ecc71);
    border-radius: 10px;
    transition: width 0.4s ease;
    font-size: 0;
  }
  </style>

  <div class="wcu-loading-image wcu-loading-stats">
    <div class="wcu-loading-loader"></div>
    <p class="wcu-loading-loader-text" id="updated_total"><?php 
    echo esc_html__( "Calculating statistics", "woo-coupon-usage" );
    ?>...</p>
    <p class="wcu-loading-loader-subtext"><?php 
    echo esc_html__( "First visit - this will take a little longer than usual.", "woo-coupon-usage" );
    ?></p>
    <?php 
    if ( current_user_can( 'administrator' ) ) {
        ?>
    <p class="stuck-loading-message wcu-loading-loader-subtext" style="display:none; margin-top: 12px;">
      <?php 
        echo esc_html__( "Notice (admin only): Page constantly loading? Try refreshing the page.", "woo-coupon-usage" );
        ?> <a href='https://couponaffiliates.com/docs/affiliate-dashboard-is-not-showing' target='_blank'><?php 
        echo esc_html__( "Or click here", "woo-coupon-usage" );
        ?></a>.
    </p>
    <?php 
    }
    ?>
  </div>

  <div class="wcu-progress-bar">
    <div class="wcu-progress-bar-fill"></div>
  </div>

  <p class="wcu-loading-loader-subtext" style="text-align:center; margin-top: 8px;"><?php 
    echo esc_html__( "The page will reload automatically when it is complete.", "woo-coupon-usage" );
    ?></p>
        
<?php 
}

add_action(
    'wcusage_hook_update_all_stats_batch_ajax',
    'wcusage_update_all_stats_batch_ajax',
    10,
    2
);