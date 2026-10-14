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
