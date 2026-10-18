<?php
/**
 * Cancel for shoppers: a full refund of a paid order, from My Account.
 *
 * Off until the merchant turns it on. WooCommerce's own Cancel link is a GET
 * meant for unpaid orders and moves no money. It stays for unpaid OutlinePay
 * orders and is removed, and refused, for paid ones, so no status list widened
 * by another plugin can cancel a paid order without a refund. Our Cancel in
 * the orders list links to the order page, and the order page carries a POST
 * form with a nonce. Only the customer who owns the order sees it.
 *
 * The label and the order note reuse WooCommerce's own wording (its text
 * domain), so shoppers and merchants read the same words as for any cancel.
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Shopper cancel.
 */
final class Shopper_Cancel {

	const FIELD = 'outlinepay_cancel_order';
	const NONCE = '_outlinepay_cancel_nonce';

	/**
	 * Hooks the list action, the form and its handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( self::class, 'list_action' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( self::class, 'native_cancel_statuses' ), 100, 2 );
		add_action( 'woocommerce_view_order', array( self::class, 'render_form' ), 20 );
		add_action( 'wp_loaded', array( self::class, 'handle_post' ), 20 );
	}

	/**
	 * Nonce action for one order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function nonce_action( $order_id ) {
		return 'outlinepay_cancel_order_' . (int) $order_id;
	}

	/**
	 * True for an order OutlinePay took money for: our payment id and a paid date.
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function paid_with_outlinepay( $order ) {
		return Order_Sync::is_ours( $order )
			&& Api_Client::valid_payment_id( $order->get_meta( Order_Sync::META_PAYMENT_ID ) )
			&& (bool) $order->get_date_paid();
	}

	/**
	 * Filter: WooCommerce's own GET cancel never applies to a paid OutlinePay
	 * order. It would cancel without a refund. Unpaid orders keep the list.
	 *
	 * @param mixed $statuses Statuses WooCommerce lets a customer cancel from.
	 * @param mixed $order    Order.
	 * @return mixed
	 */
	public static function native_cancel_statuses( $statuses, $order = null ) {
		return self::paid_with_outlinepay( $order ) ? array() : $statuses;
	}

