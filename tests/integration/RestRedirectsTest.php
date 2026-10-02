<?php

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\RedirectsController;
use Advision\Redirects\Rest\TestController;

final class RestRedirectsTest extends Adv_Redirects_Rest_TestCase {

	protected function controllers(): array {
		$repo   = new Repository();
		$chains = new ChainResolver();
		$cache  = new RuleCache( $repo );
		return [
			new RedirectsController( $repo, new Validator( $repo, $chains ), $cache, $chains ),
			new TestController( $cache, $chains ),
		];
	}

	private function create( array $body ): WP_REST_Response {
		return $this->rest( 'POST', '/redirects', $body );
	}

	public function test_permission_matrix(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/redirects' )->get_status() );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'GET', '/redirects' )->get_status() );
		$this->assertSame( 403, $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_status() );

		wp_set_current_user( self::$admin_id );
		$this->assertSame( 200, $this->rest( 'GET', '/redirects' )->get_status() );
	}

	public function test_capability_filter(): void {
		add_filter(
			'adv_redirects_capability',
			static function () {
				return 'edit_posts';
			}
		);
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 200, $this->rest( 'GET', '/redirects' )->get_status() );
	}

	public function test_create_and_list(): void {
		$response = $this->create( [ 'type' => 'exact', 'source' => '/old', 'target' => '/new', 'status_code' => 301, 'note' => 'hi' ] );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '/old', $data['rule']['source'] );
		$this->assertSame( [], $data['warnings'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( 'hi', $list[0]['note'] );
		$this->assertNull( $list[0]['chain'] );
	}

	public function test_schema_and_unknown_fields_rejected(): void {
		$this->assertSame( 400, $this->create( [ 'type' => 'glob', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 303 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'source' => str_repeat( 'a', 2049 ), 'target' => '/b', 'status_code' => 301 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'target' => '/b', 'status_code' => 301 ] )->get_status() );

		$response = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'hits' => 5000 ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'] );
	}

	public function test_validator_errors_surface_with_codes(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );

		$dupe = $this->create( [ 'type' => 'exact', 'source' => '/A/', 'target' => '/c', 'status_code' => 301 ] );
		$this->assertSame( 409, $dupe->get_status() );
		$this->assertSame( 'adv_redirects_duplicate', $dupe->get_data()['code'] );

		$loop = $this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$this->assertSame( 422, $loop->get_status() );
		$this->assertSame( 'adv_redirects_loop', $loop->get_data()['code'] );

		$unsafe = $this->create( [ 'type' => 'exact', 'source' => '/x', 'target' => '//evil.com', 'status_code' => 301 ] );
		$this->assertSame( 'adv_redirects_invalid_target', $unsafe->get_data()['code'] );
	}

	public function test_chain_warning_and_list_chain_info(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 301 ] );
		$response = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertSame( 'chain', $response->get_data()['warnings'][0]['code'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$a    = current( wp_list_filter( $list, [ 'source' => '/a' ] ) );
		$this->assertSame( '/c', $a['chain']['final'] );
	}

	public function test_update_and_delete(): void {
		$id = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$updated = $this->rest( 'PUT', "/redirects/{$id}", [ 'target' => '/c', 'enabled' => false ] );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( '/c', $updated->get_data()['rule']['target'] );
		$this->assertFalse( $updated->get_data()['rule']['enabled'] );

		$this->assertSame( 404, $this->rest( 'PUT', '/redirects/999999', [ 'target' => '/c' ] )->get_status() );

		$deleted = $this->rest( 'DELETE', "/redirects/{$id}" );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'DELETE', "/redirects/{$id}" )->get_status() );
	}

	public function test_bulk_enable_skips_rules_that_would_loop(): void {
		$a = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];
		$b = $this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301, 'enabled' => false ] )->get_data()['rule']['id'];

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'enable', 'ids' => [ $b ] ] )->get_data();
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 'adv_redirects_loop', $result['skipped'][0]['code'] );

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'disable', 'ids' => [ $a ] ] )->get_data();
		$this->assertSame( 1, $result['updated'] );

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'delete', 'ids' => [ $a, $b ] ] )->get_data();
		$this->assertSame( 2, $result['updated'] );
		$this->assertSame( [], $this->rest( 'GET', '/redirects' )->get_data() );
	}

	public function test_bulk_limits(): void {
		$this->assertSame( 400, $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'enable', 'ids' => range( 1, 501 ) ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'explode', 'ids' => [ 1 ] ] )->get_status() );
	}

	public function test_reorder(): void {
		$x = $this->create( [ 'type' => 'regex', 'source' => '^/x$', 'target' => '/x1', 'status_code' => 301 ] )->get_data()['rule']['id'];
		$y = $this->create( [ 'type' => 'regex', 'source' => '^/y$', 'target' => '/y1', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$this->rest( 'POST', '/redirects/reorder', [ 'ids' => [ $y, $x ] ] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertSame( [ $y, $x ], array_column( wp_list_filter( $list, [ 'type' => 'regex' ] ), 'id' ) );
	}

	public function test_test_endpoint(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 302 ] );

		$hit = $this->rest( 'POST', '/test', [ 'path' => '/a?x=1' ] )->get_data();
		$this->assertTrue( $hit['matched'] );
		$this->assertSame( 301, $hit['status'] );
		$this->assertSame( 'http://example.org/b?x=1', $hit['target_url'] );
		// Hops after the first resolved URL are the raw rule targets, with the request query forwarded.
		$this->assertSame( [ '/a?x=1', 'http://example.org/b?x=1', '/c?x=1' ], $hit['hops'] );
		$this->assertSame( '/c?x=1', $hit['final'] );
		$this->assertFalse( $hit['loop'] );

		$miss = $this->rest( 'POST', '/test', [ 'path' => '/nothing' ] )->get_data();
		$this->assertFalse( $miss['matched'] );
		$this->assertSame( 'no_match', $miss['reason'] );

		$external = $this->rest( 'POST', '/test', [ 'path' => 'https://other.org/a' ] )->get_data();
		$this->assertSame( 'external', $external['reason'] );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'POST', '/test', [ 'path' => '/a' ] )->get_status() );
	}
}
