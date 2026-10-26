<?php
/**
 * Notices when checkout may have lost its payment methods.
 *
 * Three parts:
 *
 * - Events. The self-check runs again when the store currency or the site
 *   address changes, or WooCommerce is deactivated or updated. This plugin's
 *   own update is caught by Scheduler::report_versions. The run is the page
 *   run: stored and sent to OutlinePay like "Run check".
 * - Hourly. The hourly method refresh records the local checks (no extra
 *   call to OutlinePay), so a failure with no event shows within an hour.
 *   A refresh that worked also counts as OutlinePay reached.
 * - Notice. While the last stored run has a failing check needed to take
 *   payments, or another failing check the merchant can fix, every admin
 *   page but the OutlinePay page says so (see notice_level()). It is not
 *   dismissable: it goes away when a later run passes.
 *
 * Debounce: the first event in a request asks for a run at the end of the
 * request. Later events in the same request add nothing. At the end, one
 * async action is queued on Action Scheduler, and none when one is already
 * waiting, or when a page run already happened after the last change in
 * this request (such as Fix all). The run happens there, never in a
 * shopper's request. Only a
 * WooCommerce deactivation runs at the end of its own admin request (see
 * flush()). Events on front-end requests are ignored. The hourly run
 * catches those.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Event listeners, the hourly record and the admin notice.
 */
final class Self_Check_Watch {

	const WC_BASENAME = 'woocommerce/woocommerce.php';

	const NOTICE_BLOCKING = 'blocking';
	const NOTICE_PROBLEM  = 'problem';

	/**
	 * Whether this request asked for a run.
	 *
	 * @var bool
	 */
	private static $requested = false;

	/**
	 * Whether WooCommerce is deactivated in this request.
	 *
	 * @var bool
	 */
	private static $wc_gone = false;

	/**
	 * Self_Check::page_runs() at the last change in this request.
	 *
	 * @var int
	 */
	private static $page_runs = 0;

	/**
	 * Test seam: returns the Self_Check to use. Null uses a new Self_Check.
	 *
	 * @var callable|null
	 */
	private static $self_check = null;

