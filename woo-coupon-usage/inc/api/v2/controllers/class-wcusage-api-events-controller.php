<?php
/**
 * Coupon Affiliates REST API v2 - Events feed.
 *
 * Exposes the wcusage_activity table as a cursor-based change feed so
 * polling integrations (and AI agents) can ask "what happened since ID X".
 * The auto-increment primary key is the cursor.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Events_Controller' ) ) {

	/**
	 * /events endpoints.
	 */
	class WCUsage_API_Events_Controller extends WCUsage_API_Controller {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'events';

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
								'after'   => array(
									'description' => __( 'Cursor: only events with an ID greater than this. Results are returned oldest first when set.', 'woo-coupon-usage' ),
									'type'        => 'integer',
									'minimum'     => 0,
								),
								'event'   => array(
									'description'       => __( 'Filter by event type, e.g. referral, commission_added, payout_paid, registration_accept.', 'woo-coupon-usage' ),
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_key',
								),
								'user_id' => array(
									'description' => __( 'Filter by the acting user ID.', 'woo-coupon-usage' ),
									'type'        => 'integer',
								),
							)
						),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);
		}

		/**
		 * GET /events
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_items( $request ) {

			global $wpdb;

			if ( ! wcusage_api_table_exists( $wpdb->prefix . 'wcusage_activity' ) ) {
				return wcusage_api_error( 'unavailable', __( 'The activity table does not exist on this installation.', 'woo-coupon-usage' ), 501 );
			}

			if ( ! wcusage_get_setting_value( 'wcusage_enable_activity_log', '1' ) ) {
				return wcusage_api_error( 'log_disabled', __( 'The activity log is disabled in the plugin settings, so the events feed is empty.', 'woo-coupon-usage' ), 400 );
			}

			$where  = array( '1=1' );
			$params = array();

			$after = absint( $request['after'] );
			if ( $after ) {
				$where[]  = 'id > %d';
				$params[] = $after;
			}

			$event = (string) $request['event'];
			if ( '' !== $event ) {
				$where[]  = 'event = %s';
				$params[] = $event;
			}

			$user_id = absint( $request['user_id'] );
			if ( $user_id ) {
				$where[]  = 'user_id = %d';
				$params[] = $user_id;
			}

			$where_sql = implode( ' AND ', $where );

			$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_activity WHERE $where_sql";
			$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			// Cursor mode reads forward (oldest first) so consumers can walk
			// the feed; without a cursor, newest first.
			$order = $after ? 'ASC' : 'DESC';

			list( $limit, $offset ) = $this->get_limit_offset( $request );
			if ( $after ) {
				$offset = 0;
			}

			if ( $this->offset_is_out_of_range( $offset, $total ) ) {
				$empty = $this->paginated_response( array(), $total, $request );
				$empty->header( 'X-WCUsage-Last-Event', (int) $after );
				return $empty;
			}

			$items_sql = "SELECT * FROM {$wpdb->prefix}wcusage_activity WHERE $where_sql ORDER BY id $order LIMIT %d OFFSET %d";
			$rows      = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

			$items   = array();
			$last_id = $after;
			foreach ( (array) $rows as $row ) {
				$items[] = array(
					'id'       => (int) $row->id,
					'event'    => sanitize_key( (string) $row->event ),
					'event_id' => (int) $row->event_id,
					'user_id'  => (int) $row->user_id,
					'info'     => sanitize_text_field( (string) $row->info ),
					'date'     => wcusage_api_format_date( (string) $row->date ),
				);
				$last_id = max( $last_id, (int) $row->id );
			}

			$response = $this->paginated_response( $items, $total, $request );
			$response->header( 'X-WCUsage-Last-Event', (int) $last_id );

			return $response;
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
				'title'      => 'wcusage_event',
				'type'       => 'object',
				'properties' => array(
					'id'       => array(
						'description' => __( 'Event ID - use as the "after" cursor.', 'woo-coupon-usage' ),
						'type'        => 'integer',
					),
					'event'    => array(
						'description' => __( 'Event type: referral, registration, registration_accept, payout_request, payout_paid, payout_reversed, commission_added, commission_removed, reward_earned, new_campaign, mla_invite and others.', 'woo-coupon-usage' ),
						'type'        => 'string',
					),
					'event_id' => array(
						'description' => __( 'Related object ID (order, payout, registration, reward - depends on the event type).', 'woo-coupon-usage' ),
						'type'        => 'integer',
					),
					'user_id'  => array(
						'description' => __( 'The acting user (0 for system/guest actions).', 'woo-coupon-usage' ),
						'type'        => 'integer',
					),
					'info'     => array(
						'description' => __( 'Event context (amount, username - depends on the event type).', 'woo-coupon-usage' ),
						'type'        => 'string',
					),
					'date'     => array( 'type' => array( 'string', 'null' ) ),
				),
			);

			return $this->add_additional_fields_schema( $this->schema );
		}
	}
}
