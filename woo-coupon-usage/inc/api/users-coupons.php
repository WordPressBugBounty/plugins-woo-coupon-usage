<?php
/**
 * Coupon Affiliates legacy API (woo-coupon-usage/v1) - a user's coupons.
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

/* Get the coupon IDs assigned to user (and unpaid commission) based on user ID */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'woo-coupon-usage/v1',
			'/users-coupons',
			array(
				'methods'             => 'GET',
				'callback'            => 'wcusage_api_users_coupons',
				'permission_callback' => 'wcusage_api_v1_read_permission',
				'args'                => array(
					'user' => array(
						'description'       => __( 'User login name.', 'woo-coupon-usage' ),
						'type'              => 'string',
						'required'          => true,
						// An explicit sanitize_callback replaces the core default
						// that normally performs the type check, so ask for
						// validation explicitly - otherwise "user[]=x" reaches
						// sanitize_user() as an array.
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_user',
					),
				),
			)
		);
	}
);

if ( ! function_exists( 'wcusage_api_users_coupons' ) ) {
	/**
	 * List the coupon IDs assigned to a user (legacy v1).
	 *
	 * @param WP_REST_Request $params Request.
	 *
	 * @return array Coupon post IDs, empty when the login does not resolve.
	 */
	function wcusage_api_users_coupons( $params ) {

		$user_login = $params['user'];

		// The parameter is schema-typed to a string, but guard anyway: passing an
		// array here used to reach get_user_by() and throw a TypeError (HTTP 500).
		if ( ! is_string( $user_login ) || '' === $user_login ) {
			return array();
		}

		$user = get_user_by( 'login', $user_login );

		// Unknown login: return an empty list rather than fatalling on ->ID.
		if ( ! $user ) {
			return array();
		}

		$coupons = wcusage_get_users_coupons_ids( $user->ID );

		return $coupons;
	}
}
