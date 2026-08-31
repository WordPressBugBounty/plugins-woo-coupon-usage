<?php
/**
 * Coupon Affiliates REST API v2 - Affiliate registrations.
 *
 * Reads the wcusage_register table; approval/decline goes through
 * wcusage_set_registration_status() so the full accept flow (coupon
 * creation from template, role assignment, emails, hooks) runs exactly
 * as it does from the admin screen.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Registrations_Controller' ) ) {

	/**
	 * /registrations endpoints.
	 */
	class WCUsage_API_Registrations_Controller extends WCUsage_API_Controller {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'registrations';

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
						'callback'            => array( $this, 'get_items' ),
						'permission_callback' => array( $this, 'permission_admin_read' ),
						'args'                => array_merge(
							$this->get_collection_params(),
							array(
								'status'  => array(
									'description'       => __( 'Filter by registration status.', 'woo-coupon-usage' ),
									'type'              => 'string',
									'enum'              => array( 'pending', 'accepted', 'declined' ),
									// See the payouts controller: an explicit sanitize_callback
									// suppresses core's default enum validation.
									'validate_callback' => 'rest_validate_request_arg',
									'sanitize_callback' => 'sanitize_key',
								),
								'user_id' => array(
									'description' => __( 'Filter by WP user ID.', 'woo-coupon-usage' ),
									'type'        => 'integer',
								),
							)
						),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item' ),
						'permission_callback' => array( $this, 'permission_admin_read' ),
						'args'                => array(
							'id' => array(
								'description' => __( 'Registration ID.', 'woo-coupon-usage' ),
								'type'        => 'integer',
								'required'    => true,
							),
						),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)/status',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'update_item_status' ),
						'permission_callback' => array( $this, 'permission_admin_write' ),
						'args'                => array(
							'id'         => array(
								'description' => __( 'Registration ID.', 'woo-coupon-usage' ),
								'type'        => 'integer',
								'required'    => true,
							),
							'status'     => array(
								'description' => __( 'accepted runs the full approval flow (creates the affiliate coupon from the template, assigns roles, sends emails).', 'woo-coupon-usage' ),
								'type'        => 'string',
								'enum'        => array( 'accepted', 'declined' ),
								'required'    => true,
							),
							'message'    => array(
								'description'       => __( 'Optional message included in the notification email.', 'woo-coupon-usage' ),
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'send_email' => array(
								'description' => __( 'Whether to send the notification email.', 'woo-coupon-usage' ),
								'type'        => 'boolean',
								'default'     => true,
							),
						),
					),
				)
			);
		}

		/**
		 * Whether the registrations table exists.
		 *
		 * @return true|WP_Error
		 */
		protected function registrations_available() {

			global $wpdb;

			if ( ! wcusage_api_table_exists( $wpdb->prefix . 'wcusage_register' ) ) {
				return wcusage_api_error( 'unavailable', __( 'The registrations table does not exist on this installation.', 'woo-coupon-usage' ), 501 );
			}

			return true;
		}

		/**
		 * GET /registrations
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_items( $request ) {

			$available = $this->registrations_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			global $wpdb;

			$where  = array( '1=1' );
			$params = array();

			$status = (string) $request['status'];
			if ( '' !== $status ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}

			$user_id = absint( $request['user_id'] );
			if ( $user_id ) {
				$where[]  = 'userid = %d';
				$params[] = $user_id;
			}

			$where_sql = implode( ' AND ', $where );

			$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_register WHERE $where_sql";
			$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			list( $limit, $offset ) = $this->get_limit_offset( $request );

			if ( $this->offset_is_out_of_range( $offset, $total ) ) {
				return $this->paginated_response( array(), $total, $request );
			}

			$items_sql = "SELECT * FROM {$wpdb->prefix}wcusage_register WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
			$rows      = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			$items = array();
			foreach ( (array) $rows as $row ) {
				$items[] = $this->prepare_registration( $row );
			}

			return $this->paginated_response( $items, $total, $request );
		}

		/**
		 * GET /registrations/{id}
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item( $request ) {

			$available = $this->registrations_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$row = $this->get_registration_row( absint( $request['id'] ) );
			if ( ! $row ) {
				return wcusage_api_error( 'not_found', __( 'Registration not found.', 'woo-coupon-usage' ), 404 );
			}

			return rest_ensure_response( $this->prepare_registration( $row ) );
		}

		/**
		 * POST /registrations/{id}/status
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function update_item_status( $request ) {

			$available = $this->registrations_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			if ( ! function_exists( 'wcusage_set_registration_status' ) ) {
				return wcusage_api_error( 'unavailable', __( 'The registration functions are not available.', 'woo-coupon-usage' ), 501 );
			}

			$row = $this->get_registration_row( absint( $request['id'] ) );
			if ( ! $row ) {
				return wcusage_api_error( 'not_found', __( 'Registration not found.', 'woo-coupon-usage' ), 404 );
			}

			$status = (string) $request['status'];

			// Re-accepting would clone another coupon from the template;
			// require an explicit different status.
			if ( $status === $row->status ) {
				return wcusage_api_error( 'no_change', __( 'The registration already has this status.', 'woo-coupon-usage' ), 400 );
			}

			// Accepting is not reversible through this API. wcusage_set_registration_status()
			// creates the affiliate's coupon from the template unconditionally, so an
			// accepted -> declined -> accepted cycle produces a second published coupon
			// with the same code and its own separate commission balance.
			if ( 'accepted' === $row->status ) {
				return wcusage_api_error(
					'already_accepted',
					__( 'This registration has already been accepted. Change it from the admin screens if it really needs to be reversed.', 'woo-coupon-usage' ),
					400
				);
			}

			// wcusage_set_registration_status() returns before touching anything
			// when the registration carries no coupon code, so without this the
			// API answered 200 with the unchanged row and the caller had to
			// notice for itself that nothing had happened.
			if ( '' === trim( (string) $row->couponcode ) ) {
				return wcusage_api_error(
					'no_coupon_code',
					__( 'This registration has no coupon code, so its status cannot be changed. Add one from the admin screens first.', 'woo-coupon-usage' ),
					409
				);
			}

			wcusage_set_registration_status(
				$status,
				(int) $row->id,
				(int) $row->userid,
				(string) $row->couponcode,
				(string) $request['message'],
				(string) $row->type,
				(bool) $request['send_email']
			);

			$updated = $this->get_registration_row( (int) $row->id );

			// Belt and braces for anything else that declines silently: report
			// what the row actually says rather than the status that was asked
			// for. The function has no return value to check.
			if ( $updated && $status !== (string) $updated->status ) {
				return wcusage_api_error(
					'not_updated',
					__( 'The registration status was not changed. Something on this site refused the update.', 'woo-coupon-usage' ),
					409
				);
			}

			return rest_ensure_response( $this->prepare_registration( $updated ? $updated : $row ) );
		}

		/**
		 * Fetch a registration row.
		 *
		 * @param int $id Registration ID.
		 *
		 * @return object|null
		 */
		protected function get_registration_row( $id ) {

			global $wpdb;

			return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wcusage_register WHERE id = %d",
					absint( $id )
				)
			);
		}

		/**
		 * Prepare a registration row for output.
		 *
		 * @param object $row Registration DB row.
		 *
		 * @return array
		 */
		protected function prepare_registration( $row ) {

			$info = array();
			if ( ! empty( $row->info ) ) {
				$decoded = json_decode( (string) $row->info, true );
				if ( is_array( $decoded ) ) {
					// Older versions keyed these fields by the
					// HTML-entity encoded label. Normalising here means consumers
					// see the same labels for old and new rows alike.
					if ( function_exists( 'wcusage_normalize_custom_fields' ) ) {
						$decoded = wcusage_normalize_custom_fields( $decoded );
					}
					$info = map_deep( $decoded, 'sanitize_text_field' );
				}
			}

			$data = array(
				'id'            => (int) $row->id,
				'user'          => wcusage_api_prepare_user_summary( (int) $row->userid ),
				'coupon_code'   => sanitize_text_field( (string) $row->couponcode ),
				'status'        => sanitize_text_field( (string) $row->status ),
				'type'          => sanitize_text_field( (string) $row->type ),
				'promote'       => sanitize_textarea_field( (string) $row->promote ),
				'referrer'      => sanitize_text_field( (string) $row->referrer ),
				'website'       => esc_url_raw( (string) $row->website ),
				'custom_fields' => $info ? $info : new stdClass(),
				'date'          => wcusage_api_format_date( (string) $row->date ),
				'date_accepted' => wcusage_api_format_date( (string) $row->dateaccepted ),
			);

			/**
			 * Filter a registration returned by the REST API.
			 *
			 * @param array  $data Prepared data.
			 * @param object $row  Raw DB row.
			 */
			return apply_filters( 'wcusage_api_prepare_registration', $data, $row );
		}

		/**
		 * Item schema.
		 *
		 * @return array
		 */
		public function get_item_schema() {

			if ( $this->schema ) {
				return $this->add_additional_fields_schema( $this->schema );
			}

			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'wcusage_registration',
				'type'       => 'object',
				'properties' => array(
					'id'            => array( 'type' => 'integer' ),
					'user'          => array( 'type' => 'object' ),
					'coupon_code'   => array( 'type' => 'string' ),
					'status'        => array(
						'type' => 'string',
						'enum' => array( 'pending', 'accepted', 'declined' ),
					),
					'type'          => array( 'type' => 'string' ),
					'promote'       => array( 'type' => 'string' ),
					'referrer'      => array( 'type' => 'string' ),
					'website'       => array( 'type' => 'string' ),
					'custom_fields' => array( 'type' => 'object' ),
					'date'          => array( 'type' => array( 'string', 'null' ) ),
					'date_accepted' => array( 'type' => array( 'string', 'null' ) ),
				),
			);

			return $this->add_additional_fields_schema( $this->schema );
		}
	}
}
