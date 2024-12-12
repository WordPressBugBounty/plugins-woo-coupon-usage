<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'SitePress' ) ) {
    // Temp fix for WPML conflict
    function wcusage_add_custom_box() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            $screen = wc_get_page_screen_id( 'shop-order' );
        } else {
            $screen = 'shop_order';
        }
        add_meta_box(
            'wcusage_affiliate_info',
            // Unique ID
            'Coupon Affiliate',
            // Box title
            'wcusage_custom_box_html',
            // Content callback, must be of type callable
            $screen,
            // Post type
            'side',
            'high'
        );
    }

    add_action( 'add_meta_boxes', 'wcusage_add_custom_box' );
}
// Display the metabox content
function wcusage_custom_box_html(  $post  ) {
    $options = get_option( 'wcusage_options' );
    $wcusage_show_column_code = wcusage_get_setting_value( 'wcusage_field_show_orders_aff_info', '1' );
    $coupon_code = "";
    $lifetimeaffiliate = "";
    if ( !empty( $post ) && $post instanceof WP_Post && property_exists( $post, 'ID' ) ) {
        $post_id = $post->ID;
    } else {
        if ( method_exists( $post, 'get_id' ) ) {
            $post_id = $post->get_id();
        } else {
            $post_id = "";
        }
    }
    $order = wc_get_order( $post_id );
    if ( $order ) {
        if ( $wcusage_show_column_code && !class_exists( 'SitePress' ) ) {
            $affiliate = array();
            $coupon_codes = array();
            $lifetimeaffiliate = wcusage_order_meta( $post_id, 'lifetime_affiliate_coupon_referrer' );
            $affiliatereferrer = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon' );
            if ( $lifetimeaffiliate ) {
                $coupon_code = $lifetimeaffiliate;
                wcusage_custom_box_html_content(
                    $lifetimeaffiliate,
                    $post,
                    $order,
                    1
                );
            } elseif ( $affiliatereferrer ) {
                wcusage_custom_box_html_content(
                    $affiliatereferrer,
                    $post,
                    $order,
                    2
                );
            } else {
                // if $order is array
                if ( class_exists( 'WooCommerce' ) ) {
                    if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
                        foreach ( $order->get_coupon_codes() as $coupon_code ) {
                            // Get the WC_Coupon object
                            if ( $coupon_code ) {
                                wcusage_custom_box_html_content(
                                    $coupon_code,
                                    $post,
                                    $order,
                                    0
                                );
                            }
                        }
                    }
                }
            }
            if ( !$order->get_coupon_codes() && !$lifetimeaffiliate && !$affiliatereferrer ) {
                echo "<p>" . esc_html__( "No coupons were used for this order.", "woo-coupon-usage" ) . "</p>";
            }
        } else {
            echo "<p>" . esc_html__( "Affiiliate Info not available.", "woo-coupon-usage" ) . "</p>";
        }
        $wcusage_referrer_coupon = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon', true );
        if ( $lifetimeaffiliate ) {
            $wcusage_referrer_coupon = "";
        }
        wp_nonce_field( basename( __FILE__ ), 'wcusage_referrer_coupon_nonce' );
    } else {
        echo "<p>" . esc_html__( "Affiiliate Info not available.", "woo-coupon-usage" ) . "</p>";
    }
    // Get order status
    if ( $order ) {
        $order_status = $order->get_status();
    } else {
        $order_status = "";
    }
    ?>

  <p>
      <label for="wcusage_referrer_coupon">Affiliate Referrer Coupon:
      <?php 
    echo wc_help_tip( esc_html__( 'Set the primary referral coupon for this order. This will override all other settings, as the default and only coupon that will earn commission from this order.', 'woo-coupon-usage' ), false );
    ?>
      </label>
      <input type="text" id="wcusage_referrer_coupon" name="wcusage_referrer_coupon" value="<?php 
    echo esc_attr( $wcusage_referrer_coupon );
    ?>" style="width: 100%;"
      <?php 
    if ( !$wcusage_referrer_coupon && $coupon_code ) {
        ?>placeholder="<?php 
        echo esc_html( $coupon_code );
        ?>"<?php 
    }
    ?>
      <?php 
    if ( $lifetimeaffiliate ) {
        ?>title="<?php 
        echo esc_html__( 'This can not be edited for a lifetime affiliate referral.', 'woo-coupon-usage' );
        ?>" readonly<?php 
    }
    ?>
      <?php 
    if ( !$lifetimeaffiliate && $order_status == 'completed' ) {
        ?>title="<?php 
        echo esc_html__( 'This can not be edited when the order is completed.', 'woo-coupon-usage' );
        ?>" readonly<?php 
    }
    ?>>
      <br/>
  </p>

  <?php 
}

