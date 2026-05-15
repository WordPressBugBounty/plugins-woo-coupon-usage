<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_field_cb_privacy( $args )
{
  $options = get_option( 'wcusage_options' );
  ?>

  <div id="privacy-settings" class="settings-area">

  <h1><?php echo esc_html__( 'Privacy & Cookies', 'woo-coupon-usage' ); ?></h1>

  <hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Cookie Settings', 'woo-coupon-usage' ); ?>:</h3>

  <p>
    <?php echo esc_html__('Disabling these will prevent cookies from being stored by the plugin in the visitors browsers.', 'woo-coupon-usage'); ?>
    <br/>
    <?php echo esc_html__('You should only disable this if your primary method of tracking referrals is through customers manually applying the affiliates coupon at checkout.', 'woo-coupon-usage'); ?>
    <br/>
    <?php echo esc_html__('If cookies are disabled, the referral links will still work but will not be as effective, since they can only attempt to auto-apply coupons on the first page they load.', 'woo-coupon-usage'); ?>
  </p>

  <br/>

  <!-- wcusage_field_store_cookies -->
  <?php wcusage_setting_toggle_option('wcusage_field_store_cookies', 1, esc_html__( 'Store cookies for referral links (recommended).', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'This will store a cookie in the visitors browser when they click on a referral link, to automatically apply the coupon code once they add items to their cart, and to track link stats better.', 'woo-coupon-usage' ); ?></i><br/>
  <i><?php echo esc_html__( 'If disabled, then it will only be able to try to automatically apply the coupon code on their first page visit, and URL only conversion tracking will not work. Coupons will be required to track referrals.', 'woo-coupon-usage' ); ?></i><br/>
  <?php wcusage_setting_toggle('.wcusage_field_store_cookies', '.wcu-referral-cookies'); // Show or Hide ?>

  <br/>

  <!-- wcusage_field_store_cookies_mla -->
  <?php wcusage_setting_toggle_option('wcusage_field_store_cookies_mla', 1, esc_html__( 'Store cookies for MLA referral links (recommended).', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'This will store a cookie in the visitors browser when they click on a referral link, so the referral can be tracked even if they do not register on their first page visit.', 'woo-coupon-usage' ); ?></i><br/>
  <i><?php echo esc_html__( 'If this is disabled, then it will only be able to try to track referrals if they register on their first page visit.', 'woo-coupon-usage' ); ?></i><br/>

  <br/>

  <!-- wcusage_field_store_cookies_domains -->
  <?php wcusage_setting_toggle_option('wcusage_field_store_cookies_domains', 1, esc_html__( 'Store cookies for domain link tracking and blacklists.', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'This is required for domain link tracking and domain blacklists to work.', 'woo-coupon-usage' ); ?></i><br/>

  <br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Session Settings', 'woo-coupon-usage' ); ?>:</h3>

  <p>
    <?php echo esc_html__( 'WooCommerce sessions can be used as a backup method to auto-apply referral coupons (only during their session) when the plugin referral cookies do not exist, for example if cookies are blocked, disabled, deleted, or otherwise unavailable.', 'woo-coupon-usage' ); ?>
  </p>
  <p>
    <?php echo esc_html__( 'This uses the existing WooCommerce customer session cookie if one is already present in the visitors browser, or will create one if not.', 'woo-coupon-usage' ); ?>
  </p>

  <br/>

  <!-- wcusage_field_store_sessions -->
  <?php wcusage_setting_toggle_option('wcusage_field_store_sessions', 1, esc_html__( 'Use WooCommerce sessions as a backup for referral coupon auto-apply.', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'When enabled, the referral coupon can be stored in the WooCommerce session and used to auto-apply the coupon if the referral cookie does not exist when the visitor has items in their cart.', 'woo-coupon-usage' ); ?></i><br/>
  <i><?php echo esc_html__( 'This is only used as a backup for referral coupon auto-apply, and is not used for URL-only order referral tracking or referral click conversion tracking.', 'woo-coupon-usage' ); ?></i><br/>

  <br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Clicks Log Privacy', 'woo-coupon-usage' ); ?>:</h3>

  <?php
    wcusage_setting_option_set_default($options, 'wcusage_field_track_click_ip', 1);
    $wcusage_track_click_ip = wcusage_get_setting_value('wcusage_field_track_click_ip', 1);
  ?>
  <p id="wcusage_field_track_click_ip_privacy_p" style="margin-left: 0px">
    <strong><?php echo esc_html__( 'Visitor Identification Method for Click Tracking', 'woo-coupon-usage' ); ?>:</strong><br/>
    <select id="wcusage_field_track_click_ip_privacy" class="wcusage_field_track_click_ip_privacy" checktype="ignore">
      <option value="1" <?php selected( $wcusage_track_click_ip, '1' ); ?>><?php echo esc_html__( 'IP Address', 'woo-coupon-usage' ); ?></option>
      <option value="0" <?php selected( $wcusage_track_click_ip, '0' ); ?>><?php echo esc_html__( 'Random ID (Cookie)', 'woo-coupon-usage' ); ?></option>
      <option value="2" <?php selected( $wcusage_track_click_ip, '2' ); ?>><?php echo esc_html__( 'Random ID (Session)', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>
  <i><?php echo esc_html__( 'IP Address: The visitor\'s IP address is stored in the "clicks" database table and used to check if a click has already been tracked for that visitor.', 'woo-coupon-usage' ); ?></i><br/>
  <i><?php echo esc_html__( 'Random ID (Cookie): A random ID is stored as a cookie ("wcusage_referral_id") for new referral clicks and used to check if a click has already been tracked for that visitor.', 'woo-coupon-usage' ); ?></i><br/>
  <i><?php echo esc_html__( 'Random ID (Session): A random ID is stored in the WooCommerce session for new referral clicks and used to check if a click has already been tracked for that visitor. No additional cookie is set.', 'woo-coupon-usage' ); ?></i><br/>

  <script>
  jQuery( document ).ready(function($) {
    var $original = $('#wcusage_field_track_click_ip');
    var $privacy = $('#wcusage_field_track_click_ip_privacy');

    if (!$privacy.length) {
      return;
    }

    function wcusagePrivacyLegacyMode() {
      var $select = $('#wcusage_field_settings_legacy');
      if ($select.length) {
        return $select.val() === '1';
      }
      var $checkbox = $('.wcusage_field_settings_legacy');
      return $checkbox.length ? $checkbox.is(':checked') : false;
    }

    function wcusagePrivacySyncFromOriginal() {
      if ($original.length) {
        $privacy.val($original.val());
      }
    }

    wcusagePrivacySyncFromOriginal();

    $(document).on('change', '#wcusage_field_track_click_ip', function() {
      $privacy.val($(this).val());
    });

    $(document).on('change', '#wcusage_field_track_click_ip_privacy', function() {
      if (!$original.length) {
        return;
      }
      $original.val($(this).val());
      if (!wcusagePrivacyLegacyMode() && typeof window.wcu_ajax_update_the_options === 'function') {
        window.wcu_ajax_update_the_options($original, 'id', 'wcu-update-text', 1, '', 'select');
      }
    });

    $('.wcusage-settings-form').on('submit', function() {
      if ($original.length) {
        $original.val($privacy.val());
      }
    });
  });
  </script>

  </div>

  <?php
}