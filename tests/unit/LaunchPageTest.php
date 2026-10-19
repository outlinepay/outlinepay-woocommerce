<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Launch_Page;
use OutlinePay\WooCommerce\Order_Sync;

/**
 * The store's launch page: what it accepts, what it refuses, and that every
 * value it prints is escaped.
 */
class LaunchPageTest extends TestCase {

	const NOW = 1760000000;

	/** @var \WC_Order */
	private $order;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->order = new \WC_Order( 42 );
		$order       = $this->order;
		Functions\when( 'wc_get_order_id_by_order_key' )->justReturn( 42 );
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $order ) {
				return (int) $id === $order->get_id() ? $order : false;
			}
		);
	}

	private static function window( array $overrides = array() ) {
		return array_merge(
			array(
				'type'           => 'form',
				'action'         => 'https://pay.example.test/pay/start',
				'method'         => 'post',
				'accept_charset' => 'euc-kr',
				'width'          => 548,
				'height'         => 550,
				'fields'         => array(
					array( 'ORDERNO', '42' ),
					array( 'AMOUNT', '25000' ),
					array( 'PRODUCTNAME', '쿠키 상점 #42' ),
				),
				'expires_at'     => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + 600 ),
			),
			$overrides
		);
	}

	private function store_launch( array $overrides = array() ) {
		$launch = Launch_Page::from_window( self::window( $overrides ), 'ppay_abc', 'Shop #42', self::NOW );
		$this->assertNotNull( $launch );
		$this->order->meta[ Order_Sync::META_LAUNCH ] = $launch;
		return $launch;
	}

	private static function page( array $overrides = array() ) {
		return array_merge(
			array(
				'store'        => 'Shop',
				'order_number' => '42',
				'amount_html'  => '<span class="amount">25,000원</span>',
				'method_label' => '신용카드',
				'back_url'     => 'https://shop.example/checkout/order-pay/42/?pay_for_order=true&key=wc_order_abc123',
				'lang'         => 'ko-KR',
			),
			$overrides
		);
	}

	// ---- the action ----

	public function test_an_https_action_is_accepted() {
		$this->assertTrue( Launch_Page::safe_action( 'https://pay.example.test/pay/start' ) );
		$this->assertTrue( Launch_Page::safe_action( 'HTTPS://pay.example.test/pay/start?a=1&b=2' ) );
	}

	public function test_javascript_data_and_plain_http_actions_are_refused() {
		$this->assertFalse( Launch_Page::safe_action( 'javascript:alert(1)' ) );
		$this->assertFalse( Launch_Page::safe_action( 'JavaScript:alert(1)//https://x' ) );
		$this->assertFalse( Launch_Page::safe_action( 'data:text/html,<script>alert(1)</script>' ) );
		$this->assertFalse( Launch_Page::safe_action( 'http://pay.example.test/pay/start' ) );
		$this->assertFalse( Launch_Page::safe_action( '//pay.example.test/pay/start' ) );
		$this->assertFalse( Launch_Page::safe_action( '/pay/start' ) );
	}

	public function test_actions_that_could_break_out_or_hide_a_host_are_refused() {
		$this->assertFalse( Launch_Page::safe_action( 'https://pay.example.test/" onmouseover="alert(1)' ) );
		$this->assertFalse( Launch_Page::safe_action( "https://pay.example.test/'><script>" ) );
		$this->assertFalse( Launch_Page::safe_action( "https://pay.example.test/\npath" ) );
		$this->assertFalse( Launch_Page::safe_action( 'https:///no-host' ) );
		$this->assertFalse( Launch_Page::safe_action( 'https://user:pass@pay.example.test/' ) );
		$this->assertFalse( Launch_Page::safe_action( '' ) );
		$this->assertFalse( Launch_Page::safe_action( array( 'https://pay.example.test/' ) ) );
	}

	// ---- the stored record ----

	public function test_a_form_window_becomes_a_launch_record_in_field_order() {
		$launch = Launch_Page::from_window( self::window( array( 'method' => 'POST', 'accept_charset' => 'EUC-KR' ) ), 'ppay_abc', 'Shop #42', self::NOW );

		$this->assertSame( 'ppay_abc', $launch['payment_id'] );
		$this->assertSame( 'https://pay.example.test/pay/start', $launch['action'] );
		$this->assertSame( 'post', $launch['method'] );
		$this->assertSame( 'euc-kr', $launch['accept_charset'] );
		$this->assertSame( 548, $launch['width'] );
		$this->assertSame( 550, $launch['height'] );
		$this->assertSame( array( 'ORDERNO', 'AMOUNT', 'PRODUCTNAME' ), array_column( $launch['fields'], 0 ) );
		$this->assertSame( '쿠키 상점 #42', $launch['fields'][2][1] );
		$this->assertSame( self::NOW + 600, $launch['expires_at'] );
		$this->assertSame( 'Shop #42', $launch['product_name'] );
	}

	public function test_unusable_windows_are_refused() {
		$refused = array(
			'javascript action' => array( 'action' => 'javascript:alert(1)' ),
			'data action'       => array( 'action' => 'data:text/html,hi' ),
			'http action'       => array( 'action' => 'http://pay.example.test/pay/start' ),
			'get method'        => array( 'method' => 'get' ),
			'no fields'         => array( 'fields' => array() ),
			'fields not pairs'  => array( 'fields' => array( 'ORDERNO' ) ),
			'unnamed field'     => array( 'fields' => array( array( '', 'x' ) ) ),
			'array value'       => array( 'fields' => array( array( 'A', array( 'x' ) ) ) ),
			'odd charset'       => array( 'accept_charset' => 'euc-kr"><script>' ),
			'expired'           => array( 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW - 1 ) ),
			'redirect window'   => array( 'type' => 'redirect' ),
		);
		foreach ( $refused as $label => $overrides ) {
			$this->assertNull( Launch_Page::from_window( self::window( $overrides ), 'ppay_abc', '', self::NOW ), $label );
		}
		$this->assertNull( Launch_Page::from_window( self::window(), 'not-a-payment', '', self::NOW ) );
		$this->assertNull( Launch_Page::from_window( null, 'ppay_abc', '', self::NOW ) );
	}

	public function test_sizes_out_of_range_fall_back_and_expiry_is_capped() {
		$launch = Launch_Page::from_window(
			self::window(
				array(
					'width'      => 99999,
					'height'     => '550',
					'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + 30 * 86400 ),
				)
			),
			'ppay_abc',
			'',
			self::NOW
		);
		$this->assertSame( Launch_Page::DEFAULT_WIDTH, $launch['width'] );
		$this->assertSame( Launch_Page::DEFAULT_HEIGHT, $launch['height'] );
		$this->assertSame( self::NOW + Launch_Page::MAX_TTL, $launch['expires_at'] );
	}

	// ---- the link ----

	public function test_a_valid_link_resolves_to_the_order_and_its_form() {
		$launch = $this->store_launch();
		$found  = Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW );

		$this->assertSame( $this->order, $found[0] );
		$this->assertSame( $launch, $found[1] );
	}

	public function test_another_key_is_refused() {
		$this->store_launch();
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc124', 'ppay_abc', self::NOW ) );
		$this->assertNull( Launch_Page::resolve( 'GET', '', 'ppay_abc', self::NOW ) );
	}

	public function test_another_payment_is_refused() {
		$this->store_launch();
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_other', self::NOW ) );
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc/../x', self::NOW ) );
	}

	public function test_a_post_is_refused() {
		$this->store_launch();
		$this->assertNull( Launch_Page::resolve( 'POST', 'wc_order_abc123', 'ppay_abc', self::NOW ) );
	}

	public function test_an_expired_form_is_refused_and_dropped() {
		$this->store_launch();
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW + 601 ) );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
	}

	public function test_a_paid_or_held_order_is_refused() {
		$this->store_launch();
		$this->order->status = 'processing';
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW ) );
		$this->order->status = 'on-hold';
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW ) );
	}

	public function test_an_order_without_a_form_or_not_ours_is_refused() {
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW ) );

		$this->store_launch();
		$this->order->payment_method = 'cod';
		$this->assertNull( Launch_Page::resolve( 'GET', 'wc_order_abc123', 'ppay_abc', self::NOW ) );
	}

	// ---- the page ----

	public function test_the_page_posts_every_field_to_a_named_window() {
		$html = Launch_Page::render( $this->store_launch(), self::page() );

		$this->assertStringContainsString( '<form id="outlinepay-launch" method="post" action="https://pay.example.test/pay/start" accept-charset="euc-kr" target="outlinepay_pay" data-width="548" data-height="550">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ORDERNO" value="42">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="AMOUNT" value="25000">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="PRODUCTNAME" value="쿠키 상점 #42">', $html );
		$this->assertStringContainsString( '<meta charset="UTF-8">', $html );
		$this->assertStringContainsString( 'window.open("", "outlinepay_pay"', $html );
		$this->assertStringContainsString( 'form.target = "_self"', $html );
		$this->assertSame( 1, substr_count( $html, '<script' ), 'only the page\'s own inline script' );
		$this->assertStringNotContainsString( '<script src', $html );
	}

	public function test_field_names_and_values_are_escaped_exactly() {
		$launch = $this->store_launch(
			array(
				'fields' => array(
					array( 'USERNAME', '"><script>alert(1)</script>' ),
					array( "N'AME", "O'Brien & <Co>" ),
					array( 'X"><img src=x>', 'a' ),
					array( 'ENC', 'already&amp;encoded' ),
				),
			)
		);
		$html = Launch_Page::render( $launch, self::page() );

		$this->assertStringContainsString( 'name="USERNAME" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html );
		$this->assertStringContainsString( 'name="N&#039;AME" value="O&#039;Brien &amp; &lt;Co&gt;"', $html );
		$this->assertStringContainsString( 'name="X&quot;&gt;&lt;img src=x&gt;"', $html );
		$this->assertStringContainsString( 'value="already&amp;amp;encoded"', $html, 'a value reaches the provider byte for byte' );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img src=x>', $html );
	}

	public function test_store_details_are_escaped() {
		$html = Launch_Page::render(
			$this->store_launch(),
			self::page(
				array(
					'store'        => 'Shop <b>"Best"</b>',
					'order_number' => '42"><script>',
					'method_label' => '<i>card</i>',
					'back_url'     => 'https://shop.example/pay?a=1&b=\'2\'',
					'lang'         => 'ko"><x',
				)
			)
		);

		$this->assertStringContainsString( '<h1>Shop &lt;b&gt;&quot;Best&quot;&lt;/b&gt;</h1>', $html );
		$this->assertStringContainsString( '#42&quot;&gt;&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;i&gt;card&lt;/i&gt;', $html );
		$this->assertStringContainsString( '<html lang="ko&quot;&gt;&lt;x">', $html );
		$this->assertStringContainsString( 'href="https://shop.example/pay?a=1&b=&#039;2&#039;"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_no_charset_attribute_when_the_form_names_none() {
		$html = Launch_Page::render( $this->store_launch( array( 'accept_charset' => null ) ), self::page() );
		$this->assertStringNotContainsString( 'accept-charset', $html );
	}

	public function test_forget_drops_the_record_only_when_there_is_one() {
		$this->assertFalse( Launch_Page::forget( $this->order ) );
		$this->store_launch();
		$this->assertTrue( Launch_Page::forget( $this->order ) );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
	}

	public function test_a_cancelled_order_drops_its_form() {
		$this->store_launch();
		Launch_Page::on_closed( 42, $this->order );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
		$this->assertSame( 1, $this->order->saves );

		Launch_Page::on_closed( 42 );
		$this->assertSame( 1, $this->order->saves, 'nothing to drop, nothing saved' );
	}

	public function test_the_personal_data_eraser_drops_the_form() {
		$this->store_launch();
		Launch_Page::on_erased( $this->order );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
		$this->assertSame( 1, $this->order->saves );
	}

	public function test_the_sweep_drops_expired_forms_and_keeps_live_ones() {
		$live = new \WC_Order( 43 );
		$live->meta[ Order_Sync::META_LAUNCH ] = Launch_Page::from_window( self::window(), 'ppay_live', 'Shop #43', self::NOW );
		$this->store_launch();
		$orders = array( $this->order, $live );
		Functions\when( 'wc_get_orders' )->justReturn( $orders );

		$this->assertSame( 0, Launch_Page::sweep_expired( self::NOW ), 'nothing has expired yet' );
		$this->assertSame( 2, Launch_Page::sweep_expired( self::NOW + 601 ) );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $this->order->meta );
		$this->assertArrayNotHasKey( Order_Sync::META_LAUNCH, $live->meta );

		$live->meta[ Order_Sync::META_LAUNCH ] = Launch_Page::from_window( self::window( array( 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + 1200 ) ) ), 'ppay_live', 'Shop #43', self::NOW + 601 );
		$this->assertSame( 0, Launch_Page::sweep_expired( self::NOW + 601 ), 'a newer attempt on the same order stays' );
		$this->assertArrayHasKey( Order_Sync::META_LAUNCH, $live->meta );
	}
}
