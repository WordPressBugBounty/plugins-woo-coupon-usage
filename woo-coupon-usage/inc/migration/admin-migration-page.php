<?php
/**
 * The Migration admin screen and its AJAX endpoints.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may run migrations: they rewrite affiliates, balances and settings
 * site-wide, so it takes the settings capability, not just the plugin's
 * admin-screen permission.
 *
 * @return bool
 */
function wcusage_migration_user_can() {
	return current_user_can( 'manage_options' );
}

/**
 * Register the screen: in the Coupon Affiliates menu while there is
 * something to migrate (see wcusage_migration_show_in_menu()), otherwise
 * hidden but still reachable from Admin Tools.
 */
function wcusage_migration_admin_menu() {
	global $submenu;

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$in_menu = wcusage_migration_show_in_menu();

	// Just above "Admin Tools".
	$position = null;
	if ( $in_menu && ! empty( $submenu['wcusage'] ) ) {
		foreach ( array_values( $submenu['wcusage'] ) as $index => $item ) {
			if ( isset( $item[2] ) && 'wcusage_tools' === $item[2] ) {
				$position = $index;
				break;
			}
		}
	}

	add_submenu_page(
		$in_menu ? 'wcusage' : 'wcusage_tools',
		__( 'Coupon Affiliates: Migration', 'woo-coupon-usage' ),
		__( 'Migration', 'woo-coupon-usage' ),
		'manage_options',
		'wcusage_migration',
		'wcusage_migration_page',
		$position
	);
}
add_action( 'admin_menu', 'wcusage_migration_admin_menu', 20 );

/**
 * Scripts and styles for the screen.
 *
 * @param string $hook
 */
function wcusage_migration_enqueue( $hook ) {
	if ( empty( $_GET['page'] ) || 'wcusage_migration' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$base = WCUSAGE_UNIQUE_PLUGIN_URL;
	$dir  = dirname( __DIR__, 2 ) . '/';

	wp_enqueue_style( 'wcusage-admin-migration', $base . 'css/admin-migration.css', array(), file_exists( $dir . 'css/admin-migration.css' ) ? filemtime( $dir . 'css/admin-migration.css' ) : WCUSAGE_VERSION );
	wp_enqueue_script( 'wcusage-admin-migration', $base . 'js/admin-migration.js', array( 'jquery' ), file_exists( $dir . 'js/admin-migration.js' ) ? filemtime( $dir . 'js/admin-migration.js' ) : WCUSAGE_VERSION, true );
	$busy = WCUsage_Migration_Runner::is_busy();
	wp_localize_script(
		'wcusage-admin-migration',
		'wcusageMigration',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wcusage_migration' ),
			'busy'     => $busy ? 1 : 0,
			// Shown straight away on a reload mid-run, rather than an empty panel
			// until the next batch returns.
			'progress' => $busy ? wcusage_migration_progress_payload( WCUsage_Migration_Runner::get_state() ) : null,
			'i18n'     => array(
				'loading'      => __( 'Checking the data...', 'woo-coupon-usage' ),
				'starting'     => __( 'Starting the migration...', 'woo-coupon-usage' ),
				'confirm'      => __( 'Start the migration now? Make sure you have a recent backup of your database.', 'woo-coupon-usage' ),
				'backup'       => __( 'Please confirm you have a backup of your database first.', 'woo-coupon-usage' ),
				'staging'      => __( 'Please confirm you have tested the migration on a staging site, or are happy to continue without testing it.', 'woo-coupon-usage' ),
				'cancel'       => __( 'Stop the migration? What has been imported so far stays, and can be undone.', 'woo-coupon-usage' ),
				'undo'         => __( 'Undo this migration? Everything it imported or changed is removed or put back.', 'woo-coupon-usage' ),
				'error'        => __( 'Something went wrong. The migration will try again in a few seconds.', 'woo-coupon-usage' ),
				'failed'       => __( 'The request failed. Check your connection and reload the page to carry on: nothing is lost.', 'woo-coupon-usage' ),
				'done'         => __( 'Migration complete', 'woo-coupon-usage' ),
				'undone'       => __( 'Migration undone', 'woo-coupon-usage' ),
				'undoing'      => __( 'Undoing the migration', 'woo-coupon-usage' ),
				'migrating'    => __( 'Migrating', 'woo-coupon-usage' ),
				'stopped'      => __( 'Migration stopped', 'woo-coupon-usage' ),
				'stoppedError' => __( 'Migration stopped with an error', 'woo-coupon-usage' ),
				'undoError'    => __( 'Undo stopped with an error', 'woo-coupon-usage' ),
				'stop'         => __( 'Stop', 'woo-coupon-usage' ),
				'close'        => __( 'Done', 'woo-coupon-usage' ),
				'log'          => __( 'Log', 'woo-coupon-usage' ),
				'checkTitle'   => __( 'Now check the results', 'woo-coupon-usage' ),
				'checkIntro'   => __( 'Every store is different, so please check these yourself before relying on the migrated data:', 'woo-coupon-usage' ),
			),
		)
	);

	if ( function_exists( 'wcusage_enqueue_font_awesome' ) ) {
		wcusage_enqueue_font_awesome();
	}
}
add_action( 'admin_enqueue_scripts', 'wcusage_migration_enqueue' );

/**
 * Labels for the counters a run keeps.
 *
 * @return array
 */
