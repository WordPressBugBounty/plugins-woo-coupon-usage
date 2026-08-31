<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Render pagination controls
if (!function_exists('wcusage_render_pagination')) {
function wcusage_render_pagination($type, $page, $per_page, $total) {
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($total_pages <= 1) {
        return;
    }
    ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html($total); ?> <?php echo esc_html__('items', 'woo-coupon-usage'); ?></span>
            <span class="pagination-links" data-type="<?php echo esc_attr($type); ?>">
                <a class="first-page button" data-page="1" aria-disabled="<?php echo $page <= 1 ? 'true' : 'false'; ?>">«</a>
                <a class="prev-page button" data-page="<?php echo esc_attr(max(1, $page - 1)); ?>" aria-disabled="<?php echo $page <= 1 ? 'true' : 'false'; ?>">‹</a>
                <span class="paging-input">
                    <input class="current-page" type="text" size="2" value="<?php echo esc_attr($page); ?>" aria-label="Current page"> of <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                </span>
                <a class="next-page button" data-page="<?php echo esc_attr(min($total_pages, $page + 1)); ?>" aria-disabled="<?php echo $page >= $total_pages ? 'true' : 'false'; ?>">›</a>
                <a class="last-page button" data-page="<?php echo esc_attr($total_pages); ?>" aria-disabled="<?php echo $page >= $total_pages ? 'true' : 'false'; ?>">»</a>
            </span>
        </div>
    </div>
    <?php
}
}

// The coupon codes assigned to an affiliate, in the order their coupons are returned.
// Shared by the Referred Orders list and the coupon dropdown that filters it, so the
// dropdown can never offer a coupon the list does not read.
if (!function_exists('wcusage_get_affiliate_coupon_codes')) {
function wcusage_get_affiliate_coupon_codes($user_id) {
    $coupon_codes = array();
    foreach (wcusage_get_users_coupons_ids($user_id) as $coupon_id) {
        $coupon_code = get_the_title($coupon_id);
        if ($coupon_code) {
            $coupon_codes[] = $coupon_code;
        }
    }
    return $coupon_codes;
}
}

/**
 * The statuses the Referred Orders status filter offers, as status => label.
 *
 * Only the statuses that count towards a coupon's referrals, because an order in
 * any other status is never in that list to begin with - offering "Cancelled"
 * where cancelled orders are not counted would only ever return nothing.
 *
 * @return array
 */
if (!function_exists('wcusage_get_affiliate_referral_status_options')) {
function wcusage_get_affiliate_referral_status_options() {
    $options = array();
    foreach (wcusage_get_coupon_order_statuses() as $status_key => $checked) {
        // The custom-statuses setting is stored the way the settings form posts it -
        // a map of ticked checkboxes - so it can also carry the form's __present marker.
        if (strpos($status_key, 'wc-') !== 0) {
            continue;
        }
        $options[substr($status_key, 3)] = wc_get_order_status_name($status_key);
    }
    return $options;
}
}

/**
 * Narrow a list of order ids to those matching a free-text search.
 *
 * Matches the order number, the billing email, and the billing name - either part
 * on its own or both together, so "Jane" and "Jane Smith" both find the order.
 *
 * Runs as one query bounded by the ids already found for the affiliate, rather than
 * loading each order to read its billing fields: this list is paginated precisely so
 * that it never has to load them all.
 *
 * @param array  $order_ids
 * @param string $search
 *
 * @return array The matching ids, in the order they were given.
 */
