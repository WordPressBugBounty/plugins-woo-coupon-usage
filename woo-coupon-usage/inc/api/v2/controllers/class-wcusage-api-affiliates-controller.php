<?php

/**
 * Coupon Affiliates REST API v2 - Affiliates.
 *
 * An "affiliate" is a WP user with at least one shop_coupon assigned via the
 * wcu_select_coupon_user post meta. There is no affiliate table; this
 * controller derives the entity the same way the admin list table does.
 *
 * @package WooCouponUsage\API
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WCUsage_API_Affiliates_Controller' ) ) {
    /**
     * /affiliates endpoints.
     */
    class WCUsage_API_Affiliates_Controller extends WCUsage_API_Controller {
        /**
         * Route base.
         *
         * @var string
         */
        protected $rest_base = 'affiliates';

        /**
         * Register routes.
         */
        public function register_routes() {
            register_rest_route( $this->namespace, '/' . $this->rest_base, array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'permission_admin_read'),
                'args'                => array_merge( $this->get_collection_params(), array(
                    'search' => array(
                        'description'       => __( 'Match against user login, email or display name.', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ) ),
            ), 'schema' => array($this, 'get_public_item_schema')) );
            register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\\d]+)', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item'),
                'permission_callback' => array($this, 'permission_self_or_admin'),
                'args'                => array(
                    'id' => array(
                        'description' => __( 'Affiliate user ID.', 'woo-coupon-usage' ),
                        'type'        => 'integer',
                        'required'    => true,
                    ),
                ),
            ), 'schema' => array($this, 'get_public_item_schema')) );
            register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\\d]+)/stats', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_item_stats'),
                'permission_callback' => array($this, 'permission_self_or_admin'),
                'args'                => array(
                    'id'   => array(
                        'description' => __( 'Affiliate user ID.', 'woo-coupon-usage' ),
                        'type'        => 'integer',
                        'required'    => true,
                    ),
                    'from' => array(
                        'description'       => __( 'Start date (Y-m-d). When set, stats are recalculated for the range instead of using the all-time cache.', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'validate_callback' => 'wcusage_api_validate_date_arg',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'to'   => array(
                        'description'       => __( 'End date (Y-m-d).', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'validate_callback' => 'wcusage_api_validate_date_arg',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )) );
        }

        /**
         * Permission: the affiliate themselves, or a plugin admin.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return true|WP_Error
         */
        public function permission_self_or_admin( $request ) {
            if ( !is_user_logged_in() ) {
                return wcusage_api_auth_required_error();
            }
            $scope_check = wcusage_api_require_scope( 'read' );
            if ( is_wp_error( $scope_check ) ) {
                return $scope_check;
            }
            $user_id = absint( $request['id'] );
            if ( wcusage_api_is_admin_user() || get_current_user_id() === $user_id ) {
                return true;
            }
            return wcusage_api_auth_required_error();
        }

        /**
         * GET /affiliates
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_items( $request ) {
            global $wpdb;
            list( $limit, $offset ) = $this->get_limit_offset( $request );
            $search = (string) $request['search'];
            $join = "FROM {$wpdb->postmeta} pm\r\n\t\t\t\tINNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id";
            $where = "WHERE pm.meta_key = 'wcu_select_coupon_user'\r\n\t\t\t\tAND pm.meta_value REGEXP '^[0-9]+\$' AND pm.meta_value != '0'\r\n\t\t\t\tAND p.post_type = 'shop_coupon' AND p.post_status = 'publish'";
            $params = array();
            if ( '' !== $search ) {
                $join .= " INNER JOIN {$wpdb->users} u ON u.ID = CAST(pm.meta_value AS UNSIGNED)";
                $where .= ' AND ( u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s )';
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $params = array($like, $like, $like);
            }
            $count_sql = "SELECT COUNT(DISTINCT pm.meta_value) {$join} {$where}";
            $total = (int) $wpdb->get_var( ( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $items_sql = "SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS uid {$join} {$where} ORDER BY uid ASC LIMIT %d OFFSET %d";
            $item_params = array_merge( $params, array($limit, $offset) );
            $user_ids = $wpdb->get_col( $wpdb->prepare( $items_sql, $item_params ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            // One query for every user on the page, rather than the query per
            // user that get_userdata() would otherwise run inside
            // wcusage_api_prepare_user_summary().
            if ( !empty( $user_ids ) ) {
                cache_users( array_map( 'intval', $user_ids ) );
            }
            $items = array();
            foreach ( $user_ids as $user_id ) {
                $item = $this->prepare_affiliate( (int) $user_id, false );
                if ( $item ) {
                    $items[] = $item;
                }
            }
            return $this->paginated_response( $items, $total, $request );
        }

        /**
         * GET /affiliates/{id}
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_item( $request ) {
            $item = $this->prepare_affiliate( absint( $request['id'] ), true );
            if ( !$item ) {
                return wcusage_api_error( 'not_found', __( 'Affiliate not found.', 'woo-coupon-usage' ), 404 );
            }
            return rest_ensure_response( $item );
        }

        /**
         * GET /affiliates/{id}/stats
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_item_stats( $request ) {
            $user_id = absint( $request['id'] );
            $from = (string) $request['from'];
            $to = (string) $request['to'];
            if ( !get_userdata( $user_id ) ) {
                return wcusage_api_error( 'not_found', __( 'Affiliate not found.', 'woo-coupon-usage' ), 404 );
            }
            // With a date range this recalculates from the orders for every one
            // of the affiliate's coupons, so one call is as expensive as their
            // whole order history. Cache each range briefly, and cap how often a
            // range that is not already cached may be calculated - otherwise
            // walking the dates is a cheap way to keep the database busy.
            $cache_key = 'wcusage_api_astats_' . md5( $user_id . '|' . $from . '|' . $to );
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return rest_ensure_response( $cached );
            }
            if ( ($from || $to) && !wcusage_api_throttle( 'astats_' . $user_id, MINUTE_IN_SECONDS ) ) {
                return wcusage_api_error(
                    'throttled',
                    __( 'Stats for this affiliate were calculated very recently. Please try again in a moment.', 'woo-coupon-usage' ),
                    429,
                    array(
                        'retry_after' => 60,
                    )
                );
            }
            $coupon_ids = ( function_exists( 'wcusage_get_users_coupons_ids' ) ? wcusage_get_users_coupons_ids( $user_id ) : array() );
            // The IDs come from a cached lookup, so nothing has primed the posts
            // behind them and each coupon below reads both its post row and its
            // meta.
            if ( !empty( $coupon_ids ) ) {
                _prime_post_caches( $coupon_ids, false, true );
            }
            $totals = array(
                'orders_count'              => 0,
                'total_sales'               => 0.0,
                'total_discount'            => 0.0,
                'total_commission'          => 0.0,
                'unpaid_commission'         => 0.0,
                'pending_payout_commission' => 0.0,
            );
            $coupons = array();
            foreach ( $coupon_ids as $coupon_id ) {
                $coupon_id = wcusage_api_get_valid_coupon_id( $coupon_id );
                if ( !$coupon_id ) {
                    continue;
                }
                if ( $from || $to ) {
                    $code = get_post_field( 'post_title', $coupon_id, 'raw' );
                    $data = wcusage_wh_getOrderbyCouponCode(
                        $code,
                        $from,
                        wcusage_api_end_date( $to ),
                        '',
                        1,
                        0
                    );
                    $stats = array(
                        'orders_count'     => ( isset( $data['total_count'] ) ? (int) $data['total_count'] : 0 ),
                        'total_sales'      => ( isset( $data['total_orders'] ) ? (float) $data['total_orders'] : 0.0 ),
                        'total_discount'   => ( isset( $data['full_discount'] ) ? (float) $data['full_discount'] : 0.0 ),
                        'total_commission' => ( isset( $data['total_commission'] ) ? (float) $data['total_commission'] : 0.0 ),
                    );
                } else {
                    $alltime = wcusage_api_get_alltime_stats( $coupon_id );
                    $stats = array(
                        'orders_count'     => $alltime['orders_count'],
                        'total_sales'      => $alltime['total_sales'],
                        'total_discount'   => $alltime['total_discount'],
                        'total_commission' => $alltime['total_commission'],
                    );
                }
                $balances = wcusage_api_get_coupon_balances( $coupon_id );
                $totals['orders_count'] += $stats['orders_count'];
                $totals['total_sales'] += $stats['total_sales'];
                $totals['total_discount'] += $stats['total_discount'];
                $totals['total_commission'] += $stats['total_commission'];
                $totals['unpaid_commission'] += $balances['unpaid_commission'];
                $totals['pending_payout_commission'] += $balances['pending_payout_commission'];
                $coupons[] = array_merge( array(
                    'id'   => $coupon_id,
                    'code' => sanitize_text_field( get_post_field( 'post_title', $coupon_id, 'raw' ) ),
                ), $stats );
            }
            $payload = array(
                'user_id' => $user_id,
                'from'    => ( $from ? $from : null ),
                'to'      => ( $to ? $to : null ),
                'totals'  => $totals,
                'coupons' => $coupons,
            );
            set_transient( $cache_key, $payload, MINUTE_IN_SECONDS );
            return rest_ensure_response( $payload );
        }

        /**
         * Build an affiliate representation.
         *
         * @param int  $user_id  User ID.
         * @param bool $detailed Include profile fields and per-coupon stats.
         *
         * @return array|null
         */
        protected function prepare_affiliate( $user_id, $detailed = false ) {
            $user = wcusage_api_prepare_user_summary( $user_id );
            if ( !$user ) {
                return null;
            }
            $coupon_ids = ( function_exists( 'wcusage_get_users_coupons_ids' ) ? wcusage_get_users_coupons_ids( $user_id ) : array() );
            // As above: one query for this affiliate's coupons instead of two
            // per coupon. On the collection endpoint this runs once per
            // affiliate on the page, which is what keeps /affiliates from
            // costing a few hundred queries.
            if ( !empty( $coupon_ids ) ) {
                _prime_post_caches( $coupon_ids, false, true );
            }
            $coupons = array();
            $unpaid = 0.0;
            $pending_payouts = 0.0;
            foreach ( $coupon_ids as $coupon_id ) {
                $summary = wcusage_api_prepare_coupon_summary( $coupon_id, $detailed );
                if ( $summary ) {
                    $coupons[] = $summary;
                    $unpaid += $summary['unpaid_commission'];
                    $pending_payouts += $summary['pending_payout_commission'];
                }
            }
            $data = array(
                'user'                      => $user,
                'coupons'                   => $coupons,
                'unpaid_commission'         => $unpaid,
                'pending_payout_commission' => $pending_payouts,
            );
            if ( $detailed ) {
                $wp_user = get_userdata( $user_id );
                $data['date_registered'] = ( $wp_user ? wcusage_api_format_date( $wp_user->user_registered ) : null );
                $data['profile'] = array(
                    'phone'    => sanitize_text_field( (string) get_user_meta( $user_id, 'wcu_phone', true ) ),
                    'website'  => esc_url_raw( (string) get_user_meta( $user_id, 'wcu_website', true ) ),
                    'promote'  => sanitize_text_field( (string) get_user_meta( $user_id, 'wcu_promote', true ) ),
                    'referrer' => sanitize_text_field( (string) get_user_meta( $user_id, 'wcu_referrer', true ) ),
                );
                $groups = array();
                if ( $wp_user && function_exists( 'wcusage_is_affiliate_group_role' ) ) {
                    foreach ( (array) $wp_user->roles as $role ) {
                        if ( wcusage_is_affiliate_group_role( $role ) ) {
                            $groups[] = $role;
                        }
                    }
                }
                $data['groups'] = $groups;
            }
            /**
             * Filter the affiliate representation returned by the REST API.
             *
             * @param array $data     Prepared data.
             * @param int   $user_id  User ID.
             * @param bool  $detailed Detailed view.
             */
            return apply_filters(
                'wcusage_api_prepare_affiliate',
                $data,
                $user_id,
                $detailed
            );
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
                'title'      => 'wcusage_affiliate',
                'type'       => 'object',
                'properties' => array(
                    'user'                      => array(
                        'description' => __( 'The WP user this affiliate is.', 'woo-coupon-usage' ),
                        'type'        => 'object',
                    ),
                    'coupons'                   => array(
                        'description' => __( 'Coupons assigned to this affiliate, with balances.', 'woo-coupon-usage' ),
                        'type'        => 'array',
                    ),
                    'unpaid_commission'         => array(
                        'description' => __( 'Total unpaid commission across all coupons.', 'woo-coupon-usage' ),
                        'type'        => 'number',
                    ),
                    'pending_payout_commission' => array(
                        'description' => __( 'Commission tied up in pending payouts.', 'woo-coupon-usage' ),
                        'type'        => 'number',
                    ),
                    'profile'                   => array(
                        'description' => __( 'Registration profile fields (detailed view only).', 'woo-coupon-usage' ),
                        'type'        => 'object',
                    ),
                    'groups'                    => array(
                        'description' => __( 'Affiliate group roles (detailed view only).', 'woo-coupon-usage' ),
                        'type'        => 'array',
                    ),
                ),
            );
            return $this->add_additional_fields_schema( $this->schema );
        }

    }

}