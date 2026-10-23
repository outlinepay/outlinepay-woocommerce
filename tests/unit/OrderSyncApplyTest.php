<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Order_Lock;
use OutlinePay\WooCommerce\Order_Sync;

/**
 * Order_Sync::apply against a stand-in order: gateway switches, payments from
 * another copy of the store, test mode notes, idempotency.
 */
class OrderSyncApplyTest extends TestCase {

	/** @var \WC_Order */
	private $order;

	/** @var ArrayLockStore */
	private $locks;

	protected function setUp(): void {
		parent::setUp();
		$this->order = new \WC_Order( 42 );
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine' );
		$order       = $this->order;
		$this->locks = new ArrayLockStore();
		Order_Lock::set_store( $this->locks );

		Functions\stubTranslationFunctions();
		Functions\when( 'wc_get_order' )->alias(
			static function () use ( $order ) {
				return $order;
			}
		);
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'outlinepay_installation' === $name ) {
					return array( 'id' => 'inst_1' );
				}
				if ( 'outlinepay_methods' === $name ) {
					return array(
						'methods' => array(
							array(
								'method'   => 'card',
								'label_ko' => '신용카드',
							),
						),
					);
				}
				return $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Order_Lock::set_store( null );
		parent::tearDown();
	}

	private function payment( array $overrides = array() ) {
		return array_merge(
			array(
				'id'             => 'ppay_mine',
				'installation'   => 'inst_1',
				'mode'           => 'live',
				'order_id'       => '42',
				'amount'         => 25000,
				'currency'       => 'KRW',
				'payment_method' => 'card',
				'status'         => 'paid',
				'transaction_id' => 'CP123',
			),
			$overrides
		);
	}

	public function test_a_paid_attempt_completes_the_order() {
		$this->assertSame( Order_Sync::COMPLETE, Order_Sync::apply( $this->order, $this->payment() ) );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertSame( 'CP123', $this->order->transaction );
		$this->assertSame( array(), $this->locks->rows, 'the lock is released' );
	}

	public function test_applying_twice_changes_nothing_the_second_time() {
		Order_Sync::apply( $this->order, $this->payment() );
		$notes = count( $this->order->notes );
		Order_Sync::apply( $this->order, $this->payment() );
		$this->assertSame( 1, $this->order->completed_calls );
		$this->assertCount( $notes, $this->order->notes );
	}

	public function test_an_order_switched_to_another_gateway_is_still_ours() {
		$this->order->payment_method = 'bacs';
		$this->assertTrue( Order_Sync::is_ours( $this->order ) );
		$this->order->meta = array();
		$this->assertFalse( Order_Sync::is_ours( $this->order ) );
	}

	public function test_a_paid_old_window_on_an_unpaid_switched_order_completes_it_and_takes_the_gateway_back() {
		$this->order->payment_method = 'bacs';
		$this->order->status         = 'on-hold';

		$this->assertSame( Order_Sync::COMPLETE, Order_Sync::apply( $this->order, $this->payment() ) );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertSame( 'outlinepay_card', $this->order->payment_method );
		$this->assertSame( 'Credit or debit card', $this->order->method_title );
		$this->assertStringContainsString( 'set back to OutlinePay', implode( "\n", $this->order->notes ) );
	}

	public function test_a_paid_old_window_on_an_order_paid_by_another_gateway_only_notes_the_double_payment() {
		$this->order->payment_method = 'bacs';
		$this->order->status         = 'processing';

		Order_Sync::apply( $this->order, $this->payment() );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertSame( 'bacs', $this->order->payment_method );
		$this->assertSame( 0, $this->order->completed_calls );
		$this->assertSame( 'double', $this->order->meta[ Order_Sync::META_SETTLED ] );
		$this->assertStringContainsString( 'paid twice', implode( "\n", $this->order->notes ) );
		$this->assertStringContainsString( 'paid twice', implode( "\n", $this->log_lines ) );
	}

	public function test_a_paid_payment_this_store_did_not_open_holds_the_order() {
		$decision = Order_Sync::apply( $this->order, $this->payment( array( 'id' => 'ppay_fromclone' ) ) );
		$this->assertSame( Order_Sync::HOLD_FOREIGN, $decision );
		$this->assertSame( 'on-hold', $this->order->status );
		$this->assertSame( 0, $this->order->completed_calls );
		$this->assertSame( 'foreign', $this->order->meta[ Order_Sync::META_SETTLED ] );
		$this->assertStringContainsString( 'another copy of this store', implode( "\n", $this->order->notes ) );
		$this->assertStringContainsString( 'another copy of this store', implode( "\n", $this->log_lines ) );
	}

	public function test_a_status_change_note_and_its_woocommerce_prefix_share_the_site_language() {
		// The site is Korean, this request runs in English.
		$locales = array( 'en_US' );
		Functions\when( 'get_locale' )->justReturn( 'ko_KR' );
		Functions\when( 'switch_to_locale' )->alias(
			static function ( $locale ) use ( &$locales ) {
				if ( end( $locales ) === $locale ) {
					return false;
				}
				$locales[] = $locale;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () use ( &$locales ) {
				array_pop( $locales );
				return end( $locales );
			}
		);
		$korean = array(
			'Order status changed from %1$s to %2$s.' => '주문 상태가 %1$s에서 %2$s(으)로 변경되었습니다.',
			'OutlinePay payment %s reports paid, but the amount or currency does not match this order. Check it before shipping.' => 'KO %s',
		);
		Functions\when( '__' )->alias(
			static function ( $text ) use ( &$locales, $korean ) {
				return 'ko_KR' === end( $locales ) && isset( $korean[ $text ] ) ? $korean[ $text ] : $text;
			}
		);
		$this->order->status_prefix = static function ( $from, $to ) {
			return sprintf( __( 'Order status changed from %1$s to %2$s.', 'woocommerce' ), $from, $to ); // phpcs:ignore
		};

		$this->assertSame( Order_Sync::HOLD_MISMATCH, Order_Sync::apply( $this->order, $this->payment( array( 'amount' => 1 ) ) ) );

		$this->assertSame( array( '주문 상태가 pending에서 on-hold(으)로 변경되었습니다. KO ppay_mine' ), $this->order->notes );
		$this->assertSame( array( 'en_US' ), $locales, 'the request runs in English again' );
	}

	public function test_a_foreign_payment_on_a_paid_order_only_adds_a_note() {
		$this->order->status = 'processing';
		Order_Sync::apply( $this->order, $this->payment( array( 'id' => 'ppay_fromclone' ) ) );
		$this->assertSame( 'processing', $this->order->status );
		$this->assertCount( 1, $this->order->notes );
	}

	public function test_an_order_without_attempts_never_completes_from_an_order_already_paid_refusal() {
		// The 409 order_already_paid path: the server names a paid payment for
		// this order id that this store never opened.
		$this->order->meta = array();
		$this->order->payment_method = 'outlinepay_card';
		Order_Sync::apply( $this->order, $this->payment( array( 'id' => 'ppay_other' ) ) );
		$this->assertSame( 'on-hold', $this->order->status );
		$this->assertSame( 0, $this->order->completed_calls );
	}

	public function test_test_mode_payments_say_so_in_every_note_and_are_marked() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine', 'ppay_va', 'ppay_review' );
		Order_Sync::apply( $this->order, $this->payment( array( 'mode' => 'test' ) ) );
		$this->assertSame( 'test', $this->order->meta[ Order_Sync::META_MODE ] );
		$this->assertNotEmpty( $this->order->notes );
		foreach ( $this->order->notes as $note ) {
			$this->assertStringEndsWith( '(test mode)', $note );
		}
		$this->assertSame( 'processing', $this->order->status, 'test payments are not held' );

		$held = new \WC_Order( 43 );
		$held->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_va' );
		Functions\when( 'wc_get_order' )->justReturn( $held );
		Order_Sync::apply(
			$held,
			$this->payment(
				array(
					'id'              => 'ppay_va',
					'order_id'        => '43',
					'mode'            => 'test',
					'status'          => 'awaiting_deposit',
					'virtual_account' => array(
						'bank'           => 'KB',
						'account_number' => '9123',
						'deadline'       => '2026-10-17T00:00:00Z',
					),
				)
			)
		);
		$this->assertStringEndsWith( '(test mode)', end( $held->notes ) );
	}

	public function test_live_payments_carry_no_test_suffix() {
		Order_Sync::apply( $this->order, $this->payment() );
		foreach ( $this->order->notes as $note ) {
			$this->assertStringNotContainsString( 'test mode', $note );
		}
		$this->assertSame( 'live', $this->order->meta[ Order_Sync::META_MODE ] );
	}

	public function test_a_busy_order_is_reported_busy() {
		$this->locks->rows['outlinepay_lock_42'] = time() . '|someoneelse';
		// Fewer attempts keep the test fast: acquire directly with one try.
		$this->assertFalse( Order_Lock::acquire( 42, time(), 1 ) );
	}

	public function test_a_duplicate_on_an_order_held_for_a_foreign_payment_is_flagged_as_likely_the_real_one() {
		$this->order->status                          = 'on-hold';
		$this->order->meta[ Order_Sync::META_HOLD ]    = 'foreign';
		$this->order->meta[ Order_Sync::META_SETTLED ] = 'foreign';
		Order_Sync::apply( $this->order, $this->payment( array( 'status' => 'duplicate' ) ) );
		$this->assertSame( 'on-hold', $this->order->status, 'stays on hold' );
		$this->assertSame( 0, $this->order->completed_calls, 'not auto-completed' );
		$note = end( $this->order->notes );
		$this->assertStringContainsString( 'probably the shopper', $note );
		$this->assertStringNotContainsString( 'Refund the second payment', $note );
	}

	public function test_a_plain_duplicate_keeps_the_refund_note() {
		$this->order->status = 'processing';
		Order_Sync::apply( $this->order, $this->payment( array( 'status' => 'duplicate' ) ) );
		$this->assertStringContainsString( 'Refund the second payment', end( $this->order->notes ) );
	}

	public function test_an_order_another_gateway_already_charged_is_never_taken_back() {
		// 3-D Secure race: the other gateway took the money, the status has not caught up.
		$this->order->payment_method = 'stripe';
		$this->order->status         = 'pending';
		$this->order->date_paid      = '2026-10-14T10:00:00+00:00';

		Order_Sync::apply( $this->order, $this->payment() );
		$this->assertSame( 'stripe', $this->order->payment_method );
		$this->assertSame( 0, $this->order->completed_calls );
		$this->assertSame( 'double', $this->order->meta[ Order_Sync::META_SETTLED ] );
		$this->assertStringContainsString( 'paid twice', end( $this->order->notes ) );
	}

	public function test_a_transaction_id_from_another_gateway_counts_as_paid_too() {
		$this->order->payment_method = 'stripe';
		$this->order->transaction    = 'pi_123';
		Order_Sync::apply( $this->order, $this->payment() );
		$this->assertSame( 0, $this->order->completed_calls );
	}

	public function test_the_mode_is_written_only_when_outlinepay_completes_the_order() {
		$this->order->meta[ Order_Sync::META_ATTEMPTS ] = array( 'ppay_mine' );
		Order_Sync::apply(
			$this->order,
			$this->payment(
				array(
					'mode'            => 'test',
					'status'          => 'awaiting_deposit',
					'virtual_account' => array( 'account_number' => '9' ),
				)
			)
		);
		$this->assertArrayNotHasKey( Order_Sync::META_MODE, $this->order->meta );
	}

	public function test_test_payment_line_needs_our_gateway_and_a_test_completion() {
		$this->order->meta[ Order_Sync::META_MODE ] = 'test';
		$this->assertTrue( \OutlinePay\WooCommerce\Order_Display::is_test_payment( $this->order ) );
		$this->order->payment_method = 'bacs';
		$this->assertFalse( \OutlinePay\WooCommerce\Order_Display::is_test_payment( $this->order ) );
	}
}