if (!function_exists('wcusage_filter_referral_order_ids_by_search')) {
function wcusage_filter_referral_order_ids_by_search($order_ids, $search) {
    global $wpdb;

    $search = trim((string) $search);
    if ('' === $search || empty($order_ids)) {
        return $order_ids;
    }

    $matched = array();

    // Order number, matched on the digits so "#123" and "123" both work.
    $number_search = ltrim($search, '#');
    if (ctype_digit($number_search)) {
        foreach ($order_ids as $order_id) {
            if (false !== strpos((string) $order_id, $number_search)) {
                $matched[(int) $order_id] = true;
            }
        }
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    $ids_sql = implode(',', array_map('intval', $order_ids));

    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        $sql = $wpdb->prepare(
            "SELECT o.id
            FROM {$wpdb->prefix}wc_orders AS o
            LEFT JOIN {$wpdb->prefix}wc_order_addresses AS a
                ON a.order_id = o.id AND a.address_type = 'billing'
            WHERE o.id IN ($ids_sql)
            AND ( o.billing_email LIKE %s
                OR a.email LIKE %s
                OR a.first_name LIKE %s
                OR a.last_name LIKE %s
                OR CONCAT_WS(' ', a.first_name, a.last_name) LIKE %s )",
            $like, $like, $like, $like, $like
        ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    } else {
        // One row per field here, so the full-name match has to be reassembled per order.
        $sql = $wpdb->prepare(
            "SELECT pm.post_id
            FROM {$wpdb->postmeta} AS pm
            WHERE pm.post_id IN ($ids_sql)
            AND pm.meta_key IN ( '_billing_email', '_billing_first_name', '_billing_last_name' )
            GROUP BY pm.post_id
            HAVING MAX( pm.meta_value LIKE %s ) = 1
                OR CONCAT_WS( ' ',
                    MAX( CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END ),
                    MAX( CASE WHEN pm.meta_key = '_billing_last_name' THEN pm.meta_value END )
                ) LIKE %s",
            $like, $like
        ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    foreach ($wpdb->get_col($sql) as $order_id) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $matched[(int) $order_id] = true;
    }

    // Rebuilt from the caller's list so the newest-first order it sorted into survives.
    $filtered = array();
    foreach ($order_ids as $order_id) {
        if (isset($matched[(int) $order_id])) {
            $filtered[] = $order_id;
        }
    }

    return $filtered;
}
}

// Referrals table
if (!function_exists('wcusage_affiliate_referrals_table')) {
function wcusage_affiliate_referrals_table($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '', $filters = array()) {
    $filters = wp_parse_args($filters, array(
        'status' => '',
        'coupon' => '',
        'search' => '',
    ));

    $coupons = wcusage_get_users_coupons_ids($user_id);
    if (empty($coupons)) {
        echo '<p>' . esc_html__('No coupons assigned to this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    $coupon_codes = wcusage_get_affiliate_coupon_codes($user_id);
    if (empty($coupon_codes)) {
        echo '<p>' . esc_html__('No valid coupon codes found for this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // The coupon filter can only narrow the list to one of this affiliate's own coupons -
    // a code that is not theirs is dropped rather than queried, so the filter cannot be
    // used to read another affiliate's orders.
    if (!empty($filters['coupon'])) {
        $filtered_coupon = '';
        foreach ($coupon_codes as $coupon_code) {
            if (0 === strcasecmp($coupon_code, $filters['coupon'])) {
                $filtered_coupon = $coupon_code;
                break;
            }
        }
        $coupon_codes = $filtered_coupon ? array($filtered_coupon) : array();
    }

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // Only the list of orders is needed here, not what they earned: the commission shown
    // below is read from saved order meta. Asking wcusage_wh_getOrderbyCouponCode() would
    // recalculate every order the affiliate has ever had - all of it discarded - to render
    // twenty rows, and this runs again on every pagination click and filter change.
    $dates_by_id = array();
    foreach ($coupon_codes as $coupon_code) {
        foreach (wcusage_get_coupon_referral_order_rows($coupon_code, $start_date, $end_date ? $end_date : wcusage_local_date(), '', $filters['status']) as $row) {
            if (!wcusage_check_if_renewal_allowed($row->order_id)) {
                continue;
            }
            $dates_by_id[$row->order_id] = $row->order_date;
        }
    }

    // Newest first, as the sort on WC_Order::get_date_created() used to do. Two orders
    // created in the same second are ordered by ascending order id, which is what that
    // sort produced - without the tie-break a same-second pair swaps places, and can land
    // on a different page. Cast for the comparison: date_created_gmt is a nullable column.
    $order_ids = array_keys($dates_by_id);
    usort($order_ids, function($a, $b) use ($dates_by_id) {
        $compare = strcmp((string) $dates_by_id[$b], (string) $dates_by_id[$a]);
        return 0 !== $compare ? $compare : ($a <=> $b);
    });

    $order_ids = wcusage_filter_referral_order_ids_by_search($order_ids, $filters['search']);

    $total = count($order_ids);

    // Load only the orders on this page.
    $orders = array();
    foreach (array_slice($order_ids, $offset, $per_page) as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $orders[] = $order;
        }
    }

    if (empty($orders)) {
        // "Nothing here yet" and "nothing matches what you asked for" are different
        // answers, and only the second one is the filters' doing. The end date is not
        // counted as a filter: it is pre-filled with today on every page load.
        if ($start_date || $filters['status'] || $filters['coupon'] || '' !== trim((string) $filters['search'])) {
            echo '<p>' . esc_html__('No referred orders match these filters.', 'woo-coupon-usage') . '</p>';
        } else {
            echo '<p>' . esc_html__('No recent referrals found for this affiliate\'s coupons. This could mean that the assigned coupons have not been used in any orders yet, or the orders are still pending.', 'woo-coupon-usage') . '</p>';
        }
        return;
    }
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Order ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Customer', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon Code', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Total', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Commission', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <?php
                $order_id = $order->get_id();
                $commission = wcusage_get_order_saved_commission($order_id);
                $billing_first_name = $order->get_billing_first_name();
                $billing_last_name = $order->get_billing_last_name();
                $customer_name = trim($billing_first_name . ' ' . $billing_last_name);
                if (empty($customer_name)) { $customer_name = esc_html__('Guest', 'woo-coupon-usage'); }
                $coupon_code = '';
                $lifetime_coupon = wcusage_order_meta($order_id, 'lifetime_affiliate_coupon_referrer');
                $referrer_coupon = wcusage_order_meta($order_id, 'wcusage_referrer_coupon');
                $used_coupons = $order->get_coupon_codes();
                if ($lifetime_coupon) {
                    $coupon_code = $lifetime_coupon;
                } elseif ($referrer_coupon) {
                    $coupon_code = $referrer_coupon;
                } elseif (!empty($used_coupons)) {
                    $coupon_code = $used_coupons[0];
                }
                ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>">#<?php echo esc_html($order_id); ?></a></td>
                    <td><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                    <td><?php echo esc_html($customer_name); ?></td>
                    <td><?php echo esc_html($coupon_code); ?></td>
                    <td><?php echo wp_kses_post(wcusage_format_price($order->get_total())); ?></td>
                    <td><?php echo wp_kses_post(wcusage_format_price($commission)); ?></td>
                    <td><?php
                        $order_status = $order->get_status();
                        $order_status_class = '';
                        switch ( $order_status ) {
                            case 'completed': $order_status_class = 'status-completed'; break;
                            case 'processing': $order_status_class = 'status-processing'; break;
                            case 'on-hold': $order_status_class = 'status-on-hold'; break;
                            case 'cancelled':
                            case 'refunded':
                            case 'failed': $order_status_class = 'status-cancelled'; break;
                            default: $order_status_class = 'status-processing'; break;
                        }
                        ?><span class="order-status <?php echo esc_attr($order_status_class); ?>"><?php echo esc_html(wc_get_order_status_name($order_status)); ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php wcusage_render_pagination('referrals', $page, $per_page, $total); ?>
    <?php
}
}

// Visits table
if (!function_exists('wcusage_affiliate_visits_table')) {
function wcusage_affiliate_visits_table($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;

    $coupons = wcusage_get_users_coupons_ids($user_id);
    if (empty($coupons)) {
        echo '<p>' . esc_html__('No coupons assigned to this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    $table_name = $wpdb->prefix . 'wcusage_clicks';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Click tracking is not currently enabled.', 'woo-coupon-usage');
        echo '<br><br>';
        echo sprintf(
            esc_html__('To enable click tracking, go to %s and enable the "Click Tracking" option.', 'woo-coupon-usage'),
            '<a href="' . esc_url(admin_url('admin.php?page=wcusage_settings')) . '">' . esc_html__('Settings', 'woo-coupon-usage') . '</a>'
        );
        echo '</p></div>';
        return;
    }

    $placeholders = array_fill(0, count($coupons), '%d');
    $in_clause = '(' . implode(',', $placeholders) . ')';

    $where_date = '';
    $params = $coupons;
    if (!empty($start_date)) { $where_date .= " AND date >= %s"; $params[] = $start_date . ' 00:00:00'; }
    if (!empty($end_date)) { $where_date .= " AND date <= %s"; $params[] = $end_date . ' 23:59:59'; }

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE couponid IN $in_clause" . $where_date,
        $params
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total = intval($wpdb->get_var($count_sql)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    $list_sql = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE couponid IN $in_clause" . $where_date . " ORDER BY date DESC LIMIT %d OFFSET %d",
        array_merge($params, array($per_page, $offset))
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $clicks = $wpdb->get_results($list_sql); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($clicks)) {
        echo '<p>' . esc_html__('No recent visits found for this affiliate\'s coupons.', 'woo-coupon-usage') . '</p>';
        return;
    }
    ?>
    <table class="wp-list-table widefat fixed striped wcusage-visits-table">
        <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html(sprintf(esc_html__('%s Coupon', 'woo-coupon-usage'), wcusage_get_affiliate_text(__('Affiliate', 'woo-coupon-usage')))); ?></th>
                <th><?php echo esc_html__('Landing Page', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Referrer URL', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('IP Address', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Visit Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Converted', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Action', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clicks as $click): ?>
                <?php
                $coupon_title = '';
                $coupon_edit_link = '';
                $uniqueurl = '';
                if ($click->couponid) {
                    $coupon_title = get_the_title($click->couponid);
                    $coupon_info = wcusage_get_coupon_info_by_id($click->couponid);
                    $uniqueurl = isset($coupon_info[4]) ? $coupon_info[4] : '';
                    $coupon_edit_link = admin_url("post.php?post=" . $click->couponid . "&action=edit&classic-editor");
                }
                $landing_page_title = '';
                if ($click->page) {
                    $landing_page_title = get_the_title($click->page);
                    if (empty($landing_page_title)) { $landing_page_title = esc_html__('Unknown Page', 'woo-coupon-usage'); }
                }
                $referrer_display = $click->referrer;
                if (empty($referrer_display)) { $referrer_display = '<em>' . esc_html__('Direct', 'woo-coupon-usage') . '</em>'; }
                $visit_datetime = strtotime($click->date);
                $formatted_date = date_i18n("M jS, Y (g:ia)", $visit_datetime);
                $is_converted = !empty($click->orderid);
                ?>
                <tr>
                    <td><?php echo esc_html($click->id); ?></td>
                    <td>
                        <?php if ($coupon_title): ?>
                            <a href="<?php echo esc_url($uniqueurl); ?>" target="_blank" title="<?php echo esc_attr(sprintf(__('View %s Dashboard', 'woo-coupon-usage'), wcusage_get_affiliate_text(__('Affiliate', 'woo-coupon-usage')))); ?>">
                                <?php echo esc_html($coupon_title); ?>
                            </a>
                        <?php else: ?>
                            <em><?php echo esc_html__('Unknown', 'woo-coupon-usage'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($click->page && $landing_page_title): ?>
                            <a href="<?php echo esc_url(get_permalink($click->page)); ?>" target="_blank" title="<?php echo esc_attr__('View Landing Page', 'woo-coupon-usage'); ?>">
                                <?php echo esc_html($landing_page_title); ?>
                            </a>
                        <?php else: ?>
                            <em><?php echo esc_html__('Unknown', 'woo-coupon-usage'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td><?php echo wp_kses_post($referrer_display); ?></td>
                    <td>
                        <code style="background: #f9fafb; padding: 2px 4px; border-radius: 3px; font-size: 12px;">
                            <?php echo esc_html($click->ipaddress); ?>
                        </code>
                    </td>
                    <td><?php echo esc_html($formatted_date); ?></td>
                    <td>
                        <?php if ($is_converted): ?>
                            <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                            <?php echo esc_html__('Yes', 'woo-coupon-usage'); ?>
                            <?php if (!empty($click->orderid)): ?>
                                <br/><a href="<?php echo esc_url(get_edit_post_link($click->orderid)); ?>" target="_blank">
                                    #<?php echo esc_html($click->orderid); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="dashicons dashicons-dismiss" style="color: red;"></span>
                            <?php echo esc_html__('No', 'woo-coupon-usage'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" id="submitclick">
                            <input type="text" id="wcu-id" name="wcu-id" value="<?php echo esc_attr($click->id); ?>" style="display: none;">
                            <input type="text" id="wcu-status-delete" name="wcu-status-delete" value="cancel" style="display: none;">
                            <?php wp_nonce_field('delete_url'); ?>
                            <button onClick="return confirm('Are you sure you want to delete visit #<?php echo esc_attr($click->id); ?>?');"
                                title="<?php echo esc_attr__('Delete this visit.', 'woo-coupon-usage'); ?>"
                                type="submit" name="submitclickdelete" style="padding: 0; background: 0; border: 0; cursor: pointer; margin-bottom: 5px; color: #B52828;">
                                <i class="fa-solid fa-trash-can"></i> <?php echo esc_html__('Delete', 'woo-coupon-usage'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php wcusage_render_pagination('visits', $page, $per_page, $total); ?>
    <?php
}
}

// Payouts table
if (!function_exists('wcusage_affiliate_payouts_table')) {
function wcusage_affiliate_payouts_table($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_payouts';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        echo '<p>' . esc_html__('Payouts system not enabled or table not found.', 'woo-coupon-usage') . '</p>';
        return;
    }
    // Determine if Files column should be shown (based on settings similar to admin payouts page)
    $payouts_enable_invoices = function_exists('wcusage_get_setting_value') ? wcusage_get_setting_value('wcusage_field_payouts_enable_invoices', '0') : '0';
    $payouts_enable_statements = function_exists('wcusage_get_setting_value') ? wcusage_get_setting_value('wcusage_field_payouts_enable_statements', '0') : '0';
    $show_files_column = ($payouts_enable_invoices || $payouts_enable_statements) ? true : false;
    $where_date = '';
    $params = array($user_id);
    if (!empty($start_date)) { $where_date .= " AND date >= %s"; $params[] = $start_date . ' 00:00:00'; }
    if (!empty($end_date)) { $where_date .= " AND date <= %s"; $params[] = $end_date . ' 23:59:59'; }

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE userid = %d" . $where_date,
        $params
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total = intval($wpdb->get_var($count_sql)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    $list_sql = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE userid = %d" . $where_date . " ORDER BY id DESC LIMIT %d OFFSET %d",
        array_merge($params, array($per_page, $offset))
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $payouts = $wpdb->get_results($list_sql); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($payouts)) {
        echo '<p>' . esc_html__('No payout history found.', 'woo-coupon-usage') . '</p>';
        return;
    }
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Amount', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Method', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
                <?php if ($show_files_column): ?>
                    <th><?php echo esc_html__('Files', 'woo-coupon-usage'); ?></th>
                <?php endif; ?>
                <th><?php echo esc_html__('Date Requested', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date Paid', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payouts as $payout): ?>
                <?php
                $status_class = '';
                switch ($payout->status) {
                    case 'paid': $status_class = 'status-completed'; break;
                    case 'pending': $status_class = 'status-on-hold'; break;
                    case 'cancel': $status_class = 'status-cancelled'; break;
                    default: $status_class = 'status-processing'; break;
                }
                $coupon_title = '';
                if ($payout->couponid) { $coupon_title = get_the_title($payout->couponid); }
                // Build files column content similar to admin payouts list
                $files_html = '';
                if ($show_files_column && function_exists('wcusage_files_downloads_buttons')) {
                    $files_html = wcusage_files_downloads_buttons(
                        isset($payout->invoiceid) ? $payout->invoiceid : 0,
                        $payout->id,
                        1,   // always_invoice (show placeholder when enabled but missing)
                        1,   // show_text
                        0,   // download (open in new tab by default)
                        isset($payout->status) ? $payout->status : '',
                        1    // showpending
                    );
                }
                ?>
                <tr>
                    <td><?php echo esc_html($payout->id); ?></td>
                    <td><?php echo esc_html($coupon_title); ?></td>
                    <td><?php echo wp_kses_post(wcusage_format_price($payout->amount)); ?></td>
                    <td><?php echo esc_html($payout->method); ?></td>
                    <td><span class="order-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($payout->status)); ?></span></td>
                    <?php if ($show_files_column): ?>
                        <td><?php echo wp_kses_post($files_html); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payout->date))); ?></td>
                    <td><?php echo ($payout->status === 'paid' && !empty($payout->datepaid)) ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payout->datepaid))) : '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php wcusage_render_pagination('payouts', $page, $per_page, $total); ?>
    <?php
}
}

// AJAX handlers – loadable on admin-ajax.php
add_action('wp_ajax_wcusage_get_affiliate_referrals', function() {
    check_ajax_referer('wcusage_affiliate_referrals', '_wpnonce');
    if (!wcusage_check_admin_access()) { wp_die('Access denied'); }
    $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 20;
    $start_date = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
    $end_date = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';
    $filters = array(
        'status' => isset($_REQUEST['order_status']) ? sanitize_text_field(wp_unslash($_REQUEST['order_status'])) : '',
        'coupon' => isset($_REQUEST['coupon_code']) ? sanitize_text_field(wp_unslash($_REQUEST['coupon_code'])) : '',
        'search' => isset($_REQUEST['search']) ? sanitize_text_field(wp_unslash($_REQUEST['search'])) : '',
    );
    if (!$user_id) { wp_die('Invalid user ID'); }
    wcusage_affiliate_referrals_table($user_id, $page, $per_page, $start_date, $end_date, $filters);
    wp_die();
});

add_action('wp_ajax_wcusage_get_affiliate_visits', function() {
    check_ajax_referer('wcusage_affiliate_visits', '_wpnonce');
    if (!wcusage_check_admin_access()) { wp_die('Access denied'); }
    $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 20;
    $start_date = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
    $end_date = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';
    if (!$user_id) { wp_die('Invalid user ID'); }
    wcusage_affiliate_visits_table($user_id, $page, $per_page, $start_date, $end_date);
    wp_die();
});

add_action('wp_ajax_wcusage_get_affiliate_payouts', function() {
    check_ajax_referer('wcusage_affiliate_payouts', '_wpnonce');
    if (!wcusage_check_admin_access()) { wp_die('Access denied'); }
    $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 20;
    $start_date = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
    $end_date = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';
    if (!$user_id) { wp_die('Invalid user ID'); }
    wcusage_affiliate_payouts_table($user_id, $page, $per_page, $start_date, $end_date);
    wp_die();
});

// Activity table
if (!function_exists('wcusage_affiliate_activity_table')) {
function wcusage_affiliate_activity_table($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_activity';

    // Build filters
    $where = ' WHERE user_id = %d';
    $params = array($user_id);
    if (!empty($start_date)) { $where .= ' AND date >= %s'; $params[] = $start_date . ' 00:00:00'; }
    if (!empty($end_date)) { $where .= ' AND date <= %s'; $params[] = $end_date . ' 23:59:59'; }

    // Pagination
    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // Count
    $count_sql = $wpdb->prepare("SELECT COUNT(*) FROM $table_name" . $where, $params); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total = intval($wpdb->get_var($count_sql)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Fetch
    $list_sql = $wpdb->prepare("SELECT * FROM $table_name" . $where . " ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($params, array($per_page, $offset))); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
    $activities = $wpdb->get_results($list_sql, ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($activities)) {
        echo '<p>' . esc_html__('No activity found for this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }
    ?>
    <div style="margin-top: 20px;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                    <th><?php echo esc_html__('Event', 'woo-coupon-usage'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td>
                            <?php echo esc_html(date_i18n('F j, Y (H:i)', strtotime($activity['date']))); ?>
                        </td>
                        <td>
                            <?php
                            $event_message = wcusage_activity_message($activity['event'], $activity['event_id'], $activity['info']);
                            echo wp_kses_post($event_message);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php wcusage_render_pagination('activity', $page, $per_page, $total); ?>
    </div>
    <?php
}
}

// AJAX: Activity list
add_action('wp_ajax_wcusage_get_affiliate_activity', function() {
    check_ajax_referer('wcusage_affiliate_activity', '_wpnonce');
    if (!wcusage_check_admin_access()) { wp_die('Access denied'); }
    $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 20;
    $start_date = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
    $end_date = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';
    if (!$user_id) { wp_die('Invalid user ID'); }
    wcusage_affiliate_activity_table($user_id, $page, $per_page, $start_date, $end_date);
    wp_die();
});

/**
 * Are the "lifetime commission" features switched on?
 *
 * The Lifetime Customers tab is only meaningful when lifetime commission is
 * enabled - either globally or per-coupon - so both the tab and its AJAX
 * handler gate on this.
 */
if (!function_exists('wcusage_lifetime_customers_enabled')) {
function wcusage_lifetime_customers_enabled() {
    if ( ! wcu_fs()->can_use_premium_code() ) {
        return false;
    }
    return (bool) wcusage_get_setting_value('wcusage_field_lifetime', '0');
}
}

/**
 * Lowercased coupon codes currently assigned to an affiliate, mapped to their
 * coupon ID.
 *
 * Used to match against the `wcu_lifetime_referrer` user meta, which stores a
 * coupon code rather than an ID. Deliberately a different name from
 * wcusage_get_affiliate_coupon_codes() above, which returns a plain list of
 * codes for the Referred Orders list - the two are not interchangeable, and a
 * shared name silently shadowed this one.
 */
if (!function_exists('wcusage_get_affiliate_coupon_code_map')) {
function wcusage_get_affiliate_coupon_code_map($user_id) {
    $coupon_ids = wcusage_get_users_coupons_ids($user_id);
    $codes = array();
    foreach ($coupon_ids as $coupon_id) {
        $code = get_the_title($coupon_id);
        if ($code) {
            $codes[strtolower($code)] = $coupon_id;
        }
    }
    return $codes;
}
}

/**
 * Lifetime Customers table.
 *
 * Lists every customer linked to one of this affiliate's coupons for lifetime
 * commission, with the date that link stops attributing their orders.
 *
 * The expiry comparison deliberately mirrors the live attribution check in
 * wcusage_on_new_order_lifetime_check() - date('Y-m-d'), inclusive of today -
 * so the badge shown here matches what the customer's next order would
 * actually do. Note that an expired link is not wiped until that customer
 * places another order, so rows can legitimately sit in "Expired" for a while.
 *
 * @param int    $user_id  Affiliate user ID.
 * @param int    $page     Current page number.
 * @param int    $per_page Rows per page.
 * @param string $status   Optional filter: 'active', 'expired' or 'never'.
 */
if (!function_exists('wcusage_affiliate_lifetime_customers_table')) {
function wcusage_affiliate_lifetime_customers_table($user_id, $page = 1, $per_page = 20, $status = '') {
    global $wpdb;

    if ( ! wcusage_lifetime_customers_enabled() ) {
        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__('Lifetime commission features are not currently enabled.', 'woo-coupon-usage');
        echo ' ';
        printf(
            /* translators: %s: link to the commission settings page */
            wp_kses_post(__('You can enable them in %s.', 'woo-coupon-usage')),
            '<a href="' . esc_url(admin_url('admin.php?page=wcusage_settings&tab=commission#wcu-setting-header-lifetime')) . '">' . esc_html__('Settings &rarr; Commission', 'woo-coupon-usage') . '</a>'
        );
        echo '</p></div>';
        return;
    }

    $coupon_codes = wcusage_get_affiliate_coupon_code_map($user_id);
    if (empty($coupon_codes)) {
        echo '<p>' . esc_html__('No coupons assigned to this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    $codes = array_keys($coupon_codes);
    $code_placeholders = implode(',', array_fill(0, count($codes), '%s'));

    // Matches the attribution check in the lifetime commission add-on.
    $today = date('Y-m-d');

    // An expiry date is only honoured when an expiry period is actually
    // configured - globally, or on the coupon the customer is linked to - or
    // the date was set by hand here. A date left behind by a period that has
    // since been switched off must not read as "Expired", so the summary counts
    // and the status filter apply the same rule as the badge on each row; on the
    // date alone they disagreed with what the rows underneath them said.
    $global_expiry = (int) wcusage_get_setting_value('wcusage_field_lifetime_expire', '0') > 0;
    $expiry_coupon_codes = array();
    foreach ($coupon_codes as $expiry_code => $expiry_coupon_id) {
        if ((int) get_post_meta($expiry_coupon_id, 'wcu_lifetime_commission_expire', true) > 0) {
            $expiry_coupon_codes[$expiry_code] = $expiry_coupon_id;
        }
    }

    // The same test as $expiry_applies in the row loop below, as SQL. The date
    // check is written out in full rather than leaning on `e.meta_value <> ''`,
    // which is NULL when there is no expiry row at all and would make the
    // surrounding NOT(...) return NULL rather than true.
    $manual_sql = "(m.meta_value IS NOT NULL AND m.meta_value <> '' AND m.meta_value <> '0')";
    $applies_params = array();
    if ($global_expiry) {
        $applies_sql = '1';
    } elseif (!empty($expiry_coupon_codes)) {
        $applies_params = array_keys($expiry_coupon_codes);
        $applies_sql = '(LOWER(r.meta_value) IN ('
            . implode(',', array_fill(0, count($applies_params), '%s'))
            . ") OR $manual_sql)";
    } else {
        $applies_sql = $manual_sql;
    }
    $dated_sql = "(e.meta_value IS NOT NULL AND e.meta_value <> '')";

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    $from_sql = "FROM {$wpdb->usermeta} r
                 LEFT JOIN {$wpdb->usermeta} e
                        ON e.user_id = r.user_id
                       AND e.meta_key = 'wcu_lifetime_referrer_expire'
                 LEFT JOIN {$wpdb->usermeta} m
                        ON m.user_id = r.user_id
                       AND m.meta_key = 'wcu_lifetime_referrer_expire_manual'
                 WHERE r.meta_key = 'wcu_lifetime_referrer'
                   AND r.meta_value <> ''
                   AND LOWER(r.meta_value) IN ($code_placeholders)";

    // Summary counts across every linked customer, not just the current page.
    $summary = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN NOT ($applies_sql AND $dated_sql) THEN 1 ELSE 0 END) AS never_expires,
                    SUM(CASE WHEN $applies_sql AND $dated_sql AND e.meta_value >= %s THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN $applies_sql AND $dated_sql AND e.meta_value < %s THEN 1 ELSE 0 END) AS expired
             $from_sql",
            array_merge(
                $applies_params,
                $applies_params,
                array($today),
                $applies_params,
                array($today),
                $codes
            )
        )
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Optional status filter.
    $status = in_array($status, array('active', 'expired', 'never'), true) ? $status : '';
    $where_status = '';
    $status_params = array();
    if ($status === 'active') {
        $where_status = " AND $applies_sql AND $dated_sql AND e.meta_value >= %s";
        $status_params = array_merge($applies_params, array($today));
    } elseif ($status === 'expired') {
        $where_status = " AND $applies_sql AND $dated_sql AND e.meta_value < %s";
        $status_params = array_merge($applies_params, array($today));
    } elseif ($status === 'never') {
        $where_status = " AND NOT ($applies_sql AND $dated_sql)";
        $status_params = $applies_params;
    }

    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) $from_sql" . $where_status,
            array_merge($codes, $status_params)
        )
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Soonest expiry first, so expired and about-to-expire links surface at the
    // top, with "never expires" - a date that does not apply included - pushed
    // to the end.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.user_id, r.meta_value AS coupon_code, e.meta_value AS expire_date
             $from_sql" . $where_status . "
             ORDER BY NOT ($applies_sql AND $dated_sql) ASC, e.meta_value ASC, r.user_id ASC
             LIMIT %d OFFSET %d",
            array_merge($codes, $status_params, $applies_params, array($per_page, $offset))
        )
    ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Prime the user cache in one query rather than one get_userdata() per row.
    if (!empty($rows)) {
        cache_users(wp_list_pluck($rows, 'user_id'));
    }
    ?>

    <div class="wcusage-lifetime-summary">
        <div class="wcusage-lifetime-summary-box">
            <div class="wcusage-lifetime-summary-value"><?php echo esc_html( (int) $summary->total ); ?></div>
            <div class="wcusage-lifetime-summary-label"><?php echo esc_html__('Linked Customers', 'woo-coupon-usage'); ?></div>
        </div>
        <div class="wcusage-lifetime-summary-box">
            <div class="wcusage-lifetime-summary-value" style="color: #00a32a;"><?php echo esc_html( (int) $summary->active ); ?></div>
            <div class="wcusage-lifetime-summary-label"><?php echo esc_html__('Active', 'woo-coupon-usage'); ?></div>
        </div>
        <div class="wcusage-lifetime-summary-box">
            <div class="wcusage-lifetime-summary-value" style="color: #dc2626;"><?php echo esc_html( (int) $summary->expired ); ?></div>
            <div class="wcusage-lifetime-summary-label"><?php echo esc_html__('Expired', 'woo-coupon-usage'); ?></div>
        </div>
        <div class="wcusage-lifetime-summary-box">
            <div class="wcusage-lifetime-summary-value" style="color: #2271b1;"><?php echo esc_html( (int) $summary->never_expires ); ?></div>
            <div class="wcusage-lifetime-summary-label"><?php echo esc_html__('Never Expires', 'woo-coupon-usage'); ?></div>
        </div>
    </div>

    <?php if (empty($rows)) { ?>
        <p>
        <?php
        if ($status) {
            echo esc_html__('No linked customers match this filter.', 'woo-coupon-usage');
        } else {
            echo esc_html__('No customers are currently linked to this affiliate for lifetime commission.', 'woo-coupon-usage');
        }
        ?>
        </p>
        <?php
        return;
    }
    ?>

    <table class="wp-list-table widefat fixed striped wcusage-lifetime-customers-table">
        <thead>
            <tr>
                <th><?php echo esc_html__('Customer', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Linked Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Expiry Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('While a link is active, ALL of this customer\'s orders are attributed to the affiliate, even if they do not re-use the coupon. The expiry date is extended each time the customer places another qualifying order on that coupon. An expired link stops attributing straight away, but is only cleared from the customer record when they next place an order.', 'woo-coupon-usage')); ?></th>
                <th><?php echo esc_html__('Actions', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) {
                $customer = get_userdata($row->user_id);
                // Empty when the current admin cannot edit that user, in which
                // case the name is shown as plain text rather than a dead link.
                $edit_link = $customer ? get_edit_user_link($row->user_id) : '';
                $expire_date = $row->expire_date;
                $code_key = strtolower($row->coupon_code);
                $coupon_id = isset($coupon_codes[$code_key]) ? $coupon_codes[$code_key] : 0;

                // The add-on only applies a date when an expiry period is configured
                // (in the settings or on the linked coupon) or the date was set by
                // hand here - any other date is ignored, and must not read as active.
                // Read from the values gathered above so this stays in step with the
                // summary counts and the status filter, which test the same thing.
                $expiry_applies = $global_expiry
                    || isset($expiry_coupon_codes[$code_key])
                    || get_user_meta($row->user_id, 'wcu_lifetime_referrer_expire_manual', true);

                if (!$expire_date) {
                    $status_class = 'status-processing';
                    $status_label = esc_html__('Never Expires', 'woo-coupon-usage');
                    $expiry_display = '&mdash;';
                } elseif (!$expiry_applies) {
                    $status_class = 'status-processing';
                    $status_label = esc_html__('Never Expires (date not applied)', 'woo-coupon-usage');
                    $expiry_display = esc_html(date_i18n(get_option('date_format'), strtotime($expire_date)));
                } else {
                    $expiry_display = esc_html(date_i18n(get_option('date_format'), strtotime($expire_date)));
                    if ($expire_date >= $today) {
                        $days_left = (int) floor((strtotime($expire_date) - strtotime($today)) / DAY_IN_SECONDS);
                        $status_class = $days_left <= 14 ? 'status-on-hold' : 'status-completed';
                        if ($days_left < 1) {
                            // Today is still inside the window, so it attributes.
                            $status_label = esc_html__('Active (expires today)', 'woo-coupon-usage');
                        } else {
                            $status_label = sprintf(
                                /* translators: %s: number of days remaining */
                                esc_html(_n('Active (%s day left)', 'Active (%s days left)', $days_left, 'woo-coupon-usage')),
                                esc_html($days_left)
                            );
                        }
                    } else {
                        $status_class = 'status-cancelled';
                        $status_label = esc_html__('Expired', 'woo-coupon-usage');
                    }
                }
                ?>
                <tr class="wcusage-lifetime-row" data-customer-id="<?php echo esc_attr($row->user_id); ?>">
                    <td>
                        <?php if ($customer) { ?>
                            <?php $customer_name = $customer->display_name ? $customer->display_name : $customer->user_login; ?>
                            <?php if ($edit_link) { ?>
                                <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($customer_name); ?></a>
                            <?php } else { ?>
                                <?php echo esc_html($customer_name); ?>
                            <?php } ?>
                            <br/><span class="wcusage-lifetime-muted"><?php echo esc_html($customer->user_email); ?></span>
                        <?php } else { ?>
                            <em><?php echo esc_html__('Deleted user', 'woo-coupon-usage'); ?></em>
                            <?php /* translators: %d: user ID */ ?>
                            <br/><span class="wcusage-lifetime-muted"><?php echo esc_html(sprintf(__('ID %d', 'woo-coupon-usage'), $row->user_id)); ?></span>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="wcusage-lifetime-view">
                            <?php if ($coupon_id) { ?>
                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $coupon_id . '&action=edit')); ?>">
                                    <?php echo esc_html($row->coupon_code); ?>
                                </a>
                            <?php } else { ?>
                                <?php echo esc_html($row->coupon_code); ?>
                            <?php } ?>
                        </span>
                        <span class="wcusage-lifetime-edit" style="display: none;">
                            <input type="text" class="wcusage-lifetime-coupon-input" value="<?php echo esc_attr($row->coupon_code); ?>" list="wcusage-lifetime-coupon-list" aria-label="<?php echo esc_attr__('Linked coupon code', 'woo-coupon-usage'); ?>" />
                        </span>
                    </td>
                    <td>
                        <span class="wcusage-lifetime-view"><?php echo wp_kses_post($expiry_display); ?></span>
                        <span class="wcusage-lifetime-edit" style="display: none;">
                            <input type="date" class="wcusage-lifetime-expiry-input" value="<?php echo esc_attr($expire_date); ?>" aria-label="<?php echo esc_attr__('Expiry date', 'woo-coupon-usage'); ?>" />
                            <br/><span class="wcusage-lifetime-muted"><?php echo esc_html__('Blank = never expires', 'woo-coupon-usage'); ?></span>
                        </span>
                    </td>
                    <td><span class="order-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                    <td>
                        <span class="wcusage-lifetime-view wcusage-lifetime-actions">
                            <a href="#" class="wcusage-lifetime-edit-button" title="<?php echo esc_attr__('Edit the linked coupon and expiry date.', 'woo-coupon-usage'); ?>">
                                <i class="fa-solid fa-pen-to-square"></i> <?php echo esc_html__('Edit', 'woo-coupon-usage'); ?>
                            </a>
                            <a href="#" class="wcusage-lifetime-remove-button" title="<?php echo esc_attr__('Unlink this customer from the affiliate.', 'woo-coupon-usage'); ?>">
                                <i class="fa-solid fa-link-slash"></i> <?php echo esc_html__('Remove', 'woo-coupon-usage'); ?>
                            </a>
                        </span>
                        <span class="wcusage-lifetime-edit wcusage-lifetime-actions" style="display: none;">
                            <a href="#" class="wcusage-lifetime-save-button"><i class="fa-solid fa-check"></i> <?php echo esc_html__('Save', 'woo-coupon-usage'); ?></a>
                            <a href="#" class="wcusage-lifetime-cancel-button"><?php echo esc_html__('Cancel', 'woo-coupon-usage'); ?></a>
                        </span>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php // Suggestions for the coupon field: this affiliate's own coupons. Any
          // other existing coupon code can still be typed in by hand. ?>
    <datalist id="wcusage-lifetime-coupon-list">
        <?php foreach ($coupon_codes as $suggest_code => $suggest_id) { ?>
            <option value="<?php echo esc_attr(get_the_title($suggest_id)); ?>"></option>
        <?php } ?>
    </datalist>

    <?php wcusage_render_pagination('lifetime', $page, $per_page, $total); ?>
    <?php
}
}

// AJAX: Lifetime customers list
add_action('wp_ajax_wcusage_get_affiliate_lifetime_customers', function() {
    check_ajax_referer('wcusage_affiliate_lifetime', '_wpnonce');
    if (!wcusage_check_admin_access()) { wp_die('Access denied'); }
    $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $per_page = isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 20;
    $status = isset($_REQUEST['lifetime_status']) ? sanitize_text_field($_REQUEST['lifetime_status']) : '';
    if (!$user_id) { wp_die('Invalid user ID'); }
    wcusage_affiliate_lifetime_customers_table($user_id, $page, $per_page, $status);
    wp_die();
});

/**
 * Shared guard for the lifetime link write endpoints.
 *
 * Returns the sanitised customer ID, or sends a JSON error and never returns.
 * The affiliate ID is required as well as the customer ID so an admin can only
 * edit a link that is actually shown on the affiliate they are looking at -
 * the nonce alone would otherwise let any customer's link be rewritten.
 *
 * @return array{customer_id:int, affiliate_id:int, codes:array}
 */
if (!function_exists('wcusage_lifetime_link_request_guard')) {
function wcusage_lifetime_link_request_guard() {
    check_ajax_referer('wcusage_affiliate_lifetime', '_wpnonce');

    if (!wcusage_check_admin_access()) {
        wp_send_json_error(array('message' => __('Access denied.', 'woo-coupon-usage')), 403);
    }

    if (!wcusage_lifetime_customers_enabled()) {
        wp_send_json_error(array('message' => __('Lifetime commission features are not enabled.', 'woo-coupon-usage')), 400);
    }

    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $affiliate_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$customer_id || !$affiliate_id) {
        wp_send_json_error(array('message' => __('Missing customer or affiliate ID.', 'woo-coupon-usage')), 400);
    }

    if (!get_userdata($customer_id)) {
        wp_send_json_error(array('message' => __('That customer no longer exists.', 'woo-coupon-usage')), 404);
    }

    // The customer must currently be linked to one of this affiliate's coupons.
    $codes = wcusage_get_affiliate_coupon_code_map($affiliate_id);
    $current = strtolower((string) get_user_meta($customer_id, 'wcu_lifetime_referrer', true));
    if (!$current || !isset($codes[$current])) {
        wp_send_json_error(array('message' => __('That customer is not linked to this affiliate.', 'woo-coupon-usage')), 400);
    }

    return array(
        'customer_id'  => $customer_id,
        'affiliate_id' => $affiliate_id,
        'codes'        => $codes,
    );
}
}

// AJAX: save an edited lifetime link (coupon code and/or expiry date)
add_action('wp_ajax_wcusage_save_lifetime_link', function() {
    $guard = wcusage_lifetime_link_request_guard();
    $customer_id = $guard['customer_id'];
    $codes = $guard['codes'];

    $old_code = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer', true);
    $old_expiry = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer_expire', true);

    $new_code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';
    $new_expiry = isset($_POST['expire_date']) ? sanitize_text_field(wp_unslash($_POST['expire_date'])) : '';

    if (!$new_code) {
        wp_send_json_error(array('message' => __('Enter a coupon code, or use Remove to unlink this customer.', 'woo-coupon-usage')), 400);
    }

    // Must be a real coupon. Reassigning to a coupon belonging to a different
    // affiliate is allowed (the row then drops off this affiliate's list), but
    // a typo that points at nothing is not.
    $new_coupon_id = wc_get_coupon_id_by_code($new_code);
    if (!$new_coupon_id) {
        wp_send_json_error(
            array(
                'message' => sprintf(
                    /* translators: %s: the coupon code that was entered */
                    __('No coupon found with the code "%s".', 'woo-coupon-usage'),
                    $new_code
                ),
            ),
            400
        );
    }
    // Store the code the way the add-on itself stores it: the raw title (the
    // the_title filters would texturize "2x10" or "&"), normalised by
    // wc_format_coupon_code() exactly as an order's coupon codes are.
    $new_code = wc_format_coupon_code( get_post_field( 'post_title', $new_coupon_id, 'raw' ) );

    // The add-on only attributes through coupons that have lifetime commission
    // switched on (unless it is on for every coupon), so a link to any other
    // coupon would show here as active while never earning anything.
    if ( ! wcusage_get_setting_value( 'wcusage_field_lifetime_all', '0' )
        && ! get_post_meta( $new_coupon_id, 'wcu_enable_lifetime_commission', true ) ) {
        wp_send_json_error(
            array(
                'message' => sprintf(
                    /* translators: %s: coupon code */
                    __( 'Lifetime commission is not enabled on coupon "%s". Enable it on that coupon (or for all coupons under Settings > Commission) before linking customers to it.', 'woo-coupon-usage' ),
                    $new_code
                ),
            ),
            400
        );
    }

    // An empty date means "never expires", which is what the add-on stores.
    if ($new_expiry !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $new_expiry);
        if (!$parsed || $parsed->format('Y-m-d') !== $new_expiry) {
            wp_send_json_error(array('message' => __('Enter a valid expiry date, or leave it blank for no expiry.', 'woo-coupon-usage')), 400);
        }
    }

    if ($new_code === $old_code && $new_expiry === $old_expiry) {
        wp_send_json_success(array('message' => __('No changes to save.', 'woo-coupon-usage')));
    }

    update_user_meta($customer_id, 'wcu_lifetime_referrer', $new_code);
    update_user_meta($customer_id, 'wcu_lifetime_referrer_expire', $new_expiry);
    // Tells the add-on this date was set by hand, so it is honoured even when no
    // expiry period is configured in the settings - where it would otherwise be
    // ignored, and shown here as active while never applying.
    if ($new_expiry !== '') {
        update_user_meta($customer_id, 'wcu_lifetime_referrer_expire_manual', '1');
    } else {
        delete_user_meta($customer_id, 'wcu_lifetime_referrer_expire_manual');
    }

    $never = __('never expires', 'woo-coupon-usage');
    $changes = array();
    if ($new_code !== $old_code) {
        $changes[] = sprintf('coupon %s &rarr; %s', $old_code, $new_code);
    }
    if ($new_expiry !== $old_expiry) {
        $changes[] = sprintf(
            'expiry %s &rarr; %s',
            $old_expiry ? $old_expiry : $never,
            $new_expiry ? $new_expiry : $never
        );
    }
    wcusage_add_activity($customer_id, 'lifetime_link_edited', implode(', ', $changes));

    // Leaving this affiliate's coupons entirely is worth calling out, because
    // the row disappears from the list on reload.
    $moved_away = !isset($codes[strtolower($new_code)]);

    wp_send_json_success(array(
        'message' => $moved_away
            ? __('Saved. That customer is now linked to a coupon belonging to a different affiliate, so they no longer appear in this list.', 'woo-coupon-usage')
            : __('Lifetime link updated.', 'woo-coupon-usage'),
    ));
});

// AJAX: remove a lifetime link entirely
add_action('wp_ajax_wcusage_remove_lifetime_link', function() {
    $guard = wcusage_lifetime_link_request_guard();
    $customer_id = $guard['customer_id'];

    $old_code = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer', true);
    $old_expiry = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer_expire', true);

    // Cleared rather than deleted, matching how the add-on expires a link.
    update_user_meta($customer_id, 'wcu_lifetime_referrer', '');
    update_user_meta($customer_id, 'wcu_lifetime_referrer_expire', '');
    delete_user_meta($customer_id, 'wcu_lifetime_referrer_expire_manual');

    wcusage_add_activity(
        $customer_id,
        'lifetime_link_removed',
        sprintf(
            'was coupon %s, expiry %s',
            $old_code,
            $old_expiry ? $old_expiry : __('never expires', 'woo-coupon-usage')
        )
    );

    wp_send_json_success(array(
        'message' => __('Lifetime link removed. Future orders from this customer will no longer be attributed to this affiliate.', 'woo-coupon-usage'),
    ));
});

/**
 * The number of days a new lifetime link lasts for a given coupon.
 *
 * Mirrors the add-on: the coupon's own "wcu_lifetime_commission_expire" wins
 * over the global setting, and 0 means the link never expires.
 *
 * @param int $coupon_id
 * @return int Days, or 0 for no expiry.
 */
if (!function_exists('wcusage_lifetime_default_expire_days')) {
function wcusage_lifetime_default_expire_days($coupon_id) {
    $days = (int) get_post_meta($coupon_id, 'wcu_lifetime_commission_expire', true);
    if (!$days) {
        $days = (int) wcusage_get_setting_value('wcusage_field_lifetime_expire', '0');
    }
    return max(0, $days);
}
}

/**
 * The coupons offered by the "Link a Customer" form, best first.
 *
 * Split out from the form itself because the toggle button sits in the tab
 * header while the form sits further down the page - both need to know whether
 * there is anything to offer, and neither should repeat the lookup.
 *
 * @param int $user_id Affiliate user ID.
 * @return array List of array{code:string, eligible:bool, expire_days:int}.
 */
if (!function_exists('wcusage_affiliate_lifetime_add_options')) {
function wcusage_affiliate_lifetime_add_options($user_id) {
    static $cache = array();

    $user_id = (int) $user_id;
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    $options = array();

    if ( wcusage_lifetime_customers_enabled() ) {

        // Only this affiliate's own coupons can be linked here - the tab is
        // scoped to one affiliate, and the AJAX handler enforces the same thing.
        $coupon_codes = wcusage_get_affiliate_coupon_code_map($user_id);
        $lifetime_all = (bool) wcusage_get_setting_value('wcusage_field_lifetime_all', '0');

        foreach ($coupon_codes as $coupon_id) {
            $code = get_post_field('post_title', $coupon_id, 'raw');
            if (!$code) {
                continue;
            }
            $options[] = array(
                'code'        => $code,
                'eligible'    => $lifetime_all || (bool) get_post_meta($coupon_id, 'wcu_enable_lifetime_commission', true),
                'expire_days' => wcusage_lifetime_default_expire_days($coupon_id),
            );
        }

        // Eligible coupons first, so the default selection is always a usable one.
        usort($options, function($a, $b) {
            if ($a['eligible'] === $b['eligible']) {
                return strcasecmp($a['code'], $b['code']);
            }
            return $a['eligible'] ? -1 : 1;
        });

    }

    $cache[$user_id] = $options;
    return $options;
}
}

/**
 * The "Link a Customer" toggle button, for the Lifetime Customers tab header.
 *
 * Printed separately from the form it opens so it can sit on the heading row,
 * where the other admin screens put their "add new" action.
 *
 * @param int $user_id Affiliate user ID.
 */
if (!function_exists('wcusage_affiliate_lifetime_add_button')) {
function wcusage_affiliate_lifetime_add_button($user_id) {
    if (empty(wcusage_affiliate_lifetime_add_options($user_id))) {
        return;
    }
    ?>
    <button type="button" id="wcusage-lifetime-add-toggle" class="button wcusage-lifetime-add-toggle"
            aria-expanded="false" aria-controls="wcusage-lifetime-add-form">
        <i class="fa-solid fa-link"></i>
        <span><?php echo esc_html__('Link a Customer', 'woo-coupon-usage'); ?></span>
    </button>
    <?php
}
}

/**
 * "Link a Customer" form for the Lifetime Customers tab.
 *
 * Lets an admin assign this affiliate as a customer's lifetime referrer by
 * hand, rather than waiting for the customer to place an order on the coupon.
 * Rendered once with the page (not inside the AJAX-reloaded table container),
 * so its state survives a table refresh.
 *
 * @param int $user_id Affiliate user ID.
 */
if (!function_exists('wcusage_affiliate_lifetime_add_form')) {
function wcusage_affiliate_lifetime_add_form($user_id) {

    $options = wcusage_affiliate_lifetime_add_options($user_id);
    if (empty($options)) {
        return;
    }

    $has_eligible = false;
    foreach ($options as $option) {
        if ($option['eligible']) {
            $has_eligible = true;
            break;
        }
    }
    ?>

    <div id="wcusage-lifetime-add-form" class="wcusage-lifetime-add-form" style="display: none;">

        <p class="wcusage-lifetime-add-desc">
            <?php echo esc_html__('Assign this affiliate as a customer\'s lifetime referrer by hand, without waiting for the customer to order on the coupon. From then on, all of that customer\'s orders are attributed to this affiliate until the link expires.', 'woo-coupon-usage'); ?>
        </p>

        <?php if (!$has_eligible) { ?>
            <div class="inline" style="margin: 0 0 12px;"><p>
                <?php echo esc_html__('None of this affiliate\'s coupons have lifetime commission enabled, so a link made here would never earn anything.', 'woo-coupon-usage'); ?>
                <?php
                printf(
                    /* translators: %s: link to the commission settings page */
                    wp_kses_post(__('Enable it on the coupon itself, or for all coupons under %s.', 'woo-coupon-usage')),
                    '<a href="' . esc_url(admin_url('admin.php?page=wcusage_settings&tab=commission#wcu-setting-header-lifetime')) . '">' . esc_html__('Settings &rarr; Commission', 'woo-coupon-usage') . '</a>'
                );
                ?>
            </p></div>
        <?php } ?>

        <div class="wcusage-lifetime-add-row">

            <div class="wcusage-lifetime-add-field wcusage-lifetime-add-customer-field">
                <label for="wcusage-lifetime-customer-search"><?php echo esc_html__('Customer', 'woo-coupon-usage'); ?></label>
                <input type="text" id="wcusage-lifetime-customer-search" class="regular-text"
                       placeholder="<?php echo esc_attr__('Search by name, username or email...', 'woo-coupon-usage'); ?>"
                       autocomplete="off" />
                <input type="hidden" id="wcusage-lifetime-customer-id" value="" />
                <span class="wcusage-lifetime-muted wcusage-lifetime-add-hint" id="wcusage-lifetime-customer-hint"></span>
            </div>

            <div class="wcusage-lifetime-add-field wcusage-lifetime-add-coupon-field">
                <label for="wcusage-lifetime-add-coupon"><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></label>
                <select id="wcusage-lifetime-add-coupon">
                    <?php foreach ($options as $option) { ?>
                        <option value="<?php echo esc_attr($option['code']); ?>"
                                data-expire-days="<?php echo esc_attr($option['expire_days']); ?>">
                            <?php
                            echo esc_html(
                                $option['eligible']
                                    ? $option['code']
                                    /* translators: %s: coupon code */
                                    : sprintf(__('%s (lifetime commission not enabled)', 'woo-coupon-usage'), $option['code'])
                            );
                            ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="wcusage-lifetime-add-field wcusage-lifetime-add-expiry-field">
                <label for="wcusage-lifetime-add-expiry"><?php echo esc_html__('Expiry Date', 'woo-coupon-usage'); ?></label>
                <input type="date" id="wcusage-lifetime-add-expiry" value="" />
                <span class="wcusage-lifetime-muted wcusage-lifetime-add-hint" id="wcusage-lifetime-add-expiry-hint"></span>
            </div>

            <div class="wcusage-lifetime-add-field wcusage-lifetime-add-submit-field">
                <button type="button" id="wcusage-lifetime-add-submit" class="button button-primary" disabled>
                    <?php echo esc_html__('Link Customer', 'woo-coupon-usage'); ?>
                </button>
                <span class="spinner wcusage-lifetime-add-spinner"></span>
            </div>

        </div>

    </div>
    <?php
}
}

// AJAX: search users to link to this affiliate for lifetime commission
add_action('wp_ajax_wcusage_search_lifetime_customers', function() {
    check_ajax_referer('wcusage_affiliate_lifetime', '_wpnonce');

    if (!wcusage_check_admin_access()) {
        wp_send_json_error(array('message' => __('Access denied.', 'woo-coupon-usage')), 403);
    }
    if (!wcusage_lifetime_customers_enabled()) {
        wp_send_json_error(array('message' => __('Lifetime commission features are not enabled.', 'woo-coupon-usage')), 400);
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    if (strlen($search) < 2) {
        wp_send_json_success(array());
    }

    $users = get_users(array(
        'search'         => '*' . $search . '*',
        'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
        'orderby'        => 'display_name',
        'number'         => 20,
    ));

    $results = array();
    foreach ($users as $user) {
        $name = $user->display_name ? $user->display_name : $user->user_login;
        // Surfaced so the admin can see they are about to overwrite an existing
        // link before they submit, rather than only when the server says no.
        $linked = (string) get_user_meta($user->ID, 'wcu_lifetime_referrer', true);
        $results[] = array(
            'id'     => $user->ID,
            'label'  => $name . ' (' . $user->user_email . ')',
            'value'  => $name,
            'linked' => $linked,
        );
    }

    wp_send_json_success($results);
});

// AJAX: link a customer to this affiliate for lifetime commission
add_action('wp_ajax_wcusage_add_lifetime_link', function() {
    check_ajax_referer('wcusage_affiliate_lifetime', '_wpnonce');

    if (!wcusage_check_admin_access()) {
        wp_send_json_error(array('message' => __('Access denied.', 'woo-coupon-usage')), 403);
    }
    if (!wcusage_lifetime_customers_enabled()) {
        wp_send_json_error(array('message' => __('Lifetime commission features are not enabled.', 'woo-coupon-usage')), 400);
    }

    $customer_id  = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $affiliate_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$customer_id || !$affiliate_id) {
        wp_send_json_error(array('message' => __('Select a customer first.', 'woo-coupon-usage')), 400);
    }

    $customer = get_userdata($customer_id);
    if (!$customer) {
        wp_send_json_error(array('message' => __('That customer no longer exists.', 'woo-coupon-usage')), 404);
    }
    $customer_name = $customer->display_name ? $customer->display_name : $customer->user_login;

    $new_code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';
    if (!$new_code) {
        wp_send_json_error(array('message' => __('Select a coupon to link the customer to.', 'woo-coupon-usage')), 400);
    }

    // Unlike the edit endpoint - which allows moving a link to another
    // affiliate's coupon - this one only ever assigns THIS affiliate, so the
    // coupon must be one of theirs.
    $codes = wcusage_get_affiliate_coupon_code_map($affiliate_id);
    $coupon_id = isset($codes[strtolower($new_code)]) ? $codes[strtolower($new_code)] : 0;
    if (!$coupon_id) {
        wp_send_json_error(array('message' => __('That coupon is not assigned to this affiliate.', 'woo-coupon-usage')), 400);
    }

    // Stored the way the add-on stores it - the raw title (the_title filters
    // would texturize codes like "2x10" or "&"), normalised the same way an
    // order's coupon codes are.
    $new_code = wc_format_coupon_code( get_post_field( 'post_title', $coupon_id, 'raw' ) );

    // A link through a coupon without lifetime commission would show as active
    // here while never actually attributing an order.
    if ( ! wcusage_get_setting_value( 'wcusage_field_lifetime_all', '0' )
        && ! get_post_meta( $coupon_id, 'wcu_enable_lifetime_commission', true ) ) {
        wp_send_json_error(
            array(
                'message' => sprintf(
                    /* translators: %s: coupon code */
                    __( 'Lifetime commission is not enabled on coupon "%s". Enable it on that coupon (or for all coupons under Settings > Commission) before linking customers to it.', 'woo-coupon-usage' ),
                    $new_code
                ),
            ),
            400
        );
    }

    $new_expiry = isset($_POST['expire_date']) ? sanitize_text_field(wp_unslash($_POST['expire_date'])) : '';
    if ($new_expiry !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $new_expiry);
        if (!$parsed || $parsed->format('Y-m-d') !== $new_expiry) {
            wp_send_json_error(array('message' => __('Enter a valid expiry date, or leave it blank for no expiry.', 'woo-coupon-usage')), 400);
        }
    }

    // Replacing an existing link takes future commission away from whoever
    // holds it, so it needs an explicit confirmation rather than happening
    // silently behind the admin's back.
    $old_code = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer', true);
    $overwrite = !empty($_POST['overwrite']);
    if ($old_code && !$overwrite) {
        if (strtolower($old_code) === strtolower($new_code)) {
            wp_send_json_error(
                array(
                    'code'    => 'already_linked_same',
                    'message' => sprintf(
                        /* translators: 1: customer name, 2: coupon code */
                        __( '%1$s is already linked to coupon "%2$s". Use Edit on their row to change the expiry date.', 'woo-coupon-usage' ),
                        $customer_name,
                        $new_code
                    ),
                ),
                409
            );
        }
        wp_send_json_error(
            array(
                'code'    => 'already_linked',
                'message' => sprintf(
                    /* translators: 1: customer name, 2: existing coupon code, 3: new coupon code */
                    __( '%1$s is already linked to coupon "%2$s". Replace that link with "%3$s"?', 'woo-coupon-usage' ),
                    $customer_name,
                    $old_code,
                    $new_code
                ),
            ),
            409
        );
    }

    $old_expiry = (string) get_user_meta($customer_id, 'wcu_lifetime_referrer_expire', true);

    update_user_meta($customer_id, 'wcu_lifetime_referrer', $new_code);
    update_user_meta($customer_id, 'wcu_lifetime_referrer_expire', $new_expiry);
    // Tells the add-on the date was set by hand, so it is honoured even when no
    // expiry period is configured - where it would otherwise be ignored, and
    // shown here as active while never applying.
    if ($new_expiry !== '') {
        update_user_meta($customer_id, 'wcu_lifetime_referrer_expire_manual', '1');
    } else {
        delete_user_meta($customer_id, 'wcu_lifetime_referrer_expire_manual');
    }

    $never = __('never expires', 'woo-coupon-usage');
    wcusage_add_activity(
        $customer_id,
        'lifetime_link_added',
        sprintf(
            'coupon %s, expiry %s%s',
            $new_code,
            $new_expiry ? $new_expiry : $never,
            $old_code ? sprintf(' (replaced %s, expiry %s)', $old_code, $old_expiry ? $old_expiry : $never) : ''
        )
    );

    wp_send_json_success(array(
        'message' => sprintf(
            /* translators: 1: customer name, 2: coupon code */
            __( '%1$s is now linked to coupon "%2$s". Their future orders will be attributed to this affiliate.', 'woo-coupon-usage' ),
            $customer_name,
            $new_code
        ),
    ));
});
