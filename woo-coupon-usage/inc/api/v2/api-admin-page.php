<?php
/**
 * Coupon Affiliates REST API v2 - Admin page.
 *
 * "Coupon Affiliates > Admin Tools > API" screen: overview of the API,
 * application password guidance, API key management and - on PRO - webhook
 * management. Form posts are handled on admin_init (nonce + capability
 * checked) so the page can redirect before output.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_api_admin_page_hook' ) ) {
	/**
	 * The admin page's hook suffix, remembered when the menu is registered.
	 *
	 * The suffix is derived from the parent menu, so it cannot be written out
	 * literally. Keeping the one add_submenu_page() returns lets the asset
	 * loader identify the screen from the hook it is passed instead of reading
	 * the page name back out of the query string.
	 *
	 * @param string|null $set Hook suffix to remember, or null to read.
	 *
	 * @return string Empty string until the menu has been registered.
	 */
	function wcusage_api_admin_page_hook( $set = null ) {
		static $hook = '';

		if ( null !== $set ) {
			$hook = (string) $set;
		}

		return $hook;
	}
}

if ( ! function_exists( 'wcusage_api_admin_menu' ) ) {
	/**
	 * Register the API admin page under Admin Tools.
	 *
	 * The parent slug is "wcusage_tools", which is itself a submenu rather than
	 * a top-level menu, so WordPress registers the screen without ever drawing
	 * it in the sidebar. Every other tool screen is registered the same way and
	 * is reached from the Admin Tools page instead of taking a menu slot.
	 */
	function wcusage_api_admin_menu() {
		// False when the current user cannot see the page, which leaves the
		// remembered hook empty and the stylesheet unloaded - correct, since
		// the screen is unreachable for them.
		$hook = add_submenu_page(
			'wcusage_tools',
			wcusage_api_webhooks_available()
				? esc_html__( 'Coupon Affiliates: API & Webhooks', 'woo-coupon-usage' )
				: esc_html__( 'Coupon Affiliates: API', 'woo-coupon-usage' ),
			esc_html__( 'API', 'woo-coupon-usage' ),
			wcusage_get_admin_menu_capability(),
			'wcusage_api',
			'wcusage_api_admin_page_render'
		);

		wcusage_api_admin_page_hook( $hook ? $hook : '' );
	}
	add_action( 'admin_menu', 'wcusage_api_admin_menu', 99 );
}

if ( ! function_exists( 'wcusage_api_admin_error_redirect' ) ) {
	/**
	 * Hand an error message to the next page load and return the redirect.
	 *
	 * The message travels in a short-lived transient rather than in the query
	 * string. Reflecting it through the URL means anyone who can talk an
	 * administrator into following a link chooses the text of the error notice
	 * that administrator is shown. It is escaped on the way out, so this was
	 * never markup injection - but an official-looking admin notice saying
	 * whatever an attacker wants is a credible way to set up the next mistake,
	 * and nothing needed the message to be in the URL.
	 *
	 * @param string $message  Message to show once.
	 * @param string $redirect Redirect target so far.
	 *
	 * @return string
	 */
	function wcusage_api_admin_error_redirect( $message, $redirect ) {

		set_transient( 'wcusage_api_admin_error_' . get_current_user_id(), (string) $message, 30 );

		return add_query_arg( 'wcusage_api_notice', 'error', $redirect );
	}
}

