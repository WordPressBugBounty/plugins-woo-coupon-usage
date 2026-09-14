<?php
/**
 * Report data layer.
 *
 * Everything an affiliate report shows is gathered here once, so the email, the
 * PDF and the on-screen preview cannot drift apart. Callers ask for a period and
 * a coupon and get back a plain array; no rendering decisions are made here.
 *
 * The heavy lifting is delegated to functions that already exist and are already
 * cached - wcusage_wh_getOrderbyCouponCode() memoises per request, and the click
 * queries are cheap index reads - so building a report is close to the cost of
 * loading the affiliate dashboard once.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Work out the last COMPLETED period for a report frequency.
 *
 * This replaces a set of relative date strings that did not survive contact with
 * PHP: "first day of last week" is not a thing strtotime() understands (the
 * "first day of" modifier only applies to months), so weekly reports were
 * covering a two month window, and quarterly reports were being handed the
 * UPCOMING quarter, which by definition has no orders in it yet.
 *
 * @param string $freq   monthly | weekly | quarterly.
 * @param int    $offset How many periods to step back. 0 is the most recently
 *                       completed period, 1 the one before it, and so on.
 * @return array
 */
if ( ! function_exists( 'wcusage_get_report_period' ) ) {
  function wcusage_get_report_period( $freq = 'monthly', $offset = 0 ) {

    $offset = max( 0, (int) $offset );
    $today  = wcusage_local_date();
    $now    = strtotime( $today );

    if ( $freq === 'weekly' ) {

      // Respect the store's week start so the period matches what the shop owner
      // sees everywhere else in WordPress.
      $start_of_week = (int) get_option( 'start_of_week', 1 );
      $weekday       = (int) gmdate( 'w', $now );
      $days_since    = ( $weekday - $start_of_week + 7 ) % 7;

      $this_week_start = strtotime( '-' . $days_since . ' days', $now );
      $start_ts        = strtotime( '-' . ( 7 * ( $offset + 1 ) ) . ' days', $this_week_start );
      $end_ts          = strtotime( '+6 days', $start_ts );

      $start = gmdate( 'Y-m-d', $start_ts );
      $end   = gmdate( 'Y-m-d', $end_ts );

      $prev_start = gmdate( 'Y-m-d', strtotime( '-7 days', $start_ts ) );
      $prev_end   = gmdate( 'Y-m-d', strtotime( '-7 days', $end_ts ) );

      $label      = wcusage_report_format_range( $start, $end );
      $prev_label = wcusage_report_format_range( $prev_start, $prev_end );
      $short      = esc_html__( 'week', 'woo-coupon-usage' );

    } elseif ( $freq === 'quarterly' ) {

      $month   = (int) gmdate( 'n', $now );
      $year    = (int) gmdate( 'Y', $now );
      $quarter = (int) ceil( $month / 3 );

      // Step back one quarter for the most recent completed one, then by $offset.
      $quarter -= ( 1 + $offset );
      while ( $quarter < 1 ) {
        $quarter += 4;
        $year--;
      }

      $start_month = ( ( $quarter - 1 ) * 3 ) + 1;
      $start       = gmdate( 'Y-m-d', mktime( 0, 0, 0, $start_month, 1, $year ) );
      $end         = gmdate( 'Y-m-t', mktime( 0, 0, 0, $start_month + 2, 1, $year ) );

      $prev_quarter = $quarter - 1;
      $prev_year    = $year;
      if ( $prev_quarter < 1 ) { $prev_quarter = 4; $prev_year--; }
      $prev_start_month = ( ( $prev_quarter - 1 ) * 3 ) + 1;
      $prev_start       = gmdate( 'Y-m-d', mktime( 0, 0, 0, $prev_start_month, 1, $prev_year ) );
      $prev_end         = gmdate( 'Y-m-t', mktime( 0, 0, 0, $prev_start_month + 2, 1, $prev_year ) );

      /* translators: 1: quarter number, 2: year. */
      $label      = sprintf( esc_html__( 'Q%1$s %2$s', 'woo-coupon-usage' ), $quarter, $year );
      $prev_label = sprintf( esc_html__( 'Q%1$s %2$s', 'woo-coupon-usage' ), $prev_quarter, $prev_year );
      $short      = esc_html__( 'quarter', 'woo-coupon-usage' );

    } else {

      $month = (int) gmdate( 'n', $now ) - ( 1 + $offset );
      $year  = (int) gmdate( 'Y', $now );
      while ( $month < 1 ) { $month += 12; $year--; }

      $start = gmdate( 'Y-m-d', mktime( 0, 0, 0, $month, 1, $year ) );
      $end   = gmdate( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) );

      $prev_month = $month - 1;
      $prev_year  = $year;
      if ( $prev_month < 1 ) { $prev_month = 12; $prev_year--; }
      $prev_start = gmdate( 'Y-m-d', mktime( 0, 0, 0, $prev_month, 1, $prev_year ) );
      $prev_end   = gmdate( 'Y-m-t', mktime( 0, 0, 0, $prev_month, 1, $prev_year ) );

      $label      = date_i18n( 'F Y', strtotime( $start ) );
      $prev_label = date_i18n( 'F Y', strtotime( $prev_start ) );
      $short      = esc_html__( 'month', 'woo-coupon-usage' );
    }

    return array(
      'type'       => $freq,
      'short'      => $short,
      'start'      => $start,
      'end'        => $end,
      'label'      => $label,
      'prev_start' => $prev_start,
      'prev_end'   => $prev_end,
      'prev_label' => $prev_label,
      'days'       => (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1,
      'file_slug'  => $freq === 'monthly' ? gmdate( 'Y-m', strtotime( $start ) ) : $start,
      'year'       => gmdate( 'Y', strtotime( $start ) ),
      'month'      => gmdate( 'm', strtotime( $start ) ),
    );
  }
}

