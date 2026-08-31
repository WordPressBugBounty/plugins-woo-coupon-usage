<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * The order statuses that count towards a coupon's referrals.
 *
 * Shared by wcusage_wh_getOrderbyCouponCode() and
 * wcusage_get_coupon_referral_order_rows() so the two cannot drift apart.
 *
 * @param string $status_filter_key A single 'wc-' prefixed status to narrow to, or ''.
 *
 * @return array Status key => label. Empty when the filter names a status the settings exclude.
 *
 */
if( !function_exists( 'wcusage_get_coupon_order_statuses' ) ) {
  function wcusage_get_coupon_order_statuses( $status_filter_key = '' ) {

    $wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');
    if(!$wcusage_field_order_type_custom) {
      // Match the statuses that check_status_show() allows to avoid
      // fetching orders that will be filtered out in rendering.
      $wcusage_field_order_type = wcusage_get_setting_value('wcusage_field_order_type', '');
      if($wcusage_field_order_type == 'completed') {
        $statuses = array('wc-completed' => 'Completed');
      } else {
        $statuses = array('wc-completed' => 'Completed', 'wc-processing' => 'Processing');
      }
    } else {
      $statuses = $wcusage_field_order_type_custom;
    }

    if ($status_filter_key) {
      if (isset($statuses[$status_filter_key])) {
        $statuses = array($status_filter_key => $statuses[$status_filter_key]);
      } else {
        $statuses = array();
      }
    }

    return $statuses;
  }
}

/**
 * Apply a coupon's own start date as a floor to the requested start date.
 *
 * A coupon with wcu_text_coupon_start_date set does not count orders placed
 * before it, whatever range the caller asked for.
 *
 * @param int $coupon_id
 * @param string $start_date Already converted to GMT by the caller.
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_get_coupon_order_start_date' ) ) {
  function wcusage_get_coupon_order_start_date( $coupon_id, $start_date ) {

    $wcu_text_coupon_start_date = get_post_meta( $coupon_id, 'wcu_text_coupon_start_date', true );

    if($wcu_text_coupon_start_date) {
      if( strtotime($start_date) < strtotime($wcu_text_coupon_start_date) || !$start_date ) {
        $start_date = $wcu_text_coupon_start_date;
      }
    }
    if(!$start_date) { $start_date = "0001-01-01"; }

    return $start_date;
  }
}

/**
 * List the orders that count as referrals for a coupon, without calculating anything.
 *
 * wcusage_wh_getOrderbyCouponCode() answers the same question, but it recalculates
 * every order's totals, discount, commission and product tally on the way - it has to,
 * because its callers want those figures. A caller that only needs to know WHICH orders
 * the coupon has (a paginated list, a count) was paying the whole calculation for every
 * order the affiliate has ever had, then throwing all of it away. On an affiliate with
 * 553 orders that is ~2 s and 116 queries against 0.08 s and 6 queries here.
 *
 * The per-order rules the calculation loop applies are all expressible in SQL and are
 * applied here, so the row set matches what that loop keeps, order for order:
 *
 *  - the configured order statuses, and the coupon's own start date as a floor;
 *  - an order attributed to a DIFFERENT coupon through lifetime_affiliate_coupon_referrer
 *    or wcusage_referrer_coupon is not this coupon's referral. The lifetime key wins when
 *    both are set. This is the same test as wcusage_check_lifetime_or_coupon().
 *
 * Note there is deliberately NO zero-total filter here, even though the loop reads
 * `if(!$theorderstatus || !$theordertotal) { continue; }`. WC_Order::get_total() returns a
 * formatted string, so a free order gives "0.00", which is truthy in PHP - that guard never
 * fires for a real order. Adding `total_amount <> 0` here looks like it matches the loop and
 * actually hides every 100%-discount order from the affiliate's referrals.
 *
 * The one rule left to the caller is wcusage_check_if_renewal_allowed(), which depends on
 * the Subscriptions plugin rather than on anything in the orders table. It short-circuits
 * and memoises unless a renewal limit is set, so running it per row is cheap.
 *
 * One deliberate difference from wcusage_wh_getOrderbyCouponCode(): an empty coupon code
 * returns nothing here. That function runs the query anyway and matches orders whose
 * attribution meta is an empty string, which are not referrals of anything. Coupons with
 * an empty post_title do exist, so callers should skip them either way.
 *
 * @param string $coupon_code
 * @param date $start_date
 * @param date $end_date
 * @param int $numberoforders
 * @param string $status_filter
 *
 * @return array Rows of order_id, order_date and order_status, newest first.
 *
 */
