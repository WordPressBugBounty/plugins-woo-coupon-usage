<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Tab - Latest Orders
 */
if ( !function_exists( 'wcusage_load_page_orders' ) ) {
    function wcusage_load_page_orders() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        wcusage_load_custom_language_wpml( $_POST["language"] );
        // WPML Support
        ?>
    <script src="<?php 
        echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL );
        ?>/js/woo-coupon-usage.js" async></script>
    <?php 
        if ( $_POST["startdate"] == "" ) {
            $isordersstartset = false;
        } else {
            $isordersstartset = true;
        }
        if ( isset( $_POST["status"] ) ) {
            $status = sanitize_text_field( $_POST["status"] );
        } else {
            $status = "";
        }
        do_action(
            'wcusage_hook_tab_latest_orders',
            sanitize_text_field( $_POST["postid"] ),
            sanitize_text_field( $_POST["couponcode"] ),
            sanitize_text_field( $_POST["startdate"] ),
            sanitize_text_field( $_POST["enddate"] ),
            $isordersstartset,
            sanitize_text_field( $status )
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_page_orders', 'wcusage_load_page_orders' );
add_action( 'wp_ajax_nopriv_wcusage_load_page_orders', 'wcusage_load_page_orders' );
/**
 * Tab - Referral URL Stats
 */
if ( !function_exists( 'wcusage_load_referral_url_stats' ) ) {
    function wcusage_load_referral_url_stats() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        wcusage_load_custom_language_wpml( $_POST["language"] );
        // WPML Support
        ?>
    <script src="<?php 
        echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL );
        ?>/js/woo-coupon-usage.js"></script>
    <?php 
        if ( isset( $_POST["campaign"] ) ) {
            $campaign = sanitize_text_field( $_POST["campaign"] );
        } else {
            $campaign = "";
        }
        do_action(
            'wcusage_hook_tab_referral_url_stats',
            sanitize_text_field( $_POST["postid"] ),
            sanitize_text_field( $_POST["couponcode"] ),
            $campaign,
            sanitize_text_field( $_POST["page"] ),
            sanitize_text_field( $_POST["converted"] )
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_referral_url_stats', 'wcusage_load_referral_url_stats' );
add_action( 'wp_ajax_nopriv_wcusage_load_referral_url_stats', 'wcusage_load_referral_url_stats' );
/**
 * Tab - Statistics
 */
if ( !function_exists( 'wcusage_load_page_statistics' ) ) {
    function wcusage_load_page_statistics() {
        check_ajax_referer( 'wcusage_dashboard_ajax_nonce' );
        wcusage_load_custom_language_wpml( $_POST["language"] );
        // WPML Support
        ?>

    <script src="<?php 
        echo esc_url( WCUSAGE_UNIQUE_PLUGIN_URL );
        ?>/js/woo-coupon-usage.js"></script>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
    google.charts.load('current', {packages: ['corechart', 'line']});
    </script>

    <?php 
        do_action(
            'wcusage_hook_tab_statistics',
            sanitize_text_field( $_POST["postid"] ),
            sanitize_text_field( $_POST["couponcode"] ),
            wcusage_convert_symbols_revert( $_POST["combinedcommission"] ),
            sanitize_text_field( $_POST["refresh"] )
        );
        exit;
    }

}
add_action( 'wp_ajax_wcusage_load_page_statistics', 'wcusage_load_page_statistics' );
add_action( 'wp_ajax_nopriv_wcusage_load_page_statistics', 'wcusage_load_page_statistics' );
// Pro
if ( wcu_fs()->can_use_premium_code() ) {
}