function wcusage_migration_count_labels() {
	return array(
		'affiliates_imported'                    => __( 'Affiliates imported', 'woo-coupon-usage' ),
		'users_created'                          => __( 'User accounts created', 'woo-coupon-usage' ),
		'coupons_created'                        => __( 'Coupons created', 'woo-coupon-usage' ),
		'coupons_created_from_codes'             => __( 'Coupons created from the old plugin\'s own coupon codes', 'woo-coupon-usage' ),
		'coupons_assigned'                       => __( 'Existing coupons assigned', 'woo-coupon-usage' ),
		'coupons_kept'                           => __( 'Existing affiliates who kept their coupons', 'woo-coupon-usage' ),
		'coupons_conflicts'                      => __( 'Coupons owned by someone else (left alone)', 'woo-coupon-usage' ),
		'affiliates_suspended'                   => __( 'Affiliates imported as suspended', 'woo-coupon-usage' ),
		'registrations_pending'                  => __( 'Pending applications', 'woo-coupon-usage' ),
		'registrations_declined'                 => __( 'Rejected applications', 'woo-coupon-usage' ),
		'affiliates_already_imported'            => __( 'Affiliates already imported before', 'woo-coupon-usage' ),
		'affiliates_skipped_inactive'            => __( 'Inactive affiliates skipped', 'woo-coupon-usage' ),
		'affiliates_skipped_pending'             => __( 'Pending affiliates skipped', 'woo-coupon-usage' ),
		'affiliates_skipped_rejected'            => __( 'Rejected affiliates skipped', 'woo-coupon-usage' ),
		'affiliates_skipped_no_coupon'           => __( 'Affiliates skipped (no coupon)', 'woo-coupon-usage' ),
		'affiliates_skipped_no_user'             => __( 'Affiliates skipped (no user account)', 'woo-coupon-usage' ),
		'affiliates_failed'                      => __( 'Affiliates that could not be imported', 'woo-coupon-usage' ),
		'affiliates_without_coupon'              => __( 'Affiliates left without a coupon', 'woo-coupon-usage' ),
		'rates_applied'                          => __( 'Custom rates applied', 'woo-coupon-usage' ),
		'groups'                                 => __( 'Groups created', 'woo-coupon-usage' ),
		'groups_skipped_own_rate'                => __( 'Affiliates with a rate of their own left out of a fixed-per-order group', 'woo-coupon-usage' ),
		'product_rates'                          => __( 'Product / category rates', 'woo-coupon-usage' ),
		'referrals_imported'                     => __( 'Referred orders imported', 'woo-coupon-usage' ),
		'referrals_skipped_rejected'             => __( 'Rejected referrals skipped', 'woo-coupon-usage' ),
		'referrals_skipped_already_imported'     => __( 'Commission parts already imported by an earlier migration', 'woo-coupon-usage' ),
		'referrals_skipped_failed_order'         => __( 'Referrals for cancelled / refunded orders skipped', 'woo-coupon-usage' ),
		'referrals_skipped_no_affiliate'         => __( 'Referrals of affiliates not imported', 'woo-coupon-usage' ),
		'referrals_conflicts'                    => __( 'Orders already credited to another affiliate', 'woo-coupon-usage' ),
		'referrals_already_in_coupon_affiliates' => __( 'Orders already in Coupon Affiliates', 'woo-coupon-usage' ),
		'commission_granted'                     => __( 'Unpaid commission on completed orders', 'woo-coupon-usage' ),
		'commission_pending'                     => __( 'Pending commission (orders not yet completed)', 'woo-coupon-usage' ),
		'commission_paid'                        => __( 'Commission already paid out', 'woo-coupon-usage' ),
		'commission_paid_since'                  => __( 'Commission paid out since an earlier migration (taken off balances)', 'woo-coupon-usage' ),
		'non_order_commission_unpaid'            => __( 'Unpaid commission with no order (added to balances)', 'woo-coupon-usage' ),
		'non_order_commission_paid'              => __( 'Paid commission with no order', 'woo-coupon-usage' ),
		'tier_commission_unpaid'                 => __( 'Unpaid multi-level commission (added to balances)', 'woo-coupon-usage' ),
		'tier_commission_paid'                   => __( 'Paid multi-level commission', 'woo-coupon-usage' ),
		'payouts_imported'                       => __( 'Payouts imported', 'woo-coupon-usage' ),
		'payouts_paid'                           => __( 'Paid out (imported payouts)', 'woo-coupon-usage' ),
		'payouts_pending'                        => __( 'Open payout requests', 'woo-coupon-usage' ),
		'balancing_payouts'                      => __( 'Historical payouts recorded for paid commission', 'woo-coupon-usage' ),
		'balancing_payouts_amount'               => __( 'Amount of those historical payouts', 'woo-coupon-usage' ),
		'creatives'                              => __( 'Creatives imported', 'woo-coupon-usage' ),
		'creatives_skipped_text'                 => __( 'Text-only creatives skipped', 'woo-coupon-usage' ),
		'visits'                                 => __( 'Visits imported', 'woo-coupon-usage' ),
		'campaigns'                              => __( 'Campaigns created', 'woo-coupon-usage' ),
		'store_credit'                           => __( 'Store credit imported', 'woo-coupon-usage' ),
		'settings_applied'                       => __( 'Settings changed', 'woo-coupon-usage' ),
		'legacy_links'                           => __( 'Old referral links kept working', 'woo-coupon-usage' ),
		'referrals_read'                         => __( 'Referrals read', 'woo-coupon-usage' ),
		'referrals_skipped_no_order'             => __( 'Referrals with no order, not carried (pending, or balances not migrated)', 'woo-coupon-usage' ),
		'referrals_skipped_order_missing'        => __( 'Referrals whose order no longer exists, not carried', 'woo-coupon-usage' ),
		'referrals_skipped_before_date'          => __( 'Referrals before the chosen date, not carried', 'woo-coupon-usage' ),
		'referrals_skipped_second_referral'      => __( 'Side commissions on another affiliate\'s order, not carried', 'woo-coupon-usage' ),
		'referrals_skipped_order_credited_elsewhere' => __( 'Referrals on orders credited elsewhere, not carried', 'woo-coupon-usage' ),
		'referrals_skipped_tier'                 => __( 'Multi-level commissions skipped', 'woo-coupon-usage' ),
		'referrals_skipped_unapproved'           => __( 'Unapproved commissions on completed orders, skipped', 'woo-coupon-usage' ),
		'referrals_skipped_history_off'          => __( 'Referrals not carried (referral history off, nothing owed)', 'woo-coupon-usage' ),
		'referral_parts_merged'                  => __( 'Extra commission records added to their order', 'woo-coupon-usage' ),
		'released_orders'                        => __( 'Orders whose earlier (unowned) coupon commission was removed', 'woo-coupon-usage' ),
		'released_amount'                        => __( 'Earlier coupon commission removed', 'woo-coupon-usage' ),
		'payouts_skipped'                        => __( 'Payouts skipped (not paid in the old plugin)', 'woo-coupon-usage' ),
		'payouts_skipped_no_affiliate'           => __( 'Payouts of affiliates not imported', 'woo-coupon-usage' ),
		'visits_skipped'                         => __( 'Visits of affiliates not imported', 'woo-coupon-usage' ),
		'warnings'                               => __( 'Warnings', 'woo-coupon-usage' ),
		'users_deleted'                          => __( 'User accounts deleted', 'woo-coupon-usage' ),
		'users_kept_with_coupons'                => __( 'User accounts kept (they still have a coupon)', 'woo-coupon-usage' ),
		'undo_kept_changed'                      => __( 'Values changed since the migration (kept)', 'woo-coupon-usage' ),
		'undo_kept_coupons'                      => __( 'Coupons kept (commission or payouts since the migration)', 'woo-coupon-usage' ),
		'undo_kept_orders'                       => __( 'Orders whose commission changed since the migration (kept as they are)', 'woo-coupon-usage' ),
	);
}

/**
 * What to check after a migration, for the parts this run migrated: shown when
 * it completes, so anything that did not come over as expected is caught
 * while it can still be undone cleanly.
 *
 * @param array $state Run state.
 * @return array[] text, and optionally url + link (the screen to check it on).
 */