if( !function_exists( 'wcusage_get_coupon_referral_order_rows' ) ) {
  function wcusage_get_coupon_referral_order_rows( $coupon_code, $start_date, $end_date, $numberoforders = '', $status_filter = '' ) {

    global $wpdb;

    $coupon_code = strtolower( sanitize_text_field( $coupon_code ) );
    if ( ! $coupon_code ) {
      return array();
    }

    $status_filter = sanitize_key( str_replace( 'wc-', '', $status_filter ) );
    $status_filter_key = $status_filter ? 'wc-' . $status_filter : '';

    $statuses = wcusage_get_coupon_order_statuses( $status_filter_key );
    if ( empty( $statuses ) ) {
      return array();
    }

    $start_date = wcusage_convert_date_to_gmt( sanitize_text_field( $start_date ), 0 );
    $end_date = wcusage_convert_date_to_gmt( sanitize_text_field( $end_date ), 1 );

    $couponinfo = wcusage_get_coupon_info( $coupon_code );
    $start_date = wcusage_get_coupon_order_start_date( $couponinfo[2], $start_date );

    $wcusage_field_order_sort = wcusage_get_setting_value( 'wcusage_field_order_sort', '' );

    // Custom Orders Table or Posts Table
    if (class_exists(OrderUtil::class) && method_exists(OrderUtil::class, 'custom_orders_table_usage_is_enabled') && OrderUtil::custom_orders_table_usage_is_enabled()) {
      $id = "id";
      $posts = "wc_orders";
      $postmeta = "wc_orders_meta";
      $post_date = "date_created_gmt";
      $post_status = "status";
      $post_id = "order_id";
    } else {
      $id = "ID";
      $posts = "posts";
      $postmeta = "postmeta";
      $post_date = "post_date_gmt";
      $post_status = "post_status";
      $post_id = "post_id";
    }

    // Same matched-orders derived table as wcusage_wh_getOrderbyCouponCode() - see the
    // note there for why this is a derived table rather than a pair of LEFT JOINs.
    $matched_orders = $wpdb->prepare(
      "SELECT woi.order_id AS order_id
      FROM {$wpdb->prefix}woocommerce_order_items AS woi
      WHERE woi.order_item_type = 'coupon' AND woi.order_item_name = %s
      UNION
      SELECT woi2.$post_id AS order_id
      FROM {$wpdb->prefix}$postmeta AS woi2
      WHERE woi2.meta_key IN ( 'lifetime_affiliate_coupon_referrer', 'wcusage_referrer_coupon' )
      AND woi2.meta_value = %s",
      $coupon_code, $coupon_code
    );

    // Exclude an order whose WINNING attribution key names another coupon. The lifetime
    // key wins when it is set, so the IF() picks which key decides and one NOT EXISTS
    // settles it.
    //
    // Written this way on purpose: the obvious pair of NOT EXISTS clauses (one per key)
    // makes MySQL materialise the first one and full-scan the order meta table, which is
    // the one thing here that would not scale - see the EXPLAIN. This form is index-only
    // on order_id_meta_key_meta_value.
    //
    // The value comparison is CAST to BINARY so it is byte-exact, matching the PHP `!=`
    // this replaces. A plain comparison would use the table collation
    // (utf8mb4_unicode_520_ci), which is accent-insensitive and PAD SPACE - it would read
    // an order attributed to "cafe-with-an-accent" as belonging to coupon "cafe" and stop
    // excluding it. LOWER() stays on the column because the loop lowercased the meta as it
    // read it, which matters for rows written before wcusage_order_meta_lowercase() did so;
    // $coupon_code is already lowercased above.
    $ownership = $wpdb->prepare(
      "AND NOT EXISTS (
        SELECT 1 FROM {$wpdb->prefix}$postmeta AS wcu_own
        WHERE wcu_own.$post_id = p.$id
        AND wcu_own.meta_key = IF (
              EXISTS ( SELECT 1 FROM {$wpdb->prefix}$postmeta AS wcu_life
                       WHERE wcu_life.$post_id = p.$id
                       AND wcu_life.meta_key = 'lifetime_affiliate_coupon_referrer'
                       AND wcu_life.meta_value <> '' ),
              'lifetime_affiliate_coupon_referrer', 'wcusage_referrer_coupon' )
        AND wcu_own.meta_value <> ''
        AND CAST( LOWER( wcu_own.meta_value ) AS BINARY ) <> CAST( %s AS BINARY ) )",
      $coupon_code
    );

    $query = "SELECT p.$id AS order_id, p.$post_date AS order_date, p.$post_status AS order_status
      FROM {$wpdb->prefix}$posts AS p
      INNER JOIN ( $matched_orders ) AS wcu_matched ON wcu_matched.order_id = p.$id
      WHERE p.$post_status IN ('" . implode("','", array_map('esc_sql', array_keys($statuses))) . "')
      $ownership";

    if ($wcusage_field_order_sort != "completeddate") {
      $query .= $wpdb->prepare(" AND p.$post_date BETWEEN %s AND %s", $start_date, $end_date);
    } else {
      $query .= $wpdb->prepare(" AND p.$id IN (
        SELECT woi2.post_id
        FROM {$wpdb->prefix}postmeta AS woi2
        WHERE woi2.meta_key = '_completed_date' AND woi2.meta_value BETWEEN %s AND %s)",
        $start_date, $end_date
      );
    }

    // order_id breaks ties between orders created in the same second, as in the
    // calculation query - without it a LIMIT could pick either of two same-second orders.
    $query .= " ORDER BY order_date DESC, order_id DESC";

    if ($numberoforders) {
      $query .= " LIMIT " . intval($numberoforders);
    }

    $rows = $wpdb->get_results($query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

    return is_array($rows) ? $rows : array();
  }
}

/**
 * Get Orders For Coupon Code Within Date Range
 *
 * @param string $coupon_code
 * @param date $start_date
 * @param date $end_date
 * @param int $numberoforders
 * @param bool $refresh
 * @param bool $update
 * @param bool $alltime
 * @param string $status_filter
 *
 * @return mixed
 *
 */
