<?php
/**
 * The self-check: what this store can check about itself, sent to OutlinePay,
 * which adds what it checks from its side and answers the whole run.
 *
 * No check reads shopper or order data. A check result is a code, pass or
 * fail, the side that acts next when it fails (shopper, store, processor,
 * outlinepay, unknown) and a few short detail strings.
 *
 * When the run reaches OutlinePay, its id (chk_...) is the diagnostic code.
 * When it does not (a copy of the store, not connected, OutlinePay
 * unreachable), the code is made here: OP-OFFLINE- followed by one letter per
 * failed check, in this fixed order. A letter never changes meaning.
 *
 *   A  connection_saved   the connection is saved and the credentials decrypt
 *   B  connection_status  the installation is connected (not revoked)
 *   C  api_reachable      OutlinePay answered within 5 seconds
 *   D  scheduler_jobs     reconcile and method refresh are scheduled, none over an hour late
 *   E  wp_cron            WP-Cron is on, or some scheduled action ran in the last 2 hours
 *   F  methods_fresh      methods fetched in the last 2 hours, at least one shown at checkout
 *   G  store_currency     the store currency is KRW
 *   H  not_a_copy         this site is the address the store was connected at
 *   I  versions           PHP, WordPress, WooCommerce at or above the plugin's minimums, HPOS declared
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks, talks to OutlinePay, stores the last result.
 */
final class Self_Check {

	const OPTION_LAST       = 'outlinepay_self_check_last';
	const OPTION_DAILY      = 'outlinepay_self_check_daily';
	const OPTION_EMAIL      = 'outlinepay_self_check_email';
	const OPTION_EMAILED_AT = 'outlinepay_self_check_emailed_at';

	const TRIGGER_PAGE  = 'page';
	const TRIGGER_DAILY = 'daily';

	const OFFLINE_PREFIX = 'OP-OFFLINE-';

	/**
	 * Seconds to wait for GET /installation.
	 */
	const API_TIMEOUT = 5;

	/**
	 * Seconds to wait for POST /self_checks. The server pings this store's
	 * callback (up to 5 seconds) before it answers.
	 */
	const POST_TIMEOUT = 15;

	const JOB_LATE      = 3600;
	const CRON_QUIET    = 7200;
	const METHODS_STALE = 7200;

	/**
	 * Local checks in order, with their offline letter. See the file comment.
	 */
	const LETTERS = array(
		'connection_saved'  => 'A',
		'connection_status' => 'B',
		'api_reachable'     => 'C',
		'scheduler_jobs'    => 'D',
		'wp_cron'           => 'E',
		'methods_fresh'     => 'F',
		'store_currency'    => 'G',
		'not_a_copy'        => 'H',
		'versions'          => 'I',
	);

	/**
	 * Sides, in the order the server ranks them: the first failing one acts first.
	 */
	const SIDE_ORDER = array( 'outlinepay', 'processor', 'store', 'shopper', 'unknown' );

	/**
	 * Returns the facts local_checks() reads (see gather_facts()).
	 *
	 * @var callable
	 */
	private $facts_provider;

	/**
	 * Client for OutlinePay.
	 *
	 * @var Api_Client|null
	 */
	private $client;

	/**
	 * Returns the unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor. All arguments are test seams with production defaults.
	 *
	 * @param callable|null   $facts_provider Facts provider.
	 * @param Api_Client|null $client         Client.
	 * @param callable|null   $clock          Clock.
	 */
	public function __construct( $facts_provider = null, $client = null, $clock = null ) {
		$this->facts_provider = $facts_provider ? $facts_provider : array( self::class, 'gather_facts' );
		$this->client         = $client;
		$this->clock          = $clock ? $clock : 'time';
	}

