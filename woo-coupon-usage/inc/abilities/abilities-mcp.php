<?php
/**
 * Coupon Affiliates - Abilities - MCP server.
 *
 * MCP itself is the WordPress MCP Adapter's job: it speaks the protocol,
 * handles the transport and turns abilities into MCP tools. This file only
 * asks it, when it is active, for a server of this plugin's own - at
 * /wp-json/coupon-affiliates/mcp - listing every active ability as a named
 * tool. An agent connected there sees "coupon-affiliates-list-affiliates"
 * and so on directly, rather than having to discover them through the
 * adapter's default server.
 *
 * The abilities are also flagged for MCP, so the adapter's default server
 * offers them too. Without the adapter nothing here runs, and nothing
 * breaks: the hook below is simply never fired.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Remember which adapter started up, for the API screen - early, so it is
// known even when the server below is not registered.
add_action( 'mcp_adapter_init', 'wcusage_abilities_mcp_adapter', 1 );

if ( ! function_exists( 'wcusage_abilities_register_mcp_server' ) ) {
	/**
	 * Register the Coupon Affiliates MCP server with the MCP Adapter.
	 *
	 * Written against the adapter's create_server() signature as of 0.6,
	 * which the 0.7 migration guide leaves unchanged, and defensive about it:
	 * every class it names is checked first, and a refusal is recorded for
	 * the API screen rather than left to fail silently.
	 *
	 * @param object $adapter The WP\MCP\Core\McpAdapter instance.
	 */
	function wcusage_abilities_register_mcp_server( $adapter ) {

		/**
		 * Filter whether Coupon Affiliates registers its own MCP server.
		 *
		 * Returning false leaves the abilities available through the
		 * adapter's default server only.
		 *
		 * @param bool $register Whether to register the server.
		 */
		if ( ! apply_filters( 'wcusage_abilities_mcp_server_enabled', true ) ) {
			return;
		}

		if ( ! wcusage_abilities_enabled() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$transport = 'WP\\MCP\\Transport\\HttpTransport';
		if ( ! class_exists( $transport ) ) {
			wcusage_abilities_mcp_server_error( __( 'This version of the MCP Adapter does not provide the HTTP transport.', 'woo-coupon-usage' ) );
			return;
		}

		// Only abilities that actually registered can become tools - the
		// adapter refuses a server whose tool list names a missing ability.
		$tools = array_values( array_filter( wcusage_abilities_active_names(), 'wp_has_ability' ) );
		if ( empty( $tools ) ) {
			return;
		}

		$error_handler = 'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';

		list( $server_id, $namespace, $route ) = wcusage_abilities_mcp_server_id();

		// This runs inside rest_api_init on every REST request while an adapter
		// is active. If some adapter release - or a copy bundled by another
		// plugin - ever changes this signature, the TypeError must not escape:
		// it would fail every REST request on the site, checkout and the block
		// editor included, not just MCP. Caught, it only leaves this server
		// out, with the reason shown on the API screen.
		try {
			$result = $adapter->create_server(
				$server_id,
				$namespace,
				$route,
				__( 'Coupon Affiliates', 'woo-coupon-usage' ),
				__( 'Read and manage this store\'s affiliate program: affiliates, coupons, referred orders, commission, registrations and payouts.', 'woo-coupon-usage' ),
				defined( 'WCUSAGE_VERSION' ) ? (string) WCUSAGE_VERSION : '1.0.0',
				array( $transport ),
				class_exists( $error_handler ) ? $error_handler : null,
				null,
				$tools,
				array(),
				array(),
				'wcusage_abilities_mcp_transport_permission'
			);
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'wcusage_abilities_mcp_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			wcusage_abilities_mcp_server_error( $result->get_error_message() );
		}
	}
	add_action( 'mcp_adapter_init', 'wcusage_abilities_register_mcp_server' );
}

if ( ! function_exists( 'wcusage_abilities_mcp_transport_permission' ) ) {
	/**
	 * Who may connect to the Coupon Affiliates MCP server at all.
	 *
	 * The server-wide gate the adapter checks before any tool is listed or
	 * called: plugin admins, and affiliates when affiliates are allowed in.
	 * Every ability then applies its own, narrower check on top.
	 *
	 * @return bool
	 */
	function wcusage_abilities_mcp_transport_permission() {

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( wcusage_api_is_admin_user() ) {
			return true;
		}

		return wcusage_abilities_affiliates_allowed()
			&& function_exists( 'wcusage_is_user_affiliate' )
			&& wcusage_is_user_affiliate( get_current_user_id() );
	}
}