if( !function_exists( 'wcusage_wh_getOrderbyCouponCode' ) ) {
  function wcusage_wh_getOrderbyCouponCode( $coupon_code, $start_date, $end_date, $numberoforders = '', $refresh = 1, $update = 0, $alltime = false, $status_filter = '' ) {

    // Request-level memo.
    //
    // The statistics tab asks for five to seven overlapping periods per page
    // load and some of them are the same window twice (the monthly toggle
    // reuses last month's figures for two different boxes), so the same
    // arguments arrive more than once in a single request.
    //
    // Only read-only calls are memoised. With $update truthy the function
    // recalculates and WRITES order and coupon meta, so those must always run -
    // and any earlier memo is dropped, because that write is exactly what would
    // make a cached answer stale.
    static $results_memo = array();

    $memo_key = md5( serialize( array( $coupon_code, $start_date, $end_date, $numberoforders, $refresh, $alltime, $status_filter ) ) );

    if ( $update ) {
      $results_memo = array();
    } elseif ( isset( $results_memo[ $memo_key ] ) ) {
      return $results_memo[ $memo_key ];
    }

    $coupon_code = sanitize_text_field($coupon_code);
    $get_start_date = sanitize_text_field($start_date);
		$get_end_date = sanitize_text_field($end_date);
		$status_filter = sanitize_key(str_replace('wc-', '', $status_filter));
		$status_filter_key = $status_filter ? 'wc-' . $status_filter : '';

	$start_date = wcusage_convert_date_to_gmt($get_start_date, 0);
	$end_date = wcusage_convert_date_to_gmt($get_end_date, 1);

    $coupon_code = strtolower($coupon_code);
    $couponinfo = wcusage_get_coupon_info($coupon_code);

  	$options = wcusage_get_options();
  	$wcu_save_all_stats_as_meta = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
    if(!$wcu_save_all_stats_as_meta) {
      delete_post_meta( $couponinfo[2], 'wcu_alltime_stats' );
    }

    $wcu_all_total_orders = "";
    $wcu_all_full_discount = "";
    $wcu_all_total_commission = "";

    $wcu_alltime_stats = get_post_meta( $couponinfo[2], 'wcu_alltime_stats', true );
  	if($wcu_alltime_stats && $wcu_save_all_stats_as_meta) {

  		if(isset($wcu_alltime_stats['total_orders'])) {
  			$wcu_all_total_orders = $wcu_alltime_stats['total_orders'];
  		}

  		if(isset($wcu_alltime_stats['full_discount'])) {
  			$wcu_all_full_discount = $wcu_alltime_stats['full_discount'];
  		}

  		if(isset($wcu_alltime_stats['total_commission'])) {
  			$wcu_all_total_commission = $wcu_alltime_stats['total_commission'];
  		}

  	}

  	$list_of_products = "";
	
  	//$refresh = 1;
  	if( $refresh || ($start_date && $end_date) || $numberoforders || !$wcu_all_total_orders || !$wcu_all_full_discount || !$wcu_all_total_commission || !$wcu_save_all_stats_as_meta ) {

  		global  $wpdb ;
  		$return_array = [];
  		$total_discount = 0;
  		$total_orders = 0;
  		$total_shipping = 0;
  		$total_count = 0;
  		$total_commission = 0;
		
  		$wcusage_field_order_sort = wcusage_get_setting_value('wcusage_field_order_sort', '');

  		$start_date = wcusage_get_coupon_order_start_date( $couponinfo[2], $start_date );

  		// Check if enable lifetime
  		$wcusage_field_lifetime_all = wcusage_get_setting_value('wcusage_field_lifetime_all', '0');
  		$wcu_coupon_enable_lifetime_commission = get_post_meta( $couponinfo[2], 'wcu_enable_lifetime_commission', true );
		$enable_renewals = wcusage_get_setting_value('wcusage_field_subscriptions_enable_renewals', '1');
		$subscription_renewals = is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' );
  		if( $wcusage_field_lifetime_all || $wcu_coupon_enable_lifetime_commission || ($enable_renewals && $subscription_renewals) ) {
  			$enablelifetime = true;
  		} else {
  			$enablelifetime = false;
  		}

  		$statuses = wcusage_get_coupon_order_statuses( $status_filter_key );

		if (empty($statuses)) {
			$allstats = array(
				'total_orders' => 0,
				'full_discount' => 0,
				'total_commission' => 0,
				'total_shipping' => 0,
				'total_count' => 0,
			);

			$empty_return = array(
				'orders' => array(),
				'list_of_products' => array(),
				'total_count' => 0,
				'full_discount' => 0,
				'total_shipping' => 0,
				'total_orders' => 0,
				'total_commission' => 0,
				'commission_summary' => array(),
				'status_counts' => array(),
				'allstats' => $allstats,
			);

			if ( ! $update ) {
				$results_memo[ $memo_key ] = $empty_return;
			}

			return $empty_return;
		}

		// Custom Orders Table or Posts Table
		if (class_exists(OrderUtil::class) && method_exists(OrderUtil::class, 'custom_orders_table_usage_is_enabled') && OrderUtil::custom_orders_table_usage_is_enabled()) {
			$id = "id";
			$posts = "wc_orders";
			$postmeta = "wc_orders_meta";
			$post_date = "date_created_gmt";
			$post_type = "";
			$post_status = "status";
			$post_id = "order_id";
		} else {
			$id = "ID";
			$posts = "posts";
			$postmeta = "postmeta";
			$post_date = "post_date_gmt";
			$post_type = "WHERE\r\n p.post_type = 'shop_order'";
			$post_status = "post_status";
			$post_id = "post_id";
		}

		// The set of orders that used this coupon, either as an applied coupon line
		// item or through referral attribution meta.
		//
		// This is a derived table rather than a pair of LEFT JOINs matching the
		// coupon in their ON clauses. A LEFT JOIN keeps every row of the orders
		// table regardless of whether it matched, so the coupon could not narrow
		// the orders table at all: every order in the store was read, de-duplicated
		// through a temporary table and filesorted on each call, however few orders
		// the coupon actually had. Matching first and joining the orders table by
		// primary key makes the cost proportional to the coupon's own orders.
		$matched_orders = $wpdb->prepare(
			"SELECT woi.order_id AS order_id
			FROM {$wpdb->prefix}woocommerce_order_items AS woi
			WHERE woi.order_item_type = 'coupon' AND woi.order_item_name = %s
			UNION
			SELECT woi2.$post_id AS order_id
			FROM {$wpdb->prefix}$postmeta AS woi2
			WHERE woi2.meta_key IN ( 'lifetime_affiliate_coupon_referrer', 'wcusage_referrer_coupon' )
			AND woi2.meta_value = %s",
			$coupon_code, $coupon_code
		);

		// Query to get orders.
		// UNION already returns each order ID once, and p.$id is the primary key,
		// so DISTINCT (and the temporary table it needs) is no longer required.
		$query = "SELECT p.$id AS order_id, p.$post_date AS order_date, p.$post_status AS order_status
			FROM {$wpdb->prefix}$posts AS p
			INNER JOIN ( $matched_orders ) AS wcu_matched ON wcu_matched.order_id = p.$id
			WHERE p.$post_status IN ('" . implode("','", array_keys($statuses)) . "')";

		if ($wcusage_field_order_sort != "completeddate") {
			$query .= $wpdb->prepare(" AND p.$post_date BETWEEN %s AND %s", $start_date, $end_date);
		} else {
			$query .= $wpdb->prepare(" AND p.$id IN (
				SELECT woi2.post_id
				FROM {$wpdb->prefix}postmeta AS woi2
				WHERE woi2.meta_key = '_completed_date' AND woi2.meta_value BETWEEN %s AND %s)", 
				$start_date, $end_date
			);
		}		

		if ($numberoforders) {
			$numberoforders = intval($numberoforders);
			$limit = "LIMIT " . $numberoforders;
		} else {
			$limit = "";
		}
		
		// order_id breaks ties between orders created in the same second. Without
		// it their relative order is left to the query plan, so which of two
		// same-second orders lands inside a LIMIT could change from call to call.
		$query .= " ORDER BY order_date DESC, order_id DESC $limit";

		$orders = $wpdb->get_results($query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		if (!is_array($orders)) {
			$orders = [];
		}

		$orders = array_reverse($orders);
		
  		$list_of_products = array();
      	$commission_summary = array();
		$status_counts = array();

  		$wcusage_show_tax = wcusage_get_setting_value('wcusage_field_show_tax', '0');

  		$save_order_commission_meta = wcusage_get_setting_value('wcusage_field_enable_order_commission_meta', '1');

		// Read every order in this batch up front. The per-order work below needs
		// each order's meta, line items and refunds, and fetching those one order
		// at a time is the dominant cost of the whole stats path. Returns a map of
		// order ID => WC_Order, empty when the result set was too large to hold in
		// memory (the loop then falls back to loading orders individually).
		$order_objects = wcusage_prime_order_meta_cache( $orders );

		// When the batch was skipped, keep the old memory guard: without it a very
		// large result set would fill the object cache one order at a time.
		// wp_suspend_cache_addition() returns the value *after* applying its
		// argument, so the current state has to be read before suspending -
		// passing its return value back would just suspend it again and leave
		// the object cache disabled for the rest of the request.
		$previous_cache_state = null;
		if ( empty( $order_objects ) && count( $orders ) > 2000 ) {
			$previous_cache_state = wp_suspend_cache_addition();
			wp_suspend_cache_addition( true );
		}

  		if ( !empty($orders) ) {
		$dp = ( isset( $filter['dp'] ) ? intval( $filter['dp'] ) : 2 );

		// Which of these orders carry more than one coupon, in a single query.
		// Only those can have a commission owner other than the coupon being
		// listed, so this is what keeps wcusage_coupon_owns_order_commission()
		// - which resolves the owner by reading each coupon on the order - off
		// the path for ordinary single-coupon orders, which are nearly all of
		// them. Asking per order cost more than the check saved.
		$stacked_coupon_orders = array();
		if ( ! $update && ! $alltime && $save_order_commission_meta ) {
			$stacked_ids = array();
			foreach ( $orders as $the_order_row ) {
				if ( isset( $the_order_row->order_id ) && $the_order_row->order_id ) {
					$stacked_ids[] = (int) $the_order_row->order_id;
				}
			}
			$stacked_ids = array_unique( array_filter( $stacked_ids ) );
			// Chunked: a date range on a busy coupon can bring back thousands of
			// orders, and one IN list of every id would eventually run into
			// max_allowed_packet.
			foreach ( array_chunk( $stacked_ids, 2000 ) as $stacked_chunk ) {
				$stacked_in = implode( ',', array_map( 'intval', $stacked_chunk ) );
				$stacked_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					"SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items
					 WHERE order_item_type = 'coupon' AND order_id IN ({$stacked_in})
					 GROUP BY order_id HAVING COUNT(*) > 1"
				);
				foreach ( (array) $stacked_rows as $stacked_row ) {
					$stacked_coupon_orders[ (int) $stacked_row ] = true;
				}
			}
		}

		// looping through all the order_id
		foreach ( $orders as $key => $the_order ) {
		
		$order_id = $the_order->order_id;

		// The order object for this row, already read by the batch above. Falls
		// back to a single read when the batch was skipped or the order is not a
		// shop_order.
		$this_order = isset( $order_objects[ $order_id ] ) ? $order_objects[ $order_id ] : null;

		// Performance optimization: When not doing a full refresh/update, try to use
		// saved order meta to avoid loading full WC_Order objects and recalculating.
		// This dramatically improves performance for affiliates with many orders.
		//
		// The meta is read through wcusage_order_meta() rather than get_post_meta().
		// Two reasons, either of which stopped this path working: the plugin writes
		// array meta as JSON (see wcusage_edit_order_meta), so a raw read returns a
		// STRING and the is_array() test below could never pass; and under High
		// Performance Order Storage order meta is not in wp_postmeta at all, so on
		// those stores the raw read returns nothing whatever the format.
		if (!$update && !$alltime && $save_order_commission_meta) {
			$cached_stats = wcusage_order_meta($order_id, 'wcusage_stats', true);

			// The saved figures belong to the ONE coupon that owns this order's
			// commission. With neither referrer meta set, the owner is decided from
			// the coupons on the order and may not be this one - on a stacked order
			// every coupon was reading the owner's saved amount back as its own, so
			// two affiliates were each credited the full commission and this tab's
			// total came out above the Statistics tab's for the same period.
			//
			// A coupon that does not own them still earns; it just has to work its
			// own figure out on the full path below instead of inheriting the
			// owner's. Dropping $cached_stats is what sends it there.
			//
			// When a referrer meta IS set the checks below already prove it names
			// this coupon, so the lookup is skipped - ordinary single-coupon orders
			// stay on the fast path untouched.
			if ( is_array($cached_stats) && !empty($cached_stats)
				&& isset( $stacked_coupon_orders[ (int) $order_id ] ) ) {
				$fp_lifetime_referrer = strtolower((string) wcusage_order_meta($order_id, 'lifetime_affiliate_coupon_referrer', true));
				$fp_referrer_coupon   = strtolower((string) wcusage_order_meta($order_id, 'wcusage_referrer_coupon', true));
				if ( ! $fp_lifetime_referrer && ! $fp_referrer_coupon
					&& ! wcusage_coupon_owns_order_commission( ( $this_order instanceof WC_Order ) ? $this_order : $order_id, $coupon_code ) ) {
					$cached_stats = array();
				}
			}

			if (is_array($cached_stats) && !empty($cached_stats)
				&& isset($cached_stats['order']) && $cached_stats['order'] > 0
				&& isset($cached_stats['commission'])) {

				// Quick meta checks for coupon ownership (without recalculating)
				$lifetime_affiliate_coupon_referrer = strtolower((string) wcusage_order_meta($order_id, 'lifetime_affiliate_coupon_referrer', true));
				if ($lifetime_affiliate_coupon_referrer && $lifetime_affiliate_coupon_referrer != $coupon_code) {
					continue;
				}
				$wcusage_referrer_coupon = strtolower((string) wcusage_order_meta($order_id, 'wcusage_referrer_coupon', true));
				if (!$lifetime_affiliate_coupon_referrer && $wcusage_referrer_coupon && $wcusage_referrer_coupon != $coupon_code) {
					continue;
				}

				$renewalcheck = wcusage_check_if_renewal_allowed($order_id);
				if (!$renewalcheck) {
					continue;
				}

				// Use cached values directly
				$cached_order_total = isset($cached_stats['order']) ? (float)$cached_stats['order'] : 0;
				$cached_discount = isset($cached_stats['discount']) ? (float)$cached_stats['discount'] : 0;
				$cached_commission = isset($cached_stats['commission']) ? (float)$cached_stats['commission'] : 0;

				// Shipping and the product tally are NOT part of the saved stats,
				// so they still come from the order. That is cheap now the orders
				// are read in one batch above, and it matters: this branch used to
				// report shipping as 0 and contribute nothing to the product list,
				// which would have silently emptied the "Products" column and the
				// shipping totals for every order that took it.
				$cached_shipping = 0;
				$fast_path_order = $this_order ? $this_order : wc_get_order( $order_id );
				if ( $fast_path_order instanceof WC_Order ) {
					$fast_path_totals = wcusage_get_order_totals( $fast_path_order );
					if ( isset( $fast_path_totals['total_shipping'] ) ) {
						$cached_shipping = (float) $fast_path_totals['total_shipping'];
					}
					wcusage_tally_order_products( $fast_path_order, $list_of_products );
				}

				$return_array[$key]['order_id'] = $order_id;
				$return_array[$key]['total'] = $cached_order_total;
				$return_array[$key]['total_discount'] = $cached_discount;
				$return_array[$key]['total_shipping'] = $cached_shipping;

				$total_discount += $cached_discount;
				$total_orders += $cached_order_total;
				$total_shipping += $cached_shipping;
				$total_count++;
				$total_commission += $cached_commission;

				// Count status from SQL result
				if (isset($the_order->order_status)) {
					$raw_status = str_replace('wc-', '', $the_order->order_status);
					$status_label = ucfirst(wc_get_order_status_name($raw_status));
					if (!isset($status_counts[$status_label])) {
						$status_counts[$status_label] = 0;
					}
					$status_counts[$status_label]++;
				}

				// Get commission summary from cached meta
				if ($start_date != "0001-01-01") {
					$cached_commission_summary = wcusage_order_meta($order_id, 'wcusage_commission_summary', true);
					if (!empty($cached_commission_summary)) {
						$a2 = $cached_commission_summary;
						if (!is_array($a2)) { $a2 = maybe_unserialize($a2); }
						if (!is_array($a2)) { $a2 = array(); }
						$a1 = $commission_summary;
						foreach (array_keys($a1 + $a2) as $cskey) {
							$a1_total = isset($a1[$cskey]['total']) && is_numeric($a1[$cskey]['total']) ? $a1[$cskey]['total'] : 0;
							$a2_total = isset($a2[$cskey]['total']) && is_numeric($a2[$cskey]['total']) ? $a2[$cskey]['total'] : 0;
							// Saved in the order's currency; the full path converts, so this must too.
							$commission_summary[$cskey]['total'] = $a1_total + ( $fast_path_order instanceof WC_Order ? wcusage_convert_order_value_to_currency( $fast_path_order, $a2_total ) : $a2_total );

							$a1_subtotal = isset($a1[$cskey]['subtotal']) && is_numeric($a1[$cskey]['subtotal']) ? $a1[$cskey]['subtotal'] : 0;
							$a2_subtotal = isset($a2[$cskey]['subtotal']) && is_numeric($a2[$cskey]['subtotal']) ? $a2[$cskey]['subtotal'] : 0;
							$commission_summary[$cskey]['subtotal'] = $a1_subtotal + ( $fast_path_order instanceof WC_Order ? wcusage_convert_order_value_to_currency( $fast_path_order, $a2_subtotal ) : $a2_subtotal );

							$a1_commission = isset($a1[$cskey]['commission']) && is_numeric($a1[$cskey]['commission']) ? $a1[$cskey]['commission'] : 0;
							$a2_commission = isset($a2[$cskey]['commission']) && is_numeric($a2[$cskey]['commission']) ? $a2[$cskey]['commission'] : 0;
							$commission_summary[$cskey]['commission'] = $a1_commission + ( $fast_path_order instanceof WC_Order ? wcusage_convert_order_value_to_currency( $fast_path_order, $a2_commission ) : $a2_commission );

							$a1_number = isset($a1[$cskey]['number']) && is_numeric($a1[$cskey]['number']) ? $a1[$cskey]['number'] : 0;
							$a2_number = isset($a2[$cskey]['number']) && is_numeric($a2[$cskey]['number']) ? $a2[$cskey]['number'] : 0;
							$commission_summary[$cskey]['number'] = $a1_number + $a2_number;
						}
					}
				}

				continue; // Skip the expensive full order loading below
			}
		}

		// Reuse the object from the batch read; only fall back to a single read
		// when the batch was skipped (very large result sets) or this row is not
		// a shop_order.
		$order = $this_order ? $this_order : wc_get_order( $order_id );

		if($refresh && $update && $alltime) {

			// Clear the pending meta, and the MLA commission so it is recalculated fresh
			// (unless "Never update saved commission" is enabled).
			//
			// Cleared through the order object: with High-Performance Order Storage an
			// order's meta is not in the post meta table, so delete_post_meta() removed
			// nothing at all here and the pending commission was left in place - which
			// then told wcusage_check_and_add_pending_commission() below there was
			// already a pending amount, so a full refresh never recalculated it.
			// The two pending keys are cleared only for orders the recalculation at
			// the end of this block will NOT rewrite. Where the order is still in a
			// pending status, wcusage_check_and_add_pending_commission() works both
			// keys out again and stores them itself, so deleting them first bought
			// nothing and cost a second full WC_Order::save() per order - under
			// High-Performance Order Storage every order meta write is one - to put
			// back a figure that had not changed. A refresh paid that on every
			// pending order it walked, every time it ran.
			$clear_keys = array();

			$rewrites_pending_keys = wcusage_get_setting_value('wcusage_field_payout_pending_enable', '1')
				&& function_exists( 'wcusage_check_and_add_pending_commission' )
				&& function_exists( 'wcusage_is_order_pending_status' )
				&& $order
				&& wcusage_is_order_pending_status( $order->get_status() );

			if ( ! $rewrites_pending_keys ) {
				$clear_keys[] = 'wcusage_pending_commission';
				if ( function_exists( 'wcusage_order_pending_commission_key' ) ) {
					$clear_keys[] = wcusage_order_pending_commission_key( $coupon_code );
				}
			}

			$never_update_commission_meta = wcusage_get_setting_value('wcusage_field_enable_never_update_commission_meta', '0');
			if ( ! $never_update_commission_meta ) {
				$clear_keys[] = 'wcu_mla_commission';
			}

			wcusage_delete_order_meta_bulk( $order_id, $clear_keys );

			// Check and add if pending (this handles the logic)
			if( function_exists('wcusage_check_and_add_pending_commission') ) {
				wcusage_check_and_add_pending_commission($order_id, $coupon_code);
			}

		}

		// if meta "lifetime_affiliate_coupon_referrer" is set, check if it's same as $coupon_code if not then skip
		// Read through the order object: under HPOS these are not in wp_postmeta,
		// so a get_post_meta() read returned "" and both ownership checks below
		// silently stopped filtering anything out.
		$lifetime_affiliate_coupon_referrer = $order ? strtolower( (string) $order->get_meta( 'lifetime_affiliate_coupon_referrer' ) ) : '';
		if( $lifetime_affiliate_coupon_referrer && $lifetime_affiliate_coupon_referrer != $coupon_code ) {
			continue;
		}

		// if meta "wcusage_referrer_coupon" is set, check if it's same as $coupon_code if not then skip
		$wcusage_referrer_coupon = $order ? strtolower( (string) $order->get_meta( 'wcusage_referrer_coupon' ) ) : '';
		if( !$lifetime_affiliate_coupon_referrer && $wcusage_referrer_coupon && $wcusage_referrer_coupon != $coupon_code ) {
			continue;
		}

		$renewalcheck = wcusage_check_if_renewal_allowed($order_id);
		if(!$renewalcheck) {
			continue;
		}

        if($order_id) {
		
			$theorderstatus = $order->get_status();

			$theordertotal = $order->get_total();
			$theordertotaltax = $order->get_total_tax();

			$check_status_show = wcusage_check_status_show($theorderstatus);

			if(!$theorderstatus || !$theordertotal) { continue; }

			// Check Lifetime
			$lifetimecheck = wcusage_check_lifetime_or_coupon($order_id, $coupon_code);

			// Subscription renewals were already checked a few lines above, and
			// $renewalcheck still holds that answer - the loop would have skipped
			// this order otherwise. Asking again re-ran the subscription lookups
			// for every order in the result set.

			if ( ($theorderstatus == "completed" || $check_status_show) && $renewalcheck && $lifetimecheck ) {

				// Count status for aggregate totals
				$status_label = ucfirst(wc_get_order_status_name($theorderstatus));
				if (!isset($status_counts[$status_label])) {
					$status_counts[$status_label] = 0;
				}
				$status_counts[$status_label]++;

				if($update) {
					$calculateorder = wcusage_calculate_order_data( $order, $coupon_code, 1, 0 );
				} else {
					$calculateorder = wcusage_calculate_order_data( $order, $coupon_code, 0, 1 );
				}
				
				$never_update_commission_meta = wcusage_get_setting_value('wcusage_field_enable_never_update_commission_meta', '0');
				
				if(isset($calculateorder['totalorders'])) {

					$shipping_data_total = 0;
					$return_array[$key]['order_id'] = $order_id;

					$order_totals = wcusage_get_order_totals( $order );

					// Get Totals For Order
					$return_array[$key]['total'] = $calculateorder['totalorders'];
					$return_array[$key]['total_discount'] = $calculateorder['totaldiscounts'];
					$return_array[$key]['total_shipping'] = $order_totals['total_shipping'];

					// Get Totals
					$this_total_discount = $return_array[$key]['total_discount'];
					$this_total_orders = $return_array[$key]['total'];
					$this_total_shipping = $return_array[$key]['total_shipping'];

					// Add To Combined Total
					$total_discount += (float)$this_total_discount;
					$total_orders += (float)$this_total_orders;
					$total_shipping += (float)$this_total_shipping;
					$total_count++;

					$affiliatecommission = $calculateorder['totalcommission'];
					$total_commission += (float)$affiliatecommission;

					// Get List Products
					wcusage_tally_order_products( $order, $list_of_products );

					}

				}

				if($start_date != "0001-01-01") {

					if(!empty($calculateorder['commission_summary'])) {
						$a2 = $calculateorder['commission_summary'];
						if(!is_array($a2)) { $a2 = maybe_unserialize($a2); }
						if(!is_array($a2)) { $a2 = array(); }
						$a1 = $commission_summary;
						foreach (array_keys($a1 + $a2) as $cskey) {
							$a1_total = isset($a1[$cskey]['total']) && is_numeric($a1[$cskey]['total']) ? $a1[$cskey]['total'] : 0;
							$a2_total = isset($a2[$cskey]['total']) && is_numeric($a2[$cskey]['total']) ? $a2[$cskey]['total'] : 0;
							$a2_total = wcusage_convert_order_value_to_currency($order, $a2_total);
							$total1 = $a1_total + $a2_total;
							$commission_summary[$cskey]['total'] = $total1;

							$a1_subtotal = isset($a1[$cskey]['subtotal']) && is_numeric($a1[$cskey]['subtotal']) ? $a1[$cskey]['subtotal'] : 0;
							$a2_subtotal = isset($a2[$cskey]['subtotal']) && is_numeric($a2[$cskey]['subtotal']) ? $a2[$cskey]['subtotal'] : 0;
							$a2_subtotal = wcusage_convert_order_value_to_currency($order, $a2_subtotal);
							$subtotal1 = $a1_subtotal + $a2_subtotal;
							$commission_summary[$cskey]['subtotal'] = $subtotal1;

							$a1_commission = isset($a1[$cskey]['commission']) && is_numeric($a1[$cskey]['commission']) ? $a1[$cskey]['commission'] : 0;
							$a2_commission = isset($a2[$cskey]['commission']) && is_numeric($a2[$cskey]['commission']) ? $a2[$cskey]['commission'] : 0;
							$a2_commission = wcusage_convert_order_value_to_currency($order, $a2_commission);
							$commission1 = $a1_commission + $a2_commission;
							$commission_summary[$cskey]['commission'] = $commission1;

							$a1_number = isset($a1[$cskey]['number']) && is_numeric($a1[$cskey]['number']) ? $a1[$cskey]['number'] : 0;
							$a2_number = isset($a2[$cskey]['number']) && is_numeric($a2[$cskey]['number']) ? $a2[$cskey]['number'] : 0;
							$commission_summary[$cskey]['number'] = $a1_number + $a2_number;
						}
					}
				}

          		}

  			}
			

  		}

		// Restore cache addition state (only touched for very large result sets)
		if ( null !== $previous_cache_state ) {
			wp_suspend_cache_addition( $previous_cache_state );
		}

		$allstats = array();
		$allstats['total_orders'] = $total_orders;
		$allstats['full_discount'] = $total_discount;
		$allstats['total_commission'] = $total_commission;
		$allstats['total_shipping'] = $total_shipping;
		$allstats['total_count'] = $total_count;
		if($start_date != "0001-01-01") {
			$allstats['commission_summary'] = $commission_summary;
		}
  		if( (!$start_date || $start_date == "0001-01-01") && $refresh && $update) {
  			update_post_meta( $couponinfo[2], 'wcu_alltime_stats', $allstats );
  		}
  		//delete_post_meta( $couponinfo[2], 'wcu_alltime_stats' );

  	} else {

  		if(isset($wcu_alltime_stats['total_orders'])) {
  			$total_orders = $wcu_alltime_stats['total_orders'];
  		} else {
  			$total_orders = 0;
  		}

  		if(isset($wcu_alltime_stats['full_discount'])) {
  			$total_discount = $wcu_alltime_stats['full_discount'];
  		} else {
  			$total_discount = 0;
  		}

  		if(isset($wcu_alltime_stats['total_commission'])) {
  			$total_commission = $wcu_alltime_stats['total_commission'];
  		} else {
  			$total_commission = 0;
  		}

  		if(isset($wcu_alltime_stats['total_shipping'])) {
  			$total_shipping = $wcu_alltime_stats['total_shipping'];
  		} else {
  			$total_shipping = 0;
  		}

  		if(isset($wcu_alltime_stats['total_count'])) {
  			$total_count = $wcu_alltime_stats['total_count'];
  		} else {
  			$total_count = 0;
  		}
		
      	if(isset($wcu_alltime_stats['commission_summary'])) {
  			$commission_summary = $wcu_alltime_stats['commission_summary'];
  		} else {
  			$commission_summary = array();
  		}

  	}

  	if( !$total_orders || !is_numeric($total_orders) ) {
  		$total_orders = 0;
  	}
  	if( !$total_shipping || !is_numeric($total_shipping) ) {
  		$total_shipping = 0;
  	}
  	if(!$list_of_products) {
  		$list_of_products = "";
  	}

	$return_array['orders'] = $orders;
  	$return_array['list_of_products'] = $list_of_products;
  	$return_array['total_count'] = $total_count;
  	$return_array['full_discount'] = $total_discount;
  	$return_array['total_shipping'] = $total_shipping;
  	$return_array['total_orders'] = $total_orders;
  	$return_array['total_commission'] = $total_commission;
    $return_array['commission_summary'] = $commission_summary;
	$return_array['status_counts'] = isset($status_counts) ? $status_counts : array();
	$return_array['allstats'] = $allstats;

	if ( ! $update ) {
		$results_memo[ $memo_key ] = $return_array;
	}

  	return $return_array;

  }
}

