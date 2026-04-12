<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Displays the latest orders tab content on affiliate dashboard
 *
 * @param int $postid
 * @param string $coupon_code
 * @param date $wcu_orders_start
 * @param date $wcu_orders_end
 * @param date $isordersstartset
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_tab_latest_orders',
    'wcusage_tab_latest_orders',
    10,
    10
);
if ( !function_exists( 'wcusage_tab_latest_orders' ) ) {
    function wcusage_tab_latest_orders(
        $postid,
        $coupon_code,
        $wcu_orders_start,
        $wcu_orders_end,
        $isordersstartset,
        $show_status = "",
        $limit = "",
        $header = true,
        $footer = true,
        $page = 1
    ) {
        if ( function_exists( 'wcusage_requests_session_check' ) ) {
            $requests_session = wcusage_requests_session_check( $postid );
        } else {
            $requests_session = array(
                'status'  => false,
                'message' => '',
            );
        }
        if ( isset( $requests_session['status'] ) && $requests_session['status'] ) {
            echo esc_html( $requests_session['message'] );
        } else {
            $couponinfo = wcusage_get_coupon_info_by_id( $postid );
            $couponuser = $couponinfo[1];
            $currentuserid = get_current_user_id();
            $wcusage_urlprivate = wcusage_get_setting_value( 'wcusage_field_urlprivate', '1' );
            // Check if user is parent affiliate
            $is_mla_parent = "";
            if ( function_exists( 'wcusage_network_check_sub_affiliate' ) ) {
                $is_mla_parent = wcusage_network_check_sub_affiliate( $currentuserid, $couponuser );
            }
            // Check to make sure not set to private, coupon is assigned to current user, or is admin
            if ( $is_mla_parent || !$couponuser && !$wcusage_urlprivate || $couponuser == $currentuserid || wcusage_check_admin_access() ) {
                $options = get_option( 'wcusage_options' );
                $option_show_orderid = wcusage_get_setting_value( 'wcusage_field_orderid', '0' );
                $option_show_status = wcusage_get_setting_value( 'wcusage_field_status', '1' );
                $option_show_ordercountry = wcusage_get_setting_value( 'wcusage_field_ordercountry', '0' );
                $option_show_ordercity = wcusage_get_setting_value( 'wcusage_field_ordercity', '0' );
                $option_show_ordername = wcusage_get_setting_value( 'wcusage_field_ordername', '0' );
                $option_show_ordernamelast = wcusage_get_setting_value( 'wcusage_field_ordernamelast', '0' );
                $option_show_amount = wcusage_get_setting_value( 'wcusage_field_amount', '1' );
                $option_show_amount_saved = wcusage_get_setting_value( 'wcusage_field_amount_saved', '1' );
                $option_show_shipping = wcusage_get_setting_value( 'wcusage_field_show_shipping', '0' );
                $option_show_tax = wcusage_get_setting_value( 'wcusage_field_show_order_tax', '0' );
                $option_show_list_products = wcusage_get_setting_value( 'wcusage_field_list_products', '1' );
                $wcusage_show_commission = wcusage_get_setting_value( 'wcusage_field_show_commission', '1' );
                if ( !$isordersstartset ) {
                    $isordersstartset = false;
                }
                /* Get If Page Load */
                global $woocommerce;
                $c = new WC_Coupon($coupon_code);
                $the_coupon_usage = $c->get_usage_count();
                $wcusaFge_page_load = wcusage_get_setting_value( 'wcusage_field_page_load', '' );
                //if($the_coupon_usage > 5000) { $wcusage_page_load = 1; }
                /**/
                $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', '1' );
                $wcusage_field_order_sort = wcusage_get_setting_value( 'wcusage_field_order_sort', '' );
                if ( !$wcusage_field_load_ajax ) {
                    // Filter Orders Submitted
                    if ( isset( $_POST['submitordersfilter'] ) ) {
                        if ( !$wcusage_page_load ) {
                            echo "<script>jQuery( document ).ready(function() { jQuery( '.tabrecentorders' ).click(); });</script>";
                        }
                        $wcu_orders_start = sanitize_text_field( $_POST['wcu_orders_start'] );
                        $wcu_orders_start = preg_replace( "([^0-9-])", "", $wcu_orders_start );
                        $wcu_orders_end = sanitize_text_field( $_POST['wcu_orders_end'] );
                        $wcu_orders_end = preg_replace( "([^0-9-])", "", $wcu_orders_end );
                    }
                    if ( $wcu_orders_start == "" ) {
                        $wcu_orders_start = "";
                    } else {
                        $isordersstartset = true;
                    }
                    if ( $wcu_orders_end == "" ) {
                        $wcu_orders_end = date( "Y-m-d" );
                    }
                }
                // "All Time" mode: start date is empty but isordersstartset is true
                // Treat as a date-range query so stats boxes and load more/all show
                $is_alltime = $isordersstartset && $wcu_orders_start === '';
                if ( $is_alltime ) {
                    $wcu_orders_start = 'alltime';
                }
                // Orders to Show
                $wcusage_field_show_order_tab = wcusage_get_setting_value( 'wcusage_field_show_order_tab', '1' );
                if ( !$limit ) {
                    $option_coupon_orders = wcusage_get_setting_value( 'wcusage_field_orders', '15' );
                    if ( $wcu_orders_start || $isordersstartset ) {
                        $option_coupon_orders = "";
                    }
                } else {
                    $option_coupon_orders = $limit;
                }
                // Fetch slightly more orders than needed as a safety margin.
                // Some orders may be skipped in rendering due to coupon ownership
                // checks or other filters. The rendering loop breaks at the actual
                // $option_coupon_orders / $per_page.
                $query_limit = $option_coupon_orders;
                if ( $page === 'all' ) {
                    // Show All: remove query limit so all orders are fetched
                    $query_limit = "";
                } elseif ( $show_status && !$wcu_orders_start ) {
                    // When a status filter is active without a date range, the SQL query
                    // fetches all statuses but the rendering loop only shows matching ones.
                    // Remove the query limit so enough matching orders can be found to fill
                    // the page; the rendering loop still breaks at $per_page.
                    $query_limit = "";
                } elseif ( $query_limit && intval( $query_limit ) > 0 ) {
                    // For non-date-range page 2+, fetch enough orders to cover all pages
                    $int_page = max( 1, intval( $page ) );
                    $query_limit = intval( $query_limit ) * $int_page + 10;
                }
                $orders = false;
                // For the SQL query, pass empty string for alltime so the fallback date (0001-01-01) is used
                $sql_start_date = ( $is_alltime ? '' : $wcu_orders_start );
                // For paginated date-range requests (page 2+), try cached orders first
                if ( $wcu_orders_start && $page > 1 ) {
                    $cache_key = 'wcu_orders_' . md5( $coupon_code . $wcu_orders_start . $wcu_orders_end . $option_coupon_orders );
                    $orders = get_transient( $cache_key );
                }
                if ( !$orders || !is_array( $orders ) ) {
                    $orders = wcusage_wh_getOrderbyCouponCode(
                        $coupon_code,
                        $sql_start_date,
                        $wcu_orders_end,
                        $query_limit,
                        1
                    );
                    $orders = array_reverse( $orders );
                    // Cache the result for 5 minutes so subsequent page clicks are instant
                    if ( $wcu_orders_start ) {
                        $cache_key = 'wcu_orders_' . md5( $coupon_code . $wcu_orders_start . $wcu_orders_end . $option_coupon_orders );
                        set_transient( $cache_key, $orders, 5 * MINUTE_IN_SECONDS );
                    }
                }
                // Show Table
                if ( $wcusage_field_show_order_tab && ($option_coupon_orders > 0 || $option_coupon_orders == "") ) {
                    do_action(
                        'wcusage_hook_show_latest_orders_table',
                        $orders,
                        "",
                        $wcu_orders_start,
                        $wcu_orders_end,
                        "",
                        $show_status,
                        $postid,
                        $header,
                        $footer,
                        $page,
                        $option_coupon_orders
                    );
                }
            }
        }
    }

}
/**
 * Displays the latest orders tab content on affiliate dashboard
 *
 * @param int $postid
 * @param string $type
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_show_latest_orders_table',
    'wcusage_show_latest_orders_table',
    10,
    11
);
if ( !function_exists( 'wcusage_show_latest_orders_table' ) ) {
    function wcusage_show_latest_orders_table(
        $orders,
        $type,
        $wcu_orders_start,
        $wcu_orders_end,
        $user_id = "",
        $show_status = "",
        $postid = "",
        $header = true,
        $footer = true,
        $page = 1,
        $limit = ""
    ) {
        $options = get_option( 'wcusage_options' );
        if ( !$user_id ) {
            $user_id = get_current_user_id();
        }
        $option_show_orderid = wcusage_get_setting_value( 'wcusage_field_orderid', '0' );
        $wcusage_field_orderid_click = wcusage_get_setting_value( 'wcusage_field_orderid_click', '0' );
        $option_show_date = wcusage_get_setting_value( 'wcusage_field_date', '1' );
        $option_show_time = wcusage_get_setting_value( 'wcusage_field_time', '0' );
        $option_show_status = wcusage_get_setting_value( 'wcusage_field_status', '1' );
        $option_show_ordercountry = wcusage_get_setting_value( 'wcusage_field_ordercountry', '0' );
        $option_show_ordercity = wcusage_get_setting_value( 'wcusage_field_ordercity', '0' );
        $option_show_ordername = wcusage_get_setting_value( 'wcusage_field_ordername', '0' );
        $option_show_ordernamelast = wcusage_get_setting_value( 'wcusage_field_ordernamelast', '0' );
        $option_show_amount = wcusage_get_setting_value( 'wcusage_field_amount', '1' );
        $option_show_amount_saved = wcusage_get_setting_value( 'wcusage_field_amount_saved', '1' );
        $option_show_shipping = wcusage_get_setting_value( 'wcusage_field_show_shipping', '0' );
        $option_show_tax = wcusage_get_setting_value( 'wcusage_field_show_order_tax', '0' );
        $option_show_list_products = wcusage_get_setting_value( 'wcusage_field_list_products', '1' );
        $wcusage_show_commission = wcusage_get_setting_value( 'wcusage_field_show_commission', '1' );
        // Check if disable non affiliate commission
        $disable_commission = wcusage_coupon_disable_commission( $postid );
        if ( $disable_commission ) {
            $wcusage_show_commission = 0;
        }
        // Always show commission column on MLA dashboard (it shows what the parent earned)
        if ( $type == "mla" ) {
            $wcusage_show_commission = 1;
        }
        $wcusage_show_orders_table_status_totals = wcusage_get_setting_value( 'wcusage_field_show_orders_table_status_totals', '1' );
        $option_coupon_orders = wcusage_get_setting_value( 'wcusage_field_orders', '15' );
        $customdaterange = false;
        $per_page = intval( $option_coupon_orders );
        if ( !$per_page || $per_page < 1 ) {
            $per_page = 15;
        }
        if ( $wcu_orders_start ) {
            $customdaterange = true;
            // When date range is set, use per_page for pagination instead of showing all
            // Use a sensible default if the setting is very small
            if ( $per_page < 15 ) {
                $per_page = 15;
            }
        }
        // If an explicit limit was passed (e.g. Latest Referrals = 5), cap per_page
        if ( $limit !== '' && intval( $limit ) > 0 ) {
            $per_page = intval( $limit );
        }
        // Pagination setup
        $show_all = $page === 'all';
        $page = ( $show_all ? 1 : max( 1, intval( $page ) ) );
        $wcusage_field_order_sort = wcusage_get_setting_value( 'wcusage_field_order_sort', '' );
        echo "<div class='coupon-orders-list'>";
        // Show stats info boxes only on the full Recent Orders tab with a date range selected
        if ( $header && $footer && $customdaterange ) {
            $wcusage_show_commission = wcusage_get_setting_value( 'wcusage_field_show_commission', '1' );
            $disable_commission = wcusage_coupon_disable_commission( $postid );
            if ( $disable_commission ) {
                $wcusage_show_commission = 0;
            }
            $stats_total_orders = ( isset( $orders['total_orders'] ) ? (float) $orders['total_orders'] : 0 );
            $stats_full_discount = ( isset( $orders['full_discount'] ) ? (float) $orders['full_discount'] : 0 );
            $stats_total_commission = ( isset( $orders['total_commission'] ) ? (float) $orders['total_commission'] : 0 );
            $stats_total_count = ( isset( $orders['total_count'] ) ? (int) $orders['total_count'] : 0 );
            $stats_net_sales = $stats_total_orders - $stats_full_discount;
            echo '<div class="wcusage-orders-date-stats" style="margin-bottom: 20px;">';
            echo '<div class="wcusage-info-box wcusage-info-box-usage"><p><span class="wcusage-info-box-title">' . esc_html__( 'Orders', 'woo-coupon-usage' ) . ':</span> ' . esc_html( $stats_total_count ) . '</p></div>';
            if ( wcusage_get_setting_value( 'wcusage_field_statistics_commissionearnings_total', '1' ) ) {
                echo '<div class="wcusage-info-box wcusage-info-box-sales"><p><span class="wcusage-info-box-title">' . esc_html__( 'Total Sales', 'woo-coupon-usage' ) . ':</span> ' . wp_kses_post( wcusage_format_price( number_format(
                    $stats_net_sales,
                    2,
                    '.',
                    ''
                ) ) ) . '</p></div>';
            }
            if ( wcusage_get_setting_value( 'wcusage_field_statistics_commissionearnings_discounts', '1' ) ) {
                echo '<div class="wcusage-info-box wcusage-info-box-discounts"><p><span class="wcusage-info-box-title">' . esc_html__( 'Total Discounts', 'woo-coupon-usage' ) . ':</span> ' . wp_kses_post( wcusage_format_price( number_format(
                    $stats_full_discount,
                    2,
                    '.',
                    ''
                ) ) ) . '</p></div>';
            }
            if ( $wcusage_show_commission && wcusage_get_setting_value( 'wcusage_field_statistics_commissionearnings_commission', '1' ) && $stats_total_commission > 0 ) {
                echo '<div class="wcusage-info-box wcusage-info-box-dollar"><p><span class="wcusage-info-box-title">' . esc_html__( 'Total Commission', 'woo-coupon-usage' ) . ':</span> ' . wp_kses_post( wcusage_format_price( number_format(
                    $stats_total_commission,
                    2,
                    '.',
                    ''
                ) ) ) . '</p></div>';
            }
            // Status totals below the stats boxes (date-range aggregate counts)
            $range_status_counts = ( isset( $orders['status_counts'] ) ? $orders['status_counts'] : array() );
            if ( $wcusage_show_orders_table_status_totals && $option_show_status && !empty( $range_status_counts ) ) {
                echo '<div class="wcusage-orders-status-totals" style="flex: 0 0 100%; text-align: center; padding: 8px 0 0; clear: both;">';
                foreach ( $range_status_counts as $st_name => $st_count ) {
                    $st_color = '#000';
                    if ( $st_name == 'Completed' ) {
                        $st_color = 'green';
                    }
                    if ( $st_name == 'Pending' ) {
                        $st_color = 'cyan';
                    }
                    if ( $st_name == 'Processing' ) {
                        $st_color = 'orange';
                    }
                    if ( $st_name == 'Refunded' ) {
                        $st_color = 'red';
                    }
                    if ( $st_name == 'Cancelled' ) {
                        $st_color = 'red';
                    }
                    echo '<i class="fa-solid fa-circle-dot" style="color: ' . esc_attr( $st_color ) . ';"></i> ' . esc_html( $st_name ) . ': ' . esc_html( $st_count ) . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        $totalcount = count( $orders );
        $completedorders = 0;
        ?>

    <!-- Mobile Reponsive Labels -->
    <?php 
        $wcusage_ro_label_count = 1;
        ?>
    <style>
    @media only screen and (max-width: 760px), (min-device-width: 768px) and (max-device-width: 1024px)  {

      .listtheproducts { display: none; }
      .listtheproducts td:before { content: "" !important; }
      .listtheproducts { padding: 10px; margin-top: -5px !important; margin-bottom: 20px !important; }
      .wcuTableFoot:nth-of-type(1):before { content: "" !important; }
      .wcuTableFoot:nth-of-type(2):before { content: "" !important; }
      .wcuTableFoot:nth-of-type(9):before { content: "" !important; }
      .wcuTableFoot:nth-of-type(10):before { content: "" !important; }
      .wcuTableFoot:nth-of-type(11):before { content: "" !important; }
      .wcuTableFoot:nth-of-type(12):before { content: "" !important; }

      <?php 
        if ( $option_show_orderid ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "ID", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $type == "mla" ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( 'Coupon', 'woo-coupon-usage' );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_date ) {
            ?>
      .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Date", "woo-coupon-usage" );
            ?>"; }
      <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>      

      <?php 
        if ( $option_show_time ) {
            ?>
      .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Time", "woo-coupon-usage" );
            ?>"; }
      <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_status ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( 'Status', 'woo-coupon-usage' );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_amount ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( 'Subtotal', 'woo-coupon-usage' );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_amount_saved ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Discount", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_amount ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Total", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $wcusage_show_commission ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Commission", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_shipping ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "Shipping"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_tax ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "Tax"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_ordercountry ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Country", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_ordercity ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "City", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_ordername || $option_show_ordernamelast ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Name", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

      <?php 
        if ( $option_show_list_products ) {
            ?>
        .wcu-table-recent-orders td:nth-of-type(<?php 
            echo esc_html( $wcusage_ro_label_count );
            ?>):before { content: "<?php 
            echo esc_html__( "Products", "woo-coupon-usage" );
            ?>"; }
        <?php 
            $wcusage_ro_label_count++;
            ?>
      <?php 
        }
        ?>

    }
    </style>

    <!-- Recent Orders Table -->
    <table id='wcuTable2' class='wcuTable wcu-table-recent-orders' border='2'>

    <?php 
        if ( $header ) {
            ?>
    <thead valign="top">

      <tr class='wcuTableRow'>

        <?php 
            if ( $option_show_orderid ) {
                ?><th class='wcuTableHead'><?php 
                echo esc_html__( 'ID', 'woo-coupon-usage' );
                ?></th><?php 
            }
            ?>

        <?php 
            if ( $type == "mla" ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Coupon', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_date ) {
                ?>
          <th class='wcuTableHead' style='width: 25%;'><?php 
                echo esc_html__( 'Date', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_time ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Time', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_status ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Status', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_amount ) {
                ?><th class='wcuTableHead'><?php 
                echo esc_html__( 'Subtotal', 'woo-coupon-usage' );
                ?></th><?php 
            }
            ?>

        <?php 
            if ( $option_show_amount_saved ) {
                ?><th class='wcuTableHead'><?php 
                echo esc_html__( 'Discount', 'woo-coupon-usage' );
                ?></th><?php 
            }
            ?>

        <?php 
            if ( $option_show_amount ) {
                ?><th class='wcuTableHead'><?php 
                echo esc_html__( 'Total', 'woo-coupon-usage' );
                ?></th><?php 
            }
            ?>

        <?php 
            if ( $option_show_tax ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Tax', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $orders['total_commission'] > 0 && $wcusage_show_commission ) {
                ?>
        <th class='wcuTableHead'>
          <?php 
                echo esc_html__( 'Commission', 'woo-coupon-usage' );
                ?>
        </th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_shipping ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Shipping', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_ordercountry ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Country', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_ordercity ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'City', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_ordername || $option_show_ordernamelast ) {
                ?>
          <th class='wcuTableHead'><?php 
                echo esc_html__( 'Name', 'woo-coupon-usage' );
                ?></th>
        <?php 
            }
            ?>

        <?php 
            if ( $option_show_list_products == "1" ) {
                ?>
          <th class='wcuTableHead'> </th>
        <?php 
            }
            ?>

      </tr>

    </thead>
    <?php 
        }
        ?>

    <?php 
        $count_orders = 0;
        $currentid = 0;
        $combined_total_discount = 0;
        $combined_shipping = 0;
        $combined_ordertotal = 0;
        $combined_ordertotaldiscounted = 0;
        $combined_totalcommission = 0;
        $colstatus = 0;
        $coltime = 0;
        $i = 0;
        $count = 0;
        $skip_count = 0;
        $cols = 0;
        $col1 = 0;
        $col2 = 0;
        $col3 = 0;
        $col4 = 0;
        $col5 = 0;
        $col6 = 0;
        $col7 = 0;
        $col8 = 0;
        $col9 = 0;
        $col10 = 0;
        $col11 = 0;
        $colmla = 0;
        $total_statuses = array();
        // Build a lookup map of order_id => raw SQL status so we can check status
        // during pagination skips without loading full WC_Order objects.
        $order_status_map = array();
        if ( isset( $orders['orders'] ) && is_array( $orders['orders'] ) ) {
            foreach ( $orders['orders'] as $raw_order ) {
                if ( isset( $raw_order->order_id ) && isset( $raw_order->order_status ) ) {
                    $order_status_map[$raw_order->order_id] = str_replace( 'wc-', '', $raw_order->order_status );
                }
            }
        }
        // Resolve coupon code once before the loop (avoids new WC_Coupon per iteration)
        $loop_coupon_code = get_the_title( $postid );
        if ( !$loop_coupon_code ) {
            $loop_coupon_obj = new WC_Coupon($postid);
            $loop_coupon_code = $loop_coupon_obj->get_code();
        }
        // Calculate offset for pagination
        // Date-range: offset based on page (unless show_all)
        // Non-date-range: offset based on page for Show More support
        if ( $show_all ) {
            $offset = 0;
        } else {
            $offset = ($page - 1) * $per_page;
        }
        $loop_broken = false;
        foreach ( $orders as $item ) {
            if ( isset( $orders[$i]['order_id'] ) ) {
                $orderid = $orders[$i]['order_id'];
            } else {
                $orderid = "";
            }
            if ( $currentid != $orderid ) {
                if ( !$orderid ) {
                    break;
                }
                $currentid = $orderid;
                // For paginated views, skip orders before the current page offset
                // Only count orders that would actually be displayed (pass status filters)
                if ( $offset > 0 && $skip_count < $offset ) {
                    // Use the pre-built status map from SQL results to avoid loading WC_Order objects
                    if ( isset( $order_status_map[$orderid] ) ) {
                        $skip_status = $order_status_map[$orderid];
                        $skip_status_show = wcusage_check_status_show( $skip_status );
                        // Also respect the status dropdown filter
                        $skip_status_match = !$show_status || 'wc-' . $skip_status === $show_status;
                        // Only count toward offset if this order would actually render
                        if ( $skip_status !== 'refunded' && $skip_status_show && $skip_status_match ) {
                            $skip_count++;
                        }
                    }
                    $i++;
                    continue;
                }
                $orderinfo = wc_get_order( $orderid );
                if ( $orderinfo ) {
                    // Count
                    $i++;
                    // Check if order can be shown by current status
                    $status = $orderinfo->get_status();
                    $check_status_show = wcusage_check_status_show( $status );
                    // MLA Tier Commission
                    $tier = 0;
                    $totalcommissionmla = 0;
                    if ( $type == "mla" ) {
                        $lifetimeaffiliate = wcusage_order_meta( $orderid, 'lifetime_affiliate_coupon_referrer' );
                        $affiliatereferrer = wcusage_order_meta( $orderid, 'wcusage_referrer_coupon' );
                        if ( $lifetimeaffiliate ) {
                            $coupon_info = wcusage_get_coupon_info( $lifetimeaffiliate );
                            $coupon_user_id = $coupon_info[1];
                            if ( $coupon_user_id ) {
                                $get_parents = get_user_meta( $coupon_user_id, 'wcu_ml_affiliate_parents', true );
                                if ( is_array( $get_parents ) ) {
                                    $tier = array_search( $user_id, $get_parents );
                                }
                            }
                        } elseif ( $affiliatereferrer ) {
                            $coupon_info = wcusage_get_coupon_info( $affiliatereferrer );
                            $coupon_user_id = $coupon_info[1];
                            if ( $coupon_user_id ) {
                                $get_parents = get_user_meta( $coupon_user_id, 'wcu_ml_affiliate_parents', true );
                                if ( is_array( $get_parents ) ) {
                                    $tier = array_search( $user_id, $get_parents );
                                }
                            }
                        } else {
                            foreach ( $orderinfo->get_coupon_codes() as $coupon_code ) {
                                $coupon = new WC_Coupon($coupon_code);
                                $couponid = $coupon->get_id();
                                $coupon_user_id = get_post_meta( $couponid, 'wcu_select_coupon_user', true );
                                if ( $coupon_user_id ) {
                                    $get_parents = get_user_meta( $coupon_user_id, 'wcu_ml_affiliate_parents', true );
                                    if ( is_array( $get_parents ) ) {
                                        $tier = array_search( $user_id, $get_parents );
                                    }
                                }
                            }
                        }
                    }
                    // Show Order
                    if ( (!$show_status || "wc-" . $status == $show_status) && $check_status_show && ($tier || $type != "mla") ) {
                        $count_orders++;
                        $enablecurrency = wcusage_get_setting_value( 'wcusage_field_enable_currency', '0' );
                        if ( $orderinfo ) {
                            $currencycode = $orderinfo->get_currency();
                        }
                        $offset = get_option( 'gmt_offset' );
                        $order_date = date_i18n( "F j, Y", strtotime( $orderinfo->get_date_created() ) + $offset * HOUR_IN_SECONDS );
                        if ( $orderinfo ) {
                            $completed_date = $orderinfo->get_date_completed();
                            if ( $completed_date ) {
                                $completed_date = date_i18n( "F j, Y", strtotime( $completed_date ) + $offset * HOUR_IN_SECONDS );
                            } else {
                                $completed_date = "";
                            }
                        }
                        if ( $wcusage_field_order_sort != "completeddate" ) {
                            $showdate = $order_date;
                            $showtime = get_the_time( 'U', $orderid );
                            $showtime = date_i18n( "g:i a", $showtime );
                        } else {
                            $showdate = $completed_date;
                            $showtime = strtotime( $orderinfo->get_date_completed() );
                            $showtime = date_i18n( "g:i a", $showtime );
                        }
                        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '0' );
                        $wcusage_currency_conversion = wcusage_order_meta( $orderid, 'wcusage_currency_conversion', true );
                        $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
                        if ( !$wcusage_currency_conversion || !$enable_save_rate ) {
                            $wcusage_currency_conversion = "";
                        }
                        $include_shipping_tax = 0;
                        $shipping = 0;
                        if ( $orderinfo->get_total_shipping() ) {
                            if ( $wcusage_show_tax ) {
                                $include_shipping_tax = wcusage_get_order_tax_percent( $orderid );
                            }
                            $shipping = $orderinfo->get_total_shipping() * (1 + $include_shipping_tax);
                        }
                        if ( $enablecurrency ) {
                            $shipping = wcusage_calculate_currency( $currencycode, $shipping, $wcusage_currency_conversion );
                        }
                        $combined_shipping += (float) $shipping;
                        if ( $wcusage_show_tax == 1 ) {
                            $total_tax = 0;
                        } else {
                            $total_tax = $orderinfo->get_total_tax();
                        }
                        $coupon_code = $loop_coupon_code;
                        $calculateorder = wcusage_calculate_order_data(
                            $orderid,
                            $coupon_code,
                            0,
                            1
                        );
                        $ordertotal = $calculateorder['totalorders'];
                        $combined_ordertotal += (float) $ordertotal;
                        $ordertotaldiscounted = $calculateorder['totalordersexcl'];
                        $combined_ordertotaldiscounted += (float) $ordertotaldiscounted;
                        $totalorders = $calculateorder['totalorders'];
                        $totaldiscounts = $calculateorder['totaldiscounts'];
                        $combined_total_discount += (float) $totaldiscounts;
                        $totalordersexcl = $calculateorder['totalordersexcl'];
                        $totalcommission = $calculateorder['totalcommission'];
                        $wcusage_field_mla_enable = wcusage_get_setting_value( 'wcusage_field_mla_enable', '0' );
                        if ( $wcusage_field_mla_enable && $type == "mla" ) {
                            // Try stored MLA commission from order meta (persisted at order time)
                            $stored_mla = wcusage_order_meta( $orderid, 'wcu_mla_commission', true );
                            if ( is_array( $stored_mla ) && isset( $stored_mla[$tier]['commission'] ) ) {
                                $totalcommission = (float) $stored_mla[$tier]['commission'];
                            } else {
                                // Fallback: recalculate (for orders before this feature was added)
                                $totalcommission = wcusage_mla_get_commission_from_tier(
                                    $totalcommission,
                                    $tier,
                                    '1',
                                    $orderid,
                                    $coupon_code,
                                    0,
                                    $user_id
                                );
                                // Persist the recalculated value so it won't recalculate again next time
                                $tier_rates = ( function_exists( 'wcusage_mla_get_tier_rates' ) ? wcusage_mla_get_tier_rates( $tier, $user_id ) : array() );
                                if ( !is_array( $stored_mla ) ) {
                                    $stored_mla = array();
                                }
                                $stored_mla[$tier] = array(
                                    'parent_id'  => (int) $user_id,
                                    'commission' => round( (float) $totalcommission, 2 ),
                                    'rates'      => $tier_rates,
                                );
                                $mla_order_obj = wc_get_order( $orderid );
                                if ( $mla_order_obj ) {
                                    $mla_order_obj->update_meta_data( 'wcu_mla_commission', json_encode( $stored_mla ) );
                                    $mla_order_obj->save_meta_data();
                                }
                            }
                        }
                        $combined_totalcommission += (float) $totalcommission;
                        $affiliatecommission = "";
                        if ( isset( $calculateorder['affiliatecommission'] ) ) {
                            $affiliatecommission = $calculateorder['affiliatecommission'];
                        }
                        $currency = $orderinfo->get_currency();
                        $order_refunds = $orderinfo->get_refunds();
                        // Get subscription renewal icon if exist
                        $subicon = wcusage_get_sub_order_icon( $orderid );
                        $random = wp_rand();
                        ?>

              <!-- Script for toggling list of products section -->
              <script>
              jQuery( document ).ready(function() {
                jQuery( "#listproductsbutton-<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?>" ).click(function() {
                  jQuery( ".wcuTableCell.orderproductstd<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?> .fa-chevron-down" ).toggle();
                  jQuery( ".wcuTableCell.orderproductstd<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?> .fa-chevron-up" ).toggle();
                  jQuery( ":not(#listproducts-<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?>).listtheproducts" ).hide();
                  jQuery( "#listproducts-<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?>" ).toggle();
                  jQuery( "#listproductsb-<?php 
                        echo esc_html( $random ) . "-" . esc_html( $orderid );
                        ?>" ).toggle();
                });
              });
              </script>

              <tr class='wcuTableRow'>
                <?php 
                        // Order ID
                        if ( $option_show_orderid ) {
                            echo "<td class='wcuTableCell'>";
                            if ( $wcusage_field_orderid_click && wcusage_check_admin_access() ) {
                                echo "<a href='" . esc_url( admin_url( 'post.php?post=' . $orderid . '&action=edit' ) ) . "' target='_blank' title='" . esc_html__( 'View Order in Backend (Admin Only)', 'woo-coupon-usage' ) . "'>";
                            }
                            echo "#" . esc_html( $orderid );
                            if ( $wcusage_field_orderid_click && wcusage_check_admin_access() ) {
                                echo "</a>";
                            }
                            echo "</td>";
                            $col1 = true;
                        }
                        if ( $type == "mla" ) {
                            echo "<td class='wcuTableCell'>";
                            foreach ( $orderinfo->get_coupon_codes() as $coupon_code ) {
                                $coupon = new WC_Coupon($coupon_code);
                                $couponid = $coupon->get_id();
                                $coupon_user_id = get_post_meta( $couponid, 'wcu_select_coupon_user', true );
                                $coupon_user_info = get_user_by( 'ID', $coupon_user_id );
                                $coupon_user_name = $coupon_user_info->user_login;
                                echo "<span title='User: " . esc_attr( $coupon_user_name ) . "'><span class='fa-solid fa-tags' style='font-size: 12px; display: inline; margin-right: 5px;'></span>" . esc_html( $coupon_code ) . "</span><br/>";
                            }
                            echo "</td>";
                            $colmla = true;
                        }
                        // Date
                        if ( $option_show_date ) {
                            echo "<td class='wcuTableCell'>";
                            if ( $completed_date && $wcusage_field_order_sort == "completeddate" ) {
                                echo "<span title='Completed Date: " . esc_html( $completed_date ) . "'>" . wp_kses_post( $subicon ) . esc_html( ucfirst( $showdate ) ) . "</span>";
                            } else {
                                echo "<span title='Order Date: " . esc_html( $order_date ) . "'>" . wp_kses_post( $subicon ) . esc_html( ucfirst( $showdate ) ) . "</span>";
                            }
                            echo "</td>";
                        }
                        // Time
                        if ( $option_show_time ) {
                            echo "<td class='wcuTableCell wcuTableCell-time'>";
                            echo "<span>" . esc_html( $showtime ) . "</span>";
                            echo "</td>";
                            $coltime = true;
                        }
                        // Status
                        if ( $option_show_status ) {
                            if ( $wcusage_show_orders_table_status_totals ) {
                                $the_status = ucfirst( wc_get_order_status_name( $orderinfo->get_status() ) );
                                if ( !isset( $total_statuses[$the_status] ) ) {
                                    $total_statuses[$the_status] = 1;
                                } else {
                                    $total_statuses[$the_status]++;
                                }
                            }
                            echo "<td class='wcuTableCell'>" . esc_html( ucfirst( wc_get_order_status_name( $orderinfo->get_status() ) ) ) . "</td>";
                            $colstatus = true;
                        }
                        // Total
                        if ( $option_show_amount != "0" ) {
                            echo "<td class='wcuTableCell'> " . wp_kses_post( wcusage_format_price( $ordertotal ) ) . "</td>";
                            $col2 = true;
                        }
                        if ( $option_show_amount_saved != "0" ) {
                            echo "<td class='wcuTableCell'> " . wp_kses_post( wcusage_format_price( number_format(
                                (float) $totaldiscounts,
                                2,
                                '.',
                                ''
                            ) ) ) . "</td>";
                            $col3 = true;
                        }
                        if ( $option_show_amount != 0 ) {
                            echo "<td class='wcuTableCell'> " . wp_kses_post( wcusage_format_price( number_format(
                                (float) $ordertotaldiscounted,
                                2,
                                '.',
                                ''
                            ) ) ) . "</td>";
                        }
                        // Tax
                        if ( $option_show_tax != "0" ) {
                            echo "<td class='wcuTableCell'> " . wp_kses_post( wcusage_format_price( $orderinfo->get_total_tax() ) ) . "</td>";
                            $col11 = true;
                        }
                        // Commission
                        if ( $orders['total_commission'] > 0 && $wcusage_show_commission ) {
                            echo "<td class='wcuTableCell'> ";
                            if ( $type == "mla" ) {
                                echo "<span title='Your commission earned from this sub-affiliate referral.'>";
                            }
                            echo wp_kses_post( wcusage_format_price( number_format(
                                (float) $totalcommission,
                                2,
                                '.',
                                ''
                            ) ) );
                            if ( $type == "mla" ) {
                                echo "</span>";
                            }
                            echo "</td>";
                            $col5 = true;
                        }
                        // Shipping
                        if ( $option_show_shipping != "0" ) {
                            echo "<td class='wcuTableCell'> " . wp_kses_post( wcusage_format_price( $shipping ) ) . "</td>";
                            $col6 = true;
                        }
                        // Country
                        $zone_country = $orderinfo->get_billing_country();
                        if ( $option_show_ordercountry ) {
                            echo "<td class='wcuTableCell'> " . esc_html( $zone_country ) . "</td>";
                            $col8 = true;
                        }
                        // City
                        $zone_city = $orderinfo->get_billing_city();
                        if ( $option_show_ordercity ) {
                            echo "<td class='wcuTableCell'> " . esc_html( $zone_city ) . "</td>";
                            $col9 = true;
                        }
                        // Name
                        if ( $option_show_ordername || $option_show_ordernamelast ) {
                            echo "<td class='wcuTableCell'> ";
                            // Billing Name
                            $zone_name = $orderinfo->get_billing_first_name();
                            $zone_name_last = $orderinfo->get_billing_last_name();
                            if ( $zone_name || $zone_name_last ) {
                                if ( $option_show_ordername ) {
                                    echo esc_html( $zone_name );
                                }
                                if ( $option_show_ordernamelast ) {
                                    echo " " . esc_html( $zone_name_last );
                                }
                            } else {
                                // Show the users username instead.
                                $user_info = get_userdata( $orderinfo->get_user_id() );
                                if ( $user_info ) {
                                    $username = $user_info->user_login;
                                    if ( strlen( $username ) > 20 ) {
                                        $username = substr( $username, 0, 20 ) . "..";
                                    }
                                    echo esc_html( $username );
                                } else {
                                    echo "";
                                }
                            }
                            echo "</td>";
                            $col10 = true;
                        }
                        /* Show the "MORE" products list column / toggle on table */
                        if ( $option_show_list_products == "1" ) {
                            if ( $orderinfo->get_items() && $orderinfo->get_status() != "refunded" && $orderinfo->get_status() != "cancelled" && $orderinfo->get_status() != "failed" ) {
                                echo "<td class='wcuTableCell excludeThisClass orderproductstd orderproductstd" . esc_attr( $random ) . "-" . esc_html( $orderid ) . "' style='min-width: 100px; font-size: 16px;'>";
                                echo "<a class='listproductsbutton' href='javascript:void(0);' id='listproductsbutton-" . esc_attr( $random ) . "-" . esc_html( $orderid ) . "'>" . esc_html__( "MORE", "woo-coupon-usage" ) . " <i class='fas fa-chevron-down'></i> <i class='fas fa-chevron-up' style='display: none;'></i></i></i></a>";
                            } else {
                                echo "<td class='wcuTableCell excludeThisClass orderproductstd'>";
                            }
                            echo "</td>";
                            $col7 = true;
                            $cols++;
                        }
                        $totalorders = 0;
                        $totaldiscounts = 0;
                        $totalcommission = 0;
                        ?>
              </tr>

              <?php 
                        // Cols Count
                        $cols = $wcusage_ro_label_count + 1;
                        /* Show the "MORE" products list section */
                        if ( $option_show_list_products == "1" ) {
                            if ( $orderinfo->get_items() ) {
                                $order_summary = $calculateorder['commission_summary'];
                                if ( isset( $order_summary ) ) {
                                    $extracols = $wcusage_ro_label_count - 7;
                                    $productcols = 2 + $extracols - 1;
                                    ?>

                <span class="excludeThisClass">

                  <tbody style="margin-bottom: 15px;" id="listproducts-<?php 
                                    echo esc_html( $random ) . "-" . esc_html( $orderid );
                                    ?>" class="listtheproducts listtheproducts-summary excludeThisClass"<?php 
                                    if ( $option_show_list_products ) {
                                        ?> style="display: none;"<?php 
                                    }
                                    ?>>

                    <?php 
                                    do_action(
                                        'wcusage_hook_get_detailed_products_summary_tr',
                                        $orderinfo,
                                        $order_summary,
                                        $productcols,
                                        $tier,
                                        $postid
                                    );
                                    ?>

                  </tbody>

                  <tbody id="listproductsb-<?php 
                                    echo esc_html( $random ) . "-" . esc_html( $orderid );
                                    ?>" style="display: none;" class="excludeThisClass">
                    <tr class="listtheproducts listtheproducts-small excludeThisClass">
                      <?php 
                                    do_action(
                                        'wcusage_hook_get_basic_list_order_products',
                                        $orderinfo,
                                        $order_refunds,
                                        $cols
                                    );
                                    ?>
                    </tr>
                  </tbody>

                </span>

                <?php 
                                }
                            }
                        }
                        ?>

            <?php 
                        $completedorders = $completedorders + 1;
                    }
                    if ( !$show_all && $completedorders >= $per_page ) {
                        // Stop at per_page for both date-range and non-date-range
                        $loop_broken = true;
                        break;
                    }
                }
            }
        }
        ?>

    <?php 
        $wcusage_show_orders_table_totals = wcusage_get_setting_value( 'wcusage_field_show_orders_table_totals', '1' );
        if ( $footer && $wcusage_show_orders_table_totals ) {
            ?>

      <?php 
            if ( $completedorders > 0 ) {
                ?>
      <tfoot valign="top">
        <tr class='wcuTableRow'>

          <?php 
                if ( $col1 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                echo "<td class='wcuTableFoot wcufoot-count'><strong>" . esc_html__( "Totals", "woo-coupon-usage" ) . ": (<span class='wcufoot-count-val'>" . esc_html( $count_orders ) . "</span>) </strong></td>";
                if ( $colstatus ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $coltime ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $colmla ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $col2 ) {
                    echo "<td class='wcuTableFoot wcufoot-subtotal' data-raw='" . esc_attr( number_format(
                        (float) $combined_ordertotal,
                        2,
                        '.',
                        ''
                    ) ) . "'><strong>" . wp_kses_post( wcusage_format_price( number_format(
                        (float) $combined_ordertotal,
                        2,
                        '.',
                        ''
                    ) ) ) . "</strong></td>";
                }
                if ( $col3 ) {
                    echo "<td class='wcuTableFoot wcufoot-discount' data-raw='" . esc_attr( number_format(
                        (float) $combined_total_discount,
                        2,
                        '.',
                        ''
                    ) ) . "'><strong>" . wp_kses_post( wcusage_format_price( number_format(
                        (float) $combined_total_discount,
                        2,
                        '.',
                        ''
                    ) ) ) . "</strong></td>";
                }
                echo "<td class='wcuTableFoot wcufoot-total' data-raw='" . esc_attr( number_format(
                    (float) $combined_ordertotaldiscounted,
                    2,
                    '.',
                    ''
                ) ) . "'><strong>" . wp_kses_post( wcusage_format_price( number_format(
                    (float) $combined_ordertotaldiscounted,
                    2,
                    '.',
                    ''
                ) ) ) . "</strong></td>";
                if ( $col5 ) {
                    echo "<td class='wcuTableFoot wcufoot-commission' data-raw='" . esc_attr( number_format(
                        (float) $combined_totalcommission,
                        2,
                        '.',
                        ''
                    ) ) . "'><strong>" . wp_kses_post( wcusage_format_price( number_format(
                        (float) $combined_totalcommission,
                        2,
                        '.',
                        ''
                    ) ) ) . "</strong></td>";
                }
                if ( $col6 ) {
                    echo "<td class='wcuTableFoot wcufoot-shipping' data-raw='" . esc_attr( number_format(
                        (float) $combined_shipping,
                        2,
                        '.',
                        ''
                    ) ) . "'><strong>" . wp_kses_post( wcusage_format_price( number_format(
                        (float) $combined_shipping,
                        2,
                        '.',
                        ''
                    ) ) ) . "</strong></td>";
                }
                $finalcolspan = 1;
                if ( $col8 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $col9 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $col10 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $col7 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                if ( $col11 ) {
                    echo "<td class='wcuTableFoot'></td>";
                }
                ?>
        </tr>
      </tfoot>
      <?php 
            }
            ?>

    <?php 
        }
        ?>

    </table>
    <?php 
        // No orders found
        if ( $completedorders == 0 ) {
            echo "<p>" . esc_html__( "No orders found.", "woo-coupon-usage" ) . "<p>";
            echo "<style>.wcu-table-recent-orders { display: none; }</style>";
        }
        // Status totals below table (non-date-range views only; date-range ones are under the stats boxes)
        if ( !$customdaterange && $wcusage_show_orders_table_status_totals && $option_show_status && !empty( $total_statuses ) ) {
            echo "<div class='wcuOrdersStatuses' style='text-align: center; padding: 8px 0; margin-top: 15px; clear: both;'>";
            foreach ( $total_statuses as $status => $status_total ) {
                $color = "#000";
                if ( $status == "Completed" ) {
                    $color = "green";
                }
                if ( $status == "Pending" ) {
                    $color = "cyan";
                }
                if ( $status == "Processing" ) {
                    $color = "orange";
                }
                if ( $status == "Refunded" ) {
                    $color = "red";
                }
                if ( $status == "Cancelled" ) {
                    $color = "red";
                }
                echo '<i class="fa-solid fa-circle-dot" style="color: ' . esc_attr( $color ) . ';"></i> ' . esc_html( $status ) . ': ' . esc_html( $status_total ) . "";
                echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            }
            echo "</div>";
        }
        // Show More button (for views with more orders to load)
        // Show buttons when:
        // 1. The page was filled ($loop_broken = true), meaning there are likely more orders, OR
        // 2. A status filter is active, the loop consumed all fetched orders without filling
        //    the page, and there could be more matching orders beyond the query limit.
        $show_more_btn = false;
        if ( !$show_all && $completedorders > 0 && $limit === '' ) {
            if ( $loop_broken ) {
                // Page was filled — there are more orders
                if ( $customdaterange ) {
                    $total_visible = ( isset( $orders['total_count'] ) ? (int) $orders['total_count'] : $totalcount );
                    $show_more_btn = $page * $per_page < $total_visible;
                } else {
                    $show_more_btn = true;
                }
            } elseif ( $show_status && !$customdaterange ) {
                // Status filter active on non-date-range view: the query had a limit,
                // so there may be more matching orders beyond what was fetched.
                // Show the buttons if the fetched count hit the query limit cap.
                $query_limit_expected = $per_page * $page + 10;
                $show_more_btn = $totalcount >= $query_limit_expected;
            }
        }
        if ( $show_more_btn ) {
            $next_page = $page + 1;
            echo '<div class="wcusage-orders-show-more" style="text-align: center; margin: 20px 0; clear: both;">';
            echo '<a href="javascript:void(0);" class="wcusage-show-more-btn" data-next-page="' . esc_attr( $next_page ) . '" style="display: inline-block; padding: 10px 30px; background: #f0f0f0; border-radius: 4px; text-decoration: none; color: #333; font-weight: 500; border: 1px solid #ddd; cursor: pointer;">' . esc_html__( 'Show More', 'woo-coupon-usage' ) . ' <i class="fa-solid fa-chevron-down"></i></a>';
            echo '&nbsp;&nbsp;';
            echo '<a href="javascript:void(0);" class="wcusage-show-all-btn" style="display: inline-block; padding: 10px 30px; background: #f0f0f0; border-radius: 4px; text-decoration: none; color: #333; font-weight: 500; border: 1px solid #ddd; cursor: pointer;">' . esc_html__( 'Show All', 'woo-coupon-usage' ) . ' <i class="fa-solid fa-angles-down"></i></a>';
            echo '</div>';
        }
        echo "</div>";
    }

}
/**
 * Gets the filters for latest orders
 *
 * @param date $wcu_orders_start
 * @param date $wcu_orders_end
 * @param string $coupon_code
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_tab_latest_orders_filters',
    'wcusage_tab_latest_orders_filters',
    10,
    4
);
if ( !function_exists( 'wcusage_tab_latest_orders_filters' ) ) {
    function wcusage_tab_latest_orders_filters(
        $wcu_orders_start,
        $wcu_orders_end,
        $coupon_code,
        $mla = 0
    ) {
        $options = get_option( 'wcusage_options' );
        $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', '1' );
        ?>

	<?php 
        ?>

	<div class="wcu-filters-col1">
		<div class="wcu-filters-inner">
			<div class="wcu-order-filters">

					<form <?php 
        if ( !$wcusage_field_load_ajax ) {
            ?>method="post" <?php 
        }
        ?>id="wcusage_settings_form_orders" class="wcusage_settings_form">
						<span class="wcu-order-filters-field">
							<select id="wcu-date-range-preset">
								<option value=""><?php 
        echo esc_html__( "Date Range", "woo-coupon-usage" );
        ?></option>
								<option value="alltime"><?php 
        echo esc_html__( "All Time", "woo-coupon-usage" );
        ?></option>
								<option value="today"><?php 
        echo esc_html__( "Today", "woo-coupon-usage" );
        ?></option>
								<option value="yesterday"><?php 
        echo esc_html__( "Yesterday", "woo-coupon-usage" );
        ?></option>
								<option value="last7"><?php 
        echo esc_html__( "Last 7 Days", "woo-coupon-usage" );
        ?></option>
								<option value="last14"><?php 
        echo esc_html__( "Last 14 Days", "woo-coupon-usage" );
        ?></option>
								<option value="last30"><?php 
        echo esc_html__( "Last 30 Days", "woo-coupon-usage" );
        ?></option>
								<option value="last90"><?php 
        echo esc_html__( "Last 90 Days", "woo-coupon-usage" );
        ?></option>
								<option value="thismonth"><?php 
        echo esc_html__( "This Month", "woo-coupon-usage" );
        ?></option>
								<option value="lastmonth"><?php 
        echo esc_html__( "Last Month", "woo-coupon-usage" );
        ?></option>
								<option value="thisquarter"><?php 
        echo esc_html__( "This Quarter", "woo-coupon-usage" );
        ?></option>
								<option value="lastquarter"><?php 
        echo esc_html__( "Last Quarter", "woo-coupon-usage" );
        ?></option>
								<option value="thisyear"><?php 
        echo esc_html__( "This Year", "woo-coupon-usage" );
        ?></option>
								<option value="lastyear"><?php 
        echo esc_html__( "Last Year", "woo-coupon-usage" );
        ?></option>
							</select>
						</span>
						<span class="wcu-order-filters-space">&nbsp;</span>
						<span class="wcu-order-filters-field"><?php 
        echo esc_html__( "Start", "woo-coupon-usage" );
        ?>: <input type="date" id="wcu-orders-start" name="wcu_orders_start" value="<?php 
        echo esc_html( $wcu_orders_start );
        ?>"></span>
            <span class="wcu-order-filters-space">&nbsp;</span>
            <span class="wcu-order-filters-field"><?php 
        echo esc_html__( "End", "woo-coupon-usage" );
        ?>: <input type="date" id="wcu-orders-end" name="wcu_orders_end" value="<?php 
        echo esc_html( $wcu_orders_end );
        ?>"></span>
            <span class="wcu-order-filters-space">&nbsp;</span>
            <?php 
        $option_show_status = wcusage_get_setting_value( 'wcusage_field_status', '1' );
        $option_filter_status = wcusage_get_setting_value( 'wcusage_field_show_orders_table_filter_status', '1' );
        if ( $option_show_status && $option_filter_status ) {
            $orderstatuses = wc_get_order_statuses();
            $show_statuses = array();
            $show_statuses_num = 0;
            foreach ( $orderstatuses as $key => $status ) {
                $checked = false;
                $wcusage_field_order_type_custom = wcusage_get_setting_value( 'wcusage_field_order_type_custom', '' );
                if ( $wcusage_field_order_type_custom ) {
                    if ( isset( $options['wcusage_field_order_type_custom'][$key] ) ) {
                        if ( $options['wcusage_field_order_type_custom'][$key] ) {
                            $checked = true;
                        }
                    }
                }
                if ( $checked ) {
                    $show_statuses_num++;
                    array_push( $show_statuses, array(
                        $key => $status,
                    ) );
                }
            }
            if ( $show_statuses_num > 1 ) {
                ?>
              <?php 
                if ( $wcusage_field_load_ajax ) {
                    ?>
              <span class="wcu-order-filters-field">
                <?php 
                    echo esc_html__( "Status", "woo-coupon-usage" );
                    ?>: <select id="wcu-orders-status" name="wcu_orders_status">
                <?php 
                    echo '<option value="">' . esc_html__( "All", "woo-coupon-usage" ) . '</option>';
                    foreach ( $show_statuses as $status ) {
                        echo '<option value="' . esc_attr( key( $status ) ) . '">' . esc_html( reset( $status ) ) . '</option>';
                    }
                    ?>
                </select>
              </span>
              <?php 
                }
                ?>
              <span class="wcu-order-filters-space">&nbsp;</span>
              <?php 
            }
        }
        ?>
            <input type="text" name="page-orders" value="1" style="display: none;">
            <input type="text" name="load-page" value="1" style="display: none;">
            <input class="ordersfilterbutton" <?php 
        if ( $wcusage_field_load_ajax ) {
            ?>type="button"<?php 
        } else {
            ?>type="submit"<?php 
        }
        ?> id="wcu-orders-button" name="submitordersfilter"
            value="<?php 
        echo esc_html__( "Filter", "woo-coupon-usage" );
        ?>" onclick="wcusage_run_tab_page_orders<?php 
        if ( $mla ) {
            ?>_mla<?php 
        }
        ?>(1);">
					</form>

			</div>
		</div>
	</div>

	<div class="wcu-filters-col2">
		<div class="wcu-filters-inner">

			<?php 
        ?>
		</div>
	</div>

	<style>.wcu-loading-orders { display: none; }</style>

	<script>
	(function() {
		var preset = document.getElementById('wcu-date-range-preset');
		if (!preset) return;
		preset.addEventListener('change', function() {
			var val = this.value;
			if (!val) return;
			var today = new Date();
			var startDate, endDate;
			endDate = new Date(today);

			function formatDate(d) {
				var y = d.getFullYear();
				var m = ('0' + (d.getMonth() + 1)).slice(-2);
				var day = ('0' + d.getDate()).slice(-2);
				return y + '-' + m + '-' + day;
			}

			switch(val) {
				case 'alltime':
					document.getElementById('wcu-orders-start').value = '';
					document.getElementById('wcu-orders-end').value = formatDate(today);
					return;
				case 'today':
					startDate = new Date(today);
					break;
				case 'yesterday':
					startDate = new Date(today);
					startDate.setDate(startDate.getDate() - 1);
					endDate = new Date(startDate);
					break;
				case 'last7':
					startDate = new Date(today);
					startDate.setDate(startDate.getDate() - 6);
					break;
				case 'last14':
					startDate = new Date(today);
					startDate.setDate(startDate.getDate() - 13);
					break;
				case 'last30':
					startDate = new Date(today);
					startDate.setDate(startDate.getDate() - 29);
					break;
				case 'last90':
					startDate = new Date(today);
					startDate.setDate(startDate.getDate() - 89);
					break;
				case 'thismonth':
					startDate = new Date(today.getFullYear(), today.getMonth(), 1);
					break;
				case 'lastmonth':
					startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
					endDate = new Date(today.getFullYear(), today.getMonth(), 0);
					break;
				case 'thisyear':
					startDate = new Date(today.getFullYear(), 0, 1);
					break;
				case 'lastyear':
					startDate = new Date(today.getFullYear() - 1, 0, 1);
					endDate = new Date(today.getFullYear() - 1, 11, 31);
					break;
				case 'thisquarter':
					var q = Math.floor(today.getMonth() / 3);
					startDate = new Date(today.getFullYear(), q * 3, 1);
					break;
				case 'lastquarter':
					var q = Math.floor(today.getMonth() / 3);
					startDate = new Date(today.getFullYear(), (q - 1) * 3, 1);
					endDate = new Date(today.getFullYear(), q * 3, 0);
					break;
				default:
					return;
			}

			document.getElementById('wcu-orders-start').value = formatDate(startDate);
			document.getElementById('wcu-orders-end').value = formatDate(endDate);
		});

		// Reset preset dropdown when date fields are manually changed
		document.getElementById('wcu-orders-start').addEventListener('change', function() {
			preset.value = '';
		});
		document.getElementById('wcu-orders-end').addEventListener('change', function() {
			preset.value = '';
		});
	})();
	</script>

	<?php 
    }

}
/**
 * Gets latest orders tab for shortcode page
 *
 * @param int $postid
 * @param string $coupon_code
 * @param int $combined_commission
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_dashboard_tab_content_latest_orders',
    'wcusage_dashboard_tab_content_latest_orders',
    10,
    4
);
if ( !function_exists( 'wcusage_dashboard_tab_content_latest_orders' ) ) {
    function wcusage_dashboard_tab_content_latest_orders(
        $postid,
        $coupon_code,
        $combined_commission,
        $wcusage_page_load
    ) {
        // *** GET SETTINGS *** /
        $options = get_option( 'wcusage_options' );
        $language = wcusage_get_language_code();
        $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', 1 );
        $wcusage_field_load_ajax_per_page = wcusage_get_setting_value( 'wcusage_field_load_ajax_per_page', 1 );
        if ( !$wcusage_field_load_ajax ) {
            $wcusage_field_load_ajax_per_page = 0;
        }
        $wcusage_show_tabs = wcusage_get_setting_value( 'wcusage_field_show_tabs', '1' );
        $wcusage_justcoupon = wcusage_get_setting_value( 'wcusage_field_justcoupon', '1' );
        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '0' );
        $wcusage_hide_all_time = wcusage_get_setting_value( 'wcusage_field_hide_all_time', '0' );
        $wcusage_urlprivate = wcusage_get_setting_value( 'wcusage_field_urlprivate', '1' );
        if ( wcusage_check_admin_access() ) {
            $wcusage_urlprivate = 0;
        }
        $ajaxerrormessage = wcusage_ajax_error();
        $custom_orders_tab_name = wcusage_get_setting_value( 'wcusage_field_tab_name_orders', '' );
        // *** DISPLAY CONTENT *** //
        ?>

  <?php 
        $wcusage_orders_nonce = wp_create_nonce( 'wcusage_dashboard_ajax_nonce' );
        ?>
  <script>
  var wcusage_orders_current_page = 1;

  /**
   * Export a table element as a proper CSV file.
   * Skips rows with class excludeThisClass and rows inside excluded tbodies.
   * Skips completely empty rows.
   */
  function wcusage_export_table_csv($table, filename) {
    var csv = [];
    /* Process thead */
    $table.find('thead tr').each(function() {
      var row = [];
      jQuery(this).find('th, td').each(function() {
        row.push(wcusage_csv_cell(jQuery(this).text()));
      });
      if (row.length) csv.push(row.join(','));
    });
    /* Process tbody rows — skip excluded tbodies and excluded rows */
    $table.find('tbody').not('.excludeThisClass').find('tr').not('.excludeThisClass').each(function() {
      var row = [];
      var hasContent = false;
      jQuery(this).find('td, th').not('.excludeThisClass').each(function() {
        var cellText = jQuery(this).text().replace(/\s+/g, ' ').trim();
        if (cellText !== '') hasContent = true;
        row.push(wcusage_csv_cell(cellText));
      });
      if (row.length && hasContent) csv.push(row.join(','));
    });
    /* Process tfoot */
    $table.find('tfoot tr').each(function() {
      var row = [];
      jQuery(this).find('td, th').each(function() {
        row.push(wcusage_csv_cell(jQuery(this).text()));
      });
      if (row.length) csv.push(row.join(','));
    });
    /* Build blob and trigger download */
    var bom = '\uFEFF';
    var blob = new Blob([bom + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }
  function wcusage_csv_cell(text) {
    text = text.replace(/\s+/g, ' ').trim();
    /* Escape double quotes and wrap in quotes if needed */
    if (text.indexOf(',') !== -1 || text.indexOf('"') !== -1 || text.indexOf('\n') !== -1) {
      text = '"' + text.replace(/"/g, '""') + '"';
    }
    return text;
  }

  function wcusage_show_all_orders() {
    /* Show All: single AJAX request that returns every order at once */
    jQuery('.wcusage-show-more-btn').css({'opacity': '0.6', 'pointer-events': 'none'});
    jQuery('.wcusage-show-all-btn').html('<?php 
        echo esc_js( __( 'Loading All...', 'woo-coupon-usage' ) );
        ?> <i class="fa-solid fa-spinner fa-spin"></i>').css({'opacity': '0.6', 'pointer-events': 'none'});
    var data = {
      action: 'wcusage_load_page_orders',
      _ajax_nonce: '<?php 
        echo esc_js( $wcusage_orders_nonce );
        ?>',
      postid: '<?php 
        echo esc_js( $postid );
        ?>',
      couponcode: '<?php 
        echo esc_js( $coupon_code );
        ?>',
      startdate: jQuery('input[name=wcu_orders_start]').val(),
      enddate: jQuery('input[name=wcu_orders_end]').val(),
      status: jQuery('#wcu-orders-status option').filter(':selected').val(),
      language: '<?php 
        echo esc_js( $language );
        ?>',
      page: 1,
      showall: '1',
      alltime: (jQuery('#wcu-date-range-preset').val() === 'alltime') ? '1' : '0',
    };
    jQuery.ajax({
      type: 'POST',
      url: '<?php 
        echo esc_url( admin_url( 'admin-ajax.php' ) );
        ?>',
      data: data,
      success: function(response) {
        /* Full replace: the response contains ALL orders, so swap everything */
        var $response = jQuery('<div/>').html(response);
        var $stats = $response.find('.wcusage-orders-date-stats').detach();
        if ($stats.length) {
          jQuery('.show_orders_stats').html($stats);
        }
        jQuery('.show_orders').html($response.html());
        jQuery('.show_orders_stats').show();
        /* Bind handlers in case any buttons are present */
        jQuery('.show_orders .wcusage-show-more-btn').on('click', function(e) {
          e.preventDefault();
          wcusage_run_tab_page_orders(jQuery(this).data('next-page'), true);
        });
        jQuery('.show_orders .wcusage-show-all-btn').on('click', function(e) {
          e.preventDefault();
          wcusage_show_all_orders();
        });
      },
      error: function() {
        jQuery('.wcusage-show-more-btn').css({'opacity': '1', 'pointer-events': 'auto'});
        jQuery('.wcusage-show-all-btn').html('<?php 
        echo esc_js( __( 'Error - try again', 'woo-coupon-usage' ) );
        ?> <i class="fa-solid fa-angles-down"></i>').css({'opacity': '1', 'pointer-events': 'auto'});
      }
    });
  }
  function wcusage_run_tab_page_orders(page, append) {
    if (typeof page === 'undefined' || !page) { page = 1; }
    if (typeof append === 'undefined') { append = false; }
    wcusage_orders_current_page = page;

    /* 3 second disable on click button */
    jQuery("#wcu-orders-button").css("opacity", "0.5");
    jQuery("#wcu-orders-button").css("pointer-events", "none");
    setTimeout(function() {
      jQuery("#wcu-orders-button").css("opacity", "1");
      jQuery("#wcu-orders-button").css("pointer-events", "auto");
    }, 3 * 1000);

    if (append) {
      /* Show More mode: show a small loader on the button, keep existing content */
      jQuery('.wcusage-show-more-btn').html('<?php 
        echo esc_js( __( 'Loading...', 'woo-coupon-usage' ) );
        ?> <i class="fa-solid fa-spinner fa-spin"></i>').css({'opacity': '0.6', 'pointer-events': 'none'});
      jQuery('.wcusage-show-all-btn').css({'opacity': '0.4', 'pointer-events': 'none'});
    } else {
      /* Full load mode: clear and show spinner */
      jQuery('.show_orders').html('');
      jQuery('.show_orders_stats').hide();
      jQuery('.wcu-loading-orders').show();
    }

    /* Ajax request */
    var data = {
      action: 'wcusage_load_page_orders',
      _ajax_nonce: '<?php 
        echo esc_js( $wcusage_orders_nonce );
        ?>',
      postid: '<?php 
        echo esc_js( $postid );
        ?>',
      couponcode: '<?php 
        echo esc_js( $coupon_code );
        ?>',
      startdate: jQuery('input[name=wcu_orders_start]').val(),
      enddate: jQuery('input[name=wcu_orders_end]').val(),
      status: jQuery('#wcu-orders-status option').filter(":selected").val(),
      language: '<?php 
        echo esc_js( $language );
        ?>',
      page: page,
      alltime: (jQuery('#wcu-date-range-preset').val() === 'alltime') ? '1' : '0',
    };
    jQuery.ajax({
      type: 'POST',
      url: '<?php 
        echo esc_url( admin_url( 'admin-ajax.php' ) );
        ?>',
      data: data,
      success: function(response) {

      if (append) {
        /* === SHOW MORE: append rows and update totals === */
        var $response = jQuery('<div/>').html(response);
        var $newTable = $response.find('.wcu-table-recent-orders');

        /* Append new order rows (and their product list tbodies) to existing table.
         * The new response contains a full table. We grab everything between
         * thead and tfoot — the main tbody rows and any listtheproducts tbodies —
         * and append them in order to preserve the correct row sequence. */
        var $existingTable = jQuery('.show_orders .wcu-table-recent-orders');
        var $existingTfoot = $existingTable.find('tfoot');
        /* Remove thead and tfoot from the new table, leaving only the content rows */
        $newTable.find('thead').remove();
        var $newTfoot = $newTable.find('tfoot').detach();
        /* All remaining children of the table are tbody elements (implicit + product lists) */
        $newTable.children('tbody').each(function() {
          $existingTfoot.before(jQuery(this));
        });

        /* Update tfoot totals by adding new page values to existing */
        var footFields = ['subtotal', 'discount', 'total', 'commission', 'shipping'];
        for (var f = 0; f < footFields.length; f++) {
          var cls = 'wcufoot-' + footFields[f];
          var $existing = jQuery('.show_orders .wcu-table-recent-orders tfoot .' + cls);
          var $incoming = $newTfoot.find('.' + cls);
          if ($existing.length && $incoming.length) {
            var oldVal = parseFloat($existing.attr('data-raw')) || 0;
            var newVal = parseFloat($incoming.attr('data-raw')) || 0;
            var combined = (oldVal + newVal).toFixed(2);
            $existing.attr('data-raw', combined);
            /* Replace the number in the existing cell's HTML while preserving currency formatting */
            var existingHtml = $existing.html();
            /* Match a number pattern like 1,234.56 or 1234.56 inside the <strong> */
            var updatedHtml = existingHtml.replace(/[\d,]+\.\d{2}/, combined.replace(/\B(?=(\d{3})+(?!\d))/g, ','));
            $existing.html(updatedHtml);
          }
        }

        /* Update the count */
        var $existingCount = jQuery('.show_orders .wcu-table-recent-orders tfoot .wcufoot-count .wcufoot-count-val');
        var $incomingCount = $newTfoot.find('.wcufoot-count .wcufoot-count-val');
        if ($existingCount.length && $incomingCount.length) {
          var oldCount = parseInt($existingCount.text()) || 0;
          var newCount = parseInt($incomingCount.text()) || 0;
          $existingCount.text(oldCount + newCount);
        }

        /* Update status counts */
        var $newStatuses = $response.find('.wcuOrdersStatuses');
        if ($newStatuses.length) {
          var $existingStatuses = jQuery('.show_orders .wcuOrdersStatuses');
          if ($existingStatuses.length) {
            /* Merge status counts */
            $newStatuses.find('i').each(function() {
              var textNode = this.nextSibling;
              if (!textNode || textNode.nodeType !== 3) return;
              var statusText = textNode.textContent.trim();
              /* Parse "StatusName: N" */
              var parts = statusText.split(':');
              if (parts.length === 2) {
                var sName = parts[0].trim();
                var sCount = parseInt(parts[1].trim()) || 0;
                /* Find matching status in existing */
                $existingStatuses.find('i').each(function() {
                  var existNode = this.nextSibling;
                  if (!existNode || existNode.nodeType !== 3) return;
                  var existText = existNode.textContent.trim();
                  if (existText.indexOf(sName + ':') === 0) {
                    var existParts = existText.split(':');
                    var existCount = parseInt(existParts[1].trim()) || 0;
                    existNode.textContent = ' ' + sName + ': ' + (existCount + sCount) + '\u00A0\u00A0\u00A0\u00A0\u00A0';
                    return false;
                  }
                });
              }
            });
          } else {
            jQuery('.show_orders .wcu-table-recent-orders').after($newStatuses);
          }
        }

        /* Replace the Show More button with the new one (or remove if no more) */
        jQuery('.show_orders .wcusage-orders-show-more').remove();
        var $newShowMore = $response.find('.wcusage-orders-show-more');
        if ($newShowMore.length) {
          jQuery('.show_orders .coupon-orders-list').append($newShowMore);
          /* Bind click handlers to new buttons */
          $newShowMore.find('.wcusage-show-more-btn').on('click', function(e) {
            e.preventDefault();
            var nextPage = jQuery(this).data('next-page');
            wcusage_run_tab_page_orders(nextPage, true);
          });
          $newShowMore.find('.wcusage-show-all-btn').on('click', function(e) {
            e.preventDefault();
            wcusage_show_all_orders();
          });
        }

      } else {
        /* === FULL LOAD: replace everything === */
        jQuery('#wcu3 .wcuTable').remove();
        var $response = jQuery('<div/>').html(response);
        var $stats = $response.find('.wcusage-orders-date-stats').detach();
        if (page <= 1 || jQuery('.show_orders_stats').is(':empty')) {
          if ($stats.length) {
            jQuery('.show_orders_stats').html($stats);
          } else {
            jQuery('.show_orders_stats').empty();
          }
        }
        jQuery('.show_orders').html($response.html());
        jQuery('.show_orders_stats').show();
        jQuery('.wcu-loading-orders').hide();

        /* Bind Show More button click handler */
        jQuery('.show_orders .wcusage-show-more-btn').on('click', function(e) {
          e.preventDefault();
          var nextPage = jQuery(this).data('next-page');
          wcusage_run_tab_page_orders(nextPage, true);
        });
        /* Bind Show All button click handler */
        jQuery('.show_orders .wcusage-show-all-btn').on('click', function(e) {
          e.preventDefault();
          wcusage_show_all_orders();
        });
      }

      },
      error: function(response) {
      jQuery('.show_orders').html('<?php 
        echo wp_kses_post( $ajaxerrormessage );
        ?>');
      }
    });
  }

  jQuery(document).ready(function() {
    <?php 
        if ( $wcusage_field_load_ajax_per_page ) {
            ?>
    jQuery("#tab-page-orders").one('click', function() { wcusage_run_tab_page_orders(1); });
    <?php 
        }
        ?>
    jQuery(".wcusage-refresh-data").on('click', function() { wcusage_run_tab_page_orders(1); });

    /* Export button handler */
    jQuery('#exportBtn2').on('click', function() {
      var startDate = jQuery('input[name=wcu_orders_start]').val();
      var isAllTime = (jQuery('#wcu-date-range-preset').val() === 'alltime');
      var $btn = jQuery(this);
      if (startDate || isAllTime) {
        /* Date range is set: fetch ALL orders via AJAX then export as CSV */
        $btn.prop('disabled', true).css('opacity', '0.6').text('<?php 
        echo esc_js( __( 'Exporting...', 'woo-coupon-usage' ) );
        ?>');
        jQuery.ajax({
          type: 'POST',
          url: '<?php 
        echo esc_url( admin_url( 'admin-ajax.php' ) );
        ?>',
          data: {
            action: 'wcusage_load_page_orders',
            _ajax_nonce: '<?php 
        echo esc_js( $wcusage_orders_nonce );
        ?>',
            postid: '<?php 
        echo esc_js( $postid );
        ?>',
            couponcode: '<?php 
        echo esc_js( $coupon_code );
        ?>',
            startdate: startDate,
            enddate: jQuery('input[name=wcu_orders_end]').val(),
            status: jQuery('#wcu-orders-status option').filter(':selected').val(),
            language: '<?php 
        echo esc_js( $language );
        ?>',
            page: 1,
            showall: '1',
            alltime: isAllTime ? '1' : '0',
          },
          success: function(response) {
            var $response = jQuery('<div/>').html(response);
            var $fullTable = $response.find('.wcu-table-recent-orders');
            if ($fullTable.length) {
              wcusage_export_table_csv($fullTable, "<?php 
        echo esc_js( ( !empty( $custom_orders_tab_name ) ? $custom_orders_tab_name : __( 'Referred Orders', 'woo-coupon-usage' ) ) );
        ?> - <?php 
        echo esc_js( $coupon_code );
        ?>.csv");
            }
            $btn.prop('disabled', false).css('opacity', '1').html('<?php 
        echo esc_js( __( 'Export', 'woo-coupon-usage' ) );
        ?> <i class="fa-solid fa-download"></i>');
          },
          error: function() {
            $btn.prop('disabled', false).css('opacity', '1').html('<?php 
        echo esc_js( __( 'Export', 'woo-coupon-usage' ) );
        ?> <i class="fa-solid fa-download"></i>');
          }
        });
      } else {
        /* No date range: export the currently displayed orders */
        var $table = jQuery('.show_orders .wcu-table-recent-orders');
        if ($table.length) {
          wcusage_export_table_csv($table, "<?php 
        echo esc_js( ( !empty( $custom_orders_tab_name ) ? $custom_orders_tab_name : __( 'Referred Orders', 'woo-coupon-usage' ) ) );
        ?> - <?php 
        echo esc_js( $coupon_code );
        ?>.csv");
        }
      }
    });
  });
  </script>

  <?php 
        if ( isset( $_POST['page-orders'] ) || !isset( $_POST['load-page'] ) || $wcusage_page_load == false ) {
            ?>

    <?php 
            // Get orders date filters
            $isordersstartset = false;
            $wcu_orders_start = "";
            $wcu_orders_end = "";
            if ( !$wcusage_field_load_ajax ) {
                if ( isset( $_POST['submitordersfilter'] ) ) {
                    $wcu_orders_start = sanitize_text_field( $_POST['wcu_orders_start'] );
                    $wcu_orders_start = preg_replace( "([^0-9-])", "", $wcu_orders_start );
                    $wcu_orders_end = sanitize_text_field( $_POST['wcu_orders_end'] );
                    $wcu_orders_end = preg_replace( "([^0-9-])", "", $wcu_orders_end );
                }
            }
            if ( $wcu_orders_start == "" ) {
                $wcu_orders_start = "";
            } else {
                $isordersstartset = true;
            }
            if ( $wcu_orders_end == "" ) {
                $wcu_orders_end = date( "Y-m-d" );
            }
            ?>

    <?php 
            if ( isset( $_POST['page-orders'] ) ) {
                ?>
    <style>#wcu3 { display: block;  }</style>
    <?php 
            }
            ?>

    <div id="wcu3" <?php 
            if ( $wcusage_show_tabs == '1' || $wcusage_show_tabs == '' ) {
                ?>class="wcutabcontent"<?php 
            }
            ?>>

      <?php 
            $orders_tab_title = ( !empty( $custom_orders_tab_name ) ? esc_html( $custom_orders_tab_name ) : esc_html__( "Referred Orders", "woo-coupon-usage" ) );
            echo "<p class='wcu-tab-title coupon-orders-list-title' style='font-size: 22px; margin-bottom: 0;'>" . $orders_tab_title . ":</p>";
            // Referred Orders
            ?>

      <?php 
            do_action(
                'wcusage_hook_tab_latest_orders_filters',
                $wcu_orders_start,
                $wcu_orders_end,
                $coupon_code
            );
            ?>
      <div style="clear: both;"></div>

      <?php 
            if ( $wcusage_field_load_ajax ) {
                ?>

        <div class="show_orders_stats"></div>
        <div class="show_orders"></div>

        <div class="wcu-loading-image wcu-loading-orders">
          <div class="wcu-loading-loader">
            <div class="loader"></div>
          </div>
          <p class="wcu-loading-loader-text"><br/><?php 
                echo esc_html__( "Loading", "woo-coupon-usage" );
                ?>...</p>
        </div>

      <?php 
            } else {
                ?>

        <?php 
                do_action(
                    'wcusage_hook_tab_latest_orders',
                    $postid,
                    $coupon_code,
                    $wcu_orders_start,
                    $wcu_orders_end,
                    $isordersstartset
                );
                ?>

      <?php 
            }
            ?>

    </div>

    <div style="width: 100%; clear: both;"></div>

  <?php 
        }
        ?>

  <?php 
    }

}