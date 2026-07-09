<?php

/**
 * Refresh Statistics box + AJAX handlers for the "View Affiliate" admin page.
 *
 * Lets an admin re-calculate each of an affiliate's coupons all-time statistics
 * directly from this page (via AJAX), without having to open each affiliate
 * dashboard. This is a full refresh: it recalculates the individual order stats
 * as well as the all-time totals — the same work the affiliate dashboard does on
 * its "REFRESH ALL DATA" pass. The "Never update the saved commission value for
 * past orders" option is respected because the recalculation flows through
 * wcusage_calculate_order_data(), which honours that setting.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Render the "Refresh Statistics" sidebar box.
 *
 * @param int   $user_id
 * @param array $coupons  Array of coupon post IDs assigned to the affiliate.
 */
if ( !function_exists( 'wcusage_render_refresh_statistics_box' ) ) {
    function wcusage_render_refresh_statistics_box(  $user_id, $coupons  ) {
        if ( empty( $coupons ) ) {
            return;
        }
        // A single coupon (e.g. the coupon edit page) still shows its checkbox,
        // but locked (checked + disabled) since there is nothing else to choose.
        $is_single = count( $coupons ) === 1;
        ?>
        <div class="wcusage-refresh-stats-box" id="wcusage-refresh-stats-box" data-user-id="<?php 
        echo esc_attr( $user_id );
        ?>">
            <button type="button" class="wcusage-refresh-toggle" aria-expanded="false" aria-controls="wcusage-refresh-panel">
                <span class="wcusage-refresh-toggle-main">
                    <i class="fas fa-sync"></i>
                    <span class="wcusage-refresh-toggle-title"><?php 
        echo esc_html__( 'Refresh Statistics', 'woo-coupon-usage' );
        ?> <span class="dashicons dashicons-arrow-down-alt2"></span></span>
                    <span class="wcusage-refresh-toggle-hint"><?php 
        echo esc_html__( 'Recalculate all-time coupon statistics without opening each affiliate dashboard', 'woo-coupon-usage' );
        ?></span>
                </span>
            </button>

            <div class="wcusage-refresh-panel" id="wcusage-refresh-panel" style="display: none;">

                <?php 
        if ( !$is_single ) {
            ?>
                <label class="wcusage-refresh-selectall-row">
                    <input type="checkbox" class="wcusage-refresh-selectall" checked />
                    <strong><?php 
            echo esc_html__( 'Select all coupons', 'woo-coupon-usage' );
            ?></strong>
                </label>
                <?php 
        }
        ?>

                <ul class="wcusage-refresh-coupon-list">
                    <?php 
        foreach ( $coupons as $coupon_id ) {
            $coupon_code = get_the_title( $coupon_id );
            ?>
                        <li class="wcusage-refresh-coupon-item" data-coupon="<?php 
            echo esc_attr( $coupon_id );
            ?>">
                            <label class="wcusage-refresh-coupon-label">
                                <input type="checkbox" class="wcusage-refresh-coupon-cb<?php 
            echo ( $is_single ? ' wcusage-refresh-cb-locked' : '' );
            ?>" value="<?php 
            echo esc_attr( $coupon_id );
            ?>" checked <?php 
            disabled( $is_single );
            ?> />
                                <span class="wcusage-refresh-coupon-code"><?php 
            echo esc_html( $coupon_code );
            ?></span>
                                <span class="wcusage-refresh-coupon-status"></span>
                            </label>
                            <div class="wcusage-refresh-progress" style="display: none;">
                                <div class="wcusage-refresh-progress-bar">
                                    <div class="wcusage-refresh-progress-fill"></div>
                                </div>
                            </div>
                        </li>
                    <?php 
        }
        ?>
                </ul>

                <p class="wcusage-refresh-start-desc">
                    <?php 
        echo esc_html__( "This will go through every order and recalculates the all-time statistics - re-checking each order's commission.", 'woo-coupon-usage' );
        ?>
                </p>

                <button type="button" class="button button-primary wcusage-refresh-start">
                    <i class="fas fa-sync" style="margin-right: 6px;"></i>
                    <?php 
        echo esc_html__( 'Start Statistics Refresh', 'woo-coupon-usage' );
        ?>
                </button>

                <div class="wcusage-refresh-log" style="display: none;"></div>
            </div>
        </div>
        <?php 
    }

}
/**
 * Render a compact grid of a single coupon's all-time stat boxes
 * (Sales, Commission, and — when the relevant features are enabled —
 * Processing and Unpaid). Used above the Refresh Statistics box on the
 * coupon edit page; the boxes update live when a refresh completes.
 *
 * @param int $coupon_id
 */
