/**
 * Admin header notification bell.
 *
 * The bell count and dropdown contents are rendered server-side on page load, so
 * a click opens the panel immediately instead of waiting for an AJAX round trip.
 * Opening then refreshes the contents (and marks referrals as seen) in the
 * background, and a timer keeps the count up to date while the page is open.
 */
jQuery(document).ready(function($){

    if (typeof wcusageAdminBell === 'undefined') {
        return;
    }

    var $bell = $('#wcusage-admin-bell');
    var $dropdown = $('#wcusage-admin-bell-dropdown');
    if (!$bell.length || !$dropdown.length) {
        return;
    }

    var $container = $bell.closest('.wcusage-admin-bell-container');
    var $content = $dropdown.find('#wcusage-admin-bell-dropdown-content');
    var $count = $bell.find('.wcusage-admin-bell-count');

    var isOpen = false;
    var bellShakeInterval;
    var bellCheckInterval;
    var originalTitle = document.title;
    var currentNotificationCount = parseInt($count.text(), 10) || 0;
    var bellRequestInFlight = false;
    var latestRequest = 0;
    // Identifies the panel contents currently on the page, so the server can skip
    // sending them again when nothing has changed.
    var contentHash = wcusageAdminBell.content_hash || '';

    // Poll interval (ms). Provided by the server (filterable) with a safe fallback.
    var pollInterval = parseInt(wcusageAdminBell.interval, 10);
    if (!pollInterval || pollInterval < 5000) {
        pollInterval = 30000; // default 30 seconds
    }

    // The count and panel contents normally come rendered with the page, so there is
    // nothing to fetch on load - just start the animation if there is something to
    // report. When nothing was cached the server skips that work rather than holding
    // up the page render, and asks for it to be fetched here instead.
    if (currentNotificationCount > 0) {
        startBellShake();
    }
    if (wcusageAdminBell.prefetch == '1') {
        loadBellData();
    }

    // Start periodic checking for new notifications. This keeps running while the
    // tab is in the background (browsers throttle background timers on their own)
    // so the tab-title count stays current when new notifications come in. With
    // notifications switched off there is nothing to check for, so it does not run
    // at all until they are switched back on.
    if (wcusageAdminBell.enabled != '0') {
        startPeriodicCheck();
    }

    // Listen for tab visibility changes (to keep the tab title count in sync)
    $(document).on('visibilitychange', handleVisibilityChange);

    /* -----------------------------------------------------------------------
     * Opening and closing
     * -------------------------------------------------------------------- */

    $bell.on('click', function(e){
        e.preventDefault();
        if (isOpen) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    // Close when clicking anywhere outside the bell or the panel. Bound on
    // mousedown so the panel is out of the way before the click lands.
    $(document).on('mousedown.wcusageAdminBell', function(e){
        if (!isOpen) {
            return;
        }
        var $target = $(e.target);
        if ($target.closest($dropdown).length || $target.closest($bell).length) {
            return;
        }
        closeDropdown();
    });

    // Close on Escape.
    $(document).on('keydown.wcusageAdminBell', function(e){
        if (isOpen && (e.key === 'Escape' || e.keyCode === 27)) {
            closeDropdown();
            $bell.trigger('focus');
        }
    });

    // The panel is positioned against the bell, so follow it if the header reflows.
    $(window).on('resize.wcusageAdminBell', function(){
        if (isOpen) {
            positionDropdown();
        }
    });

    function openDropdown() {
        if (isOpen) {
            return;
        }
        isOpen = true;
        // The header sits inside an overflow:hidden container, so the panel has to
        // be moved to the body to avoid being clipped.
        if (!$dropdown.data('wcusagePortal')) {
            $dropdown.data('wcusagePortal', true).appendTo('body');
        }
        $dropdown.show();
        positionDropdown();
        $bell.attr('aria-expanded', 'true');
        // Already visible with the contents from the last render - now refresh them
        // and mark the new referrals as seen.
        loadBellData({ updateDate: true });
    }

    function closeDropdown() {
        if (!isOpen) {
            return;
        }
        isOpen = false;
        $dropdown.hide();
        if ($dropdown.data('wcusagePortal')) {
            $dropdown.removeData('wcusagePortal')
                .appendTo($container)
                .css({ left: '50%', top: '32px' });
        }
        $bell.attr('aria-expanded', 'false');
    }

    function positionDropdown() {
        var offset = $bell.offset();
        var width = $dropdown.outerWidth() || 300;
        // The panel is centred on the bell (translateX(-50%) in its own styles).
        var center = offset.left + ($bell.outerWidth() / 2);
        // Keep it inside the viewport on narrow screens.
        var scrollLeft = $(window).scrollLeft();
        var minCenter = scrollLeft + (width / 2) + 10;
        var maxCenter = scrollLeft + $(window).width() - (width / 2) - 10;
        if (maxCenter > minCenter) {
            center = Math.min(Math.max(center, minCenter), maxCenter);
        }
        $dropdown.css({
            position: 'absolute',
            left: Math.round(center),
            top: Math.round(offset.top + $bell.outerHeight()),
            zIndex: 99999
        });
    }

    /* -----------------------------------------------------------------------
     * Data
     * -------------------------------------------------------------------- */

    // Handle toggle notifications click (the link is re-rendered with the panel
    // contents, so this stays delegated).
    $(document).on('click', '#wcusage-toggle-notifications', function(e){
        e.preventDefault();
        var $link = $(this);
        if ($link.data('wcusageBusy')) {
            return;
        }
        $link.data('wcusageBusy', true).css('opacity', '0.5');
        $.post(wcusageAdminBell.ajax_url, {
            action: 'wcusage_toggle_admin_notifications',
            nonce: wcusageAdminBell.nonce
        }).always(function(){
            // Rebuild the panel for the new state, keeping it open.
            loadBellData({ updateDate: true });
        });
    });

    /**
     * @param {Object} options updateDate: also mark notifications as seen.
     */
    function loadBellData(options) {
        options = options || {};
        // Prevent overlapping background polls from stacking up if the server is
        // slow. User-driven requests (bell open / toggle) always run.
        if (bellRequestInFlight && !options.updateDate) {
            return;
        }
        bellRequestInFlight = true;
        var requestId = ++latestRequest;
        var data = {
            action: 'wcusage_admin_bell_data',
            nonce: wcusageAdminBell.nonce,
            // The panel contents come back only when they differ from what is
            // already on the page, so polls keep it current without sending the
            // same markup every time.
            known_hash: contentHash
        };
        if (options.updateDate) {
            data.update_date = '1';
        }
        $.post(wcusageAdminBell.ajax_url, data, function(response){
            if (!response || typeof response !== 'object') {
                return;
            }
            // A request that started earlier but finished later must not overwrite
            // fresher data (an on-load prefetch landing after the bell was opened).
            if (requestId !== latestRequest) {
                return;
            }
            updateCount(response.count);
            // Empty markup means "what you have is still current", so it is never
            // treated as contents to apply.
            if (typeof response.dropdown_html === 'string' && response.dropdown_html !== '') {
                $content.html(response.dropdown_html);
            }
            if (response.dropdown_hash) {
                contentHash = response.dropdown_hash;
            }
            // Switching notifications off stops the polling; switching them back on
            // starts it again.
            if (response.enabled == '1') {
                $bell.css('opacity', '1');
                startPeriodicCheck();
            } else {
                $bell.css('opacity', '0.5');
                stopPeriodicCheck();
            }
        }).always(function(){
            bellRequestInFlight = false;
        });
    }

    function updateCount(count) {
        currentNotificationCount = parseInt(count, 10) || 0;
        if (currentNotificationCount > 0) {
            $count.text(currentNotificationCount).show();
            startBellShake();
        } else {
            $count.hide();
            stopBellShake();
        }
        updateTabTitle();
    }

    function startPeriodicCheck() {
        if (bellCheckInterval) return; // Already running
        bellCheckInterval = setInterval(function(){
            // Refresh the count, and the panel contents if they have changed.
            loadBellData();
        }, pollInterval);
    }

    function stopPeriodicCheck() {
        if (bellCheckInterval) {
            clearInterval(bellCheckInterval);
            bellCheckInterval = null;
        }
    }

    /* -----------------------------------------------------------------------
     * Bell animation and tab title
     * -------------------------------------------------------------------- */

    function startBellShake() {
        if (bellShakeInterval) return; // Already shaking
        var $icon = $bell.find('.fa-bell');
        $icon.addClass('wcusage-bell-shake');
        bellShakeInterval = setInterval(function(){
            $icon.addClass('wcusage-bell-shake');
            setTimeout(function(){
                $icon.removeClass('wcusage-bell-shake');
            }, 500); // Animation duration
        }, 2000); // Every 2 seconds
    }

    function stopBellShake() {
        if (bellShakeInterval) {
            clearInterval(bellShakeInterval);
            bellShakeInterval = null;
        }
        $bell.find('.fa-bell').removeClass('wcusage-bell-shake');
    }

    function handleVisibilityChange() {
        if (document.hidden) {
            // Tab hidden: reflect the current notification count in the tab title
            updateTabTitle();
        } else {
            // Tab visible again: restore the original title and refresh the count
            // straight away rather than waiting for the next poll (but not when
            // polling is switched off, which means there is nothing to refresh).
            restoreTabTitle();
            if (bellCheckInterval) {
                loadBellData();
            }
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