/**
 * Check if the current order status can be shown
 *
 * @param string $theorderstatus
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_check_status_show' ) ) {
	function wcusage_check_status_show($theorderstatus) {

		$wcusage_field_order_type = wcusage_get_setting_value('wcusage_field_order_type', '');
		$wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');

		$isthistrue = false;

    if(is_string($theorderstatus)) {

  		// Check Old Settings
  		if(!$wcusage_field_order_type_custom) {
  			if($wcusage_field_order_type != "completed") {
  				if ( $theorderstatus == "processing" || $theorderstatus == "completed" ) {
  					$isthistrue = true;
  				}
  			}
  			if($wcusage_field_order_type == "completed") {
  				if ( $theorderstatus == "completed" ) {
  					$isthistrue = true;
  				}
  			}
  		}

  		// Check New Settings
  		if($wcusage_field_order_type_custom) {
  			foreach( $wcusage_field_order_type_custom as $key2 => $status2 ) {
  				$thestatus = wc_get_order_status_name( $key2 );
  				$thisstatusname = wc_get_order_status_name( $theorderstatus );
  				if( $thisstatusname == $thestatus ) {
  					$isthistrue = true;
  				}
  			}
  		}

    }

		return $isthistrue;

	}
}

/**
 * Get a coupons total sales, commission, and referrals for the current year
 *
 * @param string $couponid
 *
 * @return mixed
 *
 */
