<?php
/**
 * Migration source: Affiliates Manager (free, by wp.insider / Tips and Tricks HQ).
 *
 * Read from Affiliates Manager 2.9.x's own tables (source/Data/DatabaseInstaller.php):
 *
 *   wpam_affiliates        affiliateId, userId (NULL until approved), firstName, lastName,
 *                          email, address*, phoneNumber, companyName, websiteUrl,
 *                          status (applied|approved|confirmed|active|inactive|declined|blocked),
 *                          dateCreated, uniqueRefKey (legacy link key), paymentMethod
 *                          (paypal|check|manual|bank), paypalEmail, bankDetails, nameOnCheck,
 *                          bountyType (percent|fixed), bountyAmount, userData (serialized
 *                          custom registration answers, keyed by databaseField)
 *   wpam_affiliates_fields registration form fields: type (base|custom), name (label),
 *                          databaseField
 *   wpam_transactions      the affiliate LEDGER: transactionId, affiliateId, amount (signed),
 *                          type (credit|refund|payout|adjustment), referenceId,
 *                          status (pending|confirmed|failed), email (buyer), dateCreated (UTC)
 *   wpam_tracking_tokens   clicks: sourceAffiliateId, referer, ipAddress, dateCreated (UTC)
 *   wpam_creatives         creativeId, type (text|image), name, image (URL), imagePostId,
 *                          linkText, altText, slug, status (active|inactive|deleted)
 *
 * How the ledger works (classes/CommissionTracking.php): a WooCommerce sale adds a
 * "credit" with referenceId = the order ID; a refunded or cancelled order adds a
 * "refund" row of the negated amount with the same referenceId; payouts are
 * "payout" rows with negative amounts; admins can add signed "adjustment" rows.
 * An affiliate's balance is the sum of every row that is not "failed". Credits
 * carry no paid flag at all - see plan() for how paid and unpaid are worked out.
 *
 * referenceId is not namespaced: the EDD, WP eStore, Simple PayPal Cart... integrations
 * write their own transaction IDs into the same column. A credit is only linked to
 * the WooCommerce order of that ID when the order agrees: its "_wpam_id" meta (written
 * at checkout) names the affiliate, or its billing email is the credit's buyer email.
 *
 * Referral links: "?wpam_id={affiliateId}" (and the cookie of the same name); the
 * deprecated "?wpam_refkey=" carried the affiliate ID, their email, or a packed key
 * built from uniqueRefKey and a creative ID (source/Tracking/TrackingKey.php).
 *
 * Coupons: the free plugin has none, and the storage of the paid "WooCommerce
 * Coupons" add-on is not public, so its coupon links are not migrated: every
 * affiliate gets a new coupon (see get_not_migrated()).
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_AffiliatesManager extends WCUsage_Migration_Source {

	/** Alphabet of Affiliates Manager's binary-to-text encoding (source/Util/BinConverter.php). */
	const KEY_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';

	/**
	 * Paid / unpaid split of each affiliate's ledger, worked out once per request.
	 *
	 * @var array affiliate ID => transaction ID => parts
	 */
	protected $plans = array();

	/**
	 * How many affiliates' plans are kept before the cache is cleared (see load_plans()).
	 *
	 * @var int
	 */
	protected $plan_cache_limit = 500;

	/**
	 * Cached result of field_definitions().
	 *
	 * @var array|null
	 */
	protected $field_definitions = null;

	/**
	 * Cached creative IDs, for the legacy link keys.
	 *
	 * @var int[]|null
	 */
	protected $creative_ids = null;

	/* ---------------------------------------------------------------------
	 * What the source provides
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	public function get_supported() {
		// Applicants have no WordPress user until they are approved.
		return array( 'affiliates', 'legacy_links', 'registrations', 'users_optional', 'rates', 'referrals', 'payouts', 'payout_details', 'visits', 'creatives', 'settings' );
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		return array(
			__( 'Coupons linked to affiliates by the paid "WooCommerce Coupons" add-on (each affiliate gets a new coupon)', 'woo-coupon-usage' ),
			__( 'Other program settings (only the default rate, cookie length and click attribution are copied)', 'woo-coupon-usage' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {

		$warnings = array();

		$warnings[] = __( 'Affiliates Manager does not record which commissions a payout paid: payouts are matched to each affiliate\'s oldest commissions first, so the unpaid balance matches Affiliates Manager\'s.', 'woo-coupon-usage' );

		$negative = $this->negative_balances();
		if ( $negative ) {
			$warnings[] = sprintf(
				/* translators: 1: number of affiliates, 2: total amount, 3: affiliate IDs */
				_n( '%1$d affiliate has a negative balance in Affiliates Manager (%2$s in total; affiliate ID %3$s), usually from a refund recorded after their commission was paid out. A negative balance cannot be carried over, so it is not imported: take it off their future commission by hand.', '%1$d affiliates have a negative balance in Affiliates Manager (%2$s in total; affiliate IDs %3$s), usually from refunds recorded after their commission was paid out. A negative balance cannot be carried over, so it is not imported: take it off their future commission by hand.', count( $negative ), 'woo-coupon-usage' ),
				count( $negative ),
				wc_format_decimal( array_sum( $negative ), 2 ),
				implode( ', ', array_slice( array_keys( $negative ), 0, 10 ) ) . ( count( $negative ) > 10 ? ', ...' : '' )
			);
		}

		return $warnings;
	}

	/**
	 * Affiliates whose Affiliates Manager balance (every row that is not
	 * failed) is below zero. plan() cannot hold that and drops it - this much,
	 * as the plugin's refund rows reverse a whole credit.
	 *
	 * @return array affiliate ID => amount not carried over
	 */
	protected function negative_balances() {
		$table = $this->table( 'wpam_transactions' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		$negative = array();
		foreach ( $this->rows( "SELECT affiliateId, SUM(amount) AS balance FROM `{$table}` WHERE status <> 'failed' GROUP BY affiliateId HAVING balance < -0.005 ORDER BY affiliateId ASC" ) as $row ) {
			$negative[ (int) $row['affiliateId'] ] = round( -1 * (float) $row['balance'], 2 );
		}
		return $negative;
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		$transactions = $this->table( 'wpam_transactions' );

		return array(
			'affiliates' => $this->count_rows( $this->table( 'wpam_affiliates' ) ),
			'referrals'  => $this->count_rows( $transactions, "type = 'credit' AND status <> 'failed'" ),
			'payouts'    => $this->count_rows( $transactions, "type = 'payout' AND amount < 0 AND status <> 'failed'" ),
			'visits'     => $this->count_rows( $this->table( 'wpam_tracking_tokens' ) ),
			'creatives'  => $this->count_rows( $this->table( 'wpam_creatives' ), "status <> 'deleted'" ),
		);
	}

	/**
	 * Affiliates Manager makes each affiliate's email their username, which
	 * makes a poor coupon code: name them after the affiliate instead.
	 *
	 * @return array
	 */
	public function get_option_defaults() {
		return array( 'coupon_code_format' => '{first_name}{last_name}' );
	}

	/**
	 * @return string
	 */
	public function get_default_coupon_format() {
		return '{first_name}{last_name}';
	}

	/**
	 * Settings (config.php option names, defaults from source/Options.php).
	 *
	 * The global bounty is only the default copied onto each new affiliate:
	 * commission is always worked out from the affiliate's own bounty.
	 *
	 * @return array
	 */
	public function get_settings() {

		$out = array();

		$amount = get_option( 'wpam_affbounty_amount', '' );
		if ( '' !== (string) $amount && is_numeric( $amount ) ) {
			$out['rate']      = (float) $amount;
			$out['rate_type'] = 'fixed' === get_option( 'wpam_affbounty_type', 'percent' ) ? 'flat_order' : 'percent';
		}

		// 0 is a session cookie, which has no equivalent here.
		$days = get_option( 'wpam_cookie_expire', '' );
		if ( is_numeric( $days ) && (int) $days > 0 ) {
			$out['cookie_days'] = (int) $days;
		}

		// Every click overwrites the cookie: the last affiliate is credited.
		$out['credit_first'] = false;

		return $out;
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {
		return array(
			'vars'    => array( 'wpam_id', 'wpam_refkey' ),
			'cookies' => array( 'wpam_id' ),
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

		$rows = $this->batch( $this->table( 'wpam_affiliates' ), 'affiliateId', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$users     = $this->users_for( wp_list_pluck( $rows, 'userId' ) );
		$fields    = $this->field_definitions();
		$creatives = $this->creative_ids();

		// Columns kept elsewhere in the record, not as registration answers.
		$own_columns = array( 'firstName', 'lastName', 'email', 'websiteUrl' );

		$records = array();
		foreach ( $rows as $row ) {

			$id   = (int) $row['affiliateId'];
			$user = isset( $users[ (int) $row['userId'] ] ) ? $users[ (int) $row['userId'] ] : array();
			$data = $this->unserialize( isset( $row['userData'] ) ? $row['userData'] : '' );
			$data = is_array( $data ) ? $data : array();

			// Registration answers: the address, phone and company columns, and
			// custom fields from the serialized userData, under the form's labels.
			$answers = array();
			foreach ( $fields as $column => $field ) {
				if ( in_array( $column, $own_columns, true ) ) {
					continue;
				}
				if ( 'custom' === $field['type'] ) {
					$value = isset( $data[ $column ] ) ? $data[ $column ] : '';
				} else {
					$value = isset( $row[ $column ] ) ? $row[ $column ] : '';
				}
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$answers[ $field['name'] ] = (string) $value;
				} elseif ( is_array( $value ) && $value ) {
					$answers[ $field['name'] ] = $value;
				}
			}
			// Custom answers whose field has since been removed from the form.
			foreach ( $data as $key => $value ) {
				if ( ! isset( $fields[ $key ] ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$answers[ ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) ) ] = (string) $value;
				}
			}
			if ( ! empty( $row['nameOnCheck'] ) ) {
				$answers[ __( 'Name on check', 'woo-coupon-usage' ) ] = (string) $row['nameOnCheck'];
			}

			// The affiliate's own bounty is what their commission was worked out with.
			$rate      = null;
			$rate_type = null;
			if ( in_array( $row['bountyType'], array( 'percent', 'fixed' ), true ) && null !== $row['bountyAmount'] && '' !== (string) $row['bountyAmount'] && is_numeric( $row['bountyAmount'] ) ) {
				$rate      = (float) $row['bountyAmount'];
				$rate_type = 'fixed' === $row['bountyType'] ? 'flat_order' : 'percent';
			}

			$bank = array();
			if ( ! empty( $row['bankDetails'] ) ) {
				// Free text: account name, number, IBAN... as the affiliate typed it.
				$bank['other'] = (string) $row['bankDetails'];
			}

			// What their links carried: the ID ("wpam_id", and "wpam_refkey" with an
			// ID), the email ("wpam_refkey" with an email) and the packed keys of the
			// old "wpam_refkey" links, one per creative.
			$identifiers = array( (string) $id );
			if ( ! empty( $row['email'] ) ) {
				$identifiers[] = (string) $row['email'];
			}
			$identifiers = array_merge( $identifiers, $this->packed_refkeys( (string) $row['uniqueRefKey'], $creatives ) );

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => (int) $row['userId'],
					'email'         => (string) $row['email'],
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => (string) $row['firstName'],
					'last_name'     => (string) $row['lastName'],
					'status'        => $this->status( $row['status'] ),
					// Written with date() (UTC) by every path but one add-on helper.
					'date'          => $this->date( $row['dateCreated'], true ),
					'rate'          => $rate,
					'rate_type'     => $rate_type,
					'payment_email' => (string) $row['paypalEmail'],
					'payout_method' => $this->payout_method( (string) $row['paymentMethod'] ),
					'bank'          => $bank,
					'website'       => (string) $row['websiteUrl'],
					'fields'        => $answers,
					'identifiers'   => $identifiers,
					// "wpam_id" and a numeric "wpam_refkey" are the affiliate ID.
					'link_id'       => (string) $id,
				)
			);
		}

		return $records;
	}

	/**
	 * An Affiliates Manager status in the common form.
	 *
	 * "approved" and "confirmed" affiliates were accepted but had not finished
	 * setting up (agreeing to the terms, adding payment details) - they are
	 * affiliates here.
	 *
	 * @param string $status
	 * @return string
	 */
	protected function status( $status ) {
		switch ( $status ) {
			case 'active':
			case 'confirmed':
			case 'approved':
				return 'active';
			case 'applied':
				return 'pending';
			case 'declined':
			case 'blocked':
				return 'rejected';
			default:
				return 'inactive';
		}
	}

	/**
	 * An affiliate's payout method in the common form.
	 *
	 * @param string $method paypal | check | manual | bank
	 * @return string
	 */
	protected function payout_method( $method ) {
		switch ( $method ) {
			case 'paypal':
				return 'paypal';
			case 'bank':
				return 'bank';
			case 'check':
			case 'manual':
				return 'manual';
			default:
				return '';
		}
	}

	/**
	 * The registration form's fields, by column / userData key.
	 *
	 * @return array databaseField => array( 'name' => label, 'type' => 'base'|'custom' )
	 */
	protected function field_definitions() {

		if ( null !== $this->field_definitions ) {
			return $this->field_definitions;
		}

		// The base fields the plugin installs (DatabaseInstaller.php), in case the
		// table is gone or a label is missing.
		$defaults = array(
			'phoneNumber'    => 'Phone Number',
			'addressLine1'   => 'Address Line 1',
			'addressLine2'   => 'Address Line 2',
			'addressCity'    => 'City',
			'addressState'   => 'State',
			'addressZipCode' => 'Zip Code',
			'addressCountry' => 'Country',
			'companyName'    => 'Company Name',
		);

		$fields = array();
		foreach ( $defaults as $column => $label ) {
			$fields[ $column ] = array(
				'name' => $label,
				'type' => 'base',
			);
		}

		$table = $this->table( 'wpam_affiliates_fields' );
		if ( $this->table_exists( $table ) ) {
			foreach ( $this->rows( "SELECT `type`, `name`, `databaseField` FROM `{$table}` ORDER BY `order` ASC, affiliateFieldId ASC" ) as $row ) {
				$column = (string) $row['databaseField'];
				if ( '' === $column ) {
					continue;
				}
				// A base field is only an answer if it is one of the columns above.
				if ( 'custom' !== $row['type'] && ! isset( $defaults[ $column ] ) ) {
					continue;
				}
				$fields[ $column ] = array(
					'name' => '' !== (string) $row['name'] ? (string) $row['name'] : $column,
					'type' => 'custom' === $row['type'] ? 'custom' : 'base',
				);
			}
		}

		$this->field_definitions = $fields;
		return $fields;
	}

	/* ---------------------------------------------------------------------
	 * Legacy "wpam_refkey" keys
	 * ------------------------------------------------------------------ */

	/**
	 * The creatives whose packed keys are recorded (deleted ones too: old links
	 * may still point at them).
	 *
	 * Each creative adds one key per affiliate to the identifiers recorded, so
	 * they are capped at the newest 200 - every creative on all but unusual
	 * sites - plus the default creative, whose links the affiliate area shows.
	 *
	 * @return int[]
	 */
	protected function creative_ids() {
		if ( null === $this->creative_ids ) {
			$table = $this->table( 'wpam_creatives' );
			$ids   = $this->table_exists( $table )
				? array_map( 'intval', wp_list_pluck( $this->rows( "SELECT creativeId FROM `{$table}` ORDER BY creativeId DESC LIMIT 200" ), 'creativeId' ) )
				: array();

			$default = get_option( 'wpam_default_creative_id', 0 );
			if ( is_numeric( $default ) && (int) $default > 0 && ! in_array( (int) $default, $ids, true ) ) {
				$ids[] = (int) $default;
			}

			$this->creative_ids = $ids;
		}
		return $this->creative_ids;
	}

	/**
	 * The packed "wpam_refkey" values an affiliate's old links carried: the
	 * affiliate's 20-byte key and a creative ID, packed and encoded as
	 * WPAM_Tracking_TrackingKey::pack() does.
	 *
	 * @param string $unique_ref_key The affiliate's uniqueRefKey column.
	 * @param int[]  $creative_ids
	 * @return string[]
	 */
	protected function packed_refkeys( $unique_ref_key, $creative_ids ) {

		// A 20-byte SHA-1, encoded six bits per character, is 27 characters.
		if ( 27 !== strlen( $unique_ref_key ) || ! preg_match( '/^[0-9A-Za-z,\-]+$/', $unique_ref_key ) || ! $creative_ids ) {
			return array();
		}

		$raw  = $this->string_to_bin( $unique_ref_key );
		$keys = array();
		foreach ( $creative_ids as $creative_id ) {
			// pack( "a20LL" ): machine byte order, little-endian on the servers it ran on.
			$keys[] = $this->bin_to_string( pack( 'a20VV', $raw, (int) $creative_id, 0 ) );
		}
		return $keys;
	}

	/**
	 * WPAM_Util_BinConverter::binToString(), six bits per character.
	 *
	 * @param string $bin
	 * @return string
	 */
	protected function bin_to_string( $bin ) {

		$chars = self::KEY_ALPHABET;
		$p     = 0;
		$q     = strlen( $bin );
		$w     = 0;
		$have  = 0;
		$out   = '';

		while ( true ) {
			if ( $have < 6 ) {
				if ( $p < $q ) {
					$w    |= ord( $bin[ $p++ ] ) << $have;
					$have += 8;
				} else {
					if ( 0 === $have ) {
						break;
					}
					$have = 6;
				}
			}
			$out  .= $chars[ $w & 0x3F ];
			$w   >>= 6;
			$have -= 6;
		}

		return $out;
	}

	/**
	 * WPAM_Util_BinConverter::stringToBin(), the reverse of bin_to_string()
	 * (with a guard against reading past the end, which the original lacks).
	 *
	 * @param string $str
	 * @return string
	 */
	protected function string_to_bin( $str ) {

		$length   = strlen( $str );
		$out      = '';
		$at       = 0;
		$rshift   = 0;
		$char_out = chr( 0 );

		while ( $at < $length ) {
			$char_in = strcspn( self::KEY_ALPHABET, $str[ $at++ ] );
			if ( $rshift > 0 ) {
				$char_out |= chr( ( $char_in << ( 8 - $rshift ) ) & 0xFF );
				$out      .= $char_out;
				$char_out  = chr( 0 );
				if ( $at >= $length ) {
					break;
				}
			}
			$char_out |= chr( $char_in >> $rshift );
			$rshift   += 2;
			if ( 8 === $rshift ) {
				$rshift = 0;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Referrals: the ledger
	 * ------------------------------------------------------------------ */

	/**
	 * Commission records, from the credits (and positive adjustments) in the ledger.
	 *
	 * A credit only partly covered by payouts is split into a paid part
	 * ("{id}p") and an unpaid part ("{id}u"). The cursor can therefore stop
	 * between the two parts of one transaction.
	 *
	 * Which records a credit makes changes as payouts are made, so every record
	 * carries the transaction ID as its group: a later run then skips the parts
	 * of a credit an earlier run imported in another shape.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$table = $this->table( 'wpam_transactions' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$after     = (string) $after;
		$from      = '';
		$resume_id = 0;
		$resume_at = 0;
		if ( preg_match( '/^(\d+)([a-z])$/', $after, $m ) ) {
			// Stopped part-way through a split credit: read that transaction again,
			// and leave out the part(s) already returned (and, should the ledger
			// have changed since, its whole-credit record too).
			$from      = (string) max( 0, (int) $m[1] - 1 );
			$resume_id = (int) $m[1];
			$resume_at = $this->part_rank( $m[2] );
		} elseif ( '' !== $after ) {
			$from = (string) (int) $after;
		}

		$records = array();

		while ( count( $records ) < $limit ) {

			$rows = $this->batch( $table, 'transactionId', $from, $limit );
			if ( ! $rows ) {
				break;
			}

			foreach ( $this->referrals_from_rows( $rows ) as $record ) {
				if ( $resume_id && preg_match( '/^(\d+)([a-z]?)$/', $record['source_id'], $part ) && (int) $part[1] === $resume_id && $this->part_rank( $part[2] ) <= $resume_at ) {
					continue;
				}
				$records[] = $record;
			}
			$resume_id = 0;

			$last = end( $rows );
			$from = (string) $last['transactionId'];

			if ( count( $rows ) < $limit ) {
				break;
			}
		}

		return $records;
	}

	/**
	 * Order of the records one transaction can make: the whole credit, its
	 * paid part, its unpaid part.
	 *
	 * @param string $suffix '' | 'p' | 'u'
	 * @return int
	 */
	protected function part_rank( $suffix ) {
		switch ( $suffix ) {
			case 'p':
				return 1;
			case 'u':
				return 2;
			default:
				return 0;
		}
	}

	/**
	 * The commission records for a batch of transactions.
	 *
	 * @param array[] $rows
	 * @return array[]
	 */
	protected function referrals_from_rows( $rows ) {

		$this->load_plans( wp_list_pluck( $rows, 'affiliateId' ) );
		$orders = $this->validated_orders( $rows );

		$records = array();
		foreach ( $rows as $row ) {

			$affiliate_id = (int) $row['affiliateId'];
			$id           = (int) $row['transactionId'];
			if ( empty( $this->plans[ $affiliate_id ][ $id ] ) ) {
				continue;
			}

			foreach ( $this->plans[ $affiliate_id ][ $id ] as $part ) {
				$records[] = $this->referral_record(
					array(
						'source_id'           => $id . $part['suffix'],
						'affiliate_source_id' => $affiliate_id,
						'order_id'            => isset( $orders[ $id ] ) ? $orders[ $id ] : 0,
						'amount'              => $part['cents'] / 100,
						'status'              => $part['status'],
						// Written with date() (UTC).
						'date'                => $this->date( $row['dateCreated'], true ),
						'group_id'            => (string) $id,
					)
				);
			}
		}

		return $records;
	}

	/**
	 * Work out the paid / unpaid split for affiliates not planned yet.
	 *
	 * @param array $affiliate_ids
	 */
	protected function load_plans( $affiliate_ids ) {

		$batch         = array_values( array_unique( array_map( 'intval', (array) $affiliate_ids ) ) );
		$affiliate_ids = array_values( array_diff( $batch, array_keys( $this->plans ) ) );
		if ( ! $affiliate_ids ) {
			return;
		}

		// Keep memory in check on long requests - but not by forgetting the plans
		// of this batch's other affiliates, whose transactions would then be lost.
		if ( count( $this->plans ) > $this->plan_cache_limit ) {
			$this->plans = array_intersect_key( $this->plans, array_flip( $batch ) );
		}

		$table  = $this->table( 'wpam_transactions' );
		$ledger = array();
		foreach ( array_chunk( $affiliate_ids, 200 ) as $chunk ) {
			$sql = "SELECT transactionId, affiliateId, dateCreated, amount, type, referenceId, status FROM `{$table}` WHERE affiliateId IN (" . implode( ',', $chunk ) . ') ORDER BY affiliateId ASC, dateCreated ASC, transactionId ASC';
			foreach ( $this->rows( $sql ) as $row ) {
				$ledger[ (int) $row['affiliateId'] ][] = $row;
			}
		}

		foreach ( $affiliate_ids as $affiliate_id ) {
			$this->plans[ $affiliate_id ] = $this->plan( isset( $ledger[ $affiliate_id ] ) ? $ledger[ $affiliate_id ] : array() );
		}
	}

	/**
	 * Split one affiliate's ledger into commission records.
	 *
	 * - Earnings: credits, and positive adjustments (as commission with no
	 *   order). Admin-entered rows of the "wrong" sign count as adjustments:
	 *   a negative credit takes away, a positive payout row adds.
	 * - A refund row reverses the credit for the same referenceId: that credit
	 *   is rejected (the refund itself is not a record). A refund with no credit
	 *   to reverse is a negative adjustment.
	 * - Payouts (pending and confirmed, as Affiliates Manager's balance counts
	 *   both) pay the oldest earnings first: those are "paid", and the earning
	 *   a payout only partly covered is split into a paid and an unpaid part.
	 * - Negative adjustments then take away from the newest unpaid earnings
	 *   (an earning reduced to nothing keeps its order, with no commission).
	 *   More paid out or taken away than was earned - a negative balance,
	 *   usually a refund after a payout - cannot be held here and is dropped
	 *   (get_warnings() reports it, see negative_balances()).
	 *
	 * The unpaid total is then Affiliates Manager's balance.
	 *
	 * @param array[] $rows The affiliate's transactions, oldest first.
	 * @return array transaction ID => array of array( 'suffix', 'cents', 'status' )
	 */
	protected function plan( $rows ) {

		$earnings = array();
		$by_ref   = array();
		$refunds  = array();
		$payouts  = 0;
		$negative = 0;

		foreach ( $rows as $row ) {

			if ( 'failed' === $row['status'] ) {
				continue;
			}

			$cents = (int) round( (float) $row['amount'] * 100 );
			$ref   = trim( (string) $row['referenceId'] );

			switch ( $row['type'] ) {
				case 'credit':
					if ( $cents > 0 ) {
						if ( '' !== $ref ) {
							$by_ref[ $ref ][] = count( $earnings );
						}
						$earnings[] = $this->earning( $row, $cents );
					} else {
						$negative -= $cents;
					}
					break;
				case 'refund':
					$refunds[] = array( $ref, abs( $cents ) );
					break;
				case 'payout':
					if ( $cents < 0 ) {
						$payouts -= $cents;
					} elseif ( $cents > 0 ) {
						$earnings[] = $this->earning( $row, $cents );
					}
					break;
				default: // adjustment
					if ( $cents > 0 ) {
						$earnings[] = $this->earning( $row, $cents );
					} else {
						$negative -= $cents;
					}
			}
		}

		// Refunds reverse the credit for the same sale.
		foreach ( $refunds as $refund ) {
			list( $ref, $cents ) = $refund;
			$reversed            = false;
			if ( '' !== $ref && ! empty( $by_ref[ $ref ] ) ) {
				foreach ( $by_ref[ $ref ] as $index ) {
					if ( ! $earnings[ $index ]['rejected'] ) {
						$earnings[ $index ]['rejected'] = true;
						$reversed                       = true;
						break;
					}
				}
			}
			if ( ! $reversed ) {
				$negative += $cents;
			}
		}

		// Payouts pay the oldest commission first.
		foreach ( $earnings as $index => $earning ) {
			if ( $earning['rejected'] ) {
				continue;
			}
			$covered                      = min( $earning['cents'], $payouts );
			$earnings[ $index ]['paid']   = $covered;
			$earnings[ $index ]['unpaid'] = $earning['cents'] - $covered;
			$payouts                     -= $covered;
		}

		// Negative adjustments take away from the newest unpaid commission.
		for ( $index = count( $earnings ) - 1; $index >= 0 && $negative > 0; $index-- ) {
			if ( $earnings[ $index ]['rejected'] || $earnings[ $index ]['unpaid'] <= 0 ) {
				continue;
			}
			$taken                         = min( $earnings[ $index ]['unpaid'], $negative );
			$earnings[ $index ]['unpaid'] -= $taken;
			$negative                     -= $taken;
		}

		$plan = array();
		foreach ( $earnings as $earning ) {
			$id = $earning['id'];
			if ( $earning['rejected'] ) {
				$plan[ $id ] = array( $this->part( '', $earning['cents'], 'rejected' ) );
			} elseif ( $earning['paid'] > 0 && $earning['unpaid'] > 0 ) {
				$plan[ $id ] = array( $this->part( 'p', $earning['paid'], 'paid' ), $this->part( 'u', $earning['unpaid'], 'unpaid' ) );
			} elseif ( $earning['paid'] > 0 ) {
				$plan[ $id ] = array( $this->part( '', $earning['paid'], 'paid' ) );
			} else {
				$plan[ $id ] = array( $this->part( '', $earning['unpaid'], 'unpaid' ) );
			}
		}

		return $plan;
	}

	/**
	 * @param array $row
	 * @param int   $cents
	 * @return array
	 */
	protected function earning( $row, $cents ) {
		return array(
			'id'       => (int) $row['transactionId'],
			'cents'    => $cents,
			'paid'     => 0,
			'unpaid'   => $cents,
			'rejected' => false,
		);
	}

	/**
	 * @param string $suffix
	 * @param int    $cents
	 * @param string $status
	 * @return array
	 */
	protected function part( $suffix, $cents, $status ) {
		return array(
			'suffix' => $suffix,
			'cents'  => (int) $cents,
			'status' => $status,
		);
	}

	/**
	 * The WooCommerce order behind each credit, where the order agrees it is
	 * the affiliate's: referenceId is shared with other integrations.
	 *
	 * @param array[] $rows Transactions.
	 * @return array transaction ID => order ID
	 */
	protected function validated_orders( $rows ) {

		$candidates = array();
		foreach ( $rows as $row ) {
			$ref = trim( (string) $row['referenceId'] );
			if ( 'credit' === $row['type'] && preg_match( '/^\d+$/', $ref ) && (int) $ref > 0 ) {
				$candidates[ (int) $row['transactionId'] ] = $row;
			}
		}
		if ( ! $candidates ) {
			return array();
		}

		$statuses = class_exists( 'WCUsage_Migration_Writer' ) ? WCUsage_Migration_Writer::order_statuses( wp_list_pluck( $candidates, 'referenceId' ) ) : array();

		$orders = array();
		foreach ( $candidates as $id => $row ) {
			$order_id = (int) $row['referenceId'];
			if ( ! isset( $statuses[ $order_id ] ) ) {
				continue;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}

			$affiliate = (string) (int) $row['affiliateId'];
			$tagged    = false;
			foreach ( array( '_wpam_id', '_wpam_refkey' ) as $key ) {
				$value = $order->get_meta( $key, true );
				if ( is_scalar( $value ) && trim( (string) $value ) === $affiliate ) {
					$tagged = true;
					break;
				}
			}
			$buyer = strtolower( trim( (string) $row['email'] ) );
			if ( $tagged || ( '' !== $buyer && strtolower( trim( (string) $order->get_billing_email() ) ) === $buyer ) ) {
				$orders[ $id ] = $order_id;
			}
		}

		return $orders;
	}

	/* ---------------------------------------------------------------------
	 * Payouts
	 * ------------------------------------------------------------------ */

	/**
	 * Payout rows of the ledger. Affiliates Manager does not record which
	 * commission a payout paid, so none is linked.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$rows = $this->batch( $this->table( 'wpam_transactions' ), 'transactionId', $after, $limit, "type = 'payout' AND amount < 0" );
		if ( ! $rows ) {
			return array();
		}

		$methods = array();
		$ids     = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'affiliateId' ) ) );
		$table   = $this->table( 'wpam_affiliates' );
		if ( $ids && $this->table_exists( $table ) ) {
			foreach ( $this->rows( "SELECT affiliateId, paymentMethod FROM `{$table}` WHERE affiliateId IN (" . implode( ',', $ids ) . ')' ) as $affiliate ) {
				$methods[ (int) $affiliate['affiliateId'] ] = (string) $affiliate['paymentMethod'];
			}
		}

		$records = array();
		foreach ( $rows as $row ) {

			switch ( $row['status'] ) {
				case 'failed':
					$status = 'cancelled';
					break;
				case 'pending':
					// A PayPal MassPay payout waiting to be reconciled. Its amount is
					// already out of the affiliate's balance, and the commission it
					// covers is marked paid (see plan()).
					$status = 'pending';
					break;
				default:
					$status = 'paid';
			}

			$reference = trim( (string) $row['referenceId'] );
			$method    = isset( $methods[ (int) $row['affiliateId'] ] ) ? $methods[ (int) $row['affiliateId'] ] : '';
			if ( '' !== $reference ) {
				// Only MassPay payouts carry a reference: the PayPal correlation ID.
				$method = 'paypal';
			}

			$records[] = $this->payout_record(
				array(
					'source_id'           => (int) $row['transactionId'],
					'affiliate_source_id' => (int) $row['affiliateId'],
					'amount'              => -1 * (float) $row['amount'],
					'status'              => $status,
					'method'              => 'check' === $method ? 'check' : $this->payout_method( $method ),
					'date'                => $this->date( $row['dateCreated'], true ),
					// When it was recorded, written with date() (UTC). Not dateModified:
					// MySQL rewrites that column (ON UPDATE CURRENT_TIMESTAMP) whenever
					// the row changes, in the database's own time zone.
					'date_paid'           => 'paid' === $status ? $this->date( $row['dateCreated'], true ) : '',
					'transaction_id'      => $reference,
				)
			);
		}

		return $records;
	}

	/* ---------------------------------------------------------------------
	 * Visits and creatives
	 * ------------------------------------------------------------------ */

	/**
	 * Clicks. Affiliates Manager does not keep the landing page or whether the
	 * click led to a sale.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @param string $since
	 * @return array[]
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		global $wpdb;

		$where = $since ? $wpdb->prepare( 'dateCreated >= %s', get_gmt_from_date( $since . ' 00:00:00' ) ) : '';
		$rows  = $this->batch( $this->table( 'wpam_tracking_tokens' ), 'trackingTokenId', $after, $limit, $where );

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['trackingTokenId'],
					'affiliate_source_id' => (int) $row['sourceAffiliateId'],
					'referrer'            => (string) $row['referer'],
					'ip'                  => (string) $row['ipAddress'],
					// Written with date() (UTC).
					'date'                => $this->date( $row['dateCreated'], true ),
				)
			);
		}

		return $records;
	}

	/**
	 * @return array[]
	 */
	public function get_creatives() {

		$rows = $this->batch( $this->table( 'wpam_creatives' ), 'creativeId', '', 10000, "status <> 'deleted'" );

		$landing = (string) get_option( 'wpam_landing_page', '' );

		$records = array();
		foreach ( $rows as $row ) {

			$image = (string) $row['image'];
			if ( '' === $image && ! empty( $row['imagePostId'] ) ) {
				// Creatives made by older versions kept an attachment ID.
				$image = (string) wp_get_attachment_url( (int) $row['imagePostId'] );
			}

			// The page the creative links to: the landing page (or home page) with
			// the creative's slug appended (TrackingLinkBuilder::getUrl()).
			$slug = trim( (string) $row['slug'] );
			$url  = '' !== $landing ? trailingslashit( $landing ) . $slug : home_url( '/' . $slug );

			$records[] = array(
				'source_id'   => (string) (int) $row['creativeId'],
				'name'        => (string) $row['name'],
				'description' => '',
				'type'        => 'image' === $row['type'] ? 'image' : 'text',
				'image'       => $image,
				'url'         => $url,
				'text'        => (string) $row['linkText'],
				'alt'         => (string) $row['altText'],
				'status'      => 'active' === $row['status'] ? 'active' : 'inactive',
			);
		}

		return $records;
	}
}
