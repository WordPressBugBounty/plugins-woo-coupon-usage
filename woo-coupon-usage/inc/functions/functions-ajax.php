<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'wcusage_ajax_resolve_coupon' ) ) {
    function wcusage_ajax_resolve_coupon(  $postid, $couponcode  ) {
        $resolved_postid = absint( $postid );
        $resolved_code = sanitize_text_field( $couponcode );
        if ( $resolved_postid && get_post_type( $resolved_postid ) === 'shop_coupon' ) {
            $status = get_post_status( $resolved_postid );
            if ( $status === 'trash' || $status === false ) {
                $resolved_postid = 0;
            }
        } else {
            $resolved_postid = 0;
        }
        if ( !$resolved_postid && $resolved_code && function_exists( 'wc_get_coupon_id_by_code' ) ) {
            $resolved_postid = wc_get_coupon_id_by_code( $resolved_code );
        }
        if ( !$resolved_postid ) {
            echo '<div class="wcusage-error">' . esc_html__( 'Error: Coupon does not exist.', 'woo-coupon-usage' ) . '</div>';
            return array(0, '');
        }
        if ( !$resolved_code ) {
            $resolved_code = get_the_title( $resolved_postid );
        }
        return array($resolved_postid, $resolved_code);
    }

}
/**
 * Tab - Latest Orders
 */
