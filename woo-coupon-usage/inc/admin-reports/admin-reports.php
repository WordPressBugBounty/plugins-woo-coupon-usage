<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Displays the admin reports page
 *
 */
if ( !function_exists( 'wcusage_admin_reports_page_html' ) ) {
    function wcusage_admin_reports_page_html() {
        $options = get_option( 'wcusage_options' );
        $wcusage_field_tracking_enable = wcusage_get_setting_value( 'wcusage_field_tracking_enable', 1 );
        // Check user capabilities
        if ( !wcusage_check_admin_access() ) {
            return;
        }
        ?>

  <link rel="stylesheet" href="<?php 
        echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL ) . 'fonts/font-awesome/css/all.min.css';
        ?>" crossorigin="anonymous">

  <div class="wrap admin-reports" style="margin: 0;">

  <?php 
        echo do_action( 'wcusage_hook_dashboard_page_header', '' );
        ?>

  <h1><?php 
        echo esc_html__( "Admin Reports & Analytics", "woo-coupon-usage" );
        ?></h1>

  <p style="color: #333;">
    <i class="fas fa-info-circle"></i> <?php 
        echo esc_html__( 'With admin reports, you can view statistics and analytics for all your coupons and affiliates.', 'woo-coupon-usage' );
        ?> <a href="https://couponaffiliates.com/docs/admin-reports-analytics" target="_blank">Learn More</a>.
  </p>

  <br/>

  <!----- Filters ---->
  <?php 
        if ( wcu_fs()->can_use_premium_code() ) {
            $defaultdays = "-1 month";
            $wcu_orders_date_min = "";
        } else {
            $defaultdays = "-1 month";
            $wcu_orders_date_min = date( "Y-m-d", strtotime( "-1 month" ) );
        }
        $wcu_orders_date_max = date( "Y-m-d" );
        $wcu_monthly_orders_start = date( "Y-m-d", strtotime( $defaultdays ) );
        $wcu_monthly_orders_end = date( "Y-m-d" );
        $wcu_monthly_orders_start_compare = date( "Y-m-d", strtotime( $defaultdays, strtotime( $wcu_monthly_orders_start ) ) );
        $wcu_monthly_orders_end_compare = date( "Y-m-d", strtotime( $defaultdays, strtotime( $wcu_monthly_orders_end ) ) );
        ?>
  <div>
      <form method="post" class="wcusage_settings_form wcu-admin-reports-form"
      onsubmit="return false;" style="background: linear-gradient(#fefefe, #f6f6f6); border: 1px solid #f3f3f3; box-shadow: 0px 0px 4px #dbdada;">

      <h2 style="margin: 0 auto 20px auto; display: block; font-size: 25px; text-align: center;"><?php 
        echo esc_html__( "Generate a new admin report", "woo-coupon-usage" );
        ?>:</h2>

      <div class="admin-report-form-row">

      <!-- Main Date Range -->
      <p style="padding-top: 0; margin-top: 0;">
        <span class="wcu-order-filters-field wcu-order-filters-field-date">
          <?php 
        echo esc_html__( "Start", "woo-coupon-usage" );
        ?>: <input type="date"
          min="<?php 
        echo esc_attr( $wcu_orders_date_min );
        ?>" max="<?php 
        echo esc_attr( $wcu_orders_date_max );
        ?>"
          id="wcu-orders-start" name="wcu_monthly_orders_start"
          value="<?php 
        echo esc_attr( $wcu_monthly_orders_start );
        ?>">
        </span>
        <span class="wcu-order-filters-space">&nbsp;</span>
        <span class="wcu-order-filters-field wcu-order-filters-field-date">
          <?php 
        echo esc_html__( "End", "woo-coupon-usage" );
        ?>: <input type="date"
          min="<?php 
        echo esc_attr( $wcu_orders_date_min );
        ?>" max="<?php 
        echo esc_attr( $wcu_orders_date_max );
        ?>"
          id="wcu-orders-end" name="wcu_monthly_orders_end"
          value="<?php 
        echo esc_attr( $wcu_monthly_orders_end );
        ?>">
        </span>
      </p>

      <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>
      <p style="color: #959595;">
        <?php 
            echo esc_html__( "Free version can display reports for up to the past 1 month.", "woo-coupon-usage" );
            ?>
        <br/>
        <?php 
            echo esc_html__( "Unlimited date range selection is available with the", "woo-coupon-usage" );
            ?> <a href="<?php 
            echo esc_url( get_admin_url() );
            ?>admin.php?page=wcusage-pricing&trial=true" style="color: green;">PRO version</a>.
      </p>
      <?php 
        }
        ?>

      <div <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>class="wcu-tooltip" style="opacity: 0.5;"<?php 
        }
        ?>>
      <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?><span class="wcu-tooltiptext"><?php 
            echo esc_html__( "Date comparisons and more filters are available with the", "woo-coupon-usage" );
            ?> <a href="<?php 
            echo esc_url( admin_url( 'admin.php?page=wcusage-pricing&trial=true' ) );
            ?>" style="color: green;">PRO version</a>.</span><?php 
        }
        ?>

        <!-- Compare Date Range -->
        <script>
        jQuery(document).ready(function(){
          jQuery(".wcu-report-compare-dates").hide();

          jQuery("#wcu_report_compare_to").on('change', function() {
            if (jQuery('#wcu_report_compare_to').is(':checked')) {
              jQuery(".wcu-report-compare-dates").show();
            } else {
              jQuery(".wcu-report-compare-dates").hide();
            }
          });
        });
        </script>

        <hr style="margin-top: 17px;" />

        <p><input type="checkbox" <?php 
        ?> value="true" style="margin-top: -2px;"> <strong><?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>(PRO) <?php 
        }
        echo esc_html__( "Compare with another date range", "woo-coupon-usage" );
        ?>.</strong></p>

        <div class="wcu-report-compare-dates" style="display: none;">

          <p <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>style="pointer-events: none;"<?php 
        }
        ?>>
            <span class="wcu-order-filters-field wcu-order-filters-field-date">
              <?php 
        echo esc_html__( "Start", "woo-coupon-usage" );
        ?>: <input type="date" <?php 
        ?> value="<?php 
        echo esc_attr( $wcu_monthly_orders_start_compare );
        ?>">
            </span>
            <span class="wcu-order-filters-space">&nbsp;</span>
            <span class="wcu-order-filters-field wcu-order-filters-field-date">
              <?php 
        echo esc_html__( "End", "woo-coupon-usage" );
        ?>: <input type="date" <?php 
        ?> value="<?php 
        echo esc_attr( $wcu_monthly_orders_end_compare );
        ?>">
            </span>
          </p>

          <div class="wcu-report-filtercompare-field">
            <p <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>style="pointer-events: none;"<?php 
        }
        ?>>
              <span class="wcu-order-filtercompare-field">
                <strong style="display: block; margin-bottom: 5px;"><?php 
        echo esc_html__( "Only show coupons where sales have", "woo-coupon-usage" );
        ?>:</strong>
                <select <?php 
        ?>>
                  <option value="both"><?php 
        echo esc_html__( "Increased or Decreased", "woo-coupon-usage" );
        ?></option>
                  <option value="more"><?php 
        echo esc_html__( "Increased", "woo-coupon-usage" );
        ?></option>
                  <option value="less"><?php 
        echo esc_html__( "Decreased", "woo-coupon-usage" );
        ?></option>
                </select>
                <?php 
        echo esc_html__( "by more than", "woo-coupon-usage" );
        ?>
                <input type="number" <?php 
        ?> value="0" style="max-width: 60px;" min="0" max="100%" required>%
              </span>
            </p>
          </div>

        </div>

      </div>

      <hr style="margin: 17px 0 15px 0;" />

      <p><input type="checkbox" id="wcu_report_users_only" name="wcu_report_users_only" value="true" style="margin-top: -2px;"> <strong><?php 
        echo esc_html__( "Only show coupons assigned to an affiliate user.", "woo-coupon-usage" );
        ?></strong></p>

      <hr style="margin: 17px 0;" />

      <strong>Statistics to display:</strong>
      <p style="margin-bottom: 10px">

      <!-- Compare Date Range -->
      <?php 
        $extrafilters = array(
            array("wcu_report_show_sales", ".wcu-order-filtersales-field", esc_html__( "Sales", "woo-coupon-usage" )),
            array("wcu_report_show_commission", ".wcu-order-filtercommission-field, .wcu-order-filterunpaid-field", esc_html__( "Commission", "woo-coupon-usage" )),
            array("wcu_report_show_url", ".wcu-order-filterconversions-field", esc_html__( "Referral URLs", "woo-coupon-usage" )),
            array("wcu_report_show_products", "", esc_html__( "Products", "woo-coupon-usage" ))
        );
        ?>
      <?php 
        foreach ( $extrafilters as $filters ) {
            ?>
      <script>
      jQuery(document).ready(function(){
        jQuery("#<?php 
            echo esc_html( $filters[0] );
            ?>").on('change', function() {
          if (jQuery('#<?php 
            echo esc_html( $filters[0] );
            ?>').is(':checked')) {
            jQuery("<?php 
            echo esc_html( $filters[1] );
            ?>").show();
          } else {
            jQuery("<?php 
            echo esc_html( $filters[1] );
            ?>").hide();
          }
          if ( !jQuery('#wcu_report_show_sales').is(':checked') && !jQuery('#wcu_report_show_commission').is(':checked') && !jQuery('#wcu_report_show_url').is(':checked') ) {
            jQuery(".wcu-report-filterextra-fields").hide();
          } else {
            jQuery(".wcu-report-filterextra-fields").show();
          }
        });
      });
      </script>
      <input type="checkbox" id="<?php 
            echo esc_html( $filters[0] );
            ?>" name="<?php 
            echo esc_html( $filters[0] );
            ?>" value="true" style="margin-top: -2px;" checked> <strong style="margin-right: 7px; margin-left: -4px;"><?php 
            echo esc_html( $filters[2] );
            ?></strong>
      <?php 
        }
        ?>

      </p>

    </div>

    <div class="admin-report-form-row" style="border-left: 1px solid #f3f3f3;">

      <div class="wcu-report-filterextra-fields" style="margin-bottom: 30px;">

      <strong style="display: block; margin-top: -4px;">Only show coupons where:</strong>

      <!-- Filter by Total Usage -->
      <div class="wcu-report-filterusage-field">
        <p>
          <span class="wcu-order-filterusage-field">
            <strong>Total Usage</strong> is
            <select id="wcu-orders-filterusage-type" name="wcu_orders_filterusage_type">
              <option value="more or equal"><?php 
        echo esc_html__( "Equal or More", "woo-coupon-usage" );
        ?></option>
              <option value="more"><?php 
        echo esc_html__( "More", "woo-coupon-usage" );
        ?></option>
              <option value="less or equal"><?php 
        echo esc_html__( "Equal or Less", "woo-coupon-usage" );
        ?></option>
              <option value="less"><?php 
        echo esc_html__( "Less", "woo-coupon-usage" );
        ?></option>
              <option value="equal"><?php 
        echo esc_html__( "Equal", "woo-coupon-usage" );
        ?></option>
            </select>
            than
            <input type="number" id="wcu-orders-filterusage-amount" name="wcu_orders_filterusage_amount" value="0" min="0" required>
          </span>
        </p>
      </div>

      <!-- Filter by Total Sales -->
      <div class="wcu-report-filtersales-field">
        <p>
          <span class="wcu-order-filtersales-field">
            <strong>Total Sales</strong> is
            <select id="wcu-orders-filtersales-type" name="wcu_orders_filtersales_type">
              <option value="more or equal"><?php 
        echo esc_html__( "Equal or More", "woo-coupon-usage" );
        ?></option>
              <option value="more"><?php 
        echo esc_html__( "More", "woo-coupon-usage" );
        ?></option>
              <option value="less or equal"><?php 
        echo esc_html__( "Equal or Less", "woo-coupon-usage" );
        ?></option>
              <option value="less"><?php 
        echo esc_html__( "Less", "woo-coupon-usage" );
        ?></option>
              <option value="equal"><?php 
        echo esc_html__( "Equal", "woo-coupon-usage" );
        ?></option>
            </select>
            than
            <?php 
        echo wcusage_get_currency_symbol();
        ?>
            <input type="number" id="wcu-orders-filtersales-amount" name="wcu_orders_filtersales_amount" value="0" min="0" required>
          </span>
        </p>
      </div>

      <!-- Filter by Commission Earned -->
      <div class="wcu-report-filtercommission-field">
        <p>
          <span class="wcu-order-filtercommission-field">
            <strong>Commission Earned</strong> is
            <select id="wcu-orders-filtercommission-type" name="wcu_orders_filtercommission_type">
              <option value="more or equal"><?php 
        echo esc_html__( "Equal or More", "woo-coupon-usage" );
        ?></option>
              <option value="more"><?php 
        echo esc_html__( "More", "woo-coupon-usage" );
        ?></option>
              <option value="less or equal"><?php 
        echo esc_html__( "Equal or Less", "woo-coupon-usage" );
        ?></option>
              <option value="less"><?php 
        echo esc_html__( "Less", "woo-coupon-usage" );
        ?></option>
              <option value="equal"><?php 
        echo esc_html__( "Equal", "woo-coupon-usage" );
        ?></option>
            </select>
            than
            <?php 
        echo wcusage_get_currency_symbol();
        ?>
            <input type="number" id="wcu-orders-filtercommission-amount" name="wcu_orders_filtercommission_amount" value="0" min="0" required>
          </span>
        </p>
      </div>

      <?php 
        if ( wcu_fs()->can_use_premium_code() ) {
            ?>
      <div>

      <!-- Filter by Unpaid Commission -->
      <div class="wcu-report-filterunpaid-field">
        <p>
          <span class="wcu-order-filterunpaid-field">
            <strong>Unpaid Commission</strong> is
            <select id="wcu-orders-filterunpaid-type" name="wcu_orders_filterunpaid_type">
              <option value="more or equal"><?php 
            echo esc_html__( "Equal or More", "woo-coupon-usage" );
            ?></option>
              <option value="more"><?php 
            echo esc_html__( "More", "woo-coupon-usage" );
            ?></option>
              <option value="less or equal"><?php 
            echo esc_html__( "Equal or Less", "woo-coupon-usage" );
            ?></option>
              <option value="less"><?php 
            echo esc_html__( "Less", "woo-coupon-usage" );
            ?></option>
              <option value="equal"><?php 
            echo esc_html__( "Equal", "woo-coupon-usage" );
            ?></option>
            </select>
            than
            <?php 
            echo wcusage_get_currency_symbol();
            ?>
            <input type="number" id="wcu-orders-filterunpaid-amount" name="wcu_orders_filterunpaid_amount" value="0" min="0" required>
          </span>
        </p>
      </div>

      </div>
    <?php 
        } else {
            ?>
      <select id="wcu-orders-filterunpaid-type" name="wcu_orders_filterunpaid_type" hidden>
        <option value="more or equal"><?php 
            echo esc_html__( "Equal or More", "woo-coupon-usage" );
            ?></option>
      </select>
      <input type="hidden" id="wcu-orders-filterunpaid-amount" name="wcu_orders_filterunpaid_amount" value="0" hidden>
    <?php 
        }
        ?>

      <!-- Filter by URL Conversion Rate -->
      <div class="wcu-report-filterconversions-field" style="margin-bottom: -20px;">
        <p>
          <span class="wcu-order-filterconversions-field">
            <strong>URL Conversion Rate</strong> is
            <select id="wcu-orders-filterconversions-type" name="wcu_orders_filterconversions_type">
              <option value="more or equal"><?php 
        echo esc_html__( "Equal or More", "woo-coupon-usage" );
        ?></option>
              <option value="more"><?php 
        echo esc_html__( "More", "woo-coupon-usage" );
        ?></option>
              <option value="less or equal"><?php 
        echo esc_html__( "Equal or Less", "woo-coupon-usage" );
        ?></option>
              <option value="less"><?php 
        echo esc_html__( "Less", "woo-coupon-usage" );
        ?></option>
              <option value="equal"><?php 
        echo esc_html__( "Equal", "woo-coupon-usage" );
        ?></option>
            </select>
            than
            <input type="number" id="wcu-orders-filterconversions-amount" name="wcu_orders_filterconversions_amount" value="0" min="0" required>%
          </span>
        </p>
      </div>

      </div>

    </div>

    <div style="clear: both;"></div>
    <p style="margin-top: 20px;">
      <input type="text" name="page-monthly" value="1" style="display: none;"><input type="text" name="load-page" value="1" style="display: none;">
      <button class="ordersfilterbutton wcu-button-search-report-admin" type="submit" id="wcu-monthly-orders-button" name="submitmonthlyordersfilter">
        <?php 
        echo esc_html__( "GENERATE REPORT", "woo-coupon-usage" );
        ?> <i class="fas fa-arrow-right"></i>
      </button>
    </p>

    </form>

  </div>

  <!-- Loader -->
  <script>
  var isclickedreport;

  jQuery(document).ready(function(){
    jQuery(".wcu-loading-image").hide();
    jQuery(".loaded-stats").hide();
  });

  jQuery(document).on("click", "#generate-new-report", function(){
    jQuery(".wcu-admin-reports-form").show();
    jQuery(".loaded-stats").hide();
  });

  jQuery(document).on("click", "#wcu-monthly-orders-button", function(){
    jQuery(".wcu-admin-reports-form").hide();
  });

  jQuery(document).on("click", "#wcu-monthly-orders-button", function(){
    jQuery(document).one("ajaxStop", function() {

        var checkusage = jQuery('.total-usage').text();

        jQuery(".wcu-loading-image").hide();
        jQuery(".loaded-stats").show();

        // ***** Sales Statistics / Commission Statistics ***** //

        <?php 
        $stattypes = [
            'total-usage',
            'total-sales',
            'total-discounts',
            'total-commission',
            'unpaid-commission',
            'pending-commission'
        ];
        foreach ( $stattypes as $stat ) {
            $currencysym = "";
            if ( $stat != 'total-usage' ) {
                $currencysym = wcusage_get_currency_symbol();
            }
            ?>

          // Calc Sums Total Usage
          var sum = 0;
          var showvalue = 0;
          var totalitems = 0;

          jQuery('.item-<?php 
            echo esc_html( $stat );
            ?>').each(function(){
              if(parseFloat(jQuery(this).text())) {
                totalitems++;
                sum += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
              }
          });
          showvalue = parseFloat(sum);
          <?php 
            if ( $stat != 'total-usage' ) {
                ?>
            var showvalue = sum.toFixed(2);
          <?php 
            }
            ?>
          jQuery('.<?php 
            echo esc_html( $stat );
            ?>').text( showvalue );

              // DIFFERENCE
              var sum2 = 0;
              jQuery('.item-<?php 
            echo esc_html( $stat );
            ?>-old').each(function(){
                  if(parseFloat(jQuery(this).text())) {
                    sum2 += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
                  }
                  <?php 
            if ( $stat != 'total-usage' ) {
                ?>
                    sum2 = parseFloat(sum2.toFixed(2));
                  <?php 
            }
            ?>
              });

              var decreaseValue = sum - sum2;
              if(sum > sum2) {
                var incicon = "<i class='fas fa-arrow-up'></i>";
                var inccolor = "green";
              } else {
                var incicon = "<i class='fas fa-arrow-down'></i>";
                var inccolor = "red";
              }
              var compare = incicon + " " + Math.abs( ( (decreaseValue / sum) * 100 ).toFixed(2) ) + "%";

              jQuery('.<?php 
            echo esc_html( $stat );
            ?>-old').html( "<span style='display: block; color: "+inccolor+"; font-size: 12px;' title='Previous: <?php 
            echo esc_html( $currencysym );
            ?>" + sum2 + "'>" + compare + "</span>" );

          <?php 
        }
        ?>


        // ***** Referral URL Statistics ***** //

        // Calc Sums Total Clicks
        var sum = 0;
        jQuery('.item-total-clicks').each(function(){
           sum += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
        });
        jQuery('.total-clicks').text(sum);
        var convclicks = sum;

          // DIFFERENCE
          var sum2 = 0;
          jQuery('.item-total-clicks-old').each(function(){
              if(parseFloat(jQuery(this).text())) {
                sum2 += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
              }
          });
          sum2 = sum2.toFixed(2);

          var decreaseValue = sum - sum2;
          if(sum > sum2) {
            var incicon = "<i class='fas fa-arrow-up'></i>";
            var inccolor = "green";
          } else {
            var incicon = "<i class='fas fa-arrow-down'></i>";
            var inccolor = "red";
          }
          var compare = incicon + " " + Math.abs( ( (decreaseValue / sum) * 100 ).toFixed(2) ) + "%";

          jQuery('.total-clicks-old').html( "<span style='display: block; color: "+inccolor+"; font-size: 12px;' title='Previous: " + sum2 + "'>" + compare + "</span>" );
          var convclicks2 = sum2;

        // Calc Sums Total Conversions
        var sum = 0;
        jQuery('.item-total-conversions').each(function(){
           sum += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
        });
        jQuery('.total-conversions').text(sum);
        var convs = sum;

            // DIFFERENCE
            var sum2 = 0;
            jQuery('.item-total-conversions-old').each(function(){
                if(parseFloat(jQuery(this).text())) {
                  sum2 += parseFloat(jQuery(this).text());  // Or this.innerHTML, this.innerText
                }
            });
            sum2 = sum2.toFixed(2);

            var decreaseValue = sum - sum2;
            if(sum > sum2) {
              var incicon = "<i class='fas fa-arrow-up'></i>";
              var inccolor = "green";
            } else {
              var incicon = "<i class='fas fa-arrow-down'></i>";
              var inccolor = "red";
            }
            var compare = incicon + " " + Math.abs( ( (decreaseValue / sum) * 100 ).toFixed(2) ) + "%";

            jQuery('.total-conversions-old').html( "<span style='display: block; color: "+inccolor+"; font-size: 12px;' title='Previous: " + sum2 + "'>" + compare + "</span>" );
            var convs2 = sum2;

        // Calc Sums Conversion Rate
        var sum = (convs / convclicks) * 100;
        sum = sum.toFixed(2);
        if(isNaN(sum)) {
          sum = 0;
        }
        jQuery('.total-conversion-rate').text(sum);

          // DIFFERENCE
          var sum2 = (convs2 / convclicks2) * 100;
          sum2 = sum2.toFixed(2);

          var decreaseValue = sum - sum2;
          if(sum > sum2) {
            var incicon = "<i class='fas fa-arrow-up'></i>";
            var inccolor = "green";
          } else {
            var incicon = "<i class='fas fa-arrow-down'></i>";
            var inccolor = "red";
          }
          var compare = incicon + " " + Math.abs(decreaseValue.toFixed(2)) + "%";

          jQuery('.total-conversion-rate-old').html( "<span style='display: block; color: "+inccolor+"; font-size: 12px;' title='Previous: " + sum2 + "'>" + compare + "</span>" );


        // ***** Sort By Total Sales *****/

        var divList = jQuery(".coupon-item-box");
        divList.sort(function(a, b){
            return jQuery(b).data("usage")-jQuery(a).data("usage")
        });
        jQuery("#table-coupon-items").html(divList);
        jQuery( "#sort-by-usage" ).css("font-weight","Bold");

        // Sort by usage
        jQuery( "#sort-by-usage" ).click();

    });
  });
  </script>

  <!-- Loader -->
  <div class="wcu-loading-image wcu-loading-stats" style="display: none;">
    <div class="wcu-loading-loader">
      <div class="wcu-loader"></div>
    </div>
    <p style="margin: 0;font-size:;font-weight: bold; margin-top: 20px; width: 200px;"><br/><?php 
        echo esc_html__( "Generating Report", "woo-coupon-usage" );
        ?>...</p>
    <br/>

    <span style="margin-top: 25px; font-size: 12px; color: #909090;"><br/>
      <?php 
        echo esc_html__( "This may take a few seconds...", "woo-coupon-usage" );
        ?><br/>
      <?php 
        echo esc_html__( "It will take longer if you select a large date range, or have lots of orders and coupons.", "woo-coupon-usage" );
        ?>
    </span>
  </div>

  <div class="loaded-stats-wrapper" style="display: none;">

    <div class="loaded-stats">

    <p style="margin: 0;">
    <a id="generate-new-report" href="#" onclick="return false;" style="text-decoration: none; font-weight: bold;">
      <?php 
        echo esc_html__( "GENERATE NEW REPORT", "woo-coupon-usage" );
        ?> <i class="fas fa-angle-double-right"></i>
    </a>
    </p>

    <br/>

    <h2 id="report-complete-title">Report Complete!</h2>

    <div class='after-report-complete'></div>

      <div class="wcusage-reports-stats-section-sales">

        <br/>
        <div style="clear: both;"></div>

          <fieldset class="wcusage-reports-stats-section">

            <legend class="wcusage-reports-stats-title">Sales Statistics</legend>

            <!-- Total Usage -->
            <div class="wcusage-info-box wcusage-info-box-usage">
              <p>
                <span class="wcusage-info-box-title">Total Usage:</span>
                <span class="total-usage">0</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-usage-old">0</span></span>
                </p>
            </div>

            <!-- Total Order -->
            <div class="wcusage-info-box wcusage-info-box-sales">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Total Sales", "woo-coupon-usage" );
        ?>:</span>
                <?php 
        echo wcusage_get_currency_symbol();
        ?><span class="total-sales">0.00</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-sales-old">0</span></span>
              </p>
            </div>

            <!-- Total Discounts -->
            <div class="wcusage-info-box wcusage-info-box-discounts">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Total Discounts", "woo-coupon-usage" );
        ?>:</span>
                <?php 
        echo wcusage_get_currency_symbol();
        ?><span class="total-discounts">0.00</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-discounts-old">0</span></span>
              </p>
            </div>

          </fieldset>

        </div>
        
        <div class="wcusage-reports-stats-section-commission">

          <br/>
          <div style="clear: both;"></div>

          <fieldset class="wcusage-reports-stats-section wcusage-reports-stats-section-commission">

            <legend class="wcusage-reports-stats-title">Commission Statistics</legend>

            <!-- Total Commission -->
            <div class="wcusage-info-box wcusage-info-box-dollar">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Total Commission", "woo-coupon-usage" );
        ?>:</span>
                <?php 
        echo wcusage_get_currency_symbol();
        ?><span class="total-commission">0.00</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-commission-old">Earned during this period.</span></span>
              </p>
            </div>

            <!-- Unpaid Commission -->
            <div class="wcusage-info-box wcusage-info-box-dollar" <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?> style="opacity: 0.25; pointer-events: none;"<?php 
        }
        ?>>
              <p>
                <span class="wcusage-info-box-title"><?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>(PRO)<?php 
        }
        ?> <?php 
        echo esc_html__( "Unpaid Commission", "woo-coupon-usage" );
        ?>:</span>
                <?php 
        echo wcusage_get_currency_symbol();
        ?><span class="unpaid-commission">0</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span><span style='display: block; color: #bebebe; font-size: 12px;'>(Awaiting Payout Request)</span></span></span>
              </p>
            </div>

            <!-- Pending Commission -->
            <div class="wcusage-info-box wcusage-info-box-dollar" <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?> style="opacity: 0.25; pointer-events: none;"<?php 
        }
        ?>>
              <p>
                <span class="wcusage-info-box-title"><?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>(PRO)<?php 
        }
        ?> <?php 
        echo esc_html__( "Pending Payouts", "woo-coupon-usage" );
        ?>:</span>
                <?php 
        echo wcusage_get_currency_symbol();
        ?><span class="pending-commission">0</span>
                <span class="all-time-side-text" style="font-size: 12px; font-weight: bold; display: inline;"><a href="<?php 
        echo esc_url( admin_url( 'admin.php?page=wcusage_payouts' ) );
        ?>" style="text-decoration: none;">Pay Now <i class="fas fa-arrow-right"></i></a></span>
              </p>
            </div>

          </fieldset>

        </div>

        <div class="wcusage-reports-stats-section-url">

          <br/>
          <div style="clear: both;"></div>

          <fieldset class="wcusage-reports-stats-section">

            <legend class="wcusage-reports-stats-title">Referral URL Statistics</legend>

            <!-- Total Clicks -->
            <div class="wcusage-info-box wcusage-info-box-clicks">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Total Clicks", "woo-coupon-usage" );
        ?>:</span>
                <span class="total-clicks">0</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-clicks-old"></span></span>
              </p>
            </div>

            <!-- Total Conversions -->
            <div class="wcusage-info-box wcusage-info-box-convert">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Total Conversions", "woo-coupon-usage" );
        ?>:</span>
                <span class="total-conversions">0</span>
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-conversions-old"></span></span>
              </p>
            </div>

            <!-- Total Conversions -->
            <div class="wcusage-info-box wcusage-info-box-percent">
              <p>
                <span class="wcusage-info-box-title"><?php 
        echo esc_html__( "Conversion Rate", "woo-coupon-usage" );
        ?>:</span>
                <span class="total-conversion-rate">0</span>%
                <span style="font-size: 12px; font-weight: bold; display: none;" class="all-time-previous"><span class="total-conversion-rate-old"></span></span>
              </p>
            </div>

          </fieldset>

        </div>

      <br/>
      <div style="clear: both;"></div>

      <h2>Individual Coupon Statistics</h2>

      <!-- Search -->
      <div id="search-block" style="display: inline-block;">
          <input type="text" id="inpSearch" placeholder="<?php 
        echo esc_html__( "Search Coupons", "woo-coupon-usage" );
        ?>..." style="float: left; height: 50px;" />
          <input type="button" id="inpSearchBtn" class="wcu-button-search-report-admin" value="Search">
      </div>

      <!-- Export Button -->

      <?php 
        if ( wcu_fs()->can_use_premium_code() ) {
            ?>
        <?php 
            $randomfilename = substr( md5( uniqid( mt_rand(), true ) ), 0, 8 );
            ?>
        <script src="<?php 
            echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL ) . 'js/jquery.table2excel.min.js';
            ?>"></script>
        <script>
        jQuery( document ).ready(function() {

          jQuery("#exportBtn").click(function(){

            jQuery("#table-coupon-items").table2excel({
              exclude: ".excludeThisClassExport",
              name: "Coupon Affiliates Report",
              filename: "coupon-affiliates-report-<?php 
            echo esc_html( $randomfilename );
            ?>.xls",
              preserveColors: false // set to true if you want background colors and font colors preserved
            });

          });

        });
        </script>
      <?php 
        }
        ?>

      <span <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>style="opacity: 0.4;" title="Available with Pro."<?php 
        }
        ?>>
        <input type="button" id="exportBtn"
        class="wcu-button-export-admin"
        value="<?php 
        echo esc_html__( "Download CSV", "woo-coupon-usage" );
        ?> &#x025B8;"
        <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>style="cursor: default;" onclick="return false;"<?php 
        }
        ?>>
      </span>

      <div style="clear: both;"></div>

      <p>
      Sort by:
      <span class="wcusage-reports-stats-section-sales">
      <a href="#!" id="sort-by-usage" class="sort-link"><?php 
        echo esc_html__( "Usage", "woo-coupon-usage" );
        ?></a>
      | <a href="#!" id="sort-by-orders" class="sort-link"><?php 
        echo esc_html__( "Orders", "woo-coupon-usage" );
        ?></a>
      | <a href="#!" id="sort-by-discounts" class="sort-link"><?php 
        echo esc_html__( "Discounts", "woo-coupon-usage" );
        ?></a>
      </span>
      <span class="wcusage-reports-stats-section-commission">
      | <a href="#!" id="sort-by-commission" class="sort-link"><?php 
        echo esc_html__( "Commission", "woo-coupon-usage" );
        ?></a>
        <?php 
        if ( $wcusage_field_tracking_enable && wcu_fs()->can_use_premium_code() ) {
            ?>
          | <a href="#!" id="sort-by-unpaid" class="sort-link"><?php 
            echo esc_html__( "Unpaid", "woo-coupon-usage" );
            ?></a>
          | <a href="#!" id="sort-by-pending" class="sort-link"><?php 
            echo esc_html__( "Pending", "woo-coupon-usage" );
            ?></a>
        <?php 
        }
        ?>
      </span>
      </p>

    </div>

  </div>

  <script>
  jQuery(document).ready(function(){

    jQuery.expr.pseudos.Contains = function(a, i, m) {
      return jQuery(a).text().toUpperCase()
          .indexOf(m[3].toUpperCase()) >= 0;
    };

    jQuery.expr.pseudos.contains = function(a, i, m) {
      return jQuery(a).text().toUpperCase()
          .indexOf(m[3].toUpperCase()) >= 0;
    };


    jQuery('#inpSearchBtn').on('click', function(){
       var sSearch = jQuery('#inpSearch').val();
       sSearch = sSearch.split(" ");
       jQuery('#table-coupon-items > tbody:not(:first-child)').hide();
       jQuery.each(sSearch, function(i){
       jQuery('#table-coupon-items > tbody:contains("' + sSearch[i] + '"):not(:first-child)').show();
       });
    });

    // Show/hide stats to prevent it showing randomly
    jQuery('#generate-new-report').on('click', function(){
       jQuery('.loaded-stats-wrapper').css('display', 'none');
    });
    jQuery('.wcu-button-search-report-admin').on('click', function(){
       jQuery('.loaded-stats-wrapper').css('display', 'block');
    });

  });
  </script>

  <div class="loaded-stats-wrapper">

    <div class="wrap loaded-stats" id="content"  style="margin: 0;">

      <!-- Data -->
      <script>
      jQuery(document).ready(function(){

        jQuery('.ordersfilterbutton').on('click', function() {

          jQuery(".wcu-loading-image").show();

          jQuery(".loaded-stats").hide();

          jQuery('.show_data').html('');

          var data = {
            action: 'wcusage_load_admin_reports',
            _ajax_nonce: '<?php 
        echo esc_html( wp_create_nonce( 'wcusage_admin_ajax_nonce' ) );
        ?>',
            wcu_orders_start: jQuery('input[name=wcu_monthly_orders_start]').val(),
            wcu_orders_end: jQuery('input[name=wcu_monthly_orders_end]').val(),
            <?php 
        ?>
            <?php 
        if ( !wcu_fs()->can_use_premium_code() ) {
            ?>
              wcu_orders_start_compare: "",
              wcu_orders_end_compare: "",
              wcu_compare: "",
              wcu_orders_filtercompare_type: "",
              wcu_orders_filtercompare_amount: "",
            <?php 
        }
        ?>
              wcu_orders_filterusage_type: jQuery('select[name=wcu_orders_filterusage_type]').val(),
              wcu_orders_filterusage_amount: jQuery('input[name=wcu_orders_filterusage_amount]').val(),
              wcu_orders_filtersales_type: jQuery('select[name=wcu_orders_filtersales_type]').val(),
              wcu_orders_filtersales_amount: jQuery('input[name=wcu_orders_filtersales_amount]').val(),
              wcu_orders_filtercommission_type: jQuery('select[name=wcu_orders_filtercommission_type]').val(),
              wcu_orders_filtercommission_amount: jQuery('input[name=wcu_orders_filtercommission_amount]').val(),
              wcu_orders_filterconversions_type: jQuery('select[name=wcu_orders_filterconversions_type]').val(),
              wcu_orders_filterconversions_amount: jQuery('input[name=wcu_orders_filterconversions_amount]').val(),
              wcu_orders_filterunpaid_type: jQuery('select[name=wcu_orders_filterunpaid_type]').val(),
              wcu_orders_filterunpaid_amount: jQuery('input[name=wcu_orders_filterunpaid_amount]').val(),
              wcu_report_users_only: jQuery('input[name=wcu_report_users_only]').prop('checked'),
              wcu_report_show_sales: jQuery('input[name=wcu_report_show_sales]').prop('checked'),
              wcu_report_show_commission: jQuery('input[name=wcu_report_show_commission]').prop('checked'),
              wcu_report_show_url: jQuery('input[name=wcu_report_show_url]').prop('checked'),
              wcu_report_show_products: jQuery('input[name=wcu_report_show_products]').prop('checked')
          };
          jQuery.ajax({
              type: 'POST',
              url: ajaxurl,
              data: data,
              success: function(data){
                jQuery('.show_data').html(data);
                setTimeout(function(){
                  if(jQuery('.total-usage').text() == 0) {
                    jQuery('.after-report-complete').prepend("<p style='font-size: 12px; margin-top: 20px;'><i class='fas fa-exclamation-triangle'></i> "
                    + "<?php 
        echo sprintf( wp_kses_post( "If you are having issues with generating empty reports, <a href='%s' target='_blank'>click here</a>.", "woo-coupon-usage" ), "https://couponaffiliates.com/docs/how-to-fix-empty-admin-reports/" );
        ?></p>");
                  } else {
                    jQuery('.after-report-complete').html("");
                  }
                }, 5000);
              }
          });

        });

      });
      </script>

      <div class="show_data"></div>

    </div>

  </div>

  <?php 
    }

}
/**
 * Gets the admin reports data for the values submitted via the create report form
 *
 * @param date $wcu_orders_start
 * @param date $wcu_orders_end
 * @param date $wcu_orders_start_compare
 * @param date $wcu_orders_end_compare
 * @param bool $wcu_compare
 * @param string $wcu_orders_filtercompare_type
 * @param int $wcu_orders_filtercompare_amount
 * @param string $wcu_orders_filterusage_type
 * @param int $wcu_orders_filterusage_amount
 * @param string $wcu_orders_filtersales_type
 * @param int $wcu_orders_filtersales_amount
 * @param string $wcu_orders_filtercommission_type
 * @param int $wcu_orders_filtercommission_amount
 * @param string $wcu_orders_filterconversions_type
 * @param int $wcu_orders_filterconversions_amount
 * @param string $wcu_orders_filterunpaid_type
 * @param int $wcu_orders_filterunpaid_amount
 * @param bool $wcu_report_users_only
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_get_admin_report_data' ) ) {
    function wcusage_get_admin_report_data(
        $wcu_orders_start,
        $wcu_orders_end,
        $wcu_orders_start_compare,
        $wcu_orders_end_compare,
        $wcu_compare,
        $wcu_orders_filtercompare_type,
        $wcu_orders_filtercompare_amount,
        $wcu_orders_filterusage_type,
        $wcu_orders_filterusage_amount,
        $wcu_orders_filtersales_type,
        $wcu_orders_filtersales_amount,
        $wcu_orders_filtercommission_type,
        $wcu_orders_filtercommission_amount,
        $wcu_orders_filterconversions_type,
        $wcu_orders_filterconversions_amount,
        $wcu_orders_filterunpaid_type,
        $wcu_orders_filterunpaid_amount,
        $wcu_report_users_only,
        $wcu_report_show_sales,
        $wcu_report_show_commission,
        $wcu_report_show_url,
        $wcu_report_show_products
    ) {
        $options = get_option( 'wcusage_options' );
        if ( !$wcu_compare ) {
            $wcu_compare == "false";
        }
        if ( !wcu_fs()->can_use_premium_code() ) {
            if ( strtotime( $wcu_orders_start ) < strtotime( "-1 month" ) || !$wcu_orders_start ) {
                $wcu_orders_start = date( "Y-m-d", strtotime( "-1 month" ) );
            }
            if ( strtotime( $wcu_orders_end ) > strtotime( 'now' ) || strtotime( $wcu_orders_end ) > strtotime( 'now' ) || !$wcu_orders_end ) {
                $wcu_orders_end = date( "Y-m-d" );
            }
        }
        if ( $wcu_orders_filtercompare_type == "both" ) {
            $comparetypetext = "have increased or decreased";
        }
        if ( $wcu_orders_filtercompare_type == "more" ) {
            $comparetypetext = "have increased";
        }
        if ( $wcu_orders_filtercompare_type == "less" ) {
            $comparetypetext = "have decreased";
        }
        $width = "<script>document.write(screen.width);</script>";
        // ***** Get the report details message ***** //
        ?>
    <?php 
        $reportscripthtml = "<div class='report-complete-box'><h2>" . esc_html__( "Report Complete!", "woo-coupon-usage" ) . "</h2><p>";
        $reportscripthtml .= "<i class='fas fa-check-circle'></i> " . esc_html__( "Report created for", "woo-coupon-usage" ) . " " . date_i18n( 'j F Y', strtotime( $wcu_orders_start ) ) . " to " . date_i18n( 'j F Y', strtotime( $wcu_orders_end ) );
        if ( $wcu_compare == "true" ) {
            $reportscripthtml .= "<br/><i class='fas fa-check-circle'></i> " . esc_html__( "Comparing with date period", "woo-coupon-usage" ) . " " . date_i18n( 'F j, Y', strtotime( $wcu_orders_start_compare ) ) . " " . esc_html__( "to", "woo-coupon-usage" ) . " " . date_i18n( 'F j, Y', strtotime( $wcu_orders_end_compare ) );
            if ( $wcu_orders_filtercompare_type == "both" && $wcu_orders_filtercompare_amount == 0 ) {
            } else {
                $reportscripthtml .= "<br/><i class='fas fa-check-circle'></i> " . esc_html__( "Showing coupons where sales have", "woo-coupon-usage" ) . " " . $comparetypetext . " " . esc_html__( "by more than", "woo-coupon-usage" ) . " " . $wcu_orders_filtercompare_amount . "%.";
            }
        }
        $arrayextrafilters = array(
            array(
                esc_html__( "total usage", "woo-coupon-usage" ),
                $wcu_orders_filterusage_type,
                $wcu_orders_filterusage_amount,
                $wcu_orders_filterusage_amount
            ),
            array(
                esc_html__( "total sales", "woo-coupon-usage" ),
                $wcu_orders_filtersales_type,
                $wcu_orders_filtersales_amount,
                wcusage_get_currency_symbol() . $wcu_orders_filtersales_amount
            ),
            array(
                esc_html__( "commission earned", "woo-coupon-usage" ),
                $wcu_orders_filtercommission_type,
                $wcu_orders_filtercommission_amount,
                wcusage_get_currency_symbol() . $wcu_orders_filtercommission_amount
            ),
            array(
                esc_html__( "unpaid commission", "woo-coupon-usage" ),
                $wcu_orders_filterunpaid_type,
                $wcu_orders_filterunpaid_amount,
                wcusage_get_currency_symbol() . $wcu_orders_filterunpaid_amount
            ),
            array(
                esc_html__( "URL conversion rate", "woo-coupon-usage" ),
                $wcu_orders_filterconversions_type,
                $wcu_orders_filterconversions_amount,
                $wcu_orders_filterconversions_amount . "%"
            )
        );
        foreach ( $arrayextrafilters as $filter ) {
            if ( $filter[1] == "more or equal" && $filter[2] == 0 || $filter[1] == "more or equal" && $filter[2] == "" ) {
                // Nothing
            } else {
                $reportscripthtml .= "<br/><i class='fas fa-check-circle'></i> " . esc_html__( "Showing coupons where", "woo-coupon-usage" ) . " " . $filter[0] . " " . esc_html__( "is", "woo-coupon-usage" ) . " " . $filter[1] . " " . esc_html__( "than", "woo-coupon-usage" ) . " " . $filter[3] . ".";
            }
        }
        if ( $wcu_report_users_only == "true" ) {
            $reportscripthtml .= "<br/><i class='fas fa-check-circle'></i> " . esc_html__( "Only showing coupons that are assigned to an affiliate user.", "woo-coupon-usage" );
        }
        $reportscripthtml .= "</p></div>";
        ?>

    <!-- Display the report details message -->

    <script>
    jQuery(document).ready(function(){
      jQuery("#report-complete-title").html("<?php 
        echo $reportscripthtml;
        ?>");
    });
    </script>

    <!-- Styles to Show/Hide Sections in Report -->

    <?php 
        if ( $wcu_report_show_sales == "false" ) {
            ?>
    <style>
    .wcusage-reports-stats-section-sales {
      display: none !important;
    }
    </style>
    <?php 
        }
        ?>

    <?php 
        if ( $wcu_report_show_commission == "false" ) {
            ?>
    <style>
    .wcusage-reports-stats-section-commission {
      display: none !important;
    }
    </style>
    <?php 
        }
        ?>

    <?php 
        if ( $wcu_report_show_url == "false" ) {
            ?>
    <style>
    .wcusage-reports-stats-section-url {
      display: none !important;
    }
    </style>
    <?php 
        }
        ?>

    <?php 
        if ( $wcu_compare == "true" ) {
            ?>
    <style>
    .all-time-previous, .all-time-side-text {
      display: block !important;
    }
    </style>
    <?php 
        }
        ?>

    <!-- Get Coupons -->

    <?php 
        $wcusage_field_tracking_enable = wcusage_get_setting_value( 'wcusage_field_tracking_enable', 1 );
        if ( !$wcu_report_users_only || $wcu_report_users_only == "false" ) {
            $args = array(
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'asc',
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
            );
        } else {
            $args = array(
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'asc',
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'meta_query'     => array(array(
                    'key'     => 'wcu_select_coupon_user',
                    'value'   => '',
                    'compare' => '!=',
                )),
            );
        }
        $coupons = get_posts( $args );
        $coupons = array_unique( $coupons, SORT_REGULAR );
        echo "<table id='table-coupon-items'>";
        $previous_coupon = "";
        foreach ( $coupons as $coupon ) {
            if ( $previous_coupon == $coupon ) {
                continue;
                // Skip duplicates (if any)
            }
            $previous_coupon = $coupon;
            $coupon_code = $coupon->post_title;
            $coupon_id = $coupon->ID;
            $coupon_info = wcusage_get_coupon_info_by_id( $coupon_id );
            $coupon_user_id = $coupon_info[1];
            $unpaid_commission = $coupon_info[2];
            if ( !$unpaid_commission ) {
                $unpaid_commission = 0.0;
            }
            $pending_payments = get_post_meta( $coupon_id, 'wcu_text_pending_payment_commission', true );
            if ( !$pending_payments ) {
                $pending_payments = 0.0;
            }
            $uniqueurl = $coupon_info[4];
            // Main Data
            $fullorders = wcusage_wh_getOrderbyCouponCode(
                $coupon_code,
                $wcu_orders_start,
                $wcu_orders_end,
                '',
                1
            );
            $fullorders = array_reverse( $fullorders );
            $total_count = $fullorders['total_count'];
            $total_count_compare = "";
            $total_orders = $fullorders['total_orders'];
            $total_commission = $fullorders['total_commission'];
            $list_of_products = $fullorders['list_of_products'];
            $full_discount = $fullorders['full_discount'];
            $url_stats = wcusage_get_url_stats( $coupon_id, $wcu_orders_start, $wcu_orders_end );
            $clickcount = $url_stats['clicks'];
            $convertedcount = $url_stats['convertedcount'];
            $conversionrate = $url_stats['conversionrate'];
            // User Data
            $usernamefull = "";
            if ( $coupon_user_id ) {
                $user_info = get_userdata( $coupon_user_id );
                if ( $user_info ) {
                    $username = mb_strimwidth(
                        $user_info->user_login,
                        0,
                        14,
                        "..."
                    );
                    $usernamefull = $user_info->user_login;
                } else {
                    $username = "---";
                }
            } else {
                $username = "---";
            }
            // Check Data filters
            $checkshowthis = true;
            // Filter Assigned To User
            if ( $wcu_report_users_only == "true" && !$coupon_user_id ) {
                $checkshowthis = false;
            }
            // ***** Only show coupons with usage ***** //
            // Sales More
            if ( $wcu_orders_filterusage_type == "more" && $total_count <= $wcu_orders_filterusage_amount ) {
                $checkshowthis = false;
            }
            // Sales More Equal
            if ( $wcu_orders_filterusage_type == "more or equal" && $total_count < $wcu_orders_filterusage_amount ) {
                $checkshowthis = false;
            }
            // Sales Less
            if ( $wcu_orders_filterusage_type == "less" && $total_count >= $wcu_orders_filterusage_amount ) {
                $checkshowthis = false;
            }
            // Sales Less Equal
            if ( $wcu_orders_filterusage_type == "less or equal" && $total_count > $wcu_orders_filterusage_amount ) {
                $checkshowthis = false;
            }
            // Equal
            if ( $wcu_orders_filterusage_type == "equal" && $total_count != $wcu_orders_filterusage_amount ) {
                $checkshowthis = false;
            }
            // ***** Only show coupons with sales ***** //
            // Sales More
            if ( $wcu_orders_filtersales_type == "more" && $total_orders <= $wcu_orders_filtersales_amount ) {
                $checkshowthis = false;
            }
            // Sales More Equal
            if ( $wcu_orders_filtersales_type == "more or equal" && $total_orders < $wcu_orders_filtersales_amount ) {
                $checkshowthis = false;
            }
            // Sales Less
            if ( $wcu_orders_filtersales_type == "less" && $total_orders >= $wcu_orders_filtersales_amount ) {
                $checkshowthis = false;
            }
            // Sales Less Equal
            if ( $wcu_orders_filtersales_type == "less or equal" && $total_orders > $wcu_orders_filtersales_amount ) {
                $checkshowthis = false;
            }
            // Equal
            if ( $wcu_orders_filtersales_type == "equal" && $total_orders != $wcu_orders_filtersales_amount ) {
                $checkshowthis = false;
            }
            // ***** Only show coupons with commission ***** //
            // Sales More
            if ( $wcu_orders_filtercommission_type == "more" && $total_commission <= $wcu_orders_filtercommission_amount ) {
                $checkshowthis = false;
            }
            // Sales More Equal
            if ( $wcu_orders_filtercommission_type == "more or equal" && $total_commission < $wcu_orders_filtercommission_amount ) {
                $checkshowthis = false;
            }
            // Sales Less
            if ( $wcu_orders_filtercommission_type == "less" && $total_commission >= $wcu_orders_filtercommission_amount ) {
                $checkshowthis = false;
            }
            // Sales Less Equal
            if ( $wcu_orders_filtercommission_type == "less or equal" && $total_commission > $wcu_orders_filtercommission_amount ) {
                $checkshowthis = false;
            }
            // Equal
            if ( $wcu_orders_filtercommission_type == "equal" && $total_commission != $wcu_orders_filtercommission_amount ) {
                $checkshowthis = false;
            }
            // ***** Only show coupons with conversion rate ***** //
            // Sales More
            if ( $wcu_orders_filterconversions_type == "more" && round( $conversionrate, 2 ) <= $wcu_orders_filterconversions_amount ) {
                $checkshowthis = false;
            }
            // Sales More Equal
            if ( $wcu_orders_filterconversions_type == "more or equal" && round( $conversionrate, 2 ) < $wcu_orders_filterconversions_amount ) {
                $checkshowthis = false;
            }
            // Sales Less
            if ( $wcu_orders_filterconversions_type == "less" && round( $conversionrate, 2 ) >= $wcu_orders_filterconversions_amount ) {
                $checkshowthis = false;
            }
            // Sales Less Equal
            if ( $wcu_orders_filterconversions_type == "less or equal" && round( $conversionrate, 2 ) > $wcu_orders_filterconversions_amount ) {
                $checkshowthis = false;
            }
            // Equal
            if ( $wcu_orders_filterconversions_type == "equal" && round( $conversionrate, 2 ) != $wcu_orders_filterconversions_amount ) {
                $checkshowthis = false;
            }
            // ***** Only show coupons with unpaid commission ***** //
            // Sales More
            if ( $wcu_orders_filterunpaid_type == "more" && $unpaid_commission <= $wcu_orders_filterunpaid_amount ) {
                $checkshowthis = false;
            }
            // Sales More Equal
            if ( $wcu_orders_filterunpaid_type == "more or equal" && $unpaid_commission < $wcu_orders_filterunpaid_amount ) {
                $checkshowthis = false;
            }
            // Sales Less
            if ( $wcu_orders_filterunpaid_type == "less" && $unpaid_commission >= $wcu_orders_filterunpaid_amount ) {
                $checkshowthis = false;
            }
            // Sales Less Equal
            if ( $wcu_orders_filterunpaid_type == "less or equal" && $unpaid_commission > $wcu_orders_filterunpaid_amount ) {
                $checkshowthis = false;
            }
            // Equal
            if ( $wcu_orders_filterunpaid_type == "equal" && $unpaid_commission != $wcu_orders_filterunpaid_amount ) {
                $checkshowthis = false;
            }
            // ***** Display Data ***** //
            if ( $checkshowthis ) {
                ?>
      <tbody class="coupon-item-box"
      data-usage="<?php 
                echo esc_attr( $total_count );
                ?>"
      data-orders="<?php 
                echo esc_attr( $total_orders );
                ?>"
      data-commission="<?php 
                echo esc_attr( $total_commission );
                ?>"
      data-discounts="<?php 
                echo esc_attr( $full_discount );
                ?>"
      data-unpaid="<?php 
                echo esc_attr( $unpaid_commission );
                ?>"
      data-pending="<?php 
                echo esc_attr( $pending_payments );
                ?>"
      >
          <tr class="coupon-data-row " style="padding: 20px 15px 0 15px;">
          <td colspan="7">
            <span class="wcu-coupon-name" style="font-size: 20px; margin-bottom: 10px; display: block; font-weight: bold;">
              <a href="<?php 
                echo esc_html( $uniqueurl );
                ?>" target="_blank" style="text-decoration: none;" title="<?php 
                echo esc_html__( "View Affiliate Dashboard", "woo-coupon-usage" );
                ?>"><?php 
                echo esc_html( $coupon_code );
                ?></a>
              <span style="font-size: 10px;" ><a href="<?php 
                echo esc_url( get_edit_post_link( $coupon_id ) );
                ?>" target="_blank" title="<?php 
                echo esc_html__( "Edit Coupon", "woo-coupon-usage" );
                ?>"><i class="fas fa-edit"></i></a></span>
            </span>
          </td>
          </tr>
          <tr class="coupon-data-row-head" style="padding: 0 15px 0px 15px; margin: 0px 0;">

            <td class="wcu-r-td wcu-r-td-id" style="min-width: 90px;"><?php 
                echo esc_html__( "Coupon ID", "woo-coupon-usage" );
                ?> <a class="hide-col-id wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-affiliate"><?php 
                echo esc_html__( "Affiliate User", "woo-coupon-usage" );
                ?> <a class="hide-col-affiliate wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>

            <?php 
                if ( $wcu_report_show_sales != "false" ) {
                    ?>
            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-usage"><?php 
                    echo esc_html__( "Usage", "woo-coupon-usage" );
                    ?> <a class="hide-col-usage wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-sales"><?php 
                    echo esc_html__( "Sales", "woo-coupon-usage" );
                    ?> <a class="hide-col-sales wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-discounts"><?php 
                    echo esc_html__( "Discounts", "woo-coupon-usage" );
                    ?> <a class="hide-col-discounts wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
            <?php 
                }
                ?>

            <?php 
                if ( $wcu_report_show_commission != "false" ) {
                    ?>
              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-commission"><?php 
                    echo esc_html__( "Commission", "woo-coupon-usage" );
                    ?> <a class="hide-col-commission wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
              <?php 
                    if ( $wcusage_field_tracking_enable && wcu_fs()->can_use_premium_code() ) {
                        ?>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-unpaid"><?php 
                        echo esc_html__( "Unpaid Commission", "woo-coupon-usage" );
                        ?> <a class="hide-col-unpaid wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-pending"><?php 
                        echo esc_html__( "Pending Payout", "woo-coupon-usage" );
                        ?> <a class="hide-col-pending wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
              <?php 
                    }
                    ?>
            <?php 
                }
                ?>

          </tr>
          <tr class="coupon-data-row coupon-data-row-main" style="padding: 0 15px 0px 15px; margin: 0 0 20px 0;">

            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-id coupon-data-row-head-mobile excludeThisClassExport">
              <?php 
                echo esc_html__( "Coupon ID", "woo-coupon-usage" );
                ?>
            </td>
            <td class="wcu-r-td wcu-r-td-id" style="min-width: 90px;">
              <?php 
                echo esc_html( $coupon_id );
                ?>
            </td>

            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-affiliate coupon-data-row-head-mobile excludeThisClassExport">
              <?php 
                echo esc_html__( "Affiliate User", "woo-coupon-usage" );
                ?>
            </td>
            <td class="wcu-r-td wcu-r-td-120 wcu-r-td-affiliate">
              <span title="<?php 
                echo esc_html( $usernamefull );
                ?>"><?php 
                echo esc_html( $username );
                ?></span>
            </td>

            <?php 
                if ( $wcu_report_show_sales != "false" ) {
                    ?>

              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-usage coupon-data-row-head-mobile excludeThisClassExport">
                <?php 
                    echo esc_html__( "Usage", "woo-coupon-usage" );
                    ?>
              </td>
              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-usage" id="total-usage-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                <span class="item-total-usage"><?php 
                    echo esc_html( $total_count );
                    ?></span>
                <span class="item-total-usage-old" style="display: none;"><?php 
                    echo esc_html( $total_count_compare );
                    ?></span>
                <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                  <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_total_count );
                        ?></span>
                <?php 
                    }
                    ?>
              </td>

              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-sales coupon-data-row-head-mobile excludeThisClassExport">
                <?php 
                    echo esc_html__( "Sales", "woo-coupon-usage" );
                    ?>
              </td>
              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-sales" id="total-sales-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                <?php 
                    echo wcusage_get_currency_symbol();
                    ?><span class="item-total-sales"><?php 
                    echo str_replace( ',', '', number_format( (float) $total_orders, 2 ) );
                    ?></span>
                <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                  <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_total_orders );
                        ?></span>
                  <span class="item-total-sales-old" style="display: none;"><?php 
                        echo esc_html( $total_orders_compare );
                        ?></span>
                <?php 
                    }
                    ?>
              </td>

              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-discounts coupon-data-row-head-mobile excludeThisClassExport">
                <?php 
                    echo esc_html__( "Discounts", "woo-coupon-usage" );
                    ?>
              </td>
              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-discounts" id="total-discounts-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                <?php 
                    echo wcusage_get_currency_symbol();
                    ?><span class="item-total-discounts"><?php 
                    echo str_replace( ',', '', number_format( (float) $full_discount, 2 ) );
                    ?></span>
                <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                  <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_full_discount );
                        ?></span>
                  <span class="item-total-discounts-old" style="display: none;"><?php 
                        echo esc_html( $full_discount_compare );
                        ?></span>
                <?php 
                    }
                    ?>
              </td>

            <?php 
                }
                ?>

            <?php 
                if ( $wcu_report_show_commission != "false" ) {
                    ?>

              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-commission coupon-data-row-head-mobile excludeThisClassExport">
                <?php 
                    echo esc_html__( "Commission", "woo-coupon-usage" );
                    ?>
              </td>
              <td class="wcu-r-td wcu-r-td-120 wcu-r-td-commission" id="total-commission-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                <?php 
                    echo wcusage_get_currency_symbol();
                    ?><span class="item-total-commission"><?php 
                    echo str_replace( ',', '', number_format( (float) $total_commission, 2 ) );
                    ?></span>
                <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                  <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_total_commission );
                        ?></span>
                  <span class="item-total-commission-old" style="display: none;"><?php 
                        echo esc_html( $total_commission_compare );
                        ?></span>
                <?php 
                    }
                    ?>
              </td>

              <?php 
                    if ( $wcusage_field_tracking_enable && wcu_fs()->can_use_premium_code() ) {
                        ?>

                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-unpaid coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                        echo esc_html__( "Unpaid Commission", "woo-coupon-usage" );
                        ?>
                </td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-unpaid">
                  <?php 
                        echo wcusage_get_currency_symbol();
                        ?><span class="item-unpaid-commission"><?php 
                        echo str_replace( ',', '', number_format( (float) $unpaid_commission, 2 ) );
                        ?></span>
                </td>

                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-pending coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                        echo esc_html__( "Pending Payout", "woo-coupon-usage" );
                        ?>
                </td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-pending">
                  <?php 
                        echo wcusage_get_currency_symbol();
                        ?><span class="item-pending-commission"><?php 
                        echo str_replace( ',', '', number_format( (float) $pending_payments, 2 ) );
                        ?></span>
                </td>

              <?php 
                    }
                    ?>

            <?php 
                }
                ?>

            </tr>

            <?php 
                if ( $wcu_report_show_url != "false" ) {
                    ?>

              <tr class="coupon-data-row-head wcu-r-td-products" style="padding: 0 15px 0px 15px; margin: 0px 0;">
                  <td class="wcu-r-td wcu-r-td-120 break wcu-r-td-clicks"><?php 
                    echo esc_html__( "Referral URL Clicks", "woo-coupon-usage" );
                    ?> <a class="hide-col-clicks wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
                  <td class="wcu-r-td wcu-r-td-120 break wcu-r-td-conversions"><?php 
                    echo esc_html__( "Referral URL Conversions", "woo-coupon-usage" );
                    ?> <a class="hide-col-conversions wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
                  <td class="wcu-r-td wcu-r-td-120 break wcu-r-td-conversion-rate"><?php 
                    echo esc_html__( "Conversion Rate", "woo-coupon-usage" );
                    ?> <a class="hide-col-conversion-rate wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
              </tr>

              <tr class="coupon-data-row coupon-data-row-main" style="padding: 0 15px 0px 15px; margin: 0 0 20px 0;">

                <!-- Clicks -->
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-clicks coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                    echo esc_html__( "Referral URL Clicks", "woo-coupon-usage" );
                    ?>
                </td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-clicks" id="total-clicks-<?php 
                    echo esc_attr( $coupon_id );
                    ?>">
                  <span class="item-total-clicks"><?php 
                    echo esc_html( $clickcount );
                    ?></span>
                  <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                    <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_clickcount );
                        ?></span>
                    <span class="item-total-clicks-old" style="display: none;"><?php 
                        echo esc_html( $clickcount_compare );
                        ?></span>
                  <?php 
                    }
                    ?>
                </td>

                <!-- Conversions -->
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-conversions coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                    echo esc_html__( "Referral URL Conversions", "woo-coupon-usage" );
                    ?>
                </td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-conversions" id="total-conversions-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                  <span class="item-total-conversions"><?php 
                    echo esc_html( $convertedcount );
                    ?></span>
                  <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                    <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_convertedcount );
                        ?></span>
                    <span class="item-total-conversions-old" style="display: none;"><?php 
                        echo esc_html( $convertedcount_compare );
                        ?></span>
                  <?php 
                    }
                    ?>
                </td>

                <!-- Conversion Rate -->
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-conversion-rate coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                    echo esc_html__( "Conversion Rate", "woo-coupon-usage" );
                    ?>
                </td>
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-conversion-rate" id="total-conversion-rate-<?php 
                    echo esc_html( $coupon_id );
                    ?>">
                  <span class="item-total-conversion-rate"><?php 
                    echo esc_html( round( $conversionrate, 2 ) );
                    ?></span>%
                  <?php 
                    if ( $wcu_compare == "true" ) {
                        ?>
                    <br/><span style="font-size: 10px;"><?php 
                        echo wp_kses_post( $diff_conversionrate );
                        ?></span>
                    <span class="item-total-conversion-rate-old" style="display: none;"><?php 
                        echo esc_html( $conversionrate_compare );
                        ?></span>
                  <?php 
                    }
                    ?>
                </td>

              </tr>

            <?php 
                }
                ?>

            <?php 
                if ( $wcu_report_show_products != "false" ) {
                    ?>

              <tr class="coupon-data-row-head wcu-r-td-products" style="padding: 0 15px 0px 15px; margin: 0px 0;">
                  <td class="wcu-r-td wcu-r-td-120 break wcu-r-td-products"><?php 
                    echo esc_html__( "Products", "woo-coupon-usage" );
                    ?> <a class="hide-col-products wcu-hide-col" href="#" onclick="return false;" title="Remove Column"><i class="fas fa-times"></i></a></td>
              </tr>

              <tr class="coupon-data-row coupon-data-row-main wcu-r-td-products" style="padding: 0 15px 20px 15px; margin: 0;">
                <td class="wcu-r-td wcu-r-td-120 wcu-r-td-products coupon-data-row-head-mobile excludeThisClassExport">
                  <?php 
                    echo esc_html__( "Products", "woo-coupon-usage" );
                    ?>
                </td>
                <td class="wcu-r-td break wcu-r-td-products">
                <?php 
                    if ( $list_of_products ) {
                        foreach ( $list_of_products as $key => $value ) {
                            $product = wc_get_product( $key );
                            if ( $product ) {
                                echo "&#8226; " . esc_html( $value ) . " x " . esc_html( $product->get_name() ) . "<br/>";
                            }
                        }
                    } else {
                        echo "0 products sold";
                    }
                    ?>
                </td>
              </tr>

            <?php 
                }
                ?>

      </tbody>

      <?php 
            }
        }
        ?>
  </table>

  <?php 
        do_action( 'wcusage_hook_get_admin_report_scripts_sorting' );
        ?> <!-- Insert "Scripts - Sorting" -->

  <?php 
        do_action( 'wcusage_hook_get_admin_report_scripts_remove_row' );
        ?> <!-- Insert "Scripts - Remove Cols Buttons" -->

  <?php 
    }

}
add_action(
    'wcusage_hook_get_admin_report_data',
    'wcusage_get_admin_report_data',
    10,
    22
);
/**
 * Scripts For Sorting Admin Reports
 *
 */
