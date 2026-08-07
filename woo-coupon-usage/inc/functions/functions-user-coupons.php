<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Adds "coupon_affiliate" Custom User Role
 */
if ( !function_exists( 'wcusage_update_custom_roles' ) ) {
    function wcusage_update_custom_roles() {
        if ( get_option( 'wcusage_custom_roles_version' ) < 1 ) {
            add_role( 'coupon_affiliate', 'Coupon Affiliate', array(
                'read'    => true,
                'level_0' => true,
            ) );
            update_option( 'wcusage_custom_roles_version', 1 );
        }
    }

}
add_action( 'init', 'wcusage_update_custom_roles' );
/**
 * Enqueue CSS/JS for the WooCommerce coupon edit screen (Coupon Affiliates data
 * panel + side meta box). Replaces the previously inline <style>/<script> blocks
 * in add_wcusage_coupon_data_fields() and wcusage_coupon_meta_box_markup().
 */
if ( !function_exists( 'wcusage_enqueue_coupon_edit_assets' ) ) {
    function wcusage_enqueue_coupon_edit_assets(  $hook  ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( !$screen || 'shop_coupon' !== $screen->post_type ) {
            return;
        }
        $ver = ( defined( 'WCUSAGE_VERSION' ) ? WCUSAGE_VERSION : '1.0.0' );
        // jQuery UI Autocomplete + its base stylesheet (used by the affiliate-user fields).
        wp_enqueue_script( 'jquery-ui-autocomplete' );
        wp_enqueue_style(
            'wcusage-jquery-ui',
            'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css',
            array(),
            '1.12.1'
        );
        wp_enqueue_style(
            'wcusage-coupon-edit',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-coupon-edit.css',
            array(),
            $ver
        );
        wp_enqueue_script(
            'wcusage-coupon-edit',
            WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-coupon-edit.js',
            array('jquery', 'jquery-ui-autocomplete'),
            $ver,
            true
        );
        wp_localize_script( 'wcusage-coupon-edit', 'wcusage_coupon_edit_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcusage_coupon_nonce' ),
        ) );
    }

}
add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_coupon_edit_assets' );
/**
 * Add custom settings to coupons
 */
