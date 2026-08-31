<?php
/**
 * Coupon Affiliates legacy API (woo-coupon-usage/v1) - coupon info.
 *
 * Predates the v2 API. Kept registered so existing integrations keep working;
 * the permission callback lives in api-v1-permissions.php and adds the scope
 * enforcement these routes were written without.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/* Get the coupon name and info by ID */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'woo-coupon-usage/v1',
			'/coupon-info',
			array(
				'methods'             => 'GET',
				'callback'            => 'wcusage_api_coupon_info',
				'permission_callback' => 'wcusage_api_v1_read_permission',
				'args'                => array(
					'coupon_id' => array(
						'description' => __( 'Coupon ID.', 'woo-coupon-usage' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);
	}
);

if ( ! function_exists( 'wcusage_api_coupon_info' ) ) {
	/**
	 * Return summary information for a coupon (legacy v1).
	 *
	 * @param WP_REST_Request $params Request.
	 *
	 * @return array|WP_Error Coupon summary, or a 404 when it does not exist.
	 */
	function wcusage_api_coupon_info( $params ) {

		$coupon_id = absint( $params['coupon_id'] );

		// Only report on real coupons: this used to accept any post ID and would
		// happily return an unrelated post's title as "coupon_name".
		if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return new WP_Error( 'wcusage_api_not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), array( 'status' => 404 ) );
		}

		$couponinfo = wcusage_get_coupon_info_by_id( $coupon_id );

		// Return.
		$return_array                      = array();
		$return_array['coupon_name']       = $couponinfo[3];
		$return_array['unpaid_commission'] = $couponinfo[2];
		$return_array['pending_payouts']   = $couponinfo[5];
		$return_array['coupon_user_id']    = $couponinfo[1];
		$return_array['referral_url']      = $couponinfo[4];
		return $return_array;
	}
}
