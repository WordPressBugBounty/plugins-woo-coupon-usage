/**
 * General settings tab: live preview of the affiliate dashboard or portal.
 *
 * The preview shows the "Statistics" tab, drawn from the current values of the settings
 * fields (on this tab and the Design tab) and redrawn as they change. It is a drawing,
 * not the real page: it uses example statistics, and lives in a shadow root styled by
 * css/admin-settings-general-preview.css so admin styles cannot reach it.
 *
 * Saving is untouched: every field still saves through js/admin-options-update.js.
 */
(function ($) {
  'use strict';

  var cfg = window.wcusageGeneralSettings || {};
  var t = cfg.i18n || {};
  var STORE_DEVICE = 'wcuGsPreviewDevice';

  // Width the preview is drawn at before it is scaled to fit the panel.
  var DESKTOP_WIDTH = 1180;
  var MOBILE_WIDTH = 390;

  var COLOR_DEFAULTS = {
    wcusage_field_color_tab: '#1b3e47',
    wcusage_field_color_tab_font: '#ffffff',
    wcusage_field_color_tab_hover: '#005d75',
    wcusage_field_color_tab_hover_font: '#ffffff',
    wcusage_field_color_button: '#005d75',
    wcusage_field_color_button_font: '#ffffff',
    wcusage_field_color_button_hover: '#1b3e47',
    wcusage_field_color_button_font_hover: '#ffffff',
    wcusage_field_color_table: '#f4f4f4',
    wcusage_field_color_table_font: '#0a0a0a',
    wcusage_field_color_stats_icon: '#bebebe',
    wcusage_field_color_line_graph: '#008000',
    wcusage_field_color_bonus_bar: '#2bc253',
    wcusage_field_color_bonus_bar_bg: '#d2d2d2',
    wcusage_field_color_bonus_box: '#f6d365',
    wcusage_field_color_bonus_box_font: '#000000'
  };

  var SECTION_DEFAULT_ORDER = ['section_couponinfo', 'section_commissionamounts', 'section_commissiongraphs', 'section_latestreferrals', 'section_commissionpayouts'];

  var $root;

  // ---------------------------------------------------------------------------
  // Small helpers
  // ---------------------------------------------------------------------------

  var store = {
    get: function (key, fallback) {
      try { var v = window.localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
    },
    set: function (key, value) {
      try { window.localStorage.setItem(key, value); } catch (e) {}
    }
  };

  function esc(value) {
    return String(value === undefined || value === null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // The settings field with this id. Some settings print a hidden input with the same id
  // ahead of the real control, so prefer the one that is not type="hidden".
  function field(id) {
    var els = document.querySelectorAll('#general-settings [id="' + id + '"]');
    if (!els.length) { els = document.querySelectorAll('[id="' + id + '"]'); }
    for (var i = 0; i < els.length; i++) {
      if (els[i].type !== 'hidden') { return els[i]; }
    }
    return els[0] || null;
  }

  function isOn(id, fallback) {
    var el = field(id);
    if (!el) { return !!fallback; }
    if (el.type === 'checkbox') { return el.checked; }
    return el.value === '1';
  }

  function value(id, fallback) {
    var el = field(id);
    if (!el || el.value === undefined || el.value === null || el.value === '') { return fallback; }
    return el.value;
  }

  // Colour settings can hold hex values or colour names. Anything else falls back.
  function color(id) {
    var v = $.trim(value(id, '') || '');
    if (/^(#[0-9a-f]{3,8}|[a-z]+)$/i.test(v)) { return v; }
    return COLOR_DEFAULTS[id] || '#000000';
  }

  // Formats an amount the way the store does: its currency symbol and position, separators
  // and number of decimals (WooCommerce settings, passed in by PHP).
  function money(amount, decimals) {
    var c = cfg.currency || {};
    if (decimals === undefined) {
      decimals = c.decimals === undefined ? 2 : Math.max(0, Math.min(6, parseInt(c.decimals, 10) || 0));
    }
    var fixed = amount.toFixed(decimals);
    var parts = fixed.split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand === undefined ? ',' : c.thousand);
    var num = whole + (parts[1] ? (c.decimal === undefined ? '.' : c.decimal) + parts[1] : '');
    var sym = c.symbol || '$';
    switch (c.position) {
      case 'right': return num + sym;
      case 'left_space': return sym + ' ' + num;
      case 'right_space': return num + ' ' + sym;
      default: return sym + num;
    }
  }

  // Admin-entered HTML (custom text, footer) is shown in the preview, so drop anything
  // that could run: scripts, frames, forms, event handler attributes and script URLs.
  function safeHtml(html) {
    if (!html) { return ''; }
    var doc;
    try { doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html'); } catch (e) { return esc(html); }
    var wrap = doc.body.firstChild;
    if (!wrap) { return ''; }
    // Also elements whose content parses differently once the cleaned HTML is put back
    // into the page (noscript is markup here, with scripting off, but raw text there),
    // which would let a payload hidden inside them come back to life.
    $(wrap).find('script,style,iframe,frame,object,embed,link,meta,base,form,input,button,textarea,select,noscript,xmp,noembed,noframes,plaintext,template,svg,math').remove();
    $(wrap).find('*').addBack().each(function () {
      var el = this;
      Array.prototype.slice.call(el.attributes || []).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        if (name.indexOf('on') === 0 || (/^(href|src|xlink:href|action|formaction|srcset)$/.test(name) && /^\s*(javascript|data|vbscript):/i.test(attr.value))) {
          el.removeAttribute(attr.name);
        }
      });
    });
    return wrap.innerHTML;
  }

  function isLegacySaving() {
    var $select = $('#wcusage_field_settings_legacy');
    if ($select.length) { return $select.val() === '1'; }
    return $('.wcusage_field_settings_legacy').is(':checked');
  }

  function isPortal() {
    return isOn('wcusage_field_portal_enable', false);
  }

  // ---------------------------------------------------------------------------
  // Preview: read the settings
  // ---------------------------------------------------------------------------

  function readTabs() {
    var tabs = [];
    $('#wcusage-dashboard-tabs-order .wcusage-tab-item').each(function () {
      var $item = $(this);
      var $toggle = $item.find('.wcusage-tab-header input[type=checkbox]').first();
      var enabled = !$toggle.length || $toggle.prop('disabled') || $toggle.is(':checked');
      var custom = $.trim($item.find('.wcu-tab-name-hidden').val() || '');
      tabs.push({
        id: this.id,
        label: custom || $item.data('label') || $.trim($item.find('.wcu-tab-name-display').text()),
        icon: $item.data('icon') || 'fas fa-folder',
        enabled: enabled
      });
    });
    return tabs;
  }

  function readSectionOrder() {
    var order = $('#wcusage-section-order li').map(function () { return this.id; }).get();
    if (!order.length) {
      var saved = value('wcusage_statistics_layout', '');
      order = saved ? saved.split(',') : [];
    }
    SECTION_DEFAULT_ORDER.forEach(function (key) {
      if (order.indexOf(key) === -1) { order.push(key); }
    });
    return order;
  }

  function footerHtml() {
    var id = 'wcusage_portal_footer_text';
    var editor = window.tinymce && tinymce.get(id);
    if (editor && editor.initialized && !editor.isHidden()) {
      try { return editor.getContent(); } catch (e) {}
    }
    return value(id, '');
  }

  // "Required order status to show on affiliate dashboard": the ticked statuses, in the
  // order WooCommerce lists them. Completed is always on, so it is the fallback.
  function readStatuses() {
    var list = [];
    $('input[type="checkbox"][name^="wcusage_options[wcusage_field_order_type_custom]"]').each(function () {
      if (!this.checked) { return; }
      var key = this.getAttribute('checktypekey') || (this.name.match(/\[(wc-[^\]]+)\]$/) || [])[1];
      if (!key) { return; }
      var label = $.trim($(this).parent().text()) || key.replace(/^wc-/, '');
      list.push({ key: key, label: label });
    });
    if (!list.length) { list.push({ key: 'wc-completed', label: t.completedStatus || 'Completed' }); }
    return list;
  }

  function readSettings() {
    var icon = color('wcusage_field_color_stats_icon');
    return {
      portal: isPortal(),
      pro: !!cfg.isPro,
      theme: value('wcusage_portal_theme', 'classic'),
      colors: {
        '--tab': color('wcusage_field_color_tab'),
        '--tab-font': color('wcusage_field_color_tab_font'),
        '--tab-hover': color('wcusage_field_color_tab_hover'),
        '--tab-hover-font': color('wcusage_field_color_tab_hover_font'),
        '--btn': color('wcusage_field_color_button'),
        '--btn-font': color('wcusage_field_color_button_font'),
        '--btn-hover': color('wcusage_field_color_button_hover'),
        '--btn-hover-font': color('wcusage_field_color_button_font_hover'),
        '--table': color('wcusage_field_color_table'),
        '--table-font': color('wcusage_field_color_table_font'),
        '--icon': icon,
        // Portal themes tint the icons with the button colour until this is changed.
        '--icon-themed': icon.toLowerCase() === '#bebebe' ? 'var(--btn)' : icon,
        '--graph': color('wcusage_field_color_line_graph')
      },
      font: value('wcusage_portal_font_family', ''),
      tabsStyle: value('wcusage_field_tabs_style', '2'),
      tabsRadius: value('wcusage_field_tabs_border', '1'),
      tabsPadding: value('wcusage_field_tabs_padding', '1'),
      tabsFontSize: parseInt(value('wcusage_field_tabs_font_size', ''), 10) || 0,
      mobileMenu: value('wcusage_field_mobile_menu', 'dropdown'),
      tabIcons: isOn('wcusage_field_show_tabs_icons', true),
      tabs: readTabs(),
      // Statistics tab
      sections: readSectionOrder(),
      customText: value('wcusage_field_text', ''),
      couponInfo: isOn('wcusage_field_statistics_couponinfo', true),
      earnings: isOn('wcusage_field_statistics_commissionearnings', true),
      earnTotal: isOn('wcusage_field_statistics_commissionearnings_total', true),
      earnDiscounts: isOn('wcusage_field_statistics_commissionearnings_discounts', true),
      earnCommission: isOn('wcusage_field_statistics_commissionearnings_commission', true),
      whichToggle: $('input[name="wcusage_options[wcusage_field_which_toggle]"]:checked').val() || '1',
      latest: isOn('wcusage_field_statistics_latest', true),
      payouts: isOn('wcusage_field_statistics_commissionpayouts', true),
      graphs: isOn('wcusage_field_show_graphs', true),
      // Referred orders columns, used for the "Latest Referrals" table
      colId: isOn('wcusage_field_orderid', false),
      colDate: isOn('wcusage_field_date', true),
      colStatus: isOn('wcusage_field_status', true),
      colTotal: isOn('wcusage_field_amount', true),
      colDiscount: isOn('wcusage_field_amount_saved', true),
      colMore: isOn('wcusage_field_list_products', true),
      statuses: readStatuses(),
      // Header
      prefix: value('wcusage_before_title', ''),
      switcher: isOn('wcusage_field_header_coupon_switcher', true),
      showUser: isOn('wcusage_field_show_username', true),
      showLogout: isOn('wcusage_field_show_logout_link', true),
      // Portal
      portalTitle: value('wcusage_portal_title', 'Affiliate Portal'),
      logo: value('wcusage_portal_logo', ''),
      footer: footerHtml(),
      portalDarkToggle: isOn('wcusage_portal_dark_mode', true),
      // Dashboard dark mode
      darkEnabled: isOn('wcusage_field_dark_mode_enable', false),
      darkToggle: isOn('wcusage_field_dark_mode_toggle', true),
      darkDefault: isOn('wcusage_field_dark_mode_default', false)
    };
  }

  // ---------------------------------------------------------------------------
  // Preview: draw
  // ---------------------------------------------------------------------------

  var state = {
    device: store.get(STORE_DEVICE, 'desktop') === 'mobile' ? 'mobile' : 'desktop',
    scheme: null, // null = follow the settings
    open: false,      // wide screens: the preview column is shown
    collapsed: true,  // small screens: the accordion at the bottom is closed
    stacked: false,
    expanded: false
  };

  function icon(cls, extra) {
    return '<i class="' + esc(cls) + (extra ? ' ' + extra : '') + '" aria-hidden="true"></i>';
  }

  function box(iconCls, title, val) {
    return '<div class="pv-box"><span class="pv-box-icon">' + icon(iconCls) + '</span>' +
      '<span class="pv-box-title">' + esc(title) + '<span class="pv-colon">:</span></span>' +
      '<span class="pv-box-value">' + esc(val) + '</span></div>';
  }

  function section(key, title, iconCls, body, link) {
    var head = '';
    if (title) {
      head = '<h4 class="pv-section-title">' + icon(iconCls || 'fa-solid fa-chart-simple', 'pv-section-icon') +
        '<span>' + esc(title) + '</span>' + (link ? ' <span class="pv-link">' + esc(link) + ' ' + icon('fa-solid fa-arrow-right') + '</span>' : '') + '</h4>';
    }
    return '<section class="pv-section pv-' + key + '">' + head + '<div class="pv-section-body">' + body + '</div></section>';
  }

  // Example data: a made-up coupon, and a commission graph drawn as a smooth, plainly
  // illustrative curve over the last 90 days. The chart copies the Google Charts line chart
  // the dashboard draws (400px tall, the plot inset 45.5px / 46.5px, 16px value labels,
  // 12px date labels, a 3px line), and is drawn at the width it is shown at, so the text
  // keeps its size.
  var DEMO_COUPON = cfg.demoCoupon || 'DEMO10';

  function demoGraphValue(i) {
    return 70 + 45 * Math.sin(i / 11) + 20 * Math.sin(i / 3.7) + i * 0.6;
  }

  // Spreads the enabled statuses over the example rows: Completed in most, and each
  // other enabled status once, in the alternate rows from the second.
  function demoStatuses(enabled, count) {
    var completed = enabled.filter(function (st) { return st.key === 'wc-completed'; })[0] || enabled[0];
    var others = enabled.filter(function (st) { return st !== completed; });
    var out = [];
    for (var i = 0; i < count; i++) {
      out.push(i % 2 === 1 && others.length ? others.shift() : completed);
    }
    // More other statuses than alternate rows: use the remaining rows too.
    for (var j = count - 1; j >= 0 && others.length; j--) {
      if (out[j] === completed && j !== 0) { out[j] = others.shift(); }
    }
    return out;
  }

  // Badge tone for an order status, as in the portal themes (theme.js).
  function statusTone(key) {
    key = String(key || '').replace(/^wc-/, '');
    if (/^(completed|paid)$/.test(key)) { return 'good'; }
    if (/^(processing|pending|on-hold|checkout-draft)$/.test(key)) { return 'warn'; }
    if (/^(cancelled|refunded|failed|trash)$/.test(key)) { return 'bad'; }
    return 'neutral';
  }

  // Dates in the site's language. Some WordPress locales (e.g. "de-DE-formal") are not
  // valid for the browser, which then throws, so fall back to the browser's own.
  function localDate(date, options) {
    try {
      return date.toLocaleDateString(cfg.locale || undefined, options);
    } catch (e) {
      return date.toLocaleDateString(undefined, options);
    }
  }

  function demoDate(daysAgo) {
    var d = new Date();
    d.setDate(d.getDate() - daysAgo);
    return localDate(d, { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function graphSvg(width) {
    var w = Math.max(240, Math.round(width)), h = 400;
    var left = 45.5, top = 46.5, plotW = w - left - 6.5, plotH = 333, bottom = top + plotH;
    var days = 91, x0 = left + 12, x1 = left + plotW - 12, max = 200;
    var step = (x1 - x0) / (days - 1);
    var y = function (v) { return bottom - plotH * v / max; };
    var line = '';
    for (var i = 0; i < days; i++) {
      line += (i ? ' L ' : 'M ') + (x0 + i * step).toFixed(2) + ' ' + y(Math.max(0, Math.min(max, demoGraphValue(i)))).toFixed(2);
    }
    var grid = '', values = '';
    for (var v = 0; v <= max; v += 50) {
      var gy = y(v);
      grid += '<line x1="' + left + '" x2="' + (left + plotW) + '" y1="' + gy + '" y2="' + gy + '"' + (v ? '' : ' class="pv-baseline"') + '/>';
      values += '<text class="pv-axis pv-axis-y" x="39.5" y="' + (gy + 5.6).toFixed(1) + '" text-anchor="end">' + esc(money(v, 0)) + '</text>';
    }
    // Date labels, as many as fit (Google Charts keeps them about 45px apart).
    var count = Math.max(2, Math.floor((x1 - x0) / 46) + 1);
    var start = new Date();
    start.setDate(start.getDate() - (days - 1));
    var dates = '';
    for (var d = 0; d < count; d++) {
      var day = Math.round(d * (days - 1) / (count - 1));
      var date = new Date(start.getTime() + day * 86400000);
      var label = localDate(date, { month: 'short', day: 'numeric' });
      dates += '<text class="pv-axis" x="' + (x0 + day * step).toFixed(2) + '" y="396.7" text-anchor="middle">' + esc(label) + '</text>';
    }
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" role="img">' +
      '<rect class="pv-graph-bg" width="' + w + '" height="' + h + '"/>' +
      '<g class="pv-grid">' + grid + '</g>' + values + dates +
      '<path d="' + line + '" fill="none" stroke="var(--graph-line, var(--graph))" stroke-width="3"/>' +
      '</svg>';
  }

  // The graph is drawn once the preview is laid out, at its container's width.
  function drawGraphs() {
    if (!preview.canvas) { return; }
    $(preview.canvas).find('.pv-graph').each(function () {
      var style = window.getComputedStyle(this);
      var width = this.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
      if (width <= 0) { return; }
      width = Math.round(width);
      if (+this.getAttribute('data-width') === width) { return; }
      this.setAttribute('data-width', width);
      this.innerHTML = graphSvg(width);
    });
  }

  function referralsTable(s) {
    // Example orders: round amounts (10% off, 20% commission), dated back from today.
    // Statuses come from the order statuses the dashboard is set to show: mostly
    // Completed, with each other enabled status appearing once.
    var statuses = demoStatuses(s.statuses, 5);
    var rows = [
      { id: 1005, ago: 1, subtotal: 100, discount: 10, total: 90, commission: 18 },
      { id: 1004, ago: 2, subtotal: 50, discount: 5, total: 45, commission: 9 },
      { id: 1003, ago: 4, subtotal: 200, discount: 20, total: 180, commission: 36 },
      { id: 1002, ago: 5, subtotal: 150, discount: 15, total: 135, commission: 27 },
      { id: 1001, ago: 7, subtotal: 80, discount: 8, total: 72, commission: 14.40 }
    ].map(function (r, i) { r.date = demoDate(r.ago); r.status = statuses[i].label; r.statusKey = statuses[i].key; return r; });
    var cols = [];
    if (s.colId) { cols.push({ label: '#', cell: function (r) { return '#' + r.id; }, optional: true }); }
    if (s.colDate) { cols.push({ label: t.date || 'Date', cell: function (r) { return r.date; }, cls: 'pv-col-date' }); }
    if (s.colStatus) { cols.push({ label: t.status || 'Status', cell: function (r) { return r.status; }, status: true, optional: true }); }
    if (s.colTotal) { cols.push({ label: t.subtotal || 'Subtotal', cell: function (r) { return money(r.subtotal); }, optional: true }); }
    if (s.colDiscount) { cols.push({ label: t.discount || 'Discount', cell: function (r) { return money(r.discount); }, optional: true }); }
    if (s.colTotal) { cols.push({ label: t.total || 'Total', cell: function (r) { return money(r.total); } }); }
    cols.push({ label: t.commission || 'Commission', cell: function (r) { return money(r.commission); } });
    // The products column has no heading.
    if (s.colMore) { cols.push({ label: '', cell: function () { return ''; }, more: true, optional: true, cls: 'pv-col-more' }); }

    var cellClass = function (c) {
      var cls = [c.cls, c.optional ? 'pv-col-optional' : ''].filter(Boolean).join(' ');
      return cls ? ' class="' + cls + '"' : '';
    };
    var head = '<tr>' + cols.map(function (c) {
      return '<th' + cellClass(c) + '>' + esc(c.label) + '</th>';
    }).join('') + '</tr>';
    var body = rows.map(function (r) {
      return '<tr>' + cols.map(function (c) {
        var inner = esc(c.cell(r));
        if (c.more) {
          inner = '<span class="pv-more">' + esc((t.more || 'More').toUpperCase()) + ' ' + icon('fas fa-chevron-down') + '</span>';
        } else if (c.status) {
          // Portal themes show the status as a tinted badge (theme.js).
          var tone = statusTone(r.statusKey);
          inner = '<span class="pv-status pv-status--' + tone + '">' + inner + '</span>';
        }
        // On phones each cell shows its column name beside it.
        var label = c.more ? (t.products || 'Products') : c.label;
        return '<td' + cellClass(c) + ' data-label="' + esc(label) + '">' + inner + '</td>';
      }).join('') + '</tr>';
    }).join('');
    return '<table class="pv-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table>';
  }

  function statisticsContent(s, statsTab) {
    var html = '';
    if (statsTab && !statsTab.enabled) {
      html += '<div class="pv-notice">' + esc(t.statsTabOff || '') + '</div>';
    }
    var custom = safeHtml(s.customText);
    if (custom) { html += '<div class="pv-custom-text">' + custom + '</div>'; }

    var periods = s.whichToggle === '0'
      ? [t.allTime || 'All-time', t.last30 || 'Last 30 Days', t.last7 || 'Last 7 Days']
      : [t.allTime || 'All-time', t.thisMonth || 'This Month', t.lastMonth || 'Last Month'];

    var sections = {
      section_couponinfo: function () {
        if (!s.couponInfo) { return ''; }
        return section('couponinfo', t.couponInfo || 'Coupon Info', 'fa-solid fa-tag',
          '<div class="pv-boxes">' +
          box('fa-solid fa-users', t.totalUsage || 'Total Usage', '125') +
          box('fa-solid fa-tag', t.discount || 'Discount', '10%') +
          box('fa-solid fa-percent', t.commission || 'Commission', '20%') +
          '</div>');
      },
      section_commissionamounts: function () {
        if (!s.earnings) { return ''; }
        var boxes = '';
        if (s.earnTotal) { boxes += box('fa-solid fa-cart-shopping', t.totalSales || 'Total Sales', money(12345.67)); }
        if (s.earnDiscounts) { boxes += box('fa-solid fa-tags', t.totalDiscounts || 'Total Discounts', money(1234.56)); }
        if (s.earnCommission) { boxes += box('fa-solid fa-dollar-sign', t.totalCommission || 'Total Commission', money(2187.43)); }
        // Same markup as the dashboard: the periods split by "|", then the refresh button.
        var switcher = periods.map(function (p, i) {
          return (i ? ' <span class="pv-sep">|</span> ' : '') + '<span class="pv-period' + (i === 0 ? ' is-active' : '') + '">' + esc(p) + '</span>';
        }).join('') + ' <span class="pv-refresh-btn">' + icon('fa-solid fa-arrows-rotate', 'pv-refresh') + '</span>';
        return section('commissionamounts', '', '', '<div class="pv-periods">' + switcher + '</div>' + (boxes ? '<div class="pv-boxes">' + boxes + '</div>' : ''));
      },
      section_commissiongraphs: function () {
        if (!s.pro || !s.graphs) { return ''; }
        return section('commissiongraphs', t.commissionGraph || 'Commission Graph', 'fa-solid fa-chart-line', '<div class="pv-graph"></div>');
      },
      section_latestreferrals: function () {
        if (!s.latest) { return ''; }
        return section('latestreferrals', t.latestReferrals || 'Latest Referrals', 'fa-solid fa-cart-shopping',
          referralsTable(s),
          t.viewMore || 'View More');
      },
      section_commissionpayouts: function () {
        if (!s.pro || !s.payouts) { return ''; }
        return section('commissionpayouts', t.payouts || 'Commission Payouts', 'fa-solid fa-money-bill-wave',
          '<div class="pv-boxes">' +
          box('fa-solid fa-sack-dollar', t.unpaid || 'Unpaid Commission', money(222.22)) +
          box('fa-solid fa-file-invoice-dollar', t.pending || 'Pending Payments', money(100)) +
          box('fa-solid fa-hand-holding-dollar', t.completed || 'Completed Payments', money(1900)) +
          '</div>', t.viewMore || 'View More');
      }
    };

    s.sections.forEach(function (key) {
      if (sections[key]) { html += sections[key](); }
    });
    return html;
  }

  function activeTab(s) {
    var tabs = s.tabs.filter(function (tab) { return tab.enabled; });
    var statsTab = s.tabs.filter(function (tab) { return tab.id === 'tab-page-stats'; })[0];
    var activeId = statsTab && statsTab.enabled ? 'tab-page-stats' : (tabs[0] ? tabs[0].id : '');
    return { tabs: tabs, statsTab: statsTab, activeId: activeId };
  }

  function drawDashboard(s, dark) {
    var a = activeTab(s);
    var active = a.tabs.filter(function (tab) { return tab.id === a.activeId; })[0];

    var tabsHtml = a.tabs.map(function (tab) {
      return '<span class="pv-tab' + (tab.id === a.activeId ? ' is-active' : '') + '">' +
        (s.tabIcons ? icon(tab.icon) : '') + '<span>' + esc(tab.label) + '</span></span>';
    }).join('') || '<span>' + esc(t.noTabs || '') + '</span>';

    var links = '';
    if (s.showUser) { links += '<span class="pv-dash-user">' + icon('fas fa-user') + ' ' + esc(cfg.userLogin || cfg.userName || '') + '</span>'; }
    if (s.showLogout) { links += '<span class="pv-dash-logout">' + esc(t.logout || 'Logout') + ' ' + icon('fas fa-sign-out-alt') + '</span>'; }
    if (s.darkEnabled && s.darkToggle) { links += '<span class="pv-dash-dark">' + icon(dark ? 'fas fa-sun' : 'fas fa-moon') + '</span>'; }

    var nav = [t.shop || 'Shop', t.myAccount || 'My Account', t.cart || 'Cart', t.checkout || 'Checkout'].map(function (n) { return '<span>' + esc(n) + '</span>'; }).join('');

    return '' +
      '<header class="pv-site-header">' +
        '<span class="pv-site-logo">' + esc(cfg.siteName || '') + '</span>' +
        '<nav class="pv-site-nav">' + nav + '</nav>' +
        '<span class="pv-site-burger">' + icon('fa-solid fa-bars') + '</span>' +
      '</header>' +
      '<div class="pv-page">' +
        '<h1 class="pv-page-title">' + esc(cfg.dashboardTitle || '') + '</h1>' +
        '<div class="pv-dash">' +
          '<div class="pv-dash-head">' +
            '<span class="pv-coupon-title">' + icon('fas fa-tag') + (s.prefix ? '<span class="pv-coupon-prefix">' + esc(s.prefix) + '</span> ' : '') + esc(DEMO_COUPON) + '</span>' +
            (links ? '<span class="pv-dash-links">' + links + '</span>' : '') +
          '</div>' +
          '<div class="pv-tabs">' + tabsHtml + '</div>' +
          '<div class="pv-tab-select"><span>' + (active ? esc(active.label) : '') + '</span>' + icon('fa-solid fa-chevron-down') + '</div>' +
          '<div class="pv-content">' + statisticsContent(s, a.statsTab) + '</div>' +
        '</div>' +
      '</div>';
  }

  function drawPortal(s, dark) {
    var a = activeTab(s);

    var logo = '';
    if (s.logo && /^(https?:)?\/\//i.test(s.logo)) {
      logo = '<img src="' + esc(s.logo) + '" alt="">';
    }

    var side = a.tabs.map(function (tab) {
      return '<span class="pv-side-tab' + (tab.id === a.activeId ? ' is-active' : '') + '">' +
        (s.tabIcons ? icon(tab.icon) : '') + '<span>' + esc(tab.label) + '</span></span>';
    }).join('');
    side += '<span class="pv-side-tab pv-side-back">' + (s.tabIcons ? icon('fas fa-arrow-left') : '') + '<span>' + esc(t.backToSite || 'Back to site') + '</span></span>';

    var right = '';
    if (s.portalDarkToggle) { right += '<span class="pv-dark-icon">' + icon(dark ? 'fas fa-moon' : 'fas fa-sun') + '</span>'; }
    if (s.showUser) {
      var initial = (cfg.userName || 'A').charAt(0).toUpperCase();
      var avatar = cfg.avatar
        ? '<span class="pv-avatar" style="background-image:url(\'' + esc(cfg.avatar) + '\')"></span>'
        : '<span class="pv-avatar">' + esc(initial) + '</span>';
      right += '<span class="pv-user"><span class="pv-user-name">' + esc(cfg.userName || '') + '</span>' + avatar + icon('fas fa-caret-down', 'pv-caret') + '</span>';
    }

    var footer = safeHtml(s.footer);
    // Laid out as inline text, spaces included, like the portal header.
    var welcome = icon('fas fa-tag', 'pv-tag') + ' ' +
      '<span class="pv-coupon-prefix">' + esc(s.prefix) + '</span>' + (s.prefix ? ' ' : '') +
      '<span class="pv-code">' + esc(DEMO_COUPON) + '</span>' +
      (s.switcher ? ' <span class="pv-switcher">' + icon('fas fa-chevron-down') + '</span>' : '');

    return '' +
      '<aside class="pv-sidebar">' +
        '<div class="pv-sidebar-logo">' + logo + '<h2 class="pv-portal-title">' + esc(s.portalTitle) + '</h2></div>' +
        '<nav class="pv-side-tabs">' + side + '</nav>' +
      '</aside>' +
      '<div class="pv-main">' +
        '<div class="pv-band"></div>' +
        '<header class="pv-portal-header">' +
          '<span class="pv-burger">' + icon('fas fa-bars') + '</span>' +
          '<div class="pv-welcome">' + welcome + '</div>' +
          '<div class="pv-header-right">' + right + '</div>' +
        '</header>' +
        '<div class="pv-content">' + statisticsContent(s, a.statsTab) + '</div>' +
        (footer ? '<footer class="pv-portal-footer">' + footer + '</footer>' : '') +
      '</div>';
  }

  // ---------------------------------------------------------------------------
  // Preview: shadow root, scaling and redraws
  // ---------------------------------------------------------------------------

  var preview = { inner: null, host: null, shadow: null, holder: null, canvas: null, pv: null, lastKey: '', pending: false, placeholder: null, overlay: null };

  function setupPreview() {
    var host = $root.find('.wcu-gs-preview-host')[0];
    if (!host) { return false; }
    preview.inner = $root.find('.wcu-gs-preview-inner')[0];
    preview.host = host;
    preview.shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    [cfg.fontAwesomeCss, cfg.previewCss].forEach(function (href) {
      if (!href) { return; }
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.addEventListener('load', fit);
      preview.shadow.appendChild(link);
    });

    preview.holder = document.createElement('div');
    preview.holder.className = 'wcu-gs-preview-frame';
    preview.holder.style.cssText = 'position:relative;overflow:hidden;margin:0 auto;border-radius:10px;background:#fff;box-shadow:0 1px 3px rgba(16,24,40,.12),0 12px 32px rgba(16,24,40,.12);';
    preview.canvas = document.createElement('div');
    preview.canvas.style.cssText = 'position:absolute;left:0;top:0;transform-origin:0 0;';
    preview.holder.appendChild(preview.canvas);
    preview.shadow.appendChild(preview.holder);

    // A badge over the corner so the figures are never mistaken for real ones.
    var badge = document.createElement('div');
    badge.className = 'wcu-gs-preview-badge';
    badge.textContent = t.exampleData || 'Example data';
    badge.title = t.exampleDataHint || '';
    badge.style.cssText = 'position:absolute;right:10px;bottom:10px;z-index:2;padding:4px 10px;border-radius:999px;' +
      'background:rgba(17,24,39,.78);color:#fff;font:600 11px/16px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
      'letter-spacing:.04em;text-transform:uppercase;pointer-events:auto;box-shadow:0 2px 8px rgba(0,0,0,.18);';
    preview.holder.appendChild(badge);

    // Nothing in the preview is a real link.
    preview.shadow.addEventListener('click', function (e) { e.preventDefault(); }, true);

    // Keep the frame sized to the drawing as fonts, icons and images load.
    if (window.ResizeObserver) {
      new ResizeObserver(fit).observe(preview.canvas);
      new ResizeObserver(fit).observe($(preview.inner).find('.wcu-gs-preview-stage')[0]);
    } else {
      $(window).on('resize', fit);
    }
    // Icon fonts can finish loading after the stylesheet.
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }
    return true;
  }

  function effectiveScheme(s) {
    var available = s.portal ? s.portalDarkToggle : s.darkEnabled;
    if (!available) { return { dark: false, available: false }; }
    if (state.scheme) { return { dark: state.scheme === 'dark', available: true }; }
    return { dark: !s.portal && s.darkDefault, available: true };
  }

  // ---------------------------------------------------------------------------
  // Colour cards: the portal's "Customise Affiliate Portal Design" here, or the
  // dashboard colours in the Design tab
  // ---------------------------------------------------------------------------

  // One set of colour fields serves the portal and the dashboard page (the pickers are
  // keyed by ID, so they cannot be drawn twice). Keep it where the one in use is edited:
  // here beside the preview while the portal is on, otherwise in the Design tab.
  function placeColourCards() {
    var $cards = $('#affiliate-dashboard-colors');
    if (!$cards.length) { return; }
    var portal = isPortal();
    $cards.toggleClass('is-dashboard', !portal);
    var $slot = $('.wcu-pd-slot[data-wcu-pd-slot="' + (portal ? 'portal' : 'dashboard') + '"]');
    if ($slot.length && $cards.parent()[0] !== $slot[0]) { $slot.append($cards); }
  }

  // Each card has a swatch showing its colours together. Keep it in step.
  function updateSamples() {
    $('.wcu-pd-sample').each(function () {
      var bg = this.getAttribute('data-bg');
      var fg = this.getAttribute('data-fg');
      var bar = this.getAttribute('data-bar');
      this.style.background = bg ? color(bg) : '';
      this.style.color = fg ? color(fg) : '';
      if (bar) { this.style.setProperty('--wcu-pd-bar', color(bar)); }
    });
  }

  // The colour picker's "Default" / "Clear" buttons are <input>s, so they cannot take an
  // icon from CSS. Give them a compact symbol, keeping the full name for tooltips and
  // screen readers. Covers every colour row on the settings page (.wcu-color-row).
  function compactDefaultButtons() {
    $('.wcu-color-row .wp-picker-default, .wcu-color-row .wp-picker-clear').each(function () {
      if (this.getAttribute('data-wcu-compact')) { return; }
      var isClear = $(this).hasClass('wp-picker-clear');
      var label = this.value || (isClear ? 'Clear' : 'Default');
      this.setAttribute('data-wcu-compact', '1');
      this.setAttribute('title', label);
      this.setAttribute('aria-label', this.getAttribute('aria-label') || label);
      this.value = isClear ? '\u00d7' : '\u21ba';
    });
  }

  function render() {
    preview.pending = false;
    updateSamples();
    if (!preview.canvas) { return; }
    var s = readSettings();
    var scheme = effectiveScheme(s);
    var mobile = state.device === 'mobile';

    var classes = ['pv', s.portal ? 'pv-portal' : 'pv-dashboard'];
    if (mobile) { classes.push('pv-mobile'); }
    if (scheme.dark) { classes.push('pv-dark'); }
    if (s.portal) {
      classes.push('pv-theme-' + s.theme);
      if (s.theme !== 'classic') { classes.push('pv-themed'); }
    } else {
      classes.push('pv-tabs-style-' + s.tabsStyle, 'pv-radius-' + s.tabsRadius, 'pv-pad-' + s.tabsPadding);
      if (s.mobileMenu === 'dropdown') { classes.push('pv-mobile-dropdown'); }
    }

    var vars = {};
    Object.keys(s.colors).forEach(function (k) { vars[k] = s.colors[k]; });
    vars['--font'] = s.portal && s.font ? s.font.replace(/[;{}<>]/g, '') : '';
    vars['--tabs-font-size'] = !s.portal && s.tabsFontSize >= 8 && s.tabsFontSize <= 40 ? s.tabsFontSize + 'px' : '';

    // Only rebuild the drawing when its content changes. Colour changes (including
    // dragging a colour picker) just update the custom properties, so images and
    // icons are not torn down and redrawn on every step.
    var body = s.portal ? drawPortal(s, scheme.dark) : drawDashboard(s, scheme.dark);
    var key = classes.join(' ') + '|' + body;
    if (key !== preview.lastKey || !preview.pv) {
      preview.lastKey = key;
      preview.canvas.innerHTML = '<div class="' + classes.join(' ') + '">' + body + '</div>';
      preview.pv = preview.canvas.firstChild;
      $(preview.canvas).find('img').on('load error', fit);
    }
    Object.keys(vars).forEach(function (k) {
      if (vars[k]) { preview.pv.style.setProperty(k, vars[k]); } else { preview.pv.style.removeProperty(k); }
    });

    // Toolbar state
    var $inner = $(preview.inner);
    $inner.find('.wcu-gs-preview-mode').text(s.portal ? (t.portal || 'Affiliate Portal') : (t.dashboard || 'Dashboard Page'));
    $inner.find('.wcu-gs-seg[data-control="scheme"] button').prop('disabled', !scheme.available).each(function () {
      var on = (this.getAttribute('data-value') === 'dark') === scheme.dark;
      $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
    });
    $inner.find('.wcu-gs-seg[data-control="device"] button').each(function () {
      var on = this.getAttribute('data-value') === state.device;
      $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
    });
    $inner.find('.wcu-gs-preview-note-text').text(isLegacySaving() ? (t.noteManual || '') : (t.noteAuto || ''));

    fit();
  }

  function fit() {
    if (!preview.canvas) { return; }
    var stage = $(preview.inner).find('.wcu-gs-preview-stage')[0];
    if (!stage || !stage.offsetParent) { return; } // hidden: closed, collapsed, or another settings tab is open
    var mobile = state.device === 'mobile';
    var width = mobile ? MOBILE_WIDTH : DESKTOP_WIDTH;
    var available = stage.clientWidth - 32;
    if (available <= 0) { return; }
    var cap = mobile ? (state.expanded ? 1 : 0.86) : 1;
    var scale = Math.min(cap, available / width);
    // Ignore sub-pixel width changes, so a resize can never feed back into another.
    if (preview.scale && Math.abs(scale - preview.scale) < 0.003) { scale = preview.scale; }
    preview.scale = scale;
    if (preview.canvas.style.width !== width + 'px') { preview.canvas.style.width = width + 'px'; }
    drawGraphs();
    var height = preview.canvas.scrollHeight;
    var transform = 'scale(' + scale.toFixed(4) + ')';
    if (preview.canvas.style.transform !== transform) { preview.canvas.style.transform = transform; }
    var w = Math.round(width * scale) + 'px';
    var h = Math.ceil(height * scale) + 'px';
    if (preview.holder.style.width !== w) { preview.holder.style.width = w; }
    if (preview.holder.style.height !== h) { preview.holder.style.height = h; }
    preview.holder.style.borderRadius = mobile ? '24px' : '10px';
    preview.holder.style.outline = mobile ? '7px solid #1d2939' : '0';
    preview.holder.style.outlineOffset = mobile ? '0' : '';
    preview.holder.style.margin = mobile ? '7px auto' : '0 auto';
  }

  function scheduleRender() {
    if (preview.pending) { return; }
    preview.pending = true;
    (window.requestAnimationFrame || window.setTimeout)(render);
  }

  // Wide screens: show or hide the preview column (opened from the floating button).
  function setOpen(open) {
    state.open = open;
    $root.find('.wcu-gs-layout').toggleClass('wcu-gs-preview-open', open);
    $root.find('.wcu-gs-preview-fab').attr('aria-expanded', open ? 'true' : 'false');
    if (open) {
      scheduleRender();
      setTimeout(fit, 0);
      $(preview.inner).find('.wcu-gs-preview-close').trigger('focus');
    } else {
      $root.find('.wcu-gs-preview-fab').trigger('focus');
    }
  }

  // Small screens: open or close the accordion at the bottom of the tab.
  function setCollapsed(collapsed) {
    state.collapsed = collapsed;
    $root.find('.wcu-gs-preview').toggleClass('is-collapsed', collapsed);
    $(preview.inner).find('.wcu-gs-preview-title').attr('aria-expanded', collapsed ? 'false' : 'true');
    if (!collapsed) { scheduleRender(); }
  }

  // Expanded view: the preview panel moves into a full-window overlay (it cannot simply
  // be made fixed in place, as the settings layout is a CSS container, which would
  // hold a fixed element inside it).
  function setExpanded(expanded) {
    if (expanded === state.expanded || !preview.inner) { return; }
    state.expanded = expanded;
    var $btn = $(preview.inner).find('.wcu-gs-preview-expand');
    if (expanded) {
      preview.placeholder = document.createElement('div');
      preview.inner.parentNode.insertBefore(preview.placeholder, preview.inner);
      preview.overlay = $('<div class="wcu-gs wcu-gs-overlay" role="dialog" aria-modal="true"><div class="wcu-gs-overlay-backdrop"></div><div class="wcu-gs-overlay-panel"></div></div>')[0];
      $(preview.overlay).find('.wcu-gs-overlay-panel').append(preview.inner);
      document.body.appendChild(preview.overlay);
      $('body').addClass('wcu-gs-overlay-open');
      $(preview.inner).addClass('is-expanded');
      $btn.attr('title', $btn.data('title-close')).find('.fa-solid').attr('class', 'fa-solid fa-down-left-and-up-right-to-center');
      $btn.trigger('focus');
    } else {
      preview.placeholder.parentNode.insertBefore(preview.inner, preview.placeholder);
      preview.placeholder.parentNode.removeChild(preview.placeholder);
      preview.placeholder = null;
      $(preview.overlay).remove();
      preview.overlay = null;
      $('body').removeClass('wcu-gs-overlay-open');
      $(preview.inner).removeClass('is-expanded');
      $btn.attr('title', $btn.data('title-expand')).find('.fa-solid').attr('class', 'fa-solid fa-up-right-and-down-left-from-center');
    }
    setTimeout(fit, 0);
  }

  // ---------------------------------------------------------------------------
  // Wiring
  // ---------------------------------------------------------------------------

  function bind() {
    // wcusage_go_to_settings() fires this on its target before scrolling to it. Open a
    // collapsed "Show" section (such as the portal design settings) so it can be seen.
    $(document).on('wcusage-goto', function (e) {
      var target = e.target;
      if (!target || !target.id || $(target).is(':visible')) { return; }
      var $button = $('#wcu_show_' + target.id.replace(/^wcu_/, ''));
      if ($button.length) { $button.trigger('click'); }
    });

    // Any setting change redraws the preview.
    $(document).on('change input', '.wcusage-settings-form input, .wcusage-settings-form select, .wcusage-settings-form textarea', function () {
      // Changing a dark mode setting shows its result, even after the sun/moon buttons.
      if (/^wcusage_(field_dark_mode_(enable|default|toggle)|portal_dark_mode)$/.test(this.id)) { state.scheme = null; }
      scheduleRender();
    });
    // Colour pickers report while dragging, before the field's change event.
    $(document).on('irischange', '.wcusage-settings-form input', function (e, ui) {
      var el = this;
      if (ui && ui.color && typeof ui.color.toString === 'function') {
        var hex = ui.color.toString();
        setTimeout(function () {
          if (el.value !== hex) { el.value = hex; }
          scheduleRender();
        }, 0);
      }
    });
    $(document).on('click', '.wp-picker-clear, .wp-picker-default', function () {
      setTimeout(scheduleRender, 0);
    });
    // Reordering tabs or statistics sections.
    $(document).on('sortupdate sortstop', '#wcusage-dashboard-tabs-order, #wcusage-section-order', scheduleRender);
    // Footer text editor.
    if (window.tinymce) {
      var watch = function (editor) {
        if (!editor || editor.id !== 'wcusage_portal_footer_text') { return; }
        editor.on('input keyup change SetContent Undo Redo', scheduleRender);
      };
      tinymce.on('AddEditor', function (e) { watch(e.editor); });
      for (var i = 0; i < tinymce.editors.length; i++) { watch(tinymce.editors[i]); }
    }

    // Preview toolbar. Bound on the panel itself, as it moves into the overlay.
    var $inner = $(preview.inner);
    $inner.on('click', '.wcu-gs-seg[data-control="device"] button', function () {
      state.device = this.getAttribute('data-value');
      store.set(STORE_DEVICE, state.device);
      scheduleRender();
    });
    $inner.on('click', '.wcu-gs-seg[data-control="scheme"] button', function () {
      state.scheme = this.getAttribute('data-value');
      scheduleRender();
    });
    $inner.on('click', '.wcu-gs-preview-title', function () {
      if (state.expanded || !state.stacked) { return; }
      setCollapsed(!state.collapsed);
    });
    $inner.on('click', '.wcu-gs-preview-close', function () {
      setOpen(false);
    });
    $root.on('click', '.wcu-gs-preview-fab', function () {
      setOpen(true);
    });
    $inner.on('click', '.wcu-gs-preview-expand', function () {
      setExpanded(!state.expanded);
    });
    $(document).on('click', '.wcu-gs-overlay-backdrop', function () { setExpanded(false); });
    $(document).on('keydown', function (e) {
      if (state.expanded && e.key === 'Escape') { setExpanded(false); }
    });

    // Returning to the General tab from another settings tab.
    $('#tab-general').on('click', function () { setTimeout(fit, 0); });
  }

  $(function () {
    // The colour pickers are built by their own ready handlers; catch them either way.
    compactDefaultButtons();
    setTimeout(compactDefaultButtons, 0);

    placeColourCards();
    $(document).on('change', '.wcusage_field_portal_enable', placeColourCards);
    // "Show More" / "Show Less" under the colour groups.
    $(document).on('click', '.wcu-pd-more-toggle', function () {
      var $pd = $(this).closest('.wcu-pd').toggleClass('is-showing-more');
      var open = $pd.hasClass('is-showing-more');
      $(this).attr('aria-expanded', open ? 'true' : 'false')
        .find('.wcu-pd-more-label').text(this.getAttribute(open ? 'data-label-less' : 'data-label-more'));
    });

    $root = $('#general-settings.wcu-gs');
    if (!$root.length || !setupPreview()) { return; }

    // Two columns need room. Without it, the preview is a closed accordion at the
    // bottom of the tab instead of a column opened from the floating button.
    var layout = $root.find('.wcu-gs-layout')[0];
    var stack = function () {
      if (!layout || !layout.offsetParent) { return; }
      state.stacked = layout.clientWidth < 960;
      $(layout).toggleClass('wcu-gs-stacked', state.stacked);
      $(preview.inner).find('.wcu-gs-preview-title').attr('aria-disabled', state.stacked ? null : 'true');
    };
    if (window.ResizeObserver && layout) { new ResizeObserver(stack).observe(layout); } else { $(window).on('resize', stack); }
    stack();

    bind();
    setCollapsed(state.collapsed);
    render();
  });

})(jQuery);
