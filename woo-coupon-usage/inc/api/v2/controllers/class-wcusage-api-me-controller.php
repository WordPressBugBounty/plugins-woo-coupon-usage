<?php
/**
 * Coupon Affiliates REST API v2 - /me.
 *
 * "Who am I" endpoint for integrations and AI agents: identifies the
 * authenticated user, their access level, scopes and (when they are an
 * affiliate) their coupons.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Me_Controller' ) ) {

	/**
	 * /me endpoint.
	 */
	class WCUsage_API_Me_Controller extends WCUsage_API_Controller {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'me';

		/**
		 * Register routes.
		 */
		public function register_routes() {

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item' ),
						'permission_callback' => array( $this, 'permission_authenticated' ),
					),
				)
			);
		}

		/**
		 * Permission: any authenticated user.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_authenticated( $request ) {
			if ( ! is_user_logged_in() ) {
				return wcusage_api_auth_required_error();
			}
			return true;
		}

		/**
		 * GET /me
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response
		 */
		public function get_item( $request ) {

			$user_id      = get_current_user_id();
			$is_affiliate = function_exists( 'wcusage_is_user_affiliate' ) ? wcusage_is_user_affiliate( $user_id ) : false;

			// Identity is always available so any key can introspect itself,
			// but the coupon balances are data: they need the read scope.
			$coupons = array();
			if ( $is_affiliate && wcusage_api_current_user_has_scope( 'read' ) && function_exists( 'wcusage_get_users_coupons_ids' ) ) {

				$coupon_ids = wcusage_get_users_coupons_ids( $user_id );

				// The IDs come from a cached lookup, so nothing has primed the
				// posts behind them: without this each coupon costs a query for
				// its post row and another for its meta.
				if ( ! empty( $coupon_ids ) ) {
					_prime_post_caches( $coupon_ids, false, true );
				}

				foreach ( $coupon_ids as $coupon_id ) {
					$summary = wcusage_api_prepare_coupon_summary( $coupon_id, false );
					if ( $summary ) {
						$coupons[] = $summary;
					}
				}
			}

			$key    = wcusage_api_current_key();
			$scopes = wcusage_api_current_scopes();

			return rest_ensure_response(
				array(
					'user'         => wcusage_api_prepare_user_summary( $user_id ),
					'is_admin'     => wcusage_api_is_admin_user(),
					'is_affiliate' => (bool) $is_affiliate,
					'auth'         => array(
						'method' => $key ? 'api_key' : 'wordpress',
						'key_id' => $key ? (int) $key->id : null,
						'scopes' => $scopes, // null = full access (capability-based auth).
					),
					'coupons'      => $coupons,
					'api_version'  => defined( 'WCUSAGE_VERSION' ) ? WCUSAGE_VERSION : '',
				)
			);
		}
	}
}
