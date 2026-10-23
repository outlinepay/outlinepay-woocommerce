/**
 * OutlinePay methods for the WooCommerce Checkout block.
 *
 * Plain script, no build step. Registers every method the server side made
 * active (each has its own settings entry, outlinepay_<method>_data).
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var methods = [
		'card',
		'foreign_card_krw',
		'bank_transfer',
		'virtual_account',
		'kakaopay',
		'naverpay',
		'carrier_billing',
	];

	methods.forEach( function ( method ) {
		var name = 'outlinepay_' + method;
		var data = settings.getSetting( name + '_data', null );
		if ( ! data ) {
			return;
		}

		var title = data.title || method;

		// The label is all the shopper needs. Nothing shows under it.
		var Content = function () {
			return null;
		};
		var Label = function () {
			return element.createElement( 'span', { className: 'outlinepay-label' }, title );
		};

		registry.registerPaymentMethod( {
			name: name,
			label: element.createElement( Label ),
			ariaLabel: title,
			content: element.createElement( Content ),
			edit: element.createElement( Content ),
			canMakePayment: function () {
				return true;
			},
			supports: { features: data.supports || [ 'products' ] },
		} );
	} );
} )();