/**
 * "1 - 7 Sep 2026", collapsing the parts the two dates share.
 */
if ( ! function_exists( 'wcusage_report_format_range' ) ) {
  function wcusage_report_format_range( $start, $end ) {
    $s = strtotime( $start );
    $e = strtotime( $end );
    if ( gmdate( 'Y-m', $s ) === gmdate( 'Y-m', $e ) ) {
      return date_i18n( 'j', $s ) . ' - ' . date_i18n( 'j M Y', $e );
    }
    if ( gmdate( 'Y', $s ) === gmdate( 'Y', $e ) ) {
      return date_i18n( 'j M', $s ) . ' - ' . date_i18n( 'j M Y', $e );
    }
    return date_i18n( 'j M Y', $s ) . ' - ' . date_i18n( 'j M Y', $e );
  }
}

/**
 * Percentage change between two figures.
 *
 * Returns null rather than 0 or 100 when there is no sensible comparison, so the
 * renderers can leave the slot blank instead of printing a number that reads as
 * a real result.
 */
if ( ! function_exists( 'wcusage_report_delta' ) ) {
  function wcusage_report_delta( $current, $previous ) {
    $current  = (float) $current;
    $previous = (float) $previous;
    if ( $previous == 0.0 ) {
      return null;
    }
    return ( ( $current - $previous ) / abs( $previous ) ) * 100;
  }
}

/**
 * Headline totals for one coupon over one date range.
 *
 * Split out from wcusage_get_report_data() because the previous period needs the
 * same figures without any of the per-order detail.
 */