if ( !function_exists( 'wcusage_render_coupon_stat_boxes' ) ) {
    function wcusage_render_coupon_stat_boxes(  $coupon_id  ) {
        $coupon_id = (int) $coupon_id;
        if ( !$coupon_id ) {
            return;
        }
        $alltime = get_post_meta( $coupon_id, 'wcu_alltime_stats', true );
        $total_orders = ( is_array( $alltime ) && isset( $alltime['total_orders'] ) ? (float) $alltime['total_orders'] : 0 );
        $full_discount = ( is_array( $alltime ) && isset( $alltime['full_discount'] ) ? (float) $alltime['full_discount'] : 0 );
        $total_commission = ( is_array( $alltime ) && isset( $alltime['total_commission'] ) ? (float) $alltime['total_commission'] : 0 );
        $sales = $total_orders - $full_discount;
        $processing = (float) get_post_meta( $coupon_id, 'wcu_text_pending_order_commission', true );
        $unpaid = (float) get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true );
        // Mirror the optional-column gating used on the View Affiliate page.
        $show_processing = false;
        $show_unpaid = false;
        ?>
        <p style="font-weight: bold;" class="wcusage-coupon-stats-title"><?php 
        echo esc_html__( 'All-Time Statistics Summary:', 'woo-coupon-usage' );
        ?></p>
        <div class="wcusage-coupon-stats-grid">
            <div class="wcusage-coupon-stat-box" data-stat="sales">
                <div class="wcusage-coupon-stat-value"><?php 
        echo wcusage_format_price( $sales );
        /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe formatted price markup from internal helper. */
        ?></div>
                <div class="wcusage-coupon-stat-label"><?php 
        echo esc_html__( 'Sales', 'woo-coupon-usage' );
        ?></div>
            </div>
            <div class="wcusage-coupon-stat-box" data-stat="commission">
                <div class="wcusage-coupon-stat-value"><?php 
        echo wcusage_format_price( $total_commission );
        /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe formatted price markup from internal helper. */
        ?></div>
                <div class="wcusage-coupon-stat-label"><?php 
        echo esc_html__( 'Commission', 'woo-coupon-usage' );
        ?></div>
            </div>
            <?php 
        if ( $show_processing ) {
            ?>
            <div class="wcusage-coupon-stat-box" data-stat="processing">
                <div class="wcusage-coupon-stat-value"><?php 
            echo wcusage_format_price( $processing );
            /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe formatted price markup from internal helper. */
            ?></div>
                <div class="wcusage-coupon-stat-label"><?php 
            echo esc_html__( 'Processing', 'woo-coupon-usage' );
            ?></div>
            </div>
            <?php 
        }
        ?>
            <?php 
        if ( $show_unpaid ) {
            ?>
            <div class="wcusage-coupon-stat-box" data-stat="unpaid">
                <div class="wcusage-coupon-stat-value"><?php 
            echo wcusage_format_price( $unpaid );
            /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe formatted price markup from internal helper. */
            ?></div>
                <div class="wcusage-coupon-stat-label"><?php 
            echo esc_html__( 'Unpaid', 'woo-coupon-usage' );
            ?></div>
            </div>
            <?php 
        }
        ?>
        </div>
        <?php 
    }

}
/**
 * Render the "Reset Start Date" collapsible box for the coupon edit page.
 *
 * Lets an admin change the coupon's history start date and immediately
 * recalculate its statistics. The date field is kept in sync with the
 * "Coupon History Start Date" field in the coupon data panel.
 *
 * @param int $coupon_id
 */
