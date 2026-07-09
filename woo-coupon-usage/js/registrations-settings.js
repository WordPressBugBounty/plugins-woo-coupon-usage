(function ($) {

  function settings() {
    return (typeof wcuRegSettings !== 'undefined') ? wcuRegSettings : {};
  }

  function ajaxUrl() {
    return settings().ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
  }

  // Show/hide the options + toggle columns based on the selected field type.
  function toggleFieldVisibility($item) {
    var type = $item.find('.wcu-cf-input-type').val();
    var $options = $item.find('.wcu-cf-col-options');
    var $required = $item.find('.wcu-cf-col-required');
    var $editable = $item.find('.wcu-cf-col-editable');
    var $labelText = $item.find('.reg-field-label');
    var s = settings();

    if (type === 'dropdown' || type === 'radio') {
      $options.show();
    } else {
      $options.hide();
    }

    if (type === 'header' || type === 'paragraph') {
      $required.hide();
      $editable.hide();
      if ($labelText.length) { $labelText.text(s.textLabel || 'Text'); }
    } else {
      $required.show();
      $editable.show();
      if ($labelText.length) { $labelText.text(s.fieldLabel || 'Field Label'); }
    }
  }

  // Point one input's id + name at a new base key.
  function setNameId($el, base) {
    if (!$el.length) { return; }
    $el.attr('id', base).attr('name', 'wcusage_options[' + base + ']');
  }

  // Re-key every input in a field row to a sequential index.
  function reindexItem($item, i) {
    $item.attr('data-index', i);
    setNameId($item.find('.wcu-cf-input-label'), 'wcusage_field_registration_custom_label_' + i);
    setNameId($item.find('.wcu-cf-input-type'), 'wcusage_field_registration_custom_type_' + i);
    setNameId($item.find('.wcu-cf-input-options'), 'wcusage_field_registration_custom_options_' + i);

    setNameId($item.find('.wcu-cf-input-required'), 'wcusage_field_registration_custom_required_' + i);
    $item.find('.wcu-cf-hidden-required').attr('name', 'wcusage_options[wcusage_field_registration_custom_required_' + i + ']');

    setNameId($item.find('.wcu-cf-input-editable'), 'wcusage_field_registration_custom_editable_' + i);
    $item.find('.wcu-cf-hidden-editable').attr('name', 'wcusage_options[wcusage_field_registration_custom_editable_' + i + ']');
  }

  // Re-index all rows, update the count field, and persist the full order.
  function syncOrder($list, persist) {
    var $items = $list.children('.wcu-cf-item');
    var labels = [], types = [], options = [], required = [], editable = [];

    $items.each(function (idx) {
      var $item = $(this);
      reindexItem($item, idx + 1);
      labels.push($item.find('.wcu-cf-input-label').val() || '');
      types.push($item.find('.wcu-cf-input-type').val() || 'text');
      options.push($item.find('.wcu-cf-input-options').val() || '');
      required.push($item.find('.wcu-cf-input-required').is(':checked') ? 1 : 0);
      editable.push($item.find('.wcu-cf-input-editable').is(':checked') ? 1 : 0);
    });

    $('#wcusage_field_registration_custom_fields').val($items.length);

    if (persist === false) { return; }

    $.ajax({
      type: 'POST',
      url: ajaxUrl(),
      data: {
        action: 'wcusage_reorder_custom_fields',
        _ajax_nonce: settings().nonce || '',
        labels: labels,
        types: types,
        options: options,
        required: required,
        editable: editable
      }
    });
  }

  $(function () {
    var $list = $('#wcu-registration-custom-fields');
    if (!$list.length) { return; }

    // Initialise type-based visibility for existing rows.
    $list.children('.wcu-cf-item').each(function () {
      toggleFieldVisibility($(this));
    });

    // Type change -> update visibility.
    $(document).on('change', '.wcu-cf-input-type', function () {
      toggleFieldVisibility($(this).closest('.wcu-cf-item'));
    });

    // Add new field from the hidden template.
    $(document).on('click', '#wcu-add-custom-field', function () {
      var next = $list.children('.wcu-cf-item').length + 1;
      var html = ($('#wcu-cf-template').html() || '').replace(/__INDEX__/g, next);
      if (!html) { return; }
      var $item = $(html.trim());
      $list.append($item);
      toggleFieldVisibility($item);
      syncOrder($list);
      $item.find('.wcu-cf-input-label').trigger('focus');
    });

    // Delete a field.
    $(document).on('click', '.wcu-cf-delete', function () {
      var $item = $(this).closest('.wcu-cf-item');
      var label = $item.find('.wcu-cf-input-label').val();
      if (label) {
        var confirmMsg = settings().confirmDelete || 'Remove this field?';
        if (!window.confirm(confirmMsg)) { return; }
      }
      $item.remove();
      syncOrder($list);
    });

    // Drag and drop reordering.
    if ($.fn.sortable) {
      $list.sortable({
        handle: '.wcu-cf-handle',
        items: '> .wcu-cf-item',
        placeholder: 'wcu-cf-placeholder',
        forcePlaceholderSize: true,
        tolerance: 'pointer',
        start: function (e, ui) {
          ui.item.addClass('wcu-cf-dragging');
          ui.placeholder.height(ui.item.outerHeight());
        },
        stop: function (e, ui) {
          ui.item.removeClass('wcu-cf-dragging');
        },
        update: function () {
          syncOrder($list);
        }
      });
    }
  });

})(jQuery);