function wcusage_migration_checklist( $state ) {
	$steps   = (array) ( $state['steps'] ?? array() );
	$sources = wcusage_migration_sources();
	$name    = isset( $state['source'], $sources[ $state['source'] ] ) ? $sources[ $state['source'] ]['name'] : __( 'the old plugin', 'woo-coupon-usage' );
	$has     = function ( $step ) use ( $steps ) {
		return in_array( $step, $steps, true );
	};
	$page    = function ( $slug ) {
		return admin_url( 'admin.php?page=' . $slug );
	};

	$items   = array();
	$items[] = array(
		/* translators: %s: plugin name */
		'text' => sprintf( __( 'Open a few affiliates and compare them with %s: their coupon, user role and payout details, and that their affiliate dashboard shows what you expect.', 'woo-coupon-usage' ), $name ),
		'url'  => $page( 'wcusage_affiliates' ),
		'link' => __( 'Affiliates', 'woo-coupon-usage' ),
	);
	if ( $has( 'referrals' ) ) {
		$items[] = array(
			/* translators: %s: plugin name */
			'text' => sprintf( __( 'Compare those affiliates\' unpaid and pending commission, and a few of their referred orders, with %s.', 'woo-coupon-usage' ), $name ),
			'url'  => $page( 'wcusage_referrals' ),
			'link' => __( 'Referrals', 'woo-coupon-usage' ),
		);
	}
	// The Payouts and Registrations screens only exist while their features
	// are on: otherwise point to the setting that turns them on.
	if ( $has( 'payouts' ) ) {
		$items[] = wcusage_get_setting_value( 'wcusage_field_tracking_enable', 1 ) ? array(
			'text' => __( 'Check the payout history and totals look right.', 'woo-coupon-usage' ),
			'url'  => $page( 'wcusage_payouts' ),
			'link' => __( 'Payouts', 'woo-coupon-usage' ),
		) : array(
			'text' => __( 'Payouts were imported, but the payouts features are turned off: turn them on (Settings, Payouts tab) to see the payout history, then check it looks right.', 'woo-coupon-usage' ),
			'url'  => $page( 'wcusage_settings' ),
			'link' => __( 'Settings', 'woo-coupon-usage' ),
		);
	}
	if ( ! empty( $state['counts']['registrations_pending'] ) || ! empty( $state['counts']['registrations_declined'] ) ) {
		$items[] = wcusage_get_setting_value( 'wcusage_field_registration_enable', '1' ) ? array(
			'text' => __( 'Check the imported applications, and accept or decline the pending ones.', 'woo-coupon-usage' ),
			'url'  => $page( 'wcusage_registrations' ),
			'link' => __( 'Registrations', 'woo-coupon-usage' ),
		) : array(
			'text' => __( 'Affiliate applications were imported, but affiliate registrations are turned off: turn them on (Settings, Registration tab) to see them, then accept or decline the pending ones.', 'woo-coupon-usage' ),
			'url'  => $page( 'wcusage_settings' ),
			'link' => __( 'Settings', 'woo-coupon-usage' ),
		);
	}
	if ( $has( 'settings' ) ) {
		$items[] = array(
			'text' => __( 'Review the commission rate, cookie length and click attribution settings.', 'woo-coupon-usage' ),
			'url'  => $page( 'wcusage_settings' ),
			'link' => __( 'Settings', 'woo-coupon-usage' ),
		);
	}
	if ( ! empty( $state['counts']['legacy_links'] ) ) {
		$items[] = array(
			/* translators: %s: plugin name */
			'text' => sprintf( __( 'Visit one of your affiliates\' old %s referral links and check it credits their coupon.', 'woo-coupon-usage' ), $name ),
		);
	}
	$items[] = array(
		'text' => __( 'Place a test order with an affiliate\'s coupon (or through their referral link) and check the commission is recorded for them.', 'woo-coupon-usage' ),
	);
	$items[] = array(
		'text' => __( 'Download the report under "Previous migrations" to review every affiliate that was imported.', 'woo-coupon-usage' ),
	);
	$items[] = array(
		/* translators: %s: plugin name */
		'text' => sprintf( __( 'Once you are happy, deactivate %s so new sales are not tracked in both plugins - but keep it installed until you have checked everything.', 'woo-coupon-usage' ), $name ),
	);
	$items[] = array(
		'text' => __( 'If something does not look right, use "Undo" under "Previous migrations" - it works best straight after migrating, before new orders come in.', 'woo-coupon-usage' ),
	);

	return $items;
}

/**
 * An amount as plain text in the store's currency ("£12.50"): wc_price()'s
 * markup and HTML entities removed, so it can be escaped like any text.
 *
 * @param float $amount
 * @return string
 */
