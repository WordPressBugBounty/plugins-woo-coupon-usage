<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Email to affiliate on registration
function wcusage_email_affiliate_register($user_email, $coupon_code, $firstname) {

  $options = wcusage_get_options();

  $wcusage_field_email_registration_enable = wcusage_get_setting_value('wcusage_field_email_registration_enable', '1');

  if($wcusage_field_email_registration_enable) {

    if(!empty($options['wcusage_field_email_registration_subject']) && !empty($options['wcusage_field_email_registration_message'])) {

      $to = $user_email;
      $from = wcusage_get_from_email();

      $subject = $options['wcusage_field_email_registration_subject'];
      if(!$subject) { $subject = ""; }
      $body = html_entity_decode( $options['wcusage_field_email_registration_message'] );

      if(isset($subject)) {
        if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
        if($firstname) { $subject = str_replace("{name}", $firstname, $subject); }
      }

      if($body) {
        $body = str_replace("{coupon}", $coupon_code, $body);
        $body = str_replace("{name}", $firstname, $body);
        $body = str_replace("{email}", $user_email, $body);
      }

      $dashboardurl = wcusage_get_coupon_shortcode_page(0);
      $dashboardurl = "<a href='".$dashboardurl."'>" . $dashboardurl . "</a>";
      $body = str_replace("{dashboardurl}", $dashboardurl, $body);

      $referralurl = esc_html( wcusage_get_affiliate_url( $coupon_code ) );
      $referralurl = "<a href='".$referralurl."'>" . $referralurl . "</a>";
      $body = str_replace("{referralurl}", $referralurl, $body);

      $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

      $mailer = WC()->mailer();
      $wrapped_message = $mailer->wrap_message($subject, $body);
      $wc_email = new WC_Email;
      $html_message = $wc_email->style_inline($wrapped_message);

      wp_mail( $to, $subject, $html_message, $headers );

    }

  }

}

// Email to affiliate on registration if new account
function wcusage_email_affiliate_register_new($user_email, $coupon_code, $firstname, $username, $user_id = "") {

  $options = wcusage_get_options();

  $wcusage_field_email_registration_new_enable = wcusage_get_setting_value('wcusage_field_email_registration_new_enable', '1');

  if($wcusage_field_email_registration_new_enable) {

    if(!empty($options['wcusage_field_email_registration_new_subject']) && !empty($options['wcusage_field_email_registration_new_message'])) {

      $to = $user_email;
      $from = wcusage_get_from_email();

      $subject = $options['wcusage_field_email_registration_new_subject'];
      if(!$subject) { $subject = ""; }
      $body = html_entity_decode( $options['wcusage_field_email_registration_new_message'] );

      if(isset($subject)) {
        if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
        if($firstname) { $subject = str_replace("{name}", $firstname, $subject); }
      }

      if($body) {
        $body = str_replace("{coupon}", $coupon_code, $body);
        $body = str_replace("{name}", $firstname, $body);
        $body = str_replace("{username}", $username, $body);
        $body = str_replace("{email}", $user_email, $body);
      }

      if($user_id) {
        $user = get_user_by( 'id', $user_id );
        $user_data = get_userdata( $user_id );
        if($to == $user_data->user_email) {
          $password_url = wcusage_generate_password_reset_url($user_id);
          $body = str_replace("{passwordurl}", $password_url, $body);
        }
      }

      $dashboardurl = wcusage_get_coupon_shortcode_page(0);
      $dashboardurl = "<a href='".$dashboardurl."'>" . $dashboardurl . "</a>";
      if(!$dashboardurl) { $dashboardurl = ""; }
      $body = str_replace("{dashboardurl}", $dashboardurl, $body);

      $referralurl = esc_html( wcusage_get_affiliate_url( $coupon_code ) );
      $referralurl = "<a href='".$referralurl."'>" . $referralurl . "</a>";
      $body = str_replace("{referralurl}", $referralurl, $body);

      $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

      $mailer = WC()->mailer();
      $wrapped_message = $mailer->wrap_message($subject, $body);
      $wc_email = new WC_Email;
      $html_message = $wc_email->style_inline($wrapped_message);

      wp_mail( $to, $subject, $html_message, $headers );

    }

  }

}

