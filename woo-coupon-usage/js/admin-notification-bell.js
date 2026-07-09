jQuery(document).ready(function($){
    var bellShakeInterval;
    var bellCheckInterval;
    var originalTitle = document.title;
    var currentNotificationCount = 0;
    var bellRequestInFlight = false;

    // Poll interval (ms). Provided by the server (filterable) with a safe fallback.
    var pollInterval = parseInt(wcusageAdminBell.interval, 10);
    if (!pollInterval || pollInterval < 5000) {
        pollInterval = 30000; // default 30 seconds
    }

    // Load bell count on page load (dropdown is fetched on demand when opened)
    loadBellData();

    // Start periodic checking for new notifications. This keeps running while the
    // tab is in the background (browsers throttle background timers on their own)
    // so the tab-title count stays current when new notifications come in.
    startPeriodicCheck();

    // Listen for tab visibility changes (to keep the tab title count in sync)
    $(document).on('visibilitychange', handleVisibilityChange);

    // Handle bell click to show/hide dropdown
    var bellDisabled = false;
    $('#wcusage-admin-bell').on('click', function(e){
        if (bellDisabled) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        bellDisabled = true;
        // Visually disable bell and prevent pointer events
        $('#wcusage-admin-bell').css({'pointer-events':'none'});
        setTimeout(function(){
            bellDisabled = false;
            $('#wcusage-admin-bell').css({'pointer-events':'auto'});
        }, 2000);
        // Always reload dropdown data and show it, even if notifications are disabled
        // Ensure placeholder exists
        if ($('#wcusage-admin-bell-dropdown-placeholder').length === 0) {
            $('.wcusage-admin-bell-container').append('<div id="wcusage-admin-bell-dropdown-placeholder"></div>');
        }
        loadBellData(true, true, function() {
            if (!bellDisabled) {
                $('#wcusage-admin-bell-dropdown').show();
            }
        });
    });

    // Handle toggle notifications click
    $(document).on('click', '#wcusage-toggle-notifications', function(e){
        e.preventDefault();
        $('#wcusage-admin-bell-dropdown').remove();
        // Ensure placeholder exists
        if ($('#wcusage-admin-bell-dropdown-placeholder').length === 0) {
            $('.wcusage-admin-bell-container').append('<div id="wcusage-admin-bell-dropdown-placeholder"></div>');
        } else {
            $('#wcusage-admin-bell-dropdown-placeholder').empty();
        }
        $.post(wcusageAdminBell.ajax_url, {
            action: 'wcusage_toggle_admin_notifications',
            nonce: wcusageAdminBell.nonce
        }, function(response){
            loadBellData(true, true, function() {
                $('#wcusage-admin-bell-dropdown').show();
            });
        });
    });

    function loadBellData(updateDate, includeDropdown, callback) {
        // Prevent overlapping background polls from stacking up if the server is slow.
        // User-driven requests (bell click / toggle, which set updateDate) always run.
        if (bellRequestInFlight && !updateDate) {
            return;
        }
        bellRequestInFlight = true;
        var data = {
            action: 'wcusage_admin_bell_data',
            nonce: wcusageAdminBell.nonce
        };
        if (updateDate) {
            data.update_date = '1';
        }
        if (includeDropdown) {
            data.include_dropdown = '1';
        }
        $.post(wcusageAdminBell.ajax_url, data, function(response){
            currentNotificationCount = response.count;
            if (response.count > 0) {
                $('.wcusage-admin-bell-count').text(response.count).show();
                startBellShake();
            } else {
                $('.wcusage-admin-bell-count').hide();
                stopBellShake();
            }
            // Only touch the dropdown when it was requested (bell open / toggle)
            if (includeDropdown) {
                if (response.dropdown_html && response.dropdown_html.trim() !== '') {
                    $('#wcusage-admin-bell-dropdown-placeholder').html(response.dropdown_html);
                } else {
                    $('#wcusage-admin-bell-dropdown').remove();
                }
            }
            $('#wcusage-admin-bell').css('opacity', response.enabled == '1' ? '1' : '0.5');
            updateTabTitle();
            if (callback) callback();
        }).always(function(){
            bellRequestInFlight = false;
        });
    }

    function startPeriodicCheck() {
        if (bellCheckInterval) return; // Already running
        bellCheckInterval = setInterval(function(){
            loadBellData(false, false); // Count only: don't update date, don't fetch dropdown
        }, pollInterval);
    }

    function startBellShake() {
        if (bellShakeInterval) return; // Already shaking
        $('.fa-bell').addClass('wcusage-bell-shake');
        bellShakeInterval = setInterval(function(){
            $('.fa-bell').addClass('wcusage-bell-shake');
            setTimeout(function(){
                $('.fa-bell').removeClass('wcusage-bell-shake');
            }, 500); // Animation duration
        }, 2000); // Every 2 seconds
    }

    function stopBellShake() {
        if (bellShakeInterval) {
            clearInterval(bellShakeInterval);
            bellShakeInterval = null;
        }
        $('.fa-bell').removeClass('wcusage-bell-shake');
    }

    function handleVisibilityChange() {
        if (document.hidden) {
            // Tab hidden: reflect the current notification count in the tab title
            updateTabTitle();
        } else {
            // Tab visible again: restore the original title
            restoreTabTitle();
        }
    }

    function updateTabTitle() {
        if (document.hidden && currentNotificationCount > 0) {
            document.title = '(' + currentNotificationCount + ') ' + originalTitle;
        }
    }

    function restoreTabTitle() {
        document.title = originalTitle;
    }
});
