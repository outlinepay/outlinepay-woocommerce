<?php
namespace OutlinePay\WooCommerce\Tests;

/**
 * A /methods answer with contracts, as the server sends it: contract 3 on two
 * payment gateways (Toss has no NaverPay), contract 7 on one.
 */
trait ContractsFixture {

	private static function method_row( $method, $label ) {
		return array(
			'method'   => $method,
			'label_ko' => $label,
		);
	}

	/** The raw answer body. */
	private static function methods_answer() {
		return array(
			'object'    => 'list',
			'data'      => array(
				array( 'object' => 'payment_method' ) + self::method_row( 'card', '신용카드' ),
				self::method_row( 'foreign_card_krw', '해외카드 (원화 결제)' ),
				self::method_row( 'bank_transfer', '계좌이체' ),
				self::method_row( 'virtual_account', '가상계좌' ),
				self::method_row( 'kakaopay', '카카오페이' ),
				self::method_row( 'naverpay', '네이버페이' ),
			),
			'contracts' => array(
				array(
					'object'        => 'contract',
					'contract_type' => 3,
					'label_ko'      => '인증 일반',
					'pgs'           => array(
						array(
							'pg'      => '키움페이',
							'methods' => array(
								self::method_row( 'card', '신용카드' ),
								self::method_row( 'bank_transfer', '계좌이체' ),
								self::method_row( 'virtual_account', '가상계좌' ),
								self::method_row( 'kakaopay', '카카오페이' ),
								self::method_row( 'naverpay', '네이버페이' ),
							),
						),
						array(
							'pg'      => '토스페이',
							'methods' => array(
								self::method_row( 'card', '신용카드' ),
								self::method_row( 'kakaopay', '카카오페이' ),
							),
						),
					),
				),
				array(
					'object'        => 'contract',
					'contract_type' => 7,
					'label_ko'      => '인증 해외원화',
					'pgs'           => array(
						array(
							'pg'      => '키움페이',
							'methods' => array( self::method_row( 'foreign_card_krw', '해외카드 (원화 결제)' ) ),
						),
					),
				),
			),
		);
	}

	/** The outlinepay_methods option after a refresh with that answer. */
	private static function methods_option() {
		$answer = self::methods_answer();
		return array(
			'fetched_at' => 1790000000,
			'methods'    => array_map(
				static function ( $row ) {
					return self::method_row( $row['method'], $row['label_ko'] );
				},
				$answer['data']
			),
			'contracts'  => \OutlinePay\WooCommerce\Api_Client::parse_contracts( $answer['contracts'] ),
		);
	}
}
