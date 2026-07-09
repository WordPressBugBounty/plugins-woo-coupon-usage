/**
 * Create Payout Requests page: inline "Configure payout method" panel.
 *
 * Lets an admin set an affiliate's payout method (from the role-filtered list of
 * enabled methods) without leaving the page. On save:
 *  - if the row is ready to pay, the panel is replaced with the Create Payout form;
 *  - if the chosen method still needs payout details the affiliate hasn't entered,
 *    a status message is shown instead.
 */
(function ($) {
    'use strict';

    var vars = window.wcusage_payoutcreate_vars || {};
    var i18n = vars.i18n || {};

    // Toggle the configure panel open/closed.
    $(document).on('click', '.wcu-cp-toggle', function () {
        $(this).closest('.wcu-cp-wrap').find('.wcu-cp-panel').slideToggle(120);
    });

    // Save the selected payout method.
    $(document).on('click', '.wcu-cp-save', function () {
        var $btn = $(this);
        var $wrap = $btn.closest('.wcu-cp-wrap');
        var $status = $wrap.find('.wcu-cp-status');
        var method = $wrap.find('.wcu-cp-method').val();

        if (!method) {
            $status.css('color', '#771919').text(i18n.selectMethod || 'Please select a payout method.');
            return;
        }

        $btn.prop('disabled', true);
        $status.css('color', '').text(i18n.saving || 'Saving...');

        $.post(vars.ajaxUrl, {
            action: 'wcusage_admin_set_payout_method',
            nonce: vars.nonce,
            user_id: $wrap.data('user'),
            coupon_id: $wrap.data('coupon'),
            method: method
        }).done(function (response) {
            if (response && response.success && response.data) {
                if (response.data.ready && response.data.html) {
                    // Row is ready to pay: swap in the Create Payout form.
                    $wrap.replaceWith(response.data.html);
                } else {
                    // Saved, but the method still needs payout details.
                    $status.css('color', '#8a6d00').html(response.data.message || '');
                    $btn.prop('disabled', false);
                }
            } else {
                $status.css('color', '#771919').text((response && response.data && response.data.message) || i18n.error || 'Error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $status.css('color', '#771919').text(i18n.error || 'Error');
            $btn.prop('disabled', false);
        });
    });

    // ---- Assign a user (coupon with no affiliate user assigned) ----

    // Toggle the assign panel.
    $(document).on('click', '.wcu-au-toggle', function () {
        $(this).closest('.wcu-au-wrap').find('.wcu-au-panel').slideToggle(120);
    });

    // Debounced user search.
    var auSearchTimer = null;
    $(document).on('input', '.wcu-au-search', function () {
        var $input = $(this);
        var $wrap = $input.closest('.wcu-au-wrap');
        var $results = $wrap.find('.wcu-au-results');
        // Typing invalidates any previously picked user.
        $wrap.find('.wcu-au-user-id').val('');
        var term = $input.val();

        clearTimeout(auSearchTimer);
        if (term.length < 2) {
            $results.hide().empty();
            return;
        }
        auSearchTimer = setTimeout(function () {
            $.post(vars.ajaxUrl, {
                action: 'wcusage_search_usernames',
                nonce: vars.searchNonce,
                term: term
            }).done(function (response) {
                $results.empty();
                if (response && response.success && response.data && response.data.results && response.data.results.length) {
                    $.each(response.data.results, function (i, u) {
                        $('<div class="wcu-au-result"></div>')
                            .attr('data-id', u.id)
                            .attr('data-login', u.login)
                            .text(u.login + ' (' + u.email + ')')
                            .appendTo($results);
                    });
                } else {
                    $('<div class="wcu-au-noresult"></div>').text(i18n.noResults || 'No users found.').appendTo($results);
                }
                $results.show();
            }).fail(function () {
                $results.hide().empty();
            });
        }, 250);
    });

    // Pick a search result.
    $(document).on('click', '.wcu-au-result', function () {
        var $result = $(this);
        var $wrap = $result.closest('.wcu-au-wrap');
        $wrap.find('.wcu-au-user-id').val($result.attr('data-id'));
        $wrap.find('.wcu-au-search').val($result.attr('data-login'));
        $wrap.find('.wcu-au-results').hide().empty();
    });

    // Hide results when clicking outside the search box.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wcu-au-search-wrap').length) {
            $('.wcu-au-results').hide();
        }
    });

    // Assign the selected user, then swap in the freshly-rendered cell.
    $(document).on('click', '.wcu-au-save', function () {
        var $btn = $(this);
        var $wrap = $btn.closest('.wcu-au-wrap');
        var $status = $wrap.find('.wcu-au-status');
        var userId = $wrap.find('.wcu-au-user-id').val();

        if (!userId) {
            $status.css('color', '#771919').text(i18n.selectUser || 'Please select a user.');
            return;
        }

        $btn.prop('disabled', true);
        $status.css('color', '').text(i18n.saving || 'Saving...');

        $.post(vars.ajaxUrl, {
            action: 'wcusage_admin_assign_coupon_user',
            nonce: vars.assignNonce,
            coupon_id: $wrap.data('coupon'),
            user_id: userId
        }).done(function (response) {
            if (response && response.success && response.data && typeof response.data.html !== 'undefined') {
                $wrap.replaceWith(response.data.html);
            } else {
                $status.css('color', '#771919').text((response && response.data && response.data.message) || i18n.error || 'Error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $status.css('color', '#771919').text(i18n.error || 'Error');
            $btn.prop('disabled', false);
        });
    });

    // ---- Refresh / reload a cell (re-check details, threshold, etc.) ----
    $(document).on('click', '.wcu-rc-refresh', function () {
        var $btn = $(this);
        var $cell = $btn.closest('td');
        var couponId = $btn.data('coupon');
        var mlaUser = $btn.data('mla-user');

        var data = { action: 'wcusage_admin_reload_payoutcreate_cell', nonce: vars.reloadNonce };
        if (couponId) {
            data.coupon_id = couponId;
        } else if (mlaUser) {
            data.mla_user_id = mlaUser;
        } else {
            return;
        }

        $btn.prop('disabled', true).addClass('wcu-rc-spinning');

        $.post(vars.ajaxUrl, data).done(function (response) {
            if (response && response.success && response.data && typeof response.data.html !== 'undefined') {
                // Replace the whole cell with its freshly-rendered contents.
                $cell.html(response.data.html);
            } else {
                $btn.prop('disabled', false).removeClass('wcu-rc-spinning');
            }
        }).fail(function () {
            $btn.prop('disabled', false).removeClass('wcu-rc-spinning');
        });
    });

})(jQuery);
