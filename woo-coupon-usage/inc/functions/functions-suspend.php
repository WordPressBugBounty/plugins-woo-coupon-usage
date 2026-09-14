<?php
/**
 * Affiliate suspension.
 *
 * Suspending an affiliate "pauses" their account instead of deleting it:
 *
 *  - They lose access to the affiliate dashboard, the affiliate portal and the
 *    MLA dashboard.
 *  - Every coupon assigned to them stops validating, so it can no longer be
 *    applied to a cart, auto-applied from a referral link, or used at checkout.
 *  - Nothing is removed. The user, their coupons, statistics, commission and
 *    payout history all stay exactly as they were and remain fully viewable and
 *    editable by admins, so a suspension can be lifted at any time.
 *
 * Coupons are never suspended on their own - a coupon is locked when the
 * affiliate it is assigned to is suspended.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User meta keys used to store the suspension.
 */
if ( ! defined( 'WCUSAGE_SUSPENDED_META' ) ) {
    define( 'WCUSAGE_SUSPENDED_META', 'wcusage_affiliate_suspended' );
}
if ( ! defined( 'WCUSAGE_SUSPENDED_DATE_META' ) ) {
    define( 'WCUSAGE_SUSPENDED_DATE_META', 'wcusage_affiliate_suspended_date' );
}
if ( ! defined( 'WCUSAGE_SUSPENDED_BY_META' ) ) {
    define( 'WCUSAGE_SUSPENDED_BY_META', 'wcusage_affiliate_suspended_by' );
}

/**
 * Is this affiliate user currently suspended?
 *
 * @param int $user_id
 *
 * @return bool
 */
if ( ! function_exists( 'wcusage_is_affiliate_suspended' ) ) {
    function wcusage_is_affiliate_suspended( $user_id ) {

        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return false;
        }

        $suspended = ( '1' === (string) get_user_meta( $user_id, WCUSAGE_SUSPENDED_META, true ) );

        return (bool) apply_filters( 'wcusage_is_affiliate_suspended', $suspended, $user_id );

    }
}

/**
 * Is this coupon locked because the affiliate assigned to it is suspended?
 *
 * @param int $coupon_id
 *
 * @return bool
 */
if ( ! function_exists( 'wcusage_is_coupon_suspended' ) ) {
    function wcusage_is_coupon_suspended( $coupon_id ) {

        $coupon_id = (int) $coupon_id;
        if ( ! $coupon_id ) {
            return false;
        }

        $coupon_user_id = (int) get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
        if ( ! $coupon_user_id ) {
            return false;
        }

        return wcusage_is_affiliate_suspended( $coupon_user_id );

    }
}

/**
 * Details about a suspension, for display on the admin screens.
 *
 * @param int $user_id
 *
 * @return array {
 *     @type bool   $suspended Whether the affiliate is suspended.
 *     @type string $date      MySQL date the suspension was applied ('' if unknown).
 *     @type int    $by        ID of the admin who applied it (0 if unknown).
 *     @type string $by_name   Display name of that admin ('' if unknown).
 * }
 */
if ( ! function_exists( 'wcusage_get_affiliate_suspended_info' ) ) {
    function wcusage_get_affiliate_suspended_info( $user_id ) {

        $info = array(
            'suspended' => wcusage_is_affiliate_suspended( $user_id ),
            'date'      => '',
            'by'        => 0,
            'by_name'   => '',
        );

        if ( ! $info['suspended'] ) {
            return $info;
        }

        $info['date'] = (string) get_user_meta( $user_id, WCUSAGE_SUSPENDED_DATE_META, true );
        $info['by']   = (int) get_user_meta( $user_id, WCUSAGE_SUSPENDED_BY_META, true );

        if ( $info['by'] ) {
            $admin_user = get_userdata( $info['by'] );
            if ( $admin_user ) {
                $info['by_name'] = $admin_user->display_name;
            }
        }

        return $info;

    }
}

/**
 * Suspend an affiliate and, with them, every coupon assigned to them.
 *
 * @param int $user_id
 *
 * @return bool True when the affiliate was suspended by this call.
 */
