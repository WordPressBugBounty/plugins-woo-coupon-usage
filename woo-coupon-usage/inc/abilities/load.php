<?php
/**
 * Coupon Affiliates - WordPress Abilities (AI agents & MCP) - Loader.
 *
 * Registers the plugin's abilities with the WordPress Abilities API (core
 * since 6.9), which is what AI agents, MCP clients and other plugins use to
 * discover and call into a site. Almost every ability is a thin wrapper
 * around a REST API v2 controller method, so the permission checks, queries,
 * caching and throttles are the ones the API already uses - nothing is
 * implemented twice. The few with no REST endpoint have a controller of
 * their own, built the same way (class-wcusage-abilities-controller.php).
 *
 * MCP support comes from the WordPress MCP Adapter plugin, not from code in
 * this plugin: the adapter turns abilities into MCP tools. When it is active
 * a dedicated "Coupon Affiliates" MCP server is registered with it (see
 * abilities-mcp.php); without it the abilities are still available to other
 * plugins and through core's /wp-abilities/v1 REST routes.
 *
 * Included from woo-coupon-usage.php after the REST API v2 loader, whose
 * helpers and controllers these files use.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require plugin_dir_path( __FILE__ ) . 'abilities-helpers.php';
require plugin_dir_path( __FILE__ ) . 'abilities-definitions.php';

// The registration and MCP hooks only mean anything where core ships the
// Abilities API. The admin card is loaded either way, so a site on an older
// WordPress is told what it needs rather than shown nothing.
if ( wcusage_abilities_supported() ) {
	require plugin_dir_path( __FILE__ ) . 'abilities-register.php';
	require plugin_dir_path( __FILE__ ) . 'abilities-mcp.php';
}

if ( is_admin() ) {
	include plugin_dir_path( __FILE__ ) . 'abilities-admin.php';

	// Offers to switch AI & MCP on, for stores already set up for AI apps.
	if ( wcusage_abilities_supported() ) {
		include plugin_dir_path( __FILE__ ) . 'abilities-notice.php';
	}
}
