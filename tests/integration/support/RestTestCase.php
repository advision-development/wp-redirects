<?php

use Advision\Redirects\Plugin;

/**
 * Base class for REST tests. Registers controllers directly until Plugin wires
 * them (Task 15); after that it relies on the plugin's own registration.
 */
abstract class Adv_Redirects_Rest_TestCase extends WP_UnitTestCase {

	protected static $admin_id;

	protected static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id  = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$editor_id = $factory->user->create( [ 'role' => 'editor' ] );
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', [ $this, 'register_controllers' ] );
		rest_get_server();
		wp_set_current_user( self::$admin_id );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * @return object[] Controllers exposing register_routes().
	 */
	abstract protected function controllers(): array;

	public function register_controllers(): void {
		if ( method_exists( Plugin::class, 'register_routes' ) && false !== has_action( 'rest_api_init', [ Plugin::instance(), 'register_routes' ] ) ) {
			return;
		}
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	protected function rest( string $method, string $route, ?array $body = null, array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/adv-redirects/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}
}