if ( ! function_exists( 'wcusage_get_report_totals' ) ) {
  function wcusage_get_report_totals( $coupon_code, $coupon_id, $start, $end ) {

    $orders = wcusage_wh_getOrderbyCouponCode( $coupon_code, $start, $end, '', 1 );

    $sales      = isset( $orders['total_orders'] ) ? (float) $orders['total_orders'] : 0;
    $discounts  = isset( $orders['full_discount'] ) ? (float) $orders['full_discount'] : 0;
    $commission = isset( $orders['total_commission'] ) ? (float) $orders['total_commission'] : 0;
    $count      = isset( $orders['total_count'] ) ? (int) $orders['total_count'] : 0;

    $clicks_stats = wcusage_get_url_stats( $coupon_id, $start, $end );
    $clicks       = isset( $clicks_stats['clicks'] ) ? (int) $clicks_stats['clicks'] : 0;
    $conversions  = isset( $clicks_stats['convertedcount'] ) ? (int) $clicks_stats['convertedcount'] : 0;

    $items = 0;
    if ( ! empty( $orders['list_of_products'] ) && is_array( $orders['list_of_products'] ) ) {
      foreach ( $orders['list_of_products'] as $qty ) {
        $items += (float) $qty;
      }
    }

    return array(
      'sales'           => $sales,
      'discounts'       => $discounts,
      'commission'      => $commission,
      'orders'          => $count,
      'items'           => $items,
      'clicks'          => $clicks,
      'conversions'     => $conversions,
      'conversion_rate' => $clicks > 0 ? ( $conversions / $clicks ) * 100 : 0,
      'aov'             => $count > 0 ? $sales / $count : 0,
      'epc'             => $clicks > 0 ? $commission / $clicks : 0,
      'per_order'       => $count > 0 ? $commission / $count : 0,
      'raw'             => $orders,
    );
  }
}

/**
 * Build the complete data set for one affiliate report.
 *
 * @param int    $coupon_id
 * @param array  $period  From wcusage_get_report_period().
 * @param array  $args    Section switches, so a site that has turned a block off
 *                        never pays for the queries behind it.
 * @return array|WP_Error
 */
if ( ! function_exists( 'wcusage_get_report_data' ) ) {
  function wcusage_get_report_data( $coupon_id, $period, $args = array() ) {

    $coupon_id = absint( $coupon_id );
    if ( ! $coupon_id ) {
      return new WP_Error( 'wcusage_report_no_coupon', esc_html__( 'No coupon was given for this report.', 'woo-coupon-usage' ) );
    }

    $args = wp_parse_args( $args, array(
      'compare'       => true,
      'trend'         => true,
      'products'      => true,
      'products_max'  => 5,
      'traffic'       => true,
      'traffic_max'   => 5,
      'customers'     => true,
      'payouts'       => true,
      'rank'          => false,
      'mla'           => false,
      'detail_limit'  => 2000,
    ) );

    $coupon_code = get_the_title( $coupon_id );
    if ( ! $coupon_code ) {
      return new WP_Error( 'wcusage_report_no_coupon', esc_html__( 'That coupon no longer exists.', 'woo-coupon-usage' ) );
    }

    $coupon_info = wcusage_get_coupon_info_by_id( $coupon_id );
    $user_id     = isset( $coupon_info[1] ) ? absint( $coupon_info[1] ) : 0;
    $user        = $user_id ? get_userdata( $user_id ) : false;

    $start = $period['start'];
    $end   = $period['end'];

    $totals = wcusage_get_report_totals( $coupon_code, $coupon_id, $start, $end );

    $data = array(
      'coupon_id'     => $coupon_id,
      'coupon_code'   => $coupon_code,
      'user_id'       => $user_id,
      'user'          => $user,
      'period'        => $period,
      'totals'        => $totals,
      'previous'      => null,
      'deltas'        => array(),
      'daily'         => array(),
      'best_day'      => null,
      'top_products'  => array(),
      'traffic'       => array(),
      'customers'     => array(),
      'commission'    => array(),
      'rank'          => null,
      'mla'           => null,
      'dashboard_url' => isset( $coupon_info[4] ) ? $coupon_info[4] : '',
      'site_name'     => get_bloginfo( 'name' ),
    );

    // ---------------------------------------------------------- comparison

    if ( $args['compare'] ) {
      $previous = wcusage_get_report_totals( $coupon_code, $coupon_id, $period['prev_start'], $period['prev_end'] );
      unset( $previous['raw'] );
      $data['previous'] = $previous;

      foreach ( array( 'sales', 'discounts', 'commission', 'orders', 'items', 'clicks', 'conversions', 'conversion_rate', 'aov', 'epc', 'per_order' ) as $key ) {
        $data['deltas'][ $key ] = wcusage_report_delta( $totals[ $key ], $previous[ $key ] );
      }
    }

    // ------------------------------------- per-order pass (trend, products)

    $order_rows = ( isset( $totals['raw']['orders'] ) && is_array( $totals['raw']['orders'] ) ) ? $totals['raw']['orders'] : array();
    $detailed   = count( $order_rows ) <= (int) $args['detail_limit'];

    if ( $args['trend'] || $args['products'] || $args['customers'] ) {
      $pass = wcusage_report_scan_orders( $order_rows, $coupon_code, $period, $args, $detailed );

      if ( $args['trend'] ) {
        $data['daily']    = $pass['series'];
        $data['best_day'] = $pass['best_day'];
      }
      if ( $args['products'] ) {
        $data['top_products'] = wcusage_report_top_products(
          $pass['products'],
          $totals['raw'],
          (int) $args['products_max'],
          $detailed
        );
      }
      if ( $args['customers'] ) {
        $data['customers'] = $pass['customers'];
      }
    }

    // ------------------------------------------------------------- traffic

    if ( $args['traffic'] ) {
      $data['traffic'] = wcusage_get_report_traffic( $coupon_id, $start, $end, (int) $args['traffic_max'] );
    }

    // -------------------------------------------------- commission / payouts

    $data['commission'] = wcusage_get_report_commission( $coupon_id, $user_id, $start, $end, (bool) $args['payouts'] );

    // ---------------------------------------------------------------- rank

    if ( $args['rank'] && function_exists( 'wcusage_leaderboard_date_range' ) && $user_id ) {
      $data['rank'] = wcusage_get_report_rank( $user_id, $start, $end );
    }

    // ----------------------------------------------------------------- MLA

    if ( $args['mla'] && $user_id && function_exists( 'wcusage_get_ml_sub_affiliates' ) ) {
      $subs = wcusage_get_ml_sub_affiliates( $user_id );
      $data['mla'] = array(
        'sub_affiliates' => is_array( $subs ) ? count( $subs ) : 0,
        'commission'     => function_exists( 'wcusage_mla_total_earnings' ) ? (float) wcusage_mla_total_earnings( $user_id ) : 0,
      );
    }

    /**
     * Filter the complete report data set before it is rendered.
     *
     * @param array $data
     * @param int   $coupon_id
     * @param array $period
     */
    return apply_filters( 'wcusage_report_data', $data, $coupon_id, $period );
  }
}