if( !function_exists( 'wcusage_get_coupon_yearly_totals' ) ) {
	function wcusage_get_coupon_yearly_totals($coupon_id, $update = false) {
		
		update_post_meta($coupon_id, 'wcusage_yearly_summary_data', '');
		$wcusage_monthly_summary_data = get_post_meta($coupon_id, 'wcusage_monthly_summary_data', true);
		if(!$wcusage_monthly_summary_data) { $wcusage_monthly_summary_data = array(); }

		$coupon_code = get_the_title($coupon_id);

		$total_sales_year = 0;
		$total_commission_year = 0;
		$total_referrals_year = 0;

		for ($i = 1; $i <= 12; $i++) {

			$first_day = date('Y-m-d', mktime(0, 0, 0, $i, 1, date('Y'))); // First day of the month
			$last_day = date('Y-m-d', mktime(0, 0, 0, $i + 1, 0, date('Y'))); // Last day of the month

			if( isset($wcusage_monthly_summary_data[strtotime($first_day)]) ) {

				if(isset($wcusage_monthly_summary_data[strtotime($first_day)]) && $wcusage_monthly_summary_data[strtotime($first_day)]) {

					$monthly_summary_data = $wcusage_monthly_summary_data[strtotime($first_day)];

					$total_sales_year += (float)$monthly_summary_data['totalorders'] - (float)$monthly_summary_data['totaldiscounts'];
					$total_commission_year += $monthly_summary_data['totalcommission'];
					$total_referrals_year += $monthly_summary_data['total_count'];

				}

			} else {

				$orders = wcusage_wh_getOrderbyCouponCode( $coupon_code, $first_day, $last_day, '', 1, 0 );

				$totalorders = $orders['total_orders'];
				$totaldiscounts = $orders['full_discount'];
				$totalordersexcl = $totalorders - $totaldiscounts;
				$totalcommission = $orders['total_commission'];
				$ordercount = $orders['total_count'];
				$list_of_products = $orders['list_of_products'];
				$order_summary = $orders['commission_summary'];

				$total_sales_year += $totalordersexcl;
				$total_commission_year += $totalcommission;
				$total_referrals_year += $ordercount;

				// Return Totals
				$return_array = [];
				$return_array['totalorders'] = $totalorders;
				$return_array['totaldiscounts'] = $totaldiscounts;
				$return_array['totalordersexcl'] = $totalordersexcl;
				$return_array['totalcommission'] = $totalcommission;
				$return_array['total_count'] = $ordercount;
				$return_array['list_of_products'] = $list_of_products;
				$return_array['order_summary'] = $order_summary;
				$monthly_summary_data[strtotime($first_day)] = $return_array;

			}

		}

		if(isset($monthly_summary_data)) {
			update_post_meta($coupon_id, 'wcusage_monthly_summary_data', $monthly_summary_data);
		}

		$array = array(
			'sales' => $total_sales_year,
			'commission' => $total_commission_year,
			'referrals' => $total_referrals_year,
		);

		return $array;

	}
}

