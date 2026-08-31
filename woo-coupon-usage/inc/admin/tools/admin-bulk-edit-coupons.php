<?php
if (!defined('ABSPATH')) {
    exit;
}

function wcusage_bulk_coupon_fields() {
    $coupons = get_posts(array(
        'post_type' => 'shop_coupon',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'DESC',
    ));

    foreach ($coupons as $coupon) {
        $coupon_id = $coupon->ID;
        $coupon_name = $coupon->post_title;

        $user_id = get_post_meta($coupon_id, 'wcu_select_coupon_user', true);
        $username = '';
        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            if ($user) {
                $username = $user->user_login;
            }
        }
        $commission_percent = get_post_meta($coupon_id, 'wcu_text_coupon_commission', true);
        $commission_order = get_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_order', true);
        $commission_product = get_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_product', true);
        $coupon_amount = get_post_meta($coupon_id, 'coupon_amount', true);
        ?>
        <tr data-coupon-id="<?php echo esc_attr($coupon_id); ?>" data-changed="false">
            <td><?php echo esc_html($coupon_id); ?></td>
            <td>
                <input type="text" name="coupon_name[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($coupon_name); ?>" data-original="<?php echo esc_attr($coupon_name); ?>">
            </td>
            <td>
                <select name="discount_type[<?php echo esc_attr($coupon_id); ?>]" data-original="<?php echo esc_attr(get_post_meta($coupon_id, 'discount_type', true)); ?>">
                    <?php foreach (wc_get_coupon_types() as $key => $type) { ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected(get_post_meta($coupon_id, 'discount_type', true), $key); ?>>
                            <?php echo esc_html($type); ?>
                        </option>
                    <?php } ?>
                </select>
            </td>
            <td><input type="text" name="coupon_amount[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($coupon_amount); ?>" data-original="<?php echo esc_attr($coupon_amount); ?>"></td>
            <td><input type="text" name="username[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($username); ?>" data-original="<?php echo esc_attr($username); ?>"></td>
            <?php if (wcu_fs()->can_use_premium_code()) { ?>
            <td><input type="text" name="commission_percent[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($commission_percent); ?>" data-original="<?php echo esc_attr($commission_percent); ?>"></td>
            <td><input type="text" name="commission_order[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($commission_order); ?>" data-original="<?php echo esc_attr($commission_order); ?>"></td>
            <td><input type="text" name="commission_product[<?php echo esc_attr($coupon_id); ?>]" value="<?php echo esc_attr($commission_product); ?>" data-original="<?php echo esc_attr($commission_product); ?>"></td>
            <?php } ?>
        </tr>
        <?php
    }

    $total_coupons = count($coupons);
    echo '<input type="hidden" id="total-coupons" value="' . esc_html($total_coupons) . '">';
}

