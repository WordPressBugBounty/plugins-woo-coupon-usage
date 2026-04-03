jQuery(document).ready(function($) {

    // If the page was loaded after a successful AJAX registration (PRG redirect),
    // show the stored success message instead of the form.
    if (window.location.search.indexOf('wcusage_registered=1') !== -1) {
        var storedMessage = sessionStorage.getItem('wcusage_registration_message');
        if (storedMessage) {
            sessionStorage.removeItem('wcusage_registration_message');
            var $form = $('#wcu_form_affiliate_register');
            if ($form.length) {
                $form.replaceWith('<div class="success-message">' + storedMessage + '</div>');
            }
        }
    }

    $('#wcu_form_affiliate_register').on('submit', function(e) {

        // Set wcu-register-button to disabled
        $('#wcu-register-button').hide(); // Hide button and change text
        $('.register-spinner').css('display', 'block'); // Show spinner

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
                    if (response.data && response.data.redirect) {
                        window.location.href = response.data.redirect;
                        return;
                    }
                    // Store the message in sessionStorage and redirect to the current URL
                    // with a query param so that a page refresh shows the message without
                    // re-submitting the form (Post/Redirect/Get pattern).
                    sessionStorage.setItem('wcusage_registration_message', response.data.message);
                    var redirectUrl = window.location.href.split('?')[0] + '?wcusage_registered=1';
                    window.location.href = redirectUrl;
                } else {
                    alert('Error: ' + response.data.message); // Show error message
                    // Set wcu-register-button to enabled
                    $('#wcu-register-button').show();
                    $('.register-spinner').css('display', 'none'); // Hide spinner
                }
            },
            error: function() {
                $('#wcu-register-button').show();
                $('.register-spinner').css('display', 'none');
                alert('An error occurred. Please try again: ' + response.statusText);
            }
        });
    });
});