/**
 * "Today", or a date relative to it, in the store's own timezone.
 *
 * WordPress pins PHP's default timezone to UTC, so date('Y-m-d') gives the UTC
 * date and not the store's. That matters here because wcusage_convert_date_to_gmt()
 * reads the dates it is handed as LOCAL dates and shifts them by gmt_offset: a UTC
 * "today" passed back in as the end of a range asks for a window that closes before
 * the local day does. On a store ahead of UTC, for the first gmt_offset hours after
 * local midnight every "up to today" query ended at yesterday 23:59:59 local, so an
 * order placed minutes earlier fell outside it and was missing from Referred Orders -
 * while the all-time totals, which are added up as the order comes in rather than
 * queried, still counted it. Stores behind UTC get the mirror image, a window running
 * up to a day too far ahead.
 *
 * @param string $modifier Optional strtotime() modifier applied to local now, e.g. '-7 days'.
 * @param string $format
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_local_date' ) ) {
	function wcusage_local_date( $modifier = '', $format = 'Y-m-d' ) {

		// current_time('timestamp') is the UTC time plus the store's offset, so
		// gmdate() formats it as local wall clock without shifting it a second time.
		$timestamp = current_time( 'timestamp' );

		if ( $modifier ) {
			$modified = strtotime( $modifier, $timestamp );
			if ( $modified ) {
				$timestamp = $modified;
			}
		}

		return gmdate( $format, $timestamp );
	}
}

// Convert date to GMT
if( !function_exists( 'wcusage_convert_date_to_gmt' ) ) {
	function wcusage_convert_date_to_gmt($date, $end = 0) {
		// Convert the date to a timestamp
		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return $date;
		}
		// Convert to GMT using WordPress' built-in timezone functions
		$gmt_offset = get_option( 'gmt_offset' ); // Get the GMT offset from settings
		$gmt_timestamp = $timestamp - ( $gmt_offset * HOUR_IN_SECONDS );
		if($end) {
			// Add 1 day to the end date
			$gmt_timestamp = $gmt_timestamp + ( 24 * HOUR_IN_SECONDS );
			// Take 1 second off the end date
			$gmt_timestamp = $gmt_timestamp - 1;
		}
		// Format and return the GMT date
		return gmdate( 'Y-m-d H:i:s', $gmt_timestamp );
	}
}

/**
 * Add an order's net product quantities (ordered minus refunded) to a running tally.
 *
 * Shared by both branches of the order loop so the "Products" figures do not
 * depend on which branch an order took.
 *
 * The refunded quantities are collected into a map keyed by product ID in one
 * pass. Walking every refund and every refund line for each order line, as this
 * used to, is O(items x refunds x refund lines) and re-reads the refund items
 * once per line item.
 *
 * @param WC_Order $order            Order to tally.
 * @param array    $list_of_products Running tally, product ID => quantity. Modified in place.
 *
 * @return void
 *
 */
