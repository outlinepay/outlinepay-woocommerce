<?php
/**
 * HTTP client for the OutlinePay plugin API.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * One method per endpoint. Every call returns the decoded JSON object or throws Api_Error.
 */
class Api_Client {

	const DEFAULT_BASE = 'https://api.outlinepay.com/plugin/v1';
	const TIMEOUT      = 20;

	/**
	 * Sends one request. Receives ( method, url, headers, body|null, timeout ) and
	 * returns array( 'status' => int, 'body' => string ), or throws Api_Error on a
	 * network failure.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Returns the store token, or null when not connected.
	 *
	 * @var callable
	 */
	private $token_provider;

	/**
	 * API base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param callable|null $token_provider Returns the store token or null.
	 * @param callable|null $transport      Test seam; defaults to wp_remote_request.
	 * @param string|null   $base           API base; defaults to OUTLINEPAY_API_BASE or production.
	 */
	public function __construct( $token_provider = null, $transport = null, $base = null ) {
		$this->token_provider = $token_provider ? $token_provider : array( Credentials::class, 'token' );
		$this->transport      = $transport ? $transport : array( self::class, 'wp_transport' );
		$this->base           = rtrim( null !== $base ? $base : self::configured_base(), '/' );
	}

	/**
	 * The API base: OUTLINEPAY_API_BASE from wp-config when set to an http(s) URL,
	 * production otherwise.
	 *
	 * @return string
	 */
	public static function configured_base() {
		if ( defined( 'OUTLINEPAY_API_BASE' ) && is_string( OUTLINEPAY_API_BASE ) && preg_match( '#^https?://#i', OUTLINEPAY_API_BASE ) ) {
			return OUTLINEPAY_API_BASE;
		}
		return self::DEFAULT_BASE;
	}

	/**
	 * POST /connect. Unauthenticated: the connect code is the credential.
	 *
	 * @param string $connect_code      Code from the payment provider.
	 * @param string $site_url          Store home URL.
	 * @param string $callback_url      REST callback URL.
	 * @param array  $platform_versions php, WordPress, woocommerce, plugin.
	 * @return array Installation including token and signing_secret.
	 * @throws Api_Error On any refusal.
	 */
	public function connect( $connect_code, $site_url, $callback_url, array $platform_versions ) {
		return $this->request(
			'POST',
			'/connect',
			array(
				'connect_code'      => $connect_code,
				'site_url'          => $site_url,
				'callback_url'      => $callback_url,
				'platform_versions' => $platform_versions,
			),
			false
		);
	}

	/**
	 * GET /installation.
	 *
	 * @return array
	 * @throws Api_Error On any refusal.
	 */
	public function get_installation() {
		return $this->request( 'GET', '/installation' );
	}

	/**
	 * PATCH /installation.
	 *
	 * @param array $fields callback_url and/or platform_versions.
	 * @return array
	 * @throws Api_Error On any refusal.
	 */
	public function update_installation( array $fields ) {
		return $this->request( 'PATCH', '/installation', $fields );
	}

	/**
	 * DELETE /installation: disconnect this store.
	 *
	 * @return array
	 * @throws Api_Error On any refusal.
	 */
	public function disconnect() {
		return $this->request( 'DELETE', '/installation' );
	}

	/**
	 * GET /methods.
	 *
	 * @return array List of array( 'method' => ..., 'label_ko' => ... ).
	 * @throws Api_Error On any refusal.
	 */
	public function get_methods() {
		$response = $this->request( 'GET', '/methods' );
		$methods  = array();
		$data     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		foreach ( $data as $row ) {
			if ( is_array( $row ) && isset( $row['method'] ) && is_string( $row['method'] ) ) {
				$methods[] = array(
					'method'   => $row['method'],
					'label_ko' => isset( $row['label_ko'] ) && is_string( $row['label_ko'] ) ? $row['label_ko'] : '',
				);
			}
		}
		return $methods;
	}

	/**
	 * POST /payments: one pay click.
	 *
	 * @param array $fields order_id, order_number, amount, currency, payment_method, return_url, buyer.
	 * @return array Payment including redirect_url.
	 * @throws Api_Error On any refusal; 409 refusals carry the payment.
	 */
	public function create_payment( array $fields ) {
		return $this->request( 'POST', '/payments', $fields );
	}

	/**
	 * GET /payments/:id.
	 *
	 * @param string $payment_id Payment id (ppay_...).
	 * @return array
	 * @throws Api_Error On any refusal.
	 */
	public function get_payment( $payment_id ) {
		return $this->request( 'GET', '/payments/' . self::path_id( $payment_id ) );
	}

