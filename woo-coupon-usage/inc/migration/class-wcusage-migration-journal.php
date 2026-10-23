<?php
/**
 * The migration tool's record of what it did.
 *
 * Every change a migration makes is written here first, with what was there
 * before it, in the wcusage_migration_map table:
 *
 * - "Has this source record already been imported?" - so a run can stop and
 *   resume, or be started again, without importing anything twice.
 * - "What did this run change?" - so it can be undone, change by change,
 *   newest first.
 * - "Which coupon does this old referral ID belong to?" - rows with the
 *   entity "ref_id", read after the migration by the old-link handler.
 *
 * Rows hold a target (a user, coupon, order, payout row...) and, in "data",
 * what is needed to put it back: whether the migration created it, and the
 * previous value of every meta key or setting it changed.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Journal {

	/**
	 * Run this journal writes for.
	 *
	 * @var string
	 */
	protected $run_id;

	/**
	 * Source ID.
	 *
	 * @var string
	 */
	protected $source;

	/**
	 * @param string $run_id
	 * @param string $source
	 */
	public function __construct( $run_id, $source ) {
		$this->run_id = (string) $run_id;
		$this->source = (string) $source;
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wcusage_migration_map';
	}

	/**
	 * @return string
	 */
	public function run_id() {
		return $this->run_id;
	}

	/**
	 * Record something the migration did.
	 *
	 * @param string $entity      What kind of source record it came from ("affiliate", "referral"...).
	 * @param string $source_id   That record's ID in the other plugin.
	 * @param string $target_type What was created or changed here ("user", "coupon", "order"...).
	 * @param int    $target_id
	 * @param array  $data        How to undo it, plus anything worth remembering.
	 * @return int Row ID.
	 * @throws RuntimeException When the row cannot be saved: a change the
	 *                          journal does not know about could neither be
	 *                          undone nor recognised on a re-run, so the
	 *                          migration stops instead.
	 */
	public function add( $entity, $source_id, $target_type, $target_id, $data = array() ) {
		global $wpdb;

		$saved = $wpdb->insert(
			self::table(),
			array(
				'run_id'      => $this->run_id,
				'source'      => $this->source,
				'entity'      => (string) $entity,
				'source_id'   => substr( (string) $source_id, 0, 191 ),
				'target_type' => (string) $target_type,
				'target_id'   => (int) $target_id,
				'data'        => $data ? wp_json_encode( $data ) : null,
				'created'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $saved ) {
			/* translators: %s: database error */
			throw new RuntimeException( sprintf( __( 'The migration record could not be saved (%s).', 'woo-coupon-usage' ), $wpdb->last_error ? $wpdb->last_error : 'unknown error' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace a row's data (used to keep a running record up to date).
	 *
	 * @param int   $row_id
	 * @param array $data
	 */
	public function update_data( $row_id, $data ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'data' => wp_json_encode( $data ) ), array( 'id' => (int) $row_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * The rows for a set of source records - from ANY run, so a record imported
	 * by an earlier run that was not undone is not imported again.
	 *
	 * @param string $entity
	 * @param array  $source_ids
	 * @return array source ID => row (target_type, target_id, data as array)
	 */
	public function find( $entity, $source_ids ) {
		global $wpdb;

		$source_ids = array_values( array_unique( array_map( 'strval', (array) $source_ids ) ) );
		if ( ! $source_ids ) {
			return array();
		}

		$found = array();
		foreach ( array_chunk( $source_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = $wpdb->prepare(
				'SELECT id, run_id, source_id, target_type, target_id, data FROM ' . self::table() . " WHERE source = %s AND entity = %s AND source_id IN ({$placeholders}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $this->source, $entity ), $chunk )
			);
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! isset( $found[ $row['source_id'] ] ) ) {
					$row['data']                = $row['data'] ? json_decode( $row['data'], true ) : array();
					$found[ $row['source_id'] ] = $row;
				}
			}
		}

		return $found;
	}

	/**
	 * Whether this run created a coupon.
	 *
	 * @param int $coupon_id
	 * @return bool
	 */
	public function created_coupon( $coupon_id ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM ' . self::table() . " WHERE run_id = %s AND entity = 'coupon' AND target_type = 'coupon' AND target_id = %d", $this->run_id, (int) $coupon_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $data ) {
			$data = $data ? json_decode( $data, true ) : array();
			if ( ! empty( $data['created'] ) && empty( $data['from_code'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One source record's row, from any run.
	 *
	 * @param string $entity
	 * @param string $source_id
	 * @return array|null
	 */
	public function get( $entity, $source_id ) {
		$found = $this->find( $entity, array( $source_id ) );
		return isset( $found[ (string) $source_id ] ) ? $found[ (string) $source_id ] : null;
	}

	/**
	 * Every row of an entity for this source, from any run.
	 *
	 * @param string $entity
	 * @return array[]
	 */
	public function all( $entity ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, run_id, source_id, target_type, target_id, data FROM ' . self::table() . ' WHERE source = %s AND entity = %s ORDER BY id ASC', $this->source, $entity ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$row ) {
			$row['data'] = $row['data'] ? json_decode( $row['data'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Every row of some entities for one source record, from any run.
	 *
	 * With $group, the rows of every record of a commission the old plugin
	 * split into parts (see the referral's group_id): the group's own ID, or
	 * that ID followed by a part suffix ("12p", "o5-a2-paid" - not "123").
	 *
	 * @param string[] $entities
	 * @param string   $source_id
	 * @param bool     $group
	 * @return array[]
	 */
	public function rows_for( $entities, $source_id, $group = false ) {
		global $wpdb;

		$source_id = (string) $source_id;
		$entities  = array_values( (array) $entities );
		$in        = implode( ',', array_fill( 0, count( $entities ), '%s' ) );
		$match     = $group ? 'source_id LIKE %s' : 'source_id = %s';
		$value     = $group ? $wpdb->esc_like( $source_id ) . '%' : $source_id;
		$sql       = $wpdb->prepare(
			'SELECT id, run_id, entity, source_id, target_type, target_id, data FROM ' . self::table() . " WHERE source = %s AND entity IN ({$in}) AND {$match} ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $this->source ), $entities, array( $value ) )
		);

		$rows = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rest = (string) substr( $row['source_id'], strlen( $source_id ) );
			if ( $group && '' !== $rest && ctype_digit( $rest[0] ) ) {
				continue;
			}
			$row['data'] = $row['data'] ? json_decode( $row['data'], true ) : array();
			$rows[]      = $row;
		}
		return $rows;
	}

	/**
	 * Every row a run wrote, newest first.
	 *
	 * @param string $run_id
	 * @param int    $limit
	 * @return array[]
	 */
	public static function for_run( $run_id, $limit = 500 ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE run_id = %s ORDER BY id DESC LIMIT %d', $run_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$row ) {
			$row['data'] = $row['data'] ? json_decode( $row['data'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Remove rows (once undone).
	 *
	 * @param int[] $ids
	 */
	public static function delete_rows( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * How many rows a run wrote.
	 *
	 * @param string $run_id
	 * @return int
	 */
	public static function count_for_run( $run_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE run_id = %s', $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Snapshots: what a value was before the migration changed it
	 * ------------------------------------------------------------------ */

	/**
	 * Post meta values as they are now, for undoing.
	 *
	 * @param int      $post_id
	 * @param string[] $keys
	 * @return array key => array( 'e' => existed, 'v' => value )
	 */
	public static function snapshot_post_meta( $post_id, $keys ) {
		$snapshot = array();
		foreach ( (array) $keys as $key ) {
			$exists           = metadata_exists( 'post', $post_id, $key );
			$snapshot[ $key ] = array(
				'e' => $exists,
				'v' => $exists ? get_post_meta( $post_id, $key, true ) : null,
			);
		}
		return $snapshot;
	}

	/**
	 * User meta values as they are now, for undoing.
	 *
	 * @param int      $user_id
	 * @param string[] $keys
	 * @return array key => array( 'e' => existed, 'v' => value )
	 */
	public static function snapshot_user_meta( $user_id, $keys ) {
		$snapshot = array();
		foreach ( (array) $keys as $key ) {
			$exists           = metadata_exists( 'user', $user_id, $key );
			$snapshot[ $key ] = array(
				'e' => $exists,
				'v' => $exists ? get_user_meta( $user_id, $key, true ) : null,
			);
		}
		return $snapshot;
	}

	/**
	 * Order meta values as they are now (HPOS or posts), for undoing.
	 *
	 * @param WC_Order $order
	 * @param string[] $keys
	 * @return array key => array( 'e' => existed, 'v' => value )
	 */
	public static function snapshot_order_meta( $order, $keys ) {
		$snapshot = array();
		foreach ( (array) $keys as $key ) {
			$exists           = $order->meta_exists( $key );
			$snapshot[ $key ] = array(
				'e' => $exists,
				'v' => $exists ? $order->get_meta( $key, true ) : null,
			);
		}
		return $snapshot;
	}

	/**
	 * Settings (inside wcusage_options) as they are now, for undoing.
	 *
	 * @param string[] $keys
	 * @return array key => array( 'e' => existed, 'v' => value )
	 */
	public static function snapshot_settings( $keys ) {
		$options  = get_option( 'wcusage_options', array() );
		$options  = is_array( $options ) ? $options : array();
		$snapshot = array();
		foreach ( (array) $keys as $key ) {
			$snapshot[ $key ] = array(
				'e' => array_key_exists( $key, $options ),
				'v' => array_key_exists( $key, $options ) ? $options[ $key ] : null,
			);
		}
		return $snapshot;
	}
}
