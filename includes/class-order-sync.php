<?php
/**
 * The one place a payment from GET /payments becomes an order change.
 *
 * Idempotent: applying the same payment and status twice changes nothing the
 * second time. Unknown statuses change nothing. A payment for another order
 * or another installation is ignored. A payment this store did not open for
 * the order never completes it.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Maps payments to orders.
 */
final class Order_Sync {

	const META_PAYMENT_ID = '_outlinepay_payment_id';
	const META_ATTEMPTS   = '_outlinepay_attempts';
	const META_APPLIED    = '_outlinepay_applied';
	const META_HOLD       = '_outlinepay_hold';
	const META_SETTLED    = '_outlinepay_settled';
	const META_VA         = '_outlinepay_virtual_account';
	const META_MODE       = '_outlinepay_mode';
	const META_RECEIPT    = '_outlinepay_receipt_url';
	const META_LAUNCH     = '_outlinepay_launch';
	const META_NOTED      = '_outlinepay_attempt_notes';
	const META_TELL       = '_outlinepay_shopper_notice';

	const COMPLETE       = 'complete';
	const HOLD_MISMATCH  = 'hold_mismatch';
	const HOLD_DEPOSIT   = 'hold_deposit';
	const HOLD_REVIEW    = 'hold_review';
	const HOLD_FOREIGN   = 'hold_foreign';
	const RETRY          = 'retry';
	const WAIT           = 'wait';
	const NOTE_DUPLICATE = 'note_duplicate';
	const IGNORE         = 'ignore';
	const MISMATCH       = 'ignore_mismatch';
	const UNKNOWN        = 'unknown';
	const BUSY           = 'busy';

	/**
	 * What the shopper did in the provider's window, from the payment's
	 * window_outcome. A hint only: the payment status decides the order.
	 */
	const WINDOW_OUTCOMES = array( 'cancelled', 'failed' );

	/**
	 * Most attempt ids kept per order.
	 */
	const MAX_ATTEMPTS = 50;

	/**
	 * Decides what a payment means for an order. Pure: no WordPress calls.
	 *
	 * @param mixed       $payment         Payment JSON.
	 * @param int|string  $order_id        The order's id.
	 * @param int|null    $order_krw       The order total in whole KRW, null when not whole.
	 * @param string      $order_currency  The order currency.
	 * @param string|null $installation_id Our installation id, when known.
	 * @param array|null  $attempts        Payment ids this store opened for the order. Null skips the check.
	 * @return string One of the decision constants.
	 */
	public static function decide( $payment, $order_id, $order_krw, $order_currency, $installation_id = null, $attempts = null ) {
		if ( ! is_array( $payment ) || ! isset( $payment['id'], $payment['status'], $payment['order_id'] ) || ! is_string( $payment['status'] ) || ! is_string( $payment['id'] ) ) {
			return self::IGNORE;
		}
		if ( ! is_scalar( $payment['order_id'] ) || (string) $payment['order_id'] !== (string) $order_id ) {
			return self::MISMATCH;
		}
		if ( $installation_id && isset( $payment['installation'] ) && is_string( $payment['installation'] ) && $payment['installation'] !== $installation_id ) {
			return self::MISMATCH;
		}
		if ( is_array( $attempts ) && ! in_array( $payment['id'], $attempts, true ) ) {
			// Same order id, but not an attempt this store opened: another copy of
			// the store (a staging clone with the same credentials) made it.
			return 'paid' === $payment['status'] ? self::HOLD_FOREIGN : self::MISMATCH;
		}

		switch ( $payment['status'] ) {
			case 'paid':
				$amount_ok   = isset( $payment['amount'] ) && is_int( $payment['amount'] ) && null !== $order_krw && $payment['amount'] === $order_krw;
				$currency_ok = isset( $payment['currency'] ) && is_string( $payment['currency'] ) && '' !== $payment['currency'] && strtoupper( (string) $order_currency ) === $payment['currency'];
				return $amount_ok && $currency_ok ? self::COMPLETE : self::HOLD_MISMATCH;
			case 'awaiting_deposit':
				return self::HOLD_DEPOSIT;
			case 'awaiting_payment':
				return self::WAIT;
			case 'failed':
			case 'expired':
				return self::RETRY;
			case 'needs_review':
				return self::HOLD_REVIEW;
			case 'duplicate':
				return self::NOTE_DUPLICATE;
			case 'superseded':
				return self::IGNORE;
			default:
				return self::UNKNOWN;
		}
	}

