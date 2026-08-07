<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get info from the coupon based on coupon code
 *
 * @param string $coupon_code
 *
 * @return mixed
 *
 */
if( !function_exists( 'wcusage_get_coupon_info' ) ) {
	function wcusage_get_coupon_info($coupon_code) {

		// Static cache to reduce database queries
		static $coupon_info_cache = array();

		// Return cached result if available
		if (isset($coupon_info_cache[$coupon_code])) {
			return $coupon_info_cache[$coupon_code];
		}

		try {

			if($coupon_code) {

				$couponid = wcusage_get_coupon_id($coupon_code);

				$coupon_commission_percent = get_post_meta( $couponid, 'wcu_text_coupon_commission', true );
					if(!$coupon_commission_percent) { $coupon_commission_percent = wcusage_get_setting_value('wcusage_field_affiliate', '0'); }

				$coupon_user_id = get_post_meta( $couponid, 'wcu_select_coupon_user', true );

				$result = array($coupon_commission_percent, $coupon_user_id, $couponid);
				// Cache the result
				$coupon_info_cache[$coupon_code] = $result;
				return $result;

			} else {

				$result = array('', '', '');
				$coupon_info_cache[$coupon_code] = $result;
				return $result;
			
			}

		} catch (Exception $e) {

			$result = array();
			$coupon_info_cache[$coupon_code] = $result;
			return $result;

		}

	}
}
add_action('wcusage_hook_get_coupon_info', 'wcusage_get_coupon_info', 10, 1);

/**
 * Get coupon ID
 *
 * @param string $coupon_code
 *
 * @return mixed
 *
 */
function wcusage_get_coupon_id($coupon_code) {

	// Static cache for coupon IDs
	static $coupon_id_cache = array();

    if (!isset($coupon_code)) {
		return "";
	}

	// Return cached ID if available
	if (isset($coupon_id_cache[$coupon_code])) {
		return $coupon_id_cache[$coupon_code];
	}

    $coupon_id = wc_get_coupon_id_by_code(sanitize_text_field($coupon_code));

	if(!$coupon_id)	{
		$coupon_id_cache[$coupon_code] = 0;
		return 0;
	}

	$result = esc_html($coupon_id);
	$coupon_id_cache[$coupon_code] = $result;
    return $result;

}

/**
 * Whether two strings are the same coupon code.
 *
 * WooCommerce compares coupon codes case-insensitively (wc_format_coupon_code()
 * lowercases them), so "SUMMER" and "summer" are one code as far as it is
 * concerned. Accents are deliberately NOT folded here: the post_title collation
 * (utf8mb4_unicode_520_ci on most installs) treats "korperkur" and "körperkur"
 * as equal, which is too loose to identify one specific coupon.
 *
 * @param string $code_a
 * @param string $code_b
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_coupon_codes_match' ) ) {
	function wcusage_coupon_codes_match( $code_a, $code_b ) {

		if ( ! is_string( $code_a ) || ! is_string( $code_b ) || $code_a === '' || $code_b === '' ) {
			return false;
		}

		if ( function_exists( 'wc_format_coupon_code' ) ) {
			return wc_format_coupon_code( $code_a ) === wc_format_coupon_code( $code_b );
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $code_a ) === mb_strtolower( $code_b );
		}

		return strtolower( $code_a ) === strtolower( $code_b );

	}
}

/**
 * Whether a coupon's own code is the given code.
 *
 * This asks the coupon, rather than asking the code which coupon it belongs to.
 * The difference matters when two coupons share a code - they differ only by
 * letter case, which the post_title collation ignores - because looking the code
 * up can only ever return one of them, and so reports every other coupon with
 * that code as "not that coupon".
 *
 * @param int    $couponid
 * @param string $coupon_code
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_coupon_id_has_code' ) ) {
	function wcusage_coupon_id_has_code( $couponid, $coupon_code ) {

		$couponid = absint( $couponid );

		if ( ! $couponid || ! $coupon_code || get_post_type( $couponid ) !== 'shop_coupon' ) {
			return false;
		}

		return wcusage_coupon_codes_match( get_post_field( 'post_title', $couponid, 'raw' ), $coupon_code );

	}
}

/**
 * Whether another coupon shares this coupon's code.
 *
 * Two coupons whose codes differ only by letter case are a single code to
 * WooCommerce, so only one of them is ever reachable by code. Dashboard URLs for
 * an ambiguous coupon have to carry its ID to point anywhere in particular.
 *
 * @param int $couponid
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_coupon_code_is_ambiguous' ) ) {
	function wcusage_coupon_code_is_ambiguous( $couponid ) {

		// Called once per row on the coupons and affiliate orders lists, and again for
		// every dashboard URL built on those pages. wc_get_coupon_id_by_code() runs a
		// direct query for any code not already in the object cache, so without this the
		// check adds a query per row - see wcusage_get_coupon_id(), which caches the same
		// way. Keyed on the coupon ID, which is what callers pass.
		static $ambiguous_cache = array();

		$couponid = absint( $couponid );

		if ( isset( $ambiguous_cache[ $couponid ] ) ) {
			return $ambiguous_cache[ $couponid ];
		}

		if ( ! $couponid || ! function_exists( 'wc_get_coupon_id_by_code' ) || get_post_type( $couponid ) !== 'shop_coupon' ) {
			return false;
		}

		$coupon_code = get_post_field( 'post_title', $couponid, 'raw' );
		if ( ! $coupon_code ) {
			$ambiguous_cache[ $couponid ] = false;
			return false;
		}

		// $exclude is applied after the lookup, so this returns a *different*
		// coupon that answers to the same code, when there is one.
		$ambiguous_cache[ $couponid ] = (bool) wc_get_coupon_id_by_code( $coupon_code, $couponid );

		return $ambiguous_cache[ $couponid ];

	}
}

/**
 * Resolve a "couponid" dashboard URL parameter to a coupon ID.
 *
 * The parameter is either the coupon code on its own, or "<code>-<coupon id>".
 * Where the ID suffix is present and names a coupon that really does have that
 * code, it is used directly - the only way to tell two coupons apart when they
 * share a code. A value that matches a code in full always wins, so codes that
 * legitimately end in "-<number>" (e.g. "relywp-10") are never mistaken for a
 * code plus a suffix.
 *
 * @param string $urlid
 *
 * @return int Coupon ID, or 0 when nothing matches.
 *
 */
