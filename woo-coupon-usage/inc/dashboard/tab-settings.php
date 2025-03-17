<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Displays the settings tab content on affiliate dashboard
 *
 * @param int $postid
 * @param int $couponuserid
 * @return mixed
 */
if (!function_exists('wcusage_tab_settings')) {
    function wcusage_tab_settings($postid, $couponuserid) {
        $options = get_option('wcusage_options');
        $currentuserid = get_current_user_id();

        // Notifications
        $wcu_enable_notifications = get_post_meta($postid, 'wcu_enable_notifications', true);
        if ($wcu_enable_notifications == "") {
            $wcu_enable_notifications = true;
        }

        // Reports
        $wcusage_field_enable_reports = wcusage_get_setting_value('wcusage_field_enable_reports', 1);
        $enable_reports_user_option = wcusage_get_setting_value('wcusage_field_enable_reports_user_option', 1);
        $enable_reports_default = wcusage_get_setting_value('wcusage_field_enable_reports_default', 1);
        if ($enable_reports_user_option) {
            $wcu_enable_reports = get_post_meta($postid, 'wcu_enable_reports', true);
            if ($wcu_enable_reports == "") {
                $wcu_enable_reports = $enable_reports_default;
            }
        }

        // Extra
        $wcu_notifications_extra = get_post_meta($postid, 'wcu_notifications_extra', true);
        $wcusage_email_enable_extra = wcusage_get_setting_value('wcusage_field_email_enable_extra', 1);

        if (isset($_POST['submitsettingsupdate'])) {
            // Email Notifications
            $post_wcu_email_notifications = sanitize_text_field($_POST['wcu_enable_notifications']);
            if ($post_wcu_email_notifications == "") {
                $post_wcu_email_notifications = 0;
            }
            update_post_meta($postid, 'wcu_enable_notifications', $post_wcu_email_notifications);
            $wcu_enable_notifications = get_post_meta($postid, 'wcu_enable_notifications', true);

            // Email Reports
            if ($enable_reports_user_option) {
                $post_wcu_email_reports = isset($_POST['wcu_enable_reports']) ? sanitize_text_field($_POST['wcu_enable_reports']) : "";
                if ($post_wcu_email_reports == "") {
                    $post_wcu_email_reports = 0;
                }
                update_post_meta($postid, 'wcu_enable_reports', $post_wcu_email_reports);
                $wcu_enable_reports = get_post_meta($postid, 'wcu_enable_reports', true);
            }

            // Extra Notifications
            $post_wcu_notifications_extra = isset($_POST['wcu_notifications_extra']) ? sanitize_text_field($_POST['wcu_notifications_extra']) : "";
            update_post_meta($postid, 'wcu_notifications_extra', $post_wcu_notifications_extra);
            $wcu_notifications_extra = get_post_meta($postid, 'wcu_notifications_extra', true);
        }
        ?>

        <p class="wcu-tab-title settings-title" style="font-size: 22px; margin-bottom: 25px;"><?php echo esc_html__("Settings", "woo-coupon-usage"); ?>:</p>

        <?php if ($couponuserid == $currentuserid || wcusage_check_admin_access()) { ?>

            <form method="post" class="wcusage_settings_form">
                <div class="wcu-settings-tabs">
                    <ul class="wcu-settings-tab-nav">
                        <?php $active = 0; ?>
                        <?php if (wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>
                            <?php
                            $wcusage_field_payouts_enable = wcusage_get_setting_value('wcusage_field_payouts_enable', '1');
                            if($wcusage_field_payouts_enable) {
                            $active = 1;
                            ?>
                            <li class="active"><a href="#tab-payout-settings"><?php echo esc_html__("Payout Settings", "woo-coupon-usage"); ?></a></li>
                            <?php } ?>
                            <?php
                            $wcu_enable_statements = wcusage_get_setting_value('wcusage_field_payouts_enable_statements', '0');
                            $wcu_enable_statements_data = wcusage_get_setting_value('wcusage_field_payouts_enable_statements_data', '1');
                            if($wcu_enable_statements && $wcu_enable_statements_data) { ?>
                            <li><a href="#tab-statement-settings"><?php echo esc_html__("Statement Settings", "woo-coupon-usage"); ?></a></li>
                            <?php } ?>
                        <?php } ?>
                        <li <?php if(!$active) { ?>class="active"<?php } ?>><a href="#tab-email-notifications"><?php echo esc_html__("Email Notifications", "woo-coupon-usage"); ?></a></li>
                        <?php if (wcusage_get_setting_value('wcusage_field_show_settings_tab_account', '1')) { ?>
                            <li><a href="#tab-account-details"><?php echo esc_html__("Account Details", "woo-coupon-usage"); ?></a></li>
                        <?php } ?>
                    </ul>

                    <div class="wcu-settings-tab-content">
                        <!-- Email Notifications Tab (includes Reports and Extra) -->
                        <div id="tab-email-notifications" class="wcu-settings-tab-pane <?php if(!$active) { ?>active<?php } ?>">
                            <p><strong><?php echo esc_html__("Email Notification Settings", "woo-coupon-usage"); ?></strong></p>
                            <p><input type="checkbox" id="wcu_enable_notifications" name="wcu_enable_notifications"
                                value="1" <?php if ($wcu_enable_notifications) { ?>checked<?php } ?>>
                                <?php echo esc_html__("Enable Email Notifications", "woo-coupon-usage"); ?></p>

                            <?php if ($enable_reports_user_option && $wcusage_field_enable_reports && wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>
                                <?php
                                $wcusage_field_pdfreports_freq = wcusage_get_setting_value('wcusage_field_pdfreports_freq', 'monthly');
                                $pdfreports_freq = '';
                                if ($wcusage_field_pdfreports_freq == "monthly") {
                                    $pdfreports_freq = esc_html__("Monthly", "woo-coupon-usage");
                                } elseif ($wcusage_field_pdfreports_freq == "weekly") {
                                    $pdfreports_freq = esc_html__("Weekly", "woo-coupon-usage");
                                } elseif ($wcusage_field_pdfreports_freq == "quarterly") {
                                    $pdfreports_freq = esc_html__("Quarterly", "woo-coupon-usage");
                                }
                                ?>
                                <p><input type="checkbox" id="wcu_enable_reports" name="wcu_enable_reports"
                                    value="1" <?php if ($wcu_enable_reports) { ?>checked<?php } ?>>
                                    <?php echo esc_html__("Enable Email Reports", "woo-coupon-usage"); ?> (<?php echo esc_html($pdfreports_freq); ?>)</p>
                            <?php } ?>

                            <?php if ($wcusage_email_enable_extra && wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>
                                <p><?php echo esc_html__("Additional Email Addresses: (Separate with Comma)", "woo-coupon-usage"); ?><br/>
                                    <input type="text" id="wcu_notifications_extra" name="wcu_notifications_extra"
                                        value="<?php echo esc_html($wcu_notifications_extra); ?>" style="width: 400px; max-width: 100%;"
                                        placeholder="example@email.com,another@email.com"></p>
                            <?php } ?>

                            <p>
                              <button type="submit" id="wcu-email-settings-update-button" class="wcu-save-settings-button woocommerce-Button button" name="submitsettingsupdate">
                                  <?php echo esc_html__('Save changes', 'woo-coupon-usage'); ?>
                              </button>
                            </p>
                        </div>

                        <!-- Payout Settings Tab -->
                        <?php if (wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>

                            <?php
                            $wcusage_field_payouts_enable = wcusage_get_setting_value('wcusage_field_payouts_enable', '1');
                            if($wcusage_field_payouts_enable) {
                            ?>
                            <!-- Payout Settings Tab -->
                            <div id="tab-payout-settings" class="wcu-settings-tab-pane <?php if($active) { ?>active<?php } ?>">
                                <?php do_action('wcusage_hook_output_payout_data_section', $postid, ''); ?>
                            </div>
                            <?php } ?>

                            <!-- Statement Settings Tab -->
                            <?php
                            $wcu_enable_statements = wcusage_get_setting_value('wcusage_field_payouts_enable_statements', '0');
                            $wcu_enable_statements_data = wcusage_get_setting_value('wcusage_field_payouts_enable_statements_data', '1');
                            if($wcu_enable_statements && $wcu_enable_statements_data) { ?>
                            <div id="tab-statement-settings" class="wcu-settings-tab-pane">
                                <?php do_action('wcusage_hook_output_statement_data_section', $couponuserid); ?>
                            </div>
                            <?php } ?>

                        <?php } ?>

                        <!-- Account Details Tab -->
                        <?php if (wcusage_get_setting_value('wcusage_field_show_settings_tab_account', '1')) { ?>
                            <div id="tab-account-details" class="wcu-settings-tab-pane">
                                <p class="wcu-settings-header"><strong><?php echo esc_html__("Account Details", "woo-coupon-usage"); ?></strong></p>
                                <?php if ($currentuserid == $couponuserid) { ?>
                                    <?php echo do_shortcode("[wcusage_customer_edit_account_html user='" . $couponuserid . "']"); ?>
                                <?php } else { ?>
                                    <p><?php echo esc_html__("Sorry, this coupon is not assigned to you. You can only edit your own account details.", "woo-coupon-usage"); ?></p>
                                    <?php
                                    if (wcusage_check_admin_access() && current_user_can('edit_users')) {
                                        echo "<p>" . sprintf(esc_html__("[Admin] You can edit the account details for this user in the admin area: %s", "woo-coupon-usage"),
                                            "<a href='" . get_edit_user_link($couponuserid) . "' target='_blank'>" . esc_html__("Edit User", "woo-coupon-usage") . "</a>") . "</p>";
                                        echo "<br/><span class='admin-edit-account'>" . do_shortcode("[wcusage_customer_edit_account_html user='" . $couponuserid . "']") . "</span>";
                                        echo "<style>
                                            .admin-edit-account { opacity: 0.5; }
                                            .admin-edit-account input[type='text'], .admin-edit-account input[type='email'], .admin-edit-account input[type='password'], .admin-edit-account label { pointer-events: none; }
                                            .admin-edit-account button { display: none; pointer-events: none; }
                                        </style>";
                                    }
                                    ?>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </form>

            <style>
                .wcu-settings-tabs { margin-bottom: 20px; }
                .wcu-settings-tab-nav { list-style: none; padding: 0; margin: 0; border-bottom: 1px solid #ddd; display: flex; }
                .wcu-settings-tab-nav li { margin: 0; }
                .wcu-settings-tab-nav a { display: block; padding: 10px 20px; text-decoration: none; color: #0073aa; border-bottom: 2px solid transparent; }
                .wcu-settings-tab-nav li.active a { color: #000; border-bottom: 2px solid #0073aa; }
                .wcu-settings-tab-content { padding: 20px; border: 1px solid #ddd; border-top: none; background: #fff; }
                .wcu-settings-tab-pane { display: none; }
                .wcu-settings-tab-pane.active { display: block; }
            </style>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const tabs = document.querySelectorAll('.wcu-settings-tab-nav a');
                    const panes = document.querySelectorAll('.wcu-settings-tab-pane');

                    tabs.forEach(tab => {
                        tab.addEventListener('click', function (e) {
                            e.preventDefault();
                            tabs.forEach(t => t.parentElement.classList.remove('active'));
                            panes.forEach(p => p.classList.remove('active'));

                            tab.parentElement.classList.add('active');
                            document.querySelector(tab.getAttribute('href')).classList.add('active');
                        });
                    });
                });
            </script>

        <?php } else { ?>
            <br/><p><?php echo esc_html__("Sorry, this coupon is not assigned to you.", "woo-coupon-usage"); ?></p>
        <?php } ?>
        <?php
    }
}
add_action('wcusage_hook_tab_settings', 'wcusage_tab_settings', 10, 2);

/**
 * Gets settings tab for shortcode page
 */
add_action('wcusage_hook_dashboard_tab_content_settings', 'wcusage_dashboard_tab_content_settings', 10, 6);
if (!function_exists('wcusage_dashboard_tab_content_settings')) {
    function wcusage_dashboard_tab_content_settings($postid, $coupon_code, $combined_commission, $wcusage_page_load, $coupon_user_id, $other_affiliate = '') {
        if ($other_affiliate) {
            $coupon_user_id = $other_affiliate;
        }

        $options = get_option('wcusage_options');
        $currentuserid = get_current_user_id();

        if (isset($_POST['page-settings']) || isset($_POST['ml-page-settings']) || $wcusage_page_load == false) { ?>
            <div id="<?php echo $other_affiliate ? 'ml-wcu4' : 'wcu6'; ?>" <?php if (wcusage_get_setting_value('wcusage_field_show_tabs', '1')) { ?>class="wcutabcontent"<?php } ?>>
                <?php
                if ($coupon_user_id != $currentuserid && wcusage_check_admin_access()) {
                    //echo "<p style='margin: 5px 0 0 0; font-size: 12px;'>Admin notice: The 'settings' section is only visible to affiliate users assigned to the coupon. You are also able to see this because you are an administrator.</p>";
                }

                if ($coupon_user_id == $currentuserid || wcusage_check_admin_access()) {
                    do_action('wcusage_hook_tab_settings', $postid, $coupon_user_id);
                } else { ?>
                    <br/><p><?php echo esc_html__("Sorry, this coupon is not assigned to you.", "woo-coupon-usage"); ?></p>
                <?php } ?>
            </div>
            <div style="width: 100%; clear: both; display: inline;"></div>
        <?php } ?>
        <?php
    }
}