	/**
	 * Runs every check and stores the result, except when OutlinePay says a
	 * run happened less than a minute ago: then the stored result stays and
	 * comes back with rate_limited set.
	 *
	 * @param string $trigger page or daily.
	 * @return array array( 'result' => result, 'rate_limited' => bool ).
	 */
	public function run( $trigger ) {
		$trigger = self::TRIGGER_DAILY === $trigger ? self::TRIGGER_DAILY : self::TRIGGER_PAGE;
		$facts   = call_user_func( $this->facts_provider );
		$now     = (int) call_user_func( $this->clock );
		$checks  = self::local_checks( $facts, $now );

		// A copy holds the live store's credentials: it never calls OutlinePay.
		if ( ! $facts['has_installation'] || ! $facts['credentials_readable'] || $facts['is_copy'] ) {
			return $this->finish( self::offline( $checks, $trigger, $now ), false );
		}

		$client = $this->client ? $this->client : new Api_Client();
		$probe  = self::probe( $client->with_timeout( self::API_TIMEOUT ) );
		$checks = self::insert_after( $checks, 'connection_status', $probe['check'] );
		if ( null !== $probe['status'] ) {
			// OutlinePay's answer is fresher than the saved status.
			$facts['status'] = $probe['status'];
			$checks          = self::replace( $checks, self::connection_status( $facts ) );
		}
		if ( ! $probe['check']['ok'] || 'connected' !== $facts['status'] ) {
			return $this->finish( self::offline( $checks, $trigger, $now ), false );
		}

		try {
			$run = $client->with_timeout( self::POST_TIMEOUT )->create_self_check( $trigger, self::wire( $checks ) );
		} catch ( Api_Error $error ) {
			if ( 429 === $error->get_http_status() ) {
				$last = self::last();
				if ( $last ) {
					return array(
						'result'       => $last,
						'rate_limited' => true,
					);
				}
			}
			Logger::warning( 'Self-check not accepted by OutlinePay', array( 'code' => $error->get_error_code() ) );
			$checks = self::replace( $checks, self::api_failure( $error, 'self_check' ) );
			return $this->finish( self::offline( $checks, $trigger, $now ), 429 === $error->get_http_status() );
		}

		$result = self::from_server( $run, $trigger, $now );
		if ( null === $result ) {
			Logger::warning( 'Self-check answer unreadable' );
			$checks = self::replace( $checks, self::api_failure( new Api_Error( 201, 'invalid_response' ), 'self_check' ) );
			$result = self::offline( $checks, $trigger, $now );
		}
		return $this->finish( $result, false );
	}

	/**
	 * Stores a result and wraps it.
	 *
	 * @param array $result       Result.
	 * @param bool  $rate_limited Whether OutlinePay refused for the rate limit.
	 * @return array
	 */
	private function finish( array $result, $rate_limited ) {
		self::store( $result );
		Logger::info(
			'Self-check finished',
			array(
				'code'   => $result['code'],
				'count'  => $result['failed_count'],
				'status' => $result['trigger'],
			)
		);
		return array(
			'result'       => $result,
			'rate_limited' => (bool) $rate_limited,
		);
	}

	/**
	 * The daily run, from Action Scheduler. Posts even when everything passes.
	 * Then the opt-in email when a check started failing.
	 *
	 * @return void
	 */
	public static function run_daily() {
		if ( empty( Settings::installation() ) || ! Credentials::present() || Site_Identity::is_copy() ) {
			return;
		}
		$outcome = ( new self() )->run( self::TRIGGER_DAILY );
		if ( $outcome['rate_limited'] ) {
			return;
		}
		Self_Check_Mail::after_daily( $outcome['result'], time() );
	}

