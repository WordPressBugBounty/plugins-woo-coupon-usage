jQuery(document).ready(function($) {
    // Tab switching
    const tabs = $('.wcu-settings-tab-nav a');
    const panes = $('.wcu-settings-tab-pane');

    tabs.on('click', function(e) {
        e.preventDefault();
        tabs.parent().removeClass('active');
        panes.removeClass('active');
        $(this).parent().addClass('active');
        $($(this).attr('href')).addClass('active');
    });

    // Payout type checker
    wcusage_check_payout_type();
    $('#wcu-payout-type').on('change', function() {
        wcusage_check_payout_type();
    });

    function wcusage_check_payout_type() {
        var currentpayout = $('#wcu-payout-type').val();
        $('.wcu-payout-type-custom1, .wcu-payout-type-custom2, .wcu-payout-type-banktransfer, .wcu-payout-type-paypalapi, .wcu-payout-type-stripeapi, .wcu-payout-type-credit').hide();
        
        if(currentpayout === "custom1") $('.wcu-payout-type-custom1').show();
        if(currentpayout === "custom2") $('.wcu-payout-type-custom2').show();
        if(currentpayout === "banktransfer") $('.wcu-payout-type-banktransfer').show();
        if(currentpayout === "paypalapi") $('.wcu-payout-type-paypalapi').show();
        if(currentpayout === "stripeapi") $('.wcu-payout-type-stripeapi').show();
        if(currentpayout === "credit") $('.wcu-payout-type-credit').show();
    }

    // AJAX Form Submission
    $('#wcusage-settings-form').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $form = $(this);
        var formData = {
            action: 'wcusage_update_settings',
            nonce: $('#wcusage_settings_nonce').val(),
            post_id: $form.data('post-id'),
            wcu_enable_notifications: $('#wcu_enable_notifications').is(':checked') ? '1' : '0',
            wcu_enable_reports: $('#wcu_enable_reports').is(':checked') ? '1' : '0',
            wcu_notifications_extra: $('#wcu_notifications_extra').val() || '',
            payouttype: $('#wcu-payout-type').val() || '',
            paypalemail: $('#wcu-paypal-input').val() || '',
            paypalemail2: $('#wcu-paypal-input2').val() || '',
            bankname: $('#wcu-bank-input1').val() || '',
            banksort: $('#wcu-bank-input2').val() || '',
            bankaccount: $('#wcu-bank-input3').val() || '',
            bankother: $('#wcu-bank-input4').val() || '',
            bankother2: $('#wcu-bank-input5').val() || '',
            bankother3: $('#wcu-bank-input6').val() || '',
            bankother4: $('#wcu-bank-input7').val() || '',
            paypalemailapi: $('#wcu-paypalapi-input').val() || '',
            'wcu-company': $('#wcu-company').val() || '',
            'wcu-billing1': $('#wcu-billing1').val() || '',
            'wcu-billing2': $('#wcu-billing2').val() || '',
            'wcu-billing3': $('#wcu-billing3').val() || '',
            'wcu-taxid': $('#wcu-taxid').val() || '',
            wcu_first_name: $('#wcu_first_name').val() || '',
            wcu_last_name: $('#wcu_last_name').val() || '',
            wcu_display_name: $('#wcu_display_name').val() || '',
            wcu_email: $('#wcu_email').val() || '',
            wcu_phone: $('#wcu_phone').val() || '',
            wcu_website: $('#wcu_website').val() || ''
        };

        $.ajax({
            url: wcusage_ajax.ajax_url,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                $('#wcu-settings-update-button')
                    .prop('disabled', true)
                    .text(wcusage_ajax.saving_text);
                $('#wcu-settings-ajax-message').empty();
            },
            success: function(response) {
                if (response.success) {
                    $('#wcu-settings-ajax-message').html(
                        '<p style="color: green;">' + response.data.message + '</p>'
                    ).fadeIn().delay(4000).fadeOut();
                    
                    if(response.data.updated_payout_fields.payouttype) {
                        $('#wcu-payout-type')
                            .val(response.data.updated_payout_fields.payouttype)
                            .trigger('change');
                    }
                    
                    $('#wcu-settings-update-button')
                        .prop('disabled', false)
                        .text(wcusage_ajax.save_text);
                    $("#tab-page-settings").trigger('click');
                } else {
                    $('#wcu-settings-ajax-message').html(
                        '<p style="color: red;">Error: ' + (response.data || 'Unknown error') + '</p>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('#wcu-settings-ajax-message').html(
                    '<p style="color: red;">AJAX Error: ' + error + '</p>'
                );
            },
            complete: function() {
                $('#wcu-settings-update-button')
                    .prop('disabled', false)
                    .text(wcusage_ajax.save_text);
            }
        });

        return false;
    });
});