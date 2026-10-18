<?php
/**
 * Uninstall: tell OutlinePay the store is gone (best effort), then delete options.
 *
 * Orders and their notes stay. They are the merchant's records.
 *
 * @package OutlinePay\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'OUTLINEPAY_VERSION' ) ) {
	define( 'OUTLINEPAY_VERSION', '0.3.0' );
}
require_once __DIR__ . '/includes/autoload.php';

// A copy of the store holds the live store's credentials. Disconnecting from
// here would switch off the live store, so a copy only cleans up locally.
if ( OutlinePay\WooCommerce\Credentials::present() && ! OutlinePay\WooCommerce\Site_Identity::is_copy() ) {
	try {
		( new OutlinePay\WooCommerce\Api_Client() )->disconnect();
	} catch ( OutlinePay\WooCommerce\Api_Error $outlinepay_error ) {
		// Best effort. The partner can revoke the installation from their side.
		unset( $outlinepay_error );
	}
}

OutlinePay\WooCommerce\Settings::clear_connection();
OutlinePay\WooCommerce\Scheduler::unschedule_all();

delete_option( OutlinePay\WooCommerce\Self_Check::OPTION_EMAIL );
delete_option( OutlinePay\WooCommerce\Scheduler::OPTION_DAILY_AT );
delete_option( OutlinePay\WooCommerce\Settings::OPTION_CANCEL );

foreach ( OutlinePay\WooCommerce\Settings::gateway_ids() as $outlinepay_gateway_id ) {
	delete_option( 'woocommerce_' . $outlinepay_gateway_id . '_settings' );
}
