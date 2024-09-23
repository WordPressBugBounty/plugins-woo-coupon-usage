<?php
/**
 * Ajax load admin reports
 *
 */
function wcusage_load_admin_reports() {
  check_ajax_referer('wcusage_admin_ajax_nonce');
  ?>

  <script src="<?php echo WCUSAGE_UNIQUE_PLUGIN_URL; ?>/js/woo-coupon-usage.js"></script>
  <?php
  do_action('wcusage_hook_get_admin_report_data',
  $_POST["wcu_orders_start"],
  $_POST["wcu_orders_end"],
  $_POST["wcu_orders_start_compare"],
  $_POST["wcu_orders_end_compare"],
  $_POST["wcu_compare"],
  $_POST["wcu_orders_filtercompare_type"],
  $_POST["wcu_orders_filtercompare_amount"],
  $_POST["wcu_orders_filterusage_type"],
  $_POST["wcu_orders_filterusage_amount"],
  $_POST["wcu_orders_filtersales_type"],
  $_POST["wcu_orders_filtersales_amount"],
  $_POST["wcu_orders_filtercommission_type"],
  $_POST["wcu_orders_filtercommission_amount"],
  $_POST["wcu_orders_filterconversions_type"],
  $_POST["wcu_orders_filterconversions_amount"],
  $_POST["wcu_orders_filterunpaid_type"],
  $_POST["wcu_orders_filterunpaid_amount"],
  $_POST["wcu_report_users_only"],
  $_POST["wcu_report_show_sales"],
  $_POST["wcu_report_show_commission"],
  $_POST["wcu_report_show_url"],
  $_POST["wcu_report_show_products"]
  );
  ?>

<?php
}
add_action('wp_ajax_wcusage_load_admin_reports', 'wcusage_load_admin_reports');
