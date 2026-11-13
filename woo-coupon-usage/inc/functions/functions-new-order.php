<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * On order status change update all stats
 *
 * @param int $order_id
 *
 */
if ( !function_exists( 'wcusage_new_order_update_stats' ) ) {
    function wcusage_new_order_update_stats(  $order_id, $status_from = "", $status_to = ""  ) {
        $order = wc_get_order( $order_id );
        $options = wcusage_get_options();
        // For new orders, status_to might be empty, so get current status
        if ( $status_to == "" && $order ) {
            $status_to = $order->get_status();
        }
        if ( $status_to != "refunded" ) {
            $check_status_from_show = wcusage_check_status_show( $status_from );
            $check_status_to_show = wcusage_check_status_show( $status_to );
            // Check if order is to be added to stats
            $show_order = 0;
            $wcusage_all_updated = wcusage_order_meta( $order_id, 'wcusage_all_updated' );
            if ( !$wcusage_all_updated ) {
                if ( !$check_status_from_show && $check_status_to_show ) {
                    wcusage_update_order_meta( $order_id, 'wcusage_all_updated', 1 );
                    $show_order = 1;
                }
                if ( !$show_order ) {
                    $commission_statuses = wcusage_get_commission_order_statuses();
                    if ( isset( $commission_statuses['wc-pending'] ) && isset( $commission_statuses['wc-processing'] ) ) {
                        if ( $status_from == "pending" && $status_to == "processing" ) {
                            wcusage_update_order_meta( $order_id, 'wcusage_all_updated', 1 );
                            $show_order = 1;
                        }
                    }
                }
            }
            // Check if order is to be removed from stats
            $remove_order = 0;
            $wcusage_all_updated = wcusage_order_meta( $order_id, 'wcusage_all_updated' );
            if ( $check_status_from_show && !$check_status_to_show && $wcusage_all_updated ) {
                $remove_order = 1;
            }
            // Check if refresh
            $coupon_refresh = wcusage_order_meta( $order_id, 'wcusage_referrer_refresh' );
            $coupon_refresh_prev = wcusage_order_meta( $order_id, 'wcusage_referrer_refresh_prev' );
            $meta_data = [];
            // Coupons the order is credited to, with their calculated order data, and
            // for an order leaving the stats what each was counted with.
            $credited = array();
            $removed_commission = array();
            if ( $order ) {
                // Set the currency conversion rate for order.
                $enablecurrency = wcusage_get_setting_value( 'wcusage_field_enable_currency', '0' );
                $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
                if ( $enablecurrency && $enable_save_rate ) {
                    $currencycode = $order->get_currency();
                    $currency_rate = wcusage_get_currency_rate( $currencycode );
                    $meta_data['wcusage_currency_conversion'] = $currency_rate;
                }
                $lifetimeaffiliate = wcusage_order_meta( $order_id, 'lifetime_affiliate_coupon_referrer' );
                $affiliatereferrer = wcusage_order_meta( $order_id, 'wcusage_referrer_coupon' );
                $wcusage_field_mla_enable = wcusage_get_setting_value( 'wcusage_field_mla_enable', '0' );
                // Update All-Time Stats
                $wcusage_field_enable_coupon_all_stats_meta = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
                if ( $lifetimeaffiliate ) {
                    if ( $remove_order ) {
                        $removed_commission[$lifetimeaffiliate] = wcusage_get_order_counted_commission( $order_id, $lifetimeaffiliate );
                    }
                    $calculateorder = wcusage_calculate_order_data(
                        $order_id,
                        $lifetimeaffiliate,
                        1,
                        0,
                        1
                    );
                    $credited[$lifetimeaffiliate] = $calculateorder;
                    // If is a lifetime affiliate order.
                    if ( $wcusage_field_enable_coupon_all_stats_meta ) {
                        if ( $show_order ) {
                            // If old order NOT shown and new order IS shown
                            do_action(
                                'wcusage_hook_update_all_stats_single',
                                $lifetimeaffiliate,
                                $order_id,
                                1,
                                1
                            );
                            // Add
                        }
                        if ( $remove_order ) {
                            // If old order IS shown and new order NOT shown
                            do_action(
                                'wcusage_hook_update_all_stats_single',
                                $lifetimeaffiliate,
                                $order_id,
                                0,
                                1
                            );
                            // Remove
                        }
                    }
                    $coupon_info = wcusage_get_coupon_info( $lifetimeaffiliate );
                    $coupon_user_id = $coupon_info[1];
                    $paidcommission = ( isset( $calculateorder['totalcommission'] ) ? $calculateorder['totalcommission'] : 0 );
                    $meta_data = wcusage_update_ml_affiliate_parents(
                        $meta_data,
                        $coupon_user_id,
                        $order,
                        $lifetimeaffiliate,
                        $paidcommission
                    );
                } elseif ( $affiliatereferrer ) {
                    if ( $remove_order ) {
                        $removed_commission[$affiliatereferrer] = wcusage_get_order_counted_commission( $order_id, $affiliatereferrer );
                    }
                    $calculateorder = wcusage_calculate_order_data(
                        $order_id,
                        $affiliatereferrer,
                        1,
                        0,
                        1
                    );
                    $credited[$affiliatereferrer] = $calculateorder;
                    if ( $wcusage_field_enable_coupon_all_stats_meta ) {
                        // Update Coupon Stats
                        if ( $show_order ) {
                            // If old status NOT shown and new status IS shown
                            do_action(
                                'wcusage_hook_update_all_stats_single',
                                $affiliatereferrer,
                                $order_id,
                                1,
                                1
                            );
                            // Add
                        }
                        if ( $remove_order ) {
                            // If old status IS shown and new status NOT shown
                            do_action(
                                'wcusage_hook_update_all_stats_single',
                                $affiliatereferrer,
                                $order_id,
                                0,
                                1
                            );
                            // Remove
                        }
                    }
                    $coupon_info = wcusage_get_coupon_info( $affiliatereferrer );
                    $coupon_user_id = $coupon_info[1];
                    $paidcommission = ( isset( $calculateorder['totalcommission'] ) ? $calculateorder['totalcommission'] : 0 );
                    $user_parents = get_user_meta( $coupon_user_id, 'wcu_ml_affiliate_parents', true );
                    $meta_data = wcusage_update_ml_affiliate_parents(
                        $meta_data,
                        $coupon_user_id,
                        $order,
                        $affiliatereferrer,
                        $paidcommission
                    );
                } else {
                    // Coupons
                    if ( class_exists( 'WooCommerce' ) ) {
                        if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
                            $coupons_array = $order->get_coupon_codes();
                        } else {
                            $coupons_array = $order->get_used_coupons();
                        }
                        foreach ( $coupons_array as $coupon_code ) {
                            if ( $remove_order ) {
                                $removed_commission[$coupon_code] = wcusage_get_order_counted_commission( $order_id, $coupon_code );
                            }
                            $calculateorder = wcusage_calculate_order_data(
                                $order_id,
                                $coupon_code,
                                1,
                                0,
                                1
                            );
                            $credited[$coupon_code] = $calculateorder;
                            if ( $wcusage_field_enable_coupon_all_stats_meta ) {
                                // Update Coupon Stats
                                if ( $show_order ) {
                                    // If old status NOT shown and new status IS shown
                                    do_action(
                                        'wcusage_hook_update_all_stats_single',
                                        $coupon_code,
                                        $order_id,
                                        1,
                                        1
                                    );
                                    // Add
                                }
                                if ( $remove_order ) {
                                    // If old status IS shown and new status NOT shown
                                    do_action(
                                        'wcusage_hook_update_all_stats_single',
                                        $coupon_code,
                                        $order_id,
                                        0,
                                        1
                                    );
                                    // Remove
                                }
                            }
                            $coupon_info = wcusage_get_coupon_info( $coupon_code );
                            $coupon_user_id = $coupon_info[1];
                            $paidcommission = ( isset( $calculateorder['totalcommission'] ) ? $calculateorder['totalcommission'] : 0 );
                            $meta_data = wcusage_update_ml_affiliate_parents(
                                $meta_data,
                                $coupon_user_id,
                                $order,
                                $coupon_code,
                                $paidcommission
                            );
                        }
                    }
                }
                if ( $show_order || $remove_order ) {
                    foreach ( $credited as $credited_code => $credited_data ) {
                        wcusage_do_referral_change_action(
                            ( $show_order ? 'added' : 'removed' ),
                            $order,
                            $credited_code,
                            $credited_data,
                            ( isset( $removed_commission[$credited_code] ) ? $removed_commission[$credited_code] : null )
                        );
                    }
                }
                if ( $coupon_refresh ) {
                    wcusage_delete_order_meta( $order_id, 'wcusage_referrer_refresh' );
                }
                if ( $coupon_refresh_prev ) {
                    wcusage_delete_order_meta( $order_id, 'wcusage_referrer_refresh_prev' );
                }
            }
            // Clear the "counted" flag only now that the remove has run. The flag is what
            // tells wcusage_update_all_stats_single() the order's saved stats are still in
            // the all-time totals and may be subtracted - deleting it beforehand left the
            // removal with nothing to subtract for a status the calculation reports as zero.
            if ( $remove_order ) {
                wcusage_delete_order_meta( $order_id, 'wcusage_all_updated' );
            }
        }
        if ( !empty( $meta_data ) ) {
            wcusage_update_order_meta_bulk( $order_id, $meta_data );
        }
    }

}
add_action(
    'woocommerce_checkout_update_order_meta',
    'wcusage_new_order_update_stats',
    10,
    2
);
add_action(
    'woocommerce_order_status_changed',
    'wcusage_new_order_update_stats',
    10,
    3
);
add_action(
    'woocommerce_process_shop_order_meta',
    'wcusage_new_order_update_stats',
    10,
    3
);
// NOTE: The wcusage_all_updated flag is intentionally NOT deleted on order completion.
// Deleting it on 'woocommerce_order_status_completed' caused double-counting because
// woocommerce_order_status_changed fires after that hook, sees no flag, and re-adds
// the order to the all-time stats a second time. The flag is only cleared by the
// $remove_order path when an order moves from a counted status to a non-counted one.
/* Update MLA Parents When New Order Placed */
function wcusage_update_ml_affiliate_parents(
    $meta_data,
    $coupon_user_id,
    $order,
    $coupon_code = '',
    $paidcommission = 0
) {
}