if ( !function_exists( 'wcusage_render_reset_start_date_box' ) ) {
    function wcusage_render_reset_start_date_box(  $coupon_id  ) {
        $coupon_id = (int) $coupon_id;
        if ( !$coupon_id ) {
            return;
        }
        $start_date = get_post_meta( $coupon_id, 'wcu_text_coupon_start_date', true );
        $start_date_is_set = '' !== (string) $start_date;
        $start_date_display = ( $start_date_is_set ? date_i18n( get_option( 'date_format' ), strtotime( $start_date ) ) : '' );
        ?>
        <div class="wcusage-startdate-box" id="wcusage-startdate-box" data-coupon="<?php 
        echo esc_attr( $coupon_id );
        ?>">
            <p class="wcusage-startdate-current"<?php 
        echo ( $start_date_is_set ? '' : ' style="display: none;"' );
        ?>>
                <i class="fas fa-calendar-day" style="margin-right: 5px;"></i>
                <?php 
        echo esc_html__( 'History start date set to', 'woo-coupon-usage' );
        ?>
                <strong class="wcusage-startdate-current-value"><?php 
        echo esc_html( $start_date_display );
        ?></strong>
            </p>
            <button type="button" class="wcusage-startdate-toggle" aria-expanded="false" aria-controls="wcusage-startdate-panel">
                <span class="wcusage-startdate-toggle-main">
                    <i class="fas fa-calendar-day"></i>
                    <span class="wcusage-startdate-toggle-title"><?php 
        echo esc_html__( 'Reset Start Date', 'woo-coupon-usage' );
        ?> <span class="dashicons dashicons-arrow-down-alt2"></span></span>
                </span>
                <i class="fas fa-chevron-down wcusage-startdate-toggle-arrow" aria-hidden="true"></i>
            </button>

            <div class="wcusage-startdate-panel" id="wcusage-startdate-panel" style="display: none;">
                <p class="wcusage-startdate-desc">
                    <?php 
        echo esc_html__( "Set the date this coupon's statistics start counting from - orders before it are excluded. Saving here updates the \"Coupon History Start Date\" field and recalculates the all-time statistics.", 'woo-coupon-usage' );
        ?>
                </p>
                <?php 
        $wcu_startdate_required = '' === (string) $start_date;
        ?>
                <label class="wcusage-startdate-field-label" for="wcusage-startdate-input"><?php 
        echo esc_html__( 'Coupon History Start Date', 'woo-coupon-usage' );
        if ( $wcu_startdate_required ) {
            ?> <span class="wcusage-startdate-required" aria-hidden="true">*</span><?php 
        }
        ?></label>
                <?php 
        // NB: no HTML "required" attribute here. This input lives inside the main
        // coupon <form id="post">, and a required-but-empty field in a display:none
        // panel makes the browser silently block "Update" (an invalid, non-focusable
        // control), preventing the coupon from saving. The Reset button's own JS
        // (updateResetBtnState) already enforces that a date is entered.
        ?>
                <input type="date" id="wcusage-startdate-input" class="wcusage-startdate-input" value="<?php 
        echo esc_attr( $start_date );
        ?>" />
                <button type="button" class="button button-primary wcusage-startdate-reset-btn" disabled>
                    <i class="fas fa-rotate-left" style="margin-right: 6px;"></i>
                    <?php 
        echo esc_html__( 'Reset', 'woo-coupon-usage' );
        ?>
                </button>
            </div>
        </div>
        <?php 
    }

}
/**
 * i18n strings passed to the refresh JavaScript. Shared by every context that
 * renders the box so the wording stays in one place.
 *
 * @return array
 */
if ( !function_exists( 'wcusage_refresh_i18n' ) ) {
    function wcusage_refresh_i18n() {
        return array(
            'starting'          => esc_html__( 'Starting…', 'woo-coupon-usage' ),
            'refreshing'        => esc_html__( 'Refreshing…', 'woo-coupon-usage' ),
            'saving'            => esc_html__( 'Saving…', 'woo-coupon-usage' ),
            'done'              => esc_html__( 'Done', 'woo-coupon-usage' ),
            'error'             => esc_html__( 'Error', 'woo-coupon-usage' ),
            'complete'          => esc_html__( 'Statistics refresh complete.', 'woo-coupon-usage' ),
            'none'              => esc_html__( 'Please select at least one coupon to refresh.', 'woo-coupon-usage' ),
            'confirm'           => esc_html__( 'Refresh statistics for the selected coupons now? This will go through every order, recalculate their stats and commission, then update the all-time statistics.', 'woo-coupon-usage' ),
            'startdate_confirm' => esc_html__( 'Save this start date and recalculate the statistics now?', 'woo-coupon-usage' ),
            'startdate_saved'   => esc_html__( 'Start date saved.', 'woo-coupon-usage' ),
            'startdate_error'   => esc_html__( 'Could not save the start date.', 'woo-coupon-usage' ),
        );
    }

}
/**
 * Enqueue the refresh box assets (CSS + JS + config) for a standalone context
 * such as the coupon edit page.
 *
 * The View Affiliate page localizes its own WCUAdminAffiliateView object (with
 * many other keys), so it enqueues the CSS itself and does not call this.
 *
 * @param int $user_id  Affiliate user ID associated with the coupon, or 0.
 */
