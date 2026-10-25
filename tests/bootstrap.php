<?php
/**
 * Unit test bootstrap. No WordPress, no database: WordPress functions are
 * stubbed per test with Brain Monkey.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OUTLINEPAY_VERSION', '0.5.2' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/autoload.php';
require __DIR__ . '/fakes/class-wc-order.php';
require __DIR__ . '/fakes/ArrayLockStore.php';
require __DIR__ . '/fakes/AbstractPaymentMethodType.php';
