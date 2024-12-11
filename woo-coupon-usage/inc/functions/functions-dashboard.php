<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Displays the normal dashboard tabs used in the shortcode
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_dashboard_normal_tabs',
    'wcusage_dashboard_normal_tabs',
    10,
    1
);
function wcusage_dashboard_normal_tabs(  $wcusage_page_load  ) {
    // $options
    $options = get_option( 'wcusage_options' );
    // $option_coupon_orders
    $wcusage_field_show_order_tab = wcusage_get_setting_value( 'wcusage_field_show_order_tab', '1' );
    $option_coupon_orders = wcusage_get_setting_value( 'wcusage_field_orders', '10' );
    $show_tabs_icons = wcusage_get_setting_value( 'wcusage_field_show_tabs_icons', '1' );
    $wcusage_field_mobile_menu = wcusage_get_setting_value( 'wcusage_field_mobile_menu', 'dropdown' );
    ?>

<div class="wcutab">

  <!-- ##############Info Tab ############## -->
  <?php 
    if ( $wcusage_page_load ) {
        ?><form method="post"><?php 
    }
    ?>
  <input type="text" name="page-stats" value="1" style="display: none;">

  <button id="tab-page-stats" class="wcutab-active wcutablinks wcutabfirst <?php 
    if ( isset( $_POST['page-stats'] ) || !isset( $_POST['load-page'] ) && $wcusage_page_load ) {
        ?>wcu-active-tab<?php 
    }
    ?>" <?php 
    if ( !$wcusage_page_load ) {
        ?>onclick="wcuOpenTab(event, 'wcu1')"<?php 
    }
    ?>>
    <?php 
    if ( $show_tabs_icons ) {
        ?><i class="fas fa-chart-line fa-xs"></i> <?php 
    }
    echo esc_html( ucfirst( esc_html__( "Statistics", "woo-coupon-usage" ) ) );
    ?>
  </button>

  <?php 
    if ( $wcusage_page_load ) {
        ?></form><?php 
    }
    ?>

  <!-- ############## Monthly Summary Tab ############## -->
  <?php 
    ?>

  <!-- ############## Recent Orders Tab############## -->
  <?php 
    if ( $wcusage_field_show_order_tab && ($option_coupon_orders > 0 || $option_coupon_orders == "") ) {
        ?>

    <?php 
        if ( $wcusage_page_load ) {
            ?><form method="post"><?php 
        }
        ?>
    <input type="text" name="page-orders" value="1" style="display: none;">

      <button id="tab-page-orders" name="load-page" class="wcutablinks tabrecentorders <?php 
        if ( isset( $_POST['page-orders'] ) && $wcusage_page_load ) {
            ?>wcu-active-tab<?php 
        }
        ?>" <?php 
        if ( !$wcusage_page_load ) {
            ?>onclick="wcuOpenTab(event, 'wcu3')"<?php 
        }
        ?>>
        <?php 
        if ( $show_tabs_icons ) {
            ?><i class="fas fa-shopping-cart fa-xs"></i> <?php 
        }
        echo esc_html__( "Recent Orders", "woo-coupon-usage" );
        ?>
      </button>

    <?php 
        if ( $wcusage_page_load ) {
            ?></form><?php 
        }
        ?>

  <?php 
    }
    ?>

  <!-- ############## Links Tab ############## -->
  <?php 
    $wcusage_field_urls_enable = wcusage_get_setting_value( 'wcusage_field_urls_enable', '1' );
    $wcusage_field_urls_tab_enable = wcusage_get_setting_value( 'wcusage_field_urls_tab_enable', '1' );
    if ( $wcusage_field_urls_enable == '1' && $wcusage_field_urls_tab_enable == '1' ) {
        ?>

  <?php 
        if ( $wcusage_page_load ) {
            ?><form method="post"><?php 
        }
        ?>
  <input type="text" name="page-links" value="1" style="display: none;">

  <button id="tab-page-links" name="load-page" class="wcutablinks tablinks <?php 
        if ( isset( $_POST['page-links'] ) && $wcusage_page_load ) {
            ?>wcu-active-tab<?php 
        }
        ?>" <?php 
        if ( !$wcusage_page_load ) {
            ?>onclick="wcuOpenTab(event, 'wcu4')"<?php 
        }
        ?>>
    <?php 
        if ( $show_tabs_icons ) {
            ?><i class="fas fa-link fa-xs"></i> <?php 
        }
        echo esc_html__( "Referral URL", "woo-coupon-usage" );
        ?>
  </button>

  <?php 
        if ( $wcusage_page_load ) {
            ?></form><?php 
        }
        ?>

  <?php 
    }
    ?>

  <!-- ############## Creatives Tab ############## -->
  <?php 
    $wcusage_field_creatives_enable = wcusage_get_setting_value( 'wcusage_field_creatives_enable', '1' );
    ?>

  <!-- ############## Payouts Tab ############## -->
  <?php 
    $wcusage_field_payouts_enable = wcusage_get_setting_value( 'wcusage_field_payouts_enable', '1' );
    ?>

  <!-- ############## Rates Tab ############## -->
  <?php 
    $wcusage_field_rates_enable = wcusage_get_setting_value( 'wcusage_field_rates_enable', '0' );
    ?>

  <!-- ############## Bonuses Tab ############## -->
  <?php 
    $wcusage_field_bonuses_enable = wcusage_get_setting_value( 'wcusage_field_bonuses_enable', '0' );
    $wcusage_field_bonuses_tab_enable = wcusage_get_setting_value( 'wcusage_field_bonuses_tab_enable', '1' );
    ?>

  <!-- ############## Settings Tab ############## -->
  <?php 
    $wcusage_field_show_settings_tab_show = wcusage_get_setting_value( 'wcusage_field_show_settings_tab_show', '1' );
    if ( is_user_logged_in() ) {
        if ( $wcusage_field_show_settings_tab_show ) {
            ?>

        <?php 
            if ( $wcusage_page_load ) {
                ?><form method="post"><?php 
            }
            ?>
        <input type="text" name="page-settings" value="1" style="display: none;">

        <button id="tab-page-settings" name="load-page" class="wcutablinks tabsettings <?php 
            if ( isset( $_POST['page-settings'] ) && $wcusage_page_load ) {
                ?>wcu-active-tab<?php 
            }
            ?>" <?php 
            if ( !$wcusage_page_load ) {
                ?>onclick="wcuOpenTab(event, 'wcu6')"<?php 
            }
            ?>>
          <?php 
            if ( $show_tabs_icons ) {
                ?><i class="fas fa-cog fa-xs"></i> <?php 
            }
            echo esc_html__( "Settings", "woo-coupon-usage" );
            ?>
        </button>

        <?php 
            if ( $wcusage_page_load ) {
                ?></form><?php 
            }
            ?>

    <?php 
        }
    }
    ?>

  <!-- ############## Custom Tabs ############## -->

  <?php 
    do_action( 'wcusage_hook_after_normal_tabs', $wcusage_page_load );
    // Custom Hook
    ?>

</div>

<?php 
    if ( $wcusage_field_mobile_menu == "dropdown" ) {
        ?>
<div class="wcutabmobile">
<?php 
        if ( $wcusage_page_load ) {
            ?><form method="post" class="wcu-select-tab"><?php 
        }
        ?>
<input type="text" name="load-page" value="1" style="display: none;">
<select id="wcu-select-tab" name="wcu-select-tab" onchange="this.form.submit()" style="display: block; margin-top: 0px; font-size: 20px; text-align: center;">
  <option value="page-stats" <?php 
        if ( isset( $_POST['page-stats'] ) || !isset( $_POST['load-page'] ) && $wcusage_page_load ) {
            ?>selected<?php 
        }
        ?>><?php 
        echo esc_html( ucfirst( esc_html__( "Statistics", "woo-coupon-usage" ) ) );
        ?></option>
  <?php 
        if ( $wcusage_show_months_table == '1' ) {
            ?>
  <option value="page-monthly" <?php 
            if ( isset( $_POST['page-monthly'] ) && $wcusage_page_load ) {
                ?>selected<?php 
            }
            ?>><?php 
            echo ucfirst( esc_html__( "Monthly Summary", "woo-coupon-usage" ) );
            ?></option>
  <?php 
        }
        ?>
  <?php 
        if ( $wcusage_field_show_order_tab && ($option_coupon_orders > 0 || $option_coupon_orders == "") ) {
            ?>
  <option value="page-orders" <?php 
            if ( isset( $_POST['page-orders'] ) && $wcusage_page_load ) {
                ?>selected<?php 
            }
            ?>><?php 
            echo esc_html__( "Recent Orders", "woo-coupon-usage" );
            ?></option>
  <?php 
        }
        ?>
  <?php 
        if ( $wcusage_field_urls_enable == '1' && $wcusage_field_urls_tab_enable == '1' ) {
            ?>
  <option value="page-links" <?php 
            if ( isset( $_POST['page-links'] ) && $wcusage_page_load ) {
                ?>selected<?php 
            }
            ?>><?php 
            echo esc_html__( "Referral URL", "woo-coupon-usage" );
            ?></option>
  <?php 
        }
        ?>
  <?php 
        if ( $wcusage_field_creatives_enable == '1' ) {
            $total_creatives = wp_count_posts( $post_type = 'wcu-creatives' );
            $published_creatives = $total_creatives->publish;
            if ( $published_creatives > 0 ) {
                ?>
    <option value="page-creatives" <?php 
                if ( isset( $_POST['page-creatives'] ) && $wcusage_page_load ) {
                    ?>selected<?php 
                }
                ?>><?php 
                echo esc_html__( "Creatives", "woo-coupon-usage" );
                ?></option>
    <?php 
            }
        }
        ?>

  <?php 
        ?>

  <?php 
        if ( is_user_logged_in() ) {
            if ( $wcusage_field_show_settings_tab_show ) {
                ?>
    <option value="page-settings" <?php 
                if ( isset( $_POST['page-settings'] ) && $wcusage_page_load ) {
                    ?>selected<?php 
                }
                ?>><?php 
                echo esc_html__( "Settings", "woo-coupon-usage" );
                ?></option>
    <?php 
            }
        }
        ?>
  <?php 
        $tabsnumber = wcusage_get_setting_value( 'wcusage_field_custom_tabs_number', '2' );
        if ( $tabsnumber ) {
            for ($i = 1; $i <= $tabsnumber; $i++) {
                if ( isset( $options['wcusage_field_custom_tabs'][$i]['name'] ) ) {
                    $wcusage_field_custom_tab = $options['wcusage_field_custom_tabs'][$i]['name'];
                } else {
                    $wcusage_field_custom_tab = "";
                }
                if ( $wcusage_field_custom_tab ) {
                    ?>
    <option value="custom-<?php 
                    echo $i;
                    ?>" <?php 
                    if ( isset( $_POST['page-custom-' . $i] ) || !isset( $_POST['load-page'] ) && $wcusage_page_load ) {
                        ?>selected<?php 
                    }
                    ?>><?php 
                    echo $wcusage_field_custom_tab;
                    ?></option>
    <?php 
                }
            }
        }
        ?>
</select>
<?php 
        if ( $wcusage_page_load ) {
            ?></form><?php 
        }
        ?>
<script>
document.getElementById('wcu-select-tab').addEventListener('change', function() {
  var tab = this.value;
  document.getElementById('tab-' + tab).click();
});
</script>
</div>
<?php 
    }
    ?>

<?php 
}