	/**
	 * WooCommerce's label for its cancel action, in WooCommerce's translation.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Cancel', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label, translated by WooCommerce.
	}

	/**
	 * WooCommerce's order note for a cancel by the customer.
	 *
	 * @return string
	 */
	public static function core_cancel_note() {
		return __( 'Order cancelled by customer.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own note, translated by WooCommerce.
	}

	/**
	 * True when this user may cancel this order now.
	 *
	 * @param mixed $order   Order.
	 * @param int   $user_id Current user id.
	 * @return bool
	 */
	public static function can_cancel( $order, $user_id ) {
		if ( ! $order instanceof \WC_Order || (int) $user_id <= 0 || (int) $order->get_customer_id() !== (int) $user_id ) {
			return false;
		}
		$setting = Settings::shopper_cancel();
		if ( ! $setting['enabled'] || ! in_array( $order->get_status(), $setting['statuses'], true ) ) {
			return false;
		}
		$method = Settings::method_for_gateway( $order->get_payment_method() );
		if ( null === $method || ! Settings::refundable( $method ) || ! Settings::is_connected() ) {
			return false;
		}
		if ( ! Api_Client::valid_payment_id( $order->get_meta( Order_Sync::META_PAYMENT_ID ) ) || ! $order->get_date_paid() ) {
			return false;
		}
		if ( '' !== (string) $order->get_meta( Order_Sync::META_SETTLED ) || '' !== (string) $order->get_meta( Order_Sync::META_HOLD ) ) {
			return false;
		}
		if ( (float) $order->get_total_refunded() > 0 || Refunds::has_any( $order ) ) {
			return false;
		}
		return null !== Amount::to_krw( $order->get_total() );
	}

	/**
	 * Adds Cancel to the My Account orders list. It opens the order
	 * page, where the cancel is a POST.
	 *
	 * @param array     $actions Actions.
	 * @param \WC_Order $order   Order.
	 * @return array
	 */
	public static function list_action( $actions, $order ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		if ( self::paid_with_outlinepay( $order ) ) {
			unset( $actions['cancel'] );
		}
		if ( ! self::can_cancel( $order, get_current_user_id() ) ) {
			return $actions;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
			// The order page carries the form itself. A second Cancel there would only jump to it.
			return $actions;
		}
		$actions['outlinepay-cancel'] = array(
			'url'        => $order->get_view_order_url() . '#outlinepay-cancel',
			'name'       => self::label(),
			/* translators: %s: order number. */
			'aria-label' => sprintf( __( 'Cancel order %s', 'woocommerce' ), $order->get_order_number() ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label, translated by WooCommerce.
		);
		return $actions;
	}

	/**
	 * The cancel form on the My Account order page.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function render_form( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! self::can_cancel( $order, get_current_user_id() ) ) {
			return;
		}
		$confirm = __( 'Cancel this order? The full payment goes back to your card or account.', 'outlinepay-for-woocommerce' );
		echo '<section id="outlinepay-cancel" class="outlinepay-cancel">';
		echo '<form method="post" action="' . esc_url( $order->get_view_order_url() ) . '" onsubmit="return window.confirm(\'' . esc_js( $confirm ) . '\');">';
		wp_nonce_field( self::nonce_action( $order->get_id() ), self::NONCE );
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( (string) $order->get_id() ) . '">';
		$theme_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
		echo '<button type="submit" class="' . esc_attr( trim( 'woocommerce-button button outlinepay-cancel-button ' . $theme_class ) ) . '">' . esc_html( self::label() ) . '</button>';
		echo '</form>';
		echo '</section>';
	}

	/**
	 * Handles the form post, adds the notice and goes back to the order page.
	 *
	 * @return void
	 */
	public static function handle_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is verified in cancel().
		if ( 'POST' !== $method || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}
		$order_id = absint( wp_unslash( $_POST[ self::FIELD ] ) );
		$nonce    = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::cancel( $order_id, get_current_user_id(), $nonce );
		wc_add_notice( $result['message'], $result['type'] );

		$order = wc_get_order( $order_id );
		$back  = $order instanceof \WC_Order && (int) $order->get_customer_id() === get_current_user_id() ? $order->get_view_order_url() : wc_get_page_permalink( 'myaccount' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Cancels the order: a full refund requested by the shopper.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $user_id  Current user id.
	 * @param string $nonce    Nonce from the form.
	 * @return array array( 'type' => success|notice|error, 'message' => string ).
	 */
	public static function cancel( $order_id, $user_id, $nonce ) {
		$refused = __( 'This order cannot be cancelled online. Contact the store.', 'outlinepay-for-woocommerce' );
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $order_id ) ) ) {
			return self::result( 'error', __( 'This page has expired. Reload it and try again.', 'outlinepay-for-woocommerce' ) );
		}
		if ( ! self::can_cancel( wc_get_order( $order_id ), $user_id ) ) {
			return self::result( 'error', $refused );
		}
		if ( ! Order_Lock::acquire( $order_id ) ) {
			return self::result( 'error', __( 'This order is being updated. Try again in a moment.', 'outlinepay-for-woocommerce' ) );
		}

		try {
			// Read again under the lock: a double click or a callback may have changed it.
			$order = wc_get_order( $order_id );
			if ( ! self::can_cancel( $order, $user_id ) ) {
				return self::result( 'error', $refused );
			}
			$amount     = Amount::to_krw( $order->get_total() );
			$payment_id = $order->get_meta( Order_Sync::META_PAYMENT_ID );
			$context    = array(
				'order_id'   => $order_id,
				'payment_id' => $payment_id,
			);

			try {
				$refund = Refunds::client()->create_refund( $payment_id, $amount, Refunds::SHOPPER_PREFIX . $order_id, 'shopper' );
			} catch ( Api_Error $error ) {
				$context['code'] = $error->get_error_code();
				Logger::warning( 'Shopper cancel refused', $context );
				if ( $error->is_transient() ) {
					return self::unanswered( $order, $amount );
				}
				return self::result( 'error', $refused );
			}

			if ( ! Refunds::readable( $refund ) ) {
				Logger::error( 'Shopper cancel answer unreadable', $context );
				return self::unanswered( $order, $amount );
			}
			$context['refund_id'] = $refund['id'];
			$context['status']    = $refund['status'];
			Logger::info( 'Shopper cancel answered', $context );

			switch ( $refund['status'] ) {
				case 'succeeded':
					Refunds::add_wc_refund(
						$order,
						$refund,
						__( 'Cancelled by the shopper', 'outlinepay-for-woocommerce' ),
						true,
						self::core_cancel_note() . ' ' . sprintf(
							/* translators: 1: amount such as 5,000 won, 2: OutlinePay refund id. */
							__( '%1$s refunded through OutlinePay (refund %2$s).', 'outlinepay-for-woocommerce' ),
							Refunds::won( $refund['amount'] ),
							$refund['id']
						)
					);
					return self::result( 'success', __( 'Your order is cancelled. The full payment goes back to your card or account.', 'outlinepay-for-woocommerce' ) );

				case 'failed':
					Refunds::mark_done( $order, $refund['id'] );
					$order->add_order_note(
						Order_Sync::note(
							/* translators: %s: OutlinePay refund id. */
							sprintf( __( 'The shopper tried to cancel this order, but the payment provider refused the refund (refund %s). Nothing was refunded. The order is unchanged.', 'outlinepay-for-woocommerce' ), $refund['id'] ),
							$refund
						)
					);
					$order->save();
					return self::result( 'error', $refused );

				default:
					Refunds::remember_pending( $order, $refund['id'], $refund['amount'], 'shopper' );
					$order->add_order_note(
						Order_Sync::note(
							self::core_cancel_note() . ' ' . sprintf(
								/* translators: %s: OutlinePay refund id. */
								__( 'The refund is being confirmed with the payment provider (refund %s).', 'outlinepay-for-woocommerce' ),
								$refund['id']
							),
							$refund
						)
					);
					$order->save();
					return self::result( 'notice', __( 'Your cancellation is being confirmed. We will email you.', 'outlinepay-for-woocommerce' ) );
			}
		} finally {
			Order_Lock::release( $order_id );
		}
	}

	/**
	 * The cancel may have gone through: remembered like a pending one, so the
	 * button stays hidden and the refund delivery completes it, and the
	 * merchant is told not to ship. The caller holds the lock.
	 *
	 * @param \WC_Order $order  Order.
	 * @param int       $amount Amount asked for.
	 * @return array
	 */
	private static function unanswered( $order, $amount ) {
		Refunds::remember_unanswered( $order, Refunds::SHOPPER_PREFIX . $order->get_id(), $amount );
		$order->add_order_note( __( 'The shopper asked to cancel, but OutlinePay\'s answer was lost. If the refund went through, this order will be cancelled automatically. Do not ship it until then.', 'outlinepay-for-woocommerce' ) );
		$order->save();
		return self::result( 'notice', __( 'We could not confirm your cancellation yet. We will email you.', 'outlinepay-for-woocommerce' ) );
	}

	/**
	 * Result shape.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Sentence.
	 * @return array
	 */
	private static function result( $type, $message ) {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
