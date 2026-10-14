<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Signature;

class SignatureTest extends TestCase {

	const SECRET = 'olp_isec_testsecret';
	const BODY   = '{"id":"ppay_abc","status":"paid","order_id":"42"}';
	const NOW    = 1790000000;

	public function test_accepts_a_fresh_valid_signature() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW - 10 );
		$this->assertTrue( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_accepts_the_edges_of_the_tolerance() {
		$this->assertTrue( Signature::verify( Signature::header( self::SECRET, self::BODY, self::NOW - 300 ), self::SECRET, self::BODY, self::NOW ) );
		$this->assertTrue( Signature::verify( Signature::header( self::SECRET, self::BODY, self::NOW + 300 ), self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_a_stale_signature() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW - 301 );
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_a_signature_from_the_future() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW + 301 );
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_a_tampered_body() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW );
		$this->assertFalse( Signature::verify( $header, self::SECRET, str_replace( '42', '43', self::BODY ), self::NOW ) );
	}

	public function test_rejects_a_tampered_signature() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW );
		$last   = substr( $header, -1 );
		$header = substr( $header, 0, -1 ) . ( '0' === $last ? '1' : '0' );
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_a_tampered_timestamp() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW );
		$header = str_replace( 't=' . self::NOW, 't=' . ( self::NOW - 1 ), $header );
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_the_wrong_secret() {
		$header = Signature::header( 'olp_isec_other', self::BODY, self::NOW );
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_rejects_a_missing_header_or_secret() {
		$header = Signature::header( self::SECRET, self::BODY, self::NOW );
		$this->assertFalse( Signature::verify( '', self::SECRET, self::BODY, self::NOW ) );
		$this->assertFalse( Signature::verify( null, self::SECRET, self::BODY, self::NOW ) );
		$this->assertFalse( Signature::verify( $header, '', self::BODY, self::NOW ) );
		$this->assertFalse( Signature::verify( $header, null, self::BODY, self::NOW ) );
	}

	/**
	 * @dataProvider malformed_headers
	 */
	public function test_rejects_malformed_headers( $header ) {
		$this->assertFalse( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function malformed_headers() {
		$valid = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );
		return array(
			'no timestamp'      => array( 'v1=' . $valid ),
			'no signature'      => array( 't=' . self::NOW ),
			'empty signature'   => array( 't=' . self::NOW . ',v1=' ),
			'zero timestamp'    => array( 't=0,v1=' . $valid ),
			'word timestamp'    => array( 't=now,v1=' . $valid ),
			'negative time'     => array( 't=-' . self::NOW . ',v1=' . $valid ),
			'garbage'           => array( 'garbage' ),
			'only commas'       => array( ',,,' ),
			'wrong scheme name' => array( 't=' . self::NOW . ',v0=' . $valid ),
			'array'             => array( array( 't' => self::NOW ) ),
		);
	}

	public function test_accepts_when_any_v1_matches() {
		$valid  = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );
		$header = 't=' . self::NOW . ',v1=' . str_repeat( 'a', 64 ) . ',v1=' . $valid;
		$this->assertTrue( Signature::verify( $header, self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_tolerates_spaces_after_commas() {
		$valid = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );
		$this->assertTrue( Signature::verify( 't=' . self::NOW . ', v1=' . $valid, self::SECRET, self::BODY, self::NOW ) );
	}
}
