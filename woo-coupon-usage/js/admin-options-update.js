(function($){
  'use strict';

  // The saving-mode select is read by isLegacyEnabled(), so by the time a change
  // handler runs it already holds the newly picked value. Switching *to* manual
  // saving would therefore bail out before it was written and the mode silently
  // reverted to automatic on the next page load - which is what made "Manual
  // Saving" look like it never took effect. This one field always saves by AJAX.
  var WCU_LEGACY_FIELD = 'wcusage_field_settings_legacy';
  var isLegacySelfField = function(el) {
    if (!el) { return false; }
    var id = (typeof el === 'string') ? el : $(el).attr('id');
    return id === WCU_LEGACY_FIELD;
  };

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

  // =====================================================
  // Keep duplicate copies of the same setting in step
  // =====================================================
  // 38 settings are deliberately drawn on more than one tab - the "Creatives"
  // toggle appears six times, "Login Form" three. Each one is a separate control
  // posting the same name, so a bulk save sends every copy and PHP keeps the LAST
  // one: switching a toggle off on the tab you are looking at was undone by a copy
  // on another tab that still said on. (Automatic saving was unaffected, but the
  // other copies still showed the stale value until the page was reloaded.)
  var wcuSyncDuplicates = function(el) {
    if (!el || !el.getAttribute) { return; }
    var name = el.getAttribute('name');
    if (!name || name.indexOf('wcusage_options[') !== 0) { return; }
    // A radio group legitimately shares one name across its own options.
    if (el.type === 'radio') { return; }
    // getElementsByName matches the raw name, so the square brackets in
    // wcusage_options[...] need no selector escaping.
    var $others = $(document.getElementsByName(name)).not(el).not('[type=hidden]');
    if (!$others.length) { return; }
    if (el.type === 'checkbox') { $others.filter(':checkbox').prop('checked', !!el.checked); }
    else { $others.val($(el).val()); }
    // Let the show/hide handlers bound to those copies react. The event carries no
    // originalEvent, so shouldProcessChange() keeps it from starting another save.
    $others.trigger('change');
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

  // =====================================================
  // Failure reporting
  // =====================================================
  // Every failed save used to end in the same "Failed to update. Please try
  // again." alert, whether the request was blocked before it left the browser,
  // refused by a firewall, or answered with a PHP error - so a site owner could
  // only report "an error". Say which it was.
  var WCU_FALLBACK_I18N = {
    save_failed: 'This setting could not be saved.',
    fail_blocked: 'The request never reached the server. It was blocked by the browser or the network - usually mixed HTTP/HTTPS content or a different domain to your WordPress Address, a firewall, or an ad blocker.',
    fail_forbidden: 'The server refused the request (HTTP 403). Reload this page in case your login session has expired; if it keeps happening, a security plugin or web application firewall is blocking admin-ajax.php.',
    fail_not_found: 'The server did not recognise the save action (HTTP %s). Something on this site is restricting admin-ajax.php.',
    fail_server: 'The server returned an error (HTTP %s). Check your PHP error log for the cause.',
    fail_parse: 'The server sent back something other than the expected data - usually a PHP notice or warning printed by another plugin. The raw response is in the browser console.',
    fail_generic: 'Unexpected response: %s',
    bulk_saving: 'Saving all settings (batch %1$s of %2$s)...',
    bulk_saved: 'All settings have been saved.',
    bulk_failed: 'Saving stopped at batch %1$s of %2$s: %3$s',
    max_input_vars_warning: 'This settings form posts %1$s fields, but the PHP "max_input_vars" limit on your server is %2$s, so PHP would silently discard everything after the limit. "Save All Settings" and "Save Settings" therefore save in smaller batches automatically. Settings saved automatically as you change them are not affected. To save everything in one request, ask your host to raise "max_input_vars" to %3$s or higher.'
  };
  var wcuI18n = function(key){
    var s = (window.wcusageUpdate && window.wcusageUpdate.i18n) ? window.wcusageUpdate.i18n[key] : '';
    return s || WCU_FALLBACK_I18N[key] || key;
  };
  // Minimal sprintf: %1$s style positional and plain %s placeholders.
  var wcuFormat = function(str){
    var args = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(str).replace(/%(\d+)\$s|%s/g, function(m, n){
      var v = n ? args[n - 1] : args[i++];
      return (v === undefined) ? m : String(v);
    });
  };
  var wcuDescribeFailure = function(xhr, status, error){
    var code = (xhr && typeof xhr.status === 'number') ? xhr.status : 0;
    if (status === 'parsererror') { return wcuI18n('fail_parse'); }
    if (code === 0) { return wcuI18n('fail_blocked'); }
    if (code === 403) { return wcuI18n('fail_forbidden'); }
    if (code === 400 || code === 404) { return wcuFormat(wcuI18n('fail_not_found'), code); }
    if (code >= 500) { return wcuFormat(wcuI18n('fail_server'), code); }
    return wcuFormat(wcuI18n('fail_generic'), code + ' ' + (error || status || ''));
  };
  // Persistent red note beside the field; cleared by the next successful save.
  var wcuShowFieldError = function(field, message){
    $('#wcu-update-text-' + field).remove();
    $('#wcu-update-text2-' + field).remove();
    var $p = $('<p/>', { id: 'wcu-update-text-' + field, 'class': 'wcu-update-text wcu-update-text-error' })
      .css({ color: '#b32d2e', fontWeight: '600' }).text(message);
    var $anchor = $('#' + field + '_p');
    if ($anchor.length) { $anchor.after($p); } else { $('#' + field).after($p); }
    $('.wcu-addons-box .' + field).after($p.clone().attr('id', 'wcu-update-text2-' + field));
  };

  // =====================================================
  // Bulk save (the whole form to options.php), in batches
  // =====================================================
  // PHP counts every posted name/value pair against max_input_vars and drops the
  // rest without telling anyone; the server merges what arrives over the stored
  // options, so the tabs past the cut simply "did not save". The form is well past
  // PHP's default of 1000 on a site with the PRO tabs, so post it in batches that
  // fit. Each batch carries the Settings API fields (option page, action, nonce,
  // referer) and the server-side merge keeps everything a batch leaves out.
  var WCU_BASE_FIELDS = { option_page: 1, action: 1, _wpnonce: 1, _wp_http_referer: 1 };
  var wcuMaxInputVars = function(){
    var n = parseInt(window.wcusageUpdate && window.wcusageUpdate.max_input_vars, 10);
    if (!(n > 0)) { n = parseInt($('#wcu-max-input-vars-warning').attr('data-limit'), 10); }
    return (n > 0) ? n : 0;
  };
  var wcuPostedFields = function($form){
    try { if (window.tinyMCE && typeof tinyMCE.triggerSave === 'function') { tinyMCE.triggerSave(); } } catch (e) {}
    return $form.serializeArray(); // exactly what a native submit would post
  };
  var wcuBulkBatches = function($form){
    var fields = wcuPostedFields($form);
    var base = [], groups = {}, order = [];
    $.each(fields, function(_, f){
      if (WCU_BASE_FIELDS[f.name]) { base.push(f); return; }
      // Keep every input of one option in the same batch: the server replaces an
      // option's array wholesale, so a multi-checkbox split across two batches
      // would lose its first half, and a toggle's hidden "0" must travel with it.
      var m = /^[^\[]+\[[^\]]*\]/.exec(f.name);
      var key = m ? m[0] : f.name;
      if (!groups[key]) { groups[key] = []; order.push(key); }
      groups[key].push(f);
    });
    var limit = wcuMaxInputVars();
    var perBatch = limit ? Math.max(20, Math.floor(limit * 0.9) - base.length) : Infinity;
    var batches = [], current = [];
    $.each(order, function(_, key){
      var g = groups[key];
      if (current.length && current.length + g.length > perBatch) { batches.push(current); current = []; }
      current = current.concat(g);
    });
    if (current.length || !batches.length) { batches.push(current); }
    return { base: base, batches: batches, total: fields.length, limit: limit };
  };
  var wcuBulkSave = function($form, onProgress, onDone){
    var plan = wcuBulkBatches($form);
    var url = $form.attr('action') || 'options.php';
    var i = 0;
    var next = function(){
      if (i >= plan.batches.length) { onDone(null, plan); return; }
      var n = i + 1;
      if (onProgress) { onProgress(n, plan.batches.length); }
      $.ajax({ type: 'POST', url: url, data: $.param(plan.base.concat(plan.batches[i])) })
        .done(function(){ i++; next(); })
        .fail(function(xhr, status, error){
          console.error('Coupon Affiliates: bulk save failed at batch ' + n + '/' + plan.batches.length + ' (' + (xhr && xhr.status) + ' ' + status + ')', error, xhr && xhr.responseText);
          onDone({ batch: n, of: plan.batches.length, why: wcuDescribeFailure(xhr, status, error) }, plan);
        });
    };
    next();
  };

  // AJAX helper
  window.wcu_ajax_update_the_options = function(thisObj, type, action, val, thekey, ids){
    if (!thekey) { thekey = ""; }
    if (isLegacyEnabled() && !isLegacySelfField(thisObj)) { return; }

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
        // The server answered but declined - it says why (capability, missing
        // data, or a database write that did not land).
        $(".wcu-update-icon").remove();
        $(".wcu-update-text").remove();
        var why = (json && json.data && json.data.message) ? json.data.message : '';
        console.error('Coupon Affiliates: settings save rejected for "' + myClass + '":', why || json);
        wcuShowFieldError(myClass, wcuI18n('save_failed') + (why ? ' ' + why : ''));
      }
    }).fail(function(xhr, status, error){
      // Reset UI state
      $('input, textarea, select, password, .switch').css('pointer-events','auto');
      $('.wcusage-settings-form label').css('cursor','default');
      $(document.body).css({'cursor':'default'});
      $(".wcu-update-icon").remove();
      $(".wcu-update-text").remove();

      var why = wcuDescribeFailure(xhr, status, error);
      console.error('Coupon Affiliates: settings save failed for "' + myClass + '" (' + (xhr && xhr.status) + ' ' + status + ')', error, xhr && xhr.responseText);
      wcuShowFieldError(myClass, wcuI18n('save_failed') + ' ' + why);
      alert(wcuI18n('save_failed') + '\n\n' + why);
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
      wcuSyncDuplicates(this);
      if (isLegacyEnabled() && !isLegacySelfField(this)) { return; }
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
    wcuSyncDuplicates(this);
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

  // Bulk Save All handler (posts the full settings form to options.php in batches)
  $(document).on('click', '#wcu-save-all-button', function(){
    if (isLegacyEnabled()) {
      // In legacy/manual mode, this button should not act.
      return;
    }

    var $btn = $(this);
    var $status = $('#wcu-save-all-status');
    try {
      $btn.prop('disabled', true);
      $status.stop(true, true).css('color', '#666').text(wcuFormat(wcuI18n('bulk_saving'), 1, 1)).show();
      // Hide and reset the per-field saved counter/message
      try {
        $('#wcu-number-settings-saved').text('0');
        $('#wcu-number-settings-saved-message').hide();
      } catch(e) {}

      wcuBulkSave($('.wcusage-settings-form'), function(n, of){
        $status.text(wcuFormat(wcuI18n('bulk_saving'), n, of));
      }, function(err){
        if (err) {
          var msg = wcuFormat(wcuI18n('bulk_failed'), err.batch, err.of, err.why);
          $status.css('color', '#b32d2e').text(msg).show();
          alert(msg);
        } else {
          // Keep the per-field counter hidden; show a generic success status instead
          $status.text(wcuI18n('bulk_saved')).show();
          setTimeout(function(){ $status.fadeOut(400); }, 2500);
        }
        $btn.prop('disabled', false);
      });
    } catch (e) {
      $status.css('color', '#b32d2e').text('Save All failed to start.').show();
      $btn.prop('disabled', false);
    }
  });

  // Native submit (the legacy "Save Settings" button, or Enter in a text field).
  // Let it through when the form fits in one request; otherwise post it in batches
  // and then land where options.php would have sent the browser.
  $(document).on('submit', '.wcusage-settings-form', function(e){
    var $form = $(this);
    var limit = wcuMaxInputVars();
    if (!limit) { return; }
    var count = wcuPostedFields($form).length;
    if (count < limit) { return; }
    e.preventDefault();

    var $button = $form.find('#submit, input[type=submit], button[type=submit]').first();
    var $status = $('#wcu-legacy-save-status');
    if (!$status.length) {
      $status = $('<p id="wcu-legacy-save-status" style="font-size:14px;"></p>');
      if ($button.length) { $button.closest('p.submit').length ? $button.closest('p.submit').after($status) : $button.after($status); }
      else { $form.append($status); }
    }
    $button.prop('disabled', true);
    $status.css('color', '#666').text(wcuFormat(wcuI18n('bulk_saving'), 1, 1)).show();

    wcuBulkSave($form, function(n, of){
      $status.text(wcuFormat(wcuI18n('bulk_saving'), n, of));
    }, function(err){
      if (err) {
        var msg = wcuFormat(wcuI18n('bulk_failed'), err.batch, err.of, err.why);
        $status.css('color', '#b32d2e').text(msg);
        $button.prop('disabled', false);
        alert(msg);
        return;
      }
      $status.text(wcuI18n('bulk_saved'));
      var back = $form.find('input[name="_wp_http_referer"]').val() || window.location.href;
      back = back.replace(/([?&])settings-updated=[^&]*&?/, '$1').replace(/[?&]$/, '');
      back += (back.indexOf('?') === -1 ? '?' : '&') + 'settings-updated=true';
      window.location.href = back;
    });
  });

  // Tell the site owner when the form is bigger than PHP will accept in one go.
  $(function(){
    var $warn = $('#wcu-max-input-vars-warning');
    var $form = $('.wcusage-settings-form');
    if (!$warn.length || !$form.length) { return; }
    var limit = wcuMaxInputVars();
    if (!limit) { return; }
    var count = $form.serializeArray().length;
    if (count < limit) { return; }
    var suggested = Math.ceil((count + 100) / 500) * 500;
    $warn.find('.wcu-max-input-vars-text').text(wcuFormat(wcuI18n('max_input_vars_warning'), count, limit, suggested));
    $warn.show();
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
      // Editors are built lazily as they scroll into view, so one that does not exist
      // yet has nothing to rebuild - it picks up the reloaded plugins when it is built.
      if (!editor) { return; }
      // Never interrupt a field that is being worked in.
      if (editor.initialized && (editor.isDirty() || editor === tinymce.focusedEditor)) { return; }
      try { editor.save(); } catch (e) {}
      try { tinymce.remove('#' + id); } catch (e) {}
      delete wcuBoundEditors[id]; // the replacement instance needs its own change binding
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
