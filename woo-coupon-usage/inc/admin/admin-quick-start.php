<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin onboarding UI: echoed values are escaped at source or static strings.

/**
 * Simple onboarding for small programs.
 *
 * - A "What kind of program?" first screen in the setup wizard, with a quick-start
 *   path (basics -> first affiliate -> done) for stores with just a few affiliates.
 * - One-click creation of a template coupon, so "Add New Affiliate" works without
 *   having to create one by hand first.
 * - An "Essentials / All settings" view on the settings page.
 * - A getting started checklist on the main dashboard, shown until the first
 *   affiliate exists.
 *
 * Everything here is opt-in or only fills in missing values: existing settings are
 * never overwritten, and stores that already have affiliates never see the checklist.
 */

/**
 * The setup path chosen in the wizard: 'quick', 'full', 'manual', or '' if none.
 *
 * @return string
 */
function wcusage_get_setup_mode() {
  $mode = get_option( 'wcusage_setup_mode', '' );
  return in_array( $mode, array( 'quick', 'full', 'manual' ), true ) ? $mode : '';
}

/**
 * Does the template coupon setting point at a coupon that exists?
 *
 * @return bool
 */
function wcusage_template_coupon_exists() {
  $template = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
  if ( ! $template ) {
    return false;
  }
  $info = wcusage_get_coupon_info( $template );
  return ! empty( $info[2] );
}

/**
 * Create a template coupon and save it as the registration template.
 *
 * If a template code is already set but no such coupon exists, the coupon is
 * created with that code, so the admin's setting starts working as typed.
 * Otherwise a code with a random suffix is used, so it can't be guessed and
 * redeemed at checkout.
 *
 * @param float  $discount Percentage discount the affiliate's customers get.
 * @param string $code     Optional code to use.
 *
 * @return string|false The template coupon code, or false on failure.
 */
function wcusage_create_template_coupon( $discount = 10, $code = '' ) {
  if ( ! class_exists( 'WC_Coupon' ) ) {
    return false;
  }

  $code = wc_format_coupon_code( $code );
  if ( ! $code || wc_get_coupon_id_by_code( $code ) ) {
    $code = 'affiliate-template-' . strtolower( wp_generate_password( 6, false, false ) );
  }

  $discount = max( 0, min( 100, (float) $discount ) );

  $coupon = new WC_Coupon();
  $coupon->set_code( $code );
  $coupon->set_discount_type( 'percent' );
  $coupon->set_amount( $discount );
  // The description is the post excerpt, which is not copied to affiliate coupons.
  $coupon->set_description( __( 'Template coupon for Coupon Affiliates. New affiliate coupons copy their settings from this one, so edit it to change the discount that new affiliates\' customers get.', 'woo-coupon-usage' ) );
  $coupon_id = $coupon->save();

  if ( ! $coupon_id ) {
    return false;
  }

  $options = get_option( 'wcusage_options' );
  if ( ! is_array( $options ) ) {
    $options = array();
  }
  $options['wcusage_field_registration_coupon_template'] = $code;
  update_option( 'wcusage_options', $options );

  if ( function_exists( 'wcusage_flush_coupon_info_cache' ) ) {
    wcusage_flush_coupon_info_cache();
  }

  return $code;
}

/**
 * Make sure an affiliate dashboard page exists, creating one if needed.
 *
 * @return int|string The page ID, 'portal' when the affiliate portal is used, or 0 on failure.
 */
function wcusage_quickstart_ensure_dashboard_page() {
  if ( wcusage_get_setting_value( 'wcusage_field_portal_enable', '0' ) ) {
    return 'portal';
  }

  $options = get_option( 'wcusage_options' );
  if ( ! is_array( $options ) ) {
    $options = array();
  }

  $page_id = isset( $options['wcusage_dashboard_page'] ) ? (int) $options['wcusage_dashboard_page'] : 0;
  // A published page that is set is kept even when the shortcode isn't in its
  // content: it can come from a page builder, a template or a filter.
  if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
    return $page_id;
  }

  // Reuse any published page that already has the shortcode.
  $existing_url = wcusage_get_coupon_shortcode_page( '0' );
  $existing_id  = $existing_url ? url_to_postid( $existing_url ) : 0;
  if ( $existing_id && wcusage_page_contain_shortcode( $existing_id ) ) {
    $page_id = $existing_id;
  } else {
    $page_id = wp_insert_post( array(
      'post_title'     => __( 'Affiliate Dashboard', 'woo-coupon-usage' ),
      'post_type'      => 'page',
      'post_name'      => 'affiliates',
      'comment_status' => 'closed',
      'ping_status'    => 'closed',
      'post_content'   => '[couponaffiliates]',
      'post_status'    => 'publish',
      'post_author'    => get_current_user_id(),
    ), true );
    if ( is_wp_error( $page_id ) || ! $page_id ) {
      return 0;
    }
  }

  $options = get_option( 'wcusage_options' );
  if ( ! is_array( $options ) ) {
    $options = array();
  }
  $options['wcusage_dashboard_page'] = $page_id;
  update_option( 'wcusage_options', $options );

  return $page_id;
}

/**
 * Does at least one coupon have an affiliate user assigned?
 *
 * @return bool
 */
function wcusage_has_any_affiliate() {
  $ids = get_posts( array(
    'post_type'        => 'shop_coupon',
    'post_status'      => 'publish',
    'posts_per_page'   => 1,
    'fields'           => 'ids',
    'orderby'          => 'none',
    'no_found_rows'    => true,
    'suppress_filters' => true,
    'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
      array(
        'key'     => 'wcu_select_coupon_user',
        'value'   => '',
        'compare' => '!=',
      ),
    ),
  ) );
  return ! empty( $ids );
}

/**
 * Handle onboarding requests before any output, so they can redirect.
 */