	/**
	 * From all attempts of one order (newest first), the one that speaks for it:
	 * a paid attempt, else one needing review, else the newest that is not superseded.
	 *
	 * @param array $payments Payments, newest first.
	 * @return array|null
	 */
	public static function pick( array $payments ) {
		foreach ( array( 'paid', 'needs_review' ) as $status ) {
			foreach ( $payments as $payment ) {
				if ( is_array( $payment ) && isset( $payment['status'] ) && $status === $payment['status'] ) {
					return $payment;
				}
			}
		}
		foreach ( $payments as $payment ) {
			if ( is_array( $payment ) && isset( $payment['status'] ) && 'superseded' !== $payment['status'] ) {
				return $payment;
			}
		}
		return null;
	}

	/**
	 * Payment ids this store opened for the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	public static function attempts( $order ) {
		$attempts = $order->get_meta( self::META_ATTEMPTS );
		return is_array( $attempts ) ? array_values( array_filter( $attempts, 'is_string' ) ) : array();
	}

	/**
	 * Records an attempt this store opened. The caller saves the order.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payment_id Payment id.
	 * @return void
	 */
	public static function add_attempt( $order, $payment_id ) {
		$attempts = self::attempts( $order );
		if ( ! in_array( $payment_id, $attempts, true ) ) {
			$attempts[] = $payment_id;
		}
		$order->update_meta_data( self::META_ATTEMPTS, array_slice( $attempts, -self::MAX_ATTEMPTS ) );
	}

	/**
	 * The private note for a payment that could not start, at most once per
	 * order and reason: a shopper pressing Place order again does not add
	 * another. Saves the order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason provider_error, unreachable, method_not_enabled,
	 *                          not_whole_won, bad_answer, or an OutlinePay
	 *                          error code.
	 * @param string    $method Payment method key.
	 * @return bool True when the note was added.
	 */
	public static function note_refused( $order, $reason, $method ) {
		$key   = 'refused:' . $reason;
		$noted = $order->get_meta( self::META_NOTED );
		$noted = is_array( $noted ) ? $noted : array();
		if ( in_array( $key, $noted, true ) ) {
			return false;
		}
		// Both names are read inside each note's closure, so they come out in
		// the store's language with the rest of the note (see Notes::write).
		$name     = fn () => Settings::method_name( $method );
		$provider = fn () => Settings::provider_display_name();
		switch ( $reason ) {
			case 'provider_error':
				/* translators: 1: payment method name, 2: payment provider name such as CookiePay. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %1$s could not start: %2$s returned an error. The shopper saw an error and can try again.', 'outlinepay-for-woocommerce' ), $name(), $provider() );
				break;
			case 'unreachable':
				/* translators: %s: payment method name. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %s could not start: this site could not reach OutlinePay. The shopper saw an error and can try again.', 'outlinepay-for-woocommerce' ), $name() );
				break;
			case 'method_not_enabled':
				/* translators: 1: payment method name, 2: payment provider name such as CookiePay. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %1$s could not start: %2$s has not enabled this method for the store. The shopper was asked to choose another method.', 'outlinepay-for-woocommerce' ), $name(), $provider() );
				break;
			case 'bad_answer':
				/* translators: %s: payment method name. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %s could not start: OutlinePay\'s answer could not be used. The shopper saw an error and can try again.', 'outlinepay-for-woocommerce' ), $name() );
				break;
			case 'not_whole_won':
				/* translators: %s: payment method name. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %s could not start: the order total is not a whole number of won. Check the product prices.', 'outlinepay-for-woocommerce' ), $name() );
				break;
			default:
				/* translators: 1: payment method name, 2: OutlinePay error code. */
				$text = fn () => sprintf( __( 'An OutlinePay payment by %1$s could not start (%2$s). The shopper saw an error and can try again.', 'outlinepay-for-woocommerce' ), $name(), $reason );
		}
		Notes::write( $order, $text );
		$noted[] = $key;
		$order->update_meta_data( self::META_NOTED, array_slice( $noted, -2 * self::MAX_ATTEMPTS ) );
		$order->save();
		return true;
	}

