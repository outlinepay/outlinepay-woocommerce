<?php
/**
 * Plugin options: the installation summary and the cached method list.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Option access in one place.
 */
final class Settings {

	const OPTION_INSTALLATION = 'outlinepay_installation';
	const OPTION_METHODS      = 'outlinepay_methods';
	const OPTION_VERSION      = 'outlinepay_reported_version';
	const OPTION_CANCEL       = 'outlinepay_shopper_cancel';

	/**
	 * Every method key the server may offer, in checkout order.
	 */
	const METHODS = array( 'card', 'foreign_card_krw', 'bank_transfer', 'virtual_account', 'kakaopay', 'naverpay', 'carrier_billing' );

	/**
	 * Installation fields kept locally. Never credentials.
	 */
	const INSTALLATION_FIELDS = array( 'id', 'mode', 'status', 'provider', 'permissions', 'refunds_enabled', 'refund_daily_cap', 'connected_at', 'token_last4' );

	/**
	 * The permission a store token needs to refund.
	 */
	const REFUND_PERMISSION = 'refunds.create';

	/**
	 * Methods the payment provider can refund through its API. Only used to
	 * decide whether to offer the Refund button and the shopper Cancel. OutlinePay
	 * decides every refund and refuses what the provider cannot do.
	 */
	const REFUNDABLE_METHODS = array( 'card', 'foreign_card_krw', 'bank_transfer', 'kakaopay', 'naverpay' );

	/**
	 * Order statuses a shopper may cancel from, when the merchant allows it.
	 */
	const CANCEL_STATUSES = array( 'processing', 'on-hold' );

	/**
	 * The oldest WooCommerce this plugin supports. The plugin header's
	 * "WC requires at least" says the same, and a test holds them equal.
	 */
	const MIN_WC = '9.9';

	/**
	 * From this WooCommerce version, Settings > Payments hides a gateway with
	 * no admin title and no admin description when the same plugin has a
	 * gateway with them.
	 */
	const WC_HIDES_SHELL_GATEWAYS = '9.9';