if( !function_exists( 'wcusage_tally_order_products' ) ) {
	function wcusage_tally_order_products( $order, &$list_of_products ) {

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! is_array( $list_of_products ) ) {
			$list_of_products = array();
		}

		// Refunded quantity per product, gathered once for the whole order.
		$refunded_by_product = array();
		foreach ( $order->get_refunds() as $refund ) {
			foreach ( $refund->get_items() as $refund_item ) {
				$refund_product_id = $refund_item->get_product_id();
				if ( ! isset( $refunded_by_product[ $refund_product_id ] ) ) {
					$refunded_by_product[ $refund_product_id ] = 0;
				}
				$refunded_by_product[ $refund_product_id ] += abs( $refund_item->get_quantity() );
			}
		}

		foreach ( $order->get_items() as $item ) {

			$product_id = $item->get_product_id();
			if ( ! $product_id ) {
				$product_id = 0;
			}

			$refunded_quantity = isset( $refunded_by_product[ $item['product_id'] ] ) ? $refunded_by_product[ $item['product_id'] ] : 0;

			$product_quantity = $item->get_quantity() - (float) $refunded_quantity;
			if ( ! $product_quantity ) {
				$product_quantity = 0;
			}

			if ( isset( $list_of_products[ $product_id ] ) ) {
				$list_of_products[ $product_id ] += (float) $product_quantity;
			} else {
				$list_of_products[ $product_id ] = (float) $product_quantity;
			}

		}

	}
}

