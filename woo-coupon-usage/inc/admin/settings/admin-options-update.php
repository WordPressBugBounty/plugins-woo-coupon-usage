<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wcusage_is_settings_page' ) ) {
  function wcusage_is_settings_page() {
    $is_wcu_settings_page = ( isset( $_GET['page'] ) && $_GET['page'] === 'wcusage_settings' );
    $screen_ok = false;
    if ( function_exists( 'get_current_screen' ) ) {
      $screen = get_current_screen();
      if ( isset( $screen->id ) ) {
        $screen_ok = ( $screen->id === 'coupon-affiliates_page_wcusage_settings' || false !== strpos( $screen->id, 'wcusage_settings' ) );
      }
    }
    return ( $is_wcu_settings_page || $screen_ok );
  }
}

if ( ! function_exists( 'wcusage_send_settings_nocache_headers' ) ) {
  function wcusage_send_settings_nocache_headers() {
    if ( ! function_exists( 'nocache_headers' ) ) {
      return;
    }
    if ( ! wcusage_is_settings_page() ) {
      return;
    }
    nocache_headers();
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
  }
}

// Ajax Script
function wcusage_admin_options_update_scripts( $hook_suffix ) {

  if ( ! current_user_can( 'manage_options' ) ) {
    return;
  }

  // Only load on our settings page. Be resilient to screen ID differences.
  if ( ! wcusage_is_settings_page() ) {
    return;
  }

  wcusage_send_settings_nocache_headers();

  // Enqueue our script early (in head) and localize data.
  $rel_path  = '../../../js/admin-options-update.js';
  $script_url  = plugin_dir_url( __FILE__ ) . $rel_path;
  $script_path = plugin_dir_path( __FILE__ ) . $rel_path;
  $version = file_exists( $script_path ) ? filemtime( $script_path ) : false;

  wp_enqueue_script( 'wcusage-admin-options-update', $script_url, array( 'jquery' ), $version, false ); // false = load in head
  wp_localize_script( 'wcusage-admin-options-update', 'wcusageUpdate', array(
    // Relative on purpose. This script only ever runs inside wp-admin, and the
    // absolute URL failed in the browser whenever the admin session was not on the
    // exact scheme and host stored in siteurl - Cloudflare "flexible" SSL with an
    // http siteurl, a www/non-www or staging alias - so every save died with a
    // bare "Failed to update" and nothing to go on.
    'ajax_url'       => admin_url( 'admin-ajax.php', 'relative' ),
    'nonce'          => wp_create_nonce( 'wcusage_dashboard_settings_ajax_nonce' ),
    // What PHP will accept in one POST; the script splits bulk saves to fit.
    'max_input_vars' => (int) ini_get( 'max_input_vars' ),
    'i18n'           => array(
      'save_failed'            => __( 'This setting could not be saved.', 'woo-coupon-usage' ),
      'fail_blocked'           => __( 'The request never reached the server. It was blocked by the browser or the network - usually mixed HTTP/HTTPS content or a different domain to your WordPress Address, a firewall, or an ad blocker.', 'woo-coupon-usage' ),
      'fail_forbidden'         => __( 'The server refused the request (HTTP 403). Reload this page in case your login session has expired; if it keeps happening, a security plugin or web application firewall is blocking admin-ajax.php.', 'woo-coupon-usage' ),
      /* translators: %s: HTTP status code */
      'fail_not_found'         => __( 'The server did not recognise the save action (HTTP %s). Something on this site is restricting admin-ajax.php.', 'woo-coupon-usage' ),
      /* translators: %s: HTTP status code */
      'fail_server'            => __( 'The server returned an error (HTTP %s). Check your PHP error log for the cause.', 'woo-coupon-usage' ),
      'fail_parse'             => __( 'The server sent back something other than the expected data - usually a PHP notice or warning printed by another plugin. The raw response is in the browser console.', 'woo-coupon-usage' ),
      /* translators: %s: HTTP status code and text */
      'fail_generic'           => __( 'Unexpected response: %s', 'woo-coupon-usage' ),
      /* translators: 1: batch number, 2: total batches */
      'bulk_saving'            => __( 'Saving all settings (batch %1$s of %2$s)...', 'woo-coupon-usage' ),
      'bulk_saved'             => __( 'All settings have been saved.', 'woo-coupon-usage' ),
      /* translators: 1: batch number, 2: total batches, 3: reason */
      'bulk_failed'            => __( 'Saving stopped at batch %1$s of %2$s: %3$s', 'woo-coupon-usage' ),
      /* translators: 1: number of fields the form posts, 2: current max_input_vars, 3: suggested max_input_vars */
      'max_input_vars_warning' => __( 'This settings form posts %1$s fields, but the PHP "max_input_vars" limit on your server is %2$s, so PHP would silently discard everything after the limit. "Save All Settings" and "Save Settings" therefore save in smaller batches automatically. Settings saved automatically as you change them are not affected. To save everything in one request, ask your host to raise "max_input_vars" to %3$s or higher.', 'woo-coupon-usage' ),
    ),
  ) );
}
add_action( 'admin_enqueue_scripts', 'wcusage_admin_options_update_scripts', 5 );

