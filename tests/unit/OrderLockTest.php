<?php
namespace OutlinePay\WooCommerce\Tests;

use OutlinePay\WooCommerce\Order_Lock;

class OrderLockTest extends TestCase {

	/** @var ArrayLockStore */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new ArrayLockStore();
		Order_Lock::set_store( $this->store );
	}

	protected function tearDown(): void {
		Order_Lock::set_store( null );
		parent::tearDown();
	}

	public function test_acquire_and_release() {
		$this->assertTrue( Order_Lock::acquire( 7, 1000, 1 ) );
		$this->assertMatchesRegularExpression( '/^1000\|[0-9a-f]{32}$/', $this->store->rows['outlinepay_lock_7'] );
		Order_Lock::release( 7 );
		$this->assertSame( array(), $this->store->rows );
	}

	public function test_a_fresh_lock_held_by_someone_else_is_not_taken() {
		$this->store->rows['outlinepay_lock_7'] = '1000|other';
		$this->assertFalse( Order_Lock::acquire( 7, 1010, 1 ) );
		$this->assertSame( '1000|other', $this->store->rows['outlinepay_lock_7'] );
	}

	public function test_an_expired_lock_is_taken_over() {
		$this->store->rows['outlinepay_lock_7'] = '1000|other';
		$this->assertTrue( Order_Lock::acquire( 7, 1031, 1 ) );
		$this->assertStringStartsWith( '1031|', $this->store->rows['outlinepay_lock_7'] );
	}

	public function test_only_one_of_two_waiters_takes_over_an_expired_lock() {
		$this->store->rows['outlinepay_lock_7'] = '1000|stale';
		// Another waiter takes it over between our read and our conditional update.
		$this->store->before_replace = static function ( $store ) {
			$store->before_replace           = null;
			$store->rows['outlinepay_lock_7'] = '1031|winner';
		};
		$this->assertFalse( Order_Lock::acquire( 7, 1031, 1 ) );
		$this->assertSame( '1031|winner', $this->store->rows['outlinepay_lock_7'] );
	}

	public function test_release_never_removes_a_newer_holders_lock() {
		$this->assertTrue( Order_Lock::acquire( 7, 1000, 1 ) );
		// We overran; someone took the expired lock over.
		$this->store->rows['outlinepay_lock_7'] = '1040|newer';
		Order_Lock::release( 7 );
		$this->assertSame( '1040|newer', $this->store->rows['outlinepay_lock_7'] );
	}

	public function test_release_without_holding_does_nothing() {
		$this->store->rows['outlinepay_lock_7'] = '1000|other';
		Order_Lock::release( 7 );
		$this->assertSame( '1000|other', $this->store->rows['outlinepay_lock_7'] );
	}

	public function test_expiry_rule() {
		$this->assertFalse( Order_Lock::expired( '1000|x', 1030 ) );
		$this->assertTrue( Order_Lock::expired( '1000|x', 1031 ) );
		$this->assertTrue( Order_Lock::expired( 'garbage', 1000 ) );
	}
}
