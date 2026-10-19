<?php
/**
 * Where the shopper lands after the provider window: ?wc-api=outlinepay_return.
 *
 * Nothing in the URL is believed except as a pointer. The order key proves the
 * shopper owns the order, and the outcome comes from GET /payments/:id.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the shopper's return.
 */
final class Return_Handler {

	/**
	 * Hooks the endpoint.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_api_outlinepay_return', array( self::class, 'handle' ) );
	}

	/**
	 * True when the key in the URL is this order's key.
	 *
	 * @param mixed $order_key Key stored on the order.
	 * @param mixed $given     Key from the URL.
	 * @return bool
	 */
	public static function key_matches( $order_key, $given ) {
		if ( ! is_string( $order_key ) || ! is_string( $given ) || '' === $order_key || '' === $given ) {
			return false;
		}
		return hash_equals( $order_key, $given );
	}

	/**
	 * Reads the return, syncs the order and redirects the shopper.
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the shopper returns from an external provider; the order key is the proof of ownership.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$pid    = isset( $_GET['payment_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'GET' !== $method || '' === $key || ! Api_Client::valid_payment_id( $pid ) ) {
			self::reject();
		}

		$order = wc_get_order( wc_get_order_id_by_order_key( $key ) );
		if ( ! $order instanceof \WC_Order || ! self::key_matches( $order->get_order_key(), $key ) || ! Order_Sync::is_ours( $order ) ) {
			self::reject();
		}

		$decision = null;
		if ( Site_Identity::is_copy() ) {
			// A copy of the store does not call OutlinePay. The live store settles the order.
			Logger::warning( 'Shopper return on a copy of the store, not applied', array( 'order_id' => $order->get_id() ) );
			self::finish( $order->get_checkout_order_received_url() );
		}
		try {
			$payment  = ( new Api_Client() )->get_payment( $pid );
			$decision = Order_Sync::apply( $order, $payment );
		} catch ( Api_Error $error ) {
			Logger::warning(
				'Return could not read the payment',
				array(
					'order_id'   => $order->get_id(),
					'payment_id' => $pid,
					'code'       => $error->get_error_code(),
				)
			);
		}

		$order = wc_get_order( $order->get_id() );
		if ( Launch_Page::forget( $order ) ) {
			// The shopper is back: this attempt's payment form is not posted again.
			$order->save();
		}
		self::finish( self::destination( $order, $decision ) );
	}

	/**
	 * Sends the shopper on. The return may load inside the payment window the
	 * launch page opened: then the store's tab goes to the destination and the
	 * window closes. Otherwise this tab goes there.
	 *
	 * @param string $url Destination on this site.
	 * @return void
	 */
	private static function finish( $url ) {
		$url = wp_validate_redirect( $url, home_url( '/' ) );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- finish_page() escapes every value it prints.
		echo self::finish_page( $url );
		exit;
	}

	/**
	 * The page that sends the shopper on: to the store's tab when it opened this
	 * window (same site only), else in place. A meta refresh and a link cover
	 * browsers without JavaScript.
	 *
	 * @param string $url Destination.
	 * @return string
	 */
	public static function finish_page( $url ) {
		$json = wp_json_encode( (string) $url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
		return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="2;url=' . esc_url( $url ) . '">
<title>' . esc_html__( 'Payment', 'outlinepay-for-woocommerce' ) . '</title>
<style>:root{color-scheme:light dark}body{margin:0;padding:48px 16px;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;text-align:center}a{color:inherit}</style>
</head>
<body>
<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Continue to the store', 'outlinepay-for-woocommerce' ) . '</a></p>
<script>
(function () {
	var destination = ' . $json . ';
	try {
		var opener = window.opener;
		if (opener && opener !== window && !opener.closed && opener.location.host === window.location.host) {
			opener.location.href = destination;
			window.close();
			return;
		}
	} catch (e) {}
	window.location.replace(destination);
})();
</script>
</body>
</html>
';
	}

	/**
	 * Thank-you page for a paid or held order, the pay page otherwise.
	 *
	 * @param \WC_Order   $order    Order after the sync.
	 * @param string|null $decision Sync decision, null when the payment could not be read.
	 * @return string
	 */
	private static function destination( $order, $decision ) {
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			return $order->get_checkout_order_received_url();
		}

		if ( Order_Sync::RETRY === $decision ) {
			self::notice( __( 'Your payment was not completed. Try again or choose another method.', 'outlinepay-for-woocommerce' ) );
		} else {
			self::notice( __( 'We could not confirm your payment yet. If you paid, this order updates in a few minutes. Otherwise, pay again below.', 'outlinepay-for-woocommerce' ) );
		}
		return $order->get_checkout_payment_url();
	}

	/**
	 * Adds a notice when a shopper session exists.
	 *
	 * @param string $message Notice.
	 * @return void
	 */
	private static function notice( $message ) {
		if ( function_exists( 'wc_add_notice' ) && WC()->session ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Refuses a return that does not name a known order and payment.
	 *
	 * @return void
	 */
	private static function reject() {
		wp_die(
			esc_html__( 'This payment link is not valid.', 'outlinepay-for-woocommerce' ),
			esc_html__( 'Payment', 'outlinepay-for-woocommerce' ),
			array( 'response' => 400 )
		);
	}
}
