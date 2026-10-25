<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Self_Check_Mail;

/**
 * The opt-in email when a daily check starts failing.
 */
class SelfCheckMailTest extends TestCase {

	const NOW = 1790000000;
	const DAY = 86400;

	/** @var array Options in memory. */
	private $options = array();

	/** @var array Emails sent. */
	private $mails = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$options = &$this->options;
		$mails   = &$this->mails;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$mails ) {
				$mails[] = compact( 'to', 'subject', 'body', 'headers' );
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Tea Shop' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$this->options['admin_email'] = 'owner@shop.example.com';
	}

	private static function failed() {
		return Self_Check::offline( array( Self_Check::check( 'store_currency', false, 'store', array( 'currency' => 'USD' ) ) ), 'daily', self::NOW );
	}

	private static function passed() {
		return Self_Check::offline( array( Self_Check::check( 'store_currency', true, 'store', array() ) ), 'daily', self::NOW );
	}

	public function test_should_send_only_on_the_first_failure_after_a_pass_and_at_most_daily() {
		$this->assertFalse( Self_Check_Mail::should_send( false, true, true, 0, self::NOW ), 'off' );
		$this->assertFalse( Self_Check_Mail::should_send( true, false, true, 0, self::NOW ), 'passed' );
		$this->assertFalse( Self_Check_Mail::should_send( true, true, false, 0, self::NOW ), 'still failing' );
		$this->assertTrue( Self_Check_Mail::should_send( true, true, true, 0, self::NOW ), 'newly failing' );
		$this->assertTrue( Self_Check_Mail::should_send( true, true, null, 0, self::NOW ), 'first daily run' );
		$this->assertFalse( Self_Check_Mail::should_send( true, true, true, self::NOW - self::DAY + 1, self::NOW ), 'emailed under a day ago' );
		$this->assertTrue( Self_Check_Mail::should_send( true, true, true, self::NOW - self::DAY, self::NOW ), 'a day later' );
	}

	public function test_nothing_is_sent_while_the_option_is_off() {
		$this->options[ Self_Check::OPTION_EMAIL ] = 'no';
		$this->assertFalse( Self_Check_Mail::after_daily( self::failed(), self::NOW ) );
		$this->assertSame( array(), $this->mails );
		$this->assertFalse( $this->options[ Self_Check::OPTION_DAILY ]['ok'], 'the daily state is kept anyway' );
	}

	public function test_a_store_that_never_saved_the_setting_gets_the_email() {
		$this->assertTrue( Self_Check_Mail::after_daily( self::failed(), self::NOW ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_daily_failure_after_a_pass_sends_one_email_in_korean_then_english() {
		$this->options[ Self_Check::OPTION_EMAIL ] = 'yes';
		$this->options[ Self_Check::OPTION_DAILY ] = array(
			'ok'     => true,
			'ran_at' => self::NOW - self::DAY,
		);

		$this->assertTrue( Self_Check_Mail::after_daily( self::failed(), self::NOW ) );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( 'owner@shop.example.com', $mail['to'] );
		$this->assertStringContainsString( 'Tea Shop', $mail['subject'] );
		$this->assertStringContainsString( 'text/plain', $mail['headers'][0] );
		$this->assertStringContainsString( 'OP-OFFLINE-G', $mail['body'] );
		$this->assertStringContainsString( 'page=outlinepay', $mail['body'] );
		$this->assertStringContainsString( 'USD', $mail['body'] );
		$this->assertStringContainsString( 'Who acts next: You', $mail['body'] );
		$this->assertLessThan( strpos( $mail['body'], 'Diagnostic code' ), strpos( $mail['body'], '진단 코드' ), 'Korean first' );
		$this->assertSame( self::NOW, $this->options[ Self_Check::OPTION_EMAILED_AT ] );

		// The next daily run still fails: no second email.
		$this->assertFalse( Self_Check_Mail::after_daily( self::failed(), self::NOW + self::DAY ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_pass_then_a_failure_within_a_day_of_the_last_email_sends_nothing() {
		$this->options[ Self_Check::OPTION_EMAIL ]      = 'yes';
		$this->options[ Self_Check::OPTION_EMAILED_AT ] = self::NOW - 3600;
		Self_Check_Mail::after_daily( self::passed(), self::NOW - 60 );
		$this->assertFalse( Self_Check_Mail::after_daily( self::failed(), self::NOW ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_lines_list_only_failed_checks() {
		$result = Self_Check::offline(
			array(
				Self_Check::check( 'store_currency', false, 'store', array( 'currency' => 'USD' ) ),
				Self_Check::check( 'versions', true, 'store', array() ),
			),
			'daily',
			self::NOW
		);
		$lines  = Self_Check_Mail::lines( $result, 'en_US' );
		$this->assertCount( 1, $lines );
		$this->assertSame( 'You', $lines[0]['who'] );
	}
}
