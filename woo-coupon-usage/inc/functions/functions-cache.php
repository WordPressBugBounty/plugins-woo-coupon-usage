<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 *  Coupon Affiliates — cache layer
 * ----------------------------------------------------------------------------
 *  Central home for transient cache invalidation.
 *
 *  IMPORTANT: transient invalidation must be object-cache (Redis) safe. Under a
 *  persistent object cache, WordPress stores transients in the object cache, NOT
 *  the wp_options table, so raw SQL such as
 *      DELETE FROM wp_options WHERE option_name LIKE '_transient_...'
 *  clears nothing and stale data survives until the TTL expires or the cache is
 *  flushed by hand. Always invalidate through the version-salt helpers below.
 *
 *  The feature code that actually reads/writes these caches (dashboard widgets,
 *  the affiliate users list, per-user coupon lookups, the leaderboard, etc.)
 *  lives with each feature and builds its keys with wcusage_cache_key().
 *
 *  Namespaces:
 *    - 'user'              per-affiliate caches (column, is_affiliate, coupon
 *                          ids/names, is_coupon_users access checks)
 *    - 'coupon_users_list' the admin Affiliate Users list table
 *    - 'dashboard'         admin dashboard widgets + leaderboard
 * ============================================================================
 */

/* -------------------------------------------------------------------------
 *  Core version-salt helpers
 * ---------------------------------------------------------------------- */

/**
 * Get the current cache version for a namespace.
 *
 * We fold an incrementing version number into every transient key within a
 * namespace and bump that number to invalidate the whole namespace at once.
 * Orphaned entries simply expire on their existing TTL (all caches in these
 * namespaces are set with a TTL, so WP's daily expired-transient cleanup
 * removes the leftovers on sites without an object cache).
 *
 * The version options are deliberately NOT autoloaded: they change on every
 * invalidation, and frequently-changing autoloaded options are a known source
 * of alloptions cache churn/races under persistent object caches.
 *
 * @param string $namespace Cache namespace.
 * @return int
 */
if ( ! function_exists( 'wcusage_cache_ns_version' ) ) {
    function wcusage_cache_ns_version( $namespace ) {
        $option  = 'wcusage_cache_ver_' . $namespace;
        $version = get_option( $option );
        if ( false === $version ) {
            $version = 1;
            update_option( $option, $version, false ); // non-autoloaded
        }
        return (int) $version;
    }
}

/**
 * Build a versioned transient key within a namespace.
 *
 * Usage:
 *   $key = wcusage_cache_key( 'dashboard', 'wcusage_dashboard_program_stats' );
 *   set_transient( $key, $data, HOUR_IN_SECONDS );
 *
 * @param string $namespace Cache namespace.
 * @param string $key       Base transient key.
 * @return string
 */
if ( ! function_exists( 'wcusage_cache_key' ) ) {
    function wcusage_cache_key( $namespace, $key ) {
        return $key . '_cv' . wcusage_cache_ns_version( $namespace );
    }
}

/**
 * Invalidate every cached transient in a namespace (O(1) version bump).
 *
 * @param string $namespace Cache namespace.
 * @return void
 */
if ( ! function_exists( 'wcusage_cache_flush_ns' ) ) {
    function wcusage_cache_flush_ns( $namespace ) {
        $option = 'wcusage_cache_ver_' . $namespace;
        update_option( $option, wcusage_cache_ns_version( $namespace ) + 1, false );
    }
}

/**
 * Clear the cached transients for a single affiliate user (Redis-safe).
 *
 * Note: this does NOT clear the per-coupon 'wcusage_is_coupon_users_*' access
 * checks (their keys hash coupon + user together, so they cannot be enumerated
 * per user). Those are namespaced under 'user' and are invalidated by any
 * wcusage_cache_flush_ns( 'user' ) — which every coupon assignment change
 * triggers (see wcusage_cache_on_coupon_user_meta_change).
 *
 * @param int $user_id User ID.
 * @return void
 */
if ( ! function_exists( 'wcusage_clear_user_cache' ) ) {
    function wcusage_clear_user_cache( $user_id ) {
        if ( ! $user_id ) {
            return;
        }
        delete_transient( wcusage_cache_key( 'user', 'wcusage_user_affiliate_col_' . $user_id ) );
        delete_transient( wcusage_cache_key( 'user', 'wcusage_is_affiliate_' . $user_id ) );
        delete_transient( wcusage_cache_key( 'user', 'wcusage_user_coupon_ids_' . $user_id ) );
        delete_transient( wcusage_cache_key( 'user', 'wcusage_user_coupon_names_' . $user_id ) );
    }
}

/* -------------------------------------------------------------------------
 *  Affiliate users list + per-user invalidation
 * ---------------------------------------------------------------------- */