/**
 * Message returned when wcusage_update_options_merge() reports a failed write.
 */
if ( ! function_exists( 'wcusage_settings_write_failed_message' ) ) {
  function wcusage_settings_write_failed_message() {
    return __( 'WordPress could not write the setting to the database. Check that the database is not read-only or full, and that no other plugin is intercepting the "wcusage_options" option.', 'woo-coupon-usage' );
  }
}

/***************
***** UPDATE: Text Input
***************/
add_action( 'wp_ajax_wcu-update-text', 'wcu_update_text' );
function wcu_update_text() {

  if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array( 'message' => 'Unauthorized' ) );
  }

  check_ajax_referer('wcusage_dashboard_settings_ajax_nonce');

  $option = sanitize_text_field( $_POST['option'] ?? '' );
  if ( empty( $option ) ) {
    wp_send_json_error( array( 'message' => 'Missing required information.' ) );
  }

  $value = sanitize_textarea_field( htmlentities( $_POST['value'] ?? '' ) );
  $value = html_entity_decode( stripslashes( $value ) );
  
  $CustomNum = sanitize_text_field( $_POST['customnum'] ?? '' );

  // What is stored right now, so the refresh bump below can be skipped when the
  // value is not actually changing (see wcusage_check_if_option_refresh_stats).
  $current  = wcusage_get_options();
  $previous = ( is_array( $current ) && array_key_exists( $option, $current ) ) ? $current[$option] : null;

  // Handle nested array values
  if ( $CustomNum ) {
    $CustomNum1 = sanitize_text_field( $_POST['customnum1'] ?? '' );
    $CustomNum2 = sanitize_text_field( $_POST['customnum2'] ?? '' );
    
    $nested = isset( $current[$option] ) && is_array( $current[$option] ) ? $current[$option] : array();
    $nested[$CustomNum1][$CustomNum2] = $value;
    
    $new_value = $nested;
  } else {
    $new_value = $value;
  }

  if ( false === wcusage_update_options_merge( array( $option => $new_value ) ) ) {
    wp_send_json_error( array( 'message' => wcusage_settings_write_failed_message() ) );
  }

  // A key that did not exist before counts as a change: the rate is new, so any
  // stats calculated before it was set are genuinely out of date.
  wcusage_check_if_option_refresh_stats( $option, ( null === $previous || $previous != $new_value ) );

  wp_send_json_success();
}

