<?php
/**
 * Coupon Affiliates legacy API (woo-coupon-usage/v1) - payout requests.
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

if ( wcu_fs()->can_use_premium_code() && wcu_fs()->is_premium() ) {

	/* Get the coupon name by ID */

	add_action(
		'rest_api_init',
		function () {
			register_rest_route(
				'woo-coupon-usage/v1',
				'/request-payout',
				array(
					'methods'             => 'POST',
					'callback'            => 'wcusage_api_request_payout',
					'permission_callback' => 'wcusage_api_v1_write_permission',
					'args'                => array(
						'coupon_id' => array(
							'description' => __( 'Coupon ID to request a payout for.', 'woo-coupon-usage' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'user'      => array(
							'description'       => __( 'Login name of the affiliate the coupon belongs to.', 'woo-coupon-usage' ),
							'type'              => 'string',
							'required'          => true,
							// See users-coupons.php: an explicit sanitize_callback
							// suppresses core's default type validation.
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_user',
						),
					),
				)
			);
		}
	);

	if ( ! function_exists( 'wcusage_api_request_payout' ) ) {
		/**
		 * Request a payout for a coupon's unpaid commission (legacy v1).
		 *
		 * @param WP_REST_Request $params Request.
		 *
		 * @return int 1 when a payout request was submitted, 0 otherwise.
		 */
		function wcusage_api_request_payout( $params ) {

			$coupon_id  = absint( $params['coupon_id'] );
			$user_login = $params['user'];

			if ( ! $coupon_id || ! is_string( $user_login ) || '' === $user_login ) {
				return 0;
			}

			if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
				return 0;
			}

			$wcusage_field_payouts_enable = wcusage_get_setting_value( 'wcusage_field_payouts_enable', '1' );
			if ( '1' === (string) $wcusage_field_payouts_enable ) {

				$couponinfo        = wcusage_get_coupon_info_by_id( $coupon_id );
				$unpaid_commission = $couponinfo[2];
				$coupon_user_id    = $couponinfo[1];

				$user = get_user_by( 'login', $user_login );

				// Unknown login: reading ->ID here used to be a fatal error.
				if ( ! $user ) {
					return 0;
				}

				$userid = $user->ID;

				// Custom hook - post payout.
				if ( $unpaid_commission > 0 && absint( $coupon_user_id ) === absint( $userid ) ) {

					// Payouts data.
					$payout_details_required = wcusage_get_setting_value( 'wcusage_field_payout_details_required', 1 );
					$payouts_data            = wcusage_get_user_payouts_details( $coupon_user_id );
					$currenttype             = $payouts_data['currenttype'];
					$payout_details          = $payouts_data['payout_details'];
					$require_details         = $payouts_data['require_details'];
					if ( $payout_details || ! $payout_details_required || ( $currenttype && ! $require_details ) ) {

						// Request payout.
						do_action( 'wcusage_hook_payout_post_submit', $coupon_user_id, $coupon_id, $unpaid_commission, 1, '' );
						return 1;

					}
				}

				return 0;

			}

			return 0;
		}
	}
}