/**
 * Invalidate the affiliate users list cache, and per-user caches.
 *
 * A version bump is used instead of raw SQL against wp_options so this also
 * works with a persistent object cache (e.g. Redis), where transients are not
 * stored in wp_options and a DELETE query would clear nothing.
 *
 * @param int|null $user_id Optional. Clear only this user's per-user caches;
 *                          when omitted, every user's caches are invalidated.
 * @return void
 */
if ( ! function_exists( 'wcusage_clear_coupon_users_cache' ) ) {
    function wcusage_clear_coupon_users_cache( $user_id = null ) {
        wcusage_cache_flush_ns( 'coupon_users_list' );

        if ( $user_id ) {
            // Clear just this affiliate's per-user caches.
            wcusage_clear_user_cache( $user_id );
        } else {
            // No specific user: invalidate every affiliate's per-user caches at once.
            wcusage_cache_flush_ns( 'user' );
        }
    }
}

/**
 * Invalidate caches when a coupon's assigned user changes (added, updated or
 * deleted 'wcu_select_coupon_user' meta).
 *
 * Deliberately flushes the whole 'user' namespace rather than clearing the new
 * user's keys only: a targeted clear cannot reach the previous owner (the old
 * value is already overwritten when 'updated_post_meta' fires) nor the
 * per-coupon 'wcusage_is_coupon_users_*' access checks (keys hash coupon+user).
 * Assignment changes are rare admin events, and the flush is a single option
 * UPDATE, so correctness is worth the extra cache misses.
 *
 * Fires for all three meta hooks; the first parameter is an int for
 * added/updated and an array of ids for deleted, but it is unused here.
 *
 * @param int|array $meta_ids   Meta ID(s).
 * @param int       $post_id    Post ID.
 * @param string    $meta_key   Meta key.
 * @param mixed     $meta_value Meta value.
 * @return void
 */
function wcusage_cache_on_coupon_user_meta_change( $meta_ids, $post_id, $meta_key, $meta_value ) {
    if ( 'wcu_select_coupon_user' === $meta_key ) {
        wcusage_clear_coupon_users_cache();
        wcusage_clear_dashboard_caches();
    }
}
add_action( 'added_post_meta', 'wcusage_cache_on_coupon_user_meta_change', 10, 4 );
add_action( 'updated_post_meta', 'wcusage_cache_on_coupon_user_meta_change', 10, 4 );
add_action( 'deleted_post_meta', 'wcusage_cache_on_coupon_user_meta_change', 10, 4 );

/**
 * Clear the affiliate users list cache when a shop_coupon post is saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 * @return void
 */
function wcusage_cache_on_coupon_save( $post_id, $post, $update ) {
    // Skip autosaves and revisions.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Only flush the users list when the coupon has an assigned user.
    $assigned_user = get_post_meta( $post_id, 'wcu_select_coupon_user', true );
    if ( $assigned_user ) {
        wcusage_clear_coupon_users_cache();
    }
}
add_action( 'save_post_shop_coupon', 'wcusage_cache_on_coupon_save', 20, 3 );

/**
 * Clear the affiliate users list cache after the WooCommerce coupon options
 * save action completes.
 *
 * @param int $post_id Coupon post ID.
 * @return void
 */
function wcusage_cache_on_wc_coupon_options_save( $post_id ) {
    wcusage_clear_coupon_users_cache();
}
add_action( 'woocommerce_coupon_options_save', 'wcusage_cache_on_wc_coupon_options_save', 20 );

/* -------------------------------------------------------------------------
 *  Per-user affiliate column cache (users.php list table)
 * ---------------------------------------------------------------------- */

/**
 * Clear a user's per-user caches when a coupon they are (or were) assigned to
 * is saved or deleted. Also covers coupon renames (cached coupon name lists).
 *
 * @param int $post_id Post ID.
 * @return void
 */
function wcusage_clear_user_affiliate_column_cache( $post_id ) {
    // Only for shop_coupon post type.
    if ( 'shop_coupon' !== get_post_type( $post_id ) ) {
        return;
    }

    // Get the OLD user ID (before save) from global variable if available.
    global $wcusage_old_coupon_user_id;

    // Get the NEW/current assigned user ID.
    $new_user_id = get_post_meta( $post_id, 'wcu_select_coupon_user', true );

    // Clear cache for new user.
    if ( $new_user_id ) {
        wcusage_clear_user_cache( $new_user_id );
    }

    // Clear cache for the old user (if there was one and it's different).
    if ( ! empty( $wcusage_old_coupon_user_id ) && $wcusage_old_coupon_user_id != $new_user_id ) {
        wcusage_clear_user_cache( $wcusage_old_coupon_user_id );
    }
}
add_action( 'save_post', 'wcusage_clear_user_affiliate_column_cache' );
add_action( 'delete_post', 'wcusage_clear_user_affiliate_column_cache' );