	/**
	 * The private note for an attempt that started or expired, at most once
	 * per attempt and event. The caller saves the order.
	 *
	 * @param \WC_Order  $order      Order.
	 * @param string     $payment_id OutlinePay payment id.
	 * @param string     $event      started, expired, window_cancelled or window_failed.
	 * @param array|null $payment    The payment, when known: a test payment's note says so.
	 * @return bool True when the note was added.
	 */
	public static function note_attempt( $order, $payment_id, $event, $payment = null ) {
		$key   = $payment_id . ':' . $event;
		$noted = $order->get_meta( self::META_NOTED );
		$noted = is_array( $noted ) ? $noted : array();
		if ( in_array( $key, $noted, true ) ) {
			return false;
		}
		if ( 'started' === $event ) {
			/* translators: %s: OutlinePay payment id. */
			Notes::write( $order, fn () => sprintf( __( 'OutlinePay payment %s started. Waiting for the shopper.', 'outlinepay-for-woocommerce' ), $payment_id ) );
		} elseif ( 'expired' === $event ) {
			/* translators: %s: OutlinePay payment id. */
			Notes::write( $order, fn () => sprintf( __( 'OutlinePay payment %s expired. The shopper did not finish.', 'outlinepay-for-woocommerce' ), $payment_id ) );
		} elseif ( 'window_cancelled' === $event ) {
			/* translators: %s: OutlinePay payment id. */
			Notes::write( $order, fn () => self::note( sprintf( __( 'OutlinePay payment %s: the shopper cancelled in the payment window. The order is still waiting for payment.', 'outlinepay-for-woocommerce' ), $payment_id ), is_array( $payment ) ? $payment : array() ) );
		} elseif ( 'window_failed' === $event ) {
			/* translators: %s: OutlinePay payment id. */
			Notes::write( $order, fn () => self::note( sprintf( __( 'OutlinePay payment %s: the payment provider refused the payment in its window and did not say why. The order is still waiting for payment.', 'outlinepay-for-woocommerce' ), $payment_id ), is_array( $payment ) ? $payment : array() ) );
		} else {
			return false;
		}
		if ( 'started' === $event ) {
			// A payment got going: a later failure on this order is news again.
			$noted = array_values(
				array_filter(
					$noted,
					static function ( $noted_key ) {
						return 0 !== strpos( $noted_key, 'refused:' );
					}
				)
			);
		}
		$noted[] = $key;
		$order->update_meta_data( self::META_NOTED, array_slice( $noted, -2 * self::MAX_ATTEMPTS ) );
		return true;
	}

	/**
	 * What the shopper did in the provider's window: cancelled, failed, or
	 * null. Only for a payment of this order that is still open or failed
	 * (decision WAIT or RETRY). A server that does not send window_outcome,
	 * or sends a value this plugin does not know, gives null.
	 *
	 * @param mixed       $payment  Payment JSON.
	 * @param string|null $decision Decision for the payment.
	 * @return string|null
	 */
	public static function window_outcome( $payment, $decision ) {
		if ( ! in_array( $decision, array( self::WAIT, self::RETRY ), true ) || ! is_array( $payment ) || ! isset( $payment['window_outcome'] ) ) {
			return null;
		}
		return in_array( $payment['window_outcome'], self::WINDOW_OUTCOMES, true ) ? $payment['window_outcome'] : null;
	}

