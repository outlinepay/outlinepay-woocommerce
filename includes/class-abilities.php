<?php
/**
 * Two read-only abilities on the WordPress Abilities API (WordPress 6.9 and
 * later), for tools and AI agents that act for the merchant:
 *
 *   outlinepay/run-self-check         runs the self-check, as the Run check button does
 *   outlinepay/get-connection-status  the saved connection and the last check, no network
 *
 * Both need the manage_woocommerce capability. On older WordPress nothing is
 * registered. meta.mcp.public exposes them to the MCP Adapter's default server.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Ability registration and callbacks.
 */
final class Abilities {

	const CATEGORY = 'outlinepay';

	/**
	 * Hooks registration when the Abilities API exists.
	 *
	 * @return void
	 */
	public static function register() {
		self::hook( function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ) );
	}

	/**
	 * Hooks registration, or nothing when the API is missing.
	 *
	 * @param bool $api_exists Whether WordPress has the Abilities API.
	 * @return void
	 */
	public static function hook( $api_exists ) {
		if ( ! $api_exists ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * The category both abilities belong to.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'OutlinePay', 'outlinepay-for-woocommerce' ),
				'description' => __( 'The store\'s connection to OutlinePay and its payment provider.', 'outlinepay-for-woocommerce' ),
			)
		);
	}

	/**
	 * Registers both abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		wp_register_ability(
			'outlinepay/run-self-check',
			array(
				'label'               => __( 'Run the OutlinePay self-check', 'outlinepay-for-woocommerce' ),
				'description'         => __( 'Checks this store\'s OutlinePay connection, background jobs, payment methods, currency and versions, and asks OutlinePay to check its side. Returns each check with who acts next when it fails, and a diagnostic code to send to the payment provider. Reads no shopper or order data. At most one run a minute.', 'outlinepay-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( self::class, 'run_self_check' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'output_schema'       => self::run_schema(),
				'meta'                => self::meta( false ),
			)
		);
		wp_register_ability(
			'outlinepay/get-connection-status',
			array(
				'label'               => __( 'Get the OutlinePay connection status', 'outlinepay-for-woocommerce' ),
				'description'         => __( 'Returns the saved OutlinePay connection (status, mode, payment provider, installation id, connected since) and a summary of the last self-check. Makes no network call.', 'outlinepay-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( self::class, 'connection_status' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'output_schema'       => self::status_schema(),
				'meta'                => self::meta( true ),
			)
		);
	}

	/**
	 * Read-only metadata, shown in the REST API and to the MCP Adapter.
	 *
	 * @param bool $idempotent Whether repeated calls have no further effect.
	 * @return array
	 */
	private static function meta( $idempotent ) {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => (bool) $idempotent,
			),
			'show_in_rest' => true,
			'public'       => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}

	/**
	 * Permission for both abilities.
	 *
	 * @return bool
	 */
	public static function can_use() {
		return current_user_can( Admin_Page::CAPABILITY );
	}

	/**
	 * Runs the self-check with trigger page.
	 *
	 * @return array
	 */
	public static function run_self_check() {
		$outcome = ( new Self_Check() )->run( Self_Check::TRIGGER_PAGE );
		return self::present_run( $outcome['result'], $outcome['rate_limited'] );
	}

	/**
	 * The run as the ability returns it.
	 *
	 * @param array $result       Result.
	 * @param bool  $rate_limited Whether this is the stored result of an earlier run.
	 * @return array
	 */
	public static function present_run( array $result, $rate_limited ) {
		$checks = array();
		foreach ( $result['checks'] as $check ) {
			$checks[] = array(
				'code'     => (string) $check['code'],
				'ok'       => (bool) $check['ok'],
				'side'     => (string) $check['side'],
				'who'      => Self_Check::who( $check['side'] ),
				'sentence' => Self_Check::sentence( $check ),
			);
		}
		return array(
			'code'         => (string) $result['code'],
			'online'       => (bool) $result['online'],
			'rate_limited' => (bool) $rate_limited,
			'ran_at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $result['ran_at'] ),
			'failed_count' => (int) $result['failed_count'],
			'worst_side'   => (string) $result['worst_side'],
			'checks'       => $checks,
		);
	}

	/**
	 * The saved connection and the last check. No network.
	 *
	 * @return array
	 */
	public static function connection_status() {
		$installation = Settings::installation();
		$last         = Self_Check::last();
		$field        = static function ( $key ) use ( $installation ) {
			return isset( $installation[ $key ] ) && is_scalar( $installation[ $key ] ) ? (string) $installation[ $key ] : '';
		};
		$status       = $field( 'status' );
		if ( empty( $installation ) || ! Credentials::present() ) {
			$status = 'not_connected';
		} elseif ( Site_Identity::is_copy() ) {
			$status = 'copy';
		}
		return array(
			'status'          => $status,
			'mode'            => $field( 'mode' ),
			'provider'        => $field( 'provider' ),
			'installation_id' => $field( 'id' ),
			'connected_at'    => $field( 'connected_at' ),
			'last_check'      => $last ? array(
				'code'         => (string) $last['code'],
				'online'       => ! empty( $last['online'] ),
				'ran_at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $last['ran_at'] ),
				'failed_count' => (int) $last['failed_count'],
				'worst_side'   => (string) $last['worst_side'],
			) : array(
				'code'         => '',
				'online'       => false,
				'ran_at'       => '',
				'failed_count' => 0,
				'worst_side'   => '',
			),
		);
	}

	/**
	 * Output schema of run-self-check.
	 *
	 * @return array
	 */
	private static function run_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'         => array(
					'type'        => 'string',
					'description' => 'Diagnostic code: chk_... when OutlinePay answered, OP-OFFLINE-<letters> when it did not.',
				),
				'online'       => array(
					'type'        => 'boolean',
					'description' => 'Whether OutlinePay answered this run.',
				),
				'rate_limited' => array(
					'type'        => 'boolean',
					'description' => 'True when a run happened less than a minute ago: this is that earlier result.',
				),
				'ran_at'       => array(
					'type'        => 'string',
					'description' => 'When the run happened, UTC ISO 8601.',
				),
				'failed_count' => array( 'type' => 'integer' ),
				'worst_side'   => array(
					'type'        => 'string',
					'description' => 'Who acts first among the failures: outlinepay, processor, store, shopper, unknown. Empty when nothing failed.',
				),
				'checks'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code'     => array( 'type' => 'string' ),
							'ok'       => array( 'type' => 'boolean' ),
							'side'     => array(
								'type'        => 'string',
								'description' => 'Who acts next when the check fails: shopper, store (the merchant), processor (the payment provider), outlinepay, unknown.',
							),
							'who'      => array( 'type' => 'string' ),
							'sentence' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Output schema of get-connection-status.
	 *
	 * @return array
	 */
	private static function status_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'          => array(
					'type'        => 'string',
					'description' => 'connected, revoked, pending, not_connected, or copy (this site is a copy of the store and takes no payments).',
				),
				'mode'            => array(
					'type'        => 'string',
					'description' => 'test or live.',
				),
				'provider'        => array( 'type' => 'string' ),
				'installation_id' => array( 'type' => 'string' ),
				'connected_at'    => array( 'type' => 'string' ),
				'last_check'      => array(
					'type'       => 'object',
					'properties' => array(
						'code'         => array( 'type' => 'string' ),
						'online'       => array( 'type' => 'boolean' ),
						'ran_at'       => array( 'type' => 'string' ),
						'failed_count' => array( 'type' => 'integer' ),
						'worst_side'   => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