if ( !function_exists( 'wcusage_load_page_orders' ) ) {
    function wcusage_load_page_orders() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        $language = ( isset( $_POST["language"] ) ? sanitize_text_field( $_POST["language"] ) : '' );
        $postid = ( isset( $_POST["postid"] ) ? absint( $_POST["postid"] ) : 0 );
        $couponcode = ( isset( $_POST["couponcode"] ) ? sanitize_text_field( $_POST["couponcode"] ) : '' );
        wcusage_load_custom_language_wpml( $language );
        // WPML Support
        list( $resolved_postid, $resolved_code ) = wcusage_ajax_resolve_coupon( $postid, $couponcode );
        if ( !$resolved_postid ) {
            exit;
        }
        ?>
    <?php 
        $startdate = ( isset( $_POST["startdate"] ) ? sanitize_text_field( $_POST["startdate"] ) : '' );
        $enddate = ( isset( $_POST["enddate"] ) ? sanitize_text_field( $_POST["enddate"] ) : '' );
        $alltime = isset( $_POST["alltime"] ) && $_POST["alltime"] === '1';
        $isordersstartset = $startdate !== '' || $alltime;
        $status = ( isset( $_POST["status"] ) ? sanitize_text_field( $_POST["status"] ) : '' );
        $showall = isset( $_POST["showall"] ) && $_POST["showall"] === '1';
        $page = ( $showall ? 'all' : (( isset( $_POST["page"] ) ? max( 1, intval( $_POST["page"] ) ) : 1 )) );
        do_action(
            'wcusage_hook_tab_latest_orders',
            $resolved_postid,
            $resolved_code,
            $startdate,
            $enddate,
            $isordersstartset,
            sanitize_text_field( $status ),
            '',
            true,
            true,
            $page
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_page_orders', 'wcusage_load_page_orders' );
add_action( 'wp_ajax_nopriv_wcusage_load_page_orders', 'wcusage_load_page_orders' );
/**
 * Tab - Referral URL Stats
 */
if ( !function_exists( 'wcusage_load_referral_url_stats' ) ) {
    function wcusage_load_referral_url_stats() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        $language = ( isset( $_POST["language"] ) ? sanitize_text_field( $_POST["language"] ) : '' );
        $postid = ( isset( $_POST["postid"] ) ? sanitize_text_field( $_POST["postid"] ) : '' );
        $couponcode = ( isset( $_POST["couponcode"] ) ? sanitize_text_field( $_POST["couponcode"] ) : '' );
        wcusage_load_custom_language_wpml( $language );
        // WPML Support
        list( $resolved_postid, $resolved_code ) = wcusage_ajax_resolve_coupon( $postid, $couponcode );
        if ( !$resolved_postid ) {
            exit;
        }
        ?>
    <?php 
        $campaign = ( isset( $_POST["campaign"] ) ? sanitize_text_field( $_POST["campaign"] ) : '' );
        $page = ( isset( $_POST["page"] ) ? sanitize_text_field( $_POST["page"] ) : '' );
        $converted = ( isset( $_POST["converted"] ) ? sanitize_text_field( $_POST["converted"] ) : '' );
        do_action(
            'wcusage_hook_tab_referral_url_stats',
            $resolved_postid,
            $resolved_code,
            $campaign,
            $page,
            $converted
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_referral_url_stats', 'wcusage_load_referral_url_stats' );
add_action( 'wp_ajax_nopriv_wcusage_load_referral_url_stats', 'wcusage_load_referral_url_stats' );
/**
 * Tab - Statistics
 */
if ( !function_exists( 'wcusage_load_page_statistics' ) ) {
    function wcusage_load_page_statistics() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        $language = ( isset( $_POST["language"] ) ? sanitize_text_field( $_POST["language"] ) : '' );
        $postid = ( isset( $_POST["postid"] ) ? sanitize_text_field( $_POST["postid"] ) : '' );
        $couponcode = ( isset( $_POST["couponcode"] ) ? sanitize_text_field( $_POST["couponcode"] ) : '' );
        wcusage_load_custom_language_wpml( $language );
        // WPML Support
        list( $resolved_postid, $resolved_code ) = wcusage_ajax_resolve_coupon( $postid, $couponcode );
        if ( !$resolved_postid ) {
            exit;
        }
        $combinedcommission = ( isset( $_POST["combinedcommission"] ) ? $_POST["combinedcommission"] : '' );
        $refresh = ( isset( $_POST["refresh"] ) ? sanitize_text_field( $_POST["refresh"] ) : '' );
        do_action(
            'wcusage_hook_tab_statistics',
            $resolved_postid,
            $resolved_code,
            wcusage_convert_symbols_revert( $combinedcommission ),
            $refresh
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_page_statistics', 'wcusage_load_page_statistics' );
add_action( 'wp_ajax_nopriv_wcusage_load_page_statistics', 'wcusage_load_page_statistics' );
// Pro
if ( wcu_fs()->can_use_premium_code() ) {
}
/**
 * AJAX handler to refresh dashboard stats (all-time, this month, last month)
 * Uses cached per-order meta for rapid recalculation without recalculating individual orders.
 * Updates the coupon's all-time stats meta and monthly stats meta.
 */
if ( !function_exists( 'wcusage_refresh_dashboard_stats' ) ) {
    function wcusage_refresh_dashboard_stats() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        // Require a logged-in user (this handler writes post meta)
        if ( !is_user_logged_in() ) {
            wp_send_json_error( esc_html__( 'You must be logged in.', 'woo-coupon-usage' ) );
        }
        $postid = ( isset( $_POST['postid'] ) ? sanitize_text_field( $_POST['postid'] ) : '' );
        $couponcode = ( isset( $_POST['couponcode'] ) ? sanitize_text_field( $_POST['couponcode'] ) : '' );
        list( $resolved_postid, $resolved_code ) = wcusage_ajax_resolve_coupon( $postid, $couponcode );
        if ( !$resolved_postid ) {
            wp_send_json_error( esc_html__( 'Invalid coupon.', 'woo-coupon-usage' ) );
        }
        $coupon_code = strtolower( $resolved_code );
        $couponinfo = wcusage_get_coupon_info( $coupon_code );
        $coupon_post_id = $couponinfo[2];
        $coupon_user_id = intval( $couponinfo[1] );
        $currentuserid = get_current_user_id();
        // Check MLA sub-affiliate access
        $sub_affiliate = false;
        // Check access permissions (strict comparison to prevent type juggling)
        if ( $coupon_user_id !== $currentuserid && !$sub_affiliate && !wcusage_check_admin_access() ) {
            wp_send_json_error( esc_html__( 'You do not have permission to access this data.', 'woo-coupon-usage' ) );
        }
        // Rate limiting: 1 hour between refreshes per coupon
        $last_fast_refresh = (int) get_post_meta( $coupon_post_id, 'wcu_last_fast_refresh', true );
        $cooldown = 3600;
        $elapsed = time() - $last_fast_refresh;
        if ( $last_fast_refresh && $elapsed < $cooldown ) {
            wp_send_json_success( array(
                'rate_limited' => true,
            ) );
        }
        update_post_meta( $coupon_post_id, 'wcu_last_fast_refresh', time() );
        $wcusage_field_which_toggle = wcusage_get_setting_value( 'wcusage_field_which_toggle', '1' );
        $wcusage_hide_all_time = wcusage_get_setting_value( 'wcusage_field_hide_all_time', '0' );
        $combined_commission = '';
        if ( function_exists( 'wcusage_commission_message' ) ) {
            $combined_commission = wcusage_commission_message( $coupon_post_id );
        }
        // ============================================================
        // 1. Recalculate ALL-TIME stats using cached per-order meta
        //    (fast path: $refresh=1, $update=0, $alltime=0)
        //    This reads wcusage_stats order meta instead of recalculating each order.
        // ============================================================
        // Use the fast path: refresh=1 (query orders), update=0 (use cached order meta), alltime=0 (use fast path)
        $fullorders = wcusage_wh_getOrderbyCouponCode(
            $coupon_code,
            '',
            date( 'Y-m-d' ),
            '',
            1,
            0,
            0
        );
        // Save the recalculated all-time stats to coupon meta
        if ( !empty( $fullorders ) && is_array( $fullorders ) ) {
            $allstats = array();
            $allstats['total_orders'] = ( isset( $fullorders['total_orders'] ) ? $fullorders['total_orders'] : 0 );
            $allstats['full_discount'] = ( isset( $fullorders['full_discount'] ) ? $fullorders['full_discount'] : 0 );
            $allstats['total_commission'] = ( isset( $fullorders['total_commission'] ) ? $fullorders['total_commission'] : 0 );
            $allstats['total_shipping'] = ( isset( $fullorders['total_shipping'] ) ? $fullorders['total_shipping'] : 0 );
            $allstats['total_count'] = ( isset( $fullorders['total_count'] ) ? $fullorders['total_count'] : 0 );
            if ( isset( $fullorders['commission_summary'] ) ) {
                $allstats['commission_summary'] = $fullorders['commission_summary'];
            }
            // Delete existing all-time stats so they get freshly aggregated
            delete_post_meta( $coupon_post_id, 'wcu_alltime_stats' );
            // Update with the new all-time stats
            update_post_meta( $coupon_post_id, 'wcu_alltime_stats', $allstats );
            update_post_meta( $coupon_post_id, 'wcu_last_refreshed', time() );
        }
        // ============================================================
        // 2. Recalculate MONTHLY stats (this month + last month)
        //    Clear monthly caches and re-query using cached order meta.
        // ============================================================
        $thismonthorders = '';
        $pastmonthorders = '';
        $past60orders = '';
        $past14orders = '';
        if ( !$wcusage_field_which_toggle ) {
            // Days mode
            $date7 = date( 'Y-m-d', strtotime( '-7 days' ) );
            $date14 = date( 'Y-m-d', strtotime( '-14 days' ) );
            $date30 = date( 'Y-m-d', strtotime( '-30 days' ) );
            $date60 = date( 'Y-m-d', strtotime( '-60 days' ) );
            // "Last 30 Days" toggle
            $thismonthorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date30,
                date( 'Y-m-d' ),
                '',
                1,
                0,
                0
            );
            $past60orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date60,
                $date30,
                '',
                1,
                0,
                0
            );
            // "Last 7 Days" toggle
            $this7orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date7,
                date( 'Y-m-d' ),
                '',
                1,
                0,
                0
            );
            $past14orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date14,
                $date7,
                '',
                1,
                0,
                0
            );
        } else {
            // Monthly mode
            $date1month = date( 'Y-m-01' );
            $date2month = date( 'Y-m-d', strtotime( 'first day of last month' ) );
            $date2monthend = date( 'Y-m-d', strtotime( 'last day of last month' ) );
            // "This Month" toggle
            $thismonthorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date1month,
                date( 'Y-m-d' ),
                '',
                1,
                0,
                0
            );
            // "Last Month" toggle + comparison for This Month
            $pastmonthorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date2month,
                $date2monthend,
                '',
                1,
                0,
                0
            );
            $past60orders = $pastmonthorders;
            // Month before last (comparison for Last Month)
            $date3month = date( 'Y-m-d', strtotime( 'first day of -2 month' ) );
            $date3monthend = date( 'Y-m-d', strtotime( 'last day of -2 month' ) );
            $pastoldmonthorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date3month,
                $date3monthend,
                '',
                1,
                0,
                0
            );
            $this7orders = $pastmonthorders;
            $past14orders = $pastoldmonthorders;
            // Clear monthly caches
            delete_post_meta( $coupon_post_id, 'wcusage_monthly_summary_data_orders' );
            delete_post_meta( $coupon_post_id, 'wcusage_monthly_cache_time_current' );
            delete_post_meta( $coupon_post_id, 'wcusage_monthly_summary_data' );
            // Rebuild monthly summary cache
            $wcusage_monthly_summary_data_orders = array();
            $wcusage_monthly_summary_data_orders[strtotime( $date1month )] = $thismonthorders;
            $wcusage_monthly_summary_data_orders[strtotime( $date2month )] = $pastmonthorders;
            $wcusage_monthly_summary_data_orders[strtotime( $date3month )] = $pastoldmonthorders;
            update_post_meta( $coupon_post_id, 'wcusage_monthly_summary_data_orders', $wcusage_monthly_summary_data_orders );
            update_post_meta( $coupon_post_id, 'wcusage_monthly_cache_time_current', time() );
        }
        // ============================================================
        // 3. Render the updated info boxes HTML for each toggle
        // ============================================================
        // Ensure monthly/period orders are valid arrays (with zeroes) so the info boxes
        // function never hits its get_post_meta fallback (which would show all-time stats).
        // We still pass $coupon_post_id so $hide_commission is evaluated correctly.
        $empty_orders = array(
            'total_orders'     => 0,
            'full_discount'    => 0,
            'total_commission' => 0,
            'total_shipping'   => 0,
            'total_count'      => 0,
        );
        if ( !is_array( $thismonthorders ) || empty( $thismonthorders ) ) {
            $thismonthorders = $empty_orders;
        }
        if ( !is_array( $this7orders ) || empty( $this7orders ) ) {
            $this7orders = $empty_orders;
        }
        if ( !is_array( $past60orders ) ) {
            $past60orders = '';
        }
        if ( !is_array( $past14orders ) ) {
            $past14orders = '';
        }
        // All-time HTML
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $fullorders,
            '',
            $combined_commission,
            $coupon_post_id
        );
        $html_alltime = ob_get_clean();
        // This Month (or Last 30 Days) HTML
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $thismonthorders,
            $past60orders,
            $combined_commission,
            $coupon_post_id
        );
        $html_thismonth = ob_get_clean();
        // Last Month (or Last 7 Days) HTML
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $this7orders,
            $past14orders,
            $combined_commission,
            $coupon_post_id
        );
        $html_lastmonth = ob_get_clean();
        $total_count = 0;
        if ( !empty( $fullorders ) && is_array( $fullorders ) && isset( $fullorders['total_count'] ) ) {
            $total_count = $fullorders['total_count'];
        }
        wp_send_json_success( array(
            'html_alltime'   => $html_alltime,
            'html_thismonth' => $html_thismonth,
            'html_lastmonth' => $html_lastmonth,
            'total_count'    => $total_count,
        ) );
    }

}
add_action( 'wp_ajax_wcusage_refresh_dashboard_stats', 'wcusage_refresh_dashboard_stats' );
add_action( 'wp_ajax_nopriv_wcusage_refresh_dashboard_stats', 'wcusage_refresh_dashboard_stats' );
/**
 * AJAX handler to reload dashboard stats from cached meta (no recalculation).
 * Reads wcu_alltime_stats and wcusage_monthly_summary_data_orders meta and renders info boxes.
 * This is intentionally very fast — just meta reads + HTML rendering.
 */