function wcusage_migration_plain_price( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Counters whose value is money.
 *
 * @return string[]
 */
function wcusage_migration_money_counts() {
	return array( 'commission_granted', 'commission_pending', 'commission_paid', 'commission_paid_since', 'non_order_commission_unpaid', 'non_order_commission_paid', 'tier_commission_unpaid', 'tier_commission_paid', 'payouts_paid', 'payouts_pending', 'balancing_payouts_amount', 'store_credit', 'released_amount' );
}

/**
 * The progress of a run (or undo), for the screen.
 *
 * @param array $state
 * @return array
 */
function wcusage_migration_progress_payload( $state ) {

	$labels = WCUsage_Migration_Runner::step_labels();
	$steps  = array();
	$total  = isset( $state['steps'] ) ? count( $state['steps'] ) : 0;

	foreach ( (array) ( $state['steps'] ?? array() ) as $i => $key ) {
		$steps[] = array(
			'key'   => $key,
			'label' => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
			'state' => $i < (int) ( $state['step'] ?? 0 ) || 'completed' === ( $state['status'] ?? '' ) ? 'done' : ( $i === (int) ( $state['step'] ?? 0 ) ? 'current' : 'todo' ),
		);
	}

	if ( isset( $state['undone'] ) ) {
		$percent = ! empty( $state['total'] ) ? min( 100, round( 100 * (int) $state['undone'] / (int) $state['total'] ) ) : 100;
	} else {
		$percent = $total ? round( 100 * min( (int) ( $state['step'] ?? 0 ), $total ) / $total ) : 0;
	}
	if ( in_array( $state['status'] ?? '', array( 'completed', 'undone' ), true ) ) {
		$percent = 100;
	}

	$count_labels = wcusage_migration_count_labels();
	$money        = wcusage_migration_money_counts();
	$counts       = array();
	foreach ( (array) ( $state['counts'] ?? array() ) as $key => $value ) {
		if ( ! $value ) {
			continue;
		}
		$counts[] = array(
			'key'   => $key,
			'label' => isset( $count_labels[ $key ] ) ? $count_labels[ $key ] : ucfirst( str_replace( '_', ' ', $key ) ),
			'value' => in_array( $key, $money, true ) ? wcusage_migration_plain_price( $value ) : number_format_i18n( $value ),
		);
	}

	$sources = wcusage_migration_sources();

	return array(
		'status'    => $state['status'] ?? '',
		// Only an undo's state counts what it has undone: tells a failed undo
		// from a failed migration (both have the status "failed").
		'undo'      => isset( $state['undone'] ),
		'run_id'    => $state['run_id'] ?? '',
		'source'    => isset( $state['source'], $sources[ $state['source'] ] ) ? $sources[ $state['source'] ]['name'] : '',
		'steps'     => $steps,
		'percent'   => $percent,
		'counts'    => $counts,
		'messages'  => array_slice( array_reverse( (array) ( $state['messages'] ?? array() ) ), 0, 100 ),
		'checklist' => 'completed' === ( $state['status'] ?? '' ) ? wcusage_migration_checklist( $state ) : array(),
	);
}

/**
 * Check an AJAX request may run a migration.
 */
function wcusage_migration_ajax_check() {
	check_ajax_referer( 'wcusage_migration', 'nonce' );
	if ( ! wcusage_migration_user_can() ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to run migrations.', 'woo-coupon-usage' ) ), 403 );
	}
}

/**
 * AJAX: the preview and options form for a source.
 */
function wcusage_migration_ajax_preview() {
	wcusage_migration_ajax_check();

	$source_id = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	$source    = WCUsage_Migration_Runner::get_source( $source_id );
	if ( ! $source ) {
		wp_send_json_error( array( 'message' => __( 'Unknown source plugin.', 'woo-coupon-usage' ) ) );
	}
	if ( ! wcusage_migration_source_has_data( $source_id ) ) {
		/* translators: %s: plugin name */
		wp_send_json_error( array( 'message' => sprintf( __( 'No %s data was found in the database.', 'woo-coupon-usage' ), $source->get_name() ) ) );
	}

	ob_start();
	wcusage_migration_render_setup( $source );
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
}
add_action( 'wp_ajax_wcusage_migration_preview', 'wcusage_migration_ajax_preview' );

/**
 * AJAX: start a run.
 */
function wcusage_migration_ajax_start() {
	wcusage_migration_ajax_check();

	$source_id = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	$input     = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised against the schema.

	// Creating user accounts takes its own capability, which a site admin on
	// multisite does not have.
	$source = WCUsage_Migration_Runner::get_source( $source_id );
	if ( $source && ! current_user_can( 'create_users' ) ) {
		$options = WCUsage_Migration_Options::sanitize( $source, $input );
		if ( 'create' === ( $options['missing_users'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Your account cannot create user accounts, so affiliates with no WordPress user account cannot be given one: choose to skip them, or ask someone who can create users (on a multisite network, a network administrator) to run the migration.', 'woo-coupon-usage' ) ) );
		}
	}

	$state = WCUsage_Migration_Runner::start( $source_id, $input );
	if ( is_wp_error( $state ) ) {
		wp_send_json_error( array( 'message' => $state->get_error_message() ) );
	}

	wp_send_json_success( wcusage_migration_progress_payload( $state ) );
}
add_action( 'wp_ajax_wcusage_migration_start', 'wcusage_migration_ajax_start' );

/**
 * AJAX: run the next batches of the run (or undo) in progress.
 */
function wcusage_migration_ajax_step() {
	wcusage_migration_ajax_check();

	$state = WCUsage_Migration_Runner::get_state();

	if ( 'running' === ( $state['status'] ?? '' ) ) {
		$runner = new WCUsage_Migration_Runner( $state );
		$state  = $runner->run( (float) apply_filters( 'wcusage_migration_time_budget', 15 ) );
	} elseif ( 'undoing' === ( $state['status'] ?? '' ) ) {
		$state = WCUsage_Migration_Runner::run_undo( (float) apply_filters( 'wcusage_migration_time_budget', 15 ) );
	}

	wp_send_json_success( wcusage_migration_progress_payload( $state ) );
}
add_action( 'wp_ajax_wcusage_migration_step', 'wcusage_migration_ajax_step' );

/**
 * AJAX: stop the run in progress.
 */
function wcusage_migration_ajax_cancel() {
	wcusage_migration_ajax_check();
	WCUsage_Migration_Runner::cancel();
	wp_send_json_success( wcusage_migration_progress_payload( WCUsage_Migration_Runner::get_state() ) );
}
add_action( 'wp_ajax_wcusage_migration_cancel', 'wcusage_migration_ajax_cancel' );

/**
 * AJAX: start undoing a run.
 */
function wcusage_migration_ajax_undo() {
	wcusage_migration_ajax_check();

	$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
	// Deleting user accounts takes its own capability (not a site admin's on multisite).
	$state  = WCUsage_Migration_Runner::start_undo( $run_id, ! empty( $_POST['delete_users'] ) && current_user_can( 'delete_users' ) );
	if ( is_wp_error( $state ) ) {
		wp_send_json_error( array( 'message' => $state->get_error_message() ) );
	}

	wp_send_json_success( wcusage_migration_progress_payload( $state ) );
}
add_action( 'wp_ajax_wcusage_migration_undo', 'wcusage_migration_ajax_undo' );

/**
 * What a run imported, one row per affiliate (for the CSV report): the
 * affiliates it imported, and earlier-imported ones it brought referrals or
 * payouts over for.
 *
 * @param string $run_id
 * @return array[] Rows, the first being the headings. Empty for an unknown run.
 */
function wcusage_migration_report_rows( $run_id ) {

	$runs = WCUsage_Migration_Runner::get_runs();
	if ( empty( $runs[ $run_id ] ) ) {
		return array();
	}

	// Totals per coupon from the run's referrals and payouts.
	global $wpdb;
	$table  = WCUsage_Migration_Journal::table();
	$totals = array();
	$after  = 0;
	while ( true ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, entity, target_id, data FROM {$table} WHERE run_id = %s AND entity IN ('referral','payout','non_order') AND id > %d ORDER BY id ASC LIMIT 2000", $run_id, $after ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			break;
		}
		foreach ( $rows as $row ) {
			$after     = (int) $row['id'];
			$data      = $row['data'] ? json_decode( $row['data'], true ) : array();
			$coupon_id = 'non_order' === $row['entity'] ? (int) $row['target_id'] : (int) ( $data['coupon_id'] ?? 0 );
			if ( ! isset( $totals[ $coupon_id ] ) ) {
				$totals[ $coupon_id ] = array_fill_keys( array( 'orders', 'unpaid', 'pending', 'paid', 'payouts', 'other' ), 0 );
			}
			if ( 'referral' === $row['entity'] ) {
				$totals[ $coupon_id ]['orders']++;
				$totals[ $coupon_id ]['unpaid']  += (float) ( $data['unpaid'] ?? 0 );
				$totals[ $coupon_id ]['pending'] += (float) ( $data['pending_order'] ?? 0 );
				$totals[ $coupon_id ]['paid']    += 'paid' === ( $data['state'] ?? '' ) ? (float) ( $data['amount'] ?? 0 ) : 0;
			} elseif ( 'payout' === $row['entity'] ) {
				$totals[ $coupon_id ]['payouts'] += (float) ( $data['amount'] ?? 0 );
			} else {
				$totals[ $coupon_id ]['other'] += (float) ( $data['amount'] ?? 0 );
			}
		}
	}

	// The affiliates this run imported - and those an earlier run of the same
	// plugin imported, whose new referrals or payouts this run brought over (a
	// re-run does not import an affiliate again).
	$journal    = new WCUsage_Migration_Journal( $run_id, $runs[ $run_id ]['source'] );
	$affiliates = array();
	foreach ( $journal->all( 'affiliate' ) as $row ) {
		if ( $row['run_id'] === $run_id ) {
			$affiliates[ $row['source_id'] ] = $row;
			continue;
		}
		if ( isset( $affiliates[ $row['source_id'] ] ) ) {
			continue;
		}
		foreach ( (array) ( $row['data']['coupons'] ?? array() ) as $coupon_id ) {
			if ( (int) $coupon_id && isset( $totals[ (int) $coupon_id ] ) ) {
				$affiliates[ $row['source_id'] ] = $row;
				break;
			}
		}
	}

	$rows   = array();
	$rows[] = array(
		__( 'Source affiliate ID', 'woo-coupon-usage' ),
		__( 'User ID', 'woo-coupon-usage' ),
		__( 'Username', 'woo-coupon-usage' ),
		__( 'Email', 'woo-coupon-usage' ),
		__( 'Status', 'woo-coupon-usage' ),
		__( 'User created', 'woo-coupon-usage' ),
		__( 'Coupons', 'woo-coupon-usage' ),
		__( 'Referred orders', 'woo-coupon-usage' ),
		__( 'Unpaid commission', 'woo-coupon-usage' ),
		__( 'Pending commission', 'woo-coupon-usage' ),
		__( 'Paid commission', 'woo-coupon-usage' ),
		__( 'Payouts', 'woo-coupon-usage' ),
		__( 'Other commission (no order)', 'woo-coupon-usage' ),
		__( 'Imported by migration', 'woo-coupon-usage' ),
	);
	foreach ( $affiliates as $source_id => $row ) {
		$user    = get_userdata( (int) $row['target_id'] );
		$codes   = array();
		$sum     = array_fill_keys( array( 'orders', 'unpaid', 'pending', 'paid', 'payouts', 'other' ), 0 );
		foreach ( (array) ( $row['data']['coupons'] ?? array() ) as $coupon_id ) {
			// The raw code: get_the_title() would texturize it ("2x1" to "2&#215;1").
			$codes[] = (string) get_post_field( 'post_title', (int) $coupon_id, 'raw' );
			if ( isset( $totals[ (int) $coupon_id ] ) ) {
				foreach ( $sum as $key => $value ) {
					$sum[ $key ] += $totals[ (int) $coupon_id ][ $key ];
				}
			}
		}
		$rows[] = array(
			$source_id,
			(int) $row['target_id'],
			$user ? $user->user_login : '',
			$user ? $user->user_email : '',
			$row['data']['status'] ?? '',
			! empty( $row['data']['created_user'] ) ? 'yes' : 'no',
			implode( ' ', $codes ),
			$sum['orders'],
			wc_format_decimal( $sum['unpaid'], 2 ),
			wc_format_decimal( $sum['pending'], 2 ),
			wc_format_decimal( $sum['paid'], 2 ),
			wc_format_decimal( $sum['payouts'], 2 ),
			wc_format_decimal( $sum['other'], 2 ),
			$row['run_id'],
		);
	}

	return $rows;
}

/**
 * Write rows as CSV.
 *
 * @param resource $handle
 * @param array[]  $rows
 */
function wcusage_migration_write_csv( $handle, $rows ) {
	// No escape character (PHP 7.4+): plain RFC 4180 quoting, and the default
	// one is deprecated in PHP 8.4.
	$escape = PHP_VERSION_ID >= 70400 ? '' : '\\';
	foreach ( $rows as $row ) {
		// Spreadsheet apps run a cell starting with = + - @ (or a tab or carriage
		// return before one) as a formula.
		$row = array_map(
			function ( $cell ) {
				return is_string( $cell ) && '' !== $cell && ! is_numeric( $cell ) && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $cell : $cell;
			},
			$row
		);
		fputcsv( $handle, $row, ',', '"', $escape );
	}
}

/**
 * Download a CSV of what a run imported, one row per affiliate.
 */
function wcusage_migration_download_report() {

	if ( ! wcusage_migration_user_can() ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'woo-coupon-usage' ) );
	}
	check_admin_referer( 'wcusage_migration_report' );

	$run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '';
	$rows   = wcusage_migration_report_rows( $run_id );
	if ( ! $rows ) {
		wp_die( esc_html__( 'Unknown migration run.', 'woo-coupon-usage' ) );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="coupon-affiliates-migration-' . sanitize_file_name( $run_id ) . '.csv"' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	wcusage_migration_write_csv( $out, $rows );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_wcusage_migration_report', 'wcusage_migration_download_report' );

/**
 * A run's status, for the history.
 *
 * @param string $status
 * @return string
 */
function wcusage_migration_status_label( $status ) {
	$labels = array(
		'running'   => __( 'Running', 'woo-coupon-usage' ),
		'completed' => __( 'Completed', 'woo-coupon-usage' ),
		'cancelled' => __( 'Cancelled', 'woo-coupon-usage' ),
		'failed'    => __( 'Failed', 'woo-coupon-usage' ),
		'undoing'   => __( 'Undoing', 'woo-coupon-usage' ),
		'undone'    => __( 'Undone', 'woo-coupon-usage' ),
	);
	return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
}

/**
 * The page.
 */
function wcusage_migration_page() {

	if ( ! wcusage_migration_user_can() ) {
		wp_die( esc_html__( 'You do not have permission to run migrations.', 'woo-coupon-usage' ) );
	}

	wcusage_migration_maybe_install();

	$detected = wcusage_migration_detect_sources( true );
	$sources  = wcusage_migration_sources();
	$state    = WCUsage_Migration_Runner::get_state();
	$runs     = WCUsage_Migration_Runner::get_runs();
	$busy     = WCUsage_Migration_Runner::is_busy();

	$found   = array();
	$missing = array();
	foreach ( $sources as $id => $source ) {
		if ( $detected[ $id ]['active'] || $detected[ $id ]['data'] ) {
			$found[ $id ] = $source;
		} else {
			$missing[ $id ] = $source;
		}
	}
	?>
	<div class="wrap wcusage-migration-wrap" style="margin: 0;">
	<div class="wrap wcusage-admin-page wcusage-migration-page">

		<?php do_action( 'wcusage_hook_dashboard_page_header', '' ); ?>

		<div class="wcu-page-header">
			<h1 class="wcusage-admin-title"><i class="fa-solid fa-right-left" aria-hidden="true"></i> <?php esc_html_e( 'Migrate from another affiliate plugin', 'woo-coupon-usage' ); ?> <span class="wcusage-migration-beta"><?php esc_html_e( 'Beta', 'woo-coupon-usage' ); ?></span></h1>
			<p class="wcu-page-subtitle"><?php esc_html_e( 'Import your affiliates, coupons, referral history, commission balances and more - straight from the other plugin\'s data, even after it has been deactivated.', 'woo-coupon-usage' ); ?></p>
		</div>

		<div class="wcusage-migration-body">

		<div id="wcusage-migration-progress" class="wcusage-migration-card" <?php echo $busy ? '' : 'hidden'; ?>>
			<?php // Announces the title and percentage as they change: the panel's content is redrawn after every batch, too much to read out each time. ?>
			<p id="wcusage-migration-progress-status" class="screen-reader-text" role="status" aria-live="polite"></p>
			<div id="wcusage-migration-progress-body"></div>
		</div>

		<div id="wcusage-migration-chooser" class="wcusage-migration-step">

			<h2 class="wcu-section-title"><span class="wcusage-migration-step-number">1</span> <?php esc_html_e( 'Choose the plugin to migrate from', 'woo-coupon-usage' ); ?></h2>

			<?php if ( ! $found ) : ?>
				<div class="wcusage-migration-card wcusage-migration-empty">
					<span class="wcusage-migration-empty-icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
					<p><?php esc_html_e( 'No other affiliate plugin, or its data, was found on this site.', 'woo-coupon-usage' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wcusage-migration-sources">
					<?php foreach ( $found as $id => $source ) : ?>
						<?php // Disabled while a run is in progress (the script turns them back on once it ends). ?>
						<button type="button" class="wcusage-migration-source" data-source="<?php echo esc_attr( $id ); ?>"<?php disabled( $busy ); ?>>
							<span class="wcusage-migration-source-icon"><i class="fa-solid fa-plug" aria-hidden="true"></i></span>
							<span class="wcusage-migration-source-content">
								<span class="wcusage-migration-source-name"><?php echo esc_html( $source['name'] ); ?></span>
								<span class="wcusage-migration-badges">
									<?php if ( $detected[ $id ]['active'] ) : ?>
										<span class="wcusage-migration-badge is-active"><?php esc_html_e( 'Active', 'woo-coupon-usage' ); ?></span>
									<?php else : ?>
										<span class="wcusage-migration-badge"><?php esc_html_e( 'Deactivated', 'woo-coupon-usage' ); ?></span>
									<?php endif; ?>
									<?php if ( $detected[ $id ]['data'] ) : ?>
										<span class="wcusage-migration-badge is-data"><?php esc_html_e( 'Data found', 'woo-coupon-usage' ); ?></span>
									<?php else : ?>
										<span class="wcusage-migration-badge is-warning"><?php esc_html_e( 'No data yet', 'woo-coupon-usage' ); ?></span>
									<?php endif; ?>
								</span>
							</span>
							<span class="wcusage-migration-source-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<details class="wcusage-migration-supported">
				<summary><?php esc_html_e( 'Supported plugins', 'woo-coupon-usage' ); ?></summary>
				<p><?php echo esc_html( implode( ', ', wp_list_pluck( $sources, 'name' ) ) ); ?></p>
				<?php if ( $missing ) : ?>
					<p class="description"><?php esc_html_e( 'A plugin is listed above once it is active, or its data is found in the database.', 'woo-coupon-usage' ); ?></p>
				<?php endif; ?>
			</details>

		</div>

		<div id="wcusage-migration-setup"></div>

		<?php if ( $runs ) : ?>
			<div id="wcusage-migration-history" class="wcusage-migration-step">
				<h2 class="wcu-section-title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <?php esc_html_e( 'Previous migrations', 'woo-coupon-usage' ); ?></h2>
				<div class="wcusage-migration-card">
					<table class="wcusage-migration-table wcusage-migration-runs">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Started', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Status', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Imported', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'woo-coupon-usage' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $runs as $run_id => $run ) : ?>
								<tr>
									<td><?php echo esc_html( $run['name'] ?? $run['source'] ); ?></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['started'] ) ); ?></td>
									<td><span class="wcusage-migration-status is-<?php echo esc_attr( $run['status'] ); ?>"><?php echo esc_html( wcusage_migration_status_label( $run['status'] ) ); ?></span></td>
									<td>
										<?php
										$counts     = isset( $run['counts'] ) ? (array) $run['counts'] : array();
										$affiliates = (int) ( $counts['affiliates_imported'] ?? 0 );
										$orders     = (int) ( $counts['referrals_imported'] ?? 0 );
										echo esc_html(
											sprintf(
												/* translators: 1: number of affiliates (e.g. "3 affiliates"), 2: number of orders (e.g. "5 orders") */
												__( '%1$s, %2$s', 'woo-coupon-usage' ),
												/* translators: %s: number of affiliates */
												sprintf( _n( '%s affiliate', '%s affiliates', $affiliates, 'woo-coupon-usage' ), number_format_i18n( $affiliates ) ),
												/* translators: %s: number of orders */
												sprintf( _n( '%s order', '%s orders', $orders, 'woo-coupon-usage' ), number_format_i18n( $orders ) )
											)
										);
										?>
									</td>
									<td>
										<?php
										if ( 'undone' !== $run['status'] && 'undoing' !== $run['status'] ) :
											// A run still marked running that is not the one in progress was
											// interrupted for good (its state replaced): it can be undone.
											$in_progress = 'running' === $run['status'] && ( $state['run_id'] ?? '' ) === $run_id && 'running' === ( $state['status'] ?? '' );
											$blocked_by  = $in_progress ? '' : WCUsage_Migration_Runner::undo_blocked_by( $run_id );
											?>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcusage_migration_report&run_id=' . rawurlencode( $run_id ) ), 'wcusage_migration_report' ) ); ?>"><?php esc_html_e( 'Download report', 'woo-coupon-usage' ); ?></a>
											<?php if ( ! $in_progress && $blocked_by ) : ?>
												<?php // A later run from the same plugin builds on this one: the runner refuses to undo it first. ?>
												<button type="button" class="button button-small" disabled aria-describedby="wcusage-migration-undo-note-<?php echo esc_attr( $run_id ); ?>"><?php esc_html_e( 'Undo', 'woo-coupon-usage' ); ?></button>
												<span class="wcusage-migration-undo-note description" id="wcusage-migration-undo-note-<?php echo esc_attr( $run_id ); ?>"><?php esc_html_e( 'Undo the later migration first', 'woo-coupon-usage' ); ?></span>
											<?php elseif ( ! $in_progress ) : ?>
												<button type="button" class="button button-small wcusage-migration-undo" data-run="<?php echo esc_attr( $run_id ); ?>"><?php esc_html_e( 'Undo', 'woo-coupon-usage' ); ?></button>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="wcusage-migration-history-foot">
						<p class="description"><?php esc_html_e( 'Undo removes everything a migration created and puts back what it changed - except values changed since, which are kept. It is meant for straight after a migration.', 'woo-coupon-usage' ); ?></p>
						<?php // On multisite, deleting a user here only removes them from this site: their account stays on the network. ?>
						<label class="wcusage-migration-undo-users"><input type="checkbox" id="wcusage-migration-undo-delete-users" value="1"> <?php echo esc_html( is_multisite() ? __( 'Also remove user accounts the migration created from this site (their accounts stay on the network)', 'woo-coupon-usage' ) : __( 'Also delete user accounts the migration created', 'woo-coupon-usage' ) ); ?></label>
					</div>
				</div>
			</div>
		<?php endif; ?>

		</div>

	</div>
	</div>
	<?php
}

