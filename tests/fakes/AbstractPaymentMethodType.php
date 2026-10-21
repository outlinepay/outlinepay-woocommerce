<?php
namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

/**
 * A minimal stand-in for WooCommerce Blocks' AbstractPaymentMethodType.
 */
abstract class AbstractPaymentMethodType {
	protected $name     = '';
	protected $settings = array();
}