if ( !function_exists( 'add_wcusage_coupon_data_fields' ) ) {
    function add_wcusage_coupon_data_fields(  $coupon_get_id  ) {
        echo '<div id="wcusage_coupon_data" class="panel woocommerce_options_panel">';
        $options = get_option( 'wcusage_options' );
        $wcusage_lifetime = wcusage_get_setting_value( 'wcusage_field_lifetime', '0' );
        $wcusage_field_lifetime_all = wcusage_get_setting_value( 'wcusage_field_lifetime_all', '0' );
        $post_id = ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : '' );
        $getcurrentcouponuser = ( $post_id ? get_post_meta( $post_id, 'wcu_select_coupon_user', true ) : '' );
        $currentselecteduserlogin = '';
        // Convert stored user ID to username for display
        if ( is_numeric( $getcurrentcouponuser ) && $getcurrentcouponuser ) {
            $user = get_user_by( 'id', $getcurrentcouponuser );
            $currentselecteduserlogin = ( $user ? $user->user_login : '' );
        } elseif ( $getcurrentcouponuser && is_string( $getcurrentcouponuser ) ) {
            // If it's a username (legacy data), use it directly but update to ID
            $currentselecteduserlogin = $getcurrentcouponuser;
            $user = get_user_by( 'login', $getcurrentcouponuser );
            if ( $user ) {
                update_post_meta( $post_id, 'wcu_select_coupon_user', $user->ID );
            }
        }
        ?>

        <br/><span style="display: inline-block; padding-left: 10px;"><?php 
        echo esc_html__( 'General Settings:', 'woo-coupon-usage' );
        ?></span><br/>

        <p class="form-field wcu_select_coupon_user_field">
            <label for="wcu_select_coupon_user"><?php 
        echo esc_html__( 'Affiliate User', 'woo-coupon-usage' );
        ?></label>
            <input type="text" id="wcu_select_coupon_user" name="wcu_select_coupon_user" value="<?php 
        echo esc_attr( $currentselecteduserlogin );
        ?>" class="regular-text" />
            <span class="description"><?php 
        echo esc_html__( 'Type any username. Suggestions will appear as you type, but you can keep your own input.', 'woo-coupon-usage' );
        ?></span>
        </p>

        <?php 
        if ( wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code() ) {
            ?>
            <hr/><br/><span style="display: inline-block; padding-left: 10px;"><?php 
            echo esc_html__( 'Custom Commission:', 'woo-coupon-usage' );
            ?></span><br/>
            <p><?php 
            echo sprintf( wp_kses_post( __( 'Custom commission amounts can be set for each coupon, or you can set the global commission rates for all coupons in the <a href="%s">plugin settings</a> page.', 'woo-coupon-usage' ) ), esc_url( admin_url( 'admin.php?page=wcusage_settings' ) ) );
            ?></p>

            <?php 
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_text_coupon_commission',
                'label'       => esc_html__( 'Commission %', 'woo-coupon-usage' ),
                'description' => esc_html__( 'Optional: Custom commission "percentage of total order" for this coupon.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_text_coupon_commission_fixed_order',
                'label'       => sprintf( esc_html__( 'Commission %s - Order', 'woo-coupon-usage' ), wcusage_get_currency_symbol() ),
                'description' => esc_html__( 'Optional: Custom commission "fixed amount per order" for this coupon.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_text_coupon_commission_fixed_product',
                'label'       => sprintf( esc_html__( 'Commission %s - Product', 'woo-coupon-usage' ), wcusage_get_currency_symbol() ),
                'description' => esc_html__( 'Optional: Custom commission "fixed amount per product" for this coupon.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_text_coupon_commission_message',
                'label'       => esc_html__( 'Custom Commission Message', 'woo-coupon-usage' ),
                'description' => esc_html__( 'Custom "Commission" message on coupon affiliate dashboard.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
            ?>
        <?php 
        }
        ?>

        <?php 
        woocommerce_wp_text_input( array(
            'type'        => 'date',
            'id'          => 'wcu_text_coupon_start_date',
            'label'       => esc_html__( 'Coupon History Start Date', 'woo-coupon-usage' ),
            'description' => '<i>' . wp_kses_post( esc_html__( 'Custom date to begin displaying past coupon data. Leave empty to show full history.', 'woo-coupon-usage' ) ) . '</i>',
            'desc_tip'    => false,
        ) );
        echo "<br/><hr/><br/><span style='display: inline-block; padding-left: 10px;'>" . esc_html__( 'Email Notifications:', 'woo-coupon-usage' ) . "</span><br/>";
        $wcu_enable_notifications = get_post_meta( $coupon_get_id, 'wcu_enable_notifications', true );
        woocommerce_wp_select( array(
            'id'      => 'wcu_enable_notifications',
            'label'   => esc_html__( 'Enable affiliate email notifications.', 'woo-coupon-usage' ),
            'options' => array(
                '1' => esc_html__( 'Enabled', 'woo-coupon-usage' ),
                '0' => esc_html__( 'Disabled', 'woo-coupon-usage' ),
            ),
        ) );
        if ( wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code() ) {
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_notifications_extra',
                'label'       => esc_html__( 'Additional Email Addresses', 'woo-coupon-usage' ),
                'placeholder' => 'example@email.com,another@email.com',
                'description' => esc_html__( 'Additional email addresses to send the affiliate email notifications. Separate each email with a comma.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
        }
        if ( wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code() && $wcusage_lifetime && !$wcusage_field_lifetime_all ) {
            echo "<hr/><br/>";
            woocommerce_wp_select( array(
                'id'          => 'wcu_enable_lifetime_commission',
                'label'       => esc_html__( 'Lifetime Commission:', 'woo-coupon-usage' ),
                'description' => esc_html__( 'Enable lifetime commission for this coupon.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
                'options'     => array(
                    '0' => esc_html__( 'Disabled', 'woo-coupon-usage' ),
                    '1' => esc_html__( 'Enabled', 'woo-coupon-usage' ),
                ),
            ) );
            woocommerce_wp_text_input( array(
                'id'          => 'wcu_lifetime_commission_expire',
                'label'       => esc_html__( 'Lifetime Commission Expiry', 'woo-coupon-usage' ),
                'placeholder' => '',
                'description' => esc_html__( 'How many days after being assigned as a "lifetime" referral should it expire, and the customer be unlinked from the customer. Leave empty to use the default global setting. Set to "0" for permanent lifetime commission with no expiry time.', 'woo-coupon-usage' ),
                'desc_tip'    => true,
            ) );
            if ( $wcusage_field_lifetime_all ) {
                echo "<br/><hr/><br/><span style='display: inline-block; padding-left: 10px;'><span class='dashicons dashicons-yes-alt'></span> " . esc_html__( 'Lifetime commission enabled globally.', 'woo-coupon-usage' ) . "</span><br/>";
            }
        }
        echo "<br/><hr/><br/>";
        echo "<p>" . sprintf( wp_kses_post( __( 'You can set the global commission rates for all coupons in the <a href="%s">plugin settings</a> page.', 'woo-coupon-usage' ) ), esc_url( admin_url( "admin.php?page=wcusage_settings" ) ) ) . "</p>";
        if ( !wcu_fs()->can_use_premium_code() ) {
            $wcu_pro_promo_url = "https://woocouponusage.com/pricing/?utm_source=plugin&utm_medium=admin&utm_campaign=pro_coupon_page";
            $wcu_pro_promo_features = array(
                array('dashicons-chart-line', __( 'Custom Commission Rate', 'woo-coupon-usage' ), __( 'Set a unique commission percentage for this specific coupon, overriding your global rate.', 'woo-coupon-usage' )),
                array('dashicons-cart', __( 'Fixed Commission Amounts', 'woo-coupon-usage' ), __( 'Pay a flat commission amount per order or per product for this coupon.', 'woo-coupon-usage' )),
                array('dashicons-testimonial', __( 'Custom Commission Message', 'woo-coupon-usage' ), __( 'Show a personalised commission message to the affiliate on their dashboard.', 'woo-coupon-usage' )),
                array('dashicons-update', __( 'Lifetime Commission', 'woo-coupon-usage' ), __( 'Keep earning the affiliate commission on every future order from the customers they refer.', 'woo-coupon-usage' )),
                array('dashicons-email-alt', __( 'Extra Email Recipients', 'woo-coupon-usage' ), __( "Send this coupon's affiliate email notifications to additional email addresses.", 'woo-coupon-usage' )),
                array('dashicons-edit', __( 'Manual Commission Adjustments', 'woo-coupon-usage' ), __( 'Directly edit the unpaid, pending and processing commission totals for this coupon.', 'woo-coupon-usage' ))
            );
            ?>

            <div class="wcu-coupon-pro-promo">
                <div class="wcu-coupon-pro-promo-head">
                    <span class="wcu-coupon-pro-promo-badge"><span class="dashicons dashicons-star-filled"></span> <?php 
            echo esc_html__( 'Coupon Affiliates: PRO Features', 'woo-coupon-usage' );
            ?></span>
                    <h2 class="wcu-coupon-pro-promo-title"><?php 
            echo esc_html__( 'More customisation options for every affiliate coupon', 'woo-coupon-usage' );
            ?></h2>
                    <p class="wcu-coupon-pro-promo-sub"><?php 
            echo esc_html__( 'Upgrade to PRO to unlock powerful per-coupon settings that give you full control over commissions, notifications and rewards.', 'woo-coupon-usage' );
            ?></p>
                    <a class="wcu-coupon-pro-promo-btn" href="<?php 
            echo esc_url( $wcu_pro_promo_url );
            ?>">
                        <?php 
            echo esc_html__( 'Upgrade to PRO', 'woo-coupon-usage' );
            ?> <span class="dashicons dashicons-arrow-right-alt"></span>
                    </a>
                    <p class="wcu-coupon-pro-promo-note"><?php 
            echo esc_html__( 'Includes a free trial. Cancel anytime.', 'woo-coupon-usage' );
            ?></p>
                </div>

                <div class="wcu-coupon-pro-promo-grid">
                    <?php 
            foreach ( $wcu_pro_promo_features as $wcu_pro_feature ) {
                list( $wcu_f_icon, $wcu_f_title, $wcu_f_desc ) = $wcu_pro_feature;
                ?>
                        <div class="wcu-coupon-pro-card">
                            <span class="wcu-coupon-pro-card-icon"><span class="dashicons <?php 
                echo esc_attr( $wcu_f_icon );
                ?>"></span></span>
                            <strong class="wcu-coupon-pro-card-title"><?php 
                echo esc_html( $wcu_f_title );
                ?></strong>
                            <p class="wcu-coupon-pro-card-desc"><?php 
                echo esc_html( $wcu_f_desc );
                ?></p>
                        </div>
                    <?php 
            }
            ?>
                </div>
            </div>

            <?php 
        }
        ?>        

        </div>
        <?php 
    }

}
add_action( 'woocommerce_coupon_data_panels', 'add_wcusage_coupon_data_fields', 1 );
if ( !function_exists( 'add_wcusage_coupon_data_fields_limits' ) ) {
    function add_wcusage_coupon_data_fields_limits(  $coupon_get_id  ) {
        $allow_all_customers = wcusage_get_setting_value( 'wcusage_field_allow_all_customers', '1' );
        ?>

        <br/><span style="display: inline-block; padding-left: 10px;"><?php 
        echo esc_html__( 'Coupon Affiliates - Extra Limits:', 'woo-coupon-usage' );
        ?></span><br/>

        <?php 
        $wcu_enable_first_order_only = get_post_meta( $coupon_get_id, 'wcu_enable_first_order_only', true );
        woocommerce_wp_checkbox( array(
            'id'          => 'wcu_enable_first_order_only_' . wp_rand( 1, 9999 ),
            'name'        => 'wcu_enable_first_order_only',
            'class'       => 'wcu_enable_first_order_only',
            'value'       => $wcu_enable_first_order_only,
            'label'       => esc_html__( 'New customers only?', 'woo-coupon-usage' ),
            'description' => esc_html__( 'When checked, this coupon can only be used by new customers on their first order.', 'woo-coupon-usage' ),
        ) );
        ?>
        
        <?php 
    }

}
add_action( 'woocommerce_coupon_options_usage_limit', 'add_wcusage_coupon_data_fields_limits', 1 );
/**
 * Save Coupon Settings on Save
 */
if ( !function_exists( 'wcusage_save_coupon_settings' ) ) {
    function wcusage_save_coupon_settings(  $post_id  ) {
        // Commission values changed here are genuine manual admin edits, so allow
        // the activity log to record them (see wcusage_after_update_function).
        if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
            wcusage_set_manual_commission_edit( true );
        }
        $wcu_select_coupon_user = ( isset( $_POST['wcu_select_coupon_user'] ) ? sanitize_text_field( $_POST['wcu_select_coupon_user'] ) : '' );
        // Convert username to user ID
        $user = get_user_by( 'login', $wcu_select_coupon_user );
        $user_id = ( $user ? $user->ID : '' );
        // Store the user ID
        update_post_meta( $post_id, 'wcu_select_coupon_user', $user_id );
        if ( isset( $_POST['wcu_text_coupon_start_date'] ) ) {
            $wcu_text_coupon_start_date = sanitize_text_field( $_POST['wcu_text_coupon_start_date'] );
            // Force a statistics refresh (same as changing the commission rates)
            // when the history start date changes, since it changes which orders
            // are included in the coupon's statistics.
            $wcu_previous_start_date = get_post_meta( $post_id, 'wcu_text_coupon_start_date', true );
            if ( $wcu_previous_start_date != $wcu_text_coupon_start_date ) {
                delete_post_meta( $post_id, 'wcu_last_refreshed' );
            }
            update_post_meta( $post_id, 'wcu_text_coupon_start_date', $wcu_text_coupon_start_date );
        }
        $first_order_only = ( isset( $_POST['wcu_enable_first_order_only'] ) ? 'yes' : 'no' );
        update_post_meta( $post_id, 'wcu_enable_first_order_only', $first_order_only );
        if ( wcu_fs()->is__premium_only() && wcu_fs()->can_use_premium_code() ) {
            if ( isset( $_POST ) ) {
                $wcu_text_coupon_commission = get_post_meta( $post_id, 'wcu_text_coupon_commission', true );
                $wcu_text_coupon_commission_fixed_order = get_post_meta( $post_id, 'wcu_text_coupon_commission_fixed_order', true );
                $wcu_text_coupon_commission_fixed_product = get_post_meta( $post_id, 'wcu_text_coupon_commission_fixed_product', true );
                if ( $wcu_text_coupon_commission != $_POST['wcu_text_coupon_commission'] || $wcu_text_coupon_commission_fixed_order != $_POST['wcu_text_coupon_commission_fixed_order'] || $wcu_text_coupon_commission_fixed_product != $_POST['wcu_text_coupon_commission_fixed_product'] ) {
                    delete_post_meta( $post_id, 'wcu_last_refreshed' );
                }
                if ( isset( $_POST['wcu_text_coupon_commission'] ) ) {
                    $wcu_text_coupon_commission = sanitize_text_field( $_POST['wcu_text_coupon_commission'] );
                    update_post_meta( $post_id, 'wcu_text_coupon_commission', $wcu_text_coupon_commission );
                }
                if ( isset( $_POST['wcu_text_coupon_commission_fixed_order'] ) ) {
                    $wcu_text_coupon_commission_fixed_order = sanitize_text_field( $_POST['wcu_text_coupon_commission_fixed_order'] );
                    update_post_meta( $post_id, 'wcu_text_coupon_commission_fixed_order', $wcu_text_coupon_commission_fixed_order );
                }
                if ( isset( $_POST['wcu_text_coupon_commission_fixed_product'] ) ) {
                    $wcu_text_coupon_commission_fixed_product = sanitize_text_field( $_POST['wcu_text_coupon_commission_fixed_product'] );
                    update_post_meta( $post_id, 'wcu_text_coupon_commission_fixed_product', $wcu_text_coupon_commission_fixed_product );
                }
                if ( isset( $_POST['wcu_text_coupon_commission_message'] ) ) {
                    $wcu_text_coupon_commission_message = sanitize_text_field( $_POST['wcu_text_coupon_commission_message'] );
                    update_post_meta( $post_id, 'wcu_text_coupon_commission_message', $wcu_text_coupon_commission_message );
                }
                if ( isset( $_POST['wcu_enable_lifetime_commission'] ) ) {
                    $wcu_enable_lifetime_commission = sanitize_text_field( $_POST['wcu_enable_lifetime_commission'] );
                    update_post_meta( $post_id, 'wcu_enable_lifetime_commission', $wcu_enable_lifetime_commission );
                }
                if ( isset( $_POST['wcu_lifetime_commission_expire'] ) ) {
                    $wcu_lifetime_commission_expire = sanitize_text_field( $_POST['wcu_lifetime_commission_expire'] );
                    update_post_meta( $post_id, 'wcu_lifetime_commission_expire', $wcu_lifetime_commission_expire );
                }
                if ( isset( $_POST['wcu_enable_notifications'] ) ) {
                    $wcu_enable_notifications = sanitize_text_field( $_POST['wcu_enable_notifications'] );
                    update_post_meta( $post_id, 'wcu_enable_notifications', $wcu_enable_notifications );
                }
                if ( isset( $_POST['wcu_notifications_extra'] ) ) {
                    $wcu_notifications_extra = sanitize_text_field( $_POST['wcu_notifications_extra'] );
                    update_post_meta( $post_id, 'wcu_notifications_extra', $wcu_notifications_extra );
                }
                if ( isset( $_POST['wcu_text_unpaid_commission_confirm'] ) ) {
                    $wcu_text_unpaid_commission_confirm = sanitize_text_field( $_POST['wcu_text_unpaid_commission_confirm'] );
                    if ( $wcu_text_unpaid_commission_confirm ) {
                        if ( isset( $_POST['wcu_text_unpaid_commission'] ) ) {
                            $wcu_text_unpaid_commission = floatval( wp_unslash( $_POST['wcu_text_unpaid_commission'] ) );
                            update_post_meta( $post_id, 'wcu_text_unpaid_commission', $wcu_text_unpaid_commission );
                        }
                        if ( isset( $_POST['wcu_text_pending_payment_commission'] ) ) {
                            $wcu_text_pending_payment_commission = floatval( wp_unslash( $_POST['wcu_text_pending_payment_commission'] ) );
                            update_post_meta( $post_id, 'wcu_text_pending_payment_commission', $wcu_text_pending_payment_commission );
                        }
                        if ( isset( $_POST['wcu_text_pending_order_commission'] ) ) {
                            $wcu_text_pending_order_commission = floatval( wp_unslash( $_POST['wcu_text_pending_order_commission'] ) );
                            update_post_meta( $post_id, 'wcu_text_pending_order_commission', $wcu_text_pending_order_commission );
                        }
                        update_post_meta( $post_id, 'wcu_text_unpaid_commission_confirm', 0 );
                    }
                }
            }
        }
    }

}
add_action( 'woocommerce_coupon_options_save', 'wcusage_save_coupon_settings' );
// Reset the manual-edit flag once the coupon save has fully completed, so it
// never leaks onto any automated commission writes later in the same request.
add_action( 'woocommerce_coupon_options_save', function () {
    if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
        wcusage_set_manual_commission_edit( false );
    }
}, 9999 );
/**
 * Checks if coupon is users
 */
if ( !function_exists( 'wcusage_iscouponusers' ) ) {
    function wcusage_iscouponusers(  $coupon, $current_user_id  ) {
        if ( !$current_user_id ) {
            return false;
        }
        // Check cache first (namespaced under 'user' so any coupon assignment
        // change invalidates it — this caches negative results too, so a stale
        // entry would block a newly-assigned affiliate or let a removed one in)
        $cache_key = wcusage_cache_key( 'user', 'wcusage_is_coupon_users_' . md5( $coupon . '_' . $current_user_id ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (bool) $cached;
        }
        // Get the coupon by name
        $coupon_obj = new WC_Coupon($coupon);
        if ( !$coupon_obj->get_id() ) {
            set_transient( $cache_key, 0, HOUR_IN_SECONDS );
            return false;
        }
        // Check if this specific coupon is assigned to the user
        $assigned_user_id = get_post_meta( $coupon_obj->get_id(), 'wcu_select_coupon_user', true );
        $is_users = $assigned_user_id && $assigned_user_id == $current_user_id;
        // Cache the result
        set_transient( $cache_key, ( $is_users ? 1 : 0 ), HOUR_IN_SECONDS );
        return $is_users;
    }

}
/**
 * Checks if user id is an affiliate (assigned to at least 1 coupon)
 */
if ( !function_exists( 'wcusage_is_user_affiliate' ) ) {
    function wcusage_is_user_affiliate(  $user_id  ) {
        if ( !$user_id ) {
            return false;
        }
        // Check transient cache first
        $cache_key = wcusage_cache_key( 'user', 'wcusage_is_affiliate_' . $user_id );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (bool) $cached;
        }
        // Only need to find 1 coupon to confirm affiliate status
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(array(
                'key'     => 'wcu_select_coupon_user',
                'value'   => $user_id,
                'compare' => '=',
            )),
        );
        $query = new WP_Query($args);
        $is_affiliate = $query->post_count > 0;
        // Cache for 1 hour
        set_transient( $cache_key, ( $is_affiliate ? 1 : 0 ), HOUR_IN_SECONDS );
        wp_reset_postdata();
        return $is_affiliate;
    }

}
/**
 * Get IDs of all coupons assigned to user
 */
if ( !function_exists( 'wcusage_get_users_coupons_ids' ) ) {
    function wcusage_get_users_coupons_ids(  $user_id  ) {
        if ( !$user_id ) {
            return array();
        }
        // Check transient cache first
        $cache_key = wcusage_cache_key( 'user', 'wcusage_user_coupon_ids_' . $user_id );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return ( is_array( $cached ) ? $cached : array() );
        }
        // Use 'fields' => 'ids' for efficiency - only returns IDs, not full post objects
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(array(
                'key'     => 'wcu_select_coupon_user',
                'value'   => $user_id,
                'compare' => '=',
            )),
        );
        $post_ids = get_posts( $args );
        // Cache for 1 hour
        set_transient( $cache_key, $post_ids, HOUR_IN_SECONDS );
        return ( is_array( $post_ids ) ? $post_ids : array() );
    }

}
/**
 * Get IDs of all coupons assigned to user by name
 */
