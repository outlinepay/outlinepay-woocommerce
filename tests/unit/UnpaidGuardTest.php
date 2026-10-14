<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Unpaid_Guard;

class UnpaidGuardTest extends TestCase {

	const NOW = 1790000000;

	private function p( $status, $expires_at = null ) {
		return array(
			'id'         => 'ppay_' . $status,
			'status'     => $status,
			'expires_at' => $expires_at,
		);
	}

	public function test_all_attempts_expired_lets_the_order_cancel() {
		$this->assertSame( Unpaid_Guard::CANCEL, Unpaid_Guard::decide( array( $this->p( 'expired' ), $this->p( 'failed' ) ), self::NOW ) );
		$this->assertSame( Unpaid_Guard::CANCEL, Unpaid_Guard::decide( array(), self::NOW ) );
	}

	public function test_a_superseded_attempt_still_payable_keeps_the_order() {
		$future = gmdate( 'c', self::NOW + 3600 );
		$this->assertSame( Unpaid_Guard::KEEP, Unpaid_Guard::decide( array( $this->p( 'expired' ), $this->p( 'superseded', $future ) ), self::NOW ) );
	}

	public function test_a_superseded_attempt_past_its_expiry_does_not_keep_the_order() {
		$past = gmdate( 'c', self::NOW - 60 );
		$this->assertSame( Unpaid_Guard::CANCEL, Unpaid_Guard::decide( array( $this->p( 'expired' ), $this->p( 'superseded', $past ) ), self::NOW ) );
	}

	public function test_a_superseded_attempt_without_a_readable_expiry_keeps_the_order() {
		$this->assertSame( Unpaid_Guard::KEEP, Unpaid_Guard::decide( array( $this->p( 'superseded' ) ), self::NOW ) );
		$this->assertSame( Unpaid_Guard::KEEP, Unpaid_Guard::decide( array( $this->p( 'superseded', 'soon' ) ), self::NOW ) );
	}

	public function test_an_attempt_that_did_not_expire_keeps_the_order() {
		$this->assertSame( Unpaid_Guard::KEEP, Unpaid_Guard::decide( array( $this->p( 'awaiting_payment' ) ), self::NOW ) );
	}

	public function test_a_paid_review_or_deposit_attempt_keeps_and_settles() {
		foreach ( array( 'paid', 'needs_review', 'awaiting_deposit' ) as $status ) {
			$this->assertSame( Unpaid_Guard::KEEP_SETTLE, Unpaid_Guard::decide( array( $this->p( 'expired' ), $this->p( $status ) ), self::NOW ), $status );
		}
	}
}
