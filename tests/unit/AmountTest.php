<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Amount;

class AmountTest extends TestCase {

	/**
	 * @dataProvider whole_amounts
	 */
	public function test_converts_whole_won( $total, $expected ) {
		$this->assertSame( $expected, Amount::to_krw( $total ) );
	}

	public function whole_amounts() {
		return array(
			'string'             => array( '25000', 25000 ),
			'string with zeros'  => array( '25000.00', 25000 ),
			'string with point'  => array( '25000.', 25000 ),
			'integer'            => array( 25000, 25000 ),
			'float'              => array( 25000.0, 25000 ),
			'padded'             => array( ' 1000 ', 1000 ),
			'leading zeros'      => array( '00100', 100 ),
			'one won'            => array( '1', 1 ),
			'the maximum'        => array( '1000000000000', 1000000000000 ),
		);
	}

	/**
	 * @dataProvider refused_amounts
	 */
	public function test_refuses_anything_else( $total ) {
		$this->assertNull( Amount::to_krw( $total ) );
	}

	public function refused_amounts() {
		return array(
			'fraction string'   => array( '25000.5' ),
			'tiny fraction'     => array( '25000.001' ),
			'fraction float'    => array( 25000.5 ),
			'zero'              => array( '0' ),
			'zero float'        => array( 0.0 ),
			'negative'          => array( '-100' ),
			'negative int'      => array( -100 ),
			'empty'             => array( '' ),
			'words'             => array( 'abc' ),
			'thousands comma'   => array( '25,000' ),
			'exponent'          => array( '2.5e4' ),
			'above the maximum' => array( '1000000000001' ),
			'huge'              => array( '99999999999999999999' ),
			'null'              => array( null ),
			'array'             => array( array( 1 ) ),
			'infinity'          => array( INF ),
			'nan'               => array( NAN ),
		);
	}
}