add_action( 'admin_init', 'wcusage_quickstart_handle_requests' );
function wcusage_quickstart_handle_requests() {

  if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
    return;
  }

  $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

  // Wizard: path chosen on the first screen.
  if ( 'wcusage_setup' === $page && isset( $_GET['wcusage_setup_mode'] ) ) {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    $mode  = sanitize_key( wp_unslash( $_GET['wcusage_setup_mode'] ) );
    if ( wp_verify_nonce( $nonce, 'wcusage_setup_mode' ) && in_array( $mode, array( 'full', 'manual' ), true ) ) {
      update_option( 'wcusage_setup_mode', $mode );
      if ( 'manual' === $mode ) {
        wp_safe_redirect( admin_url( 'admin.php?page=wcusage_settings' ) );
      } else {
        wp_safe_redirect( admin_url( 'admin.php?page=wcusage_setup&step=1' ) );
      }
      exit;
    }
  }

  // Wizard: quick-start basics.
  if ( 'wcusage_setup' === $page && isset( $_POST['wcusage_quickstart_basics'] ) ) {
    check_admin_referer( 'wcusage_quickstart_basics' );
    wcusage_quickstart_save_basics();
  }

  // Wizard: quick-start first affiliate.
  if ( 'wcusage_setup' === $page && isset( $_POST['wcusage_quickstart_affiliate'] ) ) {
    check_admin_referer( 'wcusage_quickstart_affiliate' );
    wcusage_quickstart_save_affiliate();
  }

  // One-click template coupon (Add New Affiliate page and setup checklist).
  if ( isset( $_GET['wcusage_create_template'] ) ) {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( wp_verify_nonce( $nonce, 'wcusage_create_template' ) ) {
      $result = 'failed';
      if ( wcusage_template_coupon_exists() ) {
        $result = 'exists';
      } elseif ( wcusage_create_template_coupon( 10, wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' ) ) ) {
        $result = 'created';
      }
      $url = remove_query_arg( array( 'wcusage_create_template', '_wpnonce' ) );
      wp_safe_redirect( add_query_arg( 'wcusage_template', $result, $url ) );
      exit;
    }
  }

  // Dashboard: dismiss the getting started checklist.
  if ( 'wcusage' === $page && isset( $_GET['wcusage_dismiss_getting_started'] ) ) {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( wp_verify_nonce( $nonce, 'wcusage_dismiss_getting_started' ) ) {
      update_user_meta( get_current_user_id(), 'wcusage_getting_started_dismissed', '1' );
      wp_safe_redirect( admin_url( 'admin.php?page=wcusage' ) );
      exit;
    }
  }

}

/**
 * Save the quick-start basics: dashboard page, commission, template coupon, sign-ups.
 */
function wcusage_quickstart_save_basics() {

  $commission = isset( $_POST['wcusage_qs_commission'] ) ? (float) wp_unslash( $_POST['wcusage_qs_commission'] ) : 0;
  $commission = max( 0, min( 100, $commission ) );
  $discount   = isset( $_POST['wcusage_qs_discount'] ) ? (float) wp_unslash( $_POST['wcusage_qs_discount'] ) : 0;
  $signups    = ! empty( $_POST['wcusage_qs_signups'] ) ? '1' : '0';

  $page = wcusage_quickstart_ensure_dashboard_page();

  $options = get_option( 'wcusage_options' );
  if ( ! is_array( $options ) ) {
    $options = array();
  }
  $options['wcusage_field_affiliate']           = (string) $commission;
  $options['wcusage_field_registration_enable'] = $signups;
  update_option( 'wcusage_options', $options );

  $template_ok = wcusage_template_coupon_exists();
  if ( ! $template_ok ) {
    $template_ok = (bool) wcusage_create_template_coupon( $discount, wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' ) );
  }

  if ( ! $page || ! $template_ok ) {
    $GLOBALS['wcusage_quickstart_error'] = ! $page
      ? __( 'The affiliate dashboard page could not be created. Please try again, or use the full setup wizard.', 'woo-coupon-usage' )
      : __( 'The template coupon could not be created. Please check that WooCommerce coupons are enabled, then try again.', 'woo-coupon-usage' );
    return;
  }

  // Only the first path chosen is recorded: coming back here from the getting started
  // checklist must not move a store that ran the full wizard onto the Essentials view.
  if ( '' === wcusage_get_setup_mode() ) {
    update_option( 'wcusage_setup_mode', 'quick' );
  }
  update_option( 'wcusage_setup_complete', '1' );

  // Fill in every other setting with its default, as the full wizard does on its last step.
  if ( function_exists( 'wcusage_get_all_default_settings' ) ) {
    wcusage_get_all_default_settings();
  }
  do_action( 'wcusage_setup_completed' );

  wp_safe_redirect( admin_url( 'admin.php?page=wcusage_setup&step=quick-affiliate' ) );
  exit;
}

/**
 * Add the first affiliate from the quick-start wizard, through the same code path as
 * the "Add New Affiliate" admin form.
 */
function wcusage_quickstart_save_affiliate() {

  $name   = isset( $_POST['wcusage_qs_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wcusage_qs_name'] ) ) : '';
  $email  = isset( $_POST['wcusage_qs_email'] ) ? sanitize_email( wp_unslash( $_POST['wcusage_qs_email'] ) ) : '';
  $coupon = isset( $_POST['wcusage_qs_coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['wcusage_qs_coupon'] ) ) : '';
  $notify = ! empty( $_POST['wcusage_qs_notify'] );

  $GLOBALS['wcusage_quickstart_prefill'] = array( 'name' => $name, 'email' => $email, 'coupon' => $coupon, 'notify' => $notify );

  if ( ! is_email( $email ) ) {
    $GLOBALS['wcusage_quickstart_error'] = __( 'Please enter a valid email address.', 'woo-coupon-usage' );
    return;
  }
  if ( '' === $coupon ) {
    $GLOBALS['wcusage_quickstart_error'] = __( 'Please enter a coupon code for this affiliate.', 'woo-coupon-usage' );
    return;
  }
  if ( wcusage_admin_registration_coupon_code_taken( $coupon ) ) {
    $GLOBALS['wcusage_quickstart_error'] = sprintf( __( 'The coupon code "%s" is already in use. Please choose a different one.', 'woo-coupon-usage' ), $coupon );
    return;
  }
  if ( ! wcusage_template_coupon_exists() ) {
    $GLOBALS['wcusage_quickstart_error'] = __( 'No template coupon is set up yet. Please complete the first quick setup step.', 'woo-coupon-usage' );
    return;
  }

  // Existing user with this email: use their account. Otherwise the email is the username.
  $user = get_user_by( 'email', $email );
  if ( $user ) {
    $username = $user->user_login;
  } else {
    $username = sanitize_user( $email, true );
    if ( username_exists( $username ) ) {
      $base = $username;
      $n    = 2;
      while ( username_exists( $base . $n ) ) {
        $n++;
      }
      $username = $base . $n;
    }
  }

  $name_parts = preg_split( '/\s+/', trim( $name ), 2 );

  // wcusage_post_submit_application() reads the Add New Affiliate form fields from $_POST.
  $_POST['wcu-input-username']        = $username;
  $_POST['wcu-input-email']           = $user ? '' : $email;
  $_POST['wcu-input-first-name']      = isset( $name_parts[0] ) ? $name_parts[0] : '';
  $_POST['wcu-input-last-name']       = isset( $name_parts[1] ) ? $name_parts[1] : '';
  $_POST['wcu-input-coupon']          = $coupon;
  $_POST['wcu-send-email']            = $notify ? 'accepted' : 'none';
  $_POST['submitaffiliateapplication'] = '1';

  // The Add New Affiliate form remembers the admin's email choice. This is a separate
  // form, so keep whatever they picked there.
  $email_pref = get_user_meta( get_current_user_id(), 'wcu_send_email_pref_add_affiliate', true );

  // Success is judged by a new coupon being assigned to the user: an existing user's
  // account is there whether or not the submission worked.
  $coupons_before = $user ? array_map( 'intval', (array) wcusage_get_users_coupons_ids( $user->ID ) ) : array();

  ob_start();
  wcusage_post_submit_application( 1 );
  // Most validation errors are printed rather than returned.
  $printed = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );

  if ( '' === $email_pref ) {
    delete_user_meta( get_current_user_id(), 'wcu_send_email_pref_add_affiliate' );
  } else {
    update_user_meta( get_current_user_id(), 'wcu_send_email_pref_add_affiliate', $email_pref );
  }

  if ( ! empty( $GLOBALS['wcusage_admin_registration_error'] ) ) {
    $GLOBALS['wcusage_quickstart_error'] = wp_strip_all_tags( $GLOBALS['wcusage_admin_registration_error'] );
    return;
  }

  $user          = get_user_by( 'login', $username );
  $coupons_after = $user ? array_map( 'intval', (array) wcusage_get_users_coupons_ids( $user->ID ) ) : array();
  if ( ! $user || ! array_diff( $coupons_after, $coupons_before ) ) {
    $GLOBALS['wcusage_quickstart_error'] = '' !== $printed
      ? $printed
      : __( 'The affiliate could not be created. Please try again from the "Add New Affiliate" page.', 'woo-coupon-usage' );
    return;
  }

  $new_coupons = array_values( array_diff( $coupons_after, $coupons_before ) );
  wp_safe_redirect( admin_url( 'admin.php?page=wcusage_setup&step=quick-done&affiliate=' . (int) $user->ID . '&coupon=' . (int) $new_coupons[0] ) );
  exit;
}

/**
 * Render one of the onboarding wizard screens.
 *
 * @param string $step 'start', 'quick', 'quick-affiliate' or 'quick-done'.
 */
function wcusage_setup_render_quickstart_step( $step ) {
  // Styled by css/admin-setup-wizard.css.
  if ( 'quick' === $step || 'quick-affiliate' === $step || 'quick-done' === $step ) {
    $labels = array(
      'quick'           => __( 'Basics', 'woo-coupon-usage' ),
      'quick-affiliate' => sprintf( __( 'First %s', 'woo-coupon-usage' ), wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) ) ),
      'quick-done'      => __( 'Done', 'woo-coupon-usage' ),
    );
    $current_index = array_search( $step, array_keys( $labels ), true );
    echo '<ol class="wcu-qs-steps">';
    $i = 0;
    foreach ( $labels as $key => $label ) {
      $class = $key === $step ? 'current' : ( $i < $current_index ? 'is-done' : '' );
      echo '<li class="' . esc_attr( $class ) . '"' . ( $key === $step ? ' aria-current="step"' : '' ) . '>' . esc_html( $label ) . '</li>';
      $i++;
    }
    echo '</ol>';
  }

  if ( ! empty( $GLOBALS['wcusage_quickstart_error'] ) ) {
    echo '<div class="wcu-qs-error" role="alert"><span class="fa-solid fa-circle-exclamation" aria-hidden="true"></span> <span>' . esc_html( $GLOBALS['wcusage_quickstart_error'] ) . '</span></div>';
  }

  switch ( $step ) {
    case 'quick':
      wcusage_setup_render_quick_basics();
      break;
    case 'quick-affiliate':
      wcusage_setup_render_quick_affiliate();
      break;
    case 'quick-done':
      wcusage_setup_render_quick_done();
      break;
    default:
      wcusage_setup_render_start();
  }
}

