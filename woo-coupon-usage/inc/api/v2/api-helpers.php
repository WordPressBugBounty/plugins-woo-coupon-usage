<?php

/**
 * Coupon Affiliates REST API v2 - Shared helpers.
 *
 * Permission checks, error helpers and small data utilities shared by all
 * v2 controllers. Everything here must work on both the free and PRO builds,
 * so premium-only functions are always guarded with function_exists().
 *
 * @package WooCouponUsage\API
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'wcusage_api_get_settings' ) ) {
    /**
     * All API settings, held in a single option.
     *
     * "disabled_endpoints" stores the exceptions rather than the whole list,
     * so every endpoint - including any added in a later release - is on by
     * default once the API itself is enabled.
     *
     * @return array
     */
    function wcusage_api_get_settings() {
        $defaults = array(
            'enabled'            => '0',
            'disabled_endpoints' => array(),
        );
        $settings = get_option( 'wcusage_api_settings', array() );
        if ( !is_array( $settings ) ) {
            $settings = array();
        }
        $settings = array_merge( $defaults, $settings );
        $settings['enabled'] = ( '1' === (string) $settings['enabled'] ? '1' : '0' );
        if ( !is_array( $settings['disabled_endpoints'] ) ) {
            $settings['disabled_endpoints'] = array();
        }
        $settings['disabled_endpoints'] = array_values( array_unique( array_map( 'strval', $settings['disabled_endpoints'] ) ) );
        return $settings;
    }

}
if ( !function_exists( 'wcusage_api_update_settings' ) ) {
    /**
     * Merge changes into the API settings option.
     *
     * @param array $changes Settings to change.
     *
     * @return array The saved settings.
     */
    function wcusage_api_update_settings(  $changes  ) {
        $settings = array_merge( wcusage_api_get_settings(), (array) $changes );
        $settings['enabled'] = ( '1' === (string) $settings['enabled'] ? '1' : '0' );
        $settings['disabled_endpoints'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) $settings['disabled_endpoints'] ) ) );
        update_option( 'wcusage_api_settings', $settings );
        return $settings;
    }

}
if ( !function_exists( 'wcusage_api_v2_is_enabled' ) ) {
    /**
     * Whether the v2 REST API is switched on for this site.
     *
     * Off until an administrator turns it on under "Coupon Affiliates >
     * Admin Tools > API", so a site never starts answering API requests just
     * because the plugin was updated. This controls the v2 routes and API key
     * authentication; the older woo-coupon-usage/v1 endpoints are unaffected
     * so existing integrations keep working.
     *
     * @return bool
     */
    function wcusage_api_v2_is_enabled() {
        $settings = wcusage_api_get_settings();
        return '1' === $settings['enabled'];
    }

}
if ( !function_exists( 'wcusage_api_normalise_route' ) ) {
    /**
     * Turn a registered route into the readable path used by the settings.
     *
     * "/wcusage/v2/coupons/(?P<id>[\d]+)/stats" becomes "/coupons/{id}/stats".
     *
     * @param string $route Registered route.
     *
     * @return string Path, or an empty string when it is not a v2 route.
     */
    function wcusage_api_normalise_route(  $route  ) {
        if ( 0 !== strpos( $route, '/wcusage/v2' ) ) {
            return '';
        }
        $path = preg_replace( '/\\(\\?P<([a-zA-Z0-9_]+)>[^)]+\\)/', '{$1}', $route );
        $path = substr( $path, strlen( '/wcusage/v2' ) );
        return ( '' === $path ? '/' : $path );
    }

}
if ( !function_exists( 'wcusage_api_endpoint_is_enabled' ) ) {
    /**
     * Whether a single endpoint is switched on.
     *
     * @param string $path Normalised path, e.g. "/coupons/{id}/stats".
     *
     * @return bool
     */
    function wcusage_api_endpoint_is_enabled(  $path  ) {
        // The namespace index is not something to switch off.
        if ( '' === $path || '/' === $path ) {
            return true;
        }
        $settings = wcusage_api_get_settings();
        return !in_array( $path, $settings['disabled_endpoints'], true );
    }

}
if ( !function_exists( 'wcusage_api_filter_disabled_endpoints' ) ) {
    /**
     * Remove switched-off endpoints from the REST route table.
     *
     * Done through the rest_endpoints filter rather than by skipping
     * registration, so the decision is made per request and a disabled
     * endpoint simply does not exist (404) rather than failing later.
     *
     * @param array $endpoints Registered endpoints.
     *
     * @return array
     */
    function wcusage_api_filter_disabled_endpoints(  $endpoints  ) {
        $settings = wcusage_api_get_settings();
        // Nothing switched off: leave the route table untouched.
        if ( empty( $settings['disabled_endpoints'] ) ) {
            return $endpoints;
        }
        foreach ( $endpoints as $route => $handlers ) {
            $path = wcusage_api_normalise_route( $route );
            if ( '' === $path || '/' === $path ) {
                continue;
            }
            if ( !wcusage_api_endpoint_is_enabled( $path ) ) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    add_filter( 'rest_endpoints', 'wcusage_api_filter_disabled_endpoints' );
}
if ( !function_exists( 'wcusage_api_webhooks_available' ) ) {
    /**
     * Whether the webhook add-on is present on this build.
     *
     * Webhooks are PRO: the delivery layer ships as its own __premium_only
     * file, so in the free build none of its functions are defined. This is a
     * runtime test rather than a build-flag test so that the same source works
     * either way - the admin screen and the REST controller both carry webhook
     * code that simply never runs without it.
     *
     * (Deliberately does not name the premium build flag: the deployment strip
     * is a plain text match, so writing that token in a comment makes it delete
     * the nearest preceding if-block.)
     *
     * @return bool
     */
    function wcusage_api_webhooks_available() {
        return function_exists( 'wcusage_api_get_webhooks' );
    }

}
if ( !function_exists( 'wcusage_api_environment_type' ) ) {
    /**
     * The site's environment type, on any supported WordPress version.
     *
     * The wp_get_environment_type() function arrived in WordPress 5.5 and is
     * the only one this API uses that the plugin's stated minimum does not
     * provide. It decides whether bearer tokens and webhook URLs have to use
     * HTTPS, so an older site must not simply fatal there - and the fallback
     * has to be the strict answer, "production", rather than the permissive
     * one.
     *
     * @return string
     */
    function wcusage_api_environment_type() {
        if ( function_exists( 'wp_get_environment_type' ) ) {
            return wp_get_environment_type();
        }
        return 'production';
    }

}
if ( !function_exists( 'wcusage_api_requires_https' ) ) {
    /**
     * Whether credentials and delivery URLs must use TLS on this site.
     *
     * @return bool
     */
    function wcusage_api_requires_https() {
        return !in_array( wcusage_api_environment_type(), array('local', 'development'), true );
    }

}
if ( !function_exists( 'wcusage_api_error' ) ) {
    /**
     * Build a WP_Error in the shape the REST server expects.
     *
     * Extra fields are merged into the same data array rather than added
     * afterwards with WP_Error::add_data(): a second add_data() call for the
     * same code REPLACES the first, so "status" would drop out of the
     * response body's "data" object and reappear under "additional_data".
     * The HTTP status itself survives that, but clients reading data.status
     * - the documented WordPress REST error shape - would not.
     *
     * @param string $code    Error code (without prefix).
     * @param string $message Human readable message.
     * @param int    $status  HTTP status code.
     * @param array  $data    Extra fields to include alongside the status.
     *
     * @return WP_Error
     */
    function wcusage_api_error(
        $code,
        $message,
        $status,
        $data = array()
    ) {
        return new WP_Error('wcusage_api_' . $code, $message, array_merge( (array) $data, array(
            'status' => (int) $status,
        ) ));
    }

}
if ( !function_exists( 'wcusage_api_auth_required_error' ) ) {
    /**
     * 401 for guests, 403 for authenticated users without access.
     *
     * @return WP_Error
     */
    function wcusage_api_auth_required_error() {
        if ( !is_user_logged_in() ) {
            return wcusage_api_error( 'unauthorized', __( 'Authentication required. Use an application password or an API key.', 'woo-coupon-usage' ), 401 );
        }
        return wcusage_api_error( 'forbidden', __( 'You do not have permission to access this resource.', 'woo-coupon-usage' ), 403 );
    }

}
if ( !function_exists( 'wcusage_api_is_admin_user' ) ) {
    /**
     * Whether the current request is authenticated as a plugin admin.
     *
     * Uses the same capability gate as the rest of the plugin.
     *
     * @return bool
     */
    function wcusage_api_is_admin_user() {
        return is_user_logged_in() && function_exists( 'wcusage_check_admin_access' ) && wcusage_check_admin_access();
    }

}
if ( !function_exists( 'wcusage_api_user_can_access_coupon' ) ) {
    /**
     * Whether the current user may read a coupon's affiliate data.
     *
     * Admin, coupon owner, or MLA upline of the owner. Unlike the AJAX
     * dashboard there is no anonymous "public coupon" path here: the REST
     * API always requires an authenticated user.
     *
     * @param int $coupon_id Coupon post ID.
     *
     * @return bool
     */
    function wcusage_api_user_can_access_coupon(  $coupon_id  ) {
        $coupon_id = absint( $coupon_id );
        if ( !$coupon_id || !is_user_logged_in() ) {
            return false;
        }
        if ( wcusage_api_is_admin_user() ) {
            return true;
        }
        $coupon_user_id = absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) );
        $current_user_id = get_current_user_id();
        if ( $coupon_user_id && $coupon_user_id === $current_user_id ) {
            return true;
        }
        return false;
    }

}
if ( !function_exists( 'wcusage_api_get_valid_coupon_id' ) ) {
    /**
     * Validate a coupon ID: must be an existing, non-trashed shop_coupon.
     *
     * @param int $coupon_id Coupon post ID.
     *
     * @return int Valid coupon ID or 0.
     */
    function wcusage_api_get_valid_coupon_id(  $coupon_id  ) {
        $coupon_id = absint( $coupon_id );
        if ( !$coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
            return 0;
        }
        // Draft and private coupons stay addressable - a coupon does not have
        // to be published to have an affiliate and a balance. Only states that
        // represent a coupon which does not really exist are refused:
        // "auto-draft" is the empty row the editor creates before a coupon has
        // been written at all.
        $status = get_post_status( $coupon_id );
        if ( false === $status || in_array( $status, array('trash', 'auto-draft'), true ) ) {
            return 0;
        }
        return $coupon_id;
    }

}
if ( !function_exists( 'wcusage_api_table_exists' ) ) {
    /**
     * Check a plugin table exists (cached per request).
     *
     * @param string $table Full table name including prefix.
     *
     * @return bool
     */
    function wcusage_api_table_exists(  $table  ) {
        static $cache = array();
        if ( isset( $cache[$table] ) ) {
            return $cache[$table];
        }
        global $wpdb;
        // "_" is a single-character wildcard in LIKE, so an unescaped prefix
        // can match a different site's table on a shared database and make
        // this report the wrong answer.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cache[$table] = $found === $table;
        return $cache[$table];
    }

}
if ( !function_exists( 'wcusage_api_format_date' ) ) {
    /**
     * Format a MySQL datetime (site timezone) as RFC3339, or null when empty.
     *
     * @param string $mysql_date MySQL datetime string.
     *
     * @return string|null
     */
    function wcusage_api_format_date(  $mysql_date  ) {
        if ( empty( $mysql_date ) || '0000-00-00 00:00:00' === $mysql_date || '0000-00-00' === $mysql_date ) {
            return null;
        }
        return mysql_to_rfc3339( $mysql_date );
    }

}
if ( !function_exists( 'wcusage_api_validate_date_arg' ) ) {
    /**
     * REST validate_callback for Y-m-d date params.
     *
     * @param string $value Raw value.
     *
     * @return bool
     */
    function wcusage_api_validate_date_arg(  $value  ) {
        if ( '' === $value || null === $value ) {
            return true;
        }
        if ( !is_string( $value ) || !preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
            return false;
        }
        $parts = explode( '-', $value );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

}
if ( !function_exists( 'wcusage_api_end_date' ) ) {
    /**
     * Default an empty end date to today (site timezone).
     *
     * The wcusage_wh_getOrderbyCouponCode() query builds a BETWEEN clause
     * from its dates and an empty end date matches nothing, so every
     * ranged call must send a real one.
     *
     * @param string $to Y-m-d date or empty.
     *
     * @return string
     */
    function wcusage_api_end_date(  $to  ) {
        return ( $to ? $to : current_time( 'Y-m-d' ) );
    }

}
if ( !function_exists( 'wcusage_api_acquire_lock' ) ) {
    /**
     * Take a short-lived mutex around a state-changing operation.
     *
     * Uses wp_cache_add()/add_option(), both of which are atomic "create
     * only if absent" operations - unlike a get-then-set, which two
     * simultaneous requests can both pass. Falls back to the options table
     * when no persistent object cache is configured, since the default
     * object cache is per-request and would never see a competing request.
     *
     * @param string $key     Lock name.
     * @param int    $timeout Seconds before the lock self-expires.
     *
     * @return bool True when the lock was acquired.
     */
    function wcusage_api_acquire_lock(  $key, $timeout = 30  ) {
        $key = 'wcusage_lock_' . md5( (string) $key );
        if ( wp_using_ext_object_cache() ) {
            return (bool) wp_cache_add(
                $key,
                1,
                'wcusage_api',
                $timeout
            );
        }
        global $wpdb;
        // add_option() is backed by a unique index on option_name, so only
        // one caller can win the insert.
        $expires = time() + max( 1, (int) $timeout );
        $added = $wpdb->query( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $key, (string) $expires )
         );
        if ( 1 === (int) $added ) {
            return true;
        }
        // A stale lock from a request that died mid-flight must not block
        // the endpoint forever.
        $held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( null !== $held && (int) $held < time() ) {
            $reclaimed = $wpdb->query( 
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    (string) $expires,
                    $key,
                    (string) $held
                )
             );
            return 1 === (int) $reclaimed;
        }
        return false;
    }

}
if ( !function_exists( 'wcusage_api_release_lock' ) ) {
    /**
     * Release a lock taken with wcusage_api_acquire_lock().
     *
     * @param string $key Lock name.
     */
    function wcusage_api_release_lock(  $key  ) {
        $key = 'wcusage_lock_' . md5( (string) $key );
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $key, 'wcusage_api' );
            return;
        }
        global $wpdb;
        $wpdb->delete( $wpdb->options, array(
            'option_name' => $key,
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

}
if ( !function_exists( 'wcusage_api_throttle' ) ) {
    /**
     * Rate-limit one named piece of expensive work.
     *
     * Unlike wcusage_api_acquire_lock(), this is not a mutex and is never
     * released: it answers "has this run recently?", so a caller that arrives
     * inside the window is told to use whatever it already has rather than
     * being made to wait. Used to stop endpoints that recalculate from source
     * data being a cheap way to make the database do a lot of work.
     *
     * @param string $key     Name of the work being throttled.
     * @param int    $seconds Minimum seconds between runs.
     *
     * @return bool True when the caller may run the work now.
     */
    function wcusage_api_throttle(  $key, $seconds  ) {
        $key = 'wcusage_api_tt_' . md5( (string) $key );
        if ( get_transient( $key ) ) {
            return false;
        }
        set_transient( $key, 1, max( 1, (int) $seconds ) );
        return true;
    }

}
if ( !function_exists( 'wcusage_api_mask' ) ) {
    /**
     * Mask a sensitive string, keeping the last 4 characters.
     *
     * @param string $value Value to mask.
     *
     * @return string
     */
    function wcusage_api_mask(  $value  ) {
        $value = (string) $value;
        $length = strlen( $value );
        if ( $length <= 4 ) {
            return str_repeat( '*', $length );
        }
        return str_repeat( '*', min( $length - 4, 12 ) ) . substr( $value, -4 );
    }

}
if ( !function_exists( 'wcusage_api_get_coupon_balances' ) ) {
    /**
     * Current commission balances for a coupon.
     *
     * @param int $coupon_id Coupon post ID.
     *
     * @return array
     */
    function wcusage_api_get_coupon_balances(  $coupon_id  ) {
        return array(
            'unpaid_commission'         => (float) get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true ),
            'pending_order_commission'  => (float) get_post_meta( $coupon_id, 'wcu_text_pending_order_commission', true ),
            'pending_payout_commission' => (float) get_post_meta( $coupon_id, 'wcu_text_pending_payment_commission', true ),
        );
    }

}
if ( !function_exists( 'wcusage_api_get_alltime_stats' ) ) {
    /**
     * Cached all-time stats for a coupon, in a stable shape.
     *
     * Reads the wcu_alltime_stats post meta cache. Returns zeros when the
     * cache has not been built yet (the stats endpoints can rebuild it).
     *
     * @param int $coupon_id Coupon post ID.
     *
     * @return array
     */
    function wcusage_api_get_alltime_stats(  $coupon_id  ) {
        $stats = get_post_meta( $coupon_id, 'wcu_alltime_stats', true );
        if ( !is_array( $stats ) ) {
            $stats = array();
        }
        $last_refreshed = (int) get_post_meta( $coupon_id, 'wcu_last_refreshed', true );
        return array(
            'orders_count'     => ( isset( $stats['total_count'] ) ? (int) $stats['total_count'] : 0 ),
            'total_sales'      => ( isset( $stats['total_orders'] ) ? (float) $stats['total_orders'] : 0.0 ),
            'total_discount'   => ( isset( $stats['full_discount'] ) ? (float) $stats['full_discount'] : 0.0 ),
            'total_shipping'   => ( isset( $stats['total_shipping'] ) ? (float) $stats['total_shipping'] : 0.0 ),
            'total_commission' => ( isset( $stats['total_commission'] ) ? (float) $stats['total_commission'] : 0.0 ),
            'last_refreshed'   => ( $last_refreshed ? wcusage_api_format_date( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $last_refreshed ) ) ) : null ),
        );
    }

}
if ( !function_exists( 'wcusage_api_prepare_coupon_summary' ) ) {
    /**
     * Compact representation of an affiliate coupon.
     *
     * @param int  $coupon_id  Coupon post ID.
     * @param bool $with_stats Include the cached all-time stats block.
     *
     * @return array|null Null when the coupon does not exist.
     */
    function wcusage_api_prepare_coupon_summary(  $coupon_id, $with_stats = false  ) {
        $coupon_id = wcusage_api_get_valid_coupon_id( $coupon_id );
        if ( !$coupon_id ) {
            return null;
        }
        $data = array(
            'id'           => $coupon_id,
            'code'         => sanitize_text_field( get_post_field( 'post_title', $coupon_id, 'raw' ) ),
            'user_id'      => absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) ),
            'date_created' => wcusage_api_format_date( get_post_field( 'post_date', $coupon_id, 'raw' ) ),
        );
        $data = array_merge( $data, wcusage_api_get_coupon_balances( $coupon_id ) );
        if ( $with_stats ) {
            $data['stats'] = wcusage_api_get_alltime_stats( $coupon_id );
        }
        /**
         * Filter the coupon summary returned by the REST API.
         *
         * @param array $data      Prepared data.
         * @param int   $coupon_id Coupon post ID.
         */
        return apply_filters( 'wcusage_api_coupon_summary', $data, $coupon_id );
    }

}
if ( !function_exists( 'wcusage_api_prepare_user_summary' ) ) {
    /**
     * Compact representation of a WP user, without PII for non-admins.
     *
     * @param int $user_id User ID.
     *
     * @return array|null
     */
    function wcusage_api_prepare_user_summary(  $user_id  ) {
        $user_id = absint( $user_id );
        if ( !$user_id ) {
            return null;
        }
        $user = get_userdata( $user_id );
        if ( !$user ) {
            return null;
        }
        $data = array(
            'id'           => $user_id,
            'display_name' => $user->display_name,
        );
        // Login and email only for plugin admins or the user themselves.
        // Logins are commonly the person's email address, so exposing them
        // to (for example) an MLA upline viewing a downline coupon would
        // leak the same data the email gate is there to protect.
        if ( wcusage_api_is_admin_user() || get_current_user_id() === $user_id ) {
            $data['login'] = $user->user_login;
            $data['email'] = $user->user_email;
        }
        return $data;
    }

}