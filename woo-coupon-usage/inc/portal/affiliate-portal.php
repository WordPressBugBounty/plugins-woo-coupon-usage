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

// Function to check if rewrite rule exists
function wcusage_check_affiliate_portal_rewrite_rule() {
    global $wp_rewrite;
    $rules = $wp_rewrite->wp_rewrite_rules();
    $wcusage_portal_slug = wcusage_get_setting_value('wcusage_portal_slug', 'affiliates');
    $rule = '^' . $wcusage_portal_slug . '/?$';
    return isset($rules[$rule]);
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Main query suppressed for affiliate portal");
        }
    }
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

// Load custom template with maximum priority
add_filter('template_include', 'wcusage_load_affiliate_portal_template', PHP_INT_MAX);
function wcusage_load_affiliate_portal_template($template) {
    if (get_query_var('affiliate_portal')) {
        $custom_template = plugin_dir_path(__FILE__) . 'template.php';
        if (file_exists($custom_template)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Initial template set to: $custom_template");
            }
            // Force HTTP 200 and page state
            status_header(200);
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            return $custom_template;
        } else {
            error_log("Template not found: $custom_template");
        }
    }
    return $template;
}

// Debug final template and override if necessary
add_action('wp', function () {
    if (get_query_var('affiliate_portal')) {
        global $wp_query;
        $current_template = get_template();
        // If template isn’t ours, force it
        $custom_template = plugin_dir_path(__FILE__) . 'template.php';
        if (file_exists($custom_template) && $current_template !== $custom_template) {
            include $custom_template;
            exit; // Stop further processing
        }
    }
}, PHP_INT_MAX);