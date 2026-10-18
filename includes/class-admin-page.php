<?php
/**
 * WooCommerce > OutlinePay: connect, status, self-check, method switches,
 * disconnect.
 *
 * Nothing on this page calls OutlinePay until the merchant connects.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The admin page.
 */
final class Admin_Page {

	const SLUG       = 'outlinepay';
	const CAPABILITY = 'manage_woocommerce';
	const SCRIPT     = 'outlinepay-admin';

	/**
	 * The page's hook suffix, set when the menu is added.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hooks the page and its form handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_action( 'admin_post_outlinepay_connect', array( self::class, 'connect' ) );
		add_action( 'admin_post_outlinepay_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_post_outlinepay_methods', array( self::class, 'save_methods' ) );
		add_action( 'admin_post_outlinepay_copy_separate', array( self::class, 'copy_separate' ) );
		add_action( 'admin_post_outlinepay_copy_moved', array( self::class, 'copy_moved' ) );
		add_action( 'admin_post_outlinepay_self_check', array( self::class, 'self_check' ) );
		add_action( 'admin_post_outlinepay_self_check_email', array( self::class, 'save_self_check_email' ) );
		add_action( 'admin_post_outlinepay_shopper_cancel', array( self::class, 'save_shopper_cancel' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'copy_notice' ) );
		add_action( 'admin_init', array( Site_Identity::class, 'ensure_saved' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OUTLINEPAY_PLUGIN_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * URL of the page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Adds the submenu under WooCommerce.
	 *
	 * @return void
	 */
	public static function menu() {
		self::$hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'OutlinePay', 'outlinepay-for-woocommerce' ),
			__( 'OutlinePay', 'outlinepay-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * The copy-code script, on this page only. Plain script, no build step.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/js/admin.js', OUTLINEPAY_PLUGIN_FILE ), array(), OUTLINEPAY_VERSION, true );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'outlinepay-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Handles the connect form.
	 *
	 * @return void
	 */
	public static function connect() {
		self::require_capability();
		check_admin_referer( 'outlinepay_connect' );
		$code = isset( $_POST['outlinepay_connect_code'] ) ? sanitize_text_field( wp_unslash( $_POST['outlinepay_connect_code'] ) ) : '';
		$code = preg_replace( '/[^A-Za-z0-9_]/', '', $code );
		if ( '' === $code ) {
			self::back( 'code_missing' );
		}

		try {
			$installation = ( new Api_Client() )->connect( $code, home_url( '/' ), Settings::callback_url(), Settings::platform_versions() );
		} catch ( Api_Error $error ) {
			self::back( self::connect_error( $error ) );
		}

		if ( empty( $installation['token'] ) || empty( $installation['signing_secret'] ) ) {
			Logger::error( 'Connect answer had no credentials' );
			self::back( 'connect_failed' );
		}

		Credentials::save( $installation['token'], $installation['signing_secret'] );
		Site_Identity::remember_current();
		unset( $installation['token'], $installation['signing_secret'] );
		Settings::save_installation( $installation );
		update_option( Settings::OPTION_VERSION, OUTLINEPAY_VERSION, false );
		Logger::info( 'Store connected', array( 'status' => isset( $installation['status'] ) ? $installation['status'] : '' ) );

		Scheduler::refresh_methods();
		Scheduler::schedule_recurring();
		self::back( 'connected' );
	}

	/**
	 * Handles the disconnect form. The server is told first, best effort.
	 *
	 * @return void
	 */
	public static function disconnect() {
		self::require_capability();
		check_admin_referer( 'outlinepay_disconnect' );
		// Never from a copy of the store: that would revoke the live store's connection.
		if ( Credentials::present() && ! Site_Identity::is_copy() ) {
			try {
				( new Api_Client() )->disconnect();
			} catch ( Api_Error $error ) {
				// The token may already be revoked. Local cleanup goes ahead.
				Logger::warning( 'Disconnect not confirmed by the server', array( 'code' => $error->get_error_code() ) );
			}
		}
		Settings::clear_connection();
		Scheduler::unschedule_all();
		self::back( 'disconnected' );
	}

	/**
	 * "Connect this copy separately": removes the copied connection from this
	 * site only. OutlinePay is not told, so the live store keeps working.
	 *
	 * @return void
	 */
	public static function copy_separate() {
		self::require_capability();
		check_admin_referer( 'outlinepay_copy_separate' );
		if ( ! Site_Identity::is_copy() ) {
			self::back( 'copy_none' );
		}
		Site_Identity::forget_copied_connection();
		self::back( 'copy_cleared' );
	}

	/**
	 * "I moved my store to this address", after the confirm step.
	 *
	 * @return void
	 */
	public static function copy_moved() {
		self::require_capability();
		check_admin_referer( 'outlinepay_copy_moved' );
		if ( ! Site_Identity::is_copy() ) {
			self::back( 'copy_none' );
		}
		$updated = Site_Identity::accept_move();
		Scheduler::schedule_recurring();
		self::back( $updated ? 'moved' : 'moved_callback_failed' );
	}

	/**
	 * Admin notice on every admin page while this site looks like a copy.
	 * The OutlinePay page shows its own banner instead.
	 *
	 * @return void
	 */
	public static function copy_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! Site_Identity::is_copy() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides which page is showing.
		if ( isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		echo '<div class="notice notice-error outlinepay-copy-notice">';
		self::copy_text();
		echo '<p><a class="button button-primary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the OutlinePay page', 'outlinepay-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * The copy explanation, shared by the notice and the banner.
	 *
	 * @return void
	 */
	private static function copy_text() {
		echo '<p><strong>' . esc_html__( 'This looks like a copy of your store.', 'outlinepay-for-woocommerce' ) . '</strong></p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: address the store was connected at, 2: this site's address. */
				__( 'It was connected at %1$s. This site is %2$s.', 'outlinepay-for-woocommerce' ),
				Site_Identity::saved(),
				Site_Identity::current()
			)
		) . '</p>';
		echo '<p>' . esc_html__( 'OutlinePay is off here, so this copy cannot take real payments.', 'outlinepay-for-woocommerce' ) . '</p>';
	}

	/**
	 * The banner and the two ways out, on the OutlinePay page.
	 *
	 * @return void
	 */
	private static function render_copy_banner() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: shows the confirm step, which posts with its own nonce.
		$confirm = isset( $_GET['outlinepay_confirm_move'] );
		echo '<div class="notice notice-error inline outlinepay-copy-banner">';
		self::copy_text();
		echo '</div>';

		if ( $confirm ) {
			echo '<h2>' . esc_html__( 'Confirm the move', 'outlinepay-for-woocommerce' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: this site's address. */
					__( 'Only confirm if %s is now your real store.', 'outlinepay-for-woocommerce' ),
					Site_Identity::current()
				)
			) . '</p>';
			echo '<p>' . esc_html__( 'Payments turn back on at this address. OutlinePay sends payment updates to this address from now on.', 'outlinepay-for-woocommerce' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'outlinepay_copy_moved' );
			echo '<input type="hidden" name="action" value="outlinepay_copy_moved">';
			echo '<p>';
			submit_button( __( 'Yes, my store moved here', 'outlinepay-for-woocommerce' ), 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'outlinepay-for-woocommerce' ) . '</a></p>';
			echo '</form>';
			return;
		}

		echo '<h2>' . esc_html__( 'Connect this copy separately', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'This removes the copied connection from this site only. Your live store keeps working. You can then connect this site with its own connect code.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_copy_separate' );
		echo '<input type="hidden" name="action" value="outlinepay_copy_separate">';
		submit_button( __( 'Connect this copy separately', 'outlinepay-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'I moved my store to this address', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose this if this site is now your real store.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::url( array( 'outlinepay_confirm_move' => '1' ) ) ) . '">' . esc_html__( 'I moved my store to this address', 'outlinepay-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * "Run check": runs the self-check with trigger page and comes back to
	 * the page, which shows the stored result.
	 *
	 * @return void
	 */
	public static function self_check() {
		self::require_capability();
		check_admin_referer( 'outlinepay_self_check' );
		$outcome = ( new Self_Check() )->run( Self_Check::TRIGGER_PAGE );
		self::back( $outcome['rate_limited'] ? 'self_check_rate_limited' : 'self_check_done' );
	}

	/**
	 * Saves "Email me when a daily check fails".
	 *
	 * @return void
	 */
	public static function save_self_check_email() {
		self::require_capability();
		check_admin_referer( 'outlinepay_self_check_email' );
		$on = isset( $_POST['outlinepay_self_check_email'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['outlinepay_self_check_email'] ) );
		update_option( Self_Check::OPTION_EMAIL, $on ? 'yes' : 'no', false );
		self::back( 'self_check_email_saved' );
	}

	/**
	 * Saves "Let shoppers cancel their own paid orders" and its statuses.
	 *
	 * @return void
	 */
	public static function save_shopper_cancel() {
		self::require_capability();
		check_admin_referer( 'outlinepay_shopper_cancel' );
		$on       = isset( $_POST['outlinepay_cancel_enabled'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['outlinepay_cancel_enabled'] ) );
		$statuses = isset( $_POST['outlinepay_cancel_statuses'] ) && is_array( $_POST['outlinepay_cancel_statuses'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['outlinepay_cancel_statuses'] ) ) : array();
		Settings::save_shopper_cancel( $on, $statuses );
		self::back( 'shopper_cancel_saved' );
	}

	/**
	 * Handles the method switches.
	 *
	 * @return void
	 */
	public static function save_methods() {
		self::require_capability();
		check_admin_referer( 'outlinepay_methods' );
		$shown = isset( $_POST['outlinepay_shown'] ) && is_array( $_POST['outlinepay_shown'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['outlinepay_shown'] ) ) : array();
		foreach ( Settings::methods() as $row ) {
			Settings::set_shown( $row['method'], in_array( $row['method'], $shown, true ) );
		}
		self::back( 'methods_saved' );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$installation = Settings::installation();
		$has_link     = ! empty( $installation ) && Credentials::present();

		$is_copy = $has_link && Site_Identity::is_copy();
		if ( $has_link && ! $is_copy ) {
			self::refresh();
			$installation = Settings::installation();
		}

		echo '<div class="wrap outlinepay-admin">';
		echo '<h1>' . esc_html__( 'OutlinePay', 'outlinepay-for-woocommerce' ) . '</h1>';
		self::render_notice();

		if ( Credentials::unreadable() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The saved connection can no longer be read. This happens when the site security keys change. Disconnect, then connect again with a new code.', 'outlinepay-for-woocommerce' ) . '</p></div>';
		}

		if ( ! $has_link ) {
			self::render_connect_form();
			self::render_disconnect_form( Credentials::unreadable() );
			echo '</div>';
			return;
		}

		if ( $is_copy ) {
			self::render_copy_banner();
			self::render_self_check();
			echo '</div>';
			return;
		}

		$status = isset( $installation['status'] ) ? (string) $installation['status'] : '';
		self::render_status( $installation );
		self::render_self_check();

		if ( 'connected' === $status ) {
			if ( 'KRW' !== get_woocommerce_currency() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html(
					sprintf(
						/* translators: %s: currency code such as USD. */
						__( 'Your store currency is %s. OutlinePay methods show at checkout only when the store currency is KRW.', 'outlinepay-for-woocommerce' ),
						get_woocommerce_currency()
					)
				) . '</p></div>';
			}
			self::render_methods();
			self::render_shopper_cancel();
		} else {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'OutlinePay no longer accepts this store\'s connection. Payments are off. Ask your payment provider for a new connect code, then disconnect and connect again.', 'outlinepay-for-woocommerce' ) . '</p></div>';
		}

		self::render_disconnect_form( true );
		echo '</div>';
	}

	/**
	 * Refreshes the installation and the method list. Called on page load when connected.
	 *
	 * @return void
	 */
	private static function refresh() {
		$client = new Api_Client();
		try {
			$installation = $client->get_installation();
			Settings::save_installation( $installation );
		} catch ( Api_Error $error ) {
			if ( 401 === $error->get_http_status() ) {
				Settings::mark_revoked();
			}
			return;
		}
		if ( isset( $installation['callback_url'] ) && is_string( $installation['callback_url'] ) && Settings::callback_url() !== $installation['callback_url'] ) {
			// The store moved, or a move could not be reported earlier.
			try {
				$client->update_installation( array( 'callback_url' => Settings::callback_url() ) );
			} catch ( Api_Error $error ) {
				// Logged by the client. Retried on the next page load.
				unset( $error );
			}
		}
		if ( Settings::is_connected() ) {
			Scheduler::refresh_methods();
			Scheduler::schedule_recurring();
		}
	}

	/**
	 * The connect form.
	 *
	 * @return void
	 */
	private static function render_connect_form() {
		echo '<h2>' . esc_html__( 'Connect your store', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your payment provider gives you a connect code. Paste it here to start taking payments.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<p>' . esc_html__( 'Connecting sends this store\'s address, its callback address and its software versions to OutlinePay.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_connect' );
		echo '<input type="hidden" name="action" value="outlinepay_connect">';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="outlinepay_connect_code">' . esc_html__( 'Connect code', 'outlinepay-for-woocommerce' ) . '</label></th>';
		echo '<td><input type="text" class="regular-text code" id="outlinepay_connect_code" name="outlinepay_connect_code" autocomplete="off" spellcheck="false" placeholder="olp_cc_..." required></td></tr></table>';
		submit_button( __( 'Connect', 'outlinepay-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * The status table.
	 *
	 * @param array $installation Installation summary.
	 * @return void
	 */
	private static function render_status( array $installation ) {
		$status = isset( $installation['status'] ) ? (string) $installation['status'] : '';
		$labels = array(
			'connected' => __( 'Connected', 'outlinepay-for-woocommerce' ),
			'revoked'   => __( 'Disconnected by OutlinePay', 'outlinepay-for-woocommerce' ),
			'pending'   => __( 'Pending', 'outlinepay-for-woocommerce' ),
		);
		echo '<h2>' . esc_html__( 'Connection', 'outlinepay-for-woocommerce' );
		if ( Settings::is_test_mode() ) {
			echo ' <span class="outlinepay-badge" style="background:#f0b849;color:#1e1e1e;border-radius:3px;padding:2px 8px;font-size:12px;vertical-align:middle;">' . esc_html__( 'Test mode', 'outlinepay-for-woocommerce' ) . '</span>';
		}
		echo '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::row( __( 'Status', 'outlinepay-for-woocommerce' ), isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
		self::row( __( 'Mode', 'outlinepay-for-woocommerce' ), Settings::is_test_mode() ? __( 'Test. No real money moves.', 'outlinepay-for-woocommerce' ) : __( 'Live', 'outlinepay-for-woocommerce' ) );
		self::row( __( 'Payment provider', 'outlinepay-for-woocommerce' ), self::provider_name( isset( $installation['provider'] ) ? (string) $installation['provider'] : '' ) );
		self::row( __( 'Installation', 'outlinepay-for-woocommerce' ), isset( $installation['id'] ) ? (string) $installation['id'] : '' );
		self::row( __( 'Refunds', 'outlinepay-for-woocommerce' ), self::refunds_on( $installation ) ? __( 'On', 'outlinepay-for-woocommerce' ) : __( 'Off', 'outlinepay-for-woocommerce' ) );
		if ( ! empty( $installation['connected_at'] ) ) {
			self::row( __( 'Connected since', 'outlinepay-for-woocommerce' ), Order_Display::deadline( (string) $installation['connected_at'] ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * The self-check: the last stored result, the code to send to the
	 * provider, the Run check button and the email option.
	 *
	 * @return void
	 */
	private static function render_self_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks one of the fixed messages below.
		$notice = isset( $_GET['outlinepay_result'] ) ? sanitize_key( wp_unslash( $_GET['outlinepay_result'] ) ) : '';
		$last   = Self_Check::last();

		echo '<h2 id="outlinepay-self-check">' . esc_html__( 'Self-check', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checks the connection between this store, OutlinePay and your payment provider. No shopper or order details are checked or sent.', 'outlinepay-for-woocommerce' ) . '</p>';

		if ( 'self_check_rate_limited' === $notice ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A check ran less than a minute ago.', 'outlinepay-for-woocommerce' ) . '</p></div>';
		}

		if ( $last ) {
			echo '<div class="outlinepay-check-code" style="margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . ( 0 === (int) $last['failed_count'] ? '#00a32a' : '#d63638' ) . ';max-width:640px;box-sizing:border-box">';
			echo '<p style="margin:0 0 4px">' . esc_html__( 'Diagnostic code', 'outlinepay-for-woocommerce' ) . '</p>';
			echo '<p style="margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:8px"><code id="outlinepay-check-code" style="font-size:20px;padding:4px 8px;user-select:all">' . esc_html( $last['code'] ) . '</code>';
			echo '<button type="button" class="button" id="outlinepay-copy-code" data-target="outlinepay-check-code" data-copied="' . esc_attr__( 'Copied', 'outlinepay-for-woocommerce' ) . '" data-manual="' . esc_attr__( 'Selected. Press Ctrl+C to copy.', 'outlinepay-for-woocommerce' ) . '">' . esc_html__( 'Copy', 'outlinepay-for-woocommerce' ) . '</button>';
			echo '<span id="outlinepay-copy-status" aria-live="polite"></span></p>';
			echo '<p style="margin:8px 0 0">' . esc_html__( 'Send this code to your payment provider if you need help.', 'outlinepay-for-woocommerce' ) . '</p>';
			echo '<p style="margin:4px 0 0;color:#50575e">' . esc_html(
				sprintf(
					/* translators: %s: date and time of the last check. */
					'daily' === $last['trigger'] ? __( 'Last run %s (daily check).', 'outlinepay-for-woocommerce' ) : __( 'Last run %s.', 'outlinepay-for-woocommerce' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['ran_at'] )
				)
			);
			if ( empty( $last['online'] ) ) {
				echo ' ' . esc_html__( 'OutlinePay was not reached, so this code was made on this site.', 'outlinepay-for-woocommerce' );
			}
			echo '</p></div>';

			echo '<table class="widefat striped outlinepay-checks" style="max-width:640px"><thead><tr>';
			echo '<th scope="col" style="width:70px">' . esc_html__( 'Result', 'outlinepay-for-woocommerce' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Check', 'outlinepay-for-woocommerce' ) . '</th>';
			echo '<th scope="col" style="width:150px">' . esc_html__( 'Who acts next', 'outlinepay-for-woocommerce' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $last['checks'] as $check ) {
				$ok = ! empty( $check['ok'] );
				echo '<tr class="' . ( $ok ? 'outlinepay-check-ok' : 'outlinepay-check-failed' ) . '">';
				echo '<td>' . ( $ok
					? '<span aria-hidden="true" style="color:#00a32a;font-weight:600">&#10003;</span> ' . esc_html__( 'Pass', 'outlinepay-for-woocommerce' )
					: '<span aria-hidden="true" style="color:#d63638;font-weight:600">&#10007;</span> <strong>' . esc_html__( 'Fail', 'outlinepay-for-woocommerce' ) . '</strong>' ) . '</td>';
				echo '<td>' . esc_html( Self_Check::sentence( $check ) ) . '</td>';
				echo '<td>' . ( $ok ? '' : esc_html( Self_Check::who( isset( $check['side'] ) ? $check['side'] : 'unknown' ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No check has run yet.', 'outlinepay-for-woocommerce' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_self_check' );
		echo '<input type="hidden" name="action" value="outlinepay_self_check">';
		submit_button( __( 'Run check', 'outlinepay-for-woocommerce' ), 'secondary', 'outlinepay_run_check', true );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_self_check_email' );
		echo '<input type="hidden" name="action" value="outlinepay_self_check_email">';
		echo '<p><label><input type="checkbox" name="outlinepay_self_check_email" value="yes"' . checked( Self_Check::email_enabled(), true, false ) . '> ';
		echo esc_html(
			sprintf(
				/* translators: %s: the site admin email address. */
				__( 'Email me when a daily check fails (to %s)', 'outlinepay-for-woocommerce' ),
				(string) get_option( 'admin_email' )
			)
		) . '</label> ';
		submit_button( __( 'Save', 'outlinepay-for-woocommerce' ), 'secondary', 'outlinepay_save_email', false );
		echo '</p></form>';
	}

	/**
	 * Whether the payment provider turned refunds on for this store.
	 *
	 * @param array $installation Installation summary.
	 * @return bool
	 */
	private static function refunds_on( array $installation ) {
		if ( isset( $installation['refunds_enabled'] ) ) {
			return true === $installation['refunds_enabled'];
		}
		return Settings::refunds_permitted();
	}

	/**
	 * The shopper cancel setting.
	 *
	 * @return void
	 */
	private static function render_shopper_cancel() {
		$setting = Settings::shopper_cancel();
		$labels  = array(
			'processing' => __( 'Processing', 'outlinepay-for-woocommerce' ),
			'on-hold'    => __( 'On hold', 'outlinepay-for-woocommerce' ),
		);
		echo '<h2>' . esc_html__( 'Refunds and cancellations', 'outlinepay-for-woocommerce' ) . '</h2>';
		if ( Settings::refunds_permitted() ) {
			echo '<p>' . esc_html__( 'Refund card, foreign card, bank transfer, KakaoPay and NaverPay payments from the order screen with the Refund button. Partial refunds work for card payments only. Refund other payments in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Your payment provider has not turned on refunds for this store. Refund payments in your payment provider\'s dashboard. Shoppers cannot cancel paid orders until refunds are on.', 'outlinepay-for-woocommerce' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_shopper_cancel' );
		echo '<input type="hidden" name="action" value="outlinepay_shopper_cancel">';
		echo '<p><label><input type="checkbox" name="outlinepay_cancel_enabled" value="yes"' . checked( $setting['enabled'], true, false ) . '> ' . esc_html__( 'Let shoppers cancel their own paid orders', 'outlinepay-for-woocommerce' ) . '</label></p>';
		echo '<p>' . esc_html__( 'Shoppers see Cancel in My Account when the order has one of these statuses and nothing was refunded yet. Cancelling refunds the full payment and cancels the order.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Order statuses shoppers can cancel', 'outlinepay-for-woocommerce' ) . '</legend>';
		foreach ( Settings::CANCEL_STATUSES as $status ) {
			echo '<label style="margin-right:16px"><input type="checkbox" name="outlinepay_cancel_statuses[]" value="' . esc_attr( $status ) . '"' . checked( in_array( $status, $setting['statuses'], true ), true, false ) . '> ' . esc_html( $labels[ $status ] ) . '</label>';
		}
		echo '</fieldset>';
		submit_button( __( 'Save', 'outlinepay-for-woocommerce' ), 'secondary', 'outlinepay_save_cancel' );
		echo '</form>';
	}

	/**
	 * The method switches.
	 *
	 * @return void
	 */
	private static function render_methods() {
		$methods = Settings::methods();
		echo '<h2>' . esc_html__( 'Payment methods', 'outlinepay-for-woocommerce' ) . '</h2>';
		if ( empty( $methods ) ) {
			echo '<p>' . esc_html__( 'Your payment provider has no methods enabled for this store yet. Reload this page later.', 'outlinepay-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'These are the methods your payment provider has enabled for your store. You can hide any of them. To add a method, ask your payment provider.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'outlinepay_methods' );
		echo '<input type="hidden" name="action" value="outlinepay_methods">';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Method', 'outlinepay-for-woocommerce' ) . '</th><th>' . esc_html__( 'Show at checkout', 'outlinepay-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $methods as $row ) {
			$id = 'outlinepay_shown_' . $row['method'];
			echo '<tr><td><label for="' . esc_attr( $id ) . '">' . esc_html( '' !== $row['label_ko'] ? $row['label_ko'] : $row['method'] ) . '</label> <code>' . esc_html( $row['method'] ) . '</code></td>';
			echo '<td><input type="checkbox" id="' . esc_attr( $id ) . '" name="outlinepay_shown[]" value="' . esc_attr( $row['method'] ) . '"' . checked( Settings::shown( $row['method'] ), true, false ) . '></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save methods', 'outlinepay-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * The disconnect form.
	 *
	 * @param bool $show Whether to show it.
	 * @return void
	 */
	private static function render_disconnect_form( $show ) {
		if ( ! $show ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Disconnect', 'outlinepay-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Disconnecting turns off OutlinePay payments in this store. Reconnecting needs a new connect code from your payment provider.', 'outlinepay-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Disconnect this store from OutlinePay?', 'outlinepay-for-woocommerce' ) ) . '\');">';
		wp_nonce_field( 'outlinepay_disconnect' );
		echo '<input type="hidden" name="action" value="outlinepay_disconnect">';
		submit_button( __( 'Disconnect', 'outlinepay-for-woocommerce' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * One status row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function row( $label, $value ) {
		echo '<tr><th scope="row" style="width:200px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Display name of a provider key.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	private static function provider_name( $provider ) {
		return 'cookiepay' === $provider ? 'CookiePay' : $provider;
	}

	/**
	 * Result notice after a form post. Fixed messages only.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks one of the fixed messages below.
		$result   = isset( $_GET['outlinepay_result'] ) ? sanitize_key( wp_unslash( $_GET['outlinepay_result'] ) ) : '';
		$messages = array(
			'connected'              => array( 'success', __( 'Your store is connected.', 'outlinepay-for-woocommerce' ) ),
			'disconnected'           => array( 'success', __( 'Your store is disconnected.', 'outlinepay-for-woocommerce' ) ),
			'methods_saved'          => array( 'success', __( 'Payment methods saved.', 'outlinepay-for-woocommerce' ) ),
			'code_missing'           => array( 'error', __( 'Enter the connect code from your payment provider.', 'outlinepay-for-woocommerce' ) ),
			'code_invalid'           => array( 'error', __( 'This connect code is not valid. It may have expired or already been used. Ask your payment provider for a new code.', 'outlinepay-for-woocommerce' ) ),
			'site_refused'           => array( 'error', __( 'OutlinePay could not accept this store\'s address. The store must be reachable over HTTPS from the internet.', 'outlinepay-for-woocommerce' ) ),
			'rate_limited'           => array( 'error', __( 'Too many connect attempts. Wait a minute and try again.', 'outlinepay-for-woocommerce' ) ),
			'unreachable'            => array( 'error', __( 'OutlinePay could not be reached. Check that this server can make outgoing HTTPS requests, then try again.', 'outlinepay-for-woocommerce' ) ),
			'connect_failed'         => array( 'error', __( 'The store could not be connected. Try again in a moment.', 'outlinepay-for-woocommerce' ) ),
			'copy_cleared'           => array( 'success', __( 'The copied connection is removed from this site. Your live store keeps working.', 'outlinepay-for-woocommerce' ) ),
			'copy_none'              => array( 'error', __( 'This site no longer looks like a copy. Nothing changed.', 'outlinepay-for-woocommerce' ) ),
			'moved'                  => array( 'success', __( 'This address is saved as your store. Payments are back on.', 'outlinepay-for-woocommerce' ) ),
			'moved_callback_failed'  => array( 'warning', __( 'This address is saved as your store and payments are back on. OutlinePay could not be told the new address yet. Open this page again later to retry.', 'outlinepay-for-woocommerce' ) ),
			'self_check_done'        => array( 'success', __( 'The check finished. The results are below.', 'outlinepay-for-woocommerce' ) ),
			'self_check_email_saved' => array( 'success', __( 'Email setting saved.', 'outlinepay-for-woocommerce' ) ),
			'shopper_cancel_saved'   => array( 'success', __( 'Cancellation setting saved.', 'outlinepay-for-woocommerce' ) ),
		);
		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $messages[ $result ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $result ][1] ) . '</p></div>';
	}

	/**
	 * Maps a connect refusal to a notice key.
	 *
	 * @param Api_Error $error Refusal.
	 * @return string
	 */
	private static function connect_error( Api_Error $error ) {
		if ( 'authentication_error' === $error->get_error_code() ) {
			return 'code_invalid';
		}
		if ( 'invalid_request' === $error->get_error_code() ) {
			return 'site_refused';
		}
		if ( 429 === $error->get_http_status() ) {
			return 'rate_limited';
		}
		if ( 0 === $error->get_http_status() ) {
			return 'unreachable';
		}
		return 'connect_failed';
	}

	/**
	 * Capability check for a form post. The nonce is checked by each handler.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change OutlinePay settings.', 'outlinepay-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirects back to the page with a result.
	 *
	 * @param string $result Notice key.
	 * @return void
	 */
	private static function back( $result ) {
		wp_safe_redirect( self::url( array( 'outlinepay_result' => $result ) ) );
		exit;
	}
}
