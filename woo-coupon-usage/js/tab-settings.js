jQuery(document).ready(function($) {
    // Tab switching
    const tabs = $('.wcu-settings-tab-nav a');
    const panes = $('.wcu-settings-tab-pane');
    
    var current_payout_type = $('#wcu-payout-type').val();

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
        $('.wcu-payout-type-custom1, .wcu-payout-type-custom2, .wcu-payout-type-banktransfer, .wcu-payout-type-paypalapi, .wcu-payout-type-wisebank, .wcu-payout-type-stripeapi, .wcu-payout-type-credit').hide();
        
        if(currentpayout === "custom1") $('.wcu-payout-type-custom1').show();
        if(currentpayout === "custom2") $('.wcu-payout-type-custom2').show();
        if(currentpayout === "banktransfer") $('.wcu-payout-type-banktransfer').show();
        if(currentpayout === "paypalapi") $('.wcu-payout-type-paypalapi').show();
        if(currentpayout === "wiseapi") $('.wcu-payout-type-wiseapi').show();
        if(currentpayout === "wisebank") {
            $('.wcu-payout-type-wisebank').show();
            wcusage_check_wisebank_region();
        }
        if(currentpayout === "stripeapi") $('.wcu-payout-type-stripeapi').show();
        if(currentpayout === "credit") $('.wcu-payout-type-credit').show();
    }

    // Wise Bank Region Field Toggle
    function wcusage_check_wisebank_region() {
        var selectedRegion = $('#wcu-wisebank-region').val();
        
        // Hide all region-specific fields first
        $('.wcu-wisebank-region-fields').hide();
        
        // Clear the required attribute from all fields first
        $('.wcu-wisebank-region-fields input, .wcu-wisebank-region-fields select').removeAttr('required');
        
        // Show the appropriate fields based on selection
        if(selectedRegion === 'us') {
            $('.wcu-wisebank-us').show();
            $('#wcu-wisebank-account-number-us, #wcu-wisebank-routing-number, #wcu-wisebank-account-type').attr('required', 'required');
            $('#wcu-wisebank-country').val('US');
            // Show state field for US
            $('.wcu-wisebank-state-field').show();
            $('#wcu-wisebank-state').attr('required', 'required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'uk') {
            $('.wcu-wisebank-uk').show();
            $('#wcu-wisebank-account-number-uk, #wcu-wisebank-sort-code').attr('required', 'required');
            $('#wcu-wisebank-country').val('GB');
            // Hide state field for non-US regions
            $('.wcu-wisebank-state-field').hide();
            $('#wcu-wisebank-state').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'eu') {
            $('.wcu-wisebank-eu').show();
            $('#wcu-wisebank-iban').attr('required', 'required');
            $('#wcu-wisebank-country').val('DE');
            // Hide state field for non-US regions
            $('.wcu-wisebank-state-field').hide();
            $('#wcu-wisebank-state').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'international') {
            $('.wcu-wisebank-international').show();
            $('#wcu-wisebank-account-number-intl, #wcu-wisebank-swift-code, #wcu-wisebank-bank-name').attr('required', 'required');
            $('#wcu-wisebank-country').val('');
            // Hide state field for non-US regions
            $('.wcu-wisebank-state-field').hide();
            $('#wcu-wisebank-state').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else {
            // Hide state field when no region selected
            $('.wcu-wisebank-state-field').hide();
            $('#wcu-wisebank-state').removeAttr('required');
        }
        
        // Debug logging
        console.log('Wise Bank Region changed to:', selectedRegion);
        console.log('Country field value:', $('#wcu-wisebank-country').val());
        console.log('Recipient Country field value:', $('#wcu-wisebank-recipient-country').val());
        console.log('State field visibility:', $('.wcu-wisebank-state-field').is(':visible'));
        
        // Log all visible required fields for US
        if (selectedRegion === 'us') {
            console.log('US Region - Required fields:');
            $('.wcu-wisebank-region-fields:visible input[required], .wcu-wisebank-region-fields:visible select[required]').each(function() {
                console.log('  - ' + $(this).attr('name') + ': ' + $(this).val());
            });
        }
    }
    
    // Initialize on page load
    wcusage_check_wisebank_region();
    
    // Bind to region change event
    $('#wcu-wisebank-region').on('change', wcusage_check_wisebank_region);

    // Initialize region fields on page load
    $(document).ready(function() {
        wcusage_check_payout_type();
        wcusage_check_wisebank_region();
    });

    // Helper function to get account number based on selected region
    function getWiseBankAccountNumber() {
        var selectedRegion = $('#wcu-wisebank-region').val();
        var accountNumber = '';
        
        if (selectedRegion === 'us') {
            accountNumber = $('#wcu-wisebank-account-number-us').val() || '';
        } else if (selectedRegion === 'uk') {
            accountNumber = $('#wcu-wisebank-account-number-uk').val() || '';
        } else if (selectedRegion === 'eu') {
            // EU uses IBAN instead of account number
            accountNumber = '';
        } else if (selectedRegion === 'international') {
            accountNumber = $('#wcu-wisebank-account-number-intl').val() || '';
        }
        
        // Debug logging
        console.log('Getting account number for region:', selectedRegion, 'value:', accountNumber);
        
        return accountNumber;
    }

    // AJAX Form Submission
    $('#wcusage-settings-form').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Get the correct account number based on visible region
        var wiseBankAccountNumber = getWiseBankAccountNumber();
        
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
            wiseemailapi: $('#wcu-wiseapi-input').val() || '',
            wisebank_region: $('#wcu-wisebank-region').val() || '',
            wisebank_account_name: $('#wcu-wisebank-account-name').val() || '',
            wisebank_account_number: wiseBankAccountNumber,
            wisebank_routing_number: $('#wcu-wisebank-routing-number').val() || '',
            wisebank_swift_code: $('#wcu-wisebank-swift-code').val() || '',
            wisebank_iban: $('#wcu-wisebank-iban').val() || '',
            wisebank_sort_code: $('#wcu-wisebank-sort-code').val() || '',
            wisebank_bank_name: $('#wcu-wisebank-bank-name').val() || '',
            wisebank_bank_address: $('#wcu-wisebank-bank-address').val() || '',
            wisebank_country: $('#wcu-wisebank-country').val() || '',
            wisebank_address: $('#wcu-wisebank-address').val() || '',
            wisebank_city: $('#wcu-wisebank-city').val() || '',
            wisebank_postcode: $('#wcu-wisebank-postcode').val() || '',
            wisebank_state: $('#wcu-wisebank-state').val() || '',
            wisebank_recipient_country: $('#wcu-wisebank-recipient-country').val() || '',
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

        // Add region-specific account number fields for debugging/backup
        var selectedRegion = $('#wcu-wisebank-region').val();
        if (selectedRegion === 'us') {
            formData.wisebank_account_number_us = $('#wcu-wisebank-account-number-us').val() || '';
        } else if (selectedRegion === 'uk') {
            formData.wisebank_account_number_uk = $('#wcu-wisebank-account-number-uk').val() || '';
        } else if (selectedRegion === 'international') {
            formData.wisebank_account_number_intl = $('#wcu-wisebank-account-number-intl').val() || '';
        }

        // Debug logging for Wise Bank fields
        console.log('Wise Bank Form Data:', {
            region: formData.wisebank_region,
            account_name: formData.wisebank_account_name,
            account_number: formData.wisebank_account_number,
            routing_number: formData.wisebank_routing_number,
            swift_code: formData.wisebank_swift_code,
            iban: formData.wisebank_iban,
            sort_code: formData.wisebank_sort_code,
            bank_name: formData.wisebank_bank_name,
            bank_address: formData.wisebank_bank_address,
            country: formData.wisebank_country,
            address: formData.wisebank_address,
            city: formData.wisebank_city,
            postcode: formData.wisebank_postcode,
            recipient_country: formData.wisebank_recipient_country
        });

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

                    // Hide .wcu-bank-details-display if exists
                    if( $('.wcu-bank-details-display').length > 0) {
                        $('.wcu-bank-details-display').hide();
                    }
                    
                    if(response.data.updated_payout_fields.payouttype) {
                        $('#wcu-payout-type')
                            .val(response.data.updated_payout_fields.payouttype)
                            .trigger('change');
                        // Reload page if payout type is different to old
                        if (current_payout_type !== response.data.updated_payout_fields.payouttype) {
                            location.reload();
                        }
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