/**
 * Read a batch of orders in bulk and return them keyed by order ID.
 *
 * The order loop in wcusage_wh_getOrderbyCouponCode() needs, for each order, its
 * meta (referrer coupon, saved stats, commission data), its line items and its
 * refunds. Fetching those one order at a time is the single largest cost in the
 * whole stats path - profiling a 656-order coupon measured 6,271 queries for
 * exactly this work, against 129 when the orders are read in bulk first.
 *
 * wc_get_orders() with 'post__in' is what does the bulk read. Note that it is
 * 'post__in' and NOT 'include': WC_Order_Query silently ignores 'include', so
 * passing that returns EVERY order in the store rather than the requested ones -
 * measured returning 1,078 orders for a 200-id request. Getting this wrong would
 * not throw, it would quietly report other affiliates' orders.
 *
 * Reading the orders also warms WooCommerce's own caches for line items, refunds
 * and refund totals, so $order->get_items() / get_refunds() / get_total_refunded()
 * in the loop below stop hitting the database per order.
 *
 * This deliberately replaces an update_meta_cache( 'post', ... ) call. Under High
 * Performance Order Storage order meta does not live in wp_postmeta at all, so
 * priming the post meta cache warmed a table the orders are not in.
 *
 * @param array $orders Rows with an ->order_id property.
 *
 * @return array Map of order ID => WC_Order for the orders that could be read.
 *
 */
if( !function_exists( 'wcusage_prime_order_meta_cache' ) ) {
	function wcusage_prime_order_meta_cache( $orders ) {

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return array();
		}

		$order_ids = array();
		foreach ( $orders as $the_order ) {
			if ( ! empty( $the_order->order_id ) ) {
				$order_ids[] = (int) $the_order->order_id;
			}
		}
		$order_ids = array_unique( $order_ids );

		if ( empty( $order_ids ) ) {
			return array();
		}

		/**
		 * How many orders to hold in memory at once while building stats.
		 *
		 * Beyond this the batch read is skipped and the loop falls back to
		 * loading orders individually, so a very large result set cannot exhaust
		 * memory. Raise it on a site with headroom to keep the bulk read.
		 *
		 * @param int $max Maximum number of orders to load in one batch.
		 */
		$max_batch = (int) apply_filters( 'wcusage_max_orders_batch_load', 5000 );
		if ( $max_batch > 0 && count( $order_ids ) > $max_batch ) {
			return array();
		}

		$loaded = array();

		foreach ( array_chunk( $order_ids, 200 ) as $chunk ) {

			$batch = wc_get_orders(
				array(
					'post__in' => $chunk,
					'limit'    => -1,
					'type'     => 'shop_order',
					'status'   => 'any',
				)
			);

			if ( ! is_array( $batch ) ) {
				continue;
			}

			foreach ( $batch as $batch_order ) {
				if ( $batch_order instanceof WC_Order ) {
					$loaded[ $batch_order->get_id() ] = $batch_order;
				}
			}
		}

		return $loaded;

	}
}

// Convert date from GMT
if( !function_exists( 'wcusage_convert_date_from_gmt' ) ) {
	function wcusage_convert_date_from_gmt($date) {
		// Convert the date to a timestamp
		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return $date;
		}
		// Convert to GMT using WordPress' built-in timezone functions
		$gmt_offset = get_option( 'gmt_offset' ); // Get the GMT offset from settings
		$gmt_timestamp = $timestamp + ( $gmt_offset * HOUR_IN_SECONDS );
		// Format and return the GMT date
		return date( 'Y-m-d H:i:s', $gmt_timestamp );
	}
}