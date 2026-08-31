<?php
/**
 * Coupon Affiliates legacy API (woo-coupon-usage/v1) - permission callbacks.
 *
 * The v1 routes predate the v2 scope system. They are reachable with a v2
 * API key (the key namespace allow-list includes this namespace), and their
 * own permission callbacks only ever checked a capability - so a key issued
 * with the "read" scope could still POST /request-payout, which writes.
 * These callbacks keep the original administrator requirement and add the
 * missing scope enforcement on top.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_api_v1_permission' ) ) {
	/**
	 * Shared gate for the legacy v1 routes.
	 *
	 * @param string $scope Scope the route needs ("read" or "write").
	 *
	 * @return true|WP_Error
	 */
	function wcusage_api_v1_permission( $scope ) {

		// v1 has always been administrator-only. Deliberately not widened to
		// the plugin's configurable admin capability: that would grant access
		// this namespace never had. The role check (rather than a capability)
		// is kept verbatim from the original v1 routes so that behaviour does
		// not change for existing integrations.
		if ( ! current_user_can( 'administrator' ) ) { // phpcs:ignore WordPress.WP.Capabilities.RoleFound
			if ( function_exists( 'wcusage_api_auth_required_error' ) ) {
				return wcusage_api_auth_required_error();
			}
			return new WP_Error(
				'wcusage_api_forbidden',
				__( 'You do not have permission to access this resource.', 'woo-coupon-usage' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}

		// Scope check only applies when an API key authenticated the request;
		// it returns true for cookie / application-password auth.
		if ( function_exists( 'wcusage_api_require_scope' ) ) {
			return wcusage_api_require_scope( $scope );
		}

		return true;
	}
}

if ( ! function_exists( 'wcusage_api_v1_read_permission' ) ) {
	/**
	 * Permission callback for the read-only v1 routes.
	 *
	 * @return true|WP_Error
	 */
	function wcusage_api_v1_read_permission() {
		return wcusage_api_v1_permission( 'read' );
	}
}

if ( ! function_exists( 'wcusage_api_v1_write_permission' ) ) {
	/**
	 * Permission callback for v1 routes that change data.
	 *
	 * @return true|WP_Error
	 */
	function wcusage_api_v1_write_permission() {
		return wcusage_api_v1_permission( 'write' );
	}
}