/**
 * First wizard screen: what kind of program is this?
 */
function wcusage_setup_render_start() {
  // The open program (the full wizard) is the default, recommended path.
  $choices = array(
    array(
      'url'         => wp_nonce_url( admin_url( 'admin.php?page=wcusage_setup&wcusage_setup_mode=full' ), 'wcusage_setup_mode' ),
      'icon'        => 'fa-bullhorn',
      'title'       => __( 'An open affiliate program', 'woo-coupon-usage' ),
      'label'       => __( 'Advanced', 'woo-coupon-usage' ),
      'description' => __( 'People can register as an affiliate through your sign-up form. We walk you through the dashboard, registration, commission and email settings. Fully customisable.', 'woo-coupon-usage' ),
      'meta'        => __( 'About 5 minutes', 'woo-coupon-usage' ),
      'meta_icon'   => 'fa-regular fa-clock',
      'recommended' => true,
    ),
    array(
      'url'         => admin_url( 'admin.php?page=wcusage_setup&step=quick' ),
      'icon'        => 'fa-user-group',
      'title'       => __( 'Just a few people I know', 'woo-coupon-usage' ),
      'label'       => __( 'Essentials', 'woo-coupon-usage' ),
      'description' => __( 'A friend, partner or customer who wants to promote your store. We set up the essentials for you, then help you add your first affiliate.', 'woo-coupon-usage' ),
      'meta'        => __( 'About 2 minutes', 'woo-coupon-usage' ),
      'meta_icon'   => 'fa-regular fa-clock',
      'recommended' => false,
    ),
    array(
      'url'         => wp_nonce_url( admin_url( 'admin.php?page=wcusage_setup&wcusage_setup_mode=manual' ), 'wcusage_setup_mode' ),
      'icon'        => 'fa-sliders',
      'title'       => __( 'I\'ll set it up myself', 'woo-coupon-usage' ),
      'description' => __( 'Skip the wizard and go straight to the full settings page.', 'woo-coupon-usage' ),
      'meta'        => __( 'For experienced users', 'woo-coupon-usage' ),
      'meta_icon'   => 'fa-solid fa-screwdriver-wrench',
      'recommended' => false,
    ),
  );
  ?>
  <div class="wcu-qs-start">
    <div class="wcu-qs-start__header">
      <p class="wcu-qs-start__eyebrow"><?php echo esc_html__( 'Setup wizard', 'woo-coupon-usage' ); ?></p>
      <h1 class="wcu-qs-start__title"><?php echo esc_html__( 'Welcome to Coupon Affiliates', 'woo-coupon-usage' ); ?></h1>
      <p class="wcu-qs-start__lead"><?php echo esc_html__( 'What kind of affiliate program are you setting up? We\'ll only ask about what you need.', 'woo-coupon-usage' ); ?></p>
    </div>

    <ul class="wcu-qs-start__choices">
      <?php foreach ( $choices as $choice ) { ?>
      <li>
        <a class="wcu-qs-option<?php echo $choice['recommended'] ? ' wcu-qs-option--recommended' : ''; ?>" href="<?php echo esc_url( $choice['url'] ); ?>">
          <span class="wcu-qs-option__icon" aria-hidden="true"><span class="fa-solid <?php echo esc_attr( $choice['icon'] ); ?>"></span></span>
          <span class="wcu-qs-option__body">
            <span class="wcu-qs-option__title">
              <?php echo esc_html( $choice['title'] ); ?>
              <?php if ( ! empty( $choice['label'] ) ) { ?><span class="wcu-qs-option__tag"><?php echo esc_html( $choice['label'] ); ?></span><?php } ?>
              <?php if ( $choice['recommended'] ) { ?><span class="wcu-qs-option__pill"><?php echo esc_html__( 'Recommended', 'woo-coupon-usage' ); ?></span><?php } ?>
            </span>
            <span class="wcu-qs-option__desc"><?php echo esc_html( $choice['description'] ); ?></span>
            <span class="wcu-qs-option__meta"><span class="<?php echo esc_attr( $choice['meta_icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $choice['meta'] ); ?></span>
          </span>
          <span class="wcu-qs-option__arrow fa-solid fa-chevron-right" aria-hidden="true"></span>
        </a>
      </li>
      <?php } ?>
    </ul>

    <p class="wcu-qs-start__note">
      <span class="fa-solid fa-circle-info" aria-hidden="true"></span>
      <?php echo esc_html__( 'This only changes how much we ask you now. Every feature stays available, and you can change any setting later.', 'woo-coupon-usage' ); ?>
    </p>
  </div>
  <?php
}

