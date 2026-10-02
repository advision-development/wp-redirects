<?php
/**
 * Integration bootstrap. Runs inside wp-env's tests-cli container.
 */

$adv_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

if ( ! file_exists( $adv_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$adv_tests_dir}. Run: npm run test:php:integration\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $adv_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/wp-redirects.php';
	}
);

require $adv_tests_dir . '/includes/bootstrap.php';

// wp-env's wp-tests-config.php pins WP_HOME and WP_SITEURL to http://localhost:8889, which
// would make home_url() ignore update_option(). Drop the constant overrides and use the stock
// WordPress test-suite site URL so tests can change 'home' and rely on http://example.org.
remove_filter( 'option_home', '_config_wp_home' );
remove_filter( 'option_siteurl', '_config_wp_siteurl' );
update_option( 'home', 'http://example.org' );
update_option( 'siteurl', 'http://example.org' );

\Advision\Redirects\Schema::install();

foreach ( glob( __DIR__ . '/support/*.php' ) as $adv_support_file ) {
	require_once $adv_support_file;
}
