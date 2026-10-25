<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey;
use OutlinePay\WooCommerce\Logger;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** @var string[] Every log line written during the test. */
	protected $log_lines = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Notes::text runs every note in the site locale. By default the
		// request already is in it, so nothing switches.
		Monkey\Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Monkey\Functions\when( 'switch_to_locale' )->justReturn( false );
		Monkey\Functions\when( 'restore_previous_locale' )->justReturn( false );
		// The gateway's icon URL. A test that cares expects plugins_url itself.
		Monkey\Functions\when( 'plugins_url' )->alias(
			static function ( $path, $plugin ) {
				return 'https://store.example/wp-content/plugins/' . basename( dirname( $plugin ) ) . '/' . $path;
			}
		);
		$this->log_lines = array();
		$lines           = &$this->log_lines;
		Logger::set_sink(
			static function ( $level, $line ) use ( &$lines ) {
				$lines[] = $level . ' ' . $line;
			}
		);
	}

	protected function tearDown(): void {
		Logger::set_sink( null );
		Monkey\tearDown();
		parent::tearDown();
	}
}
