<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Blocks_Method;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Settings;

/**
 * Method names come from the plugin's translations, not from the server's
 * Korean labels. Nothing shows under them at checkout.
 */
class MethodNamesTest extends TestCase {

	const ENGLISH = array(
		'card'             => 'Credit or debit card',
		'foreign_card_krw' => 'International card (KRW)',
		'bank_transfer'    => 'Bank transfer',
		'virtual_account'  => 'Virtual account',
		'kakaopay'         => 'KakaoPay',
		'naverpay'         => 'Naver Pay',
		'carrier_billing'  => 'Mobile carrier billing',
	);

	const KOREAN = array(
		'Credit or debit card'     => '신용카드',
		'International card (KRW)' => '해외카드 (원화 결제)',
		'Bank transfer'            => '실시간 계좌이체',
		'Virtual account'          => '가상계좌',
		'KakaoPay'                 => '카카오페이',
		'Naver Pay'                => '네이버페이',
		'Mobile carrier billing'   => '휴대폰 결제',
	);

	/** @var array Options by name. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$this->options = array(
			'outlinepay_installation' => array(
				'status'   => 'connected',
				'provider' => 'cookiepay',
			),
			'outlinepay_methods'      => array(
				'methods' => array_map(
					static function ( $method ) {
						return array(
							'method'   => $method,
							'label_ko' => '서버 라벨',
						);
					},
					Settings::METHODS
				),
			),
		);
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
	}

	public function test_every_method_has_an_english_name() {
		$this->assertSame( Settings::METHODS, array_keys( self::ENGLISH ) );
	}

	public function test_gateway_and_blocks_titles_are_the_english_names() {
		foreach ( self::ENGLISH as $method => $name ) {
			$gateway = new Gateway( $method );
			$this->assertSame( $name, $gateway->title, $method );
			$this->assertSame( 'OutlinePay: ' . $name, $gateway->method_title, $method );
			$this->assertSame( '', $gateway->description, $method );

			$data = ( new Blocks_Method( $method ) )->get_payment_method_data();
			$this->assertSame( $name, $data['title'], $method );
			$this->assertArrayNotHasKey( 'description', $data, $method );
		}
	}

	public function test_the_server_label_is_not_shown() {
		$this->assertSame( 'Credit or debit card', Settings::method_name( 'card' ) );
		$this->assertNotSame( '서버 라벨', ( new Gateway( 'card' ) )->title );
	}

	public function test_unknown_method_falls_back_to_its_key() {
		$this->assertSame( 'paypay', Settings::method_name( 'paypay' ) );
	}

	public function test_provider_name() {
		$this->assertSame( 'CookiePay', Settings::provider_name( 'cookiepay' ) );
		$this->assertSame( 'Bepsa', Settings::provider_name( 'bepsa' ) );
		$this->assertSame( '', Settings::provider_name( '' ) );
	}

	public function test_korean_translations_cover_every_name() {
		$po = file_get_contents( dirname( __DIR__, 2 ) . '/languages/outlinepay-for-woocommerce-ko_KR.po' );
		foreach ( self::KOREAN as $english => $korean ) {
			$this->assertStringContainsString( 'msgid "' . $english . "\"\nmsgstr \"" . $korean . '"', $po, $english );
		}
		$pot = file_get_contents( dirname( __DIR__, 2 ) . '/languages/outlinepay-for-woocommerce.pot' );
		foreach ( array_keys( self::KOREAN ) as $english ) {
			$this->assertStringContainsString( 'msgid "' . $english . '"', $pot, $english );
		}
	}
}
