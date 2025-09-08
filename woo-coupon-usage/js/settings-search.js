jQuery(function($){
  var $search = $('#wcu-settings-search');
  if(!$search.length) return;
  var $wrap = $('#wcu-settings-search-right');
  var $resultsWrap = $('#wcu-settings-search-results');
  var $resultsList = $('#wcu-settings-search-results ul');
  var $empty = $('#wcu-settings-search-empty');

  // Close results on outside click
  $(document).on('click', function(e){
    if($(e.target).closest('#wcu-settings-search-right').length === 0){
      $resultsWrap.hide();
    }
  });

  // Map row class -> tab info
  var tabMap = {};
  $('.nav-tab[id^="tab-"]').each(function(){
    var $tab = $(this);
    var tabId = $tab.attr('id');
    var rowKey = tabId.replace('tab-','').replace(/-/g,'_');
    var rowClass = 'wcusage_row_' + rowKey;
    tabMap[rowClass] = { id: '#'+tabId, $el: $tab, title: $.trim($tab.text()) };
  });

  // Unique ID generator for headings to avoid collisions across searches
  var wcuHeadingAutoSeq = 0;
  function ensureHeadingId($h){
    var hid = $h.attr('id');
    if(hid){
      // If this ID is duplicated or points to a different element, reassign a unique one
      var isThis = document.getElementById(hid) === $h[0];
      var dupCount = jQuery('[id="'+hid.replace(/"/g,'\\"')+'"]').length; // count elements with same id
      if(isThis && dupCount === 1){
        return hid;
      }
    }
    var candidate;
    do {
      candidate = 'wcu_heading_auto_' + (wcuHeadingAutoSeq++);
    } while(document.getElementById(candidate));
    $h.attr('id', candidate);
    return candidate;
  }

  // Helper: open any collapsed Show/Hide ancestors for a given element
  function openShowhideAncestors($elem){
    var ancestors = $elem.parents().filter(function(){
      var id = this.id;
      if(!id) return false;
      var btnSelector = '#wcu_show_' + id.replace(/^wcu_/, '');
      return jQuery(this).is(':hidden') && jQuery(btnSelector).length > 0;
    });
    jQuery(ancestors.get().reverse()).each(function(){
      var id = this.id;
      var btnSelector = '#wcu_show_' + id.replace(/^wcu_/, '');
      var $btn = jQuery(btnSelector);
      if($btn.length && jQuery(this).is(':hidden')){
        $btn.trigger('click');
      } else if(jQuery(this).is(':hidden')) {
        jQuery(this).show();
      }
    });
  }

  function render(matches){
    $resultsList.empty();
    if(!matches.length){
      $resultsWrap.hide();
      $empty.show();
      return;
    }
    $empty.hide();
    $.each(matches, function(i, m){
      var tabTitle = m.tab ? m.tab.title : 'Other';
      var $li = $('<li/>');
      var $info = $('<div/>').append(
        $('<div/>', { 'class': 'wcu-search-item-label', text: m.label })
      ).append(
        $('<div/>', { 'class': 'wcu-search-item-meta', text: tabTitle + ' • ' + (m.type === 'heading' ? 'Section' : ('#' + m.fieldId)) })
      );
      var $btn = $('<button/>', { 'class': 'button button-primary wcu-search-jump', text: 'Go to settings' });
      $btn.on('click', function(ev){
        ev.preventDefault();
        var $target = $('#'+m.pid);
        if($target.length){
          var $row = $target.closest('.wcusage_row');
          $('.nav-tab').removeClass('active');
          if(m.tab && m.tab.$el && m.tab.$el.length){ m.tab.$el.addClass('active'); }
          $('.wcusage_row').hide();
          $row.show();
          var $scrollTarget = $target;

          if(m.type === 'heading'){
            // If we know the linked section, open it and prefer scrolling to it
            if(m.sectionId){
              var $section = jQuery('#'+m.sectionId);
              if($section.length){
                // Open the section if hidden via its matching show button
                if($section.is(':hidden')){
                  var btnSelector = '#wcu_show_' + m.sectionId.replace(/^wcu_/, '');
                  var $btn = jQuery(btnSelector);
                  if($btn.length){ $btn.trigger('click'); }
                }
                // Open any hidden ancestors too
                openShowhideAncestors($section);
                $scrollTarget = $section;
              }
            } else {
              // No explicit section container; still ensure ancestors of heading are visible
              openShowhideAncestors($target);
            }
          } else {
            // For field/option targets, ensure hidden ancestors are opened
            openShowhideAncestors($target);
          }

          // If the target or chosen section is still hidden due to a parent conditional setting,
          // find the controlling option (checkbox/toggle) and scroll to that instead.
          function findConditionalController($elem){
            var $gatingInput = null;
            var $gatingP = null;
            var $r = $elem.closest('.wcusage_row');
            $elem.parents().each(function(){
              var cls = this.className || '';
              var match = cls.match(/wcu-field-section-([a-z0-9_-]+)/);
              if(match){
                var key = match[1];
                var sel = '.wcusage_field_' + key + '_enable';
                var $ctrl = $r.find(sel).first();
                if($ctrl.length){
                  $gatingInput = $ctrl;
                  $gatingP = $ctrl.closest('p[id$="_p"]');
                  return false; // break loop
                }
              }
            });
            if($gatingInput){ return { $input: $gatingInput, $p: $gatingP }; }
            return null;
          }

          if(!$scrollTarget.is(':visible')){
            var ctrl = findConditionalController($scrollTarget);
            if(ctrl){
              $scrollTarget = (ctrl.$p && ctrl.$p.length) ? ctrl.$p : ctrl.$input;
            }
          }

          var offset = $scrollTarget.offset().top - 80;
          jQuery('html, body').animate({ scrollTop: offset }, 250);
          $scrollTarget.addClass('wcu-highlight-jump');
          setTimeout(function(){ $scrollTarget.removeClass('wcu-highlight-jump'); }, 1700);
          // Close results dropdown after navigating
          $resultsWrap.hide();
          $search.blur();
        }
      });
      $li.append($info).append($btn);
      $resultsList.append($li);
    });
    $resultsWrap.show();
  }

  // Debounced search
  var timer = null;
  function normalizeLabelKey(str){
  if(!str) return '';
  var s = String(str);
  // lower-case and normalize unicode (remove diacritics)
  try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch(e) {}
  s = s.toLowerCase();
  // normalize smart quotes to straight, then strip quotes
  s = s.replace(/[\u2018\u2019]/g, "'").replace(/[\u201c\u201d]/g, '"');
  // replace all non-alphanumeric with spaces (drops punctuation incl. quotes/colons/dots)
  s = s.replace(/[^a-z0-9]+/g, ' ');
  // collapse and trim
  s = s.replace(/\s+/g,' ').trim();
  return s;
  }
  function doSearch(){
    var q = $.trim($search.val() || '').toLowerCase();
    $resultsList.empty();
    $resultsWrap.hide();
    $empty.hide();
    if(!q){ return; }

    var matches = [];
    $('.wcusage_row p[id$="_p"]').each(function(){
      var $p = $(this);
      var pid = $p.attr('id');
      if(!pid){ return; }
      var labelText = '';
      var $strong = $p.children('strong').first();
      if($strong.length){ labelText = $.trim($strong.text()); }
      if(!labelText){
        var $lbl = $p.find('label').first();
        if($lbl.length){ labelText = $.trim($lbl.text()); }
      }
      if(!labelText){ labelText = $.trim($p.text()); }
      var hay = labelText.toLowerCase();
      if(hay.indexOf(q) === -1){ return; }
      var $row = $p.closest('.wcusage_row');
      var rowClass = '';
      if($row.length){
        var classes = ($row.attr('class')||'').split(/\s+/);
        for(var i=0;i<classes.length;i++){
          if(classes[i].indexOf('wcusage_row_') === 0){ rowClass = classes[i]; break; }
        }
      }
      var tabInfo = tabMap[rowClass] || null;
      var fieldId = ($p.find('input,select,textarea').first().attr('id')) || pid.replace(/_p$/,'');
      matches.push({ label: labelText, pid: pid, rowClass: rowClass, tab: tabInfo, fieldId: fieldId });
    });

    // Also index section headings (h3) and link to their sections
    $('.wcusage_row h3').each(function(){
      var $h = $(this);
      var textOnly = $.trim($h.clone().children().remove().end().text());
      if(!textOnly) return;
      var hay = textOnly.toLowerCase();
      if(hay.indexOf(q) === -1) return;
      var $row = $h.closest('.wcusage_row');
      var rowClass = '';
      if($row.length){
        var classes = ($row.attr('class')||'').split(/\s+/);
        for(var i=0;i<classes.length;i++){
          if(classes[i].indexOf('wcusage_row_') === 0){ rowClass = classes[i]; break; }
        }
      }
      var tabInfo = tabMap[rowClass] || null;
  // Ensure heading has an id for scrolling (unique across searches)
  var hid = ensureHeadingId($h);
      // Prefer deriving section from the Show/Hide button within the heading
      var sectionId = '';
      var $btn = $h.find('.wcu-showhide-button[id^="wcu_show_"]').first();
      if($btn.length){
        var btnId = $btn.attr('id');
        sectionId = 'wcu_' + btnId.replace(/^wcu_show_/, '');
      }
      // Fallback: find the next section container after the heading
      if(!sectionId){
        var $section = $h.nextAll('div[id^="wcu_section_"]').first();
        if($section.length){ sectionId = $section.attr('id'); }
      }
      matches.push({ type: 'heading', label: textOnly, pid: hid, rowClass: rowClass, tab: tabInfo, fieldId: hid, sectionId: sectionId });
    });

    // Deduplicate by normalized label key WITHIN THE SAME TAB, keeping the first occurrence
    var seen = {};
    var deduped = [];
    for(var k=0;k<matches.length;k++){
      var m = matches[k];
      var key = normalizeLabelKey(m.label) + '::' + (m.tab && m.tab.id ? m.tab.id : 'no-tab');
      if(!seen[key]){
        seen[key] = 1;
        deduped.push(m);
      } else {
        seen[key]++;
      }
    }

    deduped.sort(function(a,b){
      var at = (a.type === 'heading') ? 0 : 1;
      var bt = (b.type === 'heading') ? 0 : 1;
      if(at !== bt) return at - bt;
      return (a.label||'').localeCompare(b.label||'');
    });
    render(deduped);
  }

  $search.on('input', function(){
    clearTimeout(timer);
    timer = setTimeout(doSearch, 200);
  });
});
