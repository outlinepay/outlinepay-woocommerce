<?php
/**
 * Plugin Name:          OutlinePay for WooCommerce
 * Plugin URI:           https://outlinepay.com
 * Description:          Accept Korean payment methods (cards, bank transfer, virtual accounts, wallets) through your payment provider, connected by OutlinePay.
 * Version:              0.2.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               OutlinePay
 * Author URI:           https://outlinepay.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          outlinepay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * Copyright (C) 2026 Arandu Payments LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * @package OutlinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'OUTLINEPAY_VERSION', '0.2.0' );
define( 'OUTLINEPAY_PLUGIN_FILE', __FILE__ );
define( 'OUTLINEPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once OUTLINEPAY_PLUGIN_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'OutlinePay\WooCommerce\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OutlinePay\WooCommerce\Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'OutlinePay\WooCommerce\Plugin', 'boot' ), 20 );
