<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Get the pending admin task counts, cached in a transient for 1 minute.
 *
 * @param bool $cached_only Return false instead of running the queries when there
 *                          is nothing cached. Used by the page render so an admin
 *                          page never waits on these counts - see
 *                          wcusage_admin_notification_bell().
 * @return array|false
 */
function wcusage_admin_bell_get_counts(  $cached_only = false  ) {
    // Static cache so a single request never runs these twice.
    static $cached_data = null;
    if ( $cached_data !== null ) {
        return $cached_data;
    }
    $cache_key = 'wcusage_admin_bell_counts';
    $cached_counts = get_transient( $cache_key );
    if ( $cached_counts === false ) {
        if ( $cached_only ) {
            return false;
        }
        $pending_registrations = wcusage_get_pending_registrations_count();
        $pending_payouts = 0;
        if ( wcu_fs()->can_use_premium_code() ) {
            $pending_payouts = wcusage_get_pending_payouts_count();
        }
        $pending_direct_links = wcusage_get_pending_direct_links_count();
        $affiliates_exist = wcusage_check_affiliates_exist();
        $cached_counts = array(
            'pending_registrations' => $pending_registrations,
            'pending_payouts'       => $pending_payouts,
            'pending_direct_links'  => $pending_direct_links,
            'affiliates_exist'      => $affiliates_exist,
        );
        set_transient( $cache_key, $cached_counts, 1 * MINUTE_IN_SECONDS );
    }
    $cached_data = $cached_counts;
    return $cached_data;
}

/**
 * Build everything the bell needs: the badge count, whether notifications are
 * enabled, and (optionally) the dropdown contents.
 *
 * @param bool $include_dropdown Whether to build the dropdown markup as well as
 *                               the count.
 * @param bool $cached_only      Return false rather than querying anything that is
 *                               not already cached.
 * @return array|false count, enabled, dropdown_html
 */
function wcusage_get_admin_bell_data(  $include_dropdown = false, $cached_only = false  ) {
    $user_id = get_current_user_id();
    $notifications_enabled = get_user_meta( $user_id, 'wcusage_admin_notifications_enabled', true );
    if ( $notifications_enabled === '' ) {
        $notifications_enabled = '1';
    }
    // default true
    // Notifications are switched off for this user, so the panel only offers to
    // switch them back on and the count is always zero. Nothing below is needed -
    // skip straight past the counting, so a bell that is turned off costs nothing.
    if ( !$notifications_enabled ) {
        return wcusage_admin_bell_prepare_data( array(
            'count'   => 0,
            'enabled' => $notifications_enabled,
        ), $include_dropdown );
    }
    $counts = wcusage_admin_bell_get_counts( $cached_only );
    if ( $counts === false ) {
        return false;
    }
    $pending_registrations = $counts['pending_registrations'];
    $pending_payouts = $counts['pending_payouts'];
    $pending_direct_links = $counts['pending_direct_links'];
    $affiliates_exist = $counts['affiliates_exist'];
    $show_affiliate_notification = !$affiliates_exist;
    $pending_total = intval( $pending_registrations ) + intval( $pending_payouts ) + intval( $pending_direct_links );
    $bell_total = intval( $pending_total ) + (( $show_affiliate_notification ? 1 : 0 ));
    // Get referral notifications
    $meta_key = 'wcusage_referral_notify_last_date';
    $now = current_time( 'mysql' );
    // Per-user cache for the referral count. This query runs on every poll, so
    // without caching it would scan the (potentially very large) activity table on
    // each request. Cleared by wcusage_admin_bell_mark_viewed() when the bell is
    // opened (last-viewed date changes).
    $referral_cache_key = 'wcusage_admin_bell_referrals_' . $user_id;
    $referral_cache = get_transient( $referral_cache_key );
    if ( $referral_cache === false ) {
        if ( $cached_only ) {
            return false;
        }
        $wpdb = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'wcusage_activity';
        $max_days = 7;
        $last_date = sanitize_text_field( get_user_meta( $user_id, $meta_key, true ) );
        if ( !$last_date ) {
            $date_limit = date( 'Y-m-d H:i:s', strtotime( "-{$max_days} days", strtotime( $now ) ) );
        } else {
            $date_limit = $last_date;
        }
        $referral_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s AND date >= %s", 'referral', $date_limit ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $referral_cache = array(
            'count'      => $referral_count,
            'date_limit' => $date_limit,
        );
        set_transient( $referral_cache_key, $referral_cache, 1 * MINUTE_IN_SECONDS );
    }
    $referral_count = intval( $referral_cache['count'] );
    $date_limit = $referral_cache['date_limit'];
    $referral_message = '';
    if ( $referral_count > 0 ) {
        /* translators: 1: number of referrals, 2: date/time */
        $referral_message = sprintf( esc_html__( 'There have been %1$d new affiliate referrals since %2$s.', 'woo-coupon-usage' ), $referral_count, date_i18n( 'F j, Y H:iA', strtotime( $date_limit ) ) );
        $bell_total += $referral_count;
        // Add actual number of new referrals to total
    }
    return wcusage_admin_bell_prepare_data( array(
        'count'                       => $bell_total,
        'enabled'                     => $notifications_enabled,
        'pending_registrations'       => $pending_registrations,
        'pending_payouts'             => $pending_payouts,
        'pending_direct_links'        => $pending_direct_links,
        'pending_total'               => $pending_total,
        'show_affiliate_notification' => $show_affiliate_notification,
        'referral_count'              => $referral_count,
        'referral_message'            => $referral_message,
    ), $include_dropdown );
}

