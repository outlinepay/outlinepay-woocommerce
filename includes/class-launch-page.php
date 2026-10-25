<?php
/**
 * The store's own launch step: ?wc-api=outlinepay_launch.
 *
 * Some providers open their payment window with a form the shopper's browser
 * posts, not with a link. OutlinePay answers that form, and this page shows
 * the order with a Pay button. The click opens a named window and posts the
 * form into it. The page is standalone (no theme) and runs no provider script.
 * It copies whatever action and fields come back, for any provider.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, checks and renders the payment form for one order.
 */
final class Launch_Page {

	const ENDPOINT       = 'outlinepay_launch';
	const WINDOW_NAME    = 'outlinepay_pay';
	const DEFAULT_WIDTH  = 548;
	const DEFAULT_HEIGHT = 550;
	const OPEN_WIDTH     = 900;
	const OPEN_HEIGHT    = 650;
	const MIN_SIZE       = 100;
	const MAX_SIZE       = 2000;
	const MAX_FIELDS     = 200;
	const MAX_NAME       = 100;
	const MAX_VALUE      = 65535;
	const MAX_ACTION     = 2048;

	/**
	 * Seconds a form stays usable when the answer names no expiry.
	 */
	const DEFAULT_TTL = 1800;

	/**
	 * Most seconds a form stays usable, whatever the answer says.
	 */
	const MAX_TTL = 86400;