	/**
	 * The private note for what the shopper did in the provider's window, at
	 * most once per attempt and outcome: a reload or a second return adds no
	 * other. None when the order is paid or on hold. With the note, the
	 * order keeps the outcome for the shopper's pay page (see
	 * Return_Handler::pay_page_notice), until the page shows it once. The
	 * caller saves the order.
	 *
	 * @param \WC_Order   $order    Order.
	 * @param array       $payment  Payment.
	 * @param string|null $decision Decision for the payment.
	 * @return bool True when the note was added.
	 */
	public static function note_window( $order, $payment, $decision ) {
		if ( ! self::window_note_due( $order, $payment, $decision ) ) {
			return false;
		}
		$outcome = self::window_outcome( $payment, $decision );
		if ( ! self::note_attempt( $order, (string) $payment['id'], 'window_' . $outcome, $payment ) ) {
			return false;
		}
		$order->update_meta_data(
			self::META_TELL,
			array(
				'payment_id' => (string) $payment['id'],
				'outcome'    => $outcome,
			)
		);
		return true;
	}

	/**
	 * True when the payment carries a window outcome and the order is neither
	 * paid (by the same test as act) nor on hold: the case note_window writes for.
	 *
	 * @param \WC_Order   $order    Order.
	 * @param mixed       $payment  Payment.
	 * @param string|null $decision Decision for the payment.
	 * @return bool
	 */
	private static function window_note_due( $order, $payment, $decision ) {
		return null !== self::window_outcome( $payment, $decision ) && ! self::counts_as_paid( $order ) && ! $order->has_status( 'on-hold' );
	}

	/**
	 * True when the order is paid, or another gateway took the money already
	 * (a 3-D Secure redirect that finished, a webhook) without the status
	 * showing it yet: its paid date or transaction id gives it away.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function counts_as_paid( $order ) {
		if ( $order->is_paid() ) {
			return true;
		}
		$other_gateway = null === Settings::method_for_gateway( $order->get_payment_method() );
		return $other_gateway && ( $order->get_date_paid() || '' !== (string) $order->get_transaction_id() );
	}

	/**
	 * True when OutlinePay may have taken money for the order: it was placed with
	 * one of our gateways, or it carries an attempt we opened (the shopper may
	 * have switched gateways since and still paid in our window).
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function is_ours( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		return null !== Settings::method_for_gateway( $order->get_payment_method() ) || array() !== self::attempts( $order );
	}

	/**
	 * Applies a payment to its order, then its refunds.
	 *
	 * @param \WC_Order  $order   Order.
	 * @param array      $payment Payment JSON from GET /payments.
	 * @param array|null $refunds Refunds of the payment, each read back with GET /refunds.
	 * @return string The decision taken.
	 */
	public static function apply( $order, $payment, $refunds = null ) {
		if ( ! self::is_ours( $order ) ) {
			return self::IGNORE;
		}
		$order_id = $order->get_id();
		if ( ! Order_Lock::acquire( $order_id ) ) {
			Logger::warning( 'Order busy, sync skipped', array( 'order_id' => $order_id ) );
			return self::BUSY;
		}

		try {
			// Read fresh: another request may have changed the order while this
			// one waited for the lock, and this request may hold a cached copy.
			self::forget_cached_order( $order_id );
			$fresh = wc_get_order( $order_id );
			if ( $fresh instanceof \WC_Order ) {
				$order = $fresh;
			}
			$installation = Settings::installation();
			$decision     = self::decide(
				$payment,
				$order_id,
				Amount::to_krw( $order->get_total() ),
				$order->get_currency(),
				isset( $installation['id'] ) ? $installation['id'] : null,
				self::attempts( $order )
			);
			$context      = array(
				'order_id'   => $order_id,
				'payment_id' => is_array( $payment ) && isset( $payment['id'] ) ? $payment['id'] : '',
				'status'     => is_array( $payment ) && isset( $payment['status'] ) ? $payment['status'] : '',
				'decision'   => $decision,
			);

			if ( in_array( $decision, array( self::IGNORE, self::MISMATCH, self::UNKNOWN, self::WAIT ), true ) ) {
				if ( self::UNKNOWN === $decision || self::MISMATCH === $decision ) {
					Logger::warning( 'Payment not applied', $context );
				}
				if ( self::note_window( $order, $payment, $decision ) ) {
					$order->save();
				}
				return $decision;
			}

			$key     = $payment['id'] . ':' . $payment['status'];
			$applied = $order->get_meta( self::META_APPLIED );
			$applied = is_array( $applied ) ? $applied : array();
			if ( ! in_array( $key, $applied, true ) ) {
				self::act( $order, $payment, $decision, $context );

				$applied[] = $key;
				$order->update_meta_data( self::META_APPLIED, array_slice( $applied, -20 ) );
				$order->save();
				Logger::info( 'Payment applied', $context );
			}

			if ( self::note_window( $order, $payment, $decision ) ) {
				$order->save();
			}

			// A paid or held order needs no payment form any more.
			if ( ( $order->is_paid() || $order->has_status( 'on-hold' ) ) && Launch_Page::forget( $order ) ) {
				$order->save();
			}

			self::after_status( $order, $payment, $decision, is_array( $refunds ) ? $refunds : array() );
			return $decision;
		} finally {
			Order_Lock::release( $order_id );
		}
	}