/**
 * Adds referrer meta and activity log on new order
 *
 * @param int $order_id
 *
 */
function wcusage_on_new_order_set_coupon_referrer(  $order_id  ) {
    if ( is_a( $order_id, 'WC_Order' ) ) {
        $order_id = $order_id->get_id();
    }
    $order_id = absint( $order_id );
    if ( !$order_id ) {
        return;
    }
    // Get settings
    $wcusage_field_url_referrals = wcusage_get_setting_value( 'wcusage_field_url_referrals', '0' );
    $wcusage_store_cookies = wcusage_get_setting_value( 'wcusage_field_store_cookies', '1' );
    // Get cookie
    $cookie = "";
    $url_applied = "";
    $coupon_applied = "";
    if ( $wcusage_store_cookies && isset( $_COOKIE['wcusage_referral'] ) ) {
        $cookie = wp_unslash( $_COOKIE['wcusage_referral'] );
    }
    if ( $wcusage_store_cookies && !$cookie && isset( $_COOKIE['wcusage_referral_code'] ) ) {
        $cookie = wp_unslash( $_COOKIE['wcusage_referral_code'] );
        $url_applied = 1;
    }
    $cookie = sanitize_text_field( $cookie );
    // If $cookie is not a coupon applied to order
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
        $coupons_array = $order->get_coupon_codes();
    } else {
        $coupons_array = $order->get_used_coupons();
    }
    /**
     * Filters the referral link coupon an order is credited to.
     *
     * Runs at checkout with the coupon from the visitor's referral cookie. Return
     * another affiliate coupon's code to credit that affiliate instead, or an empty
     * string to ignore the referral link. Coupons used on the order itself are
     * credited as normal either way; use WooCommerce's coupon validation filters to
     * stop one being used.
     *
     * @param string   $coupon_code   Coupon from the referral cookie, or empty.
     * @param WC_Order $order         Order being placed.
     * @param string[] $order_coupons Coupon codes used on the order.
     */
    $filtered_cookie = apply_filters(
        'wcusage_order_referral_coupon',
        $cookie,
        $order,
        $coupons_array
    );
    if ( $filtered_cookie !== $cookie ) {
        $cookie = sanitize_text_field( (string) $filtered_cookie );
        $url_applied = "";
    }
    // A suspended affiliate is not credited through their referral link. The cookie
    // may have been set before the suspension, so it is checked here too. Dropping it
    // lets the order fall back to any affiliate coupon used on it.
    if ( $cookie && wcusage_is_coupon_suspended( wc_get_coupon_id_by_code( $cookie ) ) ) {
        $cookie = "";
        $url_applied = "";
    }
    // Nor is an affiliate credited through their own referral link, when the settings
    // say they earn nothing on their own orders. This used to look only at the
    // logged-in user, so a guest checkout with the affiliate's own email was credited.
    if ( $cookie && wcusage_is_blocked_own_order( $order, $cookie ) ) {
        $cookie = "";
        $url_applied = "";
    }
    if ( $cookie ) {
        if ( !in_array( $cookie, $coupons_array ) ) {
            $url_applied = 1;
        }
    }
    // If one of coupons is a referral coupon
    if ( !$cookie && !$url_applied && $coupons_array ) {
        foreach ( $coupons_array as $coupon_code ) {
            $coupon_info = wcusage_get_coupon_info( $coupon_code );
            $coupon_id = $coupon_info[2];
            $coupon_user = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
            if ( $coupon_user ) {
                $coupon_applied = 1;
                $activity_log = wcusage_add_activity( $order_id, 'referral', $coupon_code );
            }
        }
    }
    // Activity Log
    if ( $cookie && !$coupon_applied && $wcusage_field_url_referrals ) {
        $coupon_info = wcusage_get_coupon_info( $cookie );
        $coupon_id = $coupon_info[2];
        $coupon_user = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
        if ( $coupon_user ) {
            $activity_log = wcusage_add_activity( $order_id, 'referral', $cookie );
        }
    }
    // URL Referrals
    if ( $cookie && $url_applied && $wcusage_field_url_referrals ) {
        $coupon_info = wcusage_get_coupon_info( $cookie );
        $meta_data = [];
        $coupon = new WC_Coupon($cookie);
        $wcusage_field_allow_assigned_user = wcusage_get_setting_value( 'wcusage_field_allow_assigned_user', 1 );
        $current_user_id = get_current_user_id();
        $iscouponusers = wcusage_iscouponusers( $coupon->get_code(), $current_user_id );
        if ( $wcusage_field_allow_assigned_user || !$iscouponusers ) {
            // if $cookie is not one of the coupons used in order
            $order = wc_get_order( $order_id );
            if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
                $coupons_array = $order->get_coupon_codes();
            } else {
                $coupons_array = $order->get_used_coupons();
            }
            if ( !in_array( $cookie, $coupons_array ) ) {
                $meta_data['wcusage_referrer_coupon'] = $cookie;
                $coupon_user_id = $coupon_info[1];
                $meta_data['wcusage_affiliate_user'] = $coupon_user_id;
            }
            if ( !empty( $meta_data ) ) {
                wcusage_update_order_meta_bulk( $order_id, $meta_data );
            }
        }
    }
}

