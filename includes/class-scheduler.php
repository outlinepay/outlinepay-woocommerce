<?php
/**
 * Background work on Action Scheduler: method refresh, reconciliation, the
 * daily self-check and the one-time version report after an update.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled actions.
 */
final class Scheduler {

	const GROUP             = 'outlinepay';
	const HOOK_METHODS      = 'outlinepay_refresh_methods';
	const HOOK_RECONCILE    = 'outlinepay_reconcile';
	const HOOK_VERSIONS     = 'outlinepay_report_versions';
	const HOOK_METHODS_NOW  = 'outlinepay_refresh_methods_now';
	const HOOK_SWITCH_CHECK = 'outlinepay_switch_check';
	const HOOK_SELF_CHECK   = 'outlinepay_daily_self_check';
	const OPTION_CURSOR     = 'outlinepay_reconcile_cursor';
	const OPTION_FAILURES   = 'outlinepay_reconcile_failures';
	const OPTION_DAILY_AT   = 'outlinepay_self_check_offset';

	const RECONCILE_INTERVAL = 900;
	const METHODS_INTERVAL   = 3600;
	const RECONCILE_LIMIT    = 50;
	const PENDING_WINDOW     = 604800;
	const DEPOSIT_GRACE      = 86400;
	const QUERY_WINDOW       = 2592000;
	const MAX_FAILURES       = 3;
	const DAY                = 86400;

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
		add_action( self::HOOK_SELF_CHECK, array( Self_Check::class, 'run_daily' ) );
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
		if ( ! as_has_scheduled_action( self::HOOK_SELF_CHECK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( self::next_daily_run( time(), self::daily_offset() ), self::DAY, self::HOOK_SELF_CHECK, array(), self::GROUP );
		}
	}

	/**
	 * Seconds after midnight UTC this store runs its daily self-check. Picked
	 * at random once and kept, so stores do not all check at the same moment.
	 *
	 * @return int
	 */
	public static function daily_offset() {
		$offset = get_option( self::OPTION_DAILY_AT, null );
		if ( is_numeric( $offset ) && (int) $offset >= 0 && (int) $offset < self::DAY ) {
			return (int) $offset;
		}
		$offset = wp_rand( 0, self::DAY - 1 );
		update_option( self::OPTION_DAILY_AT, $offset, false );
		return $offset;
	}

	/**
	 * The next time of day $offset (seconds after midnight UTC) after $now. Pure.
	 *
	 * @param int $now    Unix time.
	 * @param int $offset Seconds after midnight UTC.
	 * @return int
	 */
	public static function next_daily_run( $now, $offset ) {
		$run = $now - ( $now % self::DAY ) + $offset;
		return $run > $now ? $run : $run + self::DAY;
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
		foreach ( self::hooks() as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}

	/**
	 * Every hook this plugin schedules.
	 *
	 * @return string[]
	 */
	public static function hooks() {
		return array( self::HOOK_METHODS, self::HOOK_METHODS_NOW, self::HOOK_RECONCILE, self::HOOK_VERSIONS, self::HOOK_SWITCH_CHECK, self::HOOK_SELF_CHECK );
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
	 * virtual account deposited days later. Then follows refunds still waiting
	 * for the provider (see Refunds::follow_pending).
	 *
	 * @return void
	 */
	public static function reconcile() {
		if ( ! Settings::is_connected() ) {
			return;
		}
		$now    = time();
		$cursor = self::clamp_cursor( self::read_cursor( get_option( self::OPTION_CURSOR, array() ) ), $now );
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

		$client   = new Api_Client();
		$count    = 0;
		$done     = array();
		$failures = get_option( self::OPTION_FAILURES, array() );
		$failures = is_array( $failures ) ? $failures : array();
		foreach ( $batch['rows'] as $row ) {
			$order = $row['order'];
			if ( self::should_reconcile( self::facts( $order ), $now ) ) {
				try {
					if ( self::sync_order( $order, $client ) ) {
						++$count;
					}
					unset( $failures[ $row['id'] ] );
				} catch ( Api_Error $error ) {
					if ( 0 === $error->get_http_status() || 401 === $error->get_http_status() ) {
						// OutlinePay unreachable or the token refused: stop, keep the place.
						break;
					}
					$result   = self::note_failure( $failures, $row['id'] );
					$failures = $result['failures'];
					if ( ! $result['step_past'] ) {
						// Try this order again next run, a few times at most.
						break;
					}
					Logger::warning(
						'Reconcile stepped past an order after repeated errors',
						array(
							'order_id' => $row['id'],
							'code'     => $error->get_error_code(),
						)
					);
				}
			}
			$done[] = $row;
		}

		update_option( self::OPTION_FAILURES, array_slice( $failures, -100, null, true ), false );
		update_option( self::OPTION_CURSOR, self::advance_cursor( $cursor, $done, $batch['complete'] && count( $done ) === count( $batch['rows'] ) ), false );
		Logger::info( 'Reconcile run finished', array( 'count' => $count ) );

		// Refunds the provider had not confirmed when they were asked for.
		Refunds::follow_pending( $client );
	}

	/**
	 * Orders placed before the query window are never followed (see
	 * should_reconcile), so the walk starts no earlier than the window. Pure.
	 *
	 * @param array $cursor Cursor.
	 * @param int   $now    Unix time.
	 * @return array
	 */
	public static function clamp_cursor( array $cursor, $now ) {
		$floor = $now - self::QUERY_WINDOW;
		if ( $cursor['t'] >= $floor ) {
			return $cursor;
		}
		return array(
			't'   => $floor,
			'ids' => array(),
		);
	}

	/**
	 * Counts a server error for one order. After MAX_FAILURES the run steps
	 * past it and its count is cleared. Pure.
	 *
	 * @param array $failures Counts by order id.
	 * @param int   $order_id Order id.
	 * @return array array( 'failures' => counts, 'step_past' => bool ).
	 */
	public static function note_failure( array $failures, $order_id ) {
		$count = isset( $failures[ $order_id ] ) ? (int) $failures[ $order_id ] + 1 : 1;
		if ( $count >= self::MAX_FAILURES ) {
			unset( $failures[ $order_id ] );
			return array(
				'failures'  => $failures,
				'step_past' => true,
			);
		}
		$failures[ $order_id ] = $count;
		return array(
			'failures'  => $failures,
			'step_past' => false,
		);
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
	 * Queues the background switch check for an order another gateway just
	 * marked paid (see Switch_Guard::run_background_check).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function queue_switch_check( $order_id ) {
		$args = array( (int) $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::HOOK_SWITCH_CHECK, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_SWITCH_CHECK, $args, self::GROUP );
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
