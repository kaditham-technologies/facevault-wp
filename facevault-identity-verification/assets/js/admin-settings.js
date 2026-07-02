/**
 * Settings-screen helpers: webhook-URL copy + connection test.
 * Vanilla JS, no build step. Config arrives via wp_localize_script
 * as `facevaultAdmin` ({ restUrl, nonce, i18n }).
 */
( function () {
	'use strict';

	var cfg = window.facevaultAdmin || {};

	function onReady( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	onReady( function () {
		var copyBtn = document.getElementById( 'facevault-copy-webhook' );
		var urlEl = document.getElementById( 'facevault-webhook-url' );
		if ( copyBtn && urlEl ) {
			copyBtn.addEventListener( 'click', function () {
				var text = urlEl.textContent;
				var done = function () {
					var original = copyBtn.textContent;
					copyBtn.textContent = ( cfg.i18n && cfg.i18n.copied ) || 'Copied!';
					window.setTimeout( function () {
						copyBtn.textContent = original;
					}, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					// Clipboard API needs a secure context; plain-HTTP dev sites fall back.
					var range = document.createRange();
					range.selectNodeContents( urlEl );
					var selection = window.getSelection();
					selection.removeAllRanges();
					selection.addRange( range );
					document.execCommand( 'copy' );
					selection.removeAllRanges();
					done();
				}
			} );
		}

		var testBtn = document.getElementById( 'facevault-test-connection' );
		var result = document.getElementById( 'facevault-test-result' );
		if ( testBtn && result ) {
			testBtn.addEventListener( 'click', function () {
				testBtn.disabled = true;
				result.textContent = ( cfg.i18n && cfg.i18n.testing ) || 'Testing…';
				window
					.fetch( cfg.restUrl + 'test', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.nonce },
					} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( data ) {
						result.textContent = data && data.message ? data.message : ( cfg.i18n && cfg.i18n.failed ) || 'Request failed.';
					} )
					.catch( function () {
						result.textContent = ( cfg.i18n && cfg.i18n.failed ) || 'Request failed.';
					} )
					.finally( function () {
						testBtn.disabled = false;
					} );
			} );
		}
	} );
} )();
