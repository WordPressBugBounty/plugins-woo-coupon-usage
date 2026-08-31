<?php

/**
 * Coupon Affiliates REST API v2 - Reports.
 *
 * Store-wide summary built from the cached per-coupon all-time stats meta
 * plus SQL aggregates over the payouts table. The result is transient-cached
 * for five minutes because it walks every assigned coupon.
 *
 * @package WooCouponUsage\API
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WCUsage_API_Reports_Controller' ) ) {
    /**
     * /reports endpoints.
     */
    class WCUsage_API_Reports_Controller extends WCUsage_API_Controller {
        /**
         * Route base.
         *
         * @var string
         */
        protected $rest_base = 'reports';

        /**
         * Register routes.
         */
        public function register_routes() {
            register_rest_route( $this->namespace, '/' . $this->rest_base . '/summary', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_summary'),
                'permission_callback' => array($this, 'permission_admin_read'),
                'args'                => array(
                    'refresh' => array(
                        'description' => __( 'Bypass the 5-minute report cache.', 'woo-coupon-usage' ),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                    'top'     => array(
                        'description' => __( 'How many top affiliates to include.', 'woo-coupon-usage' ),
                        'type'        => 'integer',
                        'default'     => 10,
                        'minimum'     => 0,
                        'maximum'     => 50,
                    ),
                ),
            )) );
        }

        /**
         * GET /reports/summary
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_summary( $request ) {
            $top = absint( $request['top'] );
            $cache_key = 'wcusage_api_report_summary_' . $top;
            if ( !$request['refresh'] ) {
                $cached = get_transient( $cache_key );
                if ( is_array( $cached ) ) {
                    $cached['cached'] = true;
                    return rest_ensure_response( $cached );
                }
            }
            global $wpdb;
            // All assigned affiliate coupons.
            $coupon_rows = $wpdb->get_results( 
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT pm.post_id, CAST(pm.meta_value AS UNSIGNED) AS uid\n\t\t\t\tFROM {$wpdb->postmeta} pm\n\t\t\t\tINNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n\t\t\t\tWHERE pm.meta_key = 'wcu_select_coupon_user'\n\t\t\t\tAND pm.meta_value REGEXP '^[0-9]+\$' AND pm.meta_value != '0'\n\t\t\t\tAND p.post_type = 'shop_coupon' AND p.post_status = 'publish'"
             );
            $coupon_ids = array_map( 'intval', wp_list_pluck( (array) $coupon_rows, 'post_id' ) );
            $user_ids = array_unique( array_map( 'intval', wp_list_pluck( (array) $coupon_rows, 'uid' ) ) );
            // The raw rows are not needed once the two lists are extracted, and
            // on a large program they are the biggest thing in scope.
            unset($coupon_rows);
            $totals = array(
                'affiliates'                => count( $user_ids ),
                'coupons'                   => count( $coupon_ids ),
                'orders_count'              => 0,
                'total_sales'               => 0.0,
                'total_discount'            => 0.0,
                'total_commission'          => 0.0,
                'unpaid_commission'         => 0.0,
                'pending_payout_commission' => 0.0,
            );
            $by_user = array();
            /**
             * Filter how many coupons this report reads meta for at a time.
             *
             * The figures come from post meta, so the alternative to priming
             * the cache is a query per coupon. Priming all of them at once is
             * the other extreme: one query returning every meta row that every
             * assigned coupon has, with the whole result held in memory, which
             * is what turns a large program into an out-of-memory fatal on an
             * endpoint that is only trying to add numbers up. Each batch is
             * released again once it has been counted, so peak memory follows
             * the batch size rather than the size of the program.
             *
             * @param int $batch_size Coupons per meta-cache prime.
             */
            $batch_size = max( 1, (int) apply_filters( 'wcusage_api_report_batch_size', 200 ) );
            foreach ( array_chunk( $coupon_ids, $batch_size ) as $batch ) {
                update_meta_cache( 'post', $batch );
                foreach ( $batch as $coupon_id ) {
                    $stats = wcusage_api_get_alltime_stats( $coupon_id );
                    $balances = wcusage_api_get_coupon_balances( $coupon_id );
                    $totals['orders_count'] += $stats['orders_count'];
                    $totals['total_sales'] += $stats['total_sales'];
                    $totals['total_discount'] += $stats['total_discount'];
                    $totals['total_commission'] += $stats['total_commission'];
                    $totals['unpaid_commission'] += $balances['unpaid_commission'];
                    $totals['pending_payout_commission'] += $balances['pending_payout_commission'];
                    $uid = absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) );
                    if ( $uid ) {
                        if ( !isset( $by_user[$uid] ) ) {
                            $by_user[$uid] = array(
                                'user_id'          => $uid,
                                'orders_count'     => 0,
                                'total_sales'      => 0.0,
                                'total_commission' => 0.0,
                            );
                        }
                        $by_user[$uid]['orders_count'] += $stats['orders_count'];
                        $by_user[$uid]['total_sales'] += $stats['total_sales'];
                        $by_user[$uid]['total_commission'] += $stats['total_commission'];
                    }
                }
                // Hand the batch's meta back. Nothing later in the request
                // reads it again, and holding every batch would defeat the
                // point of reading them in batches at all.
                //
                // Only worth doing against the default in-process cache, which
                // is this request's own memory. "post_meta" is a persistent
                // group, so where a real object cache is configured the same
                // loop would instead send one delete per coupon over the wire
                // and evict entries the rest of the site is still using - it
                // would cost other requests a rebuild to save memory here.
                if ( !wp_using_ext_object_cache() ) {
                    foreach ( $batch as $coupon_id ) {
                        wp_cache_delete( $coupon_id, 'post_meta' );
                    }
                }
            }
            // Payout aggregates straight from SQL. Payouts are a PRO add-on:
            // the free build has no payouts table to read, so the report leaves
            // the section out altogether rather than reporting four zeros as
            // though the program had simply never paid anybody.
            $payouts = null;
            // Pending registrations count.
            $pending_registrations = 0;
            if ( wcusage_api_table_exists( $wpdb->prefix . 'wcusage_register' ) ) {
                $pending_registrations = (int) $wpdb->get_var( 
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wcusage_register WHERE status = 'pending'"
                 );
            }
            // Top affiliates by all-time commission.
            $top_affiliates = array();
            if ( $top > 0 ) {
                usort( $by_user, function ( $a, $b ) {
                    if ( $a['total_commission'] === $b['total_commission'] ) {
                        return 0;
                    }
                    return ( $a['total_commission'] < $b['total_commission'] ? 1 : -1 );
                } );
                foreach ( array_slice( $by_user, 0, $top ) as $entry ) {
                    $entry['user'] = wcusage_api_prepare_user_summary( $entry['user_id'] );
                    $top_affiliates[] = $entry;
                }
            }
            $summary = array(
                'generated'             => wcusage_api_format_date( current_time( 'mysql' ) ),
                'currency'              => ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
                'totals'                => $totals,
                'pending_registrations' => $pending_registrations,
                'top_affiliates'        => $top_affiliates,
                'cached'                => false,
            );
            if ( null !== $payouts ) {
                $summary['payouts'] = $payouts;
            }
            /**
             * Filter the report summary returned by the REST API.
             *
             * @param array $summary Prepared summary.
             */
            $summary = apply_filters( 'wcusage_api_report_summary', $summary );
            set_transient( $cache_key, $summary, 5 * MINUTE_IN_SECONDS );
            return rest_ensure_response( $summary );
        }

    }

}