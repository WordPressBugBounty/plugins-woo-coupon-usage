<?php
if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Register the AJAX action for both logged-in and non-logged-in users
add_action('wp_ajax_wcusage_submit_registration', 'wcusage_ajax_submit_registration');
add_action('wp_ajax_nopriv_wcusage_submit_registration', 'wcusage_ajax_submit_registration');

/**
 * Handles the AJAX submission of the affiliate registration form.
 * Validates input, creates a user if necessary, stores registration data, and sends emails.
 */
function wcusage_ajax_submit_registration() {
    // Verify the AJAX request using the nonce
    check_ajax_referer('wcusage_verify_submit_registration_form1', 'wcusage_submit_registration_form1');

    // Retrieve and sanitize form data from $_POST
    $username = isset($_POST['wcu-input-username']) ? sanitize_user($_POST['wcu-input-username']) : '';
    $email = isset($_POST['wcu-input-email']) ? sanitize_email($_POST['wcu-input-email']) : '';
    $firstname = isset($_POST['wcu-input-first-name']) ? sanitize_text_field($_POST['wcu-input-first-name']) : '';
    $lastname = isset($_POST['wcu-input-last-name']) ? sanitize_text_field($_POST['wcu-input-last-name']) : '';
    $couponcode = isset($_POST['wcu-input-coupon']) ? wc_sanitize_coupon_code($_POST['wcu-input-coupon']) : '';
    $website = isset($_POST['wcu-input-website']) ? sanitize_text_field($_POST['wcu-input-website']) : '';
    $type = isset($_POST['wcu-input-type']) ? sanitize_text_field($_POST['wcu-input-type']) : '';
    $promote = isset($_POST['wcu-input-promote']) ? sanitize_text_field($_POST['wcu-input-promote']) : '';
    $referrer = isset($_POST['wcu-input-referrer']) ? sanitize_text_field($_POST['wcu-input-referrer']) : '';
    $password = isset($_POST['wcu-input-password']) ? sanitize_text_field($_POST['wcu-input-password']) : '';
    $password_confirm = isset($_POST['wcu-input-password-confirm']) ? sanitize_text_field($_POST['wcu-input-password-confirm']) : '';
    
    $tiersnumber = wcusage_get_setting_value('wcusage_field_registration_custom_fields', '2');
    $info = array();
    for ($x = 1; $x <= $tiersnumber; $x++) {
      if(isset($_POST['wcu-input-custom-' . $x])) {
        $label = sanitize_text_field( htmlentities( wcusage_get_setting_value('wcusage_field_registration_custom_label_' . $x, '') ) );
        if(is_array($_POST['wcu-input-custom-' . $x])) {
          $info_array = $_POST['wcu-input-custom-' . $x];
          $info[$label] = sanitize_text_field( implode(', ', $info_array) );
        } else {
          $info[$label] = sanitize_text_field( htmlentities( $_POST['wcu-input-custom-' . $x] ) );
        }
      }
    }
    $info = json_encode($info);

    // Assume password confirmation is optional based on a setting (adjust as needed)
    $field_password_confirm = wcusage_get_setting_value('wcusage_field_registration_password_confirm', '0');

    if (!is_user_logged_in()) {

        // Perform validations
        if (empty($username)) {
            wp_send_json_error(array('message' => 'Username is required.'));
        }

        if (username_exists($username)) {
            wp_send_json_error(array('message' => 'This username already exists. Please try again or login.'));
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'A valid email is required.'));
        }

        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'This email already exists. Please try again or login.'));
        }

        if (empty($password)) {
            wp_send_json_error(array('message' => 'Password is required.'));
        }

        if ($field_password_confirm && $password !== $password_confirm) {
            wp_send_json_error(array('message' => 'The passwords do not match. Please try again.'));
        }

    } else {

        if (username_exists($username) && $username !== wp_get_current_user()->user_login) {
            wp_send_json_error(array('message' => 'This username already exists. Please try again or login.'));
        }

        // If the user is logged in, we can skip some validations
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'A valid email is required.'));
        }

        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'This email already exists. Please try again or login.'));
        }

    }

    // Captcha validation (if applicable)
    $captchaverify = wcusage_registration_form_verify_captcha(0);
    if (!$captchaverify) {
        wp_send_json_error(array('message' => 'Captcha verification failed. Please try again.'));
    }

    // Create a new user if the user is not logged in
    if (!is_user_logged_in()) {
        $new_affiliate_user = wcusage_add_new_affiliate_user($username, $password, $email, $firstname, $lastname, $couponcode, $website);

        if (is_wp_error($new_affiliate_user)) {
            wp_send_json_error(array('message' => 'Failed to create user: ' . $new_affiliate_user->get_error_message()));
        }

        $userid = $new_affiliate_user['userid'];
    } else {
        // Use the current user's ID if already logged in
        $current_user = wp_get_current_user();
        $userid = $current_user->ID;
    }

    // Store the registration data in a custom table
    $getregisterid = wcusage_install_register_data( $couponcode, $userid, $referrer, $promote, $website, $type, $info);

    if (!$getregisterid) {
        wp_send_json_error(array('message' => 'Failed to store registration data. Please try again.'));
    }    

    // Handle auto-accept logic based on a setting
    $auto_accept = wcusage_get_setting_value('wcusage_field_registration_auto_accept', '0');
    if ($auto_accept) {
        $message = ''; // Define any message needed for status update
        $setstatus = wcusage_set_registration_status('accepted', $getregisterid, $userid, $couponcode, $message, $type);

        if (!$setstatus) {
            wp_send_json_error(array('message' => 'Failed to auto-accept registration.'));
        }
    }

    // Send notification emails
    wcusage_email_affiliate_register($email, $couponcode, $firstname);
    wcusage_email_admin_affiliate_register($username, $couponcode, $referrer, $promote, $website, $type, $info);

    // Auto-login process if the user is newly created
    if (!is_user_logged_in() && isset($new_affiliate_user)) {
        wp_set_current_user($userid);
        wp_set_auth_cookie($userid);
        do_action('wp_login', $username, $current_user);
    }

    // Return a success response
    wp_send_json_success(array('message' => '<p class="registration-message">Your affiliate application has been submitted successfully.</p>
    <p class="registration-message">Please check your email for further instructions.</p>'));

    // Auto-login process if the user is newly created
    if (!is_user_logged_in() && isset($new_affiliate_user)) {
        wp_set_current_user($userid);
        wp_set_auth_cookie($userid);
        do_action('wp_login', $username, $current_user);
    }

    exit; // Always exit after handling AJAX requests
}