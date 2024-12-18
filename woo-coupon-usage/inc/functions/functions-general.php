<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filters to render text from wp editor fields
 *
 */
add_filter( 'wcu_meta_content', 'wptexturize' );
add_filter( 'wcu_meta_content', 'convert_smilies' );
add_filter( 'wcu_meta_content', 'convert_chars' );
add_filter( 'wcu_meta_content', 'wpautop' );
add_filter( 'wcu_meta_content', 'shortcode_unautop' );
add_filter( 'wcu_meta_content', 'prepend_attachment' );
add_filter( 'wcu_meta_content', 'do_shortcode' );

/**
 * Load admin ajax only on pages that include main plugin shortcode.
 *
 */
add_action( 'wp_enqueue_scripts', 'wcusage_enqueue_frontend_ajax', 100 );
if( !function_exists( 'wcusage_enqueue_frontend_ajax' ) ) {
 function wcusage_enqueue_frontend_ajax() {
   $post_id = get_the_ID();
   $dashboard_page = wcusage_get_setting_value('wcusage_dashboard_page', '');
   $mla_dashboard_page = wcusage_get_setting_value('wcusage_mla_dashboard_page', '');
   if( function_exists( 'is_product' ) ) {
     if( ( !is_front_page() && !is_product() ) || $post_id == $dashboard_page ) {
       if( $post_id == $dashboard_page || $post_id == $mla_dashboard_page || is_account_page() || wcusage_page_contain_shortcode($post_id) ) {
         add_filter( 'script_loader_tag', 'wcusage_remove_defer_js', 100, 1 );
       }
     }
   }
 }
}

/**
 * Replaces "defer" with nothing.
 *
 */
function wcusage_remove_defer_js( $url ) {
  return str_replace( ' defer', '', $url );
}

/**
 * Fix javascript deferred conflicts on pages that include main plugin shortcode.
 *
 */
add_action( 'wp_head', 'wcusage_fix_defer_js', 1 );
if( !function_exists( 'wcusage_fix_defer_js' ) ) {
  function wcusage_fix_defer_js() {
    if ( is_plugin_active( 'wp-rocket/wp-rocket.php' )
    || is_plugin_active( 'perfmatters/perfmatters.php' )
    || is_plugin_active( 'autoptimize/autoptimize.php' )
    || is_plugin_active( 'flying-press/flying-press.php' ) ) {
      $post_id = get_the_ID();
      $dashboard_page = wcusage_get_setting_value('wcusage_dashboard_page', '');
      $mla_dashboard_page = wcusage_get_setting_value('wcusage_mla_dashboard_page', '');
      if( $post_id == $dashboard_page || $post_id == $mla_dashboard_page || is_account_page() ) {
        // WP Rocket
        if ( is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {
          add_filter( 'pre_get_rocket_option_defer_all_js', '__return_zero' );
        }
        // Perfmatters
        if ( is_plugin_active( 'perfmatters/perfmatters.php' ) ) {
          add_filter('perfmatters_defer_js', function($defer) { return false; });
        }
        // Autoptimize
        if ( is_plugin_active( 'autoptimize/autoptimize.php' ) ) {
          add_filter('autoptimize_filter_js_defer','__return_false');
        }
        // FlyingPress
        if ( is_plugin_active( 'flying-press/flying-press.php' ) ) {
          add_filter('flying_press_is_cacheable', false);
          add_filter('flying_press_exclude_from_minify:js', function($exclude_keywords){
            $exclude_keywords = array_merge($exclude_keywords, array('woo-coupon-usage'));
            return $exclude_keywords;
          });
        }
      }
    }
  }
}

/**
 * Fix caching issues on affiliate dashboard
 *
 */
function wcusage_fix_cache() {
	$post_id = get_the_ID();
	$dashboard_page = wcusage_get_setting_value('wcusage_dashboard_page', '');
	$mla_dashboard_page = wcusage_get_setting_value('wcusage_mla_dashboard_page', '');
	if( $post_id == $dashboard_page || $post_id == $mla_dashboard_page || is_account_page() ) {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		  define( 'DONOTCACHEPAGE', true );
    }
    if ( function_exists( 'nocache_headers' ) ) {
      nocache_headers();
    }
	}
}
add_action( 'template_redirect', 'wcusage_fix_cache' );

/**
 * Round down number to decimals
 *
 * @param int $decimal
 * @param int $precision
 *
 * @return int
 *
 */
