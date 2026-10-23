<?php
/**
 * Migration source: SliceWP.
 *
 * Read from SliceWP 1.2.x's own tables (includes/base/<object>/class-object-db-*.php):
 *
 *   slicewp_affiliates   id, user_id, date_created (UTC), payment_email, website,
 *                        status (active|inactive|pending|rejected), parent_id
 *   slicewp_affiliate_meta  payout_method, promotional_methods, reject_reason,
 *                        commission_rate_sale / commission_rate_type_sale (Pro: the
 *                        affiliate's own rate), custom registration fields (by field name)
 *   slicewp_collections  id, object_context ('affiliate'), type ('group'), name - affiliate
 *                        groups (Pro), with commission_rate_sale / commission_rate_type_sale
 *                        in slicewp_collection_meta and their members in
 *                        slicewp_collection_object_relationships (object_context 'affiliate')
 *   slicewp_commissions  id, affiliate_id, visit_id, date_created (UTC), type, status
 *                        (paid|unpaid|pending|rejected), reference (order ID), origin
 *                        ('woo' for WooCommerce), amount, parent_id, payment_id, currency
 *   slicewp_payments     id, affiliate_id, amount, payout_method, status
 *                        (paid|unpaid|processing|failed), payout_id, date_created (UTC)
 *   slicewp_visits       id, affiliate_id, date_created (UTC), ip_address, landing_url,
 *                        referrer_url, commission_id
 *   slicewp_creatives    id, name, description, type (image|text|long_text), image_url,
 *                        alt_text, text, landing_url, status
 *
 * Settings are the "slicewp_settings" option. SliceWP writes no order meta:
 * commissions are linked to orders only through "reference" + origin "woo".
 * Amounts are in SliceWP's own currency setting ("active_currency", USD by
 * default), which may not be the store's.
 *
 * Payments: a payout makes one "unpaid" payment per affiliate and sets the
 * commissions' payment_id, but they stay "unpaid" until the payment is marked
 * paid. A "processing" payment is one PayPal / Stripe payouts (Pro) are still
 * sending: it becomes a pending payout here and its commission paid.
 *
 * Only what is confirmed is read: the free plugin's code, plus the affiliates'
 * own rates and the groups, whose storage FluentAffiliate's SliceWP migrator
 * reads the same way (fluent-affiliate 1.6.5, app/Services/Migrator/Providers/
 * SliceWP.php) - the rate keys also appear in the free plugin's own code. Rate
 * types are SliceWP's 'percentage' / 'fixed_amount', a fixed amount being per
 * product or per order by the store-wide commission_fixed_amount_rate_basis.
 * The rest of the Pro add-ons' data (custom slugs, lifetime customers,
 * multi-level parents) is listed in get_not_migrated() instead.
 *
 * Affiliate coupons (Pro "Affiliate Coupons" add-on) are the exception: where
 * the add-on keeps them is not public, but SliceWP's developer documents
 * slicewp_get_affiliate_coupons( $affiliate_id ) for reading them
 * (wordpress.org/support/topic/get-associated-coupon-details/). So while the
 * add-on is active they are read through it, and an entry is only used when
 * it names one existing WooCommerce coupon beyond doubt (see coupon_from_entry()).
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Source_SliceWP extends WCUsage_Migration_Source {

	/**
	 * @return array
	 */
	protected function settings_option() {
		$settings = get_option( 'slicewp_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * @return string[]
	 */
	public function get_supported() {
		$supported = array( 'affiliates', 'legacy_links', 'registrations', 'rates', 'referrals', 'payouts', 'payout_details', 'visits', 'creatives', 'settings', 'product_rates' );
		if ( $this->table_exists( $this->table( 'slicewp_collections' ) ) && $this->count_rows( $this->table( 'slicewp_collections' ), "object_context = 'affiliate' AND type = 'group'" ) ) {
			$supported[] = 'groups';
		}
		if ( $this->coupon_reader() ) {
			$supported[] = 'coupons';
		}
		return $supported;
	}

	/**
	 * @return string[]
	 */
	public function get_not_migrated() {
		$lines = array();
		if ( ! $this->coupon_reader() ) {
			$lines[] = __( 'Coupons linked to affiliates by the Affiliate Coupons add-on (SliceWP Pro), unless the add-on is active while migrating: each affiliate gets a coupon here instead', 'woo-coupon-usage' );
		}
		return array_merge(
			$lines,
			array(
				__( 'Custom affiliate slugs: old referral links with the affiliate ID still work', 'woo-coupon-usage' ),
				__( 'Multi-level affiliate parents (past multi-level commission is still added to the parent\'s balance)', 'woo-coupon-usage' ),
				__( 'Lifetime customer links', 'woo-coupon-usage' ),
				__( 'Commission switched off on product categories or single variations', 'woo-coupon-usage' ),
				__( 'Commission rates for subscriptions and other commission types (only the sale rate is migrated)', 'woo-coupon-usage' ),
				__( 'Shipping and tax exclusion, email notifications and the other program settings', 'woo-coupon-usage' ),
			)
		);
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		global $wpdb;

		$warnings = array();

		// Affiliate coupons: read through the add-on, or why they cannot be.
		if ( $this->coupon_reader() ) {
			$links = $this->all_coupon_links();
			if ( $links['complete'] ) {
				$warnings[] = sprintf(
					/* translators: %d: number of coupons */
					_n( '%d coupon is linked to a SliceWP affiliate by the Affiliate Coupons add-on, read through the add-on itself. Please check the coupons assigned after migrating.', '%d coupons are linked to SliceWP affiliates by the Affiliate Coupons add-on, read through the add-on itself. Please check the coupons assigned after migrating.', $links['total'], 'woo-coupon-usage' ),
					$links['total']
				);
			} else {
				$warnings[] = sprintf(
					/* translators: 1: number of coupons, 2: number of affiliates */
					_n( 'At least %1$d coupon is linked to SliceWP affiliates by the Affiliate Coupons add-on, read through the add-on itself: this preview only checked the first %2$d affiliates, and every affiliate\'s are read when migrating. Please check the coupons assigned after migrating.', 'At least %1$d coupons are linked to SliceWP affiliates by the Affiliate Coupons add-on, read through the add-on itself: this preview only checked the first %2$d affiliates, and every affiliate\'s are read when migrating. Please check the coupons assigned after migrating.', $links['total'], 'woo-coupon-usage' ),
					$links['total'],
					$links['checked']
				);
			}
			if ( $links['skipped'] ) {
				$warnings[] = sprintf(
					/* translators: %d: number of entries */
					_n( '%d coupon the Affiliate Coupons add-on lists could not be matched to exactly one WooCommerce coupon, so it is not assigned.', '%d coupons the Affiliate Coupons add-on lists could not be matched to exactly one WooCommerce coupon, so they are not assigned.', $links['skipped'], 'woo-coupon-usage' ),
					$links['skipped']
				) . ( $links['complete'] ? '' : ' ' . __( '(Only among the affiliates this preview checked.)', 'woo-coupon-usage' ) );
			}
		} elseif ( in_array( 'affiliate-coupons', (array) get_option( 'slicewp_active_add_ons', array() ), true ) ) {
			$warnings[] = __( 'The SliceWP Affiliate Coupons add-on was switched on, but SliceWP Pro is not active now, so the coupons it linked to affiliates cannot be read. Activate SliceWP Pro to migrate them; otherwise each affiliate gets a new coupon.', 'woo-coupon-usage' );
		}

		// Payments not paid yet.
		$payments = $this->table( 'slicewp_payments' );
		if ( $this->table_exists( $payments ) ) {
			$open = array();
			foreach ( $this->rows( "SELECT status, COUNT(*) AS total, SUM(amount) AS amount FROM `{$payments}` WHERE status IN ('processing', 'unpaid') GROUP BY status" ) as $row ) {
				$open[ $row['status'] ] = array( (int) $row['total'], wc_format_decimal( $this->money( $row['amount'] ), 2 ) );
			}
			if ( isset( $open['processing'] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: number of payments, 2: their total */
					_n( '%1$d SliceWP payment (%2$s in total) is still processing: it is imported as a pending payout and its commission as paid, so it is not paid out twice. Mark the payout paid here once it has gone through.', '%1$d SliceWP payments (%2$s in total) are still processing: they are imported as pending payouts and their commission as paid, so it is not paid out twice. Mark the payouts paid here once they have gone through.', $open['processing'][0], 'woo-coupon-usage' ),
					$open['processing'][0],
					$open['processing'][1]
				);
			}
			if ( isset( $open['unpaid'] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: number of payments, 2: their total */
					_n( '%1$d SliceWP payment (%2$s in total) was created in a payout but never paid: its commission is imported as unpaid, to be paid from Coupon Affiliates. Do not also pay it in SliceWP.', '%1$d SliceWP payments (%2$s in total) were created in a payout but never paid: their commission is imported as unpaid, to be paid from Coupon Affiliates. Do not also pay them in SliceWP.', $open['unpaid'][0], 'woo-coupon-usage' ),
					$open['unpaid'][0],
					$open['unpaid'][1]
				);
			}
		}

		// Commission in another currency than the store's.
		$store       = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', '' );
		$commissions = $this->table( 'slicewp_commissions' );
		if ( '' !== $store && $this->has_column( $commissions, 'currency' ) ) {
			$other = array();
			foreach ( $this->rows( $wpdb->prepare( "SELECT UPPER(currency) AS currency, COUNT(*) AS total FROM `{$commissions}` WHERE currency <> '' AND UPPER(currency) <> %s GROUP BY UPPER(currency) ORDER BY currency ASC", strtoupper( $store ) ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$other[ $row['currency'] ] = (int) $row['total'];
			}
			if ( $other ) {
				$warnings[] = sprintf(
					/* translators: 1: number of commissions, 2: their currencies, 3: the store's currency */
					_n( '%1$d SliceWP commission is recorded in %2$s, not the store\'s currency (%3$s): SliceWP keeps commission in its own currency setting. Its amount is imported as it is, without conversion.', '%1$d SliceWP commissions are recorded in %2$s, not the store\'s currency (%3$s): SliceWP keeps commission in its own currency setting. Their amounts are imported as they are, without conversion.', array_sum( $other ), 'woo-coupon-usage' ),
					array_sum( $other ),
					implode( ', ', array_keys( $other ) ),
					$store
				);
			}
		}

		return $warnings;
	}

	/**
	 * @return array
	 */
	public function get_counts() {
		$counts = array(
			'affiliates' => $this->count_rows( $this->table( 'slicewp_affiliates' ) ),
			'referrals'  => $this->count_rows( $this->table( 'slicewp_commissions' ) ),
			'payouts'    => $this->count_rows( $this->table( 'slicewp_payments' ), "status IN ('paid', 'processing')" ),
			'visits'     => $this->count_rows( $this->table( 'slicewp_visits' ) ),
			'creatives'  => $this->count_rows( $this->table( 'slicewp_creatives' ) ),
		);
		if ( $this->coupon_reader() ) {
			// A minimum on a large program (see all_coupon_links()).
			$counts['coupons'] = $this->all_coupon_links()['total'];
		}
		return $counts;
	}

	/**
	 * The Affiliate Coupons add-on's own reader, only there while SliceWP Pro
	 * and the add-on are active.
	 *
	 * @return callable|null
	 */
	protected function coupon_reader() {
		$reader = function_exists( 'slicewp_get_affiliate_coupons' ) ? 'slicewp_get_affiliate_coupons' : null;
		/**
		 * The function that returns a SliceWP affiliate's linked coupons.
		 *
		 * @param callable|null $reader
		 */
		$reader = apply_filters( 'wcusage_migration_slicewp_coupon_reader', $reader );
		return is_callable( $reader ) ? $reader : null;
	}

	/**
	 * Cached result of all_coupon_links().
	 *
	 * @var array|null
	 */
	protected $all_coupon_links = null;

	/**
	 * Every affiliate's linked coupons, for the preview's count and warnings.
	 *
	 * The add-on is asked once per affiliate, so on a large program the
	 * preview stops after the first affiliates, or a few seconds, and gives
	 * what it found as a minimum. Migrating reads every affiliate's.
	 *
	 * @return array total (coupons), skipped (entries left out), checked
	 *               (affiliates asked), complete (whether every affiliate was)
	 */
	protected function all_coupon_links() {
		if ( null !== $this->all_coupon_links ) {
			return $this->all_coupon_links;
		}
		$result = array(
			'total'    => 0,
			'skipped'  => 0,
			'checked'  => 0,
			'complete' => true,
		);
		/**
		 * How many affiliates' linked coupons the migration preview reads.
		 *
		 * @param int $limit
		 */
		$limit = max( 1, (int) apply_filters( 'wcusage_migration_slicewp_coupon_preview_limit', 500 ) );
		$start = microtime( true );
		$table = $this->table( 'slicewp_affiliates' );
		if ( $this->table_exists( $table ) ) {
			foreach ( $this->rows( "SELECT id FROM `{$table}` ORDER BY id ASC LIMIT " . ( $limit + 1 ) ) as $row ) {
				if ( $result['checked'] >= $limit || microtime( true ) - $start > 10 ) {
					$result['complete'] = false;
					break;
				}
				$links              = $this->coupons_of_affiliate( (int) $row['id'] );
				$result['total']   += count( $links['coupons'] );
				$result['skipped'] += $links['skipped'];
				$result['checked']++;
			}
		}
		$this->all_coupon_links = $result;
		return $result;
	}

	/**
	 * The WooCommerce coupons the add-on links to an affiliate.
	 *
	 * @param int $affiliate_id
	 * @return array coupons (coupon IDs), skipped (entries left out)
	 */
	protected function coupons_of_affiliate( $affiliate_id ) {
		$out    = array(
			'coupons' => array(),
			'skipped' => 0,
		);
		$reader = $this->coupon_reader();
		if ( ! $reader ) {
			return $out;
		}
		try {
			$entries = call_user_func( $reader, (int) $affiliate_id );
		} catch ( \Throwable $e ) {
			return $out;
		}
		if ( ! is_array( $entries ) ) {
			return $out;
		}
		foreach ( $entries as $entry ) {
			$coupon_id = $this->coupon_from_entry( $entry );
			if ( $coupon_id ) {
				$out['coupons'][] = $coupon_id;
			} else {
				$out['skipped']++;
			}
		}
		$out['coupons'] = array_values( array_unique( $out['coupons'] ) );
		return $out;
	}

	/**
	 * The WooCommerce coupon one of the add-on's entries stands for, or 0.
	 *
	 * Its return value is only documented as "an array with all linked coupons,
	 * containing specific data about the coupon", and the add-on links coupons
	 * of other integrations too (EDD, Restrict Content Pro...), whose IDs can be
	 * the same numbers as a WooCommerce coupon's. So an entry is only used when
	 * it names one existing WooCommerce coupon beyond doubt: a WC_Coupon; or an
	 * ID and a code that agree; or an ID or a code alone when the entry says it
	 * is WooCommerce's, or SliceWP tracks no other integration. An entry with
	 * two different IDs (its own and the coupon's, say) needs a code agreeing
	 * with one of them, and a code alone must belong to exactly one coupon.
	 *
	 * @param mixed $entry
	 * @return int
	 */
	protected function coupon_from_entry( $entry ) {

		if ( $entry instanceof WC_Coupon ) {
			return $this->live_coupon( (int) $entry->get_id() );
		}

		$integration = '';
		$ids         = array();
		$code        = '';

		if ( is_int( $entry ) || ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
			$ids[] = (int) $entry;
		} else {
			if ( is_object( $entry ) ) {
				$entry = method_exists( $entry, 'to_array' ) ? $entry->to_array() : get_object_vars( $entry );
			}
			if ( ! is_array( $entry ) ) {
				return 0;
			}
			foreach ( array( 'integration', 'origin' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && '' !== $entry[ $key ] ) {
					$integration = strtolower( $entry[ $key ] );
					break;
				}
			}
			// The coupon's own keys first; "id" may be the entry's own ID.
			foreach ( array( 'coupon_id', 'post_id', 'ID', 'id' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_numeric( $entry[ $key ] ) && (int) $entry[ $key ] > 0 ) {
					$ids[] = (int) $entry[ $key ];
				}
			}
			foreach ( array( 'code', 'coupon_code', 'coupon' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && '' !== trim( $entry[ $key ] ) ) {
					$code = trim( $entry[ $key ] );
					break;
				}
			}
		}

		if ( '' !== $integration && ! in_array( $integration, array( 'woo', 'woocommerce' ), true ) ) {
			return 0;
		}

		$ids     = array_values( array_unique( $ids ) );
		$by_code = '' !== $code ? $this->coupons_with_code( $code ) : array();

		// An ID and a code: the coupon both name, which with two different IDs
		// must be exactly one of them.
		if ( $ids && '' !== $code ) {
			$agree = array_values( array_intersect( $ids, $by_code ) );
			return 1 === count( $agree ) ? $agree[0] : 0;
		}
		if ( count( $ids ) > 1 ) {
			return 0;
		}

		$sure = '' !== $integration || $this->tracks_only_woocommerce();
		if ( $ids ) {
			return $sure ? $this->live_coupon( $ids[0] ) : 0;
		}
		return $sure && 1 === count( $by_code ) ? $by_code[0] : 0;
	}

	/**
	 * The published WooCommerce coupons with a code. WooCommerce codes are not
	 * case-sensitive, and more than one coupon can have the same one (a
	 * trashed coupon is not published, so it is left out).
	 *
	 * @param string $code
	 * @return int[]
	 */
	protected function coupons_with_code( $code ) {
		global $wpdb;
		$code = wc_format_coupon_code( $code );
		if ( '' === $code ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status = 'publish' AND LOWER(post_title) = %s ORDER BY ID ASC", $code ) ) );
	}

	/**
	 * A coupon ID when it is a WooCommerce coupon that has not been deleted.
	 *
	 * @param int $coupon_id
	 * @return int
	 */
	protected function live_coupon( $coupon_id ) {
		return $coupon_id > 0 && 'shop_coupon' === get_post_type( $coupon_id ) && 'trash' !== get_post_status( $coupon_id ) ? (int) $coupon_id : 0;
	}

	/**
	 * Whether WooCommerce is the only integration SliceWP tracks sales in.
	 *
	 * @return bool
	 */
	protected function tracks_only_woocommerce() {
		$s = $this->settings_option();
		return isset( $s['active_integrations'] ) && array( 'woo' ) === array_values( (array) $s['active_integrations'] );
	}

	/**
	 * @return array
	 */
	public function get_settings() {

		$s   = $this->settings_option();
		$out = array();

		if ( isset( $s['commission_rate_sale'] ) && is_numeric( $s['commission_rate_sale'] ) ) {
			$out['rate']      = (float) $s['commission_rate_sale'];
			$out['rate_type'] = $this->rate_type( isset( $s['commission_rate_type_sale'] ) ? $s['commission_rate_type_sale'] : 'percentage', isset( $s['commission_fixed_amount_rate_basis'] ) ? $s['commission_fixed_amount_rate_basis'] : 'product' );
		}
		if ( ! empty( $s['cookie_duration'] ) ) {
			$out['cookie_days'] = (int) $s['cookie_duration'];
		}
		if ( isset( $s['affiliate_credit'] ) ) {
			$out['credit_first'] = 'first' === $s['affiliate_credit'];
		}
		if ( ! empty( $s['active_currency'] ) && is_string( $s['active_currency'] ) ) {
			$out['currency'] = $s['active_currency'];
		}

		return $out;
	}

	/**
	 * SliceWP's rate type and fixed-amount basis, in the common form.
	 *
	 * @param string $type  'percentage' | 'fixed_amount'
	 * @param string $basis 'order' | 'product'
	 * @return string
	 */
	protected function rate_type( $type, $basis ) {
		if ( 'fixed_amount' === $type ) {
			return 'order' === $basis ? 'flat_order' : 'flat_product';
		}
		return 'percent';
	}

	/**
	 * @return array
	 */
	public function get_legacy_links() {
		$s = $this->settings_option();
		// SliceWP saves the keyword as typed and reads $_GET[ keyword ], which is
		// case-sensitive: keep its case, only dropping characters a parameter
		// name cannot carry.
		$keyword = ! empty( $s['affiliate_keyword'] ) && is_string( $s['affiliate_keyword'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $s['affiliate_keyword'] ) : '';
		$keyword = '' !== $keyword ? $keyword : 'aff';
		return array(
			'vars'    => array( $keyword ),
			'paths'   => array( $keyword ),
			'cookies' => array( 'slicewp_aff' ),
		);
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_affiliates( $after, $limit ) {

		$rows = $this->batch( $this->table( 'slicewp_affiliates' ), 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$ids   = wp_list_pluck( $rows, 'id' );
		$meta  = $this->meta_for( $this->table( 'slicewp_affiliate_meta' ), 'slicewp_affiliate_id', $ids );
		$users = $this->users_for( wp_list_pluck( $rows, 'user_id' ) );
		$umeta = $this->user_meta_for( wp_list_pluck( $rows, 'user_id' ), array( 'first_name', 'last_name' ) );

		$groups = $this->group_memberships( $ids );
		$basis  = $this->settings_option()['commission_fixed_amount_rate_basis'] ?? 'product';

		// Meta keys that are not registration answers.
		$reserved = array( 'payout_method', 'promotional_methods', 'reject_reason' );

		$records = array();
		foreach ( $rows as $row ) {

			$id    = (int) $row['id'];
			$m     = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$user  = isset( $users[ (int) $row['user_id'] ] ) ? $users[ (int) $row['user_id'] ] : array();
			$names = isset( $umeta[ (int) $row['user_id'] ] ) ? $umeta[ (int) $row['user_id'] ] : array();

			$fields = array();
			foreach ( $m as $key => $value ) {
				if ( in_array( $key, $reserved, true ) || 0 === strpos( $key, '_' ) || 0 === strpos( $key, 'commission_rate' ) || false !== stripos( $key, 'slug' ) || ( is_array( $value ) && ! $value ) ) {
					continue;
				}
				if ( is_scalar( $value ) || is_array( $value ) ) {
					$fields[ ucwords( str_replace( array( '_', '-' ), ' ', $key ) ) ] = $value;
				}
			}

			// The affiliate's own sale rate; with none, their group's or the default applies.
			$rate      = null;
			$rate_type = null;
			if ( isset( $m['commission_rate_sale'] ) && is_numeric( $m['commission_rate_sale'] ) ) {
				$rate      = (float) $m['commission_rate_sale'];
				$rate_type = $this->rate_type( isset( $m['commission_rate_type_sale'] ) ? $m['commission_rate_type_sale'] : 'percentage', $basis );
			}

			$status = in_array( $row['status'], array( 'active', 'pending', 'rejected' ), true ) ? $row['status'] : 'inactive';

			$records[] = $this->affiliate_record(
				array(
					'source_id'     => $id,
					'user_id'       => (int) $row['user_id'],
					'email'         => isset( $user['user_email'] ) ? $user['user_email'] : '',
					'username'      => isset( $user['user_login'] ) ? $user['user_login'] : '',
					'first_name'    => isset( $names['first_name'] ) ? $names['first_name'] : '',
					'last_name'     => isset( $names['last_name'] ) ? $names['last_name'] : '',
					'status'        => $status,
					'rate'          => $rate,
					'rate_type'     => $rate_type,
					'group_source_id' => isset( $groups[ $id ] ) ? $groups[ $id ] : '',
					'date'          => $this->date( $row['date_created'], true ),
					'payment_email' => (string) $row['payment_email'],
					'payout_method' => isset( $m['payout_method'] ) ? $this->payout_method( $m['payout_method'] ) : '',
					'website'       => (string) $row['website'],
					'promote'       => isset( $m['promotional_methods'] ) && is_scalar( $m['promotional_methods'] ) ? (string) $m['promotional_methods'] : '',
					'reject_reason' => isset( $m['reject_reason'] ) && is_scalar( $m['reject_reason'] ) ? (string) $m['reject_reason'] : '',
					'fields'        => $fields,
					'coupons'       => $this->coupons_of_affiliate( $id )['coupons'],
					// "?aff=" (and the slicewp_aff cookie) carry the affiliate ID.
					'identifiers'   => array( (string) $id ),
					'link_id'       => (string) $id,
				)
			);
		}

		return $records;
	}

	/**
	 * Affiliate group memberships: an affiliate in several groups keeps the one
	 * they were added to first.
	 *
	 * @param int[] $affiliate_ids
	 * @return array affiliate ID => group (collection) ID
	 */
	protected function group_memberships( $affiliate_ids ) {
		$table       = $this->table( 'slicewp_collection_object_relationships' );
		$collections = $this->table( 'slicewp_collections' );
		if ( ! $affiliate_ids || ! $this->table_exists( $table ) || ! $this->table_exists( $collections ) ) {
			return array();
		}
		$groups = array();
		foreach ( $this->rows( "SELECT id FROM `{$collections}` WHERE object_context = 'affiliate' AND type = 'group'" ) as $row ) {
			$groups[ (int) $row['id'] ] = true;
		}
		$map = array();
		foreach ( $this->rows( "SELECT object_id, collection_id FROM `{$table}` WHERE object_context = 'affiliate' AND object_id IN (" . implode( ',', array_map( 'intval', $affiliate_ids ) ) . ') ORDER BY id ASC' ) as $row ) {
			if ( isset( $groups[ (int) $row['collection_id'] ] ) && ! isset( $map[ (int) $row['object_id'] ] ) ) {
				$map[ (int) $row['object_id'] ] = (string) (int) $row['collection_id'];
			}
		}
		return $map;
	}

	/**
	 * Affiliate groups and their sale rates. A group with no rate of its own
	 * uses the default rate.
	 *
	 * @return array[]
	 */
	public function get_groups() {
		$table = $this->table( 'slicewp_collections' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		$rows   = $this->rows( "SELECT id, name FROM `{$table}` WHERE object_context = 'affiliate' AND type = 'group' ORDER BY id ASC" );
		$meta   = $this->meta_for( $this->table( 'slicewp_collection_meta' ), 'slicewp_collection_id', wp_list_pluck( $rows, 'id' ) );
		$basis  = $this->settings_option()['commission_fixed_amount_rate_basis'] ?? 'product';
		$groups = array();
		foreach ( $rows as $row ) {
			$m        = isset( $meta[ (int) $row['id'] ] ) ? $meta[ (int) $row['id'] ] : array();
			$rate     = isset( $m['commission_rate_sale'] ) && is_numeric( $m['commission_rate_sale'] ) ? (float) $m['commission_rate_sale'] : null;
			$groups[] = array(
				'source_id' => (string) (int) $row['id'],
				'name'      => (string) $row['name'],
				'rate'      => $rate,
				'rate_type' => null === $rate ? null : $this->rate_type( isset( $m['commission_rate_type_sale'] ) ? $m['commission_rate_type_sale'] : 'percentage', $basis ),
			);
		}
		return $groups;
	}

	/**
	 * SliceWP's payout method slug in the common form.
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
		if ( false !== strpos( $method, 'credit' ) ) {
			return 'credit';
		}
		if ( false !== strpos( $method, 'bank' ) ) {
			return 'bank';
		}
		return $method ? 'manual' : '';
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_referrals( $after, $limit ) {

		$rows     = $this->batch( $this->table( 'slicewp_commissions' ), 'id', $after, $limit );
		$payments = $this->payment_statuses( wp_list_pluck( $rows, 'payment_id' ) );

		$records = array();
		foreach ( $rows as $row ) {

			$is_woo  = ( 'woo' === (string) $row['origin'] || 'woocommerce' === (string) $row['origin'] ) && is_numeric( $row['reference'] );
			$payment = (int) $row['payment_id'];

			// A negative commission (an adjustment entered by hand) takes its
			// amount off the balance: no order can hold a negative commission here.
			if ( $is_woo && $this->money( $row['amount'] ) < 0 ) {
				$is_woo = false;
			}

			switch ( $row['status'] ) {
				case 'paid':
					$status = 'paid';
					break;
				case 'unpaid':
					// Commission stays "unpaid" in a payment until that is marked
					// paid. A payment still processing is a pending payout here, so
					// its commission is no longer owed.
					$status = $payment && isset( $payments[ $payment ] ) && 'processing' === $payments[ $payment ] ? 'paid' : 'unpaid';
					break;
				case 'pending':
					$status = 'pending';
					break;
				default:
					$status = 'rejected';
			}

			$records[] = $this->referral_record(
				array(
					'source_id'           => (int) $row['id'],
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'order_id'            => $is_woo ? (int) $row['reference'] : 0,
					'amount'              => $row['amount'],
					'status'              => $status,
					'date'                => $this->date( $row['date_created'], true ),
					// Multi-level commissions point at the commission they were paid on.
					'is_tier'             => (int) $row['parent_id'] > 0,
					'payout_source_id'    => $payment && 'paid' === $status ? (string) $payment : '',
				)
			);
		}

		return $records;
	}

	/**
	 * The status of each of a batch of payments.
	 *
	 * @param array $payment_ids
	 * @return array payment ID => status
	 */
	protected function payment_statuses( $payment_ids ) {
		$payment_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $payment_ids ) ) ) );
		$table       = $this->table( 'slicewp_payments' );
		if ( ! $payment_ids || ! $this->table_exists( $table ) ) {
			return array();
		}
		$statuses = array();
		foreach ( $this->rows( "SELECT id, status FROM `{$table}` WHERE id IN (" . implode( ',', $payment_ids ) . ')' ) as $row ) {
			$statuses[ (int) $row['id'] ] = (string) $row['status'];
		}
		return $statuses;
	}

	/**
	 * Payments (one per affiliate per payout). Paid ones are paid payouts, and
	 * processing ones (being sent by PayPal / Stripe payouts) pending payouts
	 * whose commission is imported as paid. An unpaid or failed payment's
	 * commissions are still unpaid in SliceWP, so they are migrated as unpaid
	 * commission, to be paid here, and the payment is left out.
	 *
	 * @param string $after
	 * @param int    $limit
	 * @return array[]
	 */
	public function get_payouts( $after, $limit ) {

		$rows = $this->batch( $this->table( 'slicewp_payments' ), 'id', $after, $limit );
		if ( ! $rows ) {
			return array();
		}

		$commissions = array();
		$ids         = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		foreach ( $this->rows( 'SELECT id, payment_id FROM `' . $this->table( 'slicewp_commissions' ) . '` WHERE payment_id IN (' . implode( ',', $ids ) . ')' ) as $c ) {
			$commissions[ (int) $c['payment_id'] ][] = (string) $c['id'];
		}

		$records = array();
		foreach ( $rows as $row ) {
			$id  = (int) $row['id'];
			$ids = isset( $commissions[ $id ] ) ? $commissions[ $id ] : array_filter( array_map( 'trim', explode( ',', (string) ( $row['commission_ids'] ?? '' ) ) ) );

			switch ( $row['status'] ) {
				case 'paid':
					$status = 'paid';
					break;
				case 'processing':
					$status = 'pending';
					break;
				default:
					$status = 'cancelled';
			}

			$records[] = $this->payout_record(
				array(
					'source_id'           => $id,
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'amount'              => $row['amount'],
					'status'              => $status,
					'method'              => $this->payout_method( $row['payout_method'] ),
					'date'                => $this->date( $row['date_created'], true ),
					'date_paid'           => 'paid' === $status ? $this->date( $row['date_modified'], true ) : '',
					'referral_source_ids' => $ids,
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

		$where = $since ? $wpdb->prepare( 'date_created >= %s', get_gmt_from_date( $since . ' 00:00:00' ) ) : '';
		$rows  = $this->batch( $this->table( 'slicewp_visits' ), 'id', $after, $limit, $where );

		// The orders the visits converted into, through their commission.
		$orders = array();
		$cids   = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'commission_id' ) ) );
		if ( $cids ) {
			foreach ( $this->rows( 'SELECT id, reference, origin FROM `' . $this->table( 'slicewp_commissions' ) . '` WHERE id IN (' . implode( ',', $cids ) . ')' ) as $c ) {
				if ( 'woo' === $c['origin'] && is_numeric( $c['reference'] ) ) {
					$orders[ (int) $c['id'] ] = (int) $c['reference'];
				}
			}
		}

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = $this->visit_record(
				array(
					'source_id'           => (int) $row['id'],
					'affiliate_source_id' => (int) $row['affiliate_id'],
					'url'                 => (string) $row['landing_url'],
					'referrer'            => (string) $row['referrer_url'],
					'ip'                  => (string) $row['ip_address'],
					'date'                => $this->date( $row['date_created'], true ),
					'order_id'            => isset( $orders[ (int) $row['commission_id'] ] ) ? $orders[ (int) $row['commission_id'] ] : 0,
					'converted'           => (int) $row['commission_id'] > 0,
				)
			);
		}

		return $records;
	}

	/**
	 * @return array[]
	 */
	public function get_creatives() {
		$rows    = $this->batch( $this->table( 'slicewp_creatives' ), 'id', '', 10000 );
		$records = array();
		foreach ( $rows as $row ) {
			$records[] = array(
				'source_id'   => (string) (int) $row['id'],
				'name'        => (string) $row['name'],
				'description' => (string) $row['description'],
				'type'        => 'image' === $row['type'] ? 'image' : 'text',
				'image'       => (string) $row['image_url'],
				'url'         => (string) $row['landing_url'],
				'text'        => (string) $row['text'],
				'alt'         => (string) $row['alt_text'],
				'status'      => 'active' === $row['status'] ? 'active' : 'inactive',
			);
		}
		return $records;
	}

	/**
	 * Products with commission switched off. SliceWP can switch off single
	 * variations and categories too (a category only counting when it is the
	 * product's first), which do not map cleanly here, so those are listed as
	 * not migrated instead.
	 *
	 * @return array
	 */
	public function get_product_rates() {
		global $wpdb;

		$excluded = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
				WHERE pm.meta_key = 'slicewp_disable_commissions' AND pm.meta_value = '1'"
			)
		);

		return array(
			'products'   => array(),
			'categories' => array(),
			'excluded'   => $excluded,
		);
	}
}