/**
 * One pass over the period's orders, building everything that needs per-order
 * detail: the trend series, the product tally and the customer split.
 *
 * Orders were already read (and their meta primed) by the totals call, so this
 * is mostly cache reads. When a coupon has more orders than the detail limit the
 * expensive per-item work is skipped and only the trend is built.
 */
if ( ! function_exists( 'wcusage_report_scan_orders' ) ) {
  function wcusage_report_scan_orders( $order_rows, $coupon_code, $period, $args, $detailed = true ) {

    $gmt_offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

    // Pre-seed every day in the period so quiet days are drawn as gaps rather
    // than dropped out of the axis.
    $series = array();
    $cursor = strtotime( $period['start'] );
    $last   = strtotime( $period['end'] );
    while ( $cursor <= $last ) {
      $key = gmdate( 'Y-m-d', $cursor );
      $series[ $key ] = array(
        'date'       => $key,
        'label'      => gmdate( 'j', $cursor ),
        'commission' => 0.0,
        'sales'      => 0.0,
        'orders'     => 0,
      );
      $cursor = strtotime( '+1 day', $cursor );
    }

    $products  = array();
    $customers = array();

    foreach ( $order_rows as $row ) {

      $order_id = isset( $row->order_id ) ? (int) $row->order_id : 0;
      if ( ! $order_id ) { continue; }

      $day = gmdate( 'Y-m-d', strtotime( $row->order_date ) + $gmt_offset );

      $stats      = wcusage_order_meta( $order_id, 'wcusage_stats', true );
      $commission = 0.0;
      $sales      = 0.0;
      if ( is_array( $stats ) ) {
        $commission = isset( $stats['commission'] ) ? (float) $stats['commission'] : 0.0;
        $sales      = isset( $stats['order'] ) ? (float) $stats['order'] : 0.0;
      }

      if ( isset( $series[ $day ] ) ) {
        $series[ $day ]['commission'] += $commission;
        $series[ $day ]['sales']      += $sales;
        $series[ $day ]['orders']     += 1;
      }

      if ( ! $detailed || ( ! $args['products'] && ! $args['customers'] ) ) {
        continue;
      }

      $order = wc_get_order( $order_id );
      if ( ! $order instanceof WC_Order ) { continue; }

      if ( $args['customers'] ) {
        // The billing email is the only identifier present on both guest and
        // account orders, so it is what decides new versus returning here.
        $email = strtolower( trim( (string) $order->get_billing_email() ) );
        if ( $email ) {
          if ( ! isset( $customers[ $email ] ) ) { $customers[ $email ] = 0; }
          $customers[ $email ]++;
        }
      }

      if ( $args['products'] ) {
        foreach ( $order->get_items() as $item ) {
          $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
          if ( ! $product_id ) { continue; }
          if ( ! isset( $products[ $product_id ] ) ) {
            $products[ $product_id ] = array( 'id' => $product_id, 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0 );
          }
          $products[ $product_id ]['qty']     += (float) $item->get_quantity();
          $products[ $product_id ]['revenue'] += (float) $item->get_total();
        }
      }
    }

    // Weekly reports name the days; longer periods use the day of the month.
    if ( $period['type'] === 'weekly' ) {
      foreach ( $series as $key => $point ) {
        $series[ $key ]['label'] = date_i18n( 'D', strtotime( $key ) );
      }
    }

    $best_day = null;
    foreach ( $series as $point ) {
      if ( $point['commission'] > 0 && ( $best_day === null || $point['commission'] > $best_day['commission'] ) ) {
        $best_day = $point;
      }
    }

    $returning = 0;
    foreach ( $customers as $count ) {
      if ( $count > 1 ) { $returning++; }
    }

    return array(
      'series'    => array_values( $series ),
      'best_day'  => $best_day,
      'products'  => $products,
      'customers' => array(
        'total'     => count( $customers ),
        'returning' => $returning,
        'new'       => max( 0, count( $customers ) - $returning ),
        'available' => $detailed && $args['customers'],
      ),
    );
  }
}