// Create password reset URL
function wcusage_generate_password_reset_url($user_id) {

    $user = get_user_by('id', $user_id);

    if (!$user || is_wp_error($user)) {
        return false;
    }

    $user_data = get_userdata($user_id);
    $user_login = $user->user_login;
    $key = get_password_reset_key($user_data);

    if (is_wp_error($key)) {
        return false;
    }

    // Try WooCommerce my account lost-password endpoint first, fall back to wp-login.php
    $account_page_url = wc_get_page_permalink('myaccount');
    if ($account_page_url && !is_wp_error($account_page_url)) {
        $rp_link = add_query_arg(
            array(
                'key'   => $key,
                'login' => rawurlencode($user_login),
            ),
            wc_get_endpoint_url('lost-password', '', $account_page_url)
        );
    } else {
        $rp_link = add_query_arg(
            array(
                'action' => 'rp',
                'key'    => $key,
                'login'  => rawurlencode($user_login),
            ),
            wp_login_url()
        );
    }

    return $rp_link;

}

// Email to admin on affiliate application
function wcusage_email_admin_affiliate_register($username, $coupon_code, $referrer, $promote, $website, $type, $info) {

  $options = wcusage_get_options();

  $wcusage_field_registration_enable = wcusage_get_setting_value('wcusage_field_registration_enable', '1');
  $wcusage_field_email_registration_admin_enable = wcusage_get_setting_value('wcusage_field_email_registration_admin_enable', '1');

  if($wcusage_field_registration_enable && $wcusage_field_email_registration_admin_enable) {

    if(!empty($options['wcusage_field_email_registration_admin_subject']) && !empty($options['wcusage_field_email_registration_admin_message'])) {

    $from = wcusage_get_from_email();

    $subject = $options['wcusage_field_email_registration_admin_subject'];
    $body = html_entity_decode( $options['wcusage_field_email_registration_admin_message'] );

    if(isset($subject)) {
      if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
      if($username) { $subject = str_replace("{username}", $username, $subject); }
    }

    $user = get_user_by( 'login', $username );
    if($user) {
      $user_id = $user->ID;
      $user_data = get_userdata( $user_id );
      $name = $user_data->first_name . " " . $user_data->last_name;
      $email = $user_data->user_email;
    } else {
      $user_id = "";
      $name = "";
      $email = "";
    }

    $body = str_replace("{coupon}", $coupon_code, $body);
    $body = str_replace("{username}", $username, $body);
    $body = str_replace("{referrer}", $referrer, $body);
    $body = str_replace("{promote}", $promote, $body);
    $body = str_replace("{website}", $website, $body);
    $body = str_replace("{name}", $name, $body);
    $body = str_replace("{email}", $email, $body);

    $the_info = "";
    if($info) {
      $info = json_decode($info, true);
      // Decodes labels and values stored HTML-entity encoded by older versions,
      // so the notification does not read "Recipient&#039;s full name".
      if(is_array($info) && function_exists('wcusage_normalize_custom_fields')) {
        $info = wcusage_normalize_custom_fields($info);
      }
      if(is_array($info)) {
        foreach ($info as $key => $value) {
          if(is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
          }
          $the_info .= "<p>" . esc_html($key) . ": " . esc_html($value) . "</p>";
        }
      }
    }
    $body = str_replace("{custom-fields}", $the_info, $body);

    $applicationsurl = admin_url() . "admin.php?page=wcusage_registrations";
    $applicationsurl = "<a href='".$applicationsurl."'>" . $applicationsurl . "</a>";
    $body = str_replace("{adminapplicationsurl}", $applicationsurl, $body);
    $body = str_replace("{adminurl}", $applicationsurl, $body);

      if(isset($options['wcusage_field_registration_admin_email'])) {
        $wcusage_field_registration_admin_email = $options['wcusage_field_registration_admin_email'];
      } else {
        $wcusage_field_registration_admin_email = get_bloginfo( 'admin_email' );
      }

      $to = $wcusage_field_registration_admin_email;

      $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

      $mailer = WC()->mailer();
      $wrapped_message = $mailer->wrap_message($subject, $body);
      $wc_email = new WC_Email;
      $html_message = $wc_email->style_inline($wrapped_message);

      wp_mail( $to, $subject, $html_message, $headers );

    }

  }

}

