<?php
/**
 * Migration source: Ultimate Affiliate Pro (WPIndeed, plugin folder "indeed-affiliate-pro").
 *
 * Ultimate Affiliate Pro is sold on CodeCanyon only, so its source code could
 * not be read for this importer. The storage below was pieced together from
 * code that legitimately reads it - FluentAffiliate's Ultimate Affiliate
 * migrator (wp.org), VillaTheme's AFFI (whose referral model copies UAP's
 * column for column - it appears to be derived from UAP itself, so it is not
 * independent confirmation of anything), and public site code built on UAP
 * 9.7.x - plus WPIndeed's documentation. Every column is checked before it is
 * used, and anything not confirmed by at least one of those is left alone.
 *
 *   uap_affiliates        id, uid (WordPress user), rank_id, start_data (sic, the
 *                         sign-up date), status (0 = inactive, otherwise active).
 *                         Applicants waiting for approval have the WordPress role
 *                         "pending_user" instead of a status here: they are not
 *                         imported.
 *   uap_referrals         id, refferal_wp_uid (sic), campaign, affiliate_id, visit_id,
 *                         description, source ('woo' for WooCommerce), reference
 *                         (the order ID), reference_details, parent_referral_id,
 *                         child_referral_id, amount, currency, date,
 *                         status (0 refused / 1 unverified / 2 verified),
 *                         payment (0 unpaid / 1 reserved for a payment not made
 *                         yet / 2 paid)
 *   uap_payments          id, affiliate_id, amount, currency, payment_type ('paypal',
 *                         'bt' = bank transfer, 'wallet' ...), transaction_id,
 *                         referral_ids (comma separated), status (0 and 1 not
 *                         completed - FluentAffiliate reads both as processing -
 *                         / 2 paid), create_date, update_date, payment_details
 *   uap_visits            id, affiliate_id, referral_id, url, ref_url, campaign_name,
 *                         ip, visit_date
 *   uap_ranks             id, label, slug, amount_type (percentage | flat),
 *                         amount_value, status - the commission levels
 *   uap_coupons_code_affiliates  id, code, affiliate_id, type ('woo'), status (1)
 *   uap_banners           id, name, description, image, url, status, DATE
 *
 * Settings are separate "uap_*" options: uap_referral_variable (the referral
 * link parameter, "ref" by default), uap_default_ref_format ("username", or the
 * affiliate ID - uap_affiliates.id, not the user ID) and uap_currency. The
 * PayPal email is the user meta "uap_affiliate_paypal_email".
 *
 * Only what is confirmed is migrated. Multi-level parents (uap_mlm_relations),
 * lifetime customers (uap_affiliate_referral_users_relations), program
 * settings, bank details and payout methods, custom slugs, coupons' own
 * commission rates and registration answers are not: they are listed in
 * get_not_migrated().
 *
 * Note: Uncanny Automator also creates "uap_" tables (uap_recipe_log and
 * so on), which is why the plugin is only detected when both uap_affiliates and
 * uap_referrals exist.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_UAP extends WCUsage_Migration_Source {

	/**
	 * Referral sources that are WooCommerce orders.
	 */
	const WOO_SOURCES = '/^(woo|woocommerce|wc)$/i';

	/**
	 * Referral sources that are known not to be WooCommerce orders (Ultimate
	 * Membership Pro, Ultimate Learning Pro, Easy Digital Downloads, the plugin's
	 * own sign-up, landing page and multi-level commissions, and so on).
	 */
	const OTHER_SOURCES = '/^(ump|ulp|edd|easy[ _-]?digital|mlm|landing|signup|user[ _-]?signup|register|bonus|rank|manual|admin|pmpro|memberpress|givewp|give|lifterlms|learndash|tutor|wpforms|stripe|paypal|wallet|rewrite)/i';

	/**
	 * Per-request caches.
	 *
	 * @var array|null
	 */
	protected $coupon_map = null;

	/**
	 * @var array|null referral ID => payment ID
	 */
	protected $payment_links = null;

	/**
	 * @var array referral ID => true when payment_links() names a completed payment
	 */
	protected $completed_links = array();

	/**
	 * @var array|null rank ID => rank row
	 */
	protected $ranks = null;

	/**
	 * Options change which coupon a shared code goes to, so forget what was
	 * worked out with the previous ones.
	 *
	 * @param array $options
	 */
	public function set_options( $options ) {
		parent::set_options( $options );
		$this->coupon_map = null;
	}

	/* ---------------------------------------------------------------------
	 * Tables and small helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $name Table name without the "uap_" prefix.
	 * @return string
	 */
	protected function t( $name ) {
		return $this->table( 'uap_' . $name );
	}

	/**
	 * The next batch of a table, or nothing when the table (or its key column)
	 * is not there - no query against a column that does not exist.
	 *
	 * @param string $table
	 * @param string $key
	 * @param string $after
	 * @param int    $limit
	 * @param string $where
	 * @param bool   $numeric
	 * @return array[]
	 */
	protected function batch( $table, $key, $after, $limit, $where = '', $numeric = true ) {
		if ( ! $this->has_column( $table, $key ) ) {
			return array();
		}
		return parent::batch( $table, $key, $after, $limit, $where, $numeric );
	}

	/**
	 * A column's value from a row, or a default when the column is not there.
	 *
	 * @param array  $row
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function val( $row, $key, $default = '' ) {
		return ( is_array( $row ) && array_key_exists( $key, $row ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	/**
	 * One column of a list of rows, skipping rows without it (wp_list_pluck()
	 * warns on a missing column, and a column may not exist in every version).
	 *
	 * @param array[] $rows
	 * @param string  $key
	 * @return array
	 */
	protected function pluck( $rows, $key ) {
		$values = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row[ $key ] ) ) {
				$values[] = $row[ $key ];
			}
		}
		return $values;
	}

	/**
	 * The store's currency.
	 *
	 * @return string
	 */
	protected function store_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * A list of IDs stored as CSV, a serialized array or JSON.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	protected function parse_ids( $raw ) {
		if ( is_array( $raw ) ) {
			$ids = $raw;
		} elseif ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		} else {
			$raw = trim( $raw );
			$ids = $this->unserialize( $raw );
			if ( ! is_array( $ids ) ) {
				$json = json_decode( $raw, true );
				$ids  = is_array( $json ) ? $json : preg_split( '/[\s,;|]+/', $raw );
			}
		}
		$out = array();
		foreach ( (array) $ids as $id ) {
			if ( is_scalar( $id ) && is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (string) (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The commission levels (ranks).
	 *
	 * @return array rank ID => row
	 */
	protected function ranks() {
		if ( null === $this->ranks ) {
			$this->ranks = array();
			foreach ( $this->batch( $this->t( 'ranks' ), 'id', '', 1000 ) as $row ) {
				$this->ranks[ (int) $row['id'] ] = $row;
			}
		}
		return $this->ranks;
	}

	/**
	 * A UAP amount type in the common rate type form.
	 *
	 * @param string $type 'percentage' | 'flat'
	 * @return string
	 */
	protected function rate_type( $type ) {
		$type = strtolower( (string) $type );
		// A flat amount is paid per referral, that is per order.
		return ( false !== strpos( $type, 'flat' ) || false !== strpos( $type, 'fixed' ) ) ? 'flat_order' : 'percent';
	}

	/**
	 * A payout method in the common form.
	 *
	 * @param mixed $method
	 * @return string
	 */
	protected function payout_method( $method ) {
		$method = is_string( $method ) ? strtolower( trim( $method ) ) : '';
		if ( '' === $method ) {
			return '';
		}
		if ( false !== strpos( $method, 'paypal' ) ) {
			return 'paypal';
		}
		if ( false !== strpos( $method, 'stripe' ) ) {
			return 'stripe';
		}
		if ( 'bt' === $method || false !== strpos( $method, 'bank' ) ) {
			return 'bank';
		}
		if ( false !== strpos( $method, 'wallet' ) || false !== strpos( $method, 'credit' ) ) {
			return 'credit';
		}
		return 'manual';
	}

	/* ---------------------------------------------------------------------
	 * What the source provides
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {

		$supported = array( 'affiliates', 'legacy_links', 'referrals', 'payout_details', 'tiers' );

		if ( $this->table_exists( $this->t( 'coupons_code_affiliates' ) ) ) {
			$supported[] = 'coupons';
		}
		if ( $this->table_exists( $this->t( 'payments' ) ) ) {
			$supported[] = 'payouts';
		}
		if ( $this->table_exists( $this->t( 'visits' ) ) ) {
			$supported[] = 'visits';
			// The visit's campaign, as FluentAffiliate reads it.
			if ( $this->has_column( $this->t( 'visits' ), 'campaign_name' ) ) {
				$supported[] = 'campaigns';
			}
		}
		if ( $this->ranks() ) {
			$supported[] = 'groups';
		}
		if ( $this->table_exists( $this->t( 'banners' ) ) ) {
			$supported[] = 'creatives';
		}

		return $supported;
	}

	/**
	 * @return string
	 */
	public function get_confidence() {
		return 'partial';
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {

		$lines   = array();
		$pending = $this->pending_count();
		if ( $pending ) {
			/* translators: %d: number of applications */
			$lines[] = sprintf( _n( '%d affiliate application still waiting for approval (the applicant is not imported)', '%d affiliate applications still waiting for approval (the applicants are not imported)', $pending, 'woo-coupon-usage' ), $pending );
		}
		if ( $this->count_rows( $this->t( 'mlm_relations' ) ) ) {
			$lines[] = __( 'Multi-level (MLM) affiliate parents (past MLM commission is still added to the earning affiliate\'s balance)', 'woo-coupon-usage' );
		}
		if ( $this->count_rows( $this->t( 'affiliate_referral_users_relations' ) ) ) {
			$lines[] = __( 'Lifetime customer links', 'woo-coupon-usage' );
		}

		$lines[] = __( 'Program settings, such as the cookie length and the rank new affiliates start on', 'woo-coupon-usage' );
		$lines[] = __( 'Commission rates set on individual coupons (affiliates earn their rank\'s rate, or the default)', 'woo-coupon-usage' );
		$lines[] = __( 'Bank transfer details and the payout method each affiliate chose (PayPal emails are migrated)', 'woo-coupon-usage' );
		/* translators: %s: referral link parameter, such as "ref" */
		$lines[] = sprintf( __( 'Custom affiliate slugs and friendly /%1$s/ links: old ?%1$s= links still work', 'woo-coupon-usage' ), $this->referral_variable() );
		$lines[] = __( 'Ultimate Affiliate Pro\'s referral cookie, for visitors who clicked an old link before the switch', 'woo-coupon-usage' );
		$lines[] = __( 'Registration form answers', 'woo-coupon-usage' );
		$lines[] = __( 'Offers, pay-per-click and sign-up commissions, and automatic rank changes', 'woo-coupon-usage' );

		return $lines;
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {

		$warnings = array(
			__( 'Ultimate Affiliate Pro is a premium plugin whose code could not be checked, so this importer reads its tables as other plugins that integrate with it do. Review the result before deleting the old plugin\'s data.', 'woo-coupon-usage' ),
			__( 'Referrals are treated as WooCommerce orders when their source is "woo" (or, for an unknown source, when they point at an existing order placed around the same time). Multi-level commissions (source "mlm", or with a parent referral) are added to the earning affiliate\'s balance.', 'woo-coupon-usage' ),
		);

		$currency = (string) get_option( 'uap_currency', '' );
		if ( '' !== $currency && $this->store_currency() && strtoupper( $currency ) !== strtoupper( $this->store_currency() ) ) {
			/* translators: 1: Ultimate Affiliate Pro currency, 2: store currency */
			$warnings[] = sprintf( __( 'Ultimate Affiliate Pro records commission in %1$s but the store uses %2$s. Amounts are imported as they are, without conversion.', 'woo-coupon-usage' ), $currency, $this->store_currency() );
		}

		// Each referral and payment records its own currency too.
		foreach ( array(
			/* translators: 1: store currency, 2: list such as "3 in EUR (45.00)" */
			'referrals' => __( 'Ultimate Affiliate Pro referrals recorded in a currency other than the store\'s (%1$s): %2$s. Their amounts are imported as they are, without conversion.', 'woo-coupon-usage' ),
			/* translators: 1: store currency, 2: list such as "3 in EUR (45.00)" */
			'payments'  => __( 'Ultimate Affiliate Pro payments recorded in a currency other than the store\'s (%1$s): %2$s. Their amounts are imported as they are, without conversion.', 'woo-coupon-usage' ),
		) as $name => $message ) {
			$other = $this->other_currencies( $this->t( $name ) );
			if ( $other ) {
				$warnings[] = sprintf( $message, $this->store_currency(), implode( ', ', $other ) );
			}
		}

		$map    = $this->coupon_map();
		$shared = 0;
		foreach ( $map['codes'] as $affiliates ) {
			if ( count( $affiliates ) > 1 ) {
				$shared++;
			}
		}
		if ( $shared ) {
			/* translators: %d: number of coupons */
			$warnings[] = sprintf( _n( '%d coupon is linked to more than one affiliate. A coupon can only belong to one affiliate here - choose what happens below.', '%d coupons are linked to more than one affiliate. A coupon can only belong to one affiliate here - choose what happens below.', $shared, 'woo-coupon-usage' ), $shared );
		}

		$numeric = $this->numeric_usernames();
		if ( $numeric ) {
			/* translators: %d: number of affiliates */
			$warnings[] = sprintf( _n( '%d affiliate\'s username is a number. Ultimate Affiliate Pro\'s links carried usernames here, but a number in an old link is taken as an affiliate ID, so that affiliate\'s old username links are not kept working (their coupon and ID links are).', '%d affiliates\' usernames are numbers. Ultimate Affiliate Pro\'s links carried usernames here, but a number in an old link is taken as an affiliate ID, so those affiliates\' old username links are not kept working (their coupons and ID links are).', $numeric, 'woo-coupon-usage' ), $numeric );
		}

		return $warnings;
	}

	/**
	 * Rows of a table recorded in a currency other than the store's.
	 *
	 * @param string $table Full table name, with "currency" and "amount" columns.
	 * @return string[] One entry per currency: "3 in EUR (45.00)".
	 */
	protected function other_currencies( $table ) {
		global $wpdb;

		$store = strtoupper( $this->store_currency() );
		if ( '' === $store || ! $this->has_column( $table, 'currency' ) || ! $this->has_column( $table, 'amount' ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->rows( $wpdb->prepare( "SELECT UPPER(currency) AS currency, COUNT(*) AS n, SUM(amount) AS total FROM `{$table}` WHERE currency <> '' AND UPPER(currency) <> %s GROUP BY UPPER(currency) ORDER BY n DESC LIMIT 10", $store ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			/* translators: 1: number of rows, 2: currency code, 3: total amount */
			$out[] = sprintf( __( '%1$d in %2$s (%3$s)', 'woo-coupon-usage' ), (int) $row['n'], $row['currency'], number_format_i18n( (float) $row['total'], 2 ) );
		}
		return $out;
	}

	/**
	 * How many affiliates have a numeric username, when links carried
	 * usernames (uap_default_ref_format "username").
	 *
	 * @return int
	 */
	protected function numeric_usernames() {
		global $wpdb;
		$table = $this->t( 'affiliates' );
		if ( 'username' !== get_option( 'uap_default_ref_format', '' ) || ! $this->has_column( $table, 'uid' ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` a INNER JOIN {$wpdb->users} u ON u.ID = a.uid WHERE u.user_login REGEXP '^[0-9]+$'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		$payments = $this->t( 'payments' );
		return array(
			// Applicants still waiting for approval are not imported.
			'affiliates' => max( 0, $this->count_rows( $this->t( 'affiliates' ) ) - $this->pending_count() ),
			'referrals'  => $this->count_rows( $this->t( 'referrals' ) ),
			// As get_payouts() reads them: paid, and not completed (0 and 1).
			'payouts'    => $this->has_column( $payments, 'status' ) ? $this->count_rows( $payments, 'status IN (0,1,2)' ) : $this->count_rows( $payments ),
			'visits'     => $this->count_rows( $this->t( 'visits' ) ),
			'creatives'  => $this->count_rows( $this->t( 'banners' ) ),
			'groups'     => count( $this->ranks() ),
			'coupons'    => count( $this->coupon_map()['coupon_ids'] ),
		);
	}

	/**
	 * @return array
	 */
	public function get_source_options() {
		return array(
			'uap_shared_coupons' => array(
				'type'    => 'select',
				'section' => 'coupons',
				'label'   => __( 'Coupons Ultimate Affiliate Pro linked to more than one affiliate', 'woo-coupon-usage' ),
				'desc'    => __( 'A coupon can only belong to one affiliate here. The others are given a coupon of their own.', 'woo-coupon-usage' ),
				'default' => 'first',
				'choices' => array(
					'first' => __( 'Give it to the affiliate it was linked to first', 'woo-coupon-usage' ),
					'skip'  => __( 'Give it to none of them', 'woo-coupon-usage' ),
				),
			),
		);
	}

	/**
	 * The referral link parameter. Query string keys are case-sensitive: the
	 * case is kept, and anything that cannot be in a parameter name dropped.
	 *
	 * @return string
	 */
	protected function referral_variable() {
		$var = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) get_option( 'uap_referral_variable', '' ) );
		return '' !== $var ? $var : 'ref';
	}

	/**
	 * Old links are "?ref=<affiliate ID or username>". "Friendly" /ref/jane/
	 * links and custom slugs are not kept working.
	 *
	 * @return array
	 */
	public function get_legacy_links() {
		return array(
			'vars'    => array( $this->referral_variable() ),
			// The "uap_referral" cookie's format is not known, so it is not converted.
			'cookies' => array(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Coupons
	 * ------------------------------------------------------------------ */

	/**
	 * The coupons UAP linked to affiliates, found once. A coupon's own
	 * commission rate is not carried over: an affiliate's rate here applies to
	 * all of their coupons.
	 *
	 * @return array array(
	 *   'codes'      => code => affiliate IDs (in the order they were linked),
	 *   'affiliates' => affiliate ID => coupon IDs,
	 *   'coupon_ids' => coupon IDs,
	 * )
	 */
	protected function coupon_map() {

		if ( null !== $this->coupon_map ) {
			return $this->coupon_map;
		}

		$map = array(
			'codes'      => array(),
			'affiliates' => array(),
			'coupon_ids' => array(),
		);

		$table = $this->t( 'coupons_code_affiliates' );
		if ( ! $this->table_exists( $table ) || ! $this->has_column( $table, 'code' ) || ! $this->has_column( $table, 'affiliate_id' ) ) {
			$this->coupon_map = $map;
			return $map;
		}

		$key  = $this->has_column( $table, 'id' ) ? 'id' : 'affiliate_id';
		$rows = $this->rows( "SELECT * FROM `{$table}` ORDER BY `{$key}` ASC LIMIT 100000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			// WooCommerce coupons only ('woo'), and only active links.
			$type = strtolower( (string) $this->val( $row, 'type', 'woo' ) );
			if ( '' !== $type && ! preg_match( self::WOO_SOURCES, $type ) ) {
				continue;
			}
			if ( array_key_exists( 'status', $row ) && '' !== (string) $row['status'] && ! (int) $row['status'] ) {
				continue;
			}
			$code         = wc_format_coupon_code( (string) $row['code'] );
			$affiliate_id = (int) $row['affiliate_id'];
			if ( '' === $code || ! $affiliate_id ) {
				continue;
			}
			if ( ! isset( $map['codes'][ $code ] ) || ! in_array( $affiliate_id, $map['codes'][ $code ], true ) ) {
				$map['codes'][ $code ][] = $affiliate_id;
			}
		}

		// Applicants still waiting for approval are not imported, so a coupon
		// is never theirs.
		$linked = array();
		foreach ( $map['codes'] as $affiliates ) {
			$linked = array_merge( $linked, $affiliates );
		}
		$pending = array_keys( $this->pending_among( $linked ) );
		foreach ( $map['codes'] as $code => $affiliates ) {
			$affiliates = array_values( array_diff( $affiliates, $pending ) );
			if ( $affiliates ) {
				$map['codes'][ $code ] = $affiliates;
			} else {
				unset( $map['codes'][ $code ] );
			}
		}

		$coupons = $this->coupons_by_code( array_keys( $map['codes'] ) );
		$policy  = (string) $this->option( 'uap_shared_coupons', 'first' );

		// Affiliates switched off in UAP, so a shared coupon goes to an active one.
		$inactive         = array();
		$shared           = array();
		$affiliates_table = $this->t( 'affiliates' );
		foreach ( $map['codes'] as $affiliates ) {
			if ( count( $affiliates ) > 1 ) {
				$shared = array_merge( $shared, $affiliates );
			}
		}
		if ( $shared && $this->has_column( $affiliates_table, 'status' ) ) {
			foreach ( $this->rows( "SELECT id FROM `{$affiliates_table}` WHERE status = 0 AND id IN (" . implode( ',', array_map( 'intval', array_unique( $shared ) ) ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$inactive[ (int) $row['id'] ] = true;
			}
		}

		foreach ( $map['codes'] as $code => $affiliates ) {
			if ( empty( $coupons[ $code ] ) ) {
				continue;
			}
			if ( count( $affiliates ) > 1 && 'skip' === $policy ) {
				continue;
			}
			// One owner here: the first active affiliate it was linked to (the
			// first one at all when none is active).
			$owner = $affiliates[0];
			foreach ( $affiliates as $candidate ) {
				if ( ! isset( $inactive[ $candidate ] ) ) {
					$owner = $candidate;
					break;
				}
			}

			$map['affiliates'][ $owner ][] = $coupons[ $code ];
			$map['coupon_ids'][]           = $coupons[ $code ];
		}

		$this->coupon_map = $map;
		return $map;
	}

	/**
	 * Which of some affiliates are applicants still waiting for approval: their
	 * WordPress user has UAP's "pending_user" role.
	 *
	 * @param int[] $affiliate_ids
	 * @return array affiliate ID => true
	 */
	protected function pending_among( $affiliate_ids ) {
		global $wpdb;

		$table = $this->t( 'affiliates' );
		$ids   = array_unique( array_filter( array_map( 'intval', (array) $affiliate_ids ) ) );
		if ( ! $ids || ! $this->has_column( $table, 'uid' ) ) {
			return array();
		}

		$pending = array();
		foreach ( $this->rows( $wpdb->prepare( "SELECT a.id, um.meta_value FROM `{$table}` a INNER JOIN {$wpdb->usermeta} um ON um.user_id = a.uid AND um.meta_key = %s WHERE a.id IN (" . implode( ',', $ids ) . ')', $wpdb->get_blog_prefix() . 'capabilities' ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$caps = $this->unserialize( $row['meta_value'] );
			if ( is_array( $caps ) && ! empty( $caps['pending_user'] ) ) {
				$pending[ (int) $row['id'] ] = true;
			}
		}
		return $pending;
	}

	/**
	 * How many applicants are still waiting for approval (see pending_among()).
	 *
	 * @return int
	 */
	protected function pending_count() {
		global $wpdb;

		$table = $this->t( 'affiliates' );
		if ( ! $this->has_column( $table, 'uid' ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $this->rows( $wpdb->prepare( "SELECT um.meta_value FROM `{$table}` a INNER JOIN {$wpdb->usermeta} um ON um.user_id = a.uid AND um.meta_key = %s WHERE um.meta_value LIKE %s", $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like( 'pending_user' ) . '%' ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$caps = $this->unserialize( $row['meta_value'] );
			if ( is_array( $caps ) && ! empty( $caps['pending_user'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Readers
	 * ------------------------------------------------------------------ */

	/**
	 * Affiliates. Applicants still waiting for approval are not imported, so
	 * this reads on until the batch is full or the table is done.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$table   = $this->t( 'affiliates' );
		$records = array();

		while ( count( $records ) < $limit ) {
			$want = $limit - count( $records );
			$rows = $this->batch( $table, 'id', $after, $want );
			if ( ! $rows ) {
				break;
			}
			$last    = end( $rows );
			$after   = (string) (int) $last['id'];
			$records = array_merge( $records, $this->affiliate_records( $rows ) );
			if ( count( $rows ) < $want ) {
				break;
			}
		}

		return $records;
	}

	/**
	 * Affiliate records for a batch of rows, leaving out applicants still
	 * waiting for approval.
	 *
	 * @param array[] $rows
	 * @return array[]
	 */
	protected function affiliate_records( $rows ) {

		$ids       = array_map( 'intval', $this->pluck( $rows, 'id' ) );
		$user_ids  = array_filter( array_map( 'intval', $this->pluck( $rows, 'uid' ) ) );
		$pending   = $this->pending_among( $ids );
		$users     = $this->users_for( $user_ids );
		$umeta     = $this->user_meta_for( $user_ids, array( 'first_name', 'last_name', 'uap_affiliate_paypal_email' ) );
		$coupons   = $this->coupon_map();
		$ranks     = $this->ranks();
		$usernames = 'username' === get_option( 'uap_default_ref_format', '' );

		$records = array();
		foreach ( $rows as $row ) {

			$id = (int) $row['id'];
			if ( isset( $pending[ $id ] ) ) {
				continue;
			}

			$user_id = (int) $this->val( $row, 'uid', 0 );
			$user    = isset( $users[ $user_id ] ) ? $users[ $user_id ] : array();
			$m       = isset( $umeta[ $user_id ] ) ? $umeta[ $user_id ] : array();
			$login   = isset( $user['user_login'] ) ? (string) $user['user_login'] : '';
			$status  = array_key_exists( 'status', $row ) && is_numeric( $row['status'] ) && ! (int) $row['status'] ? 'inactive' : 'active';

			// Links carry the affiliate ID (uap_affiliates.id, not the user ID),
			// or with the "username" format the username. A number in a link is
			// read as the affiliate ID, so a numeric username cannot stand beside
			// the IDs (it would take over another affiliate's ID links): their ID
			// links and coupon keep working, their username links do not
			// (counted in get_warnings()).
			$identifiers = array( (string) $id );
			if ( $usernames && '' !== $login && ! is_numeric( $login ) ) {
				$identifiers[] = $login;
			}

			$rank_id = (int) $this->val( $row, 'rank_id', 0 );

			$records[] = $this->affiliate_record(
				array(
					'source_id'       => $id,
					'user_id'         => $user_id,
					'email'           => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'        => $login,
					'first_name'      => isset( $m['first_name'] ) && is_string( $m['first_name'] ) ? $m['first_name'] : '',
					'last_name'       => isset( $m['last_name'] ) && is_string( $m['last_name'] ) ? $m['last_name'] : '',
					'status'          => $status,
					'date'            => $this->date( $this->val( $row, 'start_data', '' ) ),
					// No rate of their own: they earn their rank's, through the group.
					'payment_email'   => isset( $m['uap_affiliate_paypal_email'] ) && is_string( $m['uap_affiliate_paypal_email'] ) ? trim( $m['uap_affiliate_paypal_email'] ) : '',
					'website'         => isset( $user['user_url'] ) ? (string) $user['user_url'] : '',
					'group_source_id' => $rank_id && isset( $ranks[ $rank_id ] ) ? (string) $rank_id : '',
					'coupons'         => isset( $coupons['affiliates'][ $id ] ) ? $coupons['affiliates'][ $id ] : array(),
					'identifiers'     => $identifiers,
					'link_id'         => (string) $id,
				)
			);
		}

		return $records;
	}

	/**
	 * @return array[]
	 */
	public function get_groups() {
		$groups = array();
		foreach ( $this->ranks() as $id => $row ) {
			$value    = $this->val( $row, 'amount_value', '' );
			$rate     = is_numeric( $value ) ? (float) $value : null;
			$name     = (string) $this->val( $row, 'label', '' );
			$groups[] = array(
				'source_id' => (string) $id,
				'name'      => '' !== trim( $name ) ? $name : (string) $this->val( $row, 'slug', '' ),
				'rate'      => $rate,
				'rate_type' => null === $rate ? null : $this->rate_type( $this->val( $row, 'amount_type', 'percentage' ) ),
			);
		}
		return $groups;
	}

	/**
	 * Which payment each referral was paid in, from the payments' referral
	 * lists: paid ones (2) and ones not completed yet (0 and 1), a paid one
	 * first. A payment with any other status does not count.
	 *
	 * @return array referral ID => payment ID
	 */
	protected function payment_links() {

		if ( null !== $this->payment_links ) {
			return $this->payment_links;
		}

		$this->payment_links   = array();
		$this->completed_links = array();
		$table                 = $this->t( 'payments' );
		if ( ! $this->has_column( $table, 'id' ) || ! $this->has_column( $table, 'referral_ids' ) ) {
			return $this->payment_links;
		}

		$has_status = $this->has_column( $table, 'status' );
		$after      = 0;
		while ( true ) {
			$rows = $this->batch( $table, 'id', $after ? (string) $after : '', 2000, $has_status ? 'status IN (0,1,2)' : '' );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$paid  = ! $has_status || '2' === (string) $row['status'];
				foreach ( $this->parse_ids( $row['referral_ids'] ) as $referral_id ) {
					if ( ! isset( $this->payment_links[ $referral_id ] ) || ( $paid && ! isset( $this->completed_links[ $referral_id ] ) ) ) {
						$this->payment_links[ $referral_id ] = (string) (int) $row['id'];
						if ( $paid ) {
							$this->completed_links[ $referral_id ] = true;
						}
					}
				}
			}
			if ( count( $rows ) < 2000 ) {
				break;
			}
		}

		return $this->payment_links;
	}

	/**
	 * When each of a batch of orders was placed.
	 *
	 * @param int[] $order_ids
	 * @return array order ID => Unix time
	 */
	protected function order_times( $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) ) );
		if ( ! $order_ids ) {
			return array();
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$in   = implode( ',', $order_ids );
		$sql  = $hpos
			? "SELECT id, date_created_gmt AS d FROM {$wpdb->prefix}wc_orders WHERE id IN ({$in}) AND type = 'shop_order'"
			: "SELECT ID AS id, post_date_gmt AS d FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'shop_order'";

		$times = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$time = $row['d'] ? strtotime( $row['d'] . ' UTC' ) : 0;
			if ( $time ) {
				$times[ (int) $row['id'] ] = $time;
			}
		}
		return $times;
	}

	/**
	 * The WooCommerce order behind each of a batch of referrals.
	 *
	 * 'woo' referrals point at their order by ID. A referral from a source
	 * UAP is known to use for something else is never an order. For any other
	 * source (or when the source is not recorded) the reference only counts
	 * when it is an existing order placed within a week of the referral.
	 *
	 * @param array[] $rows Referral rows.
	 * @return array referral ID => order ID
	 */
	protected function orders_for( $rows ) {

		$orders  = array();
		$unknown = array();

		foreach ( $rows as $row ) {
			$reference = trim( (string) $this->val( $row, 'reference', '' ) );
			if ( '' === $reference || ! preg_match( '/^[0-9]+$/', $reference ) || ! (int) $reference ) {
				continue;
			}
			$source = trim( (string) $this->val( $row, 'source', '' ) );
			if ( '' !== $source && preg_match( self::WOO_SOURCES, $source ) ) {
				$orders[ (int) $row['id'] ] = (int) $reference;
			} elseif ( '' === $source || ! preg_match( self::OTHER_SOURCES, $source ) ) {
				$unknown[ (int) $row['id'] ] = (int) $reference;
			}
		}

		if ( $unknown ) {
			$times = $this->order_times( array_values( $unknown ) );
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				if ( ! isset( $unknown[ $id ] ) || ! isset( $times[ $unknown[ $id ] ] ) ) {
					continue;
				}
				$date = $this->val( $row, 'date', '' );
				$when = $date ? strtotime( (string) $date ) : 0;
				if ( ! $when || abs( $times[ $unknown[ $id ] ] - $when ) <= WEEK_IN_SECONDS + DAY_IN_SECONDS ) {
					$orders[ $id ] = $unknown[ $id ];
				}
			}
		}

		return $orders;
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->t( 'referrals' );
		$rows  = $this->batch( $table, 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$orders   = $this->orders_for( $rows );
		$payments = $this->payment_links();

		$records = array();
		foreach ( $rows as $row ) {

			$id      = (int) $row['id'];
			$status  = $this->val( $row, 'status', '' );
			$payment = $this->val( $row, 'payment', null );

			// status: 0 refused, 1 unverified, 2 verified.
			// payment: 0 unpaid, 1 reserved for a payment not made yet, 2 paid.
			// Anything else is skipped rather than guessed at.
			if ( '1' === (string) $status ) {
				$state = 'pending';
			} elseif ( '2' === (string) $status ) {
				if ( null === $payment || '' === (string) $payment ) {
					$state = 'unpaid';
				} elseif ( '0' === (string) $payment ) {
					$state = 'unpaid';
				} elseif ( '2' === (string) $payment ) {
					$state = 'paid';
				} elseif ( '1' === (string) $payment ) {
					// Not paid yet (FluentAffiliate reads it as unpaid). It counts as
					// paid only when it is in a payment that is imported (a pending
					// payout), so it is not owed and paid out at once.
					$state = isset( $payments[ (string) $id ] ) ? 'paid' : 'unpaid';
				} else {
					$state = 'rejected';
				}
			} else {
				$state = 'rejected';
			}

			// Multi-level commission: source "mlm", or a referral derived from another.
			$is_tier = (bool) preg_match( '/mlm/i', (string) $this->val( $row, 'source', '' ) ) || (int) $this->val( $row, 'parent_referral_id', 0 ) > 0;

			// The payment it was paid in. One still reserved (1) is paid here only
			// because of its unfinished payment, so that is its payment; one marked
			// paid (2) is only linked to a completed one, never to a payment that
			// has not gone through.
			$payout = '';
			if ( 'paid' === $state && isset( $payments[ (string) $id ] ) && ( '1' === (string) $payment || isset( $this->completed_links[ (string) $id ] ) ) ) {
				$payout = $payments[ (string) $id ];
			}

			$records[] = $this->referral_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $this->val( $row, 'affiliate_id', 0 ),
					'order_id'            => ! $is_tier && isset( $orders[ $id ] ) ? $orders[ $id ] : 0,
					'amount'              => $this->val( $row, 'amount', 0 ),
					'status'              => $state,
					'date'                => $this->date( $this->val( $row, 'date', '' ) ),
					'is_tier'             => $is_tier,
					'payout_source_id'    => $payout,
				)
			);
		}

		return $records;
	}

	/**
	 * Payments to affiliates. Paid ones (2) are paid payouts. Ones not
	 * completed yet (1, and 0, which FluentAffiliate also reads as processing)
	 * are pending payouts, their referrals imported as paid - unless none of
	 * the referrals it lists is still marked as in a payment or paid, when the
	 * payment never went through: its commission is migrated as owed instead,
	 * so it is not counted twice. Any other status is cancelled.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$rows = $this->batch( $this->t( 'payments' ), 'id', $after, $limit );

		// The payment flag of the referrals the unfinished payments list.
		$flags     = array();
		$listed    = array();
		$referrals = $this->t( 'referrals' );
		foreach ( $rows as $row ) {
			if ( in_array( (string) $this->val( $row, 'status', '2' ), array( '0', '1' ), true ) ) {
				$listed = array_merge( $listed, $this->parse_ids( $this->val( $row, 'referral_ids', '' ) ) );
			}
		}
		if ( $listed && $this->has_column( $referrals, 'id' ) && $this->has_column( $referrals, 'payment' ) ) {
			foreach ( array_chunk( array_unique( $listed ), 500 ) as $chunk ) {
				foreach ( $this->rows( "SELECT id, payment FROM `{$referrals}` WHERE id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$flags[ (string) (int) $r['id'] ] = (string) $r['payment'];
				}
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$status = (string) $this->val( $row, 'status', '2' );
			if ( '2' === $status ) {
				$status = 'paid';
			} elseif ( '1' === $status || '0' === $status ) {
				$known    = 0;
				$reserved = 0;
				foreach ( $this->parse_ids( $this->val( $row, 'referral_ids', '' ) ) as $referral_id ) {
					if ( isset( $flags[ $referral_id ] ) ) {
						$known++;
						if ( in_array( $flags[ $referral_id ], array( '1', '2' ), true ) ) {
							$reserved++;
						}
					}
				}
				$status = $known && ! $reserved ? 'cancelled' : 'pending';
			} else {
				$status = 'cancelled';
			}

			$created = $this->date( $this->val( $row, 'create_date', '' ) );
			$updated = $this->date( $this->val( $row, 'update_date', '' ) );

			$records[] = $this->payout_record(
				array(
					'source_id'           => (int) $row['id'],
					'affiliate_source_id' => (int) $this->val( $row, 'affiliate_id', 0 ),
					'amount'              => $this->val( $row, 'amount', 0 ),
					'status'              => $status,
					'method'              => $this->payout_method( $this->val( $row, 'payment_type', '' ) ),
					'date'                => $created ? $created : $updated,
					'date_paid'           => 'paid' === $status ? ( $updated ? $updated : $created ) : '',
					'transaction_id'      => (string) $this->val( $row, 'transaction_id', '' ),
					'referral_source_ids' => $this->parse_ids( $this->val( $row, 'referral_ids', '' ) ),
				)
			);
		}

		return $records;
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$table = $this->t( 'visits' );
		$where = $since && $this->has_column( $table, 'visit_date' ) ? $wpdb->prepare( 'visit_date >= %s', $since . ' 00:00:00' ) : '';
		$rows  = $this->batch( $table, 'id', $after, $limit, $where );
		if ( ! $rows ) {
			return array();
		}

		// The orders the visits converted into, through their referral.
		$orders       = array();
		$referral_ids = array_filter( array_map( 'intval', $this->pluck( $rows, 'referral_id' ) ) );
		if ( $referral_ids && $this->has_column( $this->t( 'referrals' ), 'id' ) ) {
			$referrals = $this->rows( 'SELECT * FROM `' . $this->t( 'referrals' ) . '` WHERE id IN (' . implode( ',', $referral_ids ) . ')' );
			$orders    = $this->orders_for( $referrals );
		}

		$records = array();
		foreach ( $rows as $row ) {
			$referral_id = (int) $this->val( $row, 'referral_id', 0 );
			$records[]   = $this->visit_record(
				array(
					'source_id'           => (int) $row['id'],
					'affiliate_source_id' => (int) $this->val( $row, 'affiliate_id', 0 ),
					'url'                 => (string) $this->val( $row, 'url', '' ),
					'referrer'            => (string) $this->val( $row, 'ref_url', '' ),
					'ip'                  => (string) $this->val( $row, 'ip', '' ),
					'campaign'            => (string) $this->val( $row, 'campaign_name', '' ),
					'date'                => $this->date( $this->val( $row, 'visit_date', '' ) ),
					'order_id'            => isset( $orders[ $referral_id ] ) ? $orders[ $referral_id ] : 0,
					'converted'           => $referral_id > 0,
				)
			);
		}

		return $records;
	}

	/**
	 * Banners.
	 *
	 * @return array[]
	 */
	public function get_creatives() {
		$records = array();
		foreach ( $this->batch( $this->t( 'banners' ), 'id', '', 10000 ) as $row ) {
			$image     = (string) $this->val( $row, 'image', '' );
			$status    = $this->val( $row, 'status', 1 );
			$records[] = array(
				'source_id'   => (string) (int) $row['id'],
				'name'        => (string) $this->val( $row, 'name', '' ),
				'description' => (string) $this->val( $row, 'description', '' ),
				'type'        => '' !== $image ? 'image' : 'text',
				'image'       => $image,
				'url'         => (string) $this->val( $row, 'url', '' ),
				'text'        => '',
				'alt'         => (string) $this->val( $row, 'name', '' ),
				'status'      => ( is_numeric( $status ) ? (int) $status : 1 ) ? 'active' : 'inactive',
			);
		}
		return $records;
	}
}
