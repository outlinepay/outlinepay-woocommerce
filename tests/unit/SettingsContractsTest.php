<?php
namespace OutlinePay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use OutlinePay\WooCommerce\Settings;

/**
 * Methods grouped by contract: the payment gateway (PG) choice per contract,
 * and which methods that choice turns on.
 */
class SettingsContractsTest extends TestCase {

	use ContractsFixture;

	/** @var array Options in memory. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		$options = &$this->options;
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
		$this->options[ Settings::OPTION_METHODS ] = self::methods_option();
	}

	public function test_save_methods_keeps_the_contracts_next_to_the_flat_list() {
		$answer = self::methods_answer();
		Settings::save_methods( $answer['data'], $answer['contracts'] );

		$saved = $this->options[ Settings::OPTION_METHODS ];
		$this->assertCount( 6, $saved['methods'] );
		$this->assertSame( array( 3, 7 ), array_column( $saved['contracts'], 'contract_type' ) );
		$this->assertGreaterThan( 0, $saved['fetched_at'] );
	}

	public function test_save_methods_without_contracts_stores_none() {
		$answer = self::methods_answer();
		Settings::save_methods( $answer['data'] );
		$this->assertArrayNotHasKey( 'contracts', $this->options[ Settings::OPTION_METHODS ] );
		$this->assertSame( array(), Settings::contracts() );

		Settings::save_methods( $answer['data'], array( 'junk' ) );
		$this->assertArrayNotHasKey( 'contracts', $this->options[ Settings::OPTION_METHODS ], 'nothing usable counts as none' );
	}

	public function test_the_first_pg_is_the_choice_until_the_merchant_chooses() {
		$this->assertSame( '키움페이', Settings::pg_for_contract( 3 ) );
		$this->assertSame( '키움페이', Settings::pg_for_contract( '7' ) );
		$this->assertNull( Settings::pg_for_contract( 9 ) );
		$this->assertNull( Settings::pg_for_contract( null ) );
	}

	public function test_a_chosen_pg_the_provider_no_longer_lists_falls_back_to_the_first() {
		$this->options[ Settings::OPTION_PG ] = array( '3' => '토스페이' );
		$this->assertSame( '토스페이', Settings::pg_for_contract( 3 ) );

		$this->options[ Settings::OPTION_PG ] = array( '3' => 'NicePay' );
		$this->assertSame( '키움페이', Settings::pg_for_contract( 3 ) );

		$this->options[ Settings::OPTION_PG ] = 'junk';
		$this->assertSame( '키움페이', Settings::pg_for_contract( 3 ) );
	}

	public function test_save_pgs_keeps_only_listed_pgs_of_known_contracts() {
		Settings::save_pgs( array( '3' => '토스페이' ) );
		$this->assertSame( '토스페이', Settings::pg_for_contract( 3 ) );

		Settings::save_pgs(
			array(
				'3' => 'NicePay',
				'7' => '토스페이',
				'9' => '키움페이',
			)
		);
		$this->assertSame( '토스페이', Settings::pg_for_contract( 3 ), 'an unknown pg is ignored' );
		$this->assertSame( '키움페이', Settings::pg_for_contract( 7 ) );
		$this->assertSame( array( '3' => '토스페이' ), $this->options[ Settings::OPTION_PG ] );
	}

	public function test_contract_for_method() {
		$this->assertSame( 3, Settings::contract_for_method( 'card' ) );
		$this->assertSame( 3, Settings::contract_for_method( 'naverpay' ) );
		$this->assertSame( 7, Settings::contract_for_method( 'foreign_card_krw' ) );
		$this->assertNull( Settings::contract_for_method( 'carrier_billing' ) );

		$this->options[ Settings::OPTION_PG ] = array( '3' => '토스페이' );
		$this->assertSame( 3, Settings::contract_for_method( 'naverpay' ), 'listed by a pg not chosen' );
	}

	public function test_methods_follow_the_chosen_pg() {
		foreach ( array( 'card', 'foreign_card_krw', 'bank_transfer', 'virtual_account', 'kakaopay', 'naverpay' ) as $method ) {
			$this->assertTrue( Settings::method_enabled_by_server( $method ), $method );
		}
		$this->assertFalse( Settings::method_enabled_by_server( 'carrier_billing' ) );
	}

	public function test_toss_without_naverpay_turns_naverpay_off() {
		$this->options[ Settings::OPTION_PG ] = array( '3' => '토스페이' );

		$this->assertTrue( Settings::method_enabled_by_server( 'card' ) );
		$this->assertTrue( Settings::method_enabled_by_server( 'kakaopay' ) );
		$this->assertTrue( Settings::method_enabled_by_server( 'foreign_card_krw' ), 'contract 7 is unaffected' );
		$this->assertFalse( Settings::method_enabled_by_server( 'naverpay' ) );
		$this->assertFalse( Settings::method_enabled_by_server( 'bank_transfer' ) );
		$this->assertFalse( Settings::method_enabled_by_server( 'virtual_account' ) );
	}

	public function test_without_contracts_the_flat_list_decides() {
		unset( $this->options[ Settings::OPTION_METHODS ]['contracts'] );
		$this->options[ Settings::OPTION_PG ] = array( '3' => '토스페이' );

		$this->assertTrue( Settings::method_enabled_by_server( 'naverpay' ) );
		$this->assertFalse( Settings::method_enabled_by_server( 'carrier_billing' ) );
		$this->assertNull( Settings::contract_for_method( 'card' ) );
		$this->assertNull( Settings::pg_for_contract( Settings::contract_for_method( 'card' ) ) );
	}

	public function test_the_contract_name_follows_the_site_language() {
		$contract = array( 'contract_type' => 3, 'label' => 'Authenticated general', 'label_ko' => '인증 일반' );

		$this->assertSame( 'Authenticated general', Settings::contract_name( $contract ) );
		Functions\when( 'get_locale' )->justReturn( 'ko_KR' );
		$this->assertSame( '인증 일반', Settings::contract_name( $contract ) );
		$this->assertSame( '인증 일반', Settings::contract_name( array( 'contract_type' => 3, 'label_ko' => '인증 일반' ) ) );
		$this->assertSame( '9', Settings::contract_name( array( 'contract_type' => 9 ) ) );
	}
}
