<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_get_options_update_scripts($ids, $action, $val, $gettype) {

  if ( current_user_can( 'manage_options' ) ) {
  ?>

    <script>
    function wcusettingsdelay(callback, ms) {
      var timer = 0;
      return function() {
        var context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function () {
          callback.apply(context, args);
        }, ms || 0);
      };
    }
    jQuery( document ).ready(function() {
      <?php if($gettype == "id") { ?>
        jQuery("<?php echo esc_html($ids); ?>").change(wcusettingsdelay(function (e) {
          var checktype = jQuery(this).attr('checktype');
          if(checktype != "ignore") {
            if(checktype != "multi") {
              wcu_ajax_update_the_options(jQuery(this), '<?php echo esc_html($gettype); ?>', '<?php echo esc_html($action); ?>', '<?php echo esc_html($val); ?>', '', '<?php echo esc_html($ids); ?>');
            } else {
              var key = jQuery(this).attr('checktypekey');
              wcu_ajax_update_the_options(jQuery(this), 'class', 'wcu-update-toggle', 1, key, '<?php echo esc_html($ids); ?>');
            }
          }
        }, 100));
      <?php } ?>
    });
    </script>

  <?php
  }

}

// Ajax Script
function wcusage_admin_options_update_scripts($hook_suffix) {

  if ( current_user_can( 'manage_options' ) ) {

    $screen = get_current_screen();
    if ($screen->id == 'coupon-affiliates_page_wcusage_settings') {
    ?>

      <script>
      function wcu_ajax_update_the_options(thisObj, type, action, val, thekey, ids) {

        var myVal = jQuery('.wcusage_field_settings_legacy').is(':checked');
        if(!thekey) { var thekey = ""; }

        if(!myVal) {

          var checktype = jQuery(thisObj).attr('checktype');

          if(type == "id") {
            var myClass = jQuery(thisObj).attr(type);
            var thetype = jQuery(thisObj).attr('type');
            if(ids == ":checkbox") {
              if(thetype == "checkbox") {
                var myVal = jQuery(thisObj).is(':checked');
              }
            } else {
              var myVal = jQuery(thisObj).val();
            }
          }
          if(type == "data-id") {
            var checktype = jQuery("#" + thisObj).attr('checktype');
            var checktype2 = jQuery("#" + thisObj).attr('checktype2');
            var myVal = tinyMCE.get(thisObj).getContent({format : 'raw'});
            if(checktype2 == "tinymce") {
              var myClass = jQuery("#" + thisObj).attr('customid');
            } else {
              var myClass = thisObj;
            }
          }

          if(type == "class") {
            var myClass = jQuery(thisObj).attr(type);
            var myVal = jQuery(thisObj).is(':checked');
            myVal = (myVal ? 1 : 0);
          }
          var customid = jQuery(thisObj).attr('customid');
          if(customid) {
            var myClass = jQuery(thisObj).attr('customid');
          }

          if(checktype == "multi") {
            myMulti = 1;
          } else {
            myMulti = 0;
          }

          if(checktype == "customnumber") {
            myCustomNumber = 1;
            if(checktype2 == "tinymce") {
              myCustomNumber1 = jQuery("#" + thisObj).attr('custom1');
              myCustomNumber2 = jQuery("#" + thisObj).attr('custom2');
            } else {
              myCustomNumber1 = jQuery(thisObj).attr('custom1');
              myCustomNumber2 = jQuery(thisObj).attr('custom2');
            }
          } else {
            myCustomNumber = 0;
            myCustomNumber1 = "";
            myCustomNumber2 = "";
          }

          var elementType = jQuery("#" + myClass).prop('nodeName');
          jQuery("input, textarea, select, password, .switch").css("pointer-events","none");
          jQuery(".wcusage-settings-form label").css('cursor','wait');
          jQuery(document.body).css({'cursor' : 'wait'});
          
          jQuery("#" + myClass).before("<p id='wcu-update-small-text-load-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-sync-alt fa-spin'></i></p>" );
          jQuery(".wcu-addons-box ." + myClass).before("<p id='wcu-update-small-text-load2-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-sync-alt fa-spin'></i></p>" );

          jQuery("#wcu-update-text-" + myClass).remove();
          jQuery("#" + myClass + "_p").after( "<p id='wcu-update-text-"+ myClass +"' class='wcu-update-text'>Updating option...</p>" );

          jQuery("#wcu-update-text2-" + myClass).remove();
          jQuery(".wcu-addons-box ." + myClass).after( "<p id='wcu-update-text2-"+ myClass +"' class='wcu-update-text'>Updating option...</p>" );

          jQuery.ajax({
              type:   'POST',
              url:    '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
              data:   {
                  _ajax_nonce: '<?php echo esc_html(wp_create_nonce( 'wcusage_dashboard_settings_ajax_nonce' )); ?>',
                  action    : action,
                  option    : myClass,
                  value     : myVal,
                  multi     : myMulti,
                  key       : thekey,
                  customnum : myCustomNumber,
                  customnum1 : myCustomNumber1,
                  customnum2 : myCustomNumber2
              },
              dataType: 'json'
          }).done(function( json ) {
              if( json.success ) {

                jQuery(".wcu-update-text").remove();

                jQuery("#" + myClass + "_p").after( "<p id='wcu-update-text-"+ myClass +"' class='wcu-update-text'>Successfully updated!</p>" );
                jQuery(".wcu-addons-box ." + myClass).after( "<p id='wcu-update-text2-"+ myClass +"' class='wcu-update-text'>Successfully updated!</p>" );

                jQuery(".wcu-update-icon").remove();
                jQuery("#" + myClass).before("<p id='wcu-update-small-text-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-check-circle'></i></p>" );
                setTimeout( function(){
                    jQuery(".wcu-update-text").remove();
                    jQuery("#wcu-update-small-text-" + myClass).remove();
                }, 250 );

                var settingsupdate = parseInt(jQuery("#wcu-number-settings-saved").text());
                var settingsupdatenew = settingsupdate + 1;
                jQuery("#wcu-number-settings-saved-message").show();
                jQuery("#wcu-number-settings-save-toggle").show();
                jQuery('.wcu-field-section-save').hide();
                jQuery("#wcu-number-settings-saved").text(settingsupdatenew);

                jQuery("input, textarea, select, password, .switch").css("pointer-events","auto");
                jQuery(".wcusage-settings-form label").css('cursor','default');
                jQuery(document.body).css({'cursor' : 'default'});

              } else if( !json.success ) { }
          }).fail(function() {
              alert( "Failed to update. Please try again." );
          });

        }

      }
      </script>

      <?php echo wcusage_get_options_update_scripts("textarea, input[type=text], input[type=number], input[type=password], input[type=radio], input[type=color], select", "wcu-update-text", 1, "id"); ?>

      <?php echo wcusage_get_options_update_scripts("textarea", "wcu-update-text", 1, "data-id"); ?>

      <?php echo wcusage_get_options_update_scripts(":checkbox", "wcu-update-toggle", 0, "id"); ?>

    <?php
    }

  }

}
add_action('admin_head', 'wcusage_admin_options_update_scripts');