	/**
	 * The installation summary, or an empty array.
	 *
	 * @return array
	 */
	public static function installation() {
		$value = get_option( self::OPTION_INSTALLATION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Saves the non-secret installation fields from an API answer.
	 *
	 * @param array $installation Installation JSON.
	 * @return void
	 */
	public static function save_installation( array $installation ) {
		$keep = array();
		foreach ( self::INSTALLATION_FIELDS as $field ) {
			if ( array_key_exists( $field, $installation ) ) {
				$keep[ $field ] = $installation[ $field ];
			}
		}
		update_option( self::OPTION_INSTALLATION, $keep, true );
	}

	/**
	 * Marks the local installation revoked after the server refused the token.
	 *
	 * @return void
	 */
	public static function mark_revoked() {
		$installation = self::installation();
		if ( empty( $installation ) ) {
			return;
		}
		$installation['status'] = 'revoked';
		update_option( self::OPTION_INSTALLATION, $installation, true );
	}

	/**
	 * Forgets everything about the connection, credentials included.
	 *
	 * @return void
	 */
	public static function clear_connection() {
		Credentials::clear();
		delete_option( self::OPTION_INSTALLATION );
		delete_option( self::OPTION_METHODS );
		delete_option( self::OPTION_VERSION );
		delete_option( Scheduler::OPTION_CURSOR );
		delete_option( Scheduler::OPTION_FAILURES );
		delete_option( Site_Identity::OPTION );
		delete_option( Self_Check::OPTION_LAST );
		delete_option( Self_Check::OPTION_DAILY );
		delete_option( Self_Check::OPTION_EMAILED_AT );
	}

	/**
	 * True when this store holds working credentials for a connected
	 * installation, at the address it was connected at. A copy of the store
	 * (see Site_Identity) is never connected: it takes no payments and calls
	 * nothing.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$installation = self::installation();
		return isset( $installation['status'] ) && 'connected' === $installation['status'] && Credentials::present() && ! Site_Identity::is_copy();
	}

	/**
	 * True when the installation runs in test mode.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		$installation = self::installation();
		return isset( $installation['mode'] ) && 'test' === $installation['mode'];
	}

	/**
	 * True when this store's token may refund (the provider turned refunds on).
	 *
	 * @return bool
	 */
	public static function refunds_permitted() {
		$installation = self::installation();
		return isset( $installation['permissions'] ) && is_array( $installation['permissions'] ) && in_array( self::REFUND_PERMISSION, $installation['permissions'], true );
	}

	/**
	 * True when payments made with this method can be refunded from the store.
	 *
	 * @param string $method Method key.
	 * @return bool
	 */
	public static function refundable( $method ) {
		return in_array( $method, self::REFUNDABLE_METHODS, true ) && self::refunds_permitted();
	}

	/**
	 * The shopper cancel setting: enabled and the statuses it applies to.
	 *
	 * @return array array( 'enabled' => bool, 'statuses' => string[] ).
	 */
	public static function shopper_cancel() {
		$value    = get_option( self::OPTION_CANCEL, array() );
		$value    = is_array( $value ) ? $value : array();
		$statuses = isset( $value['statuses'] ) && is_array( $value['statuses'] ) ? array_values( array_intersect( self::CANCEL_STATUSES, $value['statuses'] ) ) : array( 'processing' );
		return array(
			'enabled'  => isset( $value['enabled'] ) && 'yes' === $value['enabled'],
			'statuses' => $statuses,
		);
	}

	/**
	 * Saves the shopper cancel setting. Unknown statuses are dropped.
	 *
	 * @param bool  $enabled  On or off.
	 * @param array $statuses Statuses.
	 * @return void
	 */
	public static function save_shopper_cancel( $enabled, array $statuses ) {
		update_option(
			self::OPTION_CANCEL,
			array(
				'enabled'  => $enabled ? 'yes' : 'no',
				'statuses' => array_values( array_intersect( self::CANCEL_STATUSES, $statuses ) ),
			),
			true
		);
	}

	/**
	 * Cached method list from the server.
	 *
	 * @return array List of array( 'method' => ..., 'label_ko' => ... ).
	 */
	public static function methods() {
		$value = get_option( self::OPTION_METHODS, array() );
		return is_array( $value ) && isset( $value['methods'] ) && is_array( $value['methods'] ) ? $value['methods'] : array();
	}

	/**
	 * Unix time the method list was last fetched, 0 if never.
	 *
	 * @return int
	 */
	public static function methods_fetched_at() {
		$value = get_option( self::OPTION_METHODS, array() );
		return is_array( $value ) && isset( $value['fetched_at'] ) ? (int) $value['fetched_at'] : 0;
	}

	/**
	 * Saves the method list, keeping only keys this plugin knows.
	 *
	 * @param array $methods From Api_Client::get_methods().
	 * @return void
	 */
	public static function save_methods( array $methods ) {
		$known = array();
		foreach ( $methods as $row ) {
			if ( isset( $row['method'] ) && in_array( $row['method'], self::METHODS, true ) ) {
				$known[] = array(
					'method'   => $row['method'],
					'label_ko' => isset( $row['label_ko'] ) ? (string) $row['label_ko'] : '',
				);
			}
		}
		update_option(
			self::OPTION_METHODS,
			array(
				'fetched_at' => time(),
				'methods'    => $known,
			),
			true
		);
	}

	/**
	 * True when the server has the method enabled for this store.
	 *
	 * @param string $method Method key.
	 * @return bool
	 */
	public static function method_enabled_by_server( $method ) {
		foreach ( self::methods() as $row ) {
			if ( $row['method'] === $method ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Method name in the site language, or the method key when unknown.
	 * Called at runtime so the translation is loaded by then.
	 *
	 * @param string $method Method key.
	 * @return string
	 */
	public static function method_name( $method ) {
		switch ( $method ) {
			case 'card':
				return __( 'Credit or debit card', 'outlinepay-for-woocommerce' );
			case 'foreign_card_krw':
				return __( 'International card (KRW)', 'outlinepay-for-woocommerce' );
			case 'bank_transfer':
				return __( 'Bank transfer', 'outlinepay-for-woocommerce' );
			case 'virtual_account':
				return __( 'Virtual account', 'outlinepay-for-woocommerce' );
			case 'kakaopay':
				return __( 'KakaoPay', 'outlinepay-for-woocommerce' );
			case 'naverpay':
				return __( 'Naver Pay', 'outlinepay-for-woocommerce' );
			case 'carrier_billing':
				return __( 'Mobile carrier billing', 'outlinepay-for-woocommerce' );
			default:
				return (string) $method;
		}
	}

	/**
	 * Display name of a provider key, e.g. CookiePay.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function provider_name( $provider ) {
		$provider = (string) $provider;
		return 'cookiepay' === $provider ? __( 'CookiePay', 'outlinepay-for-woocommerce' ) : ucfirst( $provider );
	}

	/**
	 * The line shown under each method at checkout, in the site language.
	 * Empty when the store has no provider yet.
	 *
	 * @return string
	 */
	public static function method_description() {
		$installation = self::installation();
		$provider     = isset( $installation['provider'] ) ? self::provider_name( $installation['provider'] ) : '';
		if ( '' === $provider ) {
			return '';
		}
		/* translators: %s: payment provider name, such as CookiePay. */
		return sprintf( __( 'Paid securely through %s.', 'outlinepay-for-woocommerce' ), $provider );
	}

	/**
	 * True when the running WooCommerce shows one OutlinePay row on Settings >
	 * Payments: the card gateway carries the admin title, the others carry
	 * none and WooCommerce hides them.
	 *
	 * @param string|null $wc_version WooCommerce version, null for the running one.
	 * @return bool
	 */
	public static function collapse_payments_tab( $wc_version = null ) {
		if ( null === $wc_version ) {
			$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		}
		return '' !== (string) $wc_version && version_compare( (string) $wc_version, self::WC_HIDES_SHELL_GATEWAYS, '>=' );
	}

	/**
	 * Gateway id for a method key.
	 *
	 * @param string $method Method key.
	 * @return string
	 */
	public static function gateway_id( $method ) {
		return 'outlinepay_' . $method;
	}

	/**
	 * All gateway ids.
	 *
	 * @return string[]
	 */
	public static function gateway_ids() {
		return array_map( array( self::class, 'gateway_id' ), self::METHODS );
	}

	/**
	 * Method key for a gateway id, or null when it is not ours.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return string|null
	 */
	public static function method_for_gateway( $gateway_id ) {
		$gateway_id = (string) $gateway_id;
		if ( 0 !== strpos( $gateway_id, 'outlinepay_' ) ) {
			return null;
		}
		$method = substr( $gateway_id, strlen( 'outlinepay_' ) );
		return in_array( $method, self::METHODS, true ) ? $method : null;
	}

	/**
	 * Whether the merchant shows the method at checkout (the gateway's enabled setting).
	 *
	 * @param string $method Method key.
	 * @return bool
	 */
	public static function shown( $method ) {
		$settings = get_option( 'woocommerce_' . self::gateway_id( $method ) . '_settings', array() );
		return ! is_array( $settings ) || ! isset( $settings['enabled'] ) || 'no' !== $settings['enabled'];
	}

	/**
	 * Shows or hides a method at checkout by writing the gateway's enabled setting.
	 *
	 * @param string $method Method key.
	 * @param bool   $shown  Show it.
	 * @return void
	 */
	public static function set_shown( $method, $shown ) {
		$option   = 'woocommerce_' . self::gateway_id( $method ) . '_settings';
		$settings = get_option( $option, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['enabled'] = $shown ? 'yes' : 'no';
		update_option( $option, $settings );
	}

	/**
	 * Host versions as the server wants them: short strings under known keys.
	 *
	 * @return array
	 */
	public static function platform_versions() {
		$versions = array(
			'php'         => PHP_VERSION,
			'wordpress'   => (string) get_bloginfo( 'version' ),
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'plugin'      => OUTLINEPAY_VERSION,
		);
		foreach ( $versions as $key => $value ) {
			$versions[ $key ] = substr( (string) $value, 0, 50 );
		}
		return $versions;
	}

	/**
	 * Callback URL sent on connect.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( 'outlinepay/v1/callback' );
	}
}
