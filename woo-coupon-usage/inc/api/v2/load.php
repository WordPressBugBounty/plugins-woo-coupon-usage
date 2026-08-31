<?php

/**
 * Coupon Affiliates REST API v2 - Loader.
 *
 * Namespace: wcusage/v2
 *
 * Included from woo-coupon-usage.php alongside the legacy v1 API files.
 *
 * The v2 API is off until an administrator switches it on under
 * "Coupon Affiliates > Admin Tools > API". That setting, and the per-endpoint
 * switches, live in the "wcusage_api_settings" option. The legacy
 * woo-coupon-usage/v1 routes are not affected by them.
 *
 * @package WooCouponUsage\API
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Core (helpers, keys, auth, admin page).
require plugin_dir_path( __FILE__ ) . 'api-helpers.php';
require plugin_dir_path( __FILE__ ) . 'api-keys.php';
require plugin_dir_path( __FILE__ ) . 'api-auth.php';
if ( is_admin() ) {
    include plugin_dir_path( __FILE__ ) . 'api-admin-page.php';
}
if ( !function_exists( 'wcusage_api_v2_register_routes' ) ) {
    /**
     * Register the v2 REST routes, unless the API is switched off.
     *
     * Hooked to rest_api_init, which passes the WP_REST_Server instance to its
     * callbacks - so this deliberately takes no arguments and the actual
     * registration lives in its own function that other code can call.
     */
    function wcusage_api_v2_register_routes() {
        if ( !wcusage_api_v2_is_enabled() ) {
            return;
        }
        wcusage_api_v2_do_register_routes();
    }

    add_action( 'rest_api_init', 'wcusage_api_v2_register_routes' );
}
if ( !function_exists( 'wcusage_api_v2_do_register_routes' ) ) {
    /**
     * Register all v2 REST routes, whether or not the API is switched on.
     *
     * Separate from the rest_api_init callback so the admin screen can list the
     * endpoints while the API is off - it reads them from the route table, and
     * with nothing registered it would otherwise show an empty list on exactly
     * the screen an administrator visits to switch the API on.
     */
    function wcusage_api_v2_do_register_routes() {
        // WP_REST_Controller subclasses can only load once the REST API
        // bootstraps, so controllers are included here rather than at
        // plugin load.
        include_once plugin_dir_path( __FILE__ ) . 'class-wcusage-api-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-affiliates-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-coupons-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-registrations-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-events-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-clicks-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-reports-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-me-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-manage-controller.php';
        include_once plugin_dir_path( __FILE__ ) . 'controllers/class-wcusage-api-openapi-controller.php';
        $controllers = array(
            new WCUsage_API_Affiliates_Controller(),
            new WCUsage_API_Coupons_Controller(),
            new WCUsage_API_Registrations_Controller(),
            new WCUsage_API_Events_Controller(),
            new WCUsage_API_Clicks_Controller(),
            new WCUsage_API_Reports_Controller(),
            new WCUsage_API_Me_Controller(),
            new WCUsage_API_Manage_Controller(),
            new WCUsage_API_OpenAPI_Controller()
        );
        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }
    }

}