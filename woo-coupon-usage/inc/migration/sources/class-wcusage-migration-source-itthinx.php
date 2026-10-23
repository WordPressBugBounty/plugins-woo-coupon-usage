<?php
/**
 * Migration source: Affiliates by itthinx (free), with Affiliates WooCommerce
 * Light - and the same tables as written by Affiliates Pro / Enterprise.
 *
 * Read from Affiliates 7.0.x's own tables (lib/core/wp-init.php, affiliates_setup()):
 *
 *   aff_affiliates        affiliate_id, name, email, from_date, thru_date (an affiliate is
 *                         valid between the two), status (active|pending|deleted),
 *                         type ('direct' = the site owner's pseudo-affiliate)
 *   aff_affiliates_users  affiliate_id, user_id (optional: admins can add affiliates
 *                         with no WordPress user)
 *   aff_referrals         referral_id, affiliate_id, post_id, datetime (UTC), amount,
 *                         currency_id, data (serialized), status (accepted|pending|
 *                         rejected|closed - closed means paid), type, reference,
 *                         hit_id, integration
 *   aff_hits              hit_id, affiliate_id, datetime (UTC), ip (INT, IPv4), src_uri_id,
 *                         dest_uri_id, user_agent_id, is_robot
 *   aff_uris              uri_id, uri
 *
 * Affiliates WooCommerce Light records a referral when an order is placed
 * (affiliates-woocommerce-light.php, order_processed()): post_id is the order ID,
 * type, reference and integration are left empty, and the status is the default
 * one ("accepted") whatever then happens to the order - Light never updates it,
 * so the order's current status is checked here (a source option). Its global
 * rate is the "referral-rate" fraction in the "affiliates_woocommerce_light" option.
 * The integrations Pro / Enterprise add name themselves in the integration column:
 * only referrals with none, or a WooCommerce one, are matched to orders.
 *
 * There are no payout records: paying an affiliate closes their referrals.
 *
 * Registration answers are user meta named after the fields in the
 * "aff_registration_fields" option (class-affiliates-registration.php). A
 * "payment_email" or "paypal_email" field is the payment email, as is the
 * "paypal_email" key of Affiliates Pro / Enterprise's aff_affiliates_attributes
 * (affiliate_id, attr_key, attr_value), the one attribute the free plugin itself
 * reads (class-affiliates-shortcodes.php).
 *
 * Not migrated (see get_not_migrated()): Pro / Enterprise's other attributes
 * (coupons, rates), Rates, campaigns and multi-tier levels, whose storage is
 * not in the free code. An order with referrals for more than one affiliate is
 * reported; the runner lets the first referral claim it.
 *
 * Referral links: "?affiliates={id}" (the parameter is the "aff_pname" option),
 * or "/affiliates/{id}/"; the ID is MD5-hashed when "aff_id_encoding" is 2, and
 * otherwise read with intval() (affiliates_check_affiliate_id()), so "05" or "5x"
 * meant affiliate 5. The "wp_affiliates" cookie holds the same value, followed by
 * ".{campaign}" when a Pro campaign was in the link.
 *
 * IMPORTANT: with "aff_delete_data" set, deactivating Affiliates drops all of
 * these tables.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_Itthinx extends WCUsage_Migration_Source {

	/** Registration fields that are user columns, not answers. */
	const USER_FIELDS = array( 'first_name', 'last_name', 'user_login', 'user_email', 'user_url', 'password' );

	/** Registration fields (user meta) holding a payment email. */
	const EMAIL_KEYS = array( 'payment_email', 'paypal_email' );

	/**
	 * Cached result of direct_ids().
	 *
	 * @var int[]|null
	 */
	protected $direct_ids = null;

	/* ---------------------------------------------------------------------
	 * What the source provides
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {

		// Admins can add affiliates with no WordPress user.
		$supported = array( 'affiliates', 'legacy_links', 'registrations', 'users_optional', 'referrals', 'visits', 'settings' );

		if ( $this->has_payment_emails() ) {
			$supported[] = 'payout_details';
		}

		return $supported;
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		return array(
			__( 'Deleted affiliates, and their referrals', 'woo-coupon-usage' ),
			__( 'Commission rates from Affiliates Pro / Enterprise (Rates, and rates set on individual affiliates)', 'woo-coupon-usage' ),
			__( 'Coupons assigned to affiliates in Affiliates Pro / Enterprise (each affiliate gets a new coupon)', 'woo-coupon-usage' ),
			__( 'Campaigns (Affiliates Pro / Enterprise)', 'woo-coupon-usage' ),
			__( 'Multi-tier levels (Affiliates Enterprise)', 'woo-coupon-usage' ),
			__( 'Other program settings (only the default rate, cookie length and click attribution are copied)', 'woo-coupon-usage' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		global $wpdb;

		$warnings = array();

		if ( get_option( 'aff_delete_data', false ) || get_option( 'aff_delete_network_data', false ) ) {
			$warnings[] = __( 'IMPORTANT: Affiliates is set to delete all of its data when it is deactivated (Affiliates > Settings > General). Do not deactivate it before the migration is finished - or untick that setting first - or its affiliates, referrals and visits are gone for good.', 'woo-coupon-usage' );
		}

		$shared = $this->count_shared_orders();
		if ( $shared ) {
			$warnings[] = sprintf(
				/* translators: %d: number of orders */
				_n( '%d order has referrals for more than one affiliate. Only one affiliate can own an order here: the first referral recorded claims it and the others are carried over as part of those affiliates\' balances. Please check it.', '%d orders have referrals for more than one affiliate. Only one affiliate can own an order here: the first referral recorded claims each and the others are carried over as part of those affiliates\' balances. Please check them.', $shared, 'woo-coupon-usage' ),
				$shared
			);
		}

		$referrals = $this->table( 'aff_referrals' );
		if ( function_exists( 'get_woocommerce_currency' ) && $this->has_column( $referrals, 'currency_id' ) ) {
			$other = $this->count_rows( $referrals, $wpdb->prepare( "currency_id IS NOT NULL AND currency_id <> '' AND currency_id <> %s", get_woocommerce_currency() ) );
			if ( $other ) {
				$warnings[] = sprintf(
					/* translators: 1: number of referrals, 2: currency code */
					_n( '%1$d referral is in a currency other than the store\'s (%2$s). Its amount is imported unconverted.', '%1$d referrals are in a currency other than the store\'s (%2$s). Their amounts are imported unconverted.', $other, 'woo-coupon-usage' ),
					$other,
					get_woocommerce_currency()
				);
			}
		}

		return $warnings;
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		return array(
			'affiliates' => $this->count_rows( $this->table( 'aff_affiliates' ), $this->affiliate_where() ),
			'referrals'  => $this->count_rows( $this->table( 'aff_referrals' ), $this->not_direct( 'affiliate_id' ) ),
			'visits'     => $this->count_rows( $this->table( 'aff_hits' ), '( is_robot IS NULL OR is_robot = 0 )' . ( $this->not_direct( 'affiliate_id' ) ? ' AND ' . $this->not_direct( 'affiliate_id' ) : '' ) ),
		);
	}

	/**
	 * @return array
	 */
	public function get_source_options() {
		return array(
			'reconcile_order_status' => array(
				'type'    => 'checkbox',
				'section' => 'referrals',
				'label'   => __( 'Check each referral against its order\'s current status', 'woo-coupon-usage' ),
				'desc'    => __( 'Affiliates WooCommerce Light records a referral when the order is placed and never updates it. On: referrals for cancelled, refunded or failed orders are treated as rejected, and those for pending or on-hold orders as pending. Paid (closed) referrals are left as they are.', 'woo-coupon-usage' ),
				'default' => 1,
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_settings() {

		$out = array();

		// 0 is a session cookie, which has no equivalent here.
		$days = get_option( 'aff_cookie_timeout_days', 30 );
		if ( is_numeric( $days ) && (int) $days > 0 ) {
			$out['cookie_days'] = (int) $days;
		}

		// Every hit overwrites the cookie: the last affiliate is credited.
		$out['credit_first'] = false;

		// Light's rate is a fraction of the order subtotal less discounts.
		$light = get_option( 'affiliates_woocommerce_light', null );
		if ( is_array( $light ) && isset( $light['referral-rate'] ) && is_numeric( $light['referral-rate'] ) ) {
			$out['rate']      = round( (float) $light['referral-rate'] * 100, 2 );
			$out['rate_type'] = 'percent';
		}

		return $out;
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {
		$pname = $this->pname();
		return array(
			'vars'                => array( $pname ),
			'paths'               => array( $pname ),
			'cookies'             => array( 'wp_affiliates' ),
			// The cookie holds "{id}.{campaign}" when a Pro campaign was in the link.
			'cookie_strip_suffix' => 1,
		);
	}

	/**
	 * The referral link parameter.
	 *
	 * @return string
	 */
	protected function pname() {
		$pname = get_option( 'aff_pname', 'affiliates' );
		return is_string( $pname ) && preg_match( '/^[A-Za-z0-9_\-]+$/', $pname ) ? $pname : 'affiliates';
	}

	/**
	 * Whether links carry MD5-hashed affiliate IDs.
	 *
	 * @return bool
	 */
	protected function md5_ids() {
		return 2 === (int) get_option( 'aff_id_encoding', 1 );
	}

	/**
	 * Orders with referrals for more than one affiliate. New-user referrals,
	 * other integrations' and the Direct pseudo-affiliate's are left out.
	 *
	 * @return int
	 */
	protected function count_shared_orders() {
		global $wpdb;

		$table = $this->table( 'aff_referrals' );
		if ( ! $this->has_column( $table, 'post_id' ) ) {
			return 0;
		}
		$direct = $this->not_direct( 'affiliate_id' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT post_id FROM `{$table}` WHERE post_id > 0 AND " . $this->order_referral_where() . ( $direct ? " AND {$direct}" : '' ) . ' GROUP BY post_id HAVING COUNT(DISTINCT affiliate_id) > 1 ) shared' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Affiliates
	 * ------------------------------------------------------------------ */

	/**
	 * The site owner's "Direct" pseudo-affiliate(s).
	 *
	 * @return int[]
	 */
	protected function direct_ids() {
		if ( null === $this->direct_ids ) {
			$table            = $this->table( 'aff_affiliates' );
			$this->direct_ids = $this->table_exists( $table )
				? array_map( 'intval', wp_list_pluck( $this->rows( "SELECT affiliate_id FROM `{$table}` WHERE type = 'direct'" ), 'affiliate_id' ) )
				: array();
		}
		return $this->direct_ids;
	}

	/**
	 * A condition leaving out the Direct pseudo-affiliate.
	 *
	 * @param string $column
	 * @return string '' when there is none.
	 */
	protected function not_direct( $column ) {
		$ids = $this->direct_ids();
		return $ids ? "{$column} NOT IN (" . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * Which affiliates are imported: not the Direct pseudo-affiliate, and not
	 * deleted ones - an affiliate is deleted when the admin removes them or
	 * their WordPress user is deleted, and bringing them back (with a new user
	 * made from their old email) is not what anyone would want.
	 *
	 * @return string
	 */
	protected function affiliate_where() {
		return "status <> 'deleted' AND ( type IS NULL OR type <> 'direct' )";
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$rows = $this->batch( $this->table( 'aff_affiliates' ), 'affiliate_id', $after, $limit, $this->affiliate_where() );
		if ( ! $rows ) {
			return array();
		}

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'affiliate_id' ) );

		// Their WordPress users (the first, if several are linked).
		$user_of = array();
		$links   = $this->table( 'aff_affiliates_users' );
		if ( $this->table_exists( $links ) ) {
			foreach ( $this->rows( "SELECT affiliate_id, user_id FROM `{$links}` WHERE affiliate_id IN (" . implode( ',', $ids ) . ') ORDER BY user_id ASC' ) as $link ) {
				if ( ! isset( $user_of[ (int) $link['affiliate_id'] ] ) ) {
					$user_of[ (int) $link['affiliate_id'] ] = (int) $link['user_id'];
				}
			}
		}

		$fields = $this->registration_fields();
		$users  = $this->users_for( $user_of );
		$umeta  = $this->user_meta_for( $user_of, array_merge( array( 'first_name', 'last_name' ), array_keys( $fields ), self::EMAIL_KEYS ) );
		$paypal = $this->paypal_emails_for( $ids );
		$today  = gmdate( 'Y-m-d' );
		$md5    = $this->md5_ids();

		$records = array();
		foreach ( $rows as $row ) {

			$id      = (int) $row['affiliate_id'];
			$user_id = isset( $user_of[ $id ] ) ? $user_of[ $id ] : 0;
			$user    = isset( $users[ $user_id ] ) ? $users[ $user_id ] : array();
			$meta    = isset( $umeta[ $user_id ] ) ? $umeta[ $user_id ] : array();

			list( $first, $last ) = $this->split_name( $row['name'] );
			if ( ! empty( $meta['first_name'] ) || ! empty( $meta['last_name'] ) ) {
				$first = isset( $meta['first_name'] ) ? (string) $meta['first_name'] : '';
				$last  = isset( $meta['last_name'] ) ? (string) $meta['last_name'] : '';
			}

			// An affiliate whose end date has passed no longer earns referrals.
			if ( 'pending' === $row['status'] ) {
				$status = 'pending';
			} elseif ( 'active' === $row['status'] ) {
				$status = ! empty( $row['thru_date'] ) && '0000-00-00' !== $row['thru_date'] && $row['thru_date'] < $today ? 'inactive' : 'active';
			} else {
				$status = 'inactive';
			}

			$answers = array();
			foreach ( $fields as $name => $label ) {
				if ( isset( $meta[ $name ] ) && ( is_array( $meta[ $name ] ) ? $meta[ $name ] : '' !== trim( (string) $meta[ $name ] ) ) ) {
					$answers[ $label ] = $meta[ $name ];
				}
			}

			$payment_email = '';
			foreach ( self::EMAIL_KEYS as $key ) {
				if ( '' === $payment_email && ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
					$payment_email = $meta[ $key ];
				}
			}
			if ( '' === $payment_email && ! empty( $paypal[ $id ] ) ) {
				$payment_email = $paypal[ $id ];
			}

			// What their links and cookie carried.
			$identifiers = array( (string) $id );
			if ( $md5 ) {
				$identifiers[] = md5( (string) $id );
			}

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => $user_id,
					'email'         => ! empty( $user['user_email'] ) ? $user['user_email'] : (string) $row['email'],
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => $first,
					'last_name'     => $last,
					'status'        => $status,
					// A date only (from_date), written with date() (UTC).
					'date'          => $this->date( $row['from_date'] ),
					'payment_email' => $payment_email,
					'payout_method' => '' !== $payment_email ? 'paypal' : '',
					'website'       => isset( $user['user_url'] ) ? (string) $user['user_url'] : '',
					'fields'        => $answers,
					'identifiers'   => $identifiers,
					// What a number in a link meant, read with intval() ("05" too) -
					// unless links carried hashed IDs.
					'link_id'       => $md5 ? '' : (string) $id,
				)
			);
		}

		return $records;
	}

	/**
	 * The registration form's own fields (not the user columns or payment
	 * emails, which have places of their own).
	 *
	 * @return array field name (user meta key) => label
	 */
	protected function registration_fields() {
		$fields = get_option( 'aff_registration_fields', array() );
		$out    = array();
		foreach ( is_array( $fields ) ? $fields : array() as $name => $field ) {
			$name = (string) $name;
			if ( '' === $name || in_array( $name, self::USER_FIELDS, true ) || in_array( $name, self::EMAIL_KEYS, true ) ) {
				continue;
			}
			$label        = is_array( $field ) && ! empty( $field['label'] ) ? stripslashes( (string) $field['label'] ) : ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
			$out[ $name ] = $label;
		}
		return $out;
	}

	/**
	 * The "paypal_email" attribute (Pro / Enterprise) of a batch of affiliates,
	 * read as the free plugin reads it (class-affiliates-shortcodes.php).
	 *
	 * @param int[] $ids
	 * @return array affiliate ID => email
	 */
	protected function paypal_emails_for( $ids ) {
		$table = $this->table( 'aff_affiliates_attributes' );
		if ( ! $ids || ! $this->table_exists( $table ) ) {
			return array();
		}
		$emails = array();
		foreach ( $this->rows( "SELECT affiliate_id, attr_value FROM `{$table}` WHERE attr_key = 'paypal_email' AND affiliate_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$emails[ (int) $row['affiliate_id'] ] = (string) $row['attr_value'];
		}
		return $emails;
	}

	/**
	 * Whether any affiliate has a payment email (for payout details).
	 *
	 * @return bool
	 */
	protected function has_payment_emails() {
		global $wpdb;

		$attributes = $this->table( 'aff_affiliates_attributes' );
		if ( $this->table_exists( $attributes ) && $this->count_rows( $attributes, "attr_key = 'paypal_email' AND attr_value <> ''" ) ) {
			return true;
		}

		$links = $this->table( 'aff_affiliates_users' );
		if ( ! $this->table_exists( $links ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->usermeta} um INNER JOIN `{$links}` au ON au.user_id = um.user_id WHERE um.meta_key IN ('payment_email','paypal_email') AND um.meta_value <> '' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Referrals
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a referral can be for a WooCommerce order.
	 *
	 * Not a new-user referral (post_id is the page they signed up on), and not
	 * one another integration recorded: Pro / Enterprise integrations name
	 * themselves in the integration column, and their post_id is their own form,
	 * booking or download. Light, the free plugin and referrals from before
	 * Affiliates 3.0 leave the column empty.
	 *
	 * @param array $row Referral row.
	 * @return bool
	 */
	protected function is_order_referral( $row ) {
		if ( isset( $row['type'] ) && 'user' === (string) $row['type'] ) {
			return false;
		}
		$integration = isset( $row['integration'] ) ? strtolower( trim( (string) $row['integration'] ) ) : '';
		return '' === $integration || false !== strpos( $integration, 'woocommerce' );
	}

	/**
	 * A condition matching the referrals is_order_referral() accepts.
	 *
	 * @return string
	 */
	protected function order_referral_where() {
		$table = $this->table( 'aff_referrals' );
		$where = "( type IS NULL OR type <> 'user' )";
		if ( $this->has_column( $table, 'integration' ) ) {
			$where .= " AND ( integration IS NULL OR integration = '' OR integration LIKE '%woocommerce%' )";
		}
		return $where;
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$rows = $this->batch( $this->table( 'aff_referrals' ), 'referral_id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		// The order behind each referral: its post_id when that is an order
		// (Light and the full integration both use the order ID), or the order ID
		// Light also keeps in the referral's data.
		$order_of = array();
		foreach ( $rows as $row ) {
			if ( ! $this->is_order_referral( $row ) ) {
				continue;
			}
			$candidates = array( (int) $row['post_id'] );
			$data       = $this->unserialize( $row['data'] );
			if ( is_array( $data ) && isset( $data['order_id']['value'], $data['order_id']['domain'] ) && 'affiliates-woocommerce-light' === $data['order_id']['domain'] && is_numeric( $data['order_id']['value'] ) ) {
				$candidates[] = (int) $data['order_id']['value'];
			}
			$order_of[ (int) $row['referral_id'] ] = array_filter( $candidates );
		}

		$all = array();
		foreach ( $order_of as $candidates ) {
			$all = array_merge( $all, $candidates );
		}
		$statuses = class_exists( 'WCUsage_Migration_Writer' ) ? WCUsage_Migration_Writer::order_statuses( $all ) : array();

		$reconcile = (bool) $this->option( 'reconcile_order_status', 1 );

		$records = array();
		foreach ( $rows as $row ) {

			$id       = (int) $row['referral_id'];
			$order_id = 0;
			foreach ( isset( $order_of[ $id ] ) ? $order_of[ $id ] : array() as $candidate ) {
				if ( isset( $statuses[ $candidate ] ) ) {
					$order_id = $candidate;
					break;
				}
			}

			switch ( $row['status'] ) {
				case 'accepted':
					$status = 'unpaid';
					break;
				case 'pending':
					$status = 'pending';
					break;
				case 'closed':
					$status = 'paid';
					break;
				default:
					$status = 'rejected';
			}

			// Light never updates a referral after the order is placed.
			if ( $reconcile && $order_id && in_array( $status, array( 'unpaid', 'pending' ), true ) ) {
				$order_status = $statuses[ $order_id ];
				if ( in_array( $order_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
					$status = 'rejected';
				} elseif ( in_array( $order_status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) {
					$status = 'pending';
				}
			}

			$records[] = $this->referral_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'order_id'            => $order_id,
					// Already truncated to two decimals by Affiliates.
					'amount'              => null === $row['amount'] ? 0 : $row['amount'],
					'status'              => $status,
					'date'                => $this->date( $row['datetime'], true ),
				)
			);
		}

		return $records;
	}

	/* ---------------------------------------------------------------------
	 * Visits
	 * ------------------------------------------------------------------ */

	/**
	 * Hits, with their landing page and referrer. Robots and the Direct
	 * pseudo-affiliate are left out.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$hits = $this->table( 'aff_hits' );
		if ( ! $this->table_exists( $hits ) ) {
			return array();
		}

		$conditions = array( '( is_robot IS NULL OR is_robot = 0 )' );
		if ( $since ) {
			$conditions[] = $wpdb->prepare( 'datetime >= %s', get_gmt_from_date( $since . ' 00:00:00' ) );
		}
		if ( $this->not_direct( 'affiliate_id' ) ) {
			$conditions[] = $this->not_direct( 'affiliate_id' );
		}

		$rows = $this->batch( $hits, 'hit_id', $after, $limit, implode( ' AND ', $conditions ) );
		if ( ! $rows ) {
			return array();
		}

		// The pages, from the URIs table (looked up separately: it would be
		// joined twice, for the referrer and the landing page).
		$uris  = array();
		$table = $this->table( 'aff_uris' );
		if ( $this->table_exists( $table ) && $this->has_column( $hits, 'src_uri_id' ) ) {
			$ids = array_filter( array_map( 'intval', array_merge( wp_list_pluck( $rows, 'src_uri_id' ), wp_list_pluck( $rows, 'dest_uri_id' ) ) ) );
			if ( $ids ) {
				foreach ( $this->rows( "SELECT uri_id, uri FROM `{$table}` WHERE uri_id IN (" . implode( ',', array_unique( $ids ) ) . ')' ) as $uri ) {
					$uris[ (int) $uri['uri_id'] ] = (string) $uri['uri'];
				}
			}
		}

		// Hits that led to a referral on an order.
		$orders    = array();
		$referrals = $this->table( 'aff_referrals' );
		if ( $this->has_column( $referrals, 'hit_id' ) ) {
			$columns   = 'hit_id, post_id, type' . ( $this->has_column( $referrals, 'integration' ) ? ', integration' : '' );
			$converted = array_filter(
				$this->rows( "SELECT {$columns} FROM `{$referrals}` WHERE hit_id IN (" . implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'hit_id' ) ) ) . ')' ),
				function ( $row ) {
					return $this->is_order_referral( $row );
				}
			);
			$statuses  = class_exists( 'WCUsage_Migration_Writer' ) ? WCUsage_Migration_Writer::order_statuses( wp_list_pluck( $converted, 'post_id' ) ) : array();
			foreach ( $converted as $row ) {
				if ( isset( $statuses[ (int) $row['post_id'] ] ) ) {
					$orders[ (int) $row['hit_id'] ] = (int) $row['post_id'];
				}
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$ip        = (int) $row['ip'];
			$src       = isset( $row['src_uri_id'] ) ? (int) $row['src_uri_id'] : 0;
			$dest      = isset( $row['dest_uri_id'] ) ? (int) $row['dest_uri_id'] : 0;
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['hit_id'],
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'url'                 => isset( $uris[ $dest ] ) ? $uris[ $dest ] : '',
					'referrer'            => isset( $uris[ $src ] ) ? $uris[ $src ] : '',
					'ip'                  => $ip > 0 ? (string) long2ip( $ip ) : '',
					'date'                => $this->date( $row['datetime'], true ),
					'order_id'            => isset( $orders[ (int) $row['hit_id'] ] ) ? $orders[ (int) $row['hit_id'] ] : 0,
				)
			);
		}

		return $records;
	}
}