function wcusage_custom_box_html_content(
    $coupon_code,
    $post,
    $order,
    $type
) {
    $order_id = $order->get_id();
    if ( !empty( $_GET['update_unpaid_commission'] ) && $_GET['update_unpaid_commission'] ) {
        wcusage_do_action_order_update_commission( $order, $order_id, $coupon_code );
    }
    $getinfo = wcusage_get_the_order_coupon_info( $coupon_code, "", $order_id );
    $coupon_info = wcusage_get_coupon_info( $coupon_code );
    $coupon_id = $coupon_info[2];
    // Refresh Stats Link
    echo "<p style='position: absolute; right: 10px; top: -2px; margin: 0; padding: 0;'>";
    echo "<a href='" . esc_url( admin_url( 'post.php?post=' . esc_attr( $order_id ) . '&action=edit&refresh_stats=1' ) ) . "' style='text-decoration: none;'\r\n  onClick='return confirm(\"" . esc_html__( 'Are you sure you want to refresh the affiliate stats for this order? This will delete the current referral stats/commission and recalculate them.', 'woo-coupon-usage' ) . "\");'\r\n  title='" . esc_html__( 'Recalculate the affiliate stats for this order.', 'woo-coupon-usage' ) . "'\r\n  ><span class='dashicons dashicons-update' style='font-size: 14px; height: 14px; display: inline-block; margin-top: 4px;'></span></a>";
    if ( isset( $_GET['refresh_stats'] ) && $_GET['refresh_stats'] ) {
        delete_post_meta( $order_id, 'wcusage_commission_summary' );
        delete_post_meta( $order_id, 'wcusage_stats' );
        delete_post_meta( $order_id, 'wcusage_total_commission' );
        // Remove params from URL
        $url = remove_query_arg( 'refresh_stats' );
        wp_redirect( $url );
    }
    echo "</p>";
    echo "<p>";
    if ( $type == 1 ) {
        echo '(' . esc_html__( 'Lifetime Referrer', 'woo-coupon-usage' ) . ')<br/>';
    }
    if ( $type == 2 ) {
        echo '<strong>(' . esc_html__( 'Custom / URL Referral', 'woo-coupon-usage' ) . ')</strong><br/>';
    }
    $ispaid = "";
    // Message
    echo 'Referral Code: ' . esc_html( $coupon_code ) . '<br/>';
    echo wp_kses_post( $getinfo['affililiateusertext'] );
    if ( $order->get_status() != "refunded" && !wcusage_coupon_disable_commission( $coupon_id ) ) {
        echo esc_html__( 'Commission', 'woo-coupon-usage' ) . ": " . wp_kses_post( $getinfo['thecommission'] ) . wp_kses_post( $ispaid ) . "<br/>";
    }
    echo "<a href='" . esc_url( $getinfo['uniqueurl'] ) . "' target='_blank' style='color: #07bbe3;' title='" . esc_html__( 'View the affiliate dashboard for this affiliate coupon.', 'woo-coupon-usage' ) . "'>" . esc_html__( 'View Dashboard', 'woo-coupon-usage' ) . "</a>";
    echo "</p>";
    $wcusage_field_mla_enable = wcusage_get_setting_value( 'wcusage_field_mla_enable', '0' );
    if ( $wcusage_field_mla_enable && wcu_fs()->can_use_premium_code() && !wcusage_coupon_disable_commission( $coupon_id ) ) {
        $get_parents = get_user_meta( $getinfo['theuserid'], 'wcu_ml_affiliate_parents', true );
        if ( !empty( $get_parents ) && is_array( $get_parents ) ) {
            echo "<p><strong>MLA Commission:</strong>";
            foreach ( $get_parents as $key => $parent_id ) {
                $parent_user_info = get_user_by( 'ID', $parent_id );
                $parent_user_name = $parent_user_info->user_login;
                $parent_user_id = $parent_user_info->ID;
                $coupon_info = wcusage_get_coupon_info( $coupon_code );
                $coupon_id = $coupon_info[2];
                $parent_commission = wcusage_mla_get_commission_from_tier( $getinfo['thecommissionnum'], $key );
                echo "<br/>(" . esc_html( $key ) . ") <a href='" . esc_url( admin_url( "user-edit.php?user_id=" . $parent_user_id ) ) . "' target='_blank' style='color: #07bbe3;'>" . esc_html( $parent_user_name ) . "</a>: " . wp_kses_post( wcusage_format_price( esc_html( $parent_commission ) ) );
            }
            echo "</p>";
        }
    }
}

