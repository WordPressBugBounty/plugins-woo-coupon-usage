<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_field_cb( $args ) {
    $options = get_option( 'wcusage_options' );

    $ispro = ( wcu_fs()->can_use_premium_code() ? 1 : 0 );
    $probrackets = ( $ispro ? "" : " (PRO)" );
    ?>

<div id="general-settings" class="settings-area">

	<h1><?php echo esc_html__( "General Settings", "woo-coupon-usage" ); ?></h1>

  <?php
  if ( function_exists('wc_coupons_enabled') ) {
    if ( !wc_coupons_enabled() ) {
      echo "Notice: Coupons have been automatically enabled in your WooCommerce settings.";
      update_option( 'woocommerce_enable_coupons', 'yes' );
    }
  }
  ?>

  <hr/>

  <!-- Dashboard Page -->
  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> Dashboard:</h3>
  <?php echo do_action( 'wcusage_hook_setting_section_dashboard_page' ); ?>

  <br/><br/>

  <!-- FAQ: How to create new affiliates & coupons? -->
  <div class="wcu-admin-faq">

    <?php echo wcusage_admin_faq_toggle(
    "wcu_show_section_qna_create_affiliates",
    "wcu_qna_create_affiliates",
    "FAQ: How do I create new affiliates & coupons?");
    ?>

    <div class="wcu-admin-faq-content wcu_qna_create_affiliates" id="wcu_qna_create_affiliates" style="display: none;">

      <span class="dashicons dashicons-arrow-right"></span> <?php echo esc_html__( 'To add new affiliates and assign them to a specific coupon, you can do any of the following 3 options:', 'woo-coupon-usage' ); ?>
      
      <br/>
      
      <ul>
        <li style="margin-left: 20px; margin-bottom: 10px;">
        &bull; [Option 1] <strong>Edit Coupons Manually</strong>: <?php echo esc_html__( 'Go to the', 'woo-coupon-usage' ); ?> <a href="<?php echo esc_url(admin_url("admin.php?page=wcusage_coupons")); ?>"><?php echo esc_html__( 'coupons management page', 'woo-coupon-usage' ); ?></a>, <?php echo esc_html__( 'and add or edit a coupon, then assign users under the "coupon affiliates" tab', 'woo-coupon-usage' ); ?>. (<a href="https://couponaffiliates.com/docs/how-do-i-assign-users-to-coupons" target="_blank"><?php echo esc_html__( 'Learn More.', 'woo-coupon-usage' ); ?></a>)
        </li>
        <li style="margin-left: 20px; margin-bottom: 10px;">
        &bull; [Option 2] <strong>Add New Affiliates</strong>: <?php echo sprintf(wp_kses_post(__( 'Go to the <a href="%s" target="_blank">Add New Affiliate</a> page to add new affiliates here, which will automatically generate the coupon code for them.', 'woo-coupon-usage' )), admin_url('admin.php?page=wcusage_add_affiliate')); ?> (<a href="https://couponaffiliates.com/docs/manual-affiliate-registrations/" target="_blank"><?php echo esc_html__( 'Learn More.', 'woo-coupon-usage' ); ?></a>)
        </li>
        <li style="margin-left: 20px; margin-bottom: 10px;">
        &bull; [Option 3] <strong>Registration Form</strong>: <?php echo sprintf(wp_kses_post(__( 'Direct users to the <a href="%s" target="_blank">Affiliate Registration</a> page to allow them to register themselves. When accepted, this will then automatically create the coupon and assign them to it.', 'woo-coupon-usage' )), admin_url('admin.php?page=wcusage_registrations')); ?> (<a href="https://couponaffiliates.com/docs/pro-affiliate-registration" target="_blank"><?php echo esc_html__( 'Learn More.', 'woo-coupon-usage' ); ?></a>)
        </li>
      </ul>

      <span class="dashicons dashicons-arrow-right"></span> <?php echo esc_html__( 'The affiliate user can then visit the "affiliate dashboard page" to view their affiliate statistics, commissions, referral URLs, etc, for the coupons they are assigned to.', 'woo-coupon-usage' ); ?>

    </div>

  </div>
  
  <br/><hr/>

  <!-- Order/Sales Tracking -->
  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Order/Sales Tracking', 'woo-coupon-usage' ); ?>:</h3>
  <?php echo do_action('wcusage_hook_setting_section_ordersalestracking'); ?>

  <br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px; margin-bottom: 0;"></span>
    <?php echo esc_html__( 'Affiliate Dashboard Customisation', 'woo-coupon-usage' ); ?>
  </h3>

  <p style="margin-bottom: 10px;">
    <?php echo esc_html__( 'Customise the affiliate dashboard page to show the information and functionality exactly how you want.', 'woo-coupon-usage' ); ?>
  </p>

  <br/>

  <!-- Affiliate Dashboard - Statistics Tab -->
  
  <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_statistics_tab", "wcu_section_statistics_tab", "Show", "Hide"); ?>
  <h3><?php echo esc_html__( '"Statistics" Tab', 'woo-coupon-usage' ); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_statistics_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>
  
  <div class="wcu_section_settings" id="wcu_section_statistics_tab" style="display: none;">

    <div style="display: block; float: right; width: 500px;">

    <p><strong style="font-size: 18px;"><?php echo esc_html__( 'Section Layout', 'woo-coupon-usage' ); ?>:</strong></p>
    <p><?php echo esc_html__( 'Customise the order of sections displayed on the "Statistics" tab.', 'woo-coupon-usage' ); ?></p>
    <br/>
    <script>
    jQuery(document).ready(function($) {
        $('#wcusage-section-order').sortable({
            placeholder: 'ui-state-highlight',
            update: function(event, ui) {
                // Update the input field with the sorted order
                var sectionOrder = $('#wcusage-section-order').sortable('toArray').join(',');
                $('#wcusage_statistics_layout').val(sectionOrder).trigger('change');
            }
        });
        $('#wcusage-section-order').disableSelection();
    });
    </script>
    <?php
    $options = get_option('wcusage_options');
    $section_order = isset($options['wcusage_statistics_layout']) ? $options['wcusage_statistics_layout'] : '';

    // Get the available sections
    $sections = array(
        'section_couponinfo' => esc_html__('Coupon Info', 'woo-coupon-usage'),
        'section_commissionamounts' => esc_html__('Commission Earnings', 'woo-coupon-usage'),
        'section_commissiongraphs' => esc_html__('Commission Graph', 'woo-coupon-usage'),
        'section_latestreferrals' => esc_html__('Latest Referrals', 'woo-coupon-usage'),
        'section_commissionpayouts' => esc_html__('Commission Payouts', 'woo-coupon-usage'),
    );
    if(!$section_order) {
      $section_order = implode(',', array_keys($sections));
    }

    // Split the section order into an array
    $section_order_array = explode(',', $section_order);

    // Render the field
    echo '<ul id="wcusage-section-order" class="wcusage-sortable">';
    foreach ($section_order_array as $section_key) {
        if (array_key_exists($section_key, $sections)) {
            echo '<li id="' . esc_attr($section_key) . '">' . esc_html($sections[$section_key]) . '</li>';
        }
    }
    echo '</ul>';
    ?>
    <div style="display: none">
    <?php echo wcusage_setting_text_option("wcusage_statistics_layout", "", "", "0px"); ?>
    </div>

  	<br/>

    </div>

    <!-- Show Coupon Info -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_couponinfo', 1, esc_html__( 'Show "Coupon Info" summary.', 'woo-coupon-usage' ), '0px'); ?>
    
    <br/>

    <?php echo wcusage_setting_text_option("wcusage_field_text", "", esc_html__( 'Custom Text / Information', 'woo-coupon-usage' ), "40px"); ?>
    <i style="margin-left: 40px;"><?php echo esc_html__( 'Displayed at top the "statistics" section on the coupon affiliate dashboard page. HTML tags enabled.', 'woo-coupon-usage' ); ?></i><br/>

  	<br/>

    <!-- Show Commission Earnings-->
    <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_commissionearnings', 1, esc_html__( 'Show "Commission Earnings" summary with toggles.', 'woo-coupon-usage' ), '0px'); ?>

    <?php echo wcusage_setting_toggle('.wcusage_field_statistics_commissionearnings', '.wcu-field-statistics-commissionearnings'); // Show or Hide ?>
    <span class="wcu-field-statistics-commissionearnings">

      <br/>

      <!-- Show Total Sales-->
      <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_commissionearnings_total', 1, esc_html__( 'Show "Total Sales" box.', 'woo-coupon-usage' ), '40px'); ?>

      <br/>

      <!-- Show Total Discounts -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_commissionearnings_discounts', 1, esc_html__( 'Show "Total Discounts" box.', 'woo-coupon-usage' ), '40px'); ?>

      <br/>

      <!-- Show Total Commission -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_commissionearnings_commission', 1, esc_html__( 'Show "Total Commission" box.', 'woo-coupon-usage' ), '40px'); ?>

    </span>

    <br/>

    <!-- Toggle Between Stats Types -->
    <p style="margin-bottom: -5px; margin-left: 40px;">
      <?php
    $wcusage_field_which_toggle = wcusage_get_setting_value('wcusage_field_which_toggle', '1');
    $checked1 = ( $wcusage_field_which_toggle == '0' ? ' checked="checked"' : '' );
    $checked2 = ( $wcusage_field_which_toggle == '1' || $wcusage_field_which_toggle == '' ? ' checked="checked"' : '' );
    ?>
    <strong><label for="scales"><?php echo esc_html__( 'What toggles should be shown for statistics and line graphs?', 'woo-coupon-usage' ); ?></label></strong>
      <br/>
      <label class="switch">
          <input type="radio" value="0" id="wcusage_field_which_toggle" data-custom="custom" name="wcusage_options[wcusage_field_which_toggle]" <?php
        echo esc_html($checked1);
        ?>>
      <span class="slider round">
        <span class="on"><span class="fa-solid fa-check"></span></span>
        <span class="off"></span>
      </span>
      </label>
      <strong style="display: inline-block;"><label for="scales"><?php echo esc_html__( 'All-time', 'woo-coupon-usage' ); ?> | <?php echo esc_html__( 'Last 30 Days', 'woo-coupon-usage' ); ?> | <?php echo esc_html__( 'Last 7 Days', 'woo-coupon-usage' ); ?></label></strong>
      <br/>
      <label class="switch">
          <input type="radio" value="1" id="wcusage_field_which_toggle" data-custom="custom" name="wcusage_options[wcusage_field_which_toggle]" <?php
        echo esc_html($checked2);
        ?>>
      <span class="slider round">
        <span class="on"><span class="fa-solid fa-check"></span></span>
        <span class="off"></span>
      </span>
      </label>
      <strong style="display: inline-block;"><label for="scales"><?php echo esc_html__( 'All-time', 'woo-coupon-usage' ); ?> | <?php echo esc_html__( 'This Month', 'woo-coupon-usage' ); ?> | <?php echo esc_html__( 'Last Month', 'woo-coupon-usage' ); ?></label></strong>
    </p>

    <br/>

    <!-- Show Latest Referrals -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_latest', 1, esc_html__( 'Show "Latest Referrals" summary.', 'woo-coupon-usage' ), '0px'); ?>
    <i><?php echo esc_html__( 'Show a summary of the 5 latest orders/referrals on the "Statistics" tab.', 'woo-coupon-usage' ); ?></i><br/>
    <!-- If "wcusage_field_statistics_latest" active set #section_latestreferrals to opacity 1, otherwise set to 0.3. Check on load and change. -->
    <script>
    jQuery(document).ready(function($) {
      if ( $('#wcusage_field_statistics_latest').is(':checked') ) {
        $('#section_latestreferrals').css('display', 'block');
      } else {
        $('#section_latestreferrals').css('display', 'none');
      }
      $('#wcusage_field_statistics_latest').change(function() {
        if ( $(this).is(':checked') ) {
          $('#section_latestreferrals').css('display', 'block');
        } else {
          $('#section_latestreferrals').css('display', 'none');
        }
      });
    });
    </script>

  	<br/>

    <div <?php if ( !wcu_fs()->can_use_premium_code() ) {?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

      <!-- Show Commission Payouts -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_statistics_commissionpayouts', 1, esc_html__( 'Show "Commission Payouts" summary.', 'woo-coupon-usage' ) . esc_html($probrackets), '0px'); ?>
      <i><?php echo esc_html__( 'Show a payouts summary of the "Unpaid Commission", "Pending Payments", "Completed Payments".', 'woo-coupon-usage' ); ?></i><br/>
      <!-- If "wcusage_field_statistics_commissionpayouts" active set #section_commissionpayouts to opacity 1, otherwise set to 0.3. Check on load and change. -->
      <script>
      jQuery(document).ready(function($) {
        if ( $('#wcusage_field_statistics_commissionpayouts').is(':checked') ) {
          $('#section_commissionpayouts').css('display', 'block');
        } else {
          $('#section_commissionpayouts').css('display', 'none');
        }
        $('#wcusage_field_statistics_commissionpayouts').change(function() {
          if ( $(this).is(':checked') ) {
            $('#section_commissionpayouts').css('display', 'block');
          } else {
            $('#section_commissionpayouts').css('display', 'none');
          }
        });
        <?php if( !wcu_fs()->can_use_premium_code() ) {?>
          $('#section_commissionpayouts').css('display', 'none');
        <?php } ?>
      });
      </script>
      <br/>

      <!-- Show Commission Graphs -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_graphs', 1, esc_html__( 'Show "Commission Graphs".', 'woo-coupon-usage' ) . esc_html($probrackets), '0px'); ?>
      <i><?php echo esc_html__( 'These are line graphs that show the commission earnings for every day in the past 90 days, 30 days or 7 days.', 'woo-coupon-usage' ); ?></i><br/>
      <!-- If "wcusage_field_show_graphs" active set #section_commissiongraphs to opacity 1, otherwise set to 0.3. Check on load and change. -->
      <script>
      jQuery(document).ready(function($) {
        if ( $('#wcusage_field_show_graphs').is(':checked') ) {
          $('#section_commissiongraphs').css('display', 'block');
        } else {
          $('#section_commissiongraphs').css('display', 'none');
        }
        $('#wcusage_field_show_graphs').change(function() {
          if ( $(this).is(':checked') ) {
            $('#section_commissiongraphs').css('display', 'block');
          } else {
            $('#section_commissiongraphs').css('display', 'none');
          }
        });
        <?php if( !wcu_fs()->can_use_premium_code() ) {?>
          $('#section_commissiongraphs').css('display', 'none');
        <?php } ?>
      });
      </script>

    </div>

  </div>

  <br/>

  <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_orders_tab", "wcu_section_orders_tab", "Show", "Hide"); ?>
  <h3><?php echo esc_html__( '"Recent Orders" Tab', 'woo-coupon-usage' ); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_orders_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>
  
  <div class="wcu_section_settings" id="wcu_section_orders_tab" style="display: none;">

    <!-- Show order ID. -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_show_order_tab', 1, esc_html__( 'Show "Recent Orders" Tab', 'woo-coupon-usage' ), '0px'); ?>
    <i><?php echo esc_html__( 'Disable this if you want to hide the "Recent Orders" tab on the affiliate dashboard.', 'woo-coupon-usage' ); ?></i><br/>

    <?php echo wcusage_setting_toggle('.wcusage_field_show_order_tab', '.wcu-field-orders-tab-show'); // Show or Hide ?>
    <span class="wcu-field-orders-tab-show">

      <br/>

      <!-- Recent Orders Number -->
      <?php echo wcusage_setting_number_option('wcusage_field_orders', '10', esc_html__( 'Default amount of "latest orders" to show:', 'woo-coupon-usage' ), '0px'); ?>
      <i><?php echo esc_html__( 'Amount of orders to show on the affiliate dashboard by default.', 'woo-coupon-usage' ); ?></i>

      <br/><br/>

      <!-- Max Orders Number -->
      <?php echo wcusage_setting_number_option('wcusage_field_max_orders', '250', esc_html__( 'Maximum amount of "latest orders" to show at once:', 'woo-coupon-usage' ), '0px'); ?>
      <i><?php echo esc_html__( 'The maximum number of orders to show when filtered by date. Too many could make it take significantly longer to load.', 'woo-coupon-usage' ); ?></i>

      <br/><br/>

      <!-- Show order ID. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_orderid', 0, esc_html__( 'Show order "ID".', 'woo-coupon-usage' ), '0px'); ?>
        
      <?php echo wcusage_setting_toggle('.wcusage_field_orderid', '.wcu-field-orders-id-show'); // Show or Hide ?>
      <span class="wcu-field-orders-id-show">

        <!-- Show order ID. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_orderid_click', 0, esc_html__( 'Make the order "ID" clickable for admins.', 'woo-coupon-usage' ), '40px'); ?>
        <i style="margin-left: 40px;"><?php echo esc_html__( 'If the user is an admin, then the ID will also be clickable to open the order page in the backend.', 'woo-coupon-usage' ); ?></i><br/>

      </span>

      <br/>

      <!-- Show order "date". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_date', 1, esc_html__( 'Show order "date".', 'woo-coupon-usage' ), '0px'); ?>

      <!-- Show order "time". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_time', 0, esc_html__( 'Show order "time".', 'woo-coupon-usage' ), '0px'); ?>

      <!-- Show order "status". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_status', 1, esc_html__( 'Show order "status".', 'woo-coupon-usage' ), '0px'); ?>

      <?php echo wcusage_setting_toggle('.wcusage_field_status', '.wcu-field-orders-status-show'); // Show or Hide ?>
      <span class="wcu-field-orders-status-show">

        <!-- Show status totals -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_orders_table_status_totals', 1, esc_html__( 'Show order status totals below the table.', 'woo-coupon-usage' ), '40px'); ?>
        <i style="margin-left: 40px;"><?php echo esc_html__( 'When selected, below the orders table it will show the total number of orders for each status. The "Status" column needs to be enabled.', 'woo-coupon-usage' ); ?></i><br/>

        <br/>

        <!-- Show status filter -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_orders_table_filter_status', 1, esc_html__( 'Show "Status" dropdown filter.', 'woo-coupon-usage' ), '40px'); ?>
        <i style="margin-left: 40px;"><?php echo esc_html__( 'When selected, a "Status" dropdown will be shown as an option when filtering by date range. Will only show if you have more than 1 status enabled.', 'woo-coupon-usage' ); ?></i><br/>

      </span>

      <br/>

      <!-- Show total order amount. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_amount', 1, esc_html__( 'Show order "total".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show total discounts. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_amount_saved', 1, esc_html__( 'Show order "discount".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show order "country". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_ordercountry', 0, esc_html__( 'Show customer "country".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show order "city". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_ordercity', 0, esc_html__( 'Show customer "city".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show customer "first name". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_ordername', 0, esc_html__( 'Show customer "first name".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show customer "last name". -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_ordernamelast', 0, esc_html__( 'Show customer "last name".', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show shipping costs. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_shipping', 0, esc_html__( 'Show shipping costs.', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show list of products for orders. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_list_products', 1, esc_html__( 'Show products summary/list for orders ("MORE" column).', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Show the combined totals for all orders within the selected date range. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_orders_table_totals', 1, esc_html__( 'Show the combined totals for all orders within the selected date range.', 'woo-coupon-usage' ), '0px'); ?>
      <i><?php echo esc_html__( 'When selected, the totals for all orders within the selected date range will be shown in a new row at the bottom of the recent orders and monthly summary table.', 'woo-coupon-usage' ); ?></i><br/>

    </span>

  </div>

  <br/>

  <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_urls_tab", "wcu_section_urls_tab", "Show", "Hide"); ?>
  <h3><?php echo esc_html__( '"Referrral URLs" Tab', 'woo-coupon-usage' ); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_urls_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

  <div class="wcu_section_settings" id="wcu_section_urls_tab" style="display: none;">

  <!-- Enable Referral URLs -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_urls_enable', 1, esc_html__( 'Enable Referral URLs & Click Tracking', 'woo-coupon-usage' ), '0px'); ?>

  <br/>

  <p><?php echo esc_html__( 'To customise the "Referral URLs" tab, please go to the referral URL settings:', 'woo-coupon-usage' ); ?> <a href="#" onclick="wcusage_go_to_settings('#tab-urls', '#affiliate-reports-settings');">Click Here</a></p>

  </div>

  <br/>

  <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_settings_tab", "wcu_section_settings_tab", "Show", "Hide"); ?>
  <h3><?php echo esc_html__( '"Settings" Tab', 'woo-coupon-usage' ); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_settings_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

  <div class="wcu_section_settings" id="wcu_section_settings_tab" style="display: none;">

  <!-- Show "Settings" tab. -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_show_settings_tab_show', 1, esc_html__( 'Show "Settings" tab on the affiliate dashboard.', 'woo-coupon-usage' ), '0px'); ?>

  <br/>

  <!-- Show "Account Details" section in the "Settings" tab. -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_show_settings_tab_account', 1, esc_html__( 'Show "Account Details" section in the "Settings" tab.', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'This will show the WooCommerce "Account Details" fields directly in the "settings" tab on the affiliate dashboard, along with a logout link.', 'woo-coupon-usage' ); ?></i>

  </div>

  <br/>

  <div <?php if ( !wcu_fs()->can_use_premium_code() ) {?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

    <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_monthly_tab", "wcu_section_monthly_tab", "Show", "Hide"); ?>
    <h3><?php echo esc_html__( '"Monthly Summary" Tab', 'woo-coupon-usage' ); ?><?php echo esc_html($probrackets); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_monthly_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

    <div class="wcu_section_settings" id="wcu_section_monthly_tab" style="display: none;">

      <!-- Show "monthly summary" table section on affiliate dashboard. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table', 1, esc_html__( 'Show "monthly summary" table section on affiliate dashboard.', 'woo-coupon-usage' ), '0px'); ?>

      <?php if ( wcu_fs()->can_use_premium_code() ) { ?>

        <!-- Show "Month" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_date', 1, esc_html__( 'Show "Month" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "Order Count" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_order_count', 1, esc_html__( 'Show "Order Count" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "Total Sales" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_order', 1, esc_html__( 'Show "Total Sales" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "Discounts" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_discount', 1, esc_html__( 'Show "Discounts" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "Total" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_totalwithdiscount', 1, esc_html__( 'Show "Total" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "Commission" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_commission', 1, esc_html__( 'Show "Commission" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "% Change" Column. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_change', 1, esc_html__( 'Show "% Change" Column.', 'woo-coupon-usage' ), '30px'); ?>

        <!-- Show "More" column to show/hide "List of products purchased" section. -->
        <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_col_more', 1, esc_html__( 'Show "More" Column (Toggle for products summary/list).', 'woo-coupon-usage' ), '30px'); ?>

        <br/>

        <!-- Default number of months to show -->
        <?php echo wcusage_setting_number_option('wcusage_field_months_table_total', '6', esc_html__( 'Default number of months to show', 'woo-coupon-usage' ), '30px'); ?>
        <i style="margin-left: 30px;"><?php echo esc_html__( 'How many months to show on the "monthly summary" table by default.', 'woo-coupon-usage' ); ?></i><br/>

      <?php } ?>

    </div>

  </div>
  
  <br/>

  <div <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

    <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_creatives_tab", "wcu_section_creatives_tab", "Show", "Hide"); ?>
    <h3><?php echo esc_html__( '"Creatives" Tab', 'woo-coupon-usage' ); ?><?php echo esc_html($probrackets); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_creatives_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

    <div class="wcu_section_settings" id="wcu_section_creatives_tab" style="display: none;">

      <p>
      <?php echo wcusage_setting_toggle_option('wcusage_field_creatives_enable', 1, 'Enable "creatives" features.', '0px'); ?>
      <i><?php echo esc_html__( 'This will enable "Creatives" in the admin menu, where you can upload your own banners (creatives) for affiliates to use.', 'woo-coupon-usage' ); ?></i><br/>
      <i><?php echo esc_html__( 'A new "creatives" tab will be shown in the affiliate dashboard displaying these creatives, including a HTML code for them to copy and paste, to show the banner on their own site (with the referral link).', 'woo-coupon-usage' ); ?></i><br/>
      </p>

    <?php echo wcusage_setting_toggle('.wcusage_field_creatives_enable', '.wcu-field-section-creatives'); // Show or Hide ?>
    <span class="wcu-field-section-creatives">

      <br/>

      <p><?php echo esc_html__( 'To customise the "Creatives" tab, please go to the creatives settings:', 'woo-coupon-usage' ); ?> <a href="#" onclick="wcusage_go_to_settings('#tab-creatives', '#affiliate-reports-settings');">Click Here</a></p>

    </span>

    </div>

  </div>

  <br/>

  <div <?php if ( !wcu_fs()->can_use_premium_code() ) {?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

    <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_rates_tab", "wcu_section_rates_tab", "Show", "Hide"); ?>
    <h3><?php echo esc_html__( '"Rates" Tab', 'woo-coupon-usage' ); ?><?php echo esc_html($probrackets); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_rates_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

    <div class="wcu_section_settings" id="wcu_section_rates_tab" style="display: none;">

    <p>
      <?php echo wcusage_setting_toggle_option('wcusage_field_rates_enable', 0, 'Enable "Rates" tab.', '0px'); ?>
      <i><?php echo esc_html__( 'This will display a new "Rates" tab on the affiliate dashboard.', 'woo-coupon-usage' ); ?></i><br/>
      <i><?php echo esc_html__( 'This tab will show a table listing all your products, along with commission rates they will earn from each individual product.', 'woo-coupon-usage' ); ?></i><br/>
      <i><?php echo esc_html__( 'The table shows up to 20 products per page by default, with pagination, and a search field.', 'woo-coupon-usage' ); ?></i><br/>
    </p>
    
    <?php echo wcusage_setting_toggle('.wcusage_field_rates_enable', '.wcu-field-section-rates'); // Show or Hide ?>
    <span class="wcu-field-section-rates">

      <br/>

      <p>
        <?php echo wcusage_setting_text_option("wcusage_field_rates_name", "", esc_html__( 'Custom Tab Name', 'woo-coupon-usage' ) . " ('Rates')", "40px"); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_text_option("wcusage_field_rates_header", "", esc_html__( 'Custom Tab Header', 'woo-coupon-usage' ) . " ('Product Commission Rates')", "40px"); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_text_option("wcusage_field_rates_text", "", esc_html__( 'Custom Text / Information', 'woo-coupon-usage' ), "40px"); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_number_option("wcusage_field_rates_per_page", "20", esc_html__( 'Products Per Page', 'woo-coupon-usage' ), "40px"); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_search', 1, esc_html__( 'Show Search Field', 'woo-coupon-usage' ), '40px'); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_id', 1, esc_html__( 'Show "ID" Column', 'woo-coupon-usage' ), '40px'); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_product', 1, esc_html__( 'Show "Product" Column', 'woo-coupon-usage' ), '40px'); ?>  
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_rate', 1, esc_html__( 'Show "Commission Rate" Column', 'woo-coupon-usage' ), '40px'); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_price', 1, esc_html__( 'Show "Product Price" Column', 'woo-coupon-usage' ), '40px'); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_commission', 1, esc_html__( 'Show "Commission Per Product" Column', 'woo-coupon-usage' ), '40px'); ?>
      </p>

      <br/>

      <p>
        <?php echo wcusage_setting_toggle_option('wcusage_field_rates_show_link', 1, esc_html__( 'Show "Referral Link" Column', 'woo-coupon-usage' ), '40px'); ?>
      </p>

    </span>

    </div>

  </div>

  <br/>

  <div <?php if ( !wcu_fs()->can_use_premium_code() ) {?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

    <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_bonuses_tab", "wcu_section_bonuses_tab", "Show", "Hide"); ?>
    <h3><?php echo esc_html__( '"Bonuses" Tab', 'woo-coupon-usage' ); ?><?php echo esc_html($probrackets); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_bonuses_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

    <div class="wcu_section_settings" id="wcu_section_bonuses_tab" style="display: none;">

    <!-- Enable Referral bonuses -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_bonuses_enable', 0, esc_html__( 'Enable Performance Bonuses', 'woo-coupon-usage' ), '0px'); ?>

    <br/>

    <p><?php echo esc_html__( 'To customise the "Performance Bonuses", please go to the "Bonuses" settings:', 'woo-coupon-usage' ); ?> <a href="#" onclick="wcusage_go_to_settings('#tab-bonuses', '#affiliate-reports-settings');">Click Here</a></p>

    </div>

  </div>

  <br/>

  <?php echo wcu_admin_settings_showhide_toggle("wcu_show_section_other_tab", "wcu_section_other_tab", "Show", "Hide"); ?>
  <h3><?php echo esc_html__( 'Other Dashboard Settings', 'woo-coupon-usage' ); ?>: <button style="font-size: 14px; font-weight: normal;" class="wcu-showhide-button" type="button" id="wcu_show_section_other_tab"><?php echo esc_html__('Show', 'woo-coupon-usage'); ?> <span class='fa-solid fa-arrow-down'></span></button></h3>

  <div class="wcu_section_settings" id="wcu_section_other_tab" style="display: none;">

    <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Affiliate Dashboard - Header', 'woo-coupon-usage' ); ?>:</h3>

    <?php echo wcusage_setting_text_option("wcusage_before_title", "", esc_html__( 'Coupon Title Prefix', 'woo-coupon-usage' ), "0px"); ?>
    <i><?php echo esc_html__( 'This will be shown before the coupon code shown in the header of the affiliate dashboard page, for example you could set it to "Coupon code:".', 'woo-coupon-usage' ); ?></i>

    <br/><br/><hr/>

    <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Affiliate Dashboard - Login Form', 'woo-coupon-usage' ); ?>:</h3>

    <?php echo wcusage_setting_toggle_option('wcusage_field_loginform', 1, esc_html__( 'Show WooCommerce login form on affiliate dashboard page when users are logged out.', 'woo-coupon-usage' ), '0px'); ?>
    <i><?php echo esc_html__( 'This will allow affiliate users to login to the dashboard if they visit the base dashboard URL.', 'woo-coupon-usage' ); ?></i><br/>

    <br/><hr/>

    <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Affiliate Dashboard - Profile', 'woo-coupon-usage' ); ?>:</h3>

    <!-- Show logout link on affiliate dashboard (top right). -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_show_logout_link', 1, esc_html__( 'Show logout link on affiliate dashboard (top right).', 'woo-coupon-usage' ), '0px'); ?>

    <br/>

    <!-- Show username on affiliate dashboard (top right). -->
    <?php echo wcusage_setting_toggle_option('wcusage_field_show_username', 1, esc_html__( 'Show username on affiliate dashboard (top right).', 'woo-coupon-usage' ), '0px'); ?>

    <br/><hr/>

    <div <?php if ( !wcu_fs()->can_use_premium_code() ) {?>class="pro-settings-hidden" title="Available with Pro version."<?php } ?>>

      <h3 id="wcu-setting-header-export"><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Export to Excel Buttons', 'woo-coupon-usage' ); ?><?php echo esc_html($probrackets); ?>:</h3>

      <!-- Enable button to export an Excel file of "monthly summary" table. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_months_table_export', 1, esc_html__( 'Enable button to export an Excel file of "monthly summary" table.', 'woo-coupon-usage' ), '0px'); ?>

      <br/>

      <!-- Enable button to export an Excel file of "recent orders" table. -->
      <?php echo wcusage_setting_toggle_option('wcusage_field_show_orders_table_export', 1, esc_html__( 'Enable button to export an Excel file of "recent orders" table.', 'woo-coupon-usage' ), '0px'); ?>

    </div>

    <br/><hr/>

    <!-- Assign Affiliates to Coupons -->
    <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( '"My Account" Menu Link', 'woo-coupon-usage' ); ?>:</h3>

    <?php echo wcusage_setting_toggle_option('wcusage_field_account_tab', 1, esc_html__( 'Add an "Affiliate" menu link to the "My Account" page.', 'woo-coupon-usage' ), '0px'); ?>
    <i><?php echo esc_html__( 'With this enabled, a new "Affiliate" link will appear on the users "My Account" page menu. This will take them to the affiliate dashboard page selected above.', 'woo-coupon-usage' ); ?></i>

    <?php echo wcusage_setting_toggle('.wcusage_field_account_tab', '.wcu-field-section-show-tab'); // Show or Hide ?>
    <span class="wcu-field-section-show-tab">

      <br/><br/>

      <?php echo wcusage_setting_toggle_option('wcusage_field_account_tab_affonly', 0, esc_html__( 'Hide link for non-affiliate users.', 'woo-coupon-usage' ), '30px'); ?>
      <i style="margin-left: 30px;"><?php echo esc_html__( 'With this enabled, the link will be hidden for users that are not assigned to a coupon.', 'woo-coupon-usage' ); ?></i>
      
      <br/><br/>

      <?php echo wcusage_setting_toggle_option('wcusage_field_account_tab_create', 0, esc_html__( 'Display the affiliate dashboard as a page within the "My Account" section.', 'woo-coupon-usage' ), '30px'); ?>
      <i style="margin-left: 30px;"><?php echo esc_html__( 'With this enabled, when the "Affiliate" tab is clicked, instead of redirecting to the normal affiliate dashboard page, it will show the affiliate dashboard as a page/section within "My Account".', 'woo-coupon-usage' ); ?></i>

    </span>

    <br/>

  </div>

</div>

 <?php
}

/**
 * Settings Section: Dashboard Page
 *
 */
add_action( 'wcusage_hook_setting_section_dashboard_page', 'wcusage_setting_section_dashboard_page' );
if( !function_exists( 'wcusage_setting_section_dashboard_page' ) ) {
  function wcusage_setting_section_dashboard_page() {

    $options = get_option( 'wcusage_options' );
    ?>

    <?php if (!class_exists('SitePress')) { ?>

      <!-- Dashboard Page Dropdown -->
      <p><strong><?php echo esc_html__( 'Affiliate Dashboard Page:', 'woo-coupon-usage' ); ?><?php if ( !$options['wcusage_dashboard_page'] ) { ?> <span class="dashicons dashicons-warning" title="Important" style="color: red;"></span><?php } ?></strong></p>
      <?php
      $dashboardpage = "";
      if ( isset($options['wcusage_dashboard_page']) ) {
          //echo "Page: " . $options['wcusage_dashboard_page'] . "<br/>";
          $dashboardpage = $options['wcusage_dashboard_page'];
      } else {
          $dashboardpage = wcusage_get_coupon_shortcode_page_id();
      }
      //echo "test: " . $dashboardpage . "<br/>";
      $dropdown_args = array(
          'post_type'        => 'page',
          'selected'         => esc_html($dashboardpage),
          'name'             => 'wcusage_options[wcusage_dashboard_page]',
          'id'               => 'wcusage_dashboard_page',
          'value_field'      => 'wcusage_dashboard_page',
          'show_option_none' => '-',
      );
      foreach ( $dropdown_args as $key => $value ) {
        if ( is_string( $value ) ) {
            $dropdown_args[ $key ] = esc_attr( $value );
        }
      }
      wp_dropdown_pages( $dropdown_args );

      // Show the link
      echo "<br/><a style='margin-top: 5px; display: inline-block;' id='dashboard_link' href='".esc_url(get_permalink($dashboardpage))."' target='_blank'>".esc_url(get_permalink($dashboardpage))."</a>";
      ?>

      <script type="text/javascript">
          // jQuery is assumed to be loaded in WordPress by default
          jQuery(document).ready(function($){
              $('#wcusage_dashboard_page').on('change', function(){
                  var pageID = $(this).val();
                  // Get the URL of the selected page using WordPress AJAX (Assuming you have an AJAX handler that returns the permalink of a page given its ID)
                  jQuery.post(
                      '<?php echo esc_url(admin_url("admin-ajax.php")); ?>', 
                      {
                          'action': 'wcusage_get_permalink',
                          'page_id': pageID
                      }
                  )
                  .done(function(response){
                      if(!response) {
                        $('#dashboard_link').hide();
                      } else {
                        $('#dashboard_link').show();
                      }
                      $('#dashboard_link').attr('href', response);
                      $('#dashboard_link').text(response); 
                  })
                  .fail(function() {
                      alert('AJAX request failed');  // debugging line
                  });
              });
          });
      </script>
      
      <br/>
      <i><?php echo esc_html__( '(The page that has the [couponaffiliates] shortcode on.)', 'woo-coupon-usage' ); ?></i>
      <br/>    

    <?php } else { ?>

      <!-- Showing number input if WPML installed -->
      <?php echo wcusage_setting_number_option('wcusage_dashboard_page', '', esc_html__( 'Affiliate Dashboard Page (ID):', 'woo-coupon-usage' ), '0px'); ?>

    <?php } ?>

  <?php
  }
}

/**
 * Get Permalink AJAX
 *
 */
function wcusage_get_permalink_ajax() {
  $page_id = intval($_POST['page_id']);
  echo esc_url(get_permalink($page_id));
  die();
}
add_action('wp_ajax_wcusage_get_permalink', 'wcusage_get_permalink_ajax');

/**
 * Settings Section: Order/Sales Tracking
 *
 */
add_action( 'wcusage_hook_setting_section_ordersalestracking', 'wcusage_setting_section_ordersalestracking', 10, 1 );
if( !function_exists( 'wcusage_setting_section_ordersalestracking' ) ) {
  function wcusage_setting_section_ordersalestracking($type = "") {

  $options = get_option( 'wcusage_options' );
  ?>

    <p class="option_wcusage_field_order_type">
      <?php
      $wcusage_field_order_type = wcusage_get_setting_value('wcusage_field_order_type', '');
      $wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');
      ?>

      <!-- Order Status Type Field -->
      <strong><label for="scales"><?php echo esc_html__( 'Required order status to show on affiliate dashboard:', 'woo-coupon-usage' ); ?></label></strong><br/>

        <?php
        if( function_exists('wc_get_order_statuses') ) {
          $orderstatuses = wc_get_order_statuses();
        } else {
          $orderstatuses = array(
            'wc-pending'    => esc_html__( 'Pending payment', 'woocommerce' ),
            'wc-processing' => esc_html__( 'Processing', 'woocommerce' ),
            'wc-on-hold'    => esc_html__( 'On hold', 'woocommerce' ),
            'wc-completed'  => esc_html__( 'Completed', 'woocommerce' ),
            'wc-cancelled'  => esc_html__( 'Cancelled', 'woocommerce' ),
            'wc-refunded'   => esc_html__( 'Refunded', 'woocommerce' ),
            'wc-failed'     => esc_html__( 'Failed', 'woocommerce' ),
          );
        }
        $i = 0;
        foreach( $orderstatuses as $key => $status ){

          if($status == "Refunded") {
            if(isset($options['wcusage_field_order_type_custom'][$key])) {
              $current = $options['wcusage_field_order_type_custom'][$key];
            }
            if( !isset($current) ) {
              continue;
            }
          }

          $i++;
          if($i == 1) { $thisid = "wcusage_field_order_type_custom"; }

          $checkedx = "";

          if($wcusage_field_order_type_custom) {
            if( isset($options['wcusage_field_order_type_custom'][$key]) ) {
              // Get Current Input Value
              $current = $options['wcusage_field_order_type_custom'][$key];
              // See if Checked
              if( isset($current) ) {
                $checkedx = "checked";
              }
            }
          }

          // MAKE COMPATIBLE WITH OLD SETTING
          if( ( !$wcusage_field_order_type_custom && $wcusage_field_order_type ) || ( !$wcusage_field_order_type_custom && !$wcusage_field_order_type ) ) {
            if($wcusage_field_order_type == "completed") {
              if($key == "wc-completed") {
                $checkedx = "checked";
              }
            } else {
              if($key == "wc-completed" || $key == "wc-processing") {
                $checkedx = "checked";
              }
            }
          }

          // Force completed to be checked
          if($key == "wc-completed") {
            if(!isset($options['wcusage_field_order_type_custom']['wc-completed']) || $checkedx) {
              $option_group = get_option('wcusage_options');
              $option_group['wcusage_field_order_type_custom']['wc-completed'] = "on";
              update_option( 'wcusage_options', $option_group );
              $checkedx = "checked";
            }
          }

          // Force processing to be checked on first time load settings
          if( !get_option('wcusage_field_order_type_custom_isset') && !isset($options['wcusage_field_load_ajax']) && $key == "wc-processing" ) {
            $option_group = get_option('wcusage_options');
            $option_group['wcusage_field_order_type_custom']['wc-processing'] = "on";
            update_option( 'wcusage_options', $option_group );
            $checkedx = "checked";
          }

          $extrastyles = "";
          if($key == "wc-completed" && $checkedx == "checked") {
            $extrastyles = ' pointer-events: none !important; opacity: 0.6;';
          }

          // Output Checkbox
          if(!$type) {
            $name = 'wcusage_options[wcusage_field_order_type_custom]['.$key.']';
          } else {
            $name = 'wcusage_field_order_type_custom['.$key.']';
          }
          echo '<span style="margin-right: 20px;'.esc_attr($extrastyles).'" id="'.esc_attr($thisid).'">
          <input type="checkbox"
          style="'.esc_attr($extrastyles).'" checktype="multi"
          class="order-status-checkbox-'.esc_attr($key).'"
          checktypekey="'.esc_attr($key).'"
          customid="'.esc_attr($thisid).'"
          name="'.esc_attr($name).'"
          '.esc_attr($checkedx).'> '.esc_attr($status).'</span>';


        }
        update_option( 'wcusage_field_order_type_custom_isset', 1 );
        ?>

        <br/><i><?php echo esc_html__( 'This will affect the coupon usage stats, orders list, commission, and monthly summary.', 'woo-coupon-usage' ); ?></i> <i><?php echo esc_html__( 'Affiliate stats will be automatically refreshed when changing these statuses.', 'woo-coupon-usage' ); ?></i>

        <br/><i><?php echo esc_html__( 'For "commission payouts" in PRO, for "unpaid commission" to be granted, the order status must be "completed".', 'woo-coupon-usage' ); ?></i>

      </p>

      <div class="setup-hide">

        <?php $wcusage_field_order_sort = wcusage_get_setting_value('wcusage_field_order_sort', 'paiddate'); ?>

        <?php if( $wcusage_field_order_sort != "completeddate" ) { ?>
        <br/>
        <p><strong><?php echo esc_html__( 'Advanced Orders Settings', 'woo-coupon-usage' ); ?>:</strong>
        <button type="button" class="wcu-showhide-button" id="wcu_show_orders_advanced">Show <span class="fa-solid fa-arrow-down"></span></button></p>

        <?php echo wcu_admin_settings_showhide_toggle("wcu_show_orders_advanced", "wcu_orders_advanced", "Show", "Hide"); ?>
        <div id="wcu_orders_advanced" style="display: none;">
        <?php } ?>

        <br/>

        <!-- How to sort orders -->
        <p>
          <input type="hidden" value="0" id="wcusage_field_order_sort" data-custom="custom" name="wcusage_options[wcusage_field_order_sort]" >

          <style>
          .order-status-checkbox-wc-completed {
            pointer-events: none !important;
          }
          </style>
          <script>
          jQuery( document ).ready(function() {
            check_order_sort_dropdown();
          });
          function check_order_sort_dropdown() {
            var value = jQuery('.wcusage_field_order_sort_option:selected').val();
            if (value === 'completeddate') {
              jQuery('.option_wcusage_field_order_type').css('opacity', '0.75');
            } else {
              jQuery('.option_wcusage_field_order_type').css('opacity', '1');
            }
            if ( jQuery('.wcusage_field_order_sort_option:selected').val() == "completeddate" ) {
              jQuery(".wcu-field-section-message-orders-sort-completed").show();
            } else {
              jQuery(".wcu-field-section-message-orders-sort-completed").hide();
            }
          }
          </script>
          <strong><label for="scales"><?php echo esc_html__( 'By which date should orders be sorted on the affiliate dashboard?', 'woo-coupon-usage' ); ?></label></strong><br/>
          <select name="wcusage_options[wcusage_field_order_sort]" id="wcusage_field_order_sort" onchange="check_order_sort_dropdown()">
            <option class="wcusage_field_order_sort_option" value="paiddate" <?php if($wcusage_field_order_sort == "paiddate") { ?>selected<?php } ?>><?php echo esc_html__( 'Created Date (Recommended)', 'woo-coupon-usage' ); ?></option>
            <option class="wcusage_field_order_sort_option" value="completeddate" <?php if($wcusage_field_order_sort == "completeddate") { ?>selected<?php } ?>><?php echo esc_html__( 'Completed Date', 'woo-coupon-usage' ); ?></option>
          </select>
          <br/><i><?php echo esc_html__( 'This will determine how the orders are sorted on the affiliate dashboard, either by the day they were paid for, or the day it was set to completed.', 'woo-coupon-usage' ); ?></i>
          <span class="wcu-field-section-message-orders-sort-completed" style="display: none;">
            <br/>
            <i style="color: red; font-size: 15px; font-weight: bold;">
              <?php echo esc_html__( 'NOTE: If set to "Completed Date", only orders that have been marked as "completed" (at-least once) can be displayed on the dashboard.', 'woo-coupon-usage' ); ?>
              <br/>
              <?php echo esc_html__( 'This may therefore disregard some of the order statuses that are checked above.', 'woo-coupon-usage' ); ?>
              <?php echo esc_html__( 'Ideally you should only enable "completed" order statuses above if you have "Completed Date" selected.', 'woo-coupon-usage' ); ?>
            </i>
          </span>

        <?php if( $wcusage_field_order_sort != "completeddate" ) { ?>
        </div>
        <?php } ?>

      </div>

  	</p>

  <?php
  }
}