	/**
	 * GET /payments?order_id=: every attempt for an order, newest first.
	 *
	 * @param int|string $order_id WooCommerce order id.
	 * @return array List of payments.
	 * @throws Api_Error On any refusal.
	 */
	public function list_payments( $order_id ) {
		$response = $this->request( 'GET', '/payments?order_id=' . rawurlencode( (string) $order_id ) );
		$list     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * POST /payments/:id/expire.
	 *
	 * @param string $payment_id Payment id.
	 * @return array The payment after expiry (it may come back paid).
	 * @throws Api_Error On any refusal.
	 */
	public function expire_payment( $payment_id ) {
		return $this->request( 'POST', '/payments/' . self::path_id( $payment_id ) . '/expire' );
	}

	/**
	 * True for a payment id we are willing to put in a URL path.
	 *
	 * @param mixed $payment_id Candidate.
	 * @return bool
	 */
	public static function valid_payment_id( $payment_id ) {
		return is_string( $payment_id ) && 1 === preg_match( '/^ppay_[A-Za-z0-9]{1,64}$/', $payment_id );
	}

	/**
	 * Validates a payment id for a path segment.
	 *
	 * @param mixed $payment_id Candidate.
	 * @return string
	 * @throws Api_Error When the id is malformed.
	 */
	private static function path_id( $payment_id ) {
		if ( ! self::valid_payment_id( $payment_id ) ) {
			throw new Api_Error( 0, 'invalid_payment_id', 'Malformed payment id.' );
		}
		return $payment_id;
	}

	/**
	 * Sends a request and maps the answer.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the base.
	 * @param array|null $body   JSON body.
	 * @param bool       $auth   Whether to send the store token.
	 * @return array
	 * @throws Api_Error On any non-2xx answer or network failure.
	 */
	public function request( $method, $path, $body = null, $auth = true ) {
		$operation = strtolower( $method ) . ' ' . preg_replace( '#/ppay_[A-Za-z0-9]+#', '/:id', strtok( $path, '?' ) );
		$headers   = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'OutlinePay-WooCommerce/' . ( defined( 'OUTLINEPAY_VERSION' ) ? OUTLINEPAY_VERSION : '0' ),
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $auth ) {
			$token = call_user_func( $this->token_provider );
			if ( ! is_string( $token ) || '' === $token ) {
				throw new Api_Error( 0, 'not_connected', 'This store is not connected to OutlinePay.' );
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$encoded = null;
		if ( null !== $body ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback for unit tests only.
		}

		try {
			$response = call_user_func( $this->transport, $method, $this->base . $path, $headers, $encoded, self::TIMEOUT );
		} catch ( Api_Error $error ) {
			Logger::warning( 'API network failure on ' . $operation, array( 'code' => $error->get_error_code() ) );
			throw $error;
		}

		$status  = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$raw     = isset( $response['body'] ) ? (string) $response['body'] : '';
		$decoded = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $decoded ) ) {
				Logger::warning( 'API answer unreadable on ' . $operation, array( 'http_status' => $status ) );
				throw new Api_Error( $status, 'invalid_response', 'The OutlinePay answer could not be read.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output; callers map codes to fixed notices.
			}
			return $decoded;
		}

		$envelope = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();
		$code     = isset( $envelope['code'] ) && is_string( $envelope['code'] ) ? $envelope['code'] : 'http_' . $status;
		$message  = isset( $envelope['message'] ) && is_string( $envelope['message'] ) ? $envelope['message'] : '';
		$param    = isset( $envelope['param'] ) && is_string( $envelope['param'] ) ? $envelope['param'] : null;
		$payment  = is_array( $decoded ) && isset( $decoded['payment'] ) && is_array( $decoded['payment'] ) ? $decoded['payment'] : null;

		Logger::warning(
			'API error on ' . $operation,
			array(
				'http_status' => $status,
				'code'        => $code,
				'payment_id'  => $payment && isset( $payment['id'] ) ? $payment['id'] : null,
			)
		);

		if ( 401 === $status && $auth && function_exists( 'do_action' ) ) {
			do_action( 'outlinepay_token_rejected' );
		}

		throw new Api_Error( $status, $code, $message, $param, $payment ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never output; callers map codes to fixed notices.
	}

	/**
	 * Default transport over the WordPress HTTP API.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $url     Full URL.
	 * @param array       $headers Headers.
	 * @param string|null $body    Encoded body.
	 * @param int         $timeout Seconds.
	 * @return array
	 * @throws Api_Error On a network failure.
	 */
	public static function wp_transport( $method, $url, array $headers, $body, $timeout ) {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => $headers['User-Agent'],
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new Api_Error( 0, 'network_error', 'OutlinePay could not be reached.' );
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
