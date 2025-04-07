jQuery(document).ready(function($) {
    // For debugging - remove in production
    // alert('WooCommerce Usage Coupons JS Loaded!');

    // Initialize autocomplete for user search
    $('.wcu-autocomplete-user').each(function() {
        var $input = $(this);
        
        $input.autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: wcusage_coupons_vars.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'wcusage_search_users',
                        nonce: wcusage_coupons_vars.nonce,
                        search: request.term
                    },
                    success: function(data) {
                        if (data.success) {
                            response(data.data);
                        } else {
                            response([]);
                        }
                    },
                    error: function() {
                        response([]);
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $input.val(ui.item.value); // Display username
                return false;
            },
            focus: function(event, ui) {
                return false; // Prevent value from being inserted on focus
            },
            close: function() {
                // Optional: Clear if no valid selection (though server will handle invalid usernames)
                var currentValue = $input.val();
                $.ajax({
                    url: wcusage_coupons_vars.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'wcusage_search_users',
                        nonce: wcusage_coupons_vars.nonce,
                        search: currentValue
                    },
                    success: function(data) {
                        if (data.success && !data.data.some(item => item.value === currentValue)) {
                            $input.val(''); // Clear if not a valid username
                        }
                    }
                });
            }
        }).autocomplete('instance')._renderItem = function(ul, item) {
            return $('<li>')
                .append('<div>' + item.label + '</div>')
                .appendTo(ul);
        };

        // Clear field if no valid selection on blur (optional client-side validation)
        $input.on('blur', function() {
            setTimeout(function() {
                var currentValue = $input.val();
                if (currentValue) {
                    $.ajax({
                        url: wcusage_coupons_vars.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'wcusage_search_users',
                            nonce: wcusage_coupons_vars.nonce,
                            search: currentValue
                        },
                        success: function(data) {
                            if (data.success && !data.data.some(item => item.value === currentValue)) {
                                $input.val('');
                            }
                        }
                    });
                }
            }, 200); // Delay to allow select event to complete
        });
    });

    // Show/hide quick edit form
    $('.quick-edit-coupon').on('click', function(e) {
        e.preventDefault();
        var couponId = $(this).data('coupon-id');
        var $row = $('#quick-edit-' + couponId);
        $row.toggle();
    });

    // Cancel quick edit
    $('.cancel-quick-edit').on('click', function(e) {
        e.preventDefault();
        $(this).closest('.quick-edit-row').hide();
    });

    // Save quick edit
    $('.save-quick-edit').on('click', function(e) {
        e.preventDefault();
        var couponId = $(this).data('coupon-id');
        var $row = $('#quick-edit-' + couponId);
        var $spinner = $row.find('.spinner');
        $spinner.addClass('is-active');

        var formData = {
            action: 'wcusage_save_coupon_data',
            coupon_id: couponId,
            nonce: wcusage_coupons_vars.nonce,
            post_title: $row.find('#coupon_code_' + couponId).val(),
            post_excerpt: $row.find('#coupon_description_' + couponId).val(),
            discount_type: $row.find('#discount_type_' + couponId).val(),
            coupon_amount: $row.find('#coupon_amount_' + couponId).val(),
            free_shipping: $row.find('#free_shipping_' + couponId).is(':checked') ? 'yes' : 'no',
            expiry_date: $row.find('#expiry_date_' + couponId).val(),
            minimum_amount: $row.find('#minimum_amount_' + couponId).val(),
            maximum_amount: $row.find('#maximum_amount_' + couponId).val(),
            individual_use: $row.find('#individual_use_' + couponId).is(':checked') ? 'yes' : 'no',
            exclude_sale_items: $row.find('#exclude_sale_items_' + couponId).is(':checked') ? 'yes' : 'no',
            usage_limit_per_user: $row.find('#usage_limit_per_user_' + couponId).val(),
            wcu_enable_first_order_only: $row.find('#wcu_enable_first_order_only_' + couponId).is(':checked') ? 'yes' : 'no',
            wcu_select_coupon_user: $row.find('#wcu_select_coupon_user_' + couponId).val(), // Use username directly
            wcu_text_coupon_commission: $row.find('#wcu_text_coupon_commission_' + couponId).val(),
            wcu_text_coupon_commission_fixed_order: $row.find('#wcu_text_coupon_commission_fixed_order_' + couponId).val(),
            wcu_text_coupon_commission_fixed_product: $row.find('#wcu_text_coupon_commission_fixed_product_' + couponId).val(),
            wcu_text_unpaid_commission: $row.find('#wcu_text_unpaid_commission_' + couponId).val(),
            wcu_text_pending_payment_commission: $row.find('#wcu_text_pending_payment_commission_' + couponId).val()
        };

        $.ajax({
            url: wcusage_coupons_vars.ajax_url,
            method: 'POST',
            data: formData,
            success: function(response) {
                $spinner.removeClass('is-active');
                if (response.success) {
                    var $tableRow = $('#coupon-row-' + couponId);
                    
                    // Update Coupon Code
                    $tableRow.find('.column-post_title a').text(formData.post_title);
                    
                    // Update Coupon Type
                    var discountType = formData.discount_type;
                    var amount = formData.coupon_amount;
                    var types = {
                        'percent': wcusage_coupons_vars.types.percent,
                        'fixed_cart': wcusage_coupons_vars.types.fixed_cart,
                        'fixed_product': wcusage_coupons_vars.types.fixed_product,
                        'percent_product': wcusage_coupons_vars.types.percent_product
                    };
                    var display = types[discountType] || discountType;
                    var formattedAmount = amount ? (discountType === 'percent' ? amount + '%' : wcusage_coupons_vars.currency_symbol + amount) : '';
                    $tableRow.find('.column-coupon_type').text(display + (formattedAmount ? ' (' + formattedAmount + ')' : ''));
                    
                    // Update Affiliate User
                    var username = formData.wcu_select_coupon_user;
                    var $affiliateCell = $tableRow.find('.column-affiliate');
                    if (username) {
                        // We don't have the ID here, so we'll need to fetch it or assume server handles it
                        $affiliateCell.html('<a href="' + wcusage_coupons_vars.edit_user_url.replace('0', 'USER_ID_PLACEHOLDER') + '" target="_blank">' + username + '</a>');
                        // Ideally, fetch the ID from the server response or another AJAX call if needed
                    } else {
                        $affiliateCell.text('-');
                    }
                    
                    // Update Unpaid Commission
                    var unpaidCommission = formData.wcu_text_unpaid_commission;
                    if (unpaidCommission) {
                        unpaidCommission = parseFloat(unpaidCommission).toFixed(2);
                    }
                    $tableRow.find('.column-unpaid_commission').text(unpaidCommission ? wcusage_coupons_vars.currency_symbol + unpaidCommission : '-');
                    
                    $row.hide();
                } else {
                    alert('Error saving coupon: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function() {
                $spinner.removeClass('is-active');
                alert('Error saving coupon');
            }
        });
    });

    // Copy referral link functionality (if needed)
    $('.wcusage-copy-link-button').on('click', function() {
        var $input = $(this).siblings('.wcusage-copy-link-text');
        $input.select();
        document.execCommand('copy');
    });
});