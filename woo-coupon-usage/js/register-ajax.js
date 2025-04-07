jQuery(document).ready(function($) {
    $('#wcu_form_affiliate_register').on('submit', function(e) {

        // Set wcu-register-button to disabled
        $('#wcu-register-button').prop('disabled', true); // Disable button and change text

        e.preventDefault(); // Stop the form from submitting normally

        var formData = new FormData(this); // Collect all form data
        formData.append('wcusage_submit_registration_form1', wcusage_ajax_object.nonce); // Add nonce

        // Add action to data "wcusage_submit_registration"
        formData.append('action', 'wcusage_submit_registration');

        $.ajax({
            url: wcusage_ajax_object.ajax_url, // WordPress AJAX URL
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#wcu_form_affiliate_register').replaceWith('<div class="success-message">' + response.data.message + '</div>');
                } else {
                    alert('Error: ' + response.data.message); // Show error message
                }
            },
            error: function() {
                alert('An error occurred. Please try again: ' + response.statusText); // Show error message
                // Set wcu-register-button to enabled
                $('#wcu-register-button').prop('disabled', false); // Enable button and change text
            }
        });
    });
});