/***************
***** UPDATE: Toggles
***************/
add_action( 'wp_ajax_wcu-update-toggle', 'wcu_update_toggle' );
function wcu_update_toggle() {

  if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array( 'message' => 'Unauthorized' ) );
  }

  check_ajax_referer('wcusage_dashboard_settings_ajax_nonce');

  $option = sanitize_text_field( $_POST['option'] ?? '' );
  if ( empty( $option ) ) {
    wp_send_json_error( array( 'message' => 'Missing required information.' ) );
  }

  $multi = sanitize_text_field( $_POST['multi'] ?? '' );
  $value = sanitize_text_field( $_POST['value'] ?? '' );
  $key = sanitize_text_field( $_POST['key'] ?? '' );

  // What is stored right now, so the refresh bump below can be skipped when the
  // value is not actually changing (see wcusage_check_if_option_refresh_stats).
  $current  = wcusage_get_options();
  $previous = ( is_array( $current ) && array_key_exists( $option, $current ) ) ? $current[$option] : null;

  // Handle multi-checkbox (array) toggles
  if ( $multi ) {
    $existing = isset( $current[$option] ) ? $current[$option] : array();
    
    // Convert non-array to array
    if ( ! is_array( $existing ) ) {
      $existing = $existing ? array( $existing => 'on' ) : array();
    }
    
    if ( $value ) {
      $existing[$key] = 'on';
    } else {
      unset( $existing[$key] );
    }
    
    $new_value = $existing;
  } else {
    // Handle single toggle
    $new_value = ( $value === '1' || $value === 1 || $value === true || $value === 'true' ) ? '1' : '0';
  }

  if ( false === wcusage_update_options_merge( array( $option => $new_value ) ) ) {
    wp_send_json_error( array( 'message' => wcusage_settings_write_failed_message() ) );
  }

  // A key that did not exist before counts as a change: the setting is new, so
  // any stats calculated before it was set are genuinely out of date.
  wcusage_check_if_option_refresh_stats( $option, ( null === $previous || $previous != $new_value ) );

  wp_send_json_success();
}

/**
 * Bump the site-wide statistics refresh date when a commission-affecting
 * setting changes.
 *
 * $changed exists because the settings page saves over ajax on every 'change'
 * event (js/admin-options-update.js), and this ran on the option KEY alone.
 * Re-picking the same value in a dropdown, or toggling a setting off and back
 * on, therefore queued a full statistics recalculation for every affiliate on
 * the site - each one greeted with the "Calculating statistics..." screen on
 * their next dashboard load. The updated_option path below has always compared
 * old against new; this one simply never did.
 *
 * @param string    $option  Option key that was written.
 * @param bool|null $changed Whether the value actually changed. Pass false to
 *                           skip the refresh bump. Null (the default) keeps the
 *                           original bump-on-key-name behaviour for callers
 *                           that cannot tell.
 * @return void
 */
function wcusage_check_if_option_refresh_stats($option, $changed = null) {
  $never_update_commission_meta = wcusage_get_setting_value('wcusage_field_enable_never_update_commission_meta', '0');
  if ( $never_update_commission_meta ) {
    return;
  }
  
  // Only refresh for specific options that affect commission calculations
  static $refresh_keys = null;
  if ( $refresh_keys === null ) {
    $refresh_keys = array_flip( array(
      'wcusage_field_affiliate',
      'wcusage_field_affiliate_fixed_order',
      'wcusage_field_affiliate_fixed_product',
      'wcusage_field_commission_before_discount',
      'wcusage_field_commission_include_shipping',
      'wcusage_field_commission_before_discount_custom',
      'wcusage_field_commission_blended_discount_rate',
      'wcusage_field_commission_include_fees',
      'wcusage_field_commission_exclude_restricted_products',
      'wcusage_field_order_max_commission',
      'wcusage_field_commission_rounding_mode',
      'wcusage_field_show_tax',
      'wcusage_field_affiliate_deduct_percent',
      'wcusage_field_priority_commission',
      'wcusage_field_affiliate_deduct_percent_show',
      'wcusage_field_order_type_custom',
      'wcusage_field_order_sort'
    ) );
  }
  
  if ( false !== $changed && isset( $refresh_keys[$option] ) ) {
    wcusage_update_options_merge( array( 'wcusage_refresh_date' => time() ) );
  }
  
  do_action('wcusage_check_if_option_refresh_stats', $option);
}