if ( ! function_exists( 'wcusage_api_admin_handle_post' ) ) {
	/**
	 * Handle API admin page form submissions.
	 */
	function wcusage_api_admin_handle_post() {

		// Read before the nonce check on purpose: admin_init runs on every
		// admin request, and this only decides whether the request is one of
		// this screen's submissions at all. The nonce and capability are
		// verified below, before anything is acted on.
		if ( empty( $_POST['wcusage_api_admin_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ) );
		}

		check_admin_referer( 'wcusage_api_admin', 'wcusage_api_admin_nonce' );

		$action   = sanitize_key( wp_unslash( $_POST['wcusage_api_admin_action'] ) );
		$redirect = admin_url( 'admin.php?page=wcusage_api' );

		// Webhooks are PRO, so their handlers are simply not defined in the
		// free build. The forms that post these actions are not rendered there
		// either, but the action name travels in the request body and nothing
		// stops someone posting one by hand - which would be a fatal rather
		// than a no-op. Refused here, once, for all four of them.
		if ( false !== strpos( $action, 'webhook' ) && ! wcusage_api_webhooks_available() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		switch ( $action ) {

			case 'toggle_api':
				$enable = isset( $_POST['api_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['api_enabled'] ) );
				wcusage_api_update_settings( array( 'enabled' => $enable ? '1' : '0' ) );

				// Route registration happens on rest_api_init, so the change
				// takes effect on the next request either way.
				$redirect = add_query_arg( 'wcusage_api_notice', $enable ? 'api_enabled' : 'api_disabled', $redirect );
				break;

			case 'save_endpoints':
				// Only paths that actually exist can be stored, and the list
				// records what is switched OFF so anything new stays on.
				// Locked PRO rows are skipped: their checkbox is disabled so it
				// never posts, and recording them as "off" here would leave the
				// endpoint switched off after an upgrade to PRO.
				$known = array();
				foreach ( wcusage_api_admin_get_endpoints() as $known_endpoint ) {
					if ( empty( $known_endpoint['locked'] ) ) {
						$known[] = $known_endpoint['path'];
					}
				}
				$known     = array_values( array_unique( $known ) );
				$submitted = isset( $_POST['api_endpoints'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['api_endpoints'] ) ) : array();
				$disabled  = array_values( array_diff( $known, $submitted ) );

				wcusage_api_update_settings( array( 'disabled_endpoints' => $disabled ) );
				$redirect = add_query_arg( 'wcusage_api_notice', 'endpoints_saved', $redirect );
				break;

			case 'create_key':
				$user_id = isset( $_POST['key_user_id'] ) ? absint( wp_unslash( $_POST['key_user_id'] ) ) : 0;
				if ( ! $user_id ) {
					$user_id = get_current_user_id();
				}
				$description = isset( $_POST['key_description'] ) ? sanitize_text_field( wp_unslash( $_POST['key_description'] ) ) : '';
				$scopes      = isset( $_POST['key_scopes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['key_scopes'] ) ) : array( 'read' );
				$expires     = isset( $_POST['key_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['key_expires'] ) ) : '';

				if ( ! wcusage_api_can_create_key_for_user( $user_id ) ) {
					$result = wcusage_api_error( 'cannot_create_for_user', __( 'You do not have permission to create an API key for this user.', 'woo-coupon-usage' ), 403 );
				} else {
					$result = wcusage_api_create_key( $user_id, $description, $scopes, $expires );
				}

				if ( is_wp_error( $result ) ) {
					$redirect = wcusage_api_admin_error_redirect( $result->get_error_message(), $redirect );
				} else {
					// Shown once on the next page load, then gone.
					// Handed to the next page load and deleted on read. It is the
					// plaintext token, so it sits in the options table until
					// then - keep that window as short as a redirect needs.
					set_transient( 'wcusage_api_new_token_' . get_current_user_id(), $result['token'], 30 );
					$redirect = add_query_arg( 'wcusage_api_notice', 'key_created', $redirect );
				}
				break;

			case 'revoke_key':
				$key_id = isset( $_POST['key_id'] ) ? absint( wp_unslash( $_POST['key_id'] ) ) : 0;
				if ( ! wcusage_api_can_manage_key( $key_id ) ) {
					$redirect = wcusage_api_admin_error_redirect( __( 'You do not have permission to manage this API key.', 'woo-coupon-usage' ), $redirect );
					break;
				}
				wcusage_api_revoke_key( $key_id );
				$redirect = add_query_arg( 'wcusage_api_notice', 'key_revoked', $redirect );
				break;

			case 'delete_key':
				$key_id = isset( $_POST['key_id'] ) ? absint( wp_unslash( $_POST['key_id'] ) ) : 0;
				if ( ! wcusage_api_can_manage_key( $key_id ) ) {
					$redirect = wcusage_api_admin_error_redirect( __( 'You do not have permission to manage this API key.', 'woo-coupon-usage' ), $redirect );
					break;
				}
				wcusage_api_delete_key( $key_id );
				$redirect = add_query_arg( 'wcusage_api_notice', 'key_deleted', $redirect );
				break;

			case 'add_webhook':
				$name   = isset( $_POST['webhook_name'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_name'] ) ) : '';
				$url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
				$events = isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['webhook_events'] ) ) : array();

				$result = wcusage_api_add_webhook( $name, $url, $events );

				if ( is_wp_error( $result ) ) {
					$redirect = wcusage_api_admin_error_redirect( $result->get_error_message(), $redirect );
				} else {
					$redirect = add_query_arg( 'wcusage_api_notice', 'webhook_added', $redirect );
				}
				break;

			case 'delete_webhook':
				$webhook_id = isset( $_POST['webhook_id'] ) ? absint( wp_unslash( $_POST['webhook_id'] ) ) : 0;
				wcusage_api_delete_webhook( $webhook_id );
				$redirect = add_query_arg( 'wcusage_api_notice', 'webhook_deleted', $redirect );
				break;

			case 'toggle_webhook':
				$webhook_id = isset( $_POST['webhook_id'] ) ? absint( wp_unslash( $_POST['webhook_id'] ) ) : 0;
				$webhooks   = wcusage_api_get_webhooks();
				if ( isset( $webhooks[ $webhook_id ] ) ) {
					$new_status = ( 'active' === $webhooks[ $webhook_id ]['status'] ) ? 'disabled' : 'active';
					$fields     = array( 'status' => $new_status );
					if ( 'active' === $new_status ) {
						$fields['failures']   = 0;
						$fields['last_error'] = '';
					}
					wcusage_api_update_webhook( $webhook_id, $fields );
				}
				$redirect = add_query_arg( 'wcusage_api_notice', 'webhook_updated', $redirect );
				break;

			case 'test_webhook':
				$webhook_id = isset( $_POST['webhook_id'] ) ? absint( wp_unslash( $_POST['webhook_id'] ) ) : 0;
				$result     = wcusage_api_webhook_send_test( $webhook_id );
				if ( is_wp_error( $result ) ) {
					$redirect = wcusage_api_admin_error_redirect( $result->get_error_message(), $redirect );
				} else {
					$redirect = add_query_arg( 'wcusage_api_notice', rawurlencode( 'webhook_test_' . $result['code'] ), $redirect );
				}
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}
	add_action( 'admin_init', 'wcusage_api_admin_handle_post' );
}

if ( ! function_exists( 'wcusage_api_admin_enqueue' ) ) {
	/**
	 * Load the page stylesheet only on the API screen.
	 *
	 * @param string $hook_suffix Screen the assets are being loaded for.
	 */
	function wcusage_api_admin_enqueue( $hook_suffix = '' ) {

		$page_hook = wcusage_api_admin_page_hook();

		if ( '' === $page_hook || $hook_suffix !== $page_hook ) {
			return;
		}

		// Same handle the other admin screens use, so it loads only once.
		wp_enqueue_style( 'wcusage-font-awesome', WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css', array(), '5.15.4' );

		$path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-api.css';
		wp_enqueue_style(
			'wcusage-admin-api',
			WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-api.css',
			array( 'wcusage-font-awesome' ),
			file_exists( $path ) ? filemtime( $path ) : WCUSAGE_VERSION
		);
	}
	add_action( 'admin_enqueue_scripts', 'wcusage_api_admin_enqueue' );
}

if ( ! function_exists( 'wcusage_api_admin_copy_field' ) ) {
	/**
	 * A read-only value with a copy button.
	 *
	 * @param string $label Field label.
	 * @param string $value Value to show and copy.
	 * @param string $link  Optional URL to also link the value to.
	 */
	function wcusage_api_admin_copy_field( $label, $value, $link = '' ) {
		?>
		<div class="wcu-api-copy">
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( $link ) : ?>
				<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $value ); ?></code></a>
			<?php else : ?>
				<code><?php echo esc_html( $value ); ?></code>
			<?php endif; ?>
			<button type="button" class="button button-small wcu-api-copy-btn" data-copy="<?php echo esc_attr( $value ); ?>">
				<i class="fas fa-copy" aria-hidden="true"></i> <?php esc_html_e( 'Copy', 'woo-coupon-usage' ); ?>
			</button>
			<span class="wcu-api-copied"><?php esc_html_e( 'Copied', 'woo-coupon-usage' ); ?></span>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wcusage_api_admin_endpoint_meta' ) ) {
	/**
	 * Human descriptions and access level for each endpoint.
	 *
	 * Only the wording lives here - the paths and methods themselves are read
	 * from the registered routes, so this list cannot fall out of step with
	 * what the API actually serves.
	 *
	 * "access" is who can reach it: "admin" for the whole store, "affiliate"
	 * where an affiliate can call it for their own coupons, "any" for
	 * anything an authenticated user may call.
	 *
	 * @return array path => array( label, access ).
	 */
	function wcusage_api_admin_endpoint_meta() {

		$meta = array(
			'/me'                        => array( __( 'Identify the caller, their access level and scopes.', 'woo-coupon-usage' ), 'any' ),
			'/affiliates'                => array( __( 'List affiliates with their coupons and balances.', 'woo-coupon-usage' ), 'admin' ),
			'/affiliates/{id}'           => array( __( 'One affiliate, with profile fields and groups.', 'woo-coupon-usage' ), 'affiliate' ),
			'/affiliates/{id}/stats'     => array( __( 'Totals across all of an affiliate\'s coupons.', 'woo-coupon-usage' ), 'affiliate' ),
			'/coupons'                   => array( __( 'List affiliate coupons.', 'woo-coupon-usage' ), 'admin' ),
			'/coupons/{id}'              => array( __( 'One coupon, with commission rates and referral URL.', 'woo-coupon-usage' ), 'affiliate' ),
			'/coupons/{id}/stats'        => array( __( 'Sales and commission for a coupon, all-time or by date range.', 'woo-coupon-usage' ), 'affiliate' ),
			'/coupons/{id}/orders'       => array( __( 'Orders referred by a coupon, with commission per order.', 'woo-coupon-usage' ), 'affiliate' ),
			'/registrations'             => array( __( 'List affiliate applications.', 'woo-coupon-usage' ), 'admin' ),
			'/registrations/{id}'        => array( __( 'A single application.', 'woo-coupon-usage' ), 'admin' ),
			'/registrations/{id}/status' => array( __( 'Approve or decline an application.', 'woo-coupon-usage' ), 'admin' ),
			'/clicks/stats'              => array( __( 'Referral link clicks, conversions and conversion rate.', 'woo-coupon-usage' ), 'affiliate' ),
			'/events'                    => array( __( 'Change feed of program activity, with a cursor for polling.', 'woo-coupon-usage' ), 'admin' ),
			'/reports/summary'           => array( __( 'Store-wide totals and top affiliates.', 'woo-coupon-usage' ), 'admin' ),
			'/keys'                      => array( __( 'List or create API keys.', 'woo-coupon-usage' ), 'admin' ),
			'/keys/{id}'                 => array( __( 'Revoke an API key.', 'woo-coupon-usage' ), 'admin' ),
			'/openapi'                   => array( __( 'Machine-readable OpenAPI description of this API.', 'woo-coupon-usage' ), 'any' ),
		);

		// Payouts and webhooks are PRO add-ons. Their wording lives here rather
		// than behind is__premium_only(), which strips its contents out of the
		// free build entirely: the free version still lists these endpoints, in
		// a locked state, so it is clear what upgrading adds.
		$meta['/payouts']             = array( __( 'List payouts, or request one for a coupon\'s unpaid balance.', 'woo-coupon-usage' ), 'affiliate' );
		$meta['/payouts/{id}']        = array( __( 'A single payout.', 'woo-coupon-usage' ), 'affiliate' );
		$meta['/payouts/{id}/status'] = array( __( 'Change a payout status. Bookkeeping only - no gateway is called.', 'woo-coupon-usage' ), 'admin' );

		$meta['/webhooks']           = array( __( 'List or create webhook endpoints.', 'woo-coupon-usage' ), 'admin' );
		$meta['/webhooks/{id}']      = array( __( 'Update or delete a webhook.', 'woo-coupon-usage' ), 'admin' );
		$meta['/webhooks/{id}/test'] = array( __( 'Send a test delivery to a webhook.', 'woo-coupon-usage' ), 'admin' );
		$meta['/webhooks/events']    = array( __( 'The catalog of subscribable events.', 'woo-coupon-usage' ), 'admin' );

		return $meta;
	}
}

if ( ! function_exists( 'wcusage_api_admin_is_pro_build' ) ) {
	/**
	 * Whether this is the PRO build of the plugin.
	 *
	 * Deliberately is__premium_only() - the build marker - and NOT
	 * can_use_premium_code(). The payouts controller and the webhook routes are
	 * compiled out of the free build, so the build is what decides whether
	 * those endpoints can exist at all. On a PRO build whose licence has
	 * lapsed they stay registered and keep answering requests, so locking them
	 * on this screen would describe an API that is still serving them. Nothing
	 * on a PRO install is ever locked here, licensed or not.
	 *
	 * @return bool
	 */
	function wcusage_api_admin_is_pro_build() {
		return (bool) wcu_fs()->is__premium_only();
	}
}

if ( ! function_exists( 'wcusage_api_admin_pro_endpoints' ) ) {
	/**
	 * The endpoints only the PRO add-ons register, and the methods each serves.
	 *
	 * In the free build these routes are never registered, so they cannot be
	 * discovered from the route table the way every other row on this screen
	 * is. They are listed here so the endpoint table can still show them,
	 * greyed out and locked, instead of pretending the API is smaller than it
	 * is. Keep the methods in step with the payouts controller and the webhook
	 * routes in the manage controller.
	 *
	 * @return array path => array of HTTP methods.
	 */
	function wcusage_api_admin_pro_endpoints() {
		return array(
			'/payouts'             => array( 'GET', 'POST' ),
			'/payouts/{id}'        => array( 'GET' ),
			'/payouts/{id}/status' => array( 'POST' ),
			'/webhooks'            => array( 'GET', 'POST' ),
			'/webhooks/{id}'       => array( 'DELETE', 'PATCH' ),
			'/webhooks/{id}/test'  => array( 'POST' ),
			'/webhooks/events'     => array( 'GET' ),
		);
	}
}

if ( ! function_exists( 'wcusage_api_admin_get_endpoints' ) ) {
	/**
	 * Build the endpoint list from the routes that are actually registered.
	 *
	 * One row per method, because the same path can need different scopes
	 * depending on how it is called - GET /payouts only needs "read" while
	 * POST /payouts needs "write".
	 *
	 * @return array[] Each: path, method, label, access, scope, url.
	 */
	function wcusage_api_admin_get_endpoints() {

		$meta      = wcusage_api_admin_endpoint_meta();
		$endpoints = array();

		// Whether this is the PRO build. Anything in wcusage_api_admin_pro_endpoints()
		// is locked only in the free build, where those routes are compiled out.
		$is_pro_build = wcusage_api_admin_is_pro_build();
		$pro_paths    = wcusage_api_admin_pro_endpoints();

		// Instantiating the server fires rest_api_init, which is what
		// registers the routes; outside a REST request they do not exist yet.
		//
		// The disabled-endpoint filter is lifted for this listing only:
		// otherwise a switched-off endpoint would vanish from this page and
		// there would be no way to switch it back on.
		$had_filter = has_filter( 'rest_endpoints', 'wcusage_api_filter_disabled_endpoints' );
		if ( $had_filter ) {
			remove_filter( 'rest_endpoints', 'wcusage_api_filter_disabled_endpoints' );
		}

		$routes = rest_get_server()->get_routes();

		// With the API switched off nothing registered on rest_api_init, so the
		// route table holds no v2 routes at all - and this is the screen an
		// administrator opens to turn it on, where an empty endpoint list is
		// exactly the wrong first impression. Register them for this request
		// only; nothing dispatches a REST route during an admin page render, so
		// the API stays off.
		if ( ! wcusage_api_v2_is_enabled() && function_exists( 'wcusage_api_v2_do_register_routes' ) ) {
			wcusage_api_v2_do_register_routes();
			$routes = rest_get_server()->get_routes();
		}

		if ( $had_filter ) {
			add_filter( 'rest_endpoints', 'wcusage_api_filter_disabled_endpoints' );
		}

		foreach ( $routes as $route => $handlers ) {

			if ( 0 !== strpos( $route, '/wcusage/v2' ) ) {
				continue;
			}

			$path = preg_replace( '/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', $route );
			$path = substr( $path, strlen( '/wcusage/v2' ) );

			// The namespace index itself is not an endpoint worth listing.
			if ( '' === $path || '/' === $path ) {
				continue;
			}

			$methods = array();
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
					continue;
				}
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					$method = strtoupper( $method );
					if ( 'HEAD' === $method ) {
						continue;
					}
					$methods[ $method ] = true;
				}
			}

			if ( empty( $methods ) ) {
				continue;
			}

			$methods = array_keys( $methods );
			sort( $methods );

			// Mirrors the permission callbacks: the routes that administer the
			// API itself require "manage" whatever the method, the event
			// catalog is a plain read, and everything else needs "read" to
			// look and "write" to change something.
			$manages_api = ( 0 === strpos( $path, '/keys' ) )
				|| ( 0 === strpos( $path, '/webhooks' ) && '/webhooks/events' !== $path );

			foreach ( $methods as $method ) {

				if ( $manages_api ) {
					$scope = 'manage';
				} elseif ( 'GET' === $method ) {
					$scope = 'read';
				} else {
					$scope = 'write';
				}

				// A PRO path in the free build: list it, but locked. Belt and braces
				// against the route somehow being registered there - the handlers
				// behind it would call functions the free build does not ship.
				$locked = ( ! $is_pro_build && isset( $pro_paths[ $path ] ) );

				$endpoints[] = array(
					'path'    => $path,
					'method'  => $method,
					'label'   => isset( $meta[ $path ][0] ) ? $meta[ $path ][0] : '',
					'access'  => isset( $meta[ $path ][1] ) ? $meta[ $path ][1] : 'admin',
					'scope'   => $scope,
					'enabled' => ( ! $locked && wcusage_api_endpoint_is_enabled( $path ) ),
					'locked'  => $locked,
					// Only a GET on a path with no placeholder can be opened.
					'url'     => ( ! $locked && 'GET' === $method && false === strpos( $path, '{' ) )
						? rest_url( 'wcusage/v2' . $path )
						: '',
				);
			}
		}

		// Anything the PRO add-ons would register but did not: list it locked
		// rather than leaving a hole in the table. Only in the free build - on PRO
		// an absent route means it was deliberately not registered, and inventing
		// a row for it would be a lie.
		$registered = wp_list_pluck( $endpoints, 'path' );
		foreach ( ( $is_pro_build ? array() : $pro_paths ) as $path => $methods ) {

			if ( in_array( $path, $registered, true ) ) {
				continue;
			}

			$manages_api = ( 0 === strpos( $path, '/webhooks' ) && '/webhooks/events' !== $path );

			foreach ( $methods as $method ) {

				if ( $manages_api ) {
					$scope = 'manage';
				} elseif ( 'GET' === $method ) {
					$scope = 'read';
				} else {
					$scope = 'write';
				}

				$endpoints[] = array(
					'path'   => $path,
					'method' => $method,
					'label'  => isset( $meta[ $path ][0] ) ? $meta[ $path ][0] : '',
					'access' => isset( $meta[ $path ][1] ) ? $meta[ $path ][1] : 'admin',
					'scope'  => $scope,
					// Not registered, so it cannot be switched on or called.
					'enabled' => false,
					'locked'  => true,
					'url'     => '',
				);
			}
		}

		usort(
			$endpoints,
			function ( $a, $b ) {
				$by_path = strcmp( $a['path'], $b['path'] );
				return ( 0 !== $by_path ) ? $by_path : strcmp( $a['method'], $b['method'] );
			}
		);

		return $endpoints;
	}
}

if ( ! function_exists( 'wcusage_api_admin_page_render' ) ) {
	/**
	 * Render the API admin page.
	 */
	function wcusage_api_admin_page_render() {

		if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-coupon-usage' ) );
		}

		$base_url  = untrailingslashit( rest_url( 'wcusage/v2' ) );
		$new_token = get_transient( 'wcusage_api_new_token_' . get_current_user_id() );
		if ( $new_token ) {
			delete_transient( 'wcusage_api_new_token_' . get_current_user_id() );
		}

		$notice = isset( $_GET['wcusage_api_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wcusage_api_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Read from the transient the previous request left, never from the
		// URL - see wcusage_api_admin_error_redirect(). Deleted on read, so a
		// reload does not repeat it.
		$error = (string) get_transient( 'wcusage_api_admin_error_' . get_current_user_id() );
		if ( '' !== $error ) {
			delete_transient( 'wcusage_api_admin_error_' . get_current_user_id() );
		}

		// Every key, not the default page: this screen is the only place a key
		// can be revoked, so one that does not appear here cannot be turned off.
		$keys = wcusage_api_get_keys( max( 1, wcusage_api_count_keys() ) );

		// PRO add-on: without it there is no registry to read and no card to
		// render, so everything webhook-shaped on this screen stays empty and the
		// locked placeholder card is shown instead.
		$has_webhooks = wcusage_api_webhooks_available();
		$webhooks     = $has_webhooks ? wcusage_api_get_webhooks() : array();

		$active_keys = 0;
		foreach ( $keys as $key ) {
			if ( 'active' === $key['status'] ) {
				++$active_keys;
			}
		}
		$active_hooks = 0;
		foreach ( $webhooks as $webhook ) {
			if ( 'active' === $webhook['status'] ) {
				++$active_hooks;
			}
		}

		$api_enabled = wcusage_api_v2_is_enabled();
		?>

		<div class="wrap wcusage-admin-page wcusage-api-page">

			<?php do_action( 'wcusage_hook_dashboard_page_header', '' ); ?>

			<div class="wcu-page-header">
				<div class="wcu-page-header-text">
					<h1><i class="fas fa-plug" aria-hidden="true"></i><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p class="wcu-page-subtitle"><?php esc_html_e( 'Connect external tools, dashboards and AI assistants to your affiliate program.', 'woo-coupon-usage' ); ?></p>
				</div>
				<p class="wcu-api-back"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_tools' ) ); ?>"><?php esc_html_e( 'Go back to tools', 'woo-coupon-usage' ); ?> &gt;</a></p>
			</div>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php elseif ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wcusage_api_admin_notice_text( $notice ) ); ?></p></div>
			<?php endif; ?>

			<div class="wcu-api-card wcu-api-switch <?php echo $api_enabled ? 'is-on' : 'is-off'; ?>">
				<div class="wcu-api-switch-inner">
					<div class="wcu-api-switch-text">
						<h2>
							<i class="fas <?php echo $api_enabled ? 'fa-circle-check' : 'fa-circle-pause'; ?>" aria-hidden="true"></i>
							<?php esc_html_e( 'REST API', 'woo-coupon-usage' ); ?>
							<span class="wcu-api-badge wcu-api-badge-<?php echo $api_enabled ? 'active' : 'inactive'; ?>">
								<?php echo $api_enabled ? esc_html__( 'Enabled', 'woo-coupon-usage' ) : esc_html__( 'Disabled', 'woo-coupon-usage' ); ?>
							</span>
						</h2>
						<p>
							<?php
							// Without the webhooks add-on there is nothing being delivered, so
							// the wording drops the webhook half rather than describing a
							// feature this build does not have.
							if ( $api_enabled && $has_webhooks ) {
								esc_html_e( 'The API is answering requests and webhooks are being delivered. Anyone with a valid API key or application password can reach the endpoints their account allows.', 'woo-coupon-usage' );
							} elseif ( $api_enabled ) {
								esc_html_e( 'The API is answering requests. Anyone with a valid API key or application password can reach the endpoints their account allows.', 'woo-coupon-usage' );
							} elseif ( $has_webhooks ) {
								esc_html_e( 'The API is switched off: every request to it is refused and no webhooks are delivered. Turn it on when you are ready to connect an external tool. Keys and webhooks can still be set up and tested below.', 'woo-coupon-usage' );
							} else {
								esc_html_e( 'The API is switched off: every request to it is refused. Turn it on when you are ready to connect an external tool. Keys can still be set up below.', 'woo-coupon-usage' );
							}
							?>
						</p>
					</div>
					<div class="wcu-api-switch-action">
						<form method="post">
							<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
							<input type="hidden" name="api_enabled" value="<?php echo $api_enabled ? '0' : '1'; ?>"/>
							<button class="button <?php echo $api_enabled ? '' : 'button-primary'; ?>" name="wcusage_api_admin_action" value="toggle_api"
								<?php if ( $api_enabled ) : ?>
									onclick="return confirm('<?php echo esc_js( __( 'Disable the API? Anything currently connected to it will stop working, and no further webhooks will be delivered.', 'woo-coupon-usage' ) ); ?>');"
								<?php endif; ?>
							>
								<?php echo $api_enabled ? esc_html__( 'Disable API', 'woo-coupon-usage' ) : esc_html__( 'Enable API', 'woo-coupon-usage' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>

			<?php if ( $new_token ) : ?>
				<div class="wcu-api-token">
					<strong><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?php esc_html_e( 'Copy your new API key now - it cannot be shown again.', 'woo-coupon-usage' ); ?></strong>
					<div class="wcu-api-token-value">
						<code><?php echo esc_html( $new_token ); ?></code>
						<button type="button" class="button button-primary wcu-api-copy-btn" data-copy="<?php echo esc_attr( $new_token ); ?>">
							<i class="fas fa-copy" aria-hidden="true"></i> <?php esc_html_e( 'Copy key', 'woo-coupon-usage' ); ?>
						</button>
						<span class="wcu-api-copied"><?php esc_html_e( 'Copied', 'woo-coupon-usage' ); ?></span>
					</div>
				</div>
			<?php endif; ?>

			<div class="wcu-api-summary">
				<div class="wcu-api-stat">
					<i class="fas fa-key" aria-hidden="true"></i>
					<div>
						<div class="wcu-api-stat-value"><?php echo (int) $active_keys; ?></div>
						<div class="wcu-api-stat-label"><?php esc_html_e( 'Active keys', 'woo-coupon-usage' ); ?></div>
					</div>
				</div>
				<?php
				// Without the PRO add-on there are no webhooks to count, but the tiles
				// still render - greyed out, showing a zero - so the free version shows
				// the whole shape of the feature rather than a gap.
				$hooks_class = $has_webhooks ? 'wcu-api-stat' : 'wcu-api-stat wcu-api-stat-pro';
				?>
				<div class="<?php echo esc_attr( $hooks_class ); ?>">
					<i class="fas fa-bolt" aria-hidden="true"></i>
					<div>
						<div class="wcu-api-stat-value"><?php echo (int) $active_hooks; ?></div>
						<div class="wcu-api-stat-label">
							<?php esc_html_e( 'Active webhooks', 'woo-coupon-usage' ); ?>
						</div>
					</div>
				</div>
				<div class="<?php echo esc_attr( $hooks_class ); ?>">
					<i class="fas fa-diagram-project" aria-hidden="true"></i>
					<div>
						<div class="wcu-api-stat-value"><?php echo $has_webhooks ? (int) count( wcusage_api_webhook_event_names() ) : 0; ?></div>
						<div class="wcu-api-stat-label">
							<?php esc_html_e( 'Webhook events', 'woo-coupon-usage' ); ?>
						</div>
					</div>
				</div>
				<div class="wcu-api-stat">
					<i class="fas fa-circle-check" aria-hidden="true"></i>
					<div>
						<div class="wcu-api-stat-value"><?php echo $api_enabled ? esc_html__( 'Enabled', 'woo-coupon-usage' ) : esc_html__( 'Disabled', 'woo-coupon-usage' ); ?></div>
						<div class="wcu-api-stat-label"><?php esc_html_e( 'API status', 'woo-coupon-usage' ); ?></div>
					</div>
				</div>
			</div>

			<div class="wcu-api-card">
				<div class="wcu-api-card-head">
					<h2><i class="fas fa-circle-info" aria-hidden="true"></i><?php esc_html_e( 'Getting started', 'woo-coupon-usage' ); ?></h2>
					<a class="button" href="https://couponaffiliates.com/api-docs/?utm_campaign=plugin&amp;utm_source=api-page&amp;utm_medium=textlink" target="_blank" rel="noopener noreferrer">
						<i class="fas fa-book" aria-hidden="true"></i> <?php esc_html_e( 'API documentation', 'woo-coupon-usage' ); ?>
					</a>
				</div>
				<div class="wcu-api-card-body">
					<?php
					wcusage_api_admin_copy_field( __( 'Base URL', 'woo-coupon-usage' ), $base_url );
					wcusage_api_admin_copy_field( __( 'OpenAPI', 'woo-coupon-usage' ), $base_url . '/openapi', $base_url . '/openapi' );
					?>
					<p>
						<?php esc_html_e( 'Authenticate with a WordPress application password, or with an API key below sent as an "Authorization: Bearer" header. Administrators can read everything; affiliates only ever see their own coupons, stats and payouts.', 'woo-coupon-usage' ); ?>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: link to the online API documentation */
							esc_html__( 'Full reference, request examples and webhook payloads: %s', 'woo-coupon-usage' ),
							'<a href="https://couponaffiliates.com/api-docs/?utm_campaign=plugin&amp;utm_source=api-page&amp;utm_medium=textlink" target="_blank" rel="noopener noreferrer">' . esc_html__( 'API documentation', 'woo-coupon-usage' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<?php $endpoints = wcusage_api_admin_get_endpoints(); ?>

			<div class="wcu-api-card">
				<div class="wcu-api-card-head">
					<h2><i class="fas fa-list" aria-hidden="true"></i><?php esc_html_e( 'Available endpoints', 'woo-coupon-usage' ); ?></h2>
					<div class="wcu-api-head-tools">
						<span class="wcu-api-count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of API endpoints */
									_n( '%d endpoint', '%d endpoints', count( $endpoints ), 'woo-coupon-usage' ),
									count( $endpoints )
								)
							);
							?>
						</span>
						<input type="search" id="wcu-api-endpoint-filter" class="wcu-api-filter" placeholder="<?php esc_attr_e( 'Filter endpoints…', 'woo-coupon-usage' ); ?>" aria-label="<?php esc_attr_e( 'Filter endpoints', 'woo-coupon-usage' ); ?>"/>
					</div>
				</div>

				<form method="post">
				<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>

				<table class="wcu-api-table wcu-api-endpoint-table">
					<thead>
						<tr>
							<th class="wcu-api-enable-col"><?php esc_html_e( 'On', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Method', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Description', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Who can call it', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Scope', 'woo-coupon-usage' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$access_labels = array(
							'admin'     => __( 'Admins', 'woo-coupon-usage' ),
							'affiliate' => __( 'Admins + the affiliate', 'woo-coupon-usage' ),
							'any'       => __( 'Any logged-in user', 'woo-coupon-usage' ),
						);
						$seen_paths    = array();
						foreach ( $endpoints as $endpoint ) :
							// One checkbox per path: the toggle applies to the
							// endpoint, not to each method separately.
							$first_of_path                   = ! isset( $seen_paths[ $endpoint['path'] ] );
							$seen_paths[ $endpoint['path'] ] = true;
							?>
							<?php
							// A locked row is a PRO endpoint this build cannot serve: shown so
							// the list stays complete, but faded, with its toggle and actions
							// disabled since there is nothing behind it to call.
							$row_locked  = ! empty( $endpoint['locked'] );
							$row_classes = array();
							if ( ! $endpoint['enabled'] ) {
								$row_classes[] = 'wcu-api-row-off';
							}
							if ( $row_locked ) {
								$row_classes[] = 'wcu-api-row-pro';
							}
							?>
							<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-search="<?php echo esc_attr( strtolower( $endpoint['method'] . ' ' . $endpoint['path'] . ' ' . $endpoint['label'] . ' ' . $endpoint['scope'] ) ); ?>">
								<td class="wcu-api-enable-col">
									<?php if ( $first_of_path ) : ?>
										<input type="checkbox" name="api_endpoints[]" value="<?php echo esc_attr( $endpoint['path'] ); ?>" <?php checked( $endpoint['enabled'] ); ?> <?php disabled( $row_locked ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: endpoint path */ __( 'Enable %s', 'woo-coupon-usage' ), $endpoint['path'] ) ); ?>"/>
									<?php endif; ?>
								</td>
								<td class="wcu-api-methods">
									<span class="wcu-api-method wcu-api-method-<?php echo esc_attr( strtolower( $endpoint['method'] ) ); ?>"><?php echo esc_html( $endpoint['method'] ); ?></span>
								</td>
								<td>
									<code><?php echo esc_html( $endpoint['path'] ); ?></code>
									<?php if ( $row_locked ) : ?>
										<span class="wcu-api-badge wcu-api-badge-pro"><i class="fas fa-lock" aria-hidden="true"></i> <?php esc_html_e( 'PRO', 'woo-coupon-usage' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wcu-api-desc"><?php echo esc_html( $endpoint['label'] ); ?></td>
								<td>
									<span class="wcu-api-badge wcu-api-badge-<?php echo ( 'admin' === $endpoint['access'] ) ? 'inactive' : 'active'; ?>">
										<?php echo esc_html( isset( $access_labels[ $endpoint['access'] ] ) ? $access_labels[ $endpoint['access'] ] : $endpoint['access'] ); ?>
									</span>
								</td>
								<td><span class="wcu-api-scope"><?php echo esc_html( $endpoint['scope'] ); ?></span></td>
								<td class="wcu-api-actions">
									<?php if ( ! $row_locked ) : ?>
										<button type="button" class="button button-small wcu-api-copy-btn" data-copy="<?php echo esc_attr( $base_url . $endpoint['path'] ); ?>" title="<?php esc_attr_e( 'Copy full URL', 'woo-coupon-usage' ); ?>">
											<i class="fas fa-copy" aria-hidden="true"></i>
										</button>
										<span class="wcu-api-copied"><?php esc_html_e( 'Copied', 'woo-coupon-usage' ); ?></span>
										<?php if ( $endpoint['url'] ) : ?>
											<?php
											// A REST nonce makes the link work straight from the
											// browser using the current admin's cookie session.
											$try_url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $endpoint['url'] );
											?>
											<a class="button button-small" href="<?php echo esc_url( $try_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Open this endpoint in a new tab', 'woo-coupon-usage' ); ?>">
												<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
											</a>
										<?php endif; ?>
									<?php else : ?>
										<button type="button" class="button button-small" disabled="disabled" title="<?php esc_attr_e( 'Available in Coupon Affiliates PRO', 'woo-coupon-usage' ); ?>">
											<i class="fas fa-lock" aria-hidden="true"></i>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr class="wcu-api-no-match" style="display:none;">
							<td colspan="7"><?php esc_html_e( 'No endpoints match that filter.', 'woo-coupon-usage' ); ?></td>
						</tr>
					</tbody>
				</table>

				<div class="wcu-api-form-body wcu-api-endpoint-save">
					<button class="button button-primary" name="wcusage_api_admin_action" value="save_endpoints"><?php esc_html_e( 'Save endpoint settings', 'woo-coupon-usage' ); ?></button>
					<button type="button" class="button" id="wcu-api-select-all"><?php esc_html_e( 'Enable all', 'woo-coupon-usage' ); ?></button>
					<button type="button" class="button" id="wcu-api-select-none"><?php esc_html_e( 'Disable all', 'woo-coupon-usage' ); ?></button>
					<p class="description"><?php esc_html_e( 'A disabled endpoint does not exist as far as the API is concerned - requests to it return "no route found". New endpoints added in future updates are enabled automatically.', 'woo-coupon-usage' ); ?></p>
				</div>
				</form>
			</div>

			<div class="wcu-api-card">
				<div class="wcu-api-card-head">
					<h2><i class="fas fa-key" aria-hidden="true"></i><?php esc_html_e( 'API Keys', 'woo-coupon-usage' ); ?></h2>
				</div>

				<?php if ( empty( $keys ) ) : ?>
					<div class="wcu-api-empty">
						<i class="fas fa-key" aria-hidden="true"></i>
						<?php esc_html_e( 'No API keys yet. Create one below to connect an external tool.', 'woo-coupon-usage' ); ?>
					</div>
				<?php else : ?>
					<table class="wcu-api-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Description', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Key', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'User', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Scopes', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Status', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'woo-coupon-usage' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $keys as $key ) :
								$key_user = get_userdata( $key['user_id'] );
								?>
								<tr>
									<td><strong><?php echo $key['description'] ? esc_html( $key['description'] ) : esc_html__( '(no description)', 'woo-coupon-usage' ); ?></strong></td>
									<td><code><?php echo esc_html( $key['key_prefix'] ); ?>&hellip;</code></td>
									<td>
										<?php if ( $key_user ) : ?>
											<a href="<?php echo esc_url( get_edit_user_link( $key['user_id'] ) ); ?>"><?php echo esc_html( $key_user->user_login ); ?></a>
										<?php else : ?>
											<span class="wcu-api-badge wcu-api-badge-error"><?php esc_html_e( 'deleted user', 'woo-coupon-usage' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php foreach ( $key['scopes'] as $scope ) : ?>
											<span class="wcu-api-scope"><?php echo esc_html( $scope ); ?></span>
										<?php endforeach; ?>
									</td>
									<td>
										<span class="wcu-api-badge wcu-api-badge-<?php echo ( 'active' === $key['status'] ) ? 'active' : 'inactive'; ?>">
											<?php echo esc_html( $key['status'] ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $key['last_used'] ? $key['last_used'] : '—' ); ?></td>
									<td><?php echo esc_html( $key['date_expires'] ? $key['date_expires'] : '—' ); ?></td>
									<td class="wcu-api-actions">
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
											<input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>"/>
											<?php if ( 'active' === $key['status'] ) : ?>
												<button class="button button-small" name="wcusage_api_admin_action" value="revoke_key" onclick="return confirm('<?php echo esc_js( __( 'Revoke this API key? Anything using it will stop working immediately.', 'woo-coupon-usage' ) ); ?>');"><?php esc_html_e( 'Revoke', 'woo-coupon-usage' ); ?></button>
											<?php else : ?>
												<button class="button button-small" name="wcusage_api_admin_action" value="delete_key" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this API key?', 'woo-coupon-usage' ) ); ?>');"><?php esc_html_e( 'Delete', 'woo-coupon-usage' ); ?></button>
											<?php endif; ?>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<details class="wcu-api-form">
					<summary><?php esc_html_e( 'Create API key', 'woo-coupon-usage' ); ?></summary>
					<div class="wcu-api-form-body">
						<form method="post">
							<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="key_description"><?php esc_html_e( 'Description', 'woo-coupon-usage' ); ?></label></th>
									<td><input type="text" class="regular-text" id="key_description" name="key_description" placeholder="<?php esc_attr_e( 'e.g. Zapier integration', 'woo-coupon-usage' ); ?>"/></td>
								</tr>
								<tr>
									<th scope="row"><label for="key_user_id"><?php esc_html_e( 'Acts as user ID', 'woo-coupon-usage' ); ?></label></th>
									<td>
										<input type="number" id="key_user_id" name="key_user_id" min="1" value="<?php echo (int) get_current_user_id(); ?>"/>
										<p class="description"><?php esc_html_e( 'The key inherits this user\'s permissions. Use an affiliate\'s user ID for a key limited to their own data.', 'woo-coupon-usage' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Scopes', 'woo-coupon-usage' ); ?></th>
									<td>
										<div class="wcu-api-checklist">
											<?php foreach ( wcusage_api_allowed_scopes() as $scope => $description ) : ?>
												<label>
													<input type="checkbox" name="key_scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( 'read' === $scope ); ?>/>
													<code><?php echo esc_html( $scope ); ?></code> <?php echo esc_html( $description ); ?>
												</label>
											<?php endforeach; ?>
										</div>
										<p class="description"><?php esc_html_e( 'Give AI tools and third-party services the "read" scope only.', 'woo-coupon-usage' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="key_expires"><?php esc_html_e( 'Expires', 'woo-coupon-usage' ); ?></label></th>
									<td>
										<input type="date" id="key_expires" name="key_expires"/>
										<p class="description"><?php esc_html_e( 'Optional. The key stops working after this date.', 'woo-coupon-usage' ); ?></p>
									</td>
								</tr>
							</table>
							<p><button class="button button-primary" name="wcusage_api_admin_action" value="create_key"><?php esc_html_e( 'Create API key', 'woo-coupon-usage' ); ?></button></p>
						</form>
					</div>
				</details>
			</div>

			<?php if ( $has_webhooks ) : ?>

			<div class="wcu-api-card">
				<div class="wcu-api-card-head">
					<h2><i class="fas fa-bolt" aria-hidden="true"></i><?php esc_html_e( 'Webhooks', 'woo-coupon-usage' ); ?></h2>
				</div>

				<div class="wcu-api-card-body" style="padding-bottom:0;">
					<p><?php esc_html_e( 'Send a signed JSON notification to another service whenever something happens - a new referral, commission, registration or payout.', 'woo-coupon-usage' ); ?></p>
					<?php if ( ! $api_enabled ) : ?>
						<p class="description">
							<i class="fas fa-circle-pause" aria-hidden="true"></i>
							<?php esc_html_e( 'The API is switched off, so nothing is being delivered automatically. You can still add endpoints and use "Test" to check them; deliveries begin when you enable the API above.', 'woo-coupon-usage' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( empty( $webhooks ) ) : ?>
					<div class="wcu-api-empty">
						<i class="fas fa-bolt" aria-hidden="true"></i>
						<?php esc_html_e( 'No webhooks yet.', 'woo-coupon-usage' ); ?>
					</div>
				<?php else : ?>
					<table class="wcu-api-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Delivery URL', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Events', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Secret', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Status', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Last delivery', 'woo-coupon-usage' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $webhooks as $webhook ) : ?>
								<tr>
									<td><strong><?php echo $webhook['name'] ? esc_html( $webhook['name'] ) : esc_html__( '(unnamed)', 'woo-coupon-usage' ); ?></strong></td>
									<td><code><?php echo esc_html( $webhook['url'] ); ?></code></td>
									<td>
										<?php foreach ( (array) $webhook['events'] as $event ) : ?>
											<span class="wcu-api-scope"><?php echo esc_html( $event ); ?></span>
										<?php endforeach; ?>
									</td>
									<td>
										<?php
										// The signing secret lets its holder forge events into the
										// receiving system, so only full administrators see it in
										// clear. The plugin's admin gate can be a lower capability.
										if ( current_user_can( 'manage_options' ) ) {
											echo '<code style="user-select:all;">' . esc_html( $webhook['secret'] ) . '</code>';
										} else {
											echo '<code>' . esc_html( wcusage_api_mask( $webhook['secret'] ) ) . '</code>';
										}
										?>
									</td>
									<td>
										<span class="wcu-api-badge wcu-api-badge-<?php echo ( 'active' === $webhook['status'] ) ? 'active' : 'inactive'; ?>">
											<?php echo esc_html( $webhook['status'] ); ?>
										</span>
										<?php if ( $webhook['failures'] ) : ?>
											<br/>
											<span class="wcu-api-badge wcu-api-badge-error" title="<?php echo esc_attr( $webhook['last_error'] ); ?>">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: number of consecutive failed deliveries */
														_n( '%d failure', '%d failures', (int) $webhook['failures'], 'woo-coupon-usage' ),
														(int) $webhook['failures']
													)
												);
												?>
											</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $webhook['last_delivery'] ? $webhook['last_delivery'] : '—' ); ?></td>
									<td class="wcu-api-actions">
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
											<input type="hidden" name="webhook_id" value="<?php echo (int) $webhook['id']; ?>"/>
											<button class="button button-small" name="wcusage_api_admin_action" value="test_webhook"><?php esc_html_e( 'Test', 'woo-coupon-usage' ); ?></button>
											<button class="button button-small" name="wcusage_api_admin_action" value="toggle_webhook"><?php echo ( 'active' === $webhook['status'] ) ? esc_html__( 'Disable', 'woo-coupon-usage' ) : esc_html__( 'Enable', 'woo-coupon-usage' ); ?></button>
											<button class="button button-small" name="wcusage_api_admin_action" value="delete_webhook" onclick="return confirm('<?php echo esc_js( __( 'Delete this webhook?', 'woo-coupon-usage' ) ); ?>');"><?php esc_html_e( 'Delete', 'woo-coupon-usage' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<details class="wcu-api-form">
					<summary><?php esc_html_e( 'Add webhook', 'woo-coupon-usage' ); ?></summary>
					<div class="wcu-api-form-body">
						<form method="post">
							<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="webhook_name"><?php esc_html_e( 'Name', 'woo-coupon-usage' ); ?></label></th>
									<td><input type="text" class="regular-text" id="webhook_name" name="webhook_name" placeholder="<?php esc_attr_e( 'e.g. Slack notifications', 'woo-coupon-usage' ); ?>"/></td>
								</tr>
								<tr>
									<th scope="row"><label for="webhook_url"><?php esc_html_e( 'Delivery URL', 'woo-coupon-usage' ); ?></label></th>
									<td>
										<input type="url" class="regular-text" id="webhook_url" name="webhook_url" placeholder="https://" required/>
										<p class="description"><?php esc_html_e( 'Must be HTTPS and publicly reachable.', 'woo-coupon-usage' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Events', 'woo-coupon-usage' ); ?></th>
									<td>
										<div class="wcu-api-checklist">
											<label>
												<input type="checkbox" name="webhook_events[]" value="*"/>
												<code>*</code> <?php esc_html_e( 'All events', 'woo-coupon-usage' ); ?>
											</label>
											<?php foreach ( wcusage_api_webhook_event_names() as $event_name => $event_description ) : ?>
												<label>
													<input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $event_name ); ?>"/>
													<code><?php echo esc_html( $event_name ); ?></code> <?php echo esc_html( $event_description ); ?>
												</label>
											<?php endforeach; ?>
										</div>
									</td>
								</tr>
							</table>
							<p><button class="button button-primary" name="wcusage_api_admin_action" value="add_webhook"><?php esc_html_e( 'Add webhook', 'woo-coupon-usage' ); ?></button></p>
						</form>
					</div>
				</details>
			</div>

			<?php else : ?>

			<?php
			// Free build: the webhook registry, the event catalog and the add form all
			// live in the PRO add-on, so there is nothing real to render. The card is
			// still shown - faded and inert - so the feature is visible rather than
			// silently missing from the page.
			$wcu_api_pro_url = 'https://couponaffiliates.com/pricing?utm_campaign=plugin&utm_source=api-page&utm_medium=webhooks-locked';
			?>
			<div class="wcu-api-card wcu-api-card-pro">
				<div class="wcu-api-card-head">
					<h2>
						<i class="fas fa-bolt" aria-hidden="true"></i><?php esc_html_e( 'Webhooks', 'woo-coupon-usage' ); ?>
						<span class="wcu-api-badge wcu-api-badge-pro"><i class="fas fa-lock" aria-hidden="true"></i> <?php esc_html_e( 'PRO', 'woo-coupon-usage' ); ?></span>
					</h2>
					<a class="button" href="<?php echo esc_url( $wcu_api_pro_url ); ?>" target="_blank" rel="noopener noreferrer">
						<i class="fas fa-star" aria-hidden="true"></i> <?php esc_html_e( 'Upgrade to PRO', 'woo-coupon-usage' ); ?>
					</a>
				</div>

				<div class="wcu-api-card-body" style="padding-bottom:0;">
					<p><?php esc_html_e( 'Send a signed JSON notification to another service whenever something happens - a new referral, commission, registration or payout.', 'woo-coupon-usage' ); ?></p>
				</div>

				<div class="wcu-api-empty">
					<i class="fas fa-lock" aria-hidden="true"></i>
					<?php esc_html_e( 'Webhooks are part of Coupon Affiliates PRO.', 'woo-coupon-usage' ); ?>
				</div>

				<div class="wcu-api-form-body">
					<button type="button" class="button button-primary" disabled="disabled"><?php esc_html_e( 'Add webhook', 'woo-coupon-usage' ); ?></button>
					<p class="description"><?php esc_html_e( 'The payouts and webhook endpoints above are part of the same add-on, and are listed here so you can see what they cover.', 'woo-coupon-usage' ); ?></p>
				</div>
			</div>

			<?php endif; ?>

		</div>

		<script>
		( function () {
			document.querySelectorAll( '.wcu-api-copy-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var value = btn.getAttribute( 'data-copy' );
					var done  = btn.parentNode.querySelector( '.wcu-api-copied' );
					var show  = function () {
						if ( ! done ) { return; }
						done.style.display = 'inline';
						setTimeout( function () { done.style.display = 'none'; }, 1800 );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( value ).then( show );
						return;
					}
					var tmp = document.createElement( 'textarea' );
					tmp.value = value;
					document.body.appendChild( tmp );
					tmp.select();
					document.execCommand( 'copy' );
					document.body.removeChild( tmp );
					show();
				} );
			} );

			var setAll = function ( state ) {
				document.querySelectorAll( '.wcu-api-endpoint-table input[name="api_endpoints[]"]' ).forEach( function ( box ) {
					// Locked PRO rows have nothing behind them to switch on.
					if ( box.disabled ) { return; }
					// Only touch rows the filter is currently showing.
					var row = box.closest( 'tr' );
					if ( row && 'none' === row.style.display ) { return; }
					box.checked = state;
				} );
			};
			var all  = document.getElementById( 'wcu-api-select-all' );
			var none = document.getElementById( 'wcu-api-select-none' );
			if ( all ) { all.addEventListener( 'click', function () { setAll( true ); } ); }
			if ( none ) { none.addEventListener( 'click', function () { setAll( false ); } ); }

			var filter = document.getElementById( 'wcu-api-endpoint-filter' );
			if ( ! filter ) { return; }

			var table   = document.querySelector( '.wcu-api-endpoint-table' );
			var rows    = table ? table.querySelectorAll( 'tbody tr[data-search]' ) : [];
			var noMatch = table ? table.querySelector( '.wcu-api-no-match' ) : null;

			filter.addEventListener( 'input', function () {
				var term    = filter.value.toLowerCase().trim();
				var visible = 0;
				rows.forEach( function ( row ) {
					var hit = ! term || row.getAttribute( 'data-search' ).indexOf( term ) !== -1;
					row.style.display = hit ? '' : 'none';
					if ( hit ) { visible++; }
				} );
				if ( noMatch ) {
					noMatch.style.display = visible ? 'none' : '';
				}
			} );
		}() );
		</script>
		<?php
	}
}

if ( ! function_exists( 'wcusage_api_admin_notice_text' ) ) {
	/**
	 * Map notice slugs to text.
	 *
	 * @param string $notice Notice slug.
	 *
	 * @return string
	 */
	function wcusage_api_admin_notice_text( $notice ) {

		if ( 0 === strpos( $notice, 'webhook_test_' ) ) {
			$code = (int) substr( $notice, strlen( 'webhook_test_' ) );
			/* translators: %d: HTTP status code */
			return sprintf( __( 'Test delivery sent. The endpoint responded with HTTP %d.', 'woo-coupon-usage' ), $code );
		}

		$map = array(
			// Only reached when the message transient the redirect wrote has
			// already expired; normally the error branch renders instead.
			'error'           => __( 'Something went wrong. Please try again.', 'woo-coupon-usage' ),
			'api_enabled'     => __( 'The API is now enabled.', 'woo-coupon-usage' ),
			'api_disabled'    => __( 'The API is now disabled. Requests to it will be refused.', 'woo-coupon-usage' ),
			'endpoints_saved' => __( 'Endpoint settings saved.', 'woo-coupon-usage' ),
			'key_created'     => __( 'API key created.', 'woo-coupon-usage' ),
			'key_revoked'     => __( 'API key revoked.', 'woo-coupon-usage' ),
			'key_deleted'     => __( 'API key deleted.', 'woo-coupon-usage' ),
			'webhook_added'   => __( 'Webhook added.', 'woo-coupon-usage' ),
			'webhook_deleted' => __( 'Webhook deleted.', 'woo-coupon-usage' ),
			'webhook_updated' => __( 'Webhook updated.', 'woo-coupon-usage' ),
		);

		return isset( $map[ $notice ] ) ? $map[ $notice ] : __( 'Done.', 'woo-coupon-usage' );
	}
}
