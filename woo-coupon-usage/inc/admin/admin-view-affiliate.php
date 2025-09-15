<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check user capabilities
if (!wcusage_check_admin_access()) {
    return;
}

// Get user ID from URL
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if (!$user_id) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Invalid user ID.', 'woo-coupon-usage') . '</p></div>';
    return;
}

$user_info = get_userdata($user_id);
if (!$user_info) {
    echo '<div class="notice notice-error"><p>' . esc_html__('User not found.', 'woo-coupon-usage') . '</p></div>';
    return;
}

// Get affiliate coupons
$coupons = wcusage_get_users_coupons_ids($user_id);

// Handle form submission for user updates
if (isset($_POST['update_user']) && isset($_POST['_wpnonce'])) {
    if (wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id)) {
        // Update basic user information
        $user_data = array(
            'ID' => $user_id,
            'user_email' => sanitize_email($_POST['user_email']),
            'user_url' => esc_url_raw($_POST['user_url']),
        );

        // Update user
        $result = wp_update_user($user_data);

        if (!is_wp_error($result)) {
            // Update user meta
            update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['first_name']));
            update_user_meta($user_id, 'last_name', sanitize_text_field($_POST['last_name']));

            // Handle plugin-specific fields if the save function exists
            if (function_exists('wcusage_save_profile_fields')) {
                wcusage_save_profile_fields($user_id);
            }

            // Handle bonus fields if the save function exists
            if (function_exists('wcusage_save_custom_user_profile_fields')) {
                wcusage_save_custom_user_profile_fields($user_id);
            }

            echo '<div class="notice notice-success"><p>' . esc_html__('User updated successfully.', 'woo-coupon-usage') . '</p></div>';

            // Refresh user info
            $user_info = get_userdata($user_id);
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Error updating user.', 'woo-coupon-usage') . '</p></div>';
        }
    }
}

// Handle form submission for adding new coupon
if (isset($_POST['add_new_coupon']) && isset($_POST['add_coupon_nonce'])) {
    if (wp_verify_nonce($_POST['add_coupon_nonce'], 'admin_add_coupon_for_affiliate')) {
        $coupon_code = sanitize_text_field($_POST['new_coupon_code']);
        $affiliate_username = sanitize_text_field($_POST['affiliate_username']);
        $message = isset($_POST['wcu-message']) ? sanitize_text_field($_POST['wcu-message']) : '';

        // Verify the affiliate username matches the current user
        if ($affiliate_username !== $user_info->user_login) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Invalid affiliate username.', 'woo-coupon-usage') . '</p></div>';
        } else {
            // Check if coupon already exists
            $existing_coupon = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
            if ($existing_coupon) {
                echo '<div class="notice notice-error"><p>' . sprintf(esc_html__('Coupon "%s" already exists.', 'woo-coupon-usage'), esc_html($coupon_code)) . '</p></div>';
            } else {
                // Get template coupon settings
                $template_coupon_code = wcusage_get_setting_value('wcusage_field_registration_coupon_template', '');
                if (!$template_coupon_code) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('No template coupon configured. Please set up a template coupon in the settings.', 'woo-coupon-usage') . '</p></div>';
                } else {
                    // Create new coupon based on template
                    $template_coupon = get_page_by_title($template_coupon_code, OBJECT, 'shop_coupon');
                    if (!$template_coupon) {
                        echo '<div class="notice notice-error"><p>' . sprintf(esc_html__('Template coupon "%s" not found.', 'woo-coupon-usage'), esc_html($template_coupon_code)) . '</p></div>';
                    } else {
                        // Get template coupon data
                        $template_coupon_obj = new WC_Coupon($template_coupon->ID);
                        $template_data = array(
                            'discount_type' => $template_coupon_obj->get_discount_type(),
                            'coupon_amount' => $template_coupon_obj->get_amount(),
                            'individual_use' => $template_coupon_obj->get_individual_use(),
                            'product_ids' => $template_coupon_obj->get_product_ids(),
                            'exclude_product_ids' => $template_coupon_obj->get_excluded_product_ids(),
                            'usage_limit' => $template_coupon_obj->get_usage_limit(),
                            'usage_limit_per_user' => $template_coupon_obj->get_usage_limit_per_user(),
                            'limit_usage_to_x_items' => $template_coupon_obj->get_limit_usage_to_x_items(),
                            'expiry_date' => $template_coupon_obj->get_date_expires() ? $template_coupon_obj->get_date_expires()->date('Y-m-d') : '',
                            'free_shipping' => $template_coupon_obj->get_free_shipping(),
                            'exclude_sale_items' => $template_coupon_obj->get_exclude_sale_items(),
                            'product_categories' => $template_coupon_obj->get_product_categories(),
                            'exclude_product_categories' => $template_coupon_obj->get_excluded_product_categories(),
                            'minimum_amount' => $template_coupon_obj->get_minimum_amount(),
                            'maximum_amount' => $template_coupon_obj->get_maximum_amount(),
                        );

                        // Create new coupon
                        $new_coupon = array(
                            'post_title' => $coupon_code,
                            'post_content' => '',
                            'post_status' => 'publish',
                            'post_author' => 1,
                            'post_type' => 'shop_coupon',
                        );

                        $new_coupon_id = wp_insert_post($new_coupon);

                            if ($new_coupon_id) {
                                // Copy meta from template coupon
                                $template_meta = get_post_custom($template_coupon->ID);
                                if (is_array($template_meta)) {
                                    foreach ($template_meta as $key => $values) {
                                        foreach ($values as $value) {
                                            if (is_serialized($value)) {
                                                $value = unserialize($value);
                                            }
                                            add_post_meta($new_coupon_id, $key, $value);
                                        }
                                    }
                                }

                                // Set affiliate-specific meta
                                update_post_meta($new_coupon_id, 'wcu_select_coupon_user', $user_id);
                                update_post_meta($new_coupon_id, 'wcu_text_unpaid_commission', '0');
                                update_post_meta($new_coupon_id, 'wcu_text_pending_payment_commission', '0');
                                update_post_meta($new_coupon_id, 'usage_count', '0');

                                // Clear stats meta
                                delete_post_meta($new_coupon_id, 'wcu_alltime_stats');
                                delete_post_meta($new_coupon_id, 'wcu_last_refreshed');                            // Send notification email to affiliate
                            if (function_exists('wcusage_email_affiliate_register')) {
                                $user_email = $user_info->user_email;
                                $firstname = get_user_meta($user_id, 'first_name', true);
                                if (empty($firstname)) {
                                    $firstname = $user_info->display_name;
                                }
                                wcusage_email_affiliate_register($user_email, $coupon_code, $firstname, $message);
                            }

                            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Coupon "%s" created successfully and assigned to affiliate.', 'woo-coupon-usage'), esc_html($coupon_code)) . '</p></div>';

                            // Refresh coupons list
                            $coupons = wcusage_get_users_coupons_ids($user_id);
                        } else {
                            echo '<div class="notice notice-error"><p>' . esc_html__('Error creating coupon.', 'woo-coupon-usage') . '</p></div>';
                        }
                    }
                }
            }
        }
    }
}

