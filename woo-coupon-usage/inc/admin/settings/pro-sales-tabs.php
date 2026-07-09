<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PRO sales tabs (free version only).
 *
 * In the free version the real PRO settings are hidden and disabled. Instead,
 * a set of conversion-focused "sales" tabs are shown in the settings sidebar
 * (just below the "PRO Modules" tab). Each tab presents the benefits of a group
 * of PRO features with a clear header, description, benefit cards, and a CTA.
 *
 * These tabs are only rendered when the visitor cannot use premium code
 * (i.e. the free build, or an unlicensed Pro build). When a valid Pro license
 * is active, the normal settings tabs are shown instead.
 */

/**
 * Whether the PRO sales tabs should be displayed.
 *
 * @return bool
 */
function wcusage_show_pro_sales_tabs() {
  return ( ! wcu_fs()->can_use_premium_code() );
}

/**
 * Definition of every PRO sales tab and the benefits it promotes.
 *
 * @return array
 */
function wcusage_pro_sales_tabs() {

  return array(

    array(
      'id'       => 'tab-pro-payouts',
      'row'      => 'wcusage_row_pro_payouts',
      'label'    => __( 'Payouts', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-handshake',
      'title'    => __( 'Pay your affiliates the easy way', 'woo-coupon-usage' ),
      'subtitle' => __( 'Automatically track unpaid commission and pay affiliates in one click - via PayPal, Stripe, Wise or store credit - and let them request payouts once they hit your threshold. Or take it one step further with fully automated payouts.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-dollar-sign',          __( 'Unpaid Commission Tracking', 'woo-coupon-usage' ),   __( 'Automatically log unpaid commission to each affiliate\'s balance as orders complete, ready for payout.', 'woo-coupon-usage' ) ),
        array( 'fas fa-hand-holding-usd',      __( 'Payout Requests', 'woo-coupon-usage' ),              __( 'Affiliates pick a payment method and request payouts for their unpaid commission when they meet your threshold.', 'woo-coupon-usage' ) ),
        array( 'fab fa-paypal',                __( 'One-Click PayPal Payouts', 'woo-coupon-usage' ),     __( 'Pay affiliates instantly, straight from your PayPal account, with a single click.', 'woo-coupon-usage' ) ),
        array( 'fab fa-stripe-s',              __( 'One-Click Stripe Payouts', 'woo-coupon-usage' ),     __( 'Send commission directly from your Stripe balance — no manual transfers required.', 'woo-coupon-usage' ) ),
        array( 'fas fa-university',            __( 'Wise Bank Transfers', 'woo-coupon-usage' ),          __( 'Let affiliates get paid by Wise bank transfer in their local currency.', 'woo-coupon-usage' ) ),
        array( 'far fa-credit-card',           __( 'Store Credit Payouts', 'woo-coupon-usage' ),         __( 'Pay commission as store credit that affiliates can spend at your checkout.', 'woo-coupon-usage' ) ),
        array( 'fas fa-hourglass-start',       __( 'Scheduled & Delayed Payouts', 'woo-coupon-usage' ),  __( 'Auto-submit payout requests on a schedule, and delay commission by X days to cover refunds.', 'woo-coupon-usage' ) ),
        array( 'fas fa-file-invoice',          __( 'PDF Statements & Invoices', 'woo-coupon-usage' ),    __( 'Generate PDF statements automatically and let affiliates upload invoices when they request a payout.', 'woo-coupon-usage' ) ),
        array( 'fas fa-bolt',                  __( 'Automatic Payouts', 'woo-coupon-usage' ),            __( 'Optionally pay affiliates instantly, the moment they submit a payout request.', 'woo-coupon-usage' ) ),
        array( 'fas fa-list-check',            __( 'Payout Status Tracking', 'woo-coupon-usage' ),       __( 'Track every payout as pending, paid or cancelled, with a full history you can export to CSV.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-creatives',
      'row'      => 'wcusage_row_pro_creatives',
      'label'    => __( 'Creatives', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-images',
      'title'    => __( 'Give affiliates marketing assets that convert', 'woo-coupon-usage' ),
      'subtitle' => __( 'Provide ready-made banners, videos and graphics - or automatically generate a personalised creative for every single affiliate.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-images',  __( 'Creatives Library', 'woo-coupon-usage' ),     __( 'Share downloadable banners, videos, PDFs and brand colours from one central place.', 'woo-coupon-usage' ) ),
        array( 'fas fa-code',    __( 'One-Click Embed Codes', 'woo-coupon-usage' ), __( 'Affiliates copy a ready-made embed code to drop your banners straight onto their site.', 'woo-coupon-usage' ) ),
        array( 'fas fa-magic',       __( 'Dynamic Creatives', 'woo-coupon-usage' ),       __( 'Auto-generate a unique, personalised banner for each affiliate using merge tags like their coupon code.', 'woo-coupon-usage' ) ),
        array( 'fas fa-palette',     __( 'Brand Colours & Assets', 'woo-coupon-usage' ),  __( 'Keep affiliates on-brand with shared colour swatches and ready-to-use asset packs.', 'woo-coupon-usage' ) ),
        array( 'fas fa-photo-film',  __( 'Banners, Videos & PDFs', 'woo-coupon-usage' ),  __( 'Share any asset type — image banners, promotional videos and downloadable PDFs.', 'woo-coupon-usage' ) ),
        array( 'fas fa-link',        __( 'Built-In Referral Links', 'woo-coupon-usage' ), __( 'Every creative automatically includes the affiliate\'s own referral link or coupon.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-bonuses',
      'row'      => 'wcusage_row_pro_bonuses',
      'label'    => __( 'Bonuses & Incentives', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-gift',
      'title'    => __( 'Motivate your affiliates to sell more', 'woo-coupon-usage' ),
      'subtitle' => __( 'Reward top performers with bonuses, lifetime commission and leaderboards that drive friendly competition and long-term loyalty.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-trophy',    __( 'Performance Bonuses', 'woo-coupon-usage' ), __( 'Award bonus commission when affiliates reach the sales goals you set.', 'woo-coupon-usage' ) ),
        array( 'fas fa-user-plus', __( 'New Customer Bonus', 'woo-coupon-usage' ),  __( 'Pay an extra reward whenever an affiliate brings in a brand-new customer.', 'woo-coupon-usage' ) ),
        array( 'fas fa-handshake', __( 'Lifetime Commission', 'woo-coupon-usage' ),        __( 'Give affiliates commission on every future order from the customers they referred.', 'woo-coupon-usage' ) ),
        array( 'fas fa-ranking-star', __( 'Leaderboards', 'woo-coupon-usage' ),            __( 'Display affiliate rankings on your site to spark competition and boost engagement.', 'woo-coupon-usage' ) ),
        array( 'fas fa-coins',     __( 'Store Credit & Custom Rewards', 'woo-coupon-usage' ), __( 'Reward affiliates with bonus commission, store credit or other custom reward types.', 'woo-coupon-usage' ) ),
        array( 'fas fa-bullseye',  __( 'Goal-Based Triggers', 'woo-coupon-usage' ),         __( 'Set the exact sales or referral targets an affiliate must hit to unlock each reward.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-mla',
      'row'      => 'wcusage_row_pro_mla',
      'label'    => __( 'Multi-Level Affiliates', 'woo-coupon-usage' ),
      'icon'     => 'fa-solid fa-users',
      'title'    => __( 'Grow your program with multi-level affiliates', 'woo-coupon-usage' ),
      'subtitle' => __( 'Let affiliates recruit their own network and earn extra commission from everyone they bring on board - turning your best affiliates into a growth engine.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-layer-group', __( 'Unlimited Multi-Level Tiers', 'woo-coupon-usage' ), __( 'Build unlimited levels of affiliate hierarchy, each with its own custom commission rate.', 'woo-coupon-usage' ) ),
        array( 'fas fa-percent',     __( 'Override Commission', 'woo-coupon-usage' ),         __( 'Super-affiliates earn additional commission from every sale their recruits make.', 'woo-coupon-usage' ) ),
        array( 'fas fa-user-plus',   __( 'Email Invitation System', 'woo-coupon-usage' ),     __( 'Affiliates recruit their own sub-affiliates with simple email invitations.', 'woo-coupon-usage' ) ),
        array( 'fas fa-gauge-high',  __( 'MLA Dashboard', 'woo-coupon-usage' ),               __( 'Parent affiliates get their own dashboard to manage and track their whole network.', 'woo-coupon-usage' ) ),
        array( 'fas fa-sitemap',     __( 'Network Tree Diagram', 'woo-coupon-usage' ),        __( 'A visual tree shows each affiliate\'s full downline and structure at a glance.', 'woo-coupon-usage' ) ),
        array( 'fas fa-users',       __( 'Self-Building Program', 'woo-coupon-usage' ),       __( 'Your best affiliates grow the program for you by recruiting new members.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-reports',
      'row'      => 'wcusage_row_pro_reports',
      'label'    => __( 'Reports & Notifications', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-chart-line',
      'title'    => __( 'Know exactly how your program performs', 'woo-coupon-usage' ),
      'subtitle' => __( 'Unlock advanced reporting, automated affiliate email reports, and SMS &amp; email notifications to keep both you and your affiliates informed.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-chart-bar',     __( 'Advanced Admin Reports', 'woo-coupon-usage' ),  __( 'Unlimited date ranges, period comparisons and export-to-Excel on your admin reports.', 'woo-coupon-usage' ) ),
        array( 'far fa-file-pdf',      __( 'Affiliate Email Reports', 'woo-coupon-usage' ), __( 'Automatically email affiliates a weekly or monthly PDF summary of their stats.', 'woo-coupon-usage' ) ),
        array( 'fas fa-chart-line',    __( 'Commission Line Graphs', 'woo-coupon-usage' ),  __( 'Visualise commission and referral trends right on the affiliate dashboard.', 'woo-coupon-usage' ) ),
        array( 'far fa-calendar-alt',  __( 'Monthly Summary Table', 'woo-coupon-usage' ),   __( 'Show a month-by-month breakdown of sales and commission per coupon.', 'woo-coupon-usage' ) ),
        array( 'far fa-file-excel',    __( 'Export to Excel', 'woo-coupon-usage' ),         __( 'One-click export buttons for the monthly summary and recent orders tables.', 'woo-coupon-usage' ) ),
        array( 'fas fa-comment-sms',        __( 'SMS Notifications', 'woo-coupon-usage' ),         __( 'Text affiliates when they earn commission or receive a payout, powered by Twilio.', 'woo-coupon-usage' ) ),
        array( 'far fa-newspaper',          __( 'Email Newsletters', 'woo-coupon-usage' ),         __( 'Send broadcast newsletters to all affiliates with progress tracking and placeholders.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-referrals',
      'row'      => 'wcusage_row_pro_referrals',
      'label'    => __( 'Referral Tools', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-link',
      'title'    => __( 'More powerful referral features', 'woo-coupon-usage' ),
      'subtitle' => __( 'Go beyond simple coupon codes and referral URLs, with campaigns, direct link tracking, landing pages, QR codes and welcome popups.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-bullhorn',      __( 'Campaigns', 'woo-coupon-usage' ),             __( 'Affiliates create campaigns and unique URLs to track clicks and conversions.', 'woo-coupon-usage' ) ),
        array( 'fas fa-link',          __( 'Direct Link Tracking', 'woo-coupon-usage' ),  __( 'Affiliates link to your site from their own domain — no affiliate link required.', 'woo-coupon-usage' ) ),
        array( 'fas fa-share-alt',     __( 'Social Sharing', 'woo-coupon-usage' ),        __( 'One-tap social share buttons for affiliates\' generated referral links.', 'woo-coupon-usage' ) ),
        array( 'fas fa-qrcode',        __( 'Short URLs &amp; QR Codes', 'woo-coupon-usage' ), __( 'Generate tidy short links and scannable QR codes for any referral URL.', 'woo-coupon-usage' ) ),
        array( 'fas fa-laptop-code',   __( 'Landing Pages', 'woo-coupon-usage' ),           __( 'Link landing pages to a coupon so they work just like a referral URL.', 'woo-coupon-usage' ) ),
        array( 'fas fa-comment-dots',  __( 'Referral Welcome Popups', 'woo-coupon-usage' ),  __( 'Greet referred visitors with popups or bars that highlight their discount.', 'woo-coupon-usage' ) ),
      ),
    ),

    array(
      'id'       => 'tab-pro-advanced',
      'row'      => 'wcusage_row_pro_advanced',
      'label'    => __( 'Advanced Controls', 'woo-coupon-usage' ),
      'icon'     => 'fas fa-sliders-h',
      'title'    => __( 'Fine-tune every part of your program', 'woo-coupon-usage' ),
      'subtitle' => __( 'Take full control with custom commission rules, affiliate groups, advanced registration, custom dashboard tabs and more.', 'woo-coupon-usage' ),
      'features' => array(
        array( 'fas fa-cogs',          __( 'Flexible Commission', 'woo-coupon-usage' ),       __( 'Set custom commission per coupon, product, category, or user role/group.', 'woo-coupon-usage' ) ),
        array( 'fas fa-users',         __( 'Affiliate Groups', 'woo-coupon-usage' ),          __( 'Group affiliates and apply rates, settings and rules to a whole group at once.', 'woo-coupon-usage' ) ),
        array( 'far fa-user-circle',   __( 'Advanced Registration', 'woo-coupon-usage' ),     __( 'Custom form fields, multiple templates, auto-accept, auto-registration and dynamic code generation.', 'woo-coupon-usage' ) ),
        array( 'fas fa-pager',         __( 'Custom Dashboard Tabs', 'woo-coupon-usage' ),     __( 'Add your own tabs and content to the affiliate dashboard.', 'woo-coupon-usage' ) ),
        array( 'fas fa-envelope',          __( 'Mailing List Integrations', 'woo-coupon-usage' ), __( 'Automatically add new affiliates to your email marketing lists.', 'woo-coupon-usage' ) ),
        array( 'fas fa-file-contract',     __( 'Terms &amp; Conditions Generator', 'woo-coupon-usage' ), __( 'Generate a T&amp;C template for your program and link it in the registration form.', 'woo-coupon-usage' ) ),
      ),
    ),

  );
}

/**
 * Output the sidebar menu items for the PRO sales tabs.
 * Called from within the settings sidebar <ul>, just below "PRO Modules".
 */
function wcusage_output_pro_sales_sidebar_items() {
  if ( ! wcusage_show_pro_sales_tabs() ) {
    return;
  }
  foreach ( wcusage_pro_sales_tabs() as $tab ) {
    ?>
    <li class="wcu-sidebar-menu-item">
      <?php wcusage_admin_settings_sidebar_button( $tab['id'], wp_strip_all_tags( html_entity_decode( $tab['label'] ) ), $tab['icon'], 1, '' ); ?>
    </li>
    <?php
  }
}

/**
 * Output the tab-click JS that wires each sales sidebar item to its panel.
 * Called from within the settings page <script> block.
 */
function wcusage_output_pro_sales_tab_click_js() {
  if ( ! wcusage_show_pro_sales_tabs() ) {
    return;
  }
  foreach ( wcusage_pro_sales_tabs() as $tab ) {
    wcusage_admin_settings_tab_click( '#' . $tab['id'], '.' . $tab['row'], 1 );
  }
}

/**
 * Output the content panels for every PRO sales tab.
 * Called inside the settings content area (after do_settings_sections).
 */
function wcusage_output_pro_sales_panels() {
  if ( ! wcusage_show_pro_sales_tabs() ) {
    return;
  }
  foreach ( wcusage_pro_sales_tabs() as $tab ) {
    wcusage_render_pro_sales_panel( $tab );
  }
}

/**
 * Render a single PRO sales panel.
 *
 * @param array $tab Tab definition.
 */
function wcusage_render_pro_sales_panel( $tab ) {

  $trial_url    = 'https://couponaffiliates.com/pricing?utm_campaign=plugin&utm_source=dashboard-link&utm_medium=pro-sales-tab';
  $learn_url    = 'https://couponaffiliates.com?utm_campaign=plugin&utm_source=dashboard-link&utm_medium=pro-sales-tab';
  ?>
  <div class="wcusage_row <?php echo esc_attr( $tab['row'] ); ?> wcu-pro-sales-row" style="display:none;">
    <div class="wcu-pro-sales">

      <div class="wcu-pro-sales-hero">
        <span class="wcu-pro-sales-badge"><i class="fas fa-star" aria-hidden="true"></i> <?php echo esc_html__( 'PRO Feature', 'woo-coupon-usage' ); ?></span>
        <span class="wcu-pro-sales-hero-icon"><i class="<?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></i></span>
        <h2 class="wcu-pro-sales-title"><?php echo wp_kses_post( $tab['title'] ); ?></h2>
        <p class="wcu-pro-sales-subtitle"><?php echo wp_kses_post( $tab['subtitle'] ); ?></p>
        <div class="wcu-pro-sales-cta">
          <a class="wcu-pro-sales-btn-primary" href="<?php echo esc_url( $trial_url ); ?>" target="_blank" rel="noopener">
            <?php echo esc_html__( 'Start your FREE 7-day trial', 'woo-coupon-usage' ); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
          </a>
          <a class="wcu-pro-sales-btn-secondary" href="<?php echo esc_url( $learn_url ); ?>" target="_blank" rel="noopener">
            <?php echo esc_html__( 'Learn more', 'woo-coupon-usage' ); ?>
          </a>
        </div>
        <p class="wcu-pro-sales-note"><?php echo esc_html__( 'After your trial, just $14.99 per month. Cancel anytime.', 'woo-coupon-usage' ); ?></p>
      </div>

      <div class="wcu-pro-sales-grid">
        <?php foreach ( $tab['features'] as $feature ) {
          list( $f_icon, $f_title, $f_desc ) = $feature; ?>
          <div class="wcu-pro-benefit">
            <span class="wcu-pro-benefit-icon"><i class="<?php echo esc_attr( $f_icon ); ?>" aria-hidden="true"></i></span>
            <strong class="wcu-pro-benefit-title"><?php echo wp_kses_post( $f_title ); ?></strong>
            <p class="wcu-pro-benefit-desc"><?php echo wp_kses_post( $f_desc ); ?></p>
          </div>
        <?php } ?>
      </div>

    </div>
  </div>
  <?php
}
