<?php
if (!defined('ABSPATH')) {
    exit;
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
        if(!$postid) {
            wp_send_json_error('Permission denied: Invalid post ID');
        } elseif ($couponuserid != $currentuserid) {
            wp_send_json_error('Permission denied: You are not assigned to this coupon.');
        } else {
            wp_send_json_error('Permission denied.');
        }
        wp_die();
    }

    // Update notification settings
    $wcu_enable_notifications = isset($_POST['wcu_enable_notifications']) ? sanitize_text_field($_POST['wcu_enable_notifications']) : '0';
    update_post_meta($postid, 'wcu_enable_notifications', $wcu_enable_notifications);
    
    // Newsletter subscription toggle (user meta) - default subscribed (meta absent). If checkbox unchecked we add meta flag.
    $newsletter_subscribed = isset($_POST['wcu_newsletter_subscribed']) ? '1' : '0';
    if($newsletter_subscribed === '1') {
        delete_user_meta($couponuserid, 'wcusage_newsletter_unsubscribed');
    } else {
        update_user_meta($couponuserid, 'wcusage_newsletter_unsubscribed', 1);
    }

    $enable_reports_user_option = wcusage_get_setting_value('wcusage_field_enable_reports_user_option', 1);
    if ($enable_reports_user_option) {
        $wcu_enable_reports = isset($_POST['wcu_enable_reports']) ? sanitize_text_field($_POST['wcu_enable_reports']) : '0';
        update_post_meta($postid, 'wcu_enable_reports', $wcu_enable_reports);
    }

    $wcu_notifications_extra = isset($_POST['wcu_notifications_extra']) ? sanitize_text_field($_POST['wcu_notifications_extra']) : '';
    update_post_meta($postid, 'wcu_notifications_extra', $wcu_notifications_extra);

    // Update SMS notification settings (PRO)
    if (wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) {
        if (wcusage_get_setting_value('wcusage_sms_enable', 0) && wcusage_get_setting_value('wcusage_sms_affiliate_phone_enable', 1)) {
            if (isset($_POST['wcusage_sms_phone'])) {
                $sms_phone = sanitize_text_field($_POST['wcusage_sms_phone']);
                update_user_meta($couponuserid, 'wcusage_sms_phone', $sms_phone);
            }
        }
        if (wcusage_get_setting_value('wcusage_sms_enable', 0) && wcusage_get_setting_value('wcusage_sms_affiliate_optout_enable', 1)) {
            $sms_opted_out = (isset($_POST['wcusage_sms_opted_out']) && $_POST['wcusage_sms_opted_out'] === '1') ? 1 : 0;
            if ($sms_opted_out) {
                update_user_meta($couponuserid, 'wcusage_sms_opted_out', 1);
            } else {
                delete_user_meta($couponuserid, 'wcusage_sms_opted_out');
            }
        }
    }

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
        'paypalemailapi' => 'wcu_paypalapi',
        'wisebank_region' => 'wcu_wisebank_region',
        'wisebank_account_name' => 'wcu_wisebank_account_name',
        'wisebank_account_number' => 'wcu_wisebank_account_number',
        'wisebank_routing_number' => 'wcu_wisebank_routing_number',
        'wisebank_swift_code' => 'wcu_wisebank_swift_code',
        'wisebank_iban' => 'wcu_wisebank_iban',
        'wisebank_sort_code' => 'wcu_wisebank_sort_code',
        'wisebank_bank_name' => 'wcu_wisebank_bank_name',
        'wisebank_bank_address' => 'wcu_wisebank_bank_address',
        'wisebank_country' => 'wcu_wisebank_country',
        'wisebank_address' => 'wcu_wisebank_address',
        'wisebank_city' => 'wcu_wisebank_city',
        'wisebank_postcode' => 'wcu_wisebank_postcode',
        'wisebank_state' => 'wcu_wisebank_state',
        'wisebank_recipient_country' => 'wcu_wisebank_recipient_country'
    ];

    // Handle region-specific account number fields
    $region_account_fields = [
        'wisebank_account_number_us' => 'wcu_wisebank_account_number',
        'wisebank_account_number_uk' => 'wcu_wisebank_account_number',
        'wisebank_account_number_intl' => 'wcu_wisebank_account_number'
    ];

    $updated_payout_fields = [];
    foreach($payout_fields as $post_key => $meta_key) {
        if(isset($_POST[$post_key])) {
            $value = sanitize_text_field($_POST[$post_key]);
            
            // Check if this field should be encrypted
            if (function_exists('wcusage_should_encrypt_field') && wcusage_should_encrypt_field($meta_key)) {
                $value = wcusage_encrypt_bank_data($value);
            }
            
            update_user_meta($couponuserid, $meta_key, $value);
            $updated_payout_fields[$post_key] = sanitize_text_field($_POST[$post_key]); // Return unencrypted for response
        }
    }

    // Handle region-specific account number fields - only update if they have a value
    foreach($region_account_fields as $post_key => $meta_key) {
        if(isset($_POST[$post_key]) && !empty($_POST[$post_key])) {
            $value = sanitize_text_field($_POST[$post_key]);
            
            // Check if this field should be encrypted
            if (function_exists('wcusage_should_encrypt_field') && wcusage_should_encrypt_field($meta_key)) {
                $value = wcusage_encrypt_bank_data($value);
            }
            
            update_user_meta($couponuserid, $meta_key, $value);
            $updated_payout_fields['wisebank_account_number'] = sanitize_text_field($_POST[$post_key]); // Return unencrypted for response
        }
    }

    // Special handling for Wise Bank Transfer - combine individual fields OR handle old textarea format
    if (isset($_POST['wisebank_account_name']) || isset($_POST['wisebank_account_number']) || 
        isset($_POST['wisebank_routing_number']) || isset($_POST['wisebank_swift_code']) || 
        isset($_POST['wisebank_iban']) || isset($_POST['wisebank_sort_code']) || 
        isset($_POST['wisebank_bank_name']) || isset($_POST['wisebank_bank_address']) || 
        isset($_POST['wisebank_country']) || isset($_POST['wisebank_state'])) {
        
        $wisebank_combined = wcusage_combine_wisebank_fields($_POST);
        update_user_meta($couponuserid, 'wcu_wisebank', $wisebank_combined);
        $updated_payout_fields['wisebank'] = $wisebank_combined;
    }
    
    // Handle old textarea format for backwards compatibility
    if (isset($_POST['wisebankapi']) && !empty($_POST['wisebankapi'])) {
        $wisebank_textarea = sanitize_textarea_field($_POST['wisebankapi']);
        update_user_meta($couponuserid, 'wcu_wisebank', $wisebank_textarea);
        $updated_payout_fields['wisebank'] = $wisebank_textarea;
    }

    if (!empty($updated_payout_fields)) {
        // Pass the affiliate the details belong to: the admin screens fire this
        // too, where the current user is the admin rather than the affiliate.
        do_action('wcusage_hook_dash_update_payment_methods', $couponuserid);
    }

    // Update statement (billing) settings. The field list (labels, required,
    // show/hide and any admin-added extra fields) comes from the statements
    // add-on when it is available; free builds keep the original five.
    if (function_exists('wcusage_get_statement_affiliate_fields_visible')) {
        $billing_definitions = wcusage_get_statement_affiliate_fields_visible();
    } else {
        $billing_definitions = [
            'company'   => ['name' => 'wcu-company',  'meta' => 'wcu_billing_company',   'label' => esc_html__('Company Name', 'woo-coupon-usage'),   'required' => false],
            'address_1' => ['name' => 'wcu-billing1', 'meta' => 'wcu_billing_address_1', 'label' => esc_html__('Address Line 1', 'woo-coupon-usage'), 'required' => false],
            'address_2' => ['name' => 'wcu-billing2', 'meta' => 'wcu_billing_address_2', 'label' => esc_html__('Address Line 2', 'woo-coupon-usage'), 'required' => false],
            'address_3' => ['name' => 'wcu-billing3', 'meta' => 'wcu_billing_address_3', 'label' => esc_html__('Address Line 3', 'woo-coupon-usage'), 'required' => false],
            'taxid'     => ['name' => 'wcu-taxid',    'meta' => 'wcu_billing_taxid',     'label' => esc_html__('Tax/VAT Number', 'woo-coupon-usage'), 'required' => false],
        ];
    }

    // Collect and check the submitted values before writing any of them, so a
    // required field left blank cannot leave the section half saved. A field that
    // is absent was not part of this submission (e.g. the affiliate saved another
    // card), so its stored value is left alone.
    $submitted_billing = [];
    foreach($billing_definitions as $billing_field) {
        $post_key = $billing_field['name'];
        if(!isset($_POST[$post_key])) {
            continue;
        }
        $value = sanitize_text_field($_POST[$post_key]);
        if(!empty($billing_field['required']) && $value === '') {
            wp_send_json_error(sprintf(
                // translators: %s: the name of the required field.
                esc_html__('%s is required.', 'woo-coupon-usage'),
                esc_html( $billing_field['label'] )
            ));
            wp_die();
        }
        $submitted_billing[$post_key] = ['meta' => $billing_field['meta'], 'value' => $value];
    }

    $updated_billing_fields = [];
    foreach($submitted_billing as $post_key => $billing) {
        update_user_meta($couponuserid, $billing['meta'], $billing['value']);
        $updated_billing_fields[$post_key] = $billing['value'];
    }

    // Update custom account details
    $account_fields = [
        'wcu_first_name' => 'first_name',
        'wcu_last_name' => 'last_name',
        'wcu_display_name' => 'display_name',
        'wcu_email' => 'user_email',
        'wcu_phone' => 'wcu_phone',
        'wcu_website' => 'wcu_website'
    ];

    $updated_account_fields = [];
    $user_data = ['ID' => $couponuserid];

    // If $couponuserid matches current user ID
    if($couponuserid == get_current_user_id()) {
        foreach($account_fields as $post_key => $meta_key) {
            if($meta_key === 'user_email') {
                // Skip the email update if the field was not part of this submission.
                // It may be absent, or present but empty when the "Account Details" tab
                // is hidden (the form sends an empty string). The field is client-side
                // "required" when shown, so an empty value here means it is not being
                // edited and must not trigger a false "Email is required." error.
                if(!isset($_POST[$post_key]) || $_POST[$post_key] === '') {
                    continue;
                }
                // Check email is valid
                if(!is_email($_POST[$post_key])) {
                    wp_send_json_error(esc_html__('Invalid account email address.', 'woo-coupon-usage'));
                    wp_die();
                }
                // Check email does not already exist (for a different user)
                $existing_email_user_id = email_exists($_POST[$post_key]);
                if($existing_email_user_id && $existing_email_user_id != $couponuserid) {
                    wp_send_json_error(esc_html__('Email already exists.', 'woo-coupon-usage'));
                    wp_die();
                }
            }
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

        // Update registration custom fields (stored in 'wcu_info', keyed by field label).
        // Only touch fields that were actually submitted; preserve any other stored keys.
        $wcu_show_custom_fields = wcusage_get_setting_value('wcusage_field_show_settings_tab_custom_fields', '1');
        if ($wcu_show_custom_fields) {
            $custom_fields_number = (int) wcusage_get_setting_value('wcusage_field_registration_custom_fields', '2');
            $custom_info = function_exists('wcusage_get_user_custom_fields') ? wcusage_get_user_custom_fields($couponuserid) : array();
            $custom_changed = false;
            for ($cx = 1; $cx <= $custom_fields_number; $cx++) {
                $post_key = 'wcu_account_custom_' . $cx;
                if (!isset($_POST[$post_key])) {
                    continue;
                }
                $ctype = wcusage_get_setting_value('wcusage_field_registration_custom_type_' . $cx, '');
                if ($ctype === 'header' || $ctype === 'paragraph') {
                    continue;
                }
                // Only save fields the admin marked editable by the user.
                if (!wcusage_get_setting_value('wcusage_field_registration_custom_editable_' . $cx, '1')) {
                    continue;
                }
                $clabel = html_entity_decode(sanitize_text_field(wcusage_get_setting_value('wcusage_field_registration_custom_label_' . $cx, '')));
                if ($clabel === '') {
                    continue;
                }
                $custom_info[$clabel] = sanitize_text_field(wp_unslash($_POST[$post_key]));
                $custom_changed = true;
            }
            if ($custom_changed) {
                update_user_meta($couponuserid, 'wcu_info', wp_json_encode($custom_info));
                $updated_account_fields['custom_fields'] = $custom_info;
            }
        }
    } else {
        // Error message
        wp_send_json_error('Permission denied: You can only update your own account details.');
        wp_die();
    }

    // Handle state field for US bank accounts
    if (isset($_POST['wcu_wisebank_state'])) {
        $state = sanitize_text_field($_POST['wcu_wisebank_state']);
        update_user_meta($couponuserid, 'wcu_wisebank_state', $state);
    }

    if (count($user_data) > 1) {
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
 * AJAX: send a password reset link to the logged-in user.
 *
 * Behaves like the WooCommerce/WordPress "Lost password" form: it emails a
 * reset link to the current user's own registered address. It never accepts a
 * target account from the request, so it can only ever reset the requester's
 * own password.
 */
add_action('wp_ajax_wcusage_send_password_reset', 'wcusage_ajax_send_password_reset');
function wcusage_ajax_send_password_reset() {
    // CSRF check.
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcusage_password_reset')) {
        wp_send_json_error(esc_html__('Invalid request. Please refresh the page and try again.', 'woo-coupon-usage'));
        wp_die();
    }

    // Must be logged in — only the current user's own password can be reset.
    if (!is_user_logged_in()) {
        wp_send_json_error(esc_html__('You must be logged in to reset your password.', 'woo-coupon-usage'));
        wp_die();
    }

    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
        wp_send_json_error(esc_html__('Unable to determine your account.', 'woo-coupon-usage'));
        wp_die();
    }

    // Light throttle to prevent repeated emails being triggered.
    $throttle_key = 'wcu_pwd_reset_' . $user->ID;
    if (get_transient($throttle_key)) {
        wp_send_json_error(esc_html__('A password reset email was just sent. Please check your inbox, or try again in a minute.', 'woo-coupon-usage'));
        wp_die();
    }

    // Core WP: generates a secure reset key and sends the (WooCommerce-templated, if active) email.
    $result = retrieve_password($user->user_login);
    if (is_wp_error($result)) {
        $message = wp_strip_all_tags($result->get_error_message());
        $message = trim(preg_replace('/\s+/', ' ', $message));
        wp_send_json_error($message);
        wp_die();
    }

    set_transient($throttle_key, 1, MINUTE_IN_SECONDS);

    wp_send_json_success([
        'message' => sprintf(
            /* translators: %s: the user's email address. */
            esc_html__('A password reset link has been sent to %s. Please check your email.', 'woo-coupon-usage'),
            $user->user_email
        ),
    ]);
    wp_die();
}