/**
 * Rank the product tally and work out each row's share of the total.
 *
 * Falls back to the quantity-only tally the stats query already produced when
 * the per-order pass was skipped, so a very large affiliate still gets a list.
 */
if ( ! function_exists( 'wcusage_report_top_products' ) ) {
  function wcusage_report_top_products( $products, $raw_orders, $limit = 5, $detailed = true ) {

    if ( empty( $products ) && ! $detailed && ! empty( $raw_orders['list_of_products'] ) && is_array( $raw_orders['list_of_products'] ) ) {
      foreach ( $raw_orders['list_of_products'] as $product_id => $qty ) {
        $product = wc_get_product( $product_id );
        $products[ $product_id ] = array(
          'id'      => $product_id,
          'name'    => $product ? $product->get_name() : sprintf( '#%s', $product_id ),
          'qty'     => (float) $qty,
          'revenue' => 0.0,
        );
      }
    }

    if ( empty( $products ) ) {
      return array();
    }

    $has_revenue = false;
    foreach ( $products as $product ) {
      if ( $product['revenue'] > 0 ) { $has_revenue = true; break; }
    }

    uasort( $products, function( $a, $b ) use ( $has_revenue ) {
      if ( $has_revenue ) {
        if ( $a['revenue'] == $b['revenue'] ) { return 0; }
        return ( $a['revenue'] < $b['revenue'] ) ? 1 : -1;
      }
      if ( $a['qty'] == $b['qty'] ) { return 0; }
      return ( $a['qty'] < $b['qty'] ) ? 1 : -1;
    } );

    $top   = array_slice( array_values( $products ), 0, max( 1, (int) $limit ) );
    $peak  = 0;
    foreach ( $top as $product ) {
      $peak = max( $peak, $has_revenue ? $product['revenue'] : $product['qty'] );
    }

    foreach ( $top as $i => $product ) {
      $value = $has_revenue ? $product['revenue'] : $product['qty'];
      $top[ $i ]['share']       = $peak > 0 ? ( $value / $peak ) : 0;
      $top[ $i ]['has_revenue'] = $has_revenue;
    }

    return $top;
  }
}

