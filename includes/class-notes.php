<?php
/**
 * Order notes in the site language.
 *
 * A callback or a REST request can run in another language than the store's,
 * the request user's for one. Every merchant-facing note is built and written
 * here, inside the site locale, so the merchant reads notes in one language.
 * A status change with a note runs whole inside it (see in_site_locale).
 *
 * @package OutlinePay\WooCommerce
 */

namespace OutlinePay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Order notes.
 */
final class Notes {

	/**
	 * Builds a note in the site language and adds it to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param callable  $build Returns the note text. Runs in the site locale.
	 * @return void
	 */
	public static function write( $order, callable $build ) {
		$order->add_order_note( self::text( $build ) );
	}

	/**
	 * Builds a text in the site language.
	 *
	 * @param callable $build Returns the text.
	 * @return string
	 */
	public static function text( callable $build ) {
		return (string) self::in_site_locale( $build );
	}

	/**
	 * Runs $work in the site locale and returns what it returns. For a status
	 * change with a note: WooCommerce writes its "Order status changed" prefix
	 * while this runs, so the prefix and the note share the site language.
	 *
	 * @param callable $work Work to run.
	 * @return mixed
	 */
	public static function in_site_locale( callable $work ) {
		// False when the request already runs in the site locale: nothing to restore then.
		$switched = switch_to_locale( get_locale() );
		try {
			return call_user_func( $work );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