add_action(
    'woocommerce_checkout_order_processed',
    'wcusage_on_new_order_set_coupon_referrer',
    10,
    1
);
add_action(
    'woocommerce_store_api_checkout_order_processed',
    'wcusage_on_new_order_set_coupon_referrer',
    10,
    1
);
/**
 * On thank you page delete cookies
 *
 * @param int $order_id
 *
 */
function wcusage_on_new_order_delete_cookies(  $order_id  ) {
    // Remove cookies if set and wcusage_remove_cookies option is enabled
    $wcusage_remove_cookies = wcusage_get_setting_value( 'wcusage_remove_cookies', '0' );
    if ( $wcusage_remove_cookies ) {
        // wcusage_referral
        if ( isset( $_COOKIE['wcusage_referral'] ) ) {
            unset($_COOKIE['wcusage_referral']);
            wcusage_set_cookie( 'wcusage_referral', '', -1 );
        }
        // wcusage_referral_code
        if ( isset( $_COOKIE['wcusage_referral_code'] ) ) {
            unset($_COOKIE['wcusage_referral_code']);
            wcusage_set_cookie( 'wcusage_referral_code', '', -1 );
        }
        // wcusage_referral_click
        if ( isset( $_COOKIE['wcusage_referral_click'] ) ) {
            unset($_COOKIE['wcusage_referral_click']);
            wcusage_set_cookie( 'wcusage_referral_click', '', -1 );
        }
        // wcusage_referral_campaign
        if ( isset( $_COOKIE['wcusage_referral_campaign'] ) ) {
            unset($_COOKIE['wcusage_referral_campaign']);
            wcusage_set_cookie( 'wcusage_referral_campaign', '', -1 );
        }
        if ( function_exists( 'wcusage_clear_wc_session_value' ) ) {
            wcusage_clear_wc_session_value( 'wcusage_referral' );
        }
    }
}

