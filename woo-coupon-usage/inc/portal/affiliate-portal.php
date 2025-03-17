<?php
if(!defined('ABSPATH')) {
    exit;
}

// Register rewrite rule for affiliate portal
add_action('init', 'wcusage_add_affiliate_portal_rewrite_rule');
function wcusage_add_affiliate_portal_rewrite_rule() {
    $wcusage_portal_slug = wcusage_get_setting_value('wcusage_portal_slug', 'affiliate-portal');
    add_rewrite_rule('^' . $wcusage_portal_slug . '/?$', 'index.php?affiliate_portal=1', 'top');
}

// Add custom query variable
add_filter('query_vars', 'wcusage_add_affiliate_portal_query_var');
function wcusage_add_affiliate_portal_query_var($vars) {
    $vars[] = 'affiliate_portal';
    return $vars;
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, 'wcusage_flush_rewrite_rules1');
function wcusage_flush_rewrite_rules1() {
    wcusage_add_affiliate_portal_rewrite_rule();
    flush_rewrite_rules();
}

add_filter('template_include', 'wcusage_load_affiliate_portal_template');
function wcusage_load_affiliate_portal_template($template) {
    if (get_query_var('affiliate_portal')) {
        // Same folder as this file: template.php
        $custom_template = plugin_dir_path(__FILE__) . 'template.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    return $template;
}