if ( ! function_exists( 'wcusage_suspend_affiliate' ) ) {
    function wcusage_suspend_affiliate( $user_id ) {

        $user_id = (int) $user_id;
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return false;
        }

        if ( wcusage_is_affiliate_suspended( $user_id ) ) {
            return false;
        }

        update_user_meta( $user_id, WCUSAGE_SUSPENDED_META, '1' );
        update_user_meta( $user_id, WCUSAGE_SUSPENDED_DATE_META, current_time( 'mysql' ) );
        update_user_meta( $user_id, WCUSAGE_SUSPENDED_BY_META, get_current_user_id() );

        if ( function_exists( 'wcusage_add_activity' ) ) {
            wcusage_add_activity( $user_id, 'affiliate_suspended', '' );
        }

        do_action( 'wcusage_hook_affiliate_suspended', $user_id );

        return true;

    }
}

/**
 * Lift the suspension on an affiliate, restoring dashboard access and their coupons.
 *
 * @param int $user_id
 *
 * @return bool True when the suspension was lifted by this call.
 */
if ( ! function_exists( 'wcusage_unsuspend_affiliate' ) ) {
    function wcusage_unsuspend_affiliate( $user_id ) {

        $user_id = (int) $user_id;
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return false;
        }

        if ( ! wcusage_is_affiliate_suspended( $user_id ) ) {
            return false;
        }

        delete_user_meta( $user_id, WCUSAGE_SUSPENDED_META );
        delete_user_meta( $user_id, WCUSAGE_SUSPENDED_DATE_META );
        delete_user_meta( $user_id, WCUSAGE_SUSPENDED_BY_META );

        if ( function_exists( 'wcusage_add_activity' ) ) {
            wcusage_add_activity( $user_id, 'affiliate_unsuspended', '' );
        }

        do_action( 'wcusage_hook_affiliate_unsuspended', $user_id );

        return true;

    }
}

/*
 * ---------------------------------------------------------------------------
 * Blocking the coupons
 * ---------------------------------------------------------------------------
 */

/**
 * The error shown when somebody tries to use a coupon belonging to a
 * suspended affiliate.
 *
 * @return string
 */
if ( ! function_exists( 'wcusage_get_suspended_coupon_error' ) ) {
    function wcusage_get_suspended_coupon_error() {
        return (string) apply_filters(
            'wcusage_hook_suspended_coupon_error',
            esc_html__( 'Sorry, this coupon is no longer available.', 'woo-coupon-usage' )
        );
    }
}

/**
 * Fail validation for any coupon assigned to a suspended affiliate.
 *
 * This is the single gate that stops a suspended affiliate's coupons being
 * used: WooCommerce runs it when a coupon is applied manually, when the cart
 * and checkout re-validate their coupons, and from WC_Coupon::is_valid(), which
 * is what the plugin's own referral-link auto-apply checks before applying a
 * coupon - so a suspended coupon is silently skipped there rather than
 * producing an error on every page a referred visitor lands on.
 */
if ( ! function_exists( 'wcusage_suspended_coupon_is_valid' ) ) {
    function wcusage_suspended_coupon_is_valid( $valid, $coupon ) {

        if ( ! $valid ) {
            return $valid;
        }

        if ( ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $valid;
        }

        if ( wcusage_is_coupon_suspended( $coupon->get_id() ) ) {
            return false;
        }

        return $valid;

    }
}
add_filter( 'woocommerce_coupon_is_valid', 'wcusage_suspended_coupon_is_valid', 10, 2 );

/**
 * Replace WooCommerce's generic "Coupon is not valid." text with a clearer
 * message when the coupon was rejected because its affiliate is suspended.
 *
 * Returning false from woocommerce_coupon_is_valid (rather than throwing) keeps
 * the filter contract intact for any other code reading it, so the message has
 * to be swapped in here instead.
 */