/**
 * Opens a settings "card" wrapper on the affiliate dashboard settings screen.
 *
 * Fires 'wcusage_hook_settings_card_before' and 'wcusage_hook_settings_card_before_{key}'
 * so cards can be prepended to / customised per section.
 *
 * @param string $key   Unique card key (used for id/class and hooks).
 * @param string $title Card title shown in the header.
 * @param string $icon  Optional Font Awesome icon class (e.g. "fas fa-bell").
 */
if (!function_exists('wcusage_settings_card_open')) {
    function wcusage_settings_card_open($key, $title = '', $icon = '') {
        $key = sanitize_key($key);
        do_action('wcusage_hook_settings_card_before', $key);
        do_action("wcusage_hook_settings_card_before_{$key}");
        ?>
        <section class="wcu-settings-card wcu-settings-card--<?php echo esc_attr($key); ?>" id="wcu-settings-card-<?php echo esc_attr($key); ?>">
            <?php if ($title) { ?>
                <div class="wcu-settings-card__header">
                    <?php if ($icon) { ?><span class="wcu-settings-card__icon"><i class="<?php echo esc_attr($icon); ?>" aria-hidden="true"></i></span><?php } ?>
                    <h3 class="wcu-settings-card__title"><?php echo esc_html($title); ?></h3>
                </div>
            <?php } ?>
            <div class="wcu-settings-card__body">
        <?php
    }
}

