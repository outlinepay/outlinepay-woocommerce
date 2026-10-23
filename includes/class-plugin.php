<?php
/**
 * Wires the plugin into WordPress and WooCommerce.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap.
 */
final class Plugin {

	/**
	 * Set on activation of a store that is not connected. The next admin page
	 * shows the activation notice once (see Admin_Page::activation_notice).
	 */
	const TRANSIENT_ACTIVATED = 'outlinepay_activated';

	/**
	 * Hooks everything once WooCommerce has loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateways' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'register_blocks' ) );
		add_action( 'outlinepay_token_rejected', array( Settings::class, 'mark_revoked' ) );
		add_filter( 'wc_get_price_decimals', array( Settings::class, 'price_decimals' ) );
		// Before any gateway is asked: a store connected before 0.5.0 keeps its checkout.
		Setup::upgrade();

		Admin_Page::register();
		Return_Handler::register();
		Launch_Page::register();
		Callback_Controller::register();
		Scheduler::register();
		Unpaid_Guard::register();
		Switch_Guard::register();
		Order_Display::register();
		Refunds::register();
		Shopper_Cancel::register();
		Site_Health::register();
		Abilities::register();
	}

	/**
	 * Loads bundled translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'outlinepay-for-woocommerce', false, dirname( plugin_basename( OUTLINEPAY_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * One gateway object per method.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function add_gateways( $gateways ) {
		foreach ( Settings::METHODS as $method ) {
			$gateways[] = new Gateway( $method );
		}
		return $gateways;
	}

	/**
	 * Registers each method with the Checkout block.
	 *
	 * @param object $registry PaymentMethodRegistry.
	 * @return void
	 */
	public static function register_blocks( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		foreach ( Settings::METHODS as $method ) {
			$registry->register( new Blocks_Method( $method ) );
		}
	}

	/**
	 * Notice when WooCommerce is missing.
	 *
	 * @return void
	 */
	public static function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'OutlinePay for WooCommerce needs WooCommerce to be installed and active.', 'outlinepay-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Activation: nothing is sent anywhere. The merchant connects from the
	 * admin page, and a store that is not connected gets a notice pointing there.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( Settings::is_connected() ) {
			Scheduler::schedule_recurring();
			return;
		}
		set_transient( self::TRANSIENT_ACTIVATED, 1, Scheduler::DAY );
	}

	/**
	 * Deactivation stops background work but keeps the connection.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule_all();
	}
}