function wcusage_bulk_coupon_page() {

    // Check if user is administrator
    if ( ! wcusage_check_admin_access() ) {
        wp_die('Error: Permission denied.');
    }

    // Nonce field for security
    $nonce = wp_create_nonce('bulk_coupon_update');
    ?>

    <?php wcusage_enqueue_font_awesome(); ?>

    <div class="wrap wcusage-admin-page">
        <?php do_action('wcusage_hook_dashboard_page_header', ''); ?>
    </div>

    <div class="wrap wcusage-bulk-edit-coupons wcusage-tools">

        <h2><?php echo esc_html__('Bulk Edit: Coupon Settings', 'woo-coupon-usage'); ?></h2>
        <p><?php echo esc_html__('Use this tool to bulk edit your coupon settings.', 'woo-coupon-usage'); ?></p>
        <br/>
        <button type="button" id="import-csv" class="button"><?php esc_html_e('Import CSV', 'woo-coupon-usage'); ?></button>
        <button type="button" id="export-csv" class="button"><?php esc_html_e('Export CSV', 'woo-coupon-usage'); ?></button>
        <br/><br/>
        <form id="bulk-coupon-form" method="POST">
            <input type="hidden" name="action" value="update_coupons">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_html($nonce); ?>">
            <div class="wcu-scrollable-table">
                <table id="wcusage-tools-rows">
                    <tr>
                        <th><?php echo esc_html__('Coupon ID', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Coupon Name', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Discount Type', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Discount Amount', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Affiliate Username', 'woo-coupon-usage'); ?></th>
                        <?php if (wcu_fs()->can_use_premium_code()) { ?>
                        <th><?php echo esc_html__('Commission Percent', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Commission £ - Order', 'woo-coupon-usage'); ?></th>
                        <th><?php echo esc_html__('Commission £ - Product', 'woo-coupon-usage'); ?></th>
                        <?php } ?>
                    </tr>
                    <?php wcusage_bulk_coupon_fields(); ?>
                </table>
            </div>
            <br/>
            <p><span id="spinner" style="display: none; font-size: 20px; color: green;"><i class="fas fa-spinner fa-spin"></i> Updating... <span id="progress">0/0</span></span></p>
            <span id="update-progress"></span></p>
            <p><input type="button" value="Update Coupons" id="update-coupons-button" class="button button-primary" style="margin-bottom: 20px;"></p>
            <br/><br/>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_tools')); ?>">Go back to tools ></a></p>
        </form>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Track changes to form fields
            $('#wcusage-tools-rows').on('input change', 'input, select', function() {
                var $this = $(this);
                var currentValue = $this.val();
                var originalValue = $this.data('original') || '';
                var $row = $this.closest('tr');
                
                // Check if this field has changed
                var isChanged = (currentValue != originalValue);
                
                // Check if any field in this row has changed
                var rowHasChanges = false;
                $row.find('input, select').each(function() {
                    var fieldCurrentValue = $(this).val();
                    var fieldOriginalValue = $(this).data('original') || '';
                    if (fieldCurrentValue != fieldOriginalValue) {
                        rowHasChanges = true;
                        return false; // break out of loop
                    }
                });
                
                $row.attr('data-changed', rowHasChanges ? 'true' : 'false');
                
                // Visual indicator for changed rows
                if (rowHasChanges) {
                    $row.addClass('row-changed');
                } else {
                    $row.removeClass('row-changed');
                }
            });

            function wcusageParseBulkCouponCsv(csv) {
                var rows = [];
                var row = [];
                var value = '';
                var insideQuotes = false;

                csv = csv.replace(/^\uFEFF/, '');

                for (var index = 0; index < csv.length; index++) {
                    var character = csv.charAt(index);
                    var nextCharacter = csv.charAt(index + 1);

                    if (insideQuotes) {
                        if (character === '"') {
                            if (nextCharacter === '"') {
                                value += '"';
                                index++;
                            } else {
                                insideQuotes = false;
                            }
                        } else {
                            value += character;
                        }
                    } else if (character === '"') {
                        insideQuotes = true;
                    } else if (character === ',') {
                        row.push(value);
                        value = '';
                    } else if (character === '\n' || character === '\r') {
                        row.push(value);
                        rows.push(row);
                        row = [];
                        value = '';

                        if (character === '\r' && nextCharacter === '\n') {
                            index++;
                        }
                    } else {
                        value += character;
                    }
                }

                if (value !== '' || row.length > 0) {
                    row.push(value);
                    rows.push(row);
                }

                return rows.filter(function(rowData) {
                    return rowData.some(function(cell) {
                        return $.trim(cell) !== '';
                    });
                });
            }

            function wcusageGetBulkCouponCsvValue(row, headers, possibleHeaders, fallbackIndex) {
                var value = '';
                var foundHeader = false;

                $.each(possibleHeaders, function(index, header) {
                    var headerIndex = headers.indexOf(header.toLowerCase());

                    if (headerIndex !== -1 && typeof row[headerIndex] !== 'undefined') {
                        value = row[headerIndex];
                        foundHeader = true;
                        return false;
                    }
                });

                if (!foundHeader && typeof row[fallbackIndex] !== 'undefined') {
                    value = row[fallbackIndex];
                }

                return $.trim(value);
            }

            function wcusageSetBulkCouponField(couponRow, selector, value, eventName) {
                var field = couponRow.find(selector);

                if (!field.length) {
                    return false;
                }

                field.val(value).trigger(eventName);
                return true;
            }

            function wcusageShowBulkCouponImportMessage(message, type) {
                $('.wcusage-message.import-message').remove();
                $('<div class="wcusage-message import-message ' + type + '"><p>' + message + '</p></div>').insertAfter('#export-csv');
            }

            function wcusageEscapeBulkCouponCsvValue(value) {
                value = (typeof value === 'undefined' || value === null) ? '' : String(value);
                return '"' + value.replace(/"/g, '""') + '"';
            }

            // Import CSV button click event
            $('#import-csv').on('click', function() {
                var csvFileInput = $('<input type="file" accept=".csv" style="display:none">').appendTo('body');
                csvFileInput.change(function(e) {
                    var file = e.target.files[0];
                    if (!file) return;
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var rows = wcusageParseBulkCouponCsv(e.target.result);

                        if (rows.length < 2) {
                            wcusageShowBulkCouponImportMessage('<?php echo esc_js(__('No coupon rows were found in the selected CSV file.', 'woo-coupon-usage')); ?>', 'error');
                            csvFileInput.remove();
                            return;
                        }

                        var headers = $.map(rows[0], function(header) {
                            return $.trim(header).replace(/^\uFEFF/, '').toLowerCase();
                        });
                        var importedRows = 0;
                        var skippedRows = 0;

                        for (var i = 1; i < rows.length; i++) {
                            var data = rows[i];
                            var couponId = wcusageGetBulkCouponCsvValue(data, headers, ['coupon id'], 0).replace(/[^0-9]/g, '');
                            var couponRow = $('#wcusage-tools-rows tr[data-coupon-id="' + couponId + '"]');

                            if (!couponId || !couponRow.length) {
                                skippedRows++;
                                continue;
                            }

                            var couponName = wcusageGetBulkCouponCsvValue(data, headers, ['coupon name'], 1);
                            var discountType = wcusageGetBulkCouponCsvValue(data, headers, ['discount type'], 2);
                            var couponAmount = wcusageGetBulkCouponCsvValue(data, headers, ['discount amount'], 3);
                            var username = wcusageGetBulkCouponCsvValue(data, headers, ['affiliate username'], 4);
                            var rowUpdated = false;

                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="coupon_name[' + couponId + ']"]', couponName, 'input') || rowUpdated;
                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'select[name="discount_type[' + couponId + ']"]', discountType, 'change') || rowUpdated;
                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="coupon_amount[' + couponId + ']"]', couponAmount, 'input') || rowUpdated;
                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="username[' + couponId + ']"]', username, 'input') || rowUpdated;
                            <?php if (wcu_fs()->can_use_premium_code()) { ?>
                            var commissionPercent = wcusageGetBulkCouponCsvValue(data, headers, ['commission percent'], 5);
                            var commissionOrder = wcusageGetBulkCouponCsvValue(data, headers, ['commission £ - order', 'commission - order'], 6);
                            var commissionProduct = wcusageGetBulkCouponCsvValue(data, headers, ['commission £ - product', 'commission - product'], 7);

                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="commission_percent[' + couponId + ']"]', commissionPercent, 'input') || rowUpdated;
                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="commission_order[' + couponId + ']"]', commissionOrder, 'input') || rowUpdated;
                            rowUpdated = wcusageSetBulkCouponField(couponRow, 'input[name="commission_product[' + couponId + ']"]', commissionProduct, 'input') || rowUpdated;
                            <?php } ?>

                            if (rowUpdated) {
                                importedRows++;
                            }
                        }

                        if (importedRows > 0) {
                            var message = '<?php echo esc_js(__('Imported coupon rows: ', 'woo-coupon-usage')); ?>' + importedRows + '. <?php echo esc_js(__('Review the highlighted rows, then click "Update Coupons" to save them.', 'woo-coupon-usage')); ?>';

                            if (skippedRows > 0) {
                                message += ' <?php echo esc_js(__('Skipped rows with no matching coupon ID: ', 'woo-coupon-usage')); ?>' + skippedRows + '.';
                            }

                            wcusageShowBulkCouponImportMessage(message, 'updated');
                        } else {
                            wcusageShowBulkCouponImportMessage('<?php echo esc_js(__('No matching coupon IDs were found in the selected CSV file.', 'woo-coupon-usage')); ?>', 'error');
                        }

                        csvFileInput.remove();
                    };
                    reader.onerror = function() {
                        wcusageShowBulkCouponImportMessage('<?php echo esc_js(__('The selected CSV file could not be read.', 'woo-coupon-usage')); ?>', 'error');
                        csvFileInput.remove();
                    };
                    reader.readAsText(file);
                });
                csvFileInput.click();
            });

            // Export CSV button click event
            $('#export-csv').on('click', function() {
                var csvContent = "";
                var headers = [];
                var rows = [];

                // Get table headers
                $('#wcusage-tools-rows th').each(function() {
                    headers.push($(this).text());
                });
                rows.push($.map(headers, wcusageEscapeBulkCouponCsvValue).join(','));

                // Get table data
                $('#wcusage-tools-rows tr:gt(0)').each(function() { // Exclude the first row (headings)
                    var rowData = [];
                    var couponId = $(this).data('coupon-id');

                    if (!couponId) {
                        return;
                    }

                    rowData.push(wcusageEscapeBulkCouponCsvValue(couponId)); // Add Coupon ID to the row data
                    $(this).find('td').each(function() {
                        // exclude first
                        if ($(this).index() == 0) return;
                        var value = '';
                        var inputElement = $(this).find('input, select');
                        if (inputElement.is('input[type="text"]')) {
                            value = inputElement.val();
                        } else if (inputElement.is('select')) {
                            value = inputElement.find('option:selected').val(); // Get the selected option's value
                        }
                        rowData.push(wcusageEscapeBulkCouponCsvValue(value));
                    });
                    rows.push(rowData.join(','));
                });

                // Create CSV file
                csvContent += rows.join('\n');
                var csvBlob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                var csvUrl = URL.createObjectURL(csvBlob);
                var link = document.createElement('a');

                link.setAttribute('href', csvUrl);
                link.setAttribute('download', 'wcusage-tools.csv');
                document.body.appendChild(link);

                // Simulate click on the link to trigger the download
                link.click();

                // Clean up the temporary link element
                document.body.removeChild(link);
                URL.revokeObjectURL(csvUrl);
            });

            // Handle form submission
            $('#update-coupons-button').on('click', function(e) {
                e.preventDefault();
                $('.wcusage-message').remove();
                $(this).prop('disabled', true); // Disable the button
                
                // Get only changed rows
                var changedRows = $('#wcusage-tools-rows tr[data-changed="true"]');
                var totalChangedCoupons = changedRows.length;
                
                if (totalChangedCoupons === 0) {
                    $('<div class="wcusage-message updated"><p>No changes detected. Nothing to update.</p></div>').insertAfter('#update-coupons-button');
                    $(this).prop('disabled', false);
                    return;
                }

                var confirmMsg = '<?php echo esc_js(__('You are about to update %s coupon(s). Changes are applied immediately and cannot be automatically undone.', 'woo-coupon-usage')); ?>'.replace('%s', totalChangedCoupons);
                confirmMsg += '\n\n' + '<?php echo esc_js(__('Continue?', 'woo-coupon-usage')); ?>';
                if (!window.confirm(confirmMsg)) {
                    $(this).prop('disabled', false);
                    return;
                }

                $('#spinner').show(); // Show the spinner icon
                $('#progress').text('0/' + totalChangedCoupons); // Initialize progress with changed count
                updateChangedCoupons(changedRows, 0);
            });

            function updateChangedCoupons(changedRows, index) {
                if (index >= changedRows.length) {
                    $('<div class="wcusage-message updated"><p>All ' + changedRows.length + ' changed coupons updated successfully!</p></div>').insertAfter('#update-coupons-button');
                    $('.button-primary').prop('disabled', false);
                    $('#spinner').hide(); // Hide the spinner icon
                    return;
                }
                
                var couponRow = changedRows.eq(index);
                var couponId = couponRow.data('coupon-id');
                var couponName = $('input[name="coupon_name[' + couponId + ']"]').val();
                var username = $('input[name="username[' + couponId + ']"]').val();
                var discountType = $('select[name="discount_type[' + couponId + ']"]').val();
                var couponAmount = $('input[name="coupon_amount[' + couponId + ']"]').val();
                <?php if (wcu_fs()->can_use_premium_code()) { ?>
                var commissionPercent = $('input[name="commission_percent[' + couponId + ']"]').val();
                var commissionOrder = $('input[name="commission_order[' + couponId + ']"]').val();
                var commissionProduct = $('input[name="commission_product[' + couponId + ']"]').val();
                <?php } else { ?>
                var commissionPercent = '';
                var commissionOrder = '';
                var commissionProduct = '';
                <?php } ?>
                var nextIndex = index + 1;
                $('#progress').text(nextIndex + '/' + changedRows.length); // Update progress

                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: {
                        action: 'update_coupon',
                        coupon_id: couponId,
                        coupon_name: couponName,
                        username: username,
                        discount_type: discountType,
                        coupon_amount: couponAmount,
                        commission_percent: commissionPercent,
                        commission_order: commissionOrder,
                        commission_product: commissionProduct,
                        _wpnonce: '<?php echo esc_html($nonce); ?>'
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        $('.button-primary').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            couponRow.addClass('updated');
                            couponRow.removeClass('row-changed');
                            couponRow.attr('data-changed', 'false');
                            // Update original values to new values
                            couponRow.find('input, select').each(function() {
                                $(this).attr('data-original', $(this).val());
                            });
                            $('<div class="wcusage-message updated"><p>Coupon ' + couponId + ' updated successfully!</p></div>').insertAfter('#update-coupons-button');
                        } else {
                            couponRow.addClass('error');
                            var errorMessage = '<p style="font-weight: bold;">Error updating coupon: <span style="color: red;">' + response.error_message + '</span></p>';
                            errorMessage += '<p>Coupon ID: ' + couponId + '</p>';
                            couponRow.after('<tr class="wcusage-message error"><td colspan="8">' + errorMessage + '</td></tr>');
                        }
                    },
                    error: function(xhr, status, error) {
                        couponRow.addClass('error');
                        couponRow.after('<tr class="wcusage-message error"><td colspan="8"><p>An error occurred. Please try again.</p></td></tr>');
                    },
                    complete: function() {
                        updateChangedCoupons(changedRows, index + 1);
                    }
                });
            }

            // Remove the old updateCoupon function as it's replaced by updateChangedCoupons
        });
    </script>
    
    <style>
        .row-changed {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107 !important;
        }
    </style>
    <?php
}

