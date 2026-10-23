<?php
/**
 * Migration tool: what keeps working after a migration, on every request.
 *
 * 1. Orders imported with "keep the original commission amounts" keep the
 *    amount the old plugin recorded, however often the statistics are
 *    recalculated. A partial refund afterwards takes the refunded share off
 *    it; once the order is fully refunded, cancelled or failed, Coupon
 *    Affiliates works the commission out again the normal way. Orders
 *    whose commission the migration took back from a coupon, because the old
 *    plugin did not credit them, earn that coupon nothing at all.
 * 2. Referral links shared while the old plugin was in use (?ref=123,
 *    /ref/jane/ and so on) are translated into this plugin's own referral, so
 *    affiliates do not have to replace every link they have ever posted.
 * 3. For a while after the migration, a visitor who arrives with the old
 *    plugin's referral cookie is credited to the same affiliate.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: the commission amount imported for the order. */
define( 'WCUSAGE_MIGRATED_COMMISSION_META', '_wcusage_migrated_commission' );

/** Order meta: how many refunds the order had when it was imported. */
define( 'WCUSAGE_MIGRATED_REFUNDS_META', '_wcusage_migrated_refunds' );

/** Order meta: the order's total less refunds when it was imported. */
define( 'WCUSAGE_MIGRATED_NET_META', '_wcusage_migrated_net' );

/** Order meta: "source:record ID" the order's referral was imported from. */
define( 'WCUSAGE_MIGRATED_FROM_META', '_wcusage_migrated_from' );

/** Order meta: set when commission the old plugin had already paid out was recorded as granted. */
define( 'WCUSAGE_MIGRATED_PAID_META', '_wcusage_migrated_paid' );

/** Coupon meta: commission imported with no order behind it (see the migration writer). */
define( 'WCUSAGE_MIGRATED_ADJUSTMENT_META', 'wcu_migrated_balance_adjustment' );

/** Order meta: comma-separated codes of the coupons whose commission on the order the migration took back. */
define( 'WCUSAGE_MIGRATED_RELEASED_META', '_wcusage_migrated_released' );

/**
 * The commission amount an imported order is locked to, if any.
 *
 * Returns null when the order was not imported with a locked amount, and for
 * refunded, cancelled or failed orders, which Coupon Affiliates works out
 * again. An order refunded in part since the migration keeps the imported
 * amount less the refunded share of the order.
 *
 * @param int|WC_Order $order Order or ID.
 * @return float|null
 */
function wcusage_migration_locked_commission( $order ) {

	if ( ! $order instanceof WC_Order ) {
		$order = $order ? wc_get_order( $order ) : false;
	}
	if ( ! $order instanceof WC_Order ) {
		return null;
	}

	$amount = $order->get_meta( WCUSAGE_MIGRATED_COMMISSION_META, true );
	if ( '' === $amount || null === $amount || ! is_numeric( $amount ) ) {
		return null;
	}

	if ( in_array( $order->get_status(), array( 'refunded', 'cancelled', 'failed' ), true ) ) {
		return null;
	}

	// Refunded in part since: the old plugin's amount, less the refunded share.
	// Letting the plugin work it out again instead would switch the whole order
	// to this plugin's rates, so a small refund could change the commission by
	// far more than it refunded - or even raise it.
	$refunds_then = (int) $order->get_meta( WCUSAGE_MIGRATED_REFUNDS_META, true );
	if ( count( $order->get_refunds() ) > $refunds_then ) {
		$net_then = $order->get_meta( WCUSAGE_MIGRATED_NET_META, true );
		if ( ! is_numeric( $net_then ) || (float) $net_then <= 0 ) {
			return null;
		}
		$net_now = (float) $order->get_total() - (float) $order->get_total_refunded();
		return round( (float) $amount * max( 0, min( 1, $net_now / (float) $net_then ) ), 2 );
	}

	return round( (float) $amount, 2 );
}

