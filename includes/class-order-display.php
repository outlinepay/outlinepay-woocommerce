<?php
/**
 * Shows virtual account details and the card receipt link on the thank-you
 * page, the My Account order page and in customer emails, and the receipt
 * and test mode lines in the admin order screen.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Order display.
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
		add_action( 'woocommerce_view_order', array( self::class, 'view_order' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'email_receipt' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'admin_test_mode' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'admin_receipt' ) );
	}

	/**
	 * A receipt URL fit to show, or ''. Only https, or the OutlinePay API's
	 * own host (a development server may run without https).
	 *
	 * @param mixed $url Candidate from the payment.
	 * @return string
	 */
	public static function safe_receipt_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2000 || preg_match( '/[\s<>"\'\\\\]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return $url;
		}
		$base = wp_parse_url( Api_Client::configured_base() );
		if ( 'http' === $scheme && is_array( $base ) && isset( $base['host'] ) && strtolower( $base['host'] ) === strtolower( $parts['host'] ) ) {
			return $url;
		}
		return '';
	}

	/**
	 * The order's card receipt URL, or ''.
	 *
	 * @param mixed $order Order.
	 * @return string
	 */
	public static function receipt_url( $order ) {
		if ( ! $order instanceof \WC_Order || null === Settings::method_for_gateway( $order->get_payment_method() ) ) {
			return '';
		}
		return self::safe_receipt_url( $order->get_meta( Order_Sync::META_RECEIPT ) );
	}

	/**
	 * The receipt link, opening in a new tab, or '' when there is none.
	 *
	 * @param mixed $order Order.
	 * @return string
	 */
	public static function receipt_link( $order ) {
		$url = self::receipt_url( $order );
		if ( '' === $url ) {
			return '';
		}
		return '<a class="outlinepay-receipt-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View card receipt', 'outlinepay-for-woocommerce' ) . '</a>';
	}

	/**
	 * Receipt line in the admin order view.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function admin_receipt( $order ) {
		$link = self::receipt_link( $order );
		if ( '' === $link ) {
			return;
		}
		echo '<p class="form-field form-field-wide outlinepay-receipt">' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url and esc_html in receipt_link().
	}

	/**
	 * Deposit details and the receipt on the My Account order page.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function view_order( $order_id ) {
		$order   = wc_get_order( $order_id );
		$account = self::account( $order );
		if ( $account ) {
			self::render_deposit( $account );
		}
		self::render_receipt( $order );
	}

	/**
	 * Receipt link in the completed-order email to the shopper.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 * @param object    $email         The email.
	 * @return void
	 */
	public static function email_receipt( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! is_object( $email ) || ! isset( $email->id ) || 'customer_completed_order' !== $email->id ) {
			return;
		}
		$url = self::receipt_url( $order );
		if ( '' === $url ) {
			return;
		}
		if ( $plain_text ) {
			echo esc_html__( 'View card receipt', 'outlinepay-for-woocommerce' ) . ': ' . esc_url_raw( $url ) . "\n\n";
			return;
		}
		echo '<p>' . self::receipt_link( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url and esc_html in receipt_link().
	}

	/**
	 * The receipt paragraph for shopper pages.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	private static function render_receipt( $order ) {
		$link = self::receipt_link( $order );
		if ( '' !== $link ) {
			echo '<p class="outlinepay-receipt">' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url and esc_html in receipt_link().
		}
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
		// The mode is written when OutlinePay completes the order. The gateway
		// check keeps an order that later moved to another method from showing it.
		return $order instanceof \WC_Order
			&& null !== Settings::method_for_gateway( $order->get_payment_method() )
			&& 'test' === $order->get_meta( Order_Sync::META_MODE );
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
		$order   = wc_get_order( $order_id );
		$account = self::account( $order );
		if ( $account ) {
			self::render_deposit( $account );
		}
		self::render_receipt( $order );
	}

	/**
	 * The deposit details block.
	 *
	 * @param array $account bank, account_number, deadline.
	 * @return void
	 */
	private static function render_deposit( array $account ) {
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