	/**
	 * GET /installation, timed. A network failure is the store's to fix, a
	 * refused token the provider's (a new connect code), anything else ours.
	 *
	 * @param Api_Client $client Client with a short timeout.
	 * @return array array( 'check' => check, 'status' => installation status or null ).
	 */
	public static function probe( Api_Client $client ) {
		$started = microtime( true );
		try {
			$installation = $client->get_installation();
		} catch ( Api_Error $error ) {
			$check                         = self::api_failure( $error, 'installation' );
			$check['detail']['elapsed_ms'] = (string) (int) round( ( microtime( true ) - $started ) * 1000 );
			return array(
				'check'  => $check,
				'status' => 401 === $error->get_http_status() ? 'revoked' : null,
			);
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		return array(
			'check'  => self::check(
				'api_reachable',
				true,
				'store',
				array(
					'outcome'    => 'ok',
					'elapsed_ms' => (string) $elapsed,
				)
			),
			'status' => isset( $installation['status'] ) && is_string( $installation['status'] ) ? $installation['status'] : null,
		);
	}

	/**
	 * The failed api_reachable check for an error from OutlinePay.
	 *
	 * @param Api_Error $error Error.
	 * @param string    $stage installation or self_check.
	 * @return array
	 */
	public static function api_failure( Api_Error $error, $stage ) {
		$status = $error->get_http_status();
		if ( 0 === $status ) {
			$side    = 'store';
			$outcome = 'network_error';
		} elseif ( 401 === $status ) {
			$side    = 'processor';
			$outcome = 'token_refused';
		} elseif ( 429 === $status ) {
			$side    = 'outlinepay';
			$outcome = 'rate_limited';
		} else {
			$side    = 'outlinepay';
			$outcome = $status >= 500 ? 'server_error' : 'refused';
		}
		return self::check(
			'api_reachable',
			false,
			$side,
			array(
				'outcome'     => $outcome,
				'http_status' => (string) $status,
				'stage'       => (string) $stage,
			)
		);
	}

	/**
	 * Every local check, in LETTERS order, without api_reachable (it needs
	 * the network). Pure.
	 *
	 * @param array $facts From gather_facts().
	 * @param int   $now   Unix time.
	 * @return array List of checks.
	 */
	public static function local_checks( array $facts, $now ) {
		return array(
			self::connection_saved( $facts ),
			self::connection_status( $facts ),
			self::scheduler_jobs( $facts, $now ),
			self::wp_cron( $facts ),
			self::methods_fresh( $facts, $now ),
			self::store_currency( $facts ),
			self::not_a_copy( $facts ),
			self::versions( $facts ),
		);
	}

	/**
	 * Connection saved and readable.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function connection_saved( array $facts ) {
		if ( $facts['credentials_readable'] ) {
			$credentials = 'readable';
		} else {
			$credentials = $facts['credentials_stored'] ? 'unreadable' : 'missing';
		}
		return self::check(
			'connection_saved',
			$facts['has_installation'] && $facts['credentials_readable'],
			'store',
			array(
				'installation' => $facts['has_installation'] ? 'saved' : 'missing',
				'credentials'  => $credentials,
			)
		);
	}

	/**
	 * Installation connected. Revoked means the provider or OutlinePay turned
	 * it off: only a new connect code from the provider fixes it.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function connection_status( array $facts ) {
		$status = '' !== (string) $facts['status'] ? (string) $facts['status'] : 'none';
		return self::check(
			'connection_status',
			'connected' === $status,
			'revoked' === $status ? 'processor' : 'store',
			array( 'status' => $status )
		);
	}

	/**
	 * Reconcile and method refresh scheduled, none more than an hour past due.
	 *
	 * @param array $facts Facts.
	 * @param int   $now   Unix time.
	 * @return array
	 */
	public static function scheduler_jobs( array $facts, $now ) {
		if ( ! $facts['as_available'] ) {
			return self::check( 'scheduler_jobs', false, 'store', array( 'action_scheduler' => 'missing' ) );
		}
		$ok     = true;
		$detail = array();
		foreach ( array(
			'reconcile' => Scheduler::HOOK_RECONCILE,
			'methods'   => Scheduler::HOOK_METHODS,
		) as $key => $hook ) {
			$next = isset( $facts['jobs'][ $hook ] ) ? $facts['jobs'][ $hook ] : false;
			if ( true === $next ) {
				$state = 'running';
			} elseif ( false === $next || ! is_int( $next ) ) {
				$state = 'missing';
				$ok    = false;
			} elseif ( $next < $now - self::JOB_LATE ) {
				$state = 'late';
				$ok    = false;
			} else {
				$state = 'scheduled';
			}
			$detail[ $key ] = $state;
		}
		return self::check( 'scheduler_jobs', $ok, 'store', $detail );
	}

	/**
	 * Fails only when WP-Cron is turned off and nothing on Action Scheduler
	 * ran for two hours: then no server cron job replaces it.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function wp_cron( array $facts ) {
		return self::check(
			'wp_cron',
			! $facts['cron_disabled'] || $facts['as_ran_recently'],
			'store',
			array(
				'disable_wp_cron' => $facts['cron_disabled'] ? 'yes' : 'no',
				'recent_action'   => $facts['as_ran_recently'] ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Methods fetched in the last two hours and at least one shown at checkout.
	 *
	 * @param array $facts Facts.
	 * @param int   $now   Unix time.
	 * @return array
	 */
	public static function methods_fresh( array $facts, $now ) {
		$fetched = (int) $facts['methods_fetched_at'];
		$fresh   = $fetched > 0 && $fetched >= $now - self::METHODS_STALE;
		$shown   = (int) $facts['methods_shown'];
		if ( ! $fresh ) {
			$problem = 'stale';
		} else {
			$problem = $shown < 1 ? 'none_shown' : 'none';
		}
		return self::check(
			'methods_fresh',
			$fresh && $shown >= 1,
			'store',
			array(
				'problem'     => $problem,
				'fetched_min' => $fetched > 0 ? (string) (int) floor( max( 0, $now - $fetched ) / 60 ) : 'never',
				'enabled'     => (string) (int) $facts['methods_enabled'],
				'shown'       => (string) $shown,
			)
		);
	}

	/**
	 * Store currency KRW.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function store_currency( array $facts ) {
		$currency = substr( preg_replace( '/[^A-Z]/', '', strtoupper( (string) $facts['currency'] ) ), 0, 10 );
		return self::check( 'store_currency', 'KRW' === $currency, 'store', array( 'currency' => $currency ) );
	}

	/**
	 * Not a copy of the store.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function not_a_copy( array $facts ) {
		return self::check( 'not_a_copy', ! $facts['is_copy'], 'store', array() );
	}

	/**
	 * PHP, WordPress and WooCommerce at or above the plugin's own minimums,
	 * and HPOS compatibility declared.
	 *
	 * @param array $facts Facts.
	 * @return array
	 */
	public static function versions( array $facts ) {
		$needs = array();
		if ( ! self::at_least( $facts['php'], $facts['min_php'] ) ) {
			$needs[] = 'php';
		}
		if ( ! self::at_least( $facts['wp'], $facts['min_wp'] ) ) {
			$needs[] = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- a lowercase key, not prose.
		}
		if ( ! self::at_least( $facts['wc'], $facts['min_wc'] ) ) {
			$needs[] = 'woocommerce';
		}
		if ( 'declared' !== $facts['hpos'] ) {
			$needs[] = 'hpos';
		}
		return self::check(
			'versions',
			empty( $needs ),
			'store',
			array(
				'php'         => (string) $facts['php'],
				'wordpress'   => (string) $facts['wp'],
				'woocommerce' => '' !== (string) $facts['wc'] ? (string) $facts['wc'] : 'missing',
				'hpos'        => (string) $facts['hpos'],
				'needs'       => implode( ',', $needs ),
			)
		);
	}

	/**
	 * True when $version is at least $minimum. An empty version never is; an
	 * empty minimum always is.
	 *
	 * @param string $version Version.
	 * @param string $minimum Minimum.
	 * @return bool
	 */
	private static function at_least( $version, $minimum ) {
		if ( '' === (string) $minimum ) {
			return true;
		}
		return '' !== (string) $version && version_compare( (string) $version, (string) $minimum, '>=' );
	}

	/**
	 * Reads what the local checks need. Cheap: options and two small Action
	 * Scheduler queries, no network.
	 *
	 * @return array
	 */
	public static function gather_facts() {
		$installation = Settings::installation();
		$as_available = function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_get_scheduled_actions' );
		$jobs         = array();
		$ran_recently = false;
		if ( $as_available ) {
			foreach ( array( Scheduler::HOOK_RECONCILE, Scheduler::HOOK_METHODS ) as $hook ) {
				$jobs[ $hook ] = as_next_scheduled_action( $hook, array(), Scheduler::GROUP );
			}
			$recent       = as_get_scheduled_actions(
				array(
					'status'           => 'complete',
					'modified'         => time() - self::CRON_QUIET,
					'modified_compare' => '>=',
					'per_page'         => 1,
					'orderby'          => 'none',
				),
				'ids'
			);
			$ran_recently = ! empty( $recent );
		}

		$shown   = 0;
		$enabled = 0;
		foreach ( Settings::methods() as $row ) {
			++$enabled;
			if ( Settings::shown( $row['method'] ) ) {
				++$shown;
			}
		}

		$headers = get_file_data(
			OUTLINEPAY_PLUGIN_FILE,
			array(
				'php' => 'Requires PHP',
				'wp'  => 'Requires at least',
				'wc'  => 'WC requires at least',
			)
		);

		return array(
			'has_installation'     => ! empty( $installation ),
			'credentials_stored'   => '' !== (string) get_option( Credentials::OPTION, '' ),
			'credentials_readable' => Credentials::present(),
			'status'               => isset( $installation['status'] ) ? (string) $installation['status'] : '',
			'is_copy'              => Site_Identity::is_copy(),
			'as_available'         => $as_available,
			'jobs'                 => $jobs,
			'cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'as_ran_recently'      => $ran_recently,
			'methods_fetched_at'   => Settings::methods_fetched_at(),
			'methods_enabled'      => $enabled,
			'methods_shown'        => $shown,
			'currency'             => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'php'                  => PHP_VERSION,
			'wp'                   => (string) get_bloginfo( 'version' ),
			'wc'                   => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'min_php'              => (string) $headers['php'],
			'min_wp'               => (string) $headers['wp'],
			'min_wc'               => (string) $headers['wc'],
			'hpos'                 => self::hpos_declared(),
		);
	}

	/**
	 * Whether WooCommerce has this plugin's HPOS compatibility declaration.
	 *
	 * @return string declared, missing or unknown.
	 */
	private static function hpos_declared() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) || ! did_action( 'woocommerce_init' ) ) {
			return 'unknown';
		}
		try {
			$features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_features_for_plugin( plugin_basename( OUTLINEPAY_PLUGIN_FILE ) );
		} catch ( \Throwable $error ) {
			return 'unknown';
		}
		return isset( $features['compatible'] ) && is_array( $features['compatible'] ) && in_array( 'custom_order_tables', $features['compatible'], true ) ? 'declared' : 'missing';
	}

	/**
	 * One check.
	 *
	 * @param string $code   Code.
	 * @param bool   $ok     Passed.
	 * @param string $side   Who acts next when it fails.
	 * @param array  $detail Short strings.
	 * @return array
	 */
	public static function check( $code, $ok, $side, array $detail ) {
		return array(
			'code'   => (string) $code,
			'ok'     => (bool) $ok,
			'side'   => in_array( $side, self::SIDE_ORDER, true ) ? $side : 'unknown',
			'detail' => self::clean_detail( $detail ),
		);
	}

	/**
	 * Holds a detail object to the server's shape: at most 10 keys of
	 * lowercase letters and underscores, string values of at most 200
	 * characters. Anything else is dropped.
	 *
	 * @param mixed $detail Detail.
	 * @return array
	 */
	public static function clean_detail( $detail ) {
		$clean = array();
		if ( ! is_array( $detail ) ) {
			return $clean;
		}
		foreach ( $detail as $key => $value ) {
			if ( count( $clean ) >= 10 ) {
				break;
			}
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,40}$/', $key ) || ! is_scalar( $value ) || null === $value ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$clean[ $key ] = substr( (string) $value, 0, 200 );
		}
		return $clean;
	}

	/**
	 * The checks as the server takes them: no empty detail (an empty PHP array
	 * would be sent as a JSON list).
	 *
	 * @param array $checks Checks.
	 * @return array
	 */
	public static function wire( array $checks ) {
		$out = array();
		foreach ( $checks as $check ) {
			$row = array(
				'code' => $check['code'],
				'ok'   => $check['ok'],
				'side' => $check['side'],
			);
			if ( ! empty( $check['detail'] ) ) {
				$row['detail'] = $check['detail'];
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * A result made on this site, without OutlinePay.
	 *
	 * @param array  $checks  Checks.
	 * @param string $trigger Trigger.
	 * @param int    $now     Unix time.
	 * @return array
	 */
	public static function offline( array $checks, $trigger, $now ) {
		return self::result( self::offline_code( $checks ), false, $trigger, $now, $checks );
	}

	/**
	 * OP-OFFLINE- and one letter per failed local check, in LETTERS order.
	 * An offline result always has a reason, so never fewer than one letter:
	 * with none failed, the reason is that OutlinePay was not reached (C).
	 *
	 * @param array $checks Checks.
	 * @return string
	 */
	public static function offline_code( array $checks ) {
		$failed = array();
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$failed[ $check['code'] ] = true;
			}
		}
		$letters = '';
		foreach ( self::LETTERS as $code => $letter ) {
			if ( isset( $failed[ $code ] ) ) {
				$letters .= $letter;
			}
		}
		return self::OFFLINE_PREFIX . ( '' !== $letters ? $letters : self::LETTERS['api_reachable'] );
	}

	/**
	 * The result from a 201 answer, or null when it cannot be read.
	 *
	 * @param mixed  $run     Decoded answer.
	 * @param string $trigger Trigger.
	 * @param int    $now     Unix time.
	 * @return array|null
	 */
	public static function from_server( $run, $trigger, $now ) {
		if ( ! is_array( $run ) || ! isset( $run['id'], $run['checks'] ) || ! is_string( $run['id'] ) || ! preg_match( '/^chk_[A-Za-z0-9]{1,64}$/', $run['id'] ) || ! is_array( $run['checks'] ) ) {
			return null;
		}
		$checks = array();
		foreach ( $run['checks'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['code'], $row['ok'] ) || ! is_string( $row['code'] ) || ! preg_match( '/^[a-z0-9_]{1,40}$/', $row['code'] ) || ! is_bool( $row['ok'] ) ) {
				continue;
			}
			$checks[] = self::check( $row['code'], $row['ok'], isset( $row['side'] ) && is_string( $row['side'] ) ? $row['side'] : 'unknown', isset( $row['detail'] ) && is_array( $row['detail'] ) ? $row['detail'] : array() );
		}
		if ( empty( $checks ) ) {
			return null;
		}
		return self::result( $run['id'], true, $trigger, $now, $checks );
	}

	/**
	 * Result shape.
	 *
	 * @param string $code    Diagnostic code.
	 * @param bool   $online  Whether OutlinePay answered the run.
	 * @param string $trigger Trigger.
	 * @param int    $now     Unix time.
	 * @param array  $checks  Checks.
	 * @return array
	 */
	private static function result( $code, $online, $trigger, $now, array $checks ) {
		$failed = 0;
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				++$failed;
			}
		}
		return array(
			'code'         => (string) $code,
			'online'       => (bool) $online,
			'trigger'      => (string) $trigger,
			'ran_at'       => (int) $now,
			'failed_count' => $failed,
			'worst_side'   => self::worst_side( $checks ),
			'checks'       => array_values( $checks ),
		);
	}

	/**
	 * The side that acts first among the failed checks, '' when none failed.
	 *
	 * @param array $checks Checks.
	 * @return string
	 */
	public static function worst_side( array $checks ) {
		$sides = array();
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$sides[] = $check['side'];
			}
		}
		foreach ( self::SIDE_ORDER as $side ) {
			if ( in_array( $side, $sides, true ) ) {
				return $side;
			}
		}
		return '';
	}

	/**
	 * Puts $check after the check with code $after.
	 *
	 * @param array  $checks Checks.
	 * @param string $after  Code.
	 * @param array  $check  Check to insert.
	 * @return array
	 */
	private static function insert_after( array $checks, $after, array $check ) {
		$out = array();
		foreach ( $checks as $row ) {
			$out[] = $row;
			if ( $row['code'] === $after ) {
				$out[] = $check;
			}
		}
		return $out;
	}

	/**
	 * Replaces the check with the same code.
	 *
	 * @param array $checks Checks.
	 * @param array $check  New check.
	 * @return array
	 */
	private static function replace( array $checks, array $check ) {
		foreach ( $checks as $index => $row ) {
			if ( $row['code'] === $check['code'] ) {
				$checks[ $index ] = $check;
			}
		}
		return $checks;
	}

	/**
	 * Saves the result. Not autoloaded: only the OutlinePay page, Site Health
	 * and the abilities read it.
	 *
	 * @param array $result Result.
	 * @return void
	 */
	public static function store( array $result ) {
		update_option( self::OPTION_LAST, $result, false );
	}

	/**
	 * The last stored result, or null.
	 *
	 * @return array|null
	 */
	public static function last() {
		$value = get_option( self::OPTION_LAST, null );
		if ( ! is_array( $value ) || ! isset( $value['code'], $value['checks'], $value['ran_at'] ) || ! is_array( $value['checks'] ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Whether the merchant asked for an email when a daily check fails.
	 *
	 * @return bool
	 */
	public static function email_enabled() {
		return 'yes' === get_option( self::OPTION_EMAIL, 'no' );
	}

	/**
	 * Plain label for who acts next.
	 *
	 * @param string $side Side.
	 * @return string
	 */
	public static function who( $side ) {
		switch ( $side ) {
			case 'store':
				return __( 'You', 'outlinepay-for-woocommerce' );
			case 'processor':
				return __( 'Your payment provider', 'outlinepay-for-woocommerce' );
			case 'outlinepay':
				return __( 'OutlinePay', 'outlinepay-for-woocommerce' );
			case 'shopper':
				return __( 'Shopper', 'outlinepay-for-woocommerce' );
			default:
				return __( 'Not sure', 'outlinepay-for-woocommerce' );
		}
	}

	/**
	 * One plain sentence for a check result, for the merchant.
	 *
	 * @param array $check Check.
	 * @return string
	 */
	public static function sentence( array $check ) {
		$ok     = ! empty( $check['ok'] );
		$detail = isset( $check['detail'] ) && is_array( $check['detail'] ) ? $check['detail'] : array();
		$side   = isset( $check['side'] ) ? $check['side'] : 'unknown';
		switch ( isset( $check['code'] ) ? $check['code'] : '' ) {
			case 'connection_saved':
				return $ok
					? __( 'The connection is saved on this site.', 'outlinepay-for-woocommerce' )
					: __( 'The saved connection is missing or can no longer be read. Disconnect, then connect again with a new code from your payment provider.', 'outlinepay-for-woocommerce' );
			case 'connection_status':
				if ( $ok ) {
					return __( 'The store is connected.', 'outlinepay-for-woocommerce' );
				}
				return 'processor' === $side
					? __( 'This connection was turned off. Ask your payment provider for a new connect code.', 'outlinepay-for-woocommerce' )
					: __( 'The store is not connected. Connect it with a code from your payment provider.', 'outlinepay-for-woocommerce' );
			case 'api_reachable':
				if ( $ok ) {
					return sprintf(
						/* translators: %s: milliseconds, such as 120. */
						__( 'OutlinePay answered in %s ms.', 'outlinepay-for-woocommerce' ),
						isset( $detail['elapsed_ms'] ) ? $detail['elapsed_ms'] : '?'
					);
				}
				if ( 'store' === $side ) {
					return __( 'This site could not reach OutlinePay. Check that the server can make outgoing HTTPS requests.', 'outlinepay-for-woocommerce' );
				}
				if ( 'processor' === $side ) {
					return __( 'OutlinePay refused this store\'s connection. Ask your payment provider for a new connect code.', 'outlinepay-for-woocommerce' );
				}
				return __( 'OutlinePay could not answer the check. Try again in a few minutes.', 'outlinepay-for-woocommerce' );
			case 'scheduler_jobs':
				return $ok
					? __( 'Background payment checks are scheduled.', 'outlinepay-for-woocommerce' )
					: __( 'Background payment checks are missing or late. Open WooCommerce > Status > Scheduled Actions and look for failed outlinepay actions.', 'outlinepay-for-woocommerce' );
			case 'wp_cron':
				return $ok
					? __( 'Scheduled tasks are running.', 'outlinepay-for-woocommerce' )
					: __( 'WP-Cron is turned off and no scheduled task ran in the last 2 hours. Ask your host to run wp-cron.php from a server cron job.', 'outlinepay-for-woocommerce' );
			case 'methods_fresh':
				if ( $ok ) {
					return __( 'The payment method list is up to date and at least one method shows at checkout.', 'outlinepay-for-woocommerce' );
				}
				return isset( $detail['problem'] ) && 'none_shown' === $detail['problem']
					? __( 'No OutlinePay method shows at checkout. Turn one on under Payment methods on the OutlinePay page, or ask your payment provider to enable one.', 'outlinepay-for-woocommerce' )
					: __( 'The payment method list was not refreshed in the last 2 hours.', 'outlinepay-for-woocommerce' );
			case 'store_currency':
				if ( $ok ) {
					return __( 'The store currency is KRW.', 'outlinepay-for-woocommerce' );
				}
				return sprintf(
					/* translators: %s: currency code such as USD. */
					__( 'The store currency is %s. OutlinePay methods show only when it is KRW.', 'outlinepay-for-woocommerce' ),
					isset( $detail['currency'] ) && '' !== $detail['currency'] ? $detail['currency'] : '?'
				);
			case 'not_a_copy':
				return $ok
					? __( 'This site is at the address the store was connected at.', 'outlinepay-for-woocommerce' )
					: __( 'This site looks like a copy of your store. OutlinePay is off here.', 'outlinepay-for-woocommerce' );
			case 'versions':
				return $ok
					? __( 'PHP, WordPress and WooCommerce meet this plugin\'s requirements.', 'outlinepay-for-woocommerce' )
					: __( 'PHP, WordPress or WooCommerce is older than this plugin needs, or WooCommerce did not register its order storage support.', 'outlinepay-for-woocommerce' );
			case 'callback_reachable':
				return $ok
					? __( 'OutlinePay reached this store\'s payment update address.', 'outlinepay-for-woocommerce' )
					: __( 'OutlinePay could not reach this store\'s payment update address. Check that the site is online and that no firewall or security plugin blocks /wp-json/outlinepay/v1/callback.', 'outlinepay-for-woocommerce' );
			case 'token_used_recently':
				return $ok
					? __( 'This store talked to OutlinePay in the last 24 hours.', 'outlinepay-for-woocommerce' )
					: __( 'This store has not talked to OutlinePay in the last 24 hours.', 'outlinepay-for-woocommerce' );
			case 'deliveries_gave_up_7d':
				return $ok
					? __( 'Every payment update in the last 7 days reached the store.', 'outlinepay-for-woocommerce' )
					: __( 'Some payment updates in the last 7 days could not be delivered to the store.', 'outlinepay-for-woocommerce' );
			case 'open_problems_7d':
				return $ok
					? __( 'No open problems in the last 7 days.', 'outlinepay-for-woocommerce' )
					: __( 'OutlinePay recorded problems in the last 7 days that are not resolved yet.', 'outlinepay-for-woocommerce' );
			default:
				return $ok
					? __( 'Passed.', 'outlinepay-for-woocommerce' )
					: __( 'Failed.', 'outlinepay-for-woocommerce' );
		}
	}
}
