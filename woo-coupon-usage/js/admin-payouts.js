/**
 * Admin scripts for the Payouts page (page=wcusage_payouts).
 *
 * Enqueued via wcusage_enqueue_payouts_assets() in woo-coupon-usage.php.
 * Localized data is provided in the global wcusage_payouts_vars object.
 */
( function( $ ) {
	'use strict';

	var settings = window.wcusage_payouts_vars || {};
	var i18n = settings.i18n || {};
	var ajaxUrl = settings.ajaxUrl || ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );

	$( function() {

		// "Already paid" notice: add a close (X) button when the notice is present.
		var closeNotice = document.getElementById( 'wcu-close-me' );
		if ( closeNotice ) {
			var button = document.createElement( 'button' );
			button.innerHTML = 'X';
			button.onclick = function() {
				this.parentNode.style.display = 'none';
			};
			closeNotice.appendChild( button );
		}

		// Export Payouts dropdown: toggle the panel with the customise fields.
		var $exportWrap = $( '#wcu-export-dropdown' );
		if ( $exportWrap.length ) {
			var $exportToggle = $( '#wcu-admin-export-payouts' );
			var $exportPanel = $( '#wcu-export-panel' );

			function closeExportPanel() {
				$exportPanel.prop( 'hidden', true );
				$exportToggle.attr( 'aria-expanded', 'false' );
				$exportWrap.removeClass( 'is-open' );
			}

			$exportToggle.on( 'click', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				var isOpen = ! $exportPanel.prop( 'hidden' );
				if ( isOpen ) {
					closeExportPanel();
				} else {
					$exportPanel.prop( 'hidden', false );
					$exportToggle.attr( 'aria-expanded', 'true' );
					$exportWrap.addClass( 'is-open' );
				}
			} );

			// Keep clicks inside the panel from closing it.
			$exportPanel.on( 'click', function( e ) { e.stopPropagation(); } );

			// Close on outside click or Escape.
			$( document ).on( 'click', function() { closeExportPanel(); } );
			$( document ).on( 'keydown', function( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) { closeExportPanel(); }
			} );
		}

		// After generating a statement, auto-click its download link.
		if ( settings.autoDownloadStatement ) {
			var dl = document.getElementById( 'download-payout-statement-' + settings.autoDownloadStatement );
			if ( dl ) {
				dl.click();
			}
		}

		// Username autocomplete on the filters bar.
		var $input = $( '.wcu-autocomplete-user' );
		if ( $input.length && $.ui && $.ui.autocomplete ) {
			$input.autocomplete( {
				source: function( request, response ) {
					$.ajax( {
						url: ajaxUrl,
						method: 'POST',
						data: { action: 'wcusage_search_users', search: request.term, label: 'username' },
						success: function( data ) { if ( data && data.success ) { response( data.data ); } else { response( [] ); } },
						error: function() { response( [] ); }
					} );
				},
				minLength: 2,
				select: function( e, ui ) { $( this ).val( ui.item.value ); return false; }
			} ).autocomplete( 'instance' )._renderItem = function( ul, item ) {
				return $( '<li>' ).append( '<div>' + item.label + '</div>' ).appendTo( ul );
			};
		}

		// Bulk actions: enable/disable checkboxes based on the chosen action.
		function updatePayoutsCheckboxes( action ) {
			var boxes = $( 'input[type=checkbox][name="payouts[]"]' );
			boxes.each( function() {
				var $cb = $( this );
				var status = ( $cb.data( 'status' ) || '' ).toString();
				var method = ( $cb.data( 'method' ) || '' ).toString();
				var isPending = ( status === 'pending' || status === 'created' );
				var allowed = true;
				switch ( action ) {
					case 'paid_manual':
						allowed = isPending; break;
					case 'paid_stripe':
						allowed = isPending && method === 'stripeapi'; break;
					case 'paid_paypal':
						allowed = isPending && method === 'paypalapi'; break;
					case 'paid_credit':
						allowed = isPending && method === 'credit'; break;
					case 'paid_wise':
						// Disallow when status is Created; only Manual Paid should be allowed
						allowed = isPending && ( method === 'wisebank' || method === 'wiseapi' ) && status !== 'created';
						break;
					case 'cancel':
						allowed = isPending; break;
					case 'delete':
						// Only allow delete when payout already cancelled
						allowed = ( status === 'cancel' ); break;
					default:
						allowed = true; break;
				}
				if ( ! allowed ) { $cb.prop( 'checked', false ).prop( 'disabled', true ); }
				else { $cb.prop( 'disabled', false ); }
			} );
		}

		$( '#wcu-payouts-bulk-action' ).on( 'change', function() {
			updatePayoutsCheckboxes( $( this ).val() );
		} );

		$( '#wcu-payouts-bulk-apply' ).on( 'click', function() {
			var action = $( '#wcu-payouts-bulk-action' ).val();
			if ( ! action ) { alert( i18n.selectAction || 'Please select a bulk action.' ); return; }
			var ids = [];
			$( 'input[type=checkbox][name="payouts[]"]:checked' ).each( function() { ids.push( $( this ).val() ); } );
			if ( ids.length === 0 ) { alert( i18n.selectPayout || 'Please select at least one payout.' ); return; }
			if ( action === 'cancel' ) {
				if ( ! confirm( i18n.confirmCancel || '' ) ) { return; }
			}
			if ( action === 'delete' ) {
				if ( ! confirm( i18n.confirmDelete || '' ) ) { return; }
			}
			// Populate proxy form and submit.
			$( '#wcu_payouts_bulk_action' ).val( action );
			var idsContainer = $( '#wcu-payouts-bulk-ids' ).empty();
			ids.forEach( function( id ) {
				idsContainer.append( '<input type="hidden" name="payout_ids[]" value="' + id + '" />' );
			} );
			$( '#wcu-payouts-bulk-form' )[0].submit();
		} );

	} );

}( jQuery ) );
