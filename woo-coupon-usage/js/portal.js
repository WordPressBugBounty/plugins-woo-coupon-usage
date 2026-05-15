/* Dark Mode */

jQuery(document).ready(function($) {
    const toggleButton = $('#dark-mode-toggle');
    const body = $('body');
    const savedTheme = localStorage.getItem('theme');
    const prefersDarkScheme = window.matchMedia("(prefers-color-scheme: dark)").matches;

    if (savedTheme === 'dark') {
        body.addClass('dark-mode');
        toggleButton.removeClass('fa-sun').addClass('fa-moon');
    } else {
        body.removeClass('dark-mode');
        toggleButton.removeClass('fa-moon').addClass('fa-sun');
    }

    toggleButton.on('click', function() {
        if (body.hasClass('dark-mode')) {
            body.removeClass('dark-mode');
            toggleButton.removeClass('fa-moon').addClass('fa-sun');
            localStorage.setItem('theme', 'light');
        } else {
            body.addClass('dark-mode');
            toggleButton.removeClass('fa-sun').addClass('fa-moon');
            localStorage.setItem('theme', 'dark');
        }
    });
});

/* Affiliate Dashboard */

window.wcusage_portal_open_tab = function wcusage_portal_open_tab(evt, tabName, contentId, postid, coupon_code, force_refresh_stats) {
    var tabcontent = document.getElementsByClassName("portal-tabcontent");
    for (var i = 0; i < tabcontent.length; i++) {
        tabcontent[i].className = tabcontent[i].className.replace(" active", "");
        tabcontent[i].style.display = "none";
    }

    // Remove .active class from all .portal-tablink buttons
    var tablinks = document.getElementsByClassName("portal-tablink");
    for (var i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }

    // Set .wcutabcontent to display block
    var wcutabcontent = document.getElementsByClassName("wcutabcontent");
    for (var i = 0; i < wcutabcontent.length; i++) {
        wcutabcontent[i].style.display = "block";
    }

    // Remove .active class from all .portal-tabcontent divs
    var wcutabcontent = document.getElementsByClassName("portal-tabcontent");
    for (var i = 0; i < wcutabcontent.length; i++) {
        wcutabcontent[i].style.display = "none";
        wcutabcontent[i].classList.remove("active");
    }

    var tabEl = document.getElementById(tabName);
    if (tabEl) {
        tabEl.style.display = "block";
        tabEl.className += " active";
    }
    var contentEl = document.getElementById(contentId);
    if (contentEl) {
        contentEl.style.display = "block";
        contentEl.className += " active";
    }
    if (evt && evt.currentTarget) {
        evt.currentTarget.className += " active";
    }

    // Close the sidebar on mobile after clicking a tab
    if (window.innerWidth <= 768) {
        jQuery('.sidebar').removeClass('active');
        jQuery('.hamburger-menu').removeClass('active');
    }
}

/* Hamburger Menu Toggle */

jQuery(document).ready(function($) {
    $('.hamburger-menu').on('click', function() {
        $(this).toggleClass('active');
        $('.sidebar').toggleClass('active');
    });

    // Close sidebar when clicking the explicit close button
    $(document).on('click', '.wcu-mobile-menu-close', function(e) {
        e.preventDefault();
        $('.sidebar').removeClass('active');
        $('.hamburger-menu').removeClass('active');
    });

    // Close sidebar when clicking outside on mobile
    $(document).on('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!$(e.target).closest('.sidebar').length && !$(e.target).closest('.hamburger-menu').length) {
                $('.sidebar').removeClass('active');
                $('.hamburger-menu').removeClass('active');
            }
        }
    });
});

/* Long coupon title handling (mobile header) */

jQuery(document).ready(function($) {
    const updateLongTitleClass = function() {
        const header = $('.content-header');
        if (!header.length) {
            return;
        }

        let titleText = '';
        const select = $('#wcu-coupon-select');
        if (select.length) {
            titleText = (select.find('option:selected').text() || '').trim();
        } else {
            const headerText = header.find('.welcome-header').text() || '';
            titleText = headerText.replace(/\s+/g, ' ').trim();
        }

        if (titleText.length > 9) {
            header.addClass('wcu-header-title-long');
        } else {
            header.removeClass('wcu-header-title-long');
        }
    };

    updateLongTitleClass();
    $(document).on('change', '#wcu-coupon-select', updateLongTitleClass);
    $(window).on('resize', updateLongTitleClass);
});

/* Profile */

jQuery(document).ready(function($) {
    $('.profile-trigger').on('click', function(e) {
        e.preventDefault();
        $('.dropdown-content').toggle();
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.profile-dropdown').length) {
            $('.dropdown-content').hide();
        }
    });
});

/* Mobile */

document.addEventListener('DOMContentLoaded', function() {
    var mlaSelectTab = document.getElementById('wcu-mla-select-tab');
    if (mlaSelectTab) {
        mlaSelectTab.addEventListener('change', function() {
            var tab = this.value;
            var tabElement = document.getElementById('tab-ml-' + tab);
            if (tabElement) {
                tabElement.click();
            }
        });
    }
});

/* MLA */

window.wl_wcuOpenTab = function wl_wcuOpenTab(evt, tabName) {

    // Remove .active class from all .ml_wcutabcontent divs
    var tabContents = document.getElementsByClassName("ml_wcutabcontent");
    for (var i = 0; i < tabContents.length; i++) {
        tabContents[i].style.display = "none";
        tabContents[i].classList.remove("active");
    }
    
    // Remove .active class from #ml-wcu4 settings tab
    var tabSettings = document.getElementById("ml-wcu4");
    if (tabSettings) {
        tabSettings.style.display = "none";
        tabSettings.classList.remove("active");
    }

    // Remove .active class from all .ml_wcutablinks buttons
    var tabLinks = document.getElementsByClassName("ml_wcutablinks");
    for (var i = 0; i < tabLinks.length; i++) {
        tabLinks[i].classList.remove("active");
    }

    // Show the selected tab content and add active class
    var selectedTab = document.getElementById(tabName);
    if (selectedTab) {
        selectedTab.style.display = "block";
        selectedTab.className += " active";
    }

    // Add active class to the clicked tab link
    if (evt.currentTarget) {
        evt.currentTarget.className += " active";
    }

    // Close the sidebar on mobile after clicking a tab
    if (window.innerWidth <= 768) {
        jQuery('.sidebar').removeClass('active');
        jQuery('.hamburger-menu').removeClass('active');
    }
}

// Ensure the first tab is opened on page load
jQuery(document).ready(function($) {
    var firstTab = $('.ml-wcutabfirst');
    if (firstTab.length) {
        console.log('First tab found:', firstTab.attr('id'));
        firstTab.click();
    } else {
        console.error('No first tab found with class ml-wcutabfirst');
    }
});

// Hamburger Menu Toggle
jQuery(document).ready(function($) {
    $('.hamburger-menu').on('click', function() {
        $(this).toggleClass('active');
        $('.sidebar').toggleClass('active');
        console.log('Hamburger menu toggled. Sidebar active:', $('.sidebar').hasClass('active'));
    });

    // Close sidebar when clicking outside on mobile
    $(document).on('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!$(e.target).closest('.sidebar').length && !$(e.target).closest('.hamburger-menu').length) {
                $('.sidebar').removeClass('active');
                $('.hamburger-menu').removeClass('active');
                console.log('Clicked outside. Sidebar active:', $('.sidebar').hasClass('active'));
            }
        }
    });
});