if( !function_exists( 'wcusage_roundDown' ) ) {
  function wcusage_roundDown( $decimal, $precision )
  {

    $sign = ( $decimal > 0 ? 1 : -1 );
    $base = pow( 10, $precision );
  	$number = floor( abs( $decimal ) * $base ) / $base * $sign;

  	if($number <= 0) {
  		return 0;
  	} else {
  		return floor( abs( $decimal ) * $base ) / $base * $sign;
  	}

  }
}

/**
 * Function to trim number to 2 decimals
 *
 * @param int $number
 *
 * @return int
 *
 */
if( !function_exists( 'wcusage_trim_number' ) ) {
  function wcusage_trim_number($number) {
  	return number_format((float)str_replace( ',', '', $number ) , 2, '.', '');
  }
}

/**
 * Function to create shortcode that shows edit account form from WooCommerce
 *
 * @param mixed $atts
 *
 * @return mixed
 *
 */
if( !function_exists( 'wcusage_customer_edit_account_html_shortcode' ) ) {
  function wcusage_customer_edit_account_html_shortcode( $atts ) {

      // Attributes
      extract( shortcode_atts( array(
        'user' => get_current_user_id(),
        'text' => 'Edit Account' ), $atts ) );

      return wc_get_template_html( 'myaccount/form-edit-account.php', array( 'user' => get_user_by( 'id', $user ), 'text' => $text ) );

  }
}
add_shortcode( 'wcusage_customer_edit_account_html', 'wcusage_customer_edit_account_html_shortcode' );

/**
 * Function to create the redirect for shortcode page when edit profilee
 *
 */
if( !function_exists( 'wcusage_custom_profile_redirect' ) ) {
  function wcusage_custom_profile_redirect() {

      if(wcusage_get_coupon_shortcode_page_id()) {
  			if ( get_queried_object_id() == wcusage_get_coupon_shortcode_page_id() ) {
  				wp_redirect( $_SERVER['REQUEST_URI'] );
  				exit;
  			}
  		}

  }
}
add_action( 'profile_update', 'wcusage_custom_profile_redirect', 12 );

/**
 * Function to create the redirect for shortcode page when login
 *
 * @param string $redirect
 * @param string $user
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_custom_login_redirect' ) ) {
  function wcusage_custom_login_redirect( $redirect, $user ) {

  		if( wcusage_get_coupon_shortcode_page_id() ) {

  			$prev_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
  			$prev_path = str_replace( home_url(), '', $prev_url );
  		  $page = get_page_by_path( $prev_path );

  			if ( $page->ID == wcusage_get_coupon_shortcode_page_id() ) {

  				$redirect = get_page_link( wcusage_get_coupon_shortcode_page_id() );
  				wp_safe_redirect( $redirect, 302 );
  				exit;

  			}

  		}

  		return $redirect;

  }
}
add_action( 'woocommerce_login_redirect', 'wcusage_custom_login_redirect', 9999, 2 );


/**
 * Check if user has admin access based on settings
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_check_admin_access' ) ) {
  function wcusage_check_admin_access() {

  	$options = get_option( 'wcusage_options' );

  	if(isset($options['wcusage_field_admin_permission'])) {
  		$wcusage_field_order_sort = $options['wcusage_field_admin_permission'];
  	} else {
  		$wcusage_field_order_sort = "administrator";
  	}

    // Custom Filter
    $custom_filter = apply_filters( 'wcusage_custom_admin_access', false );

    // 
  	if( current_user_can($wcusage_field_order_sort) || current_user_can('administrator') || $custom_filter ) {
  		return true;
  	} else {
  		return false;
  	}

  	return false;

  }
}

/**
 * Check if coupon same as lifetime referrer assigned to it
 *
 * @param int $order_id
 * @param string $coupon_code
 *
 * @return bool
 *
 */
if( !function_exists( 'wcusage_check_lifetime_or_coupon' ) ) {
  function wcusage_check_lifetime_or_coupon($order_id, $coupon_code) {
  	$wcu_lifetime_referrer = wcusage_order_meta( $order_id, 'lifetime_affiliate_coupon_referrer', true );
  	if($wcu_lifetime_referrer) {
  		if($wcu_lifetime_referrer != $coupon_code) {
  			$lifetimecheck = false;
  		} else {
  			$lifetimecheck = true;
  		}
  	} else {
  		$lifetimecheck = true;
  	}
  	return $lifetimecheck;
  }
}

