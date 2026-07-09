/**
 * Behaviour for the WooCommerce coupon edit screen (Coupon Affiliates data
 * panel + side meta box). Enqueued via wcusage_enqueue_coupon_edit_assets() in
 * inc/functions/functions-user-coupons.php.
 *
 * Localised data (wcusage_coupon_edit_vars): { ajax_url, nonce }.
 */
( function ( $ ) {
	'use strict';

	var vars = window.wcusage_coupon_edit_vars || {};

	/**
	 * Autocomplete source shared by both affiliate-user fields. Queries the
	 * wcusage_search_users AJAX action for matching usernames.
	 */
	function wcusageCouponUserSource( request, response ) {
		$.ajax( {
			url: vars.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				search: request.term,
				label: '',
				action: 'wcusage_search_users',
				nonce: vars.nonce
			},
			success: function ( data ) {
				if ( ! data.success ) {
					if ( window.console && window.console.error ) {
						window.console.error( 'Autocomplete error:', data.data || 'Unknown error' );
					}
					response( [] );
					return;
				}
				response( $.map( data.data, function ( item ) {
					return { label: item.label, value: item.value || item.label };
				} ) );
			},
			error: function ( xhr ) {
				if ( window.console && window.console.error ) {
					window.console.error( 'Autocomplete AJAX error:', xhr.status + ' ' + ( xhr.responseText || 'No response from server' ) );
				}
				response( [] );
			}
		} );
	}

	/**
	 * Wire up an affiliate-user field with autocomplete, keeping its value in
	 * sync with a partner field (the panel field and the meta box field mirror
	 * each other).
	 */
	function wcusageInitCouponUserField( $field, $partner ) {
		if ( ! $field.length ) {
			return;
		}

		$field.autocomplete( {
			source: wcusageCouponUserSource,
			minLength: 1,
			select: function ( event, ui ) {
				$( this ).val( ui.item.value );
				if ( $partner.length ) {
					$partner.val( ui.item.value );
				}
				return false;
			},
			focus: function () {
				return false;
			}
		} );

		$field.on( 'change input', function () {
			if ( $partner.length ) {
				$partner.val( $( this ).val() );
			}
		} );
	}

	$( function () {
		var $panelField = $( '#wcu_select_coupon_user' );
		var $metaField = $( '#wcu_select_coupon_user_meta' );

		wcusageInitCouponUserField( $panelField, $metaField );
		wcusageInitCouponUserField( $metaField, $panelField );

		// "Customise more settings" — open the Coupon Affiliates & Commission
		// coupon data tab and scroll it into view.
		$( '.wcusage-customise-more-settings' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $tab = $( '.coupon_data_tabs .coupon-affiliates_options a' );
			if ( $tab.length ) {
				$tab.trigger( 'click' );
				var $box = $( '#woocommerce-coupon-data' );
				if ( $box.length ) {
					$( 'html, body' ).animate( { scrollTop: $box.offset().top - 40 }, 300 );
				}
			}
		} );
	} );
} )( jQuery );
