/**
 * OutlinePay page: the Copy button next to the diagnostic code.
 *
 * Plain script, no build step. Uses the Clipboard API where the browser
 * allows it, and otherwise selects the code so the merchant can copy it.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'outlinepay-copy-code' );
	if ( ! button ) {
		return;
	}
	var code = document.getElementById( button.getAttribute( 'data-target' ) );
	var status = document.getElementById( 'outlinepay-copy-status' );
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
} )();
