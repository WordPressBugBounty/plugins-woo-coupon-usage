<?php
/**
 * Migration source: AffiliateWP (premium, with its add-ons).
 *
 * Checked against AffiliateWP 2.37.1's own code (includes/class-*-db.php and
 * the WooCommerce integration). The tables and columns read:
 *
 *   affiliate_wp_affiliates   affiliate_id, user_id, rate, rate_type, payment_email,
 *                             status (active|inactive|pending|rejected), earnings,
 *                             referrals, visits, date_registered (UTC);
 *                             2.x: unpaid_earnings, flat_rate_basis, rest_id
 *   affiliate_wp_affiliatemeta  meta_id, affiliate_id, meta_key, meta_value
 *                             (_rejection_reason, promotion_method, add-on keys)
 *   affiliate_wp_referrals    referral_id, affiliate_id, visit_id, description, status
 *                             (pending|unpaid|paid|rejected|draft|failed), amount,
 *                             currency, custom, context ('woocommerce'...), campaign,
 *                             reference (the order ID), products, date (UTC);
 *                             2.x: customer_id, parent_id, type, payout_id, rest_id
 *   affiliate_wp_visits       visit_id, affiliate_id, referral_id, url, referrer,
 *                             campaign, ip, date (UTC); 2.x: context, rest_id
 *   affiliate_wp_payouts      payout_id, affiliate_id, referrals (comma-separated
 *                             referral IDs), amount, owner, payout_method, status
 *                             (paid|failed|processing), date; 2.x: service_id
 *                             (bigint, 0 for none)
 *   affiliate_wp_creatives    creative_id, name, description, url, text, image,
 *                             status, date; 2.x: attachment_id, type
 *
 * Coupons are linked to an affiliate by the coupon's "affwp_discount_affiliate"
 * meta, which holds the AFFILIATE ID (not the user ID). Settings are the
 * "affwp_settings" option. AffiliateWP writes no order meta: referrals are tied
 * to orders only through "reference" + context "woocommerce".
 *
 * With AFFILIATE_WP_NETWORK_WIDE set, the tables have no site prefix at all and
 * are shared by every site in the network, so referrals, payouts and visits
 * cannot be told apart per site and are not imported.
 *
 * Dynamic coupons (2.6+, confirmed against AffiliateWP 2.37.1's own code) are
 * not WooCommerce coupons: the codes live only in affiliate_wp_coupons
 * (coupon_id, affiliate_id, coupon_code, type 'dynamic' - '' on sites that
 * skipped the 2.9 upgrade - locked), and AffiliateWP hands them to WooCommerce
 * at checkout (woocommerce_get_shop_coupon_data) with these settings of the
 * template coupon in the "coupon_template_woocommerce" setting: amount,
 * date_expires, discount_type, exclude_sale_items, excluded_product_categories,
 * excluded_product_ids, free_shipping, individual_use, product_ids. They stop
 * working once AffiliateWP is switched off, so each is created here as a real
 * coupon with the same code and settings. Without a published template
 * AffiliateWP hands WooCommerce nothing and the codes never worked, so they are
 * not migrated then.
 *
 * Also confirmed against 2.37.1: payout statuses and what they leave their
 * referrals as (class-payouts-db.php, payout-functions.php), the affiliate's
 * payout method (2.33+ affiliate meta, see affiliate_payout_method()) and the
 * labelled registration answers (class-register.php).
 *
 * Only what is confirmed is migrated. Storage that could only be inferred -
 * affiliate groups, Lifetime Commissions, multi-tier parents, custom slugs,
 * product and category rates - is not read, and is listed in
 * get_not_migrated() instead.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_AffiliateWP extends WCUsage_Migration_Source {

	/**
	 * Product meta of the WooCommerce integration: referrals switched off.
	 */
	const REFERRALS_DISABLED_META = '_affwp_woocommerce_referrals_disabled';

	/**
	 * Prefix the tables were created with: the site prefix, or '' for
	 * AffiliateWP's network-wide mode.
	 *
	 * @var string|null
	 */
	protected $base = null;

	/**
	 * Answers worked out once per request.
	 *
	 * @var array
	 */
	protected $cache = array();

	/* ---------------------------------------------------------------------
	 * Tables and settings
	 * ------------------------------------------------------------------ */

	/**
	 * An AffiliateWP table name.
	 *
	 * @param string $name Without "affiliate_wp_".
	 * @return string
	 */
	protected function t( $name ) {
		global $wpdb;
		if ( null === $this->base ) {
			$this->base = $wpdb->prefix;
			// Network-wide mode leaves the prefix off (class-affiliates-db.php).
			if ( ! $this->table_exists( $wpdb->prefix . 'affiliate_wp_affiliates' ) && $this->table_exists( 'affiliate_wp_affiliates' ) ) {
				$this->base = '';
			}
		}
		return $this->base . 'affiliate_wp_' . $name;
	}

	/**
	 * Whether the data is in network-wide (unprefixed) tables.
	 *
	 * @return bool
	 */
	protected function is_network_wide() {
		$this->t( 'affiliates' );
		return '' === $this->base;
	}

	/**
	 * @return array
	 */
	protected function settings_option() {
		$settings = get_option( 'affwp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * One AffiliateWP setting.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function setting( $key, $default = null ) {
		$s = $this->settings_option();
		return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
	}

	/**
	 * The first of a list of columns a table has.
	 *
	 * @param string   $table
	 * @param string[] $candidates
	 * @return string '' when it has none of them.
	 */
	protected function column( $table, $candidates ) {
		foreach ( $candidates as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				return $column;
			}
		}
		return '';
	}

	/**
	 * Run a query that returns one value, once per request.
	 *
	 * @param string $key
	 * @param string $sql
	 * @return mixed
	 */
	protected function cached_var( $key, $sql ) {
		global $wpdb;
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$suppress            = $wpdb->suppress_errors( true );
			$this->cache[ $key ] = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->suppress_errors( $suppress );
		}
		return $this->cache[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * What the source can provide
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {

		if ( isset( $this->cache['supported'] ) ) {
			return $this->cache['supported'];
		}

		// Every AffiliateWP affiliate is a WordPress user, so no "users_optional".
		// Coupon links are coupon meta, always read.
		$supported = array( 'affiliates', 'legacy_links', 'registrations', 'rates', 'payout_details', 'coupons' );

		// Network-wide tables hold every site's referrals, payouts and visits,
		// with nothing to tell which site each one was for.
		$tables = array( 'creatives' => 'creatives' );
		if ( ! $this->is_network_wide() ) {
			$tables = array(
				'referrals' => 'referrals',
				'payouts'   => 'payouts',
				'visits'    => 'visits',
			) + $tables;
		}
		foreach ( $tables as $kind => $table ) {
			if ( $this->table_exists( $this->t( $table ) ) ) {
				$supported[] = $kind;
			}
		}

		if ( $this->settings_option() ) {
			$supported[] = 'settings';
		}
		if ( $this->has_excluded_products() ) {
			$supported[] = 'product_rates';
		}
		if ( in_array( 'visits', $supported, true ) && $this->has_column( $this->t( 'visits' ), 'campaign' ) && $this->cached_var( 'campaigns', 'SELECT 1 FROM `' . $this->t( 'visits' ) . "` WHERE campaign <> '' LIMIT 1" ) ) {
			$supported[] = 'campaigns';
		}
		if ( $this->has_store_credit() ) {
			$supported[] = 'store_credit';
		}
		if ( in_array( 'referrals', $supported, true ) && $this->has_column( $this->t( 'referrals' ), 'parent_id' ) ) {
			$supported[] = 'tiers';
		}

		$this->cache['supported'] = $supported;

		return $supported;
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		global $wpdb;

		$warnings = array();

		// Without a published template AffiliateWP gave WooCommerce no coupon for
		// a dynamic code (maybe_inject_dynamic_coupon()), so none of them worked.
		if ( ! $this->get_virtual_coupon_template() ) {
			$dynamic = $this->count_codes( $this->dynamic_coupons() );
			if ( $dynamic ) {
				/* translators: %d: number of coupon codes */
				$warnings[] = sprintf( _n( 'AffiliateWP\'s dynamic coupons have no published coupon template, so its %d dynamic coupon code did not work as a coupon in AffiliateWP. It is not migrated.', 'AffiliateWP\'s dynamic coupons have no published coupon template, so its %d dynamic coupon codes did not work as coupons in AffiliateWP. They are not migrated.', $dynamic, 'woo-coupon-usage' ), $dynamic );
			}
		}

		if ( $this->setting( 'uninstall_on_delete' ) ) {
			$warnings[] = __( 'AffiliateWP is set to delete all of its data when the plugin is deleted ("Remove Data on Uninstall"). Keep AffiliateWP installed (deactivated is fine) until you have checked the migrated data.', 'woo-coupon-usage' );
		}

		if ( $this->is_network_wide() ) {
			$warnings[] = __( 'AffiliateWP keeps its data network-wide here, so its tables hold every site\'s referrals, payouts and visits, with nothing to tell which site each one was for. Only affiliates, their coupons and creatives are migrated: referrals, commission balances, payouts and visits are not.', 'woo-coupon-usage' );
			return $warnings;
		}

		// Payouts whose referrals AffiliateWP still has as unpaid.
		$open = $this->open_payouts();
		if ( $open['processing'][0] ) {
			$warnings[] = sprintf(
				/* translators: 1: number of payouts, 2: their total */
				_n( '%1$d AffiliateWP payout (%2$s in total) is still processing: it is imported as a pending payout and its commission as paid, so it is not paid out twice. Mark the payout paid here once it has gone through.', '%1$d AffiliateWP payouts (%2$s in total) are still processing: they are imported as pending payouts and their commission as paid, so it is not paid out twice. Mark the payouts paid here once they have gone through.', $open['processing'][0], 'woo-coupon-usage' ),
				$open['processing'][0],
				wc_format_decimal( $open['processing'][1], 2 )
			);
		}
		if ( $open['failed'][0] ) {
			$warnings[] = sprintf(
				/* translators: 1: number of payouts, 2: their total */
				_n( '%1$d AffiliateWP payout (%2$s in total) failed and was not retried: its commission is imported as unpaid, to be paid from Coupon Affiliates. Do not also retry it in AffiliateWP.', '%1$d AffiliateWP payouts (%2$s in total) failed and were not retried: their commission is imported as unpaid, to be paid from Coupon Affiliates. Do not also retry them in AffiliateWP.', $open['failed'][0], 'woo-coupon-usage' ),
				$open['failed'][0],
				wc_format_decimal( $open['failed'][1], 2 )
			);
		}

		// AffiliateWP labels each referral with its own currency setting (USD
		// unless changed, affwp_get_currency()) but works the amount out from the
		// order, in the order's currency. So only a referral labelled with
		// neither that setting nor the store's currency was recorded in another
		// currency (by a multi-currency integration).
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$own      = strtoupper( (string) $this->setting( 'currency', 'USD' ) );
		if ( $currency && $own !== strtoupper( $currency ) ) {
			/* translators: 1: AffiliateWP's currency setting, 2: store currency */
			$warnings[] = sprintf( __( 'AffiliateWP\'s currency setting is %1$s but the store uses %2$s. AffiliateWP only labels its amounts with that setting - it works them out from the orders themselves - so they are imported as they are, as %2$s.', 'woo-coupon-usage' ), $own, $currency );
		}
		if ( $currency && $this->has_column( $this->t( 'referrals' ), 'currency' ) ) {
			$other = (int) $this->cached_var( 'other_currency', $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $this->t( 'referrals' ) . "` WHERE currency <> '' AND currency <> %s AND currency <> %s", $currency, $own ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $other ) {
				/* translators: 1: number of referrals, 2: currency code */
				$warnings[] = sprintf( _n( '%1$d AffiliateWP referral is recorded in a currency other than the store\'s (%2$s). Its amount is imported as it is.', '%1$d AffiliateWP referrals are recorded in a currency other than the store\'s (%2$s). Their amounts are imported as they are.', $other, 'woo-coupon-usage' ), $other, $currency );
			}
		}

		return $warnings;
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {

		$lines = array();

		if ( $this->is_network_wide() ) {
			$lines[] = __( 'Referrals, commission balances, payouts and visits: AffiliateWP keeps them network-wide here, so they cannot be told apart per site', 'woo-coupon-usage' );
		}

		if ( $this->global_rate_is_per_product() ) {
			$lines[] = __( 'The default flat rate per product (AffiliateWP paid it per order line, Coupon Affiliates pays it per unit)', 'woo-coupon-usage' );
		}
		$per_product = $this->affiliates_with_per_product_rate();
		if ( $per_product ) {
			/* translators: %d: number of affiliates */
			$lines[] = sprintf( _n( '%d affiliate\'s own flat rate per product (paid per order line in AffiliateWP, per unit here): they earn the default rate', '%d affiliates\' own flat rates per product (paid per order line in AffiliateWP, per unit here): they earn the default rate', $per_product, 'woo-coupon-usage' ), $per_product );
		}

		$lines[] = __( 'Multi-tier commissions and affiliate parents (past tier commission is still added to the earning affiliate\'s balance)', 'woo-coupon-usage' );
		$lines[] = __( 'Lifetime customer links', 'woo-coupon-usage' );

		// The groups table also holds creative categories.
		$groups = $this->t( 'groups' );
		if ( $this->count_rows( $groups, $this->has_column( $groups, 'type' ) ? "type LIKE '%affiliate%'" : '' ) ) {
			$lines[] = __( 'Affiliate groups and their rates', 'woo-coupon-usage' );
		}

		$lines[] = __( 'Commission rates set on products and product categories', 'woo-coupon-usage' );
		$lines[] = __( 'Custom affiliate slugs: old referral links with the affiliate ID or username still work', 'woo-coupon-usage' );

		$lines[] = __( 'Add-on rates: per-affiliate product rates, recurring rates and tiered rates', 'woo-coupon-usage' );
		$lines[] = __( 'Affiliate landing pages', 'woo-coupon-usage' );
		$lines[] = __( 'Program settings other than the default rate, cookie length and referrer credit', 'woo-coupon-usage' );
		$lines[] = __( 'Stripe and Payouts Service accounts affiliates connected (their payout method is migrated, but they connect again here)', 'woo-coupon-usage' );

		return $lines;
	}

	/**
	 * @return array
	 */
	public function get_counts() {

		// Dynamic codes only count when they worked (see get_warnings()).
		$dynamic = $this->get_virtual_coupon_template() ? $this->count_codes( $this->dynamic_coupons() ) : 0;
		$coupons = array();
		foreach ( $this->coupon_links() as $coupon_ids ) {
			$coupons = array_merge( $coupons, $coupon_ids );
		}

		$counts = array( 'affiliates' => $this->count_rows( $this->t( 'affiliates' ) ) );
		if ( ! $this->is_network_wide() ) {
			$counts['referrals'] = $this->count_rows( $this->t( 'referrals' ) );
			// What get_payouts() can import: failed payouts never are.
			$counts['payouts'] = $this->count_rows( $this->t( 'payouts' ), "status IN ('paid','processing')" );
			$counts['visits']  = $this->count_rows( $this->t( 'visits' ) );
		}
		$counts['creatives'] = $this->count_rows( $this->t( 'creatives' ) );
		$counts['coupons']   = count( array_unique( $coupons ) ) + $dynamic;

		return $counts;
	}

	/**
	 * @return array
	 */
	public function get_settings() {

		$s = $this->settings_option();
		if ( ! $s ) {
			return array();
		}

		$out = array();

		// AffiliateWP's own default rate when it was never saved. A flat rate
		// per product is not carried over (see rate_type()).
		$rate_type = $this->rate_type( $this->setting( 'referral_rate_type', 'percentage' ), $this->global_basis() );
		if ( null !== $rate_type ) {
			$out['rate']      = $this->global_rate();
			$out['rate_type'] = $rate_type;
		}

		if ( isset( $s['cookie_exp'] ) && is_numeric( $s['cookie_exp'] ) && (int) $s['cookie_exp'] > 0 ) {
			$out['cookie_days'] = (int) $s['cookie_exp'];
		} else {
			// 1 day until 2.9, 30 since.
			$out['cookie_days'] = version_compare( (string) get_option( 'affwp_version', '0' ), '2.9', '>=' ) ? 30 : 1;
		}

		// "Credit Last Referrer" off (the default) means the first referrer keeps the credit.
		$out['credit_first'] = empty( $s['referral_credit_last'] );

		return $out;
	}

	/**
	 * The global rate: 20 when it was never saved, as in affwp_get_affiliate_rate().
	 *
	 * @return float
	 */
	protected function global_rate() {
		$rate = $this->setting( 'referral_rate' );
		return is_numeric( $rate ) ? (float) $rate : 20.0;
	}

	/**
	 * The global flat rate basis.
	 *
	 * @return string
	 */
	protected function global_basis() {
		return (string) $this->setting( 'flat_rate_basis', 'per_product' );
	}

	/**
	 * A rate type as AffiliateWP reads it: anything but "flat" is a percentage
	 * (affwp_get_affiliate_rate_type()).
	 *
	 * @param string $type
	 * @return string 'flat' | 'percentage'
	 */
	protected function normal_type( $type ) {
		return 'flat' === strtolower( trim( (string) $type ) ) ? 'flat' : 'percentage';
	}

	/**
	 * Whether the global rate is a flat amount per product, which is not
	 * carried over (see rate_type()).
	 *
	 * @return bool
	 */
	protected function global_rate_is_per_product() {
		return null === $this->rate_type( $this->setting( 'referral_rate_type', 'percentage' ), $this->global_basis() );
	}

	/**
	 * How many affiliates have a flat rate per product of their own, which is
	 * not carried over: their type (the global one when they have none) and
	 * their basis (the global one when they have none). A flat type of their
	 * own counts without a rate too, as it then pays the global rate flat
	 * (see get_affiliates()).
	 *
	 * @return int
	 */
	protected function affiliates_with_per_product_rate() {

		if ( isset( $this->cache['per_product_affiliates'] ) ) {
			return $this->cache['per_product_affiliates'];
		}

		$table              = $this->t( 'affiliates' );
		$global_flat        = 'flat' === $this->normal_type( $this->setting( 'referral_rate_type', 'percentage' ) );
		$global_per_product = false === strpos( strtolower( $this->global_basis() ), 'order' );
		$has_basis          = $this->has_column( $table, 'flat_rate_basis' );
		$count              = 0;

		if ( ( $has_basis || $global_per_product ) && $this->has_column( $table, 'rate' ) && $this->has_column( $table, 'rate_type' ) ) {
			$where = $global_flat ? "TRIM(rate) <> '' AND rate_type IN ('flat','')" : "rate_type = 'flat'";
			if ( $has_basis ) {
				$where .= $global_per_product ? " AND flat_rate_basis NOT LIKE '%order%'" : " AND flat_rate_basis <> '' AND flat_rate_basis NOT LIKE '%order%'";
			}
			$count = $this->count_rows( $table, $where );
		}

		$this->cache['per_product_affiliates'] = $count;

		return $count;
	}

	/**
	 * AffiliateWP's rate type and flat rate basis, in the common form.
	 *
	 * A flat rate per product is not carried over: AffiliateWP (1.7.14) pays it
	 * once for each product in the order, whatever the quantity, while Coupon
	 * Affiliates pays a fixed amount per product for every unit.
	 *
	 * @param string $type  'percentage' | 'flat'
	 * @param string $basis 'per_product' | 'per_order'
	 * @return string|null 'percent' | 'flat_order', null for a flat rate per product.
	 */
	protected function rate_type( $type, $basis ) {
		if ( 'flat' === strtolower( (string) $type ) ) {
			return false !== strpos( strtolower( (string) $basis ), 'order' ) ? 'flat_order' : null;
		}
		return 'percent';
	}

	/**
	 * Referral links: "?ref=12", "?ref=jane", "/ref/jane/" (the rewrite endpoint
	 * is registered whether or not "Pretty Affiliate URLs" is on, see
	 * class-rewrites.php), and the "affwp_ref" cookie holding the affiliate ID -
	 * "wp-affwp_ref" in cookie compat mode (on Pantheon, or switched on by a
	 * filter, class-tracking.php).
	 *
	 * @return array
	 */
	public function get_legacy_links() {
		// The settings screen saves the variable through sanitize_key(), but
		// AffiliateWP reads $_GET[ variable ] as saved, case and all: keep its
		// case, only dropping characters a parameter name cannot carry.
		$var = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $this->setting( 'referral_var', 'ref' ) );
		$var = '' === $var ? 'ref' : $var;
		return array(
			'vars'     => array( $var ),
			'paths'    => array( $var ),
			'campaign' => 'campaign',
			'cookies'  => array( 'affwp_ref', 'wp-affwp_ref' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Affiliates
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$table = $this->t( 'affiliates' );
		$rows  = $this->batch( $table, 'affiliate_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$ids      = wp_list_pluck( $rows, 'affiliate_id' );
		$user_ids = wp_list_pluck( $rows, 'user_id' );
		$meta     = $this->meta_for( $this->t( 'affiliatemeta' ), 'affiliate_id', $ids );
		$users    = $this->users_for( $user_ids );
		$umeta    = $this->user_meta_for( $user_ids, array( 'first_name', 'last_name', 'affwp_promotion_method', 'affwp_wc_credit_balance' ) );
		$coupons  = $this->coupon_links();
		// Dynamic codes never worked without a template (see get_warnings()).
		$dynamic  = $this->get_virtual_coupon_template() ? $this->dynamic_coupons( wp_list_pluck( $rows, 'affiliate_id' ) ) : array();

		$global_type  = (string) $this->setting( 'referral_rate_type', 'percentage' );
		$global_basis = $this->global_basis();
		$has_basis    = $this->has_column( $table, 'flat_rate_basis' );

		$records = array();
		foreach ( $rows as $row ) {

			$id    = (int) $row['affiliate_id'];
			$m     = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$user  = isset( $users[ (int) $row['user_id'] ] ) ? $users[ (int) $row['user_id'] ] : array();
			$um    = isset( $umeta[ (int) $row['user_id'] ] ) ? $umeta[ (int) $row['user_id'] ] : array();

			// Rates: an empty rate means "use the global rate", as in
			// affwp_get_affiliate_rate(), and an empty type or basis the global
			// one. Their own type counts even without a rate of their own
			// (affwp_get_affiliate_rate_type()): the global rate is then paid
			// their way. A flat rate per product is not carried over
			// (rate_type()): the default applies.
			$rate_raw  = trim( (string) $row['rate'] );
			$type_raw  = trim( (string) $row['rate_type'] );
			$basis_raw = $has_basis ? trim( (string) $row['flat_rate_basis'] ) : '';
			$basis     = '' !== $basis_raw ? $basis_raw : $global_basis;
			$rate      = null;
			$rate_type = null;
			if ( '' !== $rate_raw && is_numeric( $rate_raw ) ) {
				$rate_type = $this->rate_type( '' !== $type_raw ? $type_raw : $global_type, $basis );
				$rate      = null === $rate_type ? null : (float) $rate_raw;
			} elseif ( '' === $rate_raw && '' !== $type_raw && $this->normal_type( $type_raw ) !== $this->normal_type( $global_type ) ) {
				$rate_type = $this->rate_type( $type_raw, $basis );
				$rate      = null === $rate_type ? null : $this->global_rate();
			}

			// AffiliateWP reads a number in a referral link as an affiliate ID
			// before anything else (class-tracking.php), so a numeric username
			// never identified anyone: it is left out, and the ID stands.
			$login       = isset( $user['user_login'] ) ? (string) $user['user_login'] : '';
			$identifiers = '' !== $login && ! is_numeric( $login ) ? array( $login, (string) $id ) : array( (string) $id );

			$status = in_array( $row['status'], array( 'active', 'pending', 'rejected' ), true ) ? $row['status'] : 'inactive';

			// 2.8 moved the registration answers from user meta into affiliate meta.
			$promote = isset( $m['promotion_method'] ) && is_scalar( $m['promotion_method'] ) ? (string) $m['promotion_method'] : '';
			if ( '' === $promote && isset( $um['affwp_promotion_method'] ) && is_scalar( $um['affwp_promotion_method'] ) ) {
				$promote = (string) $um['affwp_promotion_method'];
			}
			$website = '';
			foreach ( array( 'website_url', 'website', 'user_url' ) as $key ) {
				if ( ! empty( $m[ $key ] ) && is_string( $m[ $key ] ) ) {
					$website = $m[ $key ];
					break;
				}
			}
			if ( '' === $website && ! empty( $user['user_url'] ) ) {
				$website = (string) $user['user_url'];
			}

			$reject = '';
			foreach ( array( '_rejection_reason', 'rejection_reason' ) as $key ) {
				if ( ! empty( $m[ $key ] ) && is_scalar( $m[ $key ] ) ) {
					$reject = (string) $m[ $key ];
					break;
				}
			}

			$credit = isset( $um['affwp_wc_credit_balance'] ) && is_numeric( $um['affwp_wc_credit_balance'] ) && (float) $um['affwp_wc_credit_balance'] > 0 ? $this->money( $um['affwp_wc_credit_balance'] ) : null;

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => (int) $row['user_id'],
					'email'         => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => isset( $um['first_name'] ) && is_scalar( $um['first_name'] ) ? (string) $um['first_name'] : '',
					'last_name'     => isset( $um['last_name'] ) && is_scalar( $um['last_name'] ) ? (string) $um['last_name'] : '',
					'status'        => $status,
					// Written with gmdate(), or the date given less the site's
					// offset (class-affiliates-db.php): UTC.
					'date'          => $this->date( $row['date_registered'], true ),
					'rate'          => $rate,
					'rate_type'     => $rate_type,
					'payment_email' => (string) $row['payment_email'],
					'payout_method' => $this->affiliate_payout_method( $m ),
					'website'       => $website,
					'promote'       => $promote,
					'reject_reason' => $reject,
					'fields'        => $this->registration_fields( $m ),
					'coupons'       => isset( $coupons[ (string) $id ] ) ? $coupons[ (string) $id ] : array(),
					'virtual_coupons' => isset( $dynamic[ (int) $id ] ) ? $dynamic[ (int) $id ] : array(),
					'identifiers'   => $identifiers,
					'link_id'       => (string) $id,
					'store_credit'  => $credit,
				)
			);
		}

		return $records;
	}

	/**
	 * The affiliate's answers to the registration form's custom fields.
	 *
	 * The block registration form (2.13+, class-register.php) saves each answer
	 * as affiliate meta and lists them, with their labels, in
	 * "_submitted_custom_registration_fields" (meta_key, name, type), as
	 * affwp_get_custom_registration_fields() reads them. For an affiliate
	 * without that list (an older or a legacy form), any plain affiliate meta
	 * that is not one of AffiliateWP's own settings is kept instead.
	 *
	 * @param array $m Affiliate meta.
	 * @return array label => value
	 */
	protected function registration_fields( $m ) {
		$fields = array();

		if ( ! empty( $m['_submitted_custom_registration_fields'] ) && is_array( $m['_submitted_custom_registration_fields'] ) ) {
			foreach ( $m['_submitted_custom_registration_fields'] as $field ) {
				if ( ! is_array( $field ) || empty( $field['meta_key'] ) || ! is_string( $field['meta_key'] ) || ! isset( $m[ $field['meta_key'] ] ) ) {
					continue;
				}
				$value = $m[ $field['meta_key'] ];
				if ( is_array( $value ) ) {
					$value = array_filter( $value, 'is_scalar' );
					if ( ! $value ) {
						continue;
					}
				} elseif ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					continue;
				}
				$label = ! empty( $field['name'] ) && is_scalar( $field['name'] ) ? trim( wp_strip_all_tags( (string) $field['name'] ) ) : '';
				$fields[ '' !== $label ? $label : ucwords( str_replace( array( '_', '-' ), ' ', $field['meta_key'] ) ) ] = $value;
			}
			return $fields;
		}

		foreach ( $m as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_' ) || in_array( $key, array( 'notes', 'promotion_method', 'website_url', 'website', 'user_url', 'rejection_reason' ), true ) ) {
				continue;
			}
			// Settings the plugin and its add-ons keep per affiliate (custom
			// slugs and multi-tier parents among them).
			if ( preg_match( '/^affwp_|lifetime|^lc_|rate|payout|paypal|stripe|credit|coupon|bonus|fraud|flag|velocity|review|summar|notif|^qr|landing|direct_link|referral|status|slug|parent|_id$/i', $key ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = array_filter( $value, 'is_scalar' );
				if ( ! $value ) {
					continue;
				}
			} elseif ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$fields[ ucwords( str_replace( array( '_', '-' ), ' ', $key ) ) ] = $value;
		}
		return $fields;
	}

	/**
	 * The affiliate's payout method, resolved as AffiliateWP 2.33+ does
	 * (affwp_get_affiliate_payout_method()): the admin's override, then the
	 * affiliate's own pick (affiliate meta "payout_method_override" and
	 * "payout_method"), then the "default_payout_method" setting.
	 *
	 * 2.33 moved the Store Credit add-on's switches into those (class-upgrades.php)
	 * and stopped reading them, though it leaves them in place. So they only
	 * count while that move has not been done: "Enable For All Affiliates?"
	 * (store-credit-all-affiliates) beating the per-affiliate switch, as in the
	 * add-on's get_payout_method().
	 *
	 * @param array $m Affiliate meta.
	 * @return string
	 */
	protected function affiliate_payout_method( $m ) {
		foreach ( array( 'payout_method_override', 'payout_method' ) as $key ) {
			if ( ! empty( $m[ $key ] ) && is_string( $m[ $key ] ) ) {
				return $this->payout_method( $m[ $key ] );
			}
		}

		if ( ! get_option( 'affwp_sc_all_affiliates_migrated' ) && $this->setting( 'store-credit' ) && $this->setting( 'store-credit-all-affiliates' ) ) {
			return 'credit';
		}
		if ( ! get_option( 'affwp_payout_method_migrated' ) && ! empty( $m['store_credit_enabled'] ) ) {
			return 'credit';
		}

		// The site default. Store credit is only a method while it is switched
		// on (AffiliateWP falls back to Manual), and Manual - the admin paying
		// outside AffiliateWP - says nothing about how.
		$default = strtolower( (string) $this->setting( 'default_payout_method', 'manual' ) );
		if ( 'manual' === $default || ( 'store_credit' === $default && ! $this->setting( 'store-credit' ) ) ) {
			return '';
		}
		return $this->payout_method( $default );
	}

	/**
	 * A payout method slug in the common form.
	 *
	 * @param mixed $method
	 * @return string
	 */
	protected function payout_method( $method ) {
		$method = is_string( $method ) ? strtolower( $method ) : '';
		if ( '' === $method ) {
			return '';
		}
		foreach ( array(
			'paypal' => 'paypal',
			'stripe' => 'stripe',
			'credit' => 'credit',
			'bank'   => 'bank',
			// The Payouts Service and Global Payouts both pay into bank accounts.
			'service' => 'bank',
			'global'  => 'bank',
			'wire'    => 'bank',
			'check'   => 'check',
			'cheque'  => 'check',
		) as $needle => $common ) {
			if ( false !== strpos( $method, $needle ) ) {
				return $common;
			}
		}
		return 'manual';
	}

	/**
	 * Each affiliate's dynamic coupon codes (affiliate_wp_coupons).
	 *
	 * @param int[]|null $affiliate_ids Null for every affiliate.
	 * @return array affiliate ID => codes
	 */
	protected function dynamic_coupons( $affiliate_ids = null ) {
		$table = $this->t( 'coupons' );
		if ( ! $this->table_exists( $table ) || ! $this->has_column( $table, 'coupon_code' ) ) {
			return array();
		}
		$where = array( "coupon_code <> ''" );
		// Before 2.8 the table held nothing but dynamic coupons, and had no type;
		// rows from before the 2.9 upgrade that set it can still have none.
		if ( $this->has_column( $table, 'type' ) ) {
			$where[] = "type IN ('dynamic','')";
		}
		if ( null !== $affiliate_ids ) {
			$ids = array_filter( array_map( 'intval', (array) $affiliate_ids ) );
			if ( ! $ids ) {
				return array();
			}
			$where[] = 'affiliate_id IN (' . implode( ',', $ids ) . ')';
		}
		$codes = array();
		foreach ( $this->rows( "SELECT affiliate_id, coupon_code FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY coupon_id ASC' ) as $row ) {
			$codes[ (int) $row['affiliate_id'] ][] = (string) $row['coupon_code'];
		}
		return $codes;
	}

	/**
	 * Every dynamic coupon code, while they work as coupons (see
	 * get_virtual_coupon_template()).
	 *
	 * @return string[]
	 */
	public function get_virtual_coupon_codes() {
		if ( ! $this->get_virtual_coupon_template() ) {
			return array();
		}
		$codes = array();
		foreach ( $this->dynamic_coupons() as $list ) {
			$codes = array_merge( $codes, $list );
		}
		return $codes;
	}

	/**
	 * How many codes a list of dynamic_coupons() holds.
	 *
	 * @param array $codes affiliate ID => codes
	 * @return int
	 */
	protected function count_codes( $codes ) {
		$total = 0;
		foreach ( $codes as $list ) {
			$total += count( $list );
		}
		return $total;
	}

	/**
	 * The template coupon AffiliateWP's dynamic coupons take their settings
	 * from, and the settings it takes (its WooCommerce integration's
	 * maybe_inject_dynamic_coupon()). AffiliateWP only uses a published
	 * template; without one it hands WooCommerce no coupon at all for a
	 * dynamic code, so the codes did not work and are not migrated.
	 *
	 * @return array|null
	 */
	public function get_virtual_coupon_template() {
		$settings    = $this->settings_option();
		$template_id = isset( $settings['coupon_template_woocommerce'] ) ? (int) $settings['coupon_template_woocommerce'] : 0;
		if ( ! $template_id || 'shop_coupon' !== get_post_type( $template_id ) || 'publish' !== get_post_status( $template_id ) ) {
			return null;
		}
		return array(
			'id'     => $template_id,
			'fields' => array( 'amount', 'date_expires', 'discount_type', 'exclude_sale_items', 'excluded_product_categories', 'excluded_product_ids', 'free_shipping', 'individual_use', 'product_ids' ),
		);
	}

	/**
	 * Every affiliate's coupons, from the coupon meta AffiliateWP links them
	 * with ("affwp_coupon_affiliate" is an older key; AffiliateWP still reads
	 * both). Dynamic coupons are read by dynamic_coupons().
	 *
	 * @return array affiliate ID => coupon IDs
	 */
	protected function coupon_links() {
		if ( ! isset( $this->cache['coupons'] ) ) {
			$this->cache['coupons'] = $this->coupons_by_meta( array( 'affwp_discount_affiliate', 'affwp_coupon_affiliate' ) );
		}
		return $this->cache['coupons'];
	}

	/* ---------------------------------------------------------------------
	 * Referrals and payouts
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a referral is a WooCommerce order's, and the order ID.
	 *
	 * @param array $row
	 * @return int 0 when it is not.
	 */
	protected function order_id_of( $row ) {
		$reference = trim( (string) $row['reference'] );
		return 'woocommerce' === strtolower( trim( (string) $row['context'] ) ) && preg_match( '/^[0-9]+$/', $reference ) ? (int) $reference : 0;
	}

	/**
	 * AffiliateWP's referral status in the common form.
	 *
	 * @param string $status
	 * @return string
	 */
	protected function referral_status( $status ) {
		switch ( $status ) {
			case 'paid':
			case 'unpaid':
			case 'pending':
				return $status;
			default:
				// rejected, draft (2.8) and failed (2.8.1) earn nothing.
				return 'rejected';
		}
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->t( 'referrals' );
		$rows  = $this->batch( $table, 'referral_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$has_payout = $this->has_column( $table, 'payout_id' );
		$parents    = $this->has_column( $table, 'parent_id' ) ? $this->parent_referrals( $table, wp_list_pluck( $rows, 'parent_id' ) ) : array();
		$statuses   = $has_payout ? $this->payout_statuses( wp_list_pluck( $rows, 'payout_id' ) ) : array();

		$records = array();
		foreach ( $rows as $row ) {

			$status = $this->referral_status( $row['status'] );
			$payout = $has_payout ? (int) $row['payout_id'] : 0;
			// parent_id is 2.x only (see the header).
			$parent = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;

			// A payout still processing (PayPal or Stripe confirming it) leaves
			// its referrals unpaid until the money arrives, and AffiliateWP pays
			// no referral with a payout again (class-payouts-db.php,
			// affwp_compute_eligible_payouts()). It is a pending payout here, so
			// its commission is no longer owed. A failed payout's referrals are
			// still owed: the money never went out.
			if ( 'unpaid' === $status && $payout && isset( $statuses[ $payout ] ) && 'processing' === $statuses[ $payout ] ) {
				$status = 'paid';
			}

			$records[] = $this->referral_record(
				array(
					'source_id'           => (int) $row['referral_id'],
					'affiliate_source_id' => (int) $row['affiliate_id'],
					// Anything else (another integration, a manual referral, an
					// opt-in or lead) is carried as a balance by the runner.
					'order_id'            => $this->order_id_of( $row ),
					'amount'              => $row['amount'],
					'status'              => $status,
					// Written with date( 'Y-m-d H:i:s' ), which WordPress runs in UTC.
					'date'                => $this->date( $row['date'], true ),
					'payout_source_id'    => $payout && 'paid' === $status ? (string) $payout : '',
					// A multi-tier commission ("Parent referral ID for multi-tier
					// commissions, otherwise 0"): another affiliate's referral for the
					// same sale. A renewal's parent is the same affiliate's first order.
					'is_tier'             => isset( $parents[ $parent ] ) && $parents[ $parent ]['affiliate_id'] !== (int) $row['affiliate_id'] && $parents[ $parent ]['reference'] === (string) $row['reference'],
				)
			);
		}

		return $records;
	}

	/**
	 * The affiliate and order of each of a batch of parent referrals.
	 *
	 * @param string $table
	 * @param array  $referral_ids
	 * @return array referral ID => array( affiliate_id, reference )
	 */
	protected function parent_referrals( $table, $referral_ids ) {
		$referral_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $referral_ids ) ) ) );
		if ( ! $referral_ids ) {
			return array();
		}
		$parents = array();
		foreach ( $this->rows( "SELECT referral_id, affiliate_id, reference FROM `{$table}` WHERE referral_id IN (" . implode( ',', $referral_ids ) . ')' ) as $row ) {
			$parents[ (int) $row['referral_id'] ] = array(
				'affiliate_id' => (int) $row['affiliate_id'],
				'reference'    => (string) $row['reference'],
			);
		}
		return $parents;
	}

	/**
	 * The status of each of a batch of payouts.
	 *
	 * @param array $payout_ids
	 * @return array payout ID => status
	 */
	protected function payout_statuses( $payout_ids ) {
		$payout_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $payout_ids ) ) ) );
		$table      = $this->t( 'payouts' );
		if ( ! $payout_ids || ! $this->table_exists( $table ) ) {
			return array();
		}
		$statuses = array();
		foreach ( $this->rows( "SELECT payout_id, status FROM `{$table}` WHERE payout_id IN (" . implode( ',', $payout_ids ) . ')' ) as $row ) {
			$statuses[ (int) $row['payout_id'] ] = (string) $row['status'];
		}
		return $statuses;
	}

	/**
	 * Processing and failed payouts that still hold unpaid referrals (pointing
	 * back at them), for the warnings.
	 *
	 * @return array status => array( number of payouts, their total )
	 */
	protected function open_payouts() {

		if ( isset( $this->cache['open_payouts'] ) ) {
			return $this->cache['open_payouts'];
		}

		$open = array(
			'processing' => array( 0, 0.0 ),
			'failed'     => array( 0, 0.0 ),
		);

		$table     = $this->t( 'payouts' );
		$referrals = $this->t( 'referrals' );
		if ( $this->table_exists( $table ) && $this->has_column( $referrals, 'payout_id' ) ) {
			$payouts = array();
			foreach ( $this->rows( "SELECT payout_id, status, amount FROM `{$table}` WHERE status IN ('processing','failed')" ) as $row ) {
				$payouts[ (int) $row['payout_id'] ] = $row;
			}
			foreach ( array_chunk( array_keys( $payouts ), 500 ) as $chunk ) {
				foreach ( $this->rows( "SELECT DISTINCT payout_id FROM `{$referrals}` WHERE status = 'unpaid' AND payout_id IN (" . implode( ',', $chunk ) . ')' ) as $row ) {
					$payout = $payouts[ (int) $row['payout_id'] ];
					$open[ $payout['status'] ][0]++;
					$open[ $payout['status'] ][1] += $this->money( $payout['amount'] );
				}
			}
		}

		$this->cache['open_payouts'] = $open;

		return $open;
	}

	/**
	 * Payouts.
	 *
	 * AffiliateWP marks a paid payout's referrals paid, and imported "paid"
	 * referrals are counted as paid already. So a paid payout is only imported
	 * for what its referrals show as paid: one whose referrals are still unpaid
	 * is skipped, its commission migrated as unpaid, to be paid here. A
	 * processing payout is imported as pending, with its referrals that are
	 * paid or still on their way to the affiliate (unpaid, pointing back at it:
	 * get_referrals() imports those as paid). A failed payout is skipped: its
	 * referrals stay unpaid in AffiliateWP until it is retried, so they are
	 * migrated as unpaid commission.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$table = $this->t( 'payouts' );
		$rows  = $this->batch( $table, 'payout_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$referrals_table = $this->t( 'referrals' );
		// The Payouts Service and Stripe record their own reference (service_id;
		// the others are older names, just in case).
		$transaction = $this->column( $table, array( 'service_id', 'transaction_id', 'service_reference' ) );

		// The referrals each payout paid: its comma-separated list, plus any
		// referral pointing back at it (2.x "payout_id").
		$paid_by = array();
		$all_ids = array();
		foreach ( $rows as $row ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $row['referrals'] ) ) );
			$paid_by[ (int) $row['payout_id'] ] = $ids;
			$all_ids                            = array_merge( $all_ids, $ids );
		}
		if ( $this->has_column( $referrals_table, 'payout_id' ) ) {
			foreach ( $this->rows( "SELECT referral_id, payout_id FROM `{$referrals_table}` WHERE payout_id IN (" . implode( ',', array_map( 'intval', array_keys( $paid_by ) ) ) . ')' ) as $r ) {
				$paid_by[ (int) $r['payout_id'] ][] = (int) $r['referral_id'];
				$all_ids[]                          = (int) $r['referral_id'];
			}
		}

		$referrals = array();
		$all_ids   = array_unique( array_filter( $all_ids ) );
		$columns   = 'referral_id, status, amount' . ( $this->has_column( $referrals_table, 'payout_id' ) ? ', payout_id' : '' );
		foreach ( array_chunk( $all_ids, 500 ) as $chunk ) {
			foreach ( $this->rows( "SELECT {$columns} FROM `{$referrals_table}` WHERE referral_id IN (" . implode( ',', $chunk ) . ')' ) as $r ) {
				$referrals[ (int) $r['referral_id'] ] = $r;
			}
		}

		$records = array();
		foreach ( $rows as $row ) {

			$id     = (int) $row['payout_id'];
			$amount = $this->money( $row['amount'] );
			$known  = array();
			foreach ( array_unique( $paid_by[ $id ] ) as $referral_id ) {
				if ( isset( $referrals[ $referral_id ] ) ) {
					$known[ $referral_id ] = $referrals[ $referral_id ];
				}
			}

			// The referrals it counts for: paid ones, and for a processing payout
			// those still on their way to the affiliate through it.
			$processing = 'processing' === (string) $row['status'];
			$paid_ids   = array();
			$paid_total = 0.0;
			foreach ( $known as $referral_id => $r ) {
				$in_flight = $processing && 'unpaid' === $r['status'] && isset( $r['payout_id'] ) && (int) $r['payout_id'] === $id;
				if ( 'paid' === $r['status'] || $in_flight ) {
					$paid_ids[]  = (string) $referral_id;
					$paid_total += $this->money( $r['amount'] );
				}
			}
			$all_paid = $known && count( $paid_ids ) === count( $known );

			switch ( (string) $row['status'] ) {
				case 'paid':
				case 'processing':
					$status = $processing ? 'pending' : 'paid';
					if ( $processing && ! $known ) {
						$status = 'cancelled';
					} elseif ( $known && ! $all_paid ) {
						// Only the part its referrals show as paid; none of it when none are.
						$amount = min( $amount, round( $paid_total, 2 ) );
						$status = $amount > 0 ? $status : 'cancelled';
					}
					break;
				default:
					$status = 'cancelled';
			}

			// service_id is a NOT NULL bigint: 0 on payouts made without a service.
			$reference = $transaction && is_scalar( $row[ $transaction ] ) ? trim( (string) $row[ $transaction ] ) : '';

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'amount'              => $amount,
					'status'              => $status,
					'method'              => $this->payout_method( $row['payout_method'] ),
					'date'                => $this->date( $row['date'], true ),
					'date_paid'           => 'paid' === $status ? $this->date( $row['date'], true ) : '',
					'transaction_id'      => '0' === $reference ? '' : $reference,
					'referral_source_ids' => $known ? $paid_ids : array_map( 'strval', $paid_by[ $id ] ),
				)
			);
		}

		return $records;
	}

	/* ---------------------------------------------------------------------
	 * Visits
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$where = $since ? $wpdb->prepare( '`date` >= %s', get_gmt_from_date( $since . ' 00:00:00' ) ) : '';
		$rows  = $this->batch( $this->t( 'visits' ), 'visit_id', $after, $limit, $where );

		// The orders the visits converted into, through their referral.
		$orders = array();
		$rids   = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'referral_id' ) ) );
		if ( $rids ) {
			foreach ( $this->rows( 'SELECT referral_id, context, reference FROM `' . $this->t( 'referrals' ) . '` WHERE referral_id IN (' . implode( ',', array_unique( $rids ) ) . ')' ) as $r ) {
				$orders[ (int) $r['referral_id'] ] = $this->order_id_of( $r );
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['visit_id'],
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'url'                 => (string) $row['url'],
					'referrer'            => (string) $row['referrer'],
					'ip'                  => isset( $row['ip'] ) ? (string) $row['ip'] : '',
					'campaign'            => isset( $row['campaign'] ) ? (string) $row['campaign'] : '',
					'date'                => $this->date( $row['date'], true ),
					'order_id'            => isset( $orders[ (int) $row['referral_id'] ] ) ? $orders[ (int) $row['referral_id'] ] : 0,
					'converted'           => (int) $row['referral_id'] > 0,
				)
			);
		}

		return $records;
	}

	/* ---------------------------------------------------------------------
	 * Creatives, excluded products, store credit
	 * ------------------------------------------------------------------ */

	/**
	 * @return array[]
	 */
	public function get_creatives() {
		$table    = $this->t( 'creatives' );
		$rows     = $this->batch( $table, 'creative_id', '', 10000 );
		$has_type = $this->has_column( $table, 'type' );
		$records  = array();
		foreach ( $rows as $row ) {
			$image = (string) $row['image'];
			// 2.9+ may keep only the attachment.
			if ( '' === $image && ! empty( $row['attachment_id'] ) ) {
				$url   = wp_get_attachment_url( (int) $row['attachment_id'] );
				$image = $url ? $url : '';
			}
			// 2.17's QR code creatives are generated, not uploaded: not a banner.
			$is_image  = $has_type ? 'image' === strtolower( (string) $row['type'] ) : '' !== $image;
			$records[] = array(
				'source_id'   => (string) (int) $row['creative_id'],
				'name'        => (string) $row['name'],
				'description' => wp_strip_all_tags( (string) $row['description'] ),
				'type'        => $is_image && '' !== $image ? 'image' : 'text',
				'image'       => $image,
				'url'         => (string) $row['url'],
				'text'        => (string) $row['text'],
				'alt'         => (string) $row['text'],
				'status'      => 'active' === $row['status'] ? 'active' : 'inactive',
			);
		}
		return $records;
	}

	/**
	 * Whether any product has referrals switched off.
	 *
	 * @return bool
	 */
	protected function has_excluded_products() {
		global $wpdb;
		return (bool) $this->cached_var( 'excluded_products', "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '" . self::REFERRALS_DISABLED_META . "' AND meta_value <> '' AND meta_value <> '0' LIMIT 1" );
	}

	/**
	 * Products (and variations) with referrals switched off in the WooCommerce
	 * integration. Product and category rates are not carried over.
	 *
	 * @return array
	 */
	public function get_product_rates() {
		global $wpdb;

		$excluded = array();
		foreach ( $this->rows( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::REFERRALS_DISABLED_META . "' AND meta_value <> '' AND meta_value <> '0' ORDER BY post_id ASC" ) as $row ) {
			$excluded[] = (int) $row['post_id'];
		}

		return array(
			'products'   => array(),
			'categories' => array(),
			'excluded'   => $excluded,
		);
	}

	/**
	 * Whether any affiliate has a store credit balance (the Store Credit
	 * add-on's user meta, kept by core since 2.29).
	 *
	 * @return bool
	 */
	protected function has_store_credit() {
		global $wpdb;
		return (bool) $this->cached_var( 'store_credit', "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = 'affwp_wc_credit_balance' AND meta_value + 0 > 0 LIMIT 1" );
	}
}
