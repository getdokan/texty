<?php
/**
 * PHPUnit bootstrap
 *
 * Loads the WordPress test library and Texty. Inside wp-env's `tests-cli`
 * container `WP_TESTS_DIR` is already set; elsewhere point it at a
 * wordpress-develop `tests/phpunit` checkout.
 *
 * @package Texty\Tests
 */

$texty_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $texty_tests_dir ) {
    $texty_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $texty_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find the WordPress test library in {$texty_tests_dir}.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo "Run the suite with `npm run test:php` (wp-env) or set WP_TESTS_DIR.\n";
    exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $texty_tests_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    function () {
        require dirname( __DIR__, 2 ) . '/texty.php';
    }
);

require $texty_tests_dir . '/includes/bootstrap.php';

// The activation hook never runs under the test library, so create the
// SMS log table once up front for tests that exercise the send pipeline.
( new Texty\Install() )->create_tables();
