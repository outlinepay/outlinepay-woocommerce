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
	const HOOK_SYNC_ORDER  = 'outlinepay_sync_order';
	const OPTION_CURSOR    = 'outlinepay_reconcile_cursor';

	const RECONCILE_INTERVAL = 900;
	const METHODS_INTERVAL   = 3600;
	const RECONCILE_LIMIT    = 50;
	const PENDING_WINDOW     = 604800;
	const DEPOSIT_GRACE      = 86400;

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
		add_action( self::HOOK_SYNC_ORDER, array( self::class, 'sync_order_id' ) );
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
		foreach ( array( self::HOOK_METHODS, self::HOOK_METHODS_NOW, self::HOOK_RECONCILE, self::HOOK_VERSIONS, self::HOOK_SYNC_ORDER ) as $hook ) {
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
	 * Walks the unsettled orders that carry OutlinePay attempts, 50 per run,
	 * continuing where the last run stopped, and applies what the server says.
	 * Catches payments whose callback and return both went missing, including a
	 * virtual account deposited days later.
	 *
	 * @return void
	 */
	public static function reconcile() {
		if ( ! Settings::is_connected() ) {
			return;
		}
		$cursor = self::read_cursor( get_option( self::OPTION_CURSOR, array() ) );
		$orders = wc_get_orders(
			array(
				'status'       => array( 'pending', 'on-hold' ),
				'date_created' => '>=' . $cursor['t'],
				'limit'        => self::RECONCILE_LIMIT + count( $cursor['ids'] ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to 50 orders per run, every 15 minutes.
					'relation' => 'AND',
					array(
						'key'     => Order_Sync::META_ATTEMPTS,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Order_Sync::META_SETTLED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$rows = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$rows[]  = array(
				'id'    => $order->get_id(),
				't'     => $created ? $created->getTimestamp() : 0,
				'order' => $order,
			);
		}
		$batch = self::next_batch( $rows, $cursor, self::RECONCILE_LIMIT );

		$client = new Api_Client();
		$count  = 0;
		$done   = array();
		$now    = time();
		foreach ( $batch['rows'] as $row ) {
			$order = $row['order'];
			if ( self::should_reconcile( self::facts( $order ), $now ) ) {
				try {
					if ( self::sync_order( $order, $client ) ) {
						++$count;
					}
				} catch ( Api_Error $error ) {
					if ( $error->is_transient() || 401 === $error->get_http_status() ) {
						break;
					}
				}
			}
			$done[] = $row;
		}

		update_option( self::OPTION_CURSOR, self::advance_cursor( $cursor, $done, $batch['complete'] && count( $done ) === count( $batch['rows'] ) ), false );
		Logger::info( 'Reconcile run finished', array( 'count' => $count ) );
	}

	/**
	 * Reads a stored cursor.
	 *
	 * @param mixed $stored Option value.
	 * @return array array( 't' => unix, 'ids' => int[] processed at that second ).
	 */
	public static function read_cursor( $stored ) {
		$t   = is_array( $stored ) && isset( $stored['t'] ) ? (int) $stored['t'] : 0;
		$ids = is_array( $stored ) && isset( $stored['ids'] ) && is_array( $stored['ids'] ) ? array_map( 'intval', $stored['ids'] ) : array();
		return array(
			't'   => max( 0, $t ),
			'ids' => $ids,
		);
	}

	/**
	 * From rows fetched at or after the cursor second (oldest first), the next
	 * batch: rows already done at the cursor second are skipped. Pure.
	 *
	 * @param array $rows   Rows with id and t, oldest first, at most $limit plus the cursor's ids.
	 * @param array $cursor Cursor.
	 * @param int   $limit  Batch size.
	 * @return array array( 'rows' => batch, 'complete' => true when this reaches the end of the list ).
	 */
	public static function next_batch( array $rows, array $cursor, $limit ) {
		$fresh = array();
		foreach ( $rows as $row ) {
			if ( $row['t'] === $cursor['t'] && in_array( $row['id'], $cursor['ids'], true ) ) {
				continue;
			}
			$fresh[] = $row;
		}
		return array(
			'rows'     => array_slice( $fresh, 0, $limit ),
			// The query asked for $limit plus the skipped ids. Fewer back means the end.
			'complete' => count( $rows ) < $limit + count( $cursor['ids'] ) && count( $fresh ) <= $limit,
		);
	}

	/**
	 * The cursor after a run. Starts over from the oldest order once a run
	 * reached the end of the list. Pure.
	 *
	 * @param array $cursor   Cursor before the run.
	 * @param array $done     Rows processed, oldest first.
	 * @param bool  $complete Whether the run reached the end of the list.
	 * @return array
	 */
	public static function advance_cursor( array $cursor, array $done, $complete ) {
		if ( $complete ) {
			return array(
				't'   => 0,
				'ids' => array(),
			);
		}
		if ( empty( $done ) ) {
			return $cursor;
		}
		$last = end( $done );
		$ids  = $last['t'] === $cursor['t'] ? $cursor['ids'] : array();
		foreach ( $done as $row ) {
			if ( $row['t'] === $last['t'] ) {
				$ids[] = $row['id'];
			}
		}
		return array(
			't'   => $last['t'],
			'ids' => array_values( array_unique( $ids ) ),
		);
	}

	/**
	 * What should_reconcile needs to know about an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private static function facts( $order ) {
		$created = $order->get_date_created();
		$account = $order->get_meta( Order_Sync::META_VA );
		return array(
			'status'   => $order->get_status(),
			'hold'     => (string) $order->get_meta( Order_Sync::META_HOLD ),
			'settled'  => (string) $order->get_meta( Order_Sync::META_SETTLED ),
			'created'  => $created ? $created->getTimestamp() : 0,
			'deadline' => is_array( $account ) && ! empty( $account['deadline'] ) ? (string) $account['deadline'] : '',
		);
	}

	/**
	 * Whether a run should ask the server about this order. Pure.
	 *
	 * A virtual account waits until its deadline plus a day. Anything else
	 * unsettled is followed for a week after it was placed. Orders already put
	 * on hold for a mismatch, a review, a double payment or a foreign payment
	 * wait for the merchant and are skipped.
	 *
	 * @param array $facts status, hold, settled, created (unix), deadline (ISO 8601).
	 * @param int   $now   Unix time.
	 * @return bool
	 */
	public static function should_reconcile( array $facts, $now ) {
		if ( '' !== $facts['settled'] || ! in_array( $facts['status'], array( 'pending', 'on-hold' ), true ) ) {
			return false;
		}
		if ( 'on-hold' === $facts['status'] && 'deposit' === $facts['hold'] && '' !== $facts['deadline'] ) {
			$deadline = strtotime( $facts['deadline'] );
			if ( false !== $deadline ) {
				return $now <= $deadline + self::DEPOSIT_GRACE;
			}
		}
		return $now - (int) $facts['created'] <= self::PENDING_WINDOW;
	}

	/**
	 * Reads every attempt of the order and applies the one that speaks for it.
	 *
	 * @param \WC_Order       $order  Order.
	 * @param Api_Client|null $client Client.
	 * @return bool True when a payment was applied.
	 * @throws Api_Error When the server cannot answer.
	 */
	public static function sync_order( $order, $client = null ) {
		$client = $client ? $client : new Api_Client();
		$best   = Order_Sync::pick( $client->list_payments( $order->get_id() ) );
		if ( ! $best ) {
			return false;
		}
		Order_Sync::apply( $order, $best );
		return true;
	}

	/**
	 * Action handler: sync one order by id.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function sync_order_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! Settings::is_connected() || ! Order_Sync::is_ours( $order ) ) {
			return;
		}
		try {
			self::sync_order( $order );
		} catch ( Api_Error $error ) {
			// Logged by the client. The reconcile run tries again.
			return;
		}
	}

	/**
	 * Queues a sync of one order after the current request.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function queue_order_sync( $order_id ) {
		$args = array( (int) $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::HOOK_SYNC_ORDER, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_SYNC_ORDER, $args, self::GROUP );
		}
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