	/**
	 * Hooks the listeners and the notice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'update_option_woocommerce_currency', array( self::class, 'changed' ) );
		add_action( 'update_option_siteurl', array( self::class, 'changed' ) );
		add_action( 'update_option_home', array( self::class, 'changed' ) );
		add_action( 'deactivated_plugin', array( self::class, 'plugin_deactivated' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'upgraded' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * An option that can hide the payment methods changed. WordPress fires
	 * update_option_{name} only when the value really changed.
	 *
	 * @return void
	 */
	public static function changed() {
		self::request( false );
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	public static function plugin_deactivated( $plugin ) {
		if ( self::WC_BASENAME === $plugin ) {
			self::request( true );
		}
	}

	/**
	 * Plugins were updated. Only a WooCommerce update counts here.
	 *
	 * @param mixed $upgrader   Upgrader.
	 * @param mixed $hook_extra What was updated.
	 * @return void
	 */
	public static function upgraded( $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['type'], $hook_extra['action'] ) || 'plugin' !== $hook_extra['type'] || 'update' !== $hook_extra['action'] ) {
			return;
		}
		$plugins = array();
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}
		if ( in_array( self::WC_BASENAME, $plugins, true ) ) {
			self::request( false );
		}
	}

	/**
	 * Asks for one run at the end of this request.
	 *
	 * @param bool $wc_gone WooCommerce is deactivated in this request.
	 * @return void
	 */
	private static function request( $wc_gone ) {
		if ( ! self::watching() || ! self::backend_request() ) {
			return;
		}
		if ( $wc_gone ) {
			self::$wc_gone = true;
		}
		self::$page_runs = Self_Check::page_runs();
		if ( self::$requested ) {
			return;
		}
		self::$requested = true;
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/**
	 * End of the request: queues the run. When WooCommerce was deactivated,
	 * Action Scheduler goes with it, so the run happens now, with
	 * WooCommerce counted as missing. The queued action then waits and runs
	 * when WooCommerce is back.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( ! self::$requested ) {
			return;
		}
		$wc_gone         = self::$wc_gone;
		self::$requested = false;
		self::$wc_gone   = false;
		if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( plugin_basename( OUTLINEPAY_PLUGIN_FILE ) ) ) {
			// This plugin was deactivated in the same request.
			return;
		}
		if ( $wc_gone ) {
			// Waits while WooCommerce is off, runs when it is back.
			self::queue();
		} elseif ( Self_Check::page_runs() === self::$page_runs ) {
			// No page run since the last change saw it.
			self::queue();
		}
		if ( $wc_gone ) {
			self::engine( array( 'wc' => '' ) )->run( Self_Check::TRIGGER_PAGE );
		}
	}

	/**
	 * Queues one run soon, unless one is already waiting.
	 *
	 * @return void
	 */
	public static function queue() {
		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( Scheduler::HOOK_CHECK_NOW, array(), Scheduler::GROUP ) ) {
			as_enqueue_async_action( Scheduler::HOOK_CHECK_NOW, array(), Scheduler::GROUP );
		}
	}

	/**
	 * The queued run, from Action Scheduler.
	 *
	 * @return void
	 */
	public static function run_now() {
		if ( ! self::watching() ) {
			return;
		}
		self::engine()->run( Self_Check::TRIGGER_PAGE );
	}

	/**
	 * The hourly record: the local checks merged into the stored result.
	 * No network. When the method refresh just reached OutlinePay, its time
	 * is recorded as api_reachable passing. Otherwise that row stays as stored.
	 *
	 * @param int|null $refresh_ms Milliseconds the refresh took, null when it failed.
	 * @return void
	 */
	public static function record_local( $refresh_ms = null ) {
		if ( ! self::watching() ) {
			return;
		}
		$extra = array();
		if ( null !== $refresh_ms ) {
			$extra[] = Self_Check::check(
				'api_reachable',
				true,
				'store',
				array(
					'outcome'    => 'ok',
					'elapsed_ms' => (string) (int) $refresh_ms,
				)
			);
		}
		self::engine()->run_local( Self_Check::TRIGGER_HOURLY, $extra );
	}

	/**
	 * Admin notice on every admin page but the OutlinePay page, while the
	 * last run has a failing blocking check, or another failing check the
	 * merchant can fix.
	 *
	 * @return void
	 */
	public static function notice() {
		// A copy has its own notice (see Admin_Page::copy_notice).
		if ( ! current_user_can( Admin_Page::CAPABILITY ) || ! self::watching() || Site_Identity::is_copy() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides which page is showing.
		if ( isset( $_GET['page'] ) && Admin_Page::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$last  = Self_Check::last();
		$level = $last ? self::notice_level( $last ) : '';
		if ( self::NOTICE_BLOCKING === $level ) {
			// Whoever acts next: the page says who.
			echo '<div class="notice notice-error outlinepay-check-notice"><p>' . esc_html__( 'OutlinePay: a check needed to take payments is failing.', 'outlinepay-for-woocommerce' );
			$link = __( 'Open the OutlinePay page to see what to do.', 'outlinepay-for-woocommerce' );
		} elseif ( self::NOTICE_PROBLEM === $level ) {
			echo '<div class="notice notice-warning outlinepay-check-notice"><p>' . esc_html__( 'OutlinePay\'s self-check found a problem.', 'outlinepay-for-woocommerce' );
			$link = __( 'Open the OutlinePay page to fix it.', 'outlinepay-for-woocommerce' );
		} else {
			return;
		}
		echo ' <a href="' . esc_url( Admin_Page::url() ) . '">' . esc_html( $link ) . '</a></p></div>';
	}

	/**
	 * Which notice a result needs. Pure.
	 *
	 * blocking: a blocking row failed (see Self_Check::severity), whoever
	 * acts next. methods_fresh counts only when no method is shown: a stale
	 * list still shows at checkout. problem: some other failing row is the
	 * store's to fix. '' otherwise: nothing failed, or only OutlinePay or
	 * the provider can act on an advisory row. The OutlinePay page still
	 * shows those.
	 *
	 * @param array $result Result.
	 * @return string blocking, problem or ''.
	 */
	public static function notice_level( array $result ) {
		$level = '';
		foreach ( $result['checks'] as $check ) {
			if ( ! empty( $check['ok'] ) ) {
				continue;
			}
			$code  = isset( $check['code'] ) ? $check['code'] : '';
			$stale = 'methods_fresh' === $code && ! ( isset( $check['detail']['problem'] ) && 'none_shown' === $check['detail']['problem'] );
			if ( Self_Check::BLOCKING === Self_Check::severity( $check ) && ! $stale ) {
				return self::NOTICE_BLOCKING;
			}
			if ( isset( $check['side'] ) && 'store' === $check['side'] ) {
				$level = self::NOTICE_PROBLEM;
			}
		}
		return $level;
	}

	/**
	 * Whether this store has a connection worth checking. A copy is checked
	 * too: the run sees the copy and never calls OutlinePay (see
	 * Self_Check::run). A site address change on a copy is recorded that way.
	 *
	 * @return bool
	 */
	private static function watching() {
		return ! empty( Settings::installation() ) && Credentials::present();
	}

	/**
	 * Admin, cron, REST or WP-CLI. Not a shopper's page.
	 *
	 * @return bool
	 */
	private static function backend_request() {
		return is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The Self_Check to use.
	 *
	 * @param array $override Facts that replace the gathered ones.
	 * @return Self_Check
	 */
	private static function engine( array $override = array() ) {
		if ( self::$self_check ) {
			return call_user_func( self::$self_check, $override );
		}
		return new Self_Check(
			static function () use ( $override ) {
				return array_merge( Self_Check::gather_facts(), $override );
			}
		);
	}

	/**
	 * Replaces the Self_Check, for tests. The factory gets the facts that
	 * replace the gathered ones. Null restores it and clears this request's
	 * state.
	 *
	 * @param callable|null $factory Returns a Self_Check.
	 * @return void
	 */
	public static function set_self_check( $factory ) {
		self::$self_check = $factory;
		self::$requested  = false;
		self::$wc_gone    = false;
		self::$page_runs  = 0;
	}
}