// Enqueue scripts for admin page
add_action('admin_enqueue_scripts', 'wcusage_enqueue_admin_scripts_coupon');
function wcusage_enqueue_admin_scripts_coupon() {
    if (isset($_GET['page']) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'wcusage-bulk-edit-coupon') {
        wp_enqueue_script('jquery');
    }
}

// Handle form submission
add_action('wp_ajax_update_coupon', 'wcusage_update_coupon');
function wcusage_update_coupon() {
    
    $response = array();

        if ( ! wcusage_check_admin_access() || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bulk_coupon_update')) {
        $response['success'] = false;
        $response['error_message'] = 'Security check failed.';
        wp_send_json($response);
        exit;
    }

    $coupon_id = intval($_POST['coupon_id']);
    $coupon_name = sanitize_text_field($_POST['coupon_name']);
    $username = sanitize_text_field($_POST['username']);
    $discount_type = sanitize_text_field($_POST['discount_type']);
    $coupon_amount = sanitize_text_field($_POST['coupon_amount']);
    if (wcu_fs()->can_use_premium_code()) {
        $commission_percent = sanitize_text_field($_POST['commission_percent']);
        $commission_order = sanitize_text_field($_POST['commission_order']);
        $commission_product = sanitize_text_field($_POST['commission_product']);
    }

    $user = get_user_by('login', $username);
    $user_id = $user ? $user->ID : 0;

    $current_id = get_post_meta($coupon_id, 'wcu_select_coupon_user', true);

    if (wcu_fs()->can_use_premium_code()) {
        $current_commission_percent = get_post_meta($coupon_id, 'wcu_text_coupon_commission', true);
        $current_commission_order = get_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_order', true);
        $current_commission_product = get_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_product', true);
    } else {
        $current_commission_percent = '';
        $current_commission_order = '';
        $current_commission_product = '';
    }

    if (wcu_fs()->can_use_premium_code()) {
        if ($current_id == $user_id
        && $coupon_name === get_the_title($coupon_id)
        && $discount_type === get_post_meta($coupon_id, 'discount_type', true)
        && $coupon_amount === get_post_meta($coupon_id, 'coupon_amount', true)
        && $current_commission_percent === $commission_percent
        && $current_commission_order === $commission_order
        && $current_commission_product === $commission_product) {
            $response['success'] = 'pass';
            wp_send_json($response);
            exit;
        }
    } else {
        if ($current_id == $user_id
        && $coupon_name === get_the_title($coupon_id)
        && $discount_type === get_post_meta($coupon_id, 'discount_type', true)
        && $coupon_amount === get_post_meta($coupon_id, 'coupon_amount', true)) {
            $response['success'] = 'pass';
            wp_send_json($response);
            exit;
        }
    }

    // update post title
    $post = array(
        'ID' => $coupon_id,
        'post_title' => $coupon_name
    );

    wp_update_post($post);

    update_post_meta($coupon_id, 'wcu_select_coupon_user', $user_id);

    // Bulk-edit commission changes are genuine manual admin edits, so allow the
    // activity log to record them (see wcusage_after_update_function). Flag only
    // around the actual writes so it can't leak onto later automated updates.
    if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
        wcusage_set_manual_commission_edit( true );
    }

    if (wcu_fs()->can_use_premium_code()) {
        update_post_meta($coupon_id, 'wcu_text_coupon_commission', $commission_percent);
        update_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_order', $commission_order);
        update_post_meta($coupon_id, 'wcu_text_coupon_commission_fixed_product', $commission_product);
    }

    if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
        wcusage_set_manual_commission_edit( false );
    }

    update_post_meta($coupon_id, 'discount_type', $discount_type);
    update_post_meta($coupon_id, 'coupon_amount', $coupon_amount);

    $response['success'] = true;
    wp_send_json($response);
    exit;
}
