<?php
/**
 * A minimal stand-in for WC_Order, for unit tests only.
 */
class WC_Order {
	public $id;
	public $status          = 'pending';
	public $payment_method  = 'outlinepay_card';
	public $method_title    = '';
	public $total           = '25000';
	public $currency        = 'KRW';
	public $meta            = array();
	public $notes           = array();
	public $transaction     = '';
	public $completed_calls = 0;
	public $saves           = 0;
	public $date_paid       = null;
	public $customer_id     = 0;
	public $order_key       = 'wc_order_abc123';
	public $billing         = array();
	/** @var WC_Order_Refund[] */
	public $refunds         = array();

	public function __construct( $id = 42 ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function save() {
		++$this->saves;
		return $this->id;
	}
	public function is_paid() {
		return in_array( $this->status, array( 'processing', 'completed' ), true );
	}
	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}
	public function get_status() {
		return $this->status;
	}
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
	public function payment_complete( $transaction = '' ) {
		++$this->completed_calls;
		$this->transaction = $transaction;
		$this->status      = 'processing';
		return true;
	}
	public function get_payment_method() {
		return $this->payment_method;
	}
	public function set_payment_method( $method ) {
		$this->payment_method = $method;
	}
	public function set_payment_method_title( $title ) {
		$this->method_title = $title;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_date_paid() {
		return $this->date_paid;
	}
	public function get_transaction_id() {
		return $this->transaction;
	}
	public function get_checkout_order_received_url() {
		return 'https://shop.example/checkout/order-received/' . $this->id . '/';
	}
	public function get_date_created() {
		return null;
	}
	public function get_customer_id() {
		return $this->customer_id;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_order_key() {
		return $this->order_key;
	}
	public function get_checkout_payment_url() {
		return 'https://shop.example/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->order_key;
	}
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'get_billing_' ) ) {
			$field = substr( $name, strlen( 'get_billing_' ) );
			return isset( $this->billing[ $field ] ) ? $this->billing[ $field ] : '';
		}
		throw new \BadMethodCallException( 'WC_Order fake has no ' . $name );
	}
	public function get_view_order_url() {
		return 'https://shop.example/my-account/view-order/' . $this->id . '/';
	}
	/** Newest first, as WooCommerce returns them. */
	public function get_refunds() {
		$refunds = $this->refunds;
		usort(
			$refunds,
			static function ( $a, $b ) {
				return $b->get_id() - $a->get_id();
			}
		);
		return $refunds;
	}
	public function get_total_refunded() {
		$total = 0;
		foreach ( $this->refunds as $refund ) {
			$total += (float) $refund->get_amount();
		}
		return $total;
	}
}

/**
 * A minimal stand-in for WC_Order_Refund.
 */
class WC_Order_Refund {
	public $id;
	public $amount;
	public $reason = '';
	public $meta   = array();
	public $saves  = 0;

	public function __construct( $id, $amount, $reason = '' ) {
		$this->id     = $id;
		$this->amount = $amount;
		$this->reason = $reason;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_amount() {
		return $this->amount;
	}
	public function get_reason() {
		return $this->reason;
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		++$this->saves;
		return $this->id;
	}
}

/**
 * A minimal stand-in for WP_Error.
 */
class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * A minimal stand-in for WC_Payment_Gateway, enough to load the gateway class.
 */
class WC_Payment_Gateway {
	public $id;
	public $supports = array();
	public $title;
	public $description;
	public $method_title;
	public $method_description;
	public $enabled;
	public $form_fields = array();
	public function init_settings() {
	}
	public function supports( $feature ) {
		return in_array( $feature, (array) $this->supports, true );
	}
}