add_action(
    'woocommerce_thankyou',
    'wcusage_on_new_order_delete_cookies',
    20,
    1
);
/**
 * The affiliate coupons an order is credited to, and how.
 *
 * Follows the same order of precedence as the stats: a lifetime referrer, then a
 * referral link (or an applied coupon that replaced a lifetime referrer), then the
 * affiliate coupons used on the order.
 *
 * @param WC_Order $order Order.
 *
 * @return array[] List of array( 'coupon_code', 'affiliate_id', 'source' ), where
 *                 source is "lifetime", "url" or "coupon". Coupons with no affiliate
 *                 assigned are left out.
 */
if ( !function_exists( 'wcusage_get_order_referral_attribution' ) ) {
    function wcusage_get_order_referral_attribution(  $order  ) {
        if ( !is_a( $order, 'WC_Order' ) ) {
            return array();
        }
        $order_id = $order->get_id();
        $order_coupons = array_map( 'strtolower', $order->get_coupon_codes() );
        $candidates = array();
        $lifetimeaffiliate = wcusage_order_meta( $order_id, 'lifetime_affiliate_coupon_referrer' );
        $affiliatereferrer = wcusage_order_meta( $order_id, 'wcusage_referrer_coupon' );
        if ( $lifetimeaffiliate ) {
            $candidates[$lifetimeaffiliate] = 'lifetime';
        } elseif ( $affiliatereferrer ) {
            $candidates[$affiliatereferrer] = ( in_array( strtolower( $affiliatereferrer ), $order_coupons, true ) ? 'coupon' : 'url' );
        } else {
            foreach ( $order->get_coupon_codes() as $coupon_code ) {
                $candidates[$coupon_code] = 'coupon';
            }
        }
        $attribution = array();
        foreach ( $candidates as $coupon_code => $source ) {
            $coupon_info = wcusage_get_coupon_info( $coupon_code );
            $affiliate_id = ( isset( $coupon_info[1] ) ? (int) $coupon_info[1] : 0 );
            if ( $affiliate_id ) {
                $attribution[] = array(
                    'coupon_code'  => (string) $coupon_code,
                    'affiliate_id' => $affiliate_id,
                    'source'       => $source,
                );
            }
        }
        return $attribution;
    }

}
/**
 * Fire "wcusage_order_referral_attributed" once a checkout has decided who the order is credited to.
 *
 * Runs after the lifetime check (priority 1) and the referral link check
 * (priority 10) on the same checkout hooks.
 *
 * @param int|WC_Order $order_id Order.
 */