/**
 * Checks the current session to prevent spamming requests. No more than 15 requests per 2 minute session.
 *
 * @param int $postid
 *
 * @return boolean
 *
 */
function wcusage_requests_session_check(  $postid  ) {
    //delete_post_meta( $postid, 'wcu_requests_last_session' );
    //delete_post_meta( $postid, 'wcu_requests_last_session_count' );
    $blocked = 0;
    $wcu_requests_last_session = get_post_meta( $postid, 'wcu_requests_last_session', true );
    $wcu_requests_last_session_count = get_post_meta( $postid, 'wcu_requests_last_session_count', true );
    if ( $wcu_requests_last_session ) {
        $futureRequestDate = $wcu_requests_last_session + 60 * 2;
        $currentRequestDate = strtotime( date( 'Y-m-d H:i:s' ) );
        if ( $currentRequestDate < $futureRequestDate ) {
            $wcu_requests_last_session_count = get_post_meta( $postid, 'wcu_requests_last_session_count', true );
            update_post_meta( $postid, 'wcu_requests_last_session_count', $wcu_requests_last_session_count + 1 );
            $wcu_requests_last_session_count = get_post_meta( $postid, 'wcu_requests_last_session_count', true );
            if ( $wcu_requests_last_session_count > 15 ) {
                $blocked = 1;
            }
        } else {
            update_post_meta( $postid, 'wcu_requests_last_session', strtotime( date( 'Y-m-d H:i:s' ) ) );
            update_post_meta( $postid, 'wcu_requests_last_session_count', 1 );
        }
    }
    if ( !$wcu_requests_last_session ) {
        update_post_meta( $postid, 'wcu_requests_last_session', strtotime( date( 'Y-m-d H:i:s' ) ) );
        update_post_meta( $postid, 'wcu_requests_last_session_count', 1 );
        $wcu_requests_last_session = get_post_meta( $postid, 'wcu_requests_last_session', true );
        $wcu_requests_last_session_count = get_post_meta( $postid, 'wcu_requests_last_session_count', true );
    }
    $return_array = [];
    $return_array['status'] = $blocked;
    $return_array['message'] = esc_html__( 'Request Failed!', 'woo-coupon-usage' ) . " " . esc_html__( 'You are sending too many of requests in a short time and have been temporarily timed out.', 'woo-coupon-usage' ) . " " . esc_html__( 'Please try again in around 1-2 minutes.', 'woo-coupon-usage' );
    return $return_array;
}

