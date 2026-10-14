<?php
/**
 * Unit test bootstrap. No WordPress, no database: WordPress functions are
 * stubbed per test with Brain Monkey.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OUTLINEPAY_VERSION', '0.1.0' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/autoload.php';
