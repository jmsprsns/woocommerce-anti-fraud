/**
 * Content Powered > WooCommerce Anti Fraud page behaviour. Only loaded on that page.
 *
 * Tabs, rows that depend on a checkbox, the scope pickers, the ipinfo.io
 * Test button and the Clear buttons. woocommerce_anti_fraud_admin.ajaxUrl,
 * .nonce and .i18n are passed in from PHP (see
 * WooCommerce_Anti_Fraud_Admin::enqueue_assets).
 */

/* global woocommerce_anti_fraud_admin */
( function () {
	'use strict';

	var data = window.woocommerce_anti_fraud_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.wcaf-form' );

		initTabs( form );
		initDepends( form );
		initScope( form );
		initTest();
		initClear();
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-wcaf-tab]' );
		var panels = document.querySelectorAll( '[data-wcaf-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-wcaf-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-wcaf-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-wcaf-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-wcaf-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rows that only apply while a checkbox is ticked ---------------------------

	function initDepends( form ) {
		if ( ! form ) {
			return;
		}

		var rows = form.querySelectorAll( '[data-wcaf-depends]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-wcaf-depends' );
				var box = form.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );

				row.classList.toggle( 'is-disabled', !! box && ! box.checked );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// Product and category pickers only matter for "only these products" -------

	function initScope( form ) {
		var fields = document.querySelector( '[data-wcaf-scope-fields]' );

		if ( ! form || ! fields ) {
			return;
		}

		function update() {
			var chosen = form.querySelector( 'input[data-wcaf-scope]:checked' );

			fields.hidden = ! chosen || chosen.value !== 'products';
		}

		form.querySelectorAll( 'input[data-wcaf-scope]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', update );
		} );
		update();
	}

	// AJAX ---------------------------------------------------------------------

	function post( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function initTest() {
		var button = document.querySelector( '[data-wcaf-test]' );
		var result = document.querySelector( '[data-wcaf-test-result]' );
		var token = document.getElementById( 'wcaf-ipinfo_token' );

		if ( ! button || ! result ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.className = 'wcaf-test-result';
			result.textContent = data.i18n.testing;

			post( 'woocommerce_anti_fraud_test_ipinfo', { token: token ? token.value : '' } )
				.then( function ( json ) {
					result.className = 'wcaf-test-result ' + ( json && json.success ? 'is-ok' : 'is-error' );
					result.textContent = json && json.data && json.data.message ? json.data.message : data.i18n.failed;
				} )
				.catch( function () {
					result.className = 'wcaf-test-result is-error';
					result.textContent = data.i18n.failed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	function initClear() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-wcaf-clear]' );

			if ( ! button || ! window.confirm( data.i18n.confirm ) ) {
				return;
			}

			button.disabled = true;

			post( 'woocommerce_anti_fraud_clear', {
				type: button.getAttribute( 'data-type' ),
				value: button.getAttribute( 'data-value' )
			} )
				.then( function ( json ) {
					if ( json && json.success ) {
						var row = button.closest( 'tr' );

						button.textContent = data.i18n.cleared;
						if ( row ) {
							row.style.opacity = '0.5';
						}
					} else {
						button.disabled = false;
					}
				} )
				.catch( function () {
					button.disabled = false;
				} );
		} );
	}
}() );