// Handle individual delete actions (same options as Coupon Affiliate Users page)
if ( isset( $_POST['wcusage_delete_action'] ) && isset( $_POST['wcusage_user_id'] ) ) {
    $action = sanitize_text_field( $_POST['wcusage_delete_action'] );
    $delete_user_id = absint( $_POST['wcusage_user_id'] );
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, 'wcusage_delete_user_' . $delete_user_id ) ) {
        wp_die( 'Security check failed' );
    }
    if ( ! wcusage_check_admin_access() ) {
        wp_die( 'Insufficient permissions' );
    }
    if ( $delete_user_id === get_current_user_id() ) {
        wp_die( 'You cannot delete your own account' );
    }

    $message = '';
    $coupons_for_user = wcusage_get_users_coupons_ids( $delete_user_id );
    switch ( $action ) {
        case 'delete_user':
            wp_delete_user( $delete_user_id );
            $message = 'User deleted successfully.';
            break;
        case 'delete_user_coupons':
            wp_delete_user( $delete_user_id );
            if ( $coupons_for_user ) {
                foreach ( $coupons_for_user as $c_id ) { wp_delete_post( $c_id ); }
            }
            $message = 'User and associated coupons deleted successfully.';
            break;
        case 'unassign_coupons':
            if ( $coupons_for_user ) {
                foreach ( $coupons_for_user as $c_id ) {
                    $c_obj = new WC_Coupon( $c_id );
                    $c_obj->update_meta_data( 'wcu_select_coupon_user', '' );
                    $c_obj->save();
                }
            }
            $message = 'Coupons unassigned from user successfully.';
            break;
        case 'delete_coupons':
            if ( $coupons_for_user ) {
                foreach ( $coupons_for_user as $c_id ) { wp_delete_post( $c_id ); }
            }
            $message = 'User\'s coupons deleted successfully.';
            break;
        default:
            $message = 'Invalid action.';
            break;
    }

    // Redirect with message
    $redirect = add_query_arg( 'wcusage_message', urlencode( $message ), admin_url('admin.php?page=wcusage_view_affiliate&user_id=' . $user_id) );
    wp_safe_redirect( $redirect );
    exit;
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';

    ?>

    <!--- Font Awesome -->
    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

    <?php
    // Enqueue admin view affiliate styles with cache-busting
    $wcusage_admin_aff_css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-view-affiliate.css';
    $wcusage_admin_aff_css_ver = file_exists($wcusage_admin_aff_css_path) ? filemtime($wcusage_admin_aff_css_path) : WCUSAGE_VERSION;
    wp_enqueue_style('wcusage-admin-view-affiliate', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-view-affiliate.css', array(), $wcusage_admin_aff_css_ver);

    // Enqueue shared coupons quick-edit styles to match styling
    $wcusage_coupons_css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-coupons.css';
    $wcusage_coupons_css_ver = file_exists($wcusage_coupons_css_path) ? filemtime($wcusage_coupons_css_path) : WCUSAGE_VERSION;
    wp_enqueue_style('wcusage-coupons-shared', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-coupons.css', array(), $wcusage_coupons_css_ver);

    // Enqueue admin view affiliate scripts
    wp_enqueue_script('jquery-ui-autocomplete');
    $wcusage_admin_aff_js_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'js/admin-view-affiliate.js';
    $wcusage_admin_aff_js_ver = file_exists($wcusage_admin_aff_js_path) ? filemtime($wcusage_admin_aff_js_path) : WCUSAGE_VERSION;
    wp_enqueue_script('wcusage-admin-view-affiliate', WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-view-affiliate.js', array('jquery'), $wcusage_admin_aff_js_ver, true);
    // Conditionally enqueue Google Charts for MLA network tab
    $wcusage_field_mla_enable = wcusage_get_setting_value('wcusage_field_mla_enable', '0');
    $wcusage_premium_active = function_exists('wcu_fs') ? wcu_fs()->can_use_premium_code() : false;
    if ($wcusage_field_mla_enable && $wcusage_premium_active) {
        wp_enqueue_script('google-charts', 'https://www.gstatic.com/charts/loader.js', array(), null, true);
    }
    // Enqueue delete dropdown assets used in Coupon Affiliate Users page
    $wcusage_delete_css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/delete-dropdown.css';
    $wcusage_delete_css_ver = file_exists($wcusage_delete_css_path) ? filemtime($wcusage_delete_css_path) : WCUSAGE_VERSION;
    wp_enqueue_style('wcusage-delete-dropdown', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/delete-dropdown.css', array(), $wcusage_delete_css_ver);
    $wcusage_admin_common_js_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'js/admin.js';
    $wcusage_admin_common_js_ver = file_exists($wcusage_admin_common_js_path) ? filemtime($wcusage_admin_common_js_path) : WCUSAGE_VERSION;
    wp_enqueue_script('wcusage-admin-common', WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin.js', array('jquery'), $wcusage_admin_common_js_ver, true);
    wp_localize_script('wcusage-admin-view-affiliate', 'WCUAdminAffiliateView', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'user_id' => $user_id,
        'per_page' => 20,
        'coupon_nonce' => wp_create_nonce('wcusage_coupon_nonce'),
        'currency_symbol' => get_woocommerce_currency_symbol(),
        'nonce_referrals' => wp_create_nonce('wcusage_affiliate_referrals'),
        'nonce_visits' => wp_create_nonce('wcusage_affiliate_visits'),
    'nonce_payouts' => wp_create_nonce('wcusage_affiliate_payouts'),
    'nonce_activity' => wp_create_nonce('wcusage_affiliate_activity'),
    ));
    ?>

    <div class="wrap wcusage-affiliate-view-page">

        <?php if ( isset($_GET['wcusage_message']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['wcusage_message'] ) ); ?></p></div>
        <?php endif; ?>

    <div class="wrap wcusage-affiliate-view-page">

        <?php echo do_action('wcusage_hook_dashboard_page_header', ''); ?>

        <h1 class="wp-heading-inline"
        style="color: #23282d; font-size: 28px; font-weight: 600; margin-bottom: 10px; align-items: center; gap: 10px;">
            <?php echo get_avatar($user_id, 64, 'identicon', '', array('class' => 'wcusage-user-avatar', 'style' => 'border-radius: 50%; margin-right: 10px; vertical-align: middle;')); ?>
            <span>
                <?php echo sprintf(esc_html__('%s: %s', 'woo-coupon-usage'), wcusage_get_affiliate_text(__('Affiliate', 'woo-coupon-usage')),
                esc_html($user_info->user_login)); ?>
            </span>
            <?php $portal_slug = wcusage_get_setting_value('wcusage_portal_slug', 'affiliate-portal'); ?>
            <?php $preview_nonce = wp_create_nonce('wcusage_preview_affiliate_' . $user_id); ?>
            <a href="<?php echo esc_url(home_url('/' . $portal_slug . '/?userid=' . $user_id . '&preview_nonce=' . $preview_nonce)); ?>"
            class="page-title-action wcusage-preview-button button-primary"
            style="margin-left: 15px; font-size: 12px; padding: 5px 10px;" target="_blank">
                <?php echo esc_html__('View affiliate dashboard as user', 'woo-coupon-usage'); ?>
                <i class="fas fa-external-link-alt" style="margin-left: 5px; font-size: 12px;"></i>
            </a>
        </h1>

    <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_affiliates')); ?>" class="page-title-action wcusage-back-button"
    style="float: right;">
            <i class="fas fa-arrow-left" style="margin-right: 5px;"></i>
            <?php echo esc_html__('Back to Affiliates', 'woo-coupon-usage'); ?>
        </a>

        <?php
        // Delete dropdown actions for this affiliate (4 options)
        $delete_nonce = wp_create_nonce('wcusage_delete_user_' . $user_id);
        ?>
        <div class="wcusage-delete-dropdown" style="float: right; clear: right; margin-top: 8px;">
            <button type="button" class="wcusage-delete-btn" data-user-id="<?php echo esc_attr($user_id); ?>" title="<?php echo esc_attr__('Delete Options', 'woo-coupon-usage'); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
            <div class="wcusage-delete-menu" style="display: none;">
                <a href="#" class="wcusage-delete-option" data-action="delete_user" data-user-id="<?php echo esc_attr($user_id); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>"><?php echo esc_html__('Delete User', 'woo-coupon-usage'); ?></a>
                <a href="#" class="wcusage-delete-option" data-action="delete_user_coupons" data-user-id="<?php echo esc_attr($user_id); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>"><?php echo esc_html__('Delete User & Coupons', 'woo-coupon-usage'); ?></a>
                <a href="#" class="wcusage-delete-option" data-action="unassign_coupons" data-user-id="<?php echo esc_attr($user_id); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>"><?php echo esc_html__('Unassign Coupons', 'woo-coupon-usage'); ?></a>
                <a href="#" class="wcusage-delete-option" data-action="delete_coupons" data-user-id="<?php echo esc_attr($user_id); ?>" data-nonce="<?php echo esc_attr($delete_nonce); ?>"><?php echo esc_html__('Delete Coupons', 'woo-coupon-usage'); ?></a>
            </div>
        </div>


        <!-- Main Content Layout -->
        <div class="wcusage-main-content">
            <!-- Left Content Area -->
            <div class="wcusage-content-left">
                <!-- Tabs -->
                <h2 class="nav-tab-wrapper wcusage-tabs">
                    <a href="#tab-overview" class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-chart-line" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Overview', 'woo-coupon-usage'); ?>
                    </a>
                    <a href="#tab-referrals" class="nav-tab <?php echo $current_tab === 'referrals' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-shopping-cart" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Referrals', 'woo-coupon-usage'); ?>
                    </a>
                    <a href="#tab-visits" class="nav-tab <?php echo $current_tab === 'visits' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-eye" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Visits', 'woo-coupon-usage'); ?>
                    </a>
                    <a href="#tab-payouts" class="nav-tab <?php echo $current_tab === 'payouts' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-dollar-sign" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Payouts', 'woo-coupon-usage'); ?>
                    </a>
                    <a href="#tab-activity" class="nav-tab <?php echo $current_tab === 'activity' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-history" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Activity', 'woo-coupon-usage'); ?>
                    </a>
                    <?php if ($wcusage_field_mla_enable && $wcusage_premium_active && function_exists('wcusage_get_ml_sub_affiliates') && function_exists('wcusage_get_network_chart_item')): ?>
                    <a href="#tab-mla" class="nav-tab <?php echo $current_tab === 'mla' ? 'nav-tab-active' : ''; ?>">
                        <i class="fa-solid fa-network-wired" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('MLA', 'woo-coupon-usage'); ?>
                    </a>
                    <?php endif; ?>
                    <a href="#tab-edit-user" class="nav-tab <?php echo $current_tab === 'edit-user' ? 'nav-tab-active' : ''; ?>">
                        <i class="fas fa-user-edit" style="margin-right: 8px;"></i>
                        <?php echo esc_html__('Edit User', 'woo-coupon-usage'); ?>
                    </a>
                </h2>

                <!-- Tab Content -->
                <div class="wcusage-tab-content">
                    <!-- Overview Tab -->
                    <div id="tab-overview" class="tab-content <?php echo $current_tab === 'overview' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fas fa-chart-line" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('Statistics Overview', 'woo-coupon-usage'); ?>
                        </h3>

                        <?php wcusage_display_affiliate_stats($user_id, 'all'); ?>
                    </div>

                    <!-- Latest Referrals Tab -->
                    <div id="tab-referrals" class="tab-content <?php echo $current_tab === 'referrals' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fas fa-shopping-cart" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('Latest Referrals', 'woo-coupon-usage'); ?>
                        </h3>
                        <div class="wcusage-filters" id="wcusage-referrals-filters" style="margin: 0 0 15px; display:flex; gap:8px; align-items: center;">
                            <label>
                                <?php echo esc_html__('From', 'woo-coupon-usage'); ?>
                                <input type="date" id="referrals-start-date" />
                            </label>
                            <label>
                                <?php echo esc_html__('To', 'woo-coupon-usage'); ?>
                                <input type="date" id="referrals-end-date" />
                            </label>
                            <button class="button" id="referrals-apply-filters"><?php echo esc_html__('Filter', 'woo-coupon-usage'); ?></button>
                        </div>
                        <div id="wcusage-referrals-table-container">
                            <?php wcusage_display_affiliate_referrals($user_id, 1, 20, '', ''); ?>
                        </div>
                    </div>

                    <!-- Visits Tab -->
                    <div id="tab-visits" class="tab-content <?php echo $current_tab === 'visits' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fas fa-eye" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('Latest Clicks / Visits', 'woo-coupon-usage'); ?>
                        </h3>
                        <div class="wcusage-filters" id="wcusage-visits-filters" style="margin: 0 0 15px; display:flex; gap:8px; align-items: center;">
                            <label>
                                <?php echo esc_html__('From', 'woo-coupon-usage'); ?>
                                <input type="date" id="visits-start-date" />
                            </label>
                            <label>
                                <?php echo esc_html__('To', 'woo-coupon-usage'); ?>
                                <input type="date" id="visits-end-date" />
                            </label>
                            <button class="button" id="visits-apply-filters"><?php echo esc_html__('Filter', 'woo-coupon-usage'); ?></button>
                        </div>
                        <div id="wcusage-visits-table-container">
                            <?php wcusage_display_affiliate_visits($user_id, 1, 20, '', ''); ?>
                        </div>
                    </div>

                    <!-- Payouts Tab -->
                    <div id="tab-payouts" class="tab-content <?php echo $current_tab === 'payouts' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fas fa-dollar-sign" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('Payout History', 'woo-coupon-usage'); ?>
                        </h3>
                        <div class="wcusage-filters" id="wcusage-payouts-filters" style="margin: 0 0 15px; display:flex; gap:8px; align-items: center;">
                            <label>
                                <?php echo esc_html__('From', 'woo-coupon-usage'); ?>
                                <input type="date" id="payouts-start-date" />
                            </label>
                            <label>
                                <?php echo esc_html__('To', 'woo-coupon-usage'); ?>
                                <input type="date" id="payouts-end-date" />
                            </label>
                            <button class="button" id="payouts-apply-filters"><?php echo esc_html__('Filter', 'woo-coupon-usage'); ?></button>
                        </div>
                        <div id="wcusage-payouts-table-container">
                            <?php wcusage_display_affiliate_payouts($user_id, 1, 20, '', ''); ?>
                        </div>
                    </div>

                    <!-- Activity Tab -->
                    <div id="tab-activity" class="tab-content <?php echo $current_tab === 'activity' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fas fa-history" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('Activity Log', 'woo-coupon-usage'); ?>
                        </h3>
                        <div class="wcusage-filters" id="wcusage-activity-filters" style="margin: 0 0 15px; display:flex; gap:8px; align-items: center;">
                            <label>
                                <?php echo esc_html__('From', 'woo-coupon-usage'); ?>
                                <input type="date" id="activity-start-date" />
                            </label>
                            <label>
                                <?php echo esc_html__('To', 'woo-coupon-usage'); ?>
                                <input type="date" id="activity-end-date" />
                            </label>
                            <button class="button" id="activity-apply-filters"><?php echo esc_html__('Filter', 'woo-coupon-usage'); ?></button>
                        </div>
                        <div id="wcusage-activity-table-container">
                            <?php if (function_exists('wcusage_affiliate_activity_table')) { wcusage_affiliate_activity_table($user_id, 1, 20, '', ''); } ?>
                        </div>
                    </div>

                    <?php if ($wcusage_field_mla_enable && $wcusage_premium_active && function_exists('wcusage_get_ml_sub_affiliates') && function_exists('wcusage_get_network_chart_item')): ?>
                    <!-- MLA Tab -->
                    <div id="tab-mla" class="tab-content <?php echo $current_tab === 'mla' ? 'active' : ''; ?>">
                        <h3 style="color: #23282d; font-size: 22px; font-weight: 600; margin-bottom: 25px; border-bottom: 2px solid #007cba; padding-bottom: 10px;">
                            <i class="fa-solid fa-network-wired" style="color: #007cba; margin-right: 10px;"></i>
                            <?php echo esc_html__('MLA Network', 'woo-coupon-usage'); ?>
                        </h3>
                        <?php
                        // Build network for this affiliate similar to MLA dashboard
                        $sub_affiliates = wcusage_get_ml_sub_affiliates($user_id);
                        if (empty($sub_affiliates)) {
                            echo '<p>' . esc_html__("This affiliate doesn't currently have any sub-affiliates.", 'woo-coupon-usage') . '</p>';
                        } else {
                            $network_array = '';
                            // Root node (self)
                            $network_array .= wcusage_get_network_chart_item($user_id, $user_id, $user_id);
                            $coupon_ids = array();
                            foreach ($sub_affiliates as $user) {
                                $this_user_id = $user->ID;
                                $get_parents = get_user_meta($this_user_id, 'wcu_ml_affiliate_parents', true);
                                if (!$get_parents) { $get_parents = array(); }
                                $this_users_coupons = wcusage_get_users_coupons_ids($this_user_id);
                                foreach ($this_users_coupons as $this_users_coupon_id) { $coupon_ids[] = $this_users_coupon_id; }
                                $super_affiliate = empty($get_parents) ? 1 : 0;
                                if (!empty($this_users_coupons) && is_array($get_parents)) {
                                    $get_parents = array_reverse($get_parents);
                                    $x = end($get_parents); // Link to top-most parent
                                    if (!$super_affiliate) {
                                        $network_array .= wcusage_get_network_chart_item($this_user_id, $x, $user_id);
                                    }
                                }
                            }
                            $network_array = rtrim($network_array, ',');

                            $wcusage_color_tab = wcusage_get_setting_value('wcusage_field_color_tab', '#333');
                            $mla_network_text = wcusage_get_setting_value('wcusage_field_mla_network_text', '');
                            if ($mla_network_text) {
                                echo '<p>' . wp_kses_post($mla_network_text) . '</p><br/>';
                            }
                            ?>
                            <style>
                                #mla_chart_div .google-visualization-orgchart-linebottom { border-bottom: 2px solid <?php echo esc_attr($wcusage_color_tab); ?> !important; }
                                #mla_chart_div .google-visualization-orgchart-lineleft { border-left: 2px solid <?php echo esc_attr($wcusage_color_tab); ?> !important; }
                                #mla_chart_div .google-visualization-orgchart-lineright { border-right: 2px solid <?php echo esc_attr($wcusage_color_tab); ?> !important; }
                                #mla_chart_div .google-visualization-orgchart-linetop { border-top: 2px solid <?php echo esc_attr($wcusage_color_tab); ?> !important; }
                                /* Remove blue faded background on org chart nodes */
                                #mla_chart_div .google-visualization-orgchart-node,
                                #mla_chart_div .google-visualization-orgchart-node > div {
                                    background: transparent !important;
                                    box-shadow: none !important;
                                    -webkit-box-shadow: none !important;
                                }
                                #mla_chart_div .google-visualization-orgchart-node { border: 1px solid #dddddd !important; }
                                #mla_chart_div { overflow: auto; white-space: nowrap; padding-bottom: 8px; }
                            </style>
                            <div id="mla_chart_div"></div>
                            <script type="text/javascript">
                                (function(){
                                    var drawn = false;
                                    window.WCU_MLA_draw = function(){
                                        if (drawn) return; // draw only once per load
                                        function _draw(){
                                            try{
                                                var data = new google.visualization.DataTable();
                                                data.addColumn('string', 'Name');
                                                data.addColumn('string', 'Manager');
                                                data.addColumn('string', 'ToolTip');
                                                data.addRows([ <?php echo $network_array; ?> ]);
                                                var chart = new google.visualization.OrgChart(document.getElementById('mla_chart_div'));
                                                chart.draw(data, {allowHtml:true});
                                                drawn = true;
                                            }catch(e){ /* ignore until ready */ }
                                        }
                                        if (window.google && window.google.charts) {
                                            if (google.visualization && google.visualization.OrgChart) {
                                                _draw();
                                            } else {
                                                google.charts.load('current', {packages:["orgchart"]});
                                                google.charts.setOnLoadCallback(_draw);
                                            }
                                        }
                                    };
                                })();

                                // Enable click-drag horizontal scroll on the chart area
                                jQuery(function(){
                                    var slider = document.querySelector('#mla_chart_div');
                                    if (!slider) return;
                                    var mouseDown = false, startX = 0, scrollLeft = 0;
                                    function startDragging(e){ mouseDown = true; startX = e.pageX - slider.offsetLeft; scrollLeft = slider.scrollLeft; }
                                    function stopDragging(){ mouseDown = false; }
                                    slider.addEventListener('mousemove', function(e){ if(!mouseDown) return; e.preventDefault(); var x = e.pageX - slider.offsetLeft; var scroll = x - startX; slider.scrollLeft = scrollLeft - scroll; });
                                    slider.addEventListener('mousedown', startDragging, false);
                                    slider.addEventListener('mouseup', stopDragging, false);
                                    slider.addEventListener('mouseleave', stopDragging, false);
                                });
                            </script>
                            <?php
                        }
                        ?>
                    </div>
                    <?php endif; ?>

                    <!-- Edit User Tab -->
                    <div id="tab-edit-user" class="tab-content <?php echo $current_tab === 'edit-user' ? 'active' : ''; ?>">
                        <div>
                            <h3 class="wcusage-form-header">
                                <i class="fas fa-user-edit" style="margin-right: 10px;"></i>
                                <?php echo esc_html__('Edit User', 'woo-coupon-usage'); ?>
                            </h3>

                            <form method="post" action="" class="wcusage-form-body">
                                <?php wp_nonce_field('update-user_' . $user_id); ?>

                                <!-- Basic User Information -->
                                <div class="wcusage-form-section">
                                    <h4><?php echo esc_html__('Basic Information', 'woo-coupon-usage'); ?></h4>

                                    <div class="wcusage-form-row">
                                        <div class="wcusage-form-group">
                                            <label for="user_login"><?php echo esc_html__('Username', 'woo-coupon-usage'); ?></label>
                                            <input type="text" name="user_login" id="user_login" value="<?php echo esc_attr($user_info->user_login); ?>" class="regular-text" readonly />
                                            <small class="description"><?php echo esc_html__('Usernames cannot be changed.', 'woo-coupon-usage'); ?></small>
                                        </div>
                                    </div>

                                    <div class="wcusage-form-row">
                                        <div class="wcusage-form-group">
                                            <label for="first_name"><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?></label>
                                            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>" />
                                        </div>
                                        <div class="wcusage-form-group">
                                            <label for="last_name"><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?></label>
                                            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>" />
                                        </div>
                                    </div>

                                    <div class="wcusage-form-row">
                                        <div class="wcusage-form-group">
                                            <label for="user_email"><?php echo esc_html__('Email', 'woo-coupon-usage'); ?></label>
                                            <input type="email" name="user_email" id="user_email" value="<?php echo esc_attr($user_info->user_email); ?>" />
                                        </div>
                                        <div class="wcusage-form-group">
                                            <label for="user_url"><?php echo esc_html__('Website', 'woo-coupon-usage'); ?></label>
                                            <input type="url" name="user_url" id="user_url" value="<?php echo esc_attr($user_info->user_url); ?>" />
                                        </div>
                                    </div>
                                </div>

                                <?php
                                // Include plugin-specific user profile fields
                                if (function_exists('wcusage_profile_fields')) {
                                    echo '<div class="wcusage-form-section">';
                                    wcusage_profile_fields($user_info);
                                    echo '</div>';
                                }

                                // Include bonus fields if available
                                if (function_exists('wcusage_custom_user_profile_fields')) {
                                    echo '<div class="wcusage-form-section">';
                                    wcusage_custom_user_profile_fields($user_info);
                                    echo '</div>';
                                }
                                ?>

                                <div class="wcusage-form-actions">
                                    <button type="submit" name="update_user" class="wcusage-btn wcusage-btn-primary">
                                        <?php echo esc_html__('Update User', 'woo-coupon-usage'); ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="wcusage-sidebar">
                <!-- Affiliate Information -->
                <div class="wcusage-affiliate-info-box">
                    <h3><?php echo esc_html__('Affiliate Information', 'woo-coupon-usage'); ?></h3>
                    <div class="info-content">
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('Name:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value"><?php echo esc_html($user_info->display_name); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('Email:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value">
                                <a href="mailto:<?php echo esc_attr($user_info->user_email); ?>"><?php echo esc_html($user_info->user_email); ?></a>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('Join Date:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($user_info->user_registered))); ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('Website:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value">
                                <?php
                                $website = isset($user_info->user_url) ? $user_info->user_url : '';
                                if (!empty($website)) {
                                    echo '<a href="' . esc_url($website) . '" target="_blank">' . esc_html($website) . '</a>';
                                } else {
                                    echo esc_html__('Not provided', 'woo-coupon-usage');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('Coupons:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value"><?php echo count($coupons); ?> assigned</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo esc_html__('User Roles:', 'woo-coupon-usage'); ?></span>
                            <span class="info-value">
                                <?php
                                $user_roles = $user_info->roles;
                                if (!empty($user_roles)) {
                                    $role_names = array();
                                    foreach ($user_roles as $role) {
                                        $role_names[] = ucfirst($role);
                                    }
                                    echo esc_html(implode(', ', $role_names));
                                } else {
                                    echo esc_html__('No roles assigned', 'woo-coupon-usage');
                                }
                                ?>
                            </span>
                        </div>
                        <?php
                        // Extra registration details inline
                        $wcu_info_meta = get_user_meta($user_id, 'wcu_info', true);
                        $wcu_promote   = get_user_meta($user_id, 'wcu_promote', true);
                        $wcu_referrer  = get_user_meta($user_id, 'wcu_referrer', true);

                        // Normalize wcu_info into an associative array
                        $wcu_info = array();
                        if (is_array($wcu_info_meta)) {
                            $wcu_info = $wcu_info_meta;
                        } elseif (is_string($wcu_info_meta) && strlen($wcu_info_meta)) {
                            $decoded = json_decode($wcu_info_meta, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $wcu_info = $decoded;
                            } elseif (function_exists('is_serialized') && is_serialized($wcu_info_meta)) {
                                $maybe = maybe_unserialize($wcu_info_meta);
                                if (is_array($maybe)) {
                                    $wcu_info = $maybe;
                                }
                            }
                        }

                        if (!empty($wcu_promote)) : ?>
                            <div class="info-row">
                                <span class="info-label"><?php echo esc_html__('Promote:', 'woo-coupon-usage'); ?></span>
                                <span class="info-value"><?php echo esc_html((string) $wcu_promote); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($wcu_referrer)) : ?>
                            <div class="info-row">
                                <span class="info-label"><?php echo esc_html__('Referrer:', 'woo-coupon-usage'); ?></span>
                                <span class="info-value"><?php echo esc_html((string) $wcu_referrer); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($wcu_info) && is_array($wcu_info)) :
                            foreach ($wcu_info as $key => $val) {
                                if ($val === '' || $val === null) {
                                    continue;
                                }
                                $label = is_string($key) ? trim($key) : (string) $key;
                                if ($label === '') {
                                    $label = __('Field', 'woo-coupon-usage');
                                }
                                if (is_array($val)) {
                                    $flat_vals = array();
                                    foreach ($val as $vv) {
                                        if (is_scalar($vv)) {
                                            $flat_vals[] = (string) $vv;
                                        }
                                    }
                                    $value = implode(', ', $flat_vals);
                                } else {
                                    $value = (string) $val;
                                }
                                ?>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html($label . ':'); ?></span>
                                    <span class="info-value"><?php echo esc_html($value); ?></span>
                                </div>
                            <?php }
                        endif; ?>
                    </div>
                </div>

                <br/>

                <?php
                $wcusage_premium_active = function_exists('wcu_fs') ? wcu_fs()->can_use_premium_code() : false;
                $wcusage_tracking_enable = wcusage_get_setting_value('wcusage_field_tracking_enable', '0');
                if ($wcusage_premium_active && $wcusage_tracking_enable):
                ?>
                <!-- Payout Information -->
                <div class="wcusage-affiliate-info-box">
                    <h3><?php echo esc_html__('Payout Information', 'woo-coupon-usage'); ?></h3>
                    <div class="info-content">
                        <?php
                        global $wpdb;
                        $payouts_table = $wpdb->prefix . 'wcusage_payouts';

                        // Calculate commission values for this user
                        $coupons = wcusage_get_users_coupons_ids($user_id);
                        $total_commission = 0;
                        $unpaid_commission = 0;
                        
                        foreach ($coupons as $coupon) {
                            $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
                            if($wcu_alltime_stats && isset($wcu_alltime_stats['total_commission'])) {
                                $total_commission += (float)$wcu_alltime_stats['total_commission'];
                            }
                            $unpaid_commission += (float) get_post_meta($coupon, 'wcu_text_unpaid_commission', true);
                        }
                        
                        $paid_commission = $total_commission - $unpaid_commission;
                        if($paid_commission < 0) $paid_commission = 0;
                        $processing_commission = $total_commission - ($unpaid_commission + $paid_commission);
                        if($processing_commission < 0) $processing_commission = 0;

                        // Calculate pending payments from payouts table
                        $pending_payments = 0;
                        if ($wpdb->get_var("SHOW TABLES LIKE '$payouts_table'") == $payouts_table) {
                            $pending_payouts = $wpdb->get_results($wpdb->prepare(
                                "SELECT amount FROM $payouts_table WHERE userid = %d AND status IN ('pending', 'created')",
                                $user_id
                            ));
                            foreach ($pending_payouts as $payout) {
                                $pending_payments += (float)$payout->amount;
                            }
                        }

                        // Check if payouts table exists
                        if ($wpdb->get_var("SHOW TABLES LIKE '$payouts_table'") == $payouts_table) {
                            // Get the most recent payout for this user
                            $recent_payout = $wpdb->get_row($wpdb->prepare(
                                "SELECT * FROM $payouts_table WHERE userid = %d ORDER BY id DESC LIMIT 1",
                                $user_id
                            ));

                            if ($recent_payout) {
                                ?>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Payout Method:', 'woo-coupon-usage'); ?></span>
                                    <span class="info-value"><?php echo esc_html(ucfirst($recent_payout->method)); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Unpaid Commission:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Earned from completed orders but not yet paid.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($unpaid_commission); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Pending Payments:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Payout requests currently awaiting approval.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($pending_payments); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Paid Commission:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Successfully paid to affiliate.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($paid_commission); ?></span>
                                </div>
                                <?php if (!empty($recent_payout->notes)): ?>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Notes:', 'woo-coupon-usage'); ?></span>
                                    <span class="info-value"><?php echo esc_html($recent_payout->notes); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php
                            } else {
                                ?>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Payout Method:', 'woo-coupon-usage'); ?></span>
                                    <span class="info-value"><?php echo esc_html__('Not set', 'woo-coupon-usage'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Unpaid Commission:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Earned from completed orders but not yet paid.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($unpaid_commission); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Pending Payments:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Payout requests currently awaiting approval.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($pending_payments); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Paid Commission:', 'woo-coupon-usage'); ?><?php echo wcusage_admin_tooltip(esc_html__('Successfully paid to affiliate.', 'woo-coupon-usage')); ?></span>
                                    <span class="info-value"><?php echo wcusage_format_price($paid_commission); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><?php echo esc_html__('Status:', 'woo-coupon-usage'); ?></span>
                                    <span class="info-value"><?php echo esc_html__('No payouts found', 'woo-coupon-usage'); ?></span>
                                </div>
                                <?php
                            }
                        } else {
                            ?>
                            <div class="info-row">
                                <span class="info-label"><?php echo esc_html__('Status:', 'woo-coupon-usage'); ?></span>
                                <span class="info-value"><?php echo esc_html__('Payout system not enabled', 'woo-coupon-usage'); ?></span>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
        </div>
    </div>

    <?php

/**
 * Display affiliate statistics
 */
function wcusage_display_affiliate_stats($user_id, $coupon_id = 'all') {
    $coupons = $coupon_id === 'all' ? wcusage_get_users_coupons_ids($user_id) : array($coupon_id);

    // Get user info for the form
    $user_info = get_userdata($user_id);

    $total_referrals = 0;
    $total_sales = 0;
    $total_commission = 0;
    $unpaid_commission = 0;
    $show_dashboard_message = false;

    foreach ($coupons as $coupon) {
        // Get stats
        $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
        
        // Get referrals with backup logic
        $coupon_referrals = 0;
        $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
        $wcusage_hide_all_time = wcusage_get_setting_value('wcusage_field_hide_all_time', '0');
        
        if($all_stats && !$wcusage_hide_all_time && isset($wcu_alltime_stats) && isset($wcu_alltime_stats['total_count'])) {
            $coupon_referrals = $wcu_alltime_stats['total_count'];
        }
        if(!$coupon_referrals) {
            global $woocommerce;
            $coupon_code = get_the_title($coupon);
            $c = new WC_Coupon($coupon_code);
            $coupon_referrals = $c->get_usage_count();
        }
        
        $total_referrals += $coupon_referrals;
        
        // Calculate coupon sales with discount subtraction
        $coupon_sales = 0;
        if ($wcu_alltime_stats) {
            if(isset($wcu_alltime_stats['total_orders'])) {
                $coupon_sales = $wcu_alltime_stats['total_orders'];
            }
            if(isset($wcu_alltime_stats['full_discount'])) {
                $discounts = $wcu_alltime_stats['full_discount'];
                $coupon_sales = (float)$coupon_sales - (float)$discounts;
            }
        }
        
        // Calculate coupon commission
        $coupon_commission = isset($wcu_alltime_stats['total_commission']) ? $wcu_alltime_stats['total_commission'] : 0;
        
        // Check if this coupon needs dashboard message
        if ($coupon_referrals > 0 && (!$coupon_sales || !$coupon_commission)) {
            $show_dashboard_message = true;
        }
        
        $total_sales += $coupon_sales;
        $total_commission += $coupon_commission;

        $unpaid_commission += (float) get_post_meta($coupon, 'wcu_text_unpaid_commission', true);
    }

    ?>
    <div class="wcusage-stats-grid">
        <div class="wcusage-stat-box">
            <div class="stat-value"><?php echo esc_html($total_referrals); ?></div>
            <div class="stat-label"><?php echo esc_html__('Total Referrals', 'woo-coupon-usage'); ?></div>
        </div>
        <div class="wcusage-stat-box">
            <div class="stat-value"><?php echo wcusage_format_price($total_sales); ?></div>
            <div class="stat-label"><?php echo esc_html__('Total Sales', 'woo-coupon-usage'); ?></div>
        </div>
        <div class="wcusage-stat-box">
            <div class="stat-value"><?php echo wcusage_format_price($total_commission); ?></div>
            <div class="stat-label"><?php echo esc_html__('Total Commission', 'woo-coupon-usage'); ?></div>
        </div>
    </div>

    <?php if ($show_dashboard_message): ?>
    <div style="margin-top: -20px; margin-bottom: 40px; padding: 5px 10px; border: 1px solid #000000ff; border-radius: 4px; background-color: #ffc2c2ff;">
        <p><strong><?php echo esc_html__('Note:', 'woo-coupon-usage'); ?></strong> <?php echo esc_html__('The affiliate dashboard for one or more coupons needs to be loaded at least once to initially calculate and display complete the statistics.', 'woo-coupon-usage'); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($coupons)): ?>
    <h3><?php echo esc_html__('Affiliates Coupons', 'woo-coupon-usage'); ?></h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Usage', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Sales', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Commission', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Unpaid', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Dashboard', 'woo-coupon-usage'); ?></th>
                <?php if ( wcusage_get_setting_value( 'wcusage_field_urls_enable', 1 ) ) : ?>
                <th><?php echo esc_html__('Link', 'woo-coupon-usage'); ?></th>
                <?php endif; ?>
                <th><?php echo esc_html__('Actions', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $include_referral_col = wcusage_get_setting_value( 'wcusage_field_urls_enable', 1 );
            $colspan = $include_referral_col ? 8 : 7; // total columns in table body rows
            foreach ($coupons as $coupon): ?>
                <?php
                $coupon_title = get_the_title($coupon);
                $wcu_alltime_stats = get_post_meta($coupon, 'wcu_alltime_stats', true);
                
                $all_stats = wcusage_get_setting_value('wcusage_field_enable_coupon_all_stats_meta', '1');
                $wcusage_hide_all_time = wcusage_get_setting_value('wcusage_field_hide_all_time', '0');

                $coupon_referrals = 0;
                if($all_stats && !$wcusage_hide_all_time && isset($wcu_alltime_stats) && isset($wcu_alltime_stats['total_count'])) {
                    $coupon_referrals = $wcu_alltime_stats['total_count'];
                }
                if(!$coupon_referrals) {
                    global $woocommerce;
                    $coupon_code = get_the_title($coupon);
                    $c = new WC_Coupon($coupon_code);
                    $coupon_referrals = $c->get_usage_count();
                }
                
                
                // Calculate coupon sales (same logic as class-coupon-users-table.php)
                $coupon_sales = 0;
                if($wcu_alltime_stats) {
                    if(isset($wcu_alltime_stats['total_orders'])) {
                        $coupon_sales = $wcu_alltime_stats['total_orders'];
                    }
                    if(isset($wcu_alltime_stats['full_discount'])) {
                        $discounts = $wcu_alltime_stats['full_discount'];
                        $coupon_sales = (float)$coupon_sales - (float)$discounts;
                    }
                }
                
                $coupon_commission = isset($wcu_alltime_stats['total_commission']) ? $wcu_alltime_stats['total_commission'] : 0;
                $coupon_unpaid_commission = (float) get_post_meta($coupon, 'wcu_text_unpaid_commission', true);

                // Message for when stats need to be loaded
                $qmessage = esc_html__('The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage');

                // Generate affiliate dashboard URL
                $coupon_info = wcusage_get_coupon_info_by_id($coupon);
                $dashboard_url = isset($coupon_info[4]) ? $coupon_info[4] : '';
                $wcusage_urls_prefix = wcusage_get_setting_value('wcusage_field_urls_prefix', 'coupon');
                ?>
                <tr id="coupon-row-<?php echo esc_attr($coupon); ?>">
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $coupon . '&action=edit' ) ); ?>" title="<?php echo esc_attr__( 'Edit coupon', 'woo-coupon-usage' ); ?>">
                            <?php echo esc_html($coupon_title); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html($coupon_referrals); ?></td>
                    <td>
                        <?php
                        if ($coupon_referrals > 0 && !$coupon_sales) {
                            echo "<span title='" . esc_attr($qmessage) . "'><strong><i class='fa-solid fa-ellipsis'></i></strong></span>";
                        } else {
                            echo wcusage_format_price($coupon_sales);
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ($coupon_referrals > 0 && !$coupon_commission) {
                            echo "<span title='" . esc_attr($qmessage) . "'><strong><i class='fa-solid fa-ellipsis'></i></strong></span>";
                        } else {
                            echo wcusage_format_price($coupon_commission);
                        }
                        ?>
                    </td>
                    <td><?php echo wcusage_format_price($coupon_unpaid_commission); ?></td>
                    <td>
                        <?php if ($dashboard_url): ?>
                            <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank"
                            class="button button-large button-primary wcusage-view-dashboard-btn">
                                <?php echo esc_html__('View Dashboard', 'woo-coupon-usage'); ?>
                                <i class="fas fa-external-link-alt" style="margin-right: 5px;"></i>
                            </a>
                        <?php else: ?>
                            <em><?php echo esc_html__('Not available', 'woo-coupon-usage'); ?></em>
                        <?php endif; ?>
                    </td>
                    <?php if ( wcusage_get_setting_value( 'wcusage_field_urls_enable', 1 ) ) : ?>
                    <td>
                        <?php
                        $ref_link = trailingslashit( get_home_url() );
                        // Keep consistent with coupons table: home_url + ?prefix=code
                        $ref_link = get_home_url() . '?' . $wcusage_urls_prefix . '=' . esc_html( $coupon_title );
                        $input_id = 'wcusageLink' . sanitize_html_class( $coupon_title );
                        ?>
                        <div class="wcusage-copyable-link">
                            <input type="text" id="<?php echo esc_attr($input_id); ?>" class="wcusage-copy-link-text" value="<?php echo esc_url($ref_link); ?>" style="max-width: 220px; width: 75%; max-height: 24px; min-height: 24px; font-size: 12px;" readonly>
                            <button type="button" class="wcusage-copy-link-button" title="<?php echo esc_attr__('Copy', 'woo-coupon-usage'); ?>" style="max-height: 24px; min-height: 24px; background: none; border: 1px solid #ddd; padding: 2px 6px; border-radius: 3px;">
                                <i class="fa-regular fa-copy" style="cursor: pointer;"></i>
                            </button>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php
                        // Actions: stack vertically to save width
                        $edit_link = admin_url( 'post.php?post=' . $coupon . '&action=edit' );
                        $delete_link = wp_nonce_url( admin_url( 'admin.php?page=wcusage_coupons&delete_coupon=' . $coupon ), 'delete_coupon' );
                        ?>
                        <div class="wcusage-actions-inline">
                            <a href="#" class="button button-large button-primary quick-edit-coupon" data-coupon-id="<?php echo esc_attr($coupon); ?>"><?php echo esc_html__('Quick Edit', 'woo-coupon-usage'); ?></a>
                            <a href="<?php echo esc_url($edit_link); ?>" class="wcusage-inline-link"><?php echo esc_html__('Edit', 'woo-coupon-usage'); ?></a>
                            <span class="sep">|</span>
                            <a href="<?php echo esc_url($delete_link); ?>" class="wcusage-inline-link wcusage-delete-link" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this coupon?', 'woo-coupon-usage')); ?>');"><?php echo esc_html__('Delete', 'woo-coupon-usage'); ?></a>
                        </div>
                    </td>
                </tr>
                <?php
                // Prepare current values for quick edit
                $coupon_obj = new WC_Coupon($coupon);
                $desc = $coupon_obj->get_description();
                $discount_type = $coupon_obj->get_discount_type();
                $amount = $coupon_obj->get_amount();
                $free_shipping = $coupon_obj->get_free_shipping() ? 'yes' : 'no';
                $date_expires = $coupon_obj->get_date_expires() ? $coupon_obj->get_date_expires()->date('Y-m-d') : '';
                $min_amount = $coupon_obj->get_minimum_amount();
                $max_amount = $coupon_obj->get_maximum_amount();
                $individual_use = $coupon_obj->get_individual_use() ? 'yes' : 'no';
                $exclude_sale_items = $coupon_obj->get_exclude_sale_items() ? 'yes' : 'no';
                $usage_limit_per_user = $coupon_obj->get_usage_limit_per_user();
                $first_order_only = get_post_meta($coupon, 'wcu_enable_first_order_only', true) === 'yes' ? 'yes' : 'no';
                $coupon_user_id = $coupon_info[1];
                $coupon_user = $coupon_user_id ? get_userdata($coupon_user_id) : null;
                $coupon_username = $coupon_user ? $coupon_user->user_login : '';
                $meta_commission = get_post_meta($coupon, 'wcu_text_coupon_commission', true);
                $meta_commission_fixed_order = get_post_meta($coupon, 'wcu_text_coupon_commission_fixed_order', true);
                $meta_commission_fixed_product = get_post_meta($coupon, 'wcu_text_coupon_commission_fixed_product', true);
                $meta_unpaid = get_post_meta($coupon, 'wcu_text_unpaid_commission', true);
                $meta_pending = get_post_meta($coupon, 'wcu_text_pending_payment_commission', true);
                ?>
                <?php
                // Shared quick edit row (same as coupons list)
                include_once WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/admin/partials/quick-edit-coupon.php';
                wcusage_render_quick_edit_row( $coupon, intval( $colspan ) );
                ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <br/>

    <?php if (!empty($coupons)): ?>
    <div style="margin-top: 20px;">
        <button type="button" id="toggle-add-coupon-form" class="button button-secondary"
        onclick="toggleAddCouponForm()">
            <?php echo esc_html__('Add New Coupon', 'woo-coupon-usage'); ?>
            <i class="fa-solid fa-plus" style="margin-left: 5px;"></i>
        </button>

        <div id="add-coupon-form-container" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <h3><?php echo esc_html__('Add New Coupon for Affiliate', 'woo-coupon-usage'); ?></h3>
            <p><?php echo esc_html__('Create a new coupon and assign it to this affiliate.', 'woo-coupon-usage'); ?></p>

            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('admin_add_coupon_for_affiliate', 'add_coupon_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="affiliate_username"><?php echo esc_html__('Affiliate Username', 'woo-coupon-usage'); ?></label></th>
                        <td>
                            <input name="affiliate_username" type="text" id="affiliate_username" class="regular-text" value="<?php echo esc_attr($user_info->user_login); ?>" readonly>
                            <br/><i style="font-size: 10px;"><?php echo esc_html__('This affiliate will be assigned to the new coupon.', 'woo-coupon-usage'); ?></i>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="new_coupon_code"><?php echo esc_html__('Coupon Code', 'woo-coupon-usage'); ?></label></th>
                        <td>
                            <input name="new_coupon_code" type="text" id="new_coupon_code" class="regular-text" value="" required>
                            <br/><i style="font-size: 10px;"><?php echo esc_html__('Enter the name of the coupon code that will be created.', 'woo-coupon-usage'); ?></i>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcu-message"><?php echo esc_html__('Custom Message', 'woo-coupon-usage'); ?></label></th>
                        <td>
                            <input name="wcu-message" type="text" id="wcu-message" class="regular-text" value="">
                            <br/><i style="font-size: 10px;"><?php echo esc_html__('Optional custom message to include in the notification email.', 'woo-coupon-usage'); ?></i>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="add_new_coupon" class="button button-primary" value="<?php echo esc_html__('Create Coupon', 'woo-coupon-usage'); ?>">
                    <button type="button" class="button button-secondary" onclick="toggleAddCouponForm()"><?php echo esc_html__('Cancel', 'woo-coupon-usage'); ?></button>
                </p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php
}

/**
 * Display affiliate referrals
 */
function wcusage_display_affiliate_referrals($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;

    // Get all coupons assigned to this affiliate
    $coupons = wcusage_get_users_coupons_ids($user_id);

    if (empty($coupons)) {
        echo '<p>' . esc_html__('No coupons assigned to this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // Get coupon codes for these coupons
    $coupon_codes = array();
    foreach ($coupons as $coupon_id) {
        $coupon_code = get_the_title($coupon_id);
        if ($coupon_code) {
            $coupon_codes[] = $coupon_code;
        }
    }

    if (empty($coupon_codes)) {
        echo '<p>' . esc_html__('No valid coupon codes found for this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // Build query to find orders that used any of these coupon codes
    $placeholders = array_fill(0, count($coupon_codes), '%s');
    $in_clause = '(' . implode(',', $placeholders) . ')';

    $statuses = wc_get_order_statuses();
    if (isset($statuses['wc-refunded'])) {
        unset($statuses['wc-refunded']);
    }

    // Date filtering
    $where_date = '';
    $params = $coupon_codes;
    if (!empty($start_date)) {
        $where_date .= " AND p.post_date >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if (!empty($end_date)) {
        $where_date .= " AND p.post_date <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    // Pagination
    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // Count total
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->prefix}posts AS p
         INNER JOIN {$wpdb->prefix}woocommerce_order_items AS woi
            ON p.ID = woi.order_id AND woi.order_item_type = 'coupon' AND woi.order_item_name IN $in_clause
         WHERE p.post_type = 'shop_order'
           AND p.post_status IN ('" . implode("','", array_keys($statuses)) . "')" . $where_date,
        $params
    );
    $total = intval($wpdb->get_var($count_sql));

    // Query to get orders that used any of the affiliate's coupons
    $list_sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID AS order_id, p.post_date AS order_date
        FROM {$wpdb->prefix}posts AS p
        INNER JOIN {$wpdb->prefix}woocommerce_order_items AS woi
            ON p.ID = woi.order_id AND woi.order_item_type = 'coupon' AND woi.order_item_name IN $in_clause
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('" . implode("','", array_keys($statuses)) . "')" . $where_date .
        " ORDER BY p.post_date DESC LIMIT %d OFFSET %d",
        array_merge($params, array($per_page, $offset))
    );

    $orders = $wpdb->get_results($list_sql);

    if (empty($orders)) {
        echo '<p>' . esc_html__('No recent referrals found for this affiliate\'s coupons. This could mean that the assigned coupons have not been used in any orders yet, or the orders are still pending.', 'woo-coupon-usage') . '</p>';
        return;
    }

    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Order ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Customer', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon Code', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Total', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Commission', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order_data): ?>
                <?php
                $order_id = $order_data->order_id;
                $order = wc_get_order($order_id);

                if (!$order) continue;

                $commission = wcusage_order_meta($order_id, 'wcusage_total_commission');
                $billing_first_name = $order->get_billing_first_name();
                $billing_last_name = $order->get_billing_last_name();
                $customer_name = trim($billing_first_name . ' ' . $billing_last_name);
                if (empty($customer_name)) {
                    $customer_name = esc_html__('Guest', 'woo-coupon-usage');
                }

                // Get coupon code used in this order
                $coupon_code = '';
                $used_coupons = $order->get_coupon_codes();
                if (!empty($used_coupons)) {
                    $coupon_code = $used_coupons[0]; // Get first coupon code
                }
                ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>">#<?php echo esc_html($order_id); ?></a></td>
                    <td><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                    <td><?php echo esc_html($customer_name); ?></td>
                    <td><?php echo esc_html($coupon_code); ?></td>
                    <td><?php echo wcusage_format_price($order->get_total()); ?></td>
                    <td><?php echo wcusage_format_price($commission); ?></td>
                    <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php wcusage_render_pagination('referrals', $page, $per_page, $total); ?>
    <?php
}

/**
 * Display affiliate visits
 */
function wcusage_display_affiliate_visits($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;

    // Handle delete click entry
    if(isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        if(isset($_POST['wcu-status-delete']) && wp_verify_nonce($nonce, 'delete_url')) {
            $postid = sanitize_text_field($_POST['wcu-id']);
            wcusage_delete_click_entry($postid);
            echo '<div class="notice notice-success"><p>' . esc_html__('Visit deleted successfully.', 'woo-coupon-usage') . '</p></div>';
        }
    }

    // Get all coupons assigned to this affiliate
    $coupons = wcusage_get_users_coupons_ids($user_id);

    if (empty($coupons)) {
        echo '<p>' . esc_html__('No coupons assigned to this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    $table_name = $wpdb->prefix . 'wcusage_clicks';

    // Check if clicks table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Click tracking is not currently enabled.', 'woo-coupon-usage');
        echo '<br><br>';
        echo sprintf(
            esc_html__('To enable click tracking, go to %s and enable the "Click Tracking" option.', 'woo-coupon-usage'),
            '<a href="' . esc_url(admin_url('admin.php?page=wcusage_settings')) . '">' . esc_html__('Settings', 'woo-coupon-usage') . '</a>'
        );
        echo '</p></div>';
        return;
    }

    // Get recent clicks for any of the affiliate's coupons
    $placeholders = array_fill(0, count($coupons), '%d');
    $in_clause = '(' . implode(',', $placeholders) . ')';

    // Date filtering
    $where_date = '';
    $params = $coupons;
    if (!empty($start_date)) {
        $where_date .= " AND date >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if (!empty($end_date)) {
        $where_date .= " AND date <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    // Pagination
    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // Count total
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE couponid IN $in_clause" . $where_date,
        $params
    );
    $total = intval($wpdb->get_var($count_sql));

    // Fetch page
    $list_sql = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE couponid IN $in_clause" . $where_date . " ORDER BY date DESC LIMIT %d OFFSET %d",
        array_merge($params, array($per_page, $offset))
    );
    $clicks = $wpdb->get_results($list_sql);

    if (empty($clicks)) {
        echo '<p>' . esc_html__('No recent visits found for this affiliate\'s coupons.', 'woo-coupon-usage') . '</p>';
        return;
    }

    ?>
    <table class="wp-list-table widefat fixed striped wcusage-visits-table">
        <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo sprintf(esc_html__('%s Coupon', 'woo-coupon-usage'), wcusage_get_affiliate_text(__('Affiliate', 'woo-coupon-usage'))); ?></th>
                <th><?php echo esc_html__('Landing Page', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Referrer URL', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('IP Address', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Visit Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Converted', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Action', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clicks as $click): ?>
                <?php
                // Get coupon title from coupon ID
                $coupon_title = '';
                $coupon_edit_link = '';
                $uniqueurl = '';
                if ($click->couponid) {
                    $coupon_title = get_the_title($click->couponid);
                    $coupon_info = wcusage_get_coupon_info_by_id($click->couponid);
                    $uniqueurl = isset($coupon_info[4]) ? $coupon_info[4] : '';
                    $coupon_edit_link = admin_url("post.php?post=" . $click->couponid . "&action=edit&classic-editor");
                }

                // Format landing page
                $landing_page_title = '';
                if ($click->page) {
                    $landing_page_title = get_the_title($click->page);
                    if (empty($landing_page_title)) {
                        $landing_page_title = esc_html__('Unknown Page', 'woo-coupon-usage');
                    }
                }

                // Format referrer
                $referrer_display = $click->referrer;
                if (empty($referrer_display)) {
                    $referrer_display = '<em>' . esc_html__('Direct', 'woo-coupon-usage') . '</em>';
                }

                // Format date
                $visit_datetime = strtotime($click->date);
                $formatted_date = date_i18n("M jS, Y (g:ia)", $visit_datetime);

                // Check if converted
                $is_converted = !empty($click->orderid);
                ?>
                <tr>
                    <td><?php echo esc_html($click->id); ?></td>
                    <td>
                        <?php if ($coupon_title): ?>
                            <a href="<?php echo esc_url($uniqueurl); ?>" target="_blank" title="<?php echo esc_attr(sprintf(__('View %s Dashboard', 'woo-coupon-usage'), wcusage_get_affiliate_text(__('Affiliate', 'woo-coupon-usage')))); ?>">
                                <?php echo esc_html($coupon_title); ?>
                            </a>
                        <?php else: ?>
                            <em><?php echo esc_html__('Unknown', 'woo-coupon-usage'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($click->page && $landing_page_title): ?>
                            <a href="<?php echo esc_url(get_permalink($click->page)); ?>" target="_blank" title="<?php echo esc_attr__('View Landing Page', 'woo-coupon-usage'); ?>">
                                <?php echo esc_html($landing_page_title); ?>
                            </a>
                        <?php else: ?>
                            <em><?php echo esc_html__('Unknown', 'woo-coupon-usage'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td><?php echo wp_kses_post($referrer_display); ?></td>
                    <td>
                        <code style="background: #f8f9fa; padding: 2px 4px; border-radius: 3px; font-size: 12px;">
                            <?php echo esc_html($click->ipaddress); ?>
                        </code>
                    </td>
                    <td><?php echo esc_html($formatted_date); ?></td>
                    <td>
                        <?php if ($is_converted): ?>
                            <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                            <?php echo esc_html__('Yes', 'woo-coupon-usage'); ?>
                            <?php if (!empty($click->orderid)): ?>
                                <br/><a href="<?php echo esc_url(get_edit_post_link($click->orderid)); ?>" target="_blank">
                                    #<?php echo esc_html($click->orderid); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="dashicons dashicons-dismiss" style="color: red;"></span>
                            <?php echo esc_html__('No', 'woo-coupon-usage'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" id="submitclick">
                            <input type="text" id="wcu-id" name="wcu-id" value="<?php echo esc_attr($click->id); ?>" style="display: none;">
                            <input type="text" id="wcu-status-delete" name="wcu-status-delete" value="cancel" style="display: none;">
                            <?php wp_nonce_field('delete_url'); ?>
                            <button onClick="return confirm('\nAre you sure you want to delete visit #<?php echo esc_attr($click->id); ?>?');"
                                title="<?php echo esc_attr__('Delete this visit.', 'woo-coupon-usage'); ?>"
                                type="submit" name="submitclickdelete" style="padding: 0; background: 0; border: 0; cursor: pointer; margin-bottom: 5px; color: #B52828;">
                                <i class="fa-solid fa-trash-can"></i> <?php echo esc_html__('Delete', 'woo-coupon-usage'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php wcusage_render_pagination('visits', $page, $per_page, $total); ?>
    <?php
}

/**
 * Display affiliate payouts
 */
function wcusage_display_affiliate_payouts($user_id, $page = 1, $per_page = 20, $start_date = '', $end_date = '') {
    global $wpdb;

    $table_name = $wpdb->prefix . 'wcusage_payouts';

    // Check if payouts table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        echo '<p>' . esc_html__('Payouts system not enabled or table not found.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // Date filtering
    $where_date = '';
    $params = array($user_id);
    if (!empty($start_date)) {
        $where_date .= " AND date >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if (!empty($end_date)) {
        $where_date .= " AND date <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    // Pagination
    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // Count total
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE userid = %d" . $where_date,
        $params
    );
    $total = intval($wpdb->get_var($count_sql));

    // Get payouts for this affiliate
    $list_sql = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE userid = %d" . $where_date . " ORDER BY id DESC LIMIT %d OFFSET %d",
        array_merge($params, array($per_page, $offset))
    );
    $payouts = $wpdb->get_results($list_sql);

    if (empty($payouts)) {
        echo '<p>' . esc_html__('No payout history found.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // Determine if Files column should be shown (based on settings similar to admin payouts page)
    $payouts_enable_invoices = function_exists('wcusage_get_setting_value') ? wcusage_get_setting_value('wcusage_field_payouts_enable_invoices', '0') : '0';
    $payouts_enable_statements = function_exists('wcusage_get_setting_value') ? wcusage_get_setting_value('wcusage_field_payouts_enable_statements', '0') : '0';
    $show_files_column = ($payouts_enable_invoices || $payouts_enable_statements) ? true : false;

    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('ID', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Amount', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Method', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
                <?php if ($show_files_column): ?>
                    <th><?php echo esc_html__('Files', 'woo-coupon-usage'); ?></th>
                <?php endif; ?>
                <th><?php echo esc_html__('Date Requested', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date Paid', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payouts as $payout): ?>
                <?php
                $status_class = '';
                switch ($payout->status) {
                    case 'paid':
                        $status_class = 'status-completed';
                        break;
                    case 'pending':
                        $status_class = 'status-on-hold';
                        break;
                    case 'cancel':
                        $status_class = 'status-cancelled';
                        break;
                    default:
                        $status_class = 'status-processing';
                        break;
                }

                // Get coupon title
                $coupon_title = '';
                if ($payout->couponid) {
                    $coupon_title = get_the_title($payout->couponid);
                }
                // Build files column content similar to admin payouts list
                $files_html = '';
                if ($show_files_column && function_exists('wcusage_files_downloads_buttons')) {
                    $files_html = wcusage_files_downloads_buttons(
                        isset($payout->invoiceid) ? $payout->invoiceid : 0,
                        $payout->id,
                        1,   // always_invoice (show placeholder when enabled but missing)
                        1,   // show_text
                        0,   // download (open in new tab by default)
                        isset($payout->status) ? $payout->status : '',
                        1    // showpending
                    );
                }
                ?>
                <tr>
                    <td><?php echo esc_html($payout->id); ?></td>
                    <td><?php echo esc_html($coupon_title); ?></td>
                    <td><?php echo wcusage_format_price($payout->amount); ?></td>
                    <td><?php echo esc_html($payout->method); ?></td>
                    <td><span class="order-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($payout->status)); ?></span></td>
                    <?php if ($show_files_column): ?>
                        <td><?php echo wp_kses_post($files_html); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payout->date))); ?></td>
                    <td><?php echo ($payout->status === 'paid' && !empty($payout->datepaid)) ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payout->datepaid))) : '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php wcusage_render_pagination('payouts', $page, $per_page, $total); ?>
    <?php
}

// Pagination controls are provided by admin-view-affiliate-data.php

/**
 * AJAX handler for getting affiliate stats
 */
add_action('wp_ajax_wcusage_get_affiliate_stats', 'wcusage_get_affiliate_stats_ajax');
function wcusage_get_affiliate_stats_ajax() {
    check_ajax_referer('wcusage_affiliate_stats', '_wpnonce');

    if (!wcusage_check_admin_access()) {
        wp_die('Access denied');
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $coupon_id = isset($_GET['coupon_id']) ? sanitize_text_field($_GET['coupon_id']) : 'all';

    if (!$user_id) {
        wp_die('Invalid user ID');
    }

    wcusage_display_affiliate_stats($user_id, $coupon_id);
    wp_die();
}

// Referrals/Visits/Payouts AJAX handlers are registered in admin-view-affiliate-data.php (included globally)

/**
 * Display affiliate activity log
 */
function wcusage_display_affiliate_activity($user_id) {
    global $wpdb;

    // Get activity data for this user
    $table_name = $wpdb->prefix . 'wcusage_activity';
    $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY id DESC LIMIT 100", $user_id);
    $activities = $wpdb->get_results($sql, ARRAY_A);

    if (empty($activities)) {
        echo '<p>' . esc_html__('No activity found for this affiliate.', 'woo-coupon-usage') . '</p>';
        return;
    }

    // Display activity in a table format similar to the main activity page
    ?>
    <div style="margin-top: 20px;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                    <th><?php echo esc_html__('Event', 'woo-coupon-usage'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td>
                            <?php echo esc_html(date_i18n('F j, Y (H:i)', strtotime($activity['date']))); ?>
                        </td>
                        <td>
                            <?php
                            $event_message = wcusage_activity_message($activity['event'], $activity['event_id'], $activity['info']);
                            echo wp_kses_post($event_message);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($activities) >= 100): ?>
            <p style="margin-top: 10px; color: #666; font-style: italic;">
                <?php echo esc_html__('Showing the most recent 100 activities. View the full activity log for more details.', 'woo-coupon-usage'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_activity')); ?>" target="_blank">
                    <?php echo esc_html__('View Full Activity Log', 'woo-coupon-usage'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

?>

<script type="text/javascript">
function toggleAddCouponForm() {
    var formContainer = document.getElementById('add-coupon-form-container');
    var button = document.getElementById('toggle-add-coupon-form');

    if (formContainer.style.display === 'none') {
        formContainer.style.display = 'block';
        button.innerHTML = '<?php echo esc_js(__("Hide Add Coupon Form", "woo-coupon-usage")); ?>';
    } else {
        formContainer.style.display = 'none';
        button.innerHTML = '<?php echo esc_js(__("Add New Coupon +", "woo-coupon-usage")); ?>';
    }
}
</script>

<?php