// Email to affiliate on registration accepted
function wcusage_email_affiliate_register_accepted($user_email, $coupon_code, $message, $username, $name, $skip_registration_check = false) {

  $options = wcusage_get_options();

  $wcusage_field_registration_enable = wcusage_get_setting_value('wcusage_field_registration_enable', '1');
  $wcusage_field_email_registration_accept_enable = wcusage_get_setting_value('wcusage_field_email_registration_accept_enable', '1');

  if(($wcusage_field_registration_enable || $skip_registration_check) && $wcusage_field_email_registration_accept_enable) {

    if(!empty($options['wcusage_field_email_registration_accept_subject']) && !empty($options['wcusage_field_email_registration_accept_message'])) {

      $to = $user_email;
      $from = wcusage_get_from_email();

      $subject = $options['wcusage_field_email_registration_accept_subject'];
      if(!$subject) { $subject = ""; }
      $body = html_entity_decode( $options['wcusage_field_email_registration_accept_message'] );

      if(isset($subject)) {
        if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
        if($username) { $subject = str_replace("{username}", $username, $subject); }
      }

      if($body) {
        $body = str_replace("{coupon}", $coupon_code, $body);
        $body = str_replace("{name}", $name, $body);
        $body = str_replace("{username}", $username, $body);
        $body = str_replace("{message}", $message, $body);
      }

      $dashboardurl = wcusage_get_coupon_shortcode_page(0);
      $dashboardurl = "<a href='".$dashboardurl."'>" . $dashboardurl . "</a>";
      $body = str_replace("{dashboardurl}", $dashboardurl, $body);

      $referralurl = esc_html( wcusage_get_affiliate_url( $coupon_code ) );
      $referralurl = "<a href='".$referralurl."'>" . $referralurl . "</a>";
      $body = str_replace("{referralurl}", $referralurl, $body);

      $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

      $mailer = WC()->mailer();
      $wrapped_message = $mailer->wrap_message($subject, $body);
      $wc_email = new WC_Email;
      $html_message = $wc_email->style_inline($wrapped_message);

      wp_mail( $to, $subject, $html_message, $headers );

    }

  }

}

// Email to affiliate on registration declined
function wcusage_email_affiliate_register_declined($user_email, $coupon_code, $message) {

  $options = wcusage_get_options();

  $wcusage_field_registration_enable = wcusage_get_setting_value('wcusage_field_registration_enable', '1');
  $wcusage_field_email_registration_decline_enable = wcusage_get_setting_value('wcusage_field_email_registration_decline_enable', '1');

  if($wcusage_field_registration_enable && $wcusage_field_email_registration_decline_enable) {

    if(!empty($options['wcusage_field_email_registration_decline_subject']) && !empty($options['wcusage_field_email_registration_decline_message'])) {

      $to = $user_email;
      $from = wcusage_get_from_email();

      $subject = $options['wcusage_field_email_registration_decline_subject'];
      if(!$subject) { $subject = ""; }
      $body = html_entity_decode( $options['wcusage_field_email_registration_decline_message'] );

      if(isset($subject)) {
        if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
      }
      if($body) {
        $body = str_replace("{coupon}", $coupon_code, $body);
        $body = str_replace("{message}", $message, $body);
      }

      $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

      $mailer = WC()->mailer();
      $wrapped_message = $mailer->wrap_message($subject, $body);
      $wc_email = new WC_Email;
      $html_message = $wc_email->style_inline($wrapped_message);

      wp_mail( $to, $subject, $html_message, $headers );

    }

  }

}
// Default subject/message for the "New Coupon Assigned" email.
// These live in functions because both the settings page and the email function need
// them: wcusage_setting_*_option() only *renders* a default, it never writes it to the
// options blob, so on any site that has not re-saved its settings since upgrading the
// wcusage_field_email_coupon_assigned_* keys are simply missing and the email has to
// fall back to the same text the settings page shows.
function wcusage_email_coupon_assigned_default_subject() {
  return __( "New Coupon Assigned: {coupon}", "woo-coupon-usage" );
}

function wcusage_email_coupon_assigned_default_message() {
  return __( "Hello {name},<br/><br/>A new coupon code has been assigned to your affiliate account: {coupon}<br/><br/>It is active and ready to use straight away.<br/><br/>You can track its performance on the affiliate dashboard here: {dashboardurl}<br/><br/>The referral link for this coupon is: {referralurl}<br/><br/>{message}", "woo-coupon-usage" );
}