/**
 * Fill in the defaults and, when asked for, the dropdown markup and its hash.
 *
 * @param array $data            Values gathered by wcusage_get_admin_bell_data().
 * @param bool  $include_dropdown Whether to build the markup.
 * @return array
 */
function wcusage_admin_bell_prepare_data(  $data, $include_dropdown  ) {
    $data = array_merge( array(
        'count'                       => 0,
        'enabled'                     => '1',
        'dropdown_html'               => '',
        'dropdown_hash'               => '',
        'pending_registrations'       => 0,
        'pending_payouts'             => 0,
        'pending_direct_links'        => 0,
        'pending_total'               => 0,
        'show_affiliate_notification' => false,
        'referral_count'              => 0,
        'referral_message'            => '',
    ), $data );
    if ( $include_dropdown ) {
        $data['dropdown_html'] = wcusage_admin_bell_dropdown_html( $data );
        // Lets the client tell whether the panel it already has is still current,
        // so the markup only goes over the wire when something has changed.
        $data['dropdown_hash'] = md5( $data['dropdown_html'] );
    }
    return $data;
}

/**
 * Mark the notifications as seen for the current user.
 */
function wcusage_admin_bell_mark_viewed() {
    $user_id = get_current_user_id();
    update_user_meta( $user_id, 'wcusage_referral_notify_last_date', current_time( 'mysql' ) );
    // Last-viewed date changed, so the cached referral count is now stale.
    delete_transient( 'wcusage_admin_bell_referrals_' . $user_id );
}

/**
 * The contents of the bell dropdown (the panel itself is rendered by
 * wcusage_admin_notification_bell() and stays in the DOM).
 *
 * @param array $data Result of wcusage_get_admin_bell_data().
 */
