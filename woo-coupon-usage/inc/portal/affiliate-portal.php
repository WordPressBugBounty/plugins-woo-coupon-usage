<?php
if(!defined('ABSPATH')) {
    exit;
}

add_action('wp', 'wcusage_affiliate_portal_redirect_registration');
function wcusage_affiliate_portal_redirect_registration() {
    $wcusage_registration_page = wcusage_get_setting_value('wcusage_registration_page', '0');
    $wcusage_portal_slug = wcusage_get_setting_value('wcusage_portal_slug', 'affiliate-portal');
    if(!$wcusage_registration_page) {
        if(isset( $_POST['submitaffiliateapplication'])) {
            if( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcusage_submit_registration_form1'] ) ), 'wcusage_verify_submit_registration_form1' ) || is_user_logged_in() ) {
                $submit_form = wcusage_post_submit_application(0);
                $status = $submit_form['status'];
                // Redirect to the affiliate portal page
                wp_safe_redirect(home_url('/' . $wcusage_portal_slug . '/?status=' . $status));
                exit;
            }
        }
    }
}

// Normalise the configured portal slug.
//
// The raw setting used to go straight into a rewrite regex registered at 'top' priority,
// ahead of every core and WooCommerce rule. An empty value produced '^/?$', which claims
// the site root, and regex metacharacters in the slug were interpreted as pattern syntax
// rather than matched literally - '.' matching any character, and so on.
//
// Deliberately not sanitize_title(): around thirty other places build the portal link
// straight from this setting, and the field can legitimately hold a nested path such as
// "account/affiliates" - the settings screen auto-fills it from the old dashboard page
// URL. Normalising the slug here but not there would leave every link pointing at a URL
// the rule no longer matches. Only the separators that would claim the site root are
// stripped; preg_quote() in the regex below neutralises everything else.
//
// Pass $wcusage_portal_slug explicitly from the settings save handlers, which hold the
// value that was just written and must not depend on when the options cache refreshes.
function wcusage_get_affiliate_portal_slug( $wcusage_portal_slug = null ) {
    if ( null === $wcusage_portal_slug ) {
        $wcusage_portal_slug = wcusage_get_setting_value('wcusage_portal_slug', 'affiliate-portal');
    }
    $wcusage_portal_slug = trim( trim( (string) $wcusage_portal_slug ), '/' );
    if ( '' === $wcusage_portal_slug ) {
        $wcusage_portal_slug = 'affiliate-portal';
    }
    return $wcusage_portal_slug;
}

// Build the rewrite regex. Shared, so the rule that gets registered and the rule that
// wcusage_check_affiliate_portal_rewrite_rule() looks for can never drift apart.
function wcusage_get_affiliate_portal_rewrite_regex( $wcusage_portal_slug = null ) {
    return '^' . preg_quote( wcusage_get_affiliate_portal_slug( $wcusage_portal_slug ), '/' ) . '/?$';
}

// Register rewrite rule for affiliate portal
add_action('init', 'wcusage_add_affiliate_portal_rewrite_rule');
function wcusage_add_affiliate_portal_rewrite_rule() {
    add_rewrite_rule( wcusage_get_affiliate_portal_rewrite_regex(), 'index.php?affiliate_portal=1', 'top');
}

// Function to check if rewrite rule exists
function wcusage_check_affiliate_portal_rewrite_rule() {
    global $wp_rewrite;
    $rules = $wp_rewrite->wp_rewrite_rules();
    return isset( $rules[ wcusage_get_affiliate_portal_rewrite_regex() ] );
}

// Suppress default query entirely
add_action('pre_get_posts', 'wcusage_handle_affiliate_portal_query', 1);
function wcusage_handle_affiliate_portal_query($query) {
    if (!is_admin() && $query->is_main_query() && $query->get('affiliate_portal')) {
        $query->set('post_type', 'none'); // Invalid post type
        $query->set('posts_per_page', 0);
        $query->set('paged', 1);
        $query->set('pagename', ''); // Prevent page lookup
        $query->is_home = false;
        $query->is_archive = false;
    }
}

// Add custom query variable
add_filter('query_vars', 'wcusage_add_affiliate_portal_query_var');
function wcusage_add_affiliate_portal_query_var($vars) {
    $vars[] = 'affiliate_portal';
    return $vars;
}

// Prevent WordPress from treating the virtual affiliate portal URL as a 404 page.
add_filter('pre_handle_404', 'wcusage_prevent_affiliate_portal_404', 10, 2);
function wcusage_prevent_affiliate_portal_404($preempt, $wp_query) {
    if($wp_query->get('affiliate_portal')) {
        status_header(200);
        $wp_query->is_404 = false;
        return true;
    }
    return $preempt;
}

// Install the rewrite rule whenever it is missing.
//
// This was register_activation_hook(__FILE__, ...), which never fired: WordPress only ever
// fires activate_{plugin} for the main plugin file, and __FILE__ here is an include. The
// rule therefore only reached the database if settings happened to be saved afterwards,
// leaving the portal 404ing on sites that never touched them. Testing the stored rules
// instead covers activation, upgrades and slug changes alike.
add_action('wp_loaded', 'wcusage_flush_rewrite_rules1');
function wcusage_flush_rewrite_rules1() {
    if ( wcusage_check_affiliate_portal_rewrite_rule() ) {
        return;
    }
    // Only attempt this once per slug. Rebuilding the rules is expensive, and without the
    // marker a flush that cannot persist would run again on every single request.
    $wcusage_portal_slug = wcusage_get_affiliate_portal_slug();
    if ( get_option('wcusage_portal_rules_flushed') === $wcusage_portal_slug ) {
        return;
    }
    update_option('wcusage_portal_rules_flushed', $wcusage_portal_slug);
    flush_rewrite_rules();
}

// Load custom template with maximum priority
add_filter('template_include', 'wcusage_load_affiliate_portal_template', PHP_INT_MAX);
function wcusage_load_affiliate_portal_template($template) {
    if (get_query_var('affiliate_portal')) {
        $custom_template = plugin_dir_path(__FILE__) . 'template.php';
        if (file_exists($custom_template)) {
            // Force HTTP 200 and page state
            status_header(200);
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            return $custom_template;
        }
    }
    return $template;
}