if ( ! function_exists( 'wcusage_suspended_coupon_error_message' ) ) {
    function wcusage_suspended_coupon_error_message( $message, $error_code, $coupon ) {

        if ( ! class_exists( 'WC_Coupon' ) || ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $message;
        }

        if ( (int) $error_code !== (int) WC_Coupon::E_WC_COUPON_INVALID_FILTERED ) {
            return $message;
        }

        if ( ! wcusage_is_coupon_suspended( $coupon->get_id() ) ) {
            return $message;
        }

        return wcusage_get_suspended_coupon_error();

    }
}
add_filter( 'woocommerce_coupon_error', 'wcusage_suspended_coupon_error_message', 10, 3 );

/*
 * ---------------------------------------------------------------------------
 * Blocking the dashboard
 * ---------------------------------------------------------------------------
 */

/**
 * Should the affiliate dashboard be hidden from whoever is viewing it?
 *
 * Admins - including an admin previewing an affiliate's own dashboard - always
 * keep access, so a suspended account can still be inspected and managed.
 *
 * @param int  $user_id          The affiliate whose dashboard is being viewed.
 * @param bool $is_admin_preview Whether this is an admin previewing that dashboard.
 *
 * @return bool
 */
if ( ! function_exists( 'wcusage_dashboard_access_suspended' ) ) {
    function wcusage_dashboard_access_suspended( $user_id, $is_admin_preview = false ) {

        if ( $is_admin_preview ) {
            return false;
        }

        // Cheapest test first. This runs for every gated shortcode on every page,
        // including for logged-out visitors, and a cached user meta read is a lot
        // less work than resolving the admin capability.
        if ( ! wcusage_is_affiliate_suspended( $user_id ) ) {
            return false;
        }

        if ( function_exists( 'wcusage_check_admin_access' ) && wcusage_check_admin_access() ) {
            return false;
        }

        return true;

    }
}

/**
 * The notice a suspended affiliate sees in place of their dashboard.
 *
 * @return string
 */
if ( ! function_exists( 'wcusage_get_suspended_dashboard_notice' ) ) {
    function wcusage_get_suspended_dashboard_notice() {

        // The notice can be the only thing a dashboard renders, so it cannot rely
        // on the icon font having been enqueued further down the page.
        if ( function_exists( 'wcusage_enqueue_font_awesome' ) ) {
            wcusage_enqueue_font_awesome();
        }

        $affiliate_text = function_exists( 'wcusage_get_affiliate_text' )
            ? wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) )
            : __( 'affiliate', 'woo-coupon-usage' );

        /* translators: %s: affiliate label. */
        $title = sprintf( esc_html__( 'Your %s account is suspended', 'woo-coupon-usage' ), esc_html( $affiliate_text ) );

        /* translators: %s: affiliate label. */
        $message = sprintf(
            esc_html__( 'Your account has been paused, so your %s dashboard and coupons are temporarily unavailable. Nothing has been deleted. Please contact us if you think this is a mistake.', 'woo-coupon-usage' ),
            esc_html( $affiliate_text )
        );

        $notice = '<div class="wcusage-suspended-notice" style="max-width: 640px; margin: 30px auto; padding: 25px 30px; border: 1px solid #e6c200; border-left: 5px solid #e6c200; border-radius: 6px; background: #fff9e0; color: #4a3c00; text-align: center;">'
            . '<p style="margin: 0 0 10px 0; font-size: 18px; font-weight: 600;"><i class="fas fa-pause-circle" style="margin-right: 8px;"></i>' . $title . '</p>'
            . '<p style="margin: 0; font-size: 14px; line-height: 1.6;">' . $message . '</p>'
            . '</div>';

        return (string) apply_filters( 'wcusage_hook_suspended_dashboard_notice', $notice );

    }
}

/**
 * Close the side doors into the dashboard.
 *
 * The dashboard, the portal and the MLA dashboard each check the suspension
 * themselves, but the individual tabs are also available as standalone
 * shortcodes ([couponaffiliates-payouts], [couponaffiliates-rates] and the
 * rest), and those would otherwise still render an affiliate's data - and, in
 * the payouts tab's case, let them request a payout.
 *
 * The notice is printed once per page; any further gated shortcode after it
 * renders nothing, so a page built from several tabs does not repeat itself.
 */
if ( ! function_exists( 'wcusage_suspended_shortcode_output' ) ) {
    function wcusage_suspended_shortcode_output( $output, $tag ) {

        static $notice_shown = false;
        static $gated = null;

        // This runs for every shortcode on every page, so the list is built once.
        if ( null === $gated ) {
            // The registration form and the leaderboard are left alone: neither
            // shows an affiliate their own data, and the leaderboard is public
            // content that may sit on an ordinary marketing page.
            $gated = function_exists( 'wcusage_frontend_shortcodes' )
                ? array_diff(
                    wcusage_frontend_shortcodes(),
                    apply_filters( 'wcusage_hook_suspended_shortcodes_allowed', array( 'couponaffiliates-register', 'couponaffiliates-leaderboard' ) )
                )
                : array();
        }

        if ( ! in_array( $tag, $gated, true ) ) {
            return $output;
        }

        if ( ! wcusage_dashboard_access_suspended( get_current_user_id() ) ) {
            return $output;
        }

        if ( $notice_shown ) {
            return '';
        }

        $notice_shown = true;

        return wcusage_get_suspended_dashboard_notice();

    }
}
add_filter( 'do_shortcode_tag', 'wcusage_suspended_shortcode_output', 10, 2 );

/*
 * ---------------------------------------------------------------------------
 * Admin actions
 * ---------------------------------------------------------------------------
 */

/**
 * Handle the Suspend / Unsuspend buttons on the affiliates list and the
 * View Affiliate page.
 *
 * These post the same fields from both screens (see js/admin.js), so the
 * handler runs on admin_init and sends the admin back to wherever they were.
 */
if ( ! function_exists( 'wcusage_handle_affiliate_suspend_action' ) ) {
    function wcusage_handle_affiliate_suspend_action() {

        if ( empty( $_POST['wcusage_suspend_action'] ) || empty( $_POST['wcusage_suspend_user_id'] ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['wcusage_suspend_action'] ) );
        if ( ! in_array( $action, array( 'suspend_user', 'unsuspend_user' ), true ) ) {
            return;
        }

        $user_id = absint( $_POST['wcusage_suspend_user_id'] );
        $nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'wcusage_suspend_user_' . $user_id ) ) {
            wp_die( esc_html__( 'Security check failed', 'woo-coupon-usage' ) );
        }

        if ( ! wcusage_check_admin_access() ) {
            wp_die( esc_html__( 'Insufficient permissions', 'woo-coupon-usage' ) );
        }

        if ( $user_id === get_current_user_id() ) {
            wp_die( esc_html__( 'You cannot suspend your own account.', 'woo-coupon-usage' ) );
        }

        $user_info = get_userdata( $user_id );
        if ( ! $user_info ) {
            wp_die( esc_html__( 'That user no longer exists.', 'woo-coupon-usage' ) );
        }

        if ( 'suspend_user' === $action ) {
            wcusage_suspend_affiliate( $user_id );
            /* translators: %s: username. */
            $message = sprintf( __( '%s has been suspended. Their dashboard access is paused and their coupons can no longer be used.', 'woo-coupon-usage' ), $user_info->user_login );
        } else {
            wcusage_unsuspend_affiliate( $user_id );
            /* translators: %s: username. */
            $message = sprintf( __( '%s is no longer suspended. Their dashboard access and coupons have been restored.', 'woo-coupon-usage' ), $user_info->user_login );
        }

        // The buttons post back to the screen they are on, so the referer is the
        // page to return to. wp_get_referer() deliberately returns false when the
        // referer matches the current request URI, which is exactly this case -
        // hence the raw referer, validated to this site before it is used.
        $redirect = wp_get_raw_referer();
        $redirect = $redirect ? wp_validate_redirect( $redirect, '' ) : '';
        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . $user_id );
        }
        $redirect = remove_query_arg( 'wcusage_message', $redirect );
        $redirect = add_query_arg( 'wcusage_message', rawurlencode( $message ), $redirect );

        wp_safe_redirect( $redirect );
        exit;

    }
}
add_action( 'admin_init', 'wcusage_handle_affiliate_suspend_action' );

