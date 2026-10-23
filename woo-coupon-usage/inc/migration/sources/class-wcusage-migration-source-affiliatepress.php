<?php
/**
 * Migration source: AffiliatePress (free).
 *
 * Read from AffiliatePress 3.5's own tables (AffiliatePress::install() in
 * core/classes/class.affiliatepress.php):
 *
 *   affiliatepress_affiliates   ap_affiliates_id, ap_affiliates_user_id, first / last name,
 *                               user name, user email, payment email, website,
 *                               ap_affiliates_status (1 approved | 2 pending | 3 rejected),
 *                               ap_affiliates_promote_us, ap_affiliates_note (admin note),
 *                               ap_affiliates_created_at (MySQL default: database time zone)
 *   affiliatepress_commissions  ap_commission_id (starts at 1000), ap_affiliates_id, ap_visit_id,
 *                               ap_commission_status (1 approved = unpaid | 2 pending | 3 rejected | 4 paid),
 *                               ap_commission_reference_id (the order ID) + ap_commission_source
 *                               ('woocommerce' for WooCommerce), ap_commission_amount (FLOAT: read
 *                               as a DECIMAL, as MySQL (8.0 too) and MariaDB print a FLOAT to six
 *                               significant digits), ap_commission_created_date (site time).
 *                               Amounts are worked out from the order's totals, so they are in
 *                               the order's currency (unless the Multi-Currency add-on converted
 *                               them); ap_commission_currency is only the default currency's code.
 *                               Refunds never change them: the integration only approves an
 *                               order's commission or reopens it.
 *   affiliatepress_payouts      one row per payout run (not needed here)
 *   affiliatepress_payments     one row per affiliate per payout: ap_payment_amount, ap_payment_method
 *                               (manual | paypal | ...), ap_payment_status (1 unpaid | 2 pending |
 *                               3 failed | 4 paid | 5 carried forward), ap_payment_created_date. A
 *                               payment by any method but "manual" starts as pending, its
 *                               commissions still approved, until the method confirms it.
 *   affiliatepress_payment_commission  which commissions each payment covered
 *   affiliatepress_affiliate_visits    ap_visit_id, ap_affiliates_id, landing URL, referrer, IP,
 *                               campaign name, ap_commission_id (> 0 once it converted)
 *   affiliatepress_creative     ap_creative_type (image | text_link), ap_creative_image_url (a file
 *                               name in uploads/affiliatepress-affiliate-marketing/), ap_creative_status
 *   affiliatepress_settings     ap_setting_name / ap_setting_value / ap_setting_type, booleans
 *                               stored as the strings 'true' and 'false'
 *
 * AffiliatePress writes no order or coupon meta: commissions reach orders only
 * through reference ID + source 'woocommerce'. Free AffiliatePress has no
 * coupon tracking at all; its referral links are ?afref={id} where the ID is,
 * by default, the affiliate ID encoded as 'a' . base36(ID) . '9'. Products
 * with commission switched off carry "affiliatepress_disable_commissions_woocommerce".
 *
 * Only the free plugin's storage is read. AffiliatePress Pro and its add-ons
 * (Coupon Code, Groups, Multi-Level, Lifetime Commission, Product-wise
 * Commission, custom registration fields...) keep their data in places that are
 * not in the free code: none of it is migrated (see get_not_migrated()).
 *
 * Uninstalling (deleting) AffiliatePress drops every one of its tables and
 * options, so the migration has to run before the plugin is deleted.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_AffiliatePress extends WCUsage_Migration_Source {

	/**
	 * Settings rows, "type|name" => value and "name" => value.
	 *
	 * @var array|null
	 */
	protected $settings = null;

	/**
	 * The database session's offset from UTC in seconds (see db_date()).
	 *
	 * @var int|null
	 */
	protected $db_offset = null;

	/**
	 * An AffiliatePress table name with the site prefix.
	 *
	 * @param string $name Without "affiliatepress_".
	 * @return string
	 */
	protected function ap( $name ) {
		return $this->table( 'affiliatepress_' . $name );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * One AffiliatePress setting.
	 *
	 * AffiliatePress keeps a cached copy of some settings in the
	 * "affiliatepress_auto_load_settings" option, but the settings table is
	 * what it is built from, so that is what is read.
	 *
	 * @param string $name
	 * @param string $type    ap_setting_type, e.g. 'affiliate_settings'.
	 * @param mixed  $default Returned when the setting is not there.
	 * @return mixed
	 */
	protected function setting( $name, $type = '', $default = null ) {

		if ( null === $this->settings ) {
			$this->settings = array();
			$table          = $this->ap( 'settings' );
			if ( $this->table_exists( $table ) ) {
				foreach ( $this->rows( "SELECT ap_setting_name, ap_setting_value, ap_setting_type FROM `{$table}` ORDER BY ap_setting_id ASC" ) as $row ) {
					$this->settings[ $row['ap_setting_type'] . '|' . $row['ap_setting_name'] ] = $row['ap_setting_value'];
					if ( ! array_key_exists( $row['ap_setting_name'], $this->settings ) ) {
						$this->settings[ $row['ap_setting_name'] ] = $row['ap_setting_value'];
					}
				}
			}
		}

		if ( $type && array_key_exists( $type . '|' . $name, $this->settings ) ) {
			return $this->settings[ $type . '|' . $name ];
		}
		return array_key_exists( $name, $this->settings ) ? $this->settings[ $name ] : $default;
	}

	/**
	 * A rate type ('percentage' / 'fixed') in the common form. A fixed rate is
	 * per order or per product depending on AffiliatePress's commission basis.
	 *
	 * @param string $type
	 * @return string|null Null for "use the default" or an unknown type.
	 */
	protected function rate_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		if ( in_array( $type, array( 'percentage', 'percent', '%' ), true ) ) {
			return 'percent';
		}
		if ( in_array( $type, array( 'fixed', 'fixed_rate', 'fixed_amount', 'flat', 'amount' ), true ) ) {
			return 'pre_order' === $this->setting( 'flat_rate_commission_basis', 'commissions_settings', 'pre_product' ) ? 'flat_order' : 'flat_product';
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * What the source can provide
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {
		return array( 'affiliates', 'legacy_links', 'registrations', 'referrals', 'payouts', 'payout_details', 'visits', 'creatives', 'settings', 'product_rates', 'campaigns' );
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		return array(
			__( 'Coupons assigned to affiliates with the AffiliatePress Pro Coupon Code add-on (each affiliate gets a new coupon)', 'woo-coupon-usage' ),
			__( 'Affiliate groups (AffiliatePress Pro)', 'woo-coupon-usage' ),
			__( 'Commission rates set on individual affiliates or products (AffiliatePress Pro)', 'woo-coupon-usage' ),
			__( 'Multi-level affiliate parents (AffiliatePress Pro)', 'woo-coupon-usage' ),
			__( 'Lifetime customer links (AffiliatePress Pro)', 'woo-coupon-usage' ),
			__( 'Custom registration form fields and their answers', 'woo-coupon-usage' ),
			__( 'Custom links affiliates saved in their dashboard (the links themselves still work)', 'woo-coupon-usage' ),
			__( 'Other program settings (only the default rate, cookie length and click attribution are copied)', 'woo-coupon-usage' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		$warnings = array();

		$warnings[] = __( 'Deleting AffiliatePress removes every one of its tables and settings from the database, with no way to keep them. Migrate before deleting it - deactivating it is fine.', 'woo-coupon-usage' );

		$commissions = $this->ap( 'commissions' );
		$pending     = $this->count_rows( $commissions, 'ap_commission_status = 2' );
		if ( $pending ) {
			$warnings[] = sprintf(
				/* translators: %d: number of commissions */
				_n( '%d AffiliatePress commission is still waiting for approval. Coupon Affiliates has no approval step: by default it is imported as owed once its order is complete (see the source-specific options).', '%d AffiliatePress commissions are still waiting for approval. Coupon Affiliates has no approval step: by default they are imported as owed once their order is complete (see the source-specific options).', $pending, 'woo-coupon-usage' ),
				$pending
			);
		}

		$sending = $this->count_rows( $this->ap( 'payments' ), 'ap_payment_status = 2' );
		if ( $sending ) {
			$warnings[] = sprintf(
				/* translators: %d: number of payments */
				_n( '%d AffiliatePress payment is still pending (its payment method has not confirmed it). It is imported as a pending payout, and its commission as paid, so it is not paid out twice.', '%d AffiliatePress payments are still pending (their payment method has not confirmed them). They are imported as pending payouts, and their commission as paid, so it is not paid out twice.', $sending, 'woo-coupon-usage' ),
				$sending
			);
		}

		// Commissions added by hand have no source: they are balance-only anyway.
		$other = $this->count_rows( $commissions, "ap_commission_source NOT IN ('woocommerce', '') AND ap_commission_status IN (1,4)" );
		if ( $other ) {
			$warnings[] = sprintf(
				/* translators: %d: number of commissions */
				_n( '%d commission came from another AffiliatePress integration (not WooCommerce). It can only be carried over as part of the affiliate\'s balance.', '%d commissions came from other AffiliatePress integrations (not WooCommerce). They can only be carried over as part of the affiliates\' balances.', $other, 'woo-coupon-usage' ),
				$other
			);
		}

		// Commission is worked out from the order's totals, in the order's currency.
		$currencies = $this->other_currency_commissions();
		if ( $currencies ) {
			$count      = array_sum( $currencies );
			$warnings[] = sprintf(
				/* translators: 1: number of commissions, 2: list of currency codes, 3: store currency */
				_n( '%1$d commission is on an order paid in another currency (%2$s) than the store\'s (%3$s). AffiliatePress works commission out in the order\'s currency, unless its Multi-Currency add-on converted it: the amount is imported unconverted.', '%1$d commissions are on orders paid in other currencies (%2$s) than the store\'s (%3$s). AffiliatePress works commission out in the order\'s currency, unless its Multi-Currency add-on converted it: the amounts are imported unconverted.', $count, 'woo-coupon-usage' ),
				$count,
				implode( ', ', array_keys( $currencies ) ),
				$this->store_currency()
			);
		}

		// AffiliatePress never takes a refund off a commission.
		$refunded = $this->partly_refunded_orders();
		if ( $refunded ) {
			$warnings[] = sprintf(
				/* translators: 1: number of orders, 2: list of order numbers */
				_n( '%1$d order with approved or pending commission was partly refunded (%2$s). AffiliatePress never takes refunds off commission, so its commission is the full amount from before the refund, and is imported as it is: check it and adjust it if needed.', '%1$d orders with approved or pending commission were partly refunded (%2$s). AffiliatePress never takes refunds off commission, so their commission is the full amount from before the refund, and is imported as it is: check them and adjust them if needed.', count( $refunded ), 'woo-coupon-usage' ),
				count( $refunded ),
				'#' . implode( ', #', array_slice( $refunded, 0, 10 ) ) . ( count( $refunded ) > 10 ? ', ...' : '' )
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
	 * Whether WooCommerce keeps orders in its own tables (HPOS).
	 *
	 * @return bool
	 */
	protected function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * WooCommerce commissions on orders paid in a currency other than the store's.
	 *
	 * @return array currency => number of commissions
	 */
	protected function other_currency_commissions() {
		global $wpdb;

		$table = $this->ap( 'commissions' );
		$store = $this->store_currency();
		if ( '' === $store || ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( $this->hpos() ) {
			$sql = "SELECT o.currency AS currency, COUNT(*) AS total FROM `{$table}` c INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = c.ap_commission_reference_id
				WHERE c.ap_commission_source = 'woocommerce' AND o.currency <> '' AND o.currency <> %s GROUP BY o.currency ORDER BY o.currency ASC";
		} else {
			$sql = "SELECT m.meta_value AS currency, COUNT(*) AS total FROM `{$table}` c INNER JOIN {$wpdb->postmeta} m ON m.post_id = c.ap_commission_reference_id AND m.meta_key = '_order_currency'
				WHERE c.ap_commission_source = 'woocommerce' AND m.meta_value <> '' AND m.meta_value <> %s GROUP BY m.meta_value ORDER BY m.meta_value ASC";
		}

		$currencies = array();
		foreach ( $this->rows( $wpdb->prepare( $sql, $store ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$currencies[ strtoupper( (string) $row['currency'] ) ] = (int) $row['total'];
		}
		return $currencies;
	}

	/**
	 * Orders with approved or pending WooCommerce commission that were refunded
	 * in part (refunded, but not marked refunded).
	 *
	 * @return int[] Order IDs, lowest first.
	 */
	protected function partly_refunded_orders() {
		global $wpdb;

		$table = $this->ap( 'commissions' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( $this->hpos() ) {
			$sql = "SELECT DISTINCT o.id FROM `{$table}` c
				INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = c.ap_commission_reference_id AND o.type = 'shop_order' AND o.status <> 'wc-refunded'
				INNER JOIN {$wpdb->prefix}wc_orders r ON r.parent_order_id = o.id AND r.type = 'shop_order_refund'
				WHERE c.ap_commission_source = 'woocommerce' AND c.ap_commission_status IN (1,2) ORDER BY o.id ASC";
		} else {
			$sql = "SELECT DISTINCT o.ID FROM `{$table}` c
				INNER JOIN {$wpdb->posts} o ON o.ID = c.ap_commission_reference_id AND o.post_type = 'shop_order' AND o.post_status <> 'wc-refunded'
				INNER JOIN {$wpdb->posts} r ON r.post_parent = o.ID AND r.post_type = 'shop_order_refund'
				WHERE c.ap_commission_source = 'woocommerce' AND c.ap_commission_status IN (1,2) ORDER BY o.ID ASC";
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		return array(
			'affiliates' => $this->count_rows( $this->ap( 'affiliates' ) ),
			'referrals'  => $this->count_rows( $this->ap( 'commissions' ) ),
			'payouts'    => $this->count_rows( $this->ap( 'payments' ), 'ap_payment_status IN (2,4)' ),
			'visits'     => $this->count_rows( $this->ap( 'affiliate_visits' ) ),
			'creatives'  => $this->count_rows( $this->ap( 'creative' ) ),
		);
	}

	/**
	 * @return array
	 */
	public function get_source_options() {
		return array(
			'affiliatepress_pending' => array(
				'type'     => 'select',
				'section'  => 'referrals',
				'label'    => __( 'AffiliatePress commissions still waiting for approval', 'woo-coupon-usage' ),
				'desc'     => __( 'Free AffiliatePress never approves commission by itself, so on many stores most commissions are still "Pending".', 'woo-coupon-usage' ),
				'default'  => 'import',
				'choices'  => array(
					'import' => __( 'Import them: owed once the order is complete', 'woo-coupon-usage' ),
					'skip'   => __( 'Don\'t import them', 'woo-coupon-usage' ),
				),
				'requires' => 'referrals',
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_settings() {

		$out  = array();
		$rate = $this->setting( 'default_discount_val', 'commissions_settings' );

		if ( null !== $rate && is_numeric( $rate ) ) {
			$type             = $this->rate_type( (string) $this->setting( 'default_discount_type', 'commissions_settings', 'percentage' ) );
			$out['rate']      = (float) $rate;
			$out['rate_type'] = $type ? $type : 'percent';
		}

		$days = $this->setting( 'tracking_cookie_days', 'affiliate_settings' );
		if ( null !== $days && is_numeric( $days ) ) {
			// 0 means "as long as possible", which AffiliatePress caps at 180 days.
			$out['cookie_days'] = (int) $days > 0 ? (int) $days : 180;
		}

		// The referral cookie is only set when there is none yet: the first affiliate wins.
		$out['credit_first'] = true;

		return $out;
	}

	/**
	 * The referral parameter: "afref" unless it was changed.
	 *
	 * @return string
	 */
	protected function url_parameter() {
		$var = trim( (string) $this->setting( 'affiliate_url_parameter', 'affiliate_settings', '' ) );
		return '' !== $var ? $var : 'afref';
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {
		$var = $this->url_parameter();
		return array(
			'vars'     => array( $var ),
			// "Fancy" links: /afref/{id}/ appended to any page - always, as links
			// may have been shared while the setting was on.
			'paths'    => array( $var ),
			'campaign' => 'campaign',
			// Holds the decoded affiliate ID, whatever the link format.
			'cookies'  => array( 'affiliatepress_ref_cookie' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Affiliates
	 * ------------------------------------------------------------------ */

	/**
	 * The ID as AffiliatePress's default link format encodes it:
	 * 'a' . base36(ID) . '9' (AffiliatePress::affiliatepress_encode_affiliate_id()).
	 *
	 * @param int $id
	 * @return string
	 */
	public static function encode_id( $id ) {
		return 'a' . base_convert( (string) (int) $id, 10, 36 ) . '9';
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$rows = $this->batch( $this->ap( 'affiliates' ), 'ap_affiliates_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$user_ids = array_map( 'intval', wp_list_pluck( $rows, 'ap_affiliates_user_id' ) );
		$users    = $this->users_for( $user_ids );
		$umeta    = $this->user_meta_for( $user_ids, array( 'first_name', 'last_name' ) );
		$taken    = $this->ids_taken_by_numeric_logins( $users );

		$records = array();
		foreach ( $rows as $row ) {

			$id    = (int) $row['ap_affiliates_id'];
			$uid   = (int) $row['ap_affiliates_user_id'];
			$user  = isset( $users[ $uid ] ) ? $users[ $uid ] : array();
			$names = isset( $umeta[ $uid ] ) ? $umeta[ $uid ] : array();

			switch ( (int) $row['ap_affiliates_status'] ) {
				case 1:
					$status = 'active';
					break;
				case 2:
					$status = 'pending';
					break;
				case 3:
					$status = 'rejected';
					break;
				default:
					$status = 'inactive';
			}

			$username = isset( $user['user_login'] ) ? (string) $user['user_login'] : (string) $row['ap_affiliates_user_name'];

			// Every value AffiliatePress's links can carry, whichever link format it
			// was set to: the WordPress username first (it reads best as a coupon
			// code), the plain ID (also what its cookie holds), the encoded ID (the
			// default format) and the MD5 of the ID. A number in its cookie or an
			// ID link is an affiliate ID, so a numeric username is left out...
			$numeric_login = isset( $user['user_login'] ) && ctype_digit( $username );
			$identifiers   = array( is_numeric( $username ) ? '' : $username, (string) $id, self::encode_id( $id ), md5( (string) $id ) );
			$link_id       = (string) $id;
			// ...unless links carry usernames, which AffiliatePress looks up as a
			// login before taking the value for an ID: then the number in the link
			// meant this affiliate - as long as no affiliate has it as their ID,
			// whose cookie (which holds IDs) would be taken over.
			if ( $numeric_login && $this->username_links() && ! isset( $taken[ (int) $username ] ) ) {
				$identifiers[0] = $username;
				// No single meaning for a number any more: both are recorded.
				$link_id = '';
			}

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => $uid,
					'email'         => ! empty( $user['user_email'] ) ? (string) $user['user_email'] : (string) $row['ap_affiliates_user_email'],
					'username'      => $username,
					'first_name'    => '' !== (string) $row['ap_affiliates_first_name'] ? (string) $row['ap_affiliates_first_name'] : ( isset( $names['first_name'] ) ? (string) $names['first_name'] : '' ),
					'last_name'     => '' !== (string) $row['ap_affiliates_last_name'] ? (string) $row['ap_affiliates_last_name'] : ( isset( $names['last_name'] ) ? (string) $names['last_name'] : '' ),
					'status'        => $status,
					'date'          => $this->db_date( $row['ap_affiliates_created_at'] ),
					'payment_email' => (string) $row['ap_affiliates_payment_email'],
					'website'       => (string) $row['ap_affiliates_website'],
					'promote'       => (string) $row['ap_affiliates_promote_us'],
					// The admin's note: shown with a declined application. It is not
					// stored with the other answers, which affiliates can see.
					'reject_reason' => 'rejected' === $status && isset( $row['ap_affiliates_note'] ) ? (string) $row['ap_affiliates_note'] : '',
					'identifiers'   => $identifiers,
					'link_id'       => $link_id,
				)
			);
		}

		return $records;
	}

	/**
	 * Whether AffiliatePress is set to put usernames in its links
	 * (AffiliatePress::affiliatepress_decode_affiliate_id()).
	 *
	 * @return bool
	 */
	protected function username_links() {
		return 'username' === (string) $this->setting( 'default_url_type', 'affiliate_settings', 'affiliate_default_url' );
	}

	/**
	 * Which of a batch of users' numeric logins are also an affiliate's ID.
	 *
	 * @param array $users user ID => user row
	 * @return array affiliate ID => true
	 */
	protected function ids_taken_by_numeric_logins( $users ) {

		$numbers = array();
		foreach ( $users as $user ) {
			if ( ctype_digit( (string) $user['user_login'] ) && (int) $user['user_login'] > 0 ) {
				$numbers[] = (int) $user['user_login'];
			}
		}
		if ( ! $numbers || ! $this->username_links() ) {
			return array();
		}

		$taken = array();
		foreach ( $this->rows( 'SELECT ap_affiliates_id FROM `' . $this->ap( 'affiliates' ) . '` WHERE ap_affiliates_id IN (' . implode( ',', array_unique( $numbers ) ) . ')' ) as $row ) {
			$taken[ (int) $row['ap_affiliates_id'] ] = true;
		}
		return $taken;
	}

	/**
	 * A date AffiliatePress left to MySQL's CURRENT_TIMESTAMP default (its
	 * created-at columns), in site time. MySQL writes those in the database
	 * session's time zone, which WordPress never sets - so it is the server's,
	 * not the site's.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function db_date( $value ) {
		global $wpdb;

		if ( empty( $value ) || 0 === strpos( (string) $value, '0000-00-00' ) ) {
			return '';
		}
		if ( null === $this->db_offset ) {
			$this->db_offset = (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' );
		}

		$time = strtotime( (string) $value . ' UTC' );

		return $time ? $this->date( $time - $this->db_offset ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Referrals and payouts
	 * ------------------------------------------------------------------ */

	/**
	 * The next batch of a table in key order, with FLOAT columns also read as
	 * DECIMAL ("{column}_exact"): MySQL (8.0 too) and MariaDB print a FLOAT to six
	 * significant digits, so 12345.67 would come back as 12345.7.
	 *
	 * @param string   $table
	 * @param string   $key     Numeric primary key column.
	 * @param string   $after
	 * @param int      $limit
	 * @param string[] $floats  FLOAT columns.
	 * @return array[]
	 */
	protected function exact_batch( $table, $key, $after, $limit, $floats ) {
		global $wpdb;

		if ( ! $this->has_column( $table, $key ) ) {
			return array();
		}

		$select = '*';
		foreach ( $floats as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				$select .= ", CAST(`{$column}` AS DECIMAL(20,6)) AS `{$column}_exact`";
			}
		}
		$where = '' !== (string) $after ? $wpdb->prepare( " WHERE `{$key}` > %d", (int) $after ) : ''; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->rows( "SELECT {$select} FROM `{$table}`{$where} ORDER BY `{$key}` ASC LIMIT " . max( 1, (int) $limit ) );
	}

	/**
	 * A FLOAT column's value, read exactly where exact_batch() could.
	 *
	 * @param array  $row
	 * @param string $column
	 * @return mixed
	 */
	protected function exact( $row, $column ) {
		return isset( $row[ $column . '_exact' ] ) ? $row[ $column . '_exact' ] : ( isset( $row[ $column ] ) ? $row[ $column ] : 0 );
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->ap( 'commissions' );
		$rows  = $this->exact_batch( $table, 'ap_commission_id', $after, $limit, array( 'ap_commission_amount' ) );
		if ( ! $rows ) {
			return array();
		}

		$payments = $this->payments_for( array_map( 'intval', wp_list_pluck( $rows, 'ap_commission_id' ) ) );
		$skip     = 'skip' === $this->option( 'affiliatepress_pending', 'import' );

		$records = array();
		foreach ( $rows as $row ) {

			$id        = (int) $row['ap_commission_id'];
			$reference = trim( (string) $row['ap_commission_reference_id'] );
			$is_woo    = 'woocommerce' === (string) $row['ap_commission_source'] && ctype_digit( $reference ) && (int) $reference > 0;
			$payment   = isset( $payments[ $id ] ) ? $payments[ $id ] : null;

			switch ( (int) $row['ap_commission_status'] ) {
				case 1:
					// Still approved while its payment is pending: paid here, with
					// the payment imported as a pending payout, so it is not owed twice.
					$status = $payment && 2 === $payment['status'] ? 'paid' : 'unpaid';
					break;
				case 2:
					$status = $skip ? 'rejected' : 'pending';
					break;
				case 4:
					$status = 'paid';
					break;
				default:
					$status = 'rejected';
			}

			$records[] = $this->referral_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['ap_affiliates_id'],
					'order_id'            => $is_woo ? (int) $reference : 0,
					'amount'              => $this->exact( $row, 'ap_commission_amount' ),
					'status'              => $status,
					'date'                => $this->date( $row['ap_commission_created_date'] ),
					'payout_source_id'    => $payment && 'paid' === $status ? (string) $payment['id'] : '',
				)
			);
		}

		return $records;
	}

	/**
	 * The paid or pending payment each of a batch of commissions is in (a paid
	 * one first).
	 *
	 * @param int[] $commission_ids
	 * @return array commission ID => array( 'id' => payment ID, 'status' => 4|2 )
	 */
	protected function payments_for( $commission_ids ) {
		$links    = $this->ap( 'payment_commission' );
		$payments = $this->ap( 'payments' );
		if ( ! $commission_ids || ! $this->table_exists( $links ) || ! $this->table_exists( $payments ) ) {
			return array();
		}
		$map = array();
		$sql = "SELECT pc.ap_commission_id, pc.ap_payment_id, p.ap_payment_status FROM `{$links}` pc
			INNER JOIN `{$payments}` p ON p.ap_payment_id = pc.ap_payment_id AND p.ap_payment_status IN (2,4)
			WHERE pc.ap_commission_id IN (" . implode( ',', array_map( 'intval', $commission_ids ) ) . ')
			ORDER BY p.ap_payment_status DESC, pc.ap_payment_id ASC';
		foreach ( $this->rows( $sql ) as $row ) {
			if ( ! isset( $map[ (int) $row['ap_commission_id'] ] ) ) {
				$map[ (int) $row['ap_commission_id'] ] = array(
					'id'     => (int) $row['ap_payment_id'],
					'status' => (int) $row['ap_payment_status'],
				);
			}
		}
		return $map;
	}

	/**
	 * Payments (one per affiliate per payout). Paid ones are paid payouts, and
	 * pending ones - a method other than "manual" still sending the money -
	 * pending payouts, their commissions imported as paid (see get_referrals()).
	 * An unpaid, failed or carried-forward payment's commissions are still
	 * "approved" (unpaid) in AffiliatePress - a later payout picks them up
	 * again - so they are migrated as unpaid commission instead.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$rows = $this->exact_batch( $this->ap( 'payments' ), 'ap_payment_id', $after, $limit, array( 'ap_payment_amount' ) );
		if ( ! $rows ) {
			return array();
		}

		$commissions = array();
		$links       = $this->ap( 'payment_commission' );
		if ( $this->table_exists( $links ) ) {
			$ids = array_map( 'intval', wp_list_pluck( $rows, 'ap_payment_id' ) );
			foreach ( $this->rows( "SELECT ap_payment_id, ap_commission_id FROM `{$links}` WHERE ap_payment_id IN (" . implode( ',', $ids ) . ') ORDER BY ap_payment_commission_id ASC' ) as $row ) {
				$commissions[ (int) $row['ap_payment_id'] ][] = (string) (int) $row['ap_commission_id'];
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row['ap_payment_id'];
			$created = $this->date( $row['ap_payment_created_date'] );
			$updated = isset( $row['ap_payment_updated_date'] ) ? $this->date( $row['ap_payment_updated_date'] ) : '';

			switch ( (int) $row['ap_payment_status'] ) {
				case 4:
					$status = 'paid';
					break;
				case 2:
					$status = 'pending';
					break;
				default:
					$status = 'cancelled';
			}

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['ap_affiliates_id'],
					'amount'              => $this->exact( $row, 'ap_payment_amount' ),
					'status'              => $status,
					'method'              => $this->payout_method( $row['ap_payment_method'] ),
					'date'                => $created,
					'date_paid'           => 'paid' === $status ? ( $updated ? $updated : $created ) : '',
					'transaction_id'      => (string) $row['ap_payment_transaction_id'],
					'referral_source_ids' => isset( $commissions[ $id ] ) ? $commissions[ $id ] : array(),
				)
			);
		}

		return $records;
	}

	/**
	 * AffiliatePress's payment method slug in the common form.
	 *
	 * @param mixed $method
	 * @return string
	 */
	protected function payout_method( $method ) {
		$method = is_string( $method ) ? strtolower( $method ) : '';
		if ( false !== strpos( $method, 'paypal' ) ) {
			return 'paypal';
		}
		if ( false !== strpos( $method, 'stripe' ) ) {
			return 'stripe';
		}
		if ( false !== strpos( $method, 'bank' ) ) {
			return 'bank';
		}
		if ( false !== strpos( $method, 'credit' ) ) {
			return 'credit';
		}
		return '' !== $method ? 'manual' : '';
	}

	/* ---------------------------------------------------------------------
	 * Visits, creatives, products
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		// Visit dates are site time.
		$where = $since ? $wpdb->prepare( 'ap_visit_created_date >= %s', $since . ' 00:00:00' ) : '';
		$rows  = $this->batch( $this->ap( 'affiliate_visits' ), 'ap_visit_id', $after, $limit, $where );

		// The orders the visits converted into, through their commission.
		$orders = array();
		$cids   = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'ap_commission_id' ) ) );
		if ( $cids ) {
			$sql = 'SELECT ap_commission_id, ap_commission_reference_id, ap_commission_source FROM `' . $this->ap( 'commissions' ) . '` WHERE ap_commission_id IN (' . implode( ',', $cids ) . ')';
			foreach ( $this->rows( $sql ) as $c ) {
				$reference = trim( (string) $c['ap_commission_reference_id'] );
				if ( 'woocommerce' === $c['ap_commission_source'] && ctype_digit( $reference ) ) {
					$orders[ (int) $c['ap_commission_id'] ] = (int) $reference;
				}
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['ap_visit_id'],
					'affiliate_source_id' => (int) $row['ap_affiliates_id'],
					'url'                 => (string) $row['ap_visit_landing_url'],
					'referrer'            => (string) $row['ap_referrer_url'],
					'ip'                  => (string) $row['ap_visit_ip_address'],
					'campaign'            => (string) $row['ap_affiliates_campaign_name'],
					'date'                => $this->date( $row['ap_visit_created_date'] ),
					'order_id'            => isset( $orders[ (int) $row['ap_commission_id'] ] ) ? $orders[ (int) $row['ap_commission_id'] ] : 0,
					'converted'           => (int) $row['ap_commission_id'] > 0,
				)
			);
		}

		return $records;
	}

	/**
	 * @return array[]
	 */
	public function get_creatives() {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['baseurl'] ) . 'affiliatepress-affiliate-marketing/';
		$rows    = $this->batch( $this->ap( 'creative' ), 'ap_creative_id', '', 10000 );
		$records = array();
		foreach ( $rows as $row ) {
			$image = trim( (string) $row['ap_creative_image_url'] );
			// Uploaded images are stored as a bare file name.
			if ( '' !== $image && ! preg_match( '#^(https?:)?//#i', $image ) ) {
				$image = $base . ltrim( basename( $image ), '/' );
			}
			$records[] = array(
				'source_id'   => (string) (int) $row['ap_creative_id'],
				'name'        => (string) $row['ap_creative_name'],
				'description' => (string) $row['ap_creative_description'],
				'type'        => 'image' === $row['ap_creative_type'] ? 'image' : 'text',
				'image'       => 'image' === $row['ap_creative_type'] ? $image : '',
				'url'         => (string) $row['ap_creative_landing_url'],
				'text'        => (string) $row['ap_creative_text'],
				'alt'         => (string) $row['ap_creative_alt_text'],
				'status'      => 1 === (int) $row['ap_creative_status'] ? 'active' : 'inactive',
			);
		}
		return $records;
	}

	/**
	 * Products with commission switched off (AffiliatePress's product tab).
	 *
	 * @return array
	 */
	public function get_product_rates() {
		global $wpdb;

		return array(
			'products'   => array(),
			'categories' => array(),
			'excluded'   => array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'affiliatepress_disable_commissions_woocommerce' AND meta_value NOT IN ('', '0')" ) ) ) ),
		);
	}
}