if ( !function_exists( 'wcusage_refresh_enqueue_assets' ) ) {
    function wcusage_refresh_enqueue_assets(  $user_id = 0  ) {
        $css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-refresh-statistics.css';
        $css_ver = ( file_exists( $css_path ) ? filemtime( $css_path ) : WCUSAGE_VERSION );
        wp_enqueue_style(
            'wcusage-admin-refresh-statistics',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-refresh-statistics.css',
            array(),
            $css_ver
        );
        $js_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'js/admin-view-affiliate-refresh.js';
        $js_ver = ( file_exists( $js_path ) ? filemtime( $js_path ) : WCUSAGE_VERSION );
        wp_enqueue_script(
            'wcusage-admin-view-affiliate-refresh',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-view-affiliate-refresh.js',
            array('jquery'),
            $js_ver,
            true
        );
        wp_localize_script( 'wcusage-admin-view-affiliate-refresh', 'WCUAdminAffiliateView', array(
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'user_id'            => $user_id,
            'nonce_refresh'      => wp_create_nonce( 'wcusage_admin_refresh_nonce' ),
            'refresh_batch_days' => intval( wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_batch_amount', '20' ) ),
            'refresh_i18n'       => wcusage_refresh_i18n(),
        ) );
    }

}
/**
 * Shared access/ownership check for the refresh AJAX handlers.
 *
 * Verifies the nonce, admin capability, and that the requested coupon really
 * belongs to the requested affiliate. Sends a JSON error and dies on failure.
 *
 * @return array  [ int $user_id, int $coupon_id, string $coupon_code ]
 */
if ( !function_exists( 'wcusage_admin_refresh_verify_request' ) ) {
    function wcusage_admin_refresh_verify_request() {
        check_ajax_referer( 'wcusage_admin_refresh_nonce', 'security' );
        if ( !wcusage_check_admin_access() ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ),
            ) );
        }
        $user_id = ( isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0 );
        $coupon_id = ( isset( $_POST['coupon_id'] ) ? intval( $_POST['coupon_id'] ) : 0 );
        // The coupon must be a real coupon.
        if ( !$coupon_id || get_post_type( $coupon_id ) !== 'shop_coupon' ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Invalid request.', 'woo-coupon-usage' ),
            ) );
        }
        // When a specific affiliate is in context (e.g. the View Affiliate page),
        // confirm the coupon is actually assigned to them. On the coupon edit page
        // there may be no affiliate in context, in which case admin access alone
        // (verified above) is sufficient to refresh the coupon. A direct meta read
        // is used (rather than the transient-cached list) so a coupon that was just
        // reassigned is still recognised.
        if ( $user_id ) {
            $coupon_owner = (int) get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
            if ( $coupon_owner !== $user_id ) {
                wp_send_json_error( array(
                    'message' => esc_html__( 'This coupon is not assigned to this affiliate.', 'woo-coupon-usage' ),
                ) );
            }
        }
        $coupon_code = get_the_title( $coupon_id );
        if ( !$coupon_code ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Coupon not found.', 'woo-coupon-usage' ),
            ) );
        }
        return array($user_id, $coupon_id, $coupon_code);
    }

}
/**
 * Get the first and last order dates for a coupon.
 *
 * Mirrors the date-range query used by wcusage_update_all_stats_batch_ajax()
 * so the admin refresh walks the exact same order range as a dashboard refresh.
 *
 * @param string $coupon_code
 * @return array  [ 'first' => 'Y-m-d', 'last' => 'Y-m-d' ]
 */
