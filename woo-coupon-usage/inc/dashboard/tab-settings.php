<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Displays the settings tab content on affiliate dashboard
 *
 * @param int $postid
 * @param int $couponuserid
 *
 * @return mixed
 *
 */
if ( !function_exists( 'wcusage_tab_settings' ) ) {
    function wcusage_tab_settings(  $postid, $couponuserid  ) {
        $options = get_option( 'wcusage_options' );
        $currentuserid = get_current_user_id();
        // Notifications
        $wcu_enable_notifications = get_post_meta( $postid, 'wcu_enable_notifications', true );
        if ( $wcu_enable_notifications == "" ) {
            $wcu_enable_notifications = true;
        }
        // Reports
        $wcusage_field_enable_reports = wcusage_get_setting_value( 'wcusage_field_enable_reports', 1 );
        $enable_reports_user_option = wcusage_get_setting_value( 'wcusage_field_enable_reports_user_option', 1 );
        $enable_reports_default = wcusage_get_setting_value( 'wcusage_field_enable_reports_default', 1 );
        if ( $enable_reports_user_option ) {
            $wcu_enable_reports = get_post_meta( $postid, 'wcu_enable_reports', true );
            if ( $wcu_enable_reports == "" ) {
                $wcu_enable_reports = $enable_reports_default;
            }
        }
        // Extra
        $wcu_notifications_extra = get_post_meta( $postid, 'wcu_notifications_extra', true );
        $wcusage_email_enable_extra = wcusage_get_setting_value( 'wcusage_field_email_enable_extra', 1 );
        if ( isset( $_POST['submitsettingsupdate'] ) ) {
            // email_notifications
            $post_wcu_email_notifications = sanitize_text_field( $_POST['wcu_enable_notifications'] );
            if ( $post_wcu_email_notifications == "" ) {
                $post_wcu_email_notifications = 0;
            }
            update_post_meta( $postid, 'wcu_enable_notifications', $post_wcu_email_notifications );
            $wcu_enable_notifications = get_post_meta( $postid, 'wcu_enable_notifications', true );
            // email_reports
            if ( $enable_reports_user_option ) {
                $post_wcu_email_reports = sanitize_text_field( $_POST['wcu_enable_reports'] );
                if ( $post_wcu_email_reports == "" ) {
                    $post_wcu_email_reports = 0;
                }
                update_post_meta( $postid, 'wcu_enable_reports', $post_wcu_email_reports );
                $wcu_enable_reports = get_post_meta( $postid, 'wcu_enable_reports', true );
            }
            // wcu_notifications_extra
            $post_wcu_notifications_extra = sanitize_text_field( $_POST['wcu_notifications_extra'] );
            update_post_meta( $postid, 'wcu_notifications_extra', $post_wcu_notifications_extra );
            $wcu_notifications_extra = get_post_meta( $postid, 'wcu_notifications_extra', true );
        }
        ?>

		<p class="wcu-tab-title settings-title" style="font-size: 22px; margin-bottom: 25px;"><?php 
        echo esc_html__( "Settings", "woo-coupon-usage" );
        ?>:</p>

		<p><strong><?php 
        echo esc_html__( "Email Notification Settings", "woo-coupon-usage" );
        ?></strong></p>

		<?php 
        if ( $couponuserid == $currentuserid || wcusage_check_admin_access() ) {
            ?>

			<form method="post" class="wcusage_settings_form">

				<p><input type="checkbox" id="wcu_enable_notifications" name="wcu_enable_notifications"
				value="1" style="max-width: 300px;" <?php 
            if ( $wcu_enable_notifications ) {
                ?>checked<?php 
            }
            ?>> <?php 
            echo esc_html__( "Enable Email Notifications", "woo-coupon-usage" );
            ?></p>

        <?php 
            ?>

				<?php 
            ?>

        <p>
          <button type="submit" id="wcu-email-settings-update-button" class="wcu-save-settings-button woocommerce-Button button" name="submitsettingsupdate"><?php 
            echo esc_html__( 'Save changes', 'woo-coupon-usage' );
            ?></button>
        </p>

			</form>

		<?php 
        } else {
            ?>

			<br/><p><?php 
            echo esc_html__( "Sorry, this coupon is not assigned to you.", "woo-coupon-usage" );
            ?></p>

		<?php 
        }
        ?>

	<?php 
    }

}
add_action(
    'wcusage_hook_tab_settings',
    'wcusage_tab_settings',
    10,
    2
);
/**
 * Gets settings tab for shortcode page
 *
 * @param int $postid
 * @param string $coupon_code
 * @param int $combined_commission
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_dashboard_tab_content_settings',
    'wcusage_dashboard_tab_content_settings',
    10,
    6
);
if ( !function_exists( 'wcusage_dashboard_tab_content_settings' ) ) {
    function wcusage_dashboard_tab_content_settings(
        $postid,
        $coupon_code,
        $combined_commission,
        $wcusage_page_load,
        $coupon_user_id,
        $other_affiliate = ''
    ) {
        if ( $other_affiliate ) {
            $coupon_user_id = $other_affiliate;
        }
        // *** GET SETTINGS *** /
        $options = get_option( 'wcusage_options' );
        $language = wcusage_get_language_code();
        $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', 1 );
        $wcusage_field_load_ajax_per_page = wcusage_get_setting_value( 'wcusage_field_load_ajax_per_page', 1 );
        if ( !$wcusage_field_load_ajax ) {
            $wcusage_field_load_ajax_per_page = 0;
        }
        $wcusage_show_tabs = wcusage_get_setting_value( 'wcusage_field_show_tabs', '1' );
        $wcusage_justcoupon = wcusage_get_setting_value( 'wcusage_field_justcoupon', '1' );
        $wcusage_show_tax = wcusage_get_setting_value( 'wcusage_field_show_tax', '0' );
        $wcusage_hide_all_time = wcusage_get_setting_value( 'wcusage_field_hide_all_time', '0' );
        $wcusage_urlprivate = wcusage_get_setting_value( 'wcusage_field_urlprivate', '1' );
        if ( wcusage_check_admin_access() ) {
            $wcusage_urlprivate = 0;
        }
        $ajaxerrormessage = wcusage_ajax_error();
        $currentuserid = get_current_user_id();
        // *** DISPLAY CONTENT *** //
        ?>

    <?php 
        if ( isset( $_POST['page-settings'] ) || isset( $_POST['ml-page-settings'] ) || $wcusage_page_load == false ) {
            ?>

      <?php 
            if ( isset( $_POST['page-settings'] ) || isset( $_POST['ml-page-settings'] ) ) {
                ?>
      <style>#wcu6, #ml-wcu4 { display: block; }</style>
      <?php 
            }
            ?>

      <?php 
            if ( !$other_affiliate ) {
                ?>
        <div id="wcu6" <?php 
                if ( $wcusage_show_tabs == '1' || $wcusage_show_tabs == '' ) {
                    ?>class="wcutabcontent"<?php 
                }
                ?>>
      <?php 
            } else {
                ?>
        <div id="ml-wcu4" <?php 
                if ( $wcusage_show_tabs == '1' || $wcusage_show_tabs == '' ) {
                    ?>class="ml_wcutabcontent"<?php 
                }
                ?>>
      <?php 
            }
            ?>

      <?php 
            if ( $coupon_user_id != $currentuserid && wcusage_check_admin_access() ) {
                //echo "<p style='margin: 5px 0 0 0; font-size: 12px;'>Admin notice: The 'settings' section is only visible to affiliate users assigned to the coupon. You are also able to see this because you are an administrator.</p>";
            }
            ?>

      <?php 
            if ( $coupon_user_id == $currentuserid || wcusage_check_admin_access() ) {
                ?>

        <?php 
                do_action( 'wcusage_hook_tab_settings', $postid, $coupon_user_id );
                ?>

        <?php 
                ?>

        <!-- Edit Account Fields -->
        <?php 
                $wcusage_field_show_settings_tab_account = wcusage_get_setting_value( 'wcusage_field_show_settings_tab_account', '1' );
                ?>
        <?php 
                if ( $wcusage_field_show_settings_tab_account ) {
                    ?>

          <p class="wcu-settings-header"><strong><?php 
                    echo esc_html__( 'Account Details', 'woo-coupon-usage' );
                    ?></strong></p>

          <?php 
                    if ( $currentuserid == $coupon_user_id ) {
                        ?>
            <?php 
                        echo do_shortcode( "[wcusage_customer_edit_account_html]" );
                        ?>
          <?php 
                    } else {
                        ?>
            <p><?php 
                        echo esc_html__( "Sorry, this coupon is not assigned to you. You can only edit your own account details.", "woo-coupon-usage" );
                        ?></p>
            <?php 
                        if ( wcusage_check_admin_access() ) {
                            echo "<p>" . sprintf( esc_html__( "[Admin] You can edit the account details for this user in the admin area: %s", "woo-coupon-usage" ) . "</p>", "<a href='" . get_edit_user_link( $coupon_user_id ) . "' target='_blank'>" . esc_html__( "Edit User", "woo-coupon-usage" ) . "</a></p>" );
                            echo "<br/>";
                            echo "<span class='admin-edit-account'>";
                            echo do_shortcode( "[wcusage_customer_edit_account_html user='" . $coupon_user_id . "']" );
                            echo "</span>";
                            echo "<style>\r\n              .admin-edit-account { opacity: 0.5; }\r\n              .admin-edit-account input[type='text'], .admin-edit-account input[type='email'], .admin-edit-account input[type='password'], .admin-edit-account label { pointer-events: none; }\r\n              .admin-edit-account button { display: none; pointer-events: none; }\r\n              </style>";
                        }
                        ?>
          <?php 
                    }
                    ?>

        <?php 
                }
                ?>

      <?php 
            } else {
                ?>

        <br/><p><?php 
                echo esc_html__( "Sorry, this coupon is not assigned to you.", "woo-coupon-usage" );
                ?></p>

      <?php 
            }
            ?>

      </div>
      <div style="width: 100%; clear: both; display: inline;"></div>

    <?php 
        }
        ?>

    <?php 
    }

}