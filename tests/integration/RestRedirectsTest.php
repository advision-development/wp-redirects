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
		$this->assertFalse( $list[0]['trailing_slash'] );
	}

	public function test_trailing_slash_field(): void {
		$created = $this->create( [ 'type' => 'regex', 'source' => '^/forum/(.*)', 'target' => '/forum/$1', 'status_code' => 301, 'trailing_slash' => true ] );
		$this->assertSame( 201, $created->get_status() );
		$rule = $created->get_data()['rule'];
		$this->assertTrue( $rule['trailing_slash'] );

		$kept = $this->rest( 'PUT', '/redirects/' . $rule['id'], [ 'note' => 'edited' ] )->get_data()['rule'];
		$this->assertTrue( $kept['trailing_slash'], 'Left out on update, it keeps its value.' );
		$off = $this->rest( 'PUT', '/redirects/' . $rule['id'], [ 'trailing_slash' => false ] )->get_data()['rule'];
		$this->assertFalse( $off['trailing_slash'] );

		$bad = $this->create( [ 'type' => 'exact', 'source' => '/ts', 'target' => '/b', 'status_code' => 301, 'trailing_slash' => 'sometimes' ] );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'rest_invalid_param', $bad->get_data()['code'] );

		$test = $this->rest( 'POST', '/test', [ 'path' => '/forum/abc' ] )->get_data();
		$this->assertFalse( $test['matched'], 'The flag is off again, so /forum/abc resolves to itself.' );
		$this->rest( 'PUT', '/redirects/' . $rule['id'], [ 'trailing_slash' => true ] );
		$test = $this->rest( 'POST', '/test', [ 'path' => '/forum/abc' ] )->get_data();
		$this->assertSame( 'http://example.org/forum/abc/', $test['target_url'] );
		$this->assertSame( [ '/forum/abc', 'http://example.org/forum/abc/' ], $test['hops'], '/forum/abc/ resolves to itself: the chain ends.' );
		$this->assertSame( 'self', $this->rest( 'POST', '/test', [ 'path' => '/forum/abc/' ] )->get_data()['reason'] );
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

	public function test_rules_carry_attribution(): void {
		$admin = get_userdata( self::$admin_id );
		$data  = $this->create( [ 'type' => 'exact', 'source' => '/attr', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule'];
		$this->assertSame( $admin->user_login, $data['created_by_name'] );
		$this->assertSame( self::$admin_id, $data['created_by'] );
		$this->assertSame( 'manual', $data['created_via'] );
		$this->assertNull( $data['updated_by_name'] );

		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $other );
		$updated = $this->rest( 'PUT', '/redirects/' . $data['id'], [ 'note' => 'edited' ] )->get_data()['rule'];
		$this->assertSame( get_userdata( $other )->user_login, $updated['updated_by_name'] );
		$this->assertSame( $admin->user_login, $updated['created_by_name'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertSame( get_userdata( $other )->user_login, $list[0]['updated_by_name'] );
	}

	public function test_rule_whose_creator_was_deleted_says_deleted_user(): void {
		$temp = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $temp );
		$id = $this->create( [ 'type' => 'exact', 'source' => '/orphan', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $temp );
		wp_set_current_user( self::$admin_id );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$rule = current( wp_list_filter( $list, [ 'id' => $id ] ) );
		$this->assertSame( 'Deleted user', $rule['created_by_name'] );
	}

	public function test_created_via_is_not_accepted_from_the_request(): void {
		$response = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'created_via' => 'import' ] );
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

	public function test_test_endpoint_reports_a_self_redirect_as_not_redirected(): void {
		$id = $this->create( [ 'type' => 'regex', 'source' => '^/fy-self/(.*)', 'target' => '/fy-self/$1', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$result = $this->rest( 'POST', '/test', [ 'path' => '/fy-self/x' ] )->get_data();
		$this->assertFalse( $result['matched'] );
		$this->assertSame( 'self', $result['reason'] );
		$this->assertSame( $id, $result['rule_id'] );
		$this->assertNull( $result['target_url'] );
		$this->assertSame( [], $result['hops'] );

		$this->create( [ 'type' => 'exact', 'source' => '/NFL', 'target' => '/nfl', 'status_code' => 301 ] );
		$fix = $this->rest( 'POST', '/test', [ 'path' => '/NFL' ] )->get_data();
		$this->assertTrue( $fix['matched'], 'A case fix still redirects.' );
		$this->assertSame( [ '/NFL', 'http://example.org/nfl' ], $fix['hops'] );
		$this->assertFalse( $fix['loop'] );
		$this->assertSame( 'self', $this->rest( 'POST', '/test', [ 'path' => '/nfl' ] )->get_data()['reason'] );
	}

	public function test_test_endpoint_skips_paths_the_runtime_never_handles(): void {
		$this->create( [ 'type' => 'regex', 'source' => '^/(.*)$', 'target' => 'https://new.com/$1', 'status_code' => 301 ] );

		$this->assertTrue( $this->rest( 'POST', '/test', [ 'path' => '/anything' ] )->get_data()['matched'] );

		foreach ( [ '/wp-admin/', '/wp-login.php', '/?rest_route=/wp/v2/posts' ] as $path ) {
			$result = $this->rest( 'POST', '/test', [ 'path' => $path ] )->get_data();
			$this->assertFalse( $result['matched'], $path );
			$this->assertSame( 'reserved', $result['reason'], $path );
		}
	}

	public function test_chain_walk_stops_at_reserved_hops(): void {
		$this->create( [ 'type' => 'regex', 'source' => '^/(.*)$', 'target' => 'https://new.com/$1', 'status_code' => 301 ] );

		$response = $this->create( [ 'type' => 'exact', 'source' => '/go', 'target' => '/wp-admin/', 'status_code' => 301 ] );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( [], $response->get_data()['warnings'], 'The runtime never redirects /wp-admin, so there is no second hop.' );

		$result = $this->rest( 'POST', '/test', [ 'path' => '/go' ] )->get_data();
		$this->assertSame( [ '/go', 'http://example.org/wp-admin/' ], $result['hops'] );
		$this->assertFalse( $result['loop'] );

		$rest = $this->create( [ 'type' => 'exact', 'source' => '/api', 'target' => '/?rest_route=/wp/v2/posts', 'status_code' => 301 ] );
		$this->assertSame( [], $rest->get_data()['warnings'] );
	}

	public function test_unknown_fields_rejected_on_bulk_reorder_and_test(): void {
		$id = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$bulk = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'delete', 'ids' => [ $id ], 'force' => true ] );
		$this->assertSame( 400, $bulk->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $bulk->get_data()['code'] );
		$this->assertCount( 1, $this->rest( 'GET', '/redirects' )->get_data() );

		$reorder = $this->rest( 'POST', '/redirects/reorder', [ 'ids' => [ $id ], 'extra' => 1 ] );
		$this->assertSame( 400, $reorder->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $reorder->get_data()['code'] );

		$test = $this->rest( 'POST', '/test', [ 'path' => '/a', 'extra' => 1 ] );
		$this->assertSame( 400, $test->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $test->get_data()['code'] );
	}

	public function test_path_id_is_not_overridden_by_query_id(): void {
		$first  = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];
		$second = $this->create( [ 'type' => 'exact', 'source' => '/c', 'target' => '/d', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$deleted = $this->rest( 'DELETE', "/redirects/{$first}", null, [ 'id' => $second ] );
		$this->assertSame( $first, $deleted->get_data()['rule']['id'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertSame( [ $second ], array_column( $list, 'id' ) );

		$updated = $this->rest( 'PUT', "/redirects/{$second}", [ 'target' => '/e' ], [ 'id' => $first ] );
		$this->assertSame( $second, $updated->get_data()['rule']['id'] );
	}
}