/***************
***** UPDATE: Text Input
***************/
add_action( 'wp_ajax_wcu-update-text', 'wcu_update_text' );
function wcu_update_text() {

  if ( current_user_can( 'manage_options' ) ) {

    check_ajax_referer('wcusage_dashboard_settings_ajax_nonce'); // Check nonce

    $option = sanitize_text_field( $_POST['option'] );
    $value = sanitize_textarea_field( htmlentities( $_POST['value'] ) );
    $CustomNum = sanitize_text_field( $_POST['customnum'] );
    $CustomNum1 = sanitize_text_field( $_POST['customnum1'] );
    $CustomNum2 = sanitize_text_field( $_POST['customnum2'] );

    if( !isset( $option ) || $option == '' ) {
        die(
            json_encode(
                array(
                    'success' => false,
                    'message' => 'Missing required information.'
                )
            )
        );
    }

    $option_group = get_option('wcusage_options');

    if($CustomNum) {

      $value = html_entity_decode(stripslashes($value));

      if(!is_array($option_group[$option])) {
        $option_group[$option] = array();
      }
      $option_group[$option][$CustomNum1][$CustomNum2] = $value;
      update_option('wcusage_options', $option_group);

    } else {

      $value = html_entity_decode(stripslashes($value));
      $option_group[$option] = $value;
      update_option( 'wcusage_options', $option_group );

    }

    wcusage_check_if_option_refresh_stats($option); // Refresh Stats?

    die(
        json_encode(
            array(
                'success' => true,
                //'message' => 'Database updated successfully to: ' . $option
            )
        )
    );

  }

}

