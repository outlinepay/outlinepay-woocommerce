<?php
/**
 * The email when a daily self-check starts failing.
 *
 * On by default. The merchant can turn it off. When on, the site admin
 * address gets one plain-text email, Korean first and English below, the
 * first time a daily run fails after a daily run that passed. Never more than one email in 24 hours. Sent with
 * wp_mail from the store itself: nothing about it goes to OutlinePay.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Decides and sends the email.
 */
final class Self_Check_Mail {

	const MIN_GAP = 86400;

	/**
	 * After a daily run: remember whether it passed, and email when it is the
	 * first failure after a pass.
	 *
	 * @param array $result Result from Self_Check::run().
	 * @param int   $now    Unix time.
	 * @return bool True when an email went out.
	 */
	public static function after_daily( array $result, $now ) {
		$failed   = (int) $result['failed_count'] > 0;
		$previous = get_option( Self_Check::OPTION_DAILY, array() );
		$prev_ok  = is_array( $previous ) && array_key_exists( 'ok', $previous ) ? (bool) $previous['ok'] : null;
		update_option(
			Self_Check::OPTION_DAILY,
			array(
				'ok'     => ! $failed,
				'ran_at' => (int) $now,
			),
			false
		);

		$last_sent = (int) get_option( Self_Check::OPTION_EMAILED_AT, 0 );
		if ( ! self::should_send( Self_Check::email_enabled(), $failed, $prev_ok, $last_sent, $now ) ) {
			return false;
		}

		$mail = self::compose( $result, self::lines( $result, 'ko_KR' ), self::lines( $result, 'en_US' ), Admin_Page::url(), (string) get_bloginfo( 'name' ) );
		$sent = wp_mail( (string) get_option( 'admin_email' ), $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( $sent ) {
			update_option( Self_Check::OPTION_EMAILED_AT, (int) $now, false );
			Logger::info( 'Self-check email sent', array( 'code' => $result['code'] ) );
		} else {
			Logger::warning( 'Self-check email not sent', array( 'code' => $result['code'] ) );
		}
		return (bool) $sent;
	}

	/**
	 * Whether to email. Pure.
	 *
	 * The previous daily run must have passed: a store that keeps failing
	 * gets one email, not one a day. With no previous daily run (the first
	 * one after connecting or after this option was turned on), a failure
	 * counts as new.
	 *
	 * @param bool      $enabled   The merchant turned the email on.
	 * @param bool      $failed    This daily run failed.
	 * @param bool|null $prev_ok   Whether the previous daily run passed, null when there was none.
	 * @param int       $last_sent Unix time of the last email, 0 if never.
	 * @param int       $now       Unix time.
	 * @return bool
	 */
	public static function should_send( $enabled, $failed, $prev_ok, $last_sent, $now ) {
		if ( ! $enabled || ! $failed || false === $prev_ok ) {
			return false;
		}
		return $last_sent <= 0 || $now - $last_sent >= self::MIN_GAP;
	}

	/**
	 * Subject and body. Pure.
	 *
	 * @param array  $result   Result.
	 * @param array  $ko       Lines from lines() in Korean.
	 * @param array  $en       Lines from lines() in English.
	 * @param string $page_url The OutlinePay page.
	 * @param string $site     Site name.
	 * @return array array( 'subject' => string, 'body' => string ).
	 */
	public static function compose( array $result, array $ko, array $en, $page_url, $site ) {
		$code    = (string) $result['code'];
		$subject = '[' . $site . '] OutlinePay 일일 점검 실패 / OutlinePay daily check failed';

		$body   = array();
		$body[] = 'OutlinePay 일일 점검에서 문제가 발견되었습니다.';
		$body[] = '';
		foreach ( $ko as $line ) {
			$body[] = '- ' . $line['sentence'];
			$body[] = '  조치할 곳: ' . $line['who'];
		}
		$body[] = '';
		$body[] = '진단 코드: ' . $code;
		$body[] = '도움이 필요하면 이 코드를 결제대행사에 보내 주세요.';
		$body[] = 'OutlinePay 페이지: ' . $page_url;
		$body[] = '';
		$body[] = '----';
		$body[] = '';
		$body[] = 'The OutlinePay daily check found a problem.';
		$body[] = '';
		foreach ( $en as $line ) {
			$body[] = '- ' . $line['sentence'];
			$body[] = '  Who acts next: ' . $line['who'];
		}
		$body[] = '';
		$body[] = 'Diagnostic code: ' . $code;
		$body[] = 'Send this code to your payment provider if you need help.';
		$body[] = 'OutlinePay page: ' . $page_url;
		$body[] = '';
		$body[] = 'You get this email because "Email me when a daily check fails" is on in WooCommerce > OutlinePay.';

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $body ),
		);
	}

	/**
	 * The failed checks as sentence and who-acts-next lines in a locale.
	 *
	 * The language comes from this plugin's own files whatever the site and
	 * user language: Korean from the bundled ko_KR translation, English from
	 * the source strings. Other plugins' strings are not touched.
	 *
	 * @param array  $result Result.
	 * @param string $locale ko_KR or en_US.
	 * @return array List of array( 'sentence' => string, 'who' => string ).
	 */
	public static function lines( array $result, $locale ) {
		$translations = null;
		if ( 'ko_KR' === $locale && class_exists( '\MO' ) ) {
			$translations = new \MO();
			if ( ! $translations->import_from_file( dirname( OUTLINEPAY_PLUGIN_FILE ) . '/languages/outlinepay-for-woocommerce-ko_KR.mo' ) ) {
				$translations = null;
			}
		}
		$filter = static function ( $translation, $text, $domain ) use ( $translations ) {
			if ( 'outlinepay-for-woocommerce' !== $domain ) {
				return $translation;
			}
			return $translations ? $translations->translate( $text ) : $text;
		};
		add_filter( 'gettext', $filter, 999, 3 );

		$lines = array();
		foreach ( $result['checks'] as $check ) {
			if ( empty( $check['ok'] ) ) {
				$lines[] = array(
					'sentence' => Self_Check::sentence( $check ),
					'who'      => Self_Check::who( $check['side'] ),
				);
			}
		}

		remove_filter( 'gettext', $filter, 999 );
		return $lines;
	}
}