if ( !function_exists( 'wcusage_admin_refresh_get_coupon_daterange' ) ) {
    function wcusage_admin_refresh_get_coupon_daterange(  $coupon_code  ) {
        global $wpdb;
        $coupon_code = sanitize_text_field( $coupon_code );
        $wcusage_field_order_type_custom = wcusage_get_setting_value( 'wcusage_field_order_type_custom', '' );
        if ( !$wcusage_field_order_type_custom ) {
            $statuses = wc_get_order_statuses();
            if ( isset( $statuses['wc-refunded'] ) ) {
                unset($statuses['wc-refunded']);
            }
        } else {
            $statuses = $wcusage_field_order_type_custom;
        }
        // Custom Orders Table (HPOS) or legacy posts table.
        $order_util_class = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if ( class_exists( $order_util_class ) && method_exists( $order_util_class, 'custom_orders_table_usage_is_enabled' ) && call_user_func( array($order_util_class, 'custom_orders_table_usage_is_enabled') ) ) {
            $id = 'id';
            $posts = 'wc_orders';
            $postmeta = 'wc_orders_meta';
            $post_date = 'date_created_gmt';
            $post_status = 'status';
            $post_id = 'order_id';
        } else {
            $id = 'ID';
            $posts = 'posts';
            $postmeta = 'postmeta';
            $post_date = 'post_date';
            $post_status = 'post_status';
            $post_id = 'post_id';
        }
        $query = $wpdb->prepare(
            "SELECT DISTINCT p." . $id . " AS order_id, p." . $post_date . " AS order_date\n            FROM {$wpdb->prefix}" . $posts . " AS p\n            LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS woi\n                ON p." . $id . " = woi.order_id AND woi.order_item_type = 'coupon' AND woi.order_item_name = %s\n            LEFT JOIN {$wpdb->prefix}" . $postmeta . " AS woi2\n                ON p." . $id . " = woi2." . $post_id . " AND (\n                    (woi2.meta_key = 'lifetime_affiliate_coupon_referrer' AND woi2.meta_value = %s) OR\n                    (woi2.meta_key = 'wcusage_referrer_coupon' AND woi2.meta_value = %s)\n                )\n            WHERE p." . $post_status . " IN ('" . implode( "','", array_keys( $statuses ) ) . "')\n            AND (woi.order_id IS NOT NULL OR woi2.meta_value = %s AND woi2.meta_key IS NOT NULL)",
            $coupon_code,
            $coupon_code,
            $coupon_code,
            $coupon_code
        );
        $date_range_query = "SELECT MIN(sub.order_date) AS first_date, MAX(sub.order_date) AS last_date FROM (" . $query . ") AS sub";
        $date_range = $wpdb->get_row( $date_range_query );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL
        if ( $date_range && $date_range->first_date ) {
            $first_order_date = $date_range->first_date;
            $wcusage_hide_all_time = wcusage_get_setting_value( 'wcusage_field_hide_all_time', '0' );
            if ( $wcusage_hide_all_time ) {
                $first_order_date = date( 'Y-m-d' );
            }
        } else {
            $first_order_date = date( 'Y-m-d' );
        }
        if ( $date_range && $date_range->last_date ) {
            $last_order_date = $date_range->last_date;
        } else {
            $last_order_date = date( 'Y-m-d' );
        }
        return array(
            'first' => date( 'Y-m-d', strtotime( $first_order_date ) ),
            'last'  => date( 'Y-m-d', strtotime( $last_order_date ) ),
        );
    }

}
/**
 * AJAX: Start refreshing a single coupon — returns its order date range.
 */
if ( !function_exists( 'wcusage_admin_refresh_start_ajax' ) ) {
    function wcusage_admin_refresh_start_ajax() {
        list( $user_id, $coupon_id, $coupon_code ) = wcusage_admin_refresh_verify_request();
        $range = wcusage_admin_refresh_get_coupon_daterange( $coupon_code );
        // Mark the coupon as needing a refresh straight away. If this admin-side
        // refresh is interrupted before it finishes (the save step re-sets this to
        // the current time), the affiliate dashboard will still recalculate the
        // stats the next time it is loaded. This is the same trigger the old
        // "REFRESH ALL DATA" link used (see wcusage_check_if_refresh_needed()).
        delete_post_meta( $coupon_id, 'wcu_last_refreshed' );
        // Reset the processing (pending) commission total before the per-order
        // recalculation runs. Each pending order re-adds its commission to this
        // meta during the refresh, so it must start from zero to avoid doubling
        // up on top of the previous value (mirrors the dashboard batch refresh).
        update_post_meta( $coupon_id, 'wcu_text_pending_order_commission', 0 );
        wp_send_json_success( array(
            'coupon_id'   => $coupon_id,
            'coupon_code' => $coupon_code,
            'first_date'  => $range['first'],
            'last_date'   => $range['last'],
        ) );
    }

    add_action( 'wp_ajax_wcusage_admin_refresh_start', 'wcusage_admin_refresh_start_ajax' );
}
/**
 * AJAX: Return the stats for one date-range batch of a coupon (full refresh).
 *
 * Uses wcusage_wh_getOrderbyCouponCode() with $update = 1 and $alltime = 1 so
 * each order's saved stats are recalculated (picking up new commission rates),
 * exactly like the affiliate-dashboard refresh. The "Never update the saved
 * commission value for past orders" option is honoured inside that call chain.
 */
