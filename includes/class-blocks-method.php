<?php
/**
 * Cart and Checkout block integration for one method.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one gateway with the Checkout block. All methods share assets/js/blocks.js.
 */
final class Blocks_Method extends AbstractPaymentMethodType {

	const SCRIPT_HANDLE = 'outlinepay-blocks';

	/**
	 * Method key.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Constructor.
	 *
	 * @param string $method Method key.
	 */
	public function __construct( $method ) {
		$this->method = $method;
		$this->name   = Settings::gateway_id( $method );
	}

	/**
	 * Loads settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Active when the gateway would be available at checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ]->is_available();
	}

	/**
	 * The shared script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				plugins_url( 'assets/js/blocks.js', OUTLINEPAY_PLUGIN_FILE ),
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
				OUTLINEPAY_VERSION,
				true
			);
		}
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Data the script reads with getSetting( '<name>_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => Settings::label( $this->method ),
			'description' => Settings::DRAFT_DESCRIPTION_KO,
			'supports'    => Gateway::features( $this->method ),
		);
	}
}
