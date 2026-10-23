<?php
/**
 * Runs a migration, step by step, in batches.
 *
 * A migration is a list of steps (settings, affiliates, referrals...). Each
 * call to run() works through as many batches as fit in its time budget and
 * saves where it got to, so the admin screen can call it repeatedly over
 * AJAX (and WP-CLI in a loop) until it is done, and a run interrupted by a
 * timeout or a closed browser tab carries on from the last saved batch.
 *
 * Nothing is imported twice: every record imported is in the journal, and a
 * record already there - from this run or an earlier one - is skipped.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Runner {

	/** The run in progress (or last finished). */
	const STATE_OPTION = 'wcusage_migration_state';

	/** Every run, for the history and undo list. */
	const RUNS_OPTION = 'wcusage_migration_runs';

	/** Held while a batch runs, so two tabs cannot run batches at once. */
	const LOCK_OPTION = 'wcusage_migration_lock';

	/**
	 * @var array
	 */
	protected $state;

	/**
	 * @var WCUsage_Migration_Source
	 */
	protected $source;

	/**
	 * @var WCUsage_Migration_Journal
	 */
	protected $journal;

	/**
	 * @var WCUsage_Migration_Writer
	 */
	protected $writer;

	/**
	 * @var WCUsage_Migration_Writer_Pro|null
	 */
	protected $pro;

	/**
	 * Start of the current call, for the time budget.
	 *
	 * @var float
	 */
	protected $started;

	/**
	 * Seconds this call may run for.
	 *
	 * @var float
	 */
	protected $budget;

	/* ---------------------------------------------------------------------
	 * Sources and state
	 * ------------------------------------------------------------------ */

	/**
	 * A source object.
	 *
	 * @param string $source_id
	 * @return WCUsage_Migration_Source|null
	 */
	public static function get_source( $source_id ) {

		$sources = wcusage_migration_sources();
		if ( empty( $sources[ $source_id ] ) ) {
			return null;
		}

		$class = $sources[ $source_id ]['class'];
		if ( ! class_exists( $class ) && ! empty( $sources[ $source_id ]['file'] ) ) {
			$file = __DIR__ . '/sources/' . basename( $sources[ $source_id ]['file'] );
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( $class ) ) {
			return null;
		}

		return new $class( $source_id, $sources[ $source_id ]['name'] );
	}

	/**
	 * The saved state of the current (or last) run.
	 *
	 * @return array
	 */
	public static function get_state() {
		// Straight from the database: another request (Stop, an undo, a second
		// tab) may have changed it since this one cached it.
		wp_cache_delete( self::STATE_OPTION, 'options' );
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Every run so far, newest first.
	 *
	 * @return array run ID => summary
	 */
	public static function get_runs() {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * Whether a run (or undo) is in progress.
	 *
	 * @return bool
	 */
	public static function is_busy() {
		$state = self::get_state();
		return ! empty( $state['status'] ) && in_array( $state['status'], array( 'running', 'undoing' ), true );
	}

	/**
	 * The steps a run goes through, for a source and options.
	 *
	 * @param WCUsage_Migration_Source $source
	 * @param array                    $options
	 * @return string[]
	 */
	public static function plan( $source, $options ) {

		$supported = $source->get_supported();
		$pro       = wcusage_migration_is_pro();
		$has       = function ( $kind ) use ( $supported ) {
			return in_array( $kind, $supported, true );
		};
		$on        = function ( $key ) use ( $options ) {
			return ! empty( $options[ $key ] );
		};

		$steps = array();

		if ( $has( 'settings' ) && $on( 'migrate_settings' ) ) {
			$steps[] = 'settings';
		}
		if ( $pro && $has( 'groups' ) && $on( 'migrate_groups' ) ) {
			$steps[] = 'groups';
		}
		$steps[] = 'affiliates';
		if ( $pro && $has( 'product_rates' ) && $on( 'migrate_product_rates' ) ) {
			$steps[] = 'product_rates';
		}
		if ( $has( 'referrals' ) && ( $on( 'migrate_referrals' ) || ( $pro && $on( 'migrate_balances' ) ) ) ) {
			$steps[] = 'referrals';
		}
		// Whatever is migrated: left alone, the plugin's own commission on these
		// coupons from before would become the affiliates'.
		if ( $pro && $has( 'referrals' ) ) {
			$steps[] = 'release';
		}
		if ( $pro && $has( 'payouts' ) && $on( 'migrate_payouts' ) ) {
			$steps[] = 'payouts';
		}
		if ( $pro && $has( 'referrals' ) && ( $on( 'migrate_balances' ) || $on( 'migrate_payouts' ) ) ) {
			$steps[] = 'books';
		}
		if ( $pro && $has( 'creatives' ) && $on( 'migrate_creatives' ) ) {
			$steps[] = 'creatives';
		}
		if ( $has( 'visits' ) && $on( 'migrate_visits' ) ) {
			$steps[] = 'visits';
		}
		if ( $has( 'legacy_links' ) && $on( 'legacy_links' ) ) {
			$steps[] = 'legacy';
		}
		$steps[] = 'finalize';

		return $steps;
	}

	/**
	 * Labels for the steps.
	 *
	 * @return array
	 */
	public static function step_labels() {
		return array(
			'settings'      => __( 'Program settings', 'woo-coupon-usage' ),
			'groups'        => __( 'Affiliate groups', 'woo-coupon-usage' ),
			'affiliates'    => __( 'Affiliates & coupons', 'woo-coupon-usage' ),
			'product_rates' => __( 'Product commission rates', 'woo-coupon-usage' ),
			'referrals'     => __( 'Referral history & commission', 'woo-coupon-usage' ),
			'release'       => __( 'Tidying up coupon balances', 'woo-coupon-usage' ),
			'payouts'       => __( 'Payout history', 'woo-coupon-usage' ),
			'books'         => __( 'Balancing the books', 'woo-coupon-usage' ),
			'creatives'     => __( 'Creatives', 'woo-coupon-usage' ),
			'visits'        => __( 'Referral visits', 'woo-coupon-usage' ),
			'legacy'        => __( 'Old referral links', 'woo-coupon-usage' ),
			'finalize'      => __( 'Finishing up', 'woo-coupon-usage' ),
		);
	}

	/**
	 * Start a new run.
	 *
	 * @param string     $source_id
	 * @param array|null $input Options as posted (null for the defaults).
	 * @return array|WP_Error The new state.
	 */
	public static function start( $source_id, $input = null ) {

		// Under the lock, so two starts at once cannot both get through.
		if ( ! self::lock() ) {
			return new WP_Error( 'wcusage_migration_busy', __( 'A migration is already running. Wait for it to finish, or cancel it first.', 'woo-coupon-usage' ) );
		}
		try {
			return self::start_locked( $source_id, $input );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Start a new run, the lock held.
	 *
	 * @param string     $source_id
	 * @param array|null $input
	 * @return array|WP_Error
	 */
	protected static function start_locked( $source_id, $input ) {

		if ( self::is_busy() ) {
			return new WP_Error( 'wcusage_migration_busy', __( 'A migration is already running. Wait for it to finish, or cancel it first.', 'woo-coupon-usage' ) );
		}

		$source = self::get_source( $source_id );
		if ( ! $source ) {
			return new WP_Error( 'wcusage_migration_source', __( 'Unknown source plugin.', 'woo-coupon-usage' ) );
		}
		if ( ! wcusage_migration_source_has_data( $source_id ) ) {
			/* translators: %s: plugin name */
			return new WP_Error( 'wcusage_migration_no_data', sprintf( __( 'No %s data was found in the database.', 'woo-coupon-usage' ), $source->get_name() ) );
		}

		wcusage_migration_maybe_install();

		$options = WCUsage_Migration_Options::sanitize( $source, $input );
		$source->set_options( $options );

		// At most 36 characters: the journal's run_id column takes 64.
		$run_id = substr( sanitize_key( $source_id ), 0, 16 ) . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false, false ) );

		$state = array(
			'run_id'   => $run_id,
			'source'   => $source_id,
			'options'  => $options,
			'status'   => 'running',
			'steps'    => self::plan( $source, $options ),
			'step'     => 0,
			'cursor'   => '',
			'counts'   => array(),
			'messages' => array(),
			'started'  => time(),
			'updated'  => time(),
			'user'     => get_current_user_id(),
		);

		update_option( self::STATE_OPTION, $state, false );

		$runs            = self::get_runs();
		$runs            = array( $run_id => array(
			'run_id'  => $run_id,
			'source'  => $source_id,
			'name'    => $source->get_name(),
			'status'  => 'running',
			'started' => time(),
			'user'    => get_current_user_id(),
		) ) + $runs;
		update_option( self::RUNS_OPTION, $runs, false );

		return $state;
	}

	/**
	 * @param array $state
	 */
	public function __construct( $state ) {

		$this->state  = $state;
		$this->source = self::get_source( $state['source'] );

		if ( $this->source ) {
			$this->source->set_options( $state['options'] );
		}

		$this->journal = new WCUsage_Migration_Journal( $state['run_id'], $state['source'] );
		$this->writer  = new WCUsage_Migration_Writer(
			$this->journal,
			$state['source'],
			$this->source ? $this->source->get_name() : $state['source'],
			$state['options'],
			array( $this, 'log' )
		);

		if ( wcusage_migration_is_pro() ) {
			$this->pro = new WCUsage_Migration_Writer_Pro( $this->writer, $this->source ? $this->source->get_name() : $state['source'] );
		}
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	protected function option( $key ) {
		return isset( $this->state['options'][ $key ] ) ? $this->state['options'][ $key ] : null;
	}

	/**
	 * Add to a counter shown in the summary.
	 *
	 * @param string $key
	 * @param float  $by
	 */
	protected function count( $key, $by = 1 ) {
		$this->state['counts'][ $key ] = round( ( isset( $this->state['counts'][ $key ] ) ? $this->state['counts'][ $key ] : 0 ) + $by, 2 );
	}

	/**
	 * Add a message to the run's log (the newest 500 are kept).
	 *
	 * @param string $level   'info' | 'warning' | 'error'
	 * @param string $message
	 */
	public function log( $level, $message ) {
		$this->state['messages'][] = array(
			'level'   => $level,
			'message' => wp_strip_all_tags( (string) $message ),
			'time'    => time(),
		);
		if ( count( $this->state['messages'] ) > 500 ) {
			$this->state['messages'] = array_slice( $this->state['messages'], -500 );
		}
		if ( 'error' === $level || 'warning' === $level ) {
			$this->count( 'warnings' );
		}
	}

	/**
	 * Whether this call still has time for another batch.
	 *
	 * @return bool
	 */
	protected function has_time() {
		return ( microtime( true ) - $this->started ) < $this->budget;
	}

	/**
	 * Save the state.
	 */
	protected function save() {

		$saved = self::get_state();

		// An undo or another run has taken over since this batch began.
		if ( ( $saved['run_id'] ?? '' ) !== ( $this->state['run_id'] ?? '' ) ) {
			return false;
		}

		// Stopped while this batch was working: keep the stop, with this batch's
		// progress.
		if ( 'running' === ( $this->state['status'] ?? '' ) && 'cancelled' === ( $saved['status'] ?? '' ) ) {
			$this->state['status']   = 'cancelled';
			$this->state['finished'] = isset( $saved['finished'] ) ? $saved['finished'] : time();
			self::update_run( $this->state['run_id'], array( 'counts' => $this->state['counts'] ) );
		}

		$this->state['updated'] = time();
		update_option( self::STATE_OPTION, $this->state, false );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------ */

	/**
	 * Work through batches until the time budget runs out or the run is done.
	 *
	 * @param float $budget Seconds.
	 * @return array The state after this call.
	 */
	public function run( $budget = 20 ) {

		if ( 'running' !== ( $this->state['status'] ?? '' ) ) {
			return $this->state;
		}

		if ( ! $this->source ) {
			$this->state['status'] = 'failed';
			$this->log( 'error', __( 'The source plugin\'s importer could not be loaded.', 'woo-coupon-usage' ) );
			$this->save();
			self::update_run( $this->state['run_id'], array( 'status' => 'failed' ) );
			return $this->state;
		}

		if ( ! self::lock() ) {
			return $this->state;
		}

		// Carry on from the saved state, not the one this request was handed: a
		// batch that held the lock until now (another tab, WP-CLI) has moved it on,
		// or the run was stopped meanwhile.
		$saved = self::get_state();
		if ( ( $saved['run_id'] ?? '' ) !== ( $this->state['run_id'] ?? '' ) || 'running' !== ( $saved['status'] ?? '' ) ) {
			self::unlock();
			return $saved;
		}
		$single_request = ! empty( $this->state['single_request'] );
		$this->state    = $saved;
		if ( $single_request ) {
			$this->state['single_request'] = 1;
		}

		$this->started = microtime( true );
		$this->budget  = max( 2, (float) $budget );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) ceil( $this->budget ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		try {
			while ( $this->state['step'] < count( $this->state['steps'] ) && $this->has_time() ) {

				$step = $this->state['steps'][ $this->state['step'] ];
				$done = $this->do_step( $step );

				if ( $done ) {
					$this->state['step']++;
					$this->state['cursor'] = '';
					unset( $this->state['step_data'] );
				}

				// Stopped (or taken over) while the batch ran: go no further.
				if ( ! $this->save() || 'running' !== $this->state['status'] ) {
					break;
				}

				// Start the referral history in a fresh request: the plugin caches
				// coupon details for the rest of a request, from before the coupons
				// were assigned in the affiliates step.
				if ( $done && 'affiliates' === $step && empty( $this->state['single_request'] ) ) {
					break;
				}
			}

			if ( 'running' === $this->state['status'] && $this->state['step'] >= count( $this->state['steps'] ) ) {
				$this->complete();
			}
		} catch ( Throwable $e ) {
			$this->state['status'] = 'failed';
			/* translators: %s: error message */
			$this->log( 'error', sprintf( __( 'The migration stopped with an error: %s', 'woo-coupon-usage' ), $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' ) );
			$this->save();
			self::update_run( $this->state['run_id'], array( 'status' => 'failed' ) );
		} finally {
			self::unlock();
		}

		return $this->state;
	}

	/**
	 * Run until done, ignoring the time budget (WP-CLI and tests).
	 *
	 * @param int $max_calls Safety limit.
	 * @return array
	 */
	public function run_to_end( $max_calls = 100000 ) {
		$this->state['single_request'] = 1;
		$calls                         = 0;
		while ( 'running' === $this->state['status'] && $calls++ < $max_calls ) {
			$this->run( 3600 );
		}
		return $this->state;
	}

	/**
	 * Take the batch lock.
	 *
	 * @return bool
	 */
	protected static function lock() {
		global $wpdb;

		// A MySQL named lock: taking it is atomic, and the database lets it go
		// when the request ends - however it ends - so it can never go stale.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
		if ( null !== $got ) {
			return '1' === (string) $got;
		}

		// No named locks on this database (some proxies): a timed option.
		$lock = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $lock && time() - $lock < 300 ) {
			return false;
		}
		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	/**
	 * Release the batch lock.
	 */
	protected static function unlock() {
		global $wpdb;
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		if ( null === $released ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Whether a batch holds the lock right now.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		global $wpdb;
		$free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::lock_name() ) );
		if ( null !== $free ) {
			return '0' === (string) $free;
		}
		$lock = (int) get_option( self::LOCK_OPTION, 0 );
		return $lock && time() - $lock < 300;
	}

	/**
	 * The named lock, per site. Named locks belong to the whole database server,
	 * which other sites may share with the same table prefix - so the database
	 * name goes into it too (hashed: 64 characters at most).
	 *
	 * @return string
	 */
	protected static function lock_name() {
		global $wpdb;
		return 'wcusage_migration_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix );
	}

	/**
	 * Update a run's summary in the history.
	 *
	 * @param string $run_id
	 * @param array  $values
	 */
	protected static function update_run( $run_id, $values ) {
		$runs = self::get_runs();
		if ( isset( $runs[ $run_id ] ) ) {
			$runs[ $run_id ] = array_merge( $runs[ $run_id ], $values );
			update_option( self::RUNS_OPTION, $runs, false );
		}
	}

	/**
	 * Run one batch of a step.
	 *
	 * @param string $step
	 * @return bool Whether the step is finished.
	 */
	protected function do_step( $step ) {
		$method = 'step_' . $step;
		if ( ! method_exists( $this, $method ) ) {
			return true;
		}
		return (bool) $this->$method();
	}

	/**
	 * Mark the run finished.
	 */
	protected function complete() {

		// While both plugins run, an order can earn commission in each of them.
		if ( function_exists( 'wcusage_migration_source_is_active' ) && wcusage_migration_source_is_active( $this->state['source'] ) ) {
			/* translators: %s: plugin name */
			$this->log( 'info', sprintf( __( 'Once you have checked the results, deactivate %s: while it is active, new orders can earn commission in both plugins. Its old referral links keep working here.', 'woo-coupon-usage' ), $this->source->get_name() ) );
		}

		$this->state['status']   = 'completed';
		$this->state['finished'] = time();
		$this->save();

		self::update_run(
			$this->state['run_id'],
			array(
				'status'   => 'completed',
				'finished' => time(),
				'counts'   => $this->state['counts'],
			)
		);
	}

	/**
	 * Batch size for the run.
	 *
	 * @param int $scale Multiplier for cheap records (visits).
	 * @return int
	 */
	protected function limit( $scale = 1 ) {
		return max( 10, (int) $this->option( 'batch_size' ) ) * $scale;
	}

	/* ---------------------------------------------------------------------
	 * Affiliate lookups
	 * ------------------------------------------------------------------ */

	/**
	 * Imported affiliates for a set of source IDs (from any run).
	 *
	 * @param array $source_ids
	 * @return array source ID => array( user_id, coupons, primary, code, status )
	 */
	protected function affiliates_for( $source_ids ) {
		$map = array();
		foreach ( $this->journal->find( 'affiliate', $source_ids ) as $source_id => $row ) {
			$data    = $row['data'];
			$primary = isset( $data['primary'] ) ? (int) $data['primary'] : 0;
			// A coupon deleted since (or by an undone run) no longer counts.
			$coupons = array();
			foreach ( (array) ( $data['coupons'] ?? array() ) as $coupon_id ) {
				$post = get_post( (int) $coupon_id );
				if ( $post && 'shop_coupon' === $post->post_type && 'publish' === $post->post_status ) {
					$coupons[ (int) $coupon_id ] = wc_format_coupon_code( $post->post_title );
				}
			}
			if ( $primary && ! isset( $coupons[ $primary ] ) ) {
				$primary = $coupons ? (int) key( $coupons ) : 0;
			}
			$preassigned = array();
			foreach ( (array) ( $data['preassigned'] ?? array() ) as $coupon_id ) {
				if ( isset( $coupons[ (int) $coupon_id ] ) ) {
					$preassigned[] = $coupons[ (int) $coupon_id ];
				}
			}
			$map[ (string) $source_id ] = array(
				'user_id'     => (int) $row['target_id'],
				'coupons'     => $coupons,
				'preassigned' => $preassigned,
				'primary'     => $primary,
				'code'        => $primary && isset( $coupons[ $primary ] ) ? $coupons[ $primary ] : '',
				'status'      => isset( $data['status'] ) ? $data['status'] : 'active',
			);
		}
		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------ */

	/**
	 * Program settings.
	 *
	 * @return bool
	 */
	protected function step_settings() {
		// Applying the same settings again changes nothing, and the journal keeps
		// the values each run replaced, so undoing runs in turn restores them.
		$changed = $this->writer->apply_settings( $this->source->get_settings() );
		$this->count( 'settings_applied', count( $changed ) );
		return true;
	}

	/**
	 * Affiliate groups.
	 *
	 * @return bool
	 */
	protected function step_groups() {
		if ( ! $this->pro ) {
			return true;
		}
		$groups   = $this->source->get_groups();
		$existing = $this->journal->find( 'group', wp_list_pluck( $groups, 'source_id' ) );
		foreach ( $groups as $group ) {
			if ( isset( $existing[ (string) $group['source_id'] ] ) ) {
				continue;
			}
			$this->pro->create_group( $group );
			$this->count( 'groups' );
		}
		return true;
	}

	/**
	 * Whether an affiliate's own rate cannot replace their group's here.
	 *
	 * In the plugins migrated from, an affiliate's own rate replaces their
	 * group's. Here it goes on their coupon, which overrides the group role's
	 * percentage and fixed-per-product rates - but a coupon's fixed amount per
	 * order of 0 does not override a role's positive one, so the group's amount
	 * would be paid on top. Such an affiliate is left out of the group.
	 *
	 * @param array  $affiliate
	 * @param string $role
	 * @return bool
	 */
	protected function own_rate_clashes_with_group( $affiliate, $role ) {
		if ( ! $this->pro || null === $affiliate['rate'] || ! $this->option( 'migrate_rates' ) ) {
			return false;
		}
		if ( 'flat_order' === $affiliate['rate_type'] && (float) $affiliate['rate'] > 0 ) {
			return false;
		}
		return (float) wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_order_role_' . $role, '0' ) > 0;
	}

	/**
	 * Affiliates: users, roles, coupons, registrations, rates, profiles.
	 *
	 * @return bool
	 */
	protected function step_affiliates() {

		$limit = $this->limit();
		$batch = $this->source->get_affiliates( $this->state['cursor'], $limit );

		if ( ! $batch ) {
			return true;
		}

		$existing = $this->journal->find( 'affiliate', wp_list_pluck( $batch, 'source_id' ) );

		if ( ! $this->writer->has_reserved_codes() ) {
			$this->writer->reserve_codes( $this->source->get_virtual_coupon_codes() );
		}

		$groups = array();
		if ( $this->pro && in_array( 'groups', $this->source->get_supported(), true ) && $this->option( 'migrate_groups' ) ) {
			foreach ( $this->journal->all( 'group' ) as $row ) {
				$groups[ (string) $row['source_id'] ] = isset( $row['data']['role'] ) ? $row['data']['role'] : '';
			}
		}

		foreach ( $batch as $affiliate ) {
			$this->state['cursor'] = $affiliate['source_id'];
			if ( isset( $existing[ $affiliate['source_id'] ] ) ) {
				$this->count( 'affiliates_already_imported' );
				continue;
			}
			$this->import_affiliate( $affiliate, $groups );
		}

		return count( $batch ) < $limit;
	}

	/**
	 * Import one affiliate.
	 *
	 * @param array $affiliate
	 * @param array $groups Group source ID => role.
	 */
	protected function import_affiliate( $affiliate, $groups ) {

		$label   = $affiliate['email'] ? $affiliate['email'] : ( $affiliate['username'] ? $affiliate['username'] : '#' . $affiliate['source_id'] );
		$status  = $affiliate['status'];
		$suspend = false;

		if ( 'inactive' === $status ) {
			switch ( $this->option( 'inactive_affiliates' ) ) {
				case 'skip':
					$this->count( 'affiliates_skipped_inactive' );
					return;
				case 'active':
					$status = 'active';
					break;
				default:
					$status  = 'active';
					$suspend = true;
			}
		}

		if ( in_array( $status, array( 'pending', 'rejected' ), true ) && ! $this->option( 'migrate_registrations' ) ) {
			$this->count( 'affiliates_skipped_' . $status );
			return;
		}

		// Coupons the old plugin linked to them, that can be assigned to them here.
		$linked    = array();
		$to_create = array();
		if ( 'active' === $status ) {
			foreach ( $affiliate['coupons'] as $coupon_id ) {
				$post = get_post( $coupon_id );
				if ( ! $post || 'shop_coupon' !== $post->post_type || 'trash' === $post->post_status ) {
					continue;
				}
				$linked[] = (int) $coupon_id;
			}
			// Codes the old plugin only kept itself (AffiliateWP's dynamic coupons):
			// a WooCommerce coupon with the code is assigned like a linked one, and
			// the others are created as WooCommerce coupons further down.
			foreach ( $affiliate['virtual_coupons'] as $code ) {
				$existing = WCUsage_Migration_Writer::live_coupon_id( $code );
				if ( $existing ) {
					$linked[] = $existing;
				} else {
					$to_create[] = $code;
				}
			}
			$linked = array_values( array_unique( $linked ) );
			if ( ! $linked && ! $to_create && 'existing_only' === $this->option( 'coupon_mode' ) && ! $this->writer->user_coupons( $this->writer->find_user( $affiliate ) ) ) {
				/* translators: %s: affiliate */
				$this->log( 'info', sprintf( __( 'Skipped %s: they have no coupon, and only existing coupons are being assigned.', 'woo-coupon-usage' ), $label ) );
				$this->count( 'affiliates_skipped_no_coupon' );
				return;
			}
		}

		// Their WordPress user.
		$accepted_role = wcusage_get_setting_value( 'wcusage_field_registration_accepted_role', 'coupon_affiliate' );
		$pending_role  = wcusage_get_setting_value( 'wcusage_field_registration_pending_role', 'subscriber' );
		$user_id       = $this->writer->find_user( $affiliate );
		$created_user  = false;

		if ( ! $user_id ) {
			$can_create = in_array( 'users_optional', $this->source->get_supported(), true ) ? 'create' === $this->option( 'missing_users' ) : true;
			// Creating accounts takes its own capability (see the start handler),
			// which a site admin on multisite does not have. WP-CLI runs as no user.
			if ( $can_create && wp_doing_ajax() && ! current_user_can( 'create_users' ) ) {
				$can_create = false;
			}
			if ( ! $can_create ) {
				/* translators: %s: affiliate */
				$this->log( 'info', sprintf( __( 'Skipped %s: they have no WordPress user account.', 'woo-coupon-usage' ), $label ) );
				$this->count( 'affiliates_skipped_no_user' );
				return;
			}
			$role    = 'active' === $status && $this->option( 'add_role' ) && get_role( $accepted_role ) ? $accepted_role : ( get_role( $pending_role ) ? $pending_role : get_option( 'default_role', 'subscriber' ) );
			$user_id = $this->writer->create_user( $affiliate, $role );
			if ( is_wp_error( $user_id ) || ! $user_id ) {
				/* translators: 1: affiliate, 2: reason */
				$this->log( 'warning', sprintf( __( 'Skipped %1$s: a user account could not be created (%2$s).', 'woo-coupon-usage' ), $label, is_wp_error( $user_id ) ? $user_id->get_error_message() : '' ) );
				$this->count( 'affiliates_failed' );
				return;
			}
			$created_user = true;
			$this->count( 'users_created' );
		}

		$changes     = array();
		$roles_added = array();
		$coupons     = array();
		$preassigned = array();

		if ( 'active' === $status ) {

			if ( $this->option( 'add_role' ) ) {
				$this->writer->add_role( $user_id, $accepted_role, $roles_added );
			}

			// The coupons they already have here, as an affiliate: those keep their
			// rates, and the affiliate keeps their status and registration.
			$existing_coupons = $created_user ? array() : $this->writer->user_coupons( $user_id );

			// Already an affiliate here, with no coupon linked in the old plugin:
			// they keep the coupons they have rather than getting another one, and
			// those coupons' orders stay Coupon Affiliates' own.
			$own = ! $linked && ! $to_create && ! $created_user && 'always_create' !== $this->option( 'coupon_mode' ) ? $this->writer->user_coupons( $user_id ) : array();
			if ( $own ) {
				$coupons     = $own;
				$preassigned = $own;
				$this->count( 'coupons_kept' );
			}

			// A new coupon first, when one is created, so their old links point at it
			// - unless its code is one of the old plugin's own, created below.
			$format   = (string) $this->option( 'coupon_code_format' );
			$new_code = $to_create ? wc_format_coupon_code( $this->writer->format_code( $format ? $format : '{username}', $affiliate, $user_id ) ) : '';
			if ( ! $own && ( 'always_create' === $this->option( 'coupon_mode' ) || ( ! $linked && ! $to_create ) ) && ! in_array( $new_code, $to_create, true ) ) {
				$coupon_id = $this->writer->create_coupon( $affiliate, $user_id );
				if ( $coupon_id ) {
					$coupons[] = $coupon_id;
					$this->count( $this->writer->reused_coupon ? 'coupons_assigned' : 'coupons_created' );
					$this->note_reused_coupon( $coupon_id );
					if ( in_array( (int) $coupon_id, $existing_coupons, true ) ) {
						$preassigned[] = (int) $coupon_id;
					}
				}
			}

			foreach ( $linked as $coupon_id ) {
				$result = $this->writer->assign_coupon( $coupon_id, $user_id, $affiliate['source_id'] );
				if ( 'assigned' === $result || 'already' === $result ) {
					$coupons[] = $coupon_id;
					if ( 'already' === $result ) {
						// Theirs here already: its orders are Coupon Affiliates' own.
						$preassigned[] = $coupon_id;
					}
					$this->count( 'coupons_assigned' );
				} elseif ( 'conflict' === $result ) {
					/* translators: 1: coupon code, 2: affiliate */
					$this->log( 'warning', sprintf( __( 'Coupon "%1$s" already belongs to another affiliate here, so it was not assigned to %2$s.', 'woo-coupon-usage' ), $this->writer->coupon_code( $coupon_id ), $label ) );
					$this->count( 'coupons_conflicts' );
				}
			}

			// The old plugin's own codes, as WooCommerce coupons with the settings it
			// gave them, so customers can keep using them.
			if ( ! $own ) {
				foreach ( $to_create as $code ) {
					$coupon_id = $this->writer->create_coupon_with_code( $code, $affiliate, $user_id, $this->source->get_virtual_coupon_template() );
					if ( $coupon_id ) {
						$coupons[] = $coupon_id;
						$this->count( 'coupons_created_from_codes' );
						if ( $this->pro ) {
							$adopted = $this->pro->adopt_order_commission( $coupon_id, $code );
							if ( $adopted['unpaid'] || $adopted['pending_order'] ) {
								$this->journal->add( 'adopted', $code, 'coupon', $coupon_id, $adopted );
							}
						}
					} else {
						/* translators: 1: coupon code, 2: affiliate */
						$this->log( 'warning', sprintf( __( 'Coupon "%1$s" could not be created for %2$s.', 'woo-coupon-usage' ), $code, $label ) );
					}
				}
			}

			$coupons = array_values( array_unique( $coupons ) );

			if ( ! $coupons ) {
				if ( 'existing_only' !== $this->option( 'coupon_mode' ) ) {
					$coupon_id = $this->writer->create_coupon( $affiliate, $user_id );
					if ( $coupon_id ) {
						$coupons[] = $coupon_id;
						$this->count( $this->writer->reused_coupon ? 'coupons_assigned' : 'coupons_created' );
						$this->note_reused_coupon( $coupon_id );
					}
				}
			}

			if ( ! $coupons ) {
				/* translators: %s: affiliate */
				$this->log( 'warning', sprintf( __( '%s has no coupon here, so their referrals and balances could not be imported.', 'woo-coupon-usage' ), $label ) );
				$this->count( 'affiliates_without_coupon' );
			}

			// Not on the coupons an affiliate already had here: they keep their rates.
			$rated = array();
			if ( $this->pro && null !== $affiliate['rate'] && $this->option( 'migrate_rates' ) ) {
				$rated = array_diff( $coupons, $preassigned, $existing_coupons );
				foreach ( $rated as $coupon_id ) {
					$this->pro->apply_coupon_rate( $coupon_id, $affiliate['rate'], $affiliate['rate_type'] ? $affiliate['rate_type'] : 'percent', $affiliate['source_id'] );
				}
				if ( $rated ) {
					$this->count( 'rates_applied' );
				}
			}

			if ( $affiliate['group_source_id'] && ! empty( $groups[ $affiliate['group_source_id'] ] ) ) {
				$group_role = $groups[ $affiliate['group_source_id'] ];
				if ( $rated && $this->own_rate_clashes_with_group( $affiliate, $group_role ) ) {
					/* translators: 1: affiliate, 2: role */
					$this->log( 'info', sprintf( __( '%1$s has a commission rate of their own, so they were not added to the "%2$s" group: its fixed amount per order would have been paid on top of their rate.', 'woo-coupon-usage' ), $label, $group_role ) );
					$this->count( 'groups_skipped_own_rate' );
				} else {
					$this->writer->add_role( $user_id, $group_role, $roles_added );
				}
			}

			// An affiliate already active here stays so, whatever the old plugin said.
			if ( $suspend && ! $existing_coupons ) {
				$this->writer->suspend( $user_id, $changes );
				$this->count( 'affiliates_suspended' );
			}

			$this->writer->save_profile_fields( $user_id, $affiliate, $changes );

			if ( $this->pro && $this->option( 'migrate_payout_details' ) ) {
				$this->pro->apply_payout_details( $user_id, $affiliate, $changes );
			}

			if ( $this->pro && $this->option( 'migrate_store_credit' ) && $affiliate['store_credit'] > 0 ) {
				$this->pro->add_store_credit( $user_id, $affiliate['store_credit'], $changes );
				$this->count( 'store_credit', $affiliate['store_credit'] );
			}

			// An existing affiliate already has their registration.
			if ( $coupons && ! $existing_coupons && $this->option( 'registration_records' ) ) {
				$this->writer->add_registration( $user_id, $affiliate, 'accepted', $this->writer->coupon_code( $coupons[0] ) );
			}

			// The source decides which values its links carried: an affiliate ID is
			// not always one of them (YITH links carry a token, which by default is
			// the user ID - another affiliate's ID could collide with it).
			if ( $coupons ) {
				$this->writer->record_identifiers( $affiliate, $coupons[0] );
			}

			$this->count( 'affiliates_imported' );

		} else {

			$code = $this->writer->format_code( (string) $this->option( 'coupon_code_format' ), $affiliate, $user_id );
			$this->writer->add_registration( $user_id, $affiliate, 'pending' === $status ? 'pending' : 'declined', $code );
			$this->writer->save_profile_fields( $user_id, $affiliate, $changes );

			if ( $this->pro && $this->option( 'migrate_payout_details' ) ) {
				$this->pro->apply_payout_details( $user_id, $affiliate, $changes );
			}

			$this->count( 'pending' === $status ? 'registrations_pending' : 'registrations_declined' );
		}

		$this->journal->add(
			'affiliate',
			$affiliate['source_id'],
			'user',
			$user_id,
			array(
				'created_user' => $created_user,
				'roles_added'  => $roles_added,
				'meta'         => $changes,
				'coupons'      => $coupons,
				'preassigned'  => $preassigned,
				'primary'      => $coupons ? (int) $coupons[0] : 0,
				'status'       => 'active' === $status ? 'active' : ( 'pending' === $status ? 'pending' : 'declined' ),
			)
		);
	}

	/**
	 * Per-product and per-category commission rates.
	 *
	 * @return bool
	 */
	protected function step_product_rates() {
		if ( ! $this->pro ) {
			return true;
		}
		$rates = $this->source->get_product_rates();
		// Only products and categories not already done by an earlier run.
		$done = $this->journal->find( 'product_rate', array_merge( array_keys( (array) $rates['products'] ), (array) $rates['excluded'] ) );
		foreach ( array_keys( $done ) as $product_id ) {
			unset( $rates['products'][ $product_id ] );
			$rates['excluded'] = array_diff( (array) $rates['excluded'], array( $product_id ) );
		}
		$done_cats = $this->journal->find( 'category_rate', array_keys( (array) $rates['categories'] ) );
		foreach ( array_keys( $done_cats ) as $term_id ) {
			unset( $rates['categories'][ $term_id ] );
		}
		$applied = $this->pro->apply_product_rates( $rates );
		$this->count( 'product_rates', $applied );

		// The old plugin let an affiliate's own (or group) rate beat a product's;
		// this plugin lets the product's win unless told otherwise.
		if ( $applied && 'affiliate' === $this->source->get_rate_priority() && ( ! empty( $this->state['counts']['rates_applied'] ) || ! empty( $this->state['counts']['groups'] ) )
			&& 'product' === wcusage_get_setting_value( 'wcusage_field_priority_commission', 'product' ) ) {
			/* translators: %s: plugin name */
			$this->log( 'warning', sprintf( __( 'In %s an affiliate\'s own rate (or their group\'s) came before a product\'s rate. Here product rates come first: to keep the old order, set "Commission priority" to the coupon\'s rate in the commission settings.', 'woo-coupon-usage' ), $this->source->get_name() ) );
		}
		return true;
	}

	/**
	 * Referral history: credit past orders and record their commission.
	 *
	 * @return bool
	 */
	protected function step_referrals() {

		$limit = $this->limit();
		$batch = $this->source->get_referrals( $this->state['cursor'], $limit );

		if ( ! $batch ) {
			$this->settle_paid_groups();
			return true;
		}

		$ids        = wp_list_pluck( $batch, 'source_id' );
		$done       = $this->journal->find( 'referral', $ids ) + $this->journal->find( 'non_order', $ids );

		// Pending referrals an earlier run left to their orders (referral history
		// off): the plugin grants those itself when they complete, so they are
		// not imported again once the old plugin approves them.
		$left = $this->journal->find( 'history_pending', $ids );

		// Commissions an earlier run imported, under parts that have changed since
		// (a split by status that moved on): not imported again.
		$done_groups = array();
		foreach ( $this->journal->find( 'referral_group', array_filter( wp_list_pluck( $batch, 'group_id' ), 'strlen' ) ) as $group => $row ) {
			if ( $row['run_id'] !== $this->state['run_id'] ) {
				$done_groups[ $group ] = true;
			}
		}
		$affiliates = $this->affiliates_for( wp_list_pluck( $batch, 'affiliate_source_id' ) );
		$statuses   = WCUsage_Migration_Writer::order_statuses( wp_list_pluck( $batch, 'order_id' ) );

		$balances = $this->pro && $this->option( 'migrate_balances' );
		$history  = (bool) $this->option( 'migrate_referrals' );
		$since    = (string) $this->option( 'referrals_since' );

		foreach ( $batch as $referral ) {

			$this->state['cursor'] = $referral['source_id'];

			// The newest referral the old plugin recorded: orders after it were placed
			// once it had stopped tracking (see step_release()).
			if ( $referral['date'] && $referral['date'] > ( $this->state['last_referral'] ?? '' ) ) {
				$this->state['last_referral'] = $referral['date'];
			}

			if ( 'paid' === $referral['status'] && $balances ) {
				$this->note_paid_since( $referral, $done, $done_groups );
			}

			if ( isset( $done[ $referral['source_id'] ] ) ) {
				continue;
			}
			if ( isset( $left[ $referral['source_id'] ] ) ) {
				if ( $left[ $referral['source_id'] ]['run_id'] !== $this->state['run_id'] ) {
					$this->count( 'referrals_skipped_already_imported' );
				}
				continue;
			}

			if ( '' !== $referral['group_id'] && isset( $done_groups[ $referral['group_id'] ] ) ) {
				$this->count( 'referrals_skipped_already_imported' );
				continue;
			}

			// The old plugin knew this order, whatever comes of the referral: it was
			// placed while it was tracking (see step_release()).
			if ( $referral['order_id'] > 0 ) {
				$this->journal->add( 'order_seen', $referral['source_id'], 'order', (int) $referral['order_id'] );
			}

			$this->count( 'referrals_read' );

			$affiliate = isset( $affiliates[ $referral['affiliate_source_id'] ] ) ? $affiliates[ $referral['affiliate_source_id'] ] : null;
			if ( ! $affiliate || 'active' !== $affiliate['status'] || ! $affiliate['primary'] ) {
				$this->count( 'referrals_skipped_no_affiliate' );
				continue;
			}

			if ( 'rejected' === $referral['status'] ) {
				$this->count( 'referrals_skipped_rejected' );
				continue;
			}

			// Commission paid to a parent affiliate for a sub-affiliate's sale: not
			// an order of the parent's own, so it can only be carried as a balance.
			if ( $referral['is_tier'] ) {
				if ( $balances && in_array( $referral['status'], array( 'unpaid', 'paid' ), true ) ) {
					$this->pro->add_non_order_commission( $affiliate['primary'], $referral['source_id'], $referral['amount'], $referral['status'], 'tier' );
					$this->mark_group( $referral );
					$this->count( 'tier_commission_' . $referral['status'], $referral['amount'] );
				} else {
					$this->count( 'referrals_skipped_tier' );
				}
				continue;
			}

			// A side commission on an order the source itself credits to another
			// affiliate: carried as a balance, whatever order it happens to meet first.
			if ( ! empty( $referral['secondary'] ) ) {
				$this->non_order( $referral, $affiliate, $balances, 'second_referral' );
				continue;
			}

			$order_status = $referral['order_id'] && isset( $statuses[ $referral['order_id'] ] ) ? $statuses[ $referral['order_id'] ] : '';
			$too_old      = $since && $referral['date'] && substr( $referral['date'], 0, 10 ) < $since;

			if ( ! $order_status || $too_old ) {
				$this->non_order( $referral, $affiliate, $balances, $too_old ? 'before_date' : ( $referral['order_id'] ? 'order_missing' : 'no_order' ) );
				continue;
			}

			if ( in_array( $order_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				$this->count( 'referrals_skipped_failed_order' );
				continue;
			}

			// Still awaiting approval in the old plugin, though the order completed.
			if ( 'pending' === $referral['status'] && 'skip' === $this->option( 'unapproved_commission' ) && function_exists( 'wcusage_check_payout_order_status' ) && wcusage_check_payout_order_status( $referral['order_id'], '', $order_status ) ) {
				$this->count( 'referrals_skipped_unapproved' );
				continue;
			}

			// Referral history not wanted: orders are left exactly as they are, and
			// what is owed (or was paid) is carried as a balance only. Commission
			// still pending is not history yet, and no balance can hold it: with
			// balances migrated, its order is credited as usual below, so it earns
			// what the old plugin recorded - here it would earn the plugin's own
			// figure from when it was placed (before the affiliate's rate was set),
			// or nothing at all on an order tracked by a link.
			if ( ! $history && ! ( $balances && 'pending' === $referral['status'] ) ) {
				// Still pending (balances not migrated either): left to the order
				// itself, which the plugin grants to the coupon as normal when it
				// completes (step_release() leaves it).
				if ( 'pending' === $referral['status'] ) {
					$this->journal->add( 'history_pending', $referral['source_id'], 'order', (int) $referral['order_id'] );
					$this->mark_group( $referral );
				}
				$this->non_order( $referral, $affiliate, $balances, 'history_off' );
				continue;
			}

			$order = wc_get_order( $referral['order_id'] );
			if ( ! $order ) {
				$this->non_order( $referral, $affiliate, $balances, 'order_missing' );
				continue;
			}

			// Their coupon on the order, if the customer used one; otherwise the main one.
			$coupon_id = $affiliate['primary'];
			$code      = $affiliate['code'];
			foreach ( $order->get_coupon_codes() as $order_code ) {
				$order_code = wc_format_coupon_code( $order_code );
				$match      = array_search( $order_code, $affiliate['coupons'], true );
				if ( false !== $match ) {
					$coupon_id = (int) $match;
					$code      = $order_code;
					break;
				}
			}

			// The order already carries another of this plugin's referrals. More
			// of the same affiliate's commission (a plugin recording an order in
			// several parts) is added to it; another affiliate's is carried as a
			// balance, since only one affiliate can own an order here.
			$migrated_from = (string) $order->get_meta( WCUSAGE_MIGRATED_FROM_META, true );
			if ( '' !== $migrated_from && 0 === strpos( $migrated_from, $this->state['source'] . ':' ) && $migrated_from !== $this->state['source'] . ':' . $referral['source_id'] ) {
				$owner_code = wc_format_coupon_code( (string) $order->get_meta( 'wcusage_referrer_coupon', true ) );
				$owner_id   = array_search( $owner_code, $affiliate['coupons'], true );
				if ( false !== $owner_id ) {
					$this->add_order_part( $order, $referral, $affiliate, (int) $owner_id, $owner_code, $balances );
				} else {
					$this->non_order( $referral, $affiliate, $balances, 'second_referral' );
				}
				continue;
			}

			// Who the order is credited to here already. Their own coupons count
			// as theirs (assigned or created now, or one they had here before);
			// a coupon a migration from this source assigned to someone else
			// does not count either - the old plugin's choice stands over it.
			$theirs    = '';
			$other     = '';
			$overruled = array();
			foreach ( $this->writer->order_attributions( $order ) as $owned_code => $owner ) {
				if ( in_array( $owned_code, $affiliate['coupons'], true ) || (int) $owner === (int) $affiliate['user_id'] ) {
					$theirs = $theirs ? $theirs : $owned_code;
					continue;
				}
				if ( $this->is_migrated_coupon( $owned_code ) ) {
					$overruled[] = $owned_code;
					continue;
				}
				$other = $owned_code;
				break;
			}

			$granted  = $order->get_meta( 'wcu_commission_paid', true );
			$preowned = $theirs && ( in_array( $theirs, $affiliate['preassigned'], true ) || ! in_array( $theirs, $affiliate['coupons'], true ) );
			if ( $preowned && '' !== (string) $granted && (float) $granted > 0 ) {
				// Already theirs here before the migration, commission and all. Paid
				// by the old plugin, though: no longer owed here (its payout is
				// imported, so it would be paid twice).
				if ( 'paid' === $referral['status'] && $balances ) {
					$granted_here = $order->get_meta( function_exists( 'wcusage_order_granted_commission_key' ) ? wcusage_order_granted_commission_key( $theirs ) : '', true );
					$granted_here = '' !== (string) $granted_here ? (float) $granted_here : (float) $granted;
					$this->settle_paid_since( $referral['source_id'], $referral['amount'], array( array( $this->coupon_id_for( $theirs, $affiliate ), $granted_here ) ), 0 );
				}
				$this->count( 'referrals_already_in_coupon_affiliates' );
				continue;
			}
			// Credited to another affiliate here (even alongside this one's coupon).
			if ( $other ) {
				/* translators: 1: order ID, 2: coupon code */
				$this->log( 'warning', sprintf( __( 'Order #%1$d is already credited to coupon "%2$s" here, so it was left as it is (any commission still owed on it is added to the imported affiliate\'s balance).', 'woo-coupon-usage' ), $order->get_id(), $other ) );
				$this->count( 'referrals_conflicts' );
				$this->non_order( $referral, $affiliate, $balances, 'order_credited_elsewhere' );
				continue;
			}

			$changes  = array();
			$state    = 'stats';
			$unpaid   = 0;
			$pending  = 0;
			$released = array();

			// Commission the plugin already recorded on the order (it grants
			// commission even on coupons with no affiliate, by default) is taken
			// back first, or it would be counted twice - or, with balances not
			// migrated, paid on top of what the old plugin paid.
			if ( $this->pro ) {
				$released = $this->pro->release_commission( $order, $changes );
			}

			// The lifetime referrer outranks the referrer coupon everywhere, so one
			// left from before would keep the order's commission handling (a later
			// cancel, say) on another coupon.
			$lifetime = $order->get_meta( 'lifetime_affiliate_coupon_referrer', true );
			if ( is_scalar( $lifetime ) && '' !== (string) $lifetime && wc_format_coupon_code( (string) $lifetime ) !== $code ) {
				$this->writer->delete_order_meta( $order, array( 'lifetime_affiliate_coupon_referrer' ), $changes );
			}

			$this->writer->attribute_order( $order, $code, $affiliate['user_id'], $referral['source_id'], $referral['amount'], $changes );

			// Another imported affiliate's coupon on the order earns nothing on it
			// (the plugin would otherwise work out commission for it too).
			if ( $this->pro ) {
				foreach ( array_diff( $overruled, array( $code ) ) as $overruled_code ) {
					$this->pro->release_coupon_commission( $order, $overruled_code, (int) wc_get_coupon_id_by_code( $overruled_code ), $changes );
				}
			}

			// Balances not migrated: nothing is owed or paid here for the order,
			// now or when it completes - it is history only.
			if ( ! $balances && $this->pro ) {
				$this->pro->release_coupon_commission( $order, $code, $coupon_id, $changes );
			}

			if ( $balances ) {
				$amount  = $referral['amount'];
				$result  = $this->pro->record_order_commission( $order, $referral['status'], $coupon_id, $code, $amount, $changes );
				$state   = $result['state'];
				$unpaid  = $result['unpaid'];
				$pending = $result['pending_order'];
				$this->count( 'commission_' . $state, 'none' === $state ? 0 : $amount );
			}

			$this->journal->add(
				'referral',
				$referral['source_id'],
				'order',
				$order->get_id(),
				array(
					'coupon_id'     => (int) $coupon_id,
					'code'          => $code,
					'amount'        => $referral['amount'],
					'state'         => $state,
					'unpaid'        => $unpaid,
					'pending_order' => $pending,
					'released'      => $released,
					'meta'          => $changes,
				)
			);

			$this->mark_group( $referral );
			$this->count( 'referrals_imported' );
		}

		if ( count( $batch ) < $limit ) {
			$this->settle_paid_groups();
			return true;
		}
		return false;
	}

	/**
	 * Add another part of the same affiliate's commission to an order this
	 * migration already credited to them.
	 *
	 * @param WC_Order $order
	 * @param array    $referral
	 * @param array    $affiliate
	 * @param int      $coupon_id   The coupon the order is credited to.
	 * @param string   $coupon_code
	 * @param bool     $balances
	 */
	protected function add_order_part( $order, $referral, $affiliate, $coupon_id, $coupon_code, $balances ) {

		$changes = array();
		$state   = 'stats';
		$unpaid  = 0;
		$pending = 0;

		if ( 'rejected' !== $referral['status'] && '' !== (string) $order->get_meta( WCUSAGE_MIGRATED_COMMISSION_META, true ) ) {
			$total = wc_format_decimal( round( (float) $order->get_meta( WCUSAGE_MIGRATED_COMMISSION_META, true ) + $referral['amount'], 2 ), 2 );
			$this->writer->set_order_meta(
				$order,
				array(
					WCUSAGE_MIGRATED_COMMISSION_META => $total,
					'wcusage_total_commission'       => $total,
				),
				$changes
			);
			if ( function_exists( 'wcusage_forget_order_calculation' ) ) {
				wcusage_forget_order_calculation( $order->get_id() );
			}
		}

		if ( $balances ) {
			$amount  = $referral['amount'];
			$result  = $this->pro->add_order_commission_part( $order, $referral['status'], $coupon_id, $coupon_code, $amount, $changes );
			$state   = $result['state'];
			$unpaid  = $result['unpaid'];
			$pending = $result['pending_order'];
			$this->count( 'commission_' . $state, 'none' === $state ? 0 : $amount );
		}

		$this->journal->add(
			'referral',
			$referral['source_id'],
			'order',
			$order->get_id(),
			array(
				'coupon_id'     => (int) $coupon_id,
				'code'          => $coupon_code,
				'amount'        => $referral['amount'],
				'state'         => $state,
				'unpaid'        => $unpaid,
				'pending_order' => $pending,
				'part'          => true,
				'meta'          => $changes,
			)
		);

		$this->mark_group( $referral );
		$this->count( 'referral_parts_merged' );
	}

	/**
	 * Note that a commission split into parts (see the group_id of a referral)
	 * was imported, once one of its parts is: a later run does not import the
	 * parts again under the IDs they may have since.
	 *
	 * @param array $referral
	 */
	protected function mark_group( $referral ) {
		if ( '' !== $referral['group_id'] && ! $this->journal->get( 'referral_group', $referral['group_id'] ) ) {
			$this->journal->add( 'referral_group', $referral['group_id'], 'referral', 0 );
		}
	}

	/**
	 * A paid referral an earlier run imported: the old plugin may have paid it
	 * since, while it was imported as owed. Settled now for a referral of its
	 * own; for one split into parts, once all of them are read (see
	 * settle_paid_groups()).
	 *
	 * @param array $referral
	 * @param array $done        Journal rows of this batch's referrals.
	 * @param array $done_groups Groups earlier runs imported.
	 */
	protected function note_paid_since( $referral, $done, $done_groups ) {
		$row     = $done[ $referral['source_id'] ] ?? null;
		$earlier = $row && $row['run_id'] !== $this->state['run_id'];

		if ( '' !== $referral['group_id'] ) {
			if ( $earlier || isset( $done_groups[ $referral['group_id'] ] ) ) {
				$group = (string) $referral['group_id'];
				$this->state['step_data']['paid_since'][ $group ] = round( ( $this->state['step_data']['paid_since'][ $group ] ?? 0 ) + $referral['amount'], 2 );
			}
			return;
		}

		if ( $earlier ) {
			$this->settle_imported( $referral['source_id'], $referral['amount'], false );
		}
	}

	/**
	 * Settle the split referrals note_paid_since() collected.
	 */
	protected function settle_paid_groups() {
		foreach ( (array) ( $this->state['step_data']['paid_since'] ?? array() ) as $group => $paid ) {
			$this->settle_imported( (string) $group, (float) $paid, true );
		}
		unset( $this->state['step_data']['paid_since'] );
	}

	/**
	 * Settle a referral (or split one) earlier runs imported, against what the
	 * old plugin now has as paid under it.
	 *
	 * @param string $key   Referral or group ID.
	 * @param float  $paid
	 * @param bool   $group
	 */
	protected function settle_imported( $key, $paid, $group ) {

		$paid_before = 0;
		$owed        = array();

		foreach ( $this->journal->rows_for( array( 'referral', 'non_order' ), $key, $group ) as $row ) {
			$data   = (array) $row['data'];
			$amount = (float) ( $data['amount'] ?? 0 );

			if ( 'non_order' === $row['entity'] ) {
				if ( 'paid' === ( $data['kind'] ?? '' ) ) {
					$paid_before += $amount;
				} elseif ( (float) ( $data['unpaid'] ?? 0 ) > 0 ) {
					$owed[] = array( (int) $row['target_id'], (float) $data['unpaid'] );
				}
				continue;
			}

			$state     = (string) ( $data['state'] ?? '' );
			$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
			if ( 'paid' === $state ) {
				$paid_before += $amount;
			} elseif ( 'granted' === $state ) {
				$owed[] = array( $coupon_id, (float) ( $data['unpaid'] ?? 0 ) );
			} elseif ( 'pending' === $state ) {
				// Pending order commission becomes owed once the order completes here.
				$order = wc_get_order( (int) $row['target_id'] );
				if ( $order && function_exists( 'wcusage_check_payout_order_status' ) && wcusage_check_payout_order_status( $order->get_id(), '', $order->get_status() ) && (float) $order->get_meta( 'wcu_commission_paid', true ) > 0 ) {
					$owed[] = array( $coupon_id, (float) ( $data['pending_order'] ?? 0 ) );
				}
			}
		}

		$this->settle_paid_since( $key, $paid, $owed, $paid_before );
	}

	/**
	 * Commission imported (or held here) as owed that the old plugin has paid:
	 * taken off the coupon's unpaid balance, as paying it here would have. Its
	 * payout is imported with the others, so it would otherwise be paid twice.
	 *
	 * Journaled per coupon under the referral's key ("paid_since"), so a later
	 * run settles only what is left, and undo puts it back.
	 *
	 * @param string  $key         Referral or group ID.
	 * @param float   $paid        What the old plugin has as paid under it now.
	 * @param array[] $owed        array( coupon ID, amount owed here ) in order.
	 * @param float   $paid_before What was imported as paid already.
	 */
	protected function settle_paid_since( $key, $paid, $owed, $paid_before ) {

		$settled = 0;
		foreach ( $this->journal->rows_for( array( 'paid_since' ), $key ) as $row ) {
			$settled += (float) ( $row['data']['settled'] ?? 0 );
		}

		$total  = array_sum( wp_list_pluck( $owed, 1 ) );
		$settle = round( min( (float) $paid - $paid_before, $total ) - $settled, 2 );
		if ( $settle < 0.01 ) {
			return;
		}

		// Owed parts already settled are passed over, oldest first.
		$skip = $settled;
		foreach ( $owed as $part ) {
			$coupon_id = (int) $part[0];
			$skipped   = min( $skip, (float) $part[1] );
			$skip     -= $skipped;
			$chunk     = round( min( (float) $part[1] - $skipped, $settle ), 2 );
			if ( $chunk <= 0 || ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
				continue;
			}
			$settle -= $chunk;

			// Never below nothing owed: paid out here meanwhile, it is gone already.
			$taken = round( max( 0, min( $chunk, (float) get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true ) ) ), 2 );
			$this->writer->add_to_coupon_meta( $coupon_id, 'wcu_text_unpaid_commission', -$taken );
			$this->journal->add(
				'paid_since',
				$key,
				'coupon_balance',
				$coupon_id,
				array(
					'coupon_id' => $coupon_id,
					'amount'    => $taken,
					'settled'   => $chunk,
				)
			);
			$this->count( 'commission_paid_since', $taken );

			if ( $settle < 0.01 ) {
				break;
			}
		}
	}

	/**
	 * The coupon ID of one of an affiliate's codes (or one they owned here before).
	 *
	 * @param string $code
	 * @param array  $affiliate
	 * @return int
	 */
	protected function coupon_id_for( $code, $affiliate ) {
		$id = array_search( $code, $affiliate['coupons'], true );
		return false !== $id ? (int) $id : (int) wc_get_coupon_id_by_code( $code );
	}

	/**
	 * Note a coupon create_coupon() found unassigned and gave the affiliate:
	 * matched by its code only, never theirs in the old plugin.
	 *
	 * @param int $coupon_id
	 */
	protected function note_reused_coupon( $coupon_id ) {
		if ( $coupon_id && $this->writer->reused_coupon ) {
			$this->journal->add( 'coupon_reused', (string) (int) $coupon_id, 'coupon', (int) $coupon_id );
		}
	}

	/**
	 * Whether a coupon was assigned or created by a migration from this source.
	 *
	 * @param string $code
	 * @return bool
	 */
	protected function is_migrated_coupon( $code ) {
		if ( null === $this->migrated_coupons ) {
			$this->migrated_coupons = array();
			foreach ( $this->journal->all( 'coupon' ) as $row ) {
				$coupon_code = $this->writer->coupon_code( (int) $row['target_id'] );
				if ( '' !== $coupon_code ) {
					$this->migrated_coupons[ $coupon_code ] = true;
				}
			}
		}
		return isset( $this->migrated_coupons[ wc_format_coupon_code( $code ) ] );
	}

	/**
	 * Coupon codes assigned or created by migrations from this source.
	 *
	 * @var array|null
	 */
	protected $migrated_coupons = null;

	/**
	 * A referral with no usable WooCommerce order: carried as a balance only.
	 *
	 * @param array  $referral
	 * @param array  $affiliate
	 * @param bool   $balances Whether balances are being migrated.
	 * @param string $reason
	 */
	protected function non_order( $referral, $affiliate, $balances, $reason ) {

		if ( ! $balances || ! in_array( $referral['status'], array( 'unpaid', 'paid' ), true ) ) {
			$this->count( 'referrals_skipped_' . $reason );
			return;
		}

		$this->pro->add_non_order_commission( $affiliate['primary'], $referral['source_id'], $referral['amount'], $referral['status'], $reason );
		$this->mark_group( $referral );
		$this->count( 'non_order_commission_' . $referral['status'], $referral['amount'] );
	}

	/**
	 * Take back the commission Coupon Affiliates recorded on the coupons this
	 * run assigned, while they had no affiliate, for orders the old plugin did
	 * not credit.
	 *
	 * With its default settings the plugin grants commission even to coupons
	 * with no affiliate. Once the coupon belongs to an affiliate, that would
	 * become theirs - for orders the old plugin never paid them for.
	 *
	 * @return bool
	 */
	protected function step_release() {
		global $wpdb;

		if ( ! $this->pro ) {
			return true;
		}

		// The coupons: assigned (not created, unless from one of the old plugin's own
		// codes) by a migration from this source - this run, or one stopped before
		// it got this far - with no affiliate before.
		// A completed run already dealt with its own (or chose to keep their
		// commission): what they earned here since is the affiliate's.
		if ( ! isset( $this->state['step_data']['coupons'] ) ) {
			$coupons   = array();
			$completed = array();
			foreach ( self::get_runs() as $run_id => $run ) {
				if ( 'completed' === ( $run['status'] ?? '' ) && $run_id !== $this->state['run_id'] ) {
					$completed[ $run_id ] = true;
				}
			}
			$finished = array();
			foreach ( $this->journal->all( 'release_done' ) as $row ) {
				$finished[ (int) $row['target_id'] ] = true;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT run_id, target_id, data FROM ' . WCUsage_Migration_Journal::table() . " WHERE source = %s AND entity = 'coupon'", $this->state['source'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$data = $row['data'] ? json_decode( $row['data'], true ) : array();
				// A coupon created from the old plugin's own code has orders (see
				// WCUsage_Migration_Writer_Pro::adopt_order_commission()).
				if ( ( ! empty( $data['created'] ) && empty( $data['from_code'] ) ) || isset( $completed[ $row['run_id'] ] ) || isset( $finished[ (int) $row['target_id'] ] ) ) {
					continue;
				}
				$before = $data['meta']['wcu_select_coupon_user'] ?? array();
				$owner  = ! empty( $before['e'] ) ? (int) $before['v'] : 0;
				if ( ! $owner || ! get_userdata( $owner ) ) {
					$coupons[] = (int) $row['target_id'];
				}
			}
			$this->state['step_data']['coupons'] = array_values( array_unique( $coupons ) );
			$this->state['step_data']['index']   = 0;

			// Orders placed after the old plugin's newest referral came once it had
			// stopped tracking: the commission the plugin gave them is the
			// affiliate's to keep. With no referral read at all, the run's start.
			$this->state['step_data']['cutoff'] = ! empty( $this->state['last_referral'] ) ? (string) $this->state['last_referral'] : wp_date( 'Y-m-d H:i:s', (int) $this->state['started'] );
		}

		$coupons = $this->state['step_data']['coupons'];
		$index   = (int) $this->state['step_data']['index'];
		if ( ! isset( $coupons[ $index ] ) ) {
			return true;
		}

		$coupon_id = (int) $coupons[ $index ];
		$code      = $this->writer->coupon_code( $coupon_id );
		$limit     = $this->limit();

		$order_ids = '' === $code ? array() : array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'coupon' AND LOWER(order_item_name) = %s AND order_id > %d ORDER BY order_id ASC LIMIT %d",
					$code,
					(int) $this->state['cursor'],
					$limit
				)
			)
		);

		// Orders the old plugin still had pending when referral history was not
		// migrated (theirs to earn as normal when they complete), and orders it had
		// a referral for at all (placed while it was tracking, whatever their date).
		$pending_left = array();
		$seen         = array();
		if ( $order_ids ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT entity, target_id FROM ' . WCUsage_Migration_Journal::table() . " WHERE source = %s AND entity IN ('history_pending','order_seen') AND target_id IN (" . implode( ',', $order_ids ) . ')', $this->state['source'] ), ARRAY_A ) as $marker ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 'history_pending' === $marker['entity'] ) {
					$pending_left[ (int) $marker['target_id'] ] = true;
				} else {
					$seen[ (int) $marker['target_id'] ] = true;
				}
			}
		}
		// Only for coupons the old plugin linked to the affiliate. One matched by
		// its code alone was never theirs there - before the switch or after.
		$cutoff = $this->journal->get( 'coupon_reused', (string) $coupon_id ) ? '' : (string) ( $this->state['step_data']['cutoff'] ?? '' );

		foreach ( $order_ids as $order_id ) {
			$this->state['cursor'] = $order_id;

			if ( isset( $pending_left[ $order_id ] ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			// Imported from the old plugin: its commission is the imported one.
			if ( 0 === strpos( (string) $order->get_meta( WCUSAGE_MIGRATED_FROM_META, true ), $this->state['source'] . ':' ) ) {
				continue;
			}
			$created = $order->get_date_created();
			if ( $cutoff && ! isset( $seen[ $order_id ] ) && $created && $created->date( 'Y-m-d H:i:s' ) > $cutoff ) {
				continue;
			}

			$changes  = array();
			$released = $this->pro->release_coupon_commission( $order, $code, $coupon_id, $changes );
			if ( ! $changes ) {
				continue;
			}

			$this->journal->add(
				'release',
				$coupon_id . ':' . $order_id,
				'order',
				$order_id,
				array(
					'coupon_id' => $coupon_id,
					'released'  => $released,
					'meta'      => $changes,
				)
			);
			if ( function_exists( 'wcusage_forget_order_calculation' ) ) {
				wcusage_forget_order_calculation( $order_id );
			}
			if ( $released['unpaid'] || $released['pending_order'] ) {
				$this->count( 'released_orders' );
				$this->count( 'released_amount', $released['unpaid'] + $released['pending_order'] );
			}
		}

		if ( count( $order_ids ) < $limit ) {
			// Done with this coupon: a later run (after this one stopped, say)
			// leaves it alone.
			$this->journal->add( 'release_done', (string) $coupon_id, 'coupon', $coupon_id );
			$this->state['step_data']['index'] = $index + 1;
			$this->state['cursor']             = '';
		}

		return ! isset( $coupons[ (int) $this->state['step_data']['index'] ] );
	}

	/**
	 * Payout history.
	 *
	 * @return bool
	 */
	protected function step_payouts() {

		if ( ! $this->pro ) {
			return true;
		}

		$limit = $this->limit();
		$batch = $this->source->get_payouts( $this->state['cursor'], $limit );

		if ( ! $batch ) {
			return true;
		}

		$done       = $this->journal->find( 'payout_source', wp_list_pluck( $batch, 'source_id' ) );
		$affiliates = $this->affiliates_for( wp_list_pluck( $batch, 'affiliate_source_id' ) );

		foreach ( $batch as $payout ) {

			$this->state['cursor'] = $payout['source_id'];

			if ( isset( $done[ $payout['source_id'] ] ) ) {
				continue;
			}

			if ( 'cancelled' === $payout['status'] || $payout['amount'] <= 0 ) {
				$this->count( 'payouts_skipped' );
				continue;
			}

			$affiliate = isset( $affiliates[ $payout['affiliate_source_id'] ] ) ? $affiliates[ $payout['affiliate_source_id'] ] : null;
			if ( ! $affiliate || 'active' !== $affiliate['status'] || ! $affiliate['primary'] ) {
				$this->count( 'payouts_skipped_no_affiliate' );
				continue;
			}

			// Split across the coupons whose commission it paid, when known.
			$shares = array();
			if ( $payout['referral_source_ids'] ) {
				$by_coupon = array();
				$total     = 0;
				foreach ( $this->journal->find( 'referral', $payout['referral_source_ids'] ) as $row ) {
					$coupon_id = (int) ( $row['data']['coupon_id'] ?? 0 );
					$amount    = (float) ( $row['data']['amount'] ?? 0 );
					if ( $coupon_id && $amount > 0 && isset( $affiliate['coupons'][ $coupon_id ] ) ) {
						$by_coupon[ $coupon_id ] = ( $by_coupon[ $coupon_id ] ?? 0 ) + $amount;
						$total                  += $amount;
					}
				}
				if ( count( $by_coupon ) > 1 && $total > 0 ) {
					$left = $payout['amount'];
					$keys = array_keys( $by_coupon );
					$last = end( $keys );
					foreach ( $by_coupon as $coupon_id => $amount ) {
						$share                = $coupon_id === $last ? round( $left, 2 ) : round( $payout['amount'] * $amount / $total, 2 );
						$shares[ $coupon_id ] = $share;
						$left                -= $share;
					}
				} elseif ( 1 === count( $by_coupon ) ) {
					$shares[ (int) key( $by_coupon ) ] = $payout['amount'];
				}
			}
			if ( ! $shares ) {
				$shares[ $affiliate['primary'] ] = $payout['amount'];
			}

			foreach ( $shares as $coupon_id => $share ) {
				if ( $this->pro->add_payout( $payout, $affiliate['user_id'], $coupon_id, $share, $payout['source_id'] . '#' . $coupon_id ) ) {
					$this->count( 'payouts_' . ( 'pending' === $payout['status'] ? 'pending' : 'paid' ), $share );
				}
			}

			$this->journal->add( 'payout_source', $payout['source_id'], 'none', 0 );
			$this->count( 'payouts_imported' );
		}

		return count( $batch ) < $limit;
	}

	/**
	 * Close each coupon's books, once its referrals and payouts are in.
	 *
	 * @return bool
	 */
	protected function step_books() {

		if ( ! $this->pro ) {
			return true;
		}

		global $wpdb;

		$table  = WCUsage_Migration_Journal::table();
		$ledger = array();
		$after  = 0;

		while ( true ) {
			// Every run from this source still in the journal: one stopped before its
			// books were closed is balanced now, and earlier adjustments (below) keep a
			// finished run's from being added again.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, entity, target_id, data FROM {$table} WHERE source = %s AND entity IN ('referral','non_order','payout','paid_since') AND id > %d ORDER BY id ASC LIMIT 2000", $this->state['source'], $after ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$data  = $row['data'] ? json_decode( $row['data'], true ) : array();

				if ( 'referral' === $row['entity'] ) {
					$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
					$add       = array( 'paid_orders' => 'paid' === ( $data['state'] ?? '' ) ? (float) $data['amount'] : 0 );
				} elseif ( 'paid_since' === $row['entity'] ) {
					// Owed when imported, paid by the old plugin since.
					$coupon_id = (int) $row['target_id'];
					$add       = array( 'paid_orders' => (float) ( $data['amount'] ?? 0 ) );
				} elseif ( 'non_order' === $row['entity'] ) {
					$coupon_id = (int) $row['target_id'];
					$add       = array(
						'non_order_unpaid' => 'unpaid' === ( $data['kind'] ?? '' ) ? (float) $data['amount'] : 0,
						'non_order_paid'   => 'paid' === ( $data['kind'] ?? '' ) ? (float) $data['amount'] : 0,
					);
				} else {
					$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
					$add       = array( 'payouts' => (float) ( $data['amount'] ?? 0 ) );
				}

				if ( ! $coupon_id ) {
					continue;
				}
				if ( ! isset( $ledger[ $coupon_id ] ) ) {
					$ledger[ $coupon_id ] = array(
						'user_id'          => $this->writer->coupon_owner( $coupon_id ),
						'paid_orders'      => 0,
						'non_order_unpaid' => 0,
						'non_order_paid'   => 0,
						'payouts'          => 0,
					);
				}
				foreach ( $add as $key => $value ) {
					$ledger[ $coupon_id ][ $key ] += $value;
				}
			}
		}

		// Adjustments already recorded (by an earlier run, or this step run again
		// after an interruption) are not added twice.
		$existing = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT target_id, data FROM {$table} WHERE source = %s AND entity = 'adjustment'", $this->state['source'] ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data                                 = $row['data'] ? json_decode( $row['data'], true ) : array();
			$existing[ (int) $row['target_id'] ] = ( $existing[ (int) $row['target_id'] ] ?? 0 ) + (float) ( $data['adjustment'] ?? 0 );
		}

		// No historical payouts are made up when payout history was left out on
		// purpose - only where the plugin keeps no payout records at all.
		$balancing = (bool) $this->option( 'balancing_payouts' );
		if ( in_array( 'payouts', $this->source->get_supported(), true ) && ! $this->option( 'migrate_payouts' ) ) {
			$balancing = false;
		}

		$result = $this->pro->close_books( $ledger, $balancing, $existing );
		$this->count( 'balancing_payouts', $result['balancing_payouts'] );
		$this->count( 'balancing_payouts_amount', $result['balancing_amount'] );

		return true;
	}

	/**
	 * Creatives.
	 *
	 * @return bool
	 */
	protected function step_creatives() {

		if ( ! $this->pro || ! post_type_exists( 'wcu-creatives' ) ) {
			if ( $this->pro ) {
				$this->log( 'info', __( 'Creatives were not imported: the Creatives module is switched off in the settings.', 'woo-coupon-usage' ) );
			}
			return true;
		}

		$creatives = $this->source->get_creatives();
		$done      = $this->journal->find( 'creative', wp_list_pluck( $creatives, 'source_id' ) );

		foreach ( $creatives as $creative ) {
			if ( isset( $done[ (string) $creative['source_id'] ] ) ) {
				continue;
			}
			if ( $this->pro->create_creative( $creative ) ) {
				$this->count( 'creatives' );
			} else {
				$this->count( 'creatives_skipped_text' );
			}
		}

		if ( ! empty( $this->state['counts']['creatives_skipped_text'] ) ) {
			$this->log( 'info', __( 'Text-only creatives were not imported: Coupon Affiliates creatives are banners, videos and files.', 'woo-coupon-usage' ) );
		}

		return true;
	}

	/**
	 * Referral visits, into the clicks table.
	 *
	 * @return bool
	 */
	protected function step_visits() {

		// A later run carries on after the visits an earlier one imported.
		if ( '' === (string) $this->state['cursor'] && empty( $this->state['step_data']['visits_started'] ) ) {
			$this->state['step_data']['visits_started'] = 1;
			foreach ( $this->journal->all( 'visits' ) as $row ) {
				$last = (string) ( $row['data']['last'] ?? '' );
				if ( '' !== $last && ( '' === (string) $this->state['cursor'] || ( is_numeric( $last ) ? (float) $last > (float) $this->state['cursor'] : strcmp( $last, (string) $this->state['cursor'] ) > 0 ) ) ) {
					$this->state['cursor'] = $last;
				}
			}
		}

		$limit = $this->limit( 10 );
		$batch = $this->source->get_visits( $this->state['cursor'], $limit, (string) $this->option( 'visits_since' ) );

		if ( ! $batch ) {
			return true;
		}

		$first_source = $batch[0]['source_id'];
		$last_source  = $batch[ count( $batch ) - 1 ]['source_id'];

		// Imported already, by a request that ended before it could save how far
		// it got.
		if ( $this->journal->get( 'visits', $first_source . '-' . $last_source ) ) {
			$this->state['cursor'] = $last_source;
			return count( $batch ) < $limit;
		}

		$affiliates = $this->affiliates_for( wp_list_pluck( $batch, 'affiliate_source_id' ) );
		$rows       = array();
		$campaigns  = array();
		$coupons    = array();

		foreach ( $batch as $visit ) {
			$affiliate = isset( $affiliates[ $visit['affiliate_source_id'] ] ) ? $affiliates[ $visit['affiliate_source_id'] ] : null;
			if ( ! $affiliate || 'active' !== $affiliate['status'] || ! $affiliate['primary'] ) {
				$this->count( 'visits_skipped' );
				continue;
			}
			if ( $this->option( 'visits_converted_only' ) && ! $visit['converted'] ) {
				continue;
			}
			$rows[]                           = array(
				'couponid'  => $affiliate['primary'],
				'campaign'  => sanitize_text_field( $visit['campaign'] ),
				'page'      => (string) $this->writer->page_id_for_url( $visit['url'] ),
				'referrer'  => $this->writer->referrer_domain( $visit['referrer'] ),
				'ipaddress' => sanitize_text_field( $visit['ip'] ),
				'orderid'   => (int) $visit['order_id'],
				'converted' => $visit['converted'] ? 1 : 0,
				'date'      => $visit['date'] ? $visit['date'] : current_time( 'mysql' ),
			);
			$coupons[ $affiliate['primary'] ] = true;
			if ( '' !== $visit['campaign'] ) {
				$campaigns[ $affiliate['primary'] . '|' . $visit['campaign'] ] = array( $affiliate['primary'], $visit['campaign'] );
			}
		}

		$range = $this->writer->insert_clicks( $rows );

		$this->journal->add(
			'visits',
			$first_source . '-' . $last_source,
			'click_range',
			0,
			array(
				'first'   => $range ? $range[0] : 0,
				'last_id' => $range ? $range[1] : 0,
				'last'    => (string) $last_source,
				'coupons' => array_keys( $coupons ),
			)
		);

		$this->state['cursor'] = $last_source;
		$this->count( 'visits', count( $rows ) );

		if ( $this->pro && $this->option( 'migrate_campaigns' ) ) {
			foreach ( $campaigns as $pair ) {
				if ( $this->pro->add_campaign( $pair[0], $pair[1] ) ) {
					$this->count( 'campaigns' );
				}
			}
		}

		return count( $batch ) < $limit;
	}

	/**
	 * Keep the old referral links working.
	 *
	 * @return bool
	 */
	protected function step_legacy() {
		$links = $this->source->get_legacy_links();
		if ( empty( $links['vars'] ) && empty( $links['paths'] ) && empty( $links['cookies'] ) ) {
			return true;
		}
		$this->writer->save_legacy_links( $links, (int) $this->option( 'legacy_cookie_days' ) );
		$this->count( 'legacy_links', 1 );
		return true;
	}

	/**
	 * Tidy up: rebuild statistics for every coupon touched, clear caches and
	 * log the migration.
	 *
	 * @return bool
	 */
	protected function step_finalize() {

		foreach ( self::touched_coupons( $this->state['run_id'] ) as $coupon_id ) {
			delete_post_meta( $coupon_id, 'wcu_last_refreshed' );
		}

		self::clear_caches();
		wcusage_migration_reset_detection();

		if ( function_exists( 'wcusage_add_activity' ) ) {
			wcusage_add_activity(
				0,
				'migration_completed',
				/* translators: 1: plugin name, 2: affiliates, 3: referrals */
				sprintf( __( '%1$s: %2$d affiliates, %3$d referrals.', 'woo-coupon-usage' ), $this->source->get_name(), (int) ( $this->state['counts']['affiliates_imported'] ?? 0 ), (int) ( $this->state['counts']['referrals_imported'] ?? 0 ) )
			);
		}

		return true;
	}

	/**
	 * Every coupon a run created, assigned or changed balances on.
	 *
	 * @param string $run_id
	 * @return int[]
	 */
	public static function touched_coupons( $run_id ) {
		global $wpdb;

		$table   = WCUsage_Migration_Journal::table();
		$coupons = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT target_id FROM {$table} WHERE run_id = %s AND target_type IN ('coupon','coupon_balance','postmeta') AND target_id > 0", $run_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$after = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, data FROM {$table} WHERE run_id = %s AND entity IN ('referral','payout','release') AND id > %d ORDER BY id ASC LIMIT 2000", $run_id, $after ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$data  = $row['data'] ? json_decode( $row['data'], true ) : array();
				if ( ! empty( $data['coupon_id'] ) ) {
					$coupons[] = (int) $data['coupon_id'];
				}
			}
		}

		$coupons = array_values( array_unique( array_filter( $coupons ) ) );

		// Only coupons (product rate rows also use "postmeta").
		return array_values(
			array_filter(
				$coupons,
				function ( $id ) {
					return 'shop_coupon' === get_post_type( $id );
				}
			)
		);
	}

	/**
	 * Clear the plugin's cached affiliate lists and dashboards.
	 */
	public static function clear_caches() {
		if ( function_exists( 'wcusage_clear_coupon_users_cache' ) ) {
			wcusage_clear_coupon_users_cache();
		}
		if ( function_exists( 'wcusage_clear_dashboard_caches' ) ) {
			wcusage_clear_dashboard_caches();
		}
	}

	/* ---------------------------------------------------------------------
	 * Cancelling and undoing
	 * ------------------------------------------------------------------ */

	/**
	 * Stop a run part-way. What it imported stays (and can be undone).
	 */
	public static function cancel() {
		$state = self::get_state();
		// Only a running import can be stopped (not a finished run, nor an undo).
		if ( empty( $state['run_id'] ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return;
		}
		// The lock is left alone: a batch still working holds it, and finds the
		// stop when it saves (see save()).
		$state['status']   = 'cancelled';
		$state['finished'] = time();
		update_option( self::STATE_OPTION, $state, false );
		self::update_run(
			$state['run_id'],
			array(
				'status'   => 'cancelled',
				'finished' => time(),
				'counts'   => isset( $state['counts'] ) ? $state['counts'] : array(),
			)
		);
	}

	/**
	 * Begin undoing a run.
	 *
	 * @param string $run_id
	 * @param bool   $delete_users Also delete user accounts the run created.
	 * @return array|WP_Error
	 */
	public static function start_undo( $run_id, $delete_users = false ) {

		// Held while the undo is set up, so a migration started meanwhile (in
		// another tab, say) cannot be overwritten by it.
		if ( ! self::lock() ) {
			// Stopped, but its last batch is still finishing.
			return new WP_Error( 'wcusage_migration_busy', __( 'The migration\'s last batch is still finishing. Try again in a few seconds.', 'woo-coupon-usage' ) );
		}
		if ( self::is_busy() ) {
			self::unlock();
			return new WP_Error( 'wcusage_migration_busy', __( 'A migration is running. Wait for it to finish, or cancel it first.', 'woo-coupon-usage' ) );
		}

		$runs = self::get_runs();
		if ( empty( $runs[ $run_id ] ) ) {
			self::unlock();
			return new WP_Error( 'wcusage_migration_run', __( 'Unknown migration run.', 'woo-coupon-usage' ) );
		}
		if ( self::undo_blocked_by( $run_id ) ) {
			self::unlock();
			return new WP_Error( 'wcusage_migration_run', __( 'A later migration from the same plugin builds on this one: undo that one first.', 'woo-coupon-usage' ) );
		}

		$state = array(
			'run_id'       => $run_id,
			'source'       => $runs[ $run_id ]['source'],
			'options'      => array(),
			'status'       => 'undoing',
			'delete_users' => $delete_users ? 1 : 0,
			'total'        => WCUsage_Migration_Journal::count_for_run( $run_id ),
			'undone'       => 0,
			'coupons'      => self::touched_coupons( $run_id ),
			'counts'       => array(),
			'messages'     => array(),
			'started'      => time(),
			'updated'      => time(),
		);

		update_option( self::STATE_OPTION, $state, false );
		self::update_run( $run_id, array( 'status' => 'undoing' ) );
		self::unlock();

		return $state;
	}

	/**
	 * A later run from the same source that has not been undone, if any: it
	 * builds on this run (its coupons, its imported referrals), so it has to be
	 * undone first.
	 *
	 * @param string $run_id
	 * @return string The later run's ID, or ''.
	 */
	public static function undo_blocked_by( $run_id ) {
		$runs = self::get_runs();
		if ( empty( $runs[ $run_id ] ) ) {
			return '';
		}
		// Newest first: the runs before this one came after it.
		foreach ( $runs as $other_id => $other ) {
			if ( (string) $other_id === (string) $run_id ) {
				break;
			}
			if ( ( $other['source'] ?? '' ) === ( $runs[ $run_id ]['source'] ?? '' ) && 'undone' !== ( $other['status'] ?? '' ) ) {
				return (string) $other_id;
			}
		}
		return '';
	}

	/**
	 * Undo as much of a run as fits in the time budget, newest change first.
	 *
	 * @param float $budget Seconds.
	 * @return array The state after this call.
	 */
	public static function run_undo( $budget = 20 ) {

		$state = self::get_state();
		if ( 'undoing' !== ( $state['status'] ?? '' ) ) {
			return $state;
		}
		if ( ! self::lock() ) {
			return $state;
		}
		// As it is now, under the lock.
		$state = self::get_state();
		if ( 'undoing' !== ( $state['status'] ?? '' ) ) {
			self::unlock();
			return $state;
		}

		$started = microtime( true );

		try {
			while ( microtime( true ) - $started < $budget ) {
				$rows = WCUsage_Migration_Journal::for_run( $state['run_id'], 100 );
				if ( ! $rows ) {
					break;
				}
				$ids = array();
				foreach ( $rows as $row ) {
					self::undo_row( $row, $state );
					$ids[] = (int) $row['id'];
				}
				WCUsage_Migration_Journal::delete_rows( $ids );
				WCUsage_Migration_Writer::flush_coupon_cache();
				$state['undone'] += count( $ids );
				$state['updated'] = time();
				update_option( self::STATE_OPTION, $state, false );
			}

			if ( ! WCUsage_Migration_Journal::count_for_run( $state['run_id'] ) ) {
				foreach ( array_unique( array_map( 'intval', (array) ( $state['delete_later'] ?? array() ) ) ) as $user_id ) {
					self::delete_created_user( $user_id, $state );
				}
				unset( $state['delete_later'], $state['adopted'] );
				foreach ( (array) $state['coupons'] as $coupon_id ) {
					if ( get_post( $coupon_id ) ) {
						delete_post_meta( $coupon_id, 'wcu_last_refreshed' );
					}
				}
				self::clear_caches();
				$state['status']   = 'undone';
				$state['finished'] = time();
				update_option( self::STATE_OPTION, $state, false );
				self::update_run(
					$state['run_id'],
					array(
						'status'      => 'undone',
						'undone_time' => time(),
					)
				);
			}
		} catch ( Throwable $e ) {
			$state['status']     = 'failed';
			$state['messages'][] = array(
				'level'   => 'error',
				'message' => $e->getMessage(),
				'time'    => time(),
			);
			update_option( self::STATE_OPTION, $state, false );
			// Shown as failed in the history, where Undo can be tried again.
			self::update_run( $state['run_id'], array( 'status' => 'failed' ) );
		} finally {
			self::unlock();
		}

		return $state;
	}

	/**
	 * Undo one journal row.
	 *
	 * @param array $row
	 * @param array $state Undo state (counts are updated).
	 */
	protected static function undo_row( $row, &$state ) {
		global $wpdb;

		$data      = is_array( $row['data'] ) ? $row['data'] : array();
		$target_id = (int) $row['target_id'];
		$meta      = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		switch ( $row['entity'] ) {

			case 'affiliate':
				self::restore_meta( 'user', $target_id, $meta, $state );
				$user = get_userdata( $target_id );
				if ( $user ) {
					foreach ( (array) ( $data['roles_added'] ?? array() ) as $role ) {
						$user->remove_role( $role );
					}
					// Once everything else is undone (see run_undo()), when it is clear
					// whether they still have a coupon.
					if ( ! empty( $data['created_user'] ) && ! empty( $state['delete_users'] ) ) {
						$state['delete_later'][] = $target_id;
					}
				}
				break;

			case 'coupon':
				if ( ! empty( $data['created'] ) ) {
					if ( 'shop_coupon' === get_post_type( $target_id ) ) {
						// Earning or paid out since: kept, with what it holds.
						if ( self::coupon_in_use( $target_id, $state ) ) {
							$state['counts']['undo_kept_coupons'] = ( $state['counts']['undo_kept_coupons'] ?? 0 ) + 1;
							$state['messages'][]                  = array(
								'level'   => 'warning',
								/* translators: %s: coupon code */
								'message' => sprintf( __( 'Coupon "%s" was kept: it has commission or payouts from after the migration.', 'woo-coupon-usage' ), get_the_title( $target_id ) ),
								'time'    => time(),
							);
							break;
						}
						// Through WooCommerce, which also forgets the coupon's ID for its
						// code (cached, with a persistent object cache, for good).
						$coupon = new WC_Coupon( $target_id );
						$coupon->delete( true );
					}
				} else {
					self::restore_meta( 'post', $target_id, $meta, $state );
				}
				break;

			case 'registration':
				$wpdb->delete( $wpdb->prefix . 'wcusage_register', array( 'id' => $target_id ), array( '%d' ) );
				break;

			case 'referral':
				$order     = wc_get_order( $target_id );
				$unpaid    = (float) ( $data['unpaid'] ?? 0 );
				$pending   = (float) ( $data['pending_order'] ?? 0 );
				$back      = (array) ( $data['released'] ?? array() );
				$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
				if ( $order ) {
					if ( ! self::order_still_holds( $order, $meta, 'wcusage_pending_commission' ) ) {
						$pending = 0;
					}
					$released = $back;
					$back     = self::releasable_back( $order, $back );

					// Its commission moved on since (completed, refunded, partly
					// refunded...): the plugin has moved those amounts itself, so the
					// order is kept exactly as it is now - still the affiliate's, with
					// what it holds. Taking the migration's amounts off, or giving back
					// what was released, would count them twice.
					if ( self::grant_moved_on( $order, $meta, (string) ( $data['code'] ?? '' ) ) || self::holds_back( $released, $back ) ) {
						$state['counts']['undo_kept_orders'] = ( $state['counts']['undo_kept_orders'] ?? 0 ) + 1;
						$unpaid                              = 0;
						$pending                             = 0;
						$back                                = array();
						// Commission the old plugin paid stays recorded as paid, as the
						// books did (see WCUsage_Migration_Writer_Pro::close_books()).
						if ( 'paid' === ( $data['state'] ?? '' ) && $coupon_id && get_post( $coupon_id ) ) {
							self::subtract( $coupon_id, WCUSAGE_MIGRATED_ADJUSTMENT_META, (float) ( $data['amount'] ?? 0 ) );
						}
					} else {
						self::restore_order_meta( $order, $meta, $state );
					}
					if ( function_exists( 'wcusage_forget_order_calculation' ) ) {
						wcusage_forget_order_calculation( $target_id );
					}
				}
				if ( $coupon_id && get_post( $coupon_id ) ) {
					self::subtract( $coupon_id, 'wcu_text_unpaid_commission', $unpaid );
					self::subtract( $coupon_id, 'wcu_text_pending_order_commission', $pending );
				}
				// Give back what was taken off other coupons' balances.
				foreach ( $back as $released_id => $released ) {
					if ( get_post( (int) $released_id ) ) {
						self::subtract( (int) $released_id, 'wcu_text_unpaid_commission', -1 * (float) ( $released['unpaid'] ?? 0 ) );
						self::subtract( (int) $released_id, 'wcu_text_pending_order_commission', -1 * (float) ( $released['pending_order'] ?? 0 ) );
					}
				}
				break;

			case 'release':
				$order     = wc_get_order( $target_id );
				$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
				$back      = array( $coupon_id => (array) ( $data['released'] ?? array() ) );
				if ( $order ) {
					$released = $back;
					$back     = self::releasable_back( $order, $back );
					// Moved on since, so not all of it can be given back: the order is
					// kept as it is now, the coupon still earning nothing on it.
					if ( self::holds_back( $released, $back ) ) {
						$state['counts']['undo_kept_orders'] = ( $state['counts']['undo_kept_orders'] ?? 0 ) + 1;
						$back                                = array();
					} else {
						self::restore_order_meta( $order, $meta, $state );
					}
					if ( function_exists( 'wcusage_forget_order_calculation' ) ) {
						wcusage_forget_order_calculation( $target_id );
					}
				}
				if ( $coupon_id && get_post( $coupon_id ) && isset( $back[ $coupon_id ] ) ) {
					self::subtract( $coupon_id, 'wcu_text_unpaid_commission', -1 * (float) ( $back[ $coupon_id ]['unpaid'] ?? 0 ) );
					self::subtract( $coupon_id, 'wcu_text_pending_order_commission', -1 * (float) ( $back[ $coupon_id ]['pending_order'] ?? 0 ) );
				}
				break;

			case 'non_order':
				if ( get_post( $target_id ) ) {
					self::subtract( $target_id, 'wcu_text_unpaid_commission', (float) ( $data['unpaid'] ?? 0 ) );
				}
				break;

			case 'adopted':
				// Commission a coupon made from an old code took in from its orders:
				// still its own when the coupon is undone (see coupon_in_use()).
				$state['adopted'][ $target_id ] = $data;
				break;

			case 'paid_since':
				if ( get_post( $target_id ) ) {
					self::subtract( $target_id, 'wcu_text_unpaid_commission', -1 * (float) ( $data['amount'] ?? 0 ) );
				}
				break;

			case 'adjustment':
				if ( get_post( $target_id ) ) {
					self::subtract( $target_id, WCUSAGE_MIGRATED_ADJUSTMENT_META, (float) ( $data['adjustment'] ?? 0 ) );
				}
				break;

			case 'payout':
				// Paid, cancelled or deleted here since: the plugin has moved its amount
				// itself, so the payout is left as it is now.
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wcusage_payouts WHERE id = %d", $target_id ) );
				if ( null === $current || ( isset( $data['status'] ) && (string) $current !== (string) $data['status'] ) ) {
					$state['counts']['undo_kept_changed'] = ( $state['counts']['undo_kept_changed'] ?? 0 ) + 1;
					// Still counted as paid out - or, cancelled, its amount was put back
					// into the unpaid balance - with none of the coupon's commission left
					// to explain it: noted as the migration's, as the books did.
					$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
					if ( $coupon_id && get_post( $coupon_id ) && null !== $current ) {
						self::subtract( $coupon_id, WCUSAGE_MIGRATED_ADJUSTMENT_META, -1 * (float) ( $data['amount'] ?? 0 ) );
					}
					break;
				}
				$wpdb->delete( $wpdb->prefix . 'wcusage_payouts', array( 'id' => $target_id ), array( '%d' ) );
				$coupon_id = (int) ( $data['coupon_id'] ?? 0 );
				if ( $coupon_id && get_post( $coupon_id ) ) {
					self::subtract( $coupon_id, 'wcu_text_pending_payment_commission', (float) ( $data['pending_payment'] ?? 0 ) );
				}
				break;

			case 'coupon_rate':
			case 'product_rate':
				self::restore_meta( 'post', $target_id, $meta, $state );
				break;

			case 'category_rate':
				self::restore_meta( 'term', $target_id, $meta, $state );
				break;

			case 'mla':
			case 'mla_access':
			case 'lifetime':
				self::restore_meta( 'user', $target_id, $meta, $state );
				break;

			case 'group':
				self::restore_settings( $meta, $state );
				if ( ! empty( $data['created'] ) && ! empty( $data['role'] ) && 0 === strpos( $data['role'], 'coupon_affiliate_' ) ) {
					remove_role( $data['role'] );
				}
				break;

			case 'settings':
				self::restore_settings( $meta, $state );
				break;

			case 'legacy_links':
				$config = get_option( 'wcusage_migration_legacy_links', array() );
				$config = is_array( $config ) ? $config : array();
				if ( isset( $data['previous'] ) && is_array( $data['previous'] ) ) {
					$config[ $data['key'] ] = $data['previous'];
				} else {
					unset( $config[ $data['key'] ] );
				}
				update_option( 'wcusage_migration_legacy_links', $config, true );
				break;

			case 'visits':
				$first   = (int) ( $data['first'] ?? 0 );
				$last    = (int) ( $data['last_id'] ?? 0 );
				$coupons = array_filter( array_map( 'intval', (array) ( $data['coupons'] ?? array() ) ) );
				if ( $first && $last && $coupons ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wcusage_clicks WHERE id BETWEEN %d AND %d AND couponid IN (" . implode( ',', $coupons ) . ')', $first, $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				break;

			case 'creative':
				if ( 'wcu-creatives' === get_post_type( $target_id ) ) {
					wp_delete_post( $target_id, true );
				}
				break;

			case 'campaign':
				$wpdb->delete( $wpdb->prefix . 'wcusage_campaigns', array( 'id' => $target_id ), array( '%d' ) );
				break;
		}
	}

	/**
	 * Whether a coupon the run created has been used since: with the run's own
	 * amounts taken off, it still holds commission, or payouts point at it.
	 *
	 * @param int   $coupon_id
	 * @param array $state Undo state.
	 * @return bool
	 */
	protected static function coupon_in_use( $coupon_id, $state ) {
		global $wpdb;

		$adopted = (array) ( $state['adopted'][ $coupon_id ] ?? array() );
		$own     = array(
			'wcu_text_unpaid_commission'          => (float) ( $adopted['unpaid'] ?? 0 ),
			'wcu_text_pending_order_commission'   => (float) ( $adopted['pending_order'] ?? 0 ),
			'wcu_text_pending_payment_commission' => 0,
			WCUSAGE_MIGRATED_ADJUSTMENT_META      => 0,
		);
		foreach ( $own as $key => $amount ) {
			if ( abs( (float) get_post_meta( $coupon_id, $key, true ) - $amount ) >= 0.005 ) {
				return true;
			}
		}

		$payouts = $wpdb->prefix . 'wcusage_payouts';
		return wcusage_migration_table_columns( $payouts ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$payouts} WHERE couponid = %d LIMIT 1", $coupon_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete a user account the run created, once the rest of it is undone -
	 * unless they still have a coupon (one kept for being used since, or given
	 * to them after the migration).
	 *
	 * @param int   $user_id
	 * @param array $state Undo state (counts are updated).
	 */
	protected static function delete_created_user( $user_id, &$state ) {
		if ( ! get_userdata( $user_id ) || user_can( $user_id, 'manage_options' ) ) {
			return;
		}
		$coupons = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'any',
				'meta_key'    => 'wcu_select_coupon_user', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		if ( $coupons ) {
			$state['counts']['users_kept_with_coupons'] = ( $state['counts']['users_kept_with_coupons'] ?? 0 ) + 1;
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		$state['counts']['users_deleted'] = ( $state['counts']['users_deleted'] ?? 0 ) + 1;
	}

	/**
	 * Take an amount back off a numeric coupon meta value.
	 *
	 * @param int    $coupon_id
	 * @param string $key
	 * @param float  $amount
	 */
	protected static function subtract( $coupon_id, $key, $amount ) {
		if ( ! $amount ) {
			return;
		}
		$value = round( (float) get_post_meta( $coupon_id, $key, true ) - $amount, 2 );
		if ( WCUSAGE_MIGRATED_ADJUSTMENT_META === $key && abs( $value ) < 0.005 ) {
			delete_post_meta( $coupon_id, $key );
			return;
		}
		update_post_meta( $coupon_id, $key, $value );
	}

	/**
	 * Whether a stored value still holds what the migration set.
	 *
	 * @param mixed $current
	 * @param mixed $new
	 * @return bool
	 */
	protected static function unchanged( $current, $new ) {
		if ( is_array( $current ) || is_array( $new ) || is_object( $current ) ) {
			return wp_json_encode( $current ) === wp_json_encode( $new );
		}
		return (string) $current === (string) $new;
	}

	/**
	 * Put meta back as it was - but only where it still holds what the
	 * migration set: anything changed since is kept.
	 *
	 * @param string $type    'post' | 'user' | 'term'
	 * @param int    $id
	 * @param array  $changes key => array( e, v, n )
	 * @param array  $state
	 */
	protected static function restore_meta( $type, $id, $changes, &$state ) {
		if ( ! $id ) {
			return;
		}
		foreach ( (array) $changes as $key => $change ) {
			$current = get_metadata( $type, $id, $key, true );
			if ( array_key_exists( 'n', $change ) && ! self::unchanged( $current, $change['n'] ) && ! ( null === $change['n'] && '' === $current ) ) {
				$state['counts']['undo_kept_changed'] = ( $state['counts']['undo_kept_changed'] ?? 0 ) + 1;
				continue;
			}
			if ( ! empty( $change['e'] ) ) {
				update_metadata( $type, $id, $key, $change['v'] );
			} else {
				delete_metadata( $type, $id, $key );
			}
		}
	}

	/**
	 * Whether an order still holds the value the migration gave one of its keys.
	 *
	 * @param WC_Order $order
	 * @param array    $changes The journaled meta changes.
	 * @param string   $key
	 * @return bool True too when the migration did not set the key.
	 */
	protected static function order_still_holds( $order, $changes, $key ) {
		if ( ! isset( $changes[ $key ] ) || ! array_key_exists( 'n', (array) $changes[ $key ] ) ) {
			return true;
		}
		$current = $order->get_meta( $key, true );
		return self::unchanged( $current, $changes[ $key ]['n'] ) || ( null === $changes[ $key ]['n'] && '' === $current );
	}

	/**
	 * Of the commission the migration took back from coupons on an order, what
	 * can be given back: unpaid commission while the order is still in a payout
	 * status, pending commission while it is still pending. Otherwise the plugin
	 * would have taken it off by now itself.
	 *
	 * @param WC_Order $order
	 * @param array    $released coupon ID => array( unpaid, pending_order )
	 * @return array
	 */
	protected static function releasable_back( $order, $released ) {
		$status    = $order->get_status();
		$in_payout = ! function_exists( 'wcusage_check_payout_order_status' ) || wcusage_check_payout_order_status( $order->get_id(), '', $status );
		$pending   = ! function_exists( 'wcusage_is_order_pending_status' ) || wcusage_is_order_pending_status( $status );
		foreach ( $released as $coupon_id => $amounts ) {
			$released[ $coupon_id ] = array(
				'unpaid'        => $in_payout ? (float) ( $amounts['unpaid'] ?? 0 ) : 0,
				'pending_order' => $pending ? (float) ( $amounts['pending_order'] ?? 0 ) : 0,
			);
		}
		return $released;
	}

	/**
	 * Whether the commission granted on an imported order changed since: from
	 * what the migration set, or granted since where it set none (an order that
	 * completed afterwards).
	 *
	 * @param WC_Order $order
	 * @param array    $changes The journaled meta changes.
	 * @param string   $code    The coupon the order was credited to.
	 * @return bool
	 */
	protected static function grant_moved_on( $order, $changes, $code ) {
		$keys = array( 'wcu_commission_paid' );
		if ( '' !== $code && function_exists( 'wcusage_order_granted_commission_key' ) ) {
			$keys[] = wcusage_order_granted_commission_key( $code );
		}
		foreach ( $keys as $key ) {
			$moved = isset( $changes[ $key ] ) ? ! self::order_still_holds( $order, $changes, $key ) : (float) $order->get_meta( $key, true ) > 0;
			if ( $moved ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether releasable_back() holds back any of what was released.
	 *
	 * @param array $released coupon ID => array( unpaid, pending_order )
	 * @param array $back     The same, as releasable_back() returned it.
	 * @return bool
	 */
	protected static function holds_back( $released, $back ) {
		foreach ( $released as $coupon_id => $amounts ) {
			foreach ( array( 'unpaid', 'pending_order' ) as $key ) {
				if ( (float) ( $amounts[ $key ] ?? 0 ) - (float) ( $back[ $coupon_id ][ $key ] ?? 0 ) >= 0.005 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Put order meta back as it was (HPOS or posts).
	 *
	 * @param WC_Order $order
	 * @param array    $changes
	 * @param array    $state
	 */
	protected static function restore_order_meta( $order, $changes, &$state ) {
		$dirty = false;
		// Saved figures the plugin works out again whenever they are missing.
		$caches  = array( 'wcusage_stats', 'wcusage_commission_summary' );
		$changes = (array) $changes;
		// Saved since the migration on an order that had none before: noted
		// nowhere, but worked out with the migrated referrer all the same.
		foreach ( $caches as $key ) {
			if ( ! isset( $changes[ $key ] ) ) {
				$changes[ $key ] = array(
					'e' => false,
					'v' => null,
				);
			}
		}
		foreach ( $changes as $key => $change ) {
			$current = $order->get_meta( $key, true );
			if ( in_array( $key, $caches, true ) && '' !== $current && ( empty( $change['e'] ) || ! self::unchanged( $current, $change['v'] ) ) ) {
				// Worked out again since the migration (maybe with its settings, or
				// its referrer): cleared, so they are worked out afresh, not kept.
				$order->delete_meta_data( $key );
				$dirty = true;
				continue;
			}
			if ( in_array( $key, $caches, true ) && empty( $change['e'] ) ) {
				continue;
			}
			if ( array_key_exists( 'n', $change ) && ! self::unchanged( $current, $change['n'] ) && ! ( null === $change['n'] && '' === $current ) ) {
				$state['counts']['undo_kept_changed'] = ( $state['counts']['undo_kept_changed'] ?? 0 ) + 1;
				continue;
			}
			if ( ! empty( $change['e'] ) ) {
				$order->update_meta_data( $key, $change['v'] );
			} else {
				$order->delete_meta_data( $key );
			}
			$dirty = true;
		}
		if ( $dirty ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Put plugin settings back as they were, where unchanged since.
	 *
	 * @param array $changes
	 * @param array $state
	 */
	protected static function restore_settings( $changes, &$state ) {
		if ( ! $changes ) {
			return;
		}
		$options = get_option( 'wcusage_options', array() );
		$options = is_array( $options ) ? $options : array();
		foreach ( $changes as $key => $change ) {
			$current = array_key_exists( $key, $options ) ? $options[ $key ] : null;
			if ( array_key_exists( 'n', $change ) && ! self::unchanged( $current, $change['n'] ) ) {
				$state['counts']['undo_kept_changed'] = ( $state['counts']['undo_kept_changed'] ?? 0 ) + 1;
				continue;
			}
			if ( ! empty( $change['e'] ) ) {
				$options[ $key ] = $change['v'];
			} else {
				unset( $options[ $key ] );
			}
		}
		// A full replace: the plugin merges every other update into the current
		// settings, which can never remove a key.
		$options['__full_replace'] = 1;
		update_option( 'wcusage_options', $options );
		if ( function_exists( 'wcusage_options_memo' ) ) {
			wcusage_options_memo( false );
		}
	}
}