/**
 * The preview and options for one source.
 *
 * @param WCUsage_Migration_Source $source
 */
function wcusage_migration_render_setup( $source ) {

	$schema    = WCUsage_Migration_Options::schema( $source );
	$sections  = WCUsage_Migration_Options::sections();
	$counts    = $source->get_counts();
	$pro       = wcusage_migration_is_pro();
	$settings  = $source->get_settings();
	$supported = $source->get_supported();

	$count_labels = array(
		'affiliates' => __( 'Affiliates', 'woo-coupon-usage' ),
		'coupons'    => __( 'Linked coupons', 'woo-coupon-usage' ),
		'referrals'  => __( 'Referrals', 'woo-coupon-usage' ),
		'payouts'    => __( 'Payouts', 'woo-coupon-usage' ),
		'visits'     => __( 'Visits', 'woo-coupon-usage' ),
		'creatives'  => __( 'Creatives', 'woo-coupon-usage' ),
		'groups'     => __( 'Groups', 'woo-coupon-usage' ),
	);
	$count_icons = array(
		'affiliates' => 'fa-users',
		'coupons'    => 'fa-ticket',
		'referrals'  => 'fa-cart-shopping',
		'payouts'    => 'fa-money-bill-wave',
		'visits'     => 'fa-arrow-pointer',
		'creatives'  => 'fa-image',
		'groups'     => 'fa-layer-group',
	);
	$section_icons = array(
		'data'       => 'fa-list-check',
		'affiliates' => 'fa-users',
		'coupons'    => 'fa-ticket',
		'referrals'  => 'fa-cart-shopping',
		'balances'   => 'fa-wallet',
		'visits'     => 'fa-arrow-pointer',
		'settings'   => 'fa-gear',
		'links'      => 'fa-link',
		'source'     => 'fa-puzzle-piece',
		'advanced'   => 'fa-screwdriver-wrench',
	);
	?>
	<form id="wcusage-migration-form" data-source="<?php echo esc_attr( $source->get_id() ); ?>">

		<div class="wcusage-migration-step">

		<h2 class="wcu-section-title"><span class="wcusage-migration-step-number">2</span>
			<?php
			/* translators: %s: plugin name */
			echo esc_html( sprintf( __( 'Review what will be migrated from %s', 'woo-coupon-usage' ), $source->get_name() ) );
			?>
		</h2>

		<div class="wcusage-migration-counts">
			<?php foreach ( $counts as $key => $value ) : ?>
				<div class="wcusage-migration-count<?php echo (int) $value ? '' : ' is-empty'; ?>">
					<span class="wcusage-migration-count-icon"><i class="fa-solid <?php echo esc_attr( isset( $count_icons[ $key ] ) ? $count_icons[ $key ] : 'fa-database' ); ?>" aria-hidden="true"></i></span>
					<span class="wcusage-migration-count-text">
						<span class="wcusage-migration-count-value"><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></span>
						<span class="wcusage-migration-count-label"><?php echo esc_html( isset( $count_labels[ $key ] ) ? $count_labels[ $key ] : ucfirst( $key ) ); ?></span>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="wcusage-migration-notices">
			<?php if ( 'verified' !== $source->get_confidence() ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( '%s is a premium plugin whose code is not public: this importer was built from its documentation and from other tools that read its data. Please check the results afterwards - the report under "Previous migrations" lists every affiliate - and undo the migration if anything looks wrong.', 'woo-coupon-usage' ), $source->get_name() ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php foreach ( $source->get_warnings() as $warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
			<?php endforeach; ?>

			<?php if ( ! $pro ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Commission balances, payouts, payout details, groups, creatives and custom rates are migrated with Coupon Affiliates PRO.', 'woo-coupon-usage' ); ?></p></div>
			<?php endif; ?>
		</div>

		<?php $not_migrated = $source->get_not_migrated(); ?>
		<?php if ( $not_migrated ) : ?>
			<div class="wcusage-migration-card wcusage-migration-section wcusage-migration-not-migrated">
				<h3 class="wcusage-migration-section-title">
					<span class="wcusage-migration-section-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span>
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( 'Not migrated from %s', 'woo-coupon-usage' ), $source->get_name() ) );
					?>
				</h3>
				<div class="wcusage-migration-not-migrated-body">
					<p><?php esc_html_e( 'These are left in the old plugin, so set them up again in Coupon Affiliates if you need them:', 'woo-coupon-usage' ); ?></p>
					<ul>
						<?php foreach ( $not_migrated as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endif; ?>

		</div>

		<div class="wcusage-migration-step">

		<h2 class="wcu-section-title"><span class="wcusage-migration-step-number">3</span> <?php esc_html_e( 'Choose how to migrate', 'woo-coupon-usage' ); ?></h2>

		<?php
		foreach ( $sections as $section_key => $section_title ) :
			$options = array_filter(
				$schema,
				function ( $option ) use ( $section_key ) {
					return $option['section'] === $section_key;
				}
			);
			if ( ! $options ) {
				continue;
			}
			?>
			<div class="wcusage-migration-card wcusage-migration-section" data-section="<?php echo esc_attr( $section_key ); ?>">
				<h3 class="wcusage-migration-section-title">
					<span class="wcusage-migration-section-icon"><i class="fa-solid <?php echo esc_attr( isset( $section_icons[ $section_key ] ) ? $section_icons[ $section_key ] : 'fa-sliders' ); ?>" aria-hidden="true"></i></span>
					<?php echo esc_html( $section_title ); ?>
				</h3>
				<div class="wcusage-migration-options">
					<?php foreach ( $options as $key => $option ) : ?>
						<?php wcusage_migration_render_option( $key, $option, $pro, $settings ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>

		</div>

		<div class="wcusage-migration-step">

		<h2 class="wcu-section-title"><span class="wcusage-migration-step-number">4</span> <?php esc_html_e( 'Start the migration', 'woo-coupon-usage' ); ?></h2>

		<div class="wcusage-migration-card wcusage-migration-start">
			<p class="wcusage-migration-start-title"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> <?php esc_html_e( 'Before you start', 'woo-coupon-usage' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'It is recommended to test the migration on a staging site (a copy of your site) first, and check the results there before migrating data on your live site.', 'woo-coupon-usage' ); ?></li>
				<li><?php esc_html_e( 'Back up your database. Every migration can be undone, but a backup is the safest way back.', 'woo-coupon-usage' ); ?></li>
				<li>
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( 'Leave %s installed until you have checked the results: some plugins delete their data when they are deleted.', 'woo-coupon-usage' ), $source->get_name() ) );
					?>
				</li>
				<li><?php esc_html_e( 'Keep this page open while it runs. If it is closed, open it again to carry on from where it stopped.', 'woo-coupon-usage' ); ?></li>
			</ul>
			<label class="wcusage-migration-backup"><input type="checkbox" id="wcusage-migration-backup" value="1"> <?php esc_html_e( 'I have a recent backup of my database', 'woo-coupon-usage' ); ?></label>
			<label class="wcusage-migration-backup"><input type="checkbox" id="wcusage-migration-staging" value="1"> <?php esc_html_e( 'I have tested this migration on a staging site first, or I am happy to continue without testing it on a staging site', 'woo-coupon-usage' ); ?></label>
			<p class="wcusage-migration-start-actions">
				<button type="submit" class="button button-primary button-hero" id="wcusage-migration-start">
					<i class="fa-solid fa-right-left" aria-hidden="true"></i>
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( 'Start migrating from %s', 'woo-coupon-usage' ), $source->get_name() ) );
					?>
				</button>
			</p>
		</div>

		</div>

	</form>
	<?php
}

