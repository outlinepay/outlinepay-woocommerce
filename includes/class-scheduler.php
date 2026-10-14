<?php
/**
 * Background work on Action Scheduler: method refresh, reconciliation and the
 * one-time version report after an update.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled actions.
 */
final class Scheduler {

	const GROUP            = 'outlinepay';
	const HOOK_METHODS     = 'outlinepay_refresh_methods';
	const HOOK_RECONCILE   = 'outlinepay_reconcile';
	const HOOK_VERSIONS    = 'outlinepay_report_versions';
	const HOOK_METHODS_NOW = 'outlinepay_refresh_methods_now';

	const RECONCILE_INTERVAL = 900;
	const METHODS_INTERVAL   = 3600;
	const RECONCILE_LIMIT    = 50;
	const RECONCILE_WINDOW   = 172800;

	/**
	 * Hooks the actions.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_METHODS, array( self::class, 'refresh_methods' ) );
		add_action( self::HOOK_METHODS_NOW, array( self::class, 'refresh_methods' ) );
		add_action( self::HOOK_RECONCILE, array( self::class, 'reconcile' ) );
		add_action( self::HOOK_VERSIONS, array( self::class, 'report_versions' ) );
		add_action( 'init', array( self::class, 'maintain' ), 20 );
	}

	/**
	 * Keeps the recurring actions scheduled while connected, and queues the
	 * version report once after a plugin update. Runs in admin and cron only.
	 *
	 * @return void
	 */
	public static function maintain() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! Settings::is_connected() ) {
			return;
		}
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		self::schedule_recurring();
		if ( get_option( Settings::OPTION_VERSION ) !== OUTLINEPAY_VERSION && ! as_has_scheduled_action( self::HOOK_VERSIONS, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_VERSIONS, array(), self::GROUP );
		}
	}

	/**
	 * Schedules the recurring actions if missing.
	 *
	 * @return void
	 */
	public static function schedule_recurring() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK_RECONCILE, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::RECONCILE_INTERVAL, self::RECONCILE_INTERVAL, self::HOOK_RECONCILE, array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( self::HOOK_METHODS, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::METHODS_INTERVAL, self::METHODS_INTERVAL, self::HOOK_METHODS, array(), self::GROUP );
		}
	}

	/**
	 * Removes every action of ours.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array( self::HOOK_METHODS, self::HOOK_METHODS_NOW, self::HOOK_RECONCILE, self::HOOK_VERSIONS ) as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}

	/**
	 * Asks for a method refresh soon, without blocking the current request.
	 *
	 * @return void
	 */
	public static function queue_methods_refresh() {
		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::HOOK_METHODS_NOW, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_METHODS_NOW, array(), self::GROUP );
		}
	}

	/**
	 * Fetches the method list. Keeps the cached list when the server cannot answer.
	 *
	 * @return bool True when the list was refreshed.
	 */
	public static function refresh_methods() {
		if ( ! Settings::is_connected() ) {
			return false;
		}
		try {
			Settings::save_methods( ( new Api_Client() )->get_methods() );
			return true;
		} catch ( Api_Error $error ) {
			return false;
		}
	}

	/**
	 * Re-reads recent unsettled orders of ours and applies what the server says.
	 * Catches payments whose callback and return both went missing.
	 *
	 * @return void
	 */
	public static function reconcile() {
		if ( ! Settings::is_connected() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'payment_method' => Settings::gateway_ids(),
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - self::RECONCILE_WINDOW ),
				'limit'          => self::RECONCILE_LIMIT,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$client = new Api_Client();
		$count  = 0;
		foreach ( $orders as $order ) {
			if ( ! Order_Sync::is_ours( $order ) ) {
				continue;
			}
			try {
				$best = Order_Sync::pick( $client->list_payments( $order->get_id() ) );
			} catch ( Api_Error $error ) {
				if ( $error->is_transient() || 401 === $error->get_http_status() ) {
					break;
				}
				continue;
			}
			if ( $best ) {
				Order_Sync::apply( $order, $best );
				++$count;
			}
		}
		Logger::info( 'Reconcile run finished', array( 'count' => $count ) );
	}

	/**
	 * Reports host versions once after the plugin version changed.
	 *
	 * @return void
	 */
	public static function report_versions() {
		if ( ! Settings::is_connected() ) {
			return;
		}
		try {
			( new Api_Client() )->update_installation( array( 'platform_versions' => Settings::platform_versions() ) );
			update_option( Settings::OPTION_VERSION, OUTLINEPAY_VERSION, false );
		} catch ( Api_Error $error ) {
			// Logged by the client. Retried on the next admin or cron request.
			return;
		}
	}
}