/**
 * Whether a coupon is the one an imported order's commission was locked for.
 *
 * The migration always records the affiliate's coupon on the order as its
 * referrer, so that is the coupon the amount belongs to. Any other coupon
 * stacked on the same order is calculated as normal.
 *
 * @param WC_Order   $order
 * @param int|null    $coupon_id   Coupon ID, when the caller has one.
 * @param string|null $coupon_code Coupon code, when the caller has one. An
 *                                 empty code is a read-only screen asking for
 *                                 the order's own figures.
 * @return bool
 */
function wcusage_migration_lock_applies_to( $order, $coupon_id = null, $coupon_code = null ) {

	$referrer = (string) $order->get_meta( 'wcusage_referrer_coupon', true );
	if ( '' === $referrer ) {
		return false;
	}

	if ( null !== $coupon_code ) {
		$coupon_code = (string) $coupon_code;
		return '' === $coupon_code || wc_format_coupon_code( $coupon_code ) === wc_format_coupon_code( $referrer );
	}

	if ( ! $coupon_id ) {
		return true;
	}

	return (int) wc_get_coupon_id_by_code( $referrer ) === (int) $coupon_id;
}

/**
 * Keep the locked amount as the order's total commission.
 *
 * Applied where the plugin saves the order's combined commission into
 * "wcusage_stats", so the saved figures carry the imported amount too.
 *
 * @param float  $commission
 * @param int    $order_id
 * @param int    $coupon_id
 * @param int    $coupon_user
 * @param string $context     "percentage" for the percentage part alone, "total" for the combined
 *                            figure, "saved" for it rebuilt from the order's saved parts.
 * @return float
 */
function wcusage_migration_lock_total_commission( $commission, $order_id = 0, $coupon_id = 0, $coupon_user = 0, $context = '' ) {

	if ( ! in_array( $context, array( 'total', 'saved' ), true ) || ! $order_id ) {
		return $commission;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return $commission;
	}

	// The meta check first: looking the code up is a query of its own, and this
	// runs on every commission calculation, migrated or not.
	if ( $coupon_id && '' !== (string) $order->get_meta( WCUSAGE_MIGRATED_RELEASED_META, true ) && wcusage_migration_is_released( $order, wc_get_coupon_code_by_id( (int) $coupon_id ) ) ) {
		return 0;
	}

	$locked = wcusage_migration_locked_commission( $order );

	if ( null === $locked || ! wcusage_migration_lock_applies_to( $order, (int) $coupon_id ) ) {
		return $commission;
	}

	return $locked;
}
add_filter( 'wcusage_calculate_edit_commission', 'wcusage_migration_lock_total_commission', 99, 5 );

/**
 * Have the plugin apply the filter above to a migrated order even when it works
 * out no commission of its own for it (a rate the old plugin had that was not
 * carried over, say), so its saved figures still hold the imported amount.
 *
 * @param bool $filter
 * @param int  $order_id
 * @return bool
 */
function wcusage_migration_filter_zero_commission( $filter, $order_id = 0 ) {
	return $filter || ( $order_id && null !== wcusage_migration_locked_commission( $order_id ) );
}
add_filter( 'wcusage_filter_zero_order_commission', 'wcusage_migration_filter_zero_commission', 10, 2 );

/**
 * Keep the locked amount in the figures the order calculation hands back.
 *
 * The filter above does not run when the plugin works the commission out as
 * zero, and the saved-figures shortcut skips it altogether, so this is the
 * guarantee. The imported amount is already net of any refunds the old plugin
 * knew about, so no refund is taken off it again.
 *
 * @param array  $data
 * @param int    $order_id
 * @param string $coupon_code
 * @return array
 */