/**
 * One option field.
 *
 * @param string $key
 * @param array  $option
 * @param bool   $pro
 * @param array  $settings Source settings, shown next to the settings options.
 */
function wcusage_migration_render_option( $key, $option, $pro, $settings ) {

	$locked   = ! empty( $option['pro'] ) && ! $pro;
	$name     = 'options[' . $key . ']';
	$id       = 'wcusage-migration-' . $key;
	$parent   = ! empty( $option['parent'] ) ? ' data-parent="' . esc_attr( $option['parent'] ) . '"' : '';
	$disabled = $locked || ! empty( $option['locked'] ) ? ' disabled' : '';

	echo '<div class="wcusage-migration-option is-' . esc_attr( $option['type'] ) . ( $locked ? ' is-locked' : '' ) . '"' . $parent . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( 'checkbox' === $option['type'] ) {
		// A locked "required" option still has to be posted.
		if ( ! empty( $option['locked'] ) ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="1">';
		}
		// The description sits inside the label, so the whole row toggles the box.
		echo '<label class="wcusage-migration-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $option['default'] && ! $locked, true, false ) . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="wcusage-migration-check-text"><span class="wcusage-migration-check-label">' . esc_html( $option['label'] );
		if ( $locked ) {
			echo ' <span class="wcusage-tools-badge">' . esc_html__( 'PRO', 'woo-coupon-usage' ) . '</span>';
		}
		$current = wcusage_migration_setting_preview( $option, $settings );
		if ( $current ) {
			echo ' <span class="wcusage-migration-setting-value">' . esc_html( $current ) . '</span>';
		}
		echo '</span>';
		if ( ! empty( $option['desc'] ) ) {
			echo '<span class="description">' . esc_html( $option['desc'] ) . '</span>';
		}
		echo '</span></label>';
	} else {
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $option['label'] ) . '</label>';
		switch ( $option['type'] ) {
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $option['choices'] as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $option['default'], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'number':
				echo '<input type="number" min="0" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option['default'] ) . '" class="small-text"' . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'date':
				echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option['default'] ) . '"' . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option['default'] ) . '" class="regular-text"' . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! empty( $option['desc'] ) ) {
			echo '<p class="description">' . esc_html( $option['desc'] ) . '</p>';
		}
	}

	echo '</div>';
}