// Email to affiliate when an admin assigns them an additional coupon.
// Kept separate from wcusage_email_affiliate_register(): that one is the "application
// submitted" email, which is wrong here because the affiliate already exists, is already
// approved, and the new coupon is already active with nothing pending.
// Not gated on wcusage_field_registration_enable - an admin adding a coupon by hand has
// nothing to do with whether the public registration form is switched on.
function wcusage_email_affiliate_coupon_assigned($user_email, $coupon_code, $firstname, $username = "", $message = "") {

  $wcusage_field_email_coupon_assigned_enable = wcusage_get_setting_value('wcusage_field_email_coupon_assigned_enable', '1');

  if(!$wcusage_field_email_coupon_assigned_enable) {
    return;
  }

  $to = $user_email;
  if(!$to) {
    return;
  }

  $from = wcusage_get_from_email();

  $subject = wcusage_get_setting_value('wcusage_field_email_coupon_assigned_subject', wcusage_email_coupon_assigned_default_subject());
  $body = html_entity_decode( wcusage_get_setting_value('wcusage_field_email_coupon_assigned_message', wcusage_email_coupon_assigned_default_message()) );

  if(!$subject || !$body) {
    return;
  }

  if($coupon_code) { $subject = str_replace("{coupon}", $coupon_code, $subject); }
  if($firstname) { $subject = str_replace("{name}", $firstname, $subject); }
  if($username) { $subject = str_replace("{username}", $username, $subject); }

  $body = str_replace("{coupon}", $coupon_code, $body);
  $body = str_replace("{name}", $firstname, $body);
  $body = str_replace("{username}", $username, $body);
  $body = str_replace("{email}", $user_email, $body);
  $body = str_replace("{message}", $message, $body);

  $dashboardurl = wcusage_get_coupon_shortcode_page(0);
  $dashboardurl = "<a href='".$dashboardurl."'>" . $dashboardurl . "</a>";
  $body = str_replace("{dashboardurl}", $dashboardurl, $body);

  $referralurl = esc_html( wcusage_get_affiliate_url( $coupon_code ) );
  $referralurl = "<a href='".$referralurl."'>" . $referralurl . "</a>";
  $body = str_replace("{referralurl}", $referralurl, $body);

  $headers = array( 'Content-Type: text/html; charset=UTF-8;', $from );

  $mailer = WC()->mailer();
  $wrapped_message = $mailer->wrap_message($subject, $body);
  $wc_email = new WC_Email;
  $html_message = $wc_email->style_inline($wrapped_message);

  wp_mail( $to, $subject, $html_message, $headers );

}

// Normalise the admin "Send Notification Email" choice into an email key.
// $send_email used to be a plain bool threaded through wcusage_create_new_registration()
// and wcusage_set_registration_status(), so any legacy truthy value still means the
// "Affiliate Application Accepted" email and third-party callers keep working.
// Returns '' for "do not send".
function wcusage_normalise_send_email_choice($send_email) {
  if ( $send_email === 'coupon_assigned' ) {
    return 'coupon_assigned';
  }
  if ( $send_email === 'none' || $send_email === '' || $send_email === '0' || $send_email === 0 || $send_email === false || $send_email === null ) {
    return '';
  }
  return 'accepted';
}

// Remembered "Send Notification Email" choice, per admin user and per form ($context is
// 'add_coupon' or 'add_affiliate' - the two forms have different sensible defaults, so
// they remember separately). Kept in user meta rather than wcusage_options: it is a
// per-admin preference, and the options blob is autoloaded on every request.
// Stored as 'coupon_assigned'|'accepted'|'none' so a remembered "do not send" survives -
// get_user_meta() returns '' for a missing key, which would otherwise be indistinguishable.
function wcusage_get_send_email_preference($context, $available = array(), $default = '') {
  $user_id = get_current_user_id();
  if (!$user_id) {
    return $default;
  }
  $stored = get_user_meta($user_id, 'wcu_send_email_pref_' . $context, true);
  if ($stored === 'none') {
    return 'none';
  }
  // Fall back to the default if the remembered email has since been turned off in settings.
  if ($stored && (!$available || array_key_exists($stored, $available))) {
    return $stored;
  }
  return $default;
}

function wcusage_save_send_email_preference($context, $choice) {
  $user_id = get_current_user_id();
  if (!$user_id) {
    return;
  }
  $choice = ($choice === 'accepted' || $choice === 'coupon_assigned') ? $choice : 'none';
  update_user_meta($user_id, 'wcu_send_email_pref_' . $context, $choice);
}