function wcusage_migration_lock_order_data( $data, $order_id = 0, $coupon_code = '' ) {

	if ( ! is_array( $data ) || ! $order_id || ! array_key_exists( 'totalcommission', $data ) ) {
		return $data;
	}

	$order = wc_get_order( $order_id );
	if ( $order instanceof WC_Order && '' !== (string) $coupon_code && wcusage_migration_is_released( $order, (string) $coupon_code ) ) {
		$data['totalcommission']           = 0;
		$data['refund_commission_total']   = 0;
		$data['refund_commission_product'] = 0;
		return $data;
	}

	$locked = wcusage_migration_locked_commission( $order );

	if ( null === $locked || ! wcusage_migration_lock_applies_to( $order, null, (string) $coupon_code ) ) {
		return $data;
	}

	$data['totalcommission']           = $locked;
	$data['refund_commission_total']   = 0;
	$data['refund_commission_product'] = 0;

	return $data;
}
add_filter( 'wcusage_get_calculate_order_data', 'wcusage_migration_lock_order_data', 99, 3 );

/**
 * Whether the migration took back this coupon's commission on the order: an
 * order the old plugin did not credit to the coupon's new affiliate.
 *
 * Such a coupon earns nothing on the order, whatever happens to it later, so
 * the filters above work its commission out as 0 - for the statistics, for
 * any later grant, and for the pending amount the plugin takes off again when
 * another coupon on the same order is granted.
 *
 * @param WC_Order $order
 * @param string   $coupon_code
 * @return bool
 */
function wcusage_migration_is_released( $order, $coupon_code ) {

	$coupon_code = wc_format_coupon_code( (string) $coupon_code );
	if ( '' === $coupon_code ) {
		return false;
	}

	$released = array_filter( explode( ',', (string) $order->get_meta( WCUSAGE_MIGRATED_RELEASED_META, true ) ) );

	return in_array( $coupon_code, $released, true );
}

/**
 * Keep the daily job (delayed commission) off commission the migration took
 * back, rather than have it grant - and log - nothing.
 *
 * @param bool   $grant
 * @param int    $order_id
 * @param string $coupon_code
 * @return bool
 */
function wcusage_migration_keep_released( $grant, $order_id = 0, $coupon_code = '' ) {

	if ( ! $grant || ! $order_id ) {
		return $grant;
	}

	$order = wc_get_order( $order_id );

	return $order instanceof WC_Order && wcusage_migration_is_released( $order, $coupon_code ) ? false : $grant;
}
add_filter( 'wcusage_delayed_commission_grant', 'wcusage_migration_keep_released', 10, 3 );

/**
 * The old referral links and cookies the site still honours.
 *
 * Saved by the migration, one entry per source:
 * array(
 *   'vars'     => array( 'ref' ),       // query string parameters
 *   'paths'    => array( 'ref' ),       // "/ref/{id}/" style path segments
 *   'campaign' => 'campaign',           // the old campaign parameter, if any
 *   'cookies'  => array( 'affwp_ref' ), // cookies holding an affiliate identifier
 *   'cookies_until' => 1767225600,      // honour the cookies until this time
 * )
 *
 * @return array
 */
function wcusage_migration_legacy_links() {
	// Saved autoloaded, so on a site that never migrated it is simply not among
	// the autoloaded options: no query for it on every request.
	if ( ! wp_installing() ) {
		$autoloaded = wp_load_alloptions();
		if ( ! isset( $autoloaded['wcusage_migration_legacy_links'] ) ) {
			return array();
		}
	}
	$config = get_option( 'wcusage_migration_legacy_links', array() );
	return is_array( $config ) ? $config : array();
}

/**
 * The coupon code an old referral identifier now stands for.
 *
 * The migration records every identifier an affiliate's links could have used
 * (ID, username, custom slug, token...) against their coupon.
 *
 * @param string $source Source ID.
 * @param string $value  Identifier from the link or cookie.
 * @return string Coupon code, or "" if it is not one of the migrated affiliates.
 */