if ( !function_exists( 'wcusage_on_new_order_referral_attributed' ) ) {
    function wcusage_on_new_order_referral_attributed(  $order_id  ) {
        $order = ( is_a( $order_id, 'WC_Order' ) ? $order_id : wc_get_order( absint( $order_id ) ) );
        if ( !$order ) {
            return;
        }
        // A failed payment retried through the checkout runs these hooks again for
        // the same order.
        if ( wcusage_order_meta( $order->get_id(), '_wcusage_referral_attributed' ) ) {
            return;
        }
        $attribution = wcusage_get_order_referral_attribution( $order );
        if ( empty( $attribution ) ) {
            return;
        }
        wcusage_update_order_meta( $order->get_id(), '_wcusage_referral_attributed', 1 );
        foreach ( $attribution as $credit ) {
            /**
             * Fires at checkout for each affiliate an order is credited to.
             *
             * Fires once per order and affiliate coupon, whatever the order's status;
             * commission is granted later, as the order moves through its statuses (see
             * "wcusage_referral_added"). Orders created in wp-admin do not fire it.
             *
             * @param WC_Order $order        Order.
             * @param string   $coupon_code  Affiliate coupon the order is credited to.
             * @param int      $affiliate_id User ID of the affiliate.
             * @param string   $source       How: "coupon" (used on the order), "url"
             *                               (referral link) or "lifetime" (lifetime
             *                               commission referrer).
             */
            do_action(
                'wcusage_order_referral_attributed',
                $order,
                $credit['coupon_code'],
                $credit['affiliate_id'],
                $credit['source']
            );
        }
    }

}
add_action(
    'woocommerce_checkout_order_processed',
    'wcusage_on_new_order_referral_attributed',
    20,
    1
);
add_action(
    'woocommerce_store_api_checkout_order_processed',
    'wcusage_on_new_order_referral_attributed',
    20,
    1
);
/**
 * The commission one coupon's referral of an order was counted with.
 *
 * Resolved the way wcusage_update_all_stats_single() takes it back: the coupon's
 * own snapshot on an order with several affiliate coupons, what a coupon that
 * does not own the order's commission was granted or has pending, otherwise the
 * order's saved commission. Read it before the stats are updated, which drops
 * the snapshot.
 *
 * @param int    $order_id    Order ID.
 * @param string $coupon_code Coupon.
 *
 * @return float
 */
