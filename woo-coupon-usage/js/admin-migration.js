/**
 * Coupon Affiliates - Migration screen.
 *
 * Loads the options for the chosen plugin, then drives the migration (or an
 * undo) one AJAX batch at a time until the server says it is finished. A
 * failed batch is retried; nothing is lost if the page is closed, since the
 * server saves its place after every batch.
 */
( function ( $ ) {
	'use strict';

	var config = window.wcusageMigration || {};
	var i18n = config.i18n || {};
	var polling = false;
	var failures = 0;
	// The latest preview request: an earlier one still loading is aborted, and
	// its response ignored, so a slow answer cannot replace a later choice.
	var previewXhr = null;
	var previewRequest = 0;
	var announced = '';

	function post( action, data ) {
		return $.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend( { action: action, nonce: config.nonce }, data || {} )
		} );
	}

	function escapeHtml( text ) {
		// Quotes too: some of it goes into attributes.
		return $( '<div>' ).text( text === undefined || text === null ? '' : String( text ) ).html().replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	// Back to the options after a start that did not go through.
	function startFailed( message ) {
		$( '#wcusage-migration-start' ).prop( 'disabled', false );
		$( '#wcusage-migration-progress' ).attr( 'hidden', true );
		$( '#wcusage-migration-chooser, #wcusage-migration-setup' ).show();
		$( '.wcusage-migration-source' ).prop( 'disabled', false );
		window.alert( message );
	}

	function errorMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		if ( response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message ) {
			return response.responseJSON.data.message;
		}
		return i18n.failed;
	}

	/* ------------------------------------------------------------------
	 * Choosing a source
	 * ---------------------------------------------------------------- */

	$( document ).on( 'click', '.wcusage-migration-source', function () {
		var $button = $( this );
		var $setup = $( '#wcusage-migration-setup' );

		$( '.wcusage-migration-source' ).removeClass( 'is-selected' );
		$button.addClass( 'is-selected' );

		$setup.html( '<div class="wcusage-migration-card wcusage-migration-loading"><span class="spinner is-active"></span> ' + escapeHtml( i18n.loading ) + '</div>' );

		var request = ++previewRequest;
		if ( previewXhr ) {
			previewXhr.abort();
		}
		previewXhr = post( 'wcusage_migration_preview', { source: $button.data( 'source' ) } );
		previewXhr
			.always( function () {
				if ( request === previewRequest ) {
					previewXhr = null;
				}
			} )
			.done( function ( response ) {
				if ( request !== previewRequest ) {
					return;
				}
				if ( response && response.success ) {
					$setup.html( response.data.html );
					refreshDependencies();
					$( 'html, body' ).animate( { scrollTop: $setup.offset().top - 40 }, 200 );
				} else {
					$setup.html( '<div class="notice notice-error inline"><p>' + escapeHtml( errorMessage( response ) ) + '</p></div>' );
				}
			} )
			.fail( function ( xhr ) {
				if ( request !== previewRequest ) {
					return;
				}
				$setup.html( '<div class="notice notice-error inline"><p>' + escapeHtml( errorMessage( xhr ) ) + '</p></div>' );
			} );
	} );

	/* ------------------------------------------------------------------
	 * Options that depend on other options
	 * ---------------------------------------------------------------- */

	function optionValue( key ) {
		var $field = $( '#wcusage-migration-' + key );
		if ( ! $field.length ) {
			return null;
		}
		if ( $field.is( ':checkbox' ) ) {
			return $field.is( ':checked' );
		}
		return $field.val();
	}

	function toggleOption( key, show ) {
		$( '#wcusage-migration-' + key ).closest( '.wcusage-migration-option' ).toggle( !! show );
	}

	function refreshDependencies() {
		var $form = $( '#wcusage-migration-form' );
		if ( ! $form.length ) {
			return;
		}

		$form.find( '.wcusage-migration-option[data-parent]' ).each( function () {
			$( this ).toggle( !! optionValue( $( this ).data( 'parent' ) ) );
		} );

		// Coupon settings: a template, or a discount of their own.
		var mode = optionValue( 'coupon_mode' );
		var creates = mode !== 'existing_only';
		var settings = optionValue( 'coupon_settings' );
		toggleOption( 'coupon_code_format', creates );
		toggleOption( 'coupon_settings', creates );
		toggleOption( 'coupon_conflict', creates );
		toggleOption( 'coupon_template', creates && settings === 'template' );
		toggleOption( 'coupon_discount_type', creates && settings === 'discount' );
		toggleOption( 'coupon_discount_amount', creates && settings === 'discount' );

		// Sections whose data is not being migrated.
		var sectionData = {
			referrals: [ 'migrate_referrals', 'migrate_balances' ],
			balances: [ 'migrate_balances', 'migrate_payouts' ],
			visits: [ 'migrate_visits' ],
			settings: [ 'migrate_settings' ],
			links: [ 'legacy_links' ]
		};
		$.each( sectionData, function ( section, keys ) {
			var on = false;
			var known = false;
			$.each( keys, function ( i, key ) {
				var value = optionValue( key );
				if ( value !== null ) {
					known = true;
					on = on || !! value;
				}
			} );
			$form.find( '.wcusage-migration-section[data-section="' + section + '"]' ).toggleClass( 'is-off', known && ! on );
		} );
	}

	$( document ).on( 'change', '#wcusage-migration-form :input', refreshDependencies );

	/* ------------------------------------------------------------------
	 * Starting
	 * ---------------------------------------------------------------- */

	$( document ).on( 'submit', '#wcusage-migration-form', function ( event ) {
		event.preventDefault();

		var $form = $( this );

		if ( ! $( '#wcusage-migration-backup' ).is( ':checked' ) ) {
			window.alert( i18n.backup );
			return;
		}
		if ( ! $( '#wcusage-migration-staging' ).is( ':checked' ) ) {
			window.alert( i18n.staging );
			return;
		}
		if ( ! window.confirm( i18n.confirm ) ) {
			return;
		}

		var data = { source: $form.data( 'source' ) };
		$.each( $form.serializeArray(), function ( i, field ) {
			if ( field.name.indexOf( 'options[' ) === 0 ) {
				data[ field.name ] = field.value;
			}
		} );
		// Unticked boxes are not posted: send them as off.
		$form.find( 'input[type="checkbox"][name^="options["]' ).each( function () {
			if ( ! this.checked && ! this.disabled ) {
				data[ this.name ] = 0;
			}
		} );

		$( '#wcusage-migration-start' ).prop( 'disabled', true );
		showProgress( { status: 'running', steps: [], percent: 0, counts: [], messages: [ { level: 'info', message: i18n.starting } ] } );

		post( 'wcusage_migration_start', data )
			.done( function ( response ) {
				if ( response && response.success ) {
					showProgress( response.data );
					poll();
				} else {
					startFailed( errorMessage( response ) );
				}
			} )
			.fail( function ( xhr ) {
				startFailed( errorMessage( xhr ) );
			} );
	} );

	/* ------------------------------------------------------------------
	 * Progress
	 * ---------------------------------------------------------------- */

	function showProgress( data ) {
		var $panel = $( '#wcusage-migration-progress' );
		var html = '';
		var status = data.status || '';
		// A failed undo has the status "failed" too: the server says it is one.
		var undo = !! data.undo || status === 'undoing' || status === 'undone';
		var finished = status === 'completed' || status === 'undone' || status === 'cancelled' || status === 'failed';

		$panel.removeAttr( 'hidden' ).attr( 'data-status', status );
		$( '#wcusage-migration-chooser, #wcusage-migration-setup' ).toggle( finished );
		$( '.wcusage-migration-source' ).prop( 'disabled', ! finished );

		var title = undo ? ( status === 'undone' ? i18n.undone : i18n.undoing ) : ( status === 'completed' ? i18n.done : i18n.migrating );
		if ( status === 'cancelled' ) {
			title = i18n.stopped;
		} else if ( status === 'failed' ) {
			title = undo ? i18n.undoError : i18n.stoppedError;
		}

		// Read out by screen readers: only when it changes.
		var announce = title + ' - ' + parseInt( data.percent || 0, 10 ) + '%';
		if ( announce !== announced ) {
			announced = announce;
			$( '#wcusage-migration-progress-status' ).text( announce );
		}

		html += '<div class="wcusage-migration-progress-head">';
		html += '<h2>' + escapeHtml( title ) + ( data.source ? ' <span>' + escapeHtml( data.source ) + '</span>' : '' ) + '</h2>';
		if ( status === 'running' ) {
			html += '<button type="button" class="button" id="wcusage-migration-cancel">' + escapeHtml( i18n.stop ) + '</button>';
		}
		if ( finished ) {
			html += '<button type="button" class="button button-primary" id="wcusage-migration-reload">' + escapeHtml( i18n.close ) + '</button>';
		}
		html += '</div>';

		html += '<div class="wcusage-migration-bar"><span style="width:' + parseInt( data.percent || 0, 10 ) + '%"></span></div>';

		// What to check now, straight under "Migration complete".
		if ( status === 'completed' && data.checklist && data.checklist.length ) {
			html += '<div class="wcusage-migration-checklist">';
			html += '<p class="wcusage-migration-checklist-title"><i class="fa-solid fa-list-check" aria-hidden="true"></i> ' + escapeHtml( i18n.checkTitle ) + '</p>';
			html += '<p>' + escapeHtml( i18n.checkIntro ) + '</p><ul>';
			$.each( data.checklist, function ( i, item ) {
				html += '<li>' + escapeHtml( item.text );
				if ( item.url && item.link ) {
					html += ' <a href="' + escapeHtml( item.url ) + '" target="_blank" rel="noopener">' + escapeHtml( item.link ) + ' &rarr;</a>';
				}
				html += '</li>';
			} );
			html += '</ul></div>';
		}

		if ( data.steps && data.steps.length ) {
			html += '<ol class="wcusage-migration-steps">';
			$.each( data.steps, function ( i, step ) {
				html += '<li class="is-' + escapeHtml( step.state ) + '">' + escapeHtml( step.label ) + '</li>';
			} );
			html += '</ol>';
		}

		if ( data.counts && data.counts.length ) {
			html += '<table class="wcusage-migration-table wcusage-migration-summary"><tbody>';
			$.each( data.counts, function ( i, count ) {
				html += '<tr><th>' + escapeHtml( count.label ) + '</th><td>' + escapeHtml( count.value ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		if ( data.messages && data.messages.length ) {
			html += '<details class="wcusage-migration-log"' + ( status === 'failed' ? ' open' : '' ) + '><summary>' + escapeHtml( i18n.log ) + ' (' + data.messages.length + ')</summary><ul>';
			$.each( data.messages, function ( i, message ) {
				html += '<li class="is-' + escapeHtml( message.level ) + '">' + escapeHtml( message.message ) + '</li>';
			} );
			html += '</ul></details>';
		}

		$( '#wcusage-migration-progress-body' ).html( html );
	}

	function poll() {
		if ( polling ) {
			return;
		}
		polling = true;
		step();
	}

	function step() {
		post( 'wcusage_migration_step' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					retry();
					return;
				}
				failures = 0;
				showProgress( response.data );
				if ( response.data.status === 'running' || response.data.status === 'undoing' ) {
					window.setTimeout( step, 300 );
				} else {
					polling = false;
				}
			} )
			.fail( retry );
	}

	function retry() {
		failures++;
		if ( failures > 8 ) {
			polling = false;
			$( '#wcusage-migration-progress-body' ).append( '<div class="notice notice-error inline"><p>' + escapeHtml( i18n.failed ) + '</p></div>' );
			return;
		}
		$( '#wcusage-migration-progress .wcusage-migration-retry' ).remove();
		$( '#wcusage-migration-progress-body' ).append( '<p class="wcusage-migration-retry description">' + escapeHtml( i18n.error ) + '</p>' );
		window.setTimeout( step, Math.min( 30000, 2000 * failures ) );
	}

	$( document ).on( 'click', '#wcusage-migration-cancel', function () {
		if ( ! window.confirm( i18n.cancel ) ) {
			return;
		}
		post( 'wcusage_migration_cancel' ).done( function ( response ) {
			if ( response && response.success ) {
				showProgress( response.data );
			}
		} );
	} );

	$( document ).on( 'click', '#wcusage-migration-reload', function () {
		window.location.reload();
	} );

	/* ------------------------------------------------------------------
	 * Undo
	 * ---------------------------------------------------------------- */

	$( document ).on( 'click', '.wcusage-migration-undo', function () {
		if ( ! window.confirm( i18n.undo ) ) {
			return;
		}
		var $button = $( this ).prop( 'disabled', true );
		post( 'wcusage_migration_undo', {
			run_id: $button.data( 'run' ),
			delete_users: $( '#wcusage-migration-undo-delete-users' ).is( ':checked' ) ? 1 : 0
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					showProgress( response.data );
					$( 'html, body' ).animate( { scrollTop: 0 }, 200 );
					poll();
				} else {
					$button.prop( 'disabled', false );
					window.alert( errorMessage( response ) );
				}
			} )
			.fail( function ( xhr ) {
				$button.prop( 'disabled', false );
				window.alert( errorMessage( xhr ) );
			} );
	} );

	/* ------------------------------------------------------------------
	 * A run already in progress when the page loads: carry on with it.
	 * ---------------------------------------------------------------- */

	$( function () {
		if ( parseInt( config.busy, 10 ) ) {
			// Where it had got to, until the first batch returns.
			if ( config.progress ) {
				showProgress( config.progress );
			}
			poll();
		}
	} );
}( jQuery ) );
