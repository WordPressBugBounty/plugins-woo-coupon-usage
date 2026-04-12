<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Modern AJAX handler for admin reports.
 * Returns JSON data instead of HTML for better performance and flexibility.
 */
function wcusage_load_admin_reports() {
    check_ajax_referer('wcusage_admin_ajax_nonce');

    if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
        wp_send_json_error( array( 'message' => __( 'Access denied.', 'woo-coupon-usage' ) ), 403 );
    }

    global $wpdb;
    $options = get_option('wcusage_options');
    $is_pro = wcu_fs()->can_use_premium_code();

    // Sanitize inputs
    $wcu_orders_start = sanitize_text_field( $_POST['wcu_orders_start'] ?? '' );
    $wcu_orders_end = sanitize_text_field( $_POST['wcu_orders_end'] ?? '' );
    $wcu_compare = sanitize_text_field( $_POST['wcu_compare'] ?? 'false' );
    $wcu_orders_start_compare = sanitize_text_field( $_POST['wcu_orders_start_compare'] ?? '' );
    $wcu_orders_end_compare = sanitize_text_field( $_POST['wcu_orders_end_compare'] ?? '' );
    $wcu_orders_filtercompare_type = sanitize_text_field( $_POST['wcu_orders_filtercompare_type'] ?? 'both' );
    $wcu_orders_filtercompare_amount = intval( $_POST['wcu_orders_filtercompare_amount'] ?? 0 );
    $wcu_orders_filterusage_type = sanitize_text_field( $_POST['wcu_orders_filterusage_type'] ?? 'more or equal' );
    $wcu_orders_filterusage_amount = intval( $_POST['wcu_orders_filterusage_amount'] ?? 0 );
    $wcu_orders_filtersales_type = sanitize_text_field( $_POST['wcu_orders_filtersales_type'] ?? 'more or equal' );
    $wcu_orders_filtersales_amount = floatval( $_POST['wcu_orders_filtersales_amount'] ?? 0 );
    $wcu_orders_filtercommission_type = sanitize_text_field( $_POST['wcu_orders_filtercommission_type'] ?? 'more or equal' );
    $wcu_orders_filtercommission_amount = floatval( $_POST['wcu_orders_filtercommission_amount'] ?? 0 );
    $wcu_orders_filterconversions_type = sanitize_text_field( $_POST['wcu_orders_filterconversions_type'] ?? 'more or equal' );
    $wcu_orders_filterconversions_amount = floatval( $_POST['wcu_orders_filterconversions_amount'] ?? 0 );
    $wcu_orders_filterunpaid_type = sanitize_text_field( $_POST['wcu_orders_filterunpaid_type'] ?? 'more or equal' );
    $wcu_orders_filterunpaid_amount = floatval( $_POST['wcu_orders_filterunpaid_amount'] ?? 0 );
    $wcu_report_users_only = sanitize_text_field( $_POST['wcu_report_users_only'] ?? 'false' );
    $wcu_report_user_roles = isset($_POST['wcu_report_user_roles']) ? array_map('sanitize_text_field', (array)$_POST['wcu_report_user_roles']) : array();
    $wcu_report_group_role = sanitize_text_field( $_POST['wcu_report_group_role'] ?? '' );
    $wcu_report_show_sales = sanitize_text_field( $_POST['wcu_report_show_sales'] ?? 'true' );
    $wcu_report_show_commission = sanitize_text_field( $_POST['wcu_report_show_commission'] ?? 'true' );
    $wcu_report_show_url = sanitize_text_field( $_POST['wcu_report_show_url'] ?? 'true' );
    $wcu_report_show_products = sanitize_text_field( $_POST['wcu_report_show_products'] ?? 'true' );

    // Free version date restrictions
    if (!$is_pro) {
        if (strtotime($wcu_orders_start) < strtotime("-3 months") || !$wcu_orders_start) {
            $wcu_orders_start = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
        }
        if ( strtotime( $wcu_orders_end ) > strtotime( 'now' ) || ! $wcu_orders_end ) {
            $wcu_orders_end = gmdate( 'Y-m-d' );
        }
        $wcu_compare = 'false';
    }

    // ======= GET COUPONS =======
    $args = [
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'asc',
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'query_timestamp' => time(),
    ];
    if ($wcu_report_users_only === 'true') {
        $args['meta_query'] = [
            [
                'key'     => 'wcu_select_coupon_user',
                'value'   => '',
                'compare' => '!='
            ]
        ];
    }
    $coupons = get_posts($args);
    $coupons = array_unique($coupons, SORT_REGULAR);

    // Filter by user roles if specified
    if ($wcu_report_users_only === 'true' && !empty($wcu_report_user_roles)) {
        $filtered_coupons = [];
        foreach ($coupons as $coupon) {
            $user_id = get_post_meta($coupon->ID, 'wcu_select_coupon_user', true);
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user && array_intersect($wcu_report_user_roles, $user->roles)) {
                    $filtered_coupons[] = $coupon;
                }
            }
        }
        $coupons = $filtered_coupons;
    }

    // Filter by affiliate group (role) — works independently of affiliates-only toggle
    if (!empty($wcu_report_group_role)) {
        $filtered_by_group = [];
        foreach ($coupons as $coupon) {
            $user_id = get_post_meta($coupon->ID, 'wcu_select_coupon_user', true);
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user && in_array($wcu_report_group_role, (array)$user->roles, true)) {
                    $filtered_by_group[] = $coupon;
                }
            }
        }
        $coupons = $filtered_by_group;
    }

    // Initialize coupon stats
    $coupon_stats = [];
    foreach ($coupons as $coupon) {
        $coupon_code = strtolower($coupon->post_title);
        // Per-coupon start date (matches dashboard logic in functions-coupon-orders.php)
        $coupon_start_date = get_post_meta($coupon->ID, 'wcu_text_coupon_start_date', true);
        $coupon_start_date_gmt = $coupon_start_date ? wcusage_convert_date_to_gmt($coupon_start_date, 0) : '';
        $coupon_stats[$coupon_code] = [
            'id'                    => $coupon->ID,
            'coupon_start_date_gmt' => $coupon_start_date_gmt,
            'total_count'           => 0,
            'total_orders'          => 0.00,
            'total_commission'      => 0.00,
            'full_discount'         => 0.00,
            'list_of_products'      => [],
            'total_count_compare'   => 0,
            'total_orders_compare'  => 0.00,
            'total_commission_compare' => 0.00,
            'full_discount_compare' => 0.00,
            'clickcount'            => 0,
            'convertedcount'        => 0,
            'conversionrate'        => 0,
            'clickcount_compare'    => 0,
            'convertedcount_compare' => 0,
            'conversionrate_compare' => 0,
            'unpaid_commission'     => 0.00,
            'pending_payments'      => 0.00,
            'user_id'               => 0,
            'uniqueurl'             => '',
            'edit_url'              => '',
        ];
    }

    // ======= TIMESERIES BUCKETS (daily) =======
    $timeseries = [];
    $ts_start = new DateTime($wcu_orders_start);
    $ts_end   = new DateTime($wcu_orders_end);
    $ts_end->modify('+1 day'); // include end date
    $interval = new DateInterval('P1D');
    $period   = new DatePeriod($ts_start, $interval, $ts_end);
    foreach ($period as $day) {
        $key = $day->format('Y-m-d');
        $timeseries[$key] = [
            'usage'      => 0,
            'sales'      => 0.00,
            'commission' => 0.00,
            'discounts'  => 0.00,
        ];
    }

    // ======= DETERMINE DB TABLES (HPOS COMPAT) =======
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && OrderUtil::custom_orders_table_usage_is_enabled()) {
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

    // ======= GET ORDER STATUSES =======
    $wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');
    if(!$wcusage_field_order_type_custom) {
        $statuses = wc_get_order_statuses();
        unset($statuses['wc-refunded']);
    } else {
        $statuses = $wcusage_field_order_type_custom;
    }
    $status_keys = array_map(function($k) { return sanitize_text_field($k); }, array_keys($statuses));
    $status_list = "'" . implode("','", array_map('esc_sql', $status_keys)) . "'";

    // ======= FETCH ORDERS FOR MAIN DATE RANGE =======
    $start_date_gmt = wcusage_convert_date_to_gmt($wcu_orders_start, 0);
    $end_date_gmt = wcusage_convert_date_to_gmt($wcu_orders_end, 1);

    $query = $wpdb->prepare(
        "SELECT DISTINCT p.$id AS order_id, p.$post_date AS order_date
        FROM {$wpdb->prefix}$posts AS p
        LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS woi
            ON p.$id = woi.order_id AND woi.order_item_type = 'coupon'
        LEFT JOIN {$wpdb->prefix}$postmeta AS woi2
            ON p.$id = woi2.$post_id AND (
                woi2.meta_key = 'lifetime_affiliate_coupon_referrer' OR
                woi2.meta_key = 'wcusage_referrer_coupon'
            )
        WHERE p.$post_status IN ($status_list)
        AND (woi.order_id IS NOT NULL OR woi2.meta_key IS NOT NULL)
        AND p.$post_date BETWEEN %s AND %s",
        $start_date_gmt,
        $end_date_gmt
    ); // phpcs:ignore
    $orders = $wpdb->get_results($query); // phpcs:ignore

    // Suspend cache addition to prevent memory issues
    $previous_cache_state = wp_suspend_cache_addition(true);

    // Process orders
    foreach ($orders as $order_data) {
        try {
            $order_id = $order_data->order_id;
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $lifetime_coupon = strtolower(get_post_meta($order_id, 'lifetime_affiliate_coupon_referrer', true));
            $referrer_coupon = strtolower(get_post_meta($order_id, 'wcusage_referrer_coupon', true));
            $applied_coupons = array_map('strtolower', $order->get_coupon_codes());

            $renewalcheck = wcusage_check_if_renewal_allowed($order_id);
            if (!$renewalcheck) continue;

            // Check order status matches allowed statuses (same as affiliate dashboard)
            $theorderstatus = $order->get_status();
            if ($theorderstatus !== 'completed' && !wcusage_check_status_show($theorderstatus)) continue;

            // Match dashboard logic: if lifetime referrer is set, only that coupon gets credit
            if ($lifetime_coupon) {
                if (isset($coupon_stats[$lifetime_coupon])) {
                    $coupon_to_use = $lifetime_coupon;
                } else {
                    $order = null;
                    continue; // Lifetime referrer set but not in our coupon list — skip entirely
                }
            // If referrer coupon is set (no lifetime), only that coupon gets credit
            } elseif ($referrer_coupon) {
                if (isset($coupon_stats[$referrer_coupon])) {
                    $coupon_to_use = $referrer_coupon;
                } else {
                    $order = null;
                    continue; // Referrer set but not in our coupon list — skip entirely
                }
            } else {
                $relevant_coupons = array_intersect($applied_coupons, array_keys($coupon_stats));
                if (empty($relevant_coupons)) continue;
                foreach ($relevant_coupons as $coupon_code) {
                    // Skip if order is before this coupon's start date
                    if (!empty($coupon_stats[$coupon_code]['coupon_start_date_gmt'])
                        && strtotime($order_data->order_date) < strtotime($coupon_stats[$coupon_code]['coupon_start_date_gmt'])) {
                        continue;
                    }
                    $calculateorder = wcusage_calculate_order_data($order_id, $coupon_code, 0, 1);
                    if (isset($calculateorder['totalorders']) && (float)$calculateorder['totalorders'] > 0) {
                        $coupon_stats[$coupon_code]['total_count'] += 1;
                        $coupon_stats[$coupon_code]['total_orders'] += (float)$calculateorder['totalorders'];
                        $coupon_stats[$coupon_code]['total_commission'] += (float)$calculateorder['totalcommission'];
                        $coupon_stats[$coupon_code]['full_discount'] += (float)$calculateorder['totaldiscounts'];
                        // Bucket into timeseries
                        $order_local_date = wcusage_convert_date_from_gmt($order_data->order_date);
                        $bucket_key = substr($order_local_date, 0, 10);
                        if (isset($timeseries[$bucket_key])) {
                            $timeseries[$bucket_key]['usage']      += 1;
                            $timeseries[$bucket_key]['sales']      += (float)$calculateorder['totalorders'] - (float)$calculateorder['totaldiscounts'];
                            $timeseries[$bucket_key]['commission'] += (float)$calculateorder['totalcommission'];
                            $timeseries[$bucket_key]['discounts']  += (float)$calculateorder['totaldiscounts'];
                        }
                    }
                    // Products
                    if (isset($calculateorder['totalorders']) && (float)$calculateorder['totalorders'] > 0 && $wcu_report_show_products !== 'false') {
                        $product_ids = $order->get_items();
                        foreach ($product_ids as $product_data) {
                            $product = wc_get_product($product_data['product_id']);
                            $product_name = $product ? $product->get_name() : __('Unknown Product', 'woo-coupon-usage');
                            $product_qty = $product_data['quantity'];
                            if(!isset($coupon_stats[$coupon_code]['list_of_products'][$product_name])) {
                                $coupon_stats[$coupon_code]['list_of_products'][$product_name] = 0;
                            }
                            $coupon_stats[$coupon_code]['list_of_products'][$product_name] += $product_qty;
                        }
                    }
                }
                $order = null;
                continue;
            }

            // Skip if order is before this coupon's start date (matches dashboard logic)
            if (!empty($coupon_stats[$coupon_to_use]['coupon_start_date_gmt'])
                && strtotime($order_data->order_date) < strtotime($coupon_stats[$coupon_to_use]['coupon_start_date_gmt'])) {
                $order = null;
                continue;
            }

            // Process order with identified coupon
            $calculateorder = wcusage_calculate_order_data($order_id, $coupon_to_use, 0, 1);
            if (isset($calculateorder['totalorders']) && (float)$calculateorder['totalorders'] > 0) {
                $coupon_stats[$coupon_to_use]['total_count'] += 1;
                $coupon_stats[$coupon_to_use]['total_orders'] += (float)$calculateorder['totalorders'];
                $coupon_stats[$coupon_to_use]['total_commission'] += (float)$calculateorder['totalcommission'];
                $coupon_stats[$coupon_to_use]['full_discount'] += (float)$calculateorder['totaldiscounts'];
                // Bucket into timeseries
                $order_local_date = wcusage_convert_date_from_gmt($order_data->order_date);
                $bucket_key = substr($order_local_date, 0, 10);
                if (isset($timeseries[$bucket_key])) {
                    $timeseries[$bucket_key]['usage']      += 1;
                    $timeseries[$bucket_key]['sales']      += (float)$calculateorder['totalorders'] - (float)$calculateorder['totaldiscounts'];
                    $timeseries[$bucket_key]['commission'] += (float)$calculateorder['totalcommission'];
                    $timeseries[$bucket_key]['discounts']  += (float)$calculateorder['totaldiscounts'];
                }
            }

            // Products
            if (isset($calculateorder['totalorders']) && (float)$calculateorder['totalorders'] > 0 && $wcu_report_show_products !== 'false') {
                $product_ids = $order->get_items();
                foreach ($product_ids as $product_data) {
                    $product = wc_get_product($product_data['product_id']);
                    $product_name = $product ? $product->get_name() : __('Unknown Product', 'woo-coupon-usage');
                    $product_qty = $product_data['quantity'];
                    if(!isset($coupon_stats[$coupon_to_use]['list_of_products'][$product_name])) {
                        $coupon_stats[$coupon_to_use]['list_of_products'][$product_name] = 0;
                    }
                    $coupon_stats[$coupon_to_use]['list_of_products'][$product_name] += $product_qty;
                }
            }

            $order = null;
        } catch (Exception $e) {
            continue;
        } catch (Throwable $e) {
            continue;
        }
    }

    wp_suspend_cache_addition($previous_cache_state);

    // ======= COMPARISON DATE RANGE =======
    if ($wcu_compare === 'true' && $is_pro && wcu_fs()->is__premium_only()) {
        $start_date_compare_gmt = wcusage_convert_date_to_gmt($wcu_orders_start_compare, 0);
        $end_date_compare_gmt = wcusage_convert_date_to_gmt($wcu_orders_end_compare, 1);

        $query_compare = $wpdb->prepare(
            "SELECT DISTINCT p.$id AS order_id, p.$post_date AS order_date
            FROM {$wpdb->prefix}$posts AS p
            LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS woi
                ON p.$id = woi.order_id AND woi.order_item_type = 'coupon'
            LEFT JOIN {$wpdb->prefix}$postmeta AS woi2
                ON p.$id = woi2.$post_id AND (
                    woi2.meta_key = 'lifetime_affiliate_coupon_referrer' OR
                    woi2.meta_key = 'wcusage_referrer_coupon'
                )
            WHERE p.$post_status IN ($status_list)
            AND (woi.order_id IS NOT NULL OR woi2.meta_key IS NOT NULL)
            AND p.$post_date BETWEEN %s AND %s",
            $start_date_compare_gmt,
            $end_date_compare_gmt
        ); // phpcs:ignore
        $orders_compare = $wpdb->get_results($query_compare); // phpcs:ignore

        $previous_cache_state = wp_suspend_cache_addition(true);

        foreach ($orders_compare as $order_data) {
            try {
                $order_id = $order_data->order_id;
                $order = wc_get_order($order_id);
                if (!$order) continue;

                $renewalcheck = wcusage_check_if_renewal_allowed($order_id);
                if (!$renewalcheck) continue;

                // Check order status matches allowed statuses (same as affiliate dashboard)
                $theorderstatus = $order->get_status();
                if ($theorderstatus !== 'completed' && !wcusage_check_status_show($theorderstatus)) continue;

                $applied_coupons = array_map('strtolower', $order->get_coupon_codes());
                $lifetime_coupon = strtolower(get_post_meta($order_id, 'lifetime_affiliate_coupon_referrer', true));
                $referrer_coupon = strtolower(get_post_meta($order_id, 'wcusage_referrer_coupon', true));

                // Match dashboard logic: if lifetime/referrer is set, only that coupon gets credit
                if ($lifetime_coupon) {
                    if (!isset($coupon_stats[$lifetime_coupon])) { $order = null; continue; }
                    $relevant_coupons = [$lifetime_coupon];
                } elseif ($referrer_coupon) {
                    if (!isset($coupon_stats[$referrer_coupon])) { $order = null; continue; }
                    $relevant_coupons = [$referrer_coupon];
                } else {
                    $relevant_coupons = array_intersect($applied_coupons, array_keys($coupon_stats));
                    if (empty($relevant_coupons)) { $order = null; continue; }
                }

                foreach ($relevant_coupons as $coupon_code) {
                    // Skip if order is before this coupon's start date
                    if (!empty($coupon_stats[$coupon_code]['coupon_start_date_gmt'])
                        && strtotime($order_data->order_date) < strtotime($coupon_stats[$coupon_code]['coupon_start_date_gmt'])) {
                        continue;
                    }
                    $calculateorder = wcusage_calculate_order_data($order_id, $coupon_code, 0, 1);
                    if (isset($calculateorder['totalorders']) && (float)$calculateorder['totalorders'] > 0) {
                        $coupon_stats[$coupon_code]['total_count_compare'] += 1;
                        $coupon_stats[$coupon_code]['total_orders_compare'] += (float)$calculateorder['totalorders'];
                        $coupon_stats[$coupon_code]['total_commission_compare'] += (float)$calculateorder['totalcommission'];
                        $coupon_stats[$coupon_code]['full_discount_compare'] += (float)$calculateorder['totaldiscounts'];
                    }
                }
                $order = null;
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
        }
        wp_suspend_cache_addition($previous_cache_state);
    }

    // ======= AGGREGATE STATS & BUILD RESPONSE =======
    $totals = [
        'total_usage'        => 0,
        'total_sales'        => 0.00,
        'total_discounts'    => 0.00,
        'total_commission'   => 0.00,
        'unpaid_commission'  => 0.00,
        'pending_commission' => 0.00,
        'total_clicks'       => 0,
        'total_conversions'  => 0,
        'conversion_rate'    => 0,
        // Compare period raw totals
        'cmp_usage'          => 0,
        'cmp_sales'          => 0.00,
        'cmp_discounts'      => 0.00,
        'cmp_commission'     => 0.00,
        'cmp_clicks'         => 0,
        'cmp_conversions'    => 0,
    ];

    $coupon_rows = [];
    $wcusage_field_tracking_enable = wcusage_get_setting_value('wcusage_field_tracking_enable', 1);

    foreach ($coupons as $coupon) {
        $coupon_code = strtolower($coupon->post_title);
        $coupon_id = $coupon->ID;

        // Get coupon info
        $coupon_info = wcusage_get_coupon_info_by_id($coupon_id);
        $coupon_stats[$coupon_code]['user_id'] = $coupon_info[1];
        $coupon_stats[$coupon_code]['unpaid_commission'] = $coupon_info[2] ?: 0.00;
        $coupon_stats[$coupon_code]['uniqueurl'] = $coupon_info[4];
        $coupon_stats[$coupon_code]['pending_payments'] = get_post_meta($coupon_id, 'wcu_text_pending_payment_commission', true) ?: 0.00;
        $coupon_stats[$coupon_code]['edit_url'] = get_edit_post_link($coupon_id, '');

        // URL stats
        if ($wcu_report_show_url !== 'false') {
            $url_stats = wcusage_get_url_stats($coupon_id, $wcu_orders_start, $wcu_orders_end);
            $coupon_stats[$coupon_code]['clickcount'] = $url_stats['clicks'];
            $coupon_stats[$coupon_code]['convertedcount'] = $url_stats['convertedcount'];
            $coupon_stats[$coupon_code]['conversionrate'] = $url_stats['conversionrate'];

            if ($wcu_compare === 'true' && $is_pro) {
                $url_stats_compare = wcusage_get_url_stats($coupon_id, $wcu_orders_start_compare, $wcu_orders_end_compare);
                $coupon_stats[$coupon_code]['clickcount_compare'] = $url_stats_compare['clicks'];
                $coupon_stats[$coupon_code]['convertedcount_compare'] = $url_stats_compare['convertedcount'];
                $coupon_stats[$coupon_code]['conversionrate_compare'] = $url_stats_compare['conversionrate'];
            }
        }

        // User data
        $username = '—';
        $avatar_url = '';
        if ($coupon_stats[$coupon_code]['user_id']) {
            $user_info = get_userdata($coupon_stats[$coupon_code]['user_id']);
            if ($user_info) {
                $username = $user_info->user_login;
                $avatar_url = get_avatar_url($user_info->ID, array('size' => 48, 'default' => 'identicon'));
            }
        }

        // ======= APPLY FILTERS =======
        $show = true;
        $cs = $coupon_stats[$coupon_code];

        if ($wcu_report_users_only === 'true' && !$cs['user_id']) {
            $show = false;
        }

        // Comparison filter
        if ($show && $wcu_compare === 'true' && $is_pro) {
            $diff_num = wcusage_getPercentageChangeNum($cs['total_orders'] - $cs['full_discount'], $cs['total_orders_compare'] - $cs['full_discount_compare']);
            if ($wcu_orders_filtercompare_type === 'more' && $wcu_orders_filtercompare_amount >= $diff_num) {
                $show = false;
            }
            if ($wcu_orders_filtercompare_type === 'less' && -abs($wcu_orders_filtercompare_amount) <= $diff_num) {
                $show = false;
            }
        }

        // Usage filter
        if ($show) $show = wcusage_report_check_filter($cs['total_count'], $wcu_orders_filterusage_type, $wcu_orders_filterusage_amount);
        // Sales filter
        if ($show) $show = wcusage_report_check_filter($cs['total_orders'] - $cs['full_discount'], $wcu_orders_filtersales_type, $wcu_orders_filtersales_amount);
        // Commission filter
        if ($show) $show = wcusage_report_check_filter($cs['total_commission'], $wcu_orders_filtercommission_type, $wcu_orders_filtercommission_amount);
        // Conversion rate filter
        if ($show) $show = wcusage_report_check_filter(round($cs['conversionrate'], 2), $wcu_orders_filterconversions_type, $wcu_orders_filterconversions_amount);
        // Unpaid filter
        if ($show) $show = wcusage_report_check_filter($cs['unpaid_commission'], $wcu_orders_filterunpaid_type, $wcu_orders_filterunpaid_amount);

        if ($show) {
            $totals['total_usage'] += $cs['total_count'];
            $totals['total_sales'] += $cs['total_orders'] - $cs['full_discount'];
            $totals['total_discounts'] += $cs['full_discount'];
            $totals['total_commission'] += $cs['total_commission'];
            $totals['unpaid_commission'] += $cs['unpaid_commission'];
            $totals['pending_commission'] += $cs['pending_payments'];
            $totals['total_clicks'] += $cs['clickcount'];
            $totals['total_conversions'] += $cs['convertedcount'];
            if ($wcu_compare === 'true' && $is_pro) {
                $totals['cmp_usage']       += $cs['total_count_compare'];
                $totals['cmp_sales']       += $cs['total_orders_compare'] - $cs['full_discount_compare'];
                $totals['cmp_discounts']   += $cs['full_discount_compare'];
                $totals['cmp_commission']  += $cs['total_commission_compare'];
                $totals['cmp_clicks']      += $cs['clickcount_compare'];
                $totals['cmp_conversions'] += $cs['convertedcount_compare'];
            }

            // Build comparison data if applicable
            $compare_data = null;
            if ($wcu_compare === 'true' && $is_pro) {
                $compare_data = [
                    'usage'       => wcusage_getPercentageChangeNum($cs['total_count'], $cs['total_count_compare']),
                    'sales'       => wcusage_getPercentageChangeNum($cs['total_orders'] - $cs['full_discount'], $cs['total_orders_compare'] - $cs['full_discount_compare']),
                    'commission'  => wcusage_getPercentageChangeNum($cs['total_commission'], $cs['total_commission_compare']),
                    'discounts'   => wcusage_getPercentageChangeNum($cs['full_discount'], $cs['full_discount_compare']),
                    'clicks'      => wcusage_getPercentageChangeNum($cs['clickcount'], $cs['clickcount_compare']),
                    'conversions' => wcusage_getPercentageChangeNum($cs['convertedcount'], $cs['convertedcount_compare']),
                    'convrate'    => wcusage_getPercentageChangeNum($cs['conversionrate'], $cs['conversionrate_compare']),
                ];
            }

            // Top 5 products only (for performance)
            $products = $cs['list_of_products'];
            arsort($products);
            $products = array_slice($products, 0, 5, true);

            $coupon_rows[] = [
                'code'            => $coupon_code,
                'id'              => $coupon_id,
                'username'        => $username,
                'user_id'         => $cs['user_id'],
                'avatar_url'      => $avatar_url,
                'usage'           => $cs['total_count'],
                'sales'           => round($cs['total_orders'] - $cs['full_discount'], 2),
                'discounts'       => round($cs['full_discount'], 2),
                'commission'      => round($cs['total_commission'], 2),
                'unpaid'          => round((float)$cs['unpaid_commission'], 2),
                'pending'         => round((float)$cs['pending_payments'], 2),
                'clicks'          => $cs['clickcount'],
                'conversions'     => $cs['convertedcount'],
                'conversionrate'  => round($cs['conversionrate'], 2),
                'products'        => $products,
                'dashboard_url'   => $cs['uniqueurl'],
                'edit_url'        => $cs['edit_url'],
                'compare'         => $compare_data,
            ];
        }
    }

    // Final totals
    $totals['total_sales'] = round($totals['total_sales'], 2);
    $totals['total_discounts'] = round($totals['total_discounts'], 2);
    $totals['total_commission'] = round($totals['total_commission'], 2);
    $totals['unpaid_commission'] = round($totals['unpaid_commission'], 2);
    $totals['pending_commission'] = round($totals['pending_commission'], 2);
    $totals['conversion_rate'] = $totals['total_clicks'] > 0
        ? round($totals['total_conversions'] / $totals['total_clicks'] * 100, 2) : 0;
    $totals['avg_order_value'] = $totals['total_usage'] > 0
        ? round($totals['total_sales'] / $totals['total_usage'], 2) : 0;
    $totals['commission_rate'] = $totals['total_sales'] > 0
        ? round($totals['total_commission'] / $totals['total_sales'] * 100, 2) : 0;

    // Compare % changes for totals
    $totals_compare = null;
    if ($wcu_compare === 'true' && $is_pro) {
        $cmp_conv_rate = $totals['cmp_clicks'] > 0
            ? round($totals['cmp_conversions'] / $totals['cmp_clicks'] * 100, 2) : 0;
        $cmp_avg_order = $totals['cmp_usage'] > 0
            ? round($totals['cmp_sales'] / $totals['cmp_usage'], 2) : 0;
        $cmp_commission_rate = $totals['cmp_sales'] > 0
            ? round($totals['cmp_commission'] / $totals['cmp_sales'] * 100, 2) : 0;
        $totals_compare = [
            'usage'           => wcusage_getPercentageChangeNum($totals['total_usage'],      $totals['cmp_usage']),
            'sales'           => wcusage_getPercentageChangeNum($totals['total_sales'],       $totals['cmp_sales']),
            'discounts'       => wcusage_getPercentageChangeNum($totals['total_discounts'],   $totals['cmp_discounts']),
            'commission'      => wcusage_getPercentageChangeNum($totals['total_commission'],  $totals['cmp_commission']),
            'clicks'          => wcusage_getPercentageChangeNum($totals['total_clicks'],      $totals['cmp_clicks']),
            'conversions'     => wcusage_getPercentageChangeNum($totals['total_conversions'], $totals['cmp_conversions']),
            'conversion_rate' => wcusage_getPercentageChangeNum($totals['conversion_rate'],   $cmp_conv_rate),
            'avg_order'       => wcusage_getPercentageChangeNum($totals['avg_order_value'],   $cmp_avg_order),
            'commission_rate' => wcusage_getPercentageChangeNum($totals['commission_rate'],   $cmp_commission_rate),
        ];
    }

    // ======= ACTIVITY LOG COUNTS (PRO only) =======
    $activity_counts = array();
    if ( $is_pro ) {
        $activity_table = $wpdb->prefix . 'wcusage_activity';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) ) === $activity_table ) { // phpcs:ignore
            $activity_events = array(
                'referral', 'registration', 'registration_accept',
                'commission_added', 'commission_removed',
                'payout_request', 'payout_paid', 'payout_reversed',
                'reward_earned', 'new_campaign',
                'mla_commission_added', 'direct_link_domain', 'mla_invite',
            );
            $placeholders = implode( ',', array_fill( 0, count( $activity_events ), '%s' ) );
            $query_args   = $activity_events;
            $query_args[] = $wcu_orders_start . ' 00:00:00';
            $query_args[] = $wcu_orders_end . ' 23:59:59';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT event, COUNT(*) as cnt FROM $activity_table WHERE event IN ($placeholders) AND date BETWEEN %s AND %s GROUP BY event", // phpcs:ignore
                    $query_args
                )
            ); // phpcs:ignore
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    $activity_counts[ $row->event ] = intval( $row->cnt );
                }
            }
        }
    }

    // ======= TRAFFIC SOURCES (click analytics) =======
    $traffic_sources = array();
    if ($wcu_report_show_url !== 'false') {
        $clicks_table = $wpdb->prefix . 'wcusage_clicks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clicks_table ) ) === $clicks_table ) { // phpcs:ignore
            $click_date_start = $wcu_orders_start . ' 00:00:00';
            $click_date_end   = $wcu_orders_end . ' 23:59:59';

            // Build coupon ID list from visible coupons (to scope traffic to the report's coupons)
            $visible_coupon_ids = array();
            foreach ($coupon_rows as $cr) {
                $visible_coupon_ids[] = intval($cr['id']);
            }

            if (!empty($visible_coupon_ids)) {
                $id_placeholders = implode(',', array_fill(0, count($visible_coupon_ids), '%d'));

                // Top campaigns (PRO only)
                if ($is_pro) {
                    $campaigns_query = $wpdb->prepare(
                        "SELECT campaign, COUNT(*) as clicks, SUM(converted) as conversions
                         FROM $clicks_table
                         WHERE couponid IN ($id_placeholders)
                           AND date BETWEEN %s AND %s
                           AND campaign != ''
                         GROUP BY campaign
                         ORDER BY clicks DESC",
                        array_merge($visible_coupon_ids, array($click_date_start, $click_date_end))
                    );
                    $traffic_sources['campaigns'] = $wpdb->get_results($campaigns_query); // phpcs:ignore
                }

                // Top landing pages
                $pages_query = $wpdb->prepare(
                    "SELECT page, COUNT(*) as clicks, SUM(converted) as conversions
                     FROM $clicks_table
                     WHERE couponid IN ($id_placeholders)
                       AND date BETWEEN %s AND %s
                     GROUP BY page
                     ORDER BY clicks DESC",
                    array_merge($visible_coupon_ids, array($click_date_start, $click_date_end))
                );
                $pages_raw = $wpdb->get_results($pages_query); // phpcs:ignore

                // Resolve page IDs to titles
                $traffic_sources['pages'] = array();
                foreach ($pages_raw as $pg) {
                    $page_id = intval($pg->page);
                    if ($page_id === 0) {
                        $page_title = __('Homepage', 'woo-coupon-usage');
                    } else {
                        $page_title = get_the_title($page_id);
                        if (!$page_title) $page_title = '#' . $page_id;
                    }
                    $traffic_sources['pages'][] = (object) array(
                        'page'        => $page_title,
                        'page_id'     => $page_id,
                        'clicks'      => intval($pg->clicks),
                        'conversions' => intval($pg->conversions),
                    );
                }

                // Top referrers (referring URLs grouped by domain)
                $referrers_query = $wpdb->prepare(
                    "SELECT referrer, COUNT(*) as clicks, SUM(converted) as conversions
                     FROM $clicks_table
                     WHERE couponid IN ($id_placeholders)
                       AND date BETWEEN %s AND %s
                       AND referrer != ''
                     GROUP BY referrer
                     ORDER BY clicks DESC
                     LIMIT 500",
                    array_merge($visible_coupon_ids, array($click_date_start, $click_date_end))
                );
                $referrers_raw = $wpdb->get_results($referrers_query); // phpcs:ignore

                // Aggregate by domain
                $domain_map = array();
                foreach ($referrers_raw as $ref) {
                    $parsed = wp_parse_url($ref->referrer);
                    $domain = isset($parsed['host']) ? strtolower($parsed['host']) : $ref->referrer;
                    // Strip www.
                    $domain = preg_replace('/^www\./', '', $domain);
                    if (!isset($domain_map[$domain])) {
                        $domain_map[$domain] = array('clicks' => 0, 'conversions' => 0);
                    }
                    $domain_map[$domain]['clicks']      += intval($ref->clicks);
                    $domain_map[$domain]['conversions'] += intval($ref->conversions);
                }
                uasort($domain_map, function($a, $b) {
                    return $b['clicks'] - $a['clicks'];
                });
                $traffic_sources['referrers'] = array();
                foreach ($domain_map as $domain => $stats) {
                    $traffic_sources['referrers'][] = (object) array(
                        'domain'      => $domain,
                        'clicks'      => $stats['clicks'],
                        'conversions' => $stats['conversions'],
                    );
                }

                // Total clicks in range (for direct traffic calc)
                $total_clicks_query = $wpdb->prepare(
                    "SELECT COUNT(*) FROM $clicks_table
                     WHERE couponid IN ($id_placeholders)
                       AND date BETWEEN %s AND %s",
                    array_merge($visible_coupon_ids, array($click_date_start, $click_date_end))
                );
                $traffic_sources['total_clicks'] = intval($wpdb->get_var($total_clicks_query)); // phpcs:ignore

                // Direct traffic (empty referrer)
                $direct_clicks_query = $wpdb->prepare(
                    "SELECT COUNT(*) as clicks, SUM(converted) as conversions
                     FROM $clicks_table
                     WHERE couponid IN ($id_placeholders)
                       AND date BETWEEN %s AND %s
                       AND (referrer = '' OR referrer IS NULL)",
                    array_merge($visible_coupon_ids, array($click_date_start, $click_date_end))
                );
                $direct_row = $wpdb->get_row($direct_clicks_query); // phpcs:ignore
                $traffic_sources['direct_clicks']      = intval($direct_row->clicks);
                $traffic_sources['direct_conversions'] = intval($direct_row->conversions);
            }
        }
    }

    // ======= CLICKS TIMESERIES (daily) =======
    $clicks_timeseries = array();
    if ($wcu_report_show_url !== 'false') {
        $clicks_table = $wpdb->prefix . 'wcusage_clicks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clicks_table ) ) === $clicks_table ) { // phpcs:ignore
            $visible_ids_for_clicks = array();
            foreach ($coupon_rows as $cr) {
                $visible_ids_for_clicks[] = intval($cr['id']);
            }
            if (!empty($visible_ids_for_clicks)) {
                $id_ph = implode(',', array_fill(0, count($visible_ids_for_clicks), '%d'));
                $click_ts_query = $wpdb->prepare(
                    "SELECT DATE(date) as click_date, COUNT(*) as clicks
                     FROM $clicks_table
                     WHERE couponid IN ($id_ph)
                       AND date BETWEEN %s AND %s
                     GROUP BY DATE(date)
                     ORDER BY click_date ASC",
                    array_merge($visible_ids_for_clicks, array($wcu_orders_start . ' 00:00:00', $wcu_orders_end . ' 23:59:59'))
                );
                $click_rows = $wpdb->get_results($click_ts_query); // phpcs:ignore
                if ($click_rows) {
                    foreach ($click_rows as $cr) {
                        $clicks_timeseries[$cr->click_date] = intval($cr->clicks);
                    }
                }
            }
        }
    }

    // Build compact timeseries arrays (ordered by date key)
    ksort($timeseries);
    $ts_usage = []; $ts_sales = []; $ts_commission = []; $ts_discounts = []; $ts_clicks = [];
    foreach ($timeseries as $day_key => $day_data) {
        $ts_usage[]      = $day_data['usage'];
        $ts_sales[]      = round($day_data['sales'], 2);
        $ts_commission[] = round($day_data['commission'], 2);
        $ts_discounts[]  = round($day_data['discounts'], 2);
        $ts_clicks[]     = isset($clicks_timeseries[$day_key]) ? $clicks_timeseries[$day_key] : 0;
    }

    wp_send_json_success([
        'totals'      => $totals,
        'coupons'     => $coupon_rows,
        'total_count' => count($coupon_rows),
        'date_start'  => $wcu_orders_start,
        'date_end'    => $wcu_orders_end,
        'comparing'       => ($wcu_compare === 'true' && $is_pro),
        'compare_start'   => $wcu_orders_start_compare,
        'compare_end'     => $wcu_orders_end_compare,
        'totals_compare'  => $totals_compare,
        'activity'        => $activity_counts,
        'traffic_sources' => $traffic_sources,
        'timeseries'      => [
            'dates'      => array_keys($timeseries),
            'usage'      => $ts_usage,
            'sales'      => $ts_sales,
            'commission' => $ts_commission,
            'discounts'  => $ts_discounts,
            'clicks'     => $ts_clicks,
        ],
    ]);
}
add_action('wp_ajax_wcusage_load_admin_reports', 'wcusage_load_admin_reports');

/**
 * Helper: Check a filter condition.
 */
function wcusage_report_check_filter($value, $type, $amount) {
    switch ($type) {
        case 'more':
            return $value > $amount;
        case 'more or equal':
            return $value >= $amount;
        case 'less':
            return $value < $amount;
        case 'less or equal':
            return $value <= $amount;
        case 'equal':
            return $value == $amount;
    }
    return true;
}
