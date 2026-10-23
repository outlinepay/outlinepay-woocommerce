<?php
/**
 * One payment gateway per method, all from this class.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce gateway for one OutlinePay method, e.g. outlinepay_card.
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * Most characters in the product name sent to the provider.
	 */
	const PRODUCT_NAME_MAX = 40;

	/**
	 * Test seam for the API client.
	 *
	 * @var callable|null
	 */
	private static $client_factory = null;

	/**
	 * Method key, e.g. card.
	 *
	 * @var string
	 */
	public $outlinepay_method;

	/**
	 * Constructor.
	 *
	 * @param string $method Method key from Settings::METHODS.
	 */
	public function __construct( $method ) {
		$this->outlinepay_method = $method;
		$this->id                = Settings::gateway_id( $method );
		$this->has_fields        = false;
		$this->supports          = self::features( $method );
		$this->set_admin_title( $method );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = Settings::method_name( $method );
		$this->description = '';
		$this->enabled     = Settings::shown( $method ) ? 'yes' : 'no';
	}

	/**
	 * The title and description WooCommerce shows on Settings > Payments.
	 * When WooCommerce collapses the tab, the card gateway is the one
	 * OutlinePay row and the other methods have none, so WooCommerce hides
	 * them. Otherwise each method has its own row.
	 *
	 * @param string $method Method key.
	 * @return void
	 */
	private function set_admin_title( $method ) {
		$description = __( 'Takes payment through your payment provider, connected by OutlinePay. Connect the store and choose which methods to show on the OutlinePay page.', 'outlinepay-for-woocommerce' );
		if ( ! Settings::collapse_payments_tab() ) {
			$this->method_title = sprintf(
				/* translators: %s: payment method name, such as Credit or debit card. */
				__( 'OutlinePay: %s', 'outlinepay-for-woocommerce' ),
				Settings::method_name( $method )
			);
			$this->method_description = $description;
			return;
		}
		if ( 'card' !== $method ) {
			$this->method_title       = '';
			$this->method_description = '';
			return;
		}
		if ( Settings::is_connected() ) {
			$this->method_title       = __( 'OutlinePay', 'outlinepay-for-woocommerce' );
			$this->method_description = $description;
			return;
		}
		$this->method_title       = __( 'OutlinePay (not connected)', 'outlinepay-for-woocommerce' );
		$this->method_description = __( 'Connect the store on the OutlinePay page to take payments.', 'outlinepay-for-woocommerce' );
	}

	/**
	 * Features for a method. Refunds only when the provider turned them on for
	 * this store and can refund the method through its API. Otherwise
	 * WooCommerce offers a manual refund only.
	 *
	 * @param string $method Method key.
	 * @return string[]
	 */
	public static function features( $method ) {
		return Settings::refundable( $method ) ? array( 'products', 'refunds' ) : array( 'products' );
	}

	/**
	 * Refund button in the order screen. WooCommerce has saved its refund row
	 * and deletes it when this answers an error.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason. Stays in WooCommerce, never sent.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		unset( $reason );
		return Refunds::process( $order_id, $amount );
	}

	/**
	 * Whether the order screen offers "Refund via OutlinePay": the same rule
	 * Refunds::process applies, so the button never leads to a refusal. The
	 * order is paid, through an OutlinePay payment, and not settled outside
	 * the store (a hold for mismatch, review or foreign card, or a double
	 * payment, is refunded in the provider's dashboard). A pending order the
	 * shopper never paid gets WooCommerce's manual refund only.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		if ( ! $order || ! $this->supports( 'refunds' ) || ! $order->get_date_paid() ) {
			return false;
		}
		return Api_Client::valid_payment_id( $order->get_meta( Order_Sync::META_PAYMENT_ID ) )
			&& '' === (string) $order->get_meta( Order_Sync::META_SETTLED );
	}

	/**
	 * Only the show switch lives here. Everything else is on the OutlinePay page.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Show at checkout', 'outlinepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show this method at checkout when your provider has it enabled', 'outlinepay-for-woocommerce' ),
				'default' => 'yes',
			),
		);
	}

	/**
	 * Settings screen: a pointer to the OutlinePay page, then the show switch.
	 *
	 * @return void
	 */
	public function admin_options() {
		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo '<p>' . esc_html( $this->get_method_description() ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( Admin_Page::url() ) . '">' . esc_html__( 'Open the OutlinePay page', 'outlinepay-for-woocommerce' ) . '</a></p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Shown only when connected, the store sells in KRW, the provider has the
	 * method enabled and the merchant has not hidden it.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! Settings::is_connected() ) {
			return false;
		}
		if ( 'KRW' !== get_woocommerce_currency() ) {
			return false;
		}
		return Settings::method_enabled_by_server( $this->outlinepay_method );
	}

	/**
	 * One pay click: open a provider window and send the shopper there.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $this->refuse( __( 'This order could not be found.', 'outlinepay-for-woocommerce' ) );
		}
		if ( ! Settings::is_connected() ) {
			// Disconnected, revoked, or a copy of the store (see Site_Identity).
			return $this->refuse( __( 'This payment method is not available right now. Choose another method.', 'outlinepay-for-woocommerce' ) );
		}
		if ( 'KRW' !== $order->get_currency() ) {
			return $this->refuse( __( 'This payment method takes Korean won (KRW) only.', 'outlinepay-for-woocommerce' ) );
		}
		$amount = Amount::to_krw( $order->get_total() );
		if ( null === $amount ) {
			return $this->refuse( __( 'The order total must be a whole number of won. Contact the store.', 'outlinepay-for-woocommerce' ) );
		}

		$product_name = self::product_name( Launch_Page::store_name(), (string) $order->get_order_number() );
		$fields       = array(
			'order_id'       => $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'amount'         => $amount,
			'currency'       => 'KRW',
			'payment_method' => $this->outlinepay_method,
			'return_url'     => self::return_url( $order ),
			'buyer'          => self::buyer( $order ),
		);
		if ( '' !== $product_name ) {
			$fields['product_name'] = $product_name;
		}

		try {
			$payment = self::client()->create_payment( $fields );
		} catch ( Api_Error $error ) {
			return $this->handle_refusal( $order, $error );
		}

		if ( ! isset( $payment['id'] ) || ! Api_Client::valid_payment_id( $payment['id'] ) ) {
			Logger::error( 'Payment answer missing id or redirect', array( 'order_id' => $order->get_id() ) );
			return $this->refuse( __( 'The payment could not be started. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
		}

		$window = isset( $payment['window'] ) && is_array( $payment['window'] ) ? $payment['window'] : array();
		if ( isset( $window['type'] ) && 'form' === $window['type'] ) {
			// The provider's window opens with a form: the store's launch page posts it.
			$launch = Launch_Page::from_window( $window, $payment['id'], $product_name, time() );
			if ( null === $launch ) {
				Logger::error(
					'Payment answer has a payment form this store cannot post',
					array(
						'order_id'   => $order->get_id(),
						'payment_id' => $payment['id'],
					)
				);
				return $this->refuse( __( 'The payment could not be started. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
			}
			$order->update_meta_data( Order_Sync::META_LAUNCH, $launch );
			$redirect = Launch_Page::url( $order, $payment['id'] );
		} else {
			$redirect = isset( $payment['redirect_url'] ) && is_string( $payment['redirect_url'] ) ? $payment['redirect_url'] : '';
			if ( ! preg_match( '#^https?://#i', $redirect ) ) {
				Logger::error( 'Payment answer missing id or redirect', array( 'order_id' => $order->get_id() ) );
				return $this->refuse( __( 'The payment could not be started. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
			}
			// One launch slot per order: an earlier attempt's form is not posted again.
			Launch_Page::forget( $order );
		}

		$order->update_meta_data( Order_Sync::META_PAYMENT_ID, $payment['id'] );
		Order_Sync::add_attempt( $order, $payment['id'] );
		$order->save();
		Logger::info(
			'Payment opened',
			array(
				'order_id'   => $order->get_id(),
				'payment_id' => $payment['id'],
				'method'     => $this->outlinepay_method,
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Replaces the API client, for tests. Null restores the default.
	 *
	 * @param callable|null $factory Returns an Api_Client.
	 * @return void
	 */
	public static function set_client_factory( $factory ) {
		self::$client_factory = $factory;
	}

	/**
	 * The API client.
	 *
	 * @return Api_Client
	 */
	private static function client() {
		return self::$client_factory ? call_user_func( self::$client_factory ) : new Api_Client();
	}

	/**
	 * What the provider shows the shopper as the item: the store name and the
	 * order number, at most PRODUCT_NAME_MAX characters, no control or format characters.
	 * '' when nothing is left, and then none is sent.
	 *
	 * @param string $store_name   Store name, plain text.
	 * @param string $order_number Order number.
	 * @return string
	 */
	public static function product_name( $store_name, $order_number ) {
		// \p{C} is every control, format, private-use and unassigned character: the
		// server refuses all of them, as Ruby's [[:cntrl:]] does.
		$name = preg_replace( '/[\p{C}\s]+/u', ' ', trim( $store_name . ' #' . $order_number ) );
		if ( ! is_string( $name ) ) {
			// Not valid UTF-8: send none rather than a broken name.
			return '';
		}
		$name = trim( $name );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::PRODUCT_NAME_MAX, 'UTF-8' ) : substr( $name, 0, self::PRODUCT_NAME_MAX );
		return trim( $name );
	}

	/**
	 * Where the shopper comes back: our wc-api endpoint, carrying the order key.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function return_url( $order ) {
		return add_query_arg( array( 'key' => $order->get_order_key() ), WC()->api_request_url( 'outlinepay_return' ) );
	}

	/**
	 * Buyer details for the provider. Passed through, never stored, never logged.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function buyer( $order ) {
		$address = implode(
			' ',
			array_filter(
				array(
					$order->get_billing_postcode(),
					$order->get_billing_state(),
					$order->get_billing_city(),
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
				),
				'strlen'
			)
		);
		$fields  = array(
			'name'    => array( trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() ), 200 ),
			'email'   => array( (string) $order->get_billing_email(), 254 ),
			'phone'   => array( (string) $order->get_billing_phone(), 50 ),
			'address' => array( $address, 500 ),
		);
		$buyer   = array();
		foreach ( $fields as $key => $field ) {
			$value = trim( $field[0] );
			if ( '' !== $value ) {
				$buyer[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $field[1] ) : substr( $value, 0, $field[1] );
			}
		}
		return $buyer;
	}

	/**
	 * Maps a refused pay click to an order change and a notice.
	 *
	 * @param \WC_Order $order Order.
	 * @param Api_Error $error Refusal.
	 * @return array
	 */
	private function handle_refusal( $order, Api_Error $error ) {
		$code = $error->get_error_code();
		Logger::warning(
			'Payment refused',
			array(
				'order_id'    => $order->get_id(),
				'code'        => $code,
				'http_status' => $error->get_http_status(),
				'method'      => $this->outlinepay_method,
			)
		);

		switch ( $code ) {
			case 'order_already_paid':
				$payment = $error->get_payment();
				if ( $payment ) {
					Order_Sync::apply( $order, $payment );
				}
				$order = wc_get_order( $order->get_id() );
				if ( $order instanceof \WC_Order && $order->is_paid() ) {
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}
				// A paid payment this store did not open puts the order on hold instead (see Order_Sync).
				return $this->refuse( __( 'This order cannot be paid right now. Contact the store.', 'outlinepay-for-woocommerce' ) );

			case 'order_needs_review':
				$payment = $error->get_payment();
				if ( $payment ) {
					Order_Sync::apply( $order, $payment );
				}
				return $this->refuse( __( 'A payment for this order is being reviewed. The store will contact you. Do not pay again.', 'outlinepay-for-woocommerce' ) );

			case 'order_in_progress':
				return $this->refuse( __( 'A payment for this order is already being opened. Wait a moment and try again.', 'outlinepay-for-woocommerce' ) );

			case 'payment_method_not_enabled':
				Scheduler::queue_methods_refresh();
				return $this->refuse( __( 'This payment method is not available right now. Choose another method.', 'outlinepay-for-woocommerce' ) );

			default:
				return $this->refuse( __( 'The payment could not be started. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
		}
	}

	/**
	 * Adds a shopper notice and answers failure.
	 *
	 * @param string $message Notice.
	 * @return array
	 */
	private function refuse( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}
}