if ( !function_exists( 'wcusage_get_admin_report_scripts_sorting' ) ) {
    function wcusage_get_admin_report_scripts_sorting() {
        ?>
    <script>
    jQuery( "#sort-by-usage" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("usage")-jQuery(a).data("usage")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-usage" ).css("font-weight","Bold");
    });
    jQuery( "#sort-by-orders" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("orders")-jQuery(a).data("orders")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-orders" ).css("font-weight","Bold");
    });
    jQuery( "#sort-by-commission" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("commission")-jQuery(a).data("commission")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-commission" ).css("font-weight","Bold");
    });
    jQuery( "#sort-by-discounts" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("discounts")-jQuery(a).data("discounts")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-discounts" ).css("font-weight","Bold");
    });
    jQuery( "#sort-by-unpaid" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("unpaid")-jQuery(a).data("unpaid")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-unpaid" ).css("font-weight","Bold");
    });
    jQuery( "#sort-by-pending" ).on('click', function() {
      var divList = jQuery(".coupon-item-box");
      divList.sort(function(a, b){
          return jQuery(b).data("pending")-jQuery(a).data("pending")
      });
      jQuery("#table-coupon-items").html(divList);
      jQuery( ".sort-link" ).css("font-weight","normal");
      jQuery( "#sort-by-pending" ).css("font-weight","Bold");
    });
    </script>
  <?php 
    }

}
add_action( 'wcusage_hook_get_admin_report_scripts_sorting', 'wcusage_get_admin_report_scripts_sorting' );
/**
 * Scripts For Remove Cols Buttons
 *
 */