	/**
	 * What a payment carries besides its status: the card receipt and refunds.
	 * Runs every time, so a refund delivery for a payment already applied
	 * still lands. The caller holds the order lock.
	 *
	 * @param \WC_Order $order    Order.
	 * @param array     $payment  Payment.
	 * @param string    $decision Decision.
	 * @param array     $refunds  Refunds read back for this payment.
	 * @return void
	 */
	private static function after_status( $order, array $payment, $decision, array $refunds ) {
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;

		$is_order_payment = self::COMPLETE === $decision
			&& $payment['id'] === $order->get_meta( self::META_PAYMENT_ID )
			&& '' === (string) $order->get_meta( self::META_SETTLED );

		if ( $is_order_payment && isset( $payment['receipt_url'] ) ) {
			$receipt = Order_Display::safe_receipt_url( $payment['receipt_url'] );
			if ( '' !== $receipt && $receipt !== $order->get_meta( self::META_RECEIPT ) ) {
				$order->update_meta_data( self::META_RECEIPT, $receipt );
				$order->save();
			}
		}

		if ( array() !== $refunds ) {
			Refunds::mirror( $order, $payment, $refunds, $is_order_payment );
		}

		if ( $is_order_payment && isset( $payment['refunded_amount'] ) && is_int( $payment['refunded_amount'] ) ) {
			$fresh    = wc_get_order( $order->get_id() );
			$recorded = Refunds::recorded_amount( $fresh instanceof \WC_Order ? $fresh : $order );
			if ( $payment['refunded_amount'] > $recorded ) {
				// A refund this store has not heard about yet. Its refund delivery adds it.
				Logger::warning(
					'Payment shows more refunded than the order',
					array(
						'order_id'   => $order->get_id(),
						'payment_id' => $payment['id'],
						'amount'     => $payment['refunded_amount'] - $recorded,
					)
				);
			}
		}
	}

	/**
	 * Adds " (test mode)" to a note when the payment is a test payment.
	 *
	 * @param string $note    Note.
	 * @param array  $payment Payment.
	 * @return string
	 */
	public static function note( $note, array $payment ) {
		if ( isset( $payment['mode'] ) && 'test' === $payment['mode'] ) {
			$note .= ' ' . __( '(test mode)', 'outlinepay-for-woocommerce' );
		}
		return $note;
	}

