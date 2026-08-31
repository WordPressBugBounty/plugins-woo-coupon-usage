<?php
/**
 * Coupon Affiliates REST API v2 - Referral clicks.
 *
 * Aggregates over the wcusage_clicks table with COUNT() queries (the
 * dashboard's equivalent loads whole rows and counts in PHP). IP addresses
 * are never returned.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Clicks_Controller' ) ) {

	/**
	 * /clicks endpoints.
	 */
	class WCUsage_API_Clicks_Controller extends WCUsage_API_Controller {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'clicks';

		/**
		 * Register routes.
		 */
		public function register_routes() {

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/stats',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_stats' ),
						'permission_callback' => array( $this, 'permission_stats' ),
						'args'                => array(
							'coupon_id' => array(
								'description' => __( 'Coupon ID. Required for non-admin users.', 'woo-coupon-usage' ),
								'type'        => 'integer',
							),
							'campaign'  => array(
								'description'       => __( 'Filter by campaign name.', 'woo-coupon-usage' ),
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'from'      => array(
								'description'       => __( 'Start date (Y-m-d).', 'woo-coupon-usage' ),
								'type'              => 'string',
								'validate_callback' => 'wcusage_api_validate_date_arg',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'to'        => array(
								'description'       => __( 'End date (Y-m-d).', 'woo-coupon-usage' ),
								'type'              => 'string',
								'validate_callback' => 'wcusage_api_validate_date_arg',
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);
		}

		/**
		 * Permission: admin for store-wide stats, coupon access for one coupon.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_stats( $request ) {

			if ( ! is_user_logged_in() ) {
				return wcusage_api_auth_required_error();
			}

			$scope_check = wcusage_api_require_scope( 'read' );
			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}

			$coupon_id = absint( $request['coupon_id'] );

			if ( ! $coupon_id ) {
				return wcusage_api_is_admin_user() ? true : wcusage_api_error( 'coupon_required', __( 'Non-admin users must pass a coupon_id.', 'woo-coupon-usage' ), 400 );
			}

			// As in permission_coupon_read(): another affiliate's coupon is
			// reported as missing rather than forbidden, so the response cannot
			// be used to find out which coupons exist.
			if ( ! wcusage_api_get_valid_coupon_id( $coupon_id ) || ! wcusage_api_user_can_access_coupon( $coupon_id ) ) {
				return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
			}

			return true;
		}

		/**
		 * GET /clicks/stats
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_stats( $request ) {

			global $wpdb;

			if ( ! wcusage_api_table_exists( $wpdb->prefix . 'wcusage_clicks' ) ) {
				return wcusage_api_error( 'unavailable', __( 'The clicks table does not exist on this installation.', 'woo-coupon-usage' ), 501 );
			}

			$where  = array( '1=1' );
			$params = array();

			$coupon_id = absint( $request['coupon_id'] );
			if ( $coupon_id ) {
				// couponid is stored as text.
				$where[]  = 'couponid = %s';
				$params[] = (string) $coupon_id;
			}

			$campaign = (string) $request['campaign'];
			if ( '' !== $campaign ) {
				$where[]  = 'campaign = %s';
				$params[] = $campaign;
			}

			$from = (string) $request['from'];
			if ( '' !== $from ) {
				$where[]  = 'date >= %s';
				$params[] = $from . ' 00:00:00';
			}

			$to = (string) $request['to'];
			if ( '' !== $to ) {
				$where[]  = 'date <= %s';
				$params[] = $to . ' 23:59:59';
			}

			$where_sql = implode( ' AND ', $where );

			$sql = "SELECT COUNT(*) AS clicks,
				SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) AS conversions
				FROM {$wpdb->prefix}wcusage_clicks WHERE $where_sql";

			$row = $wpdb->get_row( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			$clicks      = $row ? (int) $row->clicks : 0;
			$conversions = $row ? (int) $row->conversions : 0;

			return rest_ensure_response(
				array(
					'coupon_id'       => $coupon_id ? $coupon_id : null,
					'campaign'        => '' !== $campaign ? $campaign : null,
					'from'            => '' !== $from ? $from : null,
					'to'              => '' !== $to ? $to : null,
					'clicks'          => $clicks,
					'conversions'     => $conversions,
					'conversion_rate' => $clicks > 0 ? round( ( $conversions / $clicks ) * 100, 2 ) : 0.0,
				)
			);
		}
	}
}
