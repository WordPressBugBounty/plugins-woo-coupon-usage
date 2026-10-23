<?php
/**
 * Migration tool: the always-loaded part.
 *
 * Holds the list of affiliate plugins the tool can import from, the cheap
 * checks that decide whether the "Migration" screen is offered, and the
 * table the tool keeps its records in. Everything that actually reads another
 * plugin's data or writes to this one lives in the classes loaded by
 * inc/migration/load.php, which only load in an admin context.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCUSAGE_MIGRATION_DB_VERSION', '2' );

/**
 * The affiliate plugins the migration tool can import from.
 *
 * "folders" are the plugin directory names checked to see whether the plugin is
 * active. "tables" are checked (without the site prefix) to see whether its data
 * is still in the database - a plugin that has been deactivated, or even
 * deleted, usually leaves its tables behind, and migrating after switching the
 * old plugin off is the normal order of things.
 *
 * @return array
 */
function wcusage_migration_sources() {

	$sources = array(
		'affiliatewp'               => array(
			'name'    => 'AffiliateWP',
			'class'   => 'WCUsage_Migration_Source_AffiliateWP',
			'file'    => 'class-wcusage-migration-source-affiliatewp.php',
			'folders' => array( 'affiliate-wp', 'affiliatewp' ),
			'tables'  => array( 'affiliate_wp_affiliates' ),
		),
		'affiliate-for-woocommerce' => array(
			'name'    => 'Affiliate For WooCommerce',
			'class'   => 'WCUsage_Migration_Source_AFWC',
			'file'    => 'class-wcusage-migration-source-afwc.php',
			'folders' => array( 'affiliate-for-woocommerce' ),
			'tables'  => array( 'afwc_referrals' ),
		),
		'slicewp'                   => array(
			'name'    => 'SliceWP',
			'class'   => 'WCUsage_Migration_Source_SliceWP',
			'file'    => 'class-wcusage-migration-source-slicewp.php',
			'folders' => array( 'slicewp', 'slicewp-pro' ),
			'tables'  => array( 'slicewp_affiliates' ),
		),
		'yith-affiliates'           => array(
			'name'    => 'YITH WooCommerce Affiliates',
			'class'   => 'WCUsage_Migration_Source_YITH',
			'file'    => 'class-wcusage-migration-source-yith.php',
			'folders' => array( 'yith-woocommerce-affiliates', 'yith-woocommerce-affiliates-premium' ),
			'tables'  => array( 'yith_wcaf_affiliates' ),
		),
		'solid-affiliate'           => array(
			'name'    => 'Solid Affiliate',
			'class'   => 'WCUsage_Migration_Source_Solid',
			'file'    => 'class-wcusage-migration-source-solid.php',
			'folders' => array( 'solid_affiliate', 'solid-affiliate' ),
			'tables'  => array( 'solid_affiliate_affiliates' ),
		),
		'ultimate-affiliate-pro'    => array(
			'name'    => 'Ultimate Affiliate Pro',
			'class'   => 'WCUsage_Migration_Source_UAP',
			'file'    => 'class-wcusage-migration-source-uap.php',
			'folders' => array( 'indeed-affiliate-pro', 'ultimate-affiliate-pro' ),
			// Both: Uncanny Automator also creates "uap_" tables of its own.
			'tables'  => array( 'uap_affiliates', 'uap_referrals' ),
		),
		'affiliatepress'            => array(
			'name'    => 'AffiliatePress',
			'class'   => 'WCUsage_Migration_Source_AffiliatePress',
			'file'    => 'class-wcusage-migration-source-affiliatepress.php',
			'folders' => array( 'affiliatepress-affiliate-marketing', 'affiliatepress-affiliate-marketing-pro' ),
			'tables'  => array( 'affiliatepress_affiliates' ),
		),
		'affiliates-manager'        => array(
			'name'    => 'Affiliates Manager',
			'class'   => 'WCUsage_Migration_Source_AffiliatesManager',
			'file'    => 'class-wcusage-migration-source-affiliates-manager.php',
			'folders' => array( 'affiliates-manager' ),
			'tables'  => array( 'wpam_affiliates' ),
		),
		'itthinx-affiliates'        => array(
			'name'    => 'Affiliates (itthinx)',
			'class'   => 'WCUsage_Migration_Source_Itthinx',
			'file'    => 'class-wcusage-migration-source-itthinx.php',
			'folders' => array( 'affiliates', 'affiliates-pro', 'affiliates-enterprise' ),
			'tables'  => array( 'aff_affiliates', 'aff_referrals' ),
		),
	);

	/**
	 * Filters the affiliate plugins the migration tool can import from.
	 *
	 * @param array $sources Keyed by source ID.
	 */
	return apply_filters( 'wcusage_migration_sources', $sources );
}