if( !function_exists( 'wcusage_get_dashboard_coupon_id' ) ) {
	function wcusage_get_dashboard_coupon_id( $urlid ) {

		$urlid = is_string( $urlid ) ? trim( $urlid ) : '';

		if ( ! $urlid ) {
			return 0;
		}

		$coupon_id = absint( wcusage_get_coupon_id( $urlid ) );
		if ( $coupon_id ) {
			return $coupon_id;
		}

		if ( preg_match( '/^(.+)-(\d+)$/', $urlid, $matches ) ) {

			$suffix_id = absint( $matches[2] );

			if ( wcusage_coupon_id_has_code( $suffix_id, $matches[1] ) && get_post_status( $suffix_id ) === 'publish' ) {
				return $suffix_id;
			}

			return absint( wcusage_get_coupon_id( $matches[1] ) );

		}

		return 0;

	}
}

/**
 * Safely get a WC_Coupon object without throwing exceptions.
 *
 * @param mixed $coupon_value
 *
 * @return WC_Coupon|false
 */
if( !function_exists( 'wcusage_get_coupon_object_safe' ) ) {
	function wcusage_get_coupon_object_safe( $coupon_value ) {
		if ( empty( $coupon_value ) ) {
			return false;
		}

		$coupon_id = 0;
		$coupon_value_string = is_string( $coupon_value ) ? $coupon_value : (string) $coupon_value;
		if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$coupon_id_by_code = wc_get_coupon_id_by_code( $coupon_value_string );
		} else {
			$coupon_id_by_code = 0;
		}

		if ( is_numeric( $coupon_value ) ) {
			$candidate_id = absint( $coupon_value );
			if ( $candidate_id && get_post_type( $candidate_id ) === 'shop_coupon' ) {
				$coupon_id = $candidate_id;
			} elseif ( $coupon_id_by_code ) {
				$coupon_id = $coupon_id_by_code;
			}
		} else {
			$coupon_id = $coupon_id_by_code;
		}

		if ( $coupon_id ) {
			$coupon_value = $coupon_id;
		}

		try {
			$coupon = new WC_Coupon( $coupon_value );
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $coupon || ! $coupon->get_id() ) {
			return false;
		}

		return $coupon;
	}
}

/**
 * Get coupon ID by coupon code via ajax
 *
 * @param string $coupon_code
 *
 * @return mixed
 *
 */
