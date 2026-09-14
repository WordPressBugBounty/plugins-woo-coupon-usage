<?php

/**
* Plugin Name: Coupon Affiliates for WooCommerce
* Plugin URI: https://couponaffiliates.com
* Description: The most powerful affiliate plugin for WooCommerce. Track commission, generate referral URLs, assign affiliate coupons, and display detailed stats.
* Version: 8.4.0
* Author: Elliot Sowersby, RelyWP
* Author URI: https://couponaffiliates.com/
* License: GPLv3
* Text Domain: woo-coupon-usage
* Domain Path: /languages
* Requires Plugins: woocommerce
*
* WC requires at least: 3.7
* WC tested up to: 11.1
*
*/
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Define plugin version constant
if ( !defined( 'WCUSAGE_VERSION' ) ) {
    define( 'WCUSAGE_VERSION', '8.4.0' );
}
if ( function_exists( 'wcu_fs' ) ) {
    wcu_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( 'wcu_fs' ) ) {
        // ***** SDK Integration *****
        function wcu_fs() {
            global $wcu_fs;
            if ( !isset( $wcu_fs ) ) {
                // Activate multisite network integration.
                if ( !defined( 'WP_FS__PRODUCT_2732_MULTISITE' ) ) {
                    define( 'WP_FS__PRODUCT_2732_MULTISITE', true );
                }
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/freemius/start.php';
                $wcu_fs = fs_dynamic_init( array(
                    'id'               => '2732',
                    'slug'             => 'woo-coupon-usage',
                    'premium_slug'     => 'woo-coupon-usage-pro',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_a8d9ceeaec08247afd31dbb3e26b3',
                    'is_premium'       => false,
                    'premium_suffix'   => '(PRO)',
                    'has_addons'       => true,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'trial'            => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'             => array(
                        'slug'       => 'wcusage',
                        'first-path' => 'admin.php?page=wcusage_setup',
                        'support'    => true,
                        'contact'    => true,
                        'pricing'    => true,
                        'addons'     => false,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $wcu_fs;
        }

        // Init Freemius.
        wcu_fs();
        // Signal that SDK was initiated.
        do_action( 'wcu_fs_loaded' );
        function wcu_fs_settings_url() {
            // Open the Freemius connect screen on the top-level page.
            return admin_url( 'admin.php?page=wcusage' );
        }

        function wcu_fs_settings_url2() {
            $wcusage_setup_complete = get_option( 'wcusage_setup_complete' );
            if ( !$wcusage_setup_complete ) {
                return admin_url( 'admin.php?page=wcusage_setup' );
            } else {
                return admin_url( 'admin.php?page=wcusage_setup&step=6' );
            }
        }

        wcu_fs()->add_filter( 'connect_url', 'wcu_fs_settings_url' );
        wcu_fs()->add_filter( 'after_skip_url', 'wcu_fs_settings_url2' );
        wcu_fs()->add_filter( 'after_connect_url', 'wcu_fs_settings_url2' );
        wcu_fs()->add_filter( 'after_pending_connect_url', 'wcu_fs_settings_url2' );
        /*** Include Plugin Icon ***/
        function wcusage_fs_custom_icon() {
            return dirname( __FILE__ ) . '/images/logo-icon.png';
        }

        wcu_fs()->add_filter( 'plugin_icon', 'wcusage_fs_custom_icon' );
        // ***** END SDK Integration *****
    }
    // Get Plugin Base URL
    $url = plugin_dir_url( __FILE__ );
    define( 'WCUSAGE_UNIQUE_PLUGIN_URL', $url );
    // Get Plugin Base PATH
    $url_path = plugin_dir_path( __FILE__ );
    define( 'WCUSAGE_UNIQUE_PLUGIN_PATH', $url_path );
    /**
     * Enqueue the bundled Font Awesome stylesheet, once per request.
     *
     * Roughly thirty places in the plugin used to echo a raw <link> tag for this
     * 96 KB stylesheet. Because they were echoed rather than enqueued there was
     * nothing to de-duplicate them - a dashboard showing the coupon list and the
     * registration form loaded it twice - and being emitted mid-<body> put a
     * render-blocking stylesheet where no optimisation plugin could see it.
     *
     * Enqueuing this late is fine: WordPress prints anything enqueued after the
     * head has been sent in the footer instead of dropping it.
     *
     * @return void
     */
    function wcusage_enqueue_font_awesome() {
        if ( wp_style_is( 'wcusage-font-awesome', 'enqueued' ) || wp_style_is( 'wcusage-font-awesome', 'done' ) ) {
            return;
        }
        wp_enqueue_style(
            'wcusage-font-awesome',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css',
            array(),
            WCUSAGE_VERSION
        );
    }

    /**
     * The front-end shortcodes this plugin renders.
     *
     * @return array
     */
    function wcusage_frontend_shortcodes() {
        return apply_filters( 'wcusage_frontend_shortcodes', array(
            'couponusage',
            'couponusage-mla',
            'couponusage-user',
            'couponaffiliates',
            'couponaffiliates-mla',
            'couponaffiliates-user',
            'couponaffiliates-creatives',
            'couponaffiliates-leaderboard',
            'couponaffiliates-my-coupons',
            'couponaffiliates-payouts',
            'couponaffiliates-rates',
            'couponaffiliates-referral-url',
            'couponaffiliates-referral-urls',
            'couponaffiliates-referrer',
            'couponaffiliates-register',
            'couponaffiliates-mla-payouts',
            'couponaffiliates-mla-subaffiliates',
            'couponaffiliates_bonuses',
            'couponaffiliates_credit',
            'couponaffiliates_store_credit',
            'wcusage_bonuses',
            'affiliate_qrcode'
        ) );
    }

    /**
     * Whether the page being rendered can actually show something from this plugin.
     *
     * Used to gate the front-end stylesheet, which previously loaded on every page
     * of the site regardless of whether any plugin output appeared on it.
     *
     * Deliberately errs towards loading: features that can appear on ANY page (the
     * floating widget) return true immediately rather than trying to predict where
     * they will render. 'wcusage_force_frontend_assets' is the escape hatch for a
     * theme or snippet that outputs the dashboard somewhere this cannot see, such
     * as a page builder template or a widget area.
     *
     * @return bool
     */
    function wcusage_page_needs_frontend_assets() {
        static $needed = null;
        if ( null !== $needed ) {
            return $needed;
        }
        $forced = apply_filters( 'wcusage_force_frontend_assets', null );
        if ( is_bool( $forced ) ) {
            $needed = $forced;
            return $needed;
        }
        if ( is_admin() ) {
            $needed = false;
            return $needed;
        }
        // The floating widget can be output on any page in the site.
        if ( wcusage_get_setting_value( 'wcusage_field_floating_widget_enable', '0' ) ) {
            $needed = true;
            return $needed;
        }
        // Affiliate portal and (PRO) MLA portal. Both are served by their own
        // rewrite rule with no page behind them, so nothing below could match.
        if ( get_query_var( 'affiliate_portal' ) || get_query_var( 'mla_affiliate_portal' ) ) {
            $needed = true;
            return $needed;
        }
        // My Account, when the affiliate tab is switched on.
        if ( function_exists( 'is_account_page' ) && is_account_page() && (wcusage_get_setting_value( 'wcusage_field_account_tab', 0 ) || wcusage_get_setting_value( 'wcusage_field_account_tab_create', 0 )) ) {
            $needed = true;
            return $needed;
        }
        global $post;
        $post_id = ( $post && isset( $post->ID ) ? (int) $post->ID : 0 );
        if ( !$post_id ) {
            $needed = false;
            return $needed;
        }
        // Configured dashboard / MLA dashboard / registration pages.
        foreach ( array('wcusage_dashboard_page', 'wcusage_mla_dashboard_page', 'wcusage_registration_page') as $page_option ) {
            $configured = wcusage_get_setting_value( $page_option, '' );
            if ( $configured && (int) $configured === $post_id ) {
                $needed = true;
                return $needed;
            }
        }
        // Any of the plugin's shortcodes in the content.
        $content = ( isset( $post->post_content ) ? (string) $post->post_content : '' );
        if ( $content && function_exists( 'has_shortcode' ) ) {
            foreach ( wcusage_frontend_shortcodes() as $shortcode ) {
                if ( has_shortcode( $content, $shortcode ) ) {
                    $needed = true;
                    return $needed;
                }
            }
        }
        $needed = false;
        return $needed;
    }

    /**
     * Whether the current wp-admin screen belongs to this plugin.
     *
     * Covers the plugin's own menu pages, the coupon edit screen, the orders
     * screens and the users list, which are the screens admin-style.css styles.
     *
     * @return bool
     */
    function wcusage_is_plugin_admin_screen() {
        if ( !is_admin() ) {
            return false;
        }
        // Plugin menu pages all use a "wcusage" page slug.
        $page = ( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' );
        // Plus the few plugin pages whose slug does not carry the prefix.
        $pages = apply_filters( 'wcusage_admin_asset_pages', array('signup-page-generator') );
        if ( $page && (strpos( $page, 'wcusage' ) === 0 || in_array( $page, $pages, true )) ) {
            return true;
        }
        if ( !function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        if ( !$screen ) {
            return false;
        }
        $screens = apply_filters( 'wcusage_admin_asset_screens', array(
            'shop_coupon',
            'edit-shop_coupon',
            'shop_order',
            'edit-shop_order',
            'woocommerce_page_wc-orders',
            'users',
            'user-edit',
            'profile',
            'dashboard',
            // The plugin's own post types (PRO): creatives, bonuses, statements,
            // newsletters, PDF reports and short URLs all use admin-style.css.
            'wcu-creatives',
            'wcu-bonuses',
            'wcu-statements',
            'wcu-newsletter',
            'wcu-pdfreports',
            'wcu-short-url',
        ) );
        if ( in_array( $screen->id, $screens, true ) || in_array( $screen->post_type, $screens, true ) ) {
            return true;
        }
        return false;
    }

    // Scripts
    function wcusage_include_scripts_basic() {
        // Return if not WooCommerce
        if ( !function_exists( 'is_woocommerce' ) ) {
            return;
        }
        global $post;
        // Determine whether this page contains a shortcode
        $shortcode_found = false;
        if ( $post ) {
            $post_id = get_the_ID();
            $dashboard_page = wcusage_get_setting_value( 'wcusage_dashboard_page', '' );
            $mla_dashboard_page = wcusage_get_setting_value( 'wcusage_mla_dashboard_page', '' );
            if ( $post_id == $dashboard_page || $post_id == $mla_dashboard_page ) {
                $shortcode_found = true;
            }
            if ( has_shortcode( $post->post_content, 'couponusage' ) || has_shortcode( $post->post_content, 'couponaffiliates' ) || has_shortcode( $post->post_content, 'couponaffiliates-creatives' ) || has_shortcode( $post->post_content, 'couponaffiliates-leaderboard' ) || has_shortcode( $post->post_content, 'couponaffiliates-mla' ) ) {
                $shortcode_found = true;
            }
        }
        $wcusage_field_account_tab_create = wcusage_get_setting_value( 'wcusage_field_account_tab_create', 0 );
        if ( $shortcode_found || is_account_page() && $wcusage_field_account_tab_create ) {
            if ( !is_admin() ) {
                // Ensure core jQuery is available and enqueued (use WordPress bundled version only)
                if ( !wp_script_is( 'jquery', 'enqueued' ) ) {
                    wp_enqueue_script( 'jquery' );
                }
                // Enqueue custom settings script
                wp_enqueue_script(
                    'wcusage-tab-settings',
                    plugin_dir_url( __FILE__ ) . 'js/tab-settings.js',
                    array('jquery'),
                    '1.0.3',
                    true
                );
                // Enqueue custom settings styles
                wp_enqueue_style(
                    'wcusage-tab-settings',
                    plugin_dir_url( __FILE__ ) . 'css/tab-settings.css',
                    array(),
                    '7.0.0'
                );
                // Localize script with necessary data
                wp_localize_script( 'wcusage-tab-settings', 'wcusage_ajax', array(
                    'ajax_url'      => admin_url( 'admin-ajax.php' ),
                    'saving_text'   => __( 'Saving...', 'woo-coupon-usage' ),
                    'save_text'     => __( 'Save changes', 'woo-coupon-usage' ),
                    'required_text' => __( '%s is required.', 'woo-coupon-usage' ),
                ) );
                // Dark Mode - Enqueue styles and scripts if enabled (not for portal)
                $wcusage_field_portal_enable = wcusage_get_setting_value( 'wcusage_field_portal_enable', '0' );
                $wcusage_field_dark_mode_enable = wcusage_get_setting_value( 'wcusage_field_dark_mode_enable', 0 );
                if ( $wcusage_field_dark_mode_enable && !$wcusage_field_portal_enable ) {
                    // Enqueue dark mode CSS
                    wp_enqueue_style(
                        'wcusage-dark-mode',
                        plugin_dir_url( __FILE__ ) . 'css/dark-mode.css',
                        array(),
                        '1.0.0'
                    );
                    // Enqueue dark mode JS
                    wp_enqueue_script(
                        'wcusage-dark-mode',
                        plugin_dir_url( __FILE__ ) . 'js/dark-mode.js',
                        array('jquery'),
                        '1.0.0',
                        true
                    );
                    // Localize dark mode script with settings
                    $wcusage_field_dark_mode_default = wcusage_get_setting_value( 'wcusage_field_dark_mode_default', 0 );
                    wp_localize_script( 'wcusage-dark-mode', 'wcusage_dark_mode', array(
                        'enabled'         => '1',
                        'default'         => ( $wcusage_field_dark_mode_default ? '1' : '0' ),
                        'text_dark_mode'  => __( 'Dark Mode', 'woo-coupon-usage' ),
                        'text_light_mode' => __( 'Light Mode', 'woo-coupon-usage' ),
                    ) );
                }
            }
            // Custom JS Only Loads on Page
            wp_register_script(
                'woo-coupon-usage',
                plugins_url( '/js/woo-coupon-usage.js', __FILE__ ),
                array('jquery'),
                '5.8.0',
                false
            );
            wp_enqueue_script( 'woo-coupon-usage' );
        }
        $wcusage_urls_prefix = wcusage_get_setting_value( 'wcusage_field_urls_prefix', 'coupon' );
        $wcusage_urls_prefix_mla = wcusage_get_setting_value( 'wcusage_urls_prefix_mla', 'mla' );
        if ( isset( $_GET[$wcusage_urls_prefix] ) || isset( $_GET[$wcusage_urls_prefix_mla] ) ) {
            wp_enqueue_script(
                "jquery-cookie",
                WCUSAGE_UNIQUE_PLUGIN_URL . 'js/jquery.cookie.js',
                array(),
                '0'
            );
        }
    }

    add_action( 'wp_enqueue_scripts', 'wcusage_include_scripts_basic' );
    /*** Localization ***/
    add_action( 'init', 'wcusage_load_textdomain' );
    function wcusage_load_textdomain() {
        load_plugin_textdomain( 'woo-coupon-usage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /*** Include Styles ***/
    // Gated the same way the scripts above are. This used to load on every page of
    // the site - the shop front page, every product, every post - whether or not
    // anything from this plugin appeared on it.
    /**
     * Enqueue the front-end stylesheet (and its RTL companion), once.
     *
     * Safe to call late: a style enqueued after wp_head() is printed in the footer.
     * Versioned by file time, so an edit to style.css or style-rtl.css is picked up
     * without a cache purge.
     */
    function wcusage_enqueue_frontend_style() {
        if ( wp_style_is( 'woo-coupon-usage-style', 'enqueued' ) || wp_style_is( 'woo-coupon-usage-style', 'done' ) ) {
            return;
        }
        $style_path = plugin_dir_path( __FILE__ ) . 'css/style.css';
        $style_ver = ( file_exists( $style_path ) ? filemtime( $style_path ) : WCUSAGE_VERSION );
        wp_enqueue_style(
            'woo-coupon-usage-style',
            plugin_dir_url( __FILE__ ) . 'css/style.css',
            array(),
            $style_ver
        );
        // On Hebrew/Arabic/Farsi/Urdu sites, load css/style-rtl.css on top of the
        // above. Passing true rather than 'replace' means it is an override file
        // holding only the mirrored declarations, not a duplicate stylesheet we
        // would have to keep in sync. WordPress only emits it when is_rtl().
        wp_style_add_data( 'woo-coupon-usage-style', 'rtl', true );
    }

    function wcusage_include_plugin_css() {
        if ( !wcusage_page_needs_frontend_assets() ) {
            return;
        }
        wcusage_enqueue_frontend_style();
    }

    add_action( 'wp_enqueue_scripts', 'wcusage_include_plugin_css' );
    /**
     * Safety net for the page check above: whenever one of the plugin's shortcodes
     * actually renders - from a widget, a page-builder template, a theme calling
     * do_shortcode(), anywhere the check could not see - the stylesheet goes out
     * with it.
     */
    function wcusage_enqueue_frontend_style_for_shortcode(  $return, $tag  ) {
        if ( !is_admin() && in_array( $tag, wcusage_frontend_shortcodes(), true ) ) {
            wcusage_enqueue_frontend_style();
        }
        return $return;
    }

    add_filter(
        'pre_do_shortcode_tag',
        'wcusage_enqueue_frontend_style_for_shortcode',
        10,
        2
    );
    /**
     * (PRO) Google Charts loader for the dashboard graphs.
     *
     * Enqueued on wp_enqueue_scripts so it is printed in <head>. Each chart prints
     * an inline google.charts.load() call the moment it renders, and with "load
     * tabs via AJAX" off the statistics tab renders in the middle of the page - a
     * loader that only arrived in the footer was too late for it.
     */
    function wcusage_enqueue_google_charts_loader() {
        return;
        if ( !wcusage_page_needs_frontend_assets() || !wcusage_get_setting_value( 'wcusage_field_show_graphs', 1 ) ) {
            return;
        }
        if ( !wp_script_is( 'wcusage-google-charts', 'enqueued' ) ) {
            wp_enqueue_script(
                'wcusage-google-charts',
                'https://www.gstatic.com/charts/loader.js',
                array(),
                null,
                false
            );
        }
    }

    add_action( 'wp_enqueue_scripts', 'wcusage_enqueue_google_charts_loader' );
    /*** Include Admin Styles ***/
    // 128 KB stylesheet. It used to load on every wp-admin screen in the site -
    // every post edit screen, every other plugin's settings page - so it is now
    // limited to the screens that actually use it.
    function wcusage_include_admin_styles() {
        if ( !wcusage_is_plugin_admin_screen() ) {
            return;
        }
        $plugin_url = plugin_dir_url( __FILE__ );
        $style_path = plugin_dir_path( __FILE__ ) . 'css/admin-style.css';
        $style_ver = ( file_exists( $style_path ) ? filemtime( $style_path ) : WCUSAGE_VERSION );
        wp_enqueue_style(
            'woo-coupon-usage-admin-style',
            $plugin_url . 'css/admin-style.css',
            array(),
            $style_ver
        );
    }

    add_action( 'admin_enqueue_scripts', 'wcusage_include_admin_styles' );
    /*** Enqueue Reports CSS & JS on admin reports page ***/
    function wcusage_enqueue_admin_reports_assets(  $hook  ) {
        if ( !isset( $_GET['page'] ) || $_GET['page'] !== 'wcusage_admin_reports' ) {
            return;
        }
        $plugin_url = plugin_dir_url( __FILE__ );
        wp_enqueue_style(
            'woo-coupon-usage-admin-reports',
            $plugin_url . 'css/admin-reports.css',
            array(),
            WCUSAGE_VERSION
        );
        wp_enqueue_script(
            'html2canvas',
            'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
            array(),
            '1.4.1',
            true
        );
        wp_enqueue_script(
            'jspdf',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            array(),
            '2.5.1',
            true
        );
        wp_enqueue_script(
            'woo-coupon-usage-admin-reports',
            $plugin_url . 'js/admin-reports.js',
            array('jquery', 'html2canvas', 'jspdf'),
            WCUSAGE_VERSION,
            true
        );
    }

    add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_admin_reports_assets' );
    /*** Enqueue Payouts CSS & JS on the payouts admin page ***/
    function wcusage_enqueue_payouts_assets(  $hook  ) {
        $wcusage_current_page = ( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' );
        if ( $wcusage_current_page !== 'wcusage_payouts' && $wcusage_current_page !== 'wcusage_payouts_create' ) {
            return;
        }
        $plugin_url = plugin_dir_url( __FILE__ );
        // Font Awesome (icons used across the payouts toolbar, timeline and create-page accordions).
        wp_enqueue_style(
            'wcusage-font-awesome',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css',
            array(),
            null
        );
        $payouts_css_path = plugin_dir_path( __FILE__ ) . 'css/admin-payouts.css';
        $payouts_css_ver = ( file_exists( $payouts_css_path ) ? filemtime( $payouts_css_path ) : WCUSAGE_VERSION );
        wp_enqueue_style(
            'woo-coupon-usage-admin-payouts',
            $plugin_url . 'css/admin-payouts.css',
            array(),
            $payouts_css_ver
        );
        // Create Payout Requests page: inline "configure payout method" helper.
        if ( $wcusage_current_page === 'wcusage_payouts_create' ) {
            $create_js_path = plugin_dir_path( __FILE__ ) . 'js/admin-payouts-create.js';
            $create_js_ver = ( file_exists( $create_js_path ) ? filemtime( $create_js_path ) : WCUSAGE_VERSION );
            wp_enqueue_script(
                'woo-coupon-usage-admin-payouts-create',
                $plugin_url . 'js/admin-payouts-create.js',
                array('jquery'),
                $create_js_ver,
                true
            );
            wp_localize_script( 'woo-coupon-usage-admin-payouts-create', 'wcusage_payoutcreate_vars', array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'wcusage_set_payout_method' ),
                'assignNonce' => wp_create_nonce( 'wcusage_assign_coupon_user' ),
                'searchNonce' => wp_create_nonce( 'wcusage_search_usernames' ),
                'reloadNonce' => wp_create_nonce( 'wcusage_reload_payoutcreate_cell' ),
                'i18n'        => array(
                    'selectMethod' => esc_html__( 'Please select a payout method.', 'woo-coupon-usage' ),
                    'selectUser'   => esc_html__( 'Please select a user.', 'woo-coupon-usage' ),
                    'saving'       => esc_html__( 'Saving…', 'woo-coupon-usage' ),
                    'noResults'    => esc_html__( 'No users found.', 'woo-coupon-usage' ),
                    'error'        => esc_html__( 'Something went wrong. Please try again.', 'woo-coupon-usage' ),
                ),
            ) );
            return;
        }
        // The scripts below (filter autocomplete, bulk actions) are only used on the main payouts list page.
        if ( $wcusage_current_page !== 'wcusage_payouts' ) {
            return;
        }
        wp_enqueue_script(
            'woo-coupon-usage-admin-payouts',
            $plugin_url . 'js/admin-payouts.js',
            array('jquery', 'jquery-ui-autocomplete'),
            WCUSAGE_VERSION,
            true
        );
        wp_localize_script( 'woo-coupon-usage-admin-payouts', 'wcusage_payouts_vars', array(
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'autoDownloadStatement' => ( isset( $_POST['generatestatementid'] ) ? absint( $_POST['generatestatementid'] ) : 0 ),
            'i18n'                  => array(
                'selectAction'  => __( 'Please select a bulk action.', 'woo-coupon-usage' ),
                'selectPayout'  => __( 'Please select at least one payout.', 'woo-coupon-usage' ),
                'confirmCancel' => __( 'Cancel the selected payouts? This will return funds to unpaid commission for the affiliates.', 'woo-coupon-usage' ),
                'confirmDelete' => __( 'Delete the selected payouts? Only payouts with status Cancelled will be deleted. This action cannot be undone.', 'woo-coupon-usage' ),
            ),
        ) );
    }

    add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_payouts_assets' );
    /*** Enqueue Font Awesome on relevant admin pages (including Statements/Bonuses CPTs) ***/
    function wcusage_enqueue_font_awesome_admin(  $hook  ) {
        $should_enqueue = false;
        // Load on specific plugin admin pages
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wcusage-account' ) {
            $should_enqueue = true;
        }
        // Also load on the Statements and Bonuses custom post type admin screens (list, add, edit)
        if ( !$should_enqueue && function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && isset( $screen->post_type ) && ('wcu-statements' === $screen->post_type || 'wcu-bonuses' === $screen->post_type || 'wcu-creatives' === $screen->post_type) ) {
                $should_enqueue = true;
            }
        }
        if ( $should_enqueue ) {
            wp_enqueue_style(
                'wcusage-font-awesome',
                WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css',
                array(),
                null
            );
        }
    }

    add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_font_awesome_admin' );
    /**
     * Enqueue custom JavaScript for confirming coupon title change.
     */
    function enqueue_coupon_title_change_confirmation() {
        global $post;
        if ( $post && 'shop_coupon' === $post->post_type ) {
            // If coupon meta wcu_select_coupon_user exists
            $coupon_user = get_post_meta( $post->ID, 'wcu_select_coupon_user', true );
            if ( !$coupon_user ) {
                return;
            }
            // Enqueue the script only on coupon edit page
            wp_enqueue_script(
                'coupon-title-change-confirmation',
                plugin_dir_url( __FILE__ ) . 'js/coupon-title-change-confirmation.js',
                // Make sure to adjust the path if needed.
                array('jquery'),
                // Add jQuery as a dependency
                false,
                true
            );
            // Pass the current coupon title to the JavaScript.
            wp_localize_script( 'coupon-title-change-confirmation', 'couponTitleData', array(
                'currentTitle'   => esc_js( $post->post_title ),
                'warningMessage' => __( 'Changing the coupon name may cause the affiliate dashboard statistics to be reset. Are you sure you want to proceed?', 'woo-coupon-usage' ),
            ) );
        }
    }

    add_action( 'admin_enqueue_scripts', 'enqueue_coupon_title_change_confirmation' );
    /*** Include Files ***/
    // Helper: Detect if site likely uses an SMTP plugin for outgoing email.
    if ( !function_exists( 'wcusage_is_smtp_configured' ) ) {
        function wcusage_is_smtp_configured() {
            if ( !function_exists( 'is_plugin_active' ) ) {
                include_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $smtp_plugins = array(
                'wp-mail-smtp/wp_mail_smtp.php',
                'wp-mail-smtp-pro/wp_mail_smtp.php',
                'post-smtp/postman-smtp.php',
                'post-smtp/post-smtp.php',
                'easy-wp-smtp/easy-wp-smtp.php',
                'fluent-smtp/fluent-smtp.php',
                'mailgun/mailgun.php',
                'sendgrid-email-delivery-simplified/wpsendgrid.php',
                'sendinblue/sendinblue.php',
                'smtp-mailer/main.php',
                'gmail-smtp/smtp.php',
                'amazon-ses-smtp/wp-aws-ses.php'
            );
            foreach ( $smtp_plugins as $plug ) {
                if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plug ) ) {
                    return true;
                }
            }
            // Heuristic: additional phpmailer_init hooks often indicate SMTP plugin.
            if ( has_action( 'phpmailer_init' ) ) {
                global $wp_filter;
                $hooks = ( isset( $wp_filter['phpmailer_init'] ) ? $wp_filter['phpmailer_init'] : null );
                if ( $hooks ) {
                    $count = 0;
                    if ( is_object( $hooks ) && property_exists( $hooks, 'callbacks' ) ) {
                        $count = count( $hooks->callbacks );
                    } elseif ( is_array( $hooks ) ) {
                        $count = count( $hooks );
                    }
                    if ( $count > 1 ) {
                        return true;
                    }
                    // >1 implies something else hooked besides core.
                }
            }
            return false;
        }

    }
    /**
     * Whether this request can reach an admin screen or handler.
     *
     * The settings screens, list tables and bulk tools below are only ever
     * rendered in wp-admin, but they were included on every request - about a
     * megabyte of PHP parsed, bound and hooked on front-end page views that can
     * never use any of it.
     *
     * admin-ajax.php and the admin-post handlers both set is_admin(), so gating on
     * it keeps every AJAX action working. REST and WP-CLI are allowed through
     * because plugin code can legitimately reach admin helpers from either.
     *
     * Anything that registers a front-end or cron hook is deliberately NOT gated -
     * see options-currency.php below, which schedules the conversion-rate cron.
     *
     * @return bool
     */
    if ( !function_exists( 'wcusage_is_front_end_ajax' ) ) {
        /**
         * Whether this admin-ajax.php request is one of the front-end dashboard's own.
         *
         * The affiliate dashboard, the floating widget, the leaderboard and the
         * registration form all post to admin-ajax.php, which sets is_admin() - so
         * every one of those front-end requests was loading the plugin's whole
         * wp-admin settings stack. Measured: 441 plugin files (4.6 MB) on a front-end
         * page view against 485 files (6.1 MB) in that context, the difference being
         * 38 settings and list-table files that only ever render inside wp-admin.
         *
         * The list is deliberately an ALLOW list of front-end actions rather than a
         * deny list of admin ones: an action missing from it simply keeps loading the
         * admin files as before, whereas a missing entry in a deny list would leave a
         * wp-admin screen without the file that draws it.
         *
         * @return bool
         */
        function wcusage_is_front_end_ajax() {
            if ( !function_exists( 'wp_doing_ajax' ) || !wp_doing_ajax() ) {
                return false;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; every handler does its own nonce check.
            $action = ( isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '' );
            return wcusage_is_front_end_ajax_action( $action );
        }

    }
    if ( !function_exists( 'wcusage_is_front_end_ajax_action' ) ) {
        /**
         * Whether a named AJAX action belongs to the front end.
         *
         * Kept separate from the request sniffing above because the front-end AJAX
         * endpoint (inc/functions/functions-frontend-ajax.php) uses the same list to
         * decide what it is allowed to dispatch. One list, two callers.
         *
         * @param string $action
         *
         * @return bool
         */
        function wcusage_is_front_end_ajax_action(  $action  ) {
            $action = sanitize_key( (string) $action );
            if ( !$action ) {
                return false;
            }
            // The whole floating-widget surface.
            if ( 0 === strpos( $action, 'wcusage_floating_widget_' ) ) {
                return true;
            }
            $front_end_actions = array(
                // Dashboard tabs.
                'wcusage_load_page_statistics',
                'wcusage_load_page_orders',
                'wcusage_load_page_monthly',
                'wcusage_load_page_payouts',
                'wcusage_load_page_bonuses',
                'wcusage_load_page_orders_mla',
                'wcusage_load_page_summary_mla',
                'wcusage_load_referral_url_stats',
                'wcusage_load_directlinks',
                'wcusage_load_add_directlink',
                'wcusage_load_add_campaign',
                'wcusage_load_delete_campaign',
                'wcusage_load_mlainvites',
                'wcusage_load_add_mlainvite',
                'wcusage_load_mla_sub_registrations',
                'wcusage_mla_sub_reg_action',
                'wcusage_load_short_url',
                'wcusage_load_stripe_link',
                'wcusage_rates_pagination',
                // The affiliate's own settings tab (inc/dashboard/tab-settings.php).
                'wcusage_update_settings',
                'wcusage_send_password_reset',
                // Dashboard statistics refresh.
                'wcusage_refresh_dashboard_stats',
                'wcusage_reload_dashboard_stats',
                'wcusage_update_all_stats_data',
                'wcusage_get_orders_by_coupon_ajax',
                // Shortcodes and public pages.
                'wcusage_leaderboard_render',
                'wcusage_leaderboard_shortcode',
                'wcusage_submit_registration',
                'wcusage_referral_popup_apply_coupon',
                'wcusage_load_direct_domain_coupon_visit',
            );
            return in_array( $action, $front_end_actions, true );
        }

    }
    if ( !function_exists( 'wcusage_is_admin_context' ) ) {
        function wcusage_is_admin_context() {
            static $is_admin_context = null;
            if ( null !== $is_admin_context ) {
                return $is_admin_context;
            }
            $is_admin_context = is_admin() || function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() || defined( 'REST_REQUEST' ) && REST_REQUEST || defined( 'WP_CLI' ) && WP_CLI || defined( 'DOING_CRON' ) && DOING_CRON;
            // ... except for the front-end's own admin-ajax.php requests, which set
            // is_admin() but can never render a wp-admin screen.
            if ( $is_admin_context && wcusage_is_front_end_ajax() ) {
                $is_admin_context = false;
            }
            return $is_admin_context;
        }

    }
    // Admin Settings
    // admin-options.php defines wcusage_get_setting_value() and the rest of the
    // settings API, which the front end depends on - never gate it.
    include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/admin-options.php';
    // options-currency.php registers a 'wp' hook and the twicedaily conversion-rate
    // cron, both of which run outside wp-admin, so it loads unconditionally.
    include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-currency.php';
    if ( wcusage_is_admin_context() ) {
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/admin-options-update.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/admin-setup.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-commission.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-privacy.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-debug.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-design.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-fraud.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-general.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-help.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-notifications.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-subscriptions.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-tabs.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-urls.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-widget.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-payouts.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/options-registrations.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/settings/pro-sales-tabs.php';
    }
    // Admin Affiliate View data/ajax (for AJAX handlers used on admin-ajax.php)
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-view-affiliate-data.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-view-affiliate-refresh.php';
    // Cache Layer (transient cache invalidation — object-cache/Redis safe)
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-cache.php';
    // Index migrations (indexes on tables the plugin queries but does not own)
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-db-indexes.php';
    // Admin Files
    // admin-notification-bell defines wcusage_get_pending_payouts_count(), used by
    // the payouts add-on outside wp-admin, so it is not gated.
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-notification-bell.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-page.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-tools.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-list.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-menu.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-orders-list.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-orders-box.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-url-clicks.php';
    include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-affiliate-users.php';
    if ( wcusage_is_admin_context() ) {
        include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-dashboard.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-pro-details.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-getting-started.php';
        // Classes (WP_List_Table subclasses - only ever rendered in wp-admin)
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-clicks-list-table.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-orders-filter-coupons.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-referrals-table.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-coupon-users-table.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-coupons-table.php';
        // Admin Tools
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-coupons.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-assign-orders.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-restore-registration-fields.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-edit-products.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-edit-coupons.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-import-export.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/tools/admin-bulk-product-rates.php';
    }
    // Activity Log
    $enable_activity_log = wcusage_get_setting_value( 'wcusage_enable_activity_log', '1' );
    if ( $enable_activity_log ) {
        include plugin_dir_path( __FILE__ ) . 'inc/admin/admin-activity.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin/class-activity-list-table.php';
    }
    // Main Functions
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-ajax.php';
    // Must load after functions-ajax.php: the endpoint dispatches to the handlers
    // registered there, and needs them hooked before it runs on init.
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-frontend-ajax.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-update-notice.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-shortcode.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-shortcode-extra.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-shortcode-page.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-dashboard.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-custom-styles.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-general.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-coupon-orders.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-coupon-info.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-coupon-apply.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-commission-message.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-urls.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-url-clicks.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-calculate-order.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-percentage-change.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-uninstall.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-refund.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-all-time.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-new-order.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-user-coupons.php';
    // Affiliate suspension. Loaded after functions-urls.php so its
    // woocommerce_coupon_error filter runs last and its message wins.
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-suspend.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-activity.php';
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-helper.php';
    // Shared data layer behind the affiliate reports (email, PDF and preview).
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-report-data.php';
    // Widget - New organized structure
    $wcusage_field_floating_widget_enable = wcusage_get_setting_value( 'wcusage_field_floating_widget_enable', '0' );
    include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-settings.php';
    if ( $wcusage_field_floating_widget_enable ) {
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-core.php';
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-conditions.php';
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-ajax.php';
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-tabs.php';
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-helpers.php';
        include plugin_dir_path( __FILE__ ) . 'inc/widget/widget-content.php';
    }
    // Portal
    $wcusage_field_portal_enable = wcusage_get_setting_value( 'wcusage_field_portal_enable', '0' );
    if ( $wcusage_field_portal_enable ) {
        include plugin_dir_path( __FILE__ ) . 'inc/portal/affiliate-portal.php';
    }
    // API
    // require, not include: the v1 routes below name these callbacks by string,
    // and WP core calls permission callbacks without an is_callable() check, so
    // a missing file would turn every legacy endpoint into a fatal error.
    require_once plugin_dir_path( __FILE__ ) . 'inc/api/api-v1-permissions.php';
    include plugin_dir_path( __FILE__ ) . 'inc/api/coupon-info.php';
    include plugin_dir_path( __FILE__ ) . 'inc/api/users-coupons.php';
    include plugin_dir_path( __FILE__ ) . 'inc/api/request-payout.php';
    include plugin_dir_path( __FILE__ ) . 'inc/api/v2/load.php';
    // WC Account Tab
    $wcusage_field_account_tab = wcusage_get_setting_value( 'wcusage_field_account_tab', 0 );
    if ( $wcusage_field_account_tab ) {
        include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-wc-tab.php';
    }
    // Subscriptions
    include plugin_dir_path( __FILE__ ) . 'inc/functions/functions-subscriptions.php';
    // Tabs Files
    include plugin_dir_path( __FILE__ ) . 'inc/dashboard/tab-statistics.php';
    include plugin_dir_path( __FILE__ ) . 'inc/dashboard/tab-latest-orders.php';
    include plugin_dir_path( __FILE__ ) . 'inc/dashboard/tab-referral-url.php';
    include plugin_dir_path( __FILE__ ) . 'inc/dashboard/tab-settings.php';
    // Emails
    // Reusable, table-based email building blocks. Loaded before the individual
    // emails because they draw from it.
    include plugin_dir_path( __FILE__ ) . 'inc/emails/functions-email-components.php';
    include plugin_dir_path( __FILE__ ) . 'inc/emails/new-order-email.php';
    $wcusage_cancel_email_enable = wcusage_get_setting_value( 'wcusage_field_cancel_email_enable', '0' );
    if ( $wcusage_cancel_email_enable ) {
        include plugin_dir_path( __FILE__ ) . 'inc/emails/cancelled-email.php';
    }
    // Admin Reports (admin screen + its AJAX handler only)
    if ( wcusage_is_admin_context() ) {
        include plugin_dir_path( __FILE__ ) . 'inc/admin-reports/admin-reports.php';
        include plugin_dir_path( __FILE__ ) . 'inc/admin-reports/ajax-admin-reports.php';
    }
    // Register
    include plugin_dir_path( __FILE__ ) . 'inc/emails/registration-emails.php';
    include plugin_dir_path( __FILE__ ) . 'inc/registration/registration-admin.php';
    include plugin_dir_path( __FILE__ ) . 'inc/registration/registration-form.php';
    include plugin_dir_path( __FILE__ ) . 'inc/registration/functions-registration.php';
    include plugin_dir_path( __FILE__ ) . 'inc/registration/registration-landing-page.php';
    include plugin_dir_path( __FILE__ ) . 'inc/registration/registration-ajax.php';
    $wcusage_field_registration_enable = wcusage_get_setting_value( 'wcusage_field_registration_enable', '1' );
    if ( $wcusage_field_registration_enable ) {
        // Classes
        include plugin_dir_path( __FILE__ ) . 'inc/registration/class-registrations-list-table.php';
    }
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wcusage_add_action_links' );
    function wcusage_add_action_links(  $links  ) {
        $support_link = "https://wordpress.org/support/plugin/woo-coupon-usage/#new-topic-0";
        $mylinks = array('<a href="' . esc_url( admin_url( '/admin.php?page=wcusage_settings' ) ) . '">Settings</a>', '<a href="' . $support_link . '">Support</a>');
        return array_merge( $links, $mylinks );
    }

    function wcusage_fs_is_submenu_visible(  $is_visible, $submenu_id  ) {
        $pro = wcu_fs()->can_use_premium_code();
        $trial = wcu_fs()->is_trial();
        if ( $submenu_id == "contact" ) {
            $is_visible = ( $pro ? true : false );
        }
        if ( $submenu_id == "pricing" ) {
            $is_visible = ( $pro ? false : true );
            if ( $trial ) {
                $is_visible = true;
            }
        }
        if ( $submenu_id == "support" ) {
            $is_visible = ( $pro ? false : true );
        }
        return $is_visible;
    }

    wcu_fs()->add_filter(
        'is_submenu_visible',
        'wcusage_fs_is_submenu_visible',
        10,
        2
    );
    /**
     * Hook the activation function
     */
    if ( !function_exists( 'wcusage_plugin_activation_redirect' ) ) {
        register_activation_hook( __FILE__, 'wcusage_plugin_activation_redirect' );
        function wcusage_plugin_activation_redirect() {
            // Set a transient to trigger the redirect
            set_transient( 'wcusage_activation_redirect', true, 30 );
        }

    }
    /**
     * Hook into admin_init to perform the redirect
     */
    add_action( 'admin_init', 'wcusage_do_activation_redirect' );
    function wcusage_do_activation_redirect() {
        $wcusage_setup_complete = get_option( 'wcusage_setup_complete' );
        // Check if the transient exists
        if ( get_transient( 'wcusage_activation_redirect' ) && !$wcusage_setup_complete ) {
            // Delete the transient so the redirect only happens once
            delete_transient( 'wcusage_activation_redirect' );
            // If Freemius opt-in is still pending, show the Freemius connect screen first.
            if ( function_exists( 'wcu_fs' ) ) {
                $fs = wcu_fs();
                $optin_pending = false;
                if ( method_exists( $fs, 'is_registered' ) && method_exists( $fs, 'is_anonymous' ) ) {
                    // Treat as pending if not registered and not explicitly anonymous (opted-out).
                    $optin_pending = !$fs->is_registered( true ) && !$fs->is_anonymous();
                }
                if ( $optin_pending ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=wcusage_setup' ) );
                    exit;
                }
            }
            // Otherwise proceed to setup wizard as before.
            wp_safe_redirect( admin_url( 'admin.php?page=wcusage_setup' ) );
            exit;
        }
    }

    /**
     * If Freemius opt-in is pending, redirect Settings/Setup pages
     * to the top-level page which will display the connect screen.
     */
    add_action( 'admin_init', function () {
        if ( !is_admin() ) {
            return;
        }
        if ( !function_exists( 'wcu_fs' ) ) {
            return;
        }
        // Only gate our plugin pages.
        $page = ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
        if ( !in_array( $page, array('wcusage_settings', 'wcusage_setup'), true ) ) {
            return;
        }
        $fs = wcu_fs();
        $optin_pending = false;
        if ( method_exists( $fs, 'is_registered' ) && method_exists( $fs, 'is_anonymous' ) && method_exists( $fs, 'is_activation_mode' ) ) {
            $optin_pending = !$fs->is_registered( true ) && !$fs->is_anonymous() && $fs->is_activation_mode();
        }
        if ( $optin_pending ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wcusage' ) );
            exit;
        }
    }, 3 );
}
/**
 * Compatible with WooCommerce HP
 *
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );