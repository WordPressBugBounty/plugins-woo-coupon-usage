<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wcusage_hook_getting_started_create', 'wcusage_getting_started_create' );
function wcusage_getting_started_create() {

    if ( isset( $_POST['submitnewpage'] ) || isset( $_POST['submitnewpage2'] ) || isset( $_GET['create_new_dashboard'] ) ) {

    // Verify nonce for security
    if ( isset( $_POST['submitnewpage'] ) || isset( $_POST['submitnewpage2'] ) ) {
        if ( ! isset( $_POST['_wpnonce_getting_started'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_getting_started'] ) ), 'wcusage_getting_started_action' ) ) {
            return;
        }
    }
    if ( isset( $_GET['create_new_dashboard'] ) ) {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcusage_create_dashboard' ) ) {
            return;
        }
    }

    $current_user_id = get_current_user_id();

    global $wpdb;
		$table_name = $wpdb->prefix . 'posts';
		$wpdb->insert(
			$table_name,
			array(
        'post_title'     => 'Affiliate Dashboard',
        'post_type'      => 'page',
        'post_name'      => 'affiliates',
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
        'post_content'   => '[couponaffiliates]',
        'post_status'    => 'publish',
        'post_author'    => $current_user_id,
			)
		);
		$new_page_id = $wpdb->insert_id;

    if( isset( $_POST['submitnewpage'] ) ) {
      echo "<form>";

      echo "<strong style='font-size: 1.3em;'>Affiliate dashboard page created. (ID #" . esc_html($new_page_id) . ")</strong>";

  		echo "<br/><br/>- You can now view a full list of affiliate dashboard URL's for each coupon on the <a href='".esc_url(admin_url("admin.php?page=wcusage_coupons"))."' target='_blank'>coupons list</a> page.";

      echo "<br/><br/>- You can assign users to coupons by going to the <a href='".esc_url(admin_url("admin.php?page=wcusage_coupons"))."' target='_blank'>coupons list</a> page, edit a coupon, and go to the 'coupon affiliates' tab.";

      echo "<br/><br/>- Make sure to customise the plugin, and set your commission rates etc, in the <a href='".esc_url(get_admin_url())."admin.php?page=wcusage_settings'>plugin settings</a>.";

      echo "<br/><br/>If you need help with anything at all, please <a href='".esc_url(admin_url("admin.php?page=wcusage-contact"))."' target='_blank'>contact us</a> or check out our <a href='https://couponaffiliates.com/docs/setup-guide-free' target='_blank'>setup guide</a>";

      echo "<style>.wcusage-get-started { display: none; }</style>";

      echo "</form>";
    } else {

      echo "<p style='color: green;'><strong>Affiliate dashboard page created. (ID #" . esc_html($new_page_id) . ")</strong></p><br/>";

    }

    $option_group = get_option('wcusage_options');
    $option_group['wcusage_dashboard_page'] = $new_page_id;
    update_option( 'wcusage_options', $option_group );

    }

    // Remove GET variable from URL
    if(isset($_GET['create_new_dashboard'])) {
      $url = remove_query_arg( array( 'create_new_dashboard', '_wpnonce' ) );
      wp_safe_redirect($url);
      exit;
    }

}

add_action( 'wcusage_hook_getting_started_registration_post', 'wcusage_getting_started_registration' );
function wcusage_getting_started_registration() {

    $registration_shortcode_page = wcusage_get_registration_shortcode_page_id();
    if(!$registration_shortcode_page) {

      // Only act on the explicit "generate" request from the button below. This used to
      // fire on ANY ?action= value with no nonce and no capability check behind it, so
      // merely following a crafted link published a page and repointed the
      // wcusage_registration_page setting at it.
      $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
      $nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

      if ( 'generate' === $action && wcusage_check_admin_access() && wp_verify_nonce( $nonce, 'wcusage_generate_registration_page' ) ) {

        // Create the page through the WordPress API rather than writing to the posts
        // table directly. The direct insert supplied no post_date, so the row was stored
        // with a zero date - which MySQL/MariaDB running in strict mode (NO_ZERO_DATE)
        // rejects outright, meaning the page was silently never created on those
        // servers. It also skipped slug de-duplication, left the guid empty, and never
        // fired save_post - so wcusage_clear_shortcode_page_caches() did not run and the
        // cached "registration page" lookup kept returning the previous result.
        $new_page_id = wp_insert_post( array(
          'post_title'     => 'Affiliate Registration',
          'post_type'      => 'page',
          'post_name'      => 'affiliate-registration',
          'comment_status' => 'closed',
          'ping_status'    => 'closed',
          'post_content'   => '[couponaffiliates-register]',
          'post_status'    => 'publish',
          'post_author'    => get_current_user_id(),
        ), true );

        if ( is_wp_error( $new_page_id ) || ! $new_page_id ) {

          // Previously the new id was saved to the settings whether or not the insert
          // worked, which pointed wcusage_registration_page at post 0.
          echo "<p style='color: #b32d2e;'><strong>" . esc_html__( 'The registration page could not be created. Please create a page containing the [couponaffiliates-register] shortcode, then select it below.', 'woo-coupon-usage' ) . "</strong></p>";

        } else {

          echo "<p style='color: green;'><strong>" . sprintf( esc_html__( 'Registration form page created. (ID #%d)', 'woo-coupon-usage' ), (int) $new_page_id ) . "</strong></p>";

          if ( function_exists( 'wcusage_update_options_merge' ) ) {
            wcusage_update_options_merge( array( 'wcusage_registration_page' => $new_page_id ) );
          } else {
            $option_group = get_option('wcusage_options');
            if ( ! is_array( $option_group ) ) { $option_group = array(); }
            $option_group['wcusage_registration_page'] = $new_page_id;
            update_option( 'wcusage_options', $option_group );
          }

        }

      }

    }

}

add_action( 'wcusage_hook_getting_started', 'wcusage_getting_started' );
function wcusage_getting_started() {
?>
	<form method="post" class="wcusage-get-started" action="" style="margin-top: 35px;">
    <?php wp_nonce_field( 'wcusage_getting_started_action', '_wpnonce_getting_started' ); ?>

    <div style="height: 220px; width: 55%; display: inline-block;">
  		<strong style="font-size: 1.3em;">Getting Started</strong>
  		<p>
  		<?php echo esc_html__( "(1) Add the shortcode [couponaffiliates] to a frontend page (click the button below to generate automatically).", "woo-coupon-usage" ); ?>
  		</p>
  		<p>
  		<?php echo esc_html__( "(2) As an admin, you will see a full list of affiliate coupons and dashboard links on the", "woo-coupon-usage" ); ?> <a href="<?php echo esc_url(admin_url("admin.php?page=wcusage_coupons")); ?>" target="_blank"><?php echo esc_html__( "WooCommerce coupons list", "woo-coupon-usage" ); ?></a>.
      </p>
      <p>
      <?php echo esc_html__( "(3) You can assign/link users to coupons so they can access their coupons affiliate dashboard directly, without needing the unique link.", "woo-coupon-usage" ); ?> (<a href="https://couponaffiliates.com/docs/assign-users-to-coupons" target="_blank"><?php echo esc_html__( "Learn More", "woo-coupon-usage" ); ?></a>)
  		</p>
  		<p>
  		<?php echo esc_html__( "(4) Customise the settings on this page below, and view the 'How To Use' tab for more help on getting started.", "woo-coupon-usage" ); ?>
  		</p>
      <p>
  		<?php echo esc_html__( "(5) To view our step-by-step setup guide", "woo-coupon-usage" ); ?> <a href='https://couponaffiliates.com/docs/setup-guide-free' target='_blank'><?php echo esc_html__( "click here", "woo-coupon-usage" ); ?></a>.
  		</p>

      <p style="margin-bottom: -15px;">
      <button type="submit" name="submitnewpage" class="button button-secondary" style="display: inline-block;">
        <?php echo esc_html__( "Generate Dashboard Page", "woo-coupon-usage" ); ?> <span class="fa-solid fa-circle-arrow-right"></span>
      </button>
      </p>
      <br/>
      <div style="clear: both;"></div>

    </div>

	</form>
<?php
}

add_action( 'wcusage_hook_getting_started2', 'wcusage_getting_started2' );
function wcusage_getting_started2() {
?>

<h2 style="font-size: 20px; margin: 8px 0 35px -12px;"> <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_setup')); ?>" class="wcusage-settings-button">Start Setup Wizard <span class="fa-solid fa-circle-arrow-right"></span></a></h2>

	<form method="post" class="wcusage-get-started" action="">
    <?php wp_nonce_field( 'wcusage_getting_started_action', '_wpnonce_getting_started' ); ?>

    <p><strong>Quick Guide:</strong></p>

		<p>
		<?php echo esc_html__( "(1) Add the shortcode [couponaffiliates] to a frontend page (click the button below to generate automatically).", "woo-coupon-usage" ); ?>
		</p>

		<p>
		<?php echo esc_html__( "(2) As an admin, you will see a full list of dashboard URLs to share with affiliates on the", "woo-coupon-usage" ); ?> <a href="<?php echo esc_url(admin_url("admin.php?page=wcusage_coupons")); ?>" target="_blank"><?php echo esc_html__( "WooCommerce coupons list", "woo-coupon-usage" ); ?></a>.
    </p>

    <p>
    <?php echo esc_html__( "(3) You can assign/link users to coupons so they can access their coupons affiliate dashboard directly, without needing the unique link.", "woo-coupon-usage" ); ?> (<a href="https://couponaffiliates.com/docs/assign-users-to-coupons" target="_blank"><?php echo esc_html__( "Learn More", "woo-coupon-usage" ); ?></a>)
		</p>

		<p>
		<?php echo esc_html__( "(4) Customise the plugin on the", "woo-coupon-usage" ); ?> <a href="<?php echo esc_url(get_admin_url("admin.php?page=wcusage_settings")); ?>" target="_blank"><?php echo esc_html__( "settings page", "woo-coupon-usage" ); ?></a>.
		</p>

    <p>
		<?php echo esc_html__( "(5) To view our plugin setup guide", "woo-coupon-usage" ); ?> <a href="https://couponaffiliates.com/docs/setup-guide-free" target="_blank"><?php echo esc_html__( "click here", "woo-coupon-usage" ); ?></a>.
		</p>

		<button type="button" name="submitnewpage" class="button button-secondary">
      <?php echo esc_html__( "Generate Dashboard Page", "woo-coupon-usage" ); ?> <span class="fa-solid fa-circle-arrow-right"></span>
    </button>

		<br/><br/>

	</form>
<?php
}

add_action( 'wcusage_hook_getting_started3', 'wcusage_getting_started3' );
function wcusage_getting_started3() {
?>
	<form method="post" action="" style="margin-bottom: 10px;">
		<?php wp_nonce_field( 'wcusage_getting_started_action', '_wpnonce_getting_started' ); ?>
		<button type="submit" name="submitnewpage2" class="submit-generate-page"><?php echo esc_html__( "Generate Dashboard Page", "woo-coupon-usage" ); ?> <span class="fa-solid fa-arrow-right"></span></button>
	</form>
<?php
}

add_action( 'wcusage_hook_getting_started_registration', 'wcusage_getting_started_registration_post' );
function wcusage_getting_started_registration_post() {
?>
  <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=wcusage_setup&step=2&action=generate'), 'wcusage_generate_registration_page' ) ); ?>">
      <button type="button" class="submit-generate-page"><?php echo esc_html__( "Generate Registration Page", "woo-coupon-usage" ); ?> <span class="fa-solid fa-arrow-right"></span></button>
  </a>
  <br/>
<?php
}

add_action( 'wcusage_hook_checklist', 'wcusage_hook_checklist_function' );
function wcusage_hook_checklist_function() {
?>
  <div class="wcusage-checklist" style="display: none; border: 1px solid #ccc;
  min-width: 400px; padding: 5px 20px 10px 20px; background: #f9f9f9; border-radius: 5px; margin: 20px 0 5px 0;">
      <h2 style="font-size: 20px; margin-top: 15px;"><?php echo esc_html__( "Setup Checklist", "woo-coupon-usage" ); ?></h2>
      <?php
      $show = false;

      // 1 - Create a dashboard page
      $wcusage_dashboard_page = wcusage_get_setting_value('wcusage_dashboard_page', '');
      $wcusage_dashboard_page = get_post($wcusage_dashboard_page);
      if($wcusage_dashboard_page && $wcusage_dashboard_page->post_status == 'publish') {
        $wcusage_dashboard_page = $wcusage_dashboard_page->ID;
      } else {
        $wcusage_dashboard_page = false;
        $options_group = get_option('wcusage_options');
        if(isset($options_group['wcusage_dashboard_page'])) {
          $options_group['wcusage_dashboard_page'] = '';
          update_option( 'wcusage_options', $options_group );
        }
      }
      $wcusage_field_portal_enable = wcusage_get_setting_value('wcusage_field_portal_enable', '0');
      if(!$wcusage_dashboard_page && !$wcusage_field_portal_enable) {
        echo "<p style='margin-top: 20px;'>";
        echo "<span class='fa-solid fa-exclamation-circle' style='color: red;'></span> ";
        echo "<strong>" . esc_html__( "Create the affiliate dashboard page:", "woo-coupon-usage" ) . "</strong>";
        echo sprintf( " <a href='%s'>%s</a>", esc_url(admin_url('admin.php?page=wcusage_setup&step=1')), esc_html__( "Click here", "woo-coupon-usage" ) );
        echo "</p>";
        $show = true;
      } else {
        echo "<p style='margin-top: 20px;'>";
        echo "<span class='fa-solid fa-check-circle' style='color: green;'></span> ";
        echo "<strong>" . esc_html__( "Affiliate dashboard page created.", "woo-coupon-usage" ) . "</strong>";
        echo "</p>";
      }

      $wcusage_field_registration_enable = wcusage_get_setting_value('wcusage_field_registration_enable', '1');

      if($wcusage_field_registration_enable) {

        // 2 - Create a registration page
        $wcusage_registration_page = wcusage_get_setting_value('wcusage_registration_page', '');
        if(!$wcusage_registration_page) {
          echo "<p style='margin-top: 20px;' class='wcusage-checklist-registration'>";
          echo "<span class='fa-solid fa-exclamation-circle' style='color: red;'></span> ";
          echo "<strong>" . esc_html__( "Create the affiliate registration page:", "woo-coupon-usage" ) . "</strong>";
          echo sprintf( " <a href='%s'>%s</a>", esc_url(admin_url('admin.php?page=wcusage_setup&step=2')), esc_html__( "Click here", "woo-coupon-usage" ) );
          echo "</p>";
          $show = true;
        } else {
          echo "<p style='margin-top: 20px;' class='wcusage-checklist-registration'>";
          echo "<span class='fa-solid fa-check-circle' style='color: green;'></span> ";
          echo "<strong>" . esc_html__( "Affiliate registration page created.", "woo-coupon-usage" ) . "</strong>";
          echo "</p>";
        }

        // 3 - Create template coupon
        $coupon_template = wcusage_get_setting_value('wcusage_field_registration_coupon_template', '0');
        $couponid = wcusage_get_coupon_id($coupon_template);
        if(!$couponid) {
          echo "<p style='margin-top: 20px;'>";
          echo "<span class='fa-solid fa-exclamation-circle' style='color: red;'></span> ";
          echo "<strong>" . esc_html__( "Create the template coupon:", "woo-coupon-usage" ) . "</strong>";
          echo sprintf( " <a href='%s'>%s</a>", esc_url(admin_url('admin.php?page=wcusage_setup&step=2')), esc_html__( "Click here", "woo-coupon-usage" ) );
          echo "</p>";
          $show = true;
        } else {
          echo "<p style='margin-top: 20px;'>";
          echo "<span class='fa-solid fa-check-circle' style='color: green;'></span> ";
          echo "<strong>" . esc_html__( "Template coupon created.", "woo-coupon-usage" ) . "</strong>";
          echo "</p>";
        }

      }

      // Show?
      if($show) {
        echo "<style>.wcusage-checklist { display: inline-block !important; }</style>";
      }
      ?>
  </div>
<?php
}