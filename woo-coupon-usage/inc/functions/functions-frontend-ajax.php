<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A front-end endpoint for the affiliate dashboard's own AJAX.
 *
 * Everything the dashboard loads used to POST to wp-admin/admin-ajax.php. That
 * file defines WP_ADMIN, so every installed plugin loads its wp-admin side
 * before a line of dashboard code runs. Measured on a 17-plugin site, steady
 * state: a front-end request that does nothing takes ~1.38 s, an admin-ajax.php
 * request for an action that does not even exist takes ~2.45 s. The statistics
 * tab's own work, warm, is 69 ms - so the "Loading statistics" spinner was
 * almost entirely someone else's admin bootstrap.
 *
 * This endpoint answers on the front end instead. It dispatches to exactly the
 * same wp_ajax_ / wp_ajax_nopriv_ hooks, so every handler, nonce and response
 * body is unchanged; it just skips WP_ADMIN and the admin_init that
 * admin-ajax.php fires.
 *
 * Only the actions on the front-end allow list (see wcusage_is_front_end_ajax_action)
 * are dispatchable here. Anything else falls through as if the endpoint were not
 * there, so no admin-only handler becomes reachable without wp-admin loaded.
 *
 * If a host blocks the endpoint, the dashboard JavaScript retries the request
 * against admin-ajax.php and drops a cookie; wcusage_frontend_ajax_enabled()
 * then serves admin-ajax URLs for that visitor from the next page load on.
 */

if ( ! defined( 'WCUSAGE_AJAX_QUERY_VAR' ) ) {
    define( 'WCUSAGE_AJAX_QUERY_VAR', 'wcusage-ajax' );
}

if ( ! defined( 'WCUSAGE_AJAX_FALLBACK_COOKIE' ) ) {
    define( 'WCUSAGE_AJAX_FALLBACK_COOKIE', 'wcusage_ajax_fallback' );
}

/**
 * Whether dashboard AJAX should go to the front-end endpoint.
 *
 * Off when the "Front-end ajax endpoint" setting is disabled, or when this
 * visitor's browser has already found the endpoint unreachable.
 *
 * @return bool
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_enabled' ) ) {
    function wcusage_frontend_ajax_enabled() {

        if ( ! wcusage_get_setting_value( 'wcusage_field_frontend_ajax', '1' ) ) {
            return false;
        }

        if ( ! empty( $_COOKIE[ WCUSAGE_AJAX_FALLBACK_COOKIE ] ) ) {
            return false;
        }

        return (bool) apply_filters( 'wcusage_frontend_ajax_enabled', true );

    }
}

/**
 * The URL the dashboard's AJAX should post to.
 *
 * Use this everywhere the dashboard used to print admin_url('admin-ajax.php').
 * Admin screens must keep using admin_url() directly - their handlers need the
 * wp-admin files that this endpoint deliberately does not load.
 *
 * @return string
 *
 */
if ( ! function_exists( 'wcusage_ajax_url' ) ) {
    function wcusage_ajax_url() {

        if ( ! wcusage_frontend_ajax_enabled() ) {
            return admin_url( 'admin-ajax.php' );
        }

        // Remember that this page actually handed the endpoint URL to the browser,
        // so the fallback script below is printed there and nowhere else.
        $GLOBALS['wcusage_frontend_ajax_used'] = true;

        return add_query_arg( WCUSAGE_AJAX_QUERY_VAR, '1', home_url( '/' ) );

    }
}