add_action('wp_ajax_wcusage_ajax_get_coupon_id', 'wcusage_ajax_get_coupon_id');
function wcusage_ajax_get_coupon_id() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcusage_ajax_get_coupon_id_nonce')) {
        wp_die('Security check failed');
    }
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
	$coupon_id = wcusage_get_coupon_id($_POST['coupon_name']);
    echo esc_html($coupon_id);
    wp_die();
}

/**
 * Get info from the coupon based on ID
 *
 * @param string $couponid
 *
 * @return mixed
 *
 */
if( !function_exists( 'wcusage_get_coupon_info_by_id' ) ) {
	function wcusage_get_coupon_info_by_id($couponid) {

		$options = get_option( 'wcusage_options' );

		$coupon_commission_percent = get_post_meta( $couponid, 'wcu_text_coupon_commission', true );
			if(!$coupon_commission_percent) { $coupon_commission_percent = wcusage_get_setting_value('wcusage_field_affiliate', '0'); }

		$coupon_user_id = get_post_meta( $couponid, 'wcu_select_coupon_user', true );

		$unpaid_commission = get_post_meta( $couponid, 'wcu_text_unpaid_commission', true );
			if(!$unpaid_commission) { $unpaid_commission = 0; }

    	$pending_payouts = get_post_meta( $couponid, 'wcu_text_pending_payment_commission', true );
			if(!$pending_payouts) { $pending_payouts = 0; }

		$wcusage_justcoupon = wcusage_get_setting_value('wcusage_field_justcoupon', '1');

		$coupon = get_the_title($couponid);

		// Getting the URL. The "just coupon" form cannot identify this coupon when
		// another one answers to the same code, so keep the ID suffix in that case.
		if($wcusage_justcoupon && !wcusage_coupon_code_is_ambiguous($couponid)) {
			$secretid = $coupon;
		} else {
			$secretid = $coupon . "-" . $couponid;
		}

		$thepageurl = wcusage_get_coupon_shortcode_page(1, 0);

		// If secretid contains a & make it a URL safe string
		if (strpos($secretid, '&') !== false) {
			$secretid = str_replace('&', '%26', $secretid);
		}

		$uniqueurl = $thepageurl . 'couponid=' . $secretid;

		// Return
		return array($coupon_commission_percent, $coupon_user_id, $unpaid_commission, $coupon, $uniqueurl, $pending_payouts);

	}
}
add_action('wcusage_hook_get_coupon_info_by_id', 'wcusage_get_coupon_info_by_id', 10, 1);

/**
 * Checks whether a coupon's own product/category usage restrictions allow a product.
 *
 * This mirrors WC_Coupon::is_valid_for_product() (products, categories, excluded
 * products, excluded categories, exclude sale items) but deliberately omits core's
 * coupon-type gate. Core returns false for EVERY product when the coupon is not a
 * "product" type - i.e. for fixed_cart coupons - which would wrongly report that a
 * fixed_cart coupon applies to nothing. Restrictions are still meaningful for those
 * coupons (they gate validity), so we evaluate the lists directly.
 *
 * Category matching uses wc_get_product_cat_ids(), which includes ancestors, so a
 * restriction on a parent category covers products filed in its sub-categories.
 *
 * @param WC_Coupon|string $coupon      Coupon object or code.
 * @param int              $product_id  Product or variation ID from the line item.
 * @param int              $parent_id   Parent product ID from the line item.
 *
 * @return bool True if the coupon's restrictions allow this product (or cannot be determined).
 *
 */
