<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Abilities;
use OutlinePay\WooCommerce\Credentials;
use OutlinePay\WooCommerce\Self_Check;
use OutlinePay\WooCommerce\Settings;
use OutlinePay\WooCommerce\Site_Identity;

/**
 * The two abilities on the WordPress Abilities API.
 */
class AbilitiesTest extends TestCase {

	/** @var array Options in memory. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Credentials::reset_cache();
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com/' );
	}

	protected function tearDown(): void {
		Credentials::reset_cache();
		parent::tearDown();
	}

	public function test_nothing_is_hooked_without_the_abilities_api() {
		Actions\expectAdded( 'wp_abilities_api_init' )->never();
		Actions\expectAdded( 'wp_abilities_api_categories_init' )->never();
		Abilities::hook( false );
		$this->addToAssertionCount( 1 );
	}

	public function test_registration_is_hooked_on_the_api_actions() {
		Abilities::hook( true );
		$this->assertNotFalse( has_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) ) );
		$this->assertNotFalse( has_action( 'wp_abilities_api_init', array( Abilities::class, 'register_abilities' ) ) );
	}

	public function test_the_category_is_registered() {
		Functions\expect( 'wp_register_ability_category' )->once()->with(
			'outlinepay',
			\Mockery::on(
				static function ( $args ) {
					return '' !== $args['label'] && '' !== $args['description'];
				}
			)
		);
		Abilities::register_category();
		$this->addToAssertionCount( 1 );
	}

	public function test_both_abilities_are_registered_read_only_for_store_managers_and_mcp() {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
				return null;
			}
		);
		Abilities::register_abilities();

		$this->assertSame( array( 'outlinepay/run-self-check', 'outlinepay/get-connection-status' ), array_keys( $registered ) );
		foreach ( $registered as $name => $args ) {
			$this->assertMatchesRegularExpression( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name );
			$this->assertSame( 'outlinepay', $args['category'] );
			$this->assertNotSame( '', $args['label'] );
			$this->assertNotSame( '', $args['description'] );
			$this->assertTrue( is_callable( $args['execute_callback'] ) );
			$this->assertSame( array( Abilities::class, 'can_use' ), $args['permission_callback'] );
			$this->assertArrayNotHasKey( 'input_schema', $args, 'no input: the callback gets no arguments' );
			$this->assertSame( 'object', $args['output_schema']['type'] );
			$this->assertTrue( $args['meta']['annotations']['readonly'] );
			$this->assertFalse( $args['meta']['annotations']['destructive'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertSame( 'tool', $args['meta']['mcp']['type'] );
		}
		$this->assertFalse( $registered['outlinepay/run-self-check']['meta']['annotations']['idempotent'] );
		$this->assertTrue( $registered['outlinepay/get-connection-status']['meta']['annotations']['idempotent'] );
	}

	public function test_permission_is_manage_woocommerce() {
		Functions\expect( 'current_user_can' )->twice()->with( 'manage_woocommerce' )->andReturn( true, false );
		$this->assertTrue( Abilities::can_use() );
		$this->assertFalse( Abilities::can_use() );
	}

	public function test_a_run_is_presented_with_code_and_sentences() {
		$result = Self_Check::offline(
			array(
				Self_Check::check( 'store_currency', false, 'store', array( 'currency' => 'USD' ) ),
				Self_Check::check( 'versions', true, 'store', array() ),
			),
			'page',
			1790000000
		);
		$out    = Abilities::present_run( $result, true );
		$this->assertSame( 'OP-OFFLINE-G', $out['code'] );
		$this->assertTrue( $out['rate_limited'] );
		$this->assertFalse( $out['online'] );
		$this->assertSame( '2026-09-21T14:13:20Z', $out['ran_at'] );
		$this->assertSame( 1, $out['failed_count'] );
		$this->assertSame( array( 'code', 'ok', 'side', 'who', 'sentence' ), array_keys( $out['checks'][0] ) );
		$this->assertStringContainsString( 'USD', $out['checks'][0]['sentence'] );
		$this->assertSame( 'You', $out['checks'][0]['who'] );
	}

	public function test_connection_status_reads_saved_values_only() {
		Functions\expect( 'wp_remote_request' )->never();
		Credentials::save( 'olp_it_test_TOKEN', 'olp_isec_SECRET' );
		Credentials::reset_cache();
		$this->options[ Settings::OPTION_INSTALLATION ] = array(
			'id'           => 'inst_abc',
			'status'       => 'connected',
			'mode'         => 'test',
			'provider'     => 'cookiepay',
			'connected_at' => '2026-10-16T01:02:03Z',
		);
		$this->options[ Site_Identity::OPTION ]         = 'https://shop.example.com/';
		$this->options[ Self_Check::OPTION_LAST ]       = Self_Check::offline( array( Self_Check::check( 'versions', false, 'store', array() ) ), 'daily', 1790000000 );

		$out = Abilities::connection_status();
		$this->assertSame( 'connected', $out['status'] );
		$this->assertSame( 'test', $out['mode'] );
		$this->assertSame( 'cookiepay', $out['provider'] );
		$this->assertSame( 'inst_abc', $out['installation_id'] );
		$this->assertSame( '2026-10-16T01:02:03Z', $out['connected_at'] );
		$this->assertSame( 'OP-OFFLINE-I', $out['last_check']['code'] );
		$this->assertSame( 1, $out['last_check']['failed_count'] );
	}

	public function test_connection_status_without_a_connection_or_on_a_copy() {
		$this->assertSame( 'not_connected', Abilities::connection_status()['status'] );
		$this->assertSame( '', Abilities::connection_status()['last_check']['code'] );

		Credentials::save( 'olp_it_test_TOKEN', 'olp_isec_SECRET' );
		Credentials::reset_cache();
		$this->options[ Settings::OPTION_INSTALLATION ] = array(
			'id'     => 'inst_abc',
			'status' => 'connected',
		);
		$this->options[ Site_Identity::OPTION ]         = 'https://live.example.com/';
		$this->assertSame( 'copy', Abilities::connection_status()['status'] );
	}
}
