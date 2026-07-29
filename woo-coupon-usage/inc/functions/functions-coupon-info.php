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

		// Getting the URL
		if($wcusage_justcoupon) {
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