function wcusage_migration_resolve_identifier( $source, $value ) {
	global $wpdb;

	$value = strtolower( trim( (string) $value ) );
	if ( '' === $value || strlen( $value ) > 191 ) {
		return '';
	}

	$table = $wpdb->prefix . 'wcusage_migration_map';

	$suppress  = $wpdb->suppress_errors( true );
	$coupon_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT target_id FROM {$table} WHERE source = %s AND entity = 'ref_id' AND source_id = %s ORDER BY id DESC LIMIT 1", $source, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// "?ref=05": the plugins read a number as a number.
	if ( ! $coupon_id && ctype_digit( $value ) && '0' === $value[0] && '' !== ltrim( $value, '0' ) ) {
		$coupon_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT target_id FROM {$table} WHERE source = %s AND entity = 'ref_id' AND source_id = %s ORDER BY id DESC LIMIT 1", $source, ltrim( $value, '0' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->suppress_errors( $suppress );

	if ( ! $coupon_id ) {
		return '';
	}

	$coupon = get_post( $coupon_id );
	if ( ! $coupon || 'shop_coupon' !== $coupon->post_type || 'publish' !== $coupon->post_status ) {
		return '';
	}

	return wc_format_coupon_code( $coupon->post_title );
}

/**
 * Translate an old referral link into a Coupon Affiliates one.
 *
 * Runs before the plugin's own referral handling (plugins_loaded, priority 1),
 * and simply supplies the referral parameter it reads - so the click is
 * recorded, the cookie set and the coupon applied exactly as for any other
 * referral link. "/ref/jane/" style paths are redirected to the page they were
 * appended to, with the referral parameter added.
 */
function wcusage_migration_handle_legacy_links() {

	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	$config = wcusage_migration_legacy_links();
	if ( empty( $config ) || ! function_exists( 'wcusage_get_setting_value' ) ) {
		return;
	}

	$prefix     = wcusage_get_setting_value( 'wcusage_field_urls_prefix', 'coupon' );
	$src_prefix = wcusage_get_setting_value( 'wcusage_field_src_prefix', 'src' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public referral links.

	// A link that names a coupon here already says who referred the visitor.
	if ( isset( $_GET[ $prefix ] ) ) {
		return;
	}

	foreach ( $config as $source => $links ) {

		foreach ( (array) ( $links['vars'] ?? array() ) as $var ) {
			if ( ! is_string( $var ) || '' === $var || ! isset( $_GET[ $var ] ) || ! is_scalar( $_GET[ $var ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_GET[ $var ] ) );
			$code  = wcusage_migration_resolve_identifier( $source, $value );
			if ( '' === $code ) {
				continue;
			}

			$_GET[ $prefix ] = $code;

			$campaign_var = isset( $links['campaign'] ) ? (string) $links['campaign'] : '';
			if ( $campaign_var && $campaign_var !== $src_prefix && isset( $_GET[ $campaign_var ] ) && ! isset( $_GET[ $src_prefix ] ) && is_scalar( $_GET[ $campaign_var ] ) ) {
				$_GET[ $src_prefix ] = sanitize_text_field( wp_unslash( $_GET[ $campaign_var ] ) );
			}

			return;
		}

		foreach ( (array) ( $links['paths'] ?? array() ) as $base ) {
			if ( ! is_string( $base ) || '' === $base || empty( $_SERVER['REQUEST_URI'] ) ) {
				continue;
			}
			$request = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
			if ( ! preg_match( '#^(.*?)/' . preg_quote( $base, '#' ) . '/([^/]+)/?$#', $path, $m ) ) {
				continue;
			}
			$code = wcusage_migration_resolve_identifier( $source, rawurldecode( $m[2] ) );
			if ( '' === $code ) {
				continue;
			}

			$query = (string) wp_parse_url( $request, PHP_URL_QUERY );
			$args  = array();
			if ( $query ) {
				wp_parse_str( $query, $args );
			}
			$args[ $prefix ] = $code;

			$target = home_url( ( $m[1] ? $m[1] : '' ) . '/' );
			// home_url() already carries any subdirectory the site lives in, and so
			// does the request path; drop the duplicate.
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '/' !== $home_path && $m[1] && 0 === strpos( $m[1] . '/', rtrim( $home_path, '/' ) . '/' ) ) {
				$target = home_url( substr( $m[1], strlen( rtrim( $home_path, '/' ) ) ) . '/' );
			}

			// Temporary, and not cached: which coupon an old link leads to can change
			// (undo, a coupon renamed or reassigned). urlencode_deep(), as the query
			// can hold arrays (?filter[]=...).
			nocache_headers();
			wp_safe_redirect( add_query_arg( urlencode_deep( $args ), $target ), 302 );
			exit;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	wcusage_migration_handle_legacy_cookies( $config );
}
add_action( 'plugins_loaded', 'wcusage_migration_handle_legacy_links', 0 );

/**
 * Credit a visitor who still carries the old plugin's referral cookie.
 *
 * Only while no Coupon Affiliates referral is set (that is the newer one),
 * only until the date chosen when migrating, and only once: the old cookie is
 * removed once it has been converted.
 *
 * @param array $config See wcusage_migration_legacy_links().
 */
function wcusage_migration_handle_legacy_cookies( $config ) {

	if ( ! wcusage_get_setting_value( 'wcusage_field_store_cookies', '1' ) ) {
		return;
	}
	if ( ! empty( $_COOKIE['wcusage_referral'] ) || ! empty( $_COOKIE['wcusage_referral_code'] ) ) {
		return;
	}

	foreach ( $config as $source => $links ) {

		$until = isset( $links['cookies_until'] ) ? (int) $links['cookies_until'] : 0;
		if ( ! $until || time() > $until ) {
			continue;
		}

		foreach ( (array) ( $links['cookies'] ?? array() ) as $cookie ) {
			if ( ! is_string( $cookie ) || empty( $_COOKIE[ $cookie ] ) || ! is_scalar( $_COOKIE[ $cookie ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
			// itthinx appends ".{campaign}" to the affiliate in its cookie.
			if ( false !== strpos( $value, '.' ) && ! empty( $links['cookie_strip_suffix'] ) ) {
				$value = strtok( $value, '.' );
			}

			$code = wcusage_migration_resolve_identifier( $source, $value );
			if ( '' === $code ) {
				continue;
			}

			$days   = (int) wcusage_get_setting_value( 'wcusage_urls_cookie_days', '30' );
			$expiry = time() + max( 1, $days ) * DAY_IN_SECONDS;

			// Not allowed to store the plugin's own cookie (no consent yet, say):
			// the old one is left as it is, to be converted once it is.
			if ( function_exists( 'wcusage_can_store_cookie' ) && ! wcusage_can_store_cookie( 'wcusage_referral', $code, $expiry ) ) {
				return;
			}
			if ( function_exists( 'wcusage_set_cookie' ) ) {
				wcusage_set_cookie( 'wcusage_referral', $code, $expiry );
			}
			$_COOKIE['wcusage_referral'] = $code;

			// Removed wherever the old plugin may have set it (some set it for the
			// whole domain), so it is not converted again.
			if ( ! headers_sent() ) {
				$paths   = array_unique( array_filter( array( '/', defined( 'COOKIEPATH' ) ? COOKIEPATH : '' ) ) );
				$domains = array_unique( array( '', defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '' ) );
				foreach ( $paths as $path ) {
					foreach ( $domains as $domain ) {
						setcookie( $cookie, '', time() - YEAR_IN_SECONDS, $path, $domain );
					}
				}
			}
			unset( $_COOKIE[ $cookie ] );

			return;
		}
	}
}

/**
 * Let referral links in an old plugin's format through canonical redirects,
 * as the plugin does for its own referral parameter.
 *
 * @param string|false $redirect_url
 * @return string|false
 */
function wcusage_migration_legacy_canonical( $redirect_url ) {
	if ( is_admin() || ! $redirect_url ) {
		return $redirect_url;
	}
	foreach ( wcusage_migration_legacy_links() as $links ) {
		foreach ( (array) ( $links['vars'] ?? array() ) as $var ) {
			if ( is_string( $var ) && '' !== $var && isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
		}
	}
	return $redirect_url;
}
add_filter( 'redirect_canonical', 'wcusage_migration_legacy_canonical', 9999 );
