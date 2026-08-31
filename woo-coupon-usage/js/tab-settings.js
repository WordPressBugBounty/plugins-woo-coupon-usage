jQuery(document).ready(function($) {
    // Scope to settings tab only (not payout tab)
    var $settingsForm = $('#wcusage-settings-form');
    var $payoutsForm = $('#wcu-settings-form');
    var current_payout_type = $settingsForm.find('[name="payouttype"]').val();

    // Each settings card has its own "Save changes" button. Track which one was
    // used so the saving state + confirmation message appear in that section.
    var $activeSaveBtn = null;
    $settingsForm.on('click', '.wcu-save-settings-button', function() {
        $activeSaveBtn = $(this);
    });

    // Tab switching (legacy layout only — these elements are absent in the modern layout,
    // so this binds to nothing and is a no-op when the modern boxed layout is active).
    var $tabs = $('.wcu-settings-tab-nav a');
    var $panes = $('.wcu-settings-tab-pane');
    $tabs.on('click', function(e) {
        e.preventDefault();
        $tabs.parent().removeClass('active');
        $panes.removeClass('active');
        $(this).parent().addClass('active');
        $($(this).attr('href')).addClass('active');
        // Scroll to top when switching tabs
        window.scrollTo({ top: 0, behavior: 'smooth' });
        $('html, body').animate({ scrollTop: 0 }, 300);
        var $activePane = $($(this).attr('href'));
        $activePane.parents().each(function() {
            var $parent = $(this);
            if ($parent.css('overflow-y') === 'auto' || $parent.css('overflow-y') === 'scroll') {
                $parent.animate({ scrollTop: 0 }, 300);
            }
        });
    });

    // Payout type checker - uses addClass/removeClass to work with CSS !important rules
    wcusage_check_payout_type();
    $settingsForm.find('[name="payouttype"]').on('change', function() {
        var selectedValue = $(this).val();
        
        // Sync the value to the payouts tab dropdown (if it exists)
        $('#wcu-settings-form [name="payouttype"]').val(selectedValue);
        
        wcusage_check_payout_type();
        
        // Also toggle sections inside the payouts tab form
        if(typeof window.wcusage_payouts_tab_toggle === 'function') {
            window.wcusage_payouts_tab_toggle(selectedValue);
        }
    });

    function wcusage_check_payout_type() {
        var currentpayout = $settingsForm.find('[name="payouttype"]').val();
        
        // Hide all payout type sections within settings form only
        var $sections = $settingsForm.find('.wcu-payout-type-custom1, .wcu-payout-type-custom2, .wcu-payout-type-banktransfer, .wcu-payout-type-paypalapi, .wcu-payout-type-wisebank, .wcu-payout-type-stripeapi, .wcu-payout-type-credit');
        $sections.removeClass('wcu-show');
        
        // Remove required from Wise Bank fields within settings form when not selected
        var wiseStaticRequired = $settingsForm.find('[name="wisebank_region"], [name="wisebank_account_name"], [name="wisebank_address"], [name="wisebank_city"], [name="wisebank_postcode"], [name="wisebank_recipient_country"]');
        var wiseDynamicFields = $settingsForm.find('.wcu-wisebank-region-fields input, .wcu-wisebank-region-fields select');
        var wiseState = $settingsForm.find('[name="wisebank_state"]');

        if(currentpayout !== "wisebank") {
            wiseStaticRequired.removeAttr('required');
            wiseDynamicFields.removeAttr('required');
            wiseState.removeAttr('required');
        }

        // Show the selected payout type section within settings form using wcu-show class
        if(currentpayout === "custom1") $settingsForm.find('.wcu-payout-type-custom1').addClass('wcu-show');
        if(currentpayout === "custom2") $settingsForm.find('.wcu-payout-type-custom2').addClass('wcu-show');
        if(currentpayout === "banktransfer") $settingsForm.find('.wcu-payout-type-banktransfer').addClass('wcu-show');
        if(currentpayout === "paypalapi") $settingsForm.find('.wcu-payout-type-paypalapi').addClass('wcu-show');
        if(currentpayout === "wiseapi") $settingsForm.find('.wcu-payout-type-wiseapi').addClass('wcu-show');
        if(currentpayout === "wisebank") {
            $settingsForm.find('.wcu-payout-type-wisebank').addClass('wcu-show');
            // Restore required
            wiseStaticRequired.attr('required', 'required');
            wcusage_check_wisebank_region();
        }
        if(currentpayout === "stripeapi") $settingsForm.find('.wcu-payout-type-stripeapi').addClass('wcu-show');
        if(currentpayout === "credit") $settingsForm.find('.wcu-payout-type-credit').addClass('wcu-show');
    }

    // Wise Bank Region Field Toggle
    function wcusage_check_wisebank_region() {
        var selectedRegion = $settingsForm.find('[name="wisebank_region"]').val();
        
        // Hide all region-specific fields first
        $settingsForm.find('.wcu-wisebank-region-fields').hide();
        
        // Clear the required attribute from all fields first
        $settingsForm.find('.wcu-wisebank-region-fields input, .wcu-wisebank-region-fields select').removeAttr('required');
        
        // Show the appropriate fields based on selection
        if(selectedRegion === 'us') {
            $settingsForm.find('.wcu-wisebank-us').show();
            $settingsForm.find('[name="wisebank_account_number_us"], [name="wisebank_routing_number"], [name="wisebank_account_type"]').attr('required', 'required');
            $settingsForm.find('[name="wisebank_country"]').val('US');
            // Show state field for US
            $settingsForm.find('.wcu-wisebank-state-field').show();
            $settingsForm.find('[name="wisebank_state"]').attr('required', 'required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'uk') {
            $settingsForm.find('.wcu-wisebank-uk').show();
            $settingsForm.find('[name="wisebank_account_number_uk"], [name="wisebank_sort_code"]').attr('required', 'required');
            $settingsForm.find('[name="wisebank_country"]').val('GB');
            // Hide state field for non-US regions
            $settingsForm.find('.wcu-wisebank-state-field').hide();
            $settingsForm.find('[name="wisebank_state"]').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'eu') {
            $settingsForm.find('.wcu-wisebank-eu').show();
            $settingsForm.find('[name="wisebank_iban"]').attr('required', 'required');
            $settingsForm.find('[name="wisebank_country"]').val('DE');
            // Hide state field for non-US regions
            $settingsForm.find('.wcu-wisebank-state-field').hide();
            $settingsForm.find('[name="wisebank_state"]').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else if(selectedRegion === 'international') {
            $settingsForm.find('.wcu-wisebank-international').show();
            $settingsForm.find('[name="wisebank_account_number_intl"], [name="wisebank_swift_code"], [name="wisebank_bank_name"]').attr('required', 'required');
            $settingsForm.find('[name="wisebank_country"]').val('');
            // Hide state field for non-US regions
            $settingsForm.find('.wcu-wisebank-state-field').hide();
            $settingsForm.find('[name="wisebank_state"]').removeAttr('required');
            // Don't auto-set recipient country - let user choose their actual country
        } else {
            // Hide state field when no region selected
            $settingsForm.find('.wcu-wisebank-state-field').hide();
            $settingsForm.find('[name="wisebank_state"]').removeAttr('required');
        }
        
    }
    
    // Initialize on page load
    wcusage_check_wisebank_region();
    
    // Bind to region change event - scoped to settings form
    $settingsForm.find('[name="wisebank_region"]').on('change', wcusage_check_wisebank_region);

    // Initialize region fields on page load
    $(document).ready(function() {
        wcusage_check_payout_type();
        wcusage_check_wisebank_region();
    });

    // Helper function to get account number based on selected region
    function getWiseBankAccountNumber() {
        var selectedRegion = $settingsForm.find('[name="wisebank_region"]').val();
        var accountNumber = '';
        
        if (selectedRegion === 'us') {
            accountNumber = $settingsForm.find('[name="wisebank_account_number_us"]').val() || '';
        } else if (selectedRegion === 'uk') {
            accountNumber = $settingsForm.find('[name="wisebank_account_number_uk"]').val() || '';
        } else if (selectedRegion === 'eu') {
            // EU uses IBAN instead of account number
            accountNumber = '';
        } else if (selectedRegion === 'international') {
            accountNumber = $settingsForm.find('[name="wisebank_account_number_intl"]').val() || '';
        }
        
        return accountNumber;
    }

    // AJAX Form Submission
    $settingsForm.on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Get the correct account number based on visible region
        var wiseBankAccountNumber = getWiseBankAccountNumber();
        
        var $form = $(this);
        var formData = {
            action: 'wcusage_update_settings',
            nonce: $settingsForm.find('[name="wcusage_settings_nonce"]').val(),
            post_id: $form.data('post-id'),
            wcu_enable_notifications: $settingsForm.find('#wcu_enable_notifications').is(':checked') ? '1' : '0',
            // Newsletter subscription (user meta). If checkbox exists include explicit value so server can detect.
            wcu_newsletter_subscribed: $settingsForm.find('#wcu_newsletter_subscribed').length ? ($settingsForm.find('#wcu_newsletter_subscribed').is(':checked') ? '1' : '0') : '1',
            wcu_enable_reports: $settingsForm.find('#wcu_enable_reports').is(':checked') ? '1' : '0',
            wcu_notifications_extra: $settingsForm.find('#wcu_notifications_extra').val() || '',
            payouttype: $settingsForm.find('[name="payouttype"]').val() || '',
            paypalemail: $settingsForm.find('[name="paypalemail"]').val() || '',
            paypalemail2: $settingsForm.find('[name="paypalemail2"]').val() || '',
            bankname: $settingsForm.find('[name="bankname"]').val() || '',
            banksort: $settingsForm.find('[name="banksort"]').val() || '',
            bankaccount: $settingsForm.find('[name="bankaccount"]').val() || '',
            bankother: $settingsForm.find('[name="bankother"]').val() || '',
            bankother2: $settingsForm.find('[name="bankother2"]').val() || '',
            bankother3: $settingsForm.find('[name="bankother3"]').val() || '',
            bankother4: $settingsForm.find('[name="bankother4"]').val() || '',
            paypalemailapi: $settingsForm.find('[name="paypalemailapi"]').val() || '',
            wiseemailapi: $settingsForm.find('[name="wiseemailapi"]').val() || '',
            wisebank_region: $settingsForm.find('[name="wisebank_region"]').val() || '',
            wisebank_account_name: $settingsForm.find('[name="wisebank_account_name"]').val() || '',
            wisebank_account_number: wiseBankAccountNumber,
            wisebank_routing_number: $settingsForm.find('[name="wisebank_routing_number"]').val() || '',
            wisebank_swift_code: $settingsForm.find('[name="wisebank_swift_code"]').val() || '',
            wisebank_iban: $settingsForm.find('[name="wisebank_iban"]').val() || '',
            wisebank_sort_code: $settingsForm.find('[name="wisebank_sort_code"]').val() || '',
            wisebank_bank_name: $settingsForm.find('[name="wisebank_bank_name"]').val() || '',
            wisebank_bank_address: $settingsForm.find('[name="wisebank_bank_address"]').val() || '',
            wisebank_country: $settingsForm.find('[name="wisebank_country"]').val() || '',
            wisebank_address: $settingsForm.find('[name="wisebank_address"]').val() || '',
            wisebank_city: $settingsForm.find('[name="wisebank_city"]').val() || '',
            wisebank_postcode: $settingsForm.find('[name="wisebank_postcode"]').val() || '',
            wisebank_state: $settingsForm.find('[name="wisebank_state"]').val() || '',
            wisebank_recipient_country: $settingsForm.find('[name="wisebank_recipient_country"]').val() || '',
            wcusage_sms_phone: $settingsForm.find('#wcusage_sms_phone').val() || '',
            wcusage_sms_opted_out: $settingsForm.find('#wcusage_sms_opted_out').is(':checked') ? '1' : '0'
        };

        // Payout statement detail fields. The admin can relabel, hide, require or
        // add fields, so collect whatever was rendered rather than a fixed list.
        // Omitting a hidden field lets the server's isset() check preserve its value.
        $settingsForm.find('.wcu-statement-input').each(function () {
            var statementName = $(this).attr('name');
            if (statementName) { formData[statementName] = $(this).val() || ''; }
        });

        // Only include Account Details fields when that section is actually rendered.
        // When the "Account Details" settings tab is disabled these inputs are absent
        // from the DOM. Sending them as empty strings would overwrite the saved values
        // and trigger a false "Email is required." error when saving from another tab
        // (e.g. Payout Settings). Omitting them lets the server's isset() checks skip them.
        if ($settingsForm.find('#wcu_email').length) {
            formData.wcu_first_name   = $settingsForm.find('#wcu_first_name').val()   || '';
            formData.wcu_last_name    = $settingsForm.find('#wcu_last_name').val()    || '';
            formData.wcu_display_name = $settingsForm.find('#wcu_display_name').val() || '';
            formData.wcu_email        = $settingsForm.find('#wcu_email').val()        || '';
            formData.wcu_phone        = $settingsForm.find('#wcu_phone').val()        || '';
            formData.wcu_website      = $settingsForm.find('#wcu_website').val()      || '';
        }

        // Registration custom fields shown in Account Details (dynamic; names wcu_account_custom_N).
        // Handles text/textarea/date/select plus checkbox (Yes/No) and radio groups.
        $settingsForm.find('.wcu-account-custom-input').each(function () {
            var $f = $(this);
            var name = $f.attr('name');
            if (!name) { return; }
            if ($f.is(':checkbox')) {
                formData[name] = $f.is(':checked') ? ($f.val() || 'Yes') : 'No';
            } else if ($f.is(':radio')) {
                if (!(name in formData)) { formData[name] = ''; }
                if ($f.is(':checked')) { formData[name] = $f.val(); }
            } else {
                formData[name] = $f.val() || '';
            }
        });

        // Add region-specific account number fields for debugging/backup
        var selectedRegion = $settingsForm.find('[name="wisebank_region"]').val();
        if (selectedRegion === 'us') {
            formData.wisebank_account_number_us = $settingsForm.find('[name="wisebank_account_number_us"]').val() || '';
        } else if (selectedRegion === 'uk') {
            formData.wisebank_account_number_uk = $settingsForm.find('[name="wisebank_account_number_uk"]').val() || '';
        } else if (selectedRegion === 'international') {
            formData.wisebank_account_number_intl = $settingsForm.find('[name="wisebank_account_number_intl"]').val() || '';
        }

        // Payout statement details the admin marked as required. These do not use
        // the native "required" attribute, because in the legacy (tabbed) layout
        // the statement pane is display:none and the browser will not submit a form
        // holding an invalid control it cannot focus - which would block saving from
        // every other tab. The server checks these again.
        var missingField = null;
        $settingsForm.find('.wcu-statement-input[data-wcu-required="1"]').each(function() {
            if (!missingField && !$.trim($(this).val() || '')) { missingField = $(this); }
        });

        // Target the saved section's button + message (fall back to the first button
        // and its message, e.g. when submitting via the Enter key).
        var $clickedBtn = ($activeSaveBtn && $activeSaveBtn.length)
            ? $activeSaveBtn
            : $settingsForm.find('.wcu-save-settings-button').first();
        var $btnMsg = $clickedBtn.closest('.wcu-settings-card').find('.wcu-settings-card-msg').first();
        if (!$btnMsg.length) {
            $btnMsg = $settingsForm.find('.wcu-settings-card-msg').first();
        }
        if (!$btnMsg.length) {
            // Legacy (tabs) layout uses a single shared message area.
            $btnMsg = $settingsForm.find('#wcu-settings-ajax-message');
        }

        if (missingField) {
            var missingLabel = missingField.data('wcu-label') || missingField.attr('name');
            var requiredText = (wcusage_ajax.required_text || '%s is required.').replace('%s', missingLabel);
            $btnMsg.stop(true, true).empty()
                .append($('<span/>').css('color', 'red').text(requiredText))
                .show();
            missingField.trigger('focus');
            $activeSaveBtn = null;
            return false;
        }

        $.ajax({
            url: wcusage_ajax.ajax_url,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                $clickedBtn.prop('disabled', true).text(wcusage_ajax.saving_text);
                $btnMsg.stop(true, true).empty().show();
            },
            success: function(response) {
                if (response.success) {
                    $btnMsg.html(
                        '<span style="color: green;">' + response.data.message + '</span>'
                    ).fadeIn().delay(4000).fadeOut();

                    // Hide .wcu-bank-details-display if exists (within settings form only)
                    if( $settingsForm.find('.wcu-bank-details-display').length > 0) {
                        $settingsForm.find('.wcu-bank-details-display').hide();
                    }

                    if(response.data.updated_payout_fields.payouttype) {
                        $settingsForm.find('[name="payouttype"]')
                            .val(response.data.updated_payout_fields.payouttype)
                            .trigger('change');
                        // Reload page if payout type is different to old
                        if (current_payout_type !== response.data.updated_payout_fields.payouttype) {
                            location.reload();
                        }
                    }
                } else {
                    $btnMsg.stop(true, true).html(
                        '<span style="color: red;">Error: ' + (response.data || 'Unknown error') + '</span>'
                    ).show();
                }
            },
            error: function(xhr, status, error) {
                $btnMsg.stop(true, true).html(
                    '<span style="color: red;">AJAX Error: ' + error + '</span>'
                ).show();
            },
            complete: function() {
                $settingsForm.find('.wcu-save-settings-button')
                    .prop('disabled', false)
                    .text(wcusage_ajax.save_text);
                $activeSaveBtn = null;
            }
        });

        return false;
    });

    // =====================================================
    // Password reset — behaves like the WooCommerce "Lost password" form.
    // Confirms first, then emails a reset link to the logged-in user.
    // =====================================================
    $(document).on('click', '.wcu-reset-password-link', function(e) {
        e.preventDefault();
        var $link = $(this);
        if ($link.data('sending')) { return; }

        var confirmText = $link.data('confirm') || 'Are you sure you want to reset your password?';
        if (!window.confirm(confirmText)) { return; }

        var $msg = $link.closest('p, .wcu-settings-field').find('.wcu-reset-password-msg');

        $.ajax({
            url: wcusage_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wcusage_send_password_reset',
                nonce: $link.data('nonce')
            },
            beforeSend: function() {
                $link.data('sending', true).css({ 'pointer-events': 'none', 'opacity': 0.6 });
                $msg.css('color', '').text(wcusage_ajax.saving_text || 'Sending...');
            },
            success: function(response) {
                if (response && response.success) {
                    $msg.css('color', 'green').text(response.data.message);
                } else {
                    $msg.css('color', 'red').text((response && response.data) ? response.data : 'Error.');
                }
            },
            error: function(xhr, status, error) {
                $msg.css('color', 'red').text('Error: ' + error);
            },
            complete: function() {
                $link.data('sending', false).css({ 'pointer-events': '', 'opacity': '' });
            }
        });
    });

    // =====================================================
    // Payouts Tab Form (#wcu-settings-form) - Toggle & Save
    // =====================================================
    if ($payoutsForm.length) {
        var payouts_current_payout_type = $payoutsForm.find('[name="payouttype"]').val();

        // Payout type toggle for payouts tab
        function wcusage_payouts_check_payout_type() {
            var currentpayout = $payoutsForm.find('[name="payouttype"]').val();

            var $sections = $payoutsForm.find('.wcu-payout-type-custom1, .wcu-payout-type-custom2, .wcu-payout-type-banktransfer, .wcu-payout-type-paypalapi, .wcu-payout-type-wisebank, .wcu-payout-type-stripeapi, .wcu-payout-type-credit');
            $sections.removeClass('wcu-show');

            var wiseStaticRequired = $payoutsForm.find('[name="wisebank_region"], [name="wisebank_account_name"], [name="wisebank_address"], [name="wisebank_city"], [name="wisebank_postcode"], [name="wisebank_recipient_country"]');
            var wiseDynamicFields = $payoutsForm.find('.wcu-wisebank-region-fields input, .wcu-wisebank-region-fields select');
            var wiseState = $payoutsForm.find('[name="wisebank_state"]');

            if (currentpayout !== "wisebank") {
                wiseStaticRequired.removeAttr('required');
                wiseDynamicFields.removeAttr('required');
                wiseState.removeAttr('required');
            }

            if (currentpayout === "custom1") $payoutsForm.find('.wcu-payout-type-custom1').addClass('wcu-show');
            if (currentpayout === "custom2") $payoutsForm.find('.wcu-payout-type-custom2').addClass('wcu-show');
            if (currentpayout === "banktransfer") $payoutsForm.find('.wcu-payout-type-banktransfer').addClass('wcu-show');
            if (currentpayout === "paypalapi") $payoutsForm.find('.wcu-payout-type-paypalapi').addClass('wcu-show');
            if (currentpayout === "wiseapi") $payoutsForm.find('.wcu-payout-type-wiseapi').addClass('wcu-show');
            if (currentpayout === "wisebank") {
                $payoutsForm.find('.wcu-payout-type-wisebank').addClass('wcu-show');
                wiseStaticRequired.attr('required', 'required');
                wcusage_payouts_check_wisebank_region();
            }
            if (currentpayout === "stripeapi") $payoutsForm.find('.wcu-payout-type-stripeapi').addClass('wcu-show');
            if (currentpayout === "credit") $payoutsForm.find('.wcu-payout-type-credit').addClass('wcu-show');
        }

        // Wise Bank Region toggle for payouts tab
        function wcusage_payouts_check_wisebank_region() {
            var selectedRegion = $payoutsForm.find('[name="wisebank_region"]').val();
            $payoutsForm.find('.wcu-wisebank-region-fields').hide();
            $payoutsForm.find('.wcu-wisebank-region-fields input, .wcu-wisebank-region-fields select').removeAttr('required');

            if (selectedRegion === 'us') {
                $payoutsForm.find('.wcu-wisebank-us').show();
                $payoutsForm.find('[name="wisebank_account_number_us"], [name="wisebank_routing_number"], [name="wisebank_account_type"]').attr('required', 'required');
                $payoutsForm.find('[name="wisebank_country"]').val('US');
                $payoutsForm.find('.wcu-wisebank-state-field').show();
                $payoutsForm.find('[name="wisebank_state"]').attr('required', 'required');
            } else if (selectedRegion === 'uk') {
                $payoutsForm.find('.wcu-wisebank-uk').show();
                $payoutsForm.find('[name="wisebank_account_number_uk"], [name="wisebank_sort_code"]').attr('required', 'required');
                $payoutsForm.find('[name="wisebank_country"]').val('GB');
                $payoutsForm.find('.wcu-wisebank-state-field').hide();
                $payoutsForm.find('[name="wisebank_state"]').removeAttr('required');
            } else if (selectedRegion === 'eu') {
                $payoutsForm.find('.wcu-wisebank-eu').show();
                $payoutsForm.find('[name="wisebank_iban"]').attr('required', 'required');
                $payoutsForm.find('[name="wisebank_country"]').val('DE');
                $payoutsForm.find('.wcu-wisebank-state-field').hide();
                $payoutsForm.find('[name="wisebank_state"]').removeAttr('required');
            } else if (selectedRegion === 'international') {
                $payoutsForm.find('.wcu-wisebank-international').show();
                $payoutsForm.find('[name="wisebank_account_number_intl"], [name="wisebank_swift_code"], [name="wisebank_bank_name"]').attr('required', 'required');
                $payoutsForm.find('[name="wisebank_country"]').val('');
                $payoutsForm.find('.wcu-wisebank-state-field').hide();
                $payoutsForm.find('[name="wisebank_state"]').removeAttr('required');
            } else {
                $payoutsForm.find('.wcu-wisebank-state-field').hide();
                $payoutsForm.find('[name="wisebank_state"]').removeAttr('required');
            }
        }

        // Initialize toggles
        wcusage_payouts_check_payout_type();
        wcusage_payouts_check_wisebank_region();

        // Bind change events
        $payoutsForm.find('[name="payouttype"]').on('change', function () {
            wcusage_payouts_check_payout_type();
        });
        $payoutsForm.find('[name="wisebank_region"]').on('change', wcusage_payouts_check_wisebank_region);

        // Helper for Wise account number
        function getPayoutsWiseBankAccountNumber() {
            var selectedRegion = $payoutsForm.find('[name="wisebank_region"]').val();
            if (selectedRegion === 'us') return $payoutsForm.find('[name="wisebank_account_number_us"]').val() || '';
            if (selectedRegion === 'uk') return $payoutsForm.find('[name="wisebank_account_number_uk"]').val() || '';
            if (selectedRegion === 'international') return $payoutsForm.find('[name="wisebank_account_number_intl"]').val() || '';
            return '';
        }

        // AJAX submit for payouts tab form
        $payoutsForm.on('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var wiseBankAccountNumber = getPayoutsWiseBankAccountNumber();

            var formData = {
                action: 'wcusage_update_settings',
                nonce: $payoutsForm.find('[name="wcusage_settings_nonce"]').val(),
                post_id: $payoutsForm.find('[name="post_id"]').val(),
                payouttype: $payoutsForm.find('[name="payouttype"]').val() || '',
                paypalemail: $payoutsForm.find('[name="paypalemail"]').val() || '',
                paypalemail2: $payoutsForm.find('[name="paypalemail2"]').val() || '',
                bankname: $payoutsForm.find('[name="bankname"]').val() || '',
                banksort: $payoutsForm.find('[name="banksort"]').val() || '',
                bankaccount: $payoutsForm.find('[name="bankaccount"]').val() || '',
                bankother: $payoutsForm.find('[name="bankother"]').val() || '',
                bankother2: $payoutsForm.find('[name="bankother2"]').val() || '',
                bankother3: $payoutsForm.find('[name="bankother3"]').val() || '',
                bankother4: $payoutsForm.find('[name="bankother4"]').val() || '',
                paypalemailapi: $payoutsForm.find('[name="paypalemailapi"]').val() || '',
                wiseemailapi: $payoutsForm.find('[name="wiseemailapi"]').val() || '',
                wisebank_region: $payoutsForm.find('[name="wisebank_region"]').val() || '',
                wisebank_account_name: $payoutsForm.find('[name="wisebank_account_name"]').val() || '',
                wisebank_account_number: wiseBankAccountNumber,
                wisebank_routing_number: $payoutsForm.find('[name="wisebank_routing_number"]').val() || '',
                wisebank_swift_code: $payoutsForm.find('[name="wisebank_swift_code"]').val() || '',
                wisebank_iban: $payoutsForm.find('[name="wisebank_iban"]').val() || '',
                wisebank_sort_code: $payoutsForm.find('[name="wisebank_sort_code"]').val() || '',
                wisebank_bank_name: $payoutsForm.find('[name="wisebank_bank_name"]').val() || '',
                wisebank_bank_address: $payoutsForm.find('[name="wisebank_bank_address"]').val() || '',
                wisebank_country: $payoutsForm.find('[name="wisebank_country"]').val() || '',
                wisebank_address: $payoutsForm.find('[name="wisebank_address"]').val() || '',
                wisebank_city: $payoutsForm.find('[name="wisebank_city"]').val() || '',
                wisebank_postcode: $payoutsForm.find('[name="wisebank_postcode"]').val() || '',
                wisebank_state: $payoutsForm.find('[name="wisebank_state"]').val() || '',
                wisebank_recipient_country: $payoutsForm.find('[name="wisebank_recipient_country"]').val() || ''
            };

            var selectedRegion = $payoutsForm.find('[name="wisebank_region"]').val();
            if (selectedRegion === 'us') {
                formData.wisebank_account_number_us = $payoutsForm.find('[name="wisebank_account_number_us"]').val() || '';
            } else if (selectedRegion === 'uk') {
                formData.wisebank_account_number_uk = $payoutsForm.find('[name="wisebank_account_number_uk"]').val() || '';
            } else if (selectedRegion === 'international') {
                formData.wisebank_account_number_intl = $payoutsForm.find('[name="wisebank_account_number_intl"]').val() || '';
            }

            $.ajax({
                url: wcusage_ajax.ajax_url,
                type: 'POST',
                data: formData,
                beforeSend: function () {
                    $payoutsForm.find('#wcu-settings-update-button')
                        .prop('disabled', true)
                        .text(wcusage_ajax.saving_text);
                    $payoutsForm.find('#wcu-settings-ajax-message').stop(true, true).empty().show();
                },
                success: function (response) {
                    if (response.success) {
                        $payoutsForm.find('#wcu-settings-ajax-message').html(
                            '<p style="color: green;">' + response.data.message + '</p>'
                        ).fadeIn().delay(4000).fadeOut();

                        if (response.data.updated_payout_fields.payouttype) {
                            // Reload page so the payouts tab refreshes with new method
                            if (payouts_current_payout_type !== response.data.updated_payout_fields.payouttype) {
                                location.reload();
                            }
                        }

                        $payoutsForm.find('#wcu-settings-update-button')
                            .prop('disabled', false)
                            .text(wcusage_ajax.save_text);
                    } else {
                        $payoutsForm.find('#wcu-settings-ajax-message').stop(true, true).html(
                            '<p style="color: red;">Error: ' + (response.data || 'Unknown error') + '</p>'
                        ).show();
                    }
                },
                error: function (xhr, status, error) {
                    $payoutsForm.find('#wcu-settings-ajax-message').stop(true, true).html(
                        '<p style="color: red;">AJAX Error: ' + error + '</p>'
                    ).show();
                },
                complete: function () {
                    $payoutsForm.find('#wcu-settings-update-button')
                        .prop('disabled', false)
                        .text(wcusage_ajax.save_text);
                }
            });

            return false;
        });
    }
});