/**
 * Get a random color part used in wcusage_random_color()
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_random_color_part' ) ) {
  function wcusage_random_color_part() {
      return str_pad( dechex( mt_rand( 0, 255 ) ), 2, '0', STR_PAD_LEFT);
  }
}

/**
 * Get a random color code
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_random_color' ) ) {
  function wcusage_random_color() {
      return wcusage_random_color_part() . wcusage_random_color_part() . wcusage_random_color_part();
  }
}

/**
 * Convert order value to main currency
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_convert_order_value_to_currency' ) ) {
  function wcusage_convert_order_value_to_currency($orderinfo, $the_value) {

    if($orderinfo) {

      $currencycode = $orderinfo->get_currency();
      $wcusage_currency_conversion = wcusage_order_meta( $orderinfo->get_id(), 'wcusage_currency_conversion', true );

      $enable_save_rate = wcusage_get_setting_value('wcusage_field_enable_currency_save_rate', '0');
      if(!$wcusage_currency_conversion || !$enable_save_rate) {
        $wcusage_currency_conversion = "";
      }

      $enablecurrency = wcusage_get_setting_value('wcusage_field_enable_currency', '0');

      if($enablecurrency && $currencycode) {
        $the_value = wcusage_calculate_currency($currencycode, $the_value, $wcusage_currency_conversion);
      }

    }

    return $the_value;

  }
}

/**
 * Get woocommerce currency symbol
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_get_currency_symbol' ) ) {
  function wcusage_get_currency_symbol() {
  	if( function_exists('get_woocommerce_currency_symbol') ) {
  		$currency_symbol = get_woocommerce_currency_symbol();
  	} else {
  		$currency_symbol = "";
  	}
  	return $currency_symbol;
  }
}

/**
 * Converts Symbols In Ajax to Stop Modsec Firewall Block
 *
 * @param int $combined_commission
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_convert_symbols' ) ) {
  function wcusage_convert_symbols($combined_commission) {
  	$combined_commission = str_replace("%", "[[percent]]", $combined_commission);
  	$combined_commission = str_replace("+", "[[plus]]", $combined_commission);
  	$combined_commission = str_replace("$", "[[dollar]]", $combined_commission);
  	$combined_commission = str_replace("£", "[[pound]]", $combined_commission);
  	$combined_commission = str_replace("€", "[[euro]]", $combined_commission);
  	return sanitize_text_field($combined_commission);
  }
}

/**
 * Reverts Symbols In Ajax to Stop Modsec Firewall Block
 *
 * @param int $combined_commission
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_convert_symbols_revert' ) ) {
  function wcusage_convert_symbols_revert($combined_commission) {
  	$combined_commission = str_replace("[[percent]]", "%", $combined_commission);
  	$combined_commission = str_replace("[[plus]]", "+", $combined_commission);
  	$combined_commission = str_replace("[[dollar]]", "$", $combined_commission);
  	$combined_commission = str_replace("[[pound]]", "£", $combined_commission);
  	$combined_commission = str_replace("[[euro]]", "€", $combined_commission);
  	return sanitize_text_field($combined_commission);
  }
}

/**
 * Returns language code
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_get_language_code' ) ) {
  function wcusage_get_language_code() {
  	// Get Language
  	if (class_exists('SitePress')) {
  	  global $sitepress;
  	  $language = ICL_LANGUAGE_CODE;
  	} else {
  		$language = "";
  	}
  }
}

/**
 * WPML Support Function
 *
 * @param string $language
 *
 */
if( !function_exists( 'wcusage_load_custom_language_wpml' ) ) {
	function wcusage_load_custom_language_wpml($language) {
		if (class_exists('SitePress')) {
		  global $sitepress;
		  $sitepress->switch_lang($language, true);
		  load_plugin_textdomain( 'woo-coupon-usage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}
	}
}

/**
 * Returns ajax error message
 *
 * @return string
 *
 */
if( !function_exists( 'wcusage_ajax_error' ) ) {
  function wcusage_ajax_error() {

    $ajaxerrormessage = '<br/><span style="color: red; font-weight: bold;">' . wp_kses_post( 'ERROR: Failed to load ajax request. Session may have timed out. Refresh the page to try again.', 'woo-coupon-usage' ) . '</span>';
    if(current_user_can( 'edit_posts' )) {
      $ajaxerrormessage .= '<br/>Admin: If this keeps happening, <a href="https://couponaffiliates.com/docs/error-ajax-request/" target="_blank"><strong>click here</strong></a> for more information.';
    }

    return $ajaxerrormessage;

  }
}

/**
 * Returns username for ID
 *
 * @return string
 *
 */
function wcusage_get_username_by_id($user_id) {

  $user = get_user_by( 'ID', $user_id );
  $user_name = $user->user_login;

  return $user_name;

}