if ( !function_exists( 'wcusage_admin_refresh_batch_ajax' ) ) {
    function wcusage_admin_refresh_batch_ajax() {
        list( $user_id, $coupon_id, $coupon_code ) = wcusage_admin_refresh_verify_request();
        $start = ( isset( $_POST['start'] ) ? sanitize_text_field( $_POST['start'] ) : '' );
        $end = ( isset( $_POST['end'] ) ? sanitize_text_field( $_POST['end'] ) : '' );
        // Full refresh: refresh = 1, update = 1, alltime = 1.
        $orders = wcusage_wh_getOrderbyCouponCode(
            $coupon_code,
            $start,
            $end,
            '',
            1,
            1,
            1
        );
        $allstats = ( isset( $orders['allstats'] ) && is_array( $orders['allstats'] ) ? $orders['allstats'] : array() );
        $response = array(
            'total_orders'       => ( isset( $allstats['total_orders'] ) ? (float) $allstats['total_orders'] : 0 ),
            'full_discount'      => ( isset( $allstats['full_discount'] ) ? (float) $allstats['full_discount'] : 0 ),
            'total_commission'   => ( isset( $allstats['total_commission'] ) ? (float) $allstats['total_commission'] : 0 ),
            'total_shipping'     => ( isset( $allstats['total_shipping'] ) ? (float) $allstats['total_shipping'] : 0 ),
            'total_count'        => ( isset( $allstats['total_count'] ) ? (float) $allstats['total_count'] : 0 ),
            'commission_summary' => ( isset( $allstats['commission_summary'] ) && is_array( $allstats['commission_summary'] ) ? $allstats['commission_summary'] : array() ),
        );
        wp_send_json_success( $response );
    }

    add_action( 'wp_ajax_wcusage_admin_refresh_batch', 'wcusage_admin_refresh_batch_ajax' );
}
/**
 * AJAX: Persist the accumulated all-time stats for a coupon and return the
 * freshly-formatted values for the "Affiliates Coupons" table row.
 */
