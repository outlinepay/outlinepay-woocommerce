<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Notes;
use OutlinePay\WooCommerce\Refunds;

/**
 * Order notes are built and written in the site language, whatever language
 * the request runs in.
 */
class NotesTest extends RefundTestCase {

	/** @var string[] Locale stack: the request's first, switched ones on top. */
	private $locales = array();

	/** @var array Calls to the locale switcher. */
	private $switches = array();

	protected function setUp(): void {
		parent::setUp();
		// The site is Korean. This request runs in the user's English.
		$this->locales = array( 'en_US' );
		$locales       = &$this->locales;
		$switches      = &$this->switches;
		Functions\when( 'get_locale' )->justReturn( 'ko_KR' );
		Functions\when( 'switch_to_locale' )->alias(
			static function ( $locale ) use ( &$locales, &$switches ) {
				$switches[] = 'switch ' . $locale;
				if ( end( $locales ) === $locale ) {
					return false;
				}
				$locales[] = $locale;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->alias(
			static function () use ( &$locales, &$switches ) {
				$switches[] = 'restore';
				array_pop( $locales );
				return end( $locales );
			}
		);
		$korean = array(
			'Refunded %1$s through OutlinePay (refund %2$s).' => '%1$s을(를) OutlinePay로 환불했습니다 (환불 %2$s).',
			'%s won'                                          => '%s원',
		);
		Functions\when( '__' )->alias(
			static function ( $text ) use ( &$locales, $korean ) {
				return 'ko_KR' === end( $locales ) && isset( $korean[ $text ] ) ? $korean[ $text ] : $text;
			}
		);
	}

	public function test_a_note_is_built_in_the_site_locale_and_the_request_locale_comes_back() {
		Notes::write(
			$this->order,
			fn () => sprintf( __( 'Refunded %1$s through OutlinePay (refund %2$s).', 'outlinepay-for-woocommerce' ), Refunds::won( 5000 ), 'pref_one' )
		);

		$this->assertSame( array( '5,000원을(를) OutlinePay로 환불했습니다 (환불 pref_one).' ), $this->order->notes );
		$this->assertSame( array( 'switch ko_KR', 'restore' ), $this->switches );
		$this->assertSame( array( 'en_US' ), $this->locales, 'the request runs in English again' );
	}

	public function test_a_refund_note_from_the_order_screen_is_in_the_site_language() {
		$row             = $this->wc_refund_row( 5000 );
		$this->answers[] = self::json( 200, self::refund( array( 'amount' => 5000, 'refund_id' => (string) $row->get_id() ) ) );

		$this->assertTrue( Refunds::process( 42, 5000 ) );

		$this->assertSame( array( '5,000원을(를) OutlinePay로 환불했습니다 (환불 pref_one).' ), $this->order->notes );
		$this->assertSame( array( 'en_US' ), $this->locales );
	}

	public function test_nothing_is_restored_when_the_request_already_runs_in_the_site_locale() {
		$this->locales = array( 'ko_KR' );
		Notes::write( $this->order, fn () => __( '%s won', 'outlinepay-for-woocommerce' ) );

		$this->assertSame( array( '%s원' ), $this->order->notes );
		$this->assertSame( array( 'switch ko_KR' ), $this->switches );
		$this->assertSame( array( 'ko_KR' ), $this->locales );
	}

	public function test_the_locale_comes_back_when_building_the_note_throws() {
		try {
			Notes::write(
				$this->order,
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'expected the exception' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'boom', $error->getMessage() );
		}
		$this->assertSame( array( 'en_US' ), $this->locales );
		$this->assertSame( array(), $this->order->notes );
	}
}