if( !function_exists( 'wcusage_coupon_restrictions_allow_product' ) ) {
	function wcusage_coupon_restrictions_allow_product( $coupon, $product_id, $parent_id = 0 ) {

		if ( ! ( $coupon instanceof WC_Coupon ) ) {
			if ( ! $coupon || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
				return true;
			}

			// Cache the coupon object per request. This runs once per order line item, so a
			// statistics rebuild walking thousands of orders would otherwise build the same
			// coupon object tens of thousands of times.
			static $coupon_cache = array();
			$cache_key = (string) $coupon;

			if ( ! array_key_exists( $cache_key, $coupon_cache ) ) {
				$coupon_id = wc_get_coupon_id_by_code( $coupon );
				$coupon_cache[ $cache_key ] = $coupon_id ? new WC_Coupon( $coupon_id ) : null;
			}

			if ( null === $coupon_cache[ $cache_key ] ) {
				return true; // Coupon no longer exists - do not withhold commission.
			}
			$coupon = $coupon_cache[ $cache_key ];
		}

		$include_ids   = $coupon->get_product_ids();
		$exclude_ids   = $coupon->get_excluded_product_ids();
		$include_cats  = $coupon->get_product_categories();
		$exclude_cats  = $coupon->get_excluded_product_categories();
		$exclude_sale  = $coupon->get_exclude_sale_items();

		// No restrictions set at all - nothing to evaluate.
		if ( ! count( $include_ids ) && ! count( $exclude_ids )
			&& ! count( $include_cats ) && ! count( $exclude_cats ) && ! $exclude_sale ) {
			return true;
		}

		$product_id = (int) $product_id;
		$parent_id  = (int) $parent_id;

		// Match on both the variation and its parent, as core does.
		$product_ids = array_filter( array( $product_id, $parent_id ) );

		// Ancestors included, so a parent-category restriction covers sub-categories.
		$cat_source   = $parent_id ? $parent_id : $product_id;
		$product_cats = function_exists( 'wc_get_product_cat_ids' ) ? wc_get_product_cat_ids( $cat_source ) : array();

		$valid = false;

		// Specific products included.
		if ( count( $include_ids ) && count( array_intersect( $product_ids, $include_ids ) ) ) {
			$valid = true;
		}

		// Specific categories included.
		if ( count( $include_cats ) && count( array_intersect( $product_cats, $include_cats ) ) ) {
			$valid = true;
		}

		// No include lists at all - everything is covered by default.
		if ( ! count( $include_ids ) && ! count( $include_cats ) ) {
			$valid = true;
		}

		// Specific products excluded.
		if ( count( $exclude_ids ) && count( array_intersect( $product_ids, $exclude_ids ) ) ) {
			$valid = false;
		}

		// Specific categories excluded.
		if ( count( $exclude_cats ) && count( array_intersect( $product_cats, $exclude_cats ) ) ) {
			$valid = false;
		}

		// Sale items excluded.
		if ( $exclude_sale ) {
			$product = wc_get_product( $product_id ? $product_id : $parent_id );
			if ( $product && $product->is_on_sale() ) {
				$valid = false;
			}
		}

		return apply_filters( 'wcusage_coupon_restrictions_allow_product', $valid, $coupon, $product_id, $parent_id );

	}
}

/**
 * Reset the WooCommerce usage tracking on a coupon that was just duplicated from a template.
 *
 * Every "create coupon from template" path copies the template's post meta wholesale, which
 * also drags across the template's usage history: 'usage_count' and, more damagingly, the
 * '_used_by' rows. WooCommerce reads '_used_by' directly when enforcing "Usage limit per user"
 * (WC_Coupon_Data_Store_CPT::get_usage_by_user_id), so an inherited list makes a brand new
 * coupon appear already used by those customers - they get "Coupon usage limit has been
 * reached." on a coupon nobody has ever redeemed, while the admin sees a usage count of 0.
 *
 * Call this immediately after copying template meta onto the new coupon.
 *
 * @param int $coupon_id The newly created coupon post ID.
 */
if( !function_exists( 'wcusage_reset_coupon_usage_meta' ) ) {
	function wcusage_reset_coupon_usage_meta( $coupon_id ) {

		$coupon_id = (int) $coupon_id;
		if ( ! $coupon_id ) {
			return;
		}

		// '_used_by' is stored as one meta row per redemption, so delete them all.
		delete_post_meta( $coupon_id, '_used_by' );

		update_post_meta( $coupon_id, 'usage_count', '0' );

		// WooCommerce also holds usage tentatively while a customer is at checkout, under
		// generated keys ('_coupon_held_<expiry>_<rand>' and '_maybe_used_by_<expiry>_<rand>').
		// These expire on their own, but until they do a copied row counts against the new
		// coupon, so clear any that came over with the template.
		$meta = get_post_meta( $coupon_id );
		if ( is_array( $meta ) ) {
			foreach ( array_keys( $meta ) as $meta_key ) {
				if ( 0 === strpos( $meta_key, '_coupon_held_' ) || 0 === strpos( $meta_key, '_maybe_used_by_' ) ) {
					delete_post_meta( $coupon_id, $meta_key );
				}
			}
		}

		do_action( 'wcusage_hook_reset_coupon_usage_meta', $coupon_id );

	}
}