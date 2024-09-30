<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_field_cb_custom_tabs( $args )
{
    $options = get_option( 'wcusage_options' );
    ?>

	<div id="custom-tabs-settings" class="settings-area" <?php
    if ( !wcu_fs()->can_use_premium_code() ) {
        ?>title="Available with Pro version." style="pointer-events:none; opacity: 0.6;"<?php
    }
    ?>>

	<?php
    if ( !wcu_fs()->can_use_premium_code() ) {
        ?><p><strong style="color: green;"><?php echo esc_html__( 'Available with Pro version.', 'woo-coupon-usage' ); ?></strong></p><?php
    }
    ?>

	<h1><?php echo esc_html__( 'Custom Affiliate Dashboard Tabs', 'woo-coupon-usage' ); ?> (Pro)</h1>

  <hr/>

    <p><?php echo esc_html__( 'In this section, you can create your own custom tabs to show on the affiliate dashboard. Shortcode usage supported.', 'woo-coupon-usage' ); ?></p>

  <br/><hr/>

  <?php $wcusage_field_custom_tabs = wcusage_get_setting_value('wcusage_field_custom_tabs', '');
  ?>

  <!-- Number of custom tabs -->
  <?php $tabsnumber = wcusage_get_setting_value('wcusage_field_custom_tabs_number', '2'); ?>
  <?php echo wcusage_setting_number_option('wcusage_field_custom_tabs_number', $tabsnumber, esc_html__( 'Number of custom tabs', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'Please refresh the page to add/remove the new tab settings (found below) when you update this number.', 'woo-coupon-usage' ); ?></i><br/>

 <br/><hr/>

  <?php
  // Loop through custom tabs
  for ($i = 1; $i <= $tabsnumber; $i++) {
    echo '<h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> Custom Tab #' . esc_html($i) . '</h3>';
    if(isset($options['wcusage_field_custom_tabs'][$i]['name'])) {
        $wcusage_field_custom_tab = $options['wcusage_field_custom_tabs'][$i]['name'];
    } else {
        $wcusage_field_custom_tab = "";
    }
    if(isset($options['wcusage_field_custom_tabs'][$i]['header'])) {
        $wcusage_field_custom_tab_header = $options['wcusage_field_custom_tabs'][$i]['header'];
    } else {
        $wcusage_field_custom_tab_header = "";
    }
    if(isset($options['wcusage_field_custom_tabs'][$i]['content'])) {
        $wcusage_field_custom_tabs_content = $options['wcusage_field_custom_tabs'][$i]['content'];
    } else {
        $wcusage_field_custom_tabs_content = "";
    }
    echo ' <div class="input_fields_wrap"></div>';

    echo '<strong>Tab Name:</strong><br/>';
    echo '<input type="text" id="wcusage_field_custom_tabs" checktype="customnumber" custom1="'.esc_attr($i).'" custom2="name" name="wcusage_options[wcusage_field_custom_tabs]['.esc_attr($i).'][name]" value="'.$wcusage_field_custom_tab.'">';
    echo '<br/><i>' . esc_html__('The name of the tab button.', 'woo-coupon-usage') . '</i>';

    echo '<br/><br/>';

    echo '<strong>Tab Header:</strong><br/>';
    echo '<input type="text" id="wcusage_field_custom_tabs" checktype="customnumber" custom1="'.esc_attr($i).'" custom2="header" name="wcusage_options[wcusage_field_custom_tabs]['.esc_attr($i).'][header]" value="'.$wcusage_field_custom_tab_header.'">';
    echo '<br/><i>' . esc_html__('The header text displayed at the top of the tab content.', 'woo-coupon-usage') . '</i>';
    ?>

    <br/><br/>

    <!-- Select a user role: multi select -->
    <p class="creative-type-user-role">
      <label for="user_role"><strong><?php echo esc_html__('Limit to certain user roles & groups?', 'woo-coupon-usage'); ?></strong>
    </label>

      <br/>

      <!-- User Role Select -->
      <span class="payouts-role-select-wrapper">

        <span style="height: 50px; width: 250px; overflow-y: auto;
        display: block; border: 1px solid #ddd; padding: 10px;">

        <?php
        $thisid = 'wcusage_field_custom_tabs_roles_'.$i;
        $roles = get_editable_roles();
        // Re-order with all those containing "coupon_affiliate" at the start
        $roles2 = array();
        foreach ($roles as $key => $role) {
          if (strpos($key, 'coupon_affiliate') !== false) {
            $roles2[$key] = $role;
            unset($roles[$key]);
          }
        }
        $roles2 = array_merge($roles2, $roles);
        foreach ($roles2 as $key => $role) {

          $role_name = $role['name'];
          if (strpos($key, 'coupon_affiliate') !== false) {
            $role_name = '(Group) '.$role_name;
          }
          $checked = '';
          if(isset($options[$thisid][$key])) {
            $checked = 'checked';
          }
          
          echo '<span id="'.esc_attr($thisid).'">
          <input type="checkbox" checktype="multi"
          checktypekey="'.esc_attr($key).'"
          customid="'.esc_attr($thisid).'"
          name="wcusage_options['.esc_attr($thisid).']['.esc_attr($key).']"
          '.esc_attr($checked).'> '.esc_attr($role_name).'</span><br/>';

        }
        ?>

        </span>

      </span>

      <i><?php echo esc_html__('The tab will only be visible to affiliates with any of the selected user roles.', 'woo-coupon-usage'); ?></i>
      <br/>
      <i><?php echo esc_html__('If no roles are selected, the tab will be visible to all affiliates.', 'woo-coupon-usage'); ?></i>

    </p>

    <?php
    echo '<br/><br/>';
    $settingstabscontent = array(
        'wpautop' => true,
        'media_buttons' => true,
        'textarea_name' => 'wcusage_options[wcusage_field_custom_tabs]['.esc_attr($i).'][content]',
        'editor_height' => 300,
        'textarea_rows' => 5,
        'editor_class' => 'wcusage_field_cb_custom_tabs_content',
        'tinymce' => true,
    );
    echo wcusage_tinymce_ajax_script('wcusage_field_custom_tabs_content_' . esc_html($i));
    wp_editor( $wcusage_field_custom_tabs_content, 'wcusage_field_custom_tabs_content_' . esc_html($i), $settingstabscontent );
    echo '<br/><hr/>';
    ?>
    <script type="text/javascript">
       jQuery(document).ready(function(){
          jQuery('#wcusage_field_custom_tabs_content_<?php echo esc_html($i); ?>').attr('checktype','customnumber');
          jQuery('#wcusage_field_custom_tabs_content_<?php echo esc_html($i); ?>').attr('checktype2','tinymce');
          jQuery('#wcusage_field_custom_tabs_content_<?php echo esc_html($i); ?>').attr('custom1','<?php echo esc_html($i); ?>');
          jQuery('#wcusage_field_custom_tabs_content_<?php echo esc_html($i); ?>').attr('custom2','content');
          jQuery('#wcusage_field_custom_tabs_content_<?php echo esc_html($i); ?>').attr('customid','wcusage_field_custom_tabs');
       });
    </script>
    <?php
  }
  ?>

	</div>

 <?php
}
