<?php
/**
 * Coupon Affiliates REST API v2 - Coupons.
 *
 * Affiliate coupons with balances, stats and referred orders. Reads go
 * through the same central functions the dashboard uses
 * (wcusage_wh_getOrderbyCouponCode, wcusage_get_order_saved_commission),
 * so caps, filters and rounding always match what affiliates see.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_Coupons_Controller' ) ) {

	/**
	 * /coupons endpoints.
	 */
	class WCUsage_API_Coupons_Controller extends WCUsage_API_Controller {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'coupons';

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
								'user_id' => array(
									'description' => __( 'Only coupons assigned to this affiliate user ID.', 'woo-coupon-usage' ),
									'type'        => 'integer',
								),
								'search'  => array(
									'description'       => __( 'Match against the coupon code.', 'woo-coupon-usage' ),
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
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
						'permission_callback' => array( $this, 'permission_coupon_read' ),
						'args'                => array(
							'id' => array(
								'description' => __( 'Coupon ID.', 'woo-coupon-usage' ),
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
				'/' . $this->rest_base . '/(?P<id>[\d]+)/stats',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item_stats' ),
						'permission_callback' => array( $this, 'permission_coupon_read' ),
						'args'                => array(
							'id'      => array(
								'description' => __( 'Coupon ID.', 'woo-coupon-usage' ),
								'type'        => 'integer',
								'required'    => true,
							),
							'from'    => array(
								'description'       => __( 'Start date (Y-m-d). When set, stats are calculated for the range.', 'woo-coupon-usage' ),
								'type'              => 'string',
								'validate_callback' => 'wcusage_api_validate_date_arg',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'to'      => array(
								'description'       => __( 'End date (Y-m-d).', 'woo-coupon-usage' ),
								'type'              => 'string',
								'validate_callback' => 'wcusage_api_validate_date_arg',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'refresh' => array(
								'description' => __( 'Recalculate the all-time figures from the orders instead of using the stored snapshot, and save the result. Ignored when a date range is given, as those are always calculated.', 'woo-coupon-usage' ),
								'type'        => 'boolean',
								'default'     => false,
							),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)/orders',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item_orders' ),
						'permission_callback' => array( $this, 'permission_coupon_read' ),
						'args'                => array_merge(
							$this->get_collection_params(),
							array(
								'id'     => array(
									'description' => __( 'Coupon ID.', 'woo-coupon-usage' ),
									'type'        => 'integer',
									'required'    => true,
								),
								'from'   => array(
									'description'       => __( 'Start date (Y-m-d).', 'woo-coupon-usage' ),
									'type'              => 'string',
									'validate_callback' => 'wcusage_api_validate_date_arg',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'to'     => array(
									'description'       => __( 'End date (Y-m-d).', 'woo-coupon-usage' ),
									'type'              => 'string',
									'validate_callback' => 'wcusage_api_validate_date_arg',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'status' => array(
									'description'       => __( 'Filter by order status slug (without the wc- prefix).', 'woo-coupon-usage' ),
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_key',
								),
							)
						),
					),
				)
			);
		}

		/**
		 * GET /coupons
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_items( $request ) {

			list( $limit, $offset ) = $this->get_limit_offset( $request );

			$args = array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery
			);

			$user_id = absint( $request['user_id'] );
			if ( $user_id ) {
				$args['meta_query'][] = array(
					'key'   => 'wcu_select_coupon_user',
					'value' => $user_id,
				);
			} else {
				$args['meta_query'][] = array(
					'key'     => 'wcu_select_coupon_user',
					'value'   => '',
					'compare' => '!=',
				);
			}

			$search = (string) $request['search'];
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			$query = new WP_Query( $args );

			// "fields => ids" deliberately does not prime the post cache, so
			// without this each coupon below costs a query of its own for the
			// post row - get_post_type() to validate it, then the title - on
			// top of its meta. One query for the whole page instead.
			if ( ! empty( $query->posts ) ) {
				_prime_post_caches( $query->posts, false, true );
			}

			$items = array();
			foreach ( $query->posts as $coupon_id ) {
				$summary = wcusage_api_prepare_coupon_summary( (int) $coupon_id, false );
				if ( $summary ) {
					$items[] = $summary;
				}
			}

			return $this->paginated_response( $items, (int) $query->found_posts, $request );
		}

		/**
		 * GET /coupons/{id}
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item( $request ) {

			$coupon_id = wcusage_api_get_valid_coupon_id( $request['id'] );
			$item      = wcusage_api_prepare_coupon_summary( $coupon_id, true );

			if ( ! $item ) {
				return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
			}

			// Commission settings + referral URL, resolved the same way the dashboard does.
			$info = function_exists( 'wcusage_get_coupon_info_by_id' ) ? wcusage_get_coupon_info_by_id( $coupon_id ) : array();

			$item['commission']   = array(
				'percent'           => isset( $info[0] ) ? (float) $info[0] : 0.0,
				'percent_override'  => (string) get_post_meta( $coupon_id, 'wcu_text_coupon_commission', true ),
				'fixed_per_order'   => (string) get_post_meta( $coupon_id, 'wcu_text_coupon_commission_fixed_order', true ),
				'fixed_per_product' => (string) get_post_meta( $coupon_id, 'wcu_text_coupon_commission_fixed_product', true ),
			);
			$item['referral_url'] = isset( $info[4] ) ? esc_url_raw( $info[4] ) : '';
			$item['user']         = wcusage_api_prepare_user_summary( $item['user_id'] );

			return rest_ensure_response( $item );
		}

		/**
		 * GET /coupons/{id}/stats
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item_stats( $request ) {

			$coupon_id = wcusage_api_get_valid_coupon_id( $request['id'] );
			$from      = (string) $request['from'];
			$to        = (string) $request['to'];

			// Fail closed: an invalid ID would otherwise resolve to an empty
			// coupon code, which matches every order with an empty referrer.
			if ( ! $coupon_id ) {
				return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
			}

			$code    = get_post_field( 'post_title', $coupon_id, 'raw' );
			$refresh = (bool) $request['refresh'];
			$source  = 'live';

			// Recalculating saves the result, so refresh=true is a write wearing
			// a GET's clothes. Hold it to the write scope: a read-only key still
			// gets figures, from the stored snapshot, and "source" says so.
			if ( $refresh && ! wcusage_api_current_user_has_scope( 'write' ) ) {
				return wcusage_api_error( 'insufficient_scope', __( 'Refreshing stats updates stored data and needs the "write" scope.', 'woo-coupon-usage' ), 403 );
			}

			// Another published coupon answers to the same code. Everything
			// the stats layer does is keyed by code, so figures for this
			// coupon are not reliably addressable.
			$ambiguous = function_exists( 'wcusage_coupon_code_is_ambiguous' ) && wcusage_coupon_code_is_ambiguous( $coupon_id );

			if ( $from || $to ) {

				// A date range is always calculated from the orders themselves,
				// which is the same unbounded read as a rebuild - and unlike a
				// rebuild it never settles, because a caller can always name a
				// range nobody has asked for yet. Each range is cached briefly,
				// and calculating a new one shares the per-coupon budget below.
				$range_key = 'wcusage_api_cstats_' . md5( $coupon_id . '|' . $from . '|' . $to );
				$stats     = get_transient( $range_key );

				if ( is_array( $stats ) ) {

					$source = 'cache';

				} else {

					if ( ! wcusage_api_throttle( 'stats_' . $coupon_id, MINUTE_IN_SECONDS ) ) {
						return wcusage_api_error(
							'throttled',
							__( 'Stats for this coupon were calculated very recently. Please try again in a moment.', 'woo-coupon-usage' ),
							429,
							array( 'retry_after' => 60 )
						);
					}

					$data = wcusage_wh_getOrderbyCouponCode( $code, $from, wcusage_api_end_date( $to ), '', 1, 0 );

					$stats = array(
						'orders_count'     => isset( $data['total_count'] ) ? (int) $data['total_count'] : 0,
						'total_sales'      => isset( $data['total_orders'] ) ? (float) $data['total_orders'] : 0.0,
						'total_discount'   => isset( $data['full_discount'] ) ? (float) $data['full_discount'] : 0.0,
						'total_shipping'   => isset( $data['total_shipping'] ) ? (float) $data['total_shipping'] : 0.0,
						'total_commission' => isset( $data['total_commission'] ) ? (float) $data['total_commission'] : 0.0,
						'status_counts'    => isset( $data['status_counts'] ) && is_array( $data['status_counts'] ) ? $data['status_counts'] : new stdClass(),
					);

					set_transient( $range_key, $stats, MINUTE_IN_SECONDS );
				}
			} else {

				$cached = wcusage_api_get_alltime_stats( $coupon_id );

				// The all-time figures come from the same stored snapshot the
				// affiliate dashboard shows, which is only rebuilt when stats
				// are refreshed - so it can lag reality in either direction.
				// Recalculate when asked to, or when nothing has been stored
				// yet, and persist the result so it does not have to be
				// rebuilt on every subsequent request.
				$needs_build = ( 0 === $cached['orders_count'] && 0.0 === $cached['total_sales'] );

				// Saving the snapshot is a write, so only a caller allowed to
				// write performs one. An explicit refresh=true was already
				// refused above without the scope; this covers the implicit
				// rebuild, which would otherwise let a read-only key update
				// stored data as a side effect of reading it. Such a caller
				// still gets calculated figures - they are simply not saved.
				//
				// The snapshot is also written by coupon CODE, not ID, so when
				// two coupons share a code saving here would overwrite the
				// other coupon's stats. Calculate without saving in that case
				// too, and tell the caller why the figures will not stick.
				$can_persist = ! $ambiguous && wcusage_api_current_user_has_scope( 'write' );

				$live_key = 'wcusage_api_cstats_all_' . $coupon_id;
				$stats    = null;

				if ( ! $refresh && ! $needs_build ) {

					// The ordinary path: answer from the stored snapshot
					// without touching anything else.
					$stats  = $cached;
					$source = 'cache';

				} else {

					// A calculated result is also kept in a short-lived cache
					// of its own, because it cannot always reach the snapshot:
					// a read-only caller never saves, and the stats function
					// declines to save for a coupon carrying its own stats
					// start date. Without this, "needs building" would stay
					// true for such a coupon forever and every request would
					// rescan its entire order history. Only read here, so the
					// path above stays a single meta lookup.
					$live = $refresh ? false : get_transient( $live_key );

					if ( is_array( $live ) ) {

						$stats  = $live;
						$source = 'cache';

					} elseif ( ! wcusage_api_throttle( 'stats_' . $coupon_id, MINUTE_IN_SECONDS ) ) {

						// A rebuild reads every order this coupon has ever
						// referred, and the endpoint is reachable by the
						// affiliate who owns it, so it is capped at one per
						// coupon per minute. Callers arriving inside that
						// window get the stored snapshot; "source" reports that
						// as "throttled" rather than passing it off as a fresh
						// calculation.
						$stats  = $cached;
						$source = 'throttled';
					}
				}

				if ( null === $stats ) {

					$data = wcusage_wh_getOrderbyCouponCode( $code, '', wcusage_api_end_date( '' ), '', 1, $can_persist ? 1 : 0 );

					// wcusage_wh_getOrderbyCouponCode() substitutes the
					// coupon's own stats start date for the empty one it was
					// given and then declines to save what it now reads as a
					// ranged result, so the snapshot is only really written for
					// coupons without that date. Stamp the refresh time on
					// exactly the same terms: nothing else updates it here, so
					// without this the response reports figures calculated a
					// moment ago next to whenever the dashboard last rebuilt
					// them - often nothing at all.
					if ( $can_persist && ! get_post_meta( $coupon_id, 'wcu_text_coupon_start_date', true ) ) {
						update_post_meta( $coupon_id, 'wcu_last_refreshed', time() );
					}

					$stats = array(
						'orders_count'     => isset( $data['total_count'] ) ? (int) $data['total_count'] : 0,
						'total_sales'      => isset( $data['total_orders'] ) ? (float) $data['total_orders'] : 0.0,
						'total_discount'   => isset( $data['full_discount'] ) ? (float) $data['full_discount'] : 0.0,
						'total_shipping'   => isset( $data['total_shipping'] ) ? (float) $data['total_shipping'] : 0.0,
						'total_commission' => isset( $data['total_commission'] ) ? (float) $data['total_commission'] : 0.0,
						'status_counts'    => isset( $data['status_counts'] ) && is_array( $data['status_counts'] ) ? $data['status_counts'] : new stdClass(),
						'last_refreshed'   => wcusage_api_get_alltime_stats( $coupon_id )['last_refreshed'],
					);

					set_transient( $live_key, $stats, MINUTE_IN_SECONDS );
				}
			}

			// The branches above build the same figures from different sources
			// and not every source carries every field: the stored snapshot
			// holds no per-status breakdown, and a date range is always
			// calculated so there is no "last refreshed" moment to report. Fill
			// both in rather than letting the response shape depend on which
			// path answered - a client should not have to test for the keys.
			if ( ! isset( $stats['status_counts'] ) ) {
				$stats['status_counts'] = new stdClass();
			}
			if ( ! array_key_exists( 'last_refreshed', $stats ) ) {
				$stats['last_refreshed'] = null;
			}

			return rest_ensure_response(
				array_merge(
					array(
						'coupon_id'      => $coupon_id,
						'code'           => sanitize_text_field( $code ),
						'from'           => $from ? $from : null,
						'to'             => $to ? $to : null,
						// "cache" means these all-time figures come from the
						// stored snapshot and may lag; request refresh=true
						// (or use a date range) for calculated figures.
						'source'         => $source,
						// When true, another coupon shares this code: all-time
						// figures cannot be stored against this coupon and may
						// reflect the other one.
						'code_ambiguous' => $ambiguous,
					),
					$stats,
					wcusage_api_get_coupon_balances( $coupon_id )
				)
			);
		}

		/**
		 * GET /coupons/{id}/orders
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item_orders( $request ) {

			$coupon_id = wcusage_api_get_valid_coupon_id( $request['id'] );
			$from      = (string) $request['from'];
			$to        = (string) $request['to'];
			$status    = (string) $request['status'];

			// Fail closed: an invalid ID would otherwise resolve to an empty
			// coupon code, which matches every order with an empty referrer.
			if ( ! $coupon_id ) {
				return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
			}

			$code = get_post_field( 'post_title', $coupon_id, 'raw' );

			/**
			 * Filter how many referred orders one request may load.
			 *
			 * The order query only applies a SQL LIMIT when it
			 * is given a row count, so leaving this empty materialises every
			 * order the coupon has ever referred and then cuts one page out of
			 * it in PHP. That is unbounded memory for a bounded response, on an
			 * endpoint the owning affiliate can call, so ask for a window
			 * instead. The row count is the newest N orders.
			 *
			 * @param int $max_rows Orders considered per request.
			 */
			$max_rows = max( 1, (int) apply_filters( 'wcusage_api_max_order_rows', 5000 ) );

			// Reading the referred orders scans every order this coupon has ever
			// been used on - the same unbounded read /stats performs - while the
			// response is only ever one page of it. Without a cache, walking the
			// pages repeats that scan once per page, and the endpoint is
			// reachable by the affiliate who owns the coupon. So the prepared
			// list is cached whole, per coupon, range and status: pagination
			// then costs nothing, and calculating a combination that is not
			// already cached is capped at one per coupon per minute the way the
			// ranged branch of /stats is.
			$cache_key = 'wcusage_api_corders_' . md5( $coupon_id . '|' . $from . '|' . $to . '|' . $status . '|' . $max_rows );
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) && isset( $cached['rows'], $cached['truncated'] ) ) {

				$order_rows = (array) $cached['rows'];
				$truncated  = (bool) $cached['truncated'];

			} else {

				if ( ! wcusage_api_throttle( 'orders_' . $coupon_id, MINUTE_IN_SECONDS ) ) {
					return wcusage_api_error(
						'throttled',
						__( 'Orders for this coupon were read very recently. Please try again in a moment.', 'woo-coupon-usage' ),
						429,
						array( 'retry_after' => 60 )
					);
				}

				$data = wcusage_wh_getOrderbyCouponCode( $code, $from, wcusage_api_end_date( $to ), $max_rows, 1, 0, false, $status );

				$raw_rows = ( isset( $data['orders'] ) && is_array( $data['orders'] ) ) ? $data['orders'] : array();

				// Counted before the filtering below, because the cap applies to
				// what the query returned, not to what survives.
				$truncated = ( count( $raw_rows ) >= $max_rows );

				// Per-order totals live in the numeric keys of the same array.
				$totals_by_order = array();
				foreach ( $data as $key => $row ) {
					if ( is_int( $key ) && is_array( $row ) && isset( $row['order_id'] ) ) {
						$totals_by_order[ (int) $row['order_id'] ] = $row;
					}
				}

				// "orders" is the raw SQL result: it still holds orders that used
				// this coupon but were later reassigned to another affiliate, and
				// those carry the other affiliate's commission. Only the rows that
				// also produced a totals entry actually counted towards this
				// coupon, so restrict the list to those - it keeps /orders
				// consistent with /stats as well.
				//
				// Only the fields the response needs are carried forward, so what
				// is cached is a flat list of small arrays rather than the whole
				// structure the stats layer builds. Commission is deliberately
				// left out of it: it is a meta read per row, and only the rows on
				// the requested page need one.
				$order_rows = array();
				foreach ( $raw_rows as $row ) {

					$order_id = isset( $row->order_id ) ? (int) $row->order_id : 0;
					if ( ! $order_id || ! isset( $totals_by_order[ $order_id ] ) ) {
						continue;
					}

					$totals = $totals_by_order[ $order_id ];

					$order_rows[] = array(
						'order_id' => $order_id,
						'date'     => isset( $row->order_date ) ? wcusage_api_format_date( $row->order_date ) : null,
						'status'   => isset( $row->order_status ) ? str_replace( 'wc-', '', $row->order_status ) : '',
						'total'    => isset( $totals['total'] ) ? (float) $totals['total'] : 0.0,
						'discount' => isset( $totals['total_discount'] ) ? (float) $totals['total_discount'] : 0.0,
					);
				}

				// The underlying query returns the newest orders and then reverses
				// them, so put them back: newest first matches every other
				// collection in this API, and it lines the row cap up with the
				// oldest orders rather than with the first page asked for.
				$order_rows = array_reverse( $order_rows );

				// The same window the throttle above uses, so a caller polling one
				// range is always served from the cache and never sees a 429 -
				// only one varying the range or status more than once a minute
				// asks for a scan that has not been done.
				set_transient(
					$cache_key,
					array(
						'rows'      => $order_rows,
						'truncated' => $truncated,
					),
					MINUTE_IN_SECONDS
				);
			}

			$total                  = count( $order_rows );
			list( $limit, $offset ) = $this->get_limit_offset( $request );
			$page_rows              = array_slice( $order_rows, $offset, $limit );

			$items = array();
			foreach ( $page_rows as $row ) {

				$order_id = (int) $row['order_id'];

				$item = array(
					'order_id'   => $order_id,
					'date'       => $row['date'],
					'status'     => $row['status'],
					'total'      => (float) $row['total'],
					'discount'   => (float) $row['discount'],
					'commission' => function_exists( 'wcusage_get_order_saved_commission' ) ? (float) wcusage_get_order_saved_commission( $order_id ) : 0.0,
				);

				/**
				 * Filter a referred-order row returned by the REST API.
				 *
				 * No customer PII is included by default; use this filter to
				 * add fields for trusted integrations.
				 *
				 * @param array $item      Prepared row.
				 * @param int   $order_id  Order ID.
				 * @param int   $coupon_id Coupon ID.
				 */
				$items[] = apply_filters( 'wcusage_api_order_item', $item, $order_id, $coupon_id );
			}

			$response = $this->paginated_response( $items, $total, $request );

			// Say so rather than letting a caller read a capped total as the
			// real one and conclude the older orders do not exist.
			if ( $truncated ) {
				$response->header( 'X-WCUsage-Truncated', '1' );
			}

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
				'title'      => 'wcusage_coupon',
				'type'       => 'object',
				'properties' => array(
					'id'                        => array(
						'description' => __( 'Coupon ID.', 'woo-coupon-usage' ),
						'type'        => 'integer',
					),
					'code'                      => array(
						'description' => __( 'Coupon code.', 'woo-coupon-usage' ),
						'type'        => 'string',
					),
					'user_id'                   => array(
						'description' => __( 'Assigned affiliate user ID (0 when unassigned).', 'woo-coupon-usage' ),
						'type'        => 'integer',
					),
					'unpaid_commission'         => array(
						'description' => __( 'Current unpaid commission balance.', 'woo-coupon-usage' ),
						'type'        => 'number',
					),
					'pending_order_commission'  => array(
						'description' => __( 'Commission for orders still in the pending period.', 'woo-coupon-usage' ),
						'type'        => 'number',
					),
					'pending_payout_commission' => array(
						'description' => __( 'Commission tied up in pending payouts.', 'woo-coupon-usage' ),
						'type'        => 'number',
					),
					'stats'                     => array(
						'description' => __( 'Cached all-time stats.', 'woo-coupon-usage' ),
						'type'        => 'object',
					),
					'commission'                => array(
						'description' => __( 'Commission rate settings (detailed view only).', 'woo-coupon-usage' ),
						'type'        => 'object',
					),
					'referral_url'              => array(
						'description' => __( 'Affiliate dashboard referral URL (detailed view only).', 'woo-coupon-usage' ),
						'type'        => 'string',
					),
				),
			);

			return $this->add_additional_fields_schema( $this->schema );
		}
	}
}
