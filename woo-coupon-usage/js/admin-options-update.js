(function($){
  'use strict';

  // Detect whether legacy (manual) saving mode is enabled
  var isLegacyEnabled = function() {
    var $select = $('#wcusage_field_settings_legacy');
    if ($select.length) {
      return $select.val() === '1';
    }
    var $checkbox = $('.wcusage_field_settings_legacy');
    if ($checkbox.length) {
      return $checkbox.is(':checked');
    }
    return false;
  };

  // Debounce helper
  if (typeof window.wcusettingsdelay !== 'function') {
    window.wcusettingsdelay = function(callback, ms){
      var timer = 0;
      return function(){
        var context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function(){
          callback.apply(context, args);
        }, ms || 0);
      };
    };
  }

  // AJAX helper
  window.wcu_ajax_update_the_options = function(thisObj, type, action, val, thekey, ids){
    var legacy = isLegacyEnabled();
    if (!thekey) { thekey = ""; }
    if (legacy) { return; }

    var checktype = $(thisObj).attr('checktype');
    var myClass, myVal, checktype2;

    if (type === 'id') {
      myClass = $(thisObj).attr(type);
      var thetype = $(thisObj).attr('type');
      if (ids === ':checkbox') {
        if (thetype === 'checkbox') {
          myVal = $(thisObj).is(':checked');
        }
      } else {
        myVal = $(thisObj).val();
      }
    }
    if (type === 'data-id') {
      checktype = $('#' + thisObj).attr('checktype');
      checktype2 = $('#' + thisObj).attr('checktype2');
      if (typeof tinyMCE !== 'undefined' && tinyMCE.get(thisObj)) {
        myVal = tinyMCE.get(thisObj).getContent({format:'raw'});
      } else {
        myVal = $('#' + thisObj).val();
      }
      if (checktype2 === 'tinymce') {
        myClass = $('#' + thisObj).attr('customid');
      } else {
        myClass = thisObj;
      }
    }

    if (type === 'class') {
      myClass = $(thisObj).attr(type);
      myVal = $(thisObj).is(':checked') ? 1 : 0;
    }

    var customid = $(thisObj).attr('customid');
    if (customid) {
      myClass = $(thisObj).attr('customid');
    }

    var myMulti = (checktype === 'multi') ? 1 : 0;

    var myCustomNumber = 0, myCustomNumber1 = '', myCustomNumber2 = '';
    if (checktype === 'customnumber') {
      myCustomNumber = 1;
      if (checktype2 === 'tinymce') {
        myCustomNumber1 = $('#' + thisObj).attr('custom1');
        myCustomNumber2 = $('#' + thisObj).attr('custom2');
      } else {
        myCustomNumber1 = $(thisObj).attr('custom1');
        myCustomNumber2 = $(thisObj).attr('custom2');
      }
    }

    var elementType = $('#' + myClass).prop('nodeName');
    $('input, textarea, select, password, .switch').css('pointer-events','none');
    $('.wcusage-settings-form label').css('cursor','wait');
    $(document.body).css({'cursor':'wait'});

    $('#' + myClass).before("<p id='wcu-update-small-text-load-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-sync-alt fa-spin'></i></p>");
    $(".wcu-addons-box ." + myClass).before("<p id='wcu-update-small-text-load2-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-sync-alt fa-spin'></i></p>");

    $("#wcu-update-text-" + myClass).remove();
    $("#" + myClass + "_p").after("<p id='wcu-update-text-"+ myClass +"' class='wcu-update-text'>Updating option...</p>");

    $("#wcu-update-text2-" + myClass).remove();
    $(".wcu-addons-box ." + myClass).after("<p id='wcu-update-text2-"+ myClass +"' class='wcu-update-text'>Updating option...</p>");

    $.ajax({
      type: 'POST',
      url: (window.wcusageUpdate && window.wcusageUpdate.ajax_url) ? window.wcusageUpdate.ajax_url : ajaxurl,
      data: {
        _ajax_nonce: (window.wcusageUpdate && window.wcusageUpdate.nonce) ? window.wcusageUpdate.nonce : '',
        action: action,
        option: myClass,
        value: myVal,
        multi: myMulti,
        key: thekey,
        customnum: myCustomNumber,
        customnum1: myCustomNumber1,
        customnum2: myCustomNumber2
      },
      dataType: 'json'
    }).done(function(json){
      // Reset UI state first
      $('input, textarea, select, password, .switch').css('pointer-events','auto');
      $('.wcusage-settings-form label').css('cursor','default');
      $(document.body).css({'cursor':'default'});
      
      if (json && json.success) {
        $(".wcu-update-text").remove();
        $("#" + myClass + "_p").after("<p id='wcu-update-text-"+ myClass +"' class='wcu-update-text'>Successfully updated!</p>");
        $(".wcu-addons-box ." + myClass).after("<p id='wcu-update-text2-"+ myClass +"' class='wcu-update-text'>Successfully updated!</p>");

        $(".wcu-update-icon").remove();
        $("#" + myClass).before("<p id='wcu-update-small-text-"+ myClass +"' class='wcu-update-icon wcu-update-icon-"+ elementType +"'><i class='fas fa-check-circle'></i></p>");
        setTimeout(function(){
          $(".wcu-update-text").remove();
          $("#wcu-update-small-text-" + myClass).remove();
          $("#wcu-update-small-text-load-" + myClass).remove();
          $("#wcu-update-small-text-load2-" + myClass).remove();
        }, 1000);

        var settingsupdate = parseInt($("#wcu-number-settings-saved").text()) || 0;
        var settingsupdatenew = settingsupdate + 1;
        $("#wcu-number-settings-saved-message").show();
        $("#wcu-number-settings-save-toggle").show();
        if (isLegacyEnabled()) {
          $('.wcu-field-section-save').show();
        } else {
          $('.wcu-field-section-save').hide();
        }
        $("#wcu-number-settings-saved").text(settingsupdatenew);
      } else {
        // Handle error response
        $(".wcu-update-icon").remove();
        $(".wcu-update-text").remove();
        $("#" + myClass + "_p").after("<p id='wcu-update-text-"+ myClass +"' class='wcu-update-text' style='color:red;'>Update failed!</p>");
        setTimeout(function(){
          $(".wcu-update-text").remove();
        }, 1000);
      }
    }).fail(function(xhr, status, error){
      // Reset UI state
      $('input, textarea, select, password, .switch').css('pointer-events','auto');
      $('.wcusage-settings-form label').css('cursor','default');
      $(document.body).css({'cursor':'default'});
      $(".wcu-update-icon").remove();
      $(".wcu-update-text").remove();
      
      console.error('AJAX update failed:', status, error, xhr.responseText);
      alert('Failed to update. Please try again.');
    });
  };

  // Allow color picker programmatic changes while keeping other guards
  var shouldProcessChange = function(e, $el){
    if (e && e.originalEvent) { return true; }
    if (!$el || !$el.length) { return false; }
    if ($el.hasClass('wp-color-picker')) { return true; }
    if ($el.is('input[type=color]')) { return true; }
    return false;
  };

  // Attach delegated handlers immediately (no DOM-ready wait)
  var addHandlers = function(selector, action, val, gettype){
    $(document).on('change', selector, wcusettingsdelay(function(e){
      if (!shouldProcessChange(e, $(this))) { return; }
      var legacy = isLegacyEnabled();
      if (legacy) { return; }
      var checktype = $(this).attr('checktype');
      if (checktype !== 'ignore') {
        if (checktype !== 'multi') {
          window.wcu_ajax_update_the_options($(this), gettype, action, val, '', selector);
        } else {
          var key = $(this).attr('checktypekey');
          window.wcu_ajax_update_the_options($(this), 'class', 'wcu-update-toggle', 1, key, selector);
        }
      }
    }, 50));
  };

  // Mirror original bindings (exclude textarea here to avoid double binding; handled below)
  addHandlers('input[type=text], input[type=number], input[type=password], input[type=radio], input[type=color], select', 'wcu-update-text', 1, 'id');
  addHandlers(':checkbox', 'wcu-update-toggle', 0, 'id');

  // For TinyMCE-backed textareas, trigger on change of the textarea; the AJAX helper will switch to data-id path
  $(document).on('change', 'textarea', wcusettingsdelay(function(e){
    if (!shouldProcessChange(e, $(this))) { return; }
    var id = $(this).attr('id');
    if (!id) { return; }
    window.wcu_ajax_update_the_options(id, 'data-id', 'wcu-update-text', 1, '', 'textarea');
  }, 50));

  // Show the "Save All" button only after switching the legacy dropdown to Automatic (AJAX)
  $(document).on('change', '#wcusage_field_settings_legacy', function(){
    try {
      var val = $(this).val();
      if (val === '0') {
        $('#wcu-save-all-container').show();
      } else {
        $('#wcu-save-all-container').hide();
      }
    } catch (e) {}
  });

  // Bulk Save All handler (submits the full settings form to options.php via AJAX)
  $(document).on('click', '#wcu-save-all-button', function(){
    if (isLegacyEnabled()) {
      // In legacy/manual mode, this button should not act.
      return;
    }

    var $btn = $(this);
    var $status = $('#wcu-save-all-status');
    try {
      $btn.prop('disabled', true);
      $status.text('Saving all settings...').show();
      // Hide and reset the per-field saved counter/message
      try {
        $('#wcu-number-settings-saved').text('0');
        $('#wcu-number-settings-saved-message').hide();
      } catch(e) {}

  var $form = $('.wcusage-settings-form');
  // Ensure TinyMCE-backed textareas write content back to underlying <textarea>
  try { if (window.tinyMCE && typeof tinyMCE.triggerSave === 'function') { tinyMCE.triggerSave(); } } catch (e) {}
      var formData = $form.serialize();
      var actionUrl = $form.attr('action') || 'options.php';

      $.ajax({
        type: 'POST',
        url: actionUrl,
        data: formData
      }).done(function(){
        // Keep the per-field counter hidden; show a generic success status instead
        $status.text('All settings have been saved.').show();
      }).fail(function(){
        $status.text('Failed to save settings. Please try again.').show();
      }).always(function(){
        setTimeout(function(){ $status.fadeOut(400); }, 2500);
        $btn.prop('disabled', false);
      });
    } catch (e) {
      $status.text('Save All failed to start.').show();
      $btn.prop('disabled', false);
    }
  });

  // =====================================================
  // TinyMCE backed settings fields (wcusage_setting_tinymce_option)
  // =====================================================
  var wcuTinymceFields = function(){ return window.wcusageTinymceFields || []; };
  var wcuIsOurEditor = function(id){ return id && wcuTinymceFields().indexOf(id) > -1; };

  // Auto save a rich text setting once editing stops. TinyMCE 4 replaced the old
  // editor.onChange.add() dispatcher with editor.on('change'), so this has to bind
  // after the editor exists rather than from an inline script next to the field.
  var wcuBoundEditors = {};
  var wcuBindEditor = function(editor){
    if (!editor || !editor.id || wcuBoundEditors[editor.id] || !wcuIsOurEditor(editor.id)) { return; }
    wcuBoundEditors[editor.id] = true;

    var lastSaved;
    try { lastSaved = editor.getContent({format:'raw'}); } catch (e) { lastSaved = null; }

    editor.on('change', wcusettingsdelay(function(){
      if (isLegacyEnabled()) { return; }
      var value;
      try {
        editor.save(); // write the iframe content back to the underlying <textarea>
        value = editor.getContent({format:'raw'});
      } catch (e) { return; }
      if (value === lastSaved) { return; } // ignore change events that did not alter the content
      lastSaved = value;
      window.wcu_ajax_update_the_options(editor.id, 'data-id', 'wcu-update-text', 1);
    }, 1500));
  };

  var wcuWatchEditor = function(editor){
    if (!editor || !wcuIsOurEditor(editor.id)) { return; }
    if (editor.initialized) {
      wcuBindEditor(editor);
    } else {
      editor.on('init', function(){ wcuBindEditor(editor); });
    }
  };

  // When the browser fails to fetch one of TinyMCE's own plugin scripts - a dropped
  // connection, a blocked request - the ScriptLoader caches that url as permanently
  // failed and PluginManager keeps the half-registered entry, so the plugin stays
  // missing for the rest of the page and every editor opens with a red
  // "Failed to load plugin: <name> from url ..." bar. The editors still work, but the
  // notice looks like a broken page and the plugin's features (tab key navigation for
  // tabfocus, list buttons for lists, and so on) are gone. Ask for the script once more
  // and, if it arrives, rebuild the editors that came up without it.
  var wcuRetriedEditors = false;

  // A plugin counts as failed only when it never registered *and* its script never
  // arrived - a script that loaded without registering under that name is not something
  // asking for it again would fix.
  var wcuMissingPlugins = function(){
    var missing = [];
    try {
      var urls = tinymce.PluginManager.urls;
      var suffix = tinymce.suffix || '';
      Object.keys(urls).forEach(function(name){
        if (tinymce.PluginManager.get(name)) { return; }
        if (tinymce.ScriptLoader.isDone(urls[name] + '/plugin' + suffix + '.js')) { return; }
        missing.push(name);
      });
    } catch (e) {}
    return missing;
  };

  var wcuReloadEditors = function(){
    wcuTinymceFields().forEach(function(id){
      if (!tinyMCEPreInit.mceInit[id] || !document.getElementById(id)) { return; }
      var wrap = document.getElementById('wp-' + id + '-wrap');
      if (!wrap || wrap.className.indexOf('tmce-active') === -1) { return; } // left in Text mode on purpose
      var editor = tinymce.get(id);
      if (editor) {
        // Never interrupt a field that is being worked in.
        if (editor.initialized && (editor.isDirty() || editor === tinymce.focusedEditor)) { return; }
        try { editor.save(); } catch (e) {}
        try { tinymce.remove('#' + id); } catch (e) {}
        delete wcuBoundEditors[id]; // the replacement instance needs its own change binding
      }
      try { tinymce.init(tinyMCEPreInit.mceInit[id]); } catch (e) {}
    });
  };

  var wcuRecoverEditors = function(){
    if (wcuRetriedEditors || typeof window.tinymce === 'undefined' || typeof window.tinyMCEPreInit === 'undefined') { return; }
    if (!tinyMCEPreInit.mceInit) { return; }

    var missing = wcuMissingPlugins();
    if (!missing.length) { return; }

    wcuRetriedEditors = true;
    if (window.console && console.warn) {
      console.warn('Coupon Affiliates: TinyMCE plugin(s) failed to load (' + missing.join(', ') + '), retrying.');
    }

    var suffix = tinymce.suffix || '';
    var retries = [];
    missing.forEach(function(name){
      var url = tinymce.PluginManager.urls[name] + '/plugin' + suffix + '.js';
      retries.push({ name: name, url: url });
      // PluginManager.load() skips anything already listed in PluginManager.urls, and the
      // ScriptLoader answers from its cached FAILED state, so both have to be cleared.
      try {
        tinymce.ScriptLoader.remove(url);
        tinymce.PluginManager.remove(name);
      } catch (e) {}
    });

    var waiting = retries.length;
    var settled = function(){
      waiting--;
      if (waiting > 0) { return; }
      var stillMissing = retries.filter(function(r){ return !tinymce.PluginManager.get(r.name); });
      if (stillMissing.length) { return; } // still unreachable - leave the page alone
      wcuReloadEditors();
    };

    try {
      retries.forEach(function(r){ tinymce.ScriptLoader.add(r.url, settled, null, settled); });
      tinymce.ScriptLoader.loadQueue();
    } catch (e) {}
  };

  $(function(){
    if (typeof window.tinymce === 'undefined') { return; }
    tinymce.on('AddEditor', function(e){ wcuWatchEditor(e.editor); });
    for (var i = 0; i < tinymce.editors.length; i++) { wcuWatchEditor(tinymce.editors[i]); }
  });

  // Late enough that a slow (but working) page is never rebuilt for nothing.
  $(window).on('load', function(){ setTimeout(wcuRecoverEditors, 3000); });

})(jQuery);