	/**
	 * Performs the order change for a decision.
	 *
	 * @param \WC_Order $order    Order.
	 * @param array     $payment  Payment.
	 * @param string    $decision Decision.
	 * @param array     $context  Log context.
	 * @return void
	 */
	private static function act( $order, array $payment, $decision, array $context ) {
		$payment_id    = (string) $payment['id'];
		$paid          = self::counts_as_paid( $order );
		$other_gateway = null === Settings::method_for_gateway( $order->get_payment_method() );

		switch ( $decision ) {
			case self::COMPLETE:
				if ( $paid ) {
					if ( $other_gateway ) {
						// Paid through another gateway, and our window was paid too.
						$order->update_meta_data( self::META_SETTLED, 'double' );
						Notes::write(
							$order,
							fn () => self::note(
								/* translators: %s: OutlinePay payment id. */
								sprintf( __( 'OutlinePay payment %s was paid, but this order was already paid with another payment method. The shopper paid twice. To refund the OutlinePay payment, use your payment provider dashboard.', 'outlinepay-for-woocommerce' ), $payment_id ),
								$payment
							)
						);
						Logger::warning( 'Shopper paid twice: OutlinePay payment on an order paid with another method', $context );
					}
					return;
				}
				self::take_back_gateway( $order, $payment );
				$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
				// Written only here, when OutlinePay took the payment, so a test
				// attempt on an order paid live elsewhere never reads as a test payment.
				$order->update_meta_data( self::META_MODE, isset( $payment['mode'] ) && 'test' === $payment['mode'] ? 'test' : 'live' );
				$order->delete_meta_data( self::META_HOLD );
				$transaction = isset( $payment['transaction_id'] ) && is_string( $payment['transaction_id'] ) ? $payment['transaction_id'] : '';
				Notes::write(
					$order,
					fn () => self::note(
						/* translators: %s: OutlinePay payment id. */
						sprintf( __( 'OutlinePay payment %s is paid.', 'outlinepay-for-woocommerce' ), $payment_id ),
						$payment
					)
				);
				$order->payment_complete( $transaction );
				return;

			case self::HOLD_FOREIGN:
				Logger::warning( 'Payment from another copy of this store', $context );
				$note = Notes::text(
					fn () => self::note(
						/* translators: %s: OutlinePay payment id. */
						sprintf( __( 'OutlinePay payment %s was made for this order number from another copy of this store, such as a staging site. It does not pay this order. Check it before shipping.', 'outlinepay-for-woocommerce' ), $payment_id ),
						$payment
					)
				);
				if ( $paid ) {
					Notes::write( $order, fn () => $note );
					return;
				}
				$order->update_meta_data( self::META_HOLD, 'foreign' );
				$order->update_meta_data( self::META_SETTLED, 'foreign' );
				Notes::in_site_locale( fn () => $order->update_status( 'on-hold', $note ) );
				return;

			case self::HOLD_MISMATCH:
				if ( $paid ) {
					return;
				}
				$order->update_meta_data( self::META_HOLD, 'mismatch' );
				$order->update_meta_data( self::META_SETTLED, 'mismatch' );
				Notes::in_site_locale(
					fn () => $order->update_status(
						'on-hold',
						self::note(
							/* translators: %s: OutlinePay payment id. */
							sprintf( __( 'OutlinePay payment %s reports paid, but the amount or currency does not match this order. Check it before shipping.', 'outlinepay-for-woocommerce' ), $payment_id ),
							$payment
						)
					)
				);
				return;

			case self::HOLD_DEPOSIT:
				if ( $paid ) {
					return;
				}
				$account = isset( $payment['virtual_account'] ) && is_array( $payment['virtual_account'] ) ? $payment['virtual_account'] : array();
				$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
				$order->update_meta_data(
					self::META_VA,
					array(
						'bank'           => isset( $account['bank'] ) ? sanitize_text_field( (string) $account['bank'] ) : '',
						'account_number' => isset( $account['account_number'] ) ? sanitize_text_field( (string) $account['account_number'] ) : '',
						'deadline'       => isset( $account['deadline'] ) ? sanitize_text_field( (string) $account['deadline'] ) : '',
					)
				);
				$order->update_meta_data( self::META_HOLD, 'deposit' );
				if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					Notes::in_site_locale( fn () => $order->update_status( 'on-hold', self::note( __( 'OutlinePay issued a virtual account. Waiting for the deposit.', 'outlinepay-for-woocommerce' ), $payment ) ) );
				}
				return;

			case self::RETRY:
				if ( $paid ) {
					return;
				}
				if ( $order->has_status( 'on-hold' ) && 'deposit' === $order->get_meta( self::META_HOLD ) && $payment_id === $order->get_meta( self::META_PAYMENT_ID ) ) {
					$order->delete_meta_data( self::META_HOLD );
					$order->delete_meta_data( self::META_VA );
					Notes::in_site_locale(
						fn () => $order->update_status(
							'pending',
							self::note(
								/* translators: %s: OutlinePay payment id. */
								sprintf( __( 'OutlinePay payment %s expired before the deposit arrived.', 'outlinepay-for-woocommerce' ), $payment_id ),
								$payment
							)
						)
					);
					return;
				}
				if ( 'failed' === $payment['status'] && self::window_note_due( $order, $payment, $decision ) ) {
					// The window note (see note_window, right after this) says what happened.
					return;
				}
				Notes::write(
					$order,
					fn () => self::note(
						/* translators: 1: OutlinePay payment id, 2: status such as failed or expired. */
						sprintf( __( 'OutlinePay payment %1$s is %2$s. The order is waiting for payment.', 'outlinepay-for-woocommerce' ), $payment_id, $payment['status'] ),
						$payment
					)
				);
				return;

			case self::HOLD_REVIEW:
				if ( $paid ) {
					return;
				}
				$order->update_meta_data( self::META_HOLD, 'review' );
				$order->update_meta_data( self::META_SETTLED, 'review' );
				Notes::in_site_locale(
					fn () => $order->update_status(
						'on-hold',
						self::note(
							/* translators: %s: OutlinePay payment id. */
							sprintf( __( 'OutlinePay payment %s needs review: it was paid for a different amount than ordered. OutlinePay has been alerted. Do not ship until it is settled.', 'outlinepay-for-woocommerce' ), $payment_id ),
							$payment
						)
					)
				);
				return;

			case self::NOTE_DUPLICATE:
				if ( 'foreign' === $order->get_meta( self::META_HOLD ) ) {
					// The order is on hold because a copy of the store got paid first.
					// This later payment is most likely the shopper's real one.
					Notes::write(
						$order,
						fn () => self::note(
							/* translators: %s: OutlinePay payment id. */
							sprintf( __( 'OutlinePay payment %s is probably the shopper\'s real payment for this order. The earlier payment came from another copy of this store. Check both payments before you refund anything. The order stays on hold.', 'outlinepay-for-woocommerce' ), $payment_id ),
							$payment
						)
					);
					Logger::warning( 'Duplicate payment on an order held for a foreign payment', $context );
					return;
				}
				Notes::write(
					$order,
					fn () => self::note(
						/* translators: %s: OutlinePay payment id. */
						sprintf( __( 'OutlinePay payment %s was paid after this order was already paid. The shopper paid twice. OutlinePay has been alerted. Refund the second payment in your payment provider dashboard.', 'outlinepay-for-woocommerce' ), $payment_id ),
						$payment
					)
				);
				return;
		}
	}

	/**
	 * When the shopper switched the order to another gateway and then paid in an
	 * OutlinePay window anyway, the order goes back to the matching OutlinePay gateway.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $payment Payment.
	 * @return void
	 */
	private static function take_back_gateway( $order, array $payment ) {
		if ( null !== Settings::method_for_gateway( $order->get_payment_method() ) ) {
			return;
		}
		$method = isset( $payment['payment_method'] ) && in_array( $payment['payment_method'], Settings::METHODS, true ) ? $payment['payment_method'] : 'card';
		$order->set_payment_method( Settings::gateway_id( $method ) );
		$order->set_payment_method_title( Settings::method_name( $method ) );
		Notes::write(
			$order,
			fn () => self::note(
				/* translators: %s: OutlinePay payment id. */
				sprintf( __( 'The shopper chose another payment method for this order, but then paid OutlinePay payment %s. The payment method is set back to OutlinePay.', 'outlinepay-for-woocommerce' ), (string) $payment['id'] ),
				$payment
			)
		);
	}

	/**
	 * Drops this request's cached copy of the order, so the next read comes from storage.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function forget_cached_order( $order_id ) {
		if ( function_exists( 'wp_cache_flush_runtime' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'orders' );
	}
}