/**
 * Closes a settings "card" wrapper.
 *
 * Fires 'wcusage_hook_settings_card_after_{key}' and 'wcusage_hook_settings_card_after'.
 *
 * @param string $key Unique card key (matching the one passed to wcusage_settings_card_open()).
 */
if (!function_exists('wcusage_settings_card_close')) {
    function wcusage_settings_card_close($key) {
        $key = sanitize_key($key);
        ?>
            </div>
            <?php wcusage_settings_card_footer(); ?>
        </section>
        <?php
        do_action("wcusage_hook_settings_card_after_{$key}");
        do_action('wcusage_hook_settings_card_after', $key);
    }
}

/**
 * Outputs a card footer with a "Save changes" button and an inline message area.
 * Every card shares the same settings form, so any button saves the whole form;
 * the confirmation just appears in the section that was saved from.
 */
if (!function_exists('wcusage_settings_card_footer')) {
    function wcusage_settings_card_footer() {
        ?>
        <div class="wcu-settings-card__footer">
            <button type="submit" class="wcu-save-settings-button woocommerce-Button button" name="submitsettingsupdate"><?php echo esc_html__('Save changes', 'woo-coupon-usage'); ?></button>
            <div class="wcu-settings-card-msg" role="status" aria-live="polite"></div>
        </div>
        <?php
    }
}

/**
 * Outputs a styled toggle switch (a checkbox) used inside the settings cards.
 *
 * Keeps the underlying checkbox id/name intact so existing JS/AJAX handling is unchanged.
 *
 * @param string $id      Input id.
 * @param string $name    Input name.
 * @param bool   $checked Whether the toggle is on.
 * @param string $label   Label text.
 * @param string $value   Submitted value when checked (default "1").
 */
if (!function_exists('wcusage_settings_toggle')) {
    function wcusage_settings_toggle($id, $name, $checked, $label, $value = '1') {
        ?>
        <label class="wcu-settings-toggle" for="<?php echo esc_attr($id); ?>">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked((bool) $checked); ?>>
            <span class="wcu-settings-toggle__track" aria-hidden="true"></span>
            <span class="wcu-settings-toggle__label"><?php echo esc_html($label); ?></span>
        </label>
        <?php
    }
}

/**
 * Decodes HTML entities in a registration custom-field label or value.
 *
 * Applications stored the label and the value HTML-entity encoded, so a field
 * labelled "Recipient's full name" was keyed as "Recipient&#039;s full name"
 * while every reader looks the value up by the decoded label - the field then
 * always read as blank. Values are decoded for the same reason: they were
 * escaped again on output, so an apostrophe displayed as a literal "&#039;".
 *
 * ENT_QUOTES is passed explicitly because the default flags did not decode
 * &#039; before PHP 8.1.
 *
 * @param mixed $text
 * @return mixed
 */
