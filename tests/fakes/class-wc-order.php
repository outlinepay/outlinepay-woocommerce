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
	public function get_date_created() {
		return null;
	}
}
