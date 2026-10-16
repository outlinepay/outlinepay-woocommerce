<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Site_Health;

/**
 * The Site Health test: statuses from local checks and the stored run.
 */
class SiteHealthTest extends TestCase {

	const NOW = 1790000000;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
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
	}

	private static function local( array $failing = array() ) {
		$checks = array();
		foreach ( array_keys( Self_Check::LETTERS ) as $code ) {
			if ( 'api_reachable' === $code ) {
				continue;
			}
			$side     = isset( $failing[ $code ] ) ? $failing[ $code ] : 'store';
			$checks[] = Self_Check::check( $code, ! isset( $failing[ $code ] ), $side, array() );
		}
		return $checks;
	}

	private static function stored( $age, array $checks ) {
		return Self_Check::offline( $checks, 'daily', self::NOW - $age );
	}

	public function test_not_connected_is_critical() {
		$verdict = Site_Health::evaluate( self::local( array( 'connection_saved' => 'store' ) ), null, self::NOW );
		$this->assertSame( 'critical', $verdict['status'] );
		$this->assertSame( 'not_connected', $verdict['reason'] );
	}

	public function test_a_refused_token_is_critical() {
		$verdict = Site_Health::evaluate( self::local( array( 'connection_status' => 'processor' ) ), null, self::NOW );
		$this->assertSame( 'critical', $verdict['status'] );
		$this->assertSame( 'token_refused', $verdict['reason'] );
	}

	public function test_a_store_side_failure_is_recommended() {
		$green   = self::stored( 3600, self::local() );
		$verdict = Site_Health::evaluate( self::local( array( 'store_currency' => 'store' ) ), $green, self::NOW );
		$this->assertSame( 'recommended', $verdict['status'] );
		$this->assertSame( array( 'store_currency' ), array_column( $verdict['failed'], 'code' ) );
	}

	public function test_all_green_with_a_green_run_under_26_hours_is_good() {
		$verdict = Site_Health::evaluate( self::local(), self::stored( 26 * 3600 - 1, self::local() ), self::NOW );
		$this->assertSame( 'good', $verdict['status'] );
	}

	public function test_no_run_or_an_old_run_is_recommended() {
		$this->assertSame( 'stale', Site_Health::evaluate( self::local(), null, self::NOW )['reason'] );
		$old = Site_Health::evaluate( self::local(), self::stored( 26 * 3600 + 1, self::local() ), self::NOW );
		$this->assertSame( 'recommended', $old['status'] );
		$this->assertSame( 'stale', $old['reason'] );
	}

	public function test_a_server_check_failed_in_the_last_run_is_recommended() {
		$checks   = self::local();
		$checks[] = Self_Check::check( 'callback_reachable', false, 'store', array() );
		$verdict  = Site_Health::evaluate( self::local(), self::stored( 60, $checks ), self::NOW );
		$this->assertSame( 'recommended', $verdict['status'] );
		$this->assertSame( array( 'callback_reachable' ), array_column( $verdict['failed'], 'code' ) );
	}

	public function test_a_stored_local_failure_fixed_since_does_not_count() {
		$checks  = self::local( array( 'store_currency' => 'store' ) );
		$verdict = Site_Health::evaluate( self::local(), self::stored( 60, $checks ), self::NOW );
		$this->assertSame( 'good', $verdict['status'] );
	}

	public function test_the_result_has_what_site_health_needs() {
		$result = Site_Health::present( Site_Health::evaluate( self::local( array( 'store_currency' => 'store' ) ), null, self::NOW ) );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'outlinepay_connection', $result['test'] );
		$this->assertArrayHasKey( 'label', $result['badge'] );
		$this->assertStringContainsString( 'KRW', $result['description'] );
		$this->assertStringContainsString( '(Who acts next: You)', $result['description'] );
		$this->assertStringContainsString( 'page=outlinepay', $result['actions'] );

		$this->assertSame( 'good', Site_Health::present( Site_Health::evaluate( self::local(), self::stored( 60, self::local() ), self::NOW ) )['status'] );
		$this->assertSame( 'critical', Site_Health::present( Site_Health::evaluate( self::local( array( 'connection_saved' => 'store' ) ), null, self::NOW ) )['status'] );
	}

	public function test_the_test_is_added_as_a_direct_test() {
		$tests = Site_Health::add_test(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);
		$this->assertSame( 'OutlinePay connection', $tests['direct']['outlinepay_connection']['label'] );
		$this->assertTrue( is_callable( $tests['direct']['outlinepay_connection']['test'] ) );
		$this->assertSame( array(), $tests['async'] );
	}
}
