<?php
/**
 * Coupon Affiliates REST API v2 - Base controller.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Controller' ) ) {

	/**
	 * Shared behaviour for all v2 controllers.
	 */
	abstract class WCUsage_API_Controller extends WP_REST_Controller {

		/**
		 * REST namespace.
		 *
		 * @var string
		 */
		protected $namespace = 'wcusage/v2';

		/**
		 * Permission callback: plugin admin with the read scope.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_admin_read( $request ) {
			if ( ! wcusage_api_is_admin_user() ) {
				return wcusage_api_auth_required_error();
			}
			return wcusage_api_require_scope( 'read' );
		}

		/**
		 * Permission callback: plugin admin with the write scope.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_admin_write( $request ) {
			if ( ! wcusage_api_is_admin_user() ) {
				return wcusage_api_auth_required_error();
			}
			return wcusage_api_require_scope( 'write' );
		}

		/**
		 * Permission callback: plugin admin with the manage scope.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_admin_manage( $request ) {
			if ( ! wcusage_api_is_admin_user() ) {
				return wcusage_api_auth_required_error();
			}
			return wcusage_api_require_scope( 'manage' );
		}

		/**
		 * Permission callback factory: admin, coupon owner or MLA upline,
		 * with the read scope.
		 *
		 * Reads "id" whenever it is present, falling back to "coupon_id"
		 * only when it is absent. It must not treat a supplied id of "0" as
		 * missing: the route regex matches 0, so falling through would
		 * authorise against a different, caller-supplied coupon than the
		 * handler goes on to read.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_coupon_read( $request ) {

			$coupon_id = absint( isset( $request['id'] ) ? $request['id'] : $request['coupon_id'] );

			if ( ! is_user_logged_in() ) {
				return wcusage_api_auth_required_error();
			}

			$scope_check = wcusage_api_require_scope( 'read' );
			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}

			// A coupon that exists but belongs to somebody else answers exactly
			// as one that does not exist at all. Separating the two would let
			// any authenticated affiliate walk the ID space and learn which
			// posts are coupons and roughly how many the program runs, and
			// there is nothing a legitimate caller can do with the difference.
			if ( ! wcusage_api_get_valid_coupon_id( $coupon_id ) || ! wcusage_api_user_can_access_coupon( $coupon_id ) ) {
				return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
			}

			return true;
		}

		/**
		 * Standard collection pagination params.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			return array(
				'page'     => array(
					'description' => __( 'Current page of the collection.', 'woo-coupon-usage' ),
					'type'        => 'integer',
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page' => array(
					'description' => __( 'Maximum number of items per page.', 'woo-coupon-usage' ),
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			);
		}

		/**
		 * Build a paginated collection response with the standard headers.
		 *
		 * @param array           $items   Prepared items.
		 * @param int             $total   Total matching items.
		 * @param WP_REST_Request $request Request (for per_page).
		 *
		 * @return WP_REST_Response
		 */
		protected function paginated_response( $items, $total, $request ) {

			$per_page = max( 1, absint( $request['per_page'] ? $request['per_page'] : 20 ) );

			$response = rest_ensure_response( array_values( $items ) );
			$response->header( 'X-WP-Total', (int) $total );
			$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

			return $response;
		}

		/**
		 * Resolve page/per_page into an SQL LIMIT/OFFSET pair.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return array array( $limit, $offset ).
		 */
		protected function get_limit_offset( $request ) {
			$per_page = max( 1, min( 100, absint( $request['per_page'] ? $request['per_page'] : 20 ) ) );
			$page     = max( 1, absint( $request['page'] ? $request['page'] : 1 ) );
			return array( $per_page, ( $page - 1 ) * $per_page );
		}

		/**
		 * Whether a requested page lies beyond the end of the result set.
		 *
		 * Used to skip the items query entirely for out-of-range pages: a
		 * deep OFFSET makes the database walk every preceding row before
		 * discarding it, so an unbounded "page" is otherwise a cheap way to
		 * ask for expensive work.
		 *
		 * @param int $offset Resolved offset.
		 * @param int $total  Total matching rows.
		 *
		 * @return bool
		 */
		protected function offset_is_out_of_range( $offset, $total ) {
			return ( $offset > 0 && $offset >= (int) $total );
		}
	}
}
