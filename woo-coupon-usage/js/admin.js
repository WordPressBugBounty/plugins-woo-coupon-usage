jQuery(document).ready(function($) {

    // Event delegation for copy link button
    $(document).on('click', '.wcusage-copy-link-button', function() {

        // Find the input element associated with the clicked button
        var $linkInput = $(this).prev('input[type="text"]');

        // Disable copy button for 1 second
        $(this).prop('disabled', true);

        // Store the original text
        var $originalText = $linkInput.val();

        // Save the original text to the clipboard
        var $temp = $("<input>");
        $("body").append($temp);
        $temp.val($originalText).select();
        document.execCommand("copy");
        $temp.remove();

        // Change the text to "Copied!" for 1 second
        $linkInput.val('Copied!');
        setTimeout(function() {
            $linkInput.val($originalText);
            $('.wcusage-copy-link-button').prop('disabled', false);
        }, 1000);

        try {
            // Copy the text to the clipboard
            var successful = document.execCommand('copy');
        } catch (err) {
            console.log('Oops, unable to copy');
        }

    });
    
    // Show tooltip content when hovering over the tooltip icon
    $('.custom-tooltip').hover(function() {
        $(this).find('.tooltip-content').show();
    }, function() {
        $(this).find('.tooltip-content').hide();
    });

    // Keep the tooltip content open when hovering over it
    $('.custom-tooltip .tooltip-content').hover(function() {
        $(this).show();
    }, function() {
        $(this).hide();
    });

    // Function to position the tooltip
    function positionTooltip(tooltip) {
        var tooltipContent = tooltip.find('.tooltip-content');
        var tooltipWidth = tooltipContent.outerWidth();
        var tooltipHeight = tooltipContent.outerHeight();

        var windowWidth = $(window).width();
        var windowHeight = $(window).height();

        var tooltipOffset = tooltip.offset();
        var tooltipLeft = tooltipOffset.left;
        var tooltipTop = tooltipOffset.top;

        // Adjust horizontal position
        if (tooltipLeft + tooltipWidth > windowWidth) {
            tooltipLeft = windowWidth - tooltipWidth;
        }

        // Adjust vertical position
        if (tooltipTop + tooltipHeight > windowHeight) {
            tooltipTop = windowHeight - tooltipHeight;
        }

        tooltipContent.css({
            'left': tooltipLeft,
            'top': tooltipTop,
        });
    }

});