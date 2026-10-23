/**
 * OutlinePay page: the Copy buttons for the diagnostic code.
 *
 * Plain script, no build step. Every button with data-outlinepay-copy copies
 * the text of its data-target element and writes the outcome into its
 * data-status element. Uses the Clipboard API where the browser allows it,
 * and otherwise selects the code so the merchant can copy it.
 */
( function () {
	'use strict';

	function attach( button ) {
		var code = document.getElementById( button.getAttribute( 'data-target' ) );
		var status = document.getElementById( button.getAttribute( 'data-status' ) );
		if ( ! code ) {
			return;
		}

		function say( text ) {
			if ( status ) {
				status.textContent = text;
			}
		}

		function selectCode() {
			var range = document.createRange();
			range.selectNodeContents( code );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
		}

		function fallback() {
			selectCode();
			var copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}
			say( copied ? button.getAttribute( 'data-copied' ) : button.getAttribute( 'data-manual' ) );
		}

		button.addEventListener( 'click', function () {
			var text = code.textContent.trim();
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						say( button.getAttribute( 'data-copied' ) );
					},
					fallback
				);
				return;
			}
			fallback();
		} );
	}

	var buttons = document.querySelectorAll( '[data-outlinepay-copy]' );
	for ( var i = 0; i < buttons.length; i++ ) {
		attach( buttons[ i ] );
	}
} )();
