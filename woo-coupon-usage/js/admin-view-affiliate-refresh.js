/**
 * Refresh Statistics box.
 *
 * Recalculates each selected coupon's statistics one-by-one via AJAX, mirroring
 * the affiliate-dashboard "REFRESH ALL DATA" batch refresh (a full refresh that
 * recalculates individual orders too). Used on both the View Affiliate page and
 * the coupon edit page.
 */
jQuery(function ($) {

    var cfg = window.WCUAdminAffiliateView || {};
    var $box = $('.wcusage-refresh-stats-box');
    if (!$box.length) { return; }

    // The affiliate user id is taken from the box itself (falling back to the
    // localized config) so the same script works on the View Affiliate page and
    // on the coupon edit page.
    var USER_ID = $box.attr('data-user-id');
    if (USER_ID === undefined || USER_ID === '') { USER_ID = cfg.user_id; }

    var i18n = cfg.refresh_i18n || {};
    var MAX_RETRIES = 2;

    var running = false;

    /* ------------------------------------------------------------------ */
    /* UI helpers                                                          */
    /* ------------------------------------------------------------------ */

    function log(message, type) {
        var $logbox = $box.find('.wcusage-refresh-log');
        $logbox.show();
        var cls = 'wcusage-refresh-log-line';
        if (type) { cls += ' is-' + type; }
        $('<div></div>').addClass(cls).html(message).appendTo($logbox);
        $logbox.scrollTop($logbox[0].scrollHeight);
    }

    function $itemFor(couponId) {
        return $box.find('.wcusage-refresh-coupon-item[data-coupon="' + couponId + '"]');
    }

    function setProgress($item, percent) {
        if (percent < 0) { percent = 0; }
        if (percent > 100) { percent = 100; }
        $item.find('.wcusage-refresh-progress').show();
        $item.find('.wcusage-refresh-progress-fill').css('width', percent + '%');
    }

    function setStatus($item, text, state) {
        var $status = $item.find('.wcusage-refresh-coupon-status');
        $status.removeClass('is-running is-done is-error');
        if (state) { $status.addClass('is-' + state); }
        $status.text(text ? text : '');
    }

    function couponCode($item) {
        return $item.find('.wcusage-refresh-coupon-code').text();
    }

    /* ------------------------------------------------------------------ */
    /* Panel toggling + selection                                         */
    /* ------------------------------------------------------------------ */

    function openPanel() {
        $box.addClass('is-open');
        $box.find('.wcusage-refresh-toggle').attr('aria-expanded', 'true');
        $box.find('.wcusage-refresh-panel').slideDown(150);
    }

    $box.on('click', '.wcusage-refresh-toggle', function () {
        var willOpen = !$box.hasClass('is-open');
        $box.toggleClass('is-open', willOpen);
        $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
        $box.find('.wcusage-refresh-panel').slideToggle(150);
    });

    // "Refresh Statistics" button in the "needs to be loaded once" notice:
    // scroll down to the box, open it, and briefly draw attention to it.
    $(document).on('click', '.wcusage-refresh-scroll-btn', function (e) {
        e.preventDefault();
        openPanel();
        if ($box[0] && $box[0].scrollIntoView) {
            $box[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        $box.addClass('wcusage-attention');
        setTimeout(function () { $box.removeClass('wcusage-attention'); }, 1500);
    });

    $box.on('change', '.wcusage-refresh-selectall', function () {
        if (running) { return; }
        $box.find('.wcusage-refresh-coupon-cb').prop('checked', $(this).prop('checked'));
    });

    // Keep the "select all" box in sync with individual selections.
    $box.on('change', '.wcusage-refresh-coupon-cb', function () {
        var total = $box.find('.wcusage-refresh-coupon-cb').length;
        var checked = $box.find('.wcusage-refresh-coupon-cb:checked').length;
        $box.find('.wcusage-refresh-selectall').prop('checked', total === checked);
    });

    /* ------------------------------------------------------------------ */
    /* Table row update                                                   */
    /* ------------------------------------------------------------------ */

    function updateTableRow(data) {
        var $row = $('#coupon-row-' + data.coupon_id);
        if (!$row.length) { return; }
        $row.find('.wcusage-col-usage').text(data.usage);
        $row.find('.wcusage-col-sales').html(data.sales_html);
        $row.find('.column-commission').html(data.commission_html);
        if (typeof data.processing_html !== 'undefined') {
            $row.find('.wcusage-col-processing').html(data.processing_html);
        }
        if (typeof data.unpaid_html !== 'undefined') {
            $row.find('.wcusage-col-unpaid').html(data.unpaid_html);
        }
        // Brief highlight so the admin sees which row changed.
        $row.addClass('wcusage-row-updated');
        setTimeout(function () { $row.removeClass('wcusage-row-updated'); }, 1500);
    }

    // Update the compact coupon stat boxes (coupon edit page) after a refresh.
    function updateCouponStatBoxes(data) {
        var $grid = $('.wcusage-coupon-stats-grid');
        if (!$grid.length) { return; }
        function set(stat, html) {
            if (typeof html !== 'undefined') {
                $grid.find('[data-stat="' + stat + '"] .wcusage-coupon-stat-value').html(html);
            }
        }
        set('sales', data.sales_amount_html);
        set('commission', data.commission_amount_html);
        set('processing', data.processing_html);
        set('unpaid', data.unpaid_html);
        $grid.addClass('wcusage-stats-updated');
        setTimeout(function () { $grid.removeClass('wcusage-stats-updated'); }, 1500);
    }

    /* ------------------------------------------------------------------ */
    /* Refresh a single coupon                                            */
    /* ------------------------------------------------------------------ */

    function refreshCoupon(couponId) {
        var deferred = $.Deferred();
        var $item = $itemFor(couponId);
        var code = couponCode($item);

        setProgress($item, 0);
        setStatus($item, i18n.starting || 'Starting…', 'running');

        // Step 1: get the coupon's order date range.
        $.post(cfg.ajax_url, {
            action: 'wcusage_admin_refresh_start',
            security: cfg.nonce_refresh,
            user_id: USER_ID,
            coupon_id: couponId
        }).done(function (resp) {
            if (!resp || !resp.success) {
                failCoupon($item, code, resp, deferred);
                return;
            }
            runBatches(couponId, code, $item, resp.data, deferred);
        }).fail(function (jqXHR) {
            failCoupon($item, code, jqXHR, deferred);
        });

        return deferred.promise();
    }

    function runBatches(couponId, code, $item, info, deferred) {
        // Windows of roughly equal order counts, built server-side so the work
        // scales with the number of orders rather than the length of history.
        var windows = info.windows || [];
        var index = 0;
        var retries = 0;

        var accum = {
            total_orders: 0,
            full_discount: 0,
            total_commission: 0,
            total_shipping: 0,
            total_count: 0,
            commission_summary: {}
        };

        function setRefreshingStatus(percent) {
            if (percent < 0) { percent = 0; }
            if (percent > 100) { percent = 100; }
            setStatus($item, (i18n.refreshing || 'Refreshing…') + ' (' + percent + '%)', 'running');
        }

        setRefreshingStatus(0);

        function getBatch() {
            if (index >= windows.length) {
                saveCoupon(couponId, code, $item, accum, deferred);
                return;
            }

            var currentWindow = windows[index];

            $.post(cfg.ajax_url, {
                action: 'wcusage_admin_refresh_batch',
                security: cfg.nonce_refresh,
                user_id: USER_ID,
                coupon_id: couponId,
                start: currentWindow[0],
                end: currentWindow[1]
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    failCoupon($item, code, resp, deferred);
                    return;
                }

                var d = resp.data || {};
                accum.total_count += Number(d.total_count) || 0;
                accum.total_orders += Number(d.total_orders) || 0;
                accum.full_discount += Number(d.full_discount) || 0;
                accum.total_commission += Number(d.total_commission) || 0;
                accum.total_shipping += Number(d.total_shipping) || 0;

                var cs = d.commission_summary || {};
                for (var key in cs) {
                    if (!Object.prototype.hasOwnProperty.call(cs, key)) { continue; }
                    if (accum.commission_summary[key]) {
                        accum.commission_summary[key].total += Number(cs[key].total) || 0;
                        accum.commission_summary[key].commission += Number(cs[key].commission) || 0;
                        accum.commission_summary[key].number += Number(cs[key].number) || 0;
                    } else {
                        accum.commission_summary[key] = {
                            total: Number(cs[key].total) || 0,
                            commission: Number(cs[key].commission) || 0,
                            number: Number(cs[key].number) || 0
                        };
                    }
                }

                retries = 0;
                index++;
                var progress = Math.floor((index / windows.length) * 100);
                setProgress($item, progress);
                setRefreshingStatus(progress);
                getBatch();
            }).fail(function (jqXHR) {
                // A single dropped request (a timeout, or a brief server hiccup)
                // should not abandon the coupon — retry the same window first.
                if (retries < MAX_RETRIES) {
                    retries++;
                    setTimeout(getBatch, 1500 * retries);
                    return;
                }
                failCoupon($item, code, jqXHR, deferred);
            });
        }

        getBatch();
    }

    function saveCoupon(couponId, code, $item, accum, deferred) {
        setProgress($item, 100);
        setStatus($item, i18n.saving || 'Saving…', 'running');

        $.post(cfg.ajax_url, {
            action: 'wcusage_admin_refresh_save',
            security: cfg.nonce_refresh,
            user_id: USER_ID,
            coupon_id: couponId,
            stats: accum
        }).done(function (resp) {
            if (!resp || !resp.success) {
                failCoupon($item, code, resp, deferred);
                return;
            }
            updateTableRow(resp.data);
            updateCouponStatBoxes(resp.data);
            setStatus($item, i18n.done || 'Done', 'done');
            log('<strong>' + escapeHtml(code) + '</strong> — ' + (i18n.done || 'Done'), 'done');
            deferred.resolve();
        }).fail(function (jqXHR) {
            failCoupon($item, code, jqXHR, deferred);
        });
    }

    function failCoupon($item, code, errorSource, deferred) {
        setStatus($item, i18n.error || 'Error', 'error');
        $item.find('.wcusage-refresh-progress-fill').addClass('is-error');
        var msg = extractError(errorSource);
        log('<strong>' + escapeHtml(code) + '</strong> — ' +
            (i18n.error || 'Error') + (msg ? ': ' + escapeHtml(msg) : ''), 'error');
        // Continue with the remaining coupons even if one fails.
        deferred.resolve();
    }

    function extractError(source) {
        if (!source) { return ''; }
        if (source.data && source.data.message) { return source.data.message; }
        if (typeof source.data === 'string') { return source.data; }
        if (source.statusText) { return source.statusText; }
        return '';
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ------------------------------------------------------------------ */
    /* Queue processing                                                   */
    /* ------------------------------------------------------------------ */

    function processQueue(ids, index) {
        if (index >= ids.length) {
            finish();
            return;
        }
        refreshCoupon(ids[index]).always(function () {
            processQueue(ids, index + 1);
        });
    }

    function setControlsDisabled(disabled) {
        $box.find('.wcusage-refresh-start, .wcusage-refresh-selectall').prop('disabled', disabled);
        // Coupon checkboxes are disabled during a run; locked ones (single coupon)
        // stay disabled even after re-enabling the rest.
        $box.find('.wcusage-refresh-coupon-cb').each(function () {
            $(this).prop('disabled', disabled || $(this).hasClass('wcusage-refresh-cb-locked'));
        });
        $box.find('.wcusage-refresh-start').toggleClass('is-busy', disabled);
    }

    function finish() {
        running = false;
        setControlsDisabled(false);
        log(i18n.complete || 'Statistics refresh complete.', 'complete');
        // The "dashboard needs to be loaded once" notice no longer applies once
        // the statistics have been refreshed here.
        $('.wcusage-dashboard-load-notice').fadeOut(300);
        // Collapse the box a second after completing (as if the header was clicked).
        setTimeout(function () {
            if ($box.hasClass('is-open')) {
                $box.find('.wcusage-refresh-toggle').trigger('click');
            }
        }, 1000);
    }

    // Run the refresh for the given coupon ids (shared by the Start button and
    // the Reset Start Date box). Opens the refresh panel and shows progress there.
    function startRefresh(ids) {
        if (running || !ids.length) { return; }
        running = true;
        openPanel();
        setControlsDisabled(true);

        // Reset the log and every coupon's progress indicator.
        $box.find('.wcusage-refresh-log').empty().hide();
        $box.find('.wcusage-refresh-coupon-item').each(function () {
            setStatus($(this), '');
            $(this).find('.wcusage-refresh-progress').hide();
            $(this).find('.wcusage-refresh-progress-fill').removeClass('is-error').css('width', '0%');
        });

        processQueue(ids, 0);
    }

    $box.on('click', '.wcusage-refresh-start', function () {
        if (running) { return; }

        var ids;
        var $cbs = $box.find('.wcusage-refresh-coupon-cb');
        if ($cbs.length) {
            // Multi-coupon: refresh the checked coupons.
            ids = $cbs.filter(':checked').map(function () { return $(this).val(); }).get();
        } else {
            // Single-coupon (no checkbox list): refresh the coupon item directly.
            ids = $box.find('.wcusage-refresh-coupon-item[data-coupon]').map(function () {
                return $(this).attr('data-coupon');
            }).get();
        }

        if (!ids.length) {
            window.alert(i18n.none || 'Please select at least one coupon to refresh.');
            return;
        }

        if (!window.confirm(i18n.confirm || 'Refresh statistics for the selected coupons now?')) {
            return;
        }

        startRefresh(ids);
    });

    /* ------------------------------------------------------------------ */
    /* Reset Start Date box (coupon edit page)                            */
    /* ------------------------------------------------------------------ */

    (function () {
        var $sd = $('.wcusage-startdate-box');
        if (!$sd.length) { return; }

        // The matching field in the coupon data panel.
        var $couponField = $('#wcu_text_coupon_start_date');
        var $input = $sd.find('.wcusage-startdate-input');
        var $resetBtn = $sd.find('.wcusage-startdate-reset-btn');

        // The saved value at load — Reset is only enabled once it changes.
        var originalDate = $input.val();

        // Reset is enabled only when the date actually changed. If the coupon had
        // no start date, that means a date must be entered (effectively required);
        // if it already had one, clearing it back to empty is a valid change too.
        function updateResetBtnState() {
            var v = $input.val();
            $resetBtn.prop('disabled', running || v === originalDate);
        }

        // Toggle the panel (same behaviour as the refresh box).
        $sd.on('click', '.wcusage-startdate-toggle', function () {
            var willOpen = !$sd.hasClass('is-open');
            $sd.toggleClass('is-open', willOpen);
            $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
            $sd.find('.wcusage-startdate-panel').slideToggle(150);
        });

        // Keep the two date fields in sync, both directions.
        if ($couponField.length) {
            $couponField.on('change input', function () { $input.val($couponField.val()); updateResetBtnState(); });
            $input.on('change input', function () { $couponField.val($input.val()); });
        }
        $input.on('change input', updateResetBtnState);
        updateResetBtnState();

        // Reset: save the start date, then run the statistics refresh.
        $sd.on('click', '.wcusage-startdate-reset-btn', function () {
            if (running) { return; }

            var couponId = $sd.attr('data-coupon');
            var newDate = $input.val();

            // Only act when the date actually changed.
            if (newDate === originalDate) { return; }

            if (!window.confirm(i18n.startdate_confirm || 'Save this start date and recalculate the statistics now?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);
            if ($couponField.length) { $couponField.val(newDate); }

            $.post(cfg.ajax_url, {
                action: 'wcusage_admin_save_coupon_start_date',
                security: cfg.nonce_refresh,
                user_id: USER_ID,
                coupon_id: couponId,
                start_date: newDate
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    updateResetBtnState();
                    window.alert(extractError(resp) || i18n.startdate_error || 'Could not save the start date.');
                    return;
                }
                // The saved value is now the new baseline.
                originalDate = newDate;
                updateResetBtnState();
                // Update the "currently set to" message above the button.
                var display = (resp.data && resp.data.start_date_display) ? resp.data.start_date_display : '';
                if (display) {
                    $sd.find('.wcusage-startdate-current-value').text(display);
                    $sd.find('.wcusage-startdate-current').show();
                } else {
                    $sd.find('.wcusage-startdate-current').hide();
                }
                // Collapse this box, then rebuild the stats via the refresh flow.
                if ($sd.hasClass('is-open')) {
                    $sd.find('.wcusage-startdate-toggle').trigger('click');
                }
                startRefresh([couponId]);
            }).fail(function (jqXHR) {
                updateResetBtnState();
                window.alert(extractError(jqXHR) || i18n.startdate_error || 'Could not save the start date.');
            });
        });
    })();
});
