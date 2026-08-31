/**
 * Admin View Affiliate Page JavaScript
 */

jQuery(document).ready(function($) {
    // Mobile tab dropdown — inject <select> before the tab bar
    (function() {
        var $tabs = $('.wcusage-tabs');
        if (!$tabs.length) { return; }

        // Build the select element from the existing nav-tab links
        var $select = $('<select class="wcusage-tab-select" aria-label="Navigate tabs"></select>');
        $tabs.find('.nav-tab').each(function() {
            var $tab  = $(this);
            var href  = $tab.attr('href');
            var label = $tab.text().trim();
            var $opt  = $('<option></option>').val(href).text(label);
            if ($tab.hasClass('nav-tab-active')) { $opt.prop('selected', true); }
            if ($tab.hasClass('wcusage-tab-disabled')) { $opt.prop('disabled', true); }
            $select.append($opt);
        });

        var $wrapper = $('<div class="wcusage-tab-select-wrapper"></div>').append($select);
        $tabs.before($wrapper);

        $select.on('change', function() {
            var href  = $(this).val();
            var $link = $tabs.find('.nav-tab[href="' + href + '"]');
            if ($link.length) { $link.trigger('click'); }
        });
    })();

    // Tab switching functionality
    $('.wcusage-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();

        // Remove active class from all tabs
        $('.wcusage-tabs .nav-tab').removeClass('nav-tab-active');
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');

        // Hide all tab content
        $('.wcusage-tab-content > div').removeClass('active');

        // Get tab ID from href
        var tabId = $(this).attr('href').replace('#', '');
        // Show the corresponding tab content
        $('#' + tabId).addClass('active');

        // If MLA tab, draw chart on demand
        if (tabId === 'tab-mla' && typeof window.WCU_MLA_draw === 'function') {
            try { window.WCU_MLA_draw(); } catch(e) {}
        }

        // Sync mobile dropdown
        $('.wcusage-tab-select').val('#' + tabId);

        // Update URL without page reload
        var tab = tabId.replace('tab-', '');
        var newUrl = window.location.pathname + window.location.search.replace(/([&?]tab=)[^&]*/, '$1' + tab);
        if (window.location.search.indexOf('tab=') === -1) {
            newUrl += (window.location.search ? '&' : '?') + 'tab=' + tab;
        }
        history.pushState(null, null, newUrl);
    });

    // Handle browser back/forward buttons
    $(window).on('popstate', function() {
        var urlParams = new URLSearchParams(window.location.search);
        var tab = urlParams.get('tab') || 'overview';
        $('.wcusage-tabs .nav-tab').removeClass('nav-tab-active');
        $('.wcusage-tabs .nav-tab[href="#tab-' + tab + '"]').addClass('nav-tab-active');
        $('.wcusage-tab-content > div').removeClass('active');
        $('#tab-' + tab).addClass('active');

        // Sync mobile dropdown
        $('.wcusage-tab-select').val('#tab-' + tab);

        // If MLA tab is now active, ensure chart draws
        if (tab === 'mla' && typeof window.WCU_MLA_draw === 'function') {
            try { window.WCU_MLA_draw(); } catch(e) {}
        }
    });

    // On initial load, if MLA tab is active from URL, draw immediately
    (function(){
        var urlParams = new URLSearchParams(window.location.search);
        var initTab = urlParams.get('tab') || 'overview';
        if (initTab === 'mla' && typeof window.WCU_MLA_draw === 'function') {
            try { window.WCU_MLA_draw(); } catch(e) {}
        }
        // Or if markup already marks MLA tab active
        if ($('#tab-mla').hasClass('active') && typeof window.WCU_MLA_draw === 'function') {
            try { window.WCU_MLA_draw(); } catch(e) {}
        }
    })();

    // Helpers
    function getDateVal(selector) {
        var v = $(selector).val();
        return v ? v : '';
    }

    // Last page loaded per table, so a table can be refreshed in place (e.g.
    // after saving a lifetime link) without jumping back to page 1.
    var loadedPage = {};

    function ajaxLoad(type, page) {
        var actionMap = {
            // The status and coupon selects are only rendered when there is more than
            // one to pick from; a missing input reads as an empty value, which is the
            // same as "no filter".
            referrals: { action: 'wcusage_get_affiliate_referrals', nonce: WCUAdminAffiliateView.nonce_referrals, container: '#wcusage-referrals-table-container', start: '#referrals-start-date', end: '#referrals-end-date', extra: { order_status: '#referrals-status', coupon_code: '#referrals-coupon', search: '#referrals-search' } },
            visits: { action: 'wcusage_get_affiliate_visits', nonce: WCUAdminAffiliateView.nonce_visits, container: '#wcusage-visits-table-container', start: '#visits-start-date', end: '#visits-end-date' },
            payouts: { action: 'wcusage_get_affiliate_payouts', nonce: WCUAdminAffiliateView.nonce_payouts, container: '#wcusage-payouts-table-container', start: '#payouts-start-date', end: '#payouts-end-date' },
            activity: { action: 'wcusage_get_affiliate_activity', nonce: WCUAdminAffiliateView.nonce_activity, container: '#wcusage-activity-table-container', start: '#activity-start-date', end: '#activity-end-date' },
            // Filtered by link status rather than a date range, so it declares
            // its extra field instead of the shared start/end date inputs.
            lifetime: { action: 'wcusage_get_affiliate_lifetime_customers', nonce: WCUAdminAffiliateView.nonce_lifetime, container: '#wcusage-lifetime-table-container', extra: { lifetime_status: '#lifetime-status' } }
        };
        var cfg = actionMap[type];
        if (!cfg) return;
        if (typeof WCUAdminAffiliateView === 'undefined') {
            console.error('WCUAdminAffiliateView not available');
            return;
        }
        loadedPage[type] = page || 1;
        var data = {
            action: cfg.action,
            _wpnonce: cfg.nonce,
            user_id: WCUAdminAffiliateView.user_id,
            page: page || 1,
            per_page: WCUAdminAffiliateView.per_page,
            start_date: getDateVal(cfg.start),
            end_date: getDateVal(cfg.end),
            _ts: Date.now()
        };
        if (cfg.extra) {
            $.each(cfg.extra, function(name, selector){
                data[name] = $(selector).val() || '';
            });
        }
        var $container = $(cfg.container);
        var $btns = $(cfg.container + ' .pagination-links a.button');
        $btns.prop('disabled', true);
        $container.addClass('wcusage-loading');
        $.ajax({
            url: WCUAdminAffiliateView.ajax_url,
            method: 'POST',
            data: data,
            dataType: 'html'
        }).done(function(html){
            $container.html(html);
        }).fail(function(jqXHR){
            console.error('AJAX failed', jqXHR.status, jqXHR.responseText);
            $container.html('<div class="notice notice-error"><p>Failed to load data. Please reload the page.</p></div>');
        }).always(function(){
            $container.removeClass('wcusage-loading');
            $(cfg.container + ' .pagination-links a.button').prop('disabled', false);
        });
    }

    // Apply filter buttons
    $('#referrals-apply-filters').on('click', function(e){ e.preventDefault(); ajaxLoad('referrals', 1); });
    // Enter in the search box filters, rather than submitting whatever form it sits in.
    $('#referrals-search').on('keydown', function(e){ if (e.key === 'Enter' || e.which === 13) { e.preventDefault(); ajaxLoad('referrals', 1); } });
    $('#visits-apply-filters').on('click', function(e){ e.preventDefault(); ajaxLoad('visits', 1); });
    $('#payouts-apply-filters').on('click', function(e){ e.preventDefault(); ajaxLoad('payouts', 1); });
    $('#activity-apply-filters').on('click', function(e){ e.preventDefault(); ajaxLoad('activity', 1); });
    $('#lifetime-apply-filters').on('click', function(e){ e.preventDefault(); ajaxLoad('lifetime', 1); });

    // Pagination link clicks (delegated)
    $('#wcusage-referrals-table-container').on('click', '.pagination-links a.button', function(e){
        e.preventDefault();
        if ($(this).attr('aria-disabled') === 'true' || $(this).prop('disabled')) return;
        var page = parseInt($(this).data('page'), 10) || 1;
        ajaxLoad('referrals', page);
    });
    $('#wcusage-visits-table-container').on('click', '.pagination-links a.button', function(e){
        e.preventDefault();
        if ($(this).attr('aria-disabled') === 'true' || $(this).prop('disabled')) return;
        var page = parseInt($(this).data('page'), 10) || 1;
        ajaxLoad('visits', page);
    });
    $('#wcusage-payouts-table-container').on('click', '.pagination-links a.button', function(e){
        e.preventDefault();
        if ($(this).attr('aria-disabled') === 'true' || $(this).prop('disabled')) return;
        var page = parseInt($(this).data('page'), 10) || 1;
        ajaxLoad('payouts', page);
    });
    $('#wcusage-activity-table-container').on('click', '.pagination-links a.button', function(e){
        e.preventDefault();
        if ($(this).attr('aria-disabled') === 'true' || $(this).prop('disabled')) return;
        var page = parseInt($(this).data('page'), 10) || 1;
        ajaxLoad('activity', page);
    });
    $('#wcusage-lifetime-table-container').on('click', '.pagination-links a.button', function(e){
        e.preventDefault();
        if ($(this).attr('aria-disabled') === 'true' || $(this).prop('disabled')) return;
        var page = parseInt($(this).data('page'), 10) || 1;
        ajaxLoad('lifetime', page);
    });

    // Direct page input (Enter key)
    function bindPageInput(containerSel, type) {
        $(containerSel).on('keydown', '.paging-input .current-page', function(e){
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                var val = parseInt($(this).val(), 10) || 1;
                ajaxLoad(type, val);
            }
        });
    }
    bindPageInput('#wcusage-referrals-table-container', 'referrals');
    bindPageInput('#wcusage-visits-table-container', 'visits');
    bindPageInput('#wcusage-payouts-table-container', 'payouts');
    bindPageInput('#wcusage-activity-table-container', 'activity');
    bindPageInput('#wcusage-lifetime-table-container', 'lifetime');

    // --- Lifetime Customers: inline edit / remove ---------------------------
    var lifetimeI18n = (WCUAdminAffiliateView && WCUAdminAffiliateView.lifetime_i18n) || {};

    function lifetimeMessage(text, type) {
        var $box = $('#wcusage-lifetime-message');
        if (!$box.length) { return; }
        $box.attr('class', 'notice notice-' + (type || 'success'))
            .html('<p>' + text + '</p>')
            .show();
    }

    function lifetimeRowEditing($row, editing) {
        $row.find('.wcusage-lifetime-view').toggle(!editing);
        $row.find('.wcusage-lifetime-edit').toggle(!!editing);
        $row.toggleClass('wcusage-lifetime-row-editing', !!editing);
    }

    // Only one row open at a time, so a half-finished edit cannot be lost
    // behind another one.
    function lifetimeCloseAllRows() {
        $('#wcusage-lifetime-table-container .wcusage-lifetime-row').each(function(){
            lifetimeRowEditing($(this), false);
        });
    }

    $('#wcusage-lifetime-table-container').on('click', '.wcusage-lifetime-edit-button', function(e){
        e.preventDefault();
        var $row = $(this).closest('.wcusage-lifetime-row');
        lifetimeCloseAllRows();
        lifetimeRowEditing($row, true);
        $row.find('.wcusage-lifetime-coupon-input').trigger('focus');
    });

    $('#wcusage-lifetime-table-container').on('click', '.wcusage-lifetime-cancel-button', function(e){
        e.preventDefault();
        var $row = $(this).closest('.wcusage-lifetime-row');
        // Discard edits by restoring the inputs to their rendered values.
        $row.find('.wcusage-lifetime-coupon-input, .wcusage-lifetime-expiry-input').each(function(){
            this.value = this.defaultValue;
        });
        lifetimeRowEditing($row, false);
    });

    function lifetimeWrite($row, action, extraData, confirmText) {
        if (confirmText && !window.confirm(confirmText)) { return; }
        var $actions = $row.find('.wcusage-lifetime-actions a');
        $actions.css('pointer-events', 'none').css('opacity', 0.5);
        $row.addClass('wcusage-loading');

        $.ajax({
            url: WCUAdminAffiliateView.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: $.extend({
                action: action,
                _wpnonce: WCUAdminAffiliateView.nonce_lifetime,
                user_id: WCUAdminAffiliateView.user_id,
                customer_id: $row.data('customer-id')
            }, extraData || {})
        }).done(function(res){
            if (res && res.success) {
                lifetimeMessage((res.data && res.data.message) || '', 'success');
                // Reload so the summary counts, sorting and filter stay honest.
                ajaxLoad('lifetime', loadedPage.lifetime || 1);
            } else {
                lifetimeMessage((res && res.data && res.data.message) || lifetimeI18n.error || 'Could not save.', 'error');
                $actions.css('pointer-events', '').css('opacity', '');
                $row.removeClass('wcusage-loading');
            }
        }).fail(function(jqXHR){
            var msg = lifetimeI18n.error || 'Could not save.';
            if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                msg = jqXHR.responseJSON.data.message;
            }
            lifetimeMessage(msg, 'error');
            $actions.css('pointer-events', '').css('opacity', '');
            $row.removeClass('wcusage-loading');
        });
    }

    $('#wcusage-lifetime-table-container').on('click', '.wcusage-lifetime-save-button', function(e){
        e.preventDefault();
        var $row = $(this).closest('.wcusage-lifetime-row');
        lifetimeWrite($row, 'wcusage_save_lifetime_link', {
            coupon_code: $row.find('.wcusage-lifetime-coupon-input').val(),
            expire_date: $row.find('.wcusage-lifetime-expiry-input').val()
        });
    });

    $('#wcusage-lifetime-table-container').on('click', '.wcusage-lifetime-remove-button', function(e){
        e.preventDefault();
        var $row = $(this).closest('.wcusage-lifetime-row');
        lifetimeWrite($row, 'wcusage_remove_lifetime_link', {}, lifetimeI18n.confirm_remove || 'Remove this lifetime link?');
    });

    // Enter saves, Escape cancels, while an edit row is open.
    $('#wcusage-lifetime-table-container').on('keydown', '.wcusage-lifetime-coupon-input, .wcusage-lifetime-expiry-input', function(e){
        var $row = $(this).closest('.wcusage-lifetime-row');
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            $row.find('.wcusage-lifetime-save-button').trigger('click');
        } else if (e.key === 'Escape' || e.keyCode === 27) {
            e.preventDefault();
            $row.find('.wcusage-lifetime-cancel-button').trigger('click');
        }
    });

    // --- Lifetime Customers: link a new customer ---------------------------
    var $addForm = $('#wcusage-lifetime-add-form');

    if ($addForm.length) {

        var $addSearch   = $('#wcusage-lifetime-customer-search');
        var $addId       = $('#wcusage-lifetime-customer-id');
        var $addHint     = $('#wcusage-lifetime-customer-hint');
        var $addCoupon   = $('#wcusage-lifetime-add-coupon');
        var $addExpiry   = $('#wcusage-lifetime-add-expiry');
        var $addExpHint  = $('#wcusage-lifetime-add-expiry-hint');
        var $addSubmit   = $('#wcusage-lifetime-add-submit');
        var $addSpinner  = $('.wcusage-lifetime-add-spinner');

        function lifetimeAddClearSelection() {
            $addId.val('');
            $addHint.text('').removeClass('wcusage-lifetime-warn');
            $addSubmit.prop('disabled', true);
        }

        // Date N days from today, as the Y-m-d an <input type="date"> wants.
        function lifetimeAddDateInDays(days) {
            var d = new Date();
            d.setDate(d.getDate() + days);
            var m = String(d.getMonth() + 1);
            var day = String(d.getDate());
            if (m.length < 2) { m = '0' + m; }
            if (day.length < 2) { day = '0' + day; }
            return d.getFullYear() + '-' + m + '-' + day;
        }

        // The hint under the expiry field follows the selected coupon, because
        // a coupon can carry its own expiry period that overrides the global
        // setting. Zero days means the link never expires.
        function lifetimeAddUpdateExpiryHint() {
            var days = parseInt($addCoupon.find('option:selected').data('expire-days'), 10) || 0;
            $addExpHint.empty();
            if (days > 0) {
                var label = (lifetimeI18n.use_default || 'Use default (%s days)').replace('%s', days);
                $('<a href="#"></a>')
                    .text(label)
                    .on('click', function(e){
                        e.preventDefault();
                        $addExpiry.val(lifetimeAddDateInDays(days));
                    })
                    .appendTo($addExpHint);
                $addExpHint.append(document.createTextNode(' · ' + (lifetimeI18n.no_expiry || 'Leave blank for no expiry.')));
            } else {
                $addExpHint.text(lifetimeI18n.no_expiry || 'Leave blank for no expiry.');
            }
        }

        // The toggle now sits up in the tab heading, away from the panel it
        // opens, so it carries the open/closed state itself.
        var $addToggle = $('#wcusage-lifetime-add-toggle');

        // "active" is core's own pressed-button class, so the open state picks
        // up whichever admin colour scheme the user has chosen.
        function lifetimeAddSetOpen(open) {
            $addToggle.attr('aria-expanded', open ? 'true' : 'false').toggleClass('active', open);
        }

        $addToggle.on('click', function(){
            $addForm.slideToggle(200, function(){
                var open = $addForm.is(':visible');
                lifetimeAddSetOpen(open);
                if (open) {
                    $addSearch.trigger('focus');
                }
            });
        });

        $addSearch.autocomplete({
            minLength: 2,
            source: function(request, response) {
                $.post(WCUAdminAffiliateView.ajax_url, {
                    action: 'wcusage_search_lifetime_customers',
                    _wpnonce: WCUAdminAffiliateView.nonce_lifetime,
                    search: request.term
                }).done(function(res){
                    response((res && res.success && res.data) ? res.data : []);
                }).fail(function(){ response([]); });
            },
            select: function(event, ui) {
                $addId.val(ui.item.id);
                $addSubmit.prop('disabled', false);
                // Flagged before submitting, so replacing someone else's
                // lifetime link is never a surprise.
                if (ui.item.linked) {
                    // Function replacement, so a "$&" in a coupon code is not
                    // treated as a back-reference.
                    var linked = ui.item.linked;
                    $addHint
                        .text((lifetimeI18n.already_linked || 'Already linked to coupon "%s".').replace('%s', function(){ return linked; }))
                        .addClass('wcusage-lifetime-warn');
                } else {
                    $addHint.text('').removeClass('wcusage-lifetime-warn');
                }
            }
        });

        // Typing after a selection invalidates it - the ID must come from the list.
        $addSearch.on('input', lifetimeAddClearSelection);

        $addCoupon.on('change', lifetimeAddUpdateExpiryHint);
        lifetimeAddUpdateExpiryHint();

        function lifetimeAddSubmit(overwrite) {
            if (!$addId.val()) {
                lifetimeMessage(lifetimeI18n.select_customer || 'Select a customer first.', 'error');
                return;
            }

            $addSubmit.prop('disabled', true);
            $addSpinner.addClass('is-active');

            $.ajax({
                url: WCUAdminAffiliateView.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'wcusage_add_lifetime_link',
                    _wpnonce: WCUAdminAffiliateView.nonce_lifetime,
                    user_id: WCUAdminAffiliateView.user_id,
                    customer_id: $addId.val(),
                    coupon_code: $addCoupon.val(),
                    expire_date: $addExpiry.val(),
                    overwrite: overwrite ? 1 : 0
                }
            }).done(function(res){
                if (res && res.success) {
                    lifetimeMessage((res.data && res.data.message) || '', 'success');
                    $addSearch.val('');
                    $addExpiry.val('');
                    lifetimeAddClearSelection();
                    lifetimeAddIdle();
                    $addForm.slideUp(200);
                    lifetimeAddSetOpen(false);
                    // Back to page 1 so the new row is on the page being shown.
                    ajaxLoad('lifetime', 1);
                } else {
                    lifetimeAddHandleError(res);
                }
            }).fail(function(jqXHR){
                lifetimeAddHandleError(jqXHR.responseJSON);
            });
        }

        function lifetimeAddIdle() {
            $addSpinner.removeClass('is-active');
            $addSubmit.prop('disabled', !$addId.val());
        }

        function lifetimeAddHandleError(res) {
            var data = (res && res.data) || {};
            var msg = data.message || lifetimeI18n.error || 'Could not save.';
            // The customer already has a link to a different coupon: confirm,
            // then send the same request again with the overwrite flag. The
            // form is deliberately left in its busy state until that second
            // request settles, so the button cannot be pressed twice.
            if (data.code === 'already_linked' && window.confirm(msg)) {
                lifetimeAddSubmit(true);
                return;
            }
            lifetimeMessage(msg, 'error');
            lifetimeAddIdle();
        }

        $addSubmit.on('click', function(e){
            e.preventDefault();
            lifetimeAddSubmit(false);
        });

        // Enter anywhere in the form submits it.
        $addForm.on('keydown', 'input', function(e){
            if (e.key === 'Enter' || e.keyCode === 13) {
                // Not while an autocomplete suggestion is being picked.
                if ($(this).is($addSearch) && $('.ui-autocomplete:visible').length) { return; }
                e.preventDefault();
                lifetimeAddSubmit(false);
            }
        });

    }

    // Copy referral link in Affiliate Coupons list (works even if input is hidden)
    $(document).on('click', '.wcusage-copy-link-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        var $input = $(this).siblings('.wcusage-copy-link-text');
        if (!$input.length) return;
        var text = $input.val();

        function fallbackCopy(t) {
            var ta = document.createElement('textarea');
            ta.value = t;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); } catch(e) { console.warn('Copy failed', e); }
            document.body.removeChild(ta);
        }

    if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function(){ fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    });

    // Quick Edit: toggle
    $(document).on('click', '.quick-edit-coupon', function(e) {
        e.preventDefault();
        var id = $(this).data('coupon-id');
        $('#quick-edit-' + id).toggle();
    });

    // Quick Edit: cancel
    $(document).on('click', '.cancel-quick-edit', function(e) {
        e.preventDefault();
        $(this).closest('.quick-edit-row').hide();
    });

    // Init user autocomplete inputs created in the DOM
    function initUserAutocomplete($input) {
        try {
            $input.autocomplete({
                source: function(request, response) {
                    $.post(WCUAdminAffiliateView.ajax_url, {
                        action: 'wcusage_search_users',
                        nonce: WCUAdminAffiliateView.coupon_nonce,
                        search: request.term,
                        label: 'username'
                    }).done(function(data){
                        if (data && data.success) response(data.data); else response([]);
                    }).fail(function(){ response([]); });
                },
                minLength: 2
            }).autocomplete('instance')._renderItem = function(ul, item) {
                return $('<li>').append('<div>' + item.label + '</div>').appendTo(ul);
            };
        } catch(e) {}
    }
    $(document).on('focus', '.wcu-autocomplete-user', function(){
        if (!$(this).data('ui-autocomplete')) initUserAutocomplete($(this));
    });

    // Quick Edit: save
    $(document).on('click', '.save-quick-edit', function(e) {
        e.preventDefault();
        var id = $(this).data('coupon-id');
        var $row = $('#quick-edit-' + id);
        var $spinner = $row.find('.spinner');
        $spinner.addClass('is-active');

        function val(sel){ return $row.find(sel).val(); }
        function checked(sel){ return $row.find(sel).is(':checked') ? 'yes' : 'no'; }

        var payload = {
            action: 'wcusage_save_coupon_data',
            nonce: WCUAdminAffiliateView.coupon_nonce,
            coupon_id: id,
            post_title: val('#coupon_code_' + id),
            post_excerpt: val('#coupon_description_' + id),
            discount_type: val('#discount_type_' + id),
            coupon_amount: val('#coupon_amount_' + id),
            free_shipping: checked('#free_shipping_' + id),
            expiry_date: val('#expiry_date_' + id),
            minimum_amount: val('#minimum_amount_' + id),
            maximum_amount: val('#maximum_amount_' + id),
            individual_use: checked('#individual_use_' + id),
            exclude_sale_items: checked('#exclude_sale_items_' + id),
            usage_limit_per_user: val('#usage_limit_per_user_' + id),
            wcu_text_coupon_start_date: val('#wcu_text_coupon_start_date_' + id),
            wcu_enable_first_order_only: checked('#wcu_enable_first_order_only_' + id),
            wcu_select_coupon_user: val('#wcu_select_coupon_user_' + id),
            wcu_text_coupon_commission: val('#wcu_text_coupon_commission_' + id),
            wcu_text_coupon_commission_fixed_order: val('#wcu_text_coupon_commission_fixed_order_' + id),
            wcu_text_coupon_commission_fixed_product: val('#wcu_text_coupon_commission_fixed_product_' + id),
            wcu_text_unpaid_commission: val('#wcu_text_unpaid_commission_' + id),
            wcu_text_pending_payment_commission: val('#wcu_text_pending_payment_commission_' + id),
            wcu_text_pending_order_commission: val('#wcu_text_pending_order_commission_' + id) || '0'
        };

        $.ajax({ url: WCUAdminAffiliateView.ajax_url, method: 'POST', data: payload })
        .done(function(resp){
            if (resp && resp.success) {
                // update the main row values we can safely adjust
                var $tr = $('#coupon-row-' + id);
                // Coupon code
                $tr.find('td').eq(0).text(payload.post_title);
                if (resp.data && resp.data.commission_html) {
                    $tr.find('.column-commission').html(resp.data.commission_html);
                }
                // We won't recompute stats here; they update on next refresh
                $row.hide();
            } else {
                alert('Save failed');
            }
        })
        .fail(function(){ alert('Save failed'); })
        .always(function(){ $spinner.removeClass('is-active'); });
    });
});

