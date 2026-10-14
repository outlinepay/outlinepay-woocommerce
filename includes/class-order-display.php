<?php
/**
 * Shows virtual account details on the thank-you page and in customer emails.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Virtual account display.
 */
final class Order_Display {

	/**
	 * Hooks the display.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( Settings::gateway_ids() as $gateway_id ) {
			add_action( 'woocommerce_thankyou_' . $gateway_id, array( self::class, 'thankyou' ) );
		}
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'admin_test_mode' ) );
	}

	/**
	 * The account, when the order waits for a deposit.
	 *
	 * @param \WC_Order $order Order.
	 * @return array|null
	 */
	public static function account( $order ) {
		if ( ! Order_Sync::is_ours( $order ) || ! $order->has_status( 'on-hold' ) || 'deposit' !== $order->get_meta( Order_Sync::META_HOLD ) ) {
			return null;
		}
		$account = $order->get_meta( Order_Sync::META_VA );
		if ( ! is_array( $account ) || empty( $account['account_number'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Formats an ISO 8601 deadline in the site's timezone.
	 *
	 * @param string $deadline ISO 8601.
	 * @return string
	 */
	public static function deadline( $deadline ) {
		$time = strtotime( (string) $deadline );
		if ( false === $time ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * True when the order's OutlinePay payment is a test payment.
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function is_test_payment( $order ) {
		return $order instanceof \WC_Order && 'test' === $order->get_meta( Order_Sync::META_MODE );
	}

	/**
	 * "Test payment" line in the admin order view.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function admin_test_mode( $order ) {
		if ( ! self::is_test_payment( $order ) ) {
			return;
		}
		echo '<p class="form-field form-field-wide outlinepay-test-payment"><strong>' . esc_html__( 'Test payment', 'outlinepay-for-woocommerce' ) . '</strong><br>' . esc_html__( 'OutlinePay took this payment in test mode. No real money moved.', 'outlinepay-for-woocommerce' ) . '</p>';
	}

	/**
	 * Thank-you page block.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function thankyou( $order_id ) {
		$account = self::account( wc_get_order( $order_id ) );
		if ( ! $account ) {
			return;
		}
		echo '<section class="outlinepay-virtual-account">';
		echo '<h2>' . esc_html__( 'Deposit details', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your order ships after the deposit arrives. Deposit the exact order total to this account.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<ul class="woocommerce-order-overview order_details">';
		echo '<li>' . esc_html__( 'Bank', 'outlinepay-for-woocommerce' ) . ' <strong>' . esc_html( $account['bank'] ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Account number', 'outlinepay-for-woocommerce' ) . ' <strong>' . esc_html( $account['account_number'] ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Deposit by', 'outlinepay-for-woocommerce' ) . ' <strong>' . esc_html( self::deadline( $account['deadline'] ) ) . '</strong></li>';
		echo '</ul>';
		echo '</section>';
	}

	/**
	 * Customer email block.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 * @return void
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin ) {
			return;
		}
		$account = self::account( $order );
		if ( ! $account ) {
			return;
		}
		$rows = array(
			__( 'Bank', 'outlinepay-for-woocommerce' ) => $account['bank'],
			__( 'Account number', 'outlinepay-for-woocommerce' ) => $account['account_number'],
			__( 'Deposit by', 'outlinepay-for-woocommerce' ) => self::deadline( $account['deadline'] ),
		);
		if ( $plain_text ) {
			echo esc_html( __( 'Deposit details', 'outlinepay-for-woocommerce' ) ) . "\n";
			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}
			echo "\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Deposit details', 'outlinepay-for-woocommerce' ) . '</h2><ul>';
		foreach ( $rows as $label => $value ) {
			echo '<li>' . esc_html( $label ) . ': <strong>' . esc_html( $value ) . '</strong></li>';
		}
		echo '</ul>';
	}
}
