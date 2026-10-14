<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Api_Client;
use OutlinePay\WooCommerce\Return_Handler;

class ReturnHandlerTest extends TestCase {

	public function test_the_matching_key_passes() {
		$this->assertTrue( Return_Handler::key_matches( 'wc_order_abc123', 'wc_order_abc123' ) );
	}

	public function test_another_key_fails() {
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', 'wc_order_abc124' ) );
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', 'wc_order_abc12' ) );
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', 'WC_ORDER_ABC123' ) );
	}

	public function test_empty_or_missing_keys_fail() {
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', '' ) );
		$this->assertFalse( Return_Handler::key_matches( '', '' ) );
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', null ) );
		$this->assertFalse( Return_Handler::key_matches( null, 'wc_order_abc123' ) );
		$this->assertFalse( Return_Handler::key_matches( 'wc_order_abc123', array( 'wc_order_abc123' ) ) );
	}

	public function test_only_well_formed_payment_ids_are_followed() {
		$this->assertTrue( Api_Client::valid_payment_id( 'ppay_2bXk9Qw' ) );
		$this->assertFalse( Api_Client::valid_payment_id( 'ppay_' ) );
		$this->assertFalse( Api_Client::valid_payment_id( 'ppay_abc/../installation' ) );
		$this->assertFalse( Api_Client::valid_payment_id( 'pay_abc' ) );
		$this->assertFalse( Api_Client::valid_payment_id( 'ppay_abc?x=1' ) );
		$this->assertFalse( Api_Client::valid_payment_id( 42 ) );
	}
}
