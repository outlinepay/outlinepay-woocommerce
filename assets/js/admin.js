/**
 * OutlinePay page and order screen: the Copy buttons for the diagnostic
 * code and the provider's order number, the shopper cancel switch and the
 * payment gateway choice under Payment methods.
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

	// Shopper cancel: the status boxes belong to the switch above them, so
	// they are greyed out while it is off.
	var cancelSwitch = document.getElementById( 'outlinepay-cancel-enabled' );
	var cancelStatuses = document.querySelector( '[data-outlinepay-cancel-statuses]' );
	if ( cancelSwitch && cancelStatuses ) {
		var cancelBoxes = cancelStatuses.querySelectorAll( 'input[type="checkbox"]' );
		var syncCancel = function () {
			var off = ! cancelSwitch.checked;
			for ( var j = 0; j < cancelBoxes.length; j++ ) {
				cancelBoxes[ j ].disabled = off;
			}
			cancelStatuses.style.opacity = off ? '0.5' : '';
		};
		cancelSwitch.addEventListener( 'change', syncCancel );
		syncCancel();
	}

	// Payment methods: choosing a contract's payment gateway shows that
	// gateway's method tiles and hides the others. Hidden boxes are disabled,
	// so the form sends only what the merchant sees. The is-checked classes
	// mirror the ticks for browsers without CSS :has().
	var pgChoices = document.querySelectorAll( '[data-outlinepay-pg-choice]' );
	var pgRows = document.querySelectorAll( '[data-outlinepay-pg-rows]' );
	var markChecked = function ( input ) {
		var label = input.closest( 'label' );
		if ( label ) {
			label.classList.toggle( 'is-checked', input.checked );
		}
	};
	var showPg = function ( choice ) {
		var contract = choice.getAttribute( 'data-contract' );
		for ( var k = 0; k < pgRows.length; k++ ) {
			if ( pgRows[ k ].getAttribute( 'data-contract' ) !== contract ) {
				continue;
			}
			var on = pgRows[ k ].getAttribute( 'data-pg' ) === choice.value;
			pgRows[ k ].style.display = on ? '' : 'none';
			var boxes = pgRows[ k ].querySelectorAll( 'input' );
			for ( var m = 0; m < boxes.length; m++ ) {
				boxes[ m ].disabled = ! on;
			}
		}
		var group = document.querySelectorAll( '[data-outlinepay-pg-choice][data-contract="' + contract + '"]' );
		for ( var g = 0; g < group.length; g++ ) {
			markChecked( group[ g ] );
		}
	};
	for ( var n = 0; n < pgChoices.length; n++ ) {
		pgChoices[ n ].addEventListener( 'change', function ( event ) {
			if ( event.target.checked ) {
				showPg( event.target );
			}
		} );
	}
	var methodBoxes = document.querySelectorAll( '.outlinepay-method input[type="checkbox"]' );
	for ( var b = 0; b < methodBoxes.length; b++ ) {
		methodBoxes[ b ].addEventListener( 'change', function ( event ) {
			markChecked( event.target );
		} );
	}
} )();