/**
 * Code added to end of the affiliate dashboard page shortcode.
 *
 */
if ( !function_exists( 'wcusage_do_after_dashboard' ) ) {
    function wcusage_do_after_dashboard() {
        $options = get_option( 'wcusage_options' );
        $wcusage_field_load_ajax = wcusage_get_setting_value( 'wcusage_field_load_ajax', 1 );
        $wcusage_field_load_ajax_per_page = wcusage_get_setting_value( 'wcusage_field_load_ajax_per_page', 1 );
        if ( !$wcusage_field_load_ajax ) {
            $wcusage_field_load_ajax_per_page = 0;
        }
        ?>

    <style>
    :not(section.container) #preloader,
    :not(section.container) .preloader,
    :not(section.container) .smart-page-loader,
    :not(section.container) #wptime-plugin-preloader,
    :not(section.container) .loaderWrap {
      display: none !important;
    }
    </style>

  	<?php 
        if ( $wcusage_field_load_ajax && !$wcusage_field_load_ajax_per_page ) {
            ?>
  		<script>
  		jQuery(document).ready(function(){
  			jQuery( ".wcusage-refresh-data" ).click();
  		});
  		</script>
  	<?php 
        }
        ?>

    <?php 
    }

}
add_action(
    'wcusage_hook_after_dashboard',
    'wcusage_do_after_dashboard',
    10,
    0
);
/**
 * Gets the old basic products list table row
 *
 * @param array $orderinfo
 * @param array $order_refunds
 * @param int $cols
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_get_basic_list_order_products',
    'wcusage_get_basic_list_order_products',
    10,
    3
);
function wcusage_get_basic_list_order_products(  $orderinfo, $order_refunds, $cols  ) {
    ?>

  <td class='wcuTableCell' colspan="<?php 
    echo esc_attr( $cols );
    ?>">

  <strong><?php 
    echo esc_html__( "Products", "woo-coupon-usage" );
    ?>:</strong><br/>
  <?php 
    foreach ( $orderinfo->get_items() as $key => $lineItem ) {
        $refunded_quantity = 0;
        foreach ( $order_refunds as $refund ) {
            foreach ( $refund->get_items() as $item_id => $item ) {
                if ( $item->get_product_id() == $lineItem['product_id'] ) {
                    $refunded_quantity += abs( $item->get_quantity() );
                    // Get Refund Qty
                }
            }
        }
        $itemtotal = $lineItem['qty'] - $refunded_quantity;
        echo "&#8226; " . esc_html( $itemtotal ) . " x " . esc_html( $lineItem['name'] ) . "<br/>";
    }
    ?>
  </td>

<?php 
}

/**
 * Gets the detailed products summary section / tr
 *
 * @param array $orderinfo
 * @param array $order_refunds
 * @param int $cols
 *
 * @return mixed
 *
 */
