/*
 * Affiliate portal themes (every theme except Classic).
 *
 * Small markup tidy-ups the stylesheets cannot do on their own:
 * - Headings, statistic labels and form labels end in a colon ("Referred Orders:",
 *   "Total Sales:", "Sort Code:"). The themes style these as titles, captions and field
 *   labels, where the colon reads oddly, so it is trimmed.
 * - The first title in each tab is marked as the page title, so every tab's title gets the
 *   same size and spacing whatever comes before it in the markup.
 * - The payout settings use plain paragraphs as field labels. Those are marked so they can
 *   be styled like the other labels in the Settings tab.
 * Tab content loads over AJAX, hence the re-run after requests.
 */
(function ($) {
    'use strict';

    var trimSelector = [
        '.wcu-tab-title',
        '.wcusage-subheader',
        '.show_statistics .wcusage-stats-title',
        '.wcusage-info-box-title',
        '#wcu6 label',
        '.referral-url-box > p > strong',
        '.wcu-campaigns-col1 strong',
        '.wcu-campaigns-col2 strong'
    ].join(', ');

    function lastTextNode(el) {
        var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
        var last = null;
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue.trim() !== '') {
                last = node;
            }
        }
        return last;
    }

    function trimTrailingColon(el) {
        var last = lastTextNode(el);
        if (last && /:\s*$/.test(last.nodeValue)) {
            last.nodeValue = last.nodeValue.replace(/\s*:\s*$/, '');
        }
    }

    function markPageTitles() {
        var tabs = document.querySelectorAll('.portal-tabcontent > .wcutabcontent, .wcutabcontent.portal-tabcontent');
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].querySelector('.wcu-pt-page-title')) {
                continue;
            }
            var title = tabs[i].querySelector('.wcu-tab-title:not(.wcusage-subheader)');
            if (!title) {
                continue;
            }
            // Only a title that opens the tab: some tabs print none, and then the first match
            // is a heading further down (a creatives category, say), which must stay as it is.
            var before = tabs[i].querySelector('.wcusage-subheader, input:not([type="hidden"]), select, table, .creatives-box');
            if (before && (before.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                continue;
            }
            title.classList.add('wcu-pt-page-title');
        }
    }

    function markPayoutLabels() {
        var paras = document.querySelectorAll('#wcu6 .wcu-settings-card__body p:not([class])');
        for (var i = 0; i < paras.length; i++) {
            var p = paras[i];
            if (p.children.length === 0 && p.textContent.length < 48 && /:\s*$/.test(p.textContent)) {
                p.classList.add('wcu-pt-label');
                trimTrailingColon(p);
            }
        }
    }

    // Status words and the badge tone they get. Anything else gets a neutral badge.
    var statusTones = {
        good: ['completed', 'complete', 'paid', 'active', 'approved', 'accepted'],
        warn: ['processing', 'pending', 'pending payment', 'pending approval', 'on-hold', 'on hold', 'created', 'unpaid', 'awaiting'],
        bad: ['cancelled', 'canceled', 'refunded', 'failed', 'rejected', 'declined', 'expired', 'trash']
    };

    function statusTone(text) {
        var t = text.trim().toLowerCase();
        // Translated status names ("Abgeschlossen") map back to their slug ("completed").
        var names = window.wcusagePortalTheme && window.wcusagePortalTheme.statusNames;
        if (names && Object.prototype.hasOwnProperty.call(names, t)) {
            t = names[t];
        }
        for (var tone in statusTones) {
            if (statusTones[tone].indexOf(t) !== -1) {
                return tone;
            }
        }
        return 'neutral';
    }

    // Statuses in the tables (orders, payouts) become small badges: the Status column is
    // found by its heading, and only plain-text cells are wrapped.
    function markStatuses() {
        var label = (window.wcusagePortalTheme && window.wcusagePortalTheme.statusLabel) || 'Status';
        label = label.trim().toLowerCase();
        var tables = document.querySelectorAll('table.wcuTable');
        for (var i = 0; i < tables.length; i++) {
            var heads = tables[i].querySelectorAll('tr:first-child > .wcuTableHead, thead .wcuTableHead');
            var col = -1;
            for (var h = 0; h < heads.length; h++) {
                if (heads[h].textContent.trim().replace(/:$/, '').toLowerCase() === label) {
                    col = Array.prototype.indexOf.call(heads[h].parentElement.children, heads[h]);
                    break;
                }
            }
            if (col < 0) {
                continue;
            }
            var rows = tables[i].querySelectorAll('tr.wcuTableRow');
            for (var r = 0; r < rows.length; r++) {
                var cell = rows[r].children[col];
                if (!cell || cell.classList.contains('wcuTableHead') || cell.querySelector('.wcu-pt-status') || cell.children.length || !cell.textContent.trim()) {
                    continue;
                }
                var text = cell.textContent.trim();
                var badge = document.createElement('span');
                badge.className = 'wcu-pt-status wcu-pt-status--' + statusTone(text);
                badge.textContent = text;
                cell.textContent = '';
                cell.appendChild(badge);
            }
        }
    }

    // "Unpaid Commission: <u>amount</u>" and "Payout Amount: <input>" are styled as labels
    // above what follows them: drop the colon at the end of the label text.
    function tidyPayoutBalance() {
        var titles = document.querySelectorAll('.payouts-box > .wcu-tab-title, .payouts-box .wcu_payout_button > p');
        for (var i = 0; i < titles.length; i++) {
            var node = titles[i].firstChild;
            while (node && node.nodeType !== 3) {
                node = node.nextSibling;
            }
            if (node && /:\s*$/.test(node.nodeValue)) {
                node.nodeValue = node.nodeValue.replace(/\s*:\s*$/, ' ');
            }
        }
    }

    // The themes show the referral link's QR code straight away, beside the link, instead
    // of behind a "Generate" button. Clicking it once does exactly what a visitor's click
    // would (the generator then keeps the code in step with campaign and URL changes).
    function showReferralQr() {
        var button = document.querySelector('.referral-url-box .wcusage_landing_qr_show');
        if (!button || button.getAttribute('data-wcu-pt-qr') === '1') {
            return;
        }
        button.setAttribute('data-wcu-pt-qr', '1');
        button.click();

        // The themes show Download as an icon: its own text becomes the tooltip.
        var download = document.querySelector('.referral-url-box #wcu-download-qr');
        if (download && !download.getAttribute('title')) {
            download.setAttribute('title', download.textContent.trim());
        }
    }

    function tidy() {
        // After every ready handler has run, so the generator's click handler exists.
        setTimeout(showReferralQr, 0);
        tidyPayoutBalance();
        markStatuses();
        markPayoutLabels();
        var els = document.querySelectorAll(trimSelector);
        for (var i = 0; i < els.length; i++) {
            trimTrailingColon(els[i]);
        }
        markPageTitles();
    }

    $(tidy);
    $(document).ajaxComplete(function () {
        // Let the response's own handlers insert their markup first.
        setTimeout(tidy, 0);
    });
})(jQuery);