if ( !function_exists( 'wcusage_admin_refresh_save_ajax' ) ) {
    function wcusage_admin_refresh_save_ajax() {
        list( $user_id, $coupon_id, $coupon_code ) = wcusage_admin_refresh_verify_request();
        $stats = ( isset( $_POST['stats'] ) && is_array( $_POST['stats'] ) ? wp_unslash( $_POST['stats'] ) : array() );
        // Sanitize the accumulated stats (mirrors wcusage_update_all_stats_data()).
        $allstats = array();
        $allstats['total_orders'] = ( isset( $stats['total_orders'] ) ? floatval( $stats['total_orders'] ) : 0 );
        $allstats['full_discount'] = ( isset( $stats['full_discount'] ) ? floatval( $stats['full_discount'] ) : 0 );
        $allstats['total_commission'] = ( isset( $stats['total_commission'] ) ? floatval( $stats['total_commission'] ) : 0 );
        $allstats['total_shipping'] = ( isset( $stats['total_shipping'] ) ? floatval( $stats['total_shipping'] ) : 0 );
        $allstats['total_count'] = ( isset( $stats['total_count'] ) ? floatval( $stats['total_count'] ) : 0 );
        $allstats['commission_summary'] = array();
        if ( isset( $stats['commission_summary'] ) && is_array( $stats['commission_summary'] ) ) {
            foreach ( $stats['commission_summary'] as $key => $value ) {
                $sanitized_key = sanitize_text_field( $key );
                if ( is_array( $value ) || is_object( $value ) ) {
                    $value = (array) $value;
                    $allstats['commission_summary'][$sanitized_key] = array(
                        'total'      => ( isset( $value['total'] ) ? floatval( $value['total'] ) : 0 ),
                        'commission' => ( isset( $value['commission'] ) ? floatval( $value['commission'] ) : 0 ),
                        'number'     => ( isset( $value['number'] ) ? intval( $value['number'] ) : 0 ),
                    );
                }
            }
        }
        // Persist (same meta updates a dashboard refresh performs).
        update_post_meta( $coupon_id, 'wcu_alltime_stats', $allstats );
        update_post_meta( $coupon_id, 'wcu_last_refreshed', time() );
        delete_post_meta( $coupon_id, 'wcusage_monthly_summary_data' );
        delete_post_meta( $coupon_id, 'wcusage_monthly_summary_data_orders' );
        delete_post_meta( $coupon_id, 'wcusage_monthly_cache_time_current' );
        // Keep the stored commission message in sync with the current rate so the
        // affiliate dashboard does not treat the coupon as still needing a refresh
        // on its next load (see wcusage_check_if_refresh_needed()).
        if ( function_exists( 'wcusage_commission_message' ) ) {
            update_post_meta( $coupon_id, 'wcu_commission_message', wcusage_commission_message( $coupon_id ) );
        }
        // Build the refreshed table-cell values (mirrors wcusage_display_affiliate_stats()).
        $total_count = (int) $allstats['total_count'];
        $coupon_sales = (float) $allstats['total_orders'] - (float) $allstats['full_discount'];
        $qmessage = esc_html__( 'The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage' );
        // Fallback usage from WooCommerce if the recalculated count is empty.
        $usage = $total_count;
        if ( !$usage ) {
            $c = new WC_Coupon($coupon_code);
            $usage = $c->get_usage_count();
        }
        // The stats were just recalculated and saved (wcu_alltime_stats is now a
        // non-empty array), so always show the value — even a genuine 0.00 —
        // rather than the "needs refresh" indicator. This keeps the AJAX response
        // consistent with what the page renders on reload.
        $sales_html = wcusage_format_price( $coupon_sales );
        if ( function_exists( 'wcusage_coupons_get_commission_column_output' ) ) {
            $commission_html = wcusage_coupons_get_commission_column_output(
                $coupon_id,
                $allstats,
                $total_count,
                $qmessage
            );
        } else {
            $commission_html = wcusage_format_price( $allstats['total_commission'] );
        }
        // Processing is re-summed by the full refresh above; Unpaid is managed
        // separately, so return its current stored value.
        $processing_html = wcusage_format_price( (float) get_post_meta( $coupon_id, 'wcu_text_pending_order_commission', true ) );
        $unpaid_html = wcusage_format_price( (float) get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true ) );
        wp_send_json_success( array(
            'coupon_id'              => $coupon_id,
            'usage'                  => $usage,
            'sales_html'             => $sales_html,
            'commission_html'        => $commission_html,
            'processing_html'        => $processing_html,
            'unpaid_html'            => $unpaid_html,
            'sales_amount_html'      => wcusage_format_price( $coupon_sales ),
            'commission_amount_html' => wcusage_format_price( $allstats['total_commission'] ),
        ) );
    }

    add_action( 'wp_ajax_wcusage_admin_refresh_save', 'wcusage_admin_refresh_save_ajax' );
}
/**
 * AJAX: Save a coupon's history start date, then flag it for a stats refresh.
 *
 * Mirrors what saving the coupon does (update the meta + clear wcu_last_refreshed
 * so the dashboard recalculates). The JS follows this with the normal refresh
 * flow so the statistics are rebuilt immediately.
 */
if ( !function_exists( 'wcusage_admin_save_coupon_start_date_ajax' ) ) {
    function wcusage_admin_save_coupon_start_date_ajax() {
        list( $user_id, $coupon_id, $coupon_code ) = wcusage_admin_refresh_verify_request();
        $start_date = ( isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '' );
        // Allow an empty value (full history) or a strict YYYY-MM-DD date only.
        if ( $start_date !== '' && !preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $start_date ) ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Invalid date.', 'woo-coupon-usage' ),
            ) );
        }
        update_post_meta( $coupon_id, 'wcu_text_coupon_start_date', $start_date );
        // Force a statistics refresh (same trigger the coupon save + admin refresh use).
        delete_post_meta( $coupon_id, 'wcu_last_refreshed' );
        wp_send_json_success( array(
            'coupon_id'          => $coupon_id,
            'start_date'         => $start_date,
            'start_date_display' => ( '' !== $start_date ? date_i18n( get_option( 'date_format' ), strtotime( $start_date ) ) : '' ),
        ) );
    }

    add_action( 'wp_ajax_wcusage_admin_save_coupon_start_date', 'wcusage_admin_save_coupon_start_date_ajax' );
}