/**
 * "Source value -> current value" for a settings option.
 *
 * @param array $option
 * @param array $settings
 * @return string
 */
function wcusage_migration_setting_preview( $option, $settings ) {

	if ( empty( $option['setting'] ) ) {
		return '';
	}

	switch ( $option['setting'] ) {
		case 'rate':
			if ( ! isset( $settings['rate'] ) ) {
				return '';
			}
			$type = isset( $settings['rate_type'] ) ? $settings['rate_type'] : 'percent';
			if ( 'percent' === $type ) {
				$value = wc_format_decimal( $settings['rate'] ) . '%';
			} else {
				$value = wcusage_migration_plain_price( $settings['rate'] ) . ( 'flat_order' === $type ? ' ' . __( 'per order', 'woo-coupon-usage' ) : ' ' . __( 'per product', 'woo-coupon-usage' ) );
			}
			// The migrated rate replaces the fixed per-order and per-product
			// amounts too (they are set to 0), so show any that are set now.
			$current = array( wcusage_get_setting_value( 'wcusage_field_affiliate', '0' ) . '%' );
			$fixed   = array(
				'wcusage_field_affiliate_fixed_order'   => __( 'per order', 'woo-coupon-usage' ),
				'wcusage_field_affiliate_fixed_product' => __( 'per product', 'woo-coupon-usage' ),
			);
			foreach ( $fixed as $key => $per ) {
				$amount = (float) wcusage_get_setting_value( $key, '0' );
				if ( $amount > 0 ) {
					$current[] = wcusage_migration_plain_price( $amount ) . ' ' . $per;
				}
			}
			/* translators: 1: new value, 2: current value(s), e.g. "10% + £2.00 per order" */
			return sprintf( __( '(%1$s - currently %2$s)', 'woo-coupon-usage' ), $value, implode( ' + ', $current ) );
		case 'cookie_days':
			if ( empty( $settings['cookie_days'] ) ) {
				return '';
			}
			/* translators: 1: new number of days, 2: current number of days */
			return sprintf( _n( '(%1$d day - currently %2$d)', '(%1$d days - currently %2$d)', (int) $settings['cookie_days'], 'woo-coupon-usage' ), (int) $settings['cookie_days'], (int) wcusage_get_setting_value( 'wcusage_urls_cookie_days', '30' ) );
		case 'credit_first':
			if ( ! isset( $settings['credit_first'] ) ) {
				return '';
			}
			return $settings['credit_first'] ? __( '(first click)', 'woo-coupon-usage' ) : __( '(last click)', 'woo-coupon-usage' );
	}

	return '';
}