if ( !function_exists( 'wcusage_get_admin_report_scripts_remove_row' ) ) {
    function wcusage_get_admin_report_scripts_remove_row() {
        ?>
    <script>
    jQuery( document ).ready(function() {
      wcu_report_remove_col();
    });
    jQuery( ".sort-link" ).on('click', function() {
      wcu_report_remove_col();
    });

    function wcu_report_remove_col() {

      jQuery( ".hide-col-id" ).on('click', function() {
        jQuery( ".wcu-r-td-id" ).remove();
      });
      jQuery( ".hide-col-affiliate" ).on('click', function() {
        jQuery( ".wcu-r-td-affiliate" ).remove();
      });
      jQuery( ".hide-col-usage" ).on('click', function() {
        jQuery( ".wcu-r-td-usage" ).remove();
      });
      jQuery( ".hide-col-sales" ).on('click', function() {
        jQuery( ".wcu-r-td-sales" ).remove();
      });
      jQuery( ".hide-col-commission" ).on('click', function() {
        jQuery( ".wcu-r-td-commission" ).remove();
      });
      jQuery( ".hide-col-discounts" ).on('click', function() {
        jQuery( ".wcu-r-td-discounts" ).remove();
      });
      jQuery( ".hide-col-unpaid" ).on('click', function() {
        jQuery( ".wcu-r-td-unpaid" ).remove();
      });
      jQuery( ".hide-col-pending" ).on('click', function() {
        jQuery( ".wcu-r-td-pending" ).remove();
      });
      jQuery( ".hide-col-clicks" ).on('click', function() {
        jQuery( ".wcu-r-td-clicks" ).remove();
      });
      jQuery( ".hide-col-conversions" ).on('click', function() {
        jQuery( ".wcu-r-td-conversions" ).remove();
      });
      jQuery( ".hide-col-conversion-rate" ).on('click', function() {
        jQuery( ".wcu-r-td-conversion-rate" ).remove();
      });
      jQuery( ".hide-col-products" ).on('click', function() {
        jQuery( ".wcu-r-td-products" ).remove();
      });

    }
    </script>
  <?php 
    }

}
add_action( 'wcusage_hook_get_admin_report_scripts_remove_row', 'wcusage_get_admin_report_scripts_remove_row' );