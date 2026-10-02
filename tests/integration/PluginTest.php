<?php

use Advision\Redirects\Cron;
use Advision\Redirects\Plugin;
use Advision\Redirects\Settings;
use Advision\Redirects\Uninstaller;

final class PluginTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Cron::unschedule();
		parent::tear_down();
	}

	public function test_runtime_hooks_are_registered(): void {
		$plugin = Plugin::instance();
		$this->assertSame( 1, has_action( 'init', [ $plugin->redirector(), 'maybe_redirect' ] ) );
		$this->assertSame( 0, has_action( 'template_redirect', [ $plugin->redirector(), 'maybe_send_gone' ] ) );
		$this->assertSame( 99, has_action( 'template_redirect', [ $plugin->not_found_logger(), 'maybe_log' ] ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ $plugin, 'register_routes' ] ) );
		$this->assertNotFalse( has_action( 'post_updated' ) );
		$this->assertSame( 1, did_action( 'adv_redirects_loaded' ) );
	}

	public function test_routes_are_registered(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		$routes         = rest_get_server()->get_routes();
		foreach ( [ '/redirects', '/redirects/(?P<id>\d+)', '/redirects/bulk', '/redirects/reorder', '/test', '/404s', '/404s/(?P<id>\d+)', '/404s/bulk', '/settings' ] as $route ) {
			$this->assertArrayHasKey( '/adv-redirects/v1' . $route, $routes, $route );
		}
		$wp_rest_server = null;
	}

	public function test_php_api(): void {
		$rule = adv_redirects_add( [ 'type' => 'exact', 'source' => '/api-old', 'target' => '/api-new', 'status_code' => 302 ] );
		$this->assertIsArray( $rule );
		$this->assertSame( '/api-old', $rule['source'] );
		$this->assertSame( 'api', $rule['created_via'] );

		$this->assertWPError( adv_redirects_add( [ 'type' => 'exact', 'source' => '/api-old', 'target' => '/x', 'status_code' => 301 ] ) );

		$before = did_action( 'adv_redirects_cache_flushed' );
		adv_redirects_flush_cache();
		$this->assertSame( $before + 1, did_action( 'adv_redirects_cache_flushed' ) );

		$this->assertTrue( adv_redirects_delete( $rule['id'] ) );
		$this->assertFalse( adv_redirects_delete( $rule['id'] ) );
	}

	public function test_activation_schedules_cron(): void {
		Plugin::activate();
		$this->assertNotFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
	}

	public function test_uninstall_is_opt_in(): void {
		$this->assertFalse( Uninstaller::should_remove() );
		Settings::update( [ 'remove_data_on_uninstall' => true ] );
		$this->assertTrue( Uninstaller::should_remove() );
	}
}
