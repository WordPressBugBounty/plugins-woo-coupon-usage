jQuery(document).ready(function($){
    // Checkbox enable/disable based on selected bulk action
    function updateCheckboxStates(){
        var action = $('#bulk-action-selector-top').val();
        var action2 = $('#bulk-action-selector-bottom').val();
        var selectedAction = action !== '-1' ? action : action2;
        if (selectedAction === 'update_unpaid_commission') {
            $('input[name="bulk-delete[]"]').each(function(){
                var can = $(this).data('can-update-commission');
                if (can === 1 || can === '1') {
                    $(this).prop('disabled', false).parent().removeClass('checkbox-disabled');
                } else {
                    $(this).prop('disabled', true).prop('checked', false).parent().addClass('checkbox-disabled');
                }
            });
        } else {
            $('input[name="bulk-delete[]"]').prop('disabled', false).parent().removeClass('checkbox-disabled');
        }
    }
    $(document).on('change', '#bulk-action-selector-top, #bulk-action-selector-bottom', updateCheckboxStates);
    updateCheckboxStates();

    // Export Orders dropdown: toggle the panel with the customise-export fields.
    var $exportWrap = $('#wcu-export-dropdown');
    if ($exportWrap.length) {
        var $exportToggle = $('#wcu-admin-export-csv');
        var $exportPanel  = $('#wcu-export-panel');

        function closeExportPanel(){
            $exportPanel.prop('hidden', true);
            $exportToggle.attr('aria-expanded', 'false');
            $exportWrap.removeClass('is-open');
        }

        $exportToggle.on('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            if (!$exportPanel.prop('hidden')) {
                closeExportPanel();
            } else {
                $exportPanel.prop('hidden', false);
                $exportToggle.attr('aria-expanded', 'true');
                $exportWrap.addClass('is-open');
            }
        });

        // Keep clicks inside the panel from closing it.
        $exportPanel.on('click', function(e){ e.stopPropagation(); });

        // Close on outside click or Escape.
        $(document).on('click', function(){ closeExportPanel(); });
        $(document).on('keydown', function(e){
            if (e.key === 'Escape' || e.keyCode === 27) { closeExportPanel(); }
        });
    }

    // Guard for localization
    var ajaxUrl = (typeof wcusage_referrals_vars !== 'undefined' && wcusage_referrals_vars.ajax_url) ? wcusage_referrals_vars.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce  = (typeof wcusage_referrals_vars !== 'undefined' && wcusage_referrals_vars.nonce) ? wcusage_referrals_vars.nonce : '';
    var texts  = (typeof wcusage_referrals_vars !== 'undefined' && wcusage_referrals_vars.texts) ? wcusage_referrals_vars.texts : {};

    // Confirm on submit for bulk actions (trash, status changes, unpaid commission)
    $(document).on('submit', '#referrals-table', function(e){
        var action = $('#bulk-action-selector-top').val();
        var action2 = $('#bulk-action-selector-bottom').val();
        var selectedAction = action !== '-1' ? action : action2;
        var statusActions = ['processing', 'on-hold', 'completed', 'cancelled'];
        if (selectedAction !== 'trash' && selectedAction !== 'update_unpaid_commission' && statusActions.indexOf(selectedAction) === -1) {
            return;
        }

        var checkedBoxes = $('input[name="bulk-delete[]"]:checked');
        if (checkedBoxes.length === 0) {
            alert(texts.please_select_at_least_one_order || 'Please select at least one order.');
            e.preventDefault();
            return false;
        }

        var msg = '';
        if (selectedAction === 'update_unpaid_commission') {
            msg = (texts.update_unpaid_confirm_header || 'Update unpaid commission for the selected orders?');
            if (texts.update_unpaid_confirm_line) {
                msg += '\n\n' + texts.update_unpaid_confirm_line;
            }
        } else if (selectedAction === 'trash') {
            msg = (texts.trash_confirm || 'Are you sure you want to move the selected WooCommerce orders to the trash?');
        } else {
            var $selector = action !== '-1' ? $('#bulk-action-selector-top') : $('#bulk-action-selector-bottom');
            var actionLabel = $selector.find('option:selected').text();
            msg = (texts.status_confirm_template || 'Are you sure you want to apply "%s" to the selected orders?').replace('%s', actionLabel);
            if (texts.status_confirm_line) {
                msg += '\n\n' + texts.status_confirm_line;
            }
        }
        if (texts.selected_orders) {
            msg += '\n\n' + texts.selected_orders + ' ' + checkedBoxes.length;
        }
        if (!window.confirm(msg)) {
            e.preventDefault();
            return false;
        }
    });

    // Move the "only assigned affiliates" toggle beside the top bulk actions "Apply" button
    (function moveOnlyAssignedToggle(){
        var $wrap  = $('.wcusage-only-assigned-actions');
        var $apply = $('.tablenav.top .bulkactions input#doaction');
        if ($wrap.length && $apply.length) {
            $apply.after($wrap.contents()); // keeps the hidden marker + checkbox inside the form
            $wrap.remove();
        }
    })();

    // Auto-submit the filter form when the "only assigned affiliates" checkbox is toggled
    $(document).on('change', '#wcu_only_assigned', function(){
        // Reflect the new state on the toggle immediately (before the page reloads)
        $(this).closest('.wcusage-only-assigned-filter').toggleClass('is-active', this.checked);
        var $form = $(this).closest('form');
        if ($form.length) { $form.trigger('submit'); }
    });

    // Lightweight username autocomplete for Affiliate User filter on this page
    (function initUserAutocomplete(){
        var $input = $('.wcu-autocomplete-user');
        if(!$input.length) return;
        if (!$.ui || !$.ui.autocomplete || !ajaxUrl) return;
        var labelPref = $input.data('label') || '';
        $input.autocomplete({
            source: function(request, response){
                $.post(ajaxUrl, {
                    action: 'wcusage_search_users',
                    nonce: nonce,
                    search: request.term,
                    label: labelPref
                }).done(function(res){
                    if(res && res.success && res.data){ response(res.data); } else { response([]); }
                }).fail(function(){ response([]); });
            },
            minLength: 2,
            select: function(e, ui){ $(this).val(ui.item.value); return false; }
        }).autocomplete('instance')._renderItem = function(ul, item){
            return $('<li>').append('<div>'+ item.label +'</div>').appendTo(ul);
        };
    })();

    // Handle Enter key on filter inputs to trigger filter instead of bulk action
    $(document).on('keypress', '.wcusage-admin-title-filters input[name="affiliate_user"], .wcusage-admin-title-filters input[name="coupon_code"], .wcusage-admin-title-filters input[name="date_from"], .wcusage-admin-title-filters input[name="date_to"]', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            // Find and click the filter button
            $(this).closest('.wcusage-admin-title-filters').find('button[type="submit"]').trigger('click');
            return false;
        }
    });

    // Handle Enter key on filter dropdowns to trigger filter instead of bulk action
    $(document).on('keypress', '.wcusage-admin-title-filters select[name="affiliate_group"], .wcusage-admin-title-filters select[name="order_status"]', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            // Find and click the filter button
            $(this).closest('.wcusage-admin-title-filters').find('button[type="submit"]').trigger('click');
            return false;
        }
    });
});
