<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Gateway;

/**
 * The OutlinePay mark is the gateway's icon for Settings > Payments, and
 * nothing is shown at checkout.
 */
class GatewayIconTest extends TestCase {

	public function test_the_icon_is_the_mark_in_the_plugin() {
		$url = Gateway::icon_url();

		$this->assertStringStartsWith( 'https://', $url );
		$this->assertStringEndsWith( '/assets/images/icon.svg', $url );
		$this->assertFileExists( dirname( OUTLINEPAY_PLUGIN_FILE ) . '/assets/images/icon.svg' );
	}

	public function test_checkout_shows_no_icon() {
		$gateway       = ( new \ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
		$gateway->icon = 'https://store.example/icon.svg';

		$this->assertSame( '', $gateway->get_icon() );
	}
}
