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
 * - Notice. While the last stored run has a failing check, every admin page
 *   but the OutlinePay page says so. It is not dismissable: it goes away
 *   when a later run passes.
 *
 * Debounce: the first event in a request asks for a run at the end of the
 * request. Later events in the same request add nothing. At the end, one
 * async action is queued on Action Scheduler, and none when one is already
 * waiting. The run happens there, never in a shopper's request. Only a
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
		self::queue();
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
	 * No network.
	 *
	 * @return void
	 */
	public static function record_local() {
		if ( ! self::watching() ) {
			return;
		}
		self::engine()->run_local( Self_Check::TRIGGER_HOURLY );
	}

	/**
	 * Admin notice on every admin page but the OutlinePay page, while the
	 * last run has a failing check.
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
		$last = Self_Check::last();
		if ( ! $last || (int) $last['failed_count'] < 1 ) {
			return;
		}
		echo '<div class="notice notice-error outlinepay-check-notice"><p>' . esc_html__( 'OutlinePay: payment methods are not showing at checkout.', 'outlinepay-for-woocommerce' );
		echo ' <a href="' . esc_url( Admin_Page::url() ) . '">' . esc_html__( 'Open the OutlinePay page to fix it.', 'outlinepay-for-woocommerce' ) . '</a></p></div>';
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
	}
}