/**
 * Where this coupon's referral clicks came from, and where they landed.
 *
 * The same grouping the admin Reports screen uses, narrowed to one coupon so it
 * can go to the affiliate who earned it.
 */
if ( ! function_exists( 'wcusage_get_report_traffic' ) ) {
  function wcusage_get_report_traffic( $coupon_id, $start, $end, $limit = 5 ) {

    global $wpdb;

    $table = $wpdb->prefix . 'wcusage_clicks';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
      return array();
    }

    $coupon_id = absint( $coupon_id );
    $limit     = max( 1, (int) $limit );

    // Clicks are stored with current_time('mysql'), i.e. local time, so the
    // period's own local dates bound them directly.
    $from = $start . ' 00:00:00';
    $to   = $end . ' 23:59:59';

    $traffic = array( 'referrers' => array(), 'pages' => array(), 'campaigns' => array(), 'direct' => null, 'total_clicks' => 0 );

    $traffic['total_clicks'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
      "SELECT COUNT(*) FROM {$table} WHERE couponid = %d AND date BETWEEN %s AND %s",
      $coupon_id, $from, $to
    ) );

    if ( ! $traffic['total_clicks'] ) {
      return $traffic;
    }

    // Landing pages
    $pages = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
      "SELECT page, COUNT(*) AS clicks, SUM(converted) AS conversions
       FROM {$table}
       WHERE couponid = %d AND date BETWEEN %s AND %s
       GROUP BY page ORDER BY clicks DESC LIMIT %d",
      $coupon_id, $from, $to, $limit
    ) );
    foreach ( (array) $pages as $page ) {
      $page_id = (int) $page->page;
      $title   = $page_id ? get_the_title( $page_id ) : esc_html__( 'Homepage', 'woo-coupon-usage' );
      if ( ! $title ) { $title = '#' . $page_id; }
      $traffic['pages'][] = array(
        'label'       => $title,
        'clicks'      => (int) $page->clicks,
        'conversions' => (int) $page->conversions,
      );
    }

    // Referring domains. Grouped in PHP because the column holds full URLs.
    $referrers = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
      "SELECT referrer, COUNT(*) AS clicks, SUM(converted) AS conversions
       FROM {$table}
       WHERE couponid = %d AND date BETWEEN %s AND %s AND referrer != ''
       GROUP BY referrer ORDER BY clicks DESC LIMIT 500",
      $coupon_id, $from, $to
    ) );
    $domains = array();
    foreach ( (array) $referrers as $referrer ) {
      $parsed = wp_parse_url( $referrer->referrer );
      $domain = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : $referrer->referrer;
      $domain = preg_replace( '/^www\./', '', $domain );
      if ( ! isset( $domains[ $domain ] ) ) {
        $domains[ $domain ] = array( 'label' => $domain, 'clicks' => 0, 'conversions' => 0 );
      }
      $domains[ $domain ]['clicks']      += (int) $referrer->clicks;
      $domains[ $domain ]['conversions'] += (int) $referrer->conversions;
    }
    uasort( $domains, function( $a, $b ) {
      return $b['clicks'] - $a['clicks'];
    } );
    $traffic['referrers'] = array_slice( array_values( $domains ), 0, $limit );

    // Campaigns
    $campaigns = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
      "SELECT campaign, COUNT(*) AS clicks, SUM(converted) AS conversions
       FROM {$table}
       WHERE couponid = %d AND date BETWEEN %s AND %s AND campaign != ''
       GROUP BY campaign ORDER BY clicks DESC LIMIT %d",
      $coupon_id, $from, $to, $limit
    ) );
    foreach ( (array) $campaigns as $campaign ) {
      $traffic['campaigns'][] = array(
        'label'       => $campaign->campaign,
        'clicks'      => (int) $campaign->clicks,
        'conversions' => (int) $campaign->conversions,
      );
    }

    // Direct (no referrer) traffic, so the mix adds up for the affiliate.
    $direct = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
      "SELECT COUNT(*) AS clicks, SUM(converted) AS conversions
       FROM {$table}
       WHERE couponid = %d AND date BETWEEN %s AND %s AND ( referrer = '' OR referrer IS NULL )",
      $coupon_id, $from, $to
    ) );
    if ( $direct && (int) $direct->clicks ) {
      $traffic['direct'] = array(
        'label'       => esc_html__( 'Direct / unknown', 'woo-coupon-usage' ),
        'clicks'      => (int) $direct->clicks,
        'conversions' => (int) $direct->conversions,
      );
    }

    return $traffic;
  }
}

