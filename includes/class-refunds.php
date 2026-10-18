<?php
/**
 * Refunds: the Refund button in the order screen, and refunds made elsewhere
 * (the provider's dashboard, a refund confirmed later, a shopper cancel)
 * mirrored onto the order.
 *
 * OutlinePay decides every refund. This class asks, records the answer and
 * never sends money on its own: a mirrored refund is a WooCommerce record of
 * money that already went back.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Store refunds and their mirror.
 */
final class Refunds {

	/** On a WooCommerce refund: the OutlinePay refund id (pref_...). */
	const META_REFUND_ID = '_outlinepay_refund_id';

	/** On a WooCommerce refund: the OutlinePay refund status when it was recorded. */
	const META_REFUND_STATUS = '_outlinepay_refund_status';

	/** On the order: refund ids already handled (mirrored, noted or recorded). */
	const META_DONE = '_outlinepay_refunds_done';

	/** On the order: refunds waiting for the provider, pref id => array( amount, by ). */
	const META_PENDING = '_outlinepay_pending_refunds';

	/** On the order: refund requests that got no answer, refund_id => amount. */
	const META_UNANSWERED = '_outlinepay_unanswered_refunds';

	/** On the order: store refunds that were unanswered or pending and are now on the order. */
	const META_ANSWERED = '_outlinepay_answered_refunds';

	/** How long an answered refund refuses a repeat of the same amount, in seconds. */
	const ANSWERED_WINDOW = 86400;

	/** Refund id prefix for a shopper cancel. */
	const SHOPPER_PREFIX = 'shopper-cancel-';

	/** Most ids kept per list. */
	const MAX_IDS = 50;

	/** Orders with pending refunds followed per reconcile run. */
	const FOLLOW_LIMIT = 20;

	/**
	 * Status to give an order when one of our mirrors fully refunds it, by order id.
	 *
	 * @var array
	 */
	private static $status_after_full = array();

	/**
	 * Creates the API client. Test seam.
	 *
	 * @var callable|null
	 */
	private static $client_factory = null;

	/**
	 * Hooks the filter that keeps a shopper-cancelled order Cancelled.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_order_fully_refunded_status', array( self::class, 'fully_refunded_status' ), 10, 2 );
	}

	/**
	 * Test seam: replaces the API client. Pass null to restore.
	 *
	 * @param callable|null $factory Returns an Api_Client.
	 * @return void
	 */
	public static function set_client_factory( $factory ) {
		self::$client_factory = $factory;
	}

	/**
	 * The API client.
	 *
	 * @return Api_Client
	 */
	public static function client() {
		return self::$client_factory ? call_user_func( self::$client_factory ) : new Api_Client();
	}