if ( !function_exists( 'wcusage_get_order_counted_commission' ) ) {
    function wcusage_get_order_counted_commission(  $order_id, $coupon_code  ) {
        if ( function_exists( 'wcusage_order_alltime_commission_key' ) ) {
            $own = wcusage_order_meta( $order_id, wcusage_order_alltime_commission_key( $coupon_code ), true );
            if ( is_numeric( $own ) ) {
                return (float) $own;
            }
        }
        if ( function_exists( 'wcusage_coupon_owns_order_commission' ) && !wcusage_coupon_owns_order_commission( $order_id, $coupon_code ) ) {
            foreach ( array('wcusage_order_granted_commission_key', 'wcusage_order_pending_commission_key') as $key_function ) {
                if ( function_exists( $key_function ) ) {
                    $value = wcusage_order_meta( $order_id, $key_function( $coupon_code ), true );
                    if ( is_numeric( $value ) ) {
                        return (float) $value;
                    }
                }
            }
            return 0.0;
        }
        return (float) wcusage_get_order_saved_commission( $order_id, false );
    }

}
/**
 * Fire "wcusage_referral_added" or "wcusage_referral_removed" for one coupon on an order.
 *
 * @param string   $change      "added" or "removed".
 * @param WC_Order $order       Order.
 * @param string   $coupon_code Affiliate coupon the order is credited to.
 * @param array    $order_data  The order's calculated data for that coupon
 *                              (wcusage_calculate_order_data()).
 * @param float|null $commission For "removed": the coupon's counted commission,
 *                              read with wcusage_get_order_counted_commission()
 *                              before the stats were updated. Null reads it now.
 */
if ( !function_exists( 'wcusage_do_referral_change_action' ) ) {
    function wcusage_do_referral_change_action(
        $change,
        $order,
        $coupon_code,
        $order_data = array(),
        $commission = null
    ) {
        $coupon_info = wcusage_get_coupon_info( $coupon_code );
        $affiliate_id = ( isset( $coupon_info[1] ) ? (int) $coupon_info[1] : 0 );
        if ( !$affiliate_id ) {
            return;
        }
        if ( $change === 'removed' ) {
            // The calculation reports nothing for a cancelled, failed or refunded order,
            // so use what this coupon was counted with.
            $commission = ( null === $commission ? wcusage_get_order_counted_commission( $order->get_id(), $coupon_code ) : (float) $commission );
        } else {
            $commission = ( isset( $order_data['totalcommission'] ) ? (float) $order_data['totalcommission'] : 0.0 );
        }
        if ( $change === 'added' ) {
            /**
             * Fires when an order starts counting towards an affiliate's referrals.
             *
             * That is when it first reaches a status that counts (see
             * "wcusage_commission_order_statuses"), once per order and coupon. Whether
             * the commission is unpaid yet depends on the payout settings: listen to
             * "wcusage_hook_after_update_commission" for that.
             *
             * @param WC_Order $order        Order.
             * @param string   $coupon_code  Affiliate coupon credited.
             * @param int      $affiliate_id User ID of the affiliate.
             * @param float    $commission   Commission the order earns the affiliate.
             * @param array    $order_data   The order's calculated data for that coupon.
             */
            do_action(
                'wcusage_referral_added',
                $order,
                (string) $coupon_code,
                $affiliate_id,
                $commission,
                (array) $order_data
            );
        } elseif ( $change === 'removed' ) {
            /**
             * Fires when an order stops counting towards an affiliate's referrals.
             *
             * That is when it moves to a status that does not count (cancelled, failed),
             * or is refunded in full.
             *
             * @param WC_Order $order        Order.
             * @param string   $coupon_code  Affiliate coupon it was credited to.
             * @param int      $affiliate_id User ID of the affiliate.
             * @param float    $commission   Commission the order was last saved as earning.
             * @param array    $order_data   The order's data as calculated now (zero
             *                               amounts for a cancelled, failed or refunded order).
             */
            do_action(
                'wcusage_referral_removed',
                $order,
                (string) $coupon_code,
                $affiliate_id,
                $commission,
                (array) $order_data
            );
        }
    }

}