/**
 * Quick-start step 1: the few settings a small program needs.
 */
function wcusage_setup_render_quick_basics() {
  $commission = (float) wcusage_get_setting_value( 'wcusage_field_affiliate', '0' );
  // Suggest 10% only when no commission of any kind is set yet, so a store paying a
  // fixed amount isn't given a percentage on top by pressing Save.
  if ( $commission <= 0
    && (float) wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_order', '0' ) <= 0
    && (float) wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_product', '0' ) <= 0 ) {
    $commission = 10;
  }
  $template_exists = wcusage_template_coupon_exists();
  $template_code   = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
  $has_dashboard   = (bool) wcusage_get_coupon_shortcode_page( '0' );
  // A new store starts unticked: sign-ups are on by default, which isn't a choice made
  // here. Once the store has been set up, show the real setting, so coming back here
  // and saving never switches sign-ups off. (Sign-ups work without a separate
  // registration page, as the dashboard page shows the form.)
  $is_set_up  = '' !== wcusage_get_setup_mode() || get_option( 'wcusage_setup_complete' ) || wcusage_get_setting_value( 'wcusage_registration_page', '' );
  $signups_on = $is_set_up && wcusage_get_setting_value( 'wcusage_field_registration_enable', '1' );
  $affiliate_text  = strtolower( wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) ) );
  ?>
  <h1 class="wcu-setup-title"><?php echo esc_html__( 'Quick setup', 'woo-coupon-usage' ); ?></h1>
  <p class="wcu-setup-lead wcu-qs-intro"><?php echo esc_html__( 'Each affiliate gets their own coupon code to share. When a customer uses it, the customer gets a discount, and the affiliate earns commission on the order.', 'woo-coupon-usage' ); ?></p>

  <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup&step=quick' ) ); ?>">
    <?php wp_nonce_field( 'wcusage_quickstart_basics' ); ?>

    <div class="wcu-qs-field">
      <label for="wcusage_qs_commission"><?php echo esc_html( sprintf( __( 'How much commission should each %s earn?', 'woo-coupon-usage' ), $affiliate_text ) ); ?></label>
      <span class="wcu-input-affix">
        <input type="number" id="wcusage_qs_commission" name="wcusage_qs_commission" min="0" max="100" step="0.01" value="<?php echo esc_attr( $commission ); ?>">
        <span class="wcu-input-affix__unit" aria-hidden="true">%</span>
      </span>
      <span class="description"><?php echo esc_html__( 'A percentage of each order total. 10% is a common starting point. You can set a different rate for any affiliate later.', 'woo-coupon-usage' ); ?></span>
    </div>

    <?php if ( ! $template_exists ) { ?>
    <div class="wcu-qs-field">
      <label for="wcusage_qs_discount"><?php echo esc_html__( 'What discount should their customers get?', 'woo-coupon-usage' ); ?></label>
      <span class="wcu-input-affix">
        <input type="number" id="wcusage_qs_discount" name="wcusage_qs_discount" min="0" max="100" step="0.01" value="10">
        <span class="wcu-input-affix__unit" aria-hidden="true">%</span>
      </span>
      <span class="description"><?php echo esc_html__( 'Off the customer\'s order when they use an affiliate\'s coupon code. Enter 0 for no discount.', 'woo-coupon-usage' ); ?></span>
    </div>
    <?php } ?>

    <div class="wcu-qs-field">
      <label class="wcu-qs-check">
        <input type="checkbox" name="wcusage_qs_signups" value="1" <?php checked( $signups_on ); ?>>
        <?php echo esc_html__( 'Let people apply to join through a public sign-up form', 'woo-coupon-usage' ); ?>
      </label>
      <span class="description"><?php echo esc_html__( 'Leave this off if you\'ll only add people you know yourself. You can switch it on at any time.', 'woo-coupon-usage' ); ?></span>
    </div>

    <div class="wcu-qs-panel">
    <p class="wcu-qs-panel__title"><?php echo esc_html__( 'We\'ll also do this for you:', 'woo-coupon-usage' ); ?></p>
    <ul class="wcu-qs-list">
      <?php if ( ! $has_dashboard ) { ?>
      <li><?php echo esc_html__( 'Create an "Affiliate Dashboard" page, where your affiliates see their sales and commission.', 'woo-coupon-usage' ); ?></li>
      <?php } else { ?>
      <li><?php echo esc_html__( 'Use your existing affiliate dashboard page.', 'woo-coupon-usage' ); ?></li>
      <?php } ?>
      <?php if ( ! $template_exists ) { ?>
      <li><?php echo esc_html__( 'Create a "template coupon" with your discount. Each new affiliate\'s coupon is copied from it.', 'woo-coupon-usage' ); ?></li>
      <?php } else { ?>
      <li><?php echo esc_html( sprintf( __( 'Use your existing template coupon (%s) for new affiliate coupons.', 'woo-coupon-usage' ), $template_code ) ); ?></li>
      <?php } ?>
      <li><?php echo esc_html__( 'Keep sensible defaults for everything else.', 'woo-coupon-usage' ); ?></li>
    </ul>
    </div>

    <div class="wcu-qs-actions">
      <button type="submit" name="wcusage_quickstart_basics" value="1" class="button button-primary"><?php echo esc_html__( 'Save & Continue', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></button>
      <a class="wcu-qs-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup' ) ); ?>"><?php echo esc_html__( 'Back', 'woo-coupon-usage' ); ?></a>
    </div>
  </form>
  <?php
}