/*
 * ---------------------------------------------------------------------------
 * Admin markup helpers
 * ---------------------------------------------------------------------------
 */

/**
 * The "Suspended" badge shown beside an affiliate on the admin screens.
 *
 * @param int $user_id
 *
 * @return string Empty string when the affiliate is not suspended.
 */
if ( ! function_exists( 'wcusage_get_suspended_badge_html' ) ) {
    function wcusage_get_suspended_badge_html( $user_id ) {

        if ( ! wcusage_is_affiliate_suspended( $user_id ) ) {
            return '';
        }

        $info  = wcusage_get_affiliate_suspended_info( $user_id );
        $title = esc_html__( 'This affiliate is suspended. Their dashboard access is paused and their coupons cannot be used.', 'woo-coupon-usage' );

        if ( $info['date'] ) {
            /* translators: %s: date the affiliate was suspended. */
            $title .= ' ' . sprintf(
                esc_html__( 'Suspended on %s.', 'woo-coupon-usage' ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $info['date'] ) ) )
            );
        }

        if ( $info['by_name'] ) {
            /* translators: %s: name of the admin who suspended the affiliate. */
            $title .= ' ' . sprintf( esc_html__( 'By %s.', 'woo-coupon-usage' ), esc_html( $info['by_name'] ) );
        }

        return '<span class="wcusage-suspended-badge" title="' . esc_attr( $title ) . '">'
            . '<span class="dashicons dashicons-lock"></span>'
            . esc_html__( 'Suspended', 'woo-coupon-usage' )
            . '</span>';

    }
}

/**
 * The lock shown beside a coupon whose affiliate is suspended.
 *
 * @param int  $coupon_id
 * @param bool $with_label Show the "Suspended" wording as well as the padlock.
 *
 * @return string Empty string when the coupon is not locked.
 */
if ( ! function_exists( 'wcusage_get_suspended_coupon_lock_html' ) ) {
    function wcusage_get_suspended_coupon_lock_html( $coupon_id, $with_label = true ) {

        if ( ! wcusage_is_coupon_suspended( $coupon_id ) ) {
            return '';
        }

        $title = esc_html__( 'This coupon is locked because the affiliate it is assigned to is suspended. It cannot be used until the suspension is lifted.', 'woo-coupon-usage' );

        return '<span class="wcusage-suspended-badge wcusage-suspended-coupon" title="' . esc_attr( $title ) . '">'
            . '<span class="dashicons dashicons-lock"></span>'
            . ( $with_label ? esc_html__( 'Locked', 'woo-coupon-usage' ) : '' )
            . '</span>';

    }
}

/**
 * Show the lock beside the coupon code on the WooCommerce Coupons list screen.
 */
if ( ! function_exists( 'wcusage_suspended_coupon_post_state' ) ) {
    function wcusage_suspended_coupon_post_state( $post_states, $post ) {

        if ( ! $post || 'shop_coupon' !== $post->post_type ) {
            return $post_states;
        }

        if ( wcusage_is_coupon_suspended( $post->ID ) ) {
            $post_states['wcusage_suspended'] = wcusage_get_suspended_coupon_lock_html( $post->ID );
        }

        return $post_states;

    }
}
add_filter( 'display_post_states', 'wcusage_suspended_coupon_post_state', 10, 2 );

/**
 * Styles for the suspended badges / locks, on the plugin's own admin pages and
 * on the WooCommerce Coupons list screen.
 */
if ( ! function_exists( 'wcusage_enqueue_suspended_admin_css' ) ) {
    function wcusage_enqueue_suspended_admin_css() {

        $load = false;

        if ( isset( $_GET['page'] ) && 0 === strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'wcusage' ) ) {
            $load = true;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'shop_coupon' === $screen->post_type ) {
            $load = true;
        }

        if ( ! $load ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );

        $css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-suspended.css';
        $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : WCUSAGE_VERSION;
        wp_enqueue_style( 'wcusage-admin-suspended', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-suspended.css', array(), $css_ver );

    }
}
add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_suspended_admin_css' );
