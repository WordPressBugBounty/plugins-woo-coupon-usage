jQuery(document).ready(function($) {
    $('#wcu_form_affiliate_register').on('submit', function(e) {
        e.preventDefault(); // Stop the form from submitting normally

        var formData = new FormData(this); // Collect all form data
        formData.append('wcusage_submit_registration_form1', wcusage_ajax_object.nonce); // Add nonce

        $.ajax({
            url: wcusage_ajax_object.ajax_url, // WordPress AJAX URL
            type: 'POST',
            data: formData,
            processData: false, // Required for FormData with files
            contentType: false, // Required for FormData with files
            success: function(response) {
                if (response.success) {
                    // Replace the form with a success message
                    $('#wcu_form_affiliate_register').replaceWith('<div class="success-message">' + response.data.message + '</div>');
                } else {
                    alert('Error: ' + response.data.message); // Show error message
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            }
        });
    });
});