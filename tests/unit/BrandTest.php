<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Admin_Page;
use OutlinePay\WooCommerce\Brand;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Gateway;
use OutlinePay\WooCommerce\Order_Sync;
use OutlinePay\WooCommerce\Settings;

/**
 * The provider's brand inside the plugin: read from the stored record,
 * OutlinePay before connect.
 */
class BrandTest extends TestCase {

	/** @var array Options by name. */
	private $options = array();

	/** @var string Locale of the admin reading. */
	private $user_locale = 'en_US';

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Credentials::reset_cache();
		$this->options = array(
			'outlinepay_installation' => array(
				'status'   => 'connected',
				'provider' => 'cookiepay',
			),
			'outlinepay_credentials'  => Credentials::seal(
				json_encode(
					array(
						'token'          => 'olp_it_test_token',
						'signing_secret' => 'olp_isec_test',
					)
				),
				Credentials::key()
			),
		);
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		$locale = &$this->user_locale;
		Functions\when( 'get_user_locale' )->alias(
			static function () use ( &$locale ) {
				return $locale;
			}
		);
	}

	protected function tearDown(): void {
		Credentials::reset_cache();
		parent::tearDown();
	}

	private function brand( array $brand ) {
		$this->options['outlinepay_installation']['brand'] = $brand;
	}

	private static function full() {
		return array(
			'name'        => 'CookiePay',
			'name_ko'     => '쿠키페이',
			'logo_url'    => 'https://api.example/brands/cookiepay.png',
			'accent'      => '#1B54B3',
			'support_url' => 'https://support.example/contact',
		);
	}

	private function disconnect() {
		unset( $this->options['outlinepay_credentials'] );
		Credentials::reset_cache();
	}

	private function header() {
		ob_start();
		Admin_Page::render_header();
		return ob_get_clean();
	}

	public function test_without_a_brand_object_the_provider_key_names_it() {
		$this->assertTrue( Brand::connected() );
		$this->assertSame( 'CookiePay', Brand::name() );
		$this->assertSame( 'CookiePay', Brand::name_in( 'ko_KR' ), 'no name_ko: the name' );
		$this->assertNull( Brand::logo_url() );
		$this->assertNull( Brand::accent() );
		$this->assertSame( Settings::PROVIDER_SUPPORT_URLS['cookiepay'], Brand::support_url() );
	}

	public function test_a_full_brand_object_is_read_as_sent() {
		$this->brand( self::full() );
		$this->assertSame( 'CookiePay', Brand::name() );
		$this->assertSame( '쿠키페이', Brand::name_in( 'ko_KR' ) );
		$this->assertSame( '쿠키페이', Brand::name_in( 'ko' ) );
		$this->assertSame( 'CookiePay', Brand::name_in( 'en_US' ) );
		$this->assertSame( 'https://api.example/brands/cookiepay.png', Brand::logo_url() );
		$this->assertSame( '#1b54b3', Brand::accent() );
		$this->assertSame( 'https://support.example/contact', Brand::support_url() );
	}

	public function test_the_brand_object_is_kept_when_the_installation_is_saved() {
		$saved = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$saved ) {
				$saved[ $name ] = $value;
				return true;
			}
		);
		Settings::save_installation( array( 'status' => 'connected', 'brand' => self::full(), 'token' => 'olp_it_secret' ) );
		$this->assertSame( self::full(), $saved['outlinepay_installation']['brand'] );
		$this->assertArrayNotHasKey( 'token', $saved['outlinepay_installation'] );
	}

	public function test_before_connect_everything_is_outlinepay() {
		$this->brand( self::full() );
		$this->disconnect();
		$this->assertFalse( Brand::connected() );
		$this->assertSame( 'OutlinePay', Brand::name() );
		$this->assertSame( 'OutlinePay', Brand::name_in( 'ko_KR' ) );
		$this->assertNull( Brand::logo_url() );
		$this->assertNull( Brand::accent() );
	}

	public function test_a_connection_with_no_known_provider_stays_outlinepay() {
		$this->options['outlinepay_installation']['provider'] = '';
		$this->assertFalse( Brand::connected() );
		$this->assertSame( 'OutlinePay', Brand::name() );
	}

	public function test_a_logo_that_is_not_https_is_dropped() {
		foreach ( array( 'http://api.example/logo.png', 'javascript:alert(1)', '//api.example/logo.png', 'https://', 42, null ) as $url ) {
			$this->brand( array( 'name' => 'CookiePay', 'logo_url' => $url ) );
			$this->assertNull( Brand::logo_url(), var_export( $url, true ) );
		}
	}

	public function test_the_accent_must_be_six_hex_digits() {
		foreach ( array( 'red', '#fff', '#12345g', '#1b54b3;color:red', '1b54b3', ' #1b54b3', array( '#1b54b3' ) ) as $accent ) {
			$this->brand( array( 'name' => 'CookiePay', 'accent' => $accent ) );
			$this->assertNull( Brand::accent(), var_export( $accent, true ) );
		}
		$this->brand( array( 'name' => 'CookiePay', 'accent' => '#00aa11' ) );
		$this->assertSame( '#00aa11', Brand::accent() );
	}

	public function test_a_name_that_is_not_a_plain_string_falls_back() {
		$this->brand( array( 'name' => array( 'x' ), 'name_ko' => 7 ) );
		$this->assertSame( 'CookiePay', Brand::name() );
		$this->assertSame( 'CookiePay', Brand::name_in( 'ko_KR' ) );
		$this->brand( array( 'name' => "  Cookie\nPay  " ) );
		$this->assertSame( 'Cookie Pay', Brand::name() );
	}

	public function test_the_header_before_connect_is_outlinepay() {
		$this->brand( self::full() );
		$this->disconnect();
		$this->assertSame( '<h1>OutlinePay</h1>', $this->header() );
	}

	public function test_the_header_without_a_logo_is_a_word_mark() {
		$html = $this->header();
		$this->assertStringContainsString( '<h1 class="outlinepay-brand"><span class="outlinepay-wordmark" style="font-weight:700;font-size:1.15em">CookiePay</span></h1>', $html );
		$this->assertStringContainsString( '>Powered by OutlinePay</p>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<style>', $html, 'no accent, no button color' );
	}

	public function test_the_header_with_a_logo_shows_the_logo_and_the_accent() {
		$this->brand( self::full() );
		$html = $this->header();
		$this->assertStringContainsString( '<img src="https://api.example/brands/cookiepay.png" alt="CookiePay" style="max-height:32px;width:auto;vertical-align:middle">', $html );
		$this->assertStringNotContainsString( 'outlinepay-wordmark', $html );
		$this->assertStringContainsString( '.outlinepay-admin .button-primary{background:#1b54b3;border-color:#1b54b3;color:#fff}', $html );
		$this->assertStringContainsString( '>Powered by OutlinePay</p>', $html );
	}

	public function test_a_korean_admin_reads_the_korean_name_and_english_powered_by() {
		$this->brand( self::full() );
		$this->user_locale = 'ko_KR';
		$html              = $this->header();
		$this->assertStringContainsString( 'alt="쿠키페이"', $html );
		$this->assertStringContainsString( '>Powered by OutlinePay</p>', $html );
	}

	public function test_the_header_escapes_the_record() {
		Functions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_attr' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				return htmlspecialchars( (string) $url, ENT_QUOTES );
			}
		);
		$this->brand( array( 'name' => '<script>x</script>"' ) );
		$html = $this->header();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;&quot;', $html );

		$this->brand( array( 'name' => 'A"B', 'logo_url' => 'https://api.example/a.png?x="><script>' ) );
		$html = $this->header();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'alt="A&quot;B"', $html );
	}

	public function test_the_payments_row_names_the_connected_provider() {
		$gateway = new Gateway( 'card' );
		$this->assertSame( 'Get paid through your preferred local payment providers. Connected to CookiePay.', $gateway->method_description );
	}

	public function test_the_payments_row_before_connect_names_no_provider() {
		$this->disconnect();
		$gateway = new Gateway( 'card' );
		$this->assertSame( 'Get paid through your preferred local payment providers.', $gateway->method_description );
	}

	public function test_order_notes_carry_the_brand_name() {
		$this->brand( self::full() );
		$order = new \WC_Order( 42 );
		Order_Sync::note_attempt( $order, 'ppay_test1', 'started' );
		Order_Sync::note_refused( $order, 'unreachable', 'card' );
		$this->assertSame(
			array(
				'CookiePay payment ppay_test1 started. Waiting for the shopper.',
				'CookiePay payment by Credit or debit card could not start: this site could not reach OutlinePay. The shopper saw an error and can try again.',
			),
			$order->notes
		);
	}

	public function test_order_notes_on_a_korean_site_use_the_korean_name() {
		$this->brand( self::full() );
		Functions\when( 'get_locale' )->justReturn( 'ko_KR' );
		$order = new \WC_Order( 42 );
		Order_Sync::note_attempt( $order, 'ppay_test1', 'expired' );
		$this->assertSame( array( '쿠키페이 payment ppay_test1 expired. The shopper did not finish.' ), $order->notes, 'the translation puts the name in Korean text' );
	}
}