/**
 * Quick-start step 2: add the first affiliate.
 */
function wcusage_setup_render_quick_affiliate() {
  $affiliate_text = wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) );

  if ( ! wcusage_template_coupon_exists() ) {
    ?>
    <h1 class="wcu-setup-title"><?php echo esc_html( sprintf( __( 'Add your first %s', 'woo-coupon-usage' ), strtolower( $affiliate_text ) ) ); ?></h1>
    <p class="wcu-setup-lead"><?php echo esc_html__( 'Before adding an affiliate, please complete the quick setup basics.', 'woo-coupon-usage' ); ?></p>
    <div class="wcu-qs-actions">
      <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup&step=quick' ) ); ?>"><?php echo esc_html__( 'Go to Quick Setup', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
    </div>
    <?php
    return;
  }

  $prefill = isset( $GLOBALS['wcusage_quickstart_prefill'] ) ? $GLOBALS['wcusage_quickstart_prefill'] : array();
  $prefill = wp_parse_args( $prefill, array( 'name' => '', 'email' => '', 'coupon' => '', 'notify' => true ) );
  $email_available = wcusage_get_setting_value( 'wcusage_field_email_registration_accept_enable', '1' );
  ?>
  <h1 class="wcu-setup-title"><?php echo esc_html( sprintf( __( 'Add your first %s', 'woo-coupon-usage' ), strtolower( $affiliate_text ) ) ); ?></h1>
  <p class="wcu-setup-lead wcu-qs-intro"><?php echo esc_html__( 'Who will be promoting your store? We\'ll create their account and coupon code, and they can log in to see their sales and commission.', 'woo-coupon-usage' ); ?></p>

  <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup&step=quick-affiliate' ) ); ?>">
    <?php wp_nonce_field( 'wcusage_quickstart_affiliate' ); ?>

    <div class="wcu-qs-field">
      <label for="wcusage_qs_name"><?php echo esc_html__( 'Their name', 'woo-coupon-usage' ); ?></label>
      <input type="text" id="wcusage_qs_name" name="wcusage_qs_name" value="<?php echo esc_attr( $prefill['name'] ); ?>" autocomplete="off">
    </div>

    <div class="wcu-qs-field">
      <label for="wcusage_qs_email"><?php echo esc_html__( 'Their email address', 'woo-coupon-usage' ); ?></label>
      <input type="email" id="wcusage_qs_email" name="wcusage_qs_email" value="<?php echo esc_attr( $prefill['email'] ); ?>" required autocomplete="off">
      <span class="description"><?php echo esc_html__( 'If they already have an account on your site, we\'ll use it.', 'woo-coupon-usage' ); ?></span>
    </div>

    <div class="wcu-qs-field">
      <label for="wcusage_qs_coupon"><?php echo esc_html__( 'Their coupon code', 'woo-coupon-usage' ); ?></label>
      <input type="text" id="wcusage_qs_coupon" name="wcusage_qs_coupon" value="<?php echo esc_attr( $prefill['coupon'] ); ?>" required autocomplete="off">
      <span class="description"><?php echo esc_html__( 'What they\'ll share with people, for example JANE10.', 'woo-coupon-usage' ); ?></span>
    </div>

    <?php if ( $email_available ) { ?>
    <div class="wcu-qs-field">
      <label class="wcu-qs-check">
        <input type="checkbox" name="wcusage_qs_notify" value="1" <?php checked( $prefill['notify'] ); ?>>
        <?php echo esc_html__( 'Email them their coupon code and a link to their dashboard', 'woo-coupon-usage' ); ?>
      </label>
    </div>
    <?php } ?>

    <div class="wcu-qs-actions">
      <button type="submit" name="wcusage_quickstart_affiliate" value="1" class="button button-primary"><?php echo esc_html( sprintf( __( 'Add %s', 'woo-coupon-usage' ), $affiliate_text ) ); ?> <span class="fa-solid fa-circle-arrow-right"></span></button>
      <a class="wcu-qs-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup&step=quick-done' ) ); ?>"><?php echo esc_html__( 'Skip for now', 'woo-coupon-usage' ); ?></a>
    </div>
  </form>

  <script>
  jQuery(function($) {
    // Suggest a coupon code from the name until the admin types their own.
    var $coupon = $('#wcusage_qs_coupon');
    var edited = $coupon.val() !== '';
    $coupon.on('input', function() { edited = true; });
    $('#wcusage_qs_name').on('input', function() {
      if (edited) { return; }
      var first = $(this).val().trim().split(/\s+/)[0] || '';
      var code = first.toUpperCase().replace(/[^A-Z0-9]/g, '');
      $coupon.val(code ? code + <?php echo wp_json_encode( (string) wcusage_quickstart_template_discount_label() ); ?> : '');
    });
  });
  </script>
  <?php
}

/**
 * The template coupon's percentage discount as a whole number string ('10'), or ''
 * when it isn't a percentage discount, for suggesting coupon codes like JANE10.
 *
 * @return string
 */
function wcusage_quickstart_template_discount_label() {
  $template = wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
  $id       = $template ? wc_get_coupon_id_by_code( $template ) : 0;
  if ( ! $id ) {
    return '';
  }
  $coupon = new WC_Coupon( $id );
  if ( 'percent' !== $coupon->get_discount_type() || (float) $coupon->get_amount() <= 0 ) {
    return '';
  }
  return (string) round( (float) $coupon->get_amount() );
}