/**
 * Store the old coupon user ID before saving (so the old owner's cache can be
 * cleared once the new value is written).
 *
 * @param int $post_id Post ID.
 * @return void
 */
function wcusage_store_old_coupon_user_id( $post_id ) {
    // Only for shop_coupon post type.
    if ( 'shop_coupon' !== get_post_type( $post_id ) ) {
        return;
    }

    // Store the old user ID in a global variable before the save happens.
    global $wcusage_old_coupon_user_id;
    $wcusage_old_coupon_user_id = get_post_meta( $post_id, 'wcu_select_coupon_user', true );
}
add_action( 'pre_post_update', 'wcusage_store_old_coupon_user_id' );

/**
 * Clear user affiliate column cache when commission-related user meta is updated.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $user_id    User ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * @return void
 */
function wcusage_clear_user_affiliate_column_cache_on_meta_update( $meta_id, $user_id, $meta_key, $meta_value ) {
    if ( in_array( $meta_key, array( 'wcu_text_unpaid_commission', 'wcu_ml_unpaid_commission' ), true ) ) {
        delete_transient( wcusage_cache_key( 'user', 'wcusage_user_affiliate_col_' . $user_id ) );
    }
}
add_action( 'update_user_meta', 'wcusage_clear_user_affiliate_column_cache_on_meta_update', 10, 4 );

/**
 * Invalidate affiliate caches when a user's role/group changes.
 *
 * Changing a user's group affects the affiliate users list (which is filtered
 * and keyed by role), the dashboard affiliate widgets, and the user's own
 * per-user caches. None of these were being invalidated on a role change, so the
 * change only showed up after the cache TTL expired (or a manual Redis flush).
 *
 * Fires for the WP user-edit "Role" dropdown ( set_user_role ) as well as the
 * plugin's own group assignment, registration approval, and rewards role changes
 * ( add_user_role / remove_user_role via WP_User::add_role()/remove_role() ).
 *
 * @param int $user_id User ID.
 * @return void
 */
function wcusage_clear_caches_on_role_change( $user_id ) {
    // Always clear the user's own cached data (cheap, targeted).
    wcusage_clear_user_cache( $user_id );

    // The affiliate users list and dashboard widgets are built from coupon
    // assignments, so a role change can only affect their contents when the
    // user actually is an affiliate. Skipping the flush otherwise prevents
    // cache thrash from unrelated role changes — set_user_role fires for every
    // new user registration (e.g. WooCommerce customers at checkout) and for
    // membership plugins toggling roles.
    if ( function_exists( 'wcusage_is_user_affiliate' ) && ! wcusage_is_user_affiliate( $user_id ) ) {
        return;
    }
    wcusage_cache_flush_ns( 'coupon_users_list' );
    wcusage_cache_flush_ns( 'dashboard' );
}
add_action( 'set_user_role', 'wcusage_clear_caches_on_role_change', 10, 1 );
add_action( 'add_user_role', 'wcusage_clear_caches_on_role_change', 10, 1 );
add_action( 'remove_user_role', 'wcusage_clear_caches_on_role_change', 10, 1 );

/* -------------------------------------------------------------------------
 *  Dashboard + leaderboard invalidation
 * ---------------------------------------------------------------------- */

/**
 * Clear all dashboard + leaderboard transient caches.
 * Called when orders change status, coupons change, or commission data updates.
 *
 * @return void
 */
function wcusage_clear_dashboard_caches() {
    wcusage_cache_flush_ns( 'dashboard' );
}

// Clear dashboard caches when order status changes.
add_action( 'woocommerce_order_status_changed', 'wcusage_clear_dashboard_caches', 999 );

// Clear dashboard caches when a coupon is saved.
add_action( 'save_post_shop_coupon', 'wcusage_clear_dashboard_caches', 20 );

/**
 * Clear dashboard caches when a coupon is deleted.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function wcusage_cache_on_coupon_delete( $post_id ) {
    if ( 'shop_coupon' === get_post_type( $post_id ) ) {
        wcusage_clear_dashboard_caches();
    }
}
add_action( 'delete_post', 'wcusage_cache_on_coupon_delete', 10 );

/**
 * AJAX: manually clear dashboard caches (dashboard "Clear cache" button).
 *
 * @return void
 */
function wcusage_clear_dashboard_caches_ajax() {
    check_ajax_referer( 'wcusage_dashboard_clear_cache', 'nonce' );

    if ( ! is_user_logged_in() || ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
        wp_send_json_error( array( 'message' => __( 'Not authorized.', 'woo-coupon-usage' ) ), 403 );
    }

    wcusage_clear_dashboard_caches();

    wp_send_json_success( array( 'message' => __( 'Dashboard caches cleared successfully!', 'woo-coupon-usage' ) ) );
}
add_action( 'wp_ajax_wcusage_clear_dashboard_caches', 'wcusage_clear_dashboard_caches_ajax' );