if ( !function_exists( 'wcusage_reload_dashboard_stats' ) ) {
    function wcusage_reload_dashboard_stats() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        // Require a logged-in user
        if ( !is_user_logged_in() ) {
            wp_send_json_error( esc_html__( 'You must be logged in.', 'woo-coupon-usage' ) );
        }
        $postid = ( isset( $_POST['postid'] ) ? sanitize_text_field( $_POST['postid'] ) : '' );
        $couponcode = ( isset( $_POST['couponcode'] ) ? sanitize_text_field( $_POST['couponcode'] ) : '' );
        list( $resolved_postid, $resolved_code ) = wcusage_ajax_resolve_coupon( $postid, $couponcode );
        if ( !$resolved_postid ) {
            wp_send_json_error( esc_html__( 'Invalid coupon.', 'woo-coupon-usage' ) );
        }
        $coupon_code = strtolower( $resolved_code );
        $couponinfo = wcusage_get_coupon_info( $coupon_code );
        $coupon_post_id = $couponinfo[2];
        $coupon_user_id = intval( $couponinfo[1] );
        $currentuserid = get_current_user_id();
        // Check MLA sub-affiliate access
        $sub_affiliate = false;
        // Check access permissions (strict comparison to prevent type juggling)
        if ( $coupon_user_id !== $currentuserid && !$sub_affiliate && !wcusage_check_admin_access() ) {
            wp_send_json_error( esc_html__( 'You do not have permission to access this data.', 'woo-coupon-usage' ) );
        }
        $wcusage_field_which_toggle = wcusage_get_setting_value( 'wcusage_field_which_toggle', '1' );
        $combined_commission = '';
        if ( function_exists( 'wcusage_commission_message' ) ) {
            $combined_commission = wcusage_commission_message( $coupon_post_id );
        }
        // Read all-time stats from meta
        $allstats = get_post_meta( $coupon_post_id, 'wcu_alltime_stats', true );
        $fullorders = ( is_array( $allstats ) ? $allstats : array() );
        // Read monthly/period stats from cache
        $thismonthorders = '';
        $past60orders = '';
        $this7orders = '';
        $past14orders = '';
        if ( !$wcusage_field_which_toggle ) {
            // Days mode — read from standard cache (refresh=0 uses cache)
            $date7 = date( 'Y-m-d', strtotime( '-7 days' ) );
            $date14 = date( 'Y-m-d', strtotime( '-14 days' ) );
            $date30 = date( 'Y-m-d', strtotime( '-30 days' ) );
            $date60 = date( 'Y-m-d', strtotime( '-60 days' ) );
            $thismonthorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date30,
                date( 'Y-m-d' ),
                '',
                0,
                0,
                0
            );
            $past60orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date60,
                $date30,
                '',
                0,
                0,
                0
            );
            $this7orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date7,
                date( 'Y-m-d' ),
                '',
                0,
                0,
                0
            );
            $past14orders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $date14,
                $date7,
                '',
                0,
                0,
                0
            );
        } else {
            // Monthly mode — read from cached monthly summary meta, with fallback query
            $monthly_data = get_post_meta( $coupon_post_id, 'wcusage_monthly_summary_data_orders', true );
            if ( !is_array( $monthly_data ) ) {
                $monthly_data = array();
            }
            $date1month = date( 'Y-m-01' );
            $date2month = date( 'Y-m-d', strtotime( 'first day of last month' ) );
            $date2monthend = date( 'Y-m-d', strtotime( 'last day of last month' ) );
            $date3month = date( 'Y-m-d', strtotime( 'first day of -2 month' ) );
            $date3monthend = date( 'Y-m-d', strtotime( 'last day of -2 month' ) );
            // This Month — use cached if still within 10-min TTL, otherwise re-query
            $current_month_cache_time = get_post_meta( $coupon_post_id, 'wcusage_monthly_cache_time_current', true );
            $current_month_cache_expired = !$current_month_cache_time || time() - (int) $current_month_cache_time > 600;
            if ( !empty( $monthly_data[strtotime( $date1month )] ) && !$current_month_cache_expired ) {
                $thismonthorders = $monthly_data[strtotime( $date1month )];
            } else {
                $thismonthorders = wcusage_wh_getOrderbyCouponCode(
                    $coupon_code,
                    $date1month,
                    date( 'Y-m-d' ),
                    '',
                    1,
                    0,
                    0
                );
                $monthly_data[strtotime( $date1month )] = $thismonthorders;
                update_post_meta( $coupon_post_id, 'wcusage_monthly_cache_time_current', time() );
            }
            // Last Month — cache permanently (past months don't change)
            if ( !empty( $monthly_data[strtotime( $date2month )] ) ) {
                $pastmonthorders = $monthly_data[strtotime( $date2month )];
            } else {
                $pastmonthorders = wcusage_wh_getOrderbyCouponCode(
                    $coupon_code,
                    $date2month,
                    $date2monthend,
                    '',
                    1,
                    0,
                    0
                );
                $monthly_data[strtotime( $date2month )] = $pastmonthorders;
            }
            // 2 Months Ago — cache permanently (past months don't change)
            if ( !empty( $monthly_data[strtotime( $date3month )] ) ) {
                $pastoldmonthorders = $monthly_data[strtotime( $date3month )];
            } else {
                $pastoldmonthorders = wcusage_wh_getOrderbyCouponCode(
                    $coupon_code,
                    $date3month,
                    $date3monthend,
                    '',
                    1,
                    0,
                    0
                );
                $monthly_data[strtotime( $date3month )] = $pastoldmonthorders;
            }
            // Persist any newly fetched monthly data
            update_post_meta( $coupon_post_id, 'wcusage_monthly_summary_data_orders', $monthly_data );
            $past60orders = $pastmonthorders;
            $this7orders = $pastmonthorders;
            $past14orders = $pastoldmonthorders;
        }
        // Ensure monthly/period orders are valid arrays (with zeroes) so the info boxes
        // function never hits its get_post_meta fallback (which would show all-time stats).
        // We still pass $coupon_post_id so $hide_commission is evaluated correctly.
        $empty_orders = array(
            'total_orders'     => 0,
            'full_discount'    => 0,
            'total_commission' => 0,
            'total_shipping'   => 0,
            'total_count'      => 0,
        );
        if ( !is_array( $thismonthorders ) || empty( $thismonthorders ) ) {
            $thismonthorders = $empty_orders;
        }
        if ( !is_array( $this7orders ) || empty( $this7orders ) ) {
            $this7orders = $empty_orders;
        }
        if ( !is_array( $past60orders ) ) {
            $past60orders = '';
        }
        if ( !is_array( $past14orders ) ) {
            $past14orders = '';
        }
        // Render info boxes HTML
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $fullorders,
            '',
            $combined_commission,
            $coupon_post_id
        );
        $html_alltime = ob_get_clean();
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $thismonthorders,
            $past60orders,
            $combined_commission,
            $coupon_post_id
        );
        $html_thismonth = ob_get_clean();
        ob_start();
        do_action(
            'wcusage_hook_get_main_info_boxes',
            $this7orders,
            $past14orders,
            $combined_commission,
            $coupon_post_id
        );
        $html_lastmonth = ob_get_clean();
        $total_count = 0;
        if ( !empty( $fullorders ) && is_array( $fullorders ) && isset( $fullorders['total_count'] ) ) {
            $total_count = $fullorders['total_count'];
        }
        // Render Latest Referrals HTML
        $html_latest_referrals = '';
        if ( wcusage_get_setting_value( 'wcusage_field_statistics_latest', '1' ) ) {
            $latest_referrals_start = date( 'Y-m-d', strtotime( '-90 days' ) );
            ob_start();
            do_action(
                'wcusage_hook_tab_latest_orders',
                $coupon_post_id,
                $coupon_code,
                $latest_referrals_start,
                date( 'Y-m-d' ),
                false,
                '',
                5,
                true,
                false
            );
            $html_latest_referrals = ob_get_clean();
        }
        wp_send_json_success( array(
            'html_alltime'          => $html_alltime,
            'html_thismonth'        => $html_thismonth,
            'html_lastmonth'        => $html_lastmonth,
            'total_count'           => $total_count,
            'html_latest_referrals' => $html_latest_referrals,
        ) );
    }

}
add_action( 'wp_ajax_wcusage_reload_dashboard_stats', 'wcusage_reload_dashboard_stats' );
add_action( 'wp_ajax_nopriv_wcusage_reload_dashboard_stats', 'wcusage_reload_dashboard_stats' );