add_action(
    'wcusage_hook_get_detailed_products_summary_tr',
    'wcusage_get_detailed_products_summary_tr',
    10,
    5
);
function wcusage_get_detailed_products_summary_tr(
    $orderinfo,
    $order_summary,
    $productcols,
    $tier = "",
    $postid = ""
) {
    if ( $order_summary && is_array( $order_summary ) ) {
        ksort( $order_summary );
    }
    $wcusage_show_commission_before_discount = wcusage_get_setting_value( 'wcusage_field_commission_before_discount', '0' );
    if ( $wcusage_show_commission_before_discount ) {
        $this_show_total_title = esc_html__( "Subtotal", "woo-coupon-usage" );
    } else {
        $this_show_total_title = esc_html__( "Total", "woo-coupon-usage" );
    }
    // Check if disable non affiliate commission
    $disable_commission = wcusage_coupon_disable_commission( $postid );
    ?>

  <tr class="wcuTableRow listtheproducts-summary-head">
    <td class='wcuTableHead-summary' colspan="<?php 
    echo esc_attr( $productcols );
    ?>">
      <?php 
    echo esc_html__( "Product", "woo-coupon-usage" );
    ?>
    </td>
    <td class='wcuTableHead-summary' colspan="1">
      <?php 
    echo esc_html__( "Quantity", "woo-coupon-usage" );
    ?>
    </td>
    <td class='wcuTableHead-summary' colspan="<?php 
    if ( !$disable_commission ) {
        ?>2<?php 
    } else {
        ?>4<?php 
    }
    ?>">
      <?php 
    echo esc_html( $this_show_total_title );
    ?>
    </td>
    <?php 
    if ( !$disable_commission ) {
        ?>
    <td class='wcuTableHead-summary' colspan="2">
      <?php 
        echo esc_html__( "Commission", "woo-coupon-usage" );
        ?>
    </td>
    <?php 
    }
    ?>
  </tr>

  <?php 
    if ( !empty( $order_summary ) ) {
        foreach ( $order_summary as $key => $value ) {
            $this_number = "-";
            $this_subtotal = "0.00";
            $this_total = "0.00";
            $this_discount = "0.00";
            $this_show_total = "0.00";
            if ( isset( $value['number'] ) ) {
                $this_number = $value['number'];
            }
            $the_commission = 0;
            if ( isset( $value['commission'] ) ) {
                $the_commission = $value['commission'];
            }
            $the_subtotal = 0;
            if ( isset( $value['subtotal'] ) ) {
                $the_subtotal = $value['subtotal'];
            }
            $the_total = 0;
            if ( isset( $value['total'] ) ) {
                $the_total = $value['total'];
            }
            $total_count = 0;
            if ( isset( $value['total_count'] ) ) {
                $total_count = $value['total_count'];
            }
            if ( $orderinfo ) {
                $the_commission = wcusage_convert_order_value_to_currency( $orderinfo, $the_commission );
                $the_subtotal = wcusage_convert_order_value_to_currency( $orderinfo, $the_subtotal );
                $the_total = wcusage_convert_order_value_to_currency( $orderinfo, $the_total );
            }
            if ( $tier ) {
                $the_commission = wcusage_mla_get_commission_from_tier( $the_commission, $tier );
            }
            $this_commission = wcusage_format_price( number_format(
                (float) $the_commission,
                2,
                '.',
                ''
            ) );
            if ( $wcusage_show_commission_before_discount ) {
                if ( isset( $the_subtotal ) ) {
                    $this_show_total = wcusage_format_price( number_format(
                        (float) $the_subtotal,
                        2,
                        '.',
                        ''
                    ) );
                }
            } else {
                if ( isset( $the_total ) ) {
                    $this_show_total = wcusage_format_price( number_format(
                        (float) $the_total,
                        2,
                        '.',
                        ''
                    ) );
                }
            }
            if ( is_numeric( $key ) ) {
                $product_title = get_the_title( $key );
            } else {
                $product_title = $key;
            }
            if ( $the_total > 0 ) {
                ?>
      <tr class="wcuTableRowDropdown">
        <td class='wcuTableCell' colspan="<?php 
                echo esc_attr( $productcols );
                ?>" style="padding: 0 !important;">
          <?php 
                echo esc_html( $product_title );
                ?> <a href="<?php 
                echo esc_url( get_permalink( $key ) );
                ?>" target="_blank" title="<?php 
                echo esc_html__( "View Product", "woo-coupon-usage" );
                ?>"><span class="fa-solid fa-arrow-up-right-from-square" style="font-size: 10px;"></span></a>
        </td>
        <td class='wcuTableCell' colspan="1" style="padding: 4px 10px !important;">
          <?php 
                echo esc_html( $this_number );
                ?>
        </td>
        <td class='wcuTableCell' colspan="<?php 
                if ( !$disable_commission ) {
                    ?>2<?php 
                } else {
                    ?>4<?php 
                }
                ?>" style="padding: 4px 10px !important;">
          <?php 
                echo wp_kses_post( $this_show_total );
                ?>
        </td>
        <?php 
                if ( !$disable_commission ) {
                    ?>
        <td class='wcuTableCell' colspan="2" style="padding: 4px 10px !important;">
          <?php 
                    echo wp_kses_post( $this_commission );
                    ?>
        </td>
        <?php 
                }
                ?>
      </tr>
      <?php 
            }
        }
    }
    ?>

  <tr style="height: 15px;"></tr>

<?php 
}