if (!function_exists('wcusage_decode_custom_field_text')) {
    function wcusage_decode_custom_field_text($text) {
        if (!is_string($text) || $text === '') {
            return $text;
        }
        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Normalises a set of registration custom fields so legacy (entity encoded) and
 * current keys resolve to the same label.
 *
 * Decoding can make two stored keys collide - an encoded one written at
 * registration and a decoded one written by a later profile save. The non-empty
 * value wins, so a blank legacy entry can never erase a value the affiliate or
 * the admin entered afterwards.
 *
 * @param mixed $info
 * @return array label => value
 */
if (!function_exists('wcusage_normalize_custom_fields')) {
    function wcusage_normalize_custom_fields($info) {
        if (!is_array($info)) {
            return array();
        }
        $out = array();
        foreach ($info as $key => $value) {
            $label = wcusage_decode_custom_field_text((string) $key);
            if (is_array($value)) {
                $value = array_map('wcusage_decode_custom_field_text', $value);
            } elseif (is_scalar($value)) {
                $value = wcusage_decode_custom_field_text((string) $value);
            } else {
                $value = '';
            }
            $is_empty = ($value === '' || $value === array());
            if ($is_empty && isset($out[$label]) && $out[$label] !== '') {
                continue;
            }
            $out[$label] = $value;
        }
        return $out;
    }
}

/**
 * Returns the affiliate's saved registration custom-field values.
 *
 * At registration these are stored in the 'wcu_info' user meta as a JSON object
 * keyed by the field label. This normalises that (JSON / serialized / array) into
 * a plain array, with entity-encoded labels and values decoded so applications
 * stored by older versions still resolve.
 *
 * @param int $user_id
 * @return array label => value
 */
if (!function_exists('wcusage_get_user_custom_fields')) {
    function wcusage_get_user_custom_fields($user_id) {
        $raw  = get_user_meta($user_id, 'wcu_info', true);
        $info = array();
        if (is_array($raw)) {
            $info = $raw;
        } elseif (is_string($raw) && strlen($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $info = $decoded;
            } elseif (function_exists('is_serialized') && is_serialized($raw)) {
                $maybe = maybe_unserialize($raw);
                if (is_array($maybe)) {
                    $info = $maybe;
                }
            }
        }
        return wcusage_normalize_custom_fields($info);
    }
}

/**
 * Parses the "Options (one per line)" setting for a registration custom field.
 *
 * @param int $x Field index.
 * @return array
 */
if (!function_exists('wcusage_settings_custom_field_options')) {
    function wcusage_settings_custom_field_options($x) {
        $raw  = wcusage_get_setting_value('wcusage_field_registration_custom_options_' . $x, '');
        $opts = preg_split("/\r\n|\r|\n/", (string) $raw);
        $opts = array_values(array_filter(array_map('trim', $opts), 'strlen'));
        return $opts;
    }
}

/**
 * Outputs the configured registration custom fields as editable inputs inside the
 * "Account Details" section, pre-filled with the affiliate's saved values (from
 * 'wcu_info'). Header/paragraph field types are skipped (not user data).
 *
 * Required fields show a "*" marker but the HTML "required" attribute is not added
 * so a blank legacy value can never block saving the combined settings form.
 *
 * @param int    $user_id
 * @param string $layout  'modern' or 'legacy' (controls the field wrapper markup).
 */
if (!function_exists('wcusage_settings_output_custom_fields')) {
    function wcusage_settings_output_custom_fields($user_id, $layout = 'modern') {
        if (!$user_id) {
            return;
        }

        $show = wcusage_get_setting_value('wcusage_field_show_settings_tab_custom_fields', '1');
        /** Filter whether registration custom fields are shown/editable in Account Details. */
        $show = apply_filters('wcusage_settings_show_custom_fields', $show, $user_id);
        if (!$show) {
            return;
        }

        $fieldsnumber = (int) wcusage_get_setting_value('wcusage_field_registration_custom_fields', '2');
        if ($fieldsnumber < 1) {
            return;
        }

        $info = wcusage_get_user_custom_fields($user_id);

        for ($x = 1; $x <= $fieldsnumber; $x++) {
            $label = html_entity_decode((string) wcusage_get_setting_value('wcusage_field_registration_custom_label_' . $x, ''));
            $type  = wcusage_get_setting_value('wcusage_field_registration_custom_type_' . $x, '');

            if ($label === '') {
                continue;
            }
            // Display-only types are not editable account data.
            if ($type === 'header' || $type === 'paragraph') {
                continue;
            }
            // Respect the per-field "editable by user" setting.
            if (!wcusage_get_setting_value('wcusage_field_registration_custom_editable_' . $x, '1')) {
                continue;
            }
            if (!$type) {
                $type = 'text';
            }

            $required = wcusage_get_setting_value('wcusage_field_registration_custom_required_' . $x, '');
            $star     = $required ? ' *' : '';

            $value = isset($info[$label]) ? $info[$label] : '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $input_id = 'wcu_account_custom_' . $x;

            if ($layout === 'legacy') {
                echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wcu-account-custom-field">';
            } else {
                echo '<div class="wcu-settings-field wcu-account-custom-field">';
            }

            if ($type !== 'checkbox' && $type !== 'acceptance') {
                echo '<label for="' . esc_attr($input_id) . '">' . esc_html($label . $star) . '</label>';
            }

            switch ($type) {
                case 'textarea':
                    echo '<textarea class="wcu-account-custom-input woocommerce-Input input-text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_id) . '">' . esc_textarea($value) . '</textarea>';
                    break;

                case 'date':
                    echo '<input type="date" class="wcu-account-custom-input woocommerce-Input input-text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_id) . '" value="' . esc_attr($value) . '">';
                    break;

                case 'dropdown':
                    $opts = wcusage_settings_custom_field_options($x);
                    echo '<select class="wcu-account-custom-input" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_id) . '">';
                    echo '<option value="">' . esc_html__('Select...', 'woo-coupon-usage') . '</option>';
                    // Preserve a previously-saved value even if it is no longer an available option.
                    if ($value !== '' && !in_array($value, $opts, true)) {
                        echo '<option value="' . esc_attr($value) . '" selected>' . esc_html($value) . '</option>';
                    }
                    foreach ($opts as $opt) {
                        echo '<option value="' . esc_attr($opt) . '"' . selected($value, $opt, false) . '>' . esc_html($opt) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'radio':
                    $opts = wcusage_settings_custom_field_options($x);
                    echo '<span class="wcu-account-custom-radios">';
                    foreach ($opts as $i => $opt) {
                        $rid = $input_id . '_' . $i;
                        echo '<label class="wcu-account-custom-radio" for="' . esc_attr($rid) . '"><input type="radio" class="wcu-account-custom-input" id="' . esc_attr($rid) . '" name="' . esc_attr($input_id) . '" value="' . esc_attr($opt) . '"' . checked($value, $opt, false) . '> ' . esc_html($opt) . '</label>';
                    }
                    echo '</span>';
                    break;

                case 'checkbox':
                case 'acceptance':
                    $checked = ($value === 'Yes') ? ' checked' : '';
                    echo '<label class="wcu-account-custom-checkbox" for="' . esc_attr($input_id) . '">';
                    echo '<input type="checkbox" class="wcu-account-custom-input" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_id) . '" value="Yes"' . $checked . '> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal output; verified in manual audit.
                    echo '<span>' . esc_html($label . $star) . '</span>';
                    echo '</label>';
                    break;

                case 'text':
                default:
                    echo '<input type="text" class="wcu-account-custom-input woocommerce-Input input-text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_id) . '" value="' . esc_attr($value) . '">';
                    break;
            }

            echo ($layout === 'legacy') ? '</p>' : '</div>';
        }
    }
}

/**
 * Displays the settings tab content on affiliate dashboard
 */
if (!function_exists('wcusage_tab_settings')) {
    function wcusage_tab_settings($postid, $couponuserid) {
        $options = wcusage_get_options();
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

        // SMS
        $wcusage_sms_enable               = wcusage_get_setting_value('wcusage_sms_enable', 0);
        $wcusage_sms_affiliate_phone_show  = wcusage_get_setting_value('wcusage_sms_affiliate_phone_enable', 1);
        $wcusage_sms_affiliate_optout_show = wcusage_get_setting_value('wcusage_sms_affiliate_optout_enable', 1);
        $wcu_sms_phone    = get_user_meta($couponuserid, 'wcusage_sms_phone', true);
        // Fall back to general phone field if no dedicated SMS phone set yet
        if (!$wcu_sms_phone) {
            $wcu_sms_phone = get_user_meta($couponuserid, 'wcu_phone', true);
        }
        $wcu_sms_opted_out = get_user_meta($couponuserid, 'wcusage_sms_opted_out', true) ? true : false;

        // Account details
        $user = get_userdata($couponuserid);
        if($couponuserid) {
            $first_name = get_user_meta($couponuserid, 'first_name', true);
            $last_name = get_user_meta($couponuserid, 'last_name', true);
            $display_name = $user->display_name;
            $email = $user->user_email;
            $phone = get_user_meta($couponuserid, 'wcu_phone', true);
            $website = get_user_meta($couponuserid, 'wcu_website', true);
        } else {
            $first_name = '';
            $last_name = '';
            $display_name = '';
            $email = '';
            $phone = '';
            $website = '';
        }

        $wcu_settings_layout = wcusage_get_setting_value('wcusage_field_settings_tab_layout', 'modern');
        /**
         * Filter the affiliate "Settings" screen layout.
         *
         * @param string $wcu_settings_layout 'modern' (boxed cards) or 'legacy' (tabs).
         * @param int    $postid              Coupon post ID.
         * @param int    $couponuserid        Affiliate user ID.
         */
        $wcu_settings_layout = apply_filters('wcusage_settings_tab_layout', $wcu_settings_layout, $postid, $couponuserid);

        if ($wcu_settings_layout === 'legacy') {
        ?>

        <p class="wcu-tab-title settings-title" style="font-size: 22px; margin-bottom: 25px;"><?php echo esc_html__("Settings", "woo-coupon-usage"); ?>:</p>

        <?php if ($couponuserid == $currentuserid || wcusage_check_admin_access()) { ?>

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
                            // All statement detail fields can be hidden in the settings.
                            if ($wcu_enable_statements && $wcu_enable_statements_data && function_exists('wcusage_get_statement_affiliate_fields_visible') && !wcusage_get_statement_affiliate_fields_visible()) {
                                $wcu_enable_statements_data = 0;
                            }
                            if($wcu_enable_statements && $wcu_enable_statements_data) { ?>
                            <li><a href="#tab-statement-settings"><?php echo esc_html__("Statement Settings", "woo-coupon-usage"); ?></a></li>
                            <?php } ?>
                        <?php } ?>
                        <li <?php if(!$active) { ?>class="active"<?php } ?>><a href="#tab-email-notifications"><?php echo esc_html__("Notifications", "woo-coupon-usage"); ?></a></li>
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

                            <?php if (wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()) { ?>
                                <?php
                                // Newsletter subscription state: subscribed if user meta flag not set
                                $is_unsub = get_user_meta($couponuserid, 'wcusage_newsletter_unsubscribed', true) ? true : false;
                                $newsletters_enabled = wcusage_get_setting_value('wcusage_field_email_newsletter_enable', 0);
                                $global_unsub_enabled = wcusage_get_setting_value('wcusage_field_newsletter_enable_unsubscribe', 1);
                                if($newsletters_enabled &&$global_unsub_enabled) { ?>
                                    <p><input type="checkbox" id="wcu_newsletter_subscribed" name="wcu_newsletter_subscribed" value="1" <?php if(!$is_unsub) { ?>checked<?php } ?>>
                                    <?php echo sprintf( esc_html__("Subscribe to %s Newsletters", "woo-coupon-usage"), esc_html( wcusage_get_affiliate_text( __("Affiliate", "woo-coupon-usage") ) ) ); ?>
                                <?php } ?>
                            <?php } ?>

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

                            <?php
                            // SMS Notifications (PRO) — phone number + opt-out fields
                            if (
                                wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code()
                                && $wcusage_sms_enable
                                && ($wcusage_sms_affiliate_phone_show || $wcusage_sms_affiliate_optout_show)
                            ) { ?>
                                <hr style="margin: 15px 0;"/>
                                <p><strong><?php echo esc_html__("SMS Notifications", "woo-coupon-usage"); ?></strong></p>

                                <?php if ($wcusage_sms_affiliate_phone_show) { ?>
                                <p>
                                    <label for="wcusage_sms_phone"><?php echo esc_html__("Phone Number for SMS Notifications:", "woo-coupon-usage"); ?></label><br/>
                                    <input type="tel" id="wcusage_sms_phone" name="wcusage_sms_phone"
                                        value="<?php echo esc_attr($wcu_sms_phone); ?>"
                                        placeholder=""
                                        style="width: 300px; max-width: 100%;">
                                    <br/><small><?php echo esc_html__("Enter in international format, e.g. +447911123456.", "woo-coupon-usage"); ?></small>
                                </p>
                                <?php } ?>

                                <?php if ($wcusage_sms_affiliate_optout_show) { ?>
                                <p>
                                    <input type="checkbox" id="wcusage_sms_opted_out" name="wcusage_sms_opted_out" value="1"
                                        <?php if ($wcu_sms_opted_out) { ?>checked<?php } ?>>
                                    <label for="wcusage_sms_opted_out"><?php echo esc_html__("Opt out of SMS notifications", "woo-coupon-usage"); ?></label>
                                </p>
                                <?php } ?>
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
                                <?php if ($couponuserid && $currentuserid == $couponuserid) { ?>
                                    <?php $wcusage_field_show_settings_tab_gravatar = wcusage_get_setting_value('wcusage_field_show_settings_tab_gravatar', '1'); ?>
                                    <?php if($wcusage_field_show_settings_tab_gravatar) { ?>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label><?php echo esc_html__('Profile Picture', 'woo-coupon-usage'); ?></label>
                                        <div style="margin-bottom: 10px;" class="profile-picture">
                                            <a href="https://gravatar.com/profile/avatars" target="_blank" rel="noopener noreferrer"
                                            title="<?php echo esc_attr__('Change your profile picture on Gravatar', 'woo-coupon-usage'); ?>">
                                                <?php echo get_avatar($couponuserid, 96); ?>
                                            </a>
                                        </div>
                                    </p>
                                    <?php } ?>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_first_name"><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?>:</label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_first_name" name="wcu_first_name" value="<?php echo esc_attr($first_name); ?>" autocomplete="given-name">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_last_name"><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?>:</label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_last_name" name="wcu_last_name" value="<?php echo esc_attr($last_name); ?>" autocomplete="family-name">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_display_name"><?php echo esc_html__('Display Name', 'woo-coupon-usage'); ?>:</label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_display_name" name="wcu_display_name" value="<?php echo esc_attr($display_name); ?>" autocomplete="nickname">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_email"><?php echo esc_html__('Email Address', 'woo-coupon-usage'); ?>:</label>
                                        <input type="email" class="woocommerce-Input woocommerce-Input--email input-text"
                                            id="wcu_email" name="wcu_email" value="<?php echo esc_attr($email); ?>" autocomplete="email"
                                            required>
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_phone"><?php echo esc_html__('Phone Number', 'woo-coupon-usage'); ?>:</label>
                                        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_phone" name="wcu_phone" value="<?php echo esc_attr($phone); ?>" autocomplete="tel">
                                    </p>
                                    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                                        <label for="wcu_website"><?php echo esc_html__('Website', 'woo-coupon-usage'); ?>:</label>
                                        <input type="url" class="woocommerce-Input woocommerce-Input--text input-text"
                                            id="wcu_website" name="wcu_website" value="<?php echo esc_attr($website); ?>" autocomplete="url">
                                    </p>
                                    <?php wcusage_settings_output_custom_fields($couponuserid, 'legacy'); ?>
                                    <p>
                                        <label for="wcu_password"><?php echo esc_html__('Password', 'woo-coupon-usage'); ?>:</label>
                                        <a class="wcu-reset-password-link" href="#"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('wcusage_password_reset')); ?>"
                                            data-confirm="<?php echo esc_attr__('Are you sure you want to reset your password? A password reset link will be emailed to you.', 'woo-coupon-usage'); ?>">
                                            <?php echo esc_html__('Click here to reset your password.', 'woo-coupon-usage'); ?>
                                        </a>
                                        <span class="wcu-reset-password-msg" role="status" aria-live="polite"></span>
                                    </p>
                                <?php } else { ?>
                                    <p><?php echo esc_html__("Sorry, this coupon is not assigned to you. You can only edit your own account details.", "woo-coupon-usage"); ?></p>
                                    <?php if (wcusage_check_admin_access() && current_user_can('edit_users')) { ?>
                                        <p><?php echo sprintf(esc_html__("[Admin] You can edit the account details for this user in the admin area: %s", "woo-coupon-usage"),
                                            "<a href='" . esc_url( admin_url('admin.php?page=wcusage_view_affiliate&user_id=' . $couponuserid) ) . "' target='_blank'>" . sprintf( esc_html__("View %s", "woo-coupon-usage"), esc_html( wcusage_get_affiliate_text( __("Affiliate", "woo-coupon-usage") ) ) ) . "</a>"); ?></p>
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

                    <div id="wcu-settings-ajax-message"></div>
                </div>
            </form>

        <?php } else { ?>
            <br/><p><?php echo esc_html__("Sorry, this coupon is not assigned to you.", "woo-coupon-usage"); ?></p>
        <?php }

            return;
        }
        ?>

        <div class="wcu-settings-modern">

            <?php do_action('wcusage_hook_settings_top', $postid, $couponuserid); ?>

        <?php if ($couponuserid == $currentuserid || wcusage_check_admin_access()) {

            $wcu_is_pro = wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code();

            // Determine which right-column section cards to show.
            $wcusage_field_payouts_enable = $wcu_is_pro ? wcusage_get_setting_value('wcusage_field_payouts_enable', '1') : 0;
            $wcu_enable_statements        = wcusage_get_setting_value('wcusage_field_payouts_enable_statements', '0');
            $wcu_enable_statements_data   = wcusage_get_setting_value('wcusage_field_payouts_enable_statements_data', '1');
            $wcu_show_statements          = ($wcu_is_pro && $wcu_enable_statements && $wcu_enable_statements_data);
            // Every statement detail field can be hidden in the settings; when they
            // all are, there is nothing to put in the card.
            if ($wcu_show_statements && function_exists('wcusage_get_statement_affiliate_fields_visible')) {
                $wcu_show_statements = (bool) wcusage_get_statement_affiliate_fields_visible();
            }

            $wcu_sections = array();
            if ($wcusage_field_payouts_enable) {
                $wcu_sections['payout'] = array(
                    'title' => esc_html__('Payout Settings', 'woo-coupon-usage'),
                    'icon'  => 'fas fa-wallet',
                );
            }
            if ($wcu_show_statements) {
                $wcu_sections['statement'] = array(
                    'title' => esc_html__('Statement Details', 'woo-coupon-usage'),
                    'icon'  => 'fas fa-file-invoice',
                );
            }
            $wcu_sections['notifications'] = array(
                'title' => esc_html__('Email Notifications', 'woo-coupon-usage'),
                'icon'  => 'fas fa-bell',
            );
            if ($wcu_is_pro && $wcusage_sms_enable && ($wcusage_sms_affiliate_phone_show || $wcusage_sms_affiliate_optout_show)) {
                $wcu_sections['sms'] = array(
                    'title' => esc_html__('SMS Notifications', 'woo-coupon-usage'),
                    'icon'  => 'fas fa-comment-sms',
                );
            }

            /**
             * Filter the right-column section cards and their order on the settings screen.
             * Add a custom key (['title' => '', 'icon' => '']) and hook
             * 'wcusage_hook_settings_card_content_{key}' to render its contents.
             *
             * @param array $wcu_sections  Keyed array of section definitions.
             * @param int   $postid        Coupon post ID.
             * @param int   $couponuserid  Affiliate user ID.
             */
            $wcu_sections = apply_filters('wcusage_settings_sections', $wcu_sections, $postid, $couponuserid);

            $wcu_show_account = wcusage_get_setting_value('wcusage_field_show_settings_tab_account', '1');
            /** Filter whether the account details card (left column) is shown. */
            $wcu_show_account = apply_filters('wcusage_settings_show_account_card', $wcu_show_account, $postid, $couponuserid);
            ?>

            <form method="post" class="wcusage_settings_form" id="wcusage-settings-form" data-post-id="<?php echo esc_attr($postid); ?>">
                <?php wp_nonce_field('wcusage_settings_update', 'wcusage_settings_nonce'); ?>

                <div class="wcu-settings-tab-content wcu-settings-grid<?php echo $wcu_show_account ? '' : ' wcu-settings-grid--single'; ?>">

                    <?php if ($wcu_show_account) { ?>
                    <div class="wcu-settings-col wcu-settings-col--account">
                        <?php do_action('wcusage_hook_settings_account_card_before', $postid, $couponuserid); ?>
                        <section class="wcu-settings-card wcu-settings-account-card" id="wcu-settings-card-account">
                            <?php
                            $wcu_show_gravatar = wcusage_get_setting_value('wcusage_field_show_settings_tab_gravatar', '1');
                            $wcu_identity_name = $display_name ? $display_name : trim($first_name . ' ' . $last_name);
                            if (!$wcu_identity_name) { $wcu_identity_name = $email; }
                            ?>
                            <div class="wcu-settings-account-card__identity">
                                <div class="wcu-settings-account-card__avatar">
                                    <?php
                                    if ($wcu_show_gravatar) {
                                        echo '<a href="https://gravatar.com/profile/avatars" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Change your profile picture on Gravatar', 'woo-coupon-usage') . '">';
                                        echo get_avatar($couponuserid, 120);
                                        echo '</a>';
                                    } else {
                                        if ($wcu_identity_name) {
                                            $wcu_initial = function_exists('mb_substr') ? mb_substr($wcu_identity_name, 0, 1) : substr($wcu_identity_name, 0, 1);
                                        } else {
                                            $wcu_initial = '?';
                                        }
                                        echo '<span class="wcu-settings-account-card__initial">' . esc_html(function_exists('mb_strtoupper') ? mb_strtoupper($wcu_initial) : strtoupper($wcu_initial)) . '</span>';
                                    }
                                    ?>
                                </div>
                                <?php if ($wcu_identity_name) { ?>
                                    <div class="wcu-settings-account-card__name"><?php echo esc_html($wcu_identity_name); ?></div>
                                <?php } ?>
                                <?php if ($email) { ?>
                                    <div class="wcu-settings-account-card__email"><?php echo esc_html($email); ?></div>
                                <?php } ?>
                            </div>

                            <div class="wcu-settings-card__body">
                                <p class="wcu-settings-card__eyebrow"><?php echo esc_html__("Account Details", "woo-coupon-usage"); ?></p>
                                <?php if ($couponuserid && $currentuserid == $couponuserid) { ?>

                                    <div class="wcu-settings-field">
                                        <label for="wcu_first_name"><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?></label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="wcu_first_name" name="wcu_first_name" value="<?php echo esc_attr($first_name); ?>" autocomplete="given-name">
                                    </div>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_last_name"><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?></label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="wcu_last_name" name="wcu_last_name" value="<?php echo esc_attr($last_name); ?>" autocomplete="family-name">
                                    </div>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_display_name"><?php echo esc_html__('Display Name', 'woo-coupon-usage'); ?></label>
                                        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="wcu_display_name" name="wcu_display_name" value="<?php echo esc_attr($display_name); ?>" autocomplete="nickname">
                                    </div>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_email"><?php echo esc_html__('Email Address', 'woo-coupon-usage'); ?></label>
                                        <input type="email" class="woocommerce-Input woocommerce-Input--email input-text" id="wcu_email" name="wcu_email" value="<?php echo esc_attr($email); ?>" autocomplete="email" required>
                                    </div>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_phone"><?php echo esc_html__('Phone Number', 'woo-coupon-usage'); ?></label>
                                        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" id="wcu_phone" name="wcu_phone" value="<?php echo esc_attr($phone); ?>" autocomplete="tel">
                                    </div>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_website"><?php echo esc_html__('Website', 'woo-coupon-usage'); ?></label>
                                        <input type="url" class="woocommerce-Input woocommerce-Input--text input-text" id="wcu_website" name="wcu_website" value="<?php echo esc_attr($website); ?>" autocomplete="url">
                                    </div>

                                    <?php wcusage_settings_output_custom_fields($couponuserid, 'modern'); ?>

                                    <?php do_action('wcusage_hook_settings_account_fields_after', $couponuserid); ?>

                                    <div class="wcu-settings-field wcu-settings-account-card__password">
                                        <label><?php echo esc_html__('Password', 'woo-coupon-usage'); ?></label>
                                        <a class="wcu-settings-link wcu-reset-password-link" href="#"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('wcusage_password_reset')); ?>"
                                            data-confirm="<?php echo esc_attr__('Are you sure you want to reset your password? A password reset link will be emailed to you.', 'woo-coupon-usage'); ?>"><?php echo esc_html__('Click here to reset your password.', 'woo-coupon-usage'); ?></a>
                                        <span class="wcu-reset-password-msg" role="status" aria-live="polite"></span>
                                    </div>

                                <?php } else { ?>
                                    <p><?php echo esc_html__("Sorry, this coupon is not assigned to you. You can only edit your own account details.", "woo-coupon-usage"); ?></p>
                                    <?php if (wcusage_check_admin_access() && current_user_can('edit_users')) { ?>
                                        <p><?php echo sprintf(esc_html__("[Admin] You can edit the account details for this user in the admin area: %s", "woo-coupon-usage"),
                                            "<a href='" . esc_url( admin_url('admin.php?page=wcusage_view_affiliate&user_id=' . $couponuserid) ) . "' target='_blank'>" . sprintf( esc_html__("View %s", "woo-coupon-usage"), esc_html( wcusage_get_affiliate_text( __("Affiliate", "woo-coupon-usage") ) ) ) . "</a>"); ?></p>
                                        <span class='admin-edit-account'>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('First Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($first_name); ?></label></div>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('Last Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($last_name); ?></label></div>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('Display Name', 'woo-coupon-usage'); ?>: <?php echo esc_html($display_name); ?></label></div>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('Email Address', 'woo-coupon-usage'); ?>: <?php echo esc_html($email); ?></label></div>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('Phone Number', 'woo-coupon-usage'); ?>: <?php echo esc_html($phone); ?></label></div>
                                            <div class="wcu-settings-field"><label><?php echo esc_html__('Website', 'woo-coupon-usage'); ?>: <?php echo esc_html($website); ?></label></div>
                                        </span>
                                    <?php } ?>
                                <?php } ?>
                            </div>
                            <?php if ($couponuserid && $currentuserid == $couponuserid) { wcusage_settings_card_footer(); } ?>
                        </section>
                        <?php do_action('wcusage_hook_settings_account_card_after', $postid, $couponuserid); ?>
                    </div>
                    <?php } ?>

                    <div class="wcu-settings-col wcu-settings-col--main">
                        <?php
                        do_action('wcusage_hook_settings_sections_before', $postid, $couponuserid);

                        foreach ($wcu_sections as $wcu_key => $wcu_section) {
                            $wcu_key   = sanitize_key($wcu_key);
                            $wcu_title = isset($wcu_section['title']) ? $wcu_section['title'] : '';
                            $wcu_icon  = isset($wcu_section['icon']) ? $wcu_section['icon'] : '';

                            wcusage_settings_card_open($wcu_key, $wcu_title, $wcu_icon);

                            if ($wcu_key === 'payout') {
                                do_action('wcusage_hook_output_payout_data_section', $postid, '');
                            } elseif ($wcu_key === 'statement') {
                                do_action('wcusage_hook_output_statement_data_section', $couponuserid);
                            } elseif ($wcu_key === 'notifications') {

                                wcusage_settings_toggle('wcu_enable_notifications', 'wcu_enable_notifications', $wcu_enable_notifications, esc_html__("Enable Email Notifications", "woo-coupon-usage"));

                                if ($wcu_is_pro) {
                                    // Newsletter subscription state: subscribed if user meta flag not set.
                                    $is_unsub = get_user_meta($couponuserid, 'wcusage_newsletter_unsubscribed', true) ? true : false;
                                    $newsletters_enabled = wcusage_get_setting_value('wcusage_field_email_newsletter_enable', 0);
                                    $global_unsub_enabled = wcusage_get_setting_value('wcusage_field_newsletter_enable_unsubscribe', 1);
                                    if ($newsletters_enabled && $global_unsub_enabled) {
                                        wcusage_settings_toggle('wcu_newsletter_subscribed', 'wcu_newsletter_subscribed', !$is_unsub, sprintf( esc_html__("Subscribe to %s Newsletters", "woo-coupon-usage"), esc_html( wcusage_get_affiliate_text( __("Affiliate", "woo-coupon-usage") ) ) ));
                                    }
                                }

                                if ($enable_reports_user_option && $wcusage_field_enable_reports && $wcu_is_pro) {
                                    $wcusage_field_pdfreports_freq = wcusage_get_setting_value('wcusage_field_pdfreports_freq', 'monthly');
                                    $pdfreports_freq = esc_html__("Monthly", "woo-coupon-usage");
                                    if ($wcusage_field_pdfreports_freq == "weekly") {
                                        $pdfreports_freq = esc_html__("Weekly", "woo-coupon-usage");
                                    } elseif ($wcusage_field_pdfreports_freq == "quarterly") {
                                        $pdfreports_freq = esc_html__("Quarterly", "woo-coupon-usage");
                                    }
                                    wcusage_settings_toggle('wcu_enable_reports', 'wcu_enable_reports', $wcu_enable_reports, esc_html__("Enable Email Reports", "woo-coupon-usage") . ' (' . $pdfreports_freq . ')');
                                }

                                if ($wcusage_email_enable_extra && $wcu_is_pro) { ?>
                                    <div class="wcu-settings-field">
                                        <label for="wcu_notifications_extra"><?php echo esc_html__("Additional Email Addresses", "woo-coupon-usage"); ?></label>
                                        <input type="text" id="wcu_notifications_extra" name="wcu_notifications_extra" value="<?php echo esc_attr($wcu_notifications_extra); ?>" placeholder="example@email.com, another@email.com">
                                        <small class="wcu-settings-hint"><?php echo esc_html__("Separate multiple addresses with a comma.", "woo-coupon-usage"); ?></small>
                                    </div>
                                <?php }

                            } elseif ($wcu_key === 'sms') {

                                if ($wcusage_sms_affiliate_phone_show) { ?>
                                    <div class="wcu-settings-field">
                                        <label for="wcusage_sms_phone"><?php echo esc_html__("Phone Number for SMS Notifications", "woo-coupon-usage"); ?></label>
                                        <input type="tel" id="wcusage_sms_phone" name="wcusage_sms_phone" value="<?php echo esc_attr($wcu_sms_phone); ?>">
                                        <small class="wcu-settings-hint"><?php echo esc_html__("Enter in international format, e.g. +447911123456.", "woo-coupon-usage"); ?></small>
                                    </div>
                                <?php }

                                if ($wcusage_sms_affiliate_optout_show) {
                                    wcusage_settings_toggle('wcusage_sms_opted_out', 'wcusage_sms_opted_out', $wcu_sms_opted_out, esc_html__("Opt out of SMS notifications", "woo-coupon-usage"));
                                }

                            } else {
                                /** Render a custom section registered via the 'wcusage_settings_sections' filter. */
                                do_action("wcusage_hook_settings_card_content_{$wcu_key}", $postid, $couponuserid);
                            }

                            wcusage_settings_card_close($wcu_key);
                        }

                        do_action('wcusage_hook_settings_sections_after', $postid, $couponuserid);
                        ?>
                    </div>

                </div>

            </form>

        <?php } else { ?>
            <br/><p><?php echo esc_html__("Sorry, this coupon is not assigned to you.", "woo-coupon-usage"); ?></p>
        <?php } ?>

        </div>

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

        $options = wcusage_get_options();
        $currentuserid = get_current_user_id();

        if (isset($_POST['page-settings']) || isset($_POST['ml-page-settings']) || !isset($_POST['load-page']) || $wcusage_page_load == false) { ?>
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

/**
 * Combine Wisebank fields for backward compatibility
 */
if( !function_exists( 'wcusage_combine_wisebank_fields' ) ) {
    function wcusage_combine_wisebank_fields($post_data) {
        $combined = '';
        
        if (!empty($post_data['wisebank_region'])) {
            $combined .= "Region: " . $post_data['wisebank_region'] . "\n";
        }
        
        if (!empty($post_data['wisebank_account_name'])) {
            $combined .= "Account Name: " . $post_data['wisebank_account_name'] . "\n";
        }
        
        if (!empty($post_data['wisebank_account_number'])) {
            $combined .= "Account Number: " . $post_data['wisebank_account_number'] . "\n";
        }
        
        if (!empty($post_data['wisebank_routing_number'])) {
            $combined .= "Routing Number: " . $post_data['wisebank_routing_number'] . "\n";
        }
        
        if (!empty($post_data['wisebank_swift_code'])) {
            $combined .= "SWIFT Code: " . $post_data['wisebank_swift_code'] . "\n";
        }
        
        if (!empty($post_data['wisebank_iban'])) {
            $combined .= "IBAN: " . $post_data['wisebank_iban'] . "\n";
        }
        
        if (!empty($post_data['wisebank_sort_code'])) {
            $combined .= "Sort Code: " . $post_data['wisebank_sort_code'] . "\n";
        }
        
        if (!empty($post_data['wisebank_bank_name'])) {
            $combined .= "Bank Name: " . $post_data['wisebank_bank_name'] . "\n";
        }
        
        if (!empty($post_data['wisebank_bank_address'])) {
            $combined .= "Bank Address: " . $post_data['wisebank_bank_address'] . "\n";
        }
        
        if (!empty($post_data['wisebank_country'])) {
            $combined .= "Country: " . $post_data['wisebank_country'] . "\n";
        }
        
        if (!empty($post_data['wisebank_address'])) {
            $combined .= "Recipient Address: " . $post_data['wisebank_address'] . "\n";
        }
        
        if (!empty($post_data['wisebank_city'])) {
            $combined .= "Recipient City: " . $post_data['wisebank_city'] . "\n";
        }
        
        if (!empty($post_data['wisebank_postcode'])) {
            $combined .= "Recipient Postcode: " . $post_data['wisebank_postcode'] . "\n";
        }
        
        if (!empty($post_data['wisebank_recipient_country'])) {
            $combined .= "Recipient Country: " . $post_data['wisebank_recipient_country'] . "\n";
        }
        
        return trim($combined);
    }
}