if ( !function_exists( 'wcusage_get_users_coupons_names' ) ) {
    function wcusage_get_users_coupons_names(  $user_id  ) {
        if ( !$user_id ) {
            return array();
        }
        // Check cache first
        $cache_key = wcusage_cache_key( 'user', 'wcusage_user_coupon_names_' . $user_id );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
        // Get coupon IDs efficiently (reuse existing optimized function)
        $coupon_ids = wcusage_get_users_coupons_ids( $user_id );
        $coupons = array();
        foreach ( $coupon_ids as $coupon_id ) {
            $coupon_name = get_the_title( $coupon_id );
            if ( $coupon_name ) {
                $coupons[] = $coupon_name;
            }
        }
        // Cache the result for 1 hour
        set_transient( $cache_key, $coupons, HOUR_IN_SECONDS );
        return $coupons;
    }

}
/**
 * Function to output the list of coupons assigned to user, on the affiliate dashboard
 */
if ( !function_exists( 'wcusage_getUserCouponList' ) ) {
    function wcusage_getUserCouponList() {
        ob_start();
        $current_user = wp_get_current_user();
        $current_user_id = $current_user->ID;
        $current_username = $current_user->user_login;
        // Check if admin is previewing another user's dashboard
        $preview_user_id = $current_user_id;
        $is_admin_preview = false;
        if ( isset( $_GET['userid'] ) && isset( $_GET['preview_nonce'] ) && wcusage_check_admin_access() ) {
            $preview_user_id_param = intval( $_GET['userid'] );
            $preview_nonce = sanitize_text_field( $_GET['preview_nonce'] );
            // Verify the nonce
            if ( wp_verify_nonce( $preview_nonce, 'wcusage_preview_affiliate_' . $preview_user_id_param ) ) {
                $preview_user_id = $preview_user_id_param;
                $is_admin_preview = true;
            }
        }
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
            'meta_query'     => array(array(
                'key'     => 'wcu_select_coupon_user',
                'value'   => $preview_user_id,
                'compare' => '=',
            )),
        );
        $obituary_query = new WP_Query($args);
        $numcoupons = $obituary_query->post_count;
        $urlid = ( isset( $_GET['couponid'] ) ? sanitize_text_field( $_GET['couponid'] ) : "" );
        $urlid = str_replace( array(']', '[', '"'), '', $urlid );
        $wcusage_justcoupon = wcusage_get_setting_value( 'wcusage_field_justcoupon', '1' );
        $wcusage_registration_enable = wcusage_get_setting_value( 'wcusage_field_registration_enable', '1' );
        $wcusage_loginform = wcusage_get_setting_value( 'wcusage_field_loginform', '1' );
        $wcusage_registration_enable_login = wcusage_get_setting_value( 'wcusage_field_registration_enable_login', '1' );
        $wcusage_registration_enable_logout = wcusage_get_setting_value( 'wcusage_field_registration_enable_logout', '1' );
        $wcusage_show_coupon_if_single = wcusage_get_setting_value( 'wcusage_field_show_coupon_if_single', '1' );
        $wcusage_field_form_style = wcusage_get_setting_value( 'wcusage_field_form_style', '3' );
        $wcusage_field_form_style_columns = wcusage_get_setting_value( 'wcusage_field_form_style_columns', '1' );
        if ( $urlid ) {
            if ( shortcode_exists( 'couponaffiliates' ) ) {
                echo do_shortcode( '[couponaffiliates coupon="' . esc_attr( $urlid ) . '"]' );
            }
        } else {
            ?>
            
            <?php 
            // Get username for display
            $display_username = $current_username;
            if ( $is_admin_preview ) {
                $preview_user = get_userdata( $preview_user_id );
                $display_username = ( $preview_user ? $preview_user->user_login : 'Unknown User' );
            }
            ?>

            <h3 class="wcu-user-coupon-title"><?php 
            echo sprintf( esc_html__( 'My %s Coupons', 'woo-coupon-usage' ), esc_html( ( function_exists( 'wcusage_get_affiliate_text' ) ? wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) ) : __( 'Affiliate', 'woo-coupon-usage' ) ) ) );
            ?> <?php 
            if ( $is_admin_preview ) {
                echo '<small>(Viewing as: ' . esc_html( $display_username ) . ')</small>';
            }
            ?></h3>
            <hr class="wcu-user-coupon-linebreak" />

            <?php 
            if ( !is_user_logged_in() && !$is_admin_preview ) {
                if ( $wcusage_loginform || $wcusage_registration_enable ) {
                    ob_start();
                    ?>
                    <style>.wcu-user-coupon-title { display: none; }</style>

                    <div class="wcusage-login-form-cols">

                    <?php 
                    if ( $wcusage_loginform && $wcusage_registration_enable && $wcusage_registration_enable_login && $wcusage_registration_enable_logout ) {
                        ?>
                        <div class="wcusage-login-form-col wcu_form_style_<?php 
                        echo esc_attr( $wcusage_field_form_style );
                        if ( $wcusage_field_form_style_columns ) {
                            ?> wcu_form_style_columns<?php 
                        }
                        ?>">
                    <?php 
                    }
                    ?>

                    <?php 
                    if ( $wcusage_loginform ) {
                        ?>

                    <div class="wcu-form-section">

                        <p class="wcusage-login-form-title" style="font-size: 1.2em;"><strong><?php 
                        echo esc_html__( 'Login', 'woo-coupon-usage' );
                        ?>:</strong></p>

                        <div class="wcusage-login-form-section">
                        <?php 
                        if ( function_exists( 'wc_print_notices' ) ) {
                            woocommerce_output_all_notices();
                        }
                        if ( function_exists( 'woocommerce_login_form' ) ) {
                            // Redirect back to the affiliate dashboard page after login instead
                            // of the default WooCommerce My Account page.
                            woocommerce_login_form( array(
                                'redirect' => get_page_link( wcusage_get_coupon_shortcode_page_id() ),
                            ) );
                        }
                        ?>
                        </div>

                    </div>

                    <?php 
                    }
                    ?>

                    <?php 
                    if ( $wcusage_loginform && $wcusage_registration_enable && $wcusage_registration_enable_login && $wcusage_registration_enable_logout ) {
                        ?>
                    </div>
                    <?php 
                    }
                    ?>

                    <?php 
                    if ( $wcusage_registration_enable && $wcusage_registration_enable_login && $wcusage_registration_enable_logout ) {
                        echo "<div class='wcusage-login-form-col'>";
                        if ( shortcode_exists( 'couponaffiliates-register' ) ) {
                            echo do_shortcode( '[couponaffiliates-register]' );
                        }
                        echo "</div>";
                    }
                    ?>

                    </div>

                    <?php 
                    return ob_get_clean();
                } else {
                    echo esc_html__( "No affiliate dashboard found. Please contact us.", "woo-coupon-usage" );
                    if ( current_user_can( 'administrator' ) ) {
                        echo "<br/><br/><strong>Admin message:</strong><br/>To get started, go to the '<strong><a href='" . esc_url( admin_url( "admin.php?page=wcusage_coupons" ) ) . "'>coupons list</a></strong>' in your dashboard, where you can find a list of the affiliate dashboard URLs.";
                    }
                }
            } else {
                if ( !$numcoupons ) {
                    echo '<p>' . sprintf( esc_html__( "You don't have any active %s coupons right now.", 'woo-coupon-usage' ), esc_html( ( function_exists( 'wcusage_get_affiliate_text' ) ? wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) ) : __( 'affiliate', 'woo-coupon-usage' ) ) ) ) . '</p>';
                    $wcusage_field_registration_enable_register_loggedin = wcusage_get_setting_value( 'wcusage_field_registration_enable_register_loggedin', '1' );
                    if ( $wcusage_field_registration_enable_register_loggedin || isset( $_POST['submitaffiliateapplication'] ) ) {
                        echo "<br/>";
                        if ( shortcode_exists( 'couponaffiliates-register' ) ) {
                            echo do_shortcode( '[couponaffiliates-register]' );
                        }
                    }
                }
                $countcoupons = 0;
                $countcouponsloop = 0;
                $lastcoupon = "";
                while ( $obituary_query->have_posts() ) {
                    $obituary_query->the_post();
                    $postid = get_the_ID();
                    $coupon = get_the_title();
                    $page_url = wcusage_get_coupon_shortcode_page( 1 );
                    $secretid = $coupon . "-" . $postid;
                    $uniqueurl = $page_url . 'couponid=' . $secretid;
                    if ( $numcoupons <= 1 && $wcusage_show_coupon_if_single ) {
                        if ( wcusage_iscouponusers( $coupon, $preview_user_id ) && $lastcoupon != $coupon ) {
                            $coupon = str_replace( ' ', '%20', $coupon );
                            if ( shortcode_exists( 'couponaffiliates' ) ) {
                                echo do_shortcode( "[couponaffiliates coupon=" . $coupon . "]" );
                            }
                            echo "<style>.admin-only-list-coupons, .wcu-user-coupon-title, .wcu-user-coupon-linebreak { display: none; }</style>";
                        }
                        $lastcoupon = $coupon;
                    } else {
                        $wcu_select_coupon_user = get_post_meta( $postid, 'wcu_select_coupon_user', true );
                        // This is a user ID
                        if ( get_the_title() && $wcu_select_coupon_user == $preview_user_id ) {
                            $countcoupons++;
                            $countcouponsloop++;
                            if ( $countcouponsloop == 1 ) {
                                echo "<div class='wcu-user-coupon-list-group'>";
                            }
                            echo "<div class='wcu-user-coupon-list'>";
                            echo "<h3>" . esc_html( get_the_title() ) . "</h3>";
                            $amount = get_post_meta( $postid, 'coupon_amount', true );
                            $discount_type = get_post_meta( $postid, 'discount_type', true );
                            $combined_commission = wcusage_commission_message( $postid );
                            if ( $discount_type == "percent" ) {
                                $discount_msg = $amount . "%";
                            } elseif ( $discount_type == "recurring_percent" ) {
                                $discount_msg = $amount . "% (" . esc_html__( 'Recurring', 'woo-coupon-usage' ) . ")";
                            } elseif ( $discount_type == "fixed_cart" ) {
                                $discount_msg = wcusage_get_currency_symbol() . $amount;
                            } else {
                                if ( $discount_type ) {
                                    $discount_msg = $amount . " (" . $discount_type . ")";
                                } else {
                                    $discount_msg = "";
                                }
                            }
                            if ( $discount_msg ) {
                                echo '<p>' . esc_html__( "Discount", "woo-coupon-usage" ) . ': ' . esc_html( $discount_msg ) . '</p>';
                            }
                            global $woocommerce;
                            $c = new WC_Coupon(get_the_title());
                            $usage = $c->get_usage_count();
                            if ( $usage === "" ) {
                                $usage = '0';
                            }
                            $wcu_alltime_stats = get_post_meta( $postid, 'wcu_alltime_stats', true );
                            if ( !empty( $wcu_alltime_stats['total_count'] ) ) {
                                $usage = $wcu_alltime_stats['total_count'];
                            }
                            echo '<p>' . esc_html__( "Total Usage", "woo-coupon-usage" ) . ': ' . esc_html( $usage ) . '</p>';
                            echo '<p>' . esc_html__( "Commission", "woo-coupon-usage" ) . ': ' . wp_kses_post( $combined_commission ) . '</p>';
                            // Convert user ID to username for display
                            $user = get_user_by( 'id', $wcu_select_coupon_user );
                            $display_username = ( $user ? $user->user_login : '' );
                            echo '<p>' . esc_html__( "Affiliate", "woo-coupon-usage" ) . ': ' . esc_html( $display_username ) . '</p>';
                            echo '<p style="margin: 0 0 10px 0;"><a class="wcu-coupon-list-button"
                            href="' . esc_url( $uniqueurl ) . '">' . esc_html__( 'Dashboard', 'woo-coupon-usage' ) . ' <i class="far fa-arrow-alt-circle-right"></i></a></p>';
                            echo "</div>";
                            if ( $countcouponsloop == 3 ) {
                                echo "</div>";
                                $countcouponsloop = 0;
                            }
                        }
                    }
                }
                if ( $countcouponsloop != 3 ) {
                    echo "</div>";
                }
            }
            echo "<div style='clear: both;'></div>";
        }
        $thecontent = ob_get_contents();
        ob_end_clean();
        wp_reset_postdata();
        return $thecontent;
    }

}
add_shortcode( 'couponusage-user', 'wcusage_getUserCouponList' );
add_shortcode( 'couponaffiliates-user', 'wcusage_getUserCouponList' );
add_action(
    'wcusage_hook_getUserCouponList',
    'wcusage_getUserCouponList',
    10,
    0
);
/**
 * Adds meta box to coupon page.
 */
