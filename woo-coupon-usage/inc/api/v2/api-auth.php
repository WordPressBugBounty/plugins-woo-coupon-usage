<?php
/**
 * Coupon Affiliates REST API v2 - Authentication and rate limiting.
 *
 * Three ways to authenticate:
 *  1. WordPress Application Passwords (core, Basic auth) - no plugin code needed.
 *  2. Logged-in cookie + REST nonce (core behaviour, used by same-site JS).
 *  3. Plugin API keys - "Authorization: Bearer wcus_..." handled here. The key
 *     maps to a WP user (capabilities come from that user) and carries scopes
 *     that further restrict the key.
 *
 * Bearer tokens are rejected on plain HTTP unless the environment is local
 * (filterable), and every request to the plugin namespaces is rate limited.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_api_get_auth_token' ) ) {
	/**
	 * Extract a plugin bearer token from the request headers, if present.
	 *
	 * @return string Empty string when no plugin token is present.
	 */
	function wcusage_api_get_auth_token() {

		$header = '';

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( $header && 0 === stripos( $header, 'Bearer ' ) ) {
			$token = trim( substr( $header, 7 ) );
			if ( 0 === strpos( $token, 'wcus_' ) ) {
				return $token;
			}
			return '';
		}

		// Alternative header for clients that cannot set Authorization.
		if ( ! empty( $_SERVER['HTTP_X_WCUSAGE_API_KEY'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WCUSAGE_API_KEY'] ) );
			if ( 0 === strpos( $token, 'wcus_' ) ) {
				return $token;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wcusage_api_plugin_namespaces' ) ) {
	/**
	 * REST namespaces this plugin's API keys may authenticate.
	 *
	 * @return array
	 */
	function wcusage_api_plugin_namespaces() {
		return array( 'wcusage/v2', 'woo-coupon-usage/v1' );
	}
}

if ( ! function_exists( 'wcusage_api_is_front_controller_request' ) ) {
	/**
	 * Whether WordPress is serving this request through index.php.
	 *
	 * REST is dispatched by rest_api_loaded(), which runs on parse_request -
	 * an action only WP::main() fires, only ever reached through index.php.
	 * Every other PHP entry point that boots WordPress (wp-login.php,
	 * wp-comments-post.php, xmlrpc.php, and the many plugin files that
	 * require wp-load.php directly) resolves the current user in exactly the
	 * same way but never routes anything.
	 *
	 * That matters because "rest_route" is an ordinary query var: it can be
	 * appended to any of those URLs. Without this test a bearer token
	 * presented to, say, /wp-comments-post.php?rest_route=/wcusage/v2/me
	 * authenticates that request as the key's user - and since no route is
	 * dispatched, neither rest_pre_dispatch nor any permission callback runs,
	 * so the key's scopes are never consulted and it acts with the full
	 * capabilities of the account behind it.
	 *
	 * @return bool
	 */
	function wcusage_api_is_front_controller_request() {

		if ( empty( $_SERVER['SCRIPT_NAME'] ) ) {
			return false;
		}

		$script = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ), PHP_URL_PATH );

		if ( 'index.php' !== basename( $script ) ) {
			return false;
		}

		// The name on its own is not enough. "index.php" is an ordinary file
		// name, and WordPress is booted by plenty of scripts that carry it -
		// a plugin's own entry point doing "require wp-load.php", say. Such a
		// script resolves the current user exactly as the front controller
		// does, but it never calls wp(), so parse_request never fires: the
		// backstop in wcusage_api_refuse_key_auth_outside_rest() cannot
		// disarm the token, no route is dispatched, and the scopes - which
		// are only ever consulted inside REST permission callbacks - are
		// never checked at all. "rest_route" is a public query var, so it can
		// be appended to any of them. So establish that this really is the
		// front controller, not merely something sharing its name.
		$script_path = ltrim( $script, '/' );

		// The site root. Also every sub-directory site on a multisite
		// network, which are all served by the network root's index.php.
		if ( 'index.php' === $script_path ) {
			return true;
		}

		// A sub-directory install, where the front controller sits at the
		// home path: /blog/index.php for a site at example.com/blog.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
		if ( '' !== $home_path && $home_path . '/index.php' === $script_path ) {
			return true;
		}

		// Fallback for layouts where SCRIPT_NAME and home_url() disagree -
		// WordPress living in a sub-folder but served at the domain root
		// through a rewrite, for instance. Only two directories can hold a
		// real front controller: the WordPress directory itself, and its
		// parent, which is where the root index.php goes when WordPress has
		// been given its own directory.
		if ( empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
			return false;
		}

		$directory = wp_normalize_path( dirname( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) );
		$root      = untrailingslashit( wp_normalize_path( ABSPATH ) );

		return ( $root === $directory || dirname( $root ) === $directory );
	}
}

if ( ! function_exists( 'wcusage_api_is_plugin_rest_request' ) ) {
	/**
	 * Whether the current request targets one of the plugin REST namespaces.
	 *
	 * This runs on determine_current_user, before REST routing, so the route
	 * has to be worked out from the request itself. It must resolve the real
	 * route rather than searching the whole URI: a substring test would treat
	 * "/wp-json/wp/v2/users?x=wcusage/v2" as a plugin request and let an API
	 * key authenticate a core endpoint, where none of the scope checks apply.
	 *
	 * @return bool
	 */
	function wcusage_api_is_plugin_rest_request() {

		$namespaces = wcusage_api_plugin_namespaces();

		// determine_current_user runs on every entry point, not just REST.
		// admin-ajax.php, admin-post.php and wp-admin all read the same
		// superglobals but never dispatch a REST route, so a token presented
		// there must not authenticate anything: the scope checks live in the
		// REST permission callbacks and would simply not run. WordPress core
		// draws the same line for application passwords.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		// Same boundary, drawn at the entry point rather than at the context:
		// only index.php ever reaches the parse_request action REST dispatch
		// hangs off, and "rest_route" is a query var that can be appended to
		// any other URL. See wcusage_api_is_front_controller_request().
		if ( ! wcusage_api_is_front_controller_request() ) {
			return false;
		}

		// WP_REST::parse_request() resolves the rest_route query var from
		// $_POST before $_GET and before the URI path, so a POST body can
		// name a completely different route than the one the URL shows.
		// This can only ever veto: a body value is never enough on its own,
		// because non-REST entry points (admin-ajax.php) also read $_POST.
		if ( ! empty( $_POST['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted         = ltrim( sanitize_text_field( wp_unslash( $_POST['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted_is_ours = false;
			foreach ( $namespaces as $namespace ) {
				if ( 0 === strpos( $posted, $namespace ) ) {
					$posted_is_ours = true;
					break;
				}
			}
			if ( ! $posted_is_ours ) {
				return false;
			}
		}

		// Plain permalinks: /?rest_route=/wcusage/v2/me.
		if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $namespaces as $namespace ) {
				if ( 0 === strpos( $route, $namespace ) ) {
					return true;
				}
			}
			// An explicit rest_route that is not ours settles it.
			return false;
		}

		// Everything below reads the path form, /wp-json/wcusage/v2/me, which
		// exists only because rest_api_register_rewrites() adds a rewrite rule
		// for it - and rewrite rules are not consulted at all when the site has
		// no permalink structure. On such a site get_rest_url() hands clients
		// "index.php?rest_route=..." instead, and a request for the path form
		// is served as an ordinary front-end URL. Reading it as a REST request
		// would authenticate a plain page view with the key user's full
		// capabilities, no route having been dispatched to check the scopes.
		$permalinks = ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite )
			? (string) $GLOBALS['wp_rewrite']->permalink_structure
			: (string) get_option( 'permalink_structure' );
		if ( '' === $permalinks ) {
			return false;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// Pretty permalinks: /wp-json/wcusage/v2/me (path only, query ignored).
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		if ( '' === $path ) {
			return false;
		}

		// The prefix has to sit at the START of the path, which is the only
		// place core's rewrite rule ("^wp-json/(.*)?") ever matches it.
		// Searching for it anywhere in the path instead means an ordinary
		// front-end URL that merely contains it - "/blog/wp-json/wcusage/v2/me"
		// - is taken for a plugin REST request. That request never dispatches a
		// route, so no permission callback and therefore no scope check runs,
		// and the token would authenticate a plain page view with the key
		// user's full capabilities. The steps below mirror the normalisation
		// WP::parse_request() performs before matching the rule.
		$path = ltrim( $path, '/' );

		// Sub-directory installs: core strips the home path first.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
		if ( '' !== $home_path ) {
			if ( 0 !== stripos( $path, $home_path ) ) {
				return false;
			}
			$path = ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}

		// PATH_INFO permalinks: /index.php/wp-json/wcusage/v2/me.
		$index = ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite )
			? $GLOBALS['wp_rewrite']->index
			: 'index.php';
		if ( 0 === strpos( $path, $index . '/' ) ) {
			$path = ltrim( substr( $path, strlen( $index ) ), '/' );
		}

		$prefix = trim( rest_get_url_prefix(), '/' ) . '/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			return false;
		}

		$route = ltrim( substr( $path, strlen( $prefix ) ), '/' );

		foreach ( $namespaces as $namespace ) {
			if ( 0 === strpos( $route, $namespace ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wcusage_api_auth_state' ) ) {
	/**
	 * Per-request auth state store.
	 *
	 * @param string     $field Field name (key|error).
	 * @param mixed|null $value Set the field when not null.
	 * @param bool       $clear Reset the field to null. Takes precedence over
	 *                          $value, which cannot express "unset".
	 *
	 * @return mixed
	 */
	function wcusage_api_auth_state( $field, $value = null, $clear = false ) {
		static $state = array(
			'key'   => null,
			'error' => null,
		);

		if ( $clear ) {
			$state[ $field ] = null;
		} elseif ( null !== $value ) {
			$state[ $field ] = $value;
		}

		return isset( $state[ $field ] ) ? $state[ $field ] : null;
	}
}

if ( ! function_exists( 'wcusage_api_key_auth_disarmed' ) ) {
	/**
	 * Whether API key authentication has been ruled out for this request.
	 *
	 * @param bool $disarm Pass true to rule it out. One way only.
	 *
	 * @return bool
	 */
	function wcusage_api_key_auth_disarmed( $disarm = false ) {
		static $disarmed = false;

		if ( $disarm ) {
			$disarmed = true;
		}

		return $disarmed;
	}
}

if ( ! function_exists( 'wcusage_api_refuse_key_auth_outside_rest' ) ) {
	/**
	 * Last line of defence: undo key authentication on a request that turned
	 * out not to be a REST request after all.
	 *
	 * Core hooks rest_api_loaded() to parse_request at the default priority and it
	 * ends in die(), so reaching this callback - registered as late as the
	 * priority allows - is proof that no REST route is being served, whatever
	 * wcusage_api_is_plugin_rest_request() predicted before routing. Scopes are
	 * only ever checked inside REST permission callbacks, so a key must not go
	 * on acting as its user through the rest of a request that has none: the
	 * resolved user is dropped, and any later attempt to resolve one from the
	 * same token is refused, since determine_current_user is lazy and may not
	 * have run yet.
	 *
	 * Costs nothing on the overwhelming majority of requests, which never
	 * present a plugin token at all.
	 */
	function wcusage_api_refuse_key_auth_outside_rest() {

		// rest_api_loaded() returns without dying if a dispatch is already in
		// flight, so parse_request can in principle be reached from inside one.
		// Disarming there would break the request that is actually being served.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( '' === wcusage_api_get_auth_token() ) {
			return;
		}

		wcusage_api_key_auth_disarmed( true );

		if ( wcusage_api_current_key() ) {
			wcusage_api_auth_state( 'key', null, true );
			wp_set_current_user( 0 );
		}
	}
	add_action( 'parse_request', 'wcusage_api_refuse_key_auth_outside_rest', PHP_INT_MAX );
}

if ( ! function_exists( 'wcusage_api_determine_current_user' ) ) {
	/**
	 * Authenticate plugin API key bearer tokens.
	 *
	 * Hooked to determine_current_user. Never overrides a user that another
	 * authentication method has already determined.
	 *
	 * @param int|false $user_id Current user ID or false.
	 *
	 * @return int|false
	 */
	function wcusage_api_determine_current_user( $user_id ) {

		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		// determine_current_user runs on every request the site serves, not
		// just REST ones, so the cheapest and most selective test comes first:
		// two $_SERVER reads. No plugin bearer token means there is nothing
		// here to authenticate and none of the work below - reading the API
		// settings, then reconstructing which route WordPress is about to
		// resolve - needs to happen at all. Ordering only; a token is a
		// credential to be checked, never on its own a reason to trust
		// anything, and every check that follows still applies.
		$token = wcusage_api_get_auth_token();
		if ( '' === $token ) {
			return $user_id;
		}

		// Routing has already happened and did not hand this request to the
		// REST server - see wcusage_api_refuse_key_auth_outside_rest().
		if ( wcusage_api_key_auth_disarmed() ) {
			return $user_id;
		}

		// API keys belong to the v2 system, so they authenticate nothing at
		// all while it is switched off - including the legacy v1 routes,
		// which remain reachable with an application password as before.
		if ( ! wcusage_api_v2_is_enabled() ) {
			return $user_id;
		}

		if ( ! wcusage_api_is_plugin_rest_request() ) {
			return $user_id;
		}

		// Bearer tokens are credentials: require TLS outside local environments.
		$require_https = apply_filters( 'wcusage_api_require_https', wcusage_api_requires_https() );
		if ( $require_https && ! is_ssl() ) {
			wcusage_api_auth_state( 'error', wcusage_api_error( 'https_required', __( 'API keys may only be used over HTTPS.', 'woo-coupon-usage' ), 401 ) );
			return $user_id;
		}

		// A request that fails authentication never reaches dispatch, so the
		// per-route limiter on rest_request_before_callbacks does not see it:
		// core returns the rest_authentication_errors result straight from
		// serve_request(). Without this, presenting bad tokens is the one way
		// to hit the API unthrottled, and each attempt still costs a query.
		if ( ! wcusage_api_auth_failures_ok() ) {
			wcusage_api_auth_state( 'error', wcusage_api_error( 'too_many_auth_failures', __( 'Too many failed authentication attempts. Please try again later.', 'woo-coupon-usage' ), 429 ) );
			return $user_id;
		}

		$key = function_exists( 'wcusage_api_find_key_by_token' ) ? wcusage_api_find_key_by_token( $token ) : null;

		if ( ! $key ) {
			wcusage_api_note_auth_failure();
			wcusage_api_auth_state( 'error', wcusage_api_error( 'invalid_key', __( 'The provided API key is invalid, revoked or expired.', 'woo-coupon-usage' ), 401 ) );
			return $user_id;
		}

		if ( ! get_userdata( (int) $key->user_id ) ) {
			wcusage_api_note_auth_failure();
			wcusage_api_auth_state( 'error', wcusage_api_error( 'invalid_key_user', __( 'The user this API key belongs to no longer exists.', 'woo-coupon-usage' ), 401 ) );
			return $user_id;
		}

		wcusage_api_auth_state( 'key', $key );
		wcusage_api_touch_key( (int) $key->id );

		return (int) $key->user_id;
	}
	add_filter( 'determine_current_user', 'wcusage_api_determine_current_user', 20 );
}

if ( ! function_exists( 'wcusage_api_rest_authentication_errors' ) ) {
	/**
	 * Surface bearer-token failures as REST authentication errors.
	 *
	 * A request that presented an invalid plugin token must fail, not fall
	 * through to "anonymous".
	 *
	 * @param WP_Error|null|true $error Current authentication status.
	 *
	 * @return WP_Error|null|true
	 */
	function wcusage_api_rest_authentication_errors( $error ) {

		if ( ! empty( $error ) ) {
			return $error;
		}

		$auth_error = wcusage_api_auth_state( 'error' );
		if ( is_wp_error( $auth_error ) ) {
			return $auth_error;
		}

		return $error;
	}
	add_filter( 'rest_authentication_errors', 'wcusage_api_rest_authentication_errors', 20 );
}

if ( ! function_exists( 'wcusage_api_enforce_key_namespace' ) ) {
	/**
	 * Confine API keys to the plugin's own namespaces at dispatch time.
	 *
	 * The pre-routing check in wcusage_api_is_plugin_rest_request() has to
	 * predict which route WordPress will serve. This runs once the route is
	 * actually known, so it is authoritative: if this request authenticated
	 * with a plugin API key but is not being dispatched to a plugin route,
	 * refuse it. Scopes only exist on plugin routes, so a key must never be
	 * able to reach anything else - whatever route-smuggling trick was used.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Current request.
	 *
	 * @return mixed
	 */
	function wcusage_api_enforce_key_namespace( $result, $server, $request ) {

		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( ! wcusage_api_current_key() ) {
			return $result;
		}

		$route = ltrim( (string) $request->get_route(), '/' );

		foreach ( wcusage_api_plugin_namespaces() as $namespace ) {
			if ( 0 !== strpos( $route, $namespace ) ) {
				continue;
			}

			// The v2 controllers each declare the scope they need. The legacy
			// v1 namespace has no scope awareness at all, and any route added
			// to it later would inherit that, so apply a fail-closed default
			// here: reads need "read", anything else needs "write".
			if ( 'woo-coupon-usage/v1' === $namespace ) {
				$method   = strtoupper( (string) $request->get_method() );
				$required = in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ? 'read' : 'write';
				$check    = wcusage_api_require_scope( $required );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			return $result;
		}

		return wcusage_api_error(
			'key_wrong_namespace',
			__( 'This API key can only be used with the Coupon Affiliates API.', 'woo-coupon-usage' ),
			403
		);
	}
	add_filter( 'rest_pre_dispatch', 'wcusage_api_enforce_key_namespace', 10, 3 );
}

if ( ! function_exists( 'wcusage_api_current_key' ) ) {
	/**
	 * The API key row used to authenticate this request, if any.
	 *
	 * @return object|null
	 */
	function wcusage_api_current_key() {
		return wcusage_api_auth_state( 'key' );
	}
}

if ( ! function_exists( 'wcusage_api_current_scopes' ) ) {
	/**
	 * Scopes of the current request.
	 *
	 * @return array|null Array of scopes when key-authenticated, null when
	 *                    authenticated another way (capabilities decide).
	 */
	function wcusage_api_current_scopes() {
		$key = wcusage_api_current_key();
		if ( ! $key ) {
			return null;
		}
		return wcusage_api_sanitize_scopes( $key->scopes );
	}
}

if ( ! function_exists( 'wcusage_api_current_user_has_scope' ) ) {
	/**
	 * Whether the current request may use a scope.
	 *
	 * Non-key authentication (application password, cookie) always passes;
	 * route capability checks still apply on top.
	 *
	 * @param string $scope Scope name.
	 *
	 * @return bool
	 */
	function wcusage_api_current_user_has_scope( $scope ) {
		$scopes = wcusage_api_current_scopes();
		if ( null === $scopes ) {
			return true;
		}
		return in_array( $scope, $scopes, true );
	}
}

if ( ! function_exists( 'wcusage_api_require_scope' ) ) {
	/**
	 * Scope gate for permission callbacks.
	 *
	 * @param string $scope Scope name.
	 *
	 * @return true|WP_Error
	 */
	function wcusage_api_require_scope( $scope ) {
		if ( wcusage_api_current_user_has_scope( $scope ) ) {
			return true;
		}
		return wcusage_api_error(
			'insufficient_scope',
			sprintf(
				/* translators: %s: scope name */
				__( 'This API key does not have the "%s" scope.', 'woo-coupon-usage' ),
				$scope
			),
			403
		);
	}
}

/*
 * ------------------------------------------------------------------
 * Rate limiting
 * ------------------------------------------------------------------
 */

if ( ! function_exists( 'wcusage_api_client_ip' ) ) {
	/**
	 * The requesting IP address.
	 *
	 * Deliberately REMOTE_ADDR only: forwarded-for style headers are set by
	 * the client and would let one attacker spend everyone else's budget.
	 *
	 * @return string
	 */
	function wcusage_api_client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}

if ( ! function_exists( 'wcusage_api_auth_failure_window' ) ) {
	/**
	 * How long a failed authentication attempt is remembered for.
	 *
	 * @return int Seconds.
	 */
	function wcusage_api_auth_failure_window() {
		return 15 * MINUTE_IN_SECONDS;
	}
}

if ( ! function_exists( 'wcusage_api_auth_failure_slot' ) ) {
	/**
	 * Where this client's failed-attempt count is kept.
	 *
	 * Counts are per address, because this check refuses a request outright
	 * and a shared counter would let one attacker lock a real integration
	 * out. A transient per address is the wrong way to hold them, though: it
	 * is written before the request has been authorised, so on a site with no
	 * persistent object cache a distributed attempt would add an options row
	 * per source address - exactly the growth the rate limiter spreads itself
	 * over buckets to avoid.
	 *
	 * So addresses are grouped into a fixed number of buckets, and each bucket
	 * holds a small map of address to count. Precise per-address counts, and
	 * never more than one row per bucket.
	 *
	 * @return array array( transient key, address key ).
	 */
	function wcusage_api_auth_failure_slot() {

		$ip = wcusage_api_client_ip();

		/**
		 * Filter how many buckets failed authentication attempts are held in.
		 *
		 * @param int $buckets Number of buckets.
		 */
		$buckets = max( 1, (int) apply_filters( 'wcusage_api_auth_failure_buckets', 256 ) );

		return array(
			'wcusage_api_af_' . ( abs( crc32( $ip ) ) % $buckets ),
			substr( md5( $ip ), 0, 12 ),
		);
	}
}

if ( ! function_exists( 'wcusage_api_auth_failures_ok' ) ) {
	/**
	 * Whether this client may make another authentication attempt.
	 *
	 * @return bool
	 */
	function wcusage_api_auth_failures_ok() {

		/**
		 * Filter how many failed API key attempts an address may make
		 * before it is refused. Zero or less disables the check.
		 *
		 * @param int $max Failed attempts allowed per 15 minutes.
		 */
		$max = (int) apply_filters( 'wcusage_api_max_auth_failures', 20 );
		if ( $max <= 0 ) {
			return true;
		}

		list( $key, $address ) = wcusage_api_auth_failure_slot();

		$counts = get_transient( $key );
		if ( ! is_array( $counts ) || empty( $counts[ $address ]['count'] ) ) {
			return true;
		}

		// Each address carries its own expiry. The bucket's transient is
		// refreshed by whichever address wrote to it last, so without this an
		// address could stay locked out for as long as anything else sharing
		// its bucket kept failing.
		if ( empty( $counts[ $address ]['expires'] ) || $counts[ $address ]['expires'] < time() ) {
			return true;
		}

		return ( (int) $counts[ $address ]['count'] < $max );
	}
}

if ( ! function_exists( 'wcusage_api_note_auth_failure' ) ) {
	/**
	 * Record a failed API key authentication attempt.
	 */
	function wcusage_api_note_auth_failure() {

		$now    = time();
		$window = wcusage_api_auth_failure_window();

		list( $key, $address ) = wcusage_api_auth_failure_slot();

		$counts = get_transient( $key );
		if ( ! is_array( $counts ) ) {
			$counts = array();
		}

		// Drop anything whose own window has passed, this address included -
		// an expired count starts again rather than being added to.
		foreach ( $counts as $known => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['expires'] ) || $entry['expires'] < $now ) {
				unset( $counts[ $known ] );
			}
		}

		$counts[ $address ] = array(
			'count'   => isset( $counts[ $address ]['count'] ) ? (int) $counts[ $address ]['count'] + 1 : 1,
			'expires' => $now + $window,
		);

		// A bucket only has to remember the addresses that are approaching the
		// limit. Keeping every address that ever failed once would let the
		// bucket grow without bound, which is what holding them apart was
		// meant to prevent.
		if ( count( $counts ) > 50 ) {
			uasort(
				$counts,
				function ( $a, $b ) {
					// Read defensively: this sorts a structure that came back
					// out of storage, and an undefined-key warning here would
					// be emitted into the body of a REST response.
					$left  = isset( $a['count'] ) ? (int) $a['count'] : 0;
					$right = isset( $b['count'] ) ? (int) $b['count'] : 0;
					if ( $left === $right ) {
						return 0;
					}
					return ( $left < $right ) ? 1 : -1;
				}
			);
			$counts = array_slice( $counts, 0, 50, true );
		}

		set_transient( $key, $counts, $window );
	}
}

if ( ! function_exists( 'wcusage_api_rate_limit_identity' ) ) {
	/**
	 * Identity string used for the rate limit bucket.
	 *
	 * @return string
	 */
	function wcusage_api_rate_limit_identity() {

		$key = wcusage_api_current_key();
		if ( $key ) {
			return 'key_' . (int) $key->id;
		}

		if ( is_user_logged_in() ) {
			return 'user_' . get_current_user_id();
		}

		// Anonymous callers are folded into a fixed number of buckets. The
		// counter is a transient, and this one is written before the request
		// has been authorised, so keying it on the raw address would let
		// anyone add a row to the options table per address per minute on a
		// site with no persistent object cache. Sharing a budget costs
		// anonymous callers nothing in practice: every endpoint except
		// /openapi refuses them anyway, and the window is only a minute.
		/**
		 * Filter how many buckets anonymous callers are spread across.
		 *
		 * @param int $buckets Number of buckets.
		 */
		$buckets = max( 1, (int) apply_filters( 'wcusage_api_ip_buckets', 256 ) );

		return 'ip_' . ( abs( crc32( wcusage_api_client_ip() ) ) % $buckets );
	}
}

if ( ! function_exists( 'wcusage_api_rate_limit_check' ) ) {
	/**
	 * Enforce the per-minute request limit on plugin REST routes.
	 *
	 * @param mixed           $response Result the server would send so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Current request.
	 *
	 * @return mixed WP_Error when over the limit, otherwise $response unchanged.
	 */
	function wcusage_api_rate_limit_check( $response, $handler, $request ) {

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/wcusage/v2' ) && 0 !== strpos( $route, '/woo-coupon-usage/v1' ) ) {
			return $response;
		}

		// Deliberately no early return when $response is already set. Core
		// hands this filter the error it produced for a request with a missing
		// or invalid parameter, so skipping those left one request shape - a
		// malformed one - that never counted against the limit and could be
		// repeated indefinitely. They are counted, and refused, like any other.

		$identity = wcusage_api_rate_limit_identity();

		$default_limit = is_user_logged_in() ? 120 : 30;

		/**
		 * Filter the per-minute API rate limit.
		 *
		 * @param int    $default_limit Requests per minute.
		 * @param string $identity      Bucket identity (key_*, user_*, ip_*).
		 */
		$limit = (int) apply_filters( 'wcusage_api_rate_limit', $default_limit, $identity );
		if ( $limit <= 0 ) {
			return $response;
		}

		$bucket = 'wcusage_api_rl_' . md5( $identity ) . '_' . gmdate( 'YmdHi' );
		$count  = (int) get_transient( $bucket );
		++$count;
		set_transient( $bucket, $count, 2 * MINUTE_IN_SECONDS );

		if ( $count > $limit ) {
			return wcusage_api_error(
				'rate_limited',
				__( 'Too many requests. Please slow down.', 'woo-coupon-usage' ),
				429,
				array( 'retry_after' => 60 )
			);
		}

		return $response;
	}
	add_filter( 'rest_request_before_callbacks', 'wcusage_api_rate_limit_check', 10, 3 );
}