/***************
***** UPDATE: Toggles
***************/
add_action( 'wp_ajax_wcu-update-toggle', 'wcu_update_toggle' );
function wcu_update_toggle() {

  if ( current_user_can( 'manage_options' ) ) {

    check_ajax_referer('wcusage_dashboard_settings_ajax_nonce'); // Check nonce

    $option = sanitize_text_field( $_POST['option'] );
    $multi = sanitize_text_field( $_POST['multi'] );
    $value = sanitize_text_field( $_POST['value'] );
    $key = sanitize_text_field( $_POST['key'] );

    if( !isset( $option ) || $option == '' ) {
        die(
            json_encode(
                array(
                    'success' => false,
                    'message' => 'Missing required information.'
                )
            )
        );
    }

    $option_group = get_option('wcusage_options');

    if($multi) {
      $order_type_custom = $option_group[$option];
      $order_type_custom_current = $option_group[$option];
      if(!is_array($order_type_custom)) {
        $order_type_custom = array();
        if($order_type_custom_current) {
          $order_type_custom = array($order_type_custom_current => "on");
        }
      }
      if($value) {
        $order_type_custom[$key] = "on";
      } else {
        unset($order_type_custom[$key]);
      }
      $option_group[$option] = $order_type_custom;
    } else {
      if($value == true) {
        $thevalue = "1";
      }
      if($value == "false") {
        $thevalue = "0";
      }
      $option_group[$option] = $thevalue;
    }

    update_option( 'wcusage_options', $option_group );

    wcusage_check_if_option_refresh_stats($option); // Refresh Stats?

    die(
        json_encode(
            array(
                'success' => true,
                //'message' => 'Database updated successfully to: ' . $option . " - " . $new
            )
        )
    );

  }

}

// Ajax: Refresh Stats for certain updates updating
function wcusage_check_if_option_refresh_stats($option) {
  $options_to_refresh = wcusage_options_refresh_stats();
  if(in_array($option, $options_to_refresh)) {
    $option_group = get_option('wcusage_options');
    $option_group['wcusage_refresh_date'] = time();
    update_option( 'wcusage_options', $option_group );
  }
}

// Post: Refresh Stats for certain updates updating
add_action('updated_option', 'wcusage_check_if_option_refresh_stats_post', 10, 3);
function wcusage_check_if_option_refresh_stats_post($option_name, $old_value, $value) {
    $never_update_commission_meta = wcusage_get_setting_value('wcusage_field_enable_never_update_commission_meta', '0');
    if (!$never_update_commission_meta && 'wcusage_options' == $option_name) {
        $options_to_refresh = wcusage_options_refresh_stats();
        foreach ($options_to_refresh as $key_interest) {
            if(isset($old_value[$key_interest]) && isset($value[$key_interest])) {
              if ($old_value[$key_interest] == $value[$key_interest]) {
                continue;
              }
              $option_group = get_option('wcusage_options');
              $option_group['wcusage_refresh_date'] = time();
              update_option( 'wcusage_options', $option_group );
              break;
            }
        }
    }
}

// Options to refresh stats on
function wcusage_options_refresh_stats() {
  $options_to_refresh = array(
    "wcusage_field_affiliate",
    "wcusage_field_affiliate_fixed_order",
    "wcusage_field_affiliate_fixed_product",
    "wcusage_field_commission_before_discount",
    "wcusage_field_commission_include_shipping",
    "wcusage_field_commission_before_discount_custom",
    "wcusage_field_commission_include_fees",
    "wcusage_field_order_max_commission",
    "wcusage_field_show_tax",
    "wcusage_field_affiliate_deduct_percent",
    "wcusage_field_priority_commission",
    "wcusage_field_affiliate_deduct_percent_show",
    "wcusage_field_order_type_custom",
    "wcusage_field_order_sort"
  );
  return $options_to_refresh;
}