<?php
/**
 * WP-CLI commands for the migration tool - for large sites, where running
 * the migration from the terminal avoids keeping a browser tab open.
 *
 *   wp wcusage-migrate list
 *   wp wcusage-migrate preview <source>
 *   wp wcusage-migrate run <source> [--<option>=<value>...] [--yes]
 *   wp wcusage-migrate resume
 *   wp wcusage-migrate undo <run-id> [--delete-users] [--yes]
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_CLI {

	/**
	 * List the plugins that can be migrated from, and past runs.
	 *
	 * @subcommand list
	 */
	public function list_sources() {
		$rows = array();
		foreach ( wcusage_migration_detect_sources( true ) as $id => $detected ) {
			$rows[] = array(
				'source' => $id,
				'name'   => wcusage_migration_sources()[ $id ]['name'],
				'active' => $detected['active'] ? 'yes' : 'no',
				'data'   => $detected['data'] ? 'yes' : 'no',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'name', 'active', 'data' ) );

		$runs = WCUsage_Migration_Runner::get_runs();
		if ( $runs ) {
			WP_CLI::log( '' );
			$items = array();
			foreach ( $runs as $run ) {
				$items[] = array(
					'run_id'  => $run['run_id'],
					'source'  => $run['source'],
					'status'  => $run['status'],
					'started' => wp_date( 'Y-m-d H:i', (int) $run['started'] ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $items, array( 'run_id', 'source', 'status', 'started' ) );
		}
	}

	/**
	 * Show what would be migrated from a plugin, and the options available.
	 *
	 * <source>
	 * : Source ID (see "list").
	 */
	public function preview( $args ) {
		$source = $this->source( $args[0] );
		WP_CLI::log( $source->get_name() );
		foreach ( $source->get_counts() as $key => $value ) {
			WP_CLI::log( sprintf( '  %-12s %s', $key, $value ) );
		}
		foreach ( $source->get_warnings() as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Options (pass as --<option>=<value>):' );
		foreach ( WCUsage_Migration_Options::schema( $source ) as $key => $option ) {
			WP_CLI::log( sprintf( '  --%-24s %s [default: %s]', $key, $option['label'], is_scalar( $option['default'] ) ? $option['default'] : '' ) );
		}
	}

	/**
	 * Run a migration.
	 *
	 * <source>
	 * : Source ID (see "list").
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * [--<field>=<value>]
	 * : Any option shown by "preview".
	 */
	public function run( $args, $assoc_args ) {
		$source = $this->source( $args[0] );
		// Before the confirmation, so a mistyped option can still be fixed.
		$input = self::input( $source, array_diff_key( $assoc_args, array( 'yes' => 1 ) ) );
		WP_CLI::confirm( sprintf( 'Migrate from %s now? Make sure you have a database backup.', $source->get_name() ), $assoc_args );

		$state = WCUsage_Migration_Runner::start( $args[0], $input );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}

		$this->drive( $state );
	}

	/**
	 * The options to run with, from the command's --<option>=<value> flags,
	 * warning about any the source does not have and select values it does not
	 * offer (both would be ignored). Static, so WP-CLI does not list it as a
	 * subcommand.
	 *
	 * @param WCUsage_Migration_Source $source
	 * @param array                    $assoc_args Without --yes.
	 * @return array|null Null for the defaults.
	 */
	public static function input( $source, $assoc_args ) {
		if ( ! $assoc_args ) {
			return null;
		}
		$schema = WCUsage_Migration_Options::schema( $source );
		$input  = WCUsage_Migration_Options::defaults( $source );
		foreach ( $assoc_args as $key => $value ) {
			$key = str_replace( '-', '_', $key );
			if ( ! isset( $schema[ $key ] ) ) {
				WP_CLI::warning( sprintf( 'Unknown option --%s for %s: ignored. Run "wp wcusage-migrate preview %s" for the options.', $key, $source->get_name(), $source->get_id() ) );
				continue;
			}
			if ( 'select' === $schema[ $key ]['type'] && ! isset( $schema[ $key ]['choices'][ (string) $value ] ) ) {
				WP_CLI::warning( sprintf( 'Invalid value "%s" for --%s: using "%s". Choose from: %s.', $value, $key, $schema[ $key ]['default'], implode( ', ', array_keys( $schema[ $key ]['choices'] ) ) ) );
			}
			$input[ $key ] = $value;
		}
		return $input;
	}

	/**
	 * Carry on with a run (or an undo) that was interrupted.
	 */
	public function resume() {
		$state = WCUsage_Migration_Runner::get_state();
		if ( 'undoing' === ( $state['status'] ?? '' ) ) {
			$this->drive_undo( $state );
			return;
		}
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			WP_CLI::error( 'No migration is in progress.' );
		}
		$this->drive( $state );
	}

	/**
	 * Undo a migration run.
	 *
	 * <run-id>
	 * : Run ID (see "list").
	 *
	 * [--delete-users]
	 * : Also delete user accounts the run created (on multisite, only removes them from this site).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function undo( $args, $assoc_args ) {
		WP_CLI::confirm( sprintf( 'Undo migration %s?', $args[0] ), $assoc_args );
		$state = WCUsage_Migration_Runner::start_undo( $args[0], ! empty( $assoc_args['delete-users'] ) );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}
		$this->drive_undo( $state );
	}

	/**
	 * Undo batches until the undo finishes.
	 *
	 * @param array $state
	 */
	protected function drive_undo( $state ) {
		while ( 'undoing' === $state['status'] ) {
			$before = (int) ( $state['undone'] ?? 0 );
			$state  = WCUsage_Migration_Runner::run_undo( 60 );
			// The admin screen (or another process) has the lock: wait for it.
			if ( 'undoing' === $state['status'] && (int) ( $state['undone'] ?? 0 ) === $before && WCUsage_Migration_Runner::is_locked() ) {
				sleep( 2 );
				continue;
			}
			WP_CLI::log( sprintf( 'Undone %d of %d changes', (int) $state['undone'], (int) $state['total'] ) );
		}
		if ( 'undone' === $state['status'] ) {
			WP_CLI::success( 'Migration undone.' );
		} else {
			WP_CLI::error( 'Undo stopped: ' . wp_json_encode( $state['messages'] ?? array() ) );
		}
	}

	/**
	 * @param string $id
	 * @return WCUsage_Migration_Source
	 */
	protected function source( $id ) {
		$source = WCUsage_Migration_Runner::get_source( $id );
		if ( ! $source ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Run "wp wcusage-migrate list".', $id ) );
		}
		return $source;
	}

	/**
	 * Run batches until the migration finishes, reporting progress.
	 *
	 * @param array $state
	 */
	protected function drive( $state ) {
		$labels = WCUsage_Migration_Runner::step_labels();
		$seen   = 0;
		$last   = null;
		while ( 'running' === $state['status'] ) {
			$before = (int) ( $state['updated'] ?? 0 ) . ':' . (int) $state['step'] . ':' . ( $state['cursor'] ?? '' );
			$runner = new WCUsage_Migration_Runner( $state );
			$state  = $runner->run( 60 );
			// The admin screen (or another process) has the lock: wait, then carry on
			// from the state as it saved it.
			if ( 'running' === $state['status'] && $before === (int) ( $state['updated'] ?? 0 ) . ':' . (int) $state['step'] . ':' . ( $state['cursor'] ?? '' ) && WCUsage_Migration_Runner::is_locked() ) {
				sleep( 2 );
				$state = WCUsage_Migration_Runner::get_state();
				continue;
			}
			$step   = $state['steps'][ min( $state['step'], count( $state['steps'] ) - 1 ) ];
			WP_CLI::log( sprintf( '[%d/%d] %s', min( $state['step'] + 1, count( $state['steps'] ) ), count( $state['steps'] ), isset( $labels[ $step ] ) ? $labels[ $step ] : $step ) );
			$messages = (array) ( $state['messages'] ?? array() );
			foreach ( self::new_messages( $messages, $seen, $last ) as $message ) {
				'info' === $message['level'] ? WP_CLI::log( '  ' . $message['message'] ) : WP_CLI::warning( $message['message'] );
			}
			$seen = count( $messages );
			$last = $seen ? end( $messages ) : null;
		}
		foreach ( (array) $state['counts'] as $key => $value ) {
			WP_CLI::log( sprintf( '  %-40s %s', $key, $value ) );
		}
		if ( 'completed' === $state['status'] ) {
			WP_CLI::success( sprintf( 'Migration %s complete.', $state['run_id'] ) );
		} else {
			WP_CLI::error( sprintf( 'Migration %s.', $state['status'] ) );
		}
	}

	/**
	 * The log messages added since the last ones printed.
	 *
	 * The runner keeps only the newest 500, dropping older ones from the front,
	 * so the last printed message is found by its content, at or before where
	 * it was, rather than by its position.
	 *
	 * @param array[]    $messages The run's messages now.
	 * @param int        $seen     How many there were when last printed.
	 * @param array|null $last     The last message printed (null for none).
	 * @return array[]
	 */
	public static function new_messages( $messages, $seen, $last ) {
		$messages = array_values( (array) $messages );
		if ( ! $last ) {
			return $messages;
		}
		$same = function ( $a, $b ) {
			return ( $a['time'] ?? '' ) === ( $b['time'] ?? '' ) && ( $a['level'] ?? '' ) === ( $b['level'] ?? '' ) && ( $a['message'] ?? '' ) === ( $b['message'] ?? '' );
		};
		for ( $i = min( (int) $seen, count( $messages ) ) - 1; $i >= 0; $i-- ) {
			if ( $same( $messages[ $i ], $last ) ) {
				return array_slice( $messages, $i + 1 );
			}
		}
		// Dropped already (over 500 new ones since): all that are left are new.
		return $messages;
	}
}

WP_CLI::add_command( 'wcusage-migrate', 'WCUsage_Migration_CLI' );