function wcusage_admin_bell_render_dropdown_content(  $data  ) {
    $pending_registrations = $data['pending_registrations'];
    $pending_payouts = $data['pending_payouts'];
    $pending_direct_links = $data['pending_direct_links'];
    $pending_total = $data['pending_total'];
    $show_affiliate_notification = $data['show_affiliate_notification'];
    $referral_count = $data['referral_count'];
    $referral_message = $data['referral_message'];
    if ( !$data['enabled'] ) {
        ?>
        <div style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #1d2327; text-align: center;"><?php 
        echo esc_html__( 'Notifications Disabled', 'woo-coupon-usage' );
        ?></div>
        <div style="padding: 12px 16px; text-align: center;">
            <a href="#" id="wcusage-toggle-notifications" style="color: #0073aa; text-decoration: underline; font-size: 11px;"><?php 
        echo esc_html__( 'Enable Notifications', 'woo-coupon-usage' );
        ?></a>
        </div>
        <?php 
        return;
    }
    ?>
    <?php 
    if ( $referral_count > 0 ) {
        ?>
    <div id="wcusage-admin-bell-referral-section" style="display:block;">
        <div style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #1d2327; text-align: center;"><?php 
        echo esc_html__( 'Notifications', 'woo-coupon-usage' );
        ?></div>
        <div id="wcusage-admin-bell-referral-message" style="padding:10px 16px; border-bottom: 1px solid #f3f3f3;">
            <span class="fa-solid fa-cart-plus" style="margin-right: 5px;"></span>
            <a href="<?php 
        echo esc_url( admin_url( 'admin.php?page=wcusage_referrals' ) );
        ?>"
                style="text-decoration: none; color: #111;">
                <?php 
        echo esc_html( $referral_message );
        ?>
            </a>
        </div>
    </div>
    <?php 
    }
    ?>
    <div style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #1d2327; text-align: center;"><?php 
    echo esc_html__( 'Pending Admin Tasks', 'woo-coupon-usage' );
    ?></div>
    <ul style="list-style: none; margin: 0; padding: 0;" id="wcusage-admin-bell-referral-list">
        <?php 
    if ( $show_affiliate_notification ) {
        ?>
        <li style="padding: 5px 16px 7px 16px; display: flex; align-items: center; gap: 8px; margin-bottom: 0; border-bottom: 1px solid #f3f3f3;">
            <span class="fa-solid fa-user-group" style="color: #f39c12;"></span>
            <span style="font-weight: bold; color: #f39c12;">
            <?php 
        echo sprintf( esc_html__( 'You currently have no %s!', 'woo-coupon-usage' ), esc_html( wcusage_get_affiliate_text( __( 'affiliates', 'woo-coupon-usage' ) ) ) );
        ?>
            <br/>
            <a href="<?php 
        echo esc_url( admin_url( 'admin.php?page=wcusage_add_affiliate' ) );
        ?>"
            style="margin-left: auto; color: #f39c12; text-decoration: underline; font-size: 13px; font-weight: bold;">
                <?php 
        echo sprintf( esc_html__( 'Add your first %s', 'woo-coupon-usage' ), esc_html( wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) ) ) );
        ?>
            </a>
            </span>
        </li>
        <?php 
    }
    ?>
        <?php 
    if ( $pending_registrations > 0 && wcusage_get_setting_value( 'wcusage_field_registration_enable', '1' ) ) {
        ?>
        <li style="padding: 10px 16px; border-bottom: 1px solid #f3f3f3; display: flex; align-items: center; gap: 8px; margin-bottom: 0;">
            <span class="fa-solid fa-user-plus" style="color: #0073aa;"></span>
            <span><?php 
        echo esc_html__( 'Pending Registrations:', 'woo-coupon-usage' );
        ?></span>
            <span style="margin-left: auto; font-weight: bold; color: #d9534f;"><?php 
        echo intval( $pending_registrations );
        ?></span>
            <a href="<?php 
        echo esc_url( admin_url( 'admin.php?page=wcusage_registrations' ) );
        ?>" style="margin-left: 10px; color: #0073aa; text-decoration: underline; font-size: 13px;"><?php 
        echo esc_html__( 'Manage', 'woo-coupon-usage' );
        ?></a>
        </li>
        <?php 
    }
    ?>
        <?php 
    if ( $pending_direct_links > 0 ) {
        ?>
        <li style="padding: 10px 16px; border-bottom: 1px solid #f3f3f3; display: flex; align-items: center; gap: 8px; margin-bottom: 0;">
            <span class="fa-solid fa-globe" style="color: #6f42c1;"></span>
            <span><?php 
        echo esc_html__( 'Pending Domains:', 'woo-coupon-usage' );
        ?></span>
            <span style="margin-left: auto; font-weight: bold; color: #d9534f;"><?php 
        echo intval( $pending_direct_links );
        ?></span>
            <a href="<?php 
        echo esc_url( admin_url( 'admin.php?page=wcusage_domains&status=pending' ) );
        ?>" style="margin-left: 10px; color: #6f42c1; text-decoration: underline; font-size: 13px;"><?php 
        echo esc_html__( 'Manage', 'woo-coupon-usage' );
        ?></a>
        </li>
        <?php 
    }
    ?>
        <?php 
    ?>
    </ul>
    <?php 
    if ( $pending_total == 0 && !$show_affiliate_notification ) {
        ?>
    <div style="padding: 12px 16px; color: #888; text-align: center;"><?php 
        echo esc_html__( 'No pending tasks 🎉', 'woo-coupon-usage' );
        ?></div>
    <?php 
    }
    ?>
    <div style="padding: 4px 16px 9px 16px; border-top: 1px solid #eee; text-align: center;">
        <a href="#" id="wcusage-toggle-notifications" style="color: #0073aa; text-decoration: underline; font-size: 11px;"><?php 
    echo esc_html__( 'Disable Notifications', 'woo-coupon-usage' );
    ?></a>
    </div>
    <?php 
}

/**
 * The dropdown contents as a string, for the AJAX response.
 *
 * @param array $data Result of wcusage_get_admin_bell_data().
 * @return string
 */
function wcusage_admin_bell_dropdown_html(  $data  ) {
    ob_start();
    wcusage_admin_bell_render_dropdown_content( $data );
    return ob_get_clean();
}

/**
 * AJAX handler for admin notification bell
 */
