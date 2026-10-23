<?php
/**
 * Migration source: Solid Affiliate (premium, WooCommerce only).
 *
 * Solid Affiliate's code is not public, so this importer is pieced together
 * from three things that are: the Solid Affiliate migrator in FluentAffiliate
 * (a GPL plugin on wordpress.org that reads Solid's tables directly, so its
 * table and column names are known to work), nine original Solid source files
 * its former lead developer published (CommissionCalculator, PurchaseTracking,
 * WooCommerceIntegration...), and Solid's own documentation.
 *
 *   solid_affiliate_affiliates   id, user_id, first_name, last_name, commission_type
 *                                (site_default|percentage|flat), commission_rate,
 *                                payment_email, registration_notes, status
 *                                (approved|pending|rejected), custom_registration_data,
 *                                affiliate_group_id, created_at, updated_at
 *   solid_affiliate_referrals    id, affiliate_id, referral_source (visit|coupon|auto_referral),
 *                                visit_id, coupon_id, customer_id, payout_id,
 *                                commission_amount, referral_type (purchase|
 *                                subscription_renewal|auto_referral|event), description,
 *                                status (draft|unpaid|paid|rejected), order_source
 *                                ('woocommerce'), order_amount, order_id,
 *                                serialized_item_commissions, order_refunded_at, created_at
 *   solid_affiliate_visits       id, affiliate_id, referral_id, landing_url, http_referrer,
 *                                http_ip, created_at
 *   solid_affiliate_payouts      id, bulk_payout_id, affiliate_id, amount, payout_method
 *                                (paypal|csv|store_credit), status ('paid'), created_at
 *   solid_affiliates_bulk_payouts  (sic - "affiliates") id, method, status
 *                                (processing|success|fail), currency, date_range_start/end
 *   solid_affiliate_affiliate_groups          id, name, commission_type, commission_rate
 *   solid_affiliate_creatives    id, name, description, url, creative_text,
 *                                creative_image_url, status (active|inactive)
 *
 * A coupon is linked to an affiliate by the coupon meta
 * "_solid_affiliate_woocommerce_coupon_affiliate_id" (Solid's affiliate ID,
 * not a user ID), and a product with commission switched off has the meta
 * "_solid_affiliate_woocommerce_is_product_referrals_disabled". Solid writes
 * no order meta: referrals point at orders through order_source + order_id.
 * Dates (created_at) are read as site time, as FluentAffiliate reads them.
 *
 * The exact SQL types are not confirmed, so every column is checked before it
 * is read. Only what is confirmed is migrated: Solid's settings (the option
 * holding them, and so the referral link variable, is not known), custom
 * slugs, lifetime customer links (solid_affiliate_affiliate_customer_links),
 * product and category rates and affiliate product rates
 * (solid_affiliate_affiliate_product_rates) are listed in get_not_migrated().
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_Solid extends WCUsage_Migration_Source {

	/** Coupon meta linking a coupon to a Solid affiliate ID. */
	const COUPON_META = '_solid_affiliate_woocommerce_coupon_affiliate_id';

	/** Product meta (also used on variations in later versions): commission switched off. */
	const PRODUCT_DISABLED_META = '_solid_affiliate_woocommerce_is_product_referrals_disabled';

	/* ---------------------------------------------------------------------
	 * Tables
	 * ------------------------------------------------------------------ */

	/**
	 * Full name of one of Solid's tables.
	 *
	 * @param string $name 'affiliates', 'referrals', 'visits', 'payouts', 'bulk_payouts',
	 *                     'groups', 'customer_links', 'creatives', 'product_rates'.
	 * @return string The first candidate that exists, or the expected name.
	 */
	protected function solid_table( $name ) {

		$candidates = array(
			'affiliates'     => array( 'solid_affiliate_affiliates' ),
			'referrals'      => array( 'solid_affiliate_referrals' ),
			'visits'         => array( 'solid_affiliate_visits' ),
			'payouts'        => array( 'solid_affiliate_payouts' ),
			// FluentAffiliate reads it as "solid_affiliates_..." - with an "s".
			'bulk_payouts'   => array( 'solid_affiliates_bulk_payouts', 'solid_affiliate_bulk_payouts' ),
			'groups'         => array( 'solid_affiliate_affiliate_groups' ),
			'customer_links' => array( 'solid_affiliate_affiliate_customer_links' ),
			'creatives'      => array( 'solid_affiliate_creatives' ),
			'product_rates'  => array( 'solid_affiliate_affiliate_product_rates', 'solid_affiliate_product_rates' ),
		);

		$names = isset( $candidates[ $name ] ) ? $candidates[ $name ] : array( 'solid_affiliate_' . $name );
		foreach ( $names as $candidate ) {
			if ( $this->table_exists( $this->table( $candidate ) ) ) {
				return $this->table( $candidate );
			}
		}
		return $this->table( $names[0] );
	}

	/**
	 * A column's value from a row, or a default when the column is not there.
	 *
	 * @param array  $row
	 * @param string $column
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function col( $row, $column, $default = '' ) {
		return isset( $row[ $column ] ) ? $row[ $column ] : $default;
	}

	/**
	 * A yes/no value stored in any of the usual ways.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	protected function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return (bool) $value;
		}
		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off', 'null' ), true );
	}

	/**
	 * Solid's rate type in the common form.
	 *
	 * Solid's flat rate is paid per item (rate x quantity, CommissionCalculator),
	 * so it is a fixed amount per product here. "site_default" is no custom rate.
	 *
	 * @param string $type
	 * @return string|null
	 */
	protected function rate_type( $type ) {
		switch ( strtolower( (string) $type ) ) {
			case 'percentage':
			case 'percent':
				return 'percent';
			case 'flat':
				return 'flat_product';
			default:
				return null;
		}
	}

	/* ---------------------------------------------------------------------
	 * What the source can provide
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {

		// Coupon links are coupon meta, always read.
		$supported = array( 'affiliates', 'registrations', 'rates', 'referrals', 'payout_details', 'coupons' );

		$optional = array(
			'payouts'   => 'payouts',
			'visits'    => 'visits',
			'groups'    => 'groups',
			'creatives' => 'creatives',
		);
		foreach ( $optional as $kind => $table ) {
			if ( $this->table_exists( $this->solid_table( $table ) ) ) {
				$supported[] = $kind;
			}
		}

		$rates = $this->get_product_rates();
		if ( $rates['excluded'] ) {
			$supported[] = 'product_rates';
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

		$lines = array(
			__( 'Program settings, including the default and recurring rates and the cookie length', 'woo-coupon-usage' ),
			__( 'Old referral links (such as ?sld=123) and custom affiliate slugs', 'woo-coupon-usage' ),
		);

		if ( $this->count_rows( $this->solid_table( 'customer_links' ) ) ) {
			$lines[] = __( 'Lifetime customer links', 'woo-coupon-usage' );
		}

		$lines[] = __( 'Commission rates set on products and product categories, and categories with commission switched off', 'woo-coupon-usage' );

		$rates = $this->count_rows( $this->solid_table( 'product_rates' ) );
		if ( $rates ) {
			/* translators: %d: number of rates */
			$lines[] = sprintf( _n( '%d affiliate-specific product rate, including auto-referrals (no equivalent here)', '%d affiliate-specific product rates, including auto-referrals (no equivalent here)', $rates, 'woo-coupon-usage' ), $rates );
		}

		$lines[] = __( 'Bank details from the registration form (payment emails are migrated)', 'woo-coupon-usage' );
		$lines[] = __( 'Affiliate tags and store credit balances', 'woo-coupon-usage' );

		return $lines;
	}

	/**
	 * @return array
	 */
	public function get_counts() {

		$coupons = array();
		foreach ( $this->coupons_by_meta( self::COUPON_META ) as $coupon_ids ) {
			$coupons = array_merge( $coupons, $coupon_ids );
		}

		return array(
			'affiliates' => $this->count_rows( $this->solid_table( 'affiliates' ) ),
			'coupons'    => count( array_unique( $coupons ) ),
			'referrals'  => $this->count_rows( $this->solid_table( 'referrals' ) ),
			'payouts'    => $this->count_payouts(),
			'visits'     => $this->count_rows( $this->solid_table( 'visits' ) ),
			'groups'     => $this->count_rows( $this->solid_table( 'groups' ) ),
			'creatives'  => $this->count_rows( $this->solid_table( 'creatives' ) ),
		);
	}

	/**
	 * How many payouts get_payouts() imports: paid ones, and ones still
	 * processing whose referrals are not back to unpaid - never failed ones.
	 *
	 * @return int
	 */
	protected function count_payouts() {
		global $wpdb;

		$table = $this->solid_table( 'payouts' );
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$bulk        = $this->solid_table( 'bulk_payouts' );
		$join        = '';
		$bulk_status = "''";
		if ( $this->has_column( $table, 'bulk_payout_id' ) && $this->has_column( $bulk, 'id' ) && $this->has_column( $bulk, 'status' ) ) {
			$join        = "LEFT JOIN `{$bulk}` b ON b.id = p.bulk_payout_id";
			$bulk_status = "COALESCE( b.status, '' )";
		}
		$status     = $this->has_column( $table, 'status' ) ? 'p.status' : "'paid'";
		$processing = "( {$status} = 'processing' OR {$bulk_status} = 'processing' )";

		$referrals = $this->solid_table( 'referrals' );
		if ( $this->has_column( $referrals, 'payout_id' ) && $this->has_column( $referrals, 'status' ) ) {
			$open = "( {$processing} AND NOT EXISTS ( SELECT 1 FROM `{$referrals}` r WHERE r.payout_id = p.id AND r.status <> 'paid' ) )";
		} else {
			$open = $processing;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` p {$join} WHERE {$bulk_status} <> 'fail' AND {$status} NOT IN ('fail','failed') AND ( {$open} OR ( NOT {$processing} AND {$status} IN ('paid','success') ) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Readers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$table = $this->solid_table( 'affiliates' );
		$rows  = $this->batch( $table, 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$user_ids = $this->has_column( $table, 'user_id' ) ? wp_list_pluck( $rows, 'user_id' ) : array();
		$users    = $this->users_for( $user_ids );
		$umeta    = $this->user_meta_for( $user_ids, array( 'first_name', 'last_name' ) );
		$coupons  = $this->coupons_by_meta( self::COUPON_META );
		$earned   = $this->rejected_with_history( $rows );

		$records = array();
		foreach ( $rows as $row ) {

			$id      = (int) $row['id'];
			$user_id = (int) $this->col( $row, 'user_id', 0 );
			$user    = isset( $users[ $user_id ] ) ? $users[ $user_id ] : array();
			$names   = isset( $umeta[ $user_id ] ) ? $umeta[ $user_id ] : array();
			$custom  = $this->registration_data( $this->col( $row, 'custom_registration_data' ) );

			switch ( strtolower( (string) $this->col( $row, 'status' ) ) ) {
				case 'approved':
					$status = 'active';
					break;
				case 'pending':
					$status = 'pending';
					break;
				case 'rejected':
					// Solid has no "inactive": an affiliate who is switched off is
					// rejected. One with referrals or payouts was an affiliate, so
					// they are imported switched off; one without was an applicant.
					$status = isset( $earned[ $id ] ) ? 'inactive' : 'rejected';
					break;
				default:
					$status = 'inactive';
			}

			$rate_type = $this->rate_type( (string) $this->col( $row, 'commission_type' ) );
			$rate      = $this->col( $row, 'commission_rate', null );
			if ( null === $rate_type || ! is_numeric( $rate ) ) {
				$rate      = null;
				$rate_type = null;
			}

			$first = trim( (string) $this->col( $row, 'first_name' ) );
			$last  = trim( (string) $this->col( $row, 'last_name' ) );

			$records[] = $this->affiliate_record(
				array(
					'source_id'       => $id,
					'user_id'         => $user_id,
					'email'           => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'        => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'      => '' !== $first ? $first : ( isset( $names['first_name'] ) ? (string) $names['first_name'] : '' ),
					'last_name'       => '' !== $last ? $last : ( isset( $names['last_name'] ) ? (string) $names['last_name'] : '' ),
					'status'          => $status,
					'date'            => $this->date( $this->col( $row, 'created_at' ) ),
					'rate'            => null === $rate ? null : (float) $rate,
					'rate_type'       => $rate_type,
					'payment_email'   => (string) $this->col( $row, 'payment_email' ),
					'website'         => '' !== $custom['website'] ? $custom['website'] : ( isset( $user['user_url'] ) ? (string) $user['user_url'] : '' ),
					'promote'         => (string) $this->col( $row, 'registration_notes' ),
					'fields'          => $custom['fields'],
					'group_source_id' => (int) $this->col( $row, 'affiliate_group_id', 0 ) ? (string) (int) $row['affiliate_group_id'] : '',
					'coupons'         => isset( $coupons[ (string) $id ] ) ? $coupons[ (string) $id ] : array(),
					// No identifiers: old "?sld=" links are not kept working, as
					// the setting naming the variable is not known.
				)
			);
		}

		return $records;
	}

	/**
	 * Which of a batch's rejected affiliates have referrals or payouts.
	 *
	 * @param array[] $rows Affiliate rows.
	 * @return array affiliate ID => true
	 */
	protected function rejected_with_history( $rows ) {

		$ids = array();
		foreach ( $rows as $row ) {
			if ( 'rejected' === strtolower( (string) $this->col( $row, 'status' ) ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		$queries = array();
		foreach ( array( 'referrals', 'payouts' ) as $name ) {
			$table = $this->solid_table( $name );
			if ( $ids && $this->has_column( $table, 'affiliate_id' ) ) {
				$queries[] = "SELECT DISTINCT affiliate_id FROM `{$table}` WHERE affiliate_id IN (" . implode( ',', $ids ) . ')';
			}
		}
		if ( ! $queries ) {
			return array();
		}

		$earned = array();
		foreach ( $this->rows( implode( ' UNION ', $queries ) ) as $row ) {
			$earned[ (int) $row['affiliate_id'] ] = true;
		}
		return $earned;
	}

	/**
	 * Answers to Solid's custom registration form.
	 *
	 * Its format is not confirmed: JSON or serialized, either name => value or
	 * a list of fields with a label and a value - all are read, and kept as
	 * registration answers. A website is picked out by its field name; bank
	 * details are not made payout details.
	 *
	 * @param mixed $raw
	 * @return array array( 'fields' => label => value, 'website' => string )
	 */
	protected function registration_data( $raw ) {

		$out = array(
			'fields'  => array(),
			'website' => '',
		);

		$data = $raw;
		if ( is_string( $data ) ) {
			$json = json_decode( $data, true );
			$data = is_array( $json ) ? $json : $this->unserialize( $data );
		}
		if ( ! is_array( $data ) ) {
			return $out;
		}

		// Fields Solid already keeps in their own columns, or that are not answers.
		$skip = array( 'user_login', 'user_email', 'user_pass', 'password', 'payment_email', 'registration_notes', 'first_name', 'last_name', 'is_accept_affiliate_policy', 'affiliate_group_id', '_wpnonce', '_wp_http_referer' );

		foreach ( $data as $key => $value ) {

			$label = is_string( $key ) ? $key : '';

			// A list of field definitions.
			if ( is_array( $value ) && ( isset( $value['value'] ) || array_key_exists( 'value', $value ) ) ) {
				$label = isset( $value['label'] ) && is_scalar( $value['label'] ) && '' !== (string) $value['label'] ? (string) $value['label'] : ( isset( $value['name'] ) && is_scalar( $value['name'] ) ? (string) $value['name'] : $label );
				$value = $value['value'];
			}

			$name = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $label ) ), '_' );
			if ( '' === $name || in_array( $name, $skip, true ) || false !== strpos( $name, 'password' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$value = trim( (string) $value );

			$out['fields'][ preg_match( '/[A-Z ]/', $label ) ? $label : ucwords( str_replace( array( '_', '-' ), ' ', $label ) ) ] = $value;

			if ( '' === $out['website'] && preg_match( '/website|site_?url|^url$/', $name ) && preg_match( '#^https?://[^\s]+$#i', $value ) ) {
				$out['website'] = $value;
			}
		}

		return $out;
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->solid_table( 'referrals' );
		$rows  = $this->batch( $table, 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$secondary = $this->auto_referrals_sharing_an_order( $rows );

		$records = array();
		foreach ( $rows as $row ) {

			switch ( strtolower( (string) $this->col( $row, 'status' ) ) ) {
				case 'unpaid':
					$status = 'unpaid';
					break;
				case 'paid':
					$status = 'paid';
					break;
				case 'draft':
					// Created at checkout; Solid makes it unpaid when the order is paid.
					$status = 'pending';
					break;
				default:
					$status = 'rejected';
			}

			$payout_id = (int) $this->col( $row, 'payout_id', 0 );

			$records[] = $this->referral_record(
				array(
					'source_id'           => (int) $row['id'],
					'affiliate_source_id' => (int) $this->col( $row, 'affiliate_id', 0 ),
					'order_id'            => $this->woocommerce_order_id( $row ),
					'amount'              => $this->col( $row, 'commission_amount', 0 ),
					'status'              => $status,
					'date'                => $this->date( $this->col( $row, 'created_at' ) ),
					// An auto-referral (a product's revenue share) on an order another
					// affiliate referred: the order is theirs, the share a balance.
					'secondary'           => isset( $secondary[ (int) $row['id'] ] ),
					'payout_source_id'    => $payout_id ? (string) $payout_id : '',
				)
			);
		}

		return $records;
	}

	/**
	 * The WooCommerce order a referral row is for, 0 when it is not one.
	 *
	 * Solid only integrates with WooCommerce, so any order ID is a WooCommerce
	 * order's, whatever its order_source ("woocommerce", or the Subscriptions
	 * integration's own for renewals), as FluentAffiliate reads it. The runner
	 * checks that the order exists.
	 *
	 * @param array $row
	 * @return int
	 */
	protected function woocommerce_order_id( $row ) {
		return max( 0, (int) $this->col( $row, 'order_id', 0 ) );
	}

	/**
	 * Whether a referral row is one of Solid's auto-referrals.
	 *
	 * @param array $row
	 * @return bool
	 */
	protected function is_auto_referral( $row ) {
		return 'auto_referral' === (string) $this->col( $row, 'referral_source' ) || 'auto_referral' === (string) $this->col( $row, 'referral_type' );
	}

	/**
	 * Auto-referrals in a batch whose order also has an ordinary (coupon or
	 * link) referral, not rejected, for another affiliate.
	 *
	 * Solid creates the auto-referral first, so it has the lower ID and would
	 * otherwise claim the order: here only one affiliate can own an order, and
	 * that should be the one the customer came through. When the ordinary
	 * referral is the same affiliate's, both are simply theirs.
	 *
	 * @param array[] $rows
	 * @return array referral ID => true
	 */
	protected function auto_referrals_sharing_an_order( $rows ) {

		$table = $this->solid_table( 'referrals' );
		if ( ( ! $this->has_column( $table, 'referral_source' ) && ! $this->has_column( $table, 'referral_type' ) ) || ! $this->has_column( $table, 'affiliate_id' ) ) {
			return array();
		}

		$orders = array();
		foreach ( $rows as $row ) {
			$order_id = $this->woocommerce_order_id( $row );
			if ( $order_id && $this->is_auto_referral( $row ) ) {
				$orders[ $order_id ][ (int) $row['id'] ] = (int) $this->col( $row, 'affiliate_id', 0 );
			}
		}
		if ( ! $orders ) {
			return array();
		}

		$conditions = array();
		foreach ( array( 'referral_source', 'referral_type' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				$conditions[] = "( `{$column}` IS NULL OR `{$column}` <> 'auto_referral' )";
			}
		}
		$status = $this->has_column( $table, 'status' ) ? " AND `status` <> 'rejected'" : '';

		$sql = "SELECT DISTINCT order_id, affiliate_id FROM `{$table}` WHERE order_id IN (" . implode( ',', array_map( 'intval', array_keys( $orders ) ) ) . ') AND ' . implode( ' AND ', $conditions ) . $status;

		$secondary = array();
		foreach ( $this->rows( $sql ) as $row ) {
			foreach ( isset( $orders[ (int) $row['order_id'] ] ) ? $orders[ (int) $row['order_id'] ] : array() as $referral_id => $affiliate_id ) {
				if ( $affiliate_id !== (int) $row['affiliate_id'] ) {
					$secondary[ $referral_id ] = true;
				}
			}
		}
		return $secondary;
	}

	/**
	 * Payouts: one row per affiliate paid (a bulk payout groups them).
	 *
	 * The bulk payout's status (processing | success | fail, as FluentAffiliate
	 * reads it) says whether the money went out. A failed one is cancelled
	 * here. One still processing is an open (pending) payout - unless its
	 * referrals are back to unpaid, when their commission is migrated as owed
	 * instead, so it is not counted twice. Otherwise a payout Solid has marked
	 * paid is paid, and any other is open only if the referrals it covers were
	 * already marked paid.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$table = $this->solid_table( 'payouts' );
		$rows  = $this->batch( $table, 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$ids       = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$referrals = array();
		$paid      = array();
		$ref_table = $this->solid_table( 'referrals' );
		if ( $this->has_column( $ref_table, 'payout_id' ) ) {
			$status = $this->has_column( $ref_table, 'status' ) ? 'status' : "'' AS status";
			foreach ( $this->rows( "SELECT id, payout_id, {$status} FROM `{$ref_table}` WHERE payout_id IN (" . implode( ',', $ids ) . ') ORDER BY id ASC' ) as $r ) {
				$payout_id                 = (int) $r['payout_id'];
				$referrals[ $payout_id ][] = (string) $r['id'];
				if ( ! isset( $paid[ $payout_id ] ) ) {
					$paid[ $payout_id ] = true;
				}
				if ( 'paid' !== $r['status'] ) {
					$paid[ $payout_id ] = false;
				}
			}
		}

		// The bulk payout's method (for rows without one of their own) and status.
		$bulk       = array();
		$bulk_table = $this->solid_table( 'bulk_payouts' );
		$bulk_ids   = $this->has_column( $table, 'bulk_payout_id' ) ? array_filter( array_map( 'intval', wp_list_pluck( $rows, 'bulk_payout_id' ) ) ) : array();
		if ( $bulk_ids && $this->has_column( $bulk_table, 'id' ) ) {
			$method = $this->has_column( $bulk_table, 'method' ) ? 'method' : "'' AS method";
			$status = $this->has_column( $bulk_table, 'status' ) ? 'status' : "'' AS status";
			foreach ( $this->rows( "SELECT id, {$method}, {$status} FROM `{$bulk_table}` WHERE id IN (" . implode( ',', array_unique( $bulk_ids ) ) . ')' ) as $b ) {
				$bulk[ (int) $b['id'] ] = array(
					'method' => (string) $b['method'],
					'status' => strtolower( (string) $b['status'] ),
				);
			}
		}

		$records = array();
		foreach ( $rows as $row ) {

			$id      = (int) $row['id'];
			$bulk_id = (int) $this->col( $row, 'bulk_payout_id', 0 );
			$method  = (string) $this->col( $row, 'payout_method' );
			if ( '' === $method && isset( $bulk[ $bulk_id ] ) ) {
				$method = $bulk[ $bulk_id ]['method'];
			}

			$status      = strtolower( (string) $this->col( $row, 'status', 'paid' ) );
			$bulk_status = isset( $bulk[ $bulk_id ] ) ? $bulk[ $bulk_id ]['status'] : '';
			if ( 'fail' === $bulk_status || 'fail' === $status || 'failed' === $status ) {
				$status = 'cancelled';
			} elseif ( 'processing' === $bulk_status || 'processing' === $status ) {
				$status = isset( $paid[ $id ] ) && ! $paid[ $id ] ? 'cancelled' : 'pending';
			} elseif ( 'paid' === $status || 'success' === $status ) {
				$status = 'paid';
			} elseif ( ! empty( $paid[ $id ] ) ) {
				$status = 'pending';
			} else {
				$status = 'cancelled';
			}

			$date = $this->date( $this->col( $row, 'created_at' ) );

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $this->col( $row, 'affiliate_id', 0 ),
					'amount'              => $this->col( $row, 'amount', 0 ),
					'status'              => $status,
					'method'              => $this->payout_method( $method ),
					'date'                => $date,
					'date_paid'           => 'paid' === $status ? $date : '',
					'transaction_id'      => (string) $this->col( $row, 'transaction_id', $this->col( $row, 'paypal_payout_item_id' ) ),
					'referral_source_ids' => isset( $referrals[ $id ] ) ? $referrals[ $id ] : array(),
				)
			);
		}

		return $records;
	}

	/**
	 * Solid's payout method in the common form.
	 *
	 * @param string $method 'paypal' | 'csv' | 'store_credit'
	 * @return string
	 */
	protected function payout_method( $method ) {
		$method = strtolower( (string) $method );
		if ( false !== strpos( $method, 'paypal' ) ) {
			return 'paypal';
		}
		if ( false !== strpos( $method, 'credit' ) ) {
			return 'credit';
		}
		// "csv": a payout file the store paid by hand.
		return 'manual';
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$table = $this->solid_table( 'visits' );
		$where = '';
		if ( $since && $this->has_column( $table, 'created_at' ) ) {
			$where = $wpdb->prepare( 'created_at >= %s', $since . ' 00:00:00' );
		}

		$rows = $this->batch( $table, 'id', $after, $limit, $where );
		if ( ! $rows ) {
			return array();
		}

		// The orders the visits converted into: through the visit's referral,
		// or a referral that names the visit.
		$by_referral = array();
		$by_visit    = array();
		$referrals   = $this->solid_table( 'referrals' );
		$rids        = $this->has_column( $table, 'referral_id' ) ? array_filter( array_map( 'intval', wp_list_pluck( $rows, 'referral_id' ) ) ) : array();
		$vids        = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$visit_col   = $this->has_column( $referrals, 'visit_id' );
		if ( $this->has_column( $referrals, 'order_id' ) && ( $rids || $visit_col ) ) {
			$conditions = array();
			if ( $rids ) {
				$conditions[] = 'id IN (' . implode( ',', $rids ) . ')';
			}
			if ( $visit_col ) {
				$conditions[] = 'visit_id IN (' . implode( ',', $vids ) . ')';
			}
			$visit = $visit_col ? 'visit_id' : '0 AS visit_id';
			foreach ( $this->rows( "SELECT id, order_id, {$visit} FROM `{$referrals}` WHERE " . implode( ' OR ', $conditions ) . ' ORDER BY id ASC' ) as $r ) {
				$order_id = $this->woocommerce_order_id( $r );
				if ( $order_id ) {
					$by_referral[ (int) $r['id'] ] = $order_id;
					if ( (int) $r['visit_id'] && ! isset( $by_visit[ (int) $r['visit_id'] ] ) ) {
						$by_visit[ (int) $r['visit_id'] ] = $order_id;
					}
				}
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$id          = (int) $row['id'];
			$referral_id = (int) $this->col( $row, 'referral_id', 0 );
			$order_id    = isset( $by_referral[ $referral_id ] ) ? $by_referral[ $referral_id ] : ( isset( $by_visit[ $id ] ) ? $by_visit[ $id ] : 0 );

			$records[] = $this->visit_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $this->col( $row, 'affiliate_id', 0 ),
					'url'                 => (string) $this->col( $row, 'landing_url' ),
					'referrer'            => (string) $this->col( $row, 'http_referrer' ),
					'ip'                  => (string) $this->col( $row, 'http_ip' ),
					'date'                => $this->date( $this->col( $row, 'created_at' ) ),
					'order_id'            => $order_id,
					'converted'           => $referral_id > 0,
				)
			);
		}

		return $records;
	}

	/**
	 * Groups. Solid's group names need not be unique, but each group becomes a
	 * role named after it here, so a name that more than one group has (in
	 * any case or spacing) is told apart by the group's ID: "Gold (#3)".
	 *
	 * @return array[]
	 */
	public function get_groups() {
		$rows = $this->batch( $this->solid_table( 'groups' ), 'id', '', 10000 );

		$seen = array();
		foreach ( $rows as $row ) {
			$key          = $this->group_name_key( $this->col( $row, 'name' ) );
			$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$type     = $this->rate_type( (string) $this->col( $row, 'commission_type' ) );
			$rate     = $this->col( $row, 'commission_rate', null );
			$has_rate = null !== $type && is_numeric( $rate );
			$name     = trim( (string) $this->col( $row, 'name' ) );
			$key      = $this->group_name_key( $name );
			if ( '' !== $key && $seen[ $key ] > 1 ) {
				$name .= ' (#' . (int) $row['id'] . ')';
			}
			$groups[] = array(
				'source_id' => (string) (int) $row['id'],
				'name'      => $name,
				'rate'      => $has_rate ? (float) $rate : null,
				'rate_type' => $has_rate ? $type : null,
			);
		}
		return $groups;
	}

	/**
	 * A group name with case, accents, spacing and punctuation taken out, so
	 * names that would make the same role compare equal.
	 *
	 * @param mixed $name
	 * @return string
	 */
	protected function group_name_key( $name ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( remove_accents( trim( (string) $name ) ) ) );
	}

	/**
	 * Creatives. Solid has no type column: one with an image is a banner.
	 *
	 * @return array[]
	 */
	public function get_creatives() {
		$rows    = $this->batch( $this->solid_table( 'creatives' ), 'id', '', 10000 );
		$records = array();
		foreach ( $rows as $row ) {
			$image     = (string) $this->col( $row, 'creative_image_url' );
			$records[] = array(
				'source_id'   => (string) (int) $row['id'],
				'name'        => (string) $this->col( $row, 'name' ),
				'description' => (string) $this->col( $row, 'description' ),
				'type'        => '' !== $image ? 'image' : 'text',
				'image'       => $image,
				'url'         => (string) $this->col( $row, 'url' ),
				'text'        => (string) $this->col( $row, 'creative_text' ),
				'alt'         => (string) $this->col( $row, 'name' ),
				'status'      => 'inactive' === strtolower( (string) $this->col( $row, 'status', 'active' ) ) ? 'inactive' : 'active',
			);
		}
		return $records;
	}

	/**
	 * Products (and variations) with commission switched off. Product and
	 * category rates, and categories switched off, are not carried over.
	 *
	 * @return array
	 */
	public function get_product_rates() {
		global $wpdb;

		$excluded = array();
		foreach ( $this->rows( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . self::PRODUCT_DISABLED_META . "' ORDER BY post_id ASC" ) as $row ) {
			if ( $this->truthy( $row['meta_value'] ) ) {
				$excluded[] = (int) $row['post_id'];
			}
		}

		return array(
			'products'   => array(),
			'categories' => array(),
			'excluded'   => array_values( array_unique( $excluded ) ),
		);
	}
}