function wcusage_save_postdata(  $post_id  ) {
    if ( array_key_exists( 'wcusage_field', $_POST ) ) {
        update_post_meta( $post_id, '_wcusage_meta_key', sanitize_text_field( $_POST['wcusage_field'] ) );
    }
}

add_action( 'save_post', 'wcusage_save_postdata' );
// Save the "wcusage_referrer_coupon" data
function wcusage_wcusage_referrer_coupon_meta_box_save(  $post_id  ) {
    // Check if our nonce is set.
    if ( !isset( $_POST['wcusage_referrer_coupon_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcusage_referrer_coupon_nonce'] ) ), basename( __FILE__ ) ) ) {
        return;
    }
    if ( !current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    // check if $_POST['wcusage_referrer_coupon'] exists as a woocommerce coupon, if not then return
    $coupon_code = '';
    if ( isset( $_POST['wcusage_referrer_coupon'] ) ) {
        $coupon_code = $_POST['wcusage_referrer_coupon'];
    }
    $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
    // Get the posted data
    $wcusage_referrer_coupon = ( isset( $_POST['wcusage_referrer_coupon'] ) ? sanitize_text_field( $_POST['wcusage_referrer_coupon'] ) : '' );
    // Get the old data
    $wcusage_referrer_coupon_old = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon', true );
    // show an error popup in wordpress
    if ( $coupon_code && !$coupon_id ) {
        echo '<div class="error"><p>' . esc_html__( 'The coupon code you entered does not exist.', 'woo-coupon-usage' ) . '</p></div>';
        return;
    }
    // Meta data array
    $meta_data = [];
    // Update wcusage_referrer_coupon meta
    $meta_data['wcusage_referrer_coupon'] = $wcusage_referrer_coupon;
    // If was not set and updated to new value, set wcusage_referrer_refresh
    if ( !$wcusage_referrer_coupon_old && $wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
    }
    // If was set and updated to empty value, set wcusage_referrer_refresh
    if ( $wcusage_referrer_coupon_old && !$wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
        $meta_data['wcusage_referrer_refresh_prev'] = $wcusage_referrer_coupon_old;
    }
    // If was set and updated to new value, set wcusage_referrer_refresh
    if ( $wcusage_referrer_coupon_old && $wcusage_referrer_coupon && $wcusage_referrer_coupon_old != $wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
        $meta_data['wcusage_referrer_refresh_prev'] = $wcusage_referrer_coupon_old;
    }
    $wcusage_field_enable_coupon_all_stats_meta = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
    if ( $wcusage_field_enable_coupon_all_stats_meta ) {
        // Get the order
        $order = wc_get_order( $post_id );
        if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
            $coupons_array = $order->get_coupon_codes();
        } else {
            $coupons_array = $order->get_used_coupons();
        }
        // Update all time stats
        if ( $wcusage_referrer_coupon_old != $wcusage_referrer_coupon ) {
            // Add
            if ( $wcusage_referrer_coupon ) {
                // Add to new coupon
                do_action(
                    'wcusage_hook_update_all_stats_single',
                    $wcusage_referrer_coupon,
                    $post_id,
                    1,
                    1
                );
                // Add
            } else {
                // Add to all other coupons
                foreach ( $coupons_array as $this_coupon_code ) {
                    do_action(
                        'wcusage_hook_update_all_stats_single',
                        $this_coupon_code,
                        $post_id,
                        1,
                        1
                    );
                    // Add
                }
            }
            // Remove
            if ( $wcusage_referrer_coupon_old ) {
                // Remove from previous coupon
                do_action(
                    'wcusage_hook_update_all_stats_single',
                    $wcusage_referrer_coupon_old,
                    $post_id,
                    0,
                    1
                );
                // Remove
            } else {
                // Remove to all other coupons
                foreach ( $coupons_array as $this_coupon_code ) {
                    do_action(
                        'wcusage_hook_update_all_stats_single',
                        $this_coupon_code,
                        $post_id,
                        0,
                        1
                    );
                    // Remove
                }
            }
        }
    }
    // Update meta data
    if ( !empty( $meta_data ) ) {
        wcusage_update_order_meta_bulk( $post_id, $meta_data );
    }
}

add_action( 'woocommerce_process_shop_order_meta', 'wcusage_wcusage_referrer_coupon_meta_box_save' );