// Hook into options update
function wcusage_check_portal_option_update($option) {
  if($option == "wcusage_field_portal_enable") {
    $option_group = wcusage_get_options();
    // Built by the portal itself so this matches the rule that init registered. Note the
    // helper only exists when the portal was already enabled as this request loaded.
    $portal_rule = function_exists('wcusage_get_affiliate_portal_rewrite_regex') ? wcusage_get_affiliate_portal_rewrite_regex() : '';
    if($option_group['wcusage_field_portal_enable'] == "1") {
      if($portal_rule) {
        add_rewrite_rule($portal_rule, 'index.php?affiliate_portal=1', 'top');
      }
    } else {
      // Remove the rewrite rule. It was registered on init earlier in this same request,
      // so it has to come out of the in-memory set too - flushing alone writes it back.
      global $wp_rewrite;
      if($portal_rule) {
        unset($wp_rewrite->extra_rules_top[$portal_rule]);
        $rules = get_option('rewrite_rules');
        if(isset($rules[$portal_rule])) {
          unset($rules[$portal_rule]);
          update_option('rewrite_rules', $rules);
        }
      }
    }
    // Clear the marker either way, so wcusage_flush_rewrite_rules1() reinstalls the rule
    // on a later request if this flush could not.
    delete_option('wcusage_portal_rules_flushed');
    flush_rewrite_rules();
  }
  // If wcusage_portal_slug is updated and wcusage_field_portal_enable is enabled
  if($option == "wcusage_portal_slug") {
    $option_group = wcusage_get_options();
    if($option_group['wcusage_field_portal_enable'] == "1") {
      if(function_exists('wcusage_get_affiliate_portal_rewrite_regex')) {
        add_rewrite_rule( wcusage_get_affiliate_portal_rewrite_regex($option_group['wcusage_portal_slug']), 'index.php?affiliate_portal=1', 'top');
      }
      // The slug changed, so the old marker no longer describes what is installed.
      delete_option('wcusage_portal_rules_flushed');
      flush_rewrite_rules();
    }
  }
  // If wcusage_mla_portal_slug is updated and wcusage_field_portal_enable is enabled
  if($option == "wcusage_mla_portal_slug") {
    $option_group = wcusage_get_options();
    if($option_group['wcusage_field_portal_enable'] == "1") {
      $wcusage_mla_portal_slug = isset($option_group['wcusage_mla_portal_slug']) ? sanitize_title($option_group['wcusage_mla_portal_slug']) : 'mla-affiliate-portal';
      if ( ! $wcusage_mla_portal_slug ) {
        $wcusage_mla_portal_slug = 'mla-affiliate-portal';
      }
      add_rewrite_rule('^' . preg_quote( $wcusage_mla_portal_slug, '/' ) . '/?$', 'index.php?mla_affiliate_portal=1', 'top');
      add_rewrite_rule('^' . preg_quote( $wcusage_mla_portal_slug, '/' ) . '/user/([^/]+)/?$', 'index.php?mla_affiliate_portal=1&mla_user=$matches[1]', 'top');
      // The slug changed, so the old marker no longer describes what is installed.
      delete_option('wcusage_mla_portal_rules_flushed');
      flush_rewrite_rules();
    }
  }
}
add_action('wcusage_check_if_option_refresh_stats', 'wcusage_check_portal_option_update', 10, 2);

// Post: Refresh Stats for certain updates updating
add_action('updated_option', 'wcusage_check_if_option_refresh_stats_post', 10, 3);
function wcusage_check_if_option_refresh_stats_post($option_name, $old_value, $value) {
    if ( 'wcusage_options' !== $option_name ) {
      return;
    }
    
    $never_update_commission_meta = wcusage_get_setting_value('wcusage_field_enable_never_update_commission_meta', '0');
    if ( $never_update_commission_meta ) {
      return;
    }
    
    static $refresh_keys = null;
    if ( $refresh_keys === null ) {
      $refresh_keys = array(
        'wcusage_field_affiliate',
        'wcusage_field_affiliate_fixed_order',
        'wcusage_field_affiliate_fixed_product',
        'wcusage_field_commission_before_discount',
        'wcusage_field_commission_include_shipping',
        'wcusage_field_commission_before_discount_custom',
        'wcusage_field_commission_blended_discount_rate',
        'wcusage_field_commission_include_fees',
      'wcusage_field_commission_exclude_restricted_products',
        'wcusage_field_order_max_commission',
        'wcusage_field_commission_rounding_mode',
        'wcusage_field_show_tax',
        'wcusage_field_affiliate_deduct_percent',
        'wcusage_field_priority_commission',
        'wcusage_field_affiliate_deduct_percent_show',
        'wcusage_field_order_type_custom',
        'wcusage_field_order_sort'
      );
    }
    
    foreach ( $refresh_keys as $key_interest ) {
      if ( isset( $old_value[$key_interest] ) && isset( $value[$key_interest] ) ) {
        if ( $old_value[$key_interest] != $value[$key_interest] ) {
          wcusage_update_options_merge( array( 'wcusage_refresh_date' => time() ) );
          break;
        }
      }
    }
}