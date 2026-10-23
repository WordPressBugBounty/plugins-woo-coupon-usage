<?php
/**
 * Migration source: Affiliate For WooCommerce (StoreApps, sold on woocommerce.com).
 *
 * Read from the plugin's own storage, as created by its 8.17 code
 * (includes/class-afwc-install.php, class-afwc-db-upgrade.php):
 *
 *   afwc_referrals       referral_id, affiliate_id (= WP user ID), post_id (= order ID,
 *                        HPOS or posts), datetime (UTC), amount decimal(18,2) in the
 *                        order's currency, currency_id, status (draft|unpaid|paid|rejected;
 *                        "pending" is only the column's default, never set or paid by the
 *                        plugin - its itthinx importer turns that plugin's pending into unpaid), type
 *                        (link|coupon, more "mediums" in 9.x), reference, campaign_id,
 *                        order_status ("wc-" prefixed), hit_id
 *   afwc_hits            id, affiliate_id, datetime (UTC), ip, user_id (the visitor),
 *                        count, type (link|coupon), campaign_id, user_agent, url
 *   afwc_payouts         payout_id, affiliate_id, datetime (UTC), amount, currency,
 *                        payout_notes, payment_gateway (paypal|paypal-manual|stripe|other),
 *                        receiver
 *   afwc_payout_orders   payout_id, post_id, amount - the orders a payout paid
 *   afwc_campaigns       id, title, slug (the utm_campaign value), body (HTML)...
 *   afwc_commission_plans id, name, rules (JSON), amount, type (Flat|Percentage), status...
 *                        - only the storewide plan's rate is read (see storewide_plan())
 *
 * Affiliates are WordPress users: user meta "afwc_is_affiliate" is yes / pending /
 * no, and a user with no such meta whose role is in the "affiliate_users_roles"
 * option counts as approved. "no" is both a rejected applicant and an approved
 * affiliate switched off later (class-afwc-admin-affiliate.php); only the second
 * has an afwc_signup_date, set on approval, or referrals. Referrals are only
 * recorded for approved affiliates, so "pending" with either, or no status meta
 * at all ("not registered") with referrals, is an affiliate switched off too.
 * The affiliate ID is the
 * user ID. Other user meta: afwc_ref_url_id (custom link identifier - a numeric one
 * is read as a user ID instead), afwc_signup_date (UTC), afwc_paypal_email,
 * afwc_payout_method, afwc_additional_fields (registration answers).
 *
 * A coupon belongs to an affiliate through coupon meta "afwc_referral_coupon_of"
 * (the user ID). The plugin writes no affiliate onto orders: the referrals table
 * is the only link between an order and its affiliate.
 *
 * Multi-level commission is stored as extra referral rows for the same order,
 * with "reference" holding the main referral's ID - the only rows the plugin
 * gives a reference (class-afwc-api.php), so a reference marks one even when the
 * main referral is gone. Rows imported by the plugin's own itthinx Affiliates
 * importer (which leaves an afwc_affiliates_users table behind) carry that
 * plugin's reference instead, usually the order ID: on such a store a reference
 * equal to the row's order ID is not a multi-level one. The parents' commission
 * is carried over as part of their balance; the multi-tier structure is not.
 *
 * Not migrated (see get_not_migrated()): commission plans other than the
 * storewide one, affiliate tags, multi-tier parents, lifetime customers,
 * excluded products and landing pages.
 *
 * Nothing is deleted on uninstall: the plugin has no uninstall routine.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_AFWC extends WCUsage_Migration_Source {

	/** The database version of Affiliate For WooCommerce 8.17, whose code this importer was checked against. */
	const KNOWN_DB_VERSION = '1.3.8';

	/** User meta holding the affiliate status. */
	const STATUS_META = 'afwc_is_affiliate';

	/** Coupon meta holding the affiliate's user ID. */
	const COUPON_META = 'afwc_referral_coupon_of';

	/**
	 * Cached result of storewide_plan(), false until it is read.
	 *
	 * @var array|null|false
	 */
	protected $storewide = false;

	/**
	 * Cached campaign ID => slug map.
	 *
	 * @var array|null
	 */
	protected $campaigns = null;

	/**
	 * Cached result of get_supported().
	 *
	 * @var string[]|null
	 */
	protected $supported = null;

	/**
	 * Cached result of affiliates_with_visits().
	 *
	 * @var int[]|null
	 */
	protected $visited = null;

	/**
	 * Cached result of affiliates_with_referrals().
	 *
	 * @var int[]|null
	 */
	protected $earned = null;

	/* ---------------------------------------------------------------------
	 * What the source provides
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {

		if ( null !== $this->supported ) {
			return $this->supported;
		}

		// Multi-level rows are flagged (see get_referrals()), so "tiers".
		$supported = array( 'affiliates', 'legacy_links', 'registrations', 'referrals', 'payout_details', 'tiers', 'settings', 'coupons' );

		if ( $this->table_exists( $this->table( 'afwc_payouts' ) ) ) {
			$supported[] = 'payouts';
		}

		// Very old installs had no primary key on the visits table (added by the
		// plugin's 1.3.2 database update): those cannot be read in batches.
		if ( $this->has_column( $this->table( 'afwc_hits' ), 'id' ) ) {
			$supported[] = 'visits';
			if ( $this->table_exists( $this->table( 'afwc_campaigns' ) ) && $this->has_column( $this->table( 'afwc_hits' ), 'campaign_id' ) ) {
				$supported[] = 'campaigns';
			}
		}

		$this->supported = $supported;

		return $supported;
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		$lines = array(
			__( 'Commission plans other than the storewide one (for affiliates, affiliate tags, products or categories)', 'woo-coupon-usage' ),
			__( 'Affiliate tags', 'woo-coupon-usage' ),
			__( 'Multi-tier parents (past multi-tier commission is still added to the parent\'s balance)', 'woo-coupon-usage' ),
			__( 'Lifetime customers', 'woo-coupon-usage' ),
			__( 'Products excluded from commission', 'woo-coupon-usage' ),
			__( 'Landing pages assigned to affiliates', 'woo-coupon-usage' ),
			__( 'Campaign content (visits keep their campaign name)', 'woo-coupon-usage' ),
			__( 'Other program settings (only the storewide rate, cookie length and click attribution are copied)', 'woo-coupon-usage' ),
		);

		// See affiliate_ids_sql().
		if ( $this->affiliate_roles() ) {
			$lines[] = __( 'Users who are affiliates only through their role ("Affiliate user roles" setting) and have no referrals, visits or coupon', 'woo-coupon-usage' );
		}

		return $lines;
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {

		$warnings = array();

		$db_version = (string) get_option( '_afwc_current_db_version', '' );
		if ( '' !== $db_version && version_compare( $db_version, self::KNOWN_DB_VERSION, '>' ) ) {
			/* translators: 1: database version found, 2: database version the importer was checked against */
			$warnings[] = sprintf( __( 'This site has a newer Affiliate For WooCommerce database (version %1$s) than the one this importer was checked against (%2$s, plugin version 8.17). Its tables are read defensively, but please check the results afterwards.', 'woo-coupon-usage' ), $db_version, self::KNOWN_DB_VERSION );
		}

		// The storewide rate is read as a whole number (AFWC_Plans::parse_storewide_plan_data()).
		$plan = $this->storewide_plan();
		if ( $plan && is_numeric( $plan['amount'] ) && (float) $plan['amount'] !== (float) (int) $plan['amount'] ) {
			/* translators: 1: rate as stored, 2: whole number used */
			$warnings[] = sprintf( __( 'The storewide commission is set to %1$s, but Affiliate For WooCommerce only ever used its whole number, %2$d. %2$d is migrated.', 'woo-coupon-usage' ), wc_format_decimal( $plan['amount'] ), (int) $plan['amount'] );
		}

		if ( $this->session_cookie() ) {
			$warnings[] = __( 'Affiliate For WooCommerce\'s referral cookie only lasted until the browser was closed (cookie expiration 0). Coupon Affiliates has no such setting, so its cookie lifetime is left as it is.', 'woo-coupon-usage' );
		}

		$referrals = $this->table( 'afwc_referrals' );
		if ( $this->has_column( $referrals, 'currency_id' ) ) {
			$currencies = array();
			foreach ( $this->rows( "SELECT currency_id, COUNT(*) AS total FROM `{$referrals}` WHERE currency_id IS NOT NULL AND currency_id <> '' GROUP BY currency_id ORDER BY currency_id ASC" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$currencies[ strtoupper( $row['currency_id'] ) ] = (int) $row['total'];
			}
			$store = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', '' );
			if ( count( $currencies ) > 1 || ( $currencies && $store && ! isset( $currencies[ strtoupper( $store ) ] ) ) ) {
				/* translators: %s: list of currency codes */
				$warnings[] = sprintf( __( 'Referral commission was recorded in more than one currency (%s). Affiliate For WooCommerce stores each commission in its order\'s currency; the amounts are imported as they are, in the store\'s currency.', 'woo-coupon-usage' ), implode( ', ', array_keys( $currencies ) ) );
			}
		}

		if ( $this->has_column( $referrals, 'status' ) ) {
			$unknown = array();
			foreach ( $this->rows( "SELECT status, COUNT(*) AS total FROM `{$referrals}` WHERE status NOT IN ('draft','unpaid','paid','rejected','pending') GROUP BY status" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unknown[] = ( '' === (string) $row['status'] ? '-' : $row['status'] ) . ' (' . (int) $row['total'] . ')';
			}
			if ( $unknown ) {
				/* translators: %s: list of statuses */
				$warnings[] = sprintf( __( 'Some referrals have a commission status this importer does not know (%s). They are not imported.', 'woo-coupon-usage' ), implode( ', ', $unknown ) );
			}
		}

		if ( $this->table_exists( $this->table( 'afwc_affiliates_users' ) ) ) {
			$warnings[] = __( 'Affiliate For WooCommerce once imported this store\'s affiliates from the itthinx "Affiliates" plugin. Links in that plugin\'s old format are not translated; links in Affiliate For WooCommerce\'s format are.', 'woo-coupon-usage' );
		}

		return $warnings;
	}

	/**
	 * @return array
	 */
	public function get_counts() {

		$visits_where = '';
		if ( ! $this->option( 'afwc_coupon_visits', 0 ) && $this->has_column( $this->table( 'afwc_hits' ), 'type' ) ) {
			$visits_where = "( `type` IS NULL OR `type` <> 'coupon' )";
		}

		return array(
			'affiliates' => $this->count_affiliates(),
			'coupons'    => array_sum( array_map( 'count', $this->coupons_by_meta( self::COUPON_META ) ) ),
			'referrals'  => $this->count_rows( $this->table( 'afwc_referrals' ) ),
			'payouts'    => $this->count_rows( $this->table( 'afwc_payouts' ) ),
			'visits'     => $this->has_column( $this->table( 'afwc_hits' ), 'id' ) ? $this->count_rows( $this->table( 'afwc_hits' ), $visits_where ) : 0,
		);
	}

	/**
	 * @return array
	 */
	public function get_source_options() {

		if ( ! $this->has_column( $this->table( 'afwc_hits' ), 'type' ) ) {
			return array();
		}

		return array(
			'afwc_coupon_visits' => array(
				'type'     => 'checkbox',
				'section'  => 'visits',
				'label'    => __( 'Also import the "visits" logged when a customer applied a referral coupon', 'woo-coupon-usage' ),
				'desc'     => __( 'Affiliate For WooCommerce counts applying a referral coupon as a visit. Coupon Affiliates counts only referral link clicks.', 'woo-coupon-usage' ),
				'default'  => 0,
				'requires' => 'visits',
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_option_defaults() {
		return array( 'coupon_code_format' => '{identifier}' );
	}

	/**
	 * @return string
	 */
	public function get_default_coupon_format() {
		// The custom link identifier when they set one, otherwise their username.
		return '{identifier}';
	}

	/**
	 * @return array
	 */
	public function get_settings() {

		$out  = array();
		$plan = $this->storewide_plan();

		if ( $plan ) {
			// The plugin reads the storewide amount as a whole number (see get_warnings()).
			$out['rate'] = (float) (int) $this->money( $plan['amount'] );
			// A flat storewide plan pays its amount once per order (class-afwc-plans.php).
			$out['rate_type'] = 'flat' === strtolower( (string) $plan['type'] ) ? 'flat_order' : 'percent';
		}

		// 30 days when the setting was never saved (Affiliate_For_WooCommerce, where
		// the cookie is set); 0 is a session cookie, which has no equivalent here.
		$days = get_option( 'afwc_cookie_expiration', null );
		if ( null === $days ) {
			$out['cookie_days'] = 30;
		} elseif ( is_numeric( $days ) && (int) $days > 0 ) {
			$out['cookie_days'] = (int) $days;
		}

		$out['credit_first'] = 'first' === get_option( 'afwc_credit_affiliate', 'last' );

		return $out;
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {

		// Query string keys are case-sensitive: keep the parameter as it was typed.
		$pname = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) get_option( 'afwc_pname', 'ref' ) );
		$pname = '' !== $pname ? $pname : 'ref';

		// "ref" is always read as well, whatever the parameter is set to.
		$vars = array_values( array_unique( array( $pname, 'ref' ) ) );

		// "/ref/jane/" links, while pretty referral links are switched on.
		$paths = 'yes' === get_option( 'afwc_use_pretty_referral_links', 'no' ) ? array( $pname ) : array();

		return array(
			'vars'     => $vars,
			'paths'    => $paths,
			'campaign' => 'utm_campaign',
			// The affiliate's user ID (renamable with a constant while the plugin runs).
			'cookies'  => array( defined( 'AFWC_AFFILIATES_COOKIE_NAME' ) ? (string) AFWC_AFFILIATES_COOKIE_NAME : 'affiliate_for_woocommerce' ),
		);
	}

	/**
	 * The storewide commission plan: the one the "afwc_default_commission_plan_id"
	 * setting names, whatever its status, and none while that setting is empty
	 * (afwc_get_default_plan_details()).
	 *
	 * @return array|null The plan's row.
	 */
	protected function storewide_plan() {
		global $wpdb;

		if ( false !== $this->storewide ) {
			return $this->storewide;
		}

		$this->storewide = null;

		$table = $this->table( 'afwc_commission_plans' );
		$id    = (int) get_option( 'afwc_default_commission_plan_id', 0 );
		if ( $id > 0 && $this->table_exists( $table ) ) {
			$rows            = $this->rows( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->storewide = $rows ? $rows[0] : null;
		}

		return $this->storewide;
	}

	/* ---------------------------------------------------------------------
	 * Affiliates
	 * ------------------------------------------------------------------ */

	/**
	 * The roles the plugin's "Affiliate user roles" setting makes affiliates.
	 *
	 * @return string[]
	 */
	protected function affiliate_roles() {
		$roles = get_option( 'affiliate_users_roles', array() );
		return is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ), 'strlen' ) ) : array();
	}

	/**
	 * Whether the referral cookie was a session cookie (an expiration of 0 days,
	 * or less). A setting never saved means 30 days.
	 *
	 * @return bool
	 */
	protected function session_cookie() {
		$days = get_option( 'afwc_cookie_expiration', null );
		return null !== $days && ! ( is_numeric( $days ) && (int) $days > 0 );
	}

	/**
	 * The query for affiliates' user IDs: users with an affiliate status, and
	 * users with no status whose role makes them an affiliate - only those with
	 * referrals, visits or a coupon, as a role such as "customer" would otherwise
	 * give every customer a coupon.
	 *
	 * Two indexed meta lookups joined with UNION rather than one OR'd condition,
	 * so a store with many customers is not scanned in full.
	 *
	 * @param int $after Only users after this ID.
	 * @return string SQL selecting one "ID" column, unordered.
	 */
	protected function affiliate_ids_sql( $after ) {
		global $wpdb;

		$parts = array(
			$wpdb->prepare(
				"SELECT um.user_id AS ID FROM {$wpdb->usermeta} um WHERE um.meta_key = %s AND um.meta_value IN ('yes','pending','no') AND um.user_id > %d",
				self::STATUS_META,
				(int) $after
			),
		);

		$roles = $this->affiliate_roles();
		if ( $roles ) {
			$likes = array();
			foreach ( $roles as $role ) {
				// Quoted, as in the serialized capabilities array, so "shop" does not match "shop_manager".
				$likes[] = $wpdb->prepare( 'um.meta_value LIKE %s', '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%' );
			}

			$used = array(
				$wpdb->prepare(
					"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' AND p.post_status = 'publish' WHERE pm.meta_key = %s AND pm.meta_value = um.user_id )",
					self::COUPON_META
				),
			);
			$referrals = $this->table( 'afwc_referrals' );
			if ( $this->has_column( $referrals, 'affiliate_id' ) ) {
				$used[] = "EXISTS ( SELECT 1 FROM `{$referrals}` r WHERE r.affiliate_id = um.user_id )";
			}
			// The visits table has no index on affiliate_id: read who has visits
			// once, rather than scanning it for every user.
			$visited = $this->affiliates_with_visits();
			if ( $visited ) {
				$used[] = 'um.user_id IN (' . implode( ',', $visited ) . ')';
			}

			$parts[] = $wpdb->prepare(
				"SELECT um.user_id AS ID FROM {$wpdb->usermeta} um WHERE um.meta_key = %s AND um.user_id > %d AND ( " . implode( ' OR ', $likes ) . " )
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} s WHERE s.user_id = um.user_id AND s.meta_key = %s AND s.meta_value <> '' )",
				$wpdb->get_blog_prefix() . 'capabilities',
				(int) $after,
				self::STATUS_META
			) . ' AND ( ' . implode( ' OR ', $used ) . ' )';
		}

		// Users with referrals and no status at all: affiliates the admin set to
		// "not registered" (which deletes the status meta) after they earned
		// commission - the plugin only records referrals for approved affiliates.
		// Read once, as a list: the referrals table is used in the query above already.
		$earned = $this->affiliates_with_referrals();
		if ( $earned ) {
			$parts[] = $wpdb->prepare(
				"SELECT u0.ID FROM {$wpdb->users} u0 WHERE u0.ID IN (" . implode( ',', $earned ) . ") AND u0.ID > %d
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} s WHERE s.user_id = u0.ID AND s.meta_key = %s AND s.meta_value <> '' )",
				(int) $after,
				self::STATUS_META
			);
		}

		return 'SELECT ids.ID FROM ( ' . implode( ' UNION ', $parts ) . " ) ids INNER JOIN {$wpdb->users} u ON u.ID = ids.ID";
	}

	/**
	 * The user IDs the visits table has visits for, read once per request.
	 *
	 * @return int[]
	 */
	protected function affiliates_with_visits() {
		if ( null === $this->visited ) {
			$hits          = $this->table( 'afwc_hits' );
			$this->visited = $this->has_column( $hits, 'affiliate_id' )
				? array_values( array_filter( array_map( 'intval', wp_list_pluck( $this->rows( "SELECT DISTINCT affiliate_id FROM `{$hits}`" ), 'affiliate_id' ) ) ) )
				: array();
		}
		return $this->visited;
	}

	/**
	 * The user IDs the referrals table has referrals for, read once per request.
	 *
	 * @return int[]
	 */
	protected function affiliates_with_referrals() {
		if ( null === $this->earned ) {
			$referrals    = $this->table( 'afwc_referrals' );
			$this->earned = $this->has_column( $referrals, 'affiliate_id' )
				? array_values( array_filter( array_map( 'intval', wp_list_pluck( $this->rows( "SELECT DISTINCT affiliate_id FROM `{$referrals}`" ), 'affiliate_id' ) ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: array();
		}
		return $this->earned;
	}

	/**
	 * Number of affiliates (including applicants).
	 *
	 * @return int
	 */
	protected function count_affiliates() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ( ' . $this->affiliate_ids_sql( 0 ) . ' ) affiliates' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {
		global $wpdb;

		$ids = array_map( 'intval', (array) $wpdb->get_col( $this->affiliate_ids_sql( (int) $after ) . ' ORDER BY ids.ID ASC LIMIT ' . max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $ids ) {
			return array();
		}

		$caps    = $wpdb->get_blog_prefix() . 'capabilities';
		$users   = $this->users_for( $ids );
		$meta    = $this->user_meta_for( $ids, array( 'first_name', 'last_name', self::STATUS_META, 'afwc_ref_url_id', 'afwc_signup_date', 'afwc_paypal_email', 'afwc_payout_method', 'afwc_additional_fields', 'afwc_affiliate_contact', 'afwc_affiliate_skype', 'afwc_affiliate_desc', $caps ) );
		$coupons = $this->coupons_by_meta( self::COUPON_META );
		$roles   = $this->affiliate_roles();

		// "no" or "pending" with referrals: an affiliate switched off (or back to
		// pending), not an applicant - the plugin only records referrals for
		// approved affiliates.
		$switched_off = array();
		$check        = array();
		foreach ( $ids as $id ) {
			$flag = isset( $meta[ $id ][ self::STATUS_META ] ) ? (string) $meta[ $id ][ self::STATUS_META ] : '';
			if ( in_array( $flag, array( 'no', 'pending' ), true ) ) {
				$check[] = $id;
			}
		}
		$referrals = $this->table( 'afwc_referrals' );
		if ( $check && $this->has_column( $referrals, 'affiliate_id' ) ) {
			foreach ( $this->rows( "SELECT DISTINCT affiliate_id FROM `{$referrals}` WHERE affiliate_id IN (" . implode( ',', $check ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$switched_off[ (int) $row['affiliate_id'] ] = true;
			}
		}

		$records = array();
		foreach ( $ids as $id ) {

			$user = isset( $users[ $id ] ) ? $users[ $id ] : array();
			$m    = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			$flag = isset( $m[ self::STATUS_META ] ) ? (string) $m[ self::STATUS_META ] : '';
			switch ( $flag ) {
				case 'pending':
					// The sign-up date is only set on approval.
					$status = ! empty( $m['afwc_signup_date'] ) || isset( $switched_off[ $id ] ) ? 'inactive' : 'pending';
					break;
				case 'no':
					$status = ! empty( $m['afwc_signup_date'] ) || isset( $switched_off[ $id ] ) ? 'inactive' : 'rejected';
					break;
				case 'yes':
					$status = 'active';
					break;
				default:
					// No status: an affiliate through their role, or one set to "not
					// registered" since (only returned with referrals).
					$user_roles = isset( $m[ $caps ] ) && is_array( $m[ $caps ] ) ? array_keys( array_filter( $m[ $caps ] ) ) : array();
					$status     = array_intersect( array_map( 'strval', $user_roles ), $roles ) ? 'active' : 'inactive';
			}

			$ref_id = isset( $m['afwc_ref_url_id'] ) && is_scalar( $m['afwc_ref_url_id'] ) ? trim( (string) $m['afwc_ref_url_id'] ) : '';
			// A numeric value in a link is read as a user ID, never looked up as an identifier.
			if ( is_numeric( $ref_id ) ) {
				$ref_id = '';
			}

			// Signed up when approved; their account's creation date otherwise.
			$date = ! empty( $m['afwc_signup_date'] ) && is_scalar( $m['afwc_signup_date'] ) ? $this->date( $m['afwc_signup_date'], true ) : '';
			if ( '' === $date && ! empty( $user['user_registered'] ) ) {
				$date = $this->date( $user['user_registered'], true );
			}

			list( $fields, $promote ) = $this->registration_fields( $m );

			$email = isset( $m['afwc_paypal_email'] ) && is_scalar( $m['afwc_paypal_email'] ) && is_email( trim( (string) $m['afwc_paypal_email'] ) ) ? trim( (string) $m['afwc_paypal_email'] ) : '';

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => $id,
					'email'         => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => isset( $m['first_name'] ) && is_scalar( $m['first_name'] ) ? (string) $m['first_name'] : '',
					'last_name'     => isset( $m['last_name'] ) && is_scalar( $m['last_name'] ) ? (string) $m['last_name'] : '',
					'status'        => $status,
					'date'          => $date,
					'payment_email' => $email,
					'payout_method' => isset( $m['afwc_payout_method'] ) && is_scalar( $m['afwc_payout_method'] ) ? $this->payout_method( $m['afwc_payout_method'] ) : '',
					'website'       => isset( $user['user_url'] ) ? (string) $user['user_url'] : '',
					'promote'       => $promote,
					'fields'        => $fields,
					'coupons'       => isset( $coupons[ (string) $id ] ) ? $coupons[ (string) $id ] : array(),
					// What their links and the plugin's cookie carried: "?ref=12" (and
					// the cookie) or "?ref=custom-identifier", so a new coupon is
					// named after the custom identifier.
					'identifiers'   => array( (string) $id, $ref_id ),
					// The affiliate ID is the user ID: what a number in a link meant.
					'link_id'       => (string) $id,
				)
			);
		}

		return $records;
	}

	/**
	 * Registration answers from the affiliate's user meta.
	 *
	 * @param array $m User meta.
	 * @return array array( fields (label => value), promote )
	 */
	protected function registration_fields( $m ) {

		$fields  = array();
		$promote = '';

		// Not answers worth keeping, or stored elsewhere (email, names, website, PayPal email).
		$skip = array( 'afwc_password', 'afwc_confirm_password', 'afwc_reg_password', 'afwc_reg_confirm_password', 'afwc_email', 'afwc_reg_email', 'afwc_first_name', 'afwc_last_name', 'afwc_website', 'afwc_paypal_email', 'afwc_terms', 'afwc_reg_terms' );

		$additional = isset( $m['afwc_additional_fields'] ) && is_array( $m['afwc_additional_fields'] ) ? $m['afwc_additional_fields'] : array();
		foreach ( $additional as $field ) {

			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}

			$value = $field['value'];
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			}
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$value = trim( (string) $value );

			$key  = isset( $field['key'] ) && is_scalar( $field['key'] ) ? (string) $field['key'] : '';
			$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? (string) $field['type'] : '';
			if ( in_array( $key, $skip, true ) || 'password' === $type ) {
				continue;
			}

			// The "Tell us about yourself and how you will promote us" answer.
			if ( in_array( $key, array( 'afwc_desc', 'afwc_reg_desc' ), true ) ) {
				if ( '' === $promote ) {
					$promote = $value;
				}
				continue;
			}

			$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? trim( wp_strip_all_tags( (string) $field['label'] ) ) : '';
			if ( '' === $label ) {
				$label = ucwords( trim( str_replace( array( '_', '-' ), ' ', preg_replace( '/^afwc_(reg_)?/', '', $key ) ) ) );
			}
			if ( '' === $label ) {
				$label = __( 'Answer', 'woo-coupon-usage' );
			}

			$fields[ $label ] = $value;
		}

		// Answers from the plugin's first registration form, kept in their own meta.
		$legacy = array(
			'afwc_affiliate_contact' => __( 'Way to contact', 'woo-coupon-usage' ),
			'afwc_affiliate_skype'   => __( 'Skype', 'woo-coupon-usage' ),
		);
		foreach ( $legacy as $key => $label ) {
			if ( ! empty( $m[ $key ] ) && is_scalar( $m[ $key ] ) && ! isset( $fields[ $label ] ) && ! in_array( trim( (string) $m[ $key ] ), $fields, true ) ) {
				$fields[ $label ] = trim( (string) $m[ $key ] );
			}
		}
		if ( '' === $promote && ! empty( $m['afwc_affiliate_desc'] ) && is_scalar( $m['afwc_affiliate_desc'] ) ) {
			$promote = trim( (string) $m['afwc_affiliate_desc'] );
		}

		return array( $fields, $promote );
	}

	/**
	 * The plugin's payout method key in the common form: paypal, paypal-manual,
	 * stripe or other in 8.17 (AFWC_Payout_Handler::get_payout_method()). The
	 * methods of later versions are not guessed at.
	 *
	 * @param mixed $method
	 * @return string
	 */
	protected function payout_method( $method ) {
		switch ( is_scalar( $method ) ? strtolower( trim( (string) $method ) ) : '' ) {
			case 'paypal':
			case 'paypal-manual':
				return 'paypal';
			case 'stripe':
				return 'stripe';
			case 'other':
				return 'manual';
			default:
				return '';
		}
	}

	/* ---------------------------------------------------------------------
	 * Referrals
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a table's dates are UTC for a row.
	 *
	 * The plugin's 1.2.9 database update converted its dates from site time to
	 * UTC in the background, marking each row done in a temporary "migrate_date"
	 * column it drops once finished. Where that column is still there, rows it
	 * has not reached yet are still in site time.
	 *
	 * @param string $table
	 * @param array  $row
	 * @return bool
	 */
	protected function is_gmt( $table, $row ) {
		if ( ! $this->has_column( $table, 'migrate_date' ) ) {
			return true;
		}
		return isset( $row['migrate_date'] ) && null !== $row['migrate_date'] && '' !== (string) $row['migrate_date'] && '0' !== (string) $row['migrate_date'];
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->table( 'afwc_referrals' );
		$rows  = $this->batch( $table, 'referral_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		// Data the plugin's itthinx importer brought in keeps that plugin's references.
		$itthinx = $this->table_exists( $this->table( 'afwc_affiliates_users' ) );

		$payouts = $this->payouts_for_orders( wp_list_pluck( $rows, 'post_id' ) );

		$records = array();
		foreach ( $rows as $row ) {

			$id       = (int) $row['referral_id'];
			$order_id = (int) $row['post_id'];

			// Multi-level rows are the only ones the plugin gives a reference (the
			// main referral's ID), whether or not that referral is still there.
			$reference = isset( $row['reference'] ) ? trim( (string) $row['reference'] ) : '';
			$is_tier   = ctype_digit( $reference ) && (int) $reference > 0 && (int) $reference !== $id && ( ! $itthinx || (int) $reference !== $order_id );

			switch ( strtolower( (string) $row['status'] ) ) {
				case 'unpaid':
					$status = 'unpaid';
					break;
				case 'paid':
					$status = 'paid';
					break;
				case 'draft': // The order is not paid yet.
				case 'pending': // The column's default: not a status the plugin pays.
					$status = 'pending';
					break;
				default: // Rejected, or a status this importer does not know.
					$status = 'rejected';
			}

			$affiliate = (int) $row['affiliate_id'];
			$payout    = 'paid' === $status && isset( $payouts[ $order_id . ':' . $affiliate ] ) ? $payouts[ $order_id . ':' . $affiliate ] : '';

			$records[] = $this->referral_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => $affiliate,
					'order_id'            => $order_id,
					'amount'              => $row['amount'],
					'status'              => $status,
					'date'                => $this->date( $row['datetime'], $this->is_gmt( $table, $row ) ),
					'is_tier'             => $is_tier,
					'payout_source_id'    => $payout,
				)
			);
		}

		return $records;
	}

	/**
	 * The payouts that paid a batch of orders.
	 *
	 * @param int[] $order_ids
	 * @return array "order ID:affiliate ID" => payout ID
	 */
	protected function payouts_for_orders( $order_ids ) {

		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) ) );
		$payouts   = $this->table( 'afwc_payouts' );
		$orders    = $this->table( 'afwc_payout_orders' );

		if ( ! $order_ids || ! $this->table_exists( $payouts ) || ! $this->table_exists( $orders ) ) {
			return array();
		}

		$map = array();
		foreach ( $this->rows( "SELECT po.post_id, p.affiliate_id, p.payout_id FROM `{$orders}` po INNER JOIN `{$payouts}` p ON p.payout_id = po.payout_id WHERE po.post_id IN (" . implode( ',', $order_ids ) . ') ORDER BY p.payout_id ASC' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$map[ (int) $row['post_id'] . ':' . (int) $row['affiliate_id'] ] = (string) (int) $row['payout_id'];
		}
		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Payouts
	 * ------------------------------------------------------------------ */

	/**
	 * Payouts. The plugin only records a payout once it is made, so every one
	 * is paid.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$table = $this->table( 'afwc_payouts' );
		$rows  = $this->batch( $table, 'payout_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		// The referrals each payout paid: the affiliate's paid referrals on the
		// orders listed for it.
		$paid      = array();
		$orders    = $this->table( 'afwc_payout_orders' );
		$referrals = $this->table( 'afwc_referrals' );
		if ( $this->table_exists( $orders ) && $this->table_exists( $referrals ) ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'payout_id' ) ) );
			$sql = "SELECT DISTINCT po.payout_id, r.referral_id FROM `{$orders}` po
				INNER JOIN `{$table}` p ON p.payout_id = po.payout_id
				INNER JOIN `{$referrals}` r ON r.post_id = po.post_id AND r.affiliate_id = p.affiliate_id AND r.status = 'paid'
				WHERE po.payout_id IN ({$ids}) ORDER BY r.referral_id ASC";
			foreach ( $this->rows( $sql ) as $row ) {
				$paid[ (int) $row['payout_id'] ][] = (string) (int) $row['referral_id'];
			}
		}

		$records = array();
		foreach ( $rows as $row ) {

			$id   = (int) $row['payout_id'];
			$date = $this->date( $row['datetime'], $this->is_gmt( $table, $row ) );

			// A PayPal mass payment's transaction ID, added to the notes by its IPN.
			$transaction = '';
			if ( ! empty( $row['payout_notes'] ) && preg_match( '/TransactionID:\s*([A-Za-z0-9\-]+)/', (string) $row['payout_notes'], $match ) ) {
				$transaction = $match[1];
			}

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'amount'              => $row['amount'],
					'status'              => 'paid',
					'method'              => $this->payout_method( isset( $row['payment_gateway'] ) ? $row['payment_gateway'] : '' ),
					'date'                => $date,
					'date_paid'           => $date,
					'transaction_id'      => $transaction,
					'referral_source_ids' => isset( $paid[ $id ] ) ? $paid[ $id ] : array(),
				)
			);
		}

		return $records;
	}

	/* ---------------------------------------------------------------------
	 * Visits
	 * ------------------------------------------------------------------ */

	/**
	 * Campaign slugs (the "utm_campaign" value that tagged a visit).
	 *
	 * @return array campaign ID => slug
	 */
	protected function campaign_slugs() {
		if ( null !== $this->campaigns ) {
			return $this->campaigns;
		}
		$this->campaigns = array();
		$table           = $this->table( 'afwc_campaigns' );
		if ( $this->table_exists( $table ) ) {
			foreach ( $this->rows( "SELECT id, title, slug FROM `{$table}`" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->campaigns[ (int) $row['id'] ] = '' !== (string) $row['slug'] ? (string) $row['slug'] : (string) $row['title'];
			}
		}
		return $this->campaigns;
	}

	/**
	 * An IP address. Old versions stored IPv4 addresses as integers (ip2long);
	 * the plugin's 1.3.5 update converts them, but may not have finished.
	 *
	 * @param mixed $ip
	 * @return string
	 */
	protected function ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' !== $ip && ctype_digit( $ip ) && (float) $ip <= 4294967295 && PHP_INT_SIZE >= 8 ) {
			$ip = (string) long2ip( (int) $ip );
		}
		return $ip;
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$table = $this->table( 'afwc_hits' );
		if ( ! $this->has_column( $table, 'id' ) ) {
			return array();
		}

		$where = array();
		if ( $since ) {
			$where[] = $wpdb->prepare( '`datetime` >= %s', get_gmt_from_date( $since . ' 00:00:00' ) );
		}
		// Coupon applications are not link clicks (see get_source_options()).
		if ( ! $this->option( 'afwc_coupon_visits', 0 ) && $this->has_column( $table, 'type' ) ) {
			$where[] = "( `type` IS NULL OR `type` <> 'coupon' )";
		}

		$rows = $this->batch( $table, 'id', $after, $limit, implode( ' AND ', $where ) );
		if ( ! $rows ) {
			return array();
		}

		// The orders the visits led to, through the referral that recorded the visit.
		$orders    = array();
		$referrals = $this->table( 'afwc_referrals' );
		if ( $this->has_column( $referrals, 'hit_id' ) ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
			foreach ( $this->rows( "SELECT hit_id, post_id FROM `{$referrals}` WHERE hit_id IN ({$ids}) AND post_id > 0 ORDER BY referral_id ASC" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! isset( $orders[ (int) $r['hit_id'] ] ) ) {
					$orders[ (int) $r['hit_id'] ] = (int) $r['post_id'];
				}
			}
		}

		$campaigns = $this->campaign_slugs();

		$records = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$campaign = isset( $row['campaign_id'] ) ? (int) $row['campaign_id'] : 0;

			$records[] = $this->visit_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'url'                 => isset( $row['url'] ) ? (string) $row['url'] : '',
					// The plugin keeps the landing page, not the referring site.
					'referrer'            => '',
					'ip'                  => $this->ip( isset( $row['ip'] ) ? $row['ip'] : '' ),
					'campaign'            => $campaign && isset( $campaigns[ $campaign ] ) ? $campaigns[ $campaign ] : '',
					'date'                => $this->date( $row['datetime'], $this->is_gmt( $table, $row ) ),
					'order_id'            => isset( $orders[ $id ] ) ? $orders[ $id ] : 0,
				)
			);
		}

		return $records;
	}
}
