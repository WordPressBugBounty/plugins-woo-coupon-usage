<?php
/**
 * Migration source: YITH WooCommerce Affiliates (free, with premium extras).
 *
 * Read from YITH WooCommerce Affiliates 3.x's own tables. Their structure is
 * defined by each data store's get_table_structure()
 * (includes/data-stores/class-yith-wcaf-*-data-store.php, DB version 2.2.0):
 *
 *   yith_wcaf_affiliates  ID, token, user_id, rate (NULL or 0 = the default rate),
 *                         earnings, refunds, paid, click, conversion,
 *                         enabled (-1 rejected | 0 new request | 1 accepted),
 *                         banned (0|1, on top of enabled), payment_email
 *   yith_wcaf_commissions ID, order_id, line_item_id, product_id, product_name,
 *                         affiliate_id, rate, line_total, amount (net of partial
 *                         refunds), refunds, status, created_at (site time), last_edit
 *                         One row per order LINE ITEM, not per order.
 *   yith_wcaf_payments    ID, affiliate_id, payment_email, gateway (bacs | paypal |
 *                         funds | payouts...), status (pending | on-hold | completed |
 *                         cancelled), amount, created_at, completed_at (site time),
 *                         transaction_key, gateway_details (serialized)
 *   yith_wcaf_payment_commission  payment_id, commission_id
 *   yith_wcaf_clicks      ID, affiliate_id, link, origin, origin_base, IP, click_date
 *                         (site time), order_id (NULL until converted), conv_date, conv_time
 *
 * Everything else an affiliate has is WordPress user meta prefixed
 * "_yith_wcaf_": application_date, first_name / last_name, one key per
 * registration field (field definitions in the "yith_wcaf_affiliate_profile_fields"
 * option), reject_message / ban_message and {gateway}_gateway_preferences.
 *
 * Referral links carry the affiliate's TOKEN (?ref={token}), not their ID. The
 * token is the WordPress user ID by default (or the username, a random string
 * or a custom value), so it is the only link identifier. Orders store the
 * token in "_yith_wcaf_referral"; commissions reference the affiliate by ID,
 * so that order meta is not needed here.
 *
 * Affiliate coupons are a premium feature, but their storage is in the free
 * build: coupon meta "coupon_referrer" = affiliate ID (includes/admin/class-yith-wcaf-admin-coupons.php).
 *
 * Not migrated (see get_not_migrated()): premium's persistent ("permanent")
 * customer links, whose meaning without premium's code is unknown, and its rate
 * rules, whose tables are not in the free code.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_YITH extends WCUsage_Migration_Source {

	/** User meta prefix YITH stores every affiliate meta key under. */
	const META_PREFIX = '_yith_wcaf_';

	/** Coupon meta holding the affiliate ID a coupon is assigned to (premium). */
	const COUPON_META = 'coupon_referrer';

	/**
	 * Order of the records when one order's commissions for one affiliate are
	 * split across statuses. The first record credits the order here and the
	 * runner adds the others to it. All of them share one group_id (see
	 * build_referrals()), so a split that changes between runs is not counted
	 * twice.
	 *
	 * @var array
	 */
	protected static $claim_order = array(
		'pending'  => 0,
		'unpaid'   => 1,
		'paid'     => 2,
		'rejected' => 3,
	);

	/* ---------------------------------------------------------------------
	 * Tables and identifiers
	 * ------------------------------------------------------------------ */

	/**
	 * @return string
	 */
	protected function affiliates_table() {
		return $this->table( 'yith_wcaf_affiliates' );
	}

	/**
	 * @return string
	 */
	protected function commissions_table() {
		return $this->table( 'yith_wcaf_commissions' );
	}

	/**
	 * @return string
	 */
	protected function payments_table() {
		return $this->table( 'yith_wcaf_payments' );
	}

	/**
	 * @return string
	 */
	protected function payment_commission_table() {
		return $this->table( 'yith_wcaf_payment_commission' );
	}

	/**
	 * @return string
	 */
	protected function clicks_table() {
		return $this->table( 'yith_wcaf_clicks' );
	}

	/**
	 * The source ID of an affiliate: their YITH affiliate ID.
	 *
	 * It is NOT one of their link identifiers (see get_affiliates()): YITH links
	 * carry the token, which by default is the WordPress user ID, so "?ref=7"
	 * means the affiliate whose user is 7 - not affiliate #7, who may well exist.
	 *
	 * @param int $affiliate_id
	 * @return string
	 */
	protected function affiliate_source_id( $affiliate_id ) {
		return (int) $affiliate_id > 0 ? (string) (int) $affiliate_id : '';
	}

	/* ---------------------------------------------------------------------
	 * What the source can provide
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {
		return array( 'affiliates', 'legacy_links', 'registrations', 'rates', 'referrals', 'payouts', 'payout_details', 'visits', 'settings', 'coupons' );
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		return array(
			__( 'Commission rate rules from YITH Premium, such as rates per product or category (each affiliate\'s own rate is copied)', 'woo-coupon-usage' ),
			__( 'Customers permanently linked to an affiliate (YITH Premium)', 'woo-coupon-usage' ),
			__( 'Other program settings (only the default rate, cookie length and click attribution are copied)', 'woo-coupon-usage' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		global $wpdb;

		$warnings = array();

		$subtract = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'subtract_from_commissions' AND meta_value <> ''" );
		if ( $subtract ) {
			$warnings[] = __( 'Some coupons are set in YITH to subtract their discount from the affiliate\'s commission rate. Coupon Affiliates has no such option, so check the commission rate on those coupons after migrating.', 'woo-coupon-usage' );
		}

		if ( $this->table_exists( $this->commissions_table() ) && $this->count_rows( $this->commissions_table(), "status = 'pending-payment'" ) ) {
			$warnings[] = __( 'Commission in YITH payments that are not completed yet is imported as paid, and those payments as pending payouts, so it is not paid out twice.', 'woo-coupon-usage' );
		}

		// YITH_WCAF_Orders: off, commission is worked out on the line subtotals.
		$exclude_discount = strtolower( trim( (string) get_option( 'yith_wcaf_commission_exclude_discount', 'yes' ) ) );
		if ( ! in_array( $exclude_discount, array( 'yes', '1', 'true' ), true ) ) {
			$warnings[] = __( 'YITH is set to work commission out before coupon discounts. Coupon Affiliates works it out after discounts, so new commission will be lower on discounted orders; commission already recorded is imported as it is.', 'woo-coupon-usage' );
		}

		// Each commission is in its order's currency (YITH_WCAF_Commission::get_currency()).
		$currencies = $this->other_currency_orders();
		if ( $currencies ) {
			$orders = array_sum( $currencies );
			$warnings[] = sprintf(
				/* translators: 1: number of orders, 2: list of currency codes, 3: store currency */
				_n( '%1$d order with YITH commission was paid in another currency (%2$s) than the store\'s (%3$s). YITH records commission in the order\'s currency: it is imported unconverted.', '%1$d orders with YITH commission were paid in other currencies (%2$s) than the store\'s (%3$s). YITH records commission in each order\'s currency: it is imported unconverted.', $orders, 'woo-coupon-usage' ),
				$orders,
				implode( ', ', array_keys( $currencies ) ),
				$this->store_currency()
			);
		}

		return $warnings;
	}

	/**
	 * The store's currency.
	 *
	 * @return string
	 */
	protected function store_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', '' );
	}

	/**
	 * Orders with commission that were paid in a currency other than the store's.
	 *
	 * @return array currency => number of orders
	 */
	protected function other_currency_orders() {
		global $wpdb;

		$table = $this->commissions_table();
		$store = $this->store_currency();
		if ( '' === $store || ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$sql = "SELECT o.currency AS currency, COUNT(DISTINCT o.id) AS total FROM `{$table}` c INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = c.order_id WHERE o.currency <> '' AND o.currency <> %s GROUP BY o.currency ORDER BY o.currency ASC";
		} else {
			$sql = "SELECT m.meta_value AS currency, COUNT(DISTINCT m.post_id) AS total FROM `{$table}` c INNER JOIN {$wpdb->postmeta} m ON m.post_id = c.order_id AND m.meta_key = '_order_currency' WHERE m.meta_value <> '' AND m.meta_value <> %s GROUP BY m.meta_value ORDER BY m.meta_value ASC";
		}

		$currencies = array();
		foreach ( $this->rows( $wpdb->prepare( $sql, $store ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$currencies[ strtoupper( (string) $row['currency'] ) ] = (int) $row['total'];
		}
		return $currencies;
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		global $wpdb;

		$referrals = 0;
		if ( $this->table_exists( $this->commissions_table() ) ) {
			// One referral per order and affiliate, however many line items it had.
			$referrals = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ( SELECT 1 FROM `' . $this->commissions_table() . '` GROUP BY order_id, affiliate_id ) AS referrals' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array(
			'affiliates' => $this->count_rows( $this->affiliates_table() ),
			'referrals'  => $referrals,
			'payouts'    => $this->count_rows( $this->payments_table(), "status <> 'cancelled'" ),
			'visits'     => $this->count_rows( $this->clicks_table() ),
			'coupons'    => array_sum( array_map( 'count', $this->coupons_by_meta( self::COUPON_META ) ) ),
		);
	}

	/**
	 * @return array
	 */
	public function get_option_defaults() {
		return array( 'coupon_code_format' => '{identifier}' );
	}

	/**
	 * A custom, username or random token reads well as a code, and matches the
	 * link the affiliate already shares; the default numeric token (their user
	 * ID) does not, so the username is used instead (see format_code()).
	 *
	 * @return string
	 */
	public function get_default_coupon_format() {
		return '{identifier}';
	}

	/* ---------------------------------------------------------------------
	 * Settings and old links
	 * ------------------------------------------------------------------ */

	/**
	 * @return array
	 */
	public function get_settings() {

		$out = array();

		$rate = get_option( 'yith_wcaf_general_rate', null );
		if ( null !== $rate && '' !== $rate && is_numeric( $rate ) ) {
			$out['rate']      = (float) $rate;
			$out['rate_type'] = 'percent';
		}

		// With expiry switched off YITH sets the cookie for 15 years.
		if ( 'no' === get_option( 'yith_wcaf_referral_make_cookie_expire', 'yes' ) ) {
			$out['cookie_days'] = 15 * 365;
		} else {
			$seconds = $this->duration_to_seconds( get_option( 'yith_wcaf_referral_cookie_expire', WEEK_IN_SECONDS ) );
			if ( $seconds > 0 ) {
				$out['cookie_days'] = max( 1, (int) ceil( $seconds / DAY_IN_SECONDS ) );
			}
		}

		// A token in the link replaces the one in the cookie (YITH_WCAF_Session::get_token()):
		// the last affiliate is credited.
		$out['credit_first'] = false;

		// "yith_wcaf_commission_exclude_discount" has no counterpart in the common
		// settings: Coupon Affiliates works commission out after discounts (see
		// get_warnings()).

		return $out;
	}

	/**
	 * A YITH duration setting in seconds: an integer number of seconds, or
	 * array( 'amount' => n, 'unit' => sec|mim|hour|day|week|month ) as saved by
	 * its duration field (includes/functions-yith-wcaf.php yith_wcaf_duration_to_secs()).
	 *
	 * @param mixed $duration
	 * @return int
	 */
	protected function duration_to_seconds( $duration ) {

		$duration = $this->unserialize( $duration );

		if ( is_array( $duration ) ) {
			if ( ! isset( $duration['amount'], $duration['unit'] ) || ! is_numeric( $duration['amount'] ) ) {
				return 0;
			}
			$units = array(
				'sec'   => 1,
				'mim'   => MINUTE_IN_SECONDS, // sic, YITH's key for minutes.
				'min'   => MINUTE_IN_SECONDS,
				'hour'  => HOUR_IN_SECONDS,
				'day'   => DAY_IN_SECONDS,
				'week'  => WEEK_IN_SECONDS,
				'month' => MONTH_IN_SECONDS,
			);
			$unit  = isset( $units[ $duration['unit'] ] ) ? $units[ $duration['unit'] ] : MINUTE_IN_SECONDS;
			return (int) ( $unit * (float) $duration['amount'] );
		}

		return is_numeric( $duration ) ? (int) $duration : 0;
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {

		$var = trim( (string) get_option( 'yith_wcaf_referral_var_name', 'ref' ) );
		$var = '' !== $var ? sanitize_text_field( $var ) : 'ref';

		$cookie = trim( (string) get_option( 'yith_wcaf_referral_cookie_name', 'yith_wcaf_referral_token' ) );
		$cookie = '' !== $cookie ? sanitize_text_field( $cookie ) : 'yith_wcaf_referral_token';

		// The cookie holds the token, like the link. YITH has no path-style links.
		return array(
			'vars'    => array( $var ),
			'cookies' => array( $cookie ),
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
		global $wpdb;

		$rows = $this->batch( $this->affiliates_table(), 'ID', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$user_ids = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) );
		$users    = $this->users_for( $user_ids );

		// Every "_yith_wcaf_" key of these users, plus their WordPress names.
		$meta = array();
		if ( $user_ids ) {
			$sql = $wpdb->prepare(
				"SELECT user_id AS object_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN (" . implode( ',', $user_ids ) . ') AND ( meta_key LIKE %s OR meta_key IN ( %s, %s ) )', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( self::META_PREFIX ) . '%',
				'first_name',
				'last_name'
			);
			foreach ( $this->rows( $sql ) as $row ) {
				$meta[ (int) $row['object_id'] ][ $row['meta_key'] ] = $this->unserialize( $row['meta_value'] );
			}
		}

		$coupons = $this->coupons_by_meta( self::COUPON_META );
		$defs    = $this->profile_field_definitions();

		$records = array();
		foreach ( $rows as $row ) {

			$id      = (int) $row['ID'];
			$user_id = (int) $row['user_id'];
			$user    = isset( $users[ $user_id ] ) ? $users[ $user_id ] : array();
			$m       = isset( $meta[ $user_id ] ) ? $meta[ $user_id ] : array();
			$yith    = function ( $key ) use ( $m ) {
				return isset( $m[ self::META_PREFIX . $key ] ) ? $m[ self::META_PREFIX . $key ] : '';
			};

			// Status: banned sits on top of the request status. A banned affiliate
			// who had been accepted is imported suspended; a banned applicant was
			// never an affiliate, so is a declined application.
			$enabled = (int) $row['enabled'];
			if ( -1 === $enabled ) {
				$status = 'rejected';
			} elseif ( (int) $row['banned'] ) {
				$status = 1 === $enabled ? 'inactive' : 'rejected';
			} elseif ( 0 === $enabled ) {
				$status = 'pending';
			} else {
				$status = 'active';
			}

			// YITH only uses its own rate when it is set and not zero
			// (YITH_WCAF_Rate_Handler::get_rate()); otherwise the default applies.
			$rate = null;
			if ( null !== $row['rate'] && is_numeric( $row['rate'] ) && (float) $row['rate'] > 0 ) {
				$rate = (float) $row['rate'];
			}

			// Bank details from the "Direct bank/wire transfer" gateway. Coupon
			// Affiliates' bank fields are UK-style (account name, sort code, account
			// number): the IBAN is the international account number, so it goes in
			// "account", and the SWIFT/BIC code - like a sort code, it identifies
			// the bank - in "sort".
			$bank  = array();
			$bacs  = $yith( 'bacs_gateway_preferences' );
			if ( is_array( $bacs ) ) {
				$map = array(
					'bacs_name'  => 'name',
					'bacs_iban'  => 'account',
					'bacs_swift' => 'sort',
				);
				foreach ( $map as $from => $to ) {
					if ( isset( $bacs[ $from ] ) && is_scalar( $bacs[ $from ] ) && '' !== trim( (string) $bacs[ $from ] ) ) {
						$bank[ $to ] = trim( (string) $bacs[ $from ] );
					}
				}
			}

			// The PayPal email: the affiliate's payment email, or one saved with the
			// premium PayPal gateway's preferences.
			$payment_email = is_email( (string) $row['payment_email'] ) ? (string) $row['payment_email'] : '';
			if ( '' === $payment_email ) {
				$paypal = $yith( 'paypal_gateway_preferences' );
				foreach ( is_array( $paypal ) ? $paypal : array() as $value ) {
					if ( is_string( $value ) && is_email( $value ) ) {
						$payment_email = $value;
						break;
					}
				}
			}

			// YITH keeps no preferred method: bank details are only there if the
			// affiliate entered them (the free version pays by bank transfer only),
			// so they win; otherwise a PayPal email makes it PayPal (the default).
			$payout_method = ! empty( $bank['account'] ) || ! empty( $bank['name'] ) ? 'bank' : '';

			list( $fields, $website ) = $this->profile_fields( $defs, $m );

			$first = (string) $yith( 'first_name' );
			$last  = (string) $yith( 'last_name' );

			$application = $yith( 'application_date' );
			$date        = is_string( $application ) && '' !== $application ? $this->date( $application ) : '';
			if ( '' === $date && ! empty( $user['user_registered'] ) ) {
				$date = $this->date( $user['user_registered'], true );
			}

			$reason = $yith( 'reject_message' );
			if ( ! is_string( $reason ) || '' === trim( $reason ) ) {
				$reason = $yith( 'ban_message' );
			}

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $this->affiliate_source_id( $id ),
					'user_id'       => $user_id,
					'email'         => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => '' !== $first ? $first : ( isset( $m['first_name'] ) && is_string( $m['first_name'] ) ? $m['first_name'] : '' ),
					'last_name'     => '' !== $last ? $last : ( isset( $m['last_name'] ) && is_string( $m['last_name'] ) ? $m['last_name'] : '' ),
					'status'        => $status,
					'date'          => $date,
					'rate'          => $rate,
					'rate_type'     => null === $rate ? null : 'percent',
					'payment_email' => $payment_email,
					'payout_method' => $payout_method,
					'bank'          => $bank,
					'website'       => $website,
					'reject_reason' => is_string( $reason ) ? trim( $reason ) : '',
					'fields'        => $fields,
					'coupons'       => isset( $coupons[ (string) $id ] ) ? $coupons[ (string) $id ] : array(),
					// Only the token - the value YITH's links and cookie carry. Never the
					// affiliate ID: a numeric token (a user ID, by default) can equal
					// another affiliate's ID, and the old link must reach the token's owner.
					'identifiers'   => array( (string) $row['token'] ),
					// A number in a link was looked up as a token, so a numeric token is
					// what it meant.
					'link_id'       => ctype_digit( (string) $row['token'] ) ? (string) $row['token'] : '',
				)
			);
		}

		return $records;
	}

	/**
	 * The registration form fields, from the "yith_wcaf_affiliate_profile_fields"
	 * option (YITH_WCAF_Affiliates_Profile::maybe_read_profile_fields()).
	 *
	 * @return array field name => definition
	 */
	protected function profile_field_definitions() {
		$raw  = $this->unserialize( get_option( 'yith_wcaf_affiliate_profile_fields', array() ) );
		$defs = array();
		foreach ( is_array( $raw ) ? $raw : array() as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) && is_string( $field['name'] ) ) {
				$defs[ $field['name'] ] = $field;
			}
		}
		return $defs;
	}

	/**
	 * An affiliate's registration answers, labelled as on the YITH form.
	 *
	 * @param array $defs Field definitions.
	 * @param array $meta The affiliate's user meta.
	 * @return array array( label => value fields, website )
	 */
	protected function profile_fields( $defs, $meta ) {

		// WordPress account fields (no meta of their own), and the names, which
		// have record fields of their own.
		$skip = array( 'username', 'email', 'password', 'privacy_text', 'first_name', 'last_name' );

		$fields  = array();
		$website = '';

		foreach ( $defs as $name => $def ) {

			$type = isset( $def['type'] ) ? (string) $def['type'] : 'text';
			if ( in_array( $name, $skip, true ) || 'paragraph' === $type ) {
				continue;
			}

			$key = self::META_PREFIX . $name;
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}

			$value = $this->field_value( $def, $meta[ $key ] );
			if ( '' === $value ) {
				continue;
			}

			// A website field becomes the affiliate's website.
			$validation = isset( $def['validation'] ) ? (string) $def['validation'] : '';
			if ( '' === $website && ( 'website' === $name || 'url' === $validation ) && preg_match( '#^https?://#i', $value ) && false !== filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$website = $value;
				continue;
			}

			// The admin label where there is one: the terms checkbox's label is a
			// sentence with a link in it.
			$label = ! empty( $def['admin_label'] ) ? $def['admin_label'] : ( ! empty( $def['label'] ) ? $def['label'] : $name );
			$label = trim( wp_strip_all_tags( (string) $label ) );
			if ( '' === $label ) {
				$label = ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
			}

			$fields[ $label ] = $value;
		}

		return array( $fields, $website );
	}

	/**
	 * A registration answer as text: choices by their labels, multi-checkboxes
	 * flattened to a list, checkboxes as Yes / No.
	 *
	 * @param array $def
	 * @param mixed $value
	 * @return string
	 */
	protected function field_value( $def, $value ) {

		$type    = isset( $def['type'] ) ? (string) $def['type'] : 'text';
		$options = isset( $def['options'] ) && is_array( $def['options'] ) ? $def['options'] : array();

		switch ( $type ) {
			case 'checkbox':
				if ( '' === $value || null === $value ) {
					return '';
				}
				return function_exists( 'wc_string_to_bool' ) && wc_string_to_bool( $value ) || ( is_numeric( $value ) && (int) $value ) ? __( 'Yes', 'woo-coupon-usage' ) : __( 'No', 'woo-coupon-usage' );

			case 'multicheck':
				$labels = array();
				foreach ( (array) $value as $choice ) {
					if ( ! is_scalar( $choice ) || '' === (string) $choice ) {
						continue;
					}
					$labels[] = isset( $options[ $choice ] ) && is_scalar( $options[ $choice ] ) ? (string) $options[ $choice ] : (string) $choice;
				}
				return implode( ', ', $labels );

			case 'select':
			case 'radio':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				return isset( $options[ $value ] ) && is_scalar( $options[ $value ] ) ? (string) $options[ $value ] : (string) $value;

			case 'country':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
				return isset( $countries[ $value ] ) ? html_entity_decode( (string) $countries[ $value ], ENT_QUOTES, 'UTF-8' ) : (string) $value;

			default:
				if ( is_array( $value ) ) {
					return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
				}
				return is_scalar( $value ) ? trim( (string) $value ) : '';
		}
	}

	/* ---------------------------------------------------------------------
	 * Referrals
	 * ------------------------------------------------------------------ */

	/**
	 * A YITH commission status in the common form.
	 *
	 * YITH's "pending" is commission on a completed / processing order, owed
	 * to the affiliate; "not-confirmed" is commission on an order still pending
	 * or on hold; "pending-payment" is commission in a payment not completed yet,
	 * which is imported as paid (the payment becomes a pending payout).
	 *
	 * @param string $status
	 * @return string
	 */
	protected function referral_status( $status ) {
		switch ( (string) $status ) {
			case 'pending':
				return 'unpaid';
			case 'pending-payment':
			case 'paid':
				return 'paid';
			case 'not-confirmed':
				return 'pending';
			default:
				// cancelled, refunded, trash.
				return 'rejected';
		}
	}

	/**
	 * Turn line-item commissions into referrals: one per order and affiliate,
	 * or one per status when an order's commissions are in different states.
	 *
	 * The record that claims the order is named "o{order}-a{affiliate}"; any
	 * further status for the same order and affiliate is "o{order}-a{affiliate}-{status}".
	 *
	 * Which records an order makes changes as its line items move on (one is
	 * paid, another refunded), so every record carries "o{order}-a{affiliate}"
	 * as its group, split or not: a later run then skips the parts of a
	 * commission an earlier run imported in another shape.
	 *
	 * @param array[] $rows Commission rows: ID, order_id, affiliate_id, amount, status, created_at.
	 * @return array[] In order, each: record, order, affiliate, commission_ids.
	 */
	protected function build_referrals( $rows ) {

		$groups = array();
		foreach ( $rows as $row ) {
			$order     = (int) $row['order_id'];
			$affiliate = (int) $row['affiliate_id'];
			$status    = $this->referral_status( $row['status'] );
			$key       = $order . '-' . $affiliate;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'order'     => $order,
					'affiliate' => $affiliate,
					'parts'     => array(),
				);
			}
			if ( ! isset( $groups[ $key ]['parts'][ $status ] ) ) {
				$groups[ $key ]['parts'][ $status ] = array(
					'amount' => 0.0,
					'date'   => '',
					'ids'    => array(),
				);
			}

			$part           = &$groups[ $key ]['parts'][ $status ];
			$part['amount'] += (float) $row['amount'];
			$part['ids'][]   = (int) $row['ID'];
			$date            = $this->date( $row['created_at'] );
			if ( '' !== $date && ( '' === $part['date'] || $date < $part['date'] ) ) {
				$part['date'] = $date;
			}
			unset( $part );
		}

		usort(
			$groups,
			function ( $a, $b ) {
				if ( $a['order'] !== $b['order'] ) {
					return $a['order'] < $b['order'] ? -1 : 1;
				}
				if ( $a['affiliate'] !== $b['affiliate'] ) {
					return $a['affiliate'] < $b['affiliate'] ? -1 : 1;
				}
				return 0;
			}
		);

		$claim = self::$claim_order;
		$items = array();
		foreach ( $groups as $group ) {

			$parts = $group['parts'];
			uksort(
				$parts,
				function ( $a, $b ) use ( $claim ) {
					return $claim[ $a ] - $claim[ $b ];
				}
			);

			$group_id = 'o' . $group['order'] . '-a' . $group['affiliate'];
			$first    = true;
			foreach ( $parts as $status => $part ) {
				$source_id = $group_id . ( $first ? '' : '-' . $status );
				$first     = false;

				$items[] = array(
					'record'         => $this->referral_record(
						array(
							'source_id'           => $source_id,
							'affiliate_source_id' => $this->affiliate_source_id( $group['affiliate'] ),
							'order_id'            => $group['order'],
							'amount'              => $part['amount'],
							'status'              => $status,
							'date'                => $part['date'],
							'group_id'            => $group_id,
						)
					),
					'order'          => $group['order'],
					'affiliate'      => $group['affiliate'],
					'commission_ids' => $part['ids'],
				);
			}
		}

		return $items;
	}

	/**
	 * The order and affiliate a referral source ID stands for.
	 *
	 * @param string $source_id
	 * @return array|null array( 'order' => int, 'affiliate' => int )
	 */
	protected function parse_referral_id( $source_id ) {
		if ( ! preg_match( '/^o(\d+)-a(\d+)(?:-[a-z]+)?$/', (string) $source_id, $m ) ) {
			return null;
		}
		return array(
			'order'     => (int) $m[1],
			'affiliate' => (int) $m[2],
		);
	}

	/**
	 * The next referrals, in order ID order.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {
		global $wpdb;

		$table = $this->commissions_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit     = max( 1, (int) $limit );
		$cursor    = '' === (string) $after ? null : $this->parse_referral_id( $after );
		$passed    = null === $cursor;
		$from      = $cursor ? $cursor['order'] : 0;
		$inclusive = null !== $cursor;
		$records   = array();

		while ( count( $records ) < $limit ) {

			$order_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM `{$table}` WHERE order_id " . ( $inclusive ? '>=' : '>' ) . ' %d ORDER BY order_id ASC LIMIT %d', $from, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $order_ids ) {
				break;
			}

			$rows = $this->rows( "SELECT ID, order_id, affiliate_id, amount, status, created_at FROM `{$table}` WHERE order_id IN (" . implode( ',', $order_ids ) . ') ORDER BY order_id ASC, affiliate_id ASC, ID ASC' );

			foreach ( $this->build_referrals( $rows ) as $item ) {
				if ( ! $passed ) {
					// Past the cursor's order and affiliate. A batch never ends part-way
					// through a commission (below), so the last one handed all of the
					// cursor's commission out: none of it comes again, even split into
					// parts it did not have then (one line item paid since, say) - the
					// run marks the group imported only for later runs.
					if ( $item['order'] > $cursor['order'] || ( $item['order'] === $cursor['order'] && $item['affiliate'] > $cursor['affiliate'] ) ) {
						$passed = true;
					} else {
						continue;
					}
				}
				// A batch ends between commissions, never part-way through one split
				// by status: a later run treats a commission one of whose parts was
				// imported as imported (see the runner's "referral_group"), so parts
				// left for the next batch of a run stopped in between were lost.
				if ( count( $records ) >= $limit && end( $records )['group_id'] !== $item['record']['group_id'] ) {
					break 2;
				}
				$records[] = $item['record'];
			}

			if ( count( $order_ids ) < $limit ) {
				break;
			}
			$from      = (int) end( $order_ids );
			$inclusive = false;
		}

		return $records;
	}

	/**
	 * The referral each of a set of commissions was imported in.
	 *
	 * @param int[] $commission_ids
	 * @return array commission ID => referral source ID
	 */
	protected function referral_ids_for_commissions( $commission_ids ) {

		$table          = $this->commissions_table();
		$commission_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $commission_ids ) ) ) );
		if ( ! $commission_ids || ! $this->table_exists( $table ) ) {
			return array();
		}

		// The referrals are built from all of an order's commissions, so read the
		// whole of each order these commissions belong to.
		$order_ids = array();
		foreach ( $this->rows( "SELECT DISTINCT order_id FROM `{$table}` WHERE ID IN (" . implode( ',', $commission_ids ) . ')' ) as $row ) {
			$order_ids[] = (int) $row['order_id'];
		}
		if ( ! $order_ids ) {
			return array();
		}

		$rows = $this->rows( "SELECT ID, order_id, affiliate_id, amount, status, created_at FROM `{$table}` WHERE order_id IN (" . implode( ',', $order_ids ) . ') ORDER BY order_id ASC, affiliate_id ASC, ID ASC' );

		$map = array();
		foreach ( $this->build_referrals( $rows ) as $item ) {
			foreach ( $item['commission_ids'] as $commission_id ) {
				$map[ $commission_id ] = $item['record']['source_id'];
			}
		}
		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Payouts
	 * ------------------------------------------------------------------ */

	/**
	 * YITH's gateway ID in the common form.
	 *
	 * @param string $gateway
	 * @return string
	 */
	protected function payout_method( $gateway ) {
		$gateway = strtolower( (string) $gateway );
		if ( '' === $gateway ) {
			return '';
		}
		if ( 'bacs' === $gateway || false !== strpos( $gateway, 'bank' ) ) {
			return 'bank';
		}
		if ( false !== strpos( $gateway, 'paypal' ) || 'payouts' === $gateway ) {
			return 'paypal';
		}
		if ( 'funds' === $gateway || false !== strpos( $gateway, 'fund' ) || false !== strpos( $gateway, 'credit' ) ) {
			return 'credit';
		}
		if ( false !== strpos( $gateway, 'stripe' ) ) {
			return 'stripe';
		}
		return 'manual';
	}

	/**
	 * Payments. Completed ones are paid payouts; pending and on-hold ones are
	 * pending payouts (their commission is "pending-payment" in YITH, imported
	 * as paid, so nothing is owed twice); cancelled ones are skipped.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$rows = $this->batch( $this->payments_table(), 'ID', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		// The commissions each payment paid, and the referrals they were imported in.
		$by_payment = array();
		$link_table = $this->payment_commission_table();
		if ( $this->table_exists( $link_table ) ) {
			$ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
			foreach ( $this->rows( "SELECT payment_id, commission_id FROM `{$link_table}` WHERE payment_id IN (" . implode( ',', $ids ) . ') ORDER BY ID ASC' ) as $link ) {
				$by_payment[ (int) $link['payment_id'] ][] = (int) $link['commission_id'];
			}
		}
		$referral_ids = array();
		foreach ( $by_payment as $commission_ids ) {
			$referral_ids = $referral_ids + array_fill_keys( $commission_ids, '' );
		}
		$referral_ids = $this->referral_ids_for_commissions( array_keys( $referral_ids ) );

		$records = array();
		foreach ( $rows as $row ) {

			$id = (int) $row['ID'];

			switch ( (string) $row['status'] ) {
				case 'completed':
					$status = 'paid';
					break;
				case 'pending':
				case 'on-hold':
					$status = 'pending';
					break;
				default:
					$status = 'cancelled';
			}

			$referrals = array();
			foreach ( isset( $by_payment[ $id ] ) ? $by_payment[ $id ] : array() as $commission_id ) {
				if ( isset( $referral_ids[ $commission_id ] ) ) {
					$referrals[ $referral_ids[ $commission_id ] ] = true;
				}
			}

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => $this->affiliate_source_id( $row['affiliate_id'] ),
					'amount'              => $row['amount'],
					'status'              => $status,
					'method'              => $this->payout_method( $row['gateway'] ),
					'date'                => $this->date( $row['created_at'] ),
					'date_paid'           => 'paid' === $status ? $this->date( $row['completed_at'] ) : '',
					'transaction_id'      => (string) $row['transaction_key'],
					'referral_source_ids' => array_keys( $referrals ),
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

		// click_date is site time, as is $since.
		$where = $since ? $wpdb->prepare( 'click_date >= %s', $since . ' 00:00:00' ) : '';
		$rows  = $this->batch( $this->clicks_table(), 'ID', $after, $limit, $where );

		$records = array();
		foreach ( $rows as $row ) {
			$order_id  = (int) $row['order_id'];
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['ID'],
					'affiliate_source_id' => $this->affiliate_source_id( $row['affiliate_id'] ),
					'url'                 => (string) $row['link'],
					'referrer'            => (string) $row['origin'],
					'ip'                  => (string) $row['IP'],
					'date'                => $this->date( $row['click_date'] ),
					'order_id'            => $order_id,
					'converted'           => $order_id > 0,
				)
			);
		}

		return $records;
	}
}
