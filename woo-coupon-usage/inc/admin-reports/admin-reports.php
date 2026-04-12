<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Modern Admin Reports & Analytics page.
 * Auto-loads current month report on page load for instant insights.
 */
if( !function_exists( 'wcusage_admin_reports_page_html' ) ) {
  function wcusage_admin_reports_page_html() {

    $wcusage_field_tracking_enable = wcusage_get_setting_value('wcusage_field_tracking_enable', 1);
    $is_pro = wcu_fs()->can_use_premium_code();
    $mla_enabled = $is_pro && wcusage_get_setting_value('wcusage_field_mla_enable', '0');

    if ( ! wcusage_check_admin_access() ) {
      return;
    }

    // Default date range: this month
    $current_month_start = gmdate( 'Y-m-d', strtotime( 'first day of this month' ) );
    $current_month_end   = gmdate( 'Y-m-d' );

    // Free version date restrictions
    if ( ! $is_pro ) {
      $wcu_orders_date_min = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
    } else {
      $wcu_orders_date_min = '';
    }
    $wcu_orders_date_max = gmdate( 'Y-m-d' );

    // User roles for filter
    global $wp_roles;
    $roles = $wp_roles->get_names();
    $affiliate_roles = array();
    $other_roles = array();
    foreach ($roles as $key => $role) {
      if (strpos($key, 'coupon_affiliate') !== false) {
        $affiliate_roles[$key] = '(Group) ' . $role;
      } else {
        $other_roles[$key] = $role;
      }
    }
    $all_roles = array_merge($affiliate_roles, $other_roles);

    $currency_symbol = wcusage_get_currency_symbol();
    $affiliate_text = wcusage_get_affiliate_text(__( 'Affiliate', 'woo-coupon-usage' ));
    $affiliate_text_lower = strtolower($affiliate_text);

    $nonce = wp_create_nonce( 'wcusage_admin_ajax_nonce' );
    ?>

    <link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

    <div class="wrap wcusage-admin-page wcusage-reports-modern">

      <?php do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

      <!-- ===== PAGE HEADER ===== -->
      <div class="wcu-reports-header">
        <div class="wcu-reports-header-left">
          <h1><?php echo esc_html__( "Reports & Analytics", "woo-coupon-usage" ); ?></h1>
          <p class="wcu-reports-subtitle"><?php echo esc_html__( 'Comprehensive admin reports and analytics for all your coupons and affiliates.', 'woo-coupon-usage' ); ?></p>
        </div>
      </div>

      <!-- ===== FILTER BAR ===== -->
      <div class="wcu-reports-filter-bar" id="wcu-reports-filter-bar">
        <div class="wcu-filter-bar-inner">

          <!-- Primary row: Date + Filters + Generate -->
          <div class="wcu-filter-row-main">

            <!-- Date Range -->
            <div class="wcu-filter-group wcu-filter-dates">
              <label><i class="fas fa-calendar-alt"></i> <?php echo esc_html__( "Date Range", "woo-coupon-usage" ); ?></label>
              <div class="wcu-filter-date-inputs">
                <select id="wcu-date-preset-select" class="wcu-date-preset-select">
                  <option value="today"><?php echo esc_html__( "Today", "woo-coupon-usage" ); ?></option>
                  <option value="7days"><?php echo esc_html__( "7 Days", "woo-coupon-usage" ); ?></option>
                  <option value="14days"><?php echo esc_html__( "14 Days", "woo-coupon-usage" ); ?></option>
                  <option value="30days"><?php echo esc_html__( "30 Days", "woo-coupon-usage" ); ?></option>
                  <option value="this_month" selected><?php echo esc_html__( "This Month", "woo-coupon-usage" ); ?></option>
                  <option value="last_month"><?php echo esc_html__( "Last Month", "woo-coupon-usage" ); ?></option>
                  <option value="3months"><?php echo esc_html__( "3 Months", "woo-coupon-usage" ); ?></option>
                  <?php if ($is_pro) { ?>
                  <option value="90days"><?php echo esc_html__( "90 Days", "woo-coupon-usage" ); ?></option>
                  <option value="this_quarter"><?php echo esc_html__( "This Quarter", "woo-coupon-usage" ); ?></option>
                  <option value="last_quarter"><?php echo esc_html__( "Last Quarter", "woo-coupon-usage" ); ?></option>
                  <option value="12months"><?php echo esc_html__( "12 Months", "woo-coupon-usage" ); ?></option>
                  <option value="this_year"><?php echo esc_html__( "This Year", "woo-coupon-usage" ); ?></option>
                  <option value="last_year"><?php echo esc_html__( "Last Year", "woo-coupon-usage" ); ?></option>
                  <option value="all_time"><?php echo esc_html__( "All Time", "woo-coupon-usage" ); ?></option>
                  <?php } ?>
                  <option value="custom" id="wcu-preset-custom-opt" style="display:none;"><?php echo esc_html__( "Custom", "woo-coupon-usage" ); ?></option>
                </select>
                <input type="date" id="wcu-report-start"
                  min="<?php echo esc_attr($wcu_orders_date_min); ?>"
                  max="<?php echo esc_attr($wcu_orders_date_max); ?>"
                  value="<?php echo esc_attr($current_month_start); ?>">
                <span class="wcu-filter-date-sep">&mdash;</span>
                <input type="date" id="wcu-report-end"
                  min="<?php echo esc_attr($wcu_orders_date_min); ?>"
                  max="<?php echo esc_attr($wcu_orders_date_max); ?>"
                  value="<?php echo esc_attr($current_month_end); ?>">
              </div>
              <?php if ( !$is_pro ) { ?>
              <p class="wcu-free-note"><i class="fas fa-lock"></i> <?php echo esc_html__( "Unlimited date range available with", "woo-coupon-usage" ); ?> <a href="https://couponaffiliates.com/pricing/?utm_source=plugin&utm_medium=report-filters" target="_blank">PRO</a>.</p>
              <?php } ?>

              <!-- Compare dates (collapsible, inside date column) -->
              <?php if ($is_pro) { ?>
              <div class="wcu-compare-dates" id="wcu-compare-dates" style="display: none;">
                <label><i class="fas fa-exchange-alt"></i> <?php echo esc_html__( "Compare With", "woo-coupon-usage" ); ?></label>
                <div class="wcu-filter-date-inputs">
                  <input type="date" id="wcu-compare-start" value="">
                  <span class="wcu-filter-date-sep">&mdash;</span>
                  <input type="date" id="wcu-compare-end" value="">
                </div>
                <div class="wcu-compare-options">
                  <span class="wcu-compare-options-label"><?php echo esc_html__( "Show coupons where sales have", "woo-coupon-usage" ); ?>:</span>
                  <div class="wcu-compare-options-controls">
                    <select id="wcu-compare-filter-type">
                      <option value="both"><?php echo esc_html__( "Increased or Decreased", "woo-coupon-usage" ); ?></option>
                      <option value="more"><?php echo esc_html__( "Increased", "woo-coupon-usage" ); ?></option>
                      <option value="less"><?php echo esc_html__( "Decreased", "woo-coupon-usage" ); ?></option>
                    </select>
                    <span class="wcu-compare-options-sep"><?php echo esc_html__( "by at least", "woo-coupon-usage" ); ?></span>
                    <input type="number" id="wcu-compare-filter-amount" value="0" min="0" max="100" style="width: 60px;"> %
                  </div>
                </div>
              </div>
              <?php } ?>

            </div>

            <!-- Right side: Affiliate options + Generate -->
            <div class="wcu-filter-group wcu-filter-right-col">
              <label><i class="fas fa-filter"></i> <?php echo esc_html__( "Filters", "woo-coupon-usage" ); ?></label>

              <div class="wcu-filter-inline-options">
                <!-- Compare toggle -->
                <div class="wcu-filter-inline-item">
                  <label class="wcu-checkbox-label<?php echo !$is_pro ? ' wcu-compare-disabled' : ''; ?>">
                    <input type="checkbox" id="wcu-compare-enable"<?php echo !$is_pro ? ' disabled' : ''; ?>>
                    <span><i class="fas fa-exchange-alt"></i> <?php echo esc_html__( "Compare dates", "woo-coupon-usage" ); ?></span>
                    <?php if (!$is_pro) { ?>
                    <a href="https://couponaffiliates.com/pricing/?utm_source=plugin&utm_medium=report-filters" target="_blank" class="wcu-pro-badge"><i class="fas fa-lock"></i> PRO</a>
                    <?php } ?>
                  </label>
                </div>

                <!-- Affiliate Filter -->
                <div class="wcu-filter-inline-item">
                  <label class="wcu-checkbox-label">
                    <input type="checkbox" id="wcu-affiliates-only">
                    <span><i class="fas fa-users"></i> <?php echo sprintf(esc_html__( "Only %s coupons", "woo-coupon-usage" ), esc_html($affiliate_text_lower)); ?></span>
                  </label>
                </div>

                <!-- Affiliate Group Filter -->
                <div class="wcu-filter-inline-item<?php echo !$is_pro ? ' wcu-filter-disabled' : ''; ?>">
                  <select id="wcu-report-group-role" class="wcu-select-compact"<?php echo !$is_pro ? ' disabled' : ''; ?>>
                    <option value=""><?php echo esc_html__( "All Groups", "woo-coupon-usage" ); ?></option>
                    <?php foreach ($all_roles as $key => $role_name) { ?>
                      <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($role_name); ?></option>
                    <?php } ?>
                  </select>
                  <?php if (!$is_pro) { ?>
                  <a href="https://couponaffiliates.com/pricing/?utm_source=plugin&utm_medium=report-filters" target="_blank" class="wcu-pro-badge"><i class="fas fa-lock"></i> PRO</a>
                  <?php } ?>
                </div>

                <!-- Advanced Filters Toggle -->
                <div class="wcu-filter-inline-item">
                  <button type="button" id="wcu-advanced-toggle" class="wcu-btn-advanced-toggle">
                    <i class="fas fa-sliders-h"></i> <?php echo esc_html__( "Advanced", "woo-coupon-usage" ); ?>
                    <i class="fas fa-chevron-down wcu-adv-arrow"></i>
                  </button>
                </div>
              </div>

            </div>

          </div><!-- /.wcu-filter-row-main -->

          <!-- Advanced Filters (collapsible) -->
          <div class="wcu-filter-advanced-panel" id="wcu-filter-advanced-panel" style="display: none;">
            <div class="wcu-advanced-grid">

              <div class="wcu-adv-filter">
                <strong><?php echo esc_html__( "Total Usage", "woo-coupon-usage" ); ?></strong>
                <div class="wcu-adv-controls">
                  <select id="wcu-filter-usage-type">
                    <option value="more or equal">&ge; <?php echo esc_html__( "Equal or More", "woo-coupon-usage" ); ?></option>
                    <option value="more">&gt; <?php echo esc_html__( "More", "woo-coupon-usage" ); ?></option>
                    <option value="less or equal">&le; <?php echo esc_html__( "Equal or Less", "woo-coupon-usage" ); ?></option>
                    <option value="less">&lt; <?php echo esc_html__( "Less", "woo-coupon-usage" ); ?></option>
                    <option value="equal">= <?php echo esc_html__( "Equal", "woo-coupon-usage" ); ?></option>
                  </select>
                  <span class="wcu-adv-than"><?php echo esc_html__( "than", "woo-coupon-usage" ); ?></span>
                  <input type="number" id="wcu-filter-usage-amount" value="0" min="0">
                </div>
              </div>

              <div class="wcu-adv-filter">
                <strong><?php echo esc_html__( "Total Sales", "woo-coupon-usage" ); ?></strong>
                <div class="wcu-adv-controls">
                  <select id="wcu-filter-sales-type">
                    <option value="more or equal">&ge; <?php echo esc_html__( "Equal or More", "woo-coupon-usage" ); ?></option>
                    <option value="more">&gt; <?php echo esc_html__( "More", "woo-coupon-usage" ); ?></option>
                    <option value="less or equal">&le; <?php echo esc_html__( "Equal or Less", "woo-coupon-usage" ); ?></option>
                    <option value="less">&lt; <?php echo esc_html__( "Less", "woo-coupon-usage" ); ?></option>
                    <option value="equal">= <?php echo esc_html__( "Equal", "woo-coupon-usage" ); ?></option>
                  </select>
                  <span class="wcu-adv-than"><?php echo esc_html__( "than", "woo-coupon-usage" ); ?></span>
                  <div class="wcu-adv-input-row"><?php echo wp_kses_post($currency_symbol); ?> <input type="number" id="wcu-filter-sales-amount" value="0" min="0"></div>
                </div>
              </div>

              <div class="wcu-adv-filter">
                <strong><?php echo esc_html__( "Commission Earned", "woo-coupon-usage" ); ?></strong>
                <div class="wcu-adv-controls">
                  <select id="wcu-filter-commission-type">
                    <option value="more or equal">&ge; <?php echo esc_html__( "Equal or More", "woo-coupon-usage" ); ?></option>
                    <option value="more">&gt; <?php echo esc_html__( "More", "woo-coupon-usage" ); ?></option>
                    <option value="less or equal">&le; <?php echo esc_html__( "Equal or Less", "woo-coupon-usage" ); ?></option>
                    <option value="less">&lt; <?php echo esc_html__( "Less", "woo-coupon-usage" ); ?></option>
                    <option value="equal">= <?php echo esc_html__( "Equal", "woo-coupon-usage" ); ?></option>
                  </select>
                  <span class="wcu-adv-than"><?php echo esc_html__( "than", "woo-coupon-usage" ); ?></span>
                  <div class="wcu-adv-input-row"><?php echo wp_kses_post($currency_symbol); ?> <input type="number" id="wcu-filter-commission-amount" value="0" min="0"></div>
                </div>
              </div>

              <div class="wcu-adv-filter">
                <strong><?php echo esc_html__( "Conversion Rate", "woo-coupon-usage" ); ?></strong>
                <div class="wcu-adv-controls">
                  <select id="wcu-filter-conversions-type">
                    <option value="more or equal">&ge; <?php echo esc_html__( "Equal or More", "woo-coupon-usage" ); ?></option>
                    <option value="more">&gt; <?php echo esc_html__( "More", "woo-coupon-usage" ); ?></option>
                    <option value="less or equal">&le; <?php echo esc_html__( "Equal or Less", "woo-coupon-usage" ); ?></option>
                    <option value="less">&lt; <?php echo esc_html__( "Less", "woo-coupon-usage" ); ?></option>
                    <option value="equal">= <?php echo esc_html__( "Equal", "woo-coupon-usage" ); ?></option>
                  </select>
                  <span class="wcu-adv-than"><?php echo esc_html__( "than", "woo-coupon-usage" ); ?></span>
                  <div class="wcu-adv-input-row"><input type="number" id="wcu-filter-conversions-amount" value="0" min="0"> %</div>
                </div>
              </div>

              <?php if ($is_pro) { ?>
              <div class="wcu-adv-filter">
                <strong><?php echo esc_html__( "Unpaid Commission", "woo-coupon-usage" ); ?></strong>
                <div class="wcu-adv-controls">
                  <select id="wcu-filter-unpaid-type">
                    <option value="more or equal">&ge; <?php echo esc_html__( "Equal or More", "woo-coupon-usage" ); ?></option>
                    <option value="more">&gt; <?php echo esc_html__( "More", "woo-coupon-usage" ); ?></option>
                    <option value="less or equal">&le; <?php echo esc_html__( "Equal or Less", "woo-coupon-usage" ); ?></option>
                    <option value="less">&lt; <?php echo esc_html__( "Less", "woo-coupon-usage" ); ?></option>
                    <option value="equal">= <?php echo esc_html__( "Equal", "woo-coupon-usage" ); ?></option>
                  </select>
                  <span class="wcu-adv-than"><?php echo esc_html__( "than", "woo-coupon-usage" ); ?></span>
                  <div class="wcu-adv-input-row"><?php echo wp_kses_post($currency_symbol); ?> <input type="number" id="wcu-filter-unpaid-amount" value="0" min="0"></div>
                </div>
              </div>
              <?php } else { ?>
                <input type="hidden" id="wcu-filter-unpaid-type" value="more or equal">
                <input type="hidden" id="wcu-filter-unpaid-amount" value="0">
              <?php } ?>

            </div>
          </div><!-- /.wcu-filter-advanced-panel -->

          <!-- Generate Button (centered) -->
          <div class="wcu-filter-row-generate">
            <button type="button" id="wcu-generate-report" class="wcu-btn-generate">
              <?php echo esc_html__( "Generate Report", "woo-coupon-usage" ); ?> <i class="fas fa-arrow-right"></i>
            </button>
          </div>

        </div>
      </div>

      <!-- ===== SHOW SECTIONS ===== -->
      <div class="wcu-filter-row-sections">
        <label><i class="fas fa-eye"></i> <?php echo esc_html__( "Show Sections", "woo-coupon-usage" ); ?></label>
        <div class="wcu-toggle-pills">
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-sales" checked> <i class="fas fa-shopping-cart"></i> <?php echo esc_html__( "Sales", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-commission" checked> <i class="fas fa-coins"></i> <?php echo esc_html__( "Commission", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-referrals" checked> <i class="fas fa-link"></i> <?php echo esc_html__( "Referrals", "woo-coupon-usage" ); ?></label>
          <?php if ($is_pro) { ?>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-activity" checked> <i class="fas fa-history"></i> <?php echo esc_html__( "Activity", "woo-coupon-usage" ); ?></label>
          <?php } ?>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-trends" checked> <i class="fas fa-chart-line"></i> <?php echo esc_html__( "Trends", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-traffic" checked> <i class="fas fa-globe"></i> <?php echo esc_html__( "Traffic Sources", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-products" checked> <i class="fas fa-box"></i> <?php echo esc_html__( "Products", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-top-performers" checked> <i class="fas fa-trophy"></i> <?php echo esc_html__( "Top Performers", "woo-coupon-usage" ); ?></label>
          <label class="wcu-pill wcu-pill-active"><input type="checkbox" id="wcu-show-coupons" checked> <i class="fas fa-list-alt"></i> <?php echo esc_html__( "Individual Coupons", "woo-coupon-usage" ); ?></label>
        </div>
      </div>

      <!-- ===== LOADING INDICATOR ===== -->
      <div class="wcu-reports-loader" id="wcu-reports-loader">
        <div class="wcu-loading-loader"></div>
        <p class="wcu-loading-loader-text"><?php echo esc_html__( "Generating Report", "woo-coupon-usage" ); ?>...</p>
        <p class="wcu-loading-loader-subtext"><?php echo esc_html__( "Analyzing affiliate program data for the selected date range.", "woo-coupon-usage" ); ?></p>
        <p class="wcu-loader-details" id="wcu-loader-details"></p>
      </div>

      <!-- ===== REPORT CONTENT ===== -->
      <div class="wcu-reports-content" id="wcu-reports-content" style="display: none;">

        <!-- Report Info Bar -->
        <div class="wcu-report-info-bar" id="wcu-report-info-bar">
          <span class="wcu-info-bar-text" id="wcu-info-bar-text"></span>
          <button type="button" id="wcu-download-pdf" class="wcu-btn-secondary">
            <i class="fas fa-file-pdf"></i> <?php echo esc_html__( "Download PDF Report", "woo-coupon-usage" ); ?>
          </button>
        </div>

        <!-- Summary Cards -->
        <div class="wcu-summary-grid" id="wcu-summary-grid">

          <!-- Sales -->
          <div class="wcu-section-cards wcu-section-sales" id="wcu-section-sales">
            <h3 class="wcu-section-title"><i class="fas fa-shopping-cart"></i> <?php echo esc_html__( "Sales Overview", "woo-coupon-usage" ); ?></h3>
            <div class="wcu-cards-row">
              <div class="wcu-card wcu-card-has-sparkline">
                <div class="wcu-card-icon wcu-icon-usage"><i class="fas fa-tag"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-usage">0</span>
                  <span class="wcu-card-change" id="wcu-stat-usage-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Total Usage", "woo-coupon-usage" ); ?></span>
                </div>
                <div class="wcu-sparkline" id="wcu-spark-usage"></div>
              </div>
              <div class="wcu-card wcu-card-has-sparkline">
                <div class="wcu-card-icon wcu-icon-sales"><i class="fas fa-dollar-sign"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-sales"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-change" id="wcu-stat-sales-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Total Sales", "woo-coupon-usage" ); ?></span>
                </div>
                <div class="wcu-sparkline" id="wcu-spark-sales"></div>
              </div>
              <div class="wcu-card wcu-card-has-sparkline">
                <div class="wcu-card-icon wcu-icon-discounts"><i class="fas fa-percent"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-discounts"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-change" id="wcu-stat-discounts-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Total Discounts", "woo-coupon-usage" ); ?></span>
                </div>
                <div class="wcu-sparkline" id="wcu-spark-discounts"></div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-avg"><i class="fas fa-chart-line"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-avg-order"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-change" id="wcu-stat-avg-order-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Avg Order Value", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Commission -->
          <div class="wcu-section-cards wcu-section-commission" id="wcu-section-commission">
            <h3 class="wcu-section-title"><i class="fas fa-hand-holding-usd"></i> <?php echo esc_html__( "Commission Overview", "woo-coupon-usage" ); ?></h3>
            <div class="wcu-cards-row">
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-commission"><i class="fas fa-coins"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-commission"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-change" id="wcu-stat-commission-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Total Commission", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <?php if ($is_pro) { ?>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-unpaid"><i class="fas fa-clock"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-unpaid"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Unpaid Commission", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-pending"><i class="fas fa-hourglass-half"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-pending"><?php echo wp_kses_post($currency_symbol); ?>0.00</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Pending Payouts", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <?php } ?>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-rate"><i class="fas fa-percentage"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-commission-rate">0%</span>
                  <span class="wcu-card-change" id="wcu-stat-commission-rate-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Commission vs Sales", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Referrals -->
          <div class="wcu-section-cards wcu-section-referrals" id="wcu-section-referrals">
            <h3 class="wcu-section-title"><i class="fas fa-link"></i> <?php echo esc_html__( "Referral URL Overview", "woo-coupon-usage" ); ?></h3>
            <div class="wcu-cards-row">
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-clicks"><i class="fas fa-mouse-pointer"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-clicks">0</span>
                  <span class="wcu-card-change" id="wcu-stat-clicks-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Total Clicks", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-conversions"><i class="fas fa-check-circle"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-conversions">0</span>
                  <span class="wcu-card-change" id="wcu-stat-conversions-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Conversions", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-convrate"><i class="fas fa-bullseye"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-stat-convrate">0%</span>
                  <span class="wcu-card-change" id="wcu-stat-convrate-change"></span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Conversion Rate", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Activity Log -->
          <?php if ($is_pro) { ?>
          <div class="wcu-section-cards wcu-section-activity" id="wcu-section-activity">
            <h3 class="wcu-section-title"><i class="fas fa-history"></i> <?php echo esc_html__( "Activity Log", "woo-coupon-usage" ); ?></h3>
            <div class="wcu-cards-row wcu-activity-cards" id="wcu-activity-cards">
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-registrations"><i class="fas fa-user-plus"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-registration">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Registrations", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-payouts"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-payout-paid">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Payouts Paid", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-payout-requests"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-payout-request">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Payout Requests", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-rewards"><i class="fas fa-gift"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-reward-earned">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Rewards Earned", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-campaigns"><i class="fas fa-bullhorn"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-new-campaign">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "Campaigns Created", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <?php if ($mla_enabled) { ?>
              <div class="wcu-card">
                <div class="wcu-card-icon wcu-icon-activity-mla"><i class="fas fa-sitemap"></i></div>
                <div class="wcu-card-data">
                  <span class="wcu-card-value" id="wcu-activity-mla-invite">0</span>
                  <span class="wcu-card-label"><?php echo esc_html__( "MLA Invites", "woo-coupon-usage" ); ?></span>
                </div>
              </div>
              <?php } ?>
            </div>
          </div>
          <?php } ?>

        </div>
        <div class="wcu-section-cards wcu-section-trends" id="wcu-section-trends">
          <h3 class="wcu-section-title"><i class="fas fa-chart-line"></i> <?php echo esc_html__( "Daily Trends", "woo-coupon-usage" ); ?></h3>
          <div class="wcu-trends-row">
            <!-- Line chart (left) -->
            <div class="wcu-trends-wrap wcu-trends-line-col">
              <div class="wcu-trends-legend" id="wcu-trends-legend">
                <label class="wcu-trend-toggle wcu-trend-active" data-line="sales">
                  <span class="wcu-trend-swatch" style="background:#00a32a;"></span>
                  <?php echo esc_html__( "Sales", "woo-coupon-usage" ); ?>
                </label>
                <label class="wcu-trend-toggle wcu-trend-active" data-line="commission">
                  <span class="wcu-trend-swatch" style="background:#2271b1;"></span>
                  <?php echo esc_html__( "Commission", "woo-coupon-usage" ); ?>
                </label>
                <label class="wcu-trend-toggle wcu-trend-active" data-line="clicks">
                  <span class="wcu-trend-swatch" style="background:#7c3aed;"></span>
                  <?php echo esc_html__( "Clicks", "woo-coupon-usage" ); ?>
                </label>
              </div>
              <div class="wcu-trends-canvas-wrap">
                <canvas id="wcu-trends-canvas" height="320"></canvas>
                <div class="wcu-trends-tooltip" id="wcu-trends-tooltip"></div>
              </div>
            </div>
            <!-- Trend Insights (right) -->
            <div class="wcu-trends-wrap wcu-trends-insights-col">
              <div class="wcu-insights-header">
                <span class="wcu-insights-title"><i class="fas fa-lightbulb"></i> <?php echo esc_html__( "Trend Insights", "woo-coupon-usage" ); ?></span>
                <div class="wcu-insights-toggle" id="wcu-insights-toggle">
                  <button type="button" class="wcu-insights-toggle-btn active" data-mode="orders"><?php echo esc_html__( "Orders", "woo-coupon-usage" ); ?></button>
                  <button type="button" class="wcu-insights-toggle-btn" data-mode="clicks"><?php echo esc_html__( "Clicks", "woo-coupon-usage" ); ?></button>
                </div>
              </div>
              <div class="wcu-insights-body" id="wcu-insights-body">
                <div class="wcu-insights-empty"><i class="fas fa-chart-bar"></i> <?php echo esc_html__( "Generate a report to see insights", "woo-coupon-usage" ); ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Traffic Sources -->
        <div class="wcu-section-cards wcu-section-traffic" id="wcu-section-traffic">
          <h3 class="wcu-section-title"><i class="fas fa-globe"></i> <?php echo esc_html__( "Traffic Sources", "woo-coupon-usage" ); ?></h3>
          <div class="wcu-traffic-grid">
            <?php if ($is_pro) { ?>
            <div class="wcu-traffic-box">
              <h4><i class="fas fa-bullhorn"></i> <?php echo esc_html__( "Top Campaigns", "woo-coupon-usage" ); ?></h4>
              <div class="wcu-traffic-list" id="wcu-traffic-campaigns"></div>
            </div>
            <?php } ?>
            <div class="wcu-traffic-box">
              <h4><i class="fas fa-file-alt"></i> <?php echo esc_html__( "Top Landing Pages", "woo-coupon-usage" ); ?></h4>
              <div class="wcu-traffic-list" id="wcu-traffic-pages"></div>
            </div>
            <div class="wcu-traffic-box">
              <h4><i class="fas fa-external-link-alt"></i> <?php echo esc_html__( "Top Referrers", "woo-coupon-usage" ); ?></h4>
              <div class="wcu-traffic-list" id="wcu-traffic-referrers"></div>
            </div>
          </div>
        </div>

        <!-- Top Performers -->
        <div class="wcu-top-performers" id="wcu-top-performers">
          <h3 class="wcu-section-title"><i class="fas fa-trophy"></i> <?php echo esc_html__( "Top Performers", "woo-coupon-usage" ); ?></h3>
          <div class="wcu-top-grid">
            <div class="wcu-top-box">
              <h4><i class="fas fa-dollar-sign"></i> <?php echo esc_html__( "Top by Sales", "woo-coupon-usage" ); ?></h4>
              <ol id="wcu-top-sales-list"></ol>
            </div>
            <div class="wcu-top-box">
              <h4><i class="fas fa-coins"></i> <?php echo esc_html__( "Top by Commission", "woo-coupon-usage" ); ?></h4>
              <ol id="wcu-top-commission-list"></ol>
            </div>
            <div class="wcu-top-box">
              <h4><i class="fas fa-tag"></i> <?php echo esc_html__( "Top by Usage", "woo-coupon-usage" ); ?></h4>
              <ol id="wcu-top-usage-list"></ol>
            </div>
            <div class="wcu-top-box wcu-top-products">
              <h4><i class="fas fa-box-open"></i> <?php echo esc_html__( "Top Products", "woo-coupon-usage" ); ?></h4>
              <ol id="wcu-top-products-list"></ol>
            </div>
          </div>
        </div>

        <br/>

        <!-- Coupon Table -->
        <div class="wcu-coupon-table-section">

          <div class="wcu-table-header">
            <h3 class="wcu-section-title"><i class="fas fa-list-alt"></i> <?php echo esc_html__( "Individual Coupon Statistics", "woo-coupon-usage" ); ?></h3>
            <div class="wcu-table-controls">
              <div class="wcu-table-search">
                <i class="fas fa-search"></i>
                <input type="text" id="wcu-table-search-input" placeholder="<?php echo esc_attr__( "Search coupons...", "woo-coupon-usage" ); ?>">
              </div>
              <div class="wcu-table-sort">
                <select id="wcu-table-sort-select">
                  <option value="sales-desc"><?php echo esc_html__( "Sales (High → Low)", "woo-coupon-usage" ); ?></option>
                  <option value="sales-asc"><?php echo esc_html__( "Sales (Low → High)", "woo-coupon-usage" ); ?></option>
                  <option value="usage-desc"><?php echo esc_html__( "Usage (High → Low)", "woo-coupon-usage" ); ?></option>
                  <option value="usage-asc"><?php echo esc_html__( "Usage (Low → High)", "woo-coupon-usage" ); ?></option>
                  <option value="commission-desc"><?php echo esc_html__( "Commission (High → Low)", "woo-coupon-usage" ); ?></option>
                  <option value="commission-asc"><?php echo esc_html__( "Commission (Low → High)", "woo-coupon-usage" ); ?></option>
                  <option value="discounts-desc"><?php echo esc_html__( "Discounts (High → Low)", "woo-coupon-usage" ); ?></option>
                  <option value="name-asc"><?php echo esc_html__( "Name (A → Z)", "woo-coupon-usage" ); ?></option>
                  <option value="name-desc"><?php echo esc_html__( "Name (Z → A)", "woo-coupon-usage" ); ?></option>
                </select>
              </div>
              <div class="wcu-col-chooser-wrap">
                <button type="button" id="wcu-col-chooser-btn" class="wcu-btn-secondary">
                  <i class="fas fa-columns"></i> <?php echo esc_html__( "Columns", "woo-coupon-usage" ); ?>
                  <i class="fas fa-chevron-down" style="font-size:10px;margin-left:2px;"></i>
                </button>
                <div class="wcu-col-chooser-dropdown" id="wcu-col-chooser-dropdown">
                  <label><input type="checkbox" data-col="col-name" checked disabled> <?php echo esc_html__( "Coupon", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-affiliate" checked> <?php echo esc_html( $affiliate_text ); ?></label>
                  <label><input type="checkbox" data-col="col-usage" checked> <?php echo esc_html__( "Usage", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-orders" checked> <?php echo esc_html__( "Sales", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-discounts" checked> <?php echo esc_html__( "Discounts", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-commission" checked> <?php echo esc_html__( "Commission", "woo-coupon-usage" ); ?></label>
                  <?php if ($is_pro && $wcusage_field_tracking_enable) { ?>
                  <label><input type="checkbox" data-col="col-unpaid" checked> <?php echo esc_html__( "Unpaid", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-pending" checked> <?php echo esc_html__( "Pending", "woo-coupon-usage" ); ?></label>
                  <?php } ?>
                  <label><input type="checkbox" data-col="col-clicks" checked> <?php echo esc_html__( "Clicks", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-conversions" checked> <?php echo esc_html__( "Conversions", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-convrate" checked> <?php echo esc_html__( "Conv. Rate", "woo-coupon-usage" ); ?></label>
                  <label><input type="checkbox" data-col="col-products" checked> <?php echo esc_html__( "Products", "woo-coupon-usage" ); ?></label>
                </div>
              </div>
              <button type="button" id="wcu-export-csv" class="wcu-btn-secondary"<?php if (!$is_pro) { echo ' disabled title="' . esc_attr__( 'Available with PRO version', 'woo-coupon-usage' ) . '"'; } ?>>
                <i class="fas fa-file-csv"></i> <?php echo esc_html__( "Export CSV", "woo-coupon-usage" ); ?><?php if (!$is_pro) { ?> <span class="wcu-pro-badge">PRO</span><?php } ?>
              </button>
            </div>
          </div>

          <div class="wcu-table-wrap">
            <table class="wcu-report-table" id="wcu-report-table">
              <thead>
                <!-- Group header row -->
                <tr class="wcu-thead-groups">
                  <th class="wcu-col-name wcu-col-affiliate wcu-group-header" colspan="2"><i class="fas fa-user-tag"></i> <?php echo esc_html__( "Affiliate Details", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-sales wcu-group-header" colspan="3"><i class="fas fa-shopping-cart"></i> <?php echo esc_html__( "Sales", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-comm wcu-group-header" colspan="<?php echo ($is_pro && $wcusage_field_tracking_enable) ? 3 : 1; ?>"><i class="fas fa-coins"></i> <?php echo esc_html__( "Commission", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-ref wcu-group-header" colspan="3"><i class="fas fa-link"></i> <?php echo esc_html__( "Referral Links", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-prod wcu-col-products wcu-group-header"><i class="fas fa-box"></i> <?php echo esc_html__( "Products", "woo-coupon-usage" ); ?></th>
                </tr>
                <!-- Column header row -->
                <tr class="wcu-thead-cols">
                  <th class="wcu-col-name"><?php echo esc_html__( "Coupon", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-affiliate"><?php echo esc_html( $affiliate_text ); ?></th>
                  <th class="wcu-col-usage wcu-col-sales"><?php echo esc_html__( "Usage", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-orders wcu-col-sales"><?php echo esc_html__( "Sales", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-discounts wcu-col-sales"><?php echo esc_html__( "Discounts", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-commission wcu-col-comm"><?php echo esc_html__( "Earned", "woo-coupon-usage" ); ?></th>
                  <?php if ($is_pro && $wcusage_field_tracking_enable) { ?>
                  <th class="wcu-col-unpaid wcu-col-comm"><?php echo esc_html__( "Unpaid", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-pending wcu-col-comm"><?php echo esc_html__( "Pending", "woo-coupon-usage" ); ?></th>
                  <?php } ?>
                  <th class="wcu-col-clicks wcu-col-ref"><?php echo esc_html__( "Clicks", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-conversions wcu-col-ref"><?php echo esc_html__( "Conv.", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-convrate wcu-col-ref"><?php echo esc_html__( "Rate", "woo-coupon-usage" ); ?></th>
                  <th class="wcu-col-prod wcu-col-products"><?php echo esc_html__( "Top Products", "woo-coupon-usage" ); ?></th>
                </tr>
              </thead>
              <tbody id="wcu-report-tbody"></tbody>
            </table>
          </div>

        </div>

      </div>

      <!-- No Results -->
      <div class="wcu-no-results" id="wcu-no-results" style="display: none;">
        <i class="fas fa-inbox"></i>
        <h3><?php echo esc_html__( "No Data Found", "woo-coupon-usage" ); ?></h3>
        <p><?php echo esc_html__( "No coupon data matches the selected date range and filters. Try expanding the date range or adjusting your filters.", "woo-coupon-usage" ); ?></p>
      </div>

      <!-- JS Config -->
      <script type="text/javascript">
        var wcuReportsConfig = {
          ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
          nonce: '<?php echo esc_js($nonce); ?>',
          currency: <?php echo wp_json_encode(wp_kses_post($currency_symbol)); ?>,
          isPro: <?php echo $is_pro ? 'true' : 'false'; ?>,
          mlaEnabled: <?php echo $mla_enabled ? 'true' : 'false'; ?>,
          trackingEnabled: <?php echo $wcusage_field_tracking_enable ? 'true' : 'false'; ?>,
          startDate: '<?php echo esc_js($current_month_start); ?>',
          endDate: '<?php echo esc_js($current_month_end); ?>',
          affiliateText: '<?php echo esc_js($affiliate_text); ?>',
          payoutsUrl: <?php echo wp_json_encode(admin_url('admin.php?page=wcusage_payouts')); ?>,
          viewAffiliateUrl: <?php echo wp_json_encode(admin_url('admin.php?page=wcusage_view_affiliate&user_id=')); ?>,
          i18n: {
            generating: '<?php echo esc_js(__( "Generating Report...", "woo-coupon-usage" )); ?>',
            noProducts: '<?php echo esc_js(__( "No products", "woo-coupon-usage" )); ?>',
            viewDashboard: '<?php echo esc_js(sprintf(__( "View %s Dashboard", "woo-coupon-usage" ), $affiliate_text)); ?>',
            editCoupon: '<?php echo esc_js(__( "Edit Coupon", "woo-coupon-usage" )); ?>',
            reportFor: '<?php echo esc_js(__( "Report for", "woo-coupon-usage" )); ?>',
            to: '<?php echo esc_js(__( "to", "woo-coupon-usage" )); ?>',
            comparedWith: '<?php echo esc_js(__( "Compared with", "woo-coupon-usage" )); ?>',
            noAffiliate: '—'
          }
        };
      </script>

    </div>

    <?php
  }
}
