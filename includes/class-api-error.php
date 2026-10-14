<?php
/**
 * An error answer from the OutlinePay plugin API, or a network failure.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status, the error code and, for 409 refusals, the payment.
 */
class Api_Error extends \Exception {

	/**
	 * HTTP status, or 0 for a network failure.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Error code from the envelope, e.g. order_already_paid.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Parameter the error names, if any.
	 *
	 * @var string|null
	 */
	private $param;

	/**
	 * Payment carried by a 409 refusal, if any.
	 *
	 * @var array|null
	 */
	private $payment;

	/**
	 * Constructor.
	 *
	 * @param int         $http_status HTTP status or 0.
	 * @param string      $error_code  Error code.
	 * @param string      $message     Server message (never logged).
	 * @param string|null $param       Named parameter.
	 * @param array|null  $payment     Payment from a refusal.
	 */
	public function __construct( $http_status, $error_code, $message = '', $param = null, $payment = null ) {
		parent::__construct( (string) $message );
		$this->http_status = (int) $http_status;
		$this->error_code  = (string) $error_code;
		$this->param       = $param;
		$this->payment     = is_array( $payment ) ? $payment : null;
	}

	/**
	 * HTTP status, 0 for a network failure.
	 *
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Parameter name.
	 *
	 * @return string|null
	 */
	public function get_param() {
		return $this->param;
	}

	/**
	 * Payment carried by a refusal.
	 *
	 * @return array|null
	 */
	public function get_payment() {
		return $this->payment;
	}

	/**
	 * True when retrying later may succeed (network, rate limit, provider trouble).
	 *
	 * @return bool
	 */
	public function is_transient() {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}
}