/**
 * Quick-start step 3: what was set up, and what to do next.
 */
function wcusage_setup_render_quick_done() {
  $affiliate_id   = isset( $_GET['affiliate'] ) ? absint( $_GET['affiliate'] ) : 0;
  $user           = $affiliate_id ? get_userdata( $affiliate_id ) : false;
  $coupon_ids     = $user ? array_map( 'intval', (array) wcusage_get_users_coupons_ids( $user->ID ) ) : array();
  // Show the coupon just added, for a user who already had others.
  $new_coupon_id  = isset( $_GET['coupon'] ) ? absint( $_GET['coupon'] ) : 0;
  if ( $new_coupon_id && in_array( $new_coupon_id, $coupon_ids, true ) ) {
    $coupon_ids = array( $new_coupon_id );
  }
  $affiliate_text = wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) );
  ?>
  <div class="wcu-qs-success">
    <span class="wcu-qs-success__icon" aria-hidden="true"><span class="fa-solid fa-check"></span></span>
    <h1 class="wcu-setup-title"><?php echo esc_html__( 'You\'re all set!', 'woo-coupon-usage' ); ?></h1>
  </div>

  <?php
  if ( $user && $coupon_ids ) {
    $info      = wcusage_get_coupon_info_by_id( $coupon_ids[0] );
    $code      = isset( $info[3] ) ? $info[3] : '';
    $dashboard = isset( $info[4] ) ? $info[4] : '';
    $ref_link  = wcusage_get_setting_value( 'wcusage_field_urls_enable', '1' ) ? wcusage_get_affiliate_url( $code ) : '';
    $name      = trim( $user->first_name . ' ' . $user->last_name );
    if ( '' === $name ) {
      $name = $user->display_name ? $user->display_name : $user->user_login;
    }
    ?>
    <div class="wcu-qs-summary">
      <p class="wcu-qs-summary__intro"><?php echo esc_html( sprintf( __( '%s has been added. Here\'s what they can share:', 'woo-coupon-usage' ), $name ) ); ?></p>
      <dl>
        <div class="wcu-qs-summary__row">
          <dt><?php echo esc_html__( 'Coupon code', 'woo-coupon-usage' ); ?></dt>
          <dd><code><?php echo esc_html( $code ); ?></code></dd>
        </div>
        <?php if ( $ref_link ) { ?>
        <div class="wcu-qs-summary__row">
          <dt><?php echo esc_html__( 'Referral link', 'woo-coupon-usage' ); ?></dt>
          <dd><code><?php echo esc_html( $ref_link ); ?></code></dd>
        </div>
        <?php } ?>
        <?php if ( $dashboard ) { ?>
        <div class="wcu-qs-summary__row">
          <dt><?php echo esc_html__( 'Their dashboard', 'woo-coupon-usage' ); ?></dt>
          <dd><a href="<?php echo esc_url( $dashboard ); ?>" target="_blank"><?php echo esc_html__( 'View it as they will', 'woo-coupon-usage' ); ?> <i class="fa-solid fa-arrow-up-right-from-square"></i></a></dd>
        </div>
        <?php } ?>
      </dl>
    </div>
    <?php
  }
  ?>

  <p class="wcu-qs-subheading"><?php echo esc_html__( 'How it works from here', 'woo-coupon-usage' ); ?></p>
  <ul class="wcu-qs-list wcu-qs-list--steps">
    <li><?php echo esc_html__( 'When a customer uses an affiliate\'s coupon code, or arrives through their referral link, the order is credited to that affiliate automatically.', 'woo-coupon-usage' ); ?></li>
    <li><?php echo esc_html__( 'Affiliates log in to their dashboard to see their orders and commission earned.', 'woo-coupon-usage' ); ?></li>
    <li><?php echo esc_html__( 'You can see every affiliate\'s sales on the Coupon Affiliates dashboard, and pay them however you like.', 'woo-coupon-usage' ); ?></li>
  </ul>

  <div class="wcu-qs-video">
    <p class="wcu-qs-subheading"><?php echo esc_html__( 'Watch the quick walkthrough', 'woo-coupon-usage' ); ?></p>
    <?php echo wcusage_admin_vimeo_embed( 'https://player.vimeo.com/video/1237718510?badge=0&autopause=0&player_id=0&app_id=58479' ); ?>
  </div>

  <div class="wcu-qs-actions">
    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage' ) ); ?>"><?php echo esc_html__( 'Go to Dashboard', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
    <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_setup&step=quick-affiliate' ) ); ?>"><?php echo esc_html( sprintf( __( 'Add Another %s', 'woo-coupon-usage' ), $affiliate_text ) ); ?></a>
    <a class="wcu-qs-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_settings' ) ); ?>"><?php echo esc_html__( 'Explore all settings', 'woo-coupon-usage' ); ?></a>
  </div>
  <?php
}

/**
 * Settings page view: 'essentials' or 'all'.
 *
 * New stores that took the quick-start path start on Essentials. Everyone else,
 * including every existing install, sees all settings until they choose otherwise.
 *
 * @return string
 */
function wcusage_get_settings_view() {
  $view = get_user_meta( get_current_user_id(), 'wcusage_settings_view', true );
  if ( in_array( $view, array( 'essentials', 'all' ), true ) ) {
    return $view;
  }
  return 'quick' === wcusage_get_setup_mode() ? 'essentials' : 'all';
}

/**
 * Settings tabs shown in the Essentials view.
 *
 * @return array
 */
function wcusage_get_essential_settings_tabs() {
  return apply_filters( 'wcusage_essential_settings_tabs', array(
    'tab-general',
    'tab-commission',
    'tab-registration',
    'tab-notifications',
    'tab-design',
    'tab-help',
    'tab-pro-details',
  ) );
}

/**
 * Save the settings view choice (AJAX).
 */
add_action( 'wp_ajax_wcusage_save_settings_view', 'wcusage_save_settings_view_ajax' );
function wcusage_save_settings_view_ajax() {
  check_ajax_referer( 'wcusage_settings_view', 'nonce' );
  if ( ! wcusage_check_admin_access() ) {
    wp_send_json_error( array( 'message' => __( 'Not authorized.', 'woo-coupon-usage' ) ), 403 );
  }
  $view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : '';
  if ( ! in_array( $view, array( 'essentials', 'all' ), true ) ) {
    wp_send_json_error( array( 'message' => __( 'Invalid view.', 'woo-coupon-usage' ) ), 400 );
  }
  update_user_meta( get_current_user_id(), 'wcusage_settings_view', $view );
  wp_send_json_success( array( 'view' => $view ) );
}