add_action( 'wp_ajax_wcusage_admin_bell_data', 'wcusage_admin_bell_data_ajax' );
function wcusage_admin_bell_data_ajax() {
    check_ajax_referer( 'wcusage_admin_bell', 'nonce' );
    // The panel reports pending registration/payout counts and renders affiliate
    // details, so it needs a capability check of its own - the nonce only proves the
    // request came from the logged-in user, not that they are allowed to see this.
    if ( !wcusage_check_admin_access() ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ),
        ), 403 );
    }
    // Check if this is a bell click (update date) or just a fetch
    $update_date = isset( $_POST['update_date'] ) && $_POST['update_date'] == '1';
    // Hash of the panel the browser is currently showing, so background polls can
    // keep it up to date without sending the markup back every 30 seconds.
    $known_hash = ( isset( $_POST['known_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['known_hash'] ) ) : '' );
    $data = wcusage_get_admin_bell_data( true );
    // Nothing has changed since the browser last received the panel, so there is no
    // need to send it again.
    $dropdown_html = ( $known_hash !== '' && $known_hash === $data['dropdown_hash'] ? '' : $data['dropdown_html'] );
    // Only update last viewed date if bell is clicked. This happens after the data
    // is built, so the response still shows the referrals that were just seen.
    if ( $update_date ) {
        wcusage_admin_bell_mark_viewed();
    }
    wp_send_json( array(
        'count'         => $data['count'],
        'dropdown_html' => $dropdown_html,
        'dropdown_hash' => $data['dropdown_hash'],
        'enabled'       => $data['enabled'],
    ) );
}

/**
 * AJAX handler for toggling admin notifications
 */
add_action( 'wp_ajax_wcusage_toggle_admin_notifications', 'wcusage_toggle_admin_notifications_ajax' );
function wcusage_toggle_admin_notifications_ajax() {
    check_ajax_referer( 'wcusage_admin_bell', 'nonce' );
    $user_id = get_current_user_id();
    $current = get_user_meta( $user_id, 'wcusage_admin_notifications_enabled', true );
    if ( $current === '' ) {
        $current = '1';
    }
    $new_value = ( $current == '1' ? '0' : '1' );
    update_user_meta( $user_id, 'wcusage_admin_notifications_enabled', $new_value );
    wp_send_json( array(
        'enabled' => $new_value,
    ) );
}

/**
 * Output the admin notification bell
 */
function wcusage_admin_notification_bell() {
    // Render the bell *and* the panel contents up front, so opening it is instant
    // instead of waiting for an AJAX round trip. This only uses data that is
    // already cached: on a cold cache the page render does no counting at all and
    // the script fetches it straight after load instead (which caches it for the
    // next page load), so an admin page never waits on these queries.
    $data = wcusage_get_admin_bell_data( true, true );
    $prefetch = $data === false;
    $user_id = get_current_user_id();
    $notifications_enabled = get_user_meta( $user_id, 'wcusage_admin_notifications_enabled', true );
    if ( $notifications_enabled === '' ) {
        $notifications_enabled = '1';
    }
    // default true
    // Enqueue scripts and styles
    $bell_js_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'js/admin-notification-bell.js';
    $bell_js_ver = ( file_exists( $bell_js_path ) ? filemtime( $bell_js_path ) : WCUSAGE_VERSION );
    wp_enqueue_script(
        'wcusage-admin-notification-bell',
        WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-notification-bell.js',
        array('jquery'),
        $bell_js_ver,
        true
    );
    // Polling interval in seconds. Filterable so it can be tuned per-site; minimum 5s.
    $poll_interval = max( 5, intval( apply_filters( 'wcusage_admin_bell_poll_interval', 30 ) ) );
    wp_localize_script( 'wcusage-admin-notification-bell', 'wcusageAdminBell', array(
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'wcusage_admin_bell' ),
        'interval'     => $poll_interval * 1000,
        'prefetch'     => ( $prefetch ? '1' : '0' ),
        'content_hash' => ( $prefetch ? '' : $data['dropdown_hash'] ),
        'enabled'      => $notifications_enabled,
    ) );
    // Add inline CSS for bell shake animation
    $shake_css = "\n    @keyframes wcusage-bell-shake {\n        0% { transform: rotate(0deg); }\n        25% { transform: rotate(-10deg); }\n        50% { transform: rotate(10deg); }\n        75% { transform: rotate(-10deg); }\n        100% { transform: rotate(0deg); }\n    }\n    .wcusage-bell-shake {\n        animation: wcusage-bell-shake 0.5s ease-in-out;\n    }\n    ";
    wp_add_inline_style( 'wcusage-admin-header-menu', $shake_css );
    $count = ( $prefetch ? 0 : intval( $data['count'] ) );
    ?>
    <div class="wcusage-admin-bell-container" style="position: relative; margin-left: 10px;">
        <a href="#" id="wcusage-admin-bell" role="button" aria-haspopup="true" aria-expanded="false"
            aria-label="<?php 
    echo esc_attr__( 'Notifications', 'woo-coupon-usage' );
    ?>"
            style="display: flex; align-items: center; position: relative; text-decoration: none; <?php 
    if ( !$notifications_enabled ) {
        echo 'opacity: 0.5;';
    }
    ?>">
            <span class="fa-solid fa-bell" style="font-size: 22px; color: #333;"></span>
            <span class="wcusage-admin-bell-count" style="position: absolute;
            top: -11px; right: -2px; background: #d9534f; color: #fff;
            font-size: 10px; font-weight: bold; border-radius: 50%;
            padding: 1px 1px; min-width: 22px; text-align: center; box-shadow: 0 2px 8px rgba(217,83,79,0.15); <?php 
    if ( $count < 1 ) {
        echo 'display: none;';
    }
    ?>"><?php 
    echo ( $count > 0 ? esc_html( $count ) : '' );
    ?></span>
        </a>
        <div id="wcusage-admin-bell-dropdown" style="display: none; position: absolute; margin-top: 10px; left: 50%; top: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; width: 300px; transform: translateX(-50%); box-shadow: 0 2px 16px rgba(0,0,0,0.12); z-index: 99999;">
            <div id="wcusage-admin-bell-dropdown-content">
                <?php 
    if ( $prefetch ) {
        ?>
                <div style="padding: 16px; color: #787c82; text-align: center;">
                    <span class="fa-solid fa-spinner fa-spin" style="margin-right: 6px;"></span><?php 
        echo esc_html__( 'Loading...', 'woo-coupon-usage' );
        ?>
                </div>
                <?php 
    } else {
        ?>
                <?php 
        echo $data['dropdown_html'];
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by wcusage_admin_bell_render_dropdown_content(), which escapes every value. Re-rendering it here would just build the same string twice.
        ?>
                <?php 
    }
    ?>
            </div>
        </div>
    </div>
    <?php 
}

add_action( 'wcusage_hook_admin_notification_bell', 'wcusage_admin_notification_bell' );
/**
 * Helper functions
 */
function wcusage_clear_admin_bell_cache() {
    delete_transient( 'wcusage_admin_bell_counts' );
}

// Clear cache when relevant data changes
add_action( 'wcusage_hook_registration_status_changed', 'wcusage_clear_admin_bell_cache' );
add_action( 'wcusage_hook_payout_status_changed', 'wcusage_clear_admin_bell_cache' );
add_action(
    'updated_post_meta',
    function (
        $meta_id,
        $post_id,
        $meta_key,
        $meta_value
    ) {
        if ( $meta_key === 'wcu_select_coupon_user' ) {
            wcusage_clear_admin_bell_cache();
        }
    },
    10,
    4
);
add_action(
    'deleted_post_meta',
    function (
        $meta_ids,
        $post_id,
        $meta_key,
        $meta_value
    ) {
        if ( $meta_key === 'wcu_select_coupon_user' ) {
            wcusage_clear_admin_bell_cache();
        }
    },
    10,
    4
);
function wcusage_get_pending_registrations_count() {
    global $wpdb;
    return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_register WHERE status = 'pending'" );
}

function wcusage_get_pending_payouts_count() {
    global $wpdb;
    return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_payouts WHERE status = 'pending'" );
}

function wcusage_get_pending_direct_links_count() {
    global $wpdb;
    $pending_direct_links = 0;
    $direct_links_enabled = wcusage_get_setting_value( 'wcusage_field_enable_directlinks', 0 );
    if ( $direct_links_enabled && wcu_fs()->can_use_premium_code() ) {
        $direct_links_table = $wpdb->prefix . 'wcusage_directlinks';
        $pending_direct_links = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$direct_links_table} WHERE status = 'pending'" ) );
    }
    return $pending_direct_links;
}

function wcusage_check_affiliates_exist() {
    // Use direct SQL query - much faster than get_posts(). Only whether there is at
    // least one matters, so stop at the first match rather than counting them all
    // (LIMIT does nothing on a COUNT, so this used to scan every affiliate coupon).
    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id\n         FROM {$wpdb->postmeta} pm\n         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n         WHERE pm.meta_key = %s\n         AND CAST(pm.meta_value AS UNSIGNED) > 0\n         AND p.post_type = %s\n         AND p.post_status IN ('publish', 'pending', 'draft')\n         LIMIT 1", 'wcu_select_coupon_user', 'shop_coupon' ) );
    return !empty( $exists );
}
