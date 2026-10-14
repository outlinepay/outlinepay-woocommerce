<?php
/**
 * Class autoloader for the OutlinePay\WooCommerce namespace.
 *
 * Maps OutlinePay\WooCommerce\Api_Client to includes/class-api-client.php.
 *
 * @package OutlinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'OutlinePay\\WooCommerce\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( false !== strpos( $relative, '\\' ) ) {
			return;
		}
		$file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
