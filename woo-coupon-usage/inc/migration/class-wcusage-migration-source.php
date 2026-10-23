<?php
/**
 * Base class for a plugin the migration tool can import from.
 *
 * A source only READS. It turns the other plugin's tables into the plain
 * records documented below, and the runner and writer decide what to do with
 * them - so a source never has to know how Coupon Affiliates stores anything.
 *
 * Every reader takes the last ID it handed back and a limit, and returns the
 * next records in ID order (keyset pagination), so even a table with millions
 * of rows is read in steady batches across as many requests as it takes.
 *
 * A reader that returns FEWER records than the limit is taken to have reached
 * the end. So a reader that drops rows after its query (filters, or groups
 * several rows into one record) must keep reading until it has a full batch or
 * the table is exhausted. The cursor is the last record's source_id, which may
 * be a string ("123p") as long as the reader can carry on after it.
 *
 * Inside the test suite every source table is a TEMPORARY table, and MySQL
 * cannot open one twice in a query: no self-joins or correlated subqueries on
 * one source table (run separate queries instead).
 *
 * Sources read the database directly rather than through the other plugin's
 * functions, so the migration works with that plugin deactivated, or deleted.
 *
 * ---------------------------------------------------------------------------
 * Records
 * ---------------------------------------------------------------------------
 *
 * Affiliate:
 *   source_id        (string) The plugin's own affiliate ID.
 *   user_id          (int)    WordPress user, 0 if the plugin has none for them.
 *   email, username, first_name, last_name (string)
 *   status           'active' | 'pending' | 'rejected' | 'inactive'
 *   date             (string) Registration date, site time, 'Y-m-d H:i:s'.
 *   rate             (float|null) Custom commission rate, null for "use the default".
 *   rate_type        'percent' | 'flat_order' | 'flat_product' | null
 *   payment_email    (string) PayPal / payment email.
 *   payout_method    'paypal' | 'bank' | 'stripe' | 'credit' | 'check' | 'manual' | ''
 *   bank             (array)  name, sort, account, iban, swift, other, other2 -
 *                             any that are known.
 *   website, promote, reject_reason (string)
 *   fields           (array)  Other registration answers, label => value.
 *   group_source_id  (string) Affiliate group, '' for none.
 *   coupons          (array)  Coupon IDs the plugin had assigned to them.
 *   virtual_coupons  (array)  Coupon codes the plugin gave them that are not
 *                             WooCommerce coupons (it handed WooCommerce the code
 *                             itself at checkout): created here, with the settings
 *                             of get_virtual_coupon_template().
 *   identifiers      (array)  Every value that identified them in a referral
 *                             link or cookie: ID, username, slug, token...
 *   link_id          (string) The value the plugin read a NUMERIC referral value
 *                             as (its affiliate ID, or the user ID). When set, other
 *                             numeric identifiers (a numeric username or slug) are
 *                             not recorded: the plugin itself took a number for an
 *                             ID, so they would only steal another affiliate's links.
 *   store_credit     (float|null) Store credit balance.
 *
 * Referral (commission):
 *   source_id, affiliate_source_id (string)
 *   order_id      (int)    WooCommerce order, 0 when it is not a WooCommerce sale.
 *   amount        (float)  Commission, in the store's currency.
 *   status        'unpaid' | 'pending' | 'paid' | 'rejected'
 *   date          (string) Site time.
 *   is_tier       (bool)   A multi-level commission paid to a parent affiliate:
 *                          carried as a balance, never put on an order.
 *   secondary     (bool)   A side commission on an order another affiliate
 *                          referred (Solid's auto-referrals): only one affiliate
 *                          can own an order here, so it is carried as a balance.
 *   payout_source_id (string) The payout it was paid in, if known.
 *   group_id      (string) Shared by records that are parts of one commission in
 *                          the plugin (split by status). A part whose group an
 *                          earlier run already imported is not imported again, so
 *                          a split that changed since cannot be counted twice.
 *
 * Payout:
 *   source_id, affiliate_source_id (string)
 *   amount        (float)
 *   status        'paid' | 'pending' | 'cancelled'
 *   method        'paypal' | 'bank' | 'stripe' | 'credit' | 'manual' | 'check' | ''
 *   date, date_paid (string) Site time.
 *   transaction_id (string)
 *   referral_source_ids (array) Referrals it paid, if known.
 *
 * Visit:
 *   source_id, affiliate_source_id (string)
 *   url, referrer, ip, campaign (string)
 *   date          (string) Site time.
 *   order_id      (int)    Order it converted into, 0 if none / unknown.
 *   converted     (bool)
 *
 * Group:      source_id, name, rate (float|null), rate_type
 * Creative:   source_id, name, description, type ('image'|'text'), image, url, text, alt, status ('active'|'inactive')
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WCUsage_Migration_Source {

	/**
	 * Source ID, as in wcusage_migration_sources().
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Display name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Options the admin chose for this run (see WCUsage_Migration_Options).
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * @param string $id
	 * @param string $name
	 */
	public function __construct( $id, $name ) {
		$this->id   = $id;
		$this->name = $name;
	}

	/**
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * @param array $options
	 */
	public function set_options( $options ) {
		$this->options = is_array( $options ) ? $options : array();
	}

	/**
	 * One option's value.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function option( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	/* ---------------------------------------------------------------------
	 * What the source can provide
	 * ------------------------------------------------------------------ */

	/**
	 * Which kinds of data this source can provide.
	 *
	 * Keys of: affiliates, registrations, users_optional (affiliates may have
	 * no WordPress user), coupons (coupon links are read), rates, referrals,
	 * tiers (multi-level commission rows are flagged is_tier), payouts,
	 * payout_details, visits, groups, creatives, product_rates, settings,
	 * store_credit, campaigns, legacy_links (get_legacy_links() describes the
	 * plugin's referral links). A kind can depend on the data actually being
	 * there (an add-on's table, for example).
	 *
	 * @return string[]
	 */
	abstract public function get_supported();

	/**
	 * What the plugin keeps that is NOT migrated, shown on the options screen so
	 * the admin knows what to set up again by hand. Only what this importer is
	 * sure of is carried over; the rest is listed here.
	 *
	 * @return string[] One short line each.
	 */
	public function get_not_migrated() {
		return array();
	}

	/**
	 * 'affiliate' when the plugin lets an affiliate's own rate beat a rate set
	 * on a product. Coupon Affiliates lets product rates win by default, so the
	 * runner warns when both kinds are imported.
	 *
	 * @return string 'affiliate' | ''
	 */
	public function get_rate_priority() {
		return '';
	}

	/**
	 * How much of the other plugin's storage was confirmed from its source
	 * code ('verified'), or partly pieced together from its documentation
	 * and other plugins that read it ('partial').
	 *
	 * @return string
	 */
	public function get_confidence() {
		return 'verified';
	}

	/**
	 * Notes shown before migrating: data the plugin deletes on uninstall,
	 * parts of its storage that could not be confirmed, and so on.
	 *
	 * @return string[]
	 */
	public function get_warnings() {
		return array();
	}

	/**
	 * Record counts, for the preview.
	 *
	 * @return array Keyed like get_supported(): number of records.
	 */
	abstract public function get_counts();

	/**
	 * Options only this source needs, in the same format as
	 * WCUsage_Migration_Options::schema().
	 *
	 * @return array
	 */
	public function get_source_options() {
		return array();
	}

	/**
	 * Defaults for the general options that suit this source better than the
	 * general defaults (the coupon code format, for example).
	 *
	 * @return array
	 */
	public function get_option_defaults() {
		return array();
	}

	/**
	 * The plugin's program settings, in a common form. Any key may be missing.
	 *
	 *   rate          (float)  Default commission rate.
	 *   rate_type     'percent' | 'flat_order' | 'flat_product'
	 *   cookie_days   (int)
	 *   credit_first  (bool)   First-click attribution.
	 *   currency      (string)
	 *
	 * @return array
	 */
	public function get_settings() {
		return array();
	}

	/**
	 * How the plugin's referral links and cookies looked, so old links keep
	 * working. See wcusage_migration_legacy_links().
	 *
	 * @return array vars, paths, campaign, cookies, cookie_strip_suffix.
	 */
	public function get_legacy_links() {
		return array();
	}

	/**
	 * The coupon code suggested for an affiliate who has none, when the code
	 * format is left at its default. Usually one of their link identifiers,
	 * so the code matches what they already share.
	 *
	 * @return string Code format (see WCUsage_Migration_Writer::coupon_code()).
	 */
	public function get_default_coupon_format() {
		return '{username}';
	}

	/* ---------------------------------------------------------------------
	 * Readers - override the ones the source supports
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $after Last source ID returned, '' to start.
	 * @param int    $limit
	 * @return array[] Affiliate records.
	 */
	abstract public function get_affiliates( $after, $limit );

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[] Referral records.
	 */
	public function get_referrals( $after, $limit ) {
		return array();
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @return array[] Payout records.
	 */
	public function get_payouts( $after, $limit ) {
		return array();
	}

	/**
	 * @param string $after
	 * @param int    $limit
	 * @param string $since Only visits on or after this date ('Y-m-d'), '' for all.
	 * @return array[] Visit records.
	 */
	public function get_visits( $after, $limit, $since = '' ) {
		return array();
	}

	/**
	 * The template coupon the plugin's virtual coupon codes took their settings
	 * from, and which of its settings (WC_Coupon props). Null when there is none:
	 * new coupons then use the migration's own coupon settings.
	 *
	 * @return array|null array( 'id' => coupon ID, 'fields' => string[] )
	 */
	public function get_virtual_coupon_template() {
		return null;
	}

	/**
	 * Every affiliate's virtual coupon codes (see the affiliate's
	 * virtual_coupons): kept free when coupons are made up for other affiliates,
	 * so one is never given a code customers used for another.
	 *
	 * @return string[]
	 */
	public function get_virtual_coupon_codes() {
		return array();
	}

	/**
	 * @return array[] Group records.
	 */
	public function get_groups() {
		return array();
	}

	/**
	 * @return array[] Creative records.
	 */
	public function get_creatives() {
		return array();
	}

	/**
	 * Per-product and per-category commission rates.
	 *
	 * @return array array(
	 *   'products'   => array( product_id => array( 'rate' => float, 'type' => 'percent'|'flat' ) ),
	 *   'categories' => array( term_id    => array( 'rate' => float, 'type' => 'percent'|'flat' ) ),
	 *   'excluded'   => array( product_id, ... ), // products that earn no commission
	 * )
	 */
	public function get_product_rates() {
		return array(
			'products'   => array(),
			'categories' => array(),
			'excluded'   => array(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers for sources
	 * ------------------------------------------------------------------ */

	/**
	 * A table name with the site prefix.
	 *
	 * @param string $name
	 * @return string
	 */
	protected function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		return (bool) wcusage_migration_table_columns( $table );
	}

	/**
	 * Whether a table has a column. Used for columns added in later versions
	 * of the other plugin.
	 *
	 * @param string $table  Full table name.
	 * @param string $column
	 * @return bool
	 */
	protected function has_column( $table, $column ) {
		return in_array( $column, wcusage_migration_table_columns( $table ), true );
	}

	/**
	 * Count a table's rows, 0 when it does not exist.
	 *
	 * @param string $table Full table name.
	 * @param string $where Optional WHERE clause, already escaped.
	 * @return int
	 */
	protected function count_rows( $table, $where = '' ) {
		global $wpdb;
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" . ( $where ? " WHERE {$where}" : '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Run a query and return its rows as arrays.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array[]
	 */
	protected function rows( $sql ) {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The next batch of a table in primary key order.
	 *
	 * @param string $table  Full table name.
	 * @param string $key    Primary key column.
	 * @param string $after  Last key returned, '' to start.
	 * @param int    $limit
	 * @param string $where  Extra condition, already escaped.
	 * @param bool   $numeric Whether the key is numeric.
	 * @return array[]
	 */
	protected function batch( $table, $key, $after, $limit, $where = '', $numeric = true ) {
		global $wpdb;

		// A table from another version of the plugin may lack the key column.
		if ( ! $this->has_column( $table, $key ) ) {
			return array();
		}

		$conditions = array();
		if ( '' !== (string) $after ) {
			$conditions[] = $numeric
				? $wpdb->prepare( "`{$key}` > %d", (int) $after ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "`{$key}` > %s", (string) $after ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $where ) {
			$conditions[] = "( {$where} )";
		}

		$sql = "SELECT * FROM `{$table}`" . ( $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '' ) . " ORDER BY `{$key}` ASC LIMIT " . max( 1, (int) $limit );

		return $this->rows( $sql );
	}

	/**
	 * The status of each of a batch of WooCommerce orders (HPOS or posts).
	 *
	 * @param int[] $order_ids
	 * @return array order ID => status without "wc-" (missing orders left out)
	 */
	protected function order_statuses( $order_ids ) {
		return WCUsage_Migration_Writer::order_statuses( $order_ids );
	}

	/**
	 * A money amount, rounded to cents. Handles decimal strings in any of the
	 * formats other plugins store them in (FLOAT, DECIMAL, mediumtext).
	 *
	 * @param mixed $value
	 * @return float
	 */
	protected function money( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			// A comma as the only separator is a decimal comma ("12,50").
			if ( false !== strpos( $value, ',' ) && false === strpos( $value, '.' ) ) {
				$value = str_replace( ',', '.', $value );
			}
			$value = preg_replace( '/[^0-9.\-]/', '', $value );
		}
		return round( (float) $value, 2 );
	}

	/**
	 * A date, as site time in MySQL format.
	 *
	 * @param mixed $value  A MySQL datetime, a date, or a Unix timestamp.
	 * @param bool  $is_gmt Whether the stored value is UTC.
	 * @return string '' when there is no usable date.
	 */
	protected function date( $value, $is_gmt = false ) {

		if ( empty( $value ) || '0000-00-00 00:00:00' === $value || '0000-00-00' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) && (int) $value > 100000 ) {
			return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $value ) );
		}

		$time = strtotime( (string) $value . ( $is_gmt ? ' UTC' : '' ) );
		if ( ! $time ) {
			return '';
		}

		if ( $is_gmt ) {
			return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $time ) );
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( (string) $value ) );
	}

	/**
	 * Unserialize a value stored by another plugin, never creating objects.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	protected function unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		$result = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $result && 'b:0;' !== trim( $value ) ? $value : $result;
	}

	/**
	 * Read a meta table into an array, for a batch of objects.
	 *
	 * @param string $table      Full meta table name.
	 * @param string $id_column  Column holding the object ID.
	 * @param array  $ids
	 * @param array  $keys       Only these meta keys, all when empty.
	 * @return array object ID => array( meta_key => value )
	 */
	protected function meta_for( $table, $id_column, $ids, $keys = array() ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids || ! $this->table_exists( $table ) ) {
			return array();
		}

		$sql = "SELECT `{$id_column}` AS object_id, meta_key, meta_value FROM `{$table}` WHERE `{$id_column}` IN (" . implode( ',', $ids ) . ')';
		if ( $keys ) {
			$sql .= " AND meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "')";
		}

		$meta = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$meta[ (int) $row['object_id'] ][ $row['meta_key'] ] = $this->unserialize( $row['meta_value'] );
		}
		return $meta;
	}

	/**
	 * WordPress user meta for a batch of users.
	 *
	 * @param array $user_ids
	 * @param array $keys Only these meta keys, all when empty.
	 * @return array user ID => array( meta_key => value )
	 */
	protected function user_meta_for( $user_ids, $keys = array() ) {
		global $wpdb;
		return $this->meta_for( $wpdb->usermeta, 'user_id', $user_ids, $keys );
	}

	/**
	 * WordPress users for a batch of IDs.
	 *
	 * @param array $user_ids
	 * @return array user ID => array( user_login, user_email, user_registered, user_nicename, display_name, user_url )
	 */
	protected function users_for( $user_ids ) {
		global $wpdb;

		$user_ids = array_filter( array_map( 'intval', (array) $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}

		$users = array();
		foreach ( $this->rows( "SELECT ID, user_login, user_email, user_registered, user_nicename, display_name, user_url FROM {$wpdb->users} WHERE ID IN (" . implode( ',', $user_ids ) . ')' ) as $row ) {
			$users[ (int) $row['ID'] ] = $row;
		}
		return $users;
	}

	/**
	 * Coupon (shop_coupon post) IDs carrying a meta value, keyed by that value.
	 *
	 * How most plugins link a coupon to an affiliate: a meta key on the coupon
	 * holding the affiliate's ID.
	 *
	 * @param string|string[] $meta_keys
	 * @return array meta value => array of coupon IDs
	 */
	protected function coupons_by_meta( $meta_keys ) {
		global $wpdb;

		$meta_keys = array_filter( (array) $meta_keys );
		if ( ! $meta_keys ) {
			return array();
		}

		$sql = "SELECT pm.meta_value, p.ID FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'
			AND pm.meta_key IN ('" . implode( "','", array_map( 'esc_sql', $meta_keys ) ) . "')
			AND pm.meta_value <> '' ORDER BY p.ID ASC";

		$coupons = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$coupons[ (string) $row['meta_value'] ][] = (int) $row['ID'];
		}
		return $coupons;
	}

	/**
	 * Published coupon IDs for a list of codes.
	 *
	 * @param string[] $codes
	 * @return array lowercase code => coupon ID
	 */
	protected function coupons_by_code( $codes ) {
		global $wpdb;

		$codes = array_values( array_unique( array_filter( array_map( 'wc_format_coupon_code', (array) $codes ) ) ) );
		if ( ! $codes ) {
			return array();
		}

		$found = array();
		foreach ( array_chunk( $codes, 200 ) as $chunk ) {
			$sql = "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status = 'publish' AND LOWER(post_title) IN ('" . implode( "','", array_map( 'esc_sql', $chunk ) ) . "') ORDER BY ID ASC";
			foreach ( $this->rows( $sql ) as $row ) {
				$code = wc_format_coupon_code( $row['post_title'] );
				if ( ! isset( $found[ $code ] ) ) {
					$found[ $code ] = (int) $row['ID'];
				}
			}
		}
		return $found;
	}

	/**
	 * An empty affiliate record, so sources only fill in what they know.
	 *
	 * @param array $values
	 * @return array
	 */
	protected function affiliate_record( $values ) {
		$record = array_merge(
			array(
				'source_id'        => '',
				'user_id'          => 0,
				'email'            => '',
				'username'         => '',
				'first_name'       => '',
				'last_name'        => '',
				'status'           => 'active',
				'date'             => '',
				'rate'             => null,
				'rate_type'        => null,
				'payment_email'    => '',
				'payout_method'    => '',
				'bank'             => array(),
				'website'          => '',
				'promote'          => '',
				'reject_reason'    => '',
				'fields'           => array(),
				'group_source_id'  => '',
				'coupons'          => array(),
				'virtual_coupons'  => array(),
				'identifiers'      => array(),
				'link_id'          => '',
				'store_credit'     => null,
			),
			$values
		);

		$record['source_id']        = (string) $record['source_id'];
		$record['user_id']          = (int) $record['user_id'];
		$record['group_source_id']  = (string) $record['group_source_id'];
		$record['coupons']          = array_values( array_unique( array_filter( array_map( 'intval', (array) $record['coupons'] ) ) ) );
		$record['virtual_coupons']  = array_values( array_unique( array_filter( array_map( 'wc_format_coupon_code', array_map( 'strval', (array) $record['virtual_coupons'] ) ), 'strlen' ) ) );
		$record['identifiers']      = array_values( array_unique( array_filter( array_map( 'strval', (array) $record['identifiers'] ), 'strlen' ) ) );
		$record['link_id']          = (string) $record['link_id'];

		return $record;
	}

	/**
	 * An empty referral record.
	 *
	 * @param array $values
	 * @return array
	 */
	protected function referral_record( $values ) {
		$record = array_merge(
			array(
				'source_id'           => '',
				'affiliate_source_id' => '',
				'order_id'            => 0,
				'amount'              => 0.0,
				'status'              => 'unpaid',
				'date'                => '',
				'is_tier'             => false,
				'secondary'           => false,
				'payout_source_id'    => '',
				'group_id'            => '',
			),
			$values
		);

		$record['source_id']           = (string) $record['source_id'];
		$record['affiliate_source_id'] = (string) $record['affiliate_source_id'];
		$record['order_id']            = (int) $record['order_id'];
		$record['amount']              = $this->money( $record['amount'] );
		$record['payout_source_id']    = (string) $record['payout_source_id'];
		$record['group_id']            = (string) $record['group_id'];

		return $record;
	}

	/**
	 * An empty payout record.
	 *
	 * @param array $values
	 * @return array
	 */
	protected function payout_record( $values ) {
		$record = array_merge(
			array(
				'source_id'           => '',
				'affiliate_source_id' => '',
				'amount'              => 0.0,
				'status'              => 'paid',
				'method'              => '',
				'date'                => '',
				'date_paid'           => '',
				'transaction_id'      => '',
				'referral_source_ids' => array(),
			),
			$values
		);

		$record['source_id']           = (string) $record['source_id'];
		$record['affiliate_source_id'] = (string) $record['affiliate_source_id'];
		$record['amount']              = $this->money( $record['amount'] );
		$record['referral_source_ids'] = array_values( array_filter( array_map( 'strval', (array) $record['referral_source_ids'] ), 'strlen' ) );

		return $record;
	}

	/**
	 * An empty visit record.
	 *
	 * @param array $values
	 * @return array
	 */
	protected function visit_record( $values ) {
		$record = array_merge(
			array(
				'source_id'           => '',
				'affiliate_source_id' => '',
				'url'                 => '',
				'referrer'            => '',
				'ip'                  => '',
				'campaign'            => '',
				'date'                => '',
				'order_id'            => 0,
				'converted'           => false,
			),
			$values
		);

		$record['source_id']           = (string) $record['source_id'];
		$record['affiliate_source_id'] = (string) $record['affiliate_source_id'];
		$record['order_id']            = (int) $record['order_id'];
		$record['converted']           = (bool) $record['converted'] || $record['order_id'] > 0;

		return $record;
	}

	/**
	 * Split a full name into first and last.
	 *
	 * @param string $name
	 * @return string[] array( first, last )
	 */
	protected function split_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return array( '', '' );
		}
		$parts = preg_split( '/\s+/', $name, 2 );
		return array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}
}