/**
 * The action this request is asking the endpoint to run, if any.
 *
 * Returns "" unless the endpoint query var is present AND the action is on the
 * front-end allow list, so an unknown or admin-only action is simply not ours.
 *
 * @return string
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_action' ) ) {
    function wcusage_frontend_ajax_action() {

        static $resolved = null;

        if ( null !== $resolved ) {
            return $resolved;
        }

        $resolved = '';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing only; each handler verifies its own nonce.
        if ( empty( $_REQUEST[ WCUSAGE_AJAX_QUERY_VAR ] ) ) {
            return $resolved;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( ! $action || ! wcusage_is_front_end_ajax_action( $action ) ) {
            return $resolved;
        }

        $resolved = $action;

        return $resolved;

    }
}

/**
 * Put the request into AJAX mode before anything else runs.
 *
 * Several handlers behave differently under DOING_AJAX (the registration form
 * returns JSON rather than redirecting, for one), so the constant has to be set
 * for them to work the same way here as they do on admin-ajax.php. WooCommerce
 * does exactly this for its own front-end endpoint in WC_AJAX::define_ajax(),
 * also on init priority 0.
 *
 * The plugin's own includes have already run by this point and correctly decided
 * this is not an admin context (is_admin() is false on the front end), and
 * wcusage_is_admin_context() memoises, so defining the constant here does not
 * pull the wp-admin files back in.
 *
 * @return void
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_define' ) ) {
    function wcusage_frontend_ajax_define() {

        if ( ! wcusage_frontend_ajax_action() ) {
            return;
        }

        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        if ( ! defined( 'WCUSAGE_DOING_AJAX' ) ) {
            define( 'WCUSAGE_DOING_AJAX', true );
        }

        if ( ! WP_DEBUG || ( WP_DEBUG && ! WP_DEBUG_DISPLAY ) ) {
            @ini_set( 'display_errors', 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( isset( $GLOBALS['wpdb'] ) ) {
            $GLOBALS['wpdb']->hide_errors();
        }

    }
}
add_action( 'init', 'wcusage_frontend_ajax_define', 0 );

/**
 * Dispatch the request to the same hook admin-ajax.php would have fired.
 *
 * The headers and the trailing "0" mirror wp-admin/admin-ajax.php so a caller
 * cannot tell the two apart. admin_init is deliberately NOT fired - it is a
 * wp-admin lifecycle hook, and not firing it is a good part of the saving.
 *
 * Runs on wp_loaded, NOT on init. WooCommerce registers its order types on init
 * priority 5 (WC_Post_Types::register_post_types(), and Subscriptions adds
 * shop_subscription alongside it), so dispatching during init means wc_get_orders()
 * meets an order whose type is not registered yet and WC_Order_Factory throws
 * "Could not find classname for order ID n" - which killed the response halfway
 * through the graph section. admin-ajax.php never hits this because it dispatches
 * after the whole of init. wp_loaded is the first hook where everything is
 * registered, and it is cheaper than template_redirect because the main query has
 * not had to run.
 *
 * @return void
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_dispatch' ) ) {
    function wcusage_frontend_ajax_dispatch() {

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
        if ( empty( $_REQUEST[ WCUSAGE_AJAX_QUERY_VAR ] ) ) {
            return;
        }

        $action = wcusage_frontend_ajax_action();

        if ( function_exists( 'send_origin_headers' ) ) {
            send_origin_headers();
        }

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
            header( 'X-Robots-Tag: noindex' );
        }

        send_nosniff_header();
        nocache_headers();

        if ( ! $action ) {
            // Asked for something that is not one of the dashboard's own actions.
            // Answer the way admin-ajax.php answers an unknown one rather than
            // letting the request fall through and render a whole page - which is
            // both a wasted page render and a confusing 200 for the caller. Note
            // this path never put the request into AJAX mode, so wp_die() would
            // print an HTML error document here; echo the bare body instead.
            status_header( 400 );
            echo '0';
            exit;
        }

        $hook = is_user_logged_in() ? 'wp_ajax_' . $action : 'wp_ajax_nopriv_' . $action;

        if ( has_action( $hook ) ) {
            do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        }

        // Same as admin-ajax.php: a handler that did not exit means "no output".
        wp_die( '0', '', array( 'response' => null ) );

    }
}
add_action( 'wp_loaded', 'wcusage_frontend_ajax_dispatch', 0 );

/**
 * The one-line JavaScript safety net.
 *
 * Printed with the dashboard. If a request to the endpoint fails outright - a
 * security plugin or a server rule blocking POSTs with this query string, say -
 * the same request is retried against admin-ajax.php and a cookie is set so the
 * next page load renders admin-ajax URLs to begin with. Nothing is retried for
 * a request that reached PHP: only a transport-level failure or a 5xx.
 *
 * @return string
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_fallback_script' ) ) {
    function wcusage_frontend_ajax_fallback_script() {

        if ( empty( $GLOBALS['wcusage_frontend_ajax_used'] ) || ! wcusage_frontend_ajax_enabled() ) {
            return '';
        }

        $endpoint = wcusage_ajax_url();
        $adminajax = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <script>
        jQuery(document).ajaxError(function(event, jqXHR, settings) {
          /* Only our own endpoint, and only when the request never got an answer
             from PHP (status 0) or the server itself failed (5xx). A 403 from a
             stale nonce is a real answer and must not be retried. */
          if (!settings || !settings.url || settings.url.indexOf(<?php echo wp_json_encode( $endpoint ); ?>) !== 0) { return; }
          if (jqXHR.status !== 0 && jqXHR.status < 500) { return; }
          if (settings.wcusageRetried) { return; }
          document.cookie = <?php echo wp_json_encode( WCUSAGE_AJAX_FALLBACK_COOKIE . '=1; path=/; max-age=' . DAY_IN_SECONDS . '; SameSite=Lax' ); ?>;
          var retry = jQuery.extend({}, settings, { url: <?php echo wp_json_encode( $adminajax ); ?>, wcusageRetried: true });
          jQuery.ajax(retry);
        });
        </script>
        <?php
        return ob_get_clean();

    }
}

/**
 * Print the safety net in the footer of any page that used the endpoint.
 *
 * @return void
 *
 */
if ( ! function_exists( 'wcusage_frontend_ajax_print_fallback' ) ) {
    function wcusage_frontend_ajax_print_fallback() {

        $script = wcusage_frontend_ajax_fallback_script();

        if ( ! $script ) {
            return;
        }

        echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from wp_json_encode()d values only.

    }
}
add_action( 'wp_footer', 'wcusage_frontend_ajax_print_fallback', 100 );
