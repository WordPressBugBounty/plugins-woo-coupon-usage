<?php
if (!defined('ABSPATH')) {
    exit;
}

// Enqueue jQuery
add_action('wp_enqueue_scripts', 'wcusage_enqueue_settings_scripts');
function wcusage_enqueue_settings_scripts() {
    wp_enqueue_script('jquery');
}

// AJAX Handler
add_action('wp_ajax_wcusage_update_settings', 'wcusage_ajax_update_settings');
function wcusage_ajax_update_settings() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcusage_settings_update')) {
        wp_send_json_error('Invalid nonce');
        wp_die();
    }

    $postid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $currentuserid = get_current_user_id();
    $couponuserid = get_post_meta($postid, 'wcu_select_coupon_user', true);

    if (!$postid || ($couponuserid != $currentuserid && !wcusage_check_admin_access())) {
        wp_send_json_error('Permission denied');
        wp_die();
    }

    // Update notification settings
    $wcu_enable_notifications = isset($_POST['wcu_enable_notifications']) ? sanitize_text_field($_POST['wcu_enable_notifications']) : '0';
    update_post_meta($postid, 'wcu_enable_notifications', $wcu_enable_notifications);

    $enable_reports_user_option = wcusage_get_setting_value('wcusage_field_enable_reports_user_option', 1);
    if ($enable_reports_user_option) {
        $wcu_enable_reports = isset($_POST['wcu_enable_reports']) ? sanitize_text_field($_POST['wcu_enable_reports']) : '0';
        update_post_meta($postid, 'wcu_enable_reports', $wcu_enable_reports);
    }

    $wcu_notifications_extra = isset($_POST['wcu_notifications_extra']) ? sanitize_text_field($_POST['wcu_notifications_extra']) : '';
    update_post_meta($postid, 'wcu_notifications_extra', $wcu_notifications_extra);

    // Update payout settings
    $payout_fields = [
        'payouttype' => 'wcu_payout_type',
        'paypalemail' => 'wcu_paypal',
        'paypalemail2' => 'wcu_paypal2',
        'bankname' => 'wcu_bank_name',
        'banksort' => 'wcu_bank_sort',
        'bankaccount' => 'wcu_bank_account',
        'bankother' => 'wcu_bank_other',
        'bankother2' => 'wcu_bank_other2',
        'bankother3' => 'wcu_bank_other3',
        'bankother4' => 'wcu_bank_other4',
        'paypalemailapi' => 'wcu_paypalapi'
    ];

    $updated_payout_fields = [];
    foreach($payout_fields as $post_key => $meta_key) {
        if(isset($_POST[$post_key])) {
            $value = sanitize_text_field($_POST[$post_key]);
            update_user_meta($couponuserid, $meta_key, $value);
            $updated_payout_fields[$post_key] = $value;
        }
    }

    if (!empty($updated_payout_fields)) {
        do_action('wcusage_hook_dash_update_payment_methods');
    }

    // Update statement (billing) settings
    $billing_fields = [
        'wcu-company' => 'wcu_billing_company',
        'wcu-billing1' => 'wcu_billing_address_1',
        'wcu-billing2' => 'wcu_billing_address_2',
        'wcu-billing3' => 'wcu_billing_address_3',
        'wcu-taxid' => 'wcu_billing_taxid'
    ];

    $updated_billing_fields = [];
    foreach($billing_fields as $post_key => $meta_key) {
        if(isset($_POST[$post_key])) {
            $value = sanitize_text_field($_POST[$post_key]);
            update_user_meta($couponuserid, $meta_key, $value);
            $updated_billing_fields[$post_key] = $value;
        }
    }

    // Update custom account details
    $account_fields = [
        'wcu_first_name' => 'first_name',
        'wcu_last_name' => 'last_name',
        'wcu_display_name' => 'display_name',
        'wcu_email' => 'user_email',
        'wcu_phone' => 'wcu_phone', // Custom field
        'wcu_website' => 'wcu_website' // Custom field
    ];

    $updated_account_fields = [];
    $user_data = ['ID' => $couponuserid]; // Prepare user data array for wp_update_user

    foreach($account_fields as $post_key => $meta_key) {
        if(isset($_POST[$post_key])) {
            $value = $meta_key === 'user_email' ? sanitize_email($_POST[$post_key]) : sanitize_text_field($_POST[$post_key]);
            if($meta_key === 'user_email') {
                $user_data['user_email'] = $value;
            } else {
                update_user_meta($couponuserid, $meta_key, $value);
            }
            $updated_account_fields[$post_key] = $value;
        }
    }

    // Update user data if there are changes
    if (count($user_data) > 1) { // More than just 'ID'
        $result = wp_update_user($user_data);
        if (is_wp_error($result)) {
            wp_send_json_error('Failed to update user: ' . $result->get_error_message());
            wp_die();
        }
    }

    wp_send_json_success([
        'message' => __('Settings updated successfully.', 'woo-coupon-usage'),
        'updated_payout_fields' => $updated_payout_fields,
        'updated_billing_fields' => $updated_billing_fields,
        'updated_account_fields' => $updated_account_fields
    ]);
    wp_die();
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

        // Account details
        $user = get_userdata($couponuserid);
        $first_name = get_user_meta($couponuserid, 'first_name', true);
        $last_name = get_user_meta($couponuserid, 'last_name', true);
        $display_name = $user->display_name;
        $email = $user->user_email;
        $phone = get_user_meta($couponuserid, 'wcu_phone', true);
        $website = get_user_meta($couponuserid, 'wcu_website', true);
        ?>

        <p class="wcu-tab-title settings-title" style="font-size: 22px; margin-bottom: 25px;"><?php echo esc_html__("Settings", "woo-coupon-usage"); ?>:</p>

        <?php if ($couponuserid == $currentuserid || wcusage_check_admin_access()) { ?>

            <div id="wcu-settings-ajax-message"></div>

            <form method="post" class="wcusage_settings_form" id="wcusage-settings-form" data-post-id="<?php echo esc_attr($postid); ?>">
                <?php wp_nonce_field('wcusage_settings_update', 'wcusage_settings_nonce'); ?>
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
                        <!-- Email Notifications Tab -->
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
                        </div>

                        <!-- Payout Settings Tab -->
                        <?php if (wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>
                            <?php if($wcusage_field_payouts_enable) { ?>
                                <div id="tab-payout-settings" class="wcu-settings-tab-pane <?php if($active) { ?>active<?php } ?>">
                                    <?php do_action('wcusage_hook_output_payout_data_section', $postid, ''); ?>
                                </div>
                            <?php } ?>

                            <!-- Statement Settings Tab -->
                            <?php if($wcu_enable_statements && $wcu_enable_statements_data) { ?>
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
                                    <?php $wcusage_field_show_settings_tab_gravatar = wcusage_get_setting_value('wcusage_field_show_settings_tab_gravatar', '1'); ?>
                                    <?php if($wcusage_field_show_settings_tab_gravatar) { ?>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label><?php echo esc_html__('Profile Picture', 'woo-coupon-usage'); ?></label>
                                        <div style="margin-bottom: 10px;" class="profile-picture">
                                            <?php echo get_avatar($couponuserid, 96); // Display Gravatar with 96px size ?>
                                        </div>
                                        <style>
                                        .wcu-settings-tab-content .profile-picture img {
                                            border-radius: 50%;
                                            width: 96px;
                                            height: 96px;
                                        }
                                        </style>
                                        <p style="margin-top: 0px;font-size:12px;"><?php echo esc_html__('Your profile picture is managed via Gravatar. To set or change it, visit '); ?><a href="https://gravatar.com/profile/avatars" target="_blank"><?php echo esc_html__('Gravatar.com'); ?></a>.</p>
                                    </p>
                                    <?php } ?>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_first_name"><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_first_name" name="wcu_first_name" value="<?php echo esc_attr($first_name); ?>" autocomplete="given-name">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_last_name"><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_last_name" name="wcu_last_name" value="<?php echo esc_attr($last_name); ?>" autocomplete="family-name">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_display_name"><?php echo esc_html__('Display Name', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_display_name" name="wcu_display_name" value="<?php echo esc_attr($display_name); ?>" autocomplete="nickname">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_email"><?php echo esc_html__('Email Address', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="email" class="woocommerce-Input woocommerce-Input--email input-text"
                                            id="wcu_email" name="wcu_email" value="<?php echo esc_attr($email); ?>" autocomplete="email">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_phone"><?php echo esc_html__('Phone Number', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_phone" name="wcu_phone" value="<?php echo esc_attr($phone); ?>" autocomplete="tel">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_website"><?php echo esc_html__('Website', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <input type="url" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_website" name="wcu_website" value="<?php echo esc_attr($website); ?>" autocomplete="url">
                                    </p>
                                    <p>
                                        <label for="wcu_password"><?php echo esc_html__('Password', 'woo-coupon-usage'); ?>:</label>
                                        <br/>
                                        <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" target="_blank">
                                            <?php echo esc_html__('Click here to reset your password.', 'woo-coupon-usage'); ?>
                                        </a>
                                    </p>
                                <?php } else { ?>
                                    <p><?php echo esc_html__("Sorry, this coupon is not assigned to you. You can only edit your own account details.", "woo-coupon-usage"); ?></p>
                                    <?php if (wcusage_check_admin_access() && current_user_can('edit_users')) { ?>
                                        <p><?php echo sprintf(esc_html__("[Admin] You can edit the account details for this user in the admin area: %s", "woo-coupon-usage"),
                                            "<a href='" . get_edit_user_link($couponuserid) . "' target='_blank'>" . esc_html__("Edit User", "woo-coupon-usage") . "</a>"); ?></p>
                                        <br/>
                                        <span class='admin-edit-account'>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($first_name); ?></label>
                                            </p>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($last_name); ?></label>
                                            </p>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('Display Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($display_name); ?></label>
                                            </p>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('Email Address', 'woo-coupon-usage'); ?>: <?php echo esc_html($email); ?></label>
                                            </p>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('Phone Number', 'woo-coupon-usage'); ?>: <?php echo esc_html($phone); ?></label>
                                            </p>
                                            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                                <label><?php echo esc_html__('Website', 'woo-coupon-usage'); ?>: <?php echo esc_html($website); ?></label>
                                            </p>
                                        </span>
                                        <style>
                                            .admin-edit-account { opacity: 0.5; }
                                        </style>
                                    <?php } ?>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>

                    <p>
                        <button type="submit" id="wcu-settings-update-button" class="wcu-save-settings-button woocommerce-Button button" name="submitsettingsupdate">
                            <?php echo esc_html__('Save changes', 'woo-coupon-usage'); ?>
                        </button>
                    </p>
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
            (function($) {
                $(document).ready(function() {
                    // Tab switching
                    const tabs = $('.wcu-settings-tab-nav a');
                    const panes = $('.wcu-settings-tab-pane');

                    tabs.on('click', function(e) {
                        e.preventDefault();
                        tabs.parent().removeClass('active');
                        panes.removeClass('active');
                        $(this).parent().addClass('active');
                        $($(this).attr('href')).addClass('active');
                    });

                    // Payout type checker
                    wcusage_check_payout_type();
                    $('#wcu-payout-type').on('change', function() {
                        wcusage_check_payout_type();
                    });

                    function wcusage_check_payout_type() {
                        var currentpayout = $('#wcu-payout-type').val();
                        $('.wcu-payout-type-custom1, .wcu-payout-type-custom2, .wcu-payout-type-banktransfer, .wcu-payout-type-paypalapi, .wcu-payout-type-stripeapi, .wcu-payout-type-credit').hide();
                        
                        if(currentpayout === "custom1") $('.wcu-payout-type-custom1').show();
                        if(currentpayout === "custom2") $('.wcu-payout-type-custom2').show();
                        if(currentpayout === "banktransfer") $('.wcu-payout-type-banktransfer').show();
                        if(currentpayout === "paypalapi") $('.wcu-payout-type-paypalapi').show();
                        if(currentpayout === "stripeapi") $('.wcu-payout-type-stripeapi').show();
                        if(currentpayout === "credit") $('.wcu-payout-type-credit').show();
                    }

                    // AJAX Form Submission
                    $('#wcusage-settings-form').on('submit', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        var $form = $(this);
                        var formData = {
                            action: 'wcusage_update_settings',
                            nonce: $('#wcusage_settings_nonce').val(),
                            post_id: $form.data('post-id'),
                            wcu_enable_notifications: $('#wcu_enable_notifications').is(':checked') ? '1' : '0',
                            wcu_enable_reports: $('#wcu_enable_reports').is(':checked') ? '1' : '0',
                            wcu_notifications_extra: $('#wcu_notifications_extra').val() || '',
                            payouttype: $('#wcu-payout-type').val() || '',
                            paypalemail: $('#wcu-paypal-input').val() || '',
                            paypalemail2: $('#wcu-paypal-input2').val() || '',
                            bankname: $('#wcu-bank-input1').val() || '',
                            banksort: $('#wcu-bank-input2').val() || '',
                            bankaccount: $('#wcu-bank-input3').val() || '',
                            bankother: $('#wcu-bank-input4').val() || '',
                            bankother2: $('#wcu-bank-input5').val() || '',
                            bankother3: $('#wcu-bank-input6').val() || '',
                            bankother4: $('#wcu-bank-input7').val() || '',
                            paypalemailapi: $('#wcu-paypalapi-input').val() || '',
                            'wcu-company': $('#wcu-company').val() || '',
                            'wcu-billing1': $('#wcu-billing1').val() || '',
                            'wcu-billing2': $('#wcu-billing2').val() || '',
                            'wcu-billing3': $('#wcu-billing3').val() || '',
                            'wcu-taxid': $('#wcu-taxid').val() || '',
                            wcu_first_name: $('#wcu_first_name').val() || '',
                            wcu_last_name: $('#wcu_last_name').val() || '',
                            wcu_display_name: $('#wcu_display_name').val() || '',
                            wcu_email: $('#wcu_email').val() || '',
                            wcu_phone: $('#wcu_phone').val() || '',
                            wcu_website: $('#wcu_website').val() || ''
                        };

                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: formData,
                            beforeSend: function() {
                                $('#wcu-settings-update-button')
                                    .prop('disabled', true)
                                    .text('<?php echo esc_html__('Saving...', 'woo-coupon-usage'); ?>');
                                $('#wcu-settings-ajax-message').empty();
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Show success message for 2 seconds
                                    $('#wcu-settings-ajax-message').html(
                                        '<p style="color: green;">' + response.data.message + '</p>'
                                    ).fadeIn().delay(4000).fadeOut();
                                    if(response.data.updated_payout_fields.payouttype) {
                                        $('#wcu-payout-type')
                                            .val(response.data.updated_payout_fields.payouttype)
                                            .trigger('change');
                                    }
                                    // Change button back to "Save changes"
                                    $('#wcu-settings-update-button')
                                        .prop('disabled', false)
                                        .text('<?php echo esc_html__('Save changes', 'woo-coupon-usage'); ?>');
                                    $("#tab-page-settings").trigger('click');
                                } else {
                                    $('#wcu-settings-ajax-message').html(
                                        '<p style="color: red;">Error: ' + (response.data || 'Unknown error') + '</p>'
                                    );
                                }
                            },
                            error: function(xhr, status, error) {
                                $('#wcu-settings-ajax-message').html(
                                    '<p style="color: red;">AJAX Error: ' + error + '</p>'
                                );
                            },
                            complete: function() {
                                $('#wcu-settings-update-button')
                                    .prop('disabled', false)
                                    .text('<?php echo esc_html__('Save changes', 'woo-coupon-usage'); ?>');
                            }
                        });

                        return false;
                    });
                });
            })(jQuery);
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