<?php
/**
 * Tools > Site Health: one test, "OutlinePay connection".
 *
 * Runs the cheap local checks only and reads the last stored self-check. It
 * never calls OutlinePay: Site Health also runs from WP-Cron once a week.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The Site Health test.
 */
final class Site_Health {

	const TEST = 'outlinepay_connection';

	/**
	 * A stored result counts as current for this long: one daily run plus two hours.
	 */
	const FRESH = 93600;

	/**
	 * Hooks the test.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
	}

	/**
	 * Adds the direct test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'OutlinePay connection', 'outlinepay-for-woocommerce' ),
			'test'  => array( self::class, 'test' ),
		);
		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public static function test() {
		$now     = time();
		$checks  = Self_Check::local_checks( Self_Check::gather_facts(), $now );
		$verdict = self::evaluate( $checks, Self_Check::last(), $now );
		return self::present( $verdict );
	}

	/**
	 * Decides the status. Pure.
	 *
	 * critical: not connected, or the connection was turned off (token refused).
	 * recommended: a check failed now or in the last stored run, or no run in 26 hours.
	 * good: the local checks pass and the last stored run, under 26 hours old, passed.
	 *
	 * @param array      $checks Local checks.
	 * @param array|null $last   Last stored result.
	 * @param int        $now    Unix time.
	 * @return array array( 'status' => good|recommended|critical, 'reason' => string, 'failed' => checks ).
	 */
	public static function evaluate( array $checks, $last, $now ) {
		$by_code = array();
		foreach ( $checks as $check ) {
			$by_code[ $check['code'] ] = $check;
		}
		if ( isset( $by_code['connection_saved'] ) && ! $by_code['connection_saved']['ok'] ) {
			return self::verdict( 'critical', 'not_connected', array( $by_code['connection_saved'] ) );
		}
		if ( isset( $by_code['connection_status'] ) && ! $by_code['connection_status']['ok'] ) {
			$reason = 'processor' === $by_code['connection_status']['side'] ? 'token_refused' : 'not_connected';
			return self::verdict( 'critical', $reason, array( $by_code['connection_status'] ) );
		}

		$failed = array();
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$failed[ $check['code'] ] = $check;
			}
		}
		$fresh = is_array( $last ) && (int) $last['ran_at'] >= $now - self::FRESH;
		if ( $fresh ) {
			foreach ( $last['checks'] as $check ) {
				if ( empty( $check['ok'] ) && ! isset( $failed[ $check['code'] ] ) && ! isset( self::local_codes()[ $check['code'] ] ) ) {
					// Local checks are judged by this run; the stored run adds what only it can know.
					$failed[ $check['code'] ] = $check;
				}
			}
		}
		if ( ! empty( $failed ) ) {
			return self::verdict( 'recommended', 'failed', array_values( $failed ) );
		}
		if ( ! $fresh ) {
			return self::verdict( 'recommended', 'stale', array() );
		}
		return self::verdict( 'good', 'ok', array() );
	}

	/**
	 * Codes the local checks cover (a fresh run of them replaces the stored ones).
	 *
	 * @return array Code => true.
	 */
	private static function local_codes() {
		$codes = array_fill_keys( array_keys( Self_Check::LETTERS ), true );
		unset( $codes['api_reachable'] );
		return $codes;
	}

	/**
	 * Verdict shape.
	 *
	 * @param string $status Status.
	 * @param string $reason Reason key.
	 * @param array  $failed Failed checks.
	 * @return array
	 */
	private static function verdict( $status, $reason, array $failed ) {
		return array(
			'status' => $status,
			'reason' => $reason,
			'failed' => $failed,
		);
	}

	/**
	 * The Site Health result array.
	 *
	 * @param array $verdict From evaluate().
	 * @return array
	 */
	public static function present( array $verdict ) {
		switch ( $verdict['reason'] ) {
			case 'ok':
				$label = __( 'OutlinePay is connected and its last check passed', 'outlinepay-for-woocommerce' );
				$intro = __( 'The store is connected to OutlinePay and the last self-check found no problems.', 'outlinepay-for-woocommerce' );
				break;
			case 'not_connected':
				$label = __( 'OutlinePay is not connected', 'outlinepay-for-woocommerce' );
				$intro = __( 'OutlinePay payment methods do not show at checkout until the store is connected with a code from your payment provider.', 'outlinepay-for-woocommerce' );
				break;
			case 'token_refused':
				$label = __( 'OutlinePay turned off this store\'s connection', 'outlinepay-for-woocommerce' );
				$intro = __( 'OutlinePay payments are off. Ask your payment provider for a new connect code, then disconnect and connect again.', 'outlinepay-for-woocommerce' );
				break;
			case 'stale':
				$label = __( 'OutlinePay has not checked this store in the last day', 'outlinepay-for-woocommerce' );
				$intro = __( 'Run the check on the OutlinePay page.', 'outlinepay-for-woocommerce' );
				break;
			default:
				$label = __( 'OutlinePay found a problem', 'outlinepay-for-woocommerce' );
				$intro = __( 'Payments may not work as expected until this is fixed.', 'outlinepay-for-woocommerce' );
		}

		$description = '<p>' . esc_html( $intro ) . '</p>';
		if ( ! empty( $verdict['failed'] ) ) {
			$description .= '<ul>';
			foreach ( $verdict['failed'] as $check ) {
				$description .= '<li>' . esc_html( Self_Check::sentence( $check ) ) . ' ' . esc_html(
					sprintf(
						/* translators: %s: who acts next, such as "You" or "Your payment provider". */
						__( '(Who acts next: %s)', 'outlinepay-for-woocommerce' ),
						Self_Check::who( $check['side'] )
					)
				) . '</li>';
			}
			$description .= '</ul>';
		}

		return array(
			'label'       => $label,
			'status'      => $verdict['status'],
			'badge'       => array(
				'label' => __( 'Payments', 'outlinepay-for-woocommerce' ),
				'color' => 'good' === $verdict['status'] ? 'blue' : ( 'critical' === $verdict['status'] ? 'red' : 'orange' ),
			),
			'description' => $description,
			'actions'     => '<p><a href="' . esc_url( Admin_Page::url() ) . '">' . esc_html__( 'Open the OutlinePay page', 'outlinepay-for-woocommerce' ) . '</a></p>',
			'test'        => self::TEST,
		);
	}
}