	/**
	 * The gateway's refund. WooCommerce has already saved its refund row and
	 * deletes it again when this returns a WP_Error.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount to refund.
	 * @return true|\WP_Error
	 */
	public static function process( $order_id, $amount ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return self::error( __( 'This order could not be found.', 'outlinepay-for-woocommerce' ) );
		}
		if ( ! Settings::is_connected() ) {
			return self::error( __( 'This store is not connected to OutlinePay. Refund this payment in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' ) );
		}
		$krw = Amount::to_krw( $amount );
		if ( null === $krw ) {
			return self::error( __( 'Refund a whole number of won, more than zero.', 'outlinepay-for-woocommerce' ) );
		}
		$payment_id = $order->get_meta( Order_Sync::META_PAYMENT_ID );
		if ( ! Api_Client::valid_payment_id( $payment_id ) || '' !== (string) $order->get_meta( Order_Sync::META_SETTLED ) ) {
			return self::error( __( 'This order has no OutlinePay payment that can be refunded here. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' ) );
		}
		if ( ! Order_Lock::acquire( $order->get_id() ) ) {
			return self::error( __( 'This order is being updated. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
		}

		try {
			$wc_refund = self::find_new_refund( $order, $krw );
			if ( ! $wc_refund ) {
				Logger::warning( 'Refund row not found', array( 'order_id' => $order->get_id() ) );
				return self::error( __( 'The refund could not be started. Reload the order and try again.', 'outlinepay-for-woocommerce' ) );
			}
			// A request that got no answer is sent again under its first id, so
			// OutlinePay answers the refund it already made instead of a second one.
			$refund_id = self::unanswered_id( $order, $krw );
			if ( null === $refund_id && self::take_answered( $order, $krw ) ) {
				$order->save();
				return self::error( __( 'This refund already went through and is on this order. Reload the page to see it.', 'outlinepay-for-woocommerce' ) );
			}
			if ( null === $refund_id ) {
				$refund_id = (string) $wc_refund->get_id();
			}

			try {
				$refund = self::client()->create_refund( $payment_id, $krw, $refund_id, 'store' );
			} catch ( Api_Error $error ) {
				return self::refused( $order, $error, $refund_id, $krw );
			}

			if ( ! self::readable( $refund ) ) {
				// The refund may exist. Keep its id, so a retry gets OutlinePay's answer for it.
				Logger::error( 'Refund answer unreadable', array( 'order_id' => $order->get_id() ) );
				self::note_unanswered( $order, $refund_id, $krw );
				$order->save();
				return self::error( __( 'OutlinePay answered, but the answer could not be read. Check the order notes in a few minutes before you try again.', 'outlinepay-for-woocommerce' ) );
			}
			self::forget_unanswered( $order, $refund_id );

			$context = array(
				'order_id'   => $order->get_id(),
				'payment_id' => $payment_id,
				'refund_id'  => $refund['id'],
				'status'     => $refund['status'],
			);
			Logger::info( 'Refund answered', $context );

			if ( in_array( $refund['id'], self::done_ids( $order ), true ) ) {
				// A retry of a request that got no answer, and the answer is a refund
				// this order already shows.
				$order->save();
				return self::error( __( 'This refund is already on this order. Reload the order to see it.', 'outlinepay-for-woocommerce' ) );
			}

			switch ( $refund['status'] ) {
				case 'succeeded':
					$wc_refund->update_meta_data( self::META_REFUND_ID, $refund['id'] );
					$wc_refund->update_meta_data( self::META_REFUND_STATUS, 'succeeded' );
					$wc_refund->save();
					self::mark_done( $order, $refund['id'] );
					$order->add_order_note(
						self::note(
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
							sprintf( __( 'Refunded %1$s through OutlinePay (refund %2$s).', 'outlinepay-for-woocommerce' ), self::won( $krw ), $refund['id'] ),
							$refund
						)
					);
					$order->save();
					return true;

				case 'failed':
					self::mark_done( $order, $refund['id'] );
					$order->add_order_note(
						self::note(
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
							sprintf( __( 'The payment provider refused a refund of %1$s (refund %2$s). Nothing was refunded.', 'outlinepay-for-woocommerce' ), self::won( $krw ), $refund['id'] ),
							$refund
						)
					);
					$order->save();
					return self::error( self::failure_sentence( isset( $refund['failure_code'] ) ? $refund['failure_code'] : null ) );

				default:
					// pending or unknown: the provider has not confirmed it. WooCommerce
					// drops its refund row; the confirmation adds the refund later.
					self::remember_pending( $order, $refund['id'], $krw, 'store' );
					$order->add_order_note(
						self::note(
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
							sprintf( __( 'OutlinePay is confirming a refund of %1$s with the payment provider (refund %2$s). It will be added to this order once confirmed.', 'outlinepay-for-woocommerce' ), self::won( $krw ), $refund['id'] ),
							$refund
						)
					);
					$order->save();
					return self::error( __( 'OutlinePay is confirming this refund with the payment provider. It will appear on this order automatically once confirmed.', 'outlinepay-for-woocommerce' ) );
			}
		} finally {
			Order_Lock::release( $order->get_id() );
		}
	}

	/**
	 * The refund WooCommerce just saved for this request: the newest refund on
	 * the order that carries no OutlinePay refund id, for this amount.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $krw   Amount in whole KRW.
	 * @return object|null WC_Order_Refund.
	 */
	public static function find_new_refund( $order, $krw ) {
		$found = null;
		foreach ( $order->get_refunds() as $refund ) {
			if ( '' !== (string) $refund->get_meta( self::META_REFUND_ID ) ) {
				continue;
			}
			if ( Amount::to_krw( $refund->get_amount() ) !== $krw ) {
				continue;
			}
			if ( null === $found || $refund->get_id() > $found->get_id() ) {
				$found = $refund;
			}
		}
		return $found;
	}

	/**
	 * Maps a refusal to the error WooCommerce shows. Nothing is recorded,
	 * except that a request with no answer is remembered so a retry reuses its id.
	 *
	 * @param \WC_Order $order     Order.
	 * @param Api_Error $error     Refusal.
	 * @param string    $refund_id Refund id sent.
	 * @param int       $krw       Amount sent.
	 * @return \WP_Error
	 */
	private static function refused( $order, Api_Error $error, $refund_id, $krw ) {
		Logger::warning(
			'Refund refused',
			array(
				'order_id'    => $order->get_id(),
				'code'        => $error->get_error_code(),
				'http_status' => $error->get_http_status(),
			)
		);
		if ( $error->is_transient() ) {
			self::note_unanswered( $order, $refund_id, $krw );
			$order->save();
		} else {
			self::forget_unanswered( $order, $refund_id );
			$order->save();
		}
		return self::error( self::refusal_sentence( $error ) );
	}

	/**
	 * Remembers a store refund with no answer and tells the merchant on the
	 * order. The caller saves.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $refund_id Refund id sent.
	 * @param int       $krw       Amount sent.
	 * @return void
	 */
	private static function note_unanswered( $order, $refund_id, $krw ) {
		self::remember_unanswered( $order, $refund_id, $krw );
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount such as 5,000 won. */
				__( 'OutlinePay did not answer a refund of %s. It may or may not have gone through. Do not refund this amount in your payment provider\'s dashboard. If it went through, it will appear on this order automatically, usually within a few minutes. Reload this order to check. Refunding the same amount again is safe: OutlinePay will not refund twice.', 'outlinepay-for-woocommerce' ),
				self::won( $krw )
			)
		);
	}

	/**
	 * One plain sentence per refusal, for the store manager.
	 *
	 * @param Api_Error $error Refusal.
	 * @return string
	 */
	public static function refusal_sentence( Api_Error $error ) {
		if ( $error->is_transient() ) {
			return __( 'OutlinePay did not answer. The refund may or may not have gone through. Wait a minute and check the order notes. To try again, refund the same amount.', 'outlinepay-for-woocommerce' );
		}
		switch ( $error->get_error_code() ) {
			case 'refunds_paused':
				return __( 'Refunds are paused for this store right now. Try again later, or refund in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'payment_not_refundable':
				return __( 'OutlinePay cannot refund this payment. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'refund_not_supported':
				return __( 'This payment method cannot be refunded from WooCommerce. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'partial_refund_not_supported':
				return __( 'Partial refunds work for card payments only. Refund the full amount, or refund the rest in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'refund_exceeds_remaining':
				return __( 'This is more than what is left to refund.', 'outlinepay-for-woocommerce' );
			case 'refund_cap_reached':
				return __( 'Today\'s refund limit for this store is reached. Ask your payment provider.', 'outlinepay-for-woocommerce' );
			case 'shopper_cancel_not_full':
				return __( 'A shopper cancel must refund everything that is left on the payment.', 'outlinepay-for-woocommerce' );
			case 'refund_id_reused':
				return __( 'OutlinePay already has a different refund with this refund\'s number. Reload the order and check its refunds before you try again.', 'outlinepay-for-woocommerce' );
			case 'permission_denied':
				return __( 'Your payment provider has not turned on refunds for this store. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'not_connected':
			case 'authentication_error':
				return __( 'This store is not connected to OutlinePay. Refund this payment in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'not_found':
				return __( 'OutlinePay does not know this payment. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
			case 'invalid_request':
				return __( 'OutlinePay could not accept this refund. Refund a whole number of won.', 'outlinepay-for-woocommerce' );
			default:
				return __( 'OutlinePay did not accept this refund. Nothing was refunded. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
		}
	}

	/**
	 * Sentence for a refund the provider refused.
	 *
	 * @param mixed $failure_code not_cancellable, invalid_amount or null.
	 * @return string
	 */
	public static function failure_sentence( $failure_code ) {
		if ( 'invalid_amount' === $failure_code ) {
			return __( 'The payment provider did not accept this amount. Nothing was refunded.', 'outlinepay-for-woocommerce' );
		}
		return __( 'The payment provider refused this refund. Nothing was refunded. Refund it in your payment provider\'s dashboard.', 'outlinepay-for-woocommerce' );
	}

	/**
	 * Mirrors refunds onto the order. The caller holds the order lock and has
	 * read every refund back from OutlinePay. Idempotent: a refund already
	 * handled is skipped.
	 *
	 * Only refunds of the payment that paid the order become WooCommerce
	 * refunds. A refund of any other payment (a duplicate payment, a payment
	 * on an order paid another way) is money the order never counted, so it
	 * gets an order note only.
	 *
	 * @param \WC_Order $order   Order, read fresh.
	 * @param array     $payment The payment the refunds belong to.
	 * @param array     $refunds Refund objects.
	 * @param bool      $is_order_payment True when this payment paid the order.
	 * @return int How many refunds changed the order.
	 */
	public static function mirror( $order, array $payment, array $refunds, $is_order_payment ) {
		$changed = 0;
		foreach ( $refunds as $refund ) {
			if ( ! self::readable( $refund ) || ! isset( $refund['payment'] ) || $refund['payment'] !== $payment['id'] ) {
				continue;
			}
			$id = $refund['id'];
			if ( in_array( $id, self::done_ids( $order ), true ) ) {
				self::forget_pending( $order, $id );
				continue;
			}
			$pending = self::pending( $order );
			$amount  = $refund['amount'];

			if ( 'failed' === $refund['status'] ) {
				if ( isset( $pending[ $id ] ) || self::is_unanswered( $order, self::field( $refund, 'refund_id' ) ) ) {
					$order->add_order_note(
						self::note(
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
							sprintf( __( 'The payment provider did not complete the refund of %1$s (refund %2$s). Nothing was refunded.', 'outlinepay-for-woocommerce' ), self::won( $amount ), $id ),
							$refund
						)
					);
					++$changed;
				}
				self::mark_done( $order, $id );
				self::forget_pending( $order, $id );
				self::forget_unanswered( $order, self::field( $refund, 'refund_id' ) );
				$order->save();
				continue;
			}
			if ( 'succeeded' !== $refund['status'] ) {
				continue;
			}

			if ( ! $is_order_payment ) {
				$order->add_order_note(
					self::note(
						'duplicate' === self::field( $refund, 'source' )
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay payment id. */
							? sprintf( __( 'OutlinePay cancelled the duplicate payment %2$s automatically. %1$s went back to the shopper. This order\'s own payment is unchanged.', 'outlinepay-for-woocommerce' ), self::won( $amount ), $payment['id'] )
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay payment id. */
							: sprintf( __( '%1$s of OutlinePay payment %2$s was refunded. That payment did not pay this order, so no refund is added to it.', 'outlinepay-for-woocommerce' ), self::won( $amount ), $payment['id'] ),
						$refund
					)
				);
				self::mark_done( $order, $id );
				self::forget_pending( $order, $id );
				$order->save();
				++$changed;
				continue;
			}

			$by_shopper = self::by_shopper( $refund, $pending );
			if ( self::add_wc_refund( $order, $refund, self::reason( $refund, $by_shopper ), $by_shopper ) ) {
				++$changed;
			}
			$order = self::reread( $order );
		}
		return $changed;
	}

	/**
	 * Records a succeeded refund as a WooCommerce refund without sending money,
	 * then marks it handled. For a shopper cancel the order ends Cancelled.
	 *
	 * @param \WC_Order $order      Order.
	 * @param array     $refund     Refund object, status succeeded.
	 * @param string    $reason     Reason shown on the refund.
	 * @param bool      $by_shopper Whether the shopper cancelled.
	 * @param string    $note       Order note to add instead of the confirmation note.
	 * @return bool True when a WooCommerce refund was added.
	 */
	public static function add_wc_refund( $order, array $refund, $reason, $by_shopper, $note = '' ) {
		$order_id = $order->get_id();
		$id       = $refund['id'];
		$amount   = $refund['amount'];

		self::$status_after_full[ $order_id ] = $by_shopper || $order->has_status( 'cancelled' ) ? 'cancelled' : null;
		try {
			$wc_refund = wc_create_refund(
				array(
					'amount'         => $amount,
					'reason'         => $reason,
					'order_id'       => $order_id,
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
		} finally {
			unset( self::$status_after_full[ $order_id ] );
		}

		$order = self::reread( $order );
		if ( is_wp_error( $wc_refund ) || ! is_object( $wc_refund ) ) {
			// Most often the order already shows this money refunded, recorded by hand.
			Logger::warning(
				'Confirmed refund not added to the order',
				array(
					'order_id'  => $order_id,
					'refund_id' => $id,
				)
			);
			$order->add_order_note(
				self::note(
					/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
					sprintf( __( 'OutlinePay confirmed a refund of %1$s (refund %2$s), but WooCommerce could not add it to this order. The order may already show this refund. Check the order\'s refunds.', 'outlinepay-for-woocommerce' ), self::won( $amount ), $id ),
					$refund
				)
			);
			self::mark_done( $order, $id );
			self::forget_pending( $order, $id );
			$order->save();
			return false;
		}

		$wc_refund->update_meta_data( self::META_REFUND_ID, $id );
		$wc_refund->update_meta_data( self::META_REFUND_STATUS, 'succeeded' );
		$wc_refund->save();

		$was_waiting = isset( self::pending( $order )[ $id ] ) || self::is_unanswered( $order, self::field( $refund, 'refund_id' ) );
		if ( $was_waiting && ! $by_shopper ) {
			// The merchant may still be on a page from before this landed, and was
			// told a repeat is safe. The next refund of this amount is refused once.
			self::remember_answered( $order, $id, $amount );
		}
		self::mark_done( $order, $id );
		self::forget_pending( $order, $id );
		self::forget_unanswered( $order, self::field( $refund, 'refund_id' ) );
		$order->add_order_note(
			self::note(
				'' !== $note
					? $note
					/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id, 3: reason. */
					: sprintf( __( 'OutlinePay confirmed a refund of %1$s (refund %2$s): %3$s.', 'outlinepay-for-woocommerce' ), self::won( $amount ), $id, $reason ),
				$refund
			)
		);
		if ( $by_shopper && ! $order->has_status( 'cancelled' ) ) {
			// WooCommerce sets the status only on a full refund, and other plugins may filter it.
			$order->update_status( 'cancelled', Shopper_Cancel::core_cancel_note() );
		}
		$order->save();
		Logger::info(
			'Refund added to the order',
			array(
				'order_id'  => $order_id,
				'refund_id' => $id,
			)
		);
		return true;
	}

	/**
	 * Filter: keeps an order Cancelled (or makes it Cancelled, for a shopper
	 * cancel) when one of our mirrors fully refunds it.
	 *
	 * @param string|false $status   Status WooCommerce would set.
	 * @param int          $order_id Order id.
	 * @return string|false
	 */
	public static function fully_refunded_status( $status, $order_id ) {
		if ( isset( self::$status_after_full[ (int) $order_id ] ) ) {
			return self::$status_after_full[ (int) $order_id ];
		}
		return $status;
	}

	/**
	 * Reads back every pending refund of a few orders and applies them. Catches
	 * a confirmation whose callback never arrived. Runs with reconcile.
	 *
	 * @param Api_Client|null $client Client.
	 * @return int Orders followed.
	 */
	public static function follow_pending( $client = null ) {
		$client = $client ? $client : self::client();
		$orders = wc_get_orders(
			array(
				'limit'      => self::FOLLOW_LIMIT,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to 20 orders per run, every 15 minutes.
					array(
						'key'     => self::META_PENDING,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$count  = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || array() === self::pending( $order ) ) {
				continue;
			}
			try {
				self::sync_pending( $order, $client );
				++$count;
			} catch ( Api_Error $error ) {
				if ( 0 === $error->get_http_status() || 401 === $error->get_http_status() ) {
					break;
				}
			}
		}
		return $count;
	}

	/**
	 * Reads the order's payment and its pending refunds back and applies them.
	 *
	 * @param \WC_Order  $order  Order.
	 * @param Api_Client $client Client.
	 * @return string The sync decision.
	 * @throws Api_Error When OutlinePay cannot answer.
	 */
	public static function sync_pending( $order, $client ) {
		$payment = $client->get_payment( $order->get_meta( Order_Sync::META_PAYMENT_ID ) );
		$refunds = array();
		foreach ( array_keys( self::pending( $order ) ) as $id ) {
			try {
				$refunds[] = $client->get_refund( $id );
			} catch ( Api_Error $error ) {
				if ( $error->is_transient() ) {
					throw $error;
				}
				if ( 404 === $error->get_http_status() ) {
					// OutlinePay does not know it for this store. Stop following it.
					self::forget_pending( $order, $id );
					$order->save();
				}
			}
		}
		return Order_Sync::apply( $order, $payment, $refunds );
	}

	/**
	 * Refund ids to read back for a callback: the ones in the delivery that
	 * are not handled yet, and the ones still pending on the order.
	 *
	 * @param mixed $order   Order.
	 * @param mixed $payload Decoded callback body.
	 * @return string[]
	 */
	public static function ids_to_read( $order, $payload ) {
		$ids = array();
		if ( is_array( $payload ) && isset( $payload['refunds'] ) && is_array( $payload['refunds'] ) ) {
			foreach ( $payload['refunds'] as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) && Api_Client::valid_refund_id( $row['id'] ) ) {
					$ids[] = $row['id'];
				}
			}
		}
		if ( $order instanceof \WC_Order ) {
			$ids = array_diff( array_merge( $ids, array_keys( self::pending( $order ) ) ), self::done_ids( $order ) );
		}
		return array_values( array_unique( array_filter( $ids, array( Api_Client::class, 'valid_refund_id' ) ) ) );
	}

	/**
	 * Refund ids already handled on this order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	public static function done_ids( $order ) {
		$ids = $order->get_meta( self::META_DONE );
		$ids = is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
		foreach ( $order->get_refunds() as $refund ) {
			$id = (string) $refund->get_meta( self::META_REFUND_ID );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Total of the WooCommerce refunds on this order that came through OutlinePay.
	 *
	 * @param \WC_Order $order Order.
	 * @return int Whole KRW.
	 */
	public static function recorded_amount( $order ) {
		$total = 0;
		foreach ( $order->get_refunds() as $refund ) {
			if ( '' !== (string) $refund->get_meta( self::META_REFUND_ID ) ) {
				$total += (int) Amount::to_krw( $refund->get_amount() );
			}
		}
		return $total;
	}

	/**
	 * Refunds waiting for the provider on this order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array pref id => array( 'amount' => int, 'by' => store|shopper ).
	 */
	public static function pending( $order ) {
		$pending = $order->get_meta( self::META_PENDING );
		if ( ! is_array( $pending ) ) {
			return array();
		}
		$clean = array();
		foreach ( $pending as $id => $row ) {
			if ( Api_Client::valid_refund_id( $id ) && is_array( $row ) ) {
				$clean[ $id ] = array(
					'amount' => isset( $row['amount'] ) ? (int) $row['amount'] : 0,
					'by'     => isset( $row['by'] ) && 'shopper' === $row['by'] ? 'shopper' : 'store',
				);
			}
		}
		return $clean;
	}

	/**
	 * Remembers a refund the provider has not confirmed. The caller saves.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $id     Refund id.
	 * @param int       $amount Amount.
	 * @param string    $by     store or shopper.
	 * @return void
	 */
	public static function remember_pending( $order, $id, $amount, $by ) {
		$pending        = self::pending( $order );
		$pending[ $id ] = array(
			'amount' => (int) $amount,
			'by'     => 'shopper' === $by ? 'shopper' : 'store',
		);
		$order->update_meta_data( self::META_PENDING, array_slice( $pending, -self::MAX_IDS, null, true ) );
	}

	/**
	 * Forgets a pending refund. The caller saves.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Refund id.
	 * @return void
	 */
	public static function forget_pending( $order, $id ) {
		$pending = self::pending( $order );
		if ( ! isset( $pending[ $id ] ) ) {
			return;
		}
		unset( $pending[ $id ] );
		if ( array() === $pending ) {
			$order->delete_meta_data( self::META_PENDING );
			return;
		}
		$order->update_meta_data( self::META_PENDING, $pending );
	}

	/**
	 * Marks a refund id handled. The caller saves.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Refund id.
	 * @return void
	 */
	public static function mark_done( $order, $id ) {
		$ids = $order->get_meta( self::META_DONE );
		$ids = is_array( $ids ) ? $ids : array();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
		$order->update_meta_data( self::META_DONE, array_slice( $ids, -self::MAX_IDS ) );
	}

	/**
	 * True when the order has an OutlinePay refund in any state: handled, pending or unanswered.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function has_any( $order ) {
		$unanswered = $order->get_meta( self::META_UNANSWERED );
		return array() !== self::done_ids( $order ) || array() !== self::pending( $order ) || ( is_array( $unanswered ) && array() !== $unanswered );
	}

	/**
	 * Refund id of an earlier request for this amount that got no answer.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $krw   Amount.
	 * @return string|null
	 */
	private static function unanswered_id( $order, $krw ) {
		$unanswered = $order->get_meta( self::META_UNANSWERED );
		if ( ! is_array( $unanswered ) ) {
			return null;
		}
		foreach ( $unanswered as $refund_id => $amount ) {
			// A shopper cancel has its own id and requester. The store never reuses it.
			if ( (int) $amount === $krw && 0 !== strpos( (string) $refund_id, self::SHOPPER_PREFIX ) ) {
				return (string) $refund_id;
			}
		}
		return null;
	}

	/**
	 * Remembers a request with no answer. The caller saves.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $refund_id Refund id sent.
	 * @param int       $krw       Amount sent.
	 * @return void
	 */
	public static function remember_unanswered( $order, $refund_id, $krw ) {
		$unanswered = $order->get_meta( self::META_UNANSWERED );
		$unanswered = is_array( $unanswered ) ? $unanswered : array();

		$unanswered[ (string) $refund_id ] = (int) $krw;
		$order->update_meta_data( self::META_UNANSWERED, array_slice( $unanswered, -10, null, true ) );
	}

	/**
	 * Remembers a waiting store refund that is now on the order. The caller saves.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $id     Refund id (pref_...).
	 * @param int       $amount Amount.
	 * @return void
	 */
	private static function remember_answered( $order, $id, $amount ) {
		$answered   = $order->get_meta( self::META_ANSWERED );
		$answered   = is_array( $answered ) ? $answered : array();
		$answered[] = array(
			'id'     => (string) $id,
			'amount' => (int) $amount,
			'at'     => time(),
		);
		$order->update_meta_data( self::META_ANSWERED, array_slice( $answered, -10 ) );
	}

	/**
	 * True, once, when a waiting refund of this amount landed on the order in
	 * the last day. Consumes it, so a deliberate second refund works next time.
	 * The caller saves.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $krw   Amount.
	 * @return bool
	 */
	private static function take_answered( $order, $krw ) {
		$answered = $order->get_meta( self::META_ANSWERED );
		if ( ! is_array( $answered ) ) {
			return false;
		}
		$now   = time();
		$keep  = array();
		$found = false;
		foreach ( $answered as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['amount'], $row['at'] ) || $now - (int) $row['at'] > self::ANSWERED_WINDOW ) {
				continue;
			}
			if ( ! $found && (int) $row['amount'] === $krw ) {
				$found = true;
				continue;
			}
			$keep[] = $row;
		}
		if ( array() === $keep ) {
			$order->delete_meta_data( self::META_ANSWERED );
		} else {
			$order->update_meta_data( self::META_ANSWERED, $keep );
		}
		return $found;
	}

	/**
	 * True when a request under this refund id got no answer.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $refund_id Refund id.
	 * @return bool
	 */
	public static function is_unanswered( $order, $refund_id ) {
		$unanswered = $order->get_meta( self::META_UNANSWERED );
		return '' !== (string) $refund_id && is_array( $unanswered ) && array_key_exists( (string) $refund_id, $unanswered );
	}

	/**
	 * Forgets a request once OutlinePay answered it. The caller saves.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $refund_id Refund id.
	 * @return void
	 */
	public static function forget_unanswered( $order, $refund_id ) {
		$unanswered = $order->get_meta( self::META_UNANSWERED );
		if ( ! is_array( $unanswered ) || ! array_key_exists( (string) $refund_id, $unanswered ) ) {
			return;
		}
		unset( $unanswered[ (string) $refund_id ] );
		if ( array() === $unanswered ) {
			$order->delete_meta_data( self::META_UNANSWERED );
			return;
		}
		$order->update_meta_data( self::META_UNANSWERED, $unanswered );
	}

	/**
	 * True for a refund object with the fields this class reads.
	 *
	 * @param mixed $refund Candidate.
	 * @return bool
	 */
	public static function readable( $refund ) {
		return is_array( $refund )
			&& isset( $refund['id'], $refund['status'], $refund['amount'] )
			&& Api_Client::valid_refund_id( $refund['id'] )
			&& in_array( $refund['status'], array( 'pending', 'succeeded', 'failed', 'unknown' ), true )
			&& is_int( $refund['amount'] ) && $refund['amount'] > 0;
	}

	/**
	 * True when the shopper asked for this refund.
	 *
	 * @param array $refund  Refund.
	 * @param array $pending Pending refunds on the order.
	 * @return bool
	 */
	private static function by_shopper( array $refund, array $pending ) {
		if ( 'shopper' === self::field( $refund, 'requested_by' ) ) {
			return true;
		}
		if ( isset( $pending[ $refund['id'] ] ) && 'shopper' === $pending[ $refund['id'] ]['by'] ) {
			return true;
		}
		return 0 === strpos( self::field( $refund, 'refund_id' ), self::SHOPPER_PREFIX );
	}

	/**
	 * Reason shown on a mirrored refund.
	 *
	 * @param array $refund     Refund.
	 * @param bool  $by_shopper Whether the shopper cancelled.
	 * @return string
	 */
	private static function reason( array $refund, $by_shopper ) {
		if ( $by_shopper ) {
			return __( 'Cancelled by the shopper', 'outlinepay-for-woocommerce' );
		}
		switch ( self::field( $refund, 'source' ) ) {
			case 'provider':
				return __( 'Refunded in the payment provider\'s dashboard', 'outlinepay-for-woocommerce' );
			case 'duplicate':
				return __( 'Duplicate payment cancelled automatically', 'outlinepay-for-woocommerce' );
			default:
				return __( 'Refund confirmed by the payment provider', 'outlinepay-for-woocommerce' );
		}
	}

	/**
	 * A string field of an API object, or ''.
	 *
	 * @param array  $data Object.
	 * @param string $key  Field.
	 * @return string
	 */
	private static function field( array $data, $key ) {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}

	/**
	 * Amount for a note, e.g. "5,000 won".
	 *
	 * @param int $amount Whole KRW.
	 * @return string
	 */
	public static function won( $amount ) {
		/* translators: %s: amount in Korean won, such as 5,000. */
		return sprintf( __( '%s won', 'outlinepay-for-woocommerce' ), number_format( (int) $amount ) );
	}

	/**
	 * Adds " (test mode)" for a test refund.
	 *
	 * @param string $note   Note.
	 * @param array  $refund Refund.
	 * @return string
	 */
	private static function note( $note, array $refund ) {
		return Order_Sync::note( $note, $refund );
	}

	/**
	 * A fresh copy of the order, after WooCommerce changed it.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order
	 */
	private static function reread( $order ) {
		$fresh = wc_get_order( $order->get_id() );
		return $fresh instanceof \WC_Order ? $fresh : $order;
	}

	/**
	 * The error WooCommerce shows in the refund box.
	 *
	 * @param string $message Sentence.
	 * @return \WP_Error
	 */
	private static function error( $message ) {
		return new \WP_Error( 'outlinepay_refund', $message );
	}
}
