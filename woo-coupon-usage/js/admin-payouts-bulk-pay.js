(function($) {
  'use strict';

  var config = window.WCUAdminPayoutsBulkPay || {};
  var strings = config.strings || {};
  var stopped = false;
  var running = false;

  function getString(key, fallback) {
    return strings[key] || fallback;
  }

  function appendLog(message, type) {
    var $log = $('#wcu-bulk-pay-log');
    var entryType = type ? ' is-' + type : '';
    var timestamp = new Date().toLocaleTimeString();

    $('<div/>', {
      'class': 'wcu-bulk-pay-log-entry' + entryType,
      text: '[' + timestamp + '] ' + message
    }).appendTo($log);

    $log.scrollTop($log.prop('scrollHeight'));
  }

  function setProgress(processed, total, current) {
    var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
    $('#wcu-bulk-pay-processed').text(processed);
    $('#wcu-bulk-pay-total').text(total);
    $('#wcu-bulk-pay-progress-bar').css('width', percent + '%');
    $('#wcu-bulk-pay-current').text(current || '');
  }

  function setControlsLocked(locked) {
    running = locked;
    $('#wcu-bulk-pay-run').prop('disabled', locked);
    $('#wcu-bulk-pay-stop').prop('disabled', !locked);
    $('.wcu-bulk-pay-payout, #wcu-bulk-pay-select-all').prop('disabled', locked);
  }

  function updateRow(payoutId, outcome, statusText) {
    var $row = $('#wcu-bulk-pay-row-' + payoutId);
    var rowClass = 'wcu-bulk-pay-row-' + outcome;

    $row.removeClass('wcu-bulk-pay-row-paid wcu-bulk-pay-row-created wcu-bulk-pay-row-failed wcu-bulk-pay-row-skipped');
    $row.addClass(rowClass);
    $row.find('.wcu-bulk-pay-row-status').text(statusText);
  }

  function removeCompletedRow(payoutId) {
    $('#wcu-bulk-pay-row-' + payoutId).fadeOut(200, function() {
      $(this).remove();
    });
  }

  function processPayouts(payouts, index, processed) {
    var payout;

    if (stopped) {
      appendLog(getString('stopped', 'Bulk payment run stopped.'), 'warning');
      setControlsLocked(false);
      return;
    }

    if (index >= payouts.length) {
      setProgress(processed, payouts.length, getString('complete', 'Bulk payment run complete.'));
      appendLog(getString('complete', 'Bulk payment run complete.'), 'success');
      setControlsLocked(false);
      return;
    }

    payout = payouts[index];
    setProgress(processed, payouts.length, getString('processing', 'Processing payout #%s...').replace('%s', payout.id));
    appendLog(getString('processing', 'Processing payout #%s...').replace('%s', payout.id) + ' ' + payout.amount + ' via ' + payout.method, 'warning');

    $.ajax({
      url: config.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'wcusage_bulk_pay_payout',
        nonce: config.nonce,
        payout_id: payout.id
      }
    }).done(function(response) {
      var data = response && response.data ? response.data : {};
      var outcome = data.outcome || (response.success ? 'paid' : 'failed');
      var statusText = getString(outcome, outcome.charAt(0).toUpperCase() + outcome.slice(1));
      var logType = outcome === 'paid' ? 'success' : (outcome === 'created' ? 'created' : (outcome === 'skipped' ? 'warning' : 'error'));
      var message = data.message || (response.success ? getString('paid', 'Paid') : getString('failed', 'Failed'));

      updateRow(payout.id, outcome, statusText);
      appendLog(message, logType);

      if (data.details) {
        appendLog(data.details, logType);
      }

      if (outcome === 'paid' || outcome === 'created') {
        removeCompletedRow(payout.id);
      }
    }).fail(function(xhr) {
      var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
      var message = data.message || getString('failed', 'Failed');

      updateRow(payout.id, 'failed', getString('failed', 'Failed'));
      appendLog(message, 'error');
    }).always(function() {
      processPayouts(payouts, index + 1, processed + 1);
    });
  }

  $(function() {
    $('#wcu-bulk-pay-select-all').on('change', function() {
      if (running) {
        return;
      }

      $('.wcu-bulk-pay-payout').prop('checked', $(this).prop('checked'));
    });

    $('.wcu-bulk-pay-payout').on('change', function() {
      var allChecked = $('.wcu-bulk-pay-payout').length === $('.wcu-bulk-pay-payout:checked').length;
      $('#wcu-bulk-pay-select-all').prop('checked', allChecked);
    });

    $('#wcu-bulk-pay-stop').on('click', function() {
      stopped = true;
      $(this).prop('disabled', true);
    });

    $('#wcu-bulk-pay-run').on('click', function() {
      var payouts = [];
      var skippedPayouts = [];

      $('.wcu-bulk-pay-payout').each(function() {
        var $checkbox = $(this);
        var payoutId = $checkbox.val();

        if ($checkbox.prop('checked')) {
          payouts.push({
            id: payoutId,
            amount: $checkbox.data('amount') || '',
            method: $checkbox.data('method') || ''
          });
        } else {
          skippedPayouts.push(payoutId);
        }
      });

      $('#wcu-bulk-pay-log').empty();

      $.each(skippedPayouts, function(index, payoutId) {
        appendLog('Skipped payout #' + payoutId + '.', 'warning');
      });

      if (!payouts.length) {
        appendLog(getString('noPayoutsSelected', 'Select at least one payout to process.'), 'warning');
        return;
      }

      stopped = false;
      setControlsLocked(true);
      setProgress(0, payouts.length, '');
      processPayouts(payouts, 0, 0);
    });
  });
})(jQuery);