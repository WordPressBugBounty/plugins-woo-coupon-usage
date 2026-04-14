/**
 * Modern Admin Reports & Analytics JS
 * Coupon Affiliates Plugin
 *
 * Handles: auto-load, AJAX, date presets, section toggles, sorting, searching, CSV export.
 */
(function ($) {
  "use strict";

  var cfg = window.wcuReportsConfig || {};
  var allCoupons = []; // Stores the full coupon dataset from last AJAX response
  var lastTimeseries = null; // Stores timeseries data for the trends chart
  var lastTotals = null; // Stores totals data for the breakdown chart

  /* ============================================
   * INITIALISATION – auto-load on page ready
   * ============================================ */
  $(document).ready(function () {
    bindEvents();
    restoreFilters();
    // Auto-load report with restored (or default) filters
    loadReport();
  });

  /* ============================================
   * EVENT BINDINGS
   * ============================================ */
  function bindEvents() {
    // Generate report
    $("#wcu-generate-report").on("click", function () {
      loadReport();
    });

    // Date preset dropdown
    $("#wcu-date-preset-select").on("change", function () {
      applyDatePreset($(this).val());
    });

    // Section toggle pills
    $(".wcu-pill input[type='checkbox']").on("change", function () {
      var pill = $(this).closest(".wcu-pill");
      pill.toggleClass("wcu-pill-active", this.checked);
      updateSectionVisibility();
    });

    // Compare dates toggle
    $("#wcu-compare-enable").on("change", function () {
      if (this.checked) {
        $("#wcu-compare-dates").slideDown(200);
        // Prefill compare dates if empty
        if (!$("#wcu-compare-start").val()) {
          var s = new Date($("#wcu-report-start").val());
          var e = new Date($("#wcu-report-end").val());
          var diff = e - s;
          var cs = new Date(s.getTime() - diff - 86400000);
          var ce = new Date(s.getTime() - 86400000);
          $("#wcu-compare-start").val(dateStr(cs));
          $("#wcu-compare-end").val(dateStr(ce));
        }
      } else {
        $("#wcu-compare-dates").slideUp(200);
      }
      repositionAdvancedPanel();
    });

    // Table search
    $("#wcu-table-search-input").on("input", function () {
      sortAndRenderTable();
    });

    // Table sort
    $("#wcu-table-sort-select").on("change", function () {
      sortAndRenderTable();
    });

    // CSV export
    $("#wcu-export-csv").on("click", function () {
      exportCSV();
    });

    // PDF download
    $("#wcu-download-pdf").on("click", function () {
      downloadPDF();
    });

    // Column chooser toggle
    $("#wcu-col-chooser-btn").on("click", function (e) {
      e.stopPropagation();
      $("#wcu-col-chooser-dropdown").toggleClass("wcu-open");
    });

    // Close column chooser on outside click
    $(document).on("click", function (e) {
      if (!$(e.target).closest(".wcu-col-chooser-wrap").length) {
        $("#wcu-col-chooser-dropdown").removeClass("wcu-open");
      }
    });

    // Column chooser checkbox change
    $("#wcu-col-chooser-dropdown input[type='checkbox']").on(
      "change",
      function () {
        updateColumnVisibility();
      }
    );

    // Manual date change → switch dropdown to Custom
    $("#wcu-report-start, #wcu-report-end").on("change", function () {
      $("#wcu-preset-custom-opt").show();
      $("#wcu-date-preset-select").val("custom");
    });

    // Trends chart legend toggles
    $(document).on("click", ".wcu-trend-toggle", function () {
      $(this).toggleClass("wcu-trend-active");
      renderTrendsChart();
    });

    // Insights toggle (Orders / Clicks)
    $(document).on("click", ".wcu-insights-toggle-btn", function () {
      $(".wcu-insights-toggle-btn").removeClass("active");
      $(this).addClass("active");
      renderTrendInsights();
    });

    // Redraw trends chart on window resize (debounced)
    var resizeTimer;
    $(window).on("resize", function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { renderTrendsChart(); }, 150);
    });

    // Advanced Filters toggle
    $("#wcu-advanced-toggle").on("click", function () {
      var $btn = $(this);
      var $panel = $("#wcu-filter-advanced-panel");
      $btn.toggleClass("wcu-adv-open");
      $panel.slideToggle(200);
    });
  }

  /* ============================================
   * ADVANCED PANEL POSITION
   * When Compare is on, move the advanced panel
   * inside the right column; otherwise keep it
   * full-width below both columns.
   * ============================================ */
  function repositionAdvancedPanel() {
    var $panel = $("#wcu-filter-advanced-panel");
    var compareOn = $("#wcu-compare-enable").is(":checked");

    if (compareOn) {
      // Move into the right column
      $(".wcu-filter-right-col").append($panel);
      $panel.addClass("wcu-adv-panel-inline");
    } else {
      // Move back to full-width position (before the generate row)
      $(".wcu-filter-row-generate").before($panel);
      $panel.removeClass("wcu-adv-panel-inline");
    }
  }

  /* ============================================
   * DATE PRESETS
   * ============================================ */
  function applyDatePreset(preset) {
    var now = new Date();
    var start, end, compareStart, compareEnd;

    switch (preset) {
      case "today":
        start = end = now;
        compareStart = new Date(now);
        compareStart.setDate(compareStart.getDate() - 1);
        compareEnd = new Date(compareStart);
        break;
      case "7days":
        end = now;
        start = new Date(now);
        start.setDate(start.getDate() - 6);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setDate(compareStart.getDate() - 6);
        break;
      case "14days":
        end = now;
        start = new Date(now);
        start.setDate(start.getDate() - 13);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setDate(compareStart.getDate() - 13);
        break;
      case "30days":
        end = now;
        start = new Date(now);
        start.setDate(start.getDate() - 29);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setDate(compareStart.getDate() - 29);
        break;
      case "90days":
        end = now;
        start = new Date(now);
        start.setDate(start.getDate() - 89);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setDate(compareStart.getDate() - 89);
        break;
      case "3months":
        end = now;
        start = new Date(now);
        start.setMonth(start.getMonth() - 3);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setMonth(compareStart.getMonth() - 3);
        break;
      case "12months":
        end = now;
        start = new Date(now);
        start.setMonth(start.getMonth() - 12);
        compareEnd = new Date(start);
        compareEnd.setDate(compareEnd.getDate() - 1);
        compareStart = new Date(compareEnd);
        compareStart.setMonth(compareStart.getMonth() - 12);
        break;
      case "custom":
        return;
      case "this_month":
        start = new Date(now.getFullYear(), now.getMonth(), 1);
        end = now;
        compareStart = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        compareEnd = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate());
        break;
      case "last_month":
        start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        end = new Date(now.getFullYear(), now.getMonth(), 0);
        compareStart = new Date(now.getFullYear(), now.getMonth() - 2, 1);
        compareEnd = new Date(now.getFullYear(), now.getMonth() - 1, 0);
        break;
      case "this_quarter":
        var q = Math.floor(now.getMonth() / 3) * 3;
        start = new Date(now.getFullYear(), q, 1);
        end = now;
        compareStart = new Date(now.getFullYear(), q - 3, 1);
        compareEnd = new Date(now.getFullYear(), q, 0);
        break;
      case "this_year":
        start = new Date(now.getFullYear(), 0, 1);
        end = now;
        compareStart = new Date(now.getFullYear() - 1, 0, 1);
        compareEnd = new Date(now.getFullYear() - 1, now.getMonth(), now.getDate());
        break;
      case "last_quarter":
        var lq = Math.floor(now.getMonth() / 3) * 3 - 3;
        var lqYear = lq < 0 ? now.getFullYear() - 1 : now.getFullYear();
        lq = ((lq % 12) + 12) % 12;
        start = new Date(lqYear, lq, 1);
        end = new Date(lqYear, lq + 3, 0);
        compareStart = new Date(lqYear, lq - 3, 1);
        compareEnd = new Date(lqYear, lq, 0);
        break;
      case "last_year":
        start = new Date(now.getFullYear() - 1, 0, 1);
        end = new Date(now.getFullYear() - 1, 11, 31);
        compareStart = new Date(now.getFullYear() - 2, 0, 1);
        compareEnd = new Date(now.getFullYear() - 2, 11, 31);
        break;
      case "all_time":
        start = new Date(2000, 0, 1);
        end = now;
        compareStart = null;
        compareEnd = null;
        break;
      default:
        return;
    }

    // If free, clamp start to 3 months ago
    if (!cfg.isPro) {
      var minDate = new Date();
      minDate.setMonth(minDate.getMonth() - 3);
      if (start < minDate) start = minDate;
    }

    $("#wcu-report-start").val(dateStr(start));
    $("#wcu-report-end").val(dateStr(end));

    // Update compare dates if compare is enabled or if values are already set
    if (compareStart && compareEnd) {
      if ($("#wcu-compare-enable").is(":checked") || $("#wcu-compare-start").val()) {
        $("#wcu-compare-start").val(dateStr(compareStart));
        $("#wcu-compare-end").val(dateStr(compareEnd));
      }
    }
  }

  /* ============================================
   * LOAD REPORT (AJAX)
   * ============================================ */
  var activeReportXhr = null;

  function loadReport() {
    // Abort any in-progress request so the new one takes over
    if (activeReportXhr) {
      activeReportXhr.abort();
      activeReportXhr = null;
    }

    var btn = $("#wcu-generate-report");
    btn.addClass("wcu-loading");

    $("#wcu-reports-content").hide();
    $("#wcu-no-results").hide();

    // Show report details in loader
    var loaderParts = [];
    var startVal = $("#wcu-report-start").val();
    var endVal   = $("#wcu-report-end").val();
    if (startVal && endVal) {
      loaderParts.push('<i class="fas fa-calendar-alt"></i> ' + escHtml(startVal) + ' &mdash; ' + escHtml(endVal));
    }
    if ($("#wcu-affiliates-only").is(":checked")) {
      var groupVal = $("#wcu-report-group-role option:selected").text();
      loaderParts.push('<i class="fas fa-users"></i> ' + (groupVal && $("#wcu-report-group-role").val() ? escHtml(groupVal) : 'Affiliates only'));
    }
    if (cfg.isPro && $("#wcu-compare-enable").is(":checked")) {
      var cs = $("#wcu-compare-start").val(), ce = $("#wcu-compare-end").val();
      if (cs && ce) loaderParts.push('<i class="fas fa-exchange-alt"></i> Comparing: ' + escHtml(cs) + ' &mdash; ' + escHtml(ce));
    }
    $("#wcu-loader-details").html(loaderParts.join('<span class="wcu-loader-sep">·</span>'));

    $("#wcu-reports-loader").show();

    var postData = {
      action: "wcusage_load_admin_reports",
      _ajax_nonce: cfg.nonce,
      wcu_orders_start: $("#wcu-report-start").val(),
      wcu_orders_end: $("#wcu-report-end").val(),
      wcu_report_users_only: $("#wcu-affiliates-only").is(":checked")
        ? "true"
        : "false",
      wcu_report_group_role: $("#wcu-report-group-role").val() || "",
      wcu_report_show_sales: $("#wcu-show-sales").is(":checked")
        ? "true"
        : "false",
      wcu_report_show_commission: $("#wcu-show-commission").is(":checked")
        ? "true"
        : "false",
      wcu_report_show_url: $("#wcu-show-referrals").is(":checked")
        ? "true"
        : "false",
      wcu_report_show_products: $("#wcu-show-products").is(":checked")
        ? "true"
        : "false",
      // Advanced filters
      wcu_orders_filterusage_type: $("#wcu-filter-usage-type").val(),
      wcu_orders_filterusage_amount: $("#wcu-filter-usage-amount").val(),
      wcu_orders_filtersales_type: $("#wcu-filter-sales-type").val(),
      wcu_orders_filtersales_amount: $("#wcu-filter-sales-amount").val(),
      wcu_orders_filtercommission_type: $(
        "#wcu-filter-commission-type"
      ).val(),
      wcu_orders_filtercommission_amount: $(
        "#wcu-filter-commission-amount"
      ).val(),
      wcu_orders_filterconversions_type: $(
        "#wcu-filter-conversions-type"
      ).val(),
      wcu_orders_filterconversions_amount: $(
        "#wcu-filter-conversions-amount"
      ).val(),
      wcu_orders_filterunpaid_type: $("#wcu-filter-unpaid-type").val(),
      wcu_orders_filterunpaid_amount: $("#wcu-filter-unpaid-amount").val(),
      // Compare
      wcu_compare:
        cfg.isPro && $("#wcu-compare-enable").is(":checked")
          ? "true"
          : "false",
      wcu_orders_start_compare: $("#wcu-compare-start").val(),
      wcu_orders_end_compare: $("#wcu-compare-end").val(),
      wcu_orders_filtercompare_type: $("#wcu-compare-filter-type").val(),
      wcu_orders_filtercompare_amount: $(
        "#wcu-compare-filter-amount"
      ).val(),
    };

    activeReportXhr = $.post(cfg.ajaxUrl, postData)
      .done(function (res) {
        saveFilters();
        if (res.success && res.data) {
          allCoupons = res.data.coupons || [];
          renderReport(res.data);
        } else {
          showNoResults();
        }
      })
      .fail(function (xhr) {
        // Ignore aborted requests (user triggered a new one)
        if (xhr.statusText !== "abort") {
          showNoResults();
        }
      })
      .always(function () {
        activeReportXhr = null;
        btn.removeClass("wcu-loading");
        $("#wcu-reports-loader").hide();
      });
  }

  /* ============================================
   * RENDER REPORT
   * ============================================ */
  function renderReport(data) {
    var t = data.totals;
    var coupons = data.coupons;
    lastTotals = t; // Cache for breakdown chart

    if (!coupons || coupons.length === 0) {
      showNoResults();
      return;
    }

    // Info bar
    var info =
      '<i class="fas fa-info-circle"></i> ' +
      cfg.i18n.reportFor +
      " <strong>" +
      escHtml(data.date_start) +
      "</strong> " +
      cfg.i18n.to +
      " <strong>" +
      escHtml(data.date_end) +
      "</strong>";
    if (data.comparing) {
      info +=
        " &mdash; " +
        cfg.i18n.comparedWith +
        " <strong>" +
        escHtml(data.compare_start) +
        "</strong> " +
        cfg.i18n.to +
        " <strong>" +
        escHtml(data.compare_end) +
        "</strong>";
    }
    // Count affiliate vs non-affiliate coupons
    var affiliateCount = 0;
    var nonAffiliateCount = 0;
    for (var i = 0; i < coupons.length; i++) {
      var cp = coupons[i];
      if (cp.user_id && cp.username && cp.username !== "\u2014" && cp.username !== "-") {
        affiliateCount++;
      } else {
        nonAffiliateCount++;
      }
    }
    info +=
      " - <strong>" +
      coupons.length +
      "</strong> coupons found";
    if (nonAffiliateCount > 0) {
      info +=
        " (<strong>" + affiliateCount + "</strong> " + escHtml(cfg.affiliateText).toLowerCase() +
        ", <strong>" + nonAffiliateCount + "</strong> non-" + escHtml(cfg.affiliateText).toLowerCase() + ")";
    }
    info += '.';
    $("#wcu-info-bar-text").html(info);

    // Summary cards
    $("#wcu-stat-usage").text(numberFormat(t.total_usage, 0));
    $("#wcu-stat-sales").html(cfg.currency + numberFormat(t.total_sales, 2));
    $("#wcu-stat-discounts").html(
      cfg.currency + numberFormat(t.total_discounts, 2)
    );
    $("#wcu-stat-avg-order").html(
      cfg.currency + numberFormat(t.avg_order_value, 2)
    );
    $("#wcu-stat-commission").html(
      cfg.currency + numberFormat(t.total_commission, 2)
    );
    if (cfg.isPro) {
      $("#wcu-stat-unpaid").html(
        cfg.currency + numberFormat(t.unpaid_commission, 2)
      );
      $("#wcu-stat-pending").html(
        cfg.currency + numberFormat(t.pending_commission, 2)
      );
    }
    $("#wcu-stat-commission-rate").text(t.commission_rate + "%");

    // Sparkline trend charts
    var ts = data.timeseries;
    if (ts) {
      renderSparkline("#wcu-spark-usage",      ts.usage,      "#2271b1");
      renderSparkline("#wcu-spark-sales",       ts.sales,      "#00a32a");
      renderSparkline("#wcu-spark-discounts",   ts.discounts,  "#d97706");
    }

    // Store timeseries for trends chart (rendered after fadeIn)
    lastTimeseries = ts;

    // Referral stats
    $("#wcu-stat-clicks").text(numberFormat(t.total_clicks, 0));
    $("#wcu-stat-conversions").text(numberFormat(t.total_conversions, 0));
    $("#wcu-stat-convrate").text(t.conversion_rate + "%");

    // Compare badges on stat cards
    var tc = data.totals_compare;
    var cardChanges = [
      ["#wcu-stat-usage-change",           tc ? tc.usage           : null],
      ["#wcu-stat-sales-change",            tc ? tc.sales           : null],
      ["#wcu-stat-discounts-change",        tc ? tc.discounts       : null],
      ["#wcu-stat-avg-order-change",        tc ? tc.avg_order       : null],
      ["#wcu-stat-commission-change",       tc ? tc.commission      : null],
      ["#wcu-stat-commission-rate-change",  tc ? tc.commission_rate : null],
      ["#wcu-stat-clicks-change",           tc ? tc.clicks          : null],
      ["#wcu-stat-conversions-change",      tc ? tc.conversions     : null],
      ["#wcu-stat-convrate-change",         tc ? tc.conversion_rate : null],
    ];
    $.each(cardChanges, function(i, pair) {
      $(pair[0]).html(pair[1] !== null ? changeHtml(pair[1]) : "");
    });

    // Activity log stats (PRO only)
    if (cfg.isPro) {
      var act = data.activity || {};
      $("#wcu-activity-registration").text(numberFormat((act.registration || 0) + (act.registration_accept || 0), 0));
      $("#wcu-activity-payout-paid").text(numberFormat(act.payout_paid || 0, 0));
      $("#wcu-activity-payout-request").text(numberFormat(act.payout_request || 0, 0));
      $("#wcu-activity-reward-earned").text(numberFormat(act.reward_earned || 0, 0));
      $("#wcu-activity-new-campaign").text(numberFormat(act.new_campaign || 0, 0));
      if (cfg.mlaEnabled) {
        $("#wcu-activity-mla-invite").text(numberFormat(act.mla_invite || 0, 0));
      }
    }

    // Traffic sources
    if (data.traffic_sources) {
      renderTrafficSources(data.traffic_sources);
    }

    // Top performers
    renderTopPerformers(coupons);

    // Show content first so canvas can measure its container width,
    // then update section visibility (which re-renders the table + trends chart).
    $("#wcu-no-results").hide();
    $("#wcu-reports-content").fadeIn(300, function () {
      renderTrendInsights();  // Render insights first so right column has full height
      renderTrendsChart();    // Then render canvas which reads the stretched height
    });
    updateSectionVisibility();
  }

  /* ============================================
   * TRAFFIC SOURCES
   * ============================================ */
  var TRAFFIC_PAGE_SIZE = 10;
  var trafficData = {}; // Cached full traffic data for pagination

  function renderTrafficSources(ts) {
    trafficData = ts; // Cache for pagination

    // Campaigns (PRO only — element only exists when PRO)
    if ($("#wcu-traffic-campaigns").length && ts.campaigns) {
      renderTrafficBox("campaigns", ts.campaigns, function (c) {
        return { label: escHtml(c.campaign), clicks: c.clicks, conversions: c.conversions };
      });
    }

    // Landing pages
    if (ts.pages) {
      renderTrafficBox("pages", ts.pages, function (p) {
        return { label: escHtml(p.page), clicks: p.clicks, conversions: p.conversions };
      });
    }

    // Referrers — insert direct traffic row sorted by clicks
    if (ts.referrers) {
      var refsWithDirect = ts.referrers.slice();
      if (ts.direct_clicks > 0 || !refsWithDirect.length) {
        var directRow = {
          domain: '__direct__',
          clicks: ts.direct_clicks || 0,
          conversions: ts.direct_conversions || 0
        };
        // Insert in sorted position (descending by clicks)
        var inserted = false;
        for (var ri = 0; ri < refsWithDirect.length; ri++) {
          if (directRow.clicks >= refsWithDirect[ri].clicks) {
            refsWithDirect.splice(ri, 0, directRow);
            inserted = true;
            break;
          }
        }
        if (!inserted) refsWithDirect.push(directRow);
      }
      renderTrafficBox("referrers", refsWithDirect, function (r) {
        var label = r.domain === '__direct__'
          ? '<i class="fas fa-arrow-right" style="margin-right:4px;opacity:0.5;"></i>Direct Traffic'
          : escHtml(r.domain);
        return { label: label, clicks: r.clicks, conversions: r.conversions };
      });
    }
  }

  /**
   * Render a single traffic box with page-based pagination.
   * @param {string}   key     - "campaigns", "pages", or "referrers"
   * @param {Array}    items   - Full array of items
   * @param {Function} mapFn   - Maps an item to { label, clicks, conversions }
   */
  function renderTrafficBox(key, items, mapFn) {
    var containerMap = {
      campaigns: "#wcu-traffic-campaigns",
      pages:     "#wcu-traffic-pages",
      referrers: "#wcu-traffic-referrers"
    };
    var $container = $(containerMap[key]);
    if (!$container.length) return;
    $container.empty();

    if (!items || !items.length) {
      var emptyMap = { campaigns: "No campaign data", pages: "No landing page data", referrers: "No referrer data" };
      $container.html('<div class="wcu-traffic-empty"><i class="fas fa-info-circle"></i> ' + emptyMap[key] + '</div>');
      return;
    }

    var totalClicks = trafficData.total_clicks || 0;
    var totalPages  = Math.ceil(items.length / TRAFFIC_PAGE_SIZE);
    var currentPage = 1;

    var $rows     = $('<div class="wcu-paginate-rows"></div>');
    var $controls = $('<div class="wcu-paginate-controls"></div>');
    $container.append($rows).append($controls);

    function renderPage(page) {
      currentPage = page;
      $rows.empty();
      var start = (page - 1) * TRAFFIC_PAGE_SIZE;
      var end   = Math.min(start + TRAFFIC_PAGE_SIZE, items.length);
      for (var i = start; i < end; i++) {
        var mapped  = mapFn(items[i]);
        var convRate = mapped.clicks > 0 ? ((mapped.conversions / mapped.clicks) * 100).toFixed(1) : "0.0";
        $rows.append(trafficRow(mapped.label, mapped.clicks, mapped.conversions, convRate, totalClicks));
      }
      renderPaginateControls($controls, currentPage, totalPages, items.length, renderPage);
    }

    renderPage(1);
  }

  /**
   * Build pagination controls: « Prev | 1 2 3 ... | Next »
   */
  function renderPaginateControls($controls, currentPage, totalPages, totalItems, goToPage) {
    $controls.empty();
    if (totalPages <= 1) return;

    // Prev button
    var prevDisabled = currentPage <= 1 ? ' disabled' : '';
    $controls.append('<button type="button" class="wcu-page-btn wcu-page-prev"' + prevDisabled + '><i class="fas fa-chevron-left"></i> Prev</button>');

    // Page numbers — show up to 5 pages with ellipsis
    var pages = buildPageNumbers(currentPage, totalPages);
    var $nums = $('<span class="wcu-page-numbers"></span>');
    for (var i = 0; i < pages.length; i++) {
      if (pages[i] === '...') {
        $nums.append('<span class="wcu-page-ellipsis">&hellip;</span>');
      } else {
        var active = pages[i] === currentPage ? ' wcu-page-active' : '';
        $nums.append('<button type="button" class="wcu-page-btn wcu-page-num' + active + '" data-page="' + pages[i] + '">' + pages[i] + '</button>');
      }
    }
    $controls.append($nums);

    // Next button
    var nextDisabled = currentPage >= totalPages ? ' disabled' : '';
    $controls.append('<button type="button" class="wcu-page-btn wcu-page-next"' + nextDisabled + '>Next <i class="fas fa-chevron-right"></i></button>');

    // Page info
    $controls.append('<span class="wcu-paginate-count">' + totalItems + ' items</span>');

    // Events
    $controls.off('click').on('click', '.wcu-page-prev:not([disabled])', function () { goToPage(currentPage - 1); });
    $controls.on('click', '.wcu-page-next:not([disabled])', function () { goToPage(currentPage + 1); });
    $controls.on('click', '.wcu-page-num:not(.wcu-page-active)', function () { goToPage(parseInt($(this).data('page'), 10)); });
  }

  /**
   * Build array of page numbers with ellipsis.
   * e.g. [1, 2, 3, '...', 10] or [1, '...', 4, 5, 6, '...', 10]
   */
  function buildPageNumbers(current, total) {
    if (total <= 7) {
      var arr = [];
      for (var i = 1; i <= total; i++) arr.push(i);
      return arr;
    }
    var pages = [1];
    if (current > 3) pages.push('...');
    var start = Math.max(2, current - 1);
    var end   = Math.min(total - 1, current + 1);
    for (var p = start; p <= end; p++) pages.push(p);
    if (current < total - 2) pages.push('...');
    pages.push(total);
    return pages;
  }

  /**
   * Build a single traffic row with a proportional bar.
   */
  function trafficRow(label, clicks, conversions, convRate, totalClicks) {
    var pct = totalClicks > 0 ? Math.round((clicks / totalClicks) * 100) : 0;
    return (
      '<div class="wcu-traffic-row">' +
        '<div class="wcu-traffic-bar" style="width:' + pct + '%"></div>' +
        '<div class="wcu-traffic-row-inner">' +
          '<span class="wcu-traffic-label">' + label + '</span>' +
          '<span class="wcu-traffic-stats">' +
            '<span class="wcu-traffic-clicks" title="Clicks">' + numberFormat(clicks, 0) + ' <small>clicks</small></span>' +
            '<span class="wcu-traffic-conv" title="Conversions">' + numberFormat(conversions, 0) + ' <small>conv.</small></span>' +
            '<span class="wcu-traffic-rate" title="Conversion rate">' + convRate + '%</span>' +
          '</span>' +
        '</div>' +
      '</div>'
    );
  }

  /* ============================================
   * TOP PERFORMERS
   * ============================================ */
  var TOP_PAGE_SIZE = 5;

  function renderTopPerformers(coupons) {
    var allSorted = {
      sales:      coupons.slice().sort(function (a, b) { return b.sales - a.sales; }),
      commission: coupons.slice().sort(function (a, b) { return b.commission - a.commission; }),
      usage:      coupons.slice().sort(function (a, b) { return b.usage - a.usage; })
    };

    populateTopList("#wcu-top-sales-list", allSorted.sales, function (c) {
      return cfg.currency + numberFormat(c.sales, 2);
    });
    populateTopList("#wcu-top-commission-list", allSorted.commission, function (c) {
      return cfg.currency + numberFormat(c.commission, 2);
    });
    populateTopList("#wcu-top-usage-list", allSorted.usage, function (c) {
      return numberFormat(c.usage, 0) + " uses";
    });

    // Aggregate products across all coupons
    var productTotals = {};
    $.each(coupons, function (i, c) {
      if (c.products && typeof c.products === "object") {
        $.each(c.products, function (name, qty) {
          productTotals[name] = (productTotals[name] || 0) + qty;
        });
      }
    });
    // Sort by quantity descending
    var allProducts = Object.keys(productTotals)
      .map(function (name) { return { name: name, qty: productTotals[name] }; })
      .sort(function (a, b) { return b.qty - a.qty; });

    populateTopProductsList("#wcu-top-products-list", allProducts);
  }

  function populateTopList(selector, allItems, valueFn) {
    var $list = $(selector).empty();
    if (allItems.length === 0) {
      $list.append("<li>\u2014</li>");
      return;
    }

    var totalPages  = Math.ceil(allItems.length / TOP_PAGE_SIZE);
    var currentPage = 1;
    var $paginateLi = $('<li class="wcu-top-paginate"></li>');
    var $controls   = $('<div class="wcu-paginate-controls"></div>');
    $paginateLi.append($controls);

    function renderPage(page) {
      currentPage = page;
      $list.find('li:not(.wcu-top-paginate)').remove();
      var start = (page - 1) * TOP_PAGE_SIZE;
      var end   = Math.min(start + TOP_PAGE_SIZE, allItems.length);
      $list.css('counter-reset', 'top-counter ' + start);
      for (var i = start; i < end; i++) {
        $paginateLi.before(buildTopListItem(allItems[i], valueFn));
      }
      renderPaginateControls($controls, currentPage, totalPages, allItems.length, renderPage);
    }

    $list.append($paginateLi);
    renderPage(1);
  }

  function buildTopListItem(c, valueFn) {
    var nameHtml = '';
    if (c.user_id && c.username && c.username !== "\u2014" && c.username !== "-") {
      var avatarHtml = c.avatar_url ? '<img src="' + escAttr(c.avatar_url) + '" class="wcu-avatar-sm" alt="">' : '';
      nameHtml = '<span class="wcu-top-user-row">' + avatarHtml + '<a href="' + escAttr(cfg.viewAffiliateUrl + c.user_id) + '" class="wcu-top-affiliate-link">' + escHtml(c.username) + '</a></span>';
      nameHtml += '<span class="wcu-top-coupon">' + escHtml(c.code) + '</span>';
    } else {
      nameHtml = escHtml(c.code);
    }
    return '<li><span class="wcu-top-name">' + nameHtml +
      '</span><span class="wcu-top-value">' + valueFn(c) + '</span></li>';
  }

  function populateTopProductsList(selector, allProducts) {
    var $list = $(selector).empty();
    if (allProducts.length === 0) {
      $list.append("<li>\u2014</li>");
      return;
    }

    var totalPages  = Math.ceil(allProducts.length / TOP_PAGE_SIZE);
    var currentPage = 1;
    var $paginateLi = $('<li class="wcu-top-paginate"></li>');
    var $controls   = $('<div class="wcu-paginate-controls"></div>');
    $paginateLi.append($controls);

    function renderPage(page) {
      currentPage = page;
      $list.find('li:not(.wcu-top-paginate)').remove();
      var start = (page - 1) * TOP_PAGE_SIZE;
      var end   = Math.min(start + TOP_PAGE_SIZE, allProducts.length);
      $list.css('counter-reset', 'top-counter ' + start);
      for (var i = start; i < end; i++) {
        $paginateLi.before(buildTopProductItem(allProducts[i]));
      }
      renderPaginateControls($controls, currentPage, totalPages, allProducts.length, renderPage);
    }

    $list.append($paginateLi);
    renderPage(1);
  }

  function buildTopProductItem(p) {
    return '<li><span class="wcu-top-name">' + escHtml(p.name) +
      '</span><span class="wcu-top-value">&times;' + numberFormat(p.qty, 0) + '</span></li>';
  }

  /* ============================================
   * TABLE SORT + RENDER
   * ============================================ */

  /** Sort coupons by the current sort dropdown value */
  function getSortedCoupons() {
    var sortVal = $("#wcu-table-sort-select").val() || "sales-desc";
    var parts = sortVal.split("-");
    var field = parts[0];
    var dir = parts[1] === "asc" ? 1 : -1;

    return allCoupons.slice().sort(function (a, b) {
      var va, vb;
      switch (field) {
        case "name":
          va = a.code.toLowerCase();
          vb = b.code.toLowerCase();
          return va < vb ? -dir : va > vb ? dir : 0;
        case "usage":      va = a.usage;      vb = b.usage;      break;
        case "sales":      va = a.sales;      vb = b.sales;      break;
        case "commission": va = a.commission; vb = b.commission; break;
        case "discounts":  va = a.discounts;  vb = b.discounts;  break;
        default:           va = a.sales;      vb = b.sales;
      }
      return (va - vb) * dir;
    });
  }

  function sortAndRenderTable() {
    renderTableRows(getSortedCoupons());
  }

  function renderTableRows(coupons) {
    var tbody = $("#wcu-report-tbody").empty();
    var search = $("#wcu-table-search-input").val().toLowerCase();

    $.each(coupons, function (i, c) {
      // Client-side search filter
      if (
        search &&
        c.code.toLowerCase().indexOf(search) === -1 &&
        c.username.toLowerCase().indexOf(search) === -1
      ) {
        return; // skip
      }

      var row = "<tr>";

      // Coupon name
      row += '<td class="wcu-col-name">';
      if (c.edit_url) {
        row +=
          '<a href="' +
          escAttr(c.edit_url) +
          '" target="_blank">' +
          escHtml(c.code) +
          "</a>";
      } else {
        row += "<strong>" + escHtml(c.code) + "</strong>";
      }
      row += '<div class="wcu-coupon-actions">';
      if (c.edit_url) {
        row +=
          '<a href="' +
          escAttr(c.edit_url) +
          '" target="_blank" title="' +
          cfg.i18n.editCoupon +
          '"><i class="fas fa-edit"></i></a>';
      }
      if (c.dashboard_url) {
        row +=
          '<a href="' +
          escAttr(c.dashboard_url) +
          '" target="_blank" title="' +
          cfg.i18n.viewDashboard +
          '"><i class="fas fa-external-link-alt"></i></a>';
      }
      row += "</div></td>";

      // Affiliate
      row += '<td class="wcu-col-affiliate">';
      if (c.user_id && c.username && c.username !== "\u2014" && c.username !== "-") {
        row += '<span class="wcu-affiliate-cell">';
        if (c.avatar_url) {
          row += '<img src="' + escAttr(c.avatar_url) + '" class="wcu-avatar-sm" alt="">';
        }
        row += '<a href="' + escAttr(cfg.viewAffiliateUrl + c.user_id) + '" class="wcu-affiliate-link">' + escHtml(c.username) + '</a>';
        row += '</span>';
      } else {
        row += cfg.i18n.noAffiliate;
      }
      row += "</td>";

      // Usage
      row += '<td class="wcu-col-usage wcu-col-sales">' + c.usage;
      if (c.compare) row += changeHtml(c.compare.usage);
      row += "</td>";

      // Sales
      row +=
        '<td class="wcu-col-orders wcu-col-sales">' +
        cfg.currency +
        numberFormat(c.sales, 2);
      if (c.compare) row += changeHtml(c.compare.sales);
      row += "</td>";

      // Discounts
      row +=
        '<td class="wcu-col-discounts wcu-col-sales">' +
        cfg.currency +
        numberFormat(c.discounts, 2);
      if (c.compare) row += changeHtml(c.compare.discounts);
      row += "</td>";

      // Commission
      row +=
        '<td class="wcu-col-commission wcu-col-comm">' +
        cfg.currency +
        numberFormat(c.commission, 2);
      if (c.compare) row += changeHtml(c.compare.commission);
      row += "</td>";

      // Unpaid + Pending (PRO with tracking)
      if (cfg.isPro && cfg.trackingEnabled) {
        row +=
          '<td class="wcu-col-unpaid wcu-col-comm">' +
          cfg.currency +
          numberFormat(c.unpaid, 2) +
          "</td>";
        row +=
          '<td class="wcu-col-pending wcu-col-comm">' +
          cfg.currency +
          numberFormat(c.pending, 2) +
          "</td>";
      }

      // Clicks
      row += '<td class="wcu-col-clicks wcu-col-ref">' + c.clicks;
      if (c.compare) row += changeHtml(c.compare.clicks);
      row += "</td>";

      // Conversions
      row += '<td class="wcu-col-conversions wcu-col-ref">' + c.conversions;
      if (c.compare) row += changeHtml(c.compare.conversions);
      row += "</td>";

      // Conversion rate
      row +=
        '<td class="wcu-col-convrate wcu-col-ref">' +
        c.conversionrate +
        "%";
      if (c.compare) row += changeHtml(c.compare.convrate);
      row += "</td>";

      // Products
      row += '<td class="wcu-col-products wcu-col-prod">';
      if (c.products && Object.keys(c.products).length > 0) {
        var productKeys = Object.keys(c.products);
        for (var p = 0; p < productKeys.length && p < 5; p++) {
          row +=
            '<span class="wcu-product-item">' +
            escHtml(productKeys[p]) +
            " &times;" +
            c.products[productKeys[p]] +
            "</span>";
        }
      } else {
        row +=
          '<span style="color:#ccc;">' + cfg.i18n.noProducts + "</span>";
      }
      row += "</td>";

      row += "</tr>";
      tbody.append(row);
    });
  }

  /* ============================================
   * SECTION VISIBILITY
   * ============================================ */
  function updateSectionVisibility() {
    var container = $(".wcusage-reports-modern");

    // Sales
    if ($("#wcu-show-sales").is(":checked")) {
      container.removeClass("wcu-hide-sales");
      $("#wcu-section-sales").show();
    } else {
      container.addClass("wcu-hide-sales");
      $("#wcu-section-sales").hide();
    }

    // Commission
    if ($("#wcu-show-commission").is(":checked")) {
      container.removeClass("wcu-hide-commission");
      $("#wcu-section-commission").show();
    } else {
      container.addClass("wcu-hide-commission");
      $("#wcu-section-commission").hide();
    }

    // Referrals
    if ($("#wcu-show-referrals").is(":checked")) {
      container.removeClass("wcu-hide-referrals");
      $("#wcu-section-referrals").show();
    } else {
      container.addClass("wcu-hide-referrals");
      $("#wcu-section-referrals").hide();
    }

    // Activity
    if ($("#wcu-show-activity").is(":checked")) {
      container.removeClass("wcu-hide-activity");
      $("#wcu-section-activity").show();
    } else {
      container.addClass("wcu-hide-activity");
      $("#wcu-section-activity").hide();
    }

    // Trends
    if ($("#wcu-show-trends").is(":checked")) {
      container.removeClass("wcu-hide-trends");
      $("#wcu-section-trends").show();
      renderTrendsChart();
    } else {
      container.addClass("wcu-hide-trends");
      $("#wcu-section-trends").hide();
    }

    // Traffic Sources
    if ($("#wcu-show-traffic").is(":checked")) {
      container.removeClass("wcu-hide-traffic");
      $("#wcu-section-traffic").show();
    } else {
      container.addClass("wcu-hide-traffic");
      $("#wcu-section-traffic").hide();
    }

    // Products column — re-render table so column is fully added/removed
    if ($("#wcu-show-products").is(":checked")) {
      container.removeClass("wcu-hide-products");
    } else {
      container.addClass("wcu-hide-products");
    }

    // Top Performers
    if ($("#wcu-show-top-performers").is(":checked")) {
      container.removeClass("wcu-hide-top-performers");
      $("#wcu-top-performers").show();
    } else {
      container.addClass("wcu-hide-top-performers");
      $("#wcu-top-performers").hide();
    }

    // Individual Coupons
    if ($("#wcu-show-coupons").is(":checked")) {
      container.removeClass("wcu-hide-coupons");
      $(".wcu-coupon-table-section").show();
    } else {
      container.addClass("wcu-hide-coupons");
      $(".wcu-coupon-table-section").hide();
    }

    sortAndRenderTable();
  }

  /* ============================================
   * COLUMN VISIBILITY (per-column chooser)
   * ============================================ */
  function updateColumnVisibility() {
    var table = $("#wcu-report-table");
    var hidden = {};
    $("#wcu-col-chooser-dropdown input[type='checkbox']").each(function () {
      var col = $(this).data("col");
      if (!col) return;
      var cls = "wcu-hide-" + col;
      if (this.checked) {
        table.removeClass(cls);
      } else {
        table.addClass(cls);
        hidden[col] = true;
      }
    });

    // Hide group headers when ALL child columns are hidden; adjust colspan otherwise
    var groups = [
      { cls: "wcu-hide-group-affiliate", sel: ".wcu-group-header.wcu-col-affiliate", cols: ["col-name", "col-affiliate"] },
      { cls: "wcu-hide-group-sales",     sel: ".wcu-group-header.wcu-col-sales",     cols: ["col-usage", "col-orders", "col-discounts"] },
      { cls: "wcu-hide-group-comm",      sel: ".wcu-group-header.wcu-col-comm",      cols: ["col-commission", "col-unpaid", "col-pending"] },
      { cls: "wcu-hide-group-ref",       sel: ".wcu-group-header.wcu-col-ref",       cols: ["col-clicks", "col-conversions", "col-convrate"] },
      { cls: "wcu-hide-group-products",  sel: ".wcu-group-header.wcu-col-prod",      cols: ["col-products"] }
    ];
    $.each(groups, function (_, g) {
      var visibleCount = 0;
      $.each(g.cols, function (_, c) {
        var $cb = $("#wcu-col-chooser-dropdown input[data-col='" + c + "']");
        // Skip columns that don't exist in the chooser (e.g. PRO-only cols in free)
        if ($cb.length === 0) return;
        // Count as visible if checkbox is checked (includes disabled+checked)
        if (!hidden[c]) { visibleCount++; }
      });
      if (visibleCount === 0) {
        table.addClass(g.cls);
      } else {
        table.removeClass(g.cls);
        table.find(g.sel).attr("colspan", visibleCount);
      }
    });
  }

  /* ============================================
   * PERSIST FILTERS (localStorage)
   * ============================================ */
  var STORAGE_KEY = "wcu_report_filters";

  function saveFilters() {
    try {
      // Only save the date preset if it is a named one (not custom)
      var activePreset = $("#wcu-date-preset-select").val() || "";
      var saveDate = activePreset && activePreset !== "custom";

      var data = {
        // Date — only when a named preset is active
        preset:            saveDate ? activePreset : null,
        // Affiliate filters
        affiliatesOnly:    $("#wcu-affiliates-only").is(":checked"),
        groupRole:         $("#wcu-report-group-role").val() || "",
        // Advanced filters
        usageType:         $("#wcu-filter-usage-type").val(),
        usageAmount:       $("#wcu-filter-usage-amount").val(),
        salesType:         $("#wcu-filter-sales-type").val(),
        salesAmount:       $("#wcu-filter-sales-amount").val(),
        commissionType:    $("#wcu-filter-commission-type").val(),
        commissionAmount:  $("#wcu-filter-commission-amount").val(),
        conversionsType:   $("#wcu-filter-conversions-type").val(),
        conversionsAmount: $("#wcu-filter-conversions-amount").val(),
        unpaidType:        $("#wcu-filter-unpaid-type").val(),
        unpaidAmount:      $("#wcu-filter-unpaid-amount").val(),
        // Compare (PRO)
        compareEnabled:    $("#wcu-compare-enable").is(":checked"),
        compareStart:      $("#wcu-compare-start").val(),
        compareEnd:        $("#wcu-compare-end").val(),
        compareFilterType:   $("#wcu-compare-filter-type").val(),
        compareFilterAmount: $("#wcu-compare-filter-amount").val(),
        // Advanced panel open/closed
        advancedOpen:      $("#wcu-advanced-toggle").hasClass("wcu-adv-open"),
        // Column chooser
        hiddenCols: (function () {
          var hidden = [];
          $("#wcu-col-chooser-dropdown input[type='checkbox']").each(function () {
            if (!this.checked) hidden.push($(this).data("col"));
          });
          return hidden;
        }()),
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    } catch (e) { /* storage unavailable */ }
  }

  function restoreFilters() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      var d = JSON.parse(raw);

      // Date preset (only if a named preset was saved)
      if (d.preset && d.preset !== "custom") {
        var $sel = $("#wcu-date-preset-select");
        if ($sel.find("option[value='" + d.preset + "']").length) {
          $sel.val(d.preset);
          applyDatePreset(d.preset);
        }
      }

      // Affiliate filters
      if (d.affiliatesOnly !== undefined) $("#wcu-affiliates-only").prop("checked", d.affiliatesOnly);
      if (d.groupRole      !== undefined) $("#wcu-report-group-role").val(d.groupRole);

      // Advanced filters
      if (d.usageType         !== undefined) $("#wcu-filter-usage-type").val(d.usageType);
      if (d.usageAmount       !== undefined) $("#wcu-filter-usage-amount").val(d.usageAmount);
      if (d.salesType         !== undefined) $("#wcu-filter-sales-type").val(d.salesType);
      if (d.salesAmount       !== undefined) $("#wcu-filter-sales-amount").val(d.salesAmount);
      if (d.commissionType    !== undefined) $("#wcu-filter-commission-type").val(d.commissionType);
      if (d.commissionAmount  !== undefined) $("#wcu-filter-commission-amount").val(d.commissionAmount);
      if (d.conversionsType   !== undefined) $("#wcu-filter-conversions-type").val(d.conversionsType);
      if (d.conversionsAmount !== undefined) $("#wcu-filter-conversions-amount").val(d.conversionsAmount);
      if (d.unpaidType        !== undefined) $("#wcu-filter-unpaid-type").val(d.unpaidType);
      if (d.unpaidAmount      !== undefined) $("#wcu-filter-unpaid-amount").val(d.unpaidAmount);

      // Compare (PRO)
      if (cfg.isPro && d.compareEnabled) {
        $("#wcu-compare-enable").prop("checked", true);
        $("#wcu-compare-dates").show();
        if (d.compareStart)        $("#wcu-compare-start").val(d.compareStart);
        if (d.compareEnd)          $("#wcu-compare-end").val(d.compareEnd);
        if (d.compareFilterType)   $("#wcu-compare-filter-type").val(d.compareFilterType);
        if (d.compareFilterAmount) $("#wcu-compare-filter-amount").val(d.compareFilterAmount);
      }

      // Reposition advanced panel based on compare state
      repositionAdvancedPanel();

      // Advanced panel open/closed
      if (d.advancedOpen) {
        $("#wcu-advanced-toggle").addClass("wcu-adv-open");
        $("#wcu-filter-advanced-panel").show();
      }

      // Column chooser hidden columns
      if (d.hiddenCols && d.hiddenCols.length) {
        $.each(d.hiddenCols, function (i, col) {
          $("#wcu-col-chooser-dropdown input[data-col='" + col + "']").prop("checked", false);
        });
        updateColumnVisibility();
      }
    } catch (e) { /* corrupted storage — ignore */ }
  }

  /* ============================================
   * PDF DOWNLOAD
   * ============================================ */
  function downloadPDF() {
    var btn = $("#wcu-download-pdf");
    var origHtml = btn.html();
    btn.html('<i class="fas fa-spinner fa-spin"></i> Generating PDF...').prop("disabled", true);

    var content = document.getElementById("wcu-reports-content");
    if (!content) {
      btn.html(origHtml).prop("disabled", false);
      return;
    }

    // Hide elements that shouldn't appear in PDF
    var pdfBtn = document.getElementById("wcu-download-pdf");
    var tableControls = content.querySelector(".wcu-table-controls");
    var couponCount = content.querySelector(".wcu-coupon-count");
    var paginateControls = content.querySelectorAll(".wcu-paginate-controls");
    if (pdfBtn) pdfBtn.style.display = "none";
    if (tableControls) tableControls.style.display = "none";
    if (couponCount) couponCount.style.display = "none";
    for (var pi = 0; pi < paginateControls.length; pi++) {
      paginateControls[pi].style.display = "none";
    }

    // Temporarily expand table to full width for capture
    var tableWrap = content.querySelector(".wcu-table-wrap");
    var origOverflow, origMaxH;
    if (tableWrap) {
      origOverflow = tableWrap.style.overflow;
      origMaxH = tableWrap.style.maxHeight;
      tableWrap.style.overflow = "visible";
      tableWrap.style.maxHeight = "none";
    }

    // Calculate the target capture width so the content fills the PDF page
    // A4 landscape = 297mm wide, with 10mm margins = 277mm usable
    var pdfPageW = 297;
    var pdfMargin = 10;
    var targetWidthPx = Math.max(content.scrollWidth, 1140);

    // Temporarily widen the content container for a full-width capture
    var origContentWidth = content.style.width;
    var origContentMaxWidth = content.style.maxWidth;
    var origContentMinWidth = content.style.minWidth;
    content.style.width = targetWidthPx + "px";
    content.style.maxWidth = targetWidthPx + "px";
    content.style.minWidth = targetWidthPx + "px";

    // Kill all CSS animations/transitions so html2canvas never captures
    // mid-animation elements (the wcu-fadeIn animation can cause a faded look).
    content.style.animation = "none";
    content.style.transition = "none";
    var animEls = content.querySelectorAll(".wcu-section-cards, .wcu-reports-content, .wcu-top-performers, .wcu-section-trends");
    var origAnims = [];
    for (var ai = 0; ai < animEls.length; ai++) {
      origAnims.push(animEls[ai].style.animation);
      animEls[ai].style.animation = "none";
    }
    // Force a reflow so the browser applies the style changes before capture
    void content.offsetHeight;

    /** Restore all temporarily modified DOM styles */
    function restoreDOM() {
      content.style.width = origContentWidth;
      content.style.maxWidth = origContentMaxWidth;
      content.style.minWidth = origContentMinWidth;
      content.style.animation = "";
      content.style.transition = "";
      for (var aj = 0; aj < animEls.length; aj++) {
        animEls[aj].style.animation = origAnims[aj] || "";
      }
      if (pdfBtn) pdfBtn.style.display = "";
      if (tableControls) tableControls.style.display = "";
      if (couponCount) couponCount.style.display = "";
      for (var pk = 0; pk < paginateControls.length; pk++) {
        paginateControls[pk].style.display = "";
      }
      if (tableWrap) {
        tableWrap.style.overflow = origOverflow;
        tableWrap.style.maxHeight = origMaxH;
      }
    }

    // ── Collect safe page-break Y positions (canvas px, relative to content top) ──
    // Two layers: section boundaries (high priority) and table-row boundaries.
    var canvasScale = 2; // must match html2canvas scale option
    var contentRect = content.getBoundingClientRect();

    /**
     * sectionBreaks – array of {top, bottom} in canvas-px for every major
     * section. A page break should ideally land at a section top so nothing
     * is sliced through the middle.
     */
    var sectionBreaks = [];
    var sectionSels = [
      ".wcu-section-sales",
      ".wcu-section-commission",
      ".wcu-section-referrals",
      ".wcu-section-trends",
      ".wcu-section-traffic",
      ".wcu-top-performers",
      ".wcu-section-activity",
      ".wcu-coupon-table-section"
    ];
    sectionSels.forEach(function (sel) {
      var el = content.querySelector(sel);
      if (el && el.offsetParent !== null) { // visible only
        var r = el.getBoundingClientRect();
        sectionBreaks.push({
          top: Math.round((r.top - contentRect.top) * canvasScale),
          bottom: Math.round((r.bottom - contentRect.top) * canvasScale)
        });
      }
    });

    /** rowBreaks – table-row Y positions (canvas px) for fine-grained snapping */
    var rowBreaks = [];
    var tbodyEl = content.querySelector("#wcu-report-tbody");
    if (tbodyEl) {
      var trs = tbodyEl.querySelectorAll("tr");
      for (var ri = 0; ri < trs.length; ri++) {
        var trRect = trs[ri].getBoundingClientRect();
        rowBreaks.push(Math.round((trRect.top - contentRect.top) * canvasScale));
      }
      if (trs.length) {
        var lastTr = trs[trs.length - 1].getBoundingClientRect();
        rowBreaks.push(Math.round((lastTr.bottom - contentRect.top) * canvasScale));
      }
    }

    // Use html2canvas to capture the report area
    html2canvas(content, {
      scale: canvasScale,
      useCORS: true,
      logging: false,
      backgroundColor: "#ffffff",
      windowWidth: targetWidthPx,
      width: targetWidthPx,
      height: content.scrollHeight,
      scrollX: 0,
      scrollY: 0
    }).then(function (canvas) {
      restoreDOM();

      var jsPDF = window.jspdf.jsPDF;
      var imgData = canvas.toDataURL("image/jpeg", 1.0);
      var imgW = canvas.width;
      var imgH = canvas.height;

      // A4 landscape for wide tables
      var pdf = new jsPDF("l", "mm", "a4");
      var pageW = pdf.internal.pageSize.getWidth();
      var pageH = pdf.internal.pageSize.getHeight();
      var margin = pdfMargin;
      var usableW = pageW - margin * 2;
      var usableH = pageH - margin * 2;

      // ── Build PDF title / filter summary ──
      var startDate = $("#wcu-report-start").val() || "";
      var endDate = $("#wcu-report-end").val() || "";

      // ── Render title header on PDF ──
      var titleY = margin;
      // Title
      pdf.setFont("helvetica", "bold");
      pdf.setFontSize(18);
      pdf.setTextColor(30, 30, 30);
      pdf.text("Coupon Affiliates Report", margin, titleY + 6);
      titleY += 10;

      // Thin separator line
      titleY += 2;
      pdf.setDrawColor(200, 200, 200);
      pdf.setLineWidth(0.3);
      pdf.line(margin, titleY, pageW - margin, titleY);
      titleY += 4;

      // Small gap before report content
      titleY += 3;

      var headerH = titleY - margin; // total height consumed by header
      var contentUsableH = usableH - headerH; // remaining on first page

      // Scale image to fill the full usable page width
      var ratio = usableW / imgW;
      var scaledH = imgH * ratio;

      /**
       * Find the best Y (canvas px) at which to cut a page so that no
       * section is sliced through its middle.
       *
       * Strategy (in priority order):
       *  1. If a section straddles the proposed cut (its top is above and
       *     its bottom is below), move the cut UP to that section's top
       *     so the entire section starts on the next page — unless the
       *     section is taller than the available page height (in which
       *     case we can't avoid cutting it and fall through to step 2).
       *  2. Snap to the nearest table-row boundary that is at or before
       *     the proposed cut (same logic as the old snapToRowBreak).
       *  3. Fall back to the raw proposed value.
       */
      function snapToBreak(proposedY, pageSliceH, pageStartY) {
        // ── 1. Section-level snapping ──
        for (var s = 0; s < sectionBreaks.length; s++) {
          var sec = sectionBreaks[s];
          // Section straddles the proposed cut?
          if (sec.top < proposedY && sec.bottom > proposedY) {
            var sectionH = sec.bottom - sec.top;
            // Only bump to section top if the section fits on one page,
            // the section top is after the current page start,
            // AND we wouldn't lose more than 50% of page space.
            if (sectionH <= pageSliceH && sec.top > pageStartY && sec.top > proposedY - pageSliceH * 0.5) {
              return sec.top;
            }
            // Section too tall to fit one page — fall through to row snap
            break;
          }
        }
        // ── 2. Table-row-level snapping ──
        if (rowBreaks.length) {
          var best = proposedY;
          for (var b = rowBreaks.length - 1; b >= 0; b--) {
            if (rowBreaks[b] <= proposedY && rowBreaks[b] > pageStartY) {
              best = rowBreaks[b];
              break;
            }
          }
          // Don't snap if it would waste more than 40 % of the page
          if (best >= proposedY * 0.6) return best;
        }
        // ── 3. Fallback ──
        return proposedY;
      }

      // If the content fits on the first page (below the header)
      if (scaledH <= contentUsableH) {
        pdf.addImage(imgData, "JPEG", margin, titleY, usableW, scaledH, undefined, "NONE");
      } else {
        // Multi-page: slice the canvas into page-sized chunks,
        // snapping cuts to section / table-row boundaries.
        var firstPageSliceH = Math.floor(contentUsableH / ratio);
        var subsequentSliceH = Math.floor(usableH / ratio);
        var y = 0;
        var page = 0;
        while (y < imgH) {
          if (page > 0) pdf.addPage();
          var maxSliceH = page === 0 ? firstPageSliceH : subsequentSliceH;
          var proposedEnd = y + maxSliceH;
          // If the remaining content fits on this page, don't snap — just use it all
          var snappedEnd;
          if (proposedEnd >= imgH) {
            snappedEnd = imgH;
          } else {
            snappedEnd = snapToBreak(proposedEnd, maxSliceH, y);
          }
          var thisSliceH = Math.min(snappedEnd - y, imgH - y);
          if (thisSliceH <= 0) thisSliceH = Math.min(maxSliceH, imgH - y);
          // Create a sub-canvas for this page slice
          var sliceCanvas = document.createElement("canvas");
          sliceCanvas.width = imgW;
          sliceCanvas.height = thisSliceH;
          var ctx = sliceCanvas.getContext("2d");
          // Fill with white first (JPEG has no transparency)
          ctx.fillStyle = "#ffffff";
          ctx.fillRect(0, 0, imgW, thisSliceH);
          ctx.drawImage(canvas, 0, y, imgW, thisSliceH, 0, 0, imgW, thisSliceH);
          var sliceData = sliceCanvas.toDataURL("image/jpeg", 1.0);
          var sliceScaledH = thisSliceH * ratio;
          var imgY = page === 0 ? titleY : margin;
          pdf.addImage(sliceData, "JPEG", margin, imgY, usableW, sliceScaledH, undefined, "NONE");
          y += thisSliceH;
          page++;
        }
      }

      // Build filename
      var filename = "coupon-report-" + startDate + "-to-" + endDate + ".pdf";
      pdf.save(filename);

      btn.html(origHtml).prop("disabled", false);
    }).catch(function (err) {
      console.error('PDF generation error:', err);
      restoreDOM();
      btn.html(origHtml).prop("disabled", false);
      alert("Failed to generate PDF. Please try again.");
    });
  }

  /* ============================================
   * CSV EXPORT
   * ============================================ */
  function exportCSV() {
    if (!allCoupons.length) return;

    var sorted = getSortedCoupons();

    // Apply the same search filter as the table
    var search = $("#wcu-table-search-input").val().toLowerCase();
    if (search) {
      sorted = sorted.filter(function (c) {
        return (
          c.code.toLowerCase().indexOf(search) !== -1 ||
          c.username.toLowerCase().indexOf(search) !== -1
        );
      });
    }

    // Determine which columns are visible via the column chooser
    var hiddenCols = {};
    $("#wcu-col-chooser-dropdown input[type='checkbox']").each(function () {
      if (!this.checked) hiddenCols[$(this).data("col")] = true;
    });
    // Also respect section pills (hide entire groups if unchecked)
    var hideSales      = !$("#wcu-show-sales").is(":checked");
    var hideCommission = !$("#wcu-show-commission").is(":checked");
    var hideReferrals  = !$("#wcu-show-referrals").is(":checked");
    var hideProducts   = !$("#wcu-show-products").is(":checked");

    // Map CSV column keys to column-chooser data-col values
    var colKeyToDataCol = {
      code: "col-name", affiliate: "col-affiliate", usage: "col-usage",
      orders: "col-orders", discounts: "col-discounts", commission: "col-commission",
      unpaid: "col-unpaid", pending: "col-pending", clicks: "col-clicks",
      conversions: "col-conversions", convrate: "col-convrate", products: "col-products"
    };

    function colVisible(name, group) {
      if (hiddenCols[colKeyToDataCol[name]]) return false;
      if (group === "sales"      && hideSales)      return false;
      if (group === "commission" && hideCommission)  return false;
      if (group === "referrals"  && hideReferrals)  return false;
      if (group === "products"   && hideProducts)   return false;
      return true;
    }

    // Build header + rows only for visible columns
    var colDefs = [
      { key: "code",           label: "Coupon",       group: null },
      { key: "affiliate",      label: "Affiliate",    group: null },
      { key: "usage",          label: "Usage",        group: "sales" },
      { key: "orders",         label: "Sales",        group: "sales" },
      { key: "discounts",      label: "Discounts",    group: "sales" },
      { key: "commission",     label: "Commission",   group: "commission" },
    ];
    if (cfg.isPro && cfg.trackingEnabled) {
      colDefs.push(
        { key: "unpaid",   label: "Unpaid",   group: "commission" },
        { key: "pending",  label: "Pending",  group: "commission" }
      );
    }
    colDefs.push(
      { key: "clicks",       label: "Clicks",       group: "referrals" },
      { key: "conversions",  label: "Conversions",  group: "referrals" },
      { key: "convrate",     label: "Conv. Rate",   group: "referrals" },
      { key: "products",     label: "Top Products", group: "products" }
    );

    var visibleCols = colDefs.filter(function (col) {
      return colVisible(col.key, col.group);
    });

    var headers = visibleCols.map(function (col) { return col.label; });
    var rows = [headers];

    $.each(sorted, function (i, c) {
      // Resolve affiliate: treat "—", empty, or missing as blank
      var affiliateName = (c.username && c.username !== "\u2014" && c.username !== "-") ? c.username : "";

      var row = [];
      $.each(visibleCols, function (j, col) {
        switch (col.key) {
          case "code":        row.push(c.code);                         break;
          case "affiliate":   row.push(affiliateName);                  break;
          case "usage":       row.push(c.usage);                        break;
          case "orders":      row.push(c.sales);                        break;
          case "discounts":   row.push(c.discounts);                    break;
          case "commission":  row.push(c.commission);                   break;
          case "unpaid":      row.push(c.unpaid);                       break;
          case "pending":     row.push(c.pending);                      break;
          case "clicks":      row.push(c.clicks);                       break;
          case "conversions": row.push(c.conversions);                  break;
          case "convrate":    row.push(c.conversionrate + "%");         break;
          case "products": {
            var prodStr = "";
            if (c.products && Object.keys(c.products).length > 0) {
              var pArr = [];
              $.each(c.products, function (name, qty) { pArr.push(name + " x" + qty); });
              prodStr = pArr.join("; ");
            }
            row.push(prodStr);
            break;
          }
        }
      });
      rows.push(row);
    });

    // Build CSV string
    var csvContent = "";
    $.each(rows, function (i, row) {
      var escaped = $.map(row, function (cell) {
        var str = String(cell).replace(/"/g, '""');
        return '"' + str + '"';
      });
      csvContent += escaped.join(",") + "\n";
    });

    // Download
    var blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute(
      "download",
      "coupon-report-" +
        $("#wcu-report-start").val() +
        "_" +
        $("#wcu-report-end").val() +
        ".csv"
    );
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  /* ============================================
   * NO RESULTS
   * ============================================ */
  function showNoResults() {
    $("#wcu-reports-content").hide();
    $("#wcu-no-results").fadeIn(200);
  }

  /* ============================================
   * MULTI-LINE TRENDS CHART (Canvas)
   * ============================================ */

  /**
   * Line definitions: key matches timeseries property, color, label, and
   * whether it uses the left Y-axis (currency) or the right Y-axis (count).
   */
  var trendLines = [
    { key: "sales",      color: "#00a32a", label: "Sales",      axis: "left" },
    { key: "commission", color: "#2271b1", label: "Commission", axis: "left" },
    { key: "clicks",     color: "#7c3aed", label: "Clicks",     axis: "right" }
  ];

  /** Cached chart geometry so mousemove can map to data points. */
  var chartGeo = null;

  function renderTrendsChart() {
    var canvas = document.getElementById("wcu-trends-canvas");
    if (!canvas || !lastTimeseries) return;
    var tsRaw = lastTimeseries;
    if (!tsRaw.dates || tsRaw.dates.length < 2) return;

    // Determine which lines are active via legend toggles
    var active = [];
    $("#wcu-trends-legend .wcu-trend-toggle.wcu-trend-active").each(function () {
      var k = $(this).data("line");
      for (var i = 0; i < trendLines.length; i++) {
        if (trendLines[i].key === k) { active.push(trendLines[i]); break; }
      }
    });
    if (!active.length) {
      // Nothing selected — clear canvas
      var ctx0 = canvas.getContext("2d");
      canvas.width = canvas.parentNode.offsetWidth;
      ctx0.clearRect(0, 0, canvas.width, canvas.height);
      chartGeo = null;
      return;
    }

    // Trim leading zero-only days so the chart starts at the first date
    // that has a non-zero value on any active line.
    var firstIdx = 0;
    for (var fi = 0; fi < tsRaw.dates.length; fi++) {
      var hasValue = false;
      for (var ai = 0; ai < active.length; ai++) {
        if (tsRaw[active[ai].key] && tsRaw[active[ai].key][fi] > 0) {
          hasValue = true;
          break;
        }
      }
      if (hasValue) { firstIdx = fi; break; }
    }
    var ts;
    if (firstIdx > 0 && (tsRaw.dates.length - firstIdx) >= 2) {
      ts = {
        dates:      tsRaw.dates.slice(firstIdx),
        sales:      tsRaw.sales.slice(firstIdx),
        commission: tsRaw.commission.slice(firstIdx),
        discounts:  tsRaw.discounts.slice(firstIdx),
        usage:      tsRaw.usage.slice(firstIdx),
        clicks:     tsRaw.clicks.slice(firstIdx)
      };
    } else {
      ts = tsRaw;
    }
    if (ts.dates.length < 2) return;

    // Separate lines into left/right axis groups
    var leftLines = active.filter(function (l) { return l.axis === "left"; });
    var rightLines = active.filter(function (l) { return l.axis === "right"; });

    // Set canvas size to container (retina-aware)
    var wrap = canvas.parentNode;
    var dpr = window.devicePixelRatio || 1;
    var W = wrap.offsetWidth;
    var H = Math.max(280, wrap.offsetHeight || 320);
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width = W + "px";
    canvas.style.height = H + "px";

    var ctx = canvas.getContext("2d");
    ctx.scale(dpr, dpr);

    // Chart padding — minimal so chart fills the full width
    var padTop = 12, padBottom = 30;
    var padLeft = 8;
    var padRight = 8;
    var chartW = W - padLeft - padRight;
    var chartH = H - padTop - padBottom;

    var n = ts.dates.length;

    // Helper: compute nice axis max
    function niceMax(arr) {
      var m = Math.max.apply(null, arr);
      if (m === 0) return 10;
      var magnitude = Math.pow(10, Math.floor(Math.log10(m)));
      var res = Math.ceil(m / magnitude) * magnitude;
      if (res === m) res += magnitude;
      return res;
    }

    // Compute per-line max so each line's peak touches the top of the chart
    // (with a small 5% headroom so dots aren't clipped).
    var lineMax = {};
    active.forEach(function (l) {
      var m = Math.max.apply(null, ts[l.key]);
      lineMax[l.key] = m > 0 ? m * 1.05 : 10;
    });

    // Axis label ranges (use the largest per-line max on each axis)
    var leftMax = 0, rightMax = 0;
    leftLines.forEach(function (l) {
      if (lineMax[l.key] > leftMax) leftMax = lineMax[l.key];
    });
    rightLines.forEach(function (l) {
      if (lineMax[l.key] > rightMax) rightMax = lineMax[l.key];
    });
    if (leftMax === 0) leftMax = 10;
    if (rightMax === 0) rightMax = 10;
    // Scale right axis so clicks only fills ~half the chart height
    rightMax = rightMax * 2;

    // ── Clear & draw background ──
    ctx.clearRect(0, 0, W, H);

    // ── Grid lines ──
    var gridSteps = 5;
    ctx.strokeStyle = "#f0f0f0";
    ctx.lineWidth = 1;
    for (var g = 0; g <= gridSteps; g++) {
      var gy = padTop + (g / gridSteps) * chartH;
      ctx.beginPath();
      ctx.moveTo(padLeft, gy);
      ctx.lineTo(padLeft + chartW, gy);
      ctx.stroke();
    }

    // ── Y-axis labels (left = currency, inside chart) ──
    if (leftLines.length) {
      ctx.fillStyle = "rgba(107,114,128,0.7)";
      ctx.font = "10px -apple-system, BlinkMacSystemFont, sans-serif";
      ctx.textAlign = "left";
      ctx.textBaseline = "bottom";
      for (var gl = 0; gl <= gridSteps; gl++) {
        var yVal = leftMax - (gl / gridSteps) * leftMax;
        var yPos = padTop + (gl / gridSteps) * chartH;
        ctx.fillText(formatAxisVal(yVal, true), padLeft + 4, yPos - 2);
      }
    }

    // ── Y-axis labels (right = count, inside chart) ──
    if (rightLines.length) {
      ctx.fillStyle = "rgba(107,114,128,0.7)";
      ctx.font = "10px -apple-system, BlinkMacSystemFont, sans-serif";
      ctx.textAlign = "right";
      ctx.textBaseline = "bottom";
      for (var gr = 0; gr <= gridSteps; gr++) {
        var rVal = rightMax - (gr / gridSteps) * rightMax;
        var rPos = padTop + (gr / gridSteps) * chartH;
        ctx.fillText(formatAxisVal(rVal, false), padLeft + chartW - 4, rPos - 2);
      }
    }

    // ── X-axis date labels ──
    ctx.fillStyle = "#9ca3af";
    ctx.font = "10px -apple-system, BlinkMacSystemFont, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "top";
    var maxLabels = Math.floor(chartW / 70);
    var labelStep = Math.max(1, Math.ceil(n / maxLabels));
    var lastDrawnXi = 0;
    for (var xi = 0; xi < n; xi += labelStep) {
      var xPos = padLeft + (xi / (n - 1)) * chartW;
      var dateLabel = formatDateLabel(ts.dates[xi]);
      ctx.fillText(dateLabel, xPos, padTop + chartH + 8);
      lastDrawnXi = xi;
    }
    // Draw last label only if it wasn't already drawn and there's enough gap
    if ((n - 1) % labelStep !== 0) {
      var lastXPos = padLeft + chartW;
      var prevXPos = padLeft + (lastDrawnXi / (n - 1)) * chartW;
      if (lastXPos - prevXPos > 50) {
        ctx.fillText(formatDateLabel(ts.dates[n - 1]), lastXPos, padTop + chartH + 8);
      }
    }

    // ── Draw lines ──
    function drawLine(lineObj) {
      var data = ts[lineObj.key];
      if (!data) return;
      var maxVal = lineMax[lineObj.key];

      // Area fill
      ctx.beginPath();
      for (var i = 0; i < n; i++) {
        var x = padLeft + (i / (n - 1)) * chartW;
        var y = padTop + chartH - (data[i] / maxVal) * chartH;
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      }
      ctx.lineTo(padLeft + chartW, padTop + chartH);
      ctx.lineTo(padLeft, padTop + chartH);
      ctx.closePath();
      ctx.fillStyle = hexToRgba(lineObj.color, 0.07);
      ctx.fill();

      // Line
      ctx.beginPath();
      for (var j = 0; j < n; j++) {
        var lx = padLeft + (j / (n - 1)) * chartW;
        var ly = padTop + chartH - (data[j] / maxVal) * chartH;
        if (j === 0) ctx.moveTo(lx, ly);
        else ctx.lineTo(lx, ly);
      }
      ctx.strokeStyle = lineObj.color;
      ctx.lineWidth = 2;
      ctx.lineJoin = "round";
      ctx.lineCap = "round";
      ctx.stroke();

      // Dots (only if ≤ 60 data points)
      if (n <= 60) {
        for (var d = 0; d < n; d++) {
          var dx = padLeft + (d / (n - 1)) * chartW;
          var dy = padTop + chartH - (data[d] / maxVal) * chartH;
          ctx.beginPath();
          ctx.arc(dx, dy, 2.5, 0, Math.PI * 2);
          ctx.fillStyle = lineObj.color;
          ctx.fill();
        }
      }
    }

    active.forEach(drawLine);

    // Cache geometry + trimmed timeseries for tooltip hit-testing
    chartGeo = {
      padLeft: padLeft,
      padRight: padRight,
      padTop: padTop,
      chartW: chartW,
      chartH: chartH,
      n: n,
      leftMax: leftMax,
      rightMax: rightMax,
      lineMax: lineMax,
      active: active,
      W: W,
      H: H,
      ts: ts
    };
  }

  /* ── Trends chart tooltip (mousemove on canvas) ── */
  $(document).on("mousemove", "#wcu-trends-canvas", function (e) {
    if (!chartGeo || !chartGeo.ts) return;
    var rect = this.getBoundingClientRect();
    var g = chartGeo;
    var ts = g.ts;

    // Scale mouse position from CSS bounding rect to chart coordinate space
    var scaleX = g.W / rect.width;
    var scaleY = g.H / rect.height;
    var mx = (e.clientX - rect.left) * scaleX;
    var my = (e.clientY - rect.top) * scaleY;

    // Determine which date index the mouse is closest to
    var relX = mx - g.padLeft;
    if (relX < 0 || relX > g.chartW || my < g.padTop || my > g.padTop + g.chartH) {
      $("#wcu-trends-tooltip").hide();
      return;
    }
    var idx = Math.round((relX / g.chartW) * (g.n - 1));
    idx = Math.max(0, Math.min(g.n - 1, idx));

    // Build tooltip content
    var date = ts.dates[idx];
    var html = '<div class="wcu-tt-date">' + formatDateLabelFull(date) + '</div>';
    g.active.forEach(function (l) {
      var val = ts[l.key] ? ts[l.key][idx] : 0;
      var display = l.axis === "left" ? (decodedCurrency + numberFormat(val, 2)) : numberFormat(val, 0);
      html += '<div class="wcu-tt-row">' +
        '<span class="wcu-tt-swatch" style="background:' + l.color + ';"></span>' +
        '<span class="wcu-tt-label">' + l.label + '</span>' +
        '<span class="wcu-tt-value">' + display + '</span>' +
      '</div>';
    });

    var $tip = $("#wcu-trends-tooltip");
    $tip.html(html).show();

    // Position tooltip
    var tipW = $tip.outerWidth();
    var tipH = $tip.outerHeight();
    var tx = mx + 14;
    if (tx + tipW > g.W - 10) tx = mx - tipW - 14;
    var ty = my - tipH / 2;
    ty = Math.max(4, Math.min(g.H - tipH - 4, ty));
    $tip.css({ left: tx + "px", top: ty + "px" });

    // Draw crosshair line at mouse position on canvas
    renderTrendsChart();
    var canvas = document.getElementById("wcu-trends-canvas");
    if (canvas) {
      var dpr = window.devicePixelRatio || 1;
      var ctx = canvas.getContext("2d");
      ctx.save();
      // Use setTransform (not cumulative scale) so the dpr scaling from
      // renderTrendsChart() is replaced rather than doubled.
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      // Use actual mouse X for the crosshair line (smooth tracking)
      ctx.strokeStyle = "rgba(0,0,0,0.12)";
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 3]);
      ctx.beginPath();
      ctx.moveTo(mx, g.padTop);
      ctx.lineTo(mx, g.padTop + g.chartH);
      ctx.stroke();
      ctx.setLineDash([]);
      // Draw highlight dots at the snapped data-point index
      var cx = g.padLeft + (idx / (g.n - 1)) * g.chartW;
      g.active.forEach(function (l) {
        var data = ts[l.key];
        if (!data) return;
        var maxVal = g.lineMax[l.key];
        var dy = g.padTop + g.chartH - (data[idx] / maxVal) * g.chartH;
        ctx.beginPath();
        ctx.arc(cx, dy, 4.5, 0, Math.PI * 2);
        ctx.fillStyle = "#fff";
        ctx.fill();
        ctx.strokeStyle = l.color;
        ctx.lineWidth = 2;
        ctx.stroke();
      });
      ctx.restore();
    }
  });

  $(document).on("mouseleave", "#wcu-trends-canvas", function () {
    $("#wcu-trends-tooltip").hide();
    renderTrendsChart(); // Redraw without crosshair
  });

  /** Decode HTML entities (e.g. &pound;) into real characters for canvas text */
  var decodedCurrency = (function () {
    var el = document.createElement("textarea");
    el.innerHTML = cfg.currency || "";
    return el.value;
  })();

  /** Format Y-axis value: abbreviate large numbers */
  function formatAxisVal(val, isCurrency) {
    var prefix = isCurrency ? decodedCurrency : "";
    if (val >= 1000000) return prefix + (val / 1000000).toFixed(1) + "M";
    if (val >= 1000) return prefix + (val / 1000).toFixed(1) + "K";
    return prefix + val.toFixed(isCurrency ? 0 : 0);
  }

  /** Format date label: "Jan 15" (compact, for X-axis) */
  function formatDateLabel(dateStr) {
    var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    var parts = dateStr.split("-");
    if (parts.length < 3) return dateStr;
    return months[parseInt(parts[1], 10) - 1] + " " + parseInt(parts[2], 10);
  }

  /** Format date label with year: "Jan 15, 2026" (for tooltip) */
  function formatDateLabelFull(dateStr) {
    var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    var parts = dateStr.split("-");
    if (parts.length < 3) return dateStr;
    return months[parseInt(parts[1], 10) - 1] + " " + parseInt(parts[2], 10) + ", " + parts[0];
  }

  /** Convert hex color to rgba string */
  function hexToRgba(hex, alpha) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return "rgba(" + r + "," + g + "," + b + "," + alpha + ")";
  }

  /* ============================================
   * SPARKLINE CHARTS (inline SVG)
   * ============================================ */

  /**
   * Render an inline SVG sparkline inside the given container.
   * @param {string} selector  - jQuery selector for the container div
   * @param {Array}  dataPoints - array of numeric values (one per day)
   * @param {string} color      - stroke / gradient colour
   */
  function renderSparkline(selector, dataPoints, color) {
    var $el = $(selector);
    if (!$el.length || !dataPoints || dataPoints.length < 2) {
      $el.empty();
      return;
    }

    // Trim leading zeros so the chart starts from the first date with data
    var firstNonZero = 0;
    for (var t = 0; t < dataPoints.length; t++) {
      if (dataPoints[t] !== 0) { firstNonZero = t; break; }
    }
    if (firstNonZero > 0) {
      dataPoints = dataPoints.slice(firstNonZero);
    }
    if (dataPoints.length < 2) {
      $el.empty();
      return;
    }

    // If every value is 0, show a flat line
    var allZero = true;
    for (var z = 0; z < dataPoints.length; z++) {
      if (dataPoints[z] !== 0) { allZero = false; break; }
    }

    var W = 120, H = 38;
    var pad = 1;
    var n = dataPoints.length;
    var max = Math.max.apply(null, dataPoints);
    var min = Math.min.apply(null, dataPoints);
    var range = max - min || 1;

    // Build polyline points
    var points = [];
    for (var i = 0; i < n; i++) {
      var x = pad + (i / (n - 1)) * (W - pad * 2);
      var y = allZero
        ? H - pad - 2
        : H - pad - ((dataPoints[i] - min) / range) * (H - pad * 2 - 2);
      points.push(x.toFixed(1) + "," + y.toFixed(1));
    }
    var polyline = points.join(" ");

    // Build filled area (close the path along the bottom)
    var areaPoints = polyline +
      " " + (W - pad).toFixed(1) + "," + H +
      " " + pad.toFixed(1) + "," + H;

    var uid = "wcu-sg-" + selector.replace(/[^a-zA-Z0-9]/g, "");

    var svg =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" class="wcu-sparkline-svg">' +
        '<defs>' +
          '<linearGradient id="' + uid + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.18"/>' +
            '<stop offset="100%" stop-color="' + color + '" stop-opacity="0.01"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<polygon points="' + areaPoints + '" fill="url(#' + uid + ')" />' +
        '<polyline points="' + polyline + '" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"' +
          (allZero ? ' stroke-dasharray="2 2" stroke-opacity="0.35"' : '') + ' />' +
      '</svg>';

    $el.html(svg);
  }

  /* ============================================
   * TREND INSIGHTS PANEL
   * Analyses timeseries data to surface useful
   * patterns: day-of-week performance, peak day,
   * best streak, and active-day distribution.
   * ============================================ */
  function renderTrendInsights() {
    var $body = $("#wcu-insights-body");
    if (!$body.length) return;

    var ts = lastTimeseries;
    if (!ts || !ts.dates || ts.dates.length < 2) {
      $body.html('<div class="wcu-insights-empty"><i class="fas fa-chart-bar"></i> Not enough data for insights</div>');
      return;
    }

    var mode = $(".wcu-insights-toggle-btn.active").data("mode") || "orders";
    var isClicks = mode === "clicks";
    var metricLabel = isClicks ? "clicks" : "orders";

    var dates  = ts.dates;
    var metric = isClicks ? ts.clicks : ts.usage;
    var sales  = ts.sales;
    var clicks = ts.clicks;
    var usage  = ts.usage;
    var n = dates.length;

    // ── 1. Day-of-week aggregation ──
    var dowNames  = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    var dowTotals = [0, 0, 0, 0, 0, 0, 0];
    for (var i = 0; i < n; i++) {
      var d = new Date(dates[i] + "T00:00:00");
      var dow = d.getDay();
      dowTotals[dow] += metric[i] || 0;
    }
    var dowMax = Math.max.apply(null, dowTotals);
    var peakDow = dowTotals.indexOf(dowMax);

    // Build day-of-week bar chart HTML (Mon first)
    var dowOrder = [1, 2, 3, 4, 5, 6, 0]; // Mon–Sun
    var dowTitle = isClicks ? "Clicks by Weekday" : "Orders by Weekday";
    var dowHtml = '<div class="wcu-insight-block">';
    dowHtml += '<div class="wcu-insight-block-title">' + dowTitle + '</div>';
    dowHtml += '<div class="wcu-dow-bars">';
    for (var b = 0; b < dowOrder.length; b++) {
      var di = dowOrder[b];
      var pct = dowMax > 0 ? Math.round((dowTotals[di] / dowMax) * 100) : 0;
      var isPeak = di === peakDow && dowMax > 0;
      dowHtml += '<div class="wcu-dow-row' + (isPeak ? ' wcu-dow-peak' : '') + '">';
      dowHtml += '<span class="wcu-dow-label">' + dowNames[di] + '</span>';
      dowHtml += '<div class="wcu-dow-bar-wrap"><div class="wcu-dow-bar" style="width:' + pct + '%"></div></div>';
      dowHtml += '<span class="wcu-dow-val">' + numberFormat(dowTotals[di], 0) + '</span>';
      dowHtml += '</div>';
    }
    dowHtml += '</div></div>';

    // ── 2. Peak day ──
    var peakIdx = 0;
    var peakVal = 0;
    for (var p = 0; p < n; p++) {
      if ((metric[p] || 0) > peakVal) {
        peakVal = metric[p];
        peakIdx = p;
      }
    }

    // Daily average
    var totalMetric = 0;
    for (var t = 0; t < n; t++) totalMetric += (metric[t] || 0);
    var dailyAvg = n > 0 ? totalMetric / n : 0;

    var statsHtml = '<div class="wcu-insight-block">';
    statsHtml += '<div class="wcu-insight-block-title">Key Moments</div>';
    statsHtml += '<div class="wcu-insight-stats">';

    // Peak day
    statsHtml += '<div class="wcu-insight-stat-row">';
    statsHtml += '<i class="fas fa-arrow-up"></i> ';
    statsHtml += 'Best day: <strong>' + formatDateLabelFull(dates[peakIdx]) + '</strong>';
    statsHtml += ' — <span class="wcu-insight-value">' + numberFormat(peakVal, 0) + ' ' + metricLabel + '</span>';
    statsHtml += '</div>';

    // Daily average
    statsHtml += '<div class="wcu-insight-stat-row">';
    statsHtml += '<i class="fas fa-chart-line"></i> ';
    statsHtml += 'Daily avg: <strong>' + numberFormat(dailyAvg, 1) + '</strong> ' + metricLabel + '/day';
    statsHtml += '</div>';

    // Best day of week
    statsHtml += '<div class="wcu-insight-stat-row">';
    statsHtml += '<i class="fas fa-calendar-day"></i> ';
    statsHtml += 'Best weekday: <strong>' + dowNames[peakDow] + '</strong>';
    statsHtml += ' — ' + numberFormat(dowTotals[peakDow], 0) + ' ' + metricLabel;
    statsHtml += '</div>';

    statsHtml += '</div></div>';

    // ── 3. Activity distribution ──
    var activeDays = 0;
    for (var q = 0; q < n; q++) {
      if ((metric[q] || 0) > 0) {
        activeDays++;
      }
    }
    var activePct = n > 0 ? Math.round((activeDays / n) * 100) : 0;

    var actHtml = '<div class="wcu-insight-block">';
    actHtml += '<div class="wcu-insight-block-title">Activity Coverage</div>';
    actHtml += '<div class="wcu-insight-stat-row" style="margin-bottom:4px;">';
    actHtml += '<i class="fas fa-calendar-check"></i> ';
    actHtml += '<strong>' + activeDays + '</strong> of ' + n + ' days had ' + metricLabel;
    actHtml += '</div>';
    actHtml += '<div class="wcu-activity-meter">';
    actHtml += '<div class="wcu-activity-bar-bg"><div class="wcu-activity-bar-fill" style="width:' + activePct + '%"></div></div>';
    actHtml += '<span class="wcu-activity-pct">' + activePct + '%</span>';
    actHtml += '</div>';
    actHtml += '</div>';

    $body.html(dowHtml + statsHtml + actHtml);
  }

  /* ============================================
   * HELPERS
   * ============================================ */

  /** Format YYYY-MM-DD from Date object */
  function dateStr(d) {
    var mm = String(d.getMonth() + 1).padStart(2, "0");
    var dd = String(d.getDate()).padStart(2, "0");
    return d.getFullYear() + "-" + mm + "-" + dd;
  }

  /** Format number with commas / decimals */
  function numberFormat(num, decimals) {
    num = parseFloat(num) || 0;
    return num.toLocaleString("en-US", {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  /** Render change % indicator */
  function changeHtml(pct) {
    pct = parseFloat(pct) || 0;
    var display = parseFloat(Math.abs(pct).toFixed(2));
    if (pct > 0) {
      return '<span class="wcu-change-up">↑ ' + display + "%</span>";
    } else if (pct < 0) {
      return '<span class="wcu-change-down">↓ ' + display + "%</span>";
    }
    return '<span class="wcu-change-neutral">— 0%</span>';
  }

  /** Escape HTML entities */
  function escHtml(str) {
    if (!str) return "";
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /** Escape attribute value */
  function escAttr(str) {
    if (!str) return "";
    return str
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }
})(jQuery);