/**
 * Whether the PRO parts of the migration (balances, payouts, MLA and so on)
 * can run on this site.
 *
 * Those steps write to features that only exist in PRO, and their code ships
 * in premium-files/, so the class has to be there as well as the licence.
 *
 * @return bool
 */
function wcusage_migration_is_pro() {
	$pro = class_exists( 'WCUsage_Migration_Writer_Pro' ) && function_exists( 'wcu_fs' ) && wcu_fs()->can_use_premium_code();

	/**
	 * Filters whether the PRO migration steps are available.
	 *
	 * Only makes a difference where the PRO writer class is loaded: in the free
	 * build that code is not there to run.
	 *
	 * @param bool $pro
	 */
	return class_exists( 'WCUsage_Migration_Writer_Pro' ) && (bool) apply_filters( 'wcusage_migration_is_pro', $pro );
}

/**
 * The folders of every active plugin on this site (and network).
 *
 * Read straight from the options rather than through is_plugin_active(), which
 * lives in a wp-admin include that is not loaded on every request.
 *
 * @return string[]
 */
function wcusage_migration_active_plugin_folders() {
	$plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	$folders = array();
	foreach ( $plugins as $plugin ) {
		$folders[] = strtolower( dirname( (string) $plugin ) );
	}
	return array_unique( $folders );
}

/**
 * Whether a source plugin is active right now.
 *
 * @param string $source_id
 * @return bool
 */
function wcusage_migration_source_is_active( $source_id ) {
	$sources = wcusage_migration_sources();
	if ( empty( $sources[ $source_id ] ) ) {
		return false;
	}
	$active = wcusage_migration_active_plugin_folders();
	foreach ( (array) $sources[ $source_id ]['folders'] as $folder ) {
		if ( in_array( strtolower( $folder ), $active, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a table exists, by asking for its columns.
 *
 * SHOW TABLES cannot see temporary tables, which is how the WordPress test
 * suite creates every table inside a test, so asking for the columns is the
 * check that works everywhere. Errors are suppressed for the duration: a
 * missing table is the expected answer most of the time.
 *
 * @param string|null $table Full table name, including any prefix. Null forgets
 *                           every table remembered so far.
 * @return string[] Column names, or an empty array when there is no such table.
 */
function wcusage_migration_table_columns( $table ) {
	global $wpdb;

	static $cache = array();

	if ( null === $table ) {
		$cache = array();
		return array();
	}

	if ( isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}

	if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
		return array();
	}

	$suppress = $wpdb->suppress_errors( true );
	$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $suppress );

	$columns = is_array( $columns ) ? $columns : array();

	// Only remember tables that exist: a test (or a plugin activated while this
	// request runs) can create one later on.
	if ( $columns ) {
		$cache[ $table ] = $columns;
	}

	return $columns;
}

/**
 * Whether a source plugin's data is in the database.
 *
 * @param string $source_id
 * @return bool
 */
function wcusage_migration_source_has_data( $source_id ) {
	global $wpdb;

	$sources = wcusage_migration_sources();
	if ( empty( $sources[ $source_id ] ) ) {
		return false;
	}

	foreach ( (array) $sources[ $source_id ]['tables'] as $table ) {
		$found = wcusage_migration_table_columns( $wpdb->prefix . $table );
		// AffiliateWP's network-wide mode creates its tables without the site prefix.
		if ( ! $found && 'affiliatewp' === $source_id ) {
			$found = wcusage_migration_table_columns( $table );
		}
		if ( ! $found ) {
			return false;
		}
	}

	return true;
}

/**
 * Which source plugins are active and which have data, cached.
 *
 * The checks are cheap, but the admin menu is built on every admin page load,
 * so the table checks are cached for a while and reset whenever a plugin is
 * switched on or off.
 *
 * @param bool $force Run the checks again rather than using the cache.
 * @return array Keyed by source ID: array( 'active' => bool, 'data' => bool ).
 */
function wcusage_migration_detect_sources( $force = false ) {

	$cached = $force ? false : get_transient( 'wcusage_migration_detected' );

	if ( ! is_array( $cached ) ) {
		$cached = array();
		foreach ( array_keys( wcusage_migration_sources() ) as $source_id ) {
			$cached[ $source_id ] = wcusage_migration_source_has_data( $source_id );
		}
		set_transient( 'wcusage_migration_detected', $cached, 12 * HOUR_IN_SECONDS );
	}

	$result = array();
	foreach ( array_keys( wcusage_migration_sources() ) as $source_id ) {
		$result[ $source_id ] = array(
			'active' => wcusage_migration_source_is_active( $source_id ),
			'data'   => ! empty( $cached[ $source_id ] ),
		);
	}

	return $result;
}

/**
 * Forget the cached detection results.
 */
function wcusage_migration_reset_detection() {
	delete_transient( 'wcusage_migration_detected' );
}
add_action( 'activated_plugin', 'wcusage_migration_reset_detection' );
add_action( 'deactivated_plugin', 'wcusage_migration_reset_detection' );

/**
 * Whether the "Migration" screen is listed in the Coupon Affiliates menu.
 *
 * It is listed while another affiliate plugin is active, while one's data is
 * still in the database (so a store that switched the old plugin off first can
 * still migrate), and once a migration has been run, so it can be reviewed or
 * undone. Otherwise the screen is still reachable from Admin Tools.
 *
 * @return bool
 */
function wcusage_migration_show_in_menu() {

	foreach ( wcusage_migration_detect_sources() as $detected ) {
		if ( $detected['active'] || $detected['data'] ) {
			return (bool) apply_filters( 'wcusage_migration_show_in_menu', true );
		}
	}

	$runs = get_option( 'wcusage_migration_runs', array() );

	return (bool) apply_filters( 'wcusage_migration_show_in_menu', ! empty( $runs ) );
}

/**
 * Create (or update) the table the migration tool records its work in.
 *
 * One row per thing the tool did: which source record it came from, what it
 * created or changed here, and what was there before. That is what lets a run
 * resume where it stopped without importing anything twice, lets it be undone,
 * and lets old referral links be translated after the migration.
 */
function wcusage_install_migration_tables() {
	global $wpdb;

	$table = $wpdb->prefix . 'wcusage_migration_map';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		run_id varchar(64) NOT NULL DEFAULT '',
		source varchar(64) NOT NULL DEFAULT '',
		entity varchar(40) NOT NULL DEFAULT '',
		source_id varchar(191) NOT NULL DEFAULT '',
		target_type varchar(40) NOT NULL DEFAULT '',
		target_id bigint unsigned NOT NULL DEFAULT 0,
		data longtext NULL,
		created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY source_entity (source,entity,source_id),
		KEY run_id (run_id),
		KEY target (target_type,target_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'wcusage_migration_db_version', WCUSAGE_MIGRATION_DB_VERSION, false );
}

/**
 * Create the table the first time an admin screen is loaded.
 *
 * Only once a migration could actually be started - nothing is created on
 * stores that never open the tool.
 */
function wcusage_migration_maybe_install() {
	if ( get_option( 'wcusage_migration_db_version' ) !== WCUSAGE_MIGRATION_DB_VERSION ) {
		wcusage_install_migration_tables();
	}
}
