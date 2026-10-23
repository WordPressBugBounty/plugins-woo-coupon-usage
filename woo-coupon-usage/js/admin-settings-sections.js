/**
 * Settings page: turns the <hr> divided sections inside each settings tab into separate
 * cards (css/admin-settings-sections.css).
 *
 * The option files mark sections with an <hr> followed by an <h3>, and show/hide or
 * PRO-only wrappers often open inside one section and close after the next divider, so
 * the grouping is done here rather than in the markup. Existing nodes are moved, never
 * copied, so their IDs, event handlers and toggles keep working; the only copies made
 * are empty ones of class-toggled wrappers (see shellOf).
 */
(function () {
  'use strict';

  // Containers that can be split into cards. Anything else (tables, lists, paragraphs)
  // keeps its <hr> as a plain divider.
  var SPLITTABLE = { DIV: 1, SPAN: 1, SECTION: 1 };

  // Elements the spacing pass looks inside to find a card's first and last content.
  var SEE_THROUGH = { DIV: 1, SPAN: 1, SECTION: 1, P: 1 };

  var CONTROLS = 'input:not([type="hidden"]), select, textarea, button, .switch';

  // Nodes that are only spacing (or invisible) and do not make a card on their own.
  function isFiller(node) {
    if (node.nodeType === 3) {
      return !node.textContent.trim();
    }
    if (node.nodeType !== 1) {
      return true;
    }
    var tag = node.tagName;
    return tag === 'BR' || tag === 'SCRIPT' || tag === 'STYLE' ||
      ( tag === 'INPUT' && node.type === 'hidden' );
  }

  function isBreak(node) {
    return node.nodeType === 1 && node.tagName === 'HR';
  }

  // A wrapper that holds sections of its own (has a divider somewhere inside).
  function holdsSections(node) {
    return node.nodeType === 1 && !!SPLITTABLE[node.tagName] && !!node.querySelector('hr');
  }

  function hasDirectHr(el) {
    for (var i = 0; i < el.children.length; i++) {
      if (isBreak(el.children[i])) {
        return true;
      }
    }
    return false;
  }

  // Hidden by a show/hide toggle (jQuery sets an inline display: none).
  function isHidden(node) {
    return node.nodeType === 1 && node.style && node.style.display === 'none';
  }

  function firstContent(el, skipHidden) {
    for (var i = 0; i < el.childNodes.length; i++) {
      var n = el.childNodes[i];
      if (!isFiller(n) && !(skipHidden && isHidden(n))) {
        return n;
      }
    }
    return null;
  }

  // The heading that opens a run of nodes, looking inside wrappers.
  function openingHeading(first, skipHidden) {
    for (var depth = 0; first && first.nodeType === 1 && SPLITTABLE[first.tagName] && depth < 2; depth++) {
      var style = window.getComputedStyle(first);
      if (parseFloat(style.paddingTop) || parseFloat(style.marginTop) || parseFloat(style.borderTopWidth)) {
        return null;
      }
      first = firstContent(first, skipHidden);
    }
    return first && first.nodeType === 1 && first.tagName === 'H3' ? first : null;
  }

  function hasControls(nodes) {
    return nodes.some(function (n) {
      return n.nodeType === 1 && ( n.matches(CONTROLS) || !!n.querySelector(CONTROLS) );
    });
  }

  function hideFillers(nodes) {
    nodes.forEach(function (n) {
      if (n.nodeType === 1 && n.tagName === 'BR') {
        n.classList.add('wcu-settings-card-break');
      }
    });
  }

  // "Commission Amounts:" -> "Commission Amounts".
  function stripColon(heading) {
    var lastText = null;
    for (var i = 0; i < heading.childNodes.length; i++) {
      var n = heading.childNodes[i];
      if (n.nodeType === 3 && n.textContent.trim()) {
        lastText = n;
      }
    }
    if (lastText) {
      lastText.textContent = lastText.textContent.replace(/\s*:\s*$/, ' ');
    }
  }

  // Every section heading uses the same gear icon; give each one an icon that matches
  // its title (first match wins), keeping the gear when nothing fits.
  var HEADING_ICONS = [
    [/uninstall/, 'trash'],
    [/\bsms\b|mobile/, 'smartphone'],
    [/email|notification/, 'email-alt'],
    [/\bapi\b|credential/, 'admin-network'],
    [/captcha|spam|blacklist|restriction|fraud/, 'shield'],
    [/permission/, 'lock'],
    [/privacy|cookie|session/, 'privacy'],
    [/performance/, 'performance'],
    [/activity log|history/, 'backup'],
    [/invoice|statement|pdf/, 'media-document'],
    [/payout|payment/, 'bank'],
    [/currency|conversion rate/, 'admin-site-alt3'],
    [/tax|calculation/, 'calculator'],
    [/bonus|reward/, 'awards'],
    [/\bmla\b|multi-level|tier/, 'networking'],
    [/commission/, 'money-alt'],
    [/order|sales|checkout|woocommerce/, 'cart'],
    [/\bqr\b/, 'grid-view'],
    [/social|sharing/, 'share'],
    [/creative/, 'format-image'],
    [/landing page/, 'admin-page'],
    [/popup|bar notice/, 'megaphone'],
    [/colou?r|font|design|dark mode/, 'art'],
    [/css/, 'editor-code'],
    [/translation|terminology/, 'translation'],
    [/custom text|wording/, 'editor-paste-text'],
    [/tracking|conversion|click|attribution|campaign/, 'chart-line'],
    [/menu/, 'menu-alt'],
    [/link|url/, 'admin-links'],
    [/export|excel/, 'download'],
    [/widget|button/, 'button'],
    [/\btabs?\b/, 'menu-alt'],
    [/mailing list|integration/, 'megaphone'],
    [/terms/, 'clipboard'],
    [/accept/, 'yes-alt'],
    [/user role|profile|user account|new customer|users?\b/, 'admin-users'],
    [/registration|form|field|benefit/, 'forms'],
    [/coupon|code/, 'tickets-alt'],
    [/schedul|automatic|auto-|unpaid|processing/, 'clock'],
    [/lifetime/, 'update'],
    [/priority/, 'sort'],
    [/visibility|display/, 'visibility'],
    [/portal|dashboard|header/, 'dashboard']
  ];

  function headingIcon(icon, heading) {
    if (!icon.classList.contains('dashicons-admin-generic')) {
      return;
    }
    // The heading's own words, not those of an info tooltip inside it.
    var title = '';
    for (var j = 0; j < heading.childNodes.length; j++) {
      var n = heading.childNodes[j];
      if (n.nodeType === 3 || (n.nodeType === 1 && !n.classList.contains('wcu-setting-info'))) {
        title += n.textContent;
      }
    }
    title = title.toLowerCase();
    for (var i = 0; i < HEADING_ICONS.length; i++) {
      if (HEADING_ICONS[i][0].test(title)) {
        icon.classList.remove('dashicons-admin-generic');
        icon.classList.add('dashicons-' + HEADING_ICONS[i][1]);
        return;
      }
    }
  }

  // Section headings are the ones that open with a dashicon; other h3s (such as the
  // portal design panels) keep their own styling.
  function markHeadings(card) {
    var headings = card.querySelectorAll('h3');
    for (var h = 0; h < headings.length; h++) {
      var icon = firstContent(headings[h]);
      if (icon && icon.nodeType === 1 && icon.classList.contains('dashicons')) {
        headings[h].classList.add('wcu-settings-card-heading');
        stripColon(headings[h]);
        headingIcon(icon, headings[h]);
      }
    }
  }

  /* -----------------------------------------------------------------------
   * Spacing. Run on every card at load and again when a toggle shows or hides part
   * of it: marks the heading that opens the card as its title, hides line breaks and
   * dividers at its top and bottom, and clears the outer margins of its first and last
   * content so every card has the same padding.
   * -------------------------------------------------------------------- */

  function edgeWalk(container, fromEnd, depth) {
    var kids = container.childNodes;
    for (var k = 0; k < kids.length; k++) {
      var n = kids[fromEnd ? kids.length - 1 - k : k];

      if (n.nodeType === 3) {
        if (n.textContent.trim()) {
          return true;
        }
        continue;
      }
      if (n.nodeType !== 1) {
        continue;
      }

      var tag = n.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || ( tag === 'INPUT' && n.type === 'hidden' )) {
        continue;
      }
      if (tag === 'BR' || tag === 'HR') {
        n.classList.add('wcu-card-edge');
        continue;
      }

      var style = window.getComputedStyle(n);
      if (style.display === 'none') {
        continue;
      }
      if (n.classList.contains('wcu-settings-card-title')) {
        if (fromEnd) {
          return true;
        }
        continue;
      }

      var edgeClass = fromEnd ? 'wcu-card-last' : 'wcu-card-first';
      n.classList.add(edgeClass);

      var boxed = fromEnd
        ? parseFloat(style.paddingBottom) || parseFloat(style.borderBottomWidth)
        : parseFloat(style.paddingTop) || parseFloat(style.borderTopWidth);
      if (!SEE_THROUGH[tag] || boxed || depth >= 4) {
        return true;
      }
      if (edgeWalk(n, fromEnd, depth + 1)) {
        return true;
      }
      // Nothing visible inside (an empty paragraph, or a placeholder filled in later):
      // drop its margins and look at the next one along.
      n.classList.remove(edgeClass);
      n.classList.add('wcu-card-void');
    }
    return false;
  }

  /* Line breaks that only add empty lines. Where an option used to be followed by a
   * line of description (now an info icon), the breaks around it are left on their own
   * and stack up into a tall gap. A run of breaks keeps at most one empty line. */

  var BLOCK_DISPLAY = /^(block|flex|grid|table|list-item|flow-root)$/;

  // What a line break follows on screen, looking backwards past invisible nodes and into
  // inline wrappers: 'br', 'block' (it starts a new line after a block or at the top of
  // one), 'inline' (it ends a line of text or controls) or null (nothing yet).
  function lastRendered(el) {
    for (var k = el.childNodes.length - 1; k >= 0; k--) {
      var kind = renderedKind(el.childNodes[k]);
      if (kind) {
        return kind;
      }
    }
    return null;
  }

  function renderedKind(n) {
    if (n.nodeType === 3) {
      return n.textContent.trim() ? 'inline' : null;
    }
    if (n.nodeType !== 1) {
      return null;
    }
    var tag = n.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE' || ( tag === 'INPUT' && n.type === 'hidden' )) {
      return null;
    }
    var style = window.getComputedStyle(n);
    if (style.display === 'none') {
      return null;
    }
    if (tag === 'BR') {
      return 'br';
    }
    if (BLOCK_DISPLAY.test(style.display)) {
      return 'block';
    }
    if (style.display !== 'inline' || /^(INPUT|SELECT|TEXTAREA|BUTTON|IMG|IFRAME|CANVAS|SVG)$/.test(tag)) {
      return 'inline';
    }
    // An inline wrapper: whatever it ends with (show/hide spans often hold whole options).
    return lastRendered(n);
  }

  function before(br) {
    var n = br;
    while (n) {
      for (var p = n.previousSibling; p; p = p.previousSibling) {
        var kind = renderedKind(p);
        if (kind) {
          return kind;
        }
      }
      n = n.parentNode;
      if (!n || n.classList.contains('wcu-settings-card')) {
        return 'block';
      }
      if (BLOCK_DISPLAY.test(window.getComputedStyle(n).display)) {
        return 'block';
      }
    }
    return 'block';
  }

  // Shown within its card. Not getClientRects(): cards in the tabs that are not open
  // have no layout, but are still tidied at load.
  function shownInCard(el, card) {
    for (var n = el; n && n !== card; n = n.parentNode) {
      if (window.getComputedStyle(n).display === 'none') {
        return false;
      }
    }
    return true;
  }

  // The first thing shown inside an inline wrapper, other than line breaks.
  function firstShown(el) {
    for (var k = 0; k < el.childNodes.length; k++) {
      var found = shownNode(el.childNodes[k]);
      if (found && !( found.nodeType === 1 && found.tagName === 'BR' )) {
        return found;
      }
    }
    return null;
  }

  function shownNode(n) {
    if (n.nodeType === 3) {
      return n.textContent.trim() ? n : null;
    }
    if (n.nodeType !== 1 || n.tagName === 'SCRIPT' || n.tagName === 'STYLE' || ( n.tagName === 'INPUT' && n.type === 'hidden' )) {
      return null;
    }
    var display = window.getComputedStyle(n).display;
    if (display === 'none') {
      return null;
    }
    if (display === 'inline' && n.tagName !== 'BR' && n.childNodes.length) {
      return firstShown(n) || null;
    }
    return n;
  }

  // What follows a run of breaks (the breaks themselves are skipped).
  function after(br) {
    for (var n = br; n && !n.classList.contains('wcu-settings-card'); n = n.parentNode) {
      for (var s = n.nextSibling; s; s = s.nextSibling) {
        var found = shownNode(s);
        if (found && found.nodeType === 1 && found.tagName === 'BR') {
          continue;
        }
        if (found) {
          return found;
        }
      }
      if (n !== br && BLOCK_DISPLAY.test(window.getComputedStyle(n).display)) {
        return null;
      }
    }
    return null;
  }

  function collapseBreaks(card) {
    var breaks = card.querySelectorAll('br');
    var emptyLines = 0;
    for (var b = 0; b < breaks.length; b++) {
      var br = breaks[b];
      if (!shownInCard(br, card)) {
        continue;
      }
      var kind = before(br);
      if (kind === 'inline') {
        // Ends a line of content; a fresh run of empty lines can follow it.
        emptyLines = 0;
        continue;
      }
      if (kind === 'block') {
        emptyLines = 0;
      }
      emptyLines++;
      // A divider or heading next brings its own margin, so no empty line before it.
      var next = after(br);
      if (emptyLines > 1 || ( next && next.nodeType === 1 && /^(HR|H1|H2|H3|H4)$/.test(next.tagName) )) {
        br.classList.add('wcu-card-extra-br');
      }
    }
  }

  function updateTitle(card) {
    var current = card.querySelectorAll('.wcu-settings-card-title');
    for (var c = 0; c < current.length; c++) {
      current[c].classList.remove('wcu-settings-card-title');
    }
    var title = openingHeading(firstContent(card, true), true);
    if (title) {
      title.classList.add('wcu-settings-card-title');
    }
    card.classList.toggle('wcu-settings-card-has-title', !!title);
    return title;
  }

  function refreshCard(card) {
    var marked = card.querySelectorAll('.wcu-card-edge, .wcu-card-first, .wcu-card-last, .wcu-card-void, .wcu-card-extra-br');
    for (var m = 0; m < marked.length; m++) {
      marked[m].classList.remove('wcu-card-edge', 'wcu-card-first', 'wcu-card-last', 'wcu-card-void', 'wcu-card-extra-br');
    }

    var title = updateTitle(card);
    var hasContent = edgeWalk(card, false, 0);
    edgeWalk(card, true, 0);
    collapseBreaks(card);

    // Everything in it is switched off.
    card.classList.toggle('wcu-settings-card-empty', !hasContent && !title);
  }

  // Show/hide toggles change what is visible inside a card.
  function watchToggles(form) {
    if (!window.MutationObserver) {
      return;
    }
    var pending = [];
    var scheduled = false;
    new MutationObserver(function (records) {
      records.forEach(function (r) {
        // Each auto-save toggles pointer-events on every control and the cursor on
        // every label, which is not a show/hide: skip style changes that touched
        // nothing else.
        var stripPe = function (s) { return (s || '').replace(/(pointer-events|cursor)\s*:[^;]*;?/gi, '').replace(/\s+/g, ''); };
        if (stripPe(r.oldValue) === stripPe(r.target.getAttribute('style'))) {
          return;
        }
        var card = r.target.closest && r.target.closest('.wcu-settings-card');
        if (card && pending.indexOf(card) === -1) {
          pending.push(card);
        }
      });
      if (pending.length && !scheduled) {
        scheduled = true;
        // A timeout rather than requestAnimationFrame, which never runs in a background tab.
        window.setTimeout(function () {
          pending.forEach(refreshCard);
          pending = [];
          scheduled = false;
        }, 30);
      }
    }).observe(form, { attributes: true, attributeFilter: ['style'], attributeOldValue: true, subtree: true });
  }

  /* -----------------------------------------------------------------------
   * Grouping.
   * -------------------------------------------------------------------- */

  // A copy of a wrapper (without its ID or our markers) to carry part of its content
  // elsewhere. It keeps the class the toggles look for, so both parts show and hide
  // together.
  function shellOf(wrapper) {
    var shell = wrapper.cloneNode(false);
    shell.removeAttribute('data-wcu-cards');
    shell.classList.remove('wcu-settings-cards', 'wcu-settings-card-group');
    shell.classList.add('wcu-settings-split');
    return shell;
  }

  // Where content of the container goes to join the card: the card itself, or (when the
  // card sits outside the container) a copy of each wrapper in between, nested inside
  // the card. Null if a wrapper in between is toggled by its ID, which a copy can't share.
  function bridge(container, card) {
    if (container.contains(card)) {
      return card;
    }
    var chain = [];
    for (var el = container; el && !el.contains(card); el = el.parentNode) {
      if (el.id) {
        return null;
      }
      chain.push(el);
    }
    var inner = null;
    var outer = null;
    chain.forEach(function (wrapper) {
      var shell = shellOf(wrapper);
      if (outer) {
        shell.appendChild(outer);
      } else {
        inner = shell;
      }
      outer = shell;
    });
    card.appendChild(outer);
    return inner;
  }

  // prevCard: the card just before this container, which untitled content at the start
  // of the container carries on from.
  function group(container, tab, prevCard) {
    if (container.getAttribute('data-wcu-cards')) {
      return;
    }

    if (!hasDirectHr(container)) {
      // Pass through to any wrapper that holds sections of its own.
      Array.prototype.slice.call(container.children).forEach(function (child) {
        if (holdsSections(child)) {
          group(child, tab, null);
        }
      });
      return;
    }

    container.setAttribute('data-wcu-cards', '1');
    container.classList.add('wcu-settings-cards');

    var run = [];
    var lastCard = prevCard || null;

    function flush(before) {
      var nodes = run;
      run = [];

      var meaningful = nodes.filter(function (n) { return !isFiller(n); });
      if (!meaningful.length) {
        hideFillers(nodes);
        return;
      }

      // The tab's own title and description stay above the cards.
      if (meaningful[0].nodeType === 1 && /^H[12]$/.test(meaningful[0].tagName)) {
        var lastHeader = meaningful[meaningful.length - 1];
        hideFillers(nodes.slice(nodes.indexOf(lastHeader) + 1));
        meaningful.forEach(function (n) {
          if (n.nodeType === 1 && n.tagName === 'P') {
            n.classList.add('wcu-settings-header-text');
          }
        });
        if (lastHeader.nodeType === 1) {
          lastHeader.classList.add('wcu-settings-header-end');
        }
        lastCard = null;
        return;
      }

      var titled = !!openingHeading(meaningful[0], false);

      // Untitled text before the first card is more of the tab's description.
      if (!tab.cards && !titled && !hasControls(meaningful)) {
        var intro = document.createElement('div');
        intro.className = 'wcu-settings-intro';
        container.insertBefore(intro, before);
        nodes.forEach(function (n) { intro.appendChild(n); });
        hideFillers(nodes.slice(0, nodes.indexOf(meaningful[0])));
        hideFillers(nodes.slice(nodes.indexOf(meaningful[meaningful.length - 1]) + 1));
        var lastIntro = meaningful[meaningful.length - 1];
        if (lastIntro.nodeType === 1) {
          // Line breaks closing its last paragraph.
          for (var t = lastIntro.lastChild; t && isFiller(t); t = t.previousSibling) {
            if (t.nodeType === 1 && t.tagName === 'BR') {
              t.classList.add('wcu-settings-card-break');
            }
          }
        }
        lastCard = null;
        return;
      }

      // Untitled content straight after a card carries on that card's section. When
      // that card sits outside this wrapper, the content goes there in a copy of it.
      var target = !titled && lastCard ? bridge(container, lastCard) : null;
      if (target) {
        var divider = document.createElement('hr');
        divider.className = 'wcu-settings-card-divider';
        target.appendChild(divider);
        nodes.forEach(function (n) { target.appendChild(n); });
        return;
      }

      var card = document.createElement('div');
      card.className = 'wcu-settings-card';
      container.insertBefore(card, before);
      nodes.forEach(function (n) { card.appendChild(n); });
      tab.cards++;
      lastCard = card;
    }

    Array.prototype.slice.call(container.childNodes).forEach(function (child) {
      if (isBreak(child)) {
        flush(child);
        child.classList.add('wcu-settings-card-break');
        return;
      }

      if (holdsSections(child)) {
        // A wrapper that opens part way through a section (a show/hide or PRO-only
        // span closing after the next divider): its content up to that divider belongs
        // to the current section, so it moves there inside a copy of the wrapper.
        // Wrappers toggled by their ID are left whole.
        var lead = [];
        for (var c = child.firstChild; c && !isBreak(c) && !holdsSections(c); c = c.nextSibling) {
          lead.push(c);
        }
        if (!child.id && lead.some(function (n) { return !isFiller(n); })) {
          var shell = shellOf(child);
          lead.forEach(function (n) { shell.appendChild(n); });
          container.insertBefore(shell, child);
          run.push(shell);
        }

        flush(child);
        child.classList.add('wcu-settings-card-group');
        group(child, tab, lastCard);
        lastCard = null;
        return;
      }

      run.push(child);
    });

    flush(null);
  }

  // Some sections start with a heading but no divider before it (the email groups, the
  // popup types). A divider is added before every section heading (one that opens with
  // a dashicon); where there already was one, the empty run between them is ignored.
  function addSectionBreaks(form) {
    var headings = form.querySelectorAll('.wcusage_row h3');
    for (var i = 0; i < headings.length; i++) {
      var h = headings[i];
      var icon = firstContent(h);
      var parent = h.parentNode;
      if (icon && icon.nodeType === 1 && icon.classList.contains('dashicons') &&
        ( SPLITTABLE[parent.tagName] || parent.tagName === 'TD' )) {
        var hr = document.createElement('hr');
        hr.className = 'wcu-settings-auto-break';
        parent.insertBefore(hr, h);
      }
    }
  }

  // Help text typed as "- Sentence" or "• Sentence" paragraphs: the typed marker is
  // dropped and the paragraph gets a drawn bullet instead (.wcu-help-bullet).
  var TYPED_BULLET = /^\s*[-•]\s+/;

  function markHelpBullets(form) {
    var paras = form.querySelectorAll('.wcusage_row p');
    for (var i = 0; i < paras.length; i++) {
      var text = paras[i].firstChild;
      while (text && text.nodeType === 3 && !text.nodeValue.trim()) {
        text = text.nextSibling;
      }
      if (text && text.nodeType === 3 && TYPED_BULLET.test(text.nodeValue)) {
        text.nodeValue = text.nodeValue.replace(TYPED_BULLET, '');
        paras[i].classList.add('wcu-help-bullet');
      }
    }
  }

  function init() {
    var form = document.querySelector('.wcusage-settings-form');
    if (!form) {
      return;
    }

    markHelpBullets(form);
    addSectionBreaks(form);

    // Each tab is a table row; not every tab wraps its content in a .settings-area.
    var tabs = form.querySelectorAll('.wcusage_row > td');
    for (var i = 0; i < tabs.length; i++) {
      group(tabs[i], { cards: 0 });
    }

    var cards = form.querySelectorAll('.wcu-settings-card');
    for (var j = 0; j < cards.length; j++) {
      markHeadings(cards[j]);
      refreshCard(cards[j]);
    }

    form.classList.add('wcu-cards-ready');
    watchToggles(form);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