	/**
	 * Hooks the endpoint.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( self::class, 'handle' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'on_closed' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'on_closed' ), 10, 2 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( self::class, 'on_erased' ) );
	}

	/**
	 * A cancelled or failed order (an unpaid order WooCommerce timed out, for
	 * one) keeps no payment form.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order    Order, when WooCommerce passes it.
	 * @return void
	 */
	public static function on_closed( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && self::forget( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Where process_payment sends the shopper: our endpoint, with the order key.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payment_id Payment id.
	 * @return string
	 */
	public static function url( $order, $payment_id ) {
		return add_query_arg(
			array(
				'key'        => $order->get_order_key(),
				'payment_id' => $payment_id,
			),
			WC()->api_request_url( self::ENDPOINT )
		);
	}

	/**
	 * True for an absolute https URL with a host and nothing that could break
	 * out of an attribute. javascript:, data: and http: are refused.
	 *
	 * @param mixed $action Form action.
	 * @return bool
	 */
	public static function safe_action( $action ) {
		if ( ! is_string( $action ) || '' === $action || strlen( $action ) > self::MAX_ACTION ) {
			return false;
		}
		if ( 0 !== stripos( $action, 'https://' ) || preg_match( '/[\x00-\x20\x7F"\'<>\\\\`]/', $action ) ) {
			return false;
		}
		$parts = wp_parse_url( $action );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& '' !== $parts['host']
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}

	/**
	 * The launch record for a form window from POST /payments, or null when the
	 * form is not one this page can post.
	 *
	 * @param mixed  $window       The answer's window object.
	 * @param string $payment_id   Payment id.
	 * @param string $product_name What the provider shows as the item, '' when not sent.
	 * @param int    $now          Current Unix time.
	 * @return array|null
	 */
	public static function from_window( $window, $payment_id, $product_name, $now ) {
		if ( ! is_array( $window ) || ! isset( $window['type'] ) || 'form' !== $window['type'] ) {
			return null;
		}
		$expires = isset( $window['expires_at'] ) && is_string( $window['expires_at'] ) ? strtotime( $window['expires_at'] ) : false;
		$expires = false === $expires ? $now + self::DEFAULT_TTL : min( (int) $expires, $now + self::MAX_TTL );

		return self::normalise(
			array(
				'payment_id'     => $payment_id,
				'action'         => isset( $window['action'] ) ? $window['action'] : null,
				'method'         => isset( $window['method'] ) ? $window['method'] : 'post',
				'accept_charset' => isset( $window['accept_charset'] ) ? $window['accept_charset'] : '',
				'width'          => isset( $window['width'] ) ? $window['width'] : null,
				'height'         => isset( $window['height'] ) ? $window['height'] : null,
				'fields'         => isset( $window['fields'] ) ? $window['fields'] : null,
				'expires_at'     => $expires,
				'product_name'   => $product_name,
			),
			$now
		);
	}

	/**
	 * Checks a launch record, from the answer or from order meta, and returns it
	 * in its stored shape. Null when anything is off or it has expired.
	 *
	 * @param mixed $launch Launch record.
	 * @param int   $now    Current Unix time.
	 * @return array|null
	 */
	public static function normalise( $launch, $now ) {
		if ( ! is_array( $launch ) || ! isset( $launch['payment_id'], $launch['action'], $launch['fields'], $launch['expires_at'] ) ) {
			return null;
		}
		if ( ! Api_Client::valid_payment_id( $launch['payment_id'] ) || ! self::safe_action( $launch['action'] ) ) {
			return null;
		}
		if ( ! is_int( $launch['expires_at'] ) || $launch['expires_at'] <= $now ) {
			return null;
		}
		$method = isset( $launch['method'] ) && is_string( $launch['method'] ) ? strtolower( $launch['method'] ) : '';
		if ( 'post' !== $method ) {
			return null;
		}
		$charset = isset( $launch['accept_charset'] ) && is_string( $launch['accept_charset'] ) ? strtolower( trim( $launch['accept_charset'] ) ) : '';
		if ( '' !== $charset && ! preg_match( '/^[a-z0-9._:-]{1,40}$/', $charset ) ) {
			return null;
		}
		$fields = self::fields( $launch['fields'] );
		if ( null === $fields ) {
			return null;
		}
		$product = isset( $launch['product_name'] ) && is_string( $launch['product_name'] ) ? $launch['product_name'] : '';

		return array(
			'payment_id'     => $launch['payment_id'],
			'action'         => $launch['action'],
			'method'         => 'post',
			'accept_charset' => $charset,
			'width'          => self::size( isset( $launch['width'] ) ? $launch['width'] : null, self::DEFAULT_WIDTH ),
			'height'         => self::size( isset( $launch['height'] ) ? $launch['height'] : null, self::DEFAULT_HEIGHT ),
			'fields'         => $fields,
			'expires_at'     => $launch['expires_at'],
			'product_name'   => $product,
		);
	}

	/**
	 * The form's [name, value] pairs, in order, as strings. Null when there are
	 * none, too many, or one is malformed.
	 *
	 * @param mixed $fields Fields.
	 * @return array|null
	 */
	private static function fields( $fields ) {
		if ( ! is_array( $fields ) || array() === $fields || count( $fields ) > self::MAX_FIELDS ) {
			return null;
		}
		$pairs = array();
		foreach ( $fields as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) || ! isset( $pair[0] ) || ! array_key_exists( 1, $pair ) ) {
				return null;
			}
			$name  = $pair[0];
			$value = null === $pair[1] ? '' : $pair[1];
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > self::MAX_NAME || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				return null;
			}
			if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
				return null;
			}
			$value = (string) $value;
			if ( strlen( $value ) > self::MAX_VALUE ) {
				return null;
			}
			$pairs[] = array( $name, $value );
		}
		return $pairs;
	}

	/**
	 * A window size in pixels, or the default when it is out of range.
	 *
	 * @param mixed $value    Size.
	 * @param int   $fallback Default.
	 * @return int
	 */
	private static function size( $value, $fallback ) {
		return is_int( $value ) && $value >= self::MIN_SIZE && $value <= self::MAX_SIZE ? $value : $fallback;
	}

	/**
	 * The size the window opens at: at least OPEN_WIDTH by OPEN_HEIGHT, since
	 * some providers' desktop pages cut off their amount panel in a smaller
	 * window. A larger size from the answer is kept. The page's script caps
	 * both at the screen.
	 *
	 * @param int $width  Width from the launch record.
	 * @param int $height Height from the launch record.
	 * @return int[] array( width, height ).
	 */
	public static function open_size( $width, $height ) {
		return array( max( (int) $width, self::OPEN_WIDTH ), max( (int) $height, self::OPEN_HEIGHT ) );
	}

	/**
	 * Drops the order's launch record. The caller saves the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool True when there was one.
	 */
	public static function forget( $order ) {
		if ( '' === $order->get_meta( Order_Sync::META_LAUNCH ) ) {
			return false;
		}
		$order->delete_meta_data( Order_Sync::META_LAUNCH );
		return true;
	}

	/**
	 * WooCommerce's personal data eraser ran on the order: the payment form
	 * carries the shopper's details too, so it goes as well.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function on_erased( $order ) {
		if ( $order instanceof \WC_Order && self::forget( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Drops expired payment forms from unpaid orders the shopper walked away
	 * from, which no return, payment or cancellation ever cleans up, and notes
	 * the expired attempt once. Runs with the background payment check.
	 *
	 * @param int $now   Current Unix time.
	 * @param int $limit Orders per run.
	 * @return int Forms dropped.
	 */
	public static function sweep_expired( $now, $limit = 50 ) {
		$orders  = wc_get_orders(
			array(
				'status'     => array( 'pending' ),
				'limit'      => $limit,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to 50 orders per run, every 15 minutes.
					array(
						'key'     => Order_Sync::META_LAUNCH,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$dropped = 0;
		foreach ( $orders as $order ) {
			$stored = $order->get_meta( Order_Sync::META_LAUNCH );
			if ( null === self::normalise( $stored, $now ) && self::forget( $order ) ) {
				if ( is_array( $stored ) && isset( $stored['payment_id'] ) && Api_Client::valid_payment_id( $stored['payment_id'] ) ) {
					Order_Sync::note_attempt( $order, $stored['payment_id'], 'expired' );
				}
				$order->save();
				++$dropped;
			}
		}
		return $dropped;
	}

	/**
	 * The order and its launch record for a request, or null when the link is
	 * not valid: not a GET, no such order, another key, not ours, no form,
	 * another payment, expired, or the order is paid or on hold.
	 *
	 * @param string $method     Request method.
	 * @param string $key        Order key from the URL.
	 * @param string $payment_id Payment id from the URL.
	 * @param int    $now        Current Unix time.
	 * @return array|null array( \WC_Order, array ) or null.
	 */
	public static function resolve( $method, $key, $payment_id, $now ) {
		if ( 'GET' !== $method || ! is_string( $key ) || '' === $key || ! Api_Client::valid_payment_id( $payment_id ) ) {
			return null;
		}
		$order = wc_get_order( wc_get_order_id_by_order_key( $key ) );
		if ( ! $order instanceof \WC_Order || ! Return_Handler::key_matches( $order->get_order_key(), $key ) || ! Order_Sync::is_ours( $order ) ) {
			return null;
		}
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			return null;
		}
		$stored = $order->get_meta( Order_Sync::META_LAUNCH );
		if ( ! is_array( $stored ) || ! isset( $stored['payment_id'] ) || ! is_string( $stored['payment_id'] ) || ! hash_equals( $stored['payment_id'], $payment_id ) ) {
			return null;
		}
		$launch = self::normalise( $stored, $now );
		if ( null === $launch ) {
			// Expired or unusable: nothing will post it again.
			self::forget( $order );
			$order->save();
			return null;
		}
		return array( $order, $launch );
	}

	/**
	 * Shows the launch page, or refuses the link.
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key in the URL is the proof of ownership, as on WooCommerce's own pay page. GET changes nothing.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$pid    = isset( $_GET['payment_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$found = self::resolve( $method, $key, $pid, time() );
		if ( null === $found ) {
			wp_die(
				esc_html__( 'This payment link is not valid.', 'outlinepay-for-woocommerce' ),
				esc_html__( 'Payment', 'outlinepay-for-woocommerce' ),
				array( 'response' => 400 )
			);
		}
		list( $order, $launch ) = $found;

		$gateway_method = Settings::method_for_gateway( $order->get_payment_method() );
		$page           = array(
			'store'        => self::store_name(),
			'order_number' => (string) $order->get_order_number(),
			'amount_html'  => wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ),
			'method_label' => null === $gateway_method ? '' : Settings::method_name( $gateway_method ),
			'back_url'     => $order->get_checkout_payment_url(),
			'lang'         => get_bloginfo( 'language' ),
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value it prints.
		echo self::render( $launch, $page );
		exit;
	}

	/**
	 * The store's name as plain text.
	 *
	 * @return string
	 */
	public static function store_name() {
		return trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * An attribute value, encoded exactly: unlike esc_attr, existing entities
	 * are encoded again, so a field value reaches the provider byte for byte.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * The launch page. Every value is escaped here.
	 *
	 * @param array $launch Launch record (see normalise()).
	 * @param array $page   store, order_number, amount_html, method_label, back_url, lang.
	 * @return string
	 */
	public static function render( array $launch, array $page ) {
		$lang    = isset( $page['lang'] ) && '' !== $page['lang'] ? $page['lang'] : 'en';
		$charset = '' !== $launch['accept_charset'] ? ' accept-charset="' . esc_attr( $launch['accept_charset'] ) . '"' : '';

		$inputs = '';
		foreach ( $launch['fields'] as $pair ) {
			$inputs .= '<input type="hidden" name="' . self::attr( $pair[0] ) . '" value="' . self::attr( $pair[1] ) . '">' . "\n";
		}

		$details  = '<dl>';
		$details .= '<dt>' . esc_html__( 'Order', 'outlinepay-for-woocommerce' ) . '</dt><dd>' . esc_html( '#' . $page['order_number'] ) . '</dd>';
		$details .= '<dt>' . esc_html__( 'Total', 'outlinepay-for-woocommerce' ) . '</dt><dd>' . wp_kses_post( $page['amount_html'] ) . '</dd>';
		if ( '' !== $page['method_label'] ) {
			$details .= '<dt>' . esc_html__( 'Payment method', 'outlinepay-for-woocommerce' ) . '</dt><dd>' . esc_html( $page['method_label'] ) . '</dd>';
		}
		$details .= '</dl>';

		list( $width, $height ) = self::open_size( $launch['width'], $launch['height'] );

		return '<!DOCTYPE html>
<html lang="' . esc_attr( $lang ) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html__( 'Payment', 'outlinepay-for-woocommerce' ) . ' - ' . esc_html( $page['store'] ) . '</title>
<style>
:root{--bg:#f6f7f7;--card:#fff;--text:#1d2327;--muted:#50575e;--line:#dcdcde;--accent:#1d2327;--accent-text:#fff;--warn:#b32d2e;color-scheme:light dark}
@media (prefers-color-scheme:dark){:root{--bg:#141517;--card:#1e2023;--text:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--accent:#f0f0f1;--accent-text:#141517;--warn:#f86368}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;word-break:keep-all;overflow-wrap:anywhere}
main{max-width:420px;margin:48px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:24px}
h1{font-size:20px;margin:0 0 16px;overflow-wrap:anywhere}
dl{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;margin:0 0 24px}
dt{color:var(--muted)}
dd{margin:0;text-align:right;overflow-wrap:anywhere}
button{width:100%;padding:12px 16px;border:0;border-radius:6px;background:var(--accent);color:var(--accent-text);font:inherit;font-weight:600;cursor:pointer}
button:disabled{opacity:.5;cursor:default}
p{margin:16px 0 0;color:var(--muted);font-size:14px}
#outlinepay-closed{color:var(--warn)}
a{color:inherit}
</style>
</head>
<body>
<main>
<div class="card">
<h1>' . esc_html( $page['store'] ) . '</h1>
' . $details . '
<form id="outlinepay-launch" method="post" action="' . esc_url( $launch['action'], array( 'https' ) ) . '"' . $charset . ' target="' . esc_attr( self::WINDOW_NAME ) . '" data-width="' . esc_attr( (string) $width ) . '" data-height="' . esc_attr( (string) $height ) . '">
' . $inputs . '<button type="submit" id="outlinepay-pay" data-busy="' . esc_attr__( 'Finish the payment in the window', 'outlinepay-for-woocommerce' ) . '">' . esc_html__( 'Pay', 'outlinepay-for-woocommerce' ) . '</button>
</form>
<p><a href="' . esc_url( $page['back_url'] ) . '">' . esc_html__( 'Back to the order', 'outlinepay-for-woocommerce' ) . '</a></p>
<p>' . esc_html__( 'A payment window opens. Finish there and this page continues.', 'outlinepay-for-woocommerce' ) . '</p>
<p id="outlinepay-closed" hidden>' . esc_html__( 'The payment window was closed. Pay again or go back.', 'outlinepay-for-woocommerce' ) . ' <a href="' . esc_url( $page['back_url'] ) . '">' . esc_html__( 'Back to the order', 'outlinepay-for-woocommerce' ) . '</a></p>
</div>
</main>
<script>
(function () {
	var form = document.getElementById("outlinepay-launch");
	var button = document.getElementById("outlinepay-pay");
	var closed = document.getElementById("outlinepay-closed");
	var label = button.textContent;
	var timer = null;
	// The size the store asks for, no larger than the screen.
	function fit(size, available) {
		size = parseInt(size, 10);
		return available > 0 ? Math.min(size, available) : size;
	}
	form.addEventListener("submit", function () {
		var display = window.screen || {};
		var features = "width=" + fit(form.getAttribute("data-width"), display.availWidth) + ",height=" + fit(form.getAttribute("data-height"), display.availHeight);
		var popup = window.open("", "' . self::WINDOW_NAME . '", features);
		if (!popup) {
			form.target = "_self";
			return;
		}
		form.target = "' . self::WINDOW_NAME . '";
		closed.hidden = true;
		if (timer) {
			clearInterval(timer);
		}
		// After the submit: a button disabled during it would not post the form.
		setTimeout(function () {
			button.disabled = true;
			button.textContent = button.getAttribute("data-busy");
		}, 0);
		timer = setInterval(function () {
			if (popup.closed) {
				clearInterval(timer);
				timer = null;
				button.disabled = false;
				button.textContent = label;
				closed.hidden = false;
			}
		}, 1000);
	});
})();
</script>
</body>
</html>
';
	}
}