if ( !function_exists( 'wcusage_add_coupon_meta_box' ) ) {
    function wcusage_add_coupon_meta_box() {
        add_meta_box(
            "wcusage-meta-box",
            "Coupon Affiliates",
            "wcusage_coupon_meta_box_markup",
            "shop_coupon",
            "side",
            "low",
            null
        );
    }

}
add_action( "add_meta_boxes", "wcusage_add_coupon_meta_box" );
/**
 * Position the "Coupon Affiliates" meta box directly under the Publish box on
 * the coupon edit page. The Publish box is forced to the top of the side column
 * with our box right beneath it, while any other side boxes keep their order.
 */
if ( !function_exists( 'wcusage_default_coupon_meta_box_order' ) ) {
    function wcusage_default_coupon_meta_box_order(  $order  ) {
        if ( !is_array( $order ) ) {
            $order = array();
        }
        // Existing side boxes (from the user's saved order, if any).
        $side = ( isset( $order['side'] ) ? $order['side'] : '' );
        $ids = array_values( array_filter( array_map( 'trim', explode( ',', $side ) ) ) );
        // Remove Publish + our box so we can re-add them at the front in order.
        $ids = array_values( array_filter( $ids, function ( $id ) {
            return 'submitdiv' !== $id && 'wcusage-meta-box' !== $id;
        } ) );
        // Publish first, then Coupon Affiliates directly below it, then the rest.
        array_unshift( $ids, 'submitdiv', 'wcusage-meta-box' );
        $order['side'] = implode( ',', $ids );
        return $order;
    }

}
add_filter( 'get_user_option_meta-box-order_shop_coupon', 'wcusage_default_coupon_meta_box_order' );
/**
 * Content for meta box on coupons page.
 */