/**
 * Output the Essentials / All settings switch, its styles and script.
 */
function wcusage_settings_view_toggle() {
  $view      = wcusage_get_settings_view();
  $essential = wcusage_get_essential_settings_tabs();

  // Every sidebar item that is not an essential tab is hidden in the Essentials view,
  // except the open tab when the view is switched to Essentials (.wcu-tab-revealed).
  // Jumping to a hidden tab switches the view to All settings (see the script below).
  $not = array();
  foreach ( $essential as $tab ) {
    $not[] = ':has(> #' . sanitize_html_class( $tab ) . ')';
  }
  $selector = '.wcu-settings-view-essentials .wcu-sidebar-menu-item:not(' . implode( '):not(', $not ) . '):not(.wcu-sidebar-menu-item--sales):not(.wcu-tab-revealed)';
  ?>
  <div class="wcu-settings-view" role="group" aria-label="<?php echo esc_attr__( 'Settings view', 'woo-coupon-usage' ); ?>">
    <button type="button" class="wcu-settings-view__btn<?php echo 'essentials' === $view ? ' is-active' : ''; ?>" data-view="essentials" aria-pressed="<?php echo 'essentials' === $view ? 'true' : 'false'; ?>">
      <span class="fa-solid fa-list-check" aria-hidden="true"></span>
      <?php echo esc_html__( 'Essentials', 'woo-coupon-usage' ); ?>
    </button>
    <button type="button" class="wcu-settings-view__btn<?php echo 'all' === $view ? ' is-active' : ''; ?>" data-view="all" aria-pressed="<?php echo 'all' === $view ? 'true' : 'false'; ?>">
      <span class="fa-solid fa-sliders" aria-hidden="true"></span>
      <?php echo esc_html__( 'All settings', 'woo-coupon-usage' ); ?>
    </button>
  </div>
  <style>
    /* Segmented control at the top of the settings tabs card. */
    .wcusage-settings .wcu-settings-view {
      display: grid; grid-template-columns: 1fr 1fr; gap: 3px;
      margin: 0 0 10px; padding: 3px;
      background: #f2f4f7; border: 1px solid #eaecf0; border-radius: 10px;
    }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 7px;
      min-height: 34px; margin: 0; padding: 6px 10px;
      border: 0; border-radius: 8px; background: transparent; box-shadow: none;
      color: #667085; font-size: 13px; font-weight: 500; line-height: 1.2; white-space: nowrap;
      cursor: pointer; transition: background .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn .fa-solid { font-size: 12px; color: #98a2b3; transition: color .15s ease; }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn:hover { color: #101828; }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn:hover .fa-solid { color: #667085; }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn.is-active {
      background: #fff; color: #101828; font-weight: 600;
      box-shadow: 0 1px 3px rgba(16, 24, 40, .1), 0 1px 2px rgba(16, 24, 40, .06);
    }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn.is-active .fa-solid { color: #1c7a38; }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn:focus { outline: none; }
    .wcusage-settings .wcu-settings-view .wcu-settings-view__btn:focus-visible { box-shadow: 0 0 0 3px rgba(28, 122, 56, .25); }
    @media screen and (max-width: 1200px) {
      .wcusage-settings .wcu-settings-view { max-width: 340px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .wcusage-settings .wcu-settings-view .wcu-settings-view__btn { transition: none; }
    }
    <?php echo $selector; // phpcs:ignore -- Built from sanitized class names. ?> { display: none; }
    /* "Show all settings (n more)" at the foot of the Essentials list. */
    .wcu-sidebar-show-all { display: none; }
    .wcu-settings-view-essentials .wcu-sidebar-menu .wcu-sidebar-show-all { display: block; flex-basis: 100%; margin: 6px 0 0; padding-top: 8px; border-top: 1px solid #eef0f3; }
    .wcusage-settings .wcu-sidebar-show-all a {
      display: flex; align-items: center; justify-content: center; gap: 6px;
      padding: 7px 10px; border-radius: 8px;
      color: #667085; font-size: 12.5px; font-weight: 500; text-decoration: none;
    }
    .wcusage-settings .wcu-sidebar-show-all a:hover { background: #f6f7f9; color: #1c7a38; }
    .wcusage-settings .wcu-sidebar-show-all a:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(28, 122, 56, .25); }
  </style>
  <script>
  jQuery(function($) {
    var $wrap = $('.wcusage-settings');
    var $menu = $('.wcu-sidebar-menu');
    var essential = <?php echo wp_json_encode( array_values( $essential ) ); ?>;

    // Tabs hidden by the Essentials view only: not ones whose module is switched off,
    // which stay hidden in "All settings" too.
    function hiddenCount() {
      return $menu.children('.wcu-sidebar-menu-item').filter(function() {
        var $link = $(this).children('.wcu-sidebar-link');
        return $(this).css('display') === 'none' && $link.length && $link[0].style.display !== 'none';
      }).length;
    }
    function refreshShowAll() {
      var n = hiddenCount();
      $menu.find('.wcu-sidebar-show-all .wcu-sidebar-show-all__text').text(<?php echo wp_json_encode( __( 'Show all settings (%d more)', 'woo-coupon-usage' ) ); ?>.replace('%d', n));
      $menu.find('.wcu-sidebar-show-all').toggle($wrap.hasClass('wcu-settings-view-essentials') && n > 0);
    }

    $menu.append('<li class="wcu-sidebar-show-all"><a href="#" data-view="all"><span class="wcu-sidebar-show-all__text"></span> <span class="fa-solid fa-chevron-down" aria-hidden="true"></span></a></li>');

    function setView(view, save) {
      $wrap.toggleClass('wcu-settings-view-essentials', view === 'essentials');
      $('.wcu-settings-view__btn').each(function() {
        var on = $(this).data('view') === view;
        $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
      });
      $('.wcu-tab-revealed').removeClass('wcu-tab-revealed');
      if (view === 'essentials') {
        // If the open tab is now hidden, keep it visible rather than leave a blank page.
        var $active = $('.wcu-sidebar-link.active').first();
        if ($active.length && essential.indexOf($active.attr('id')) === -1) {
          $active.closest('.wcu-sidebar-menu-item').addClass('wcu-tab-revealed');
        }
      }
      refreshShowAll();
      if (save) {
        $.post(ajaxurl, { action: 'wcusage_save_settings_view', view: view, nonce: <?php echo wp_json_encode( wp_create_nonce( 'wcusage_settings_view' ) ); ?> });
      }
    }

    $(document).on('click', '.wcu-settings-view__btn, .wcu-sidebar-show-all a', function(e) {
      e.preventDefault();
      setView($(this).data('view'), true);
    });

    // Hidden tabs can't be clicked, so a click on one in the Essentials view comes from
    // an in-page jump: "Go to settings" in the settings search, a "go to setting" link
    // (wcusage_go_to_settings) or a ?section= link. Switch to All settings so the tab,
    // and the setting being jumped to, show properly. The tab kept visible after
    // switching to Essentials (.wcu-tab-revealed) is the one exception.
    $(document).on('click', '.wcu-sidebar-link', function() {
      var $item = $(this).closest('.wcu-sidebar-menu-item');
      // PRO upsell tabs stay visible in Essentials, so clicking one is a normal click.
      if ($item.hasClass('wcu-sidebar-menu-item--sales')) {
        return;
      }
      if ($wrap.hasClass('wcu-settings-view-essentials') && essential.indexOf(this.id) === -1 && !$item.hasClass('wcu-tab-revealed')) {
        setView('all', true);
        return;
      }
      $('.wcu-tab-revealed').not($item).removeClass('wcu-tab-revealed');
      refreshShowAll();
    });

    setView(<?php echo wp_json_encode( $view ); ?>, false);

    // The module tab toggles (wcusage_js_settings_tab_toggle) hide switched-off tabs in
    // their own ready handlers, which run after this one, so count again once they have.
    setTimeout(refreshShowAll, 0);
    $(window).on('load', refreshShowAll);
  });
  </script>
  <?php
}

/**
 * Getting started checklist on the main dashboard, until the first affiliate exists.
 */
add_action( 'wcusage_hook_dashboard_getting_started', 'wcusage_dashboard_getting_started' );
function wcusage_dashboard_getting_started() {

  if ( ! wcusage_check_admin_access() ) {
    return;
  }
  if ( get_user_meta( get_current_user_id(), 'wcusage_getting_started_dismissed', true ) ) {
    return;
  }
  if ( wcusage_has_any_affiliate() ) {
    return;
  }

  $affiliate_text = strtolower( wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) ) );
  $has_dashboard  = wcusage_get_setting_value( 'wcusage_field_portal_enable', '0' ) || wcusage_get_coupon_shortcode_page( '0' );
  $has_commission = (float) wcusage_get_setting_value( 'wcusage_field_affiliate', '0' ) > 0
    || (float) wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_order', '0' ) > 0
    || (float) wcusage_get_setting_value( 'wcusage_field_affiliate_fixed_product', '0' ) > 0;
  $basics_done    = $has_dashboard && wcusage_template_coupon_exists();

  $add_url = $basics_done
    ? admin_url( 'admin.php?page=wcusage_setup&step=quick-affiliate' )
    : admin_url( 'admin.php?page=wcusage_setup&step=quick' );

  $items = array(
    array(
      'done'  => $has_dashboard,
      'label' => __( 'Create the affiliate dashboard page', 'woo-coupon-usage' ),
      'url'   => admin_url( 'admin.php?page=wcusage_setup&step=quick' ),
    ),
    array(
      'done'  => $has_commission,
      'label' => __( 'Choose how much commission affiliates earn', 'woo-coupon-usage' ),
      'url'   => admin_url( 'admin.php?page=wcusage_setup&step=quick' ),
    ),
    array(
      'done'  => false,
      'label' => sprintf( __( 'Add your first %s', 'woo-coupon-usage' ), $affiliate_text ),
      'url'   => $add_url,
    ),
    array(
      'done'  => false,
      'label' => __( 'They share their coupon code, and you see every sale it brings in here', 'woo-coupon-usage' ),
      'url'   => '',
    ),
  );
  $dismiss_url = wp_nonce_url( admin_url( 'admin.php?page=wcusage&wcusage_dismiss_getting_started=1' ), 'wcusage_dismiss_getting_started' );
  ?>
  <div class="wcusage-getting-started-box">
    <style>
      .wcusage-getting-started-box { position: relative; background: #fff; border: 1px solid #c5d9ed; border-left: 4px solid #2271b1; border-radius: 6px; padding: 16px 20px; margin: 0 0 30px; }
      .wcusage-getting-started-box h2 { margin: 0 0 4px; font-size: 18px; }
      .wcusage-getting-started-box > p { margin: 0 0 12px; color: #50575e; }
      .wcusage-getting-started-box ol { margin: 0 0 14px; padding: 0; list-style: none; }
      .wcusage-getting-started-box li { margin: 0 0 8px; font-size: 14px; }
      .wcusage-getting-started-box li .fa-solid { width: 18px; }
      .wcusage-getting-started-box li.is-done { color: #646970; text-decoration: line-through; }
      .wcusage-getting-started-box li.is-done .fa-solid { color: #40965d; }
      .wcusage-getting-started-box li:not(.is-done) .fa-solid { color: #8c8f94; }
      .wcusage-getting-started-dismiss { position: absolute; top: 12px; right: 14px; font-size: 12px; text-decoration: none; color: #646970; }
    </style>
    <a class="wcusage-getting-started-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'Dismiss', 'woo-coupon-usage' ); ?> &times;</a>
    <h2><i class="fa-solid fa-flag-checkered"></i> <?php echo esc_html__( 'Getting started', 'woo-coupon-usage' ); ?></h2>
    <p><?php echo esc_html__( 'A few minutes is all it takes to start rewarding the people who promote your store.', 'woo-coupon-usage' ); ?></p>
    <ol>
      <?php foreach ( $items as $item ) { ?>
      <li class="<?php echo $item['done'] ? 'is-done' : ''; ?>">
        <span class="fa-solid <?php echo $item['done'] ? 'fa-circle-check' : 'fa-circle'; ?>" aria-hidden="true"></span>
        <?php if ( $item['url'] && ! $item['done'] ) { ?>
          <a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
        <?php } else { ?>
          <?php echo esc_html( $item['label'] ); ?>
        <?php } ?>
      </li>
      <?php } ?>
    </ol>
    <a class="button button-primary" href="<?php echo esc_url( $add_url ); ?>"><?php echo esc_html( sprintf( __( 'Add your first %s', 'woo-coupon-usage' ), $affiliate_text ) ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
  </div>
  <?php
}
