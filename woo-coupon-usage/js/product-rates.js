jQuery(document).ready(function($) {
    $(document).on('submit', '#product_search_form', function(e) {
        e.preventDefault();
        var searchTerm = $(this).find('input[name="search"]').val();
        loadData(searchTerm, 1);
    });

    $(document).on('click', '.rates-pagination a', function(e) {
        e.preventDefault();
        var page = $(this).data('paged');
        var searchTerm = $('#product_search_form input[name="search"]').val();
        loadData(searchTerm, page);
    });

    // Delegated copy handler — works after AJAX replacements
    $(document).on('click', '.product-rates-copy', function(e) {
        e.preventDefault();
        var $button   = $(this);
        var $input    = $button.siblings('input[type="text"]');
        var $copyIcon = $button.find('i').eq(0);
        var $copiedIcon = $button.find('i').eq(1);
        var text = $input.val();

        function showCopied() {
            $copyIcon.hide();
            $copiedIcon.show();
            setTimeout(function() {
                $copyIcon.show();
                $copiedIcon.hide();
            }, 1000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showCopied).catch(function() {
                fallbackCopy(text, showCopied);
            });
        } else {
            fallbackCopy(text, showCopied);
        }
    });

    function fallbackCopy(text, callback) {
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(text).select();
        try {
            document.execCommand('copy');
            if (callback) { callback(); }
        } catch (err) {
            console.warn('Copy failed', err);
        }
        $temp.remove();
    }

    function loadData(searchTerm, page) {
        $.post(wcusage_product_rates_ajax.ajax_url, {
            action: 'wcusage_rates_pagination',
            search: searchTerm,
            coupon: $('#product_search_form input[name="coupon"]').val(),
            paged: page,
        }, function(response) {
            // replaceWith keeps only one .wcusage-product-rates in the DOM
            // (the AJAX response already contains the wrapper div)
            $('.wcusage-product-rates').replaceWith(response);
        });
    }
});