if ( !function_exists( 'wcusage_coupon_meta_box_markup' ) ) {
    function wcusage_coupon_meta_box_markup() {
        if ( isset( $_GET['post'] ) ) {
            $post_id = absint( $_GET['post'] );
            $coupon_info = wcusage_get_coupon_info_by_id( $post_id );
            $uniqueurl = $coupon_info[4];
            $coupon_user = get_post_meta( $post_id, 'wcu_select_coupon_user', true );
            // Convert user ID to username for display
            if ( is_numeric( $coupon_user ) && $coupon_user ) {
                $user = get_user_by( 'id', $coupon_user );
                $coupon_user = ( $user ? $user->user_login : '' );
            } elseif ( $coupon_user && is_string( $coupon_user ) ) {
                // If it's a username (legacy data), update to ID
                $user = get_user_by( 'login', $coupon_user );
                if ( $user ) {
                    update_post_meta( $post_id, 'wcu_select_coupon_user', $user->ID );
                }
                $coupon_user = ( $user ? $user->user_login : '' );
            }
            if ( !is_string( $coupon_user ) && !is_numeric( $coupon_user ) ) {
                $coupon_user = '';
            }
            if ( isset( $_GET['refreshstats'] ) ) {
                if ( $_GET['refreshstats'] ) {
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php 
                    echo sprintf( wp_kses_post( __( 'Done! The affiliate statistics for this coupon will be refreshed the next time the <a href="%s">affiliate dashboard</a> is loaded.', 'woo-coupon-usage' ) ), esc_url( $uniqueurl ) );
                    ?></p>
                    </div>
                    <?php 
                    delete_post_meta( $post_id, 'wcu_last_refreshed' );
                }
            }
        }
        ?>

        <?php 
        // Coupon codes are case-insensitive to WooCommerce, so two coupons whose codes
        // differ only by letter case are one code: only one of them can ever be applied
        // at checkout, and looking that code up always returns the same one. Warn here,
        // because nothing else in WooCommerce does.
        if ( isset( $post_id ) && $post_id && function_exists( 'wcusage_coupon_code_is_ambiguous' ) && wcusage_coupon_code_is_ambiguous( $post_id ) ) {
            $wcu_duplicate_id = wc_get_coupon_id_by_code( get_post_field( 'post_title', $post_id, 'raw' ), $post_id );
            ?>
            <div class="notice notice-warning inline" style="margin: 14px 0 0 0; padding: 8px 12px;">
                <p style="margin: 0;"><strong><?php 
            echo esc_html__( 'Duplicate coupon code', 'woo-coupon-usage' );
            ?></strong><br/>
                <?php 
            echo sprintf( wp_kses_post( __( 'Another coupon (<a href="%1$s">%2$s</a>) uses the same code as this one - coupon codes ignore letter case. Only one of them can be used at checkout, and both share the same statistics. Rename one of them to keep their affiliate dashboards separate.', 'woo-coupon-usage' ) ), esc_url( get_edit_post_link( $wcu_duplicate_id ) ), esc_html( get_the_title( $wcu_duplicate_id ) ) );
            ?></p>
            </div>
            <?php 
        }
        ?>

        <?php 
        if ( isset( $post_id ) && $post_id ) {
            ?>
            <p style="margin-top: 14px;"><a href="<?php 
            echo esc_url( $uniqueurl );
            ?>" target="_blank" class="wcusage-settings-button"
            style="margin: 0; text-align: center; margin: 0 auto; display: block;">
                <?php 
            echo esc_html__( 'View Affiliate Dashboard', 'woo-coupon-usage' );
            ?>
                <span class="dashicons dashicons-external"></span></a></p>
        <?php 
        }
        ?>

            <p class="form-field wcu_select_coupon_user_meta_field" style="margin-top: 5px; margin-bottom: 6px;">
                <label for="wcu_select_coupon_user_meta"><?php 
        echo esc_html__( 'Affiliate User', 'woo-coupon-usage' );
        ?></label>
                <input type="text" id="wcu_select_coupon_user_meta" style="width: 100%;"
                name="wcu_select_coupon_user_meta" value="<?php 
        echo esc_attr( $coupon_user );
        ?>" class="regular-text" />
            </p>

            <p style="margin-top: 0; margin-bottom: 20px;">
                <a href="#wcusage_coupon_data" class="wcusage-customise-more-settings" style="font-size: 12px; text-decoration: none;">
                    <span class="dashicons dashicons-admin-generic" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
                    <?php 
        echo esc_html__( 'Customise more settings', 'woo-coupon-usage' );
        ?>
                </a>
            </p>

            <?php 
        // All-time stats + Refresh Statistics — recalculate this coupon's
        // statistics directly here via AJAX (no need to open the affiliate
        // dashboard). Replaces the old "REFRESH ALL DATA" link that relied on
        // a first dashboard visit.
        if ( isset( $_GET['post'] ) ) {
            $wcu_refresh_coupon_user_id = intval( get_post_meta( $post_id, 'wcu_select_coupon_user', true ) );
            if ( function_exists( 'wcusage_refresh_enqueue_assets' ) ) {
                wcusage_refresh_enqueue_assets( $wcu_refresh_coupon_user_id );
            }
            if ( function_exists( 'wcusage_render_coupon_stat_boxes' ) ) {
                wcusage_render_coupon_stat_boxes( $post_id );
            }
            if ( function_exists( 'wcusage_render_refresh_statistics_box' ) ) {
                wcusage_render_refresh_statistics_box( $wcu_refresh_coupon_user_id, array($post_id) );
            }
            if ( function_exists( 'wcusage_render_reset_start_date_box' ) ) {
                wcusage_render_reset_start_date_box( $post_id );
            }
        }
    }

}
/**
 * Unlink user from coupon
 */
if ( !function_exists( 'wcusage_coupon_affiliate_unlink' ) ) {
    function wcusage_coupon_affiliate_unlink(  $coupon  ) {
        // Get the current user ID before unlinking
        $user_id = get_post_meta( $coupon, 'wcu_select_coupon_user', true );
        // Unlink the coupon
        update_post_meta( $coupon, 'wcu_select_coupon_user', '' );
        // Clear the user's affiliate column cache, affiliate status cache, and coupon IDs cache
        if ( $user_id ) {
            wcusage_clear_user_cache( $user_id );
        }
        $coupon_name = get_the_title( $coupon );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Coupon unlinked from user:', 'woo-coupon-usage' ) . esc_html( $coupon ) . '</p></div>';
    }

}
add_filter(
    'wcusage_hook_coupon_affiliate_unlink',
    'wcusage_coupon_affiliate_unlink',
    10,
    1
);