<?php
/**
 * Loads the migration tool's classes and admin screen (admin, AJAX and CLI only).
 *
 * Source classes are loaded on demand by WCUsage_Migration_Runner::get_source().
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wcusage-migration-source.php';
require_once __DIR__ . '/class-wcusage-migration-journal.php';
require_once __DIR__ . '/class-wcusage-migration-options.php';
require_once __DIR__ . '/class-wcusage-migration-writer.php';
require_once __DIR__ . '/class-wcusage-migration-runner.php';
require_once __DIR__ . '/admin-migration-page.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-wcusage-migration-cli.php';
}
