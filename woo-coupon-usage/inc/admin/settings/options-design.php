<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_field_cb_design( $args )
{
    $options = get_option( 'wcusage_options' );
    ?>

	<div id="design-settings" class="settings-area">

	<h1><?php echo esc_html__( 'Design & Layout Customisation', 'woo-coupon-usage' ); ?></h1>

  <hr/>

  <?php $wcusage_field_show_tabs = wcusage_get_setting_value('wcusage_field_show_tabs', '1');
  if(!$wcusage_field_show_tabs) { ?>
  <!-- Enable "tabbed" layout - Discontinued but option hidden if turned off -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_show_tabs', 1, esc_html__( 'Enable "tabbed" layout (recommended).', 'woo-coupon-usage' ), '0px'); ?>
	<br/>
  <?php } ?>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Affiliate Dashboard Tabs', 'woo-coupon-usage' ); ?></h3>

  <!-- Tabs Style -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">
  <p>
    <?php $wcusage_field_tabs_style = wcusage_get_setting_value('wcusage_field_tabs_style', '2'); ?>
    <input type="hidden" value="0" id="wcusage_field_tabs_style" data-custom="custom" name="wcusage_options[wcusage_field_tabs_style]" >
    <strong><label for="scales"><?php echo esc_html__( 'Tabs Style', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <select name="wcusage_options[wcusage_field_tabs_style]" id="wcusage_field_tabs_style" class="wcusage_field_tabs_style">
      <option value="1" <?php if($wcusage_field_tabs_style == "1") { ?>selected<?php } ?>><?php echo esc_html__( 'Style #1 - Basic (Legacy)', 'woo-coupon-usage' ); ?></option>
      <option value="2" <?php if($wcusage_field_tabs_style == "2") { ?>selected<?php } ?>><?php echo esc_html__( 'Style #2 - Full Width (Modern)', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>
  </div>

  <!-- Border Radius -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">
  <p>
    <?php $wcusage_field_tabs_border = wcusage_get_setting_value('wcusage_field_tabs_border', '1'); ?>
    <input type="hidden" value="0" id="wcusage_field_tabs_border" data-custom="custom" name="wcusage_options[wcusage_field_tabs_border]" >
    <strong><label for="scales"><?php echo esc_html__( 'Tabs Border Radius', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <select name="wcusage_options[wcusage_field_tabs_border]" id="wcusage_field_tabs_border" class="wcusage_field_tabs_border">
      <option value="1" <?php if($wcusage_field_tabs_border == "1") { ?>selected<?php } ?>><?php echo esc_html__( 'Curved (5px)', 'woo-coupon-usage' ); ?></option>
      <option value="2" <?php if($wcusage_field_tabs_border == "2") { ?>selected<?php } ?>><?php echo esc_html__( 'Rounded (25px)', 'woo-coupon-usage' ); ?></option>
      <option value="3" <?php if($wcusage_field_tabs_border == "3") { ?>selected<?php } ?>><?php echo esc_html__( 'Square (0px)', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>
  </div>

  <!-- Padding -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">
  <p>
    <?php $wcusage_field_tabs_padding = wcusage_get_setting_value('wcusage_field_tabs_padding', '1'); ?>
    <input type="hidden" value="0" id="wcusage_field_tabs_padding" data-custom="custom" name="wcusage_options[wcusage_field_tabs_padding]" >
    <strong><label for="scales"><?php echo esc_html__( 'Tabs Size / Padding', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <select name="wcusage_options[wcusage_field_tabs_padding]" id="wcusage_field_tabs_padding" class="wcusage_field_tabs_padding">
      <option value="1" <?php if($wcusage_field_tabs_padding == "1") { ?>selected<?php } ?>><?php echo esc_html__( 'Small (8px)', 'woo-coupon-usage' ); ?></option>
      <option value="2" <?php if($wcusage_field_tabs_padding == "2") { ?>selected<?php } ?>><?php echo esc_html__( 'Medium (10px)', 'woo-coupon-usage' ); ?></option>
      <option value="3" <?php if($wcusage_field_tabs_padding == "3") { ?>selected<?php } ?>><?php echo esc_html__( 'Large (12px)', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>
  </div>

  <!-- Font Size -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">
  <p>
    <?php $wcusage_field_tabs_font_size = wcusage_get_setting_value('wcusage_field_tabs_font_size', ''); ?>
    <input type="hidden" value="0" id="wcusage_field_tabs_font_size" data-custom="custom" name="wcusage_options[wcusage_field_tabs_font_size]" >
    <strong><label for="scales"><?php echo esc_html__( 'Tabs Font Size', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <input type="number" name="wcusage_options[wcusage_field_tabs_font_size]" id="wcusage_field_tabs_font_size"
    value="<?php echo esc_html($wcusage_field_tabs_font_size); ?>" placeholder="Default"
    min="10" max="30"
    class="wcusage_field_tabs_font_size" />
  </p>
  </div>

  <div style="clear: both;"></div>

  <br/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Tab Colours', 'woo-coupon-usage' ); ?></h3>

  <!-- Tabs -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Tabs', 'woo-coupon-usage' ); ?></h3>

    <!-- Background -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_tab', '#1b3e47', esc_html__( 'Background', 'woo-coupon-usage' ), '0px'); ?>

    <!-- Text -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_tab_font', '#ffffff', esc_html__( 'Text', 'woo-coupon-usage' ), '0px'); ?>

  </div>

  <!-- Tabs Hover -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Tabs Hover', 'woo-coupon-usage' ); ?></h3>

    <!-- Background -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_tab_hover', '#005d75', esc_html__( 'Background', 'woo-coupon-usage' ), '0px'); ?>

    <!-- Text -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_tab_hover_font', '#ffffff', esc_html__( 'Text', 'woo-coupon-usage' ), '0px'); ?>

  </div>
  <div style="clear: both;"></div>

  <br/><hr/>

  <?php echo do_action('wcusage_hook_setting_section_colours'); ?>

	<br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Registration & Login Form', 'woo-coupon-usage' ); ?></h3>

  <p>
    <?php $wcusage_field_form_style = wcusage_get_setting_value('wcusage_field_form_style', '1'); ?>
    <input type="hidden" value="0" id="wcusage_field_form_style" data-custom="custom" name="wcusage_options[wcusage_field_form_style]" >
    <strong><label for="scales"><?php echo esc_html__( 'Form Style', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <select name="wcusage_options[wcusage_field_form_style]" id="wcusage_field_form_style" class="wcusage_field_form_style">
      <option value="1" <?php if($wcusage_field_form_style == "1") { ?>selected<?php } ?>><?php echo esc_html__( 'Style #1 - Default', 'woo-coupon-usage' ); ?></option>
      <option value="2" <?php if($wcusage_field_form_style == "2") { ?>selected<?php } ?>><?php echo esc_html__( 'Style #2 - Modern (Bold)', 'woo-coupon-usage' ); ?></option>
      <option value="3" <?php if($wcusage_field_form_style == "3") { ?>selected<?php } ?>><?php echo esc_html__( 'Style #3 - Modern (Compact)', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>

  <br/>

  <!-- Use the email address as username. -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_form_style_columns', 1, esc_html__( 'Enable 2 Column Layout', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'With this enabled, some of the fields on the form will be displayed in 2 columns, such as first and last name.', 'woo-coupon-usage' ); ?></i><br/>

  <br/>

  <!-- Form Title -->
  <?php echo wcusage_setting_text_option('wcusage_field_registration_form_title', '', esc_html__( 'Custom Registration Form Title', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'Default', 'woo-coupon-usage' ); ?>: <?php echo esc_html__( 'Register New Affiliate Account', 'woo-coupon-usage' ); ?></i><br/>

  <br/>

  <!-- Submit button text field label -->
  <?php echo wcusage_setting_text_option('wcusage_field_registration_submit_button_text', '', esc_html__( 'Custom Submit Button Text', 'woo-coupon-usage' ), '0px'); ?>
  <i><?php echo esc_html__( 'Default', 'woo-coupon-usage' ); ?>: <?php echo esc_html__( 'Submit Application', 'woo-coupon-usage' ); ?></i><br/>

	<br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Mobile Menu', 'woo-coupon-usage' ); ?></h3>

  <p>
    <?php $wcusage_field_mobile_menu = wcusage_get_setting_value('wcusage_field_mobile_menu', 'dropdown'); ?>
    <input type="hidden" value="0" id="wcusage_field_mobile_menu" data-custom="custom" name="wcusage_options[wcusage_field_mobile_menu]" >
    <strong><label for="scales"><?php echo esc_html__( 'Mobile Menu Style', 'woo-coupon-usage' ); ?>:</label></strong><br/>
    <select name="wcusage_options[wcusage_field_mobile_menu]" id="wcusage_field_mobile_menu" class="wcusage_field_mobile_menu">
      <option value="dropdown" <?php if($wcusage_field_mobile_menu == "dropdown") { ?>selected<?php } ?>><?php echo esc_html__( 'Dropdown', 'woo-coupon-usage' ); ?></option>
      <option value="tabs" <?php if($wcusage_field_mobile_menu == "tabs") { ?>selected<?php } ?>><?php echo esc_html__( 'Tabs', 'woo-coupon-usage' ); ?></option>
    </select>
  </p>

	</div>

 <?php
}

/**
 * Settings Section: Colours
 *
 */
add_action( 'wcusage_hook_setting_section_colours', 'wcusage_setting_section_colours', 10, 1 );
if( !function_exists( 'wcusage_setting_section_colours' ) ) {
  function wcusage_setting_section_colours() {

  $options = get_option( 'wcusage_options' );
  ?>

  <style>
  .wcusage-settings-style-colors {
      width: calc(50% - 20px);
      max-width: 290px;
      float: left;
      margin-right: 20px;
      margin-bottom: 40px;
  }
  .wcusage-settings-style-colors h3 {
    margin-top: 0;
    margin-bottom: 10px;
  }
  </style>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Other Colours', 'woo-coupon-usage' ); ?></h3>

  <!-- Table Header & Footer -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Table Header & Footer', 'woo-coupon-usage' ); ?></h3>

    <!-- Background -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_table', '#f4f4f4', esc_html__( 'Background', 'woo-coupon-usage' ), '0px'); ?>

    <!-- Text -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_table_font', '#0a0a0a', esc_html__( 'Text', 'woo-coupon-usage' ), '0px'); ?>

  </div>

  <!-- Buttons -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Buttons', 'woo-coupon-usage' ); ?></h3>

    <!-- Background -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_button', '#005d75', esc_html__( 'Background', 'woo-coupon-usage' ), '0px'); ?>

    <!-- Text -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_button_font', '#ffffff', esc_html__( 'Text', 'woo-coupon-usage' ), '0px'); ?>

  </div>

  <!-- Buttons Hover -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Buttons Hover', 'woo-coupon-usage' ); ?></h3>

    <!-- Background -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_button_hover', '#1b3e47', esc_html__( 'Background', 'woo-coupon-usage' ), '0px'); ?>

    <!-- Text -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_button_font_hover', '#ffffff', esc_html__( 'Text', 'woo-coupon-usage' ), '0px'); ?>

  </div>

  <div style="clear: both;"></div>

  <br/><hr/>

  <h3><span class="dashicons dashicons-admin-generic" style="margin-top: 2px;"></span> <?php echo esc_html__( 'Icons', 'woo-coupon-usage' ); ?></h3>

  <!-- Show icons on affiliate dashboard tabs -->
  <?php echo wcusage_setting_toggle_option('wcusage_field_show_tabs_icons', 1, esc_html__( 'Show icons on the affiliate dashboard tabs.', 'woo-coupon-usage' ), '0px'); ?>

  <div style="clear: both;"></div>

  <br/>

  <!-- Stats Icons -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <h3><?php echo esc_html__( 'Icon Colour', 'woo-coupon-usage' ); ?></h3>

    <!-- Main -->
    <?php echo wcusage_setting_color_option('wcusage_field_color_stats_icon', '#bebebe', '', '0px'); ?>

  </div>

  <div style="clear: both;"></div>

  <?php if( wcu_fs()->can_use_premium_code() ) { ?>
  <br/>
  <!-- Line Graph -->
  <div class="wcusage-settings-style-colors" style="margin-bottom: 0;">

    <span <?php if( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.4 !important; display: block; pointer-events: none;" class="wcu-settings-pro-only"<?php } ?>>

      <h3><?php echo esc_html__( 'Line Graph', 'woo-coupon-usage' ); ?> (PRO)</h3>

      <!-- Main -->
      <?php echo wcusage_setting_color_option('wcusage_field_color_line_graph', '#008000', '', '0px'); ?>

    </span>

  </div>
  <div style="clear: both;"></div>
  <?php } ?>

  <?php
  }
}
