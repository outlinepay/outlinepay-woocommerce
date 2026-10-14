<?php
namespace OutlinePay\WooCommerce\Tests;

/**
 * In-memory lock store with the same contract as Options_Lock_Store.
 */
class ArrayLockStore {
	public $rows = array();

	/** Called before replace_if, to let a test play a racing writer. */
	public $before_replace = null;

	public function insert_if_absent( $name, $value ) {
		if ( array_key_exists( $name, $this->rows ) ) {
			return false;
		}
		$this->rows[ $name ] = $value;
		return true;
	}
	public function get( $name ) {
		return isset( $this->rows[ $name ] ) ? $this->rows[ $name ] : null;
	}
	public function replace_if( $name, $old, $fresh ) {
		if ( $this->before_replace ) {
			call_user_func( $this->before_replace, $this );
		}
		if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ] !== $old ) {
			return false;
		}
		$this->rows[ $name ] = $fresh;
		return true;
	}
	public function delete_if( $name, $value ) {
		if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ] !== $value ) {
			return false;
		}
		unset( $this->rows[ $name ] );
		return true;
	}
}