/**
 * Commission balances and payout activity for the report footer.
 */
if ( ! function_exists( 'wcusage_get_report_commission' ) ) {
  function wcusage_get_report_commission( $coupon_id, $user_id, $start, $end, $include_payouts = true ) {

    $unpaid  = (float) get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true );
    $pending = (float) get_post_meta( $coupon_id, 'wcu_text_pending_payment_commission', true );

    $commission = array(
      'unpaid'         => $unpaid,
      'pending'        => $pending,
      'paid_in_period' => 0.0,
      'lifetime_paid'  => 0.0,
      'last_payout'    => null,
      'available'      => false,
    );

    if ( ! $include_payouts ) {
      return $commission;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wcusage_payouts';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
      return $commission;
    }

    $commission['available'] = true;

    $commission['paid_in_period'] = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
      "SELECT COALESCE( SUM( amount ), 0 ) FROM {$table}
       WHERE couponid = %d AND status = 'paid' AND datepaid BETWEEN %s AND %s",
      $coupon_id, $start . ' 00:00:00', $end . ' 23:59:59'
    ) );

    $commission['lifetime_paid'] = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
      "SELECT COALESCE( SUM( amount ), 0 ) FROM {$table} WHERE couponid = %d AND status = 'paid'",
      $coupon_id
    ) );

    $last = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
      "SELECT amount, datepaid FROM {$table}
       WHERE couponid = %d AND status = 'paid' ORDER BY datepaid DESC LIMIT 1",
      $coupon_id
    ) );
    if ( $last && $last->datepaid && $last->datepaid !== '0000-00-00 00:00:00' ) {
      $commission['last_payout'] = array(
        'amount' => (float) $last->amount,
        'date'   => $last->datepaid,
      );
    }

    return $commission;
  }
}

/**
 * Where this affiliate placed against the rest of the programme.
 *
 * Off by default: it is motivating for the affiliates at the top and tells
 * everyone something about everyone else, so it is the site owner's call.
 */
if ( ! function_exists( 'wcusage_get_report_rank' ) ) {
  function wcusage_get_report_rank( $user_id, $start, $end ) {

    // A batch run asks for the standings once per affiliate. The leaderboard
    // query itself is transient-cached, but loading every coupon and sorting the
    // table is not, so the ordered list is held for the life of the request.
    static $standings = array();

    $key = $start . '|' . $end;

    if ( ! isset( $standings[ $key ] ) ) {

      $coupons = get_posts( array(
        'posts_per_page' => -1,
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'fields'         => 'all',
      ) );

      $affiliates = wcusage_leaderboard_date_range( $coupons, $start, $end, 60 );

      uasort( $affiliates, function( $a, $b ) {
        if ( $a['total_commission'] == $b['total_commission'] ) { return 0; }
        return ( $a['total_commission'] < $b['total_commission'] ) ? 1 : -1;
      } );

      $standings[ $key ] = $affiliates;
    }

    $affiliates = $standings[ $key ];

    if ( empty( $affiliates ) || ! isset( $affiliates[ $user_id ] ) ) {
      return null;
    }

    $position = 0;
    $index    = 0;
    foreach ( $affiliates as $affiliate_id => $stats ) {
      $index++;
      if ( (int) $affiliate_id === (int) $user_id ) {
        $position = $index;
        break;
      }
    }

    if ( ! $position ) {
      return null;
    }

    $total = count( $affiliates );

    return array(
      'position'   => $position,
      'total'      => $total,
      'percentile' => $total > 1 ? ( 1 - ( ( $position - 1 ) / $total ) ) * 100 : 100,
    );
  }
}


