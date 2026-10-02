<?php

use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ValidatorTest extends WP_UnitTestCase {

	private Repository $repo;
	private Validator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->repo      = new Repository();
		$this->validator = new Validator( $this->repo, new ChainResolver() );
	}

	private function valid( array $input, ?int $id = null ): array {
		$result = $this->validator->validate( $input, $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function error_code( array $input, ?int $id = null ): string {
		$result = $this->validator->validate( $input, $id );
		$this->assertWPError( $result );
		return $result->get_error_code();
	}

	private function save( array $input ): int {
		return $this->repo->insert( $this->valid( $input )['data'] )->id;
	}

	public function test_valid_exact_rule(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => ' /old ', 'target' => '/new', 'status_code' => 301 ] );
		$this->assertSame( '/old', $result['data']['source'] );
		$this->assertSame( '/new', $result['data']['target'] );
		$this->assertTrue( $result['data']['enabled'] );
		$this->assertSame( [], $result['warnings'] );
	}

	public function test_own_host_absolute_source_becomes_path(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => 'http://example.org/old?x=1', 'target' => '/new', 'status_code' => 301 ] );
		$this->assertSame( '/old?x=1', $result['data']['source'] );
	}

	public function test_invalid_sources(): void {
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => 'old', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => 'https://other.org/old', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => '//example.org/x', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => '', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => "/a\nb", 'target' => '/new', 'status_code' => 301 ] ) );
	}

	public function test_reserved_sources_rejected(): void {
		foreach ( [ '/wp-login.php', '/wp-admin', '/WP-ADMIN/options.php', '/wp-json/wp/v2/users', '/xmlrpc.php' ] as $source ) {
			$this->assertSame(
				'adv_redirects_reserved_source',
				$this->error_code( [ 'type' => 'exact', 'source' => $source, 'target' => '/new', 'status_code' => 301 ] ),
				$source
			);
		}
	}

	public function test_invalid_regex_rejected(): void {
		$this->assertSame( 'adv_redirects_invalid_regex', $this->error_code( [ 'type' => 'regex', 'source' => '^/old/(.*$', 'target' => '/new', 'status_code' => 301 ] ) );
	}

	public function test_target_rules(): void {
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '/x', 'status_code' => 410 ] ) );
		foreach ( [ '//evil.com', 'javascript:alert(1)', "/a\r\nLocation: x", '/\\evil.com', 'https://good.com@evil.com' ] as $target ) {
			$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => $target, 'status_code' => 301 ] ), $target );
		}
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'regex', 'source' => '^/(.*)$', 'target' => 'https://$1.example.com/', 'status_code' => 301 ] ) );

		$gone = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => null, 'status_code' => 451 ] );
		$this->assertNull( $gone['data']['target'] );
	}

	public function test_capture_directly_after_host_is_allowed(): void {
		$result = $this->valid( [ 'type' => 'regex', 'source' => '^(/.*)$', 'target' => 'https://new.com$1', 'status_code' => 301 ] );
		$this->assertSame( 'https://new.com$1', $result['data']['target'] );
	}

	public function test_invalid_status_and_type(): void {
		$this->assertSame( 'adv_redirects_invalid_status', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 303 ] ) );
		$this->assertSame( 'adv_redirects_invalid_type', $this->error_code( [ 'type' => 'glob', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] ) );
	}

	public function test_duplicate_detection_ignores_case_and_trailing_slash(): void {
		$id     = $this->save( [ 'type' => 'exact', 'source' => '/old-page', 'target' => '/new', 'status_code' => 301 ] );
		$result = $this->validator->validate( [ 'type' => 'exact', 'source' => '/Old-Page/', 'target' => '/other', 'status_code' => 301 ] );
		$this->assertWPError( $result );
		$this->assertSame( 'adv_redirects_duplicate', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $id, $result->get_error_data()['existing_id'] );

		// Updating the same rule is not a duplicate of itself.
		$this->valid( [ 'source' => '/OLD-PAGE' ], $id );
	}

	public function test_self_redirect_rejected(): void {
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a/', 'target' => 'http://example.org/A', 'status_code' => 301 ] ) );
	}

	public function test_loop_rejected_with_path(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$result = $this->validator->validate( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertWPError( $result );
		$this->assertSame( 'adv_redirects_loop', $result->get_error_code() );
		$this->assertSame( 'Creates a loop: /a → /b → /a', $result->get_error_message() );
	}

	public function test_loop_through_regex_rejected(): void {
		$this->save( [ 'type' => 'regex', 'source' => '^/news/(.*)$', 'target' => '/blog/$1', 'status_code' => 301 ] );
		$this->assertSame( 'adv_redirects_loop', $this->error_code( [ 'type' => 'exact', 'source' => '/blog/x', 'target' => '/news/x', 'status_code' => 301 ] ) );
	}

	public function test_chain_returns_warning(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 301 ] );
		$result = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertSame( [ [ 'code' => 'chain', 'hops' => [ '/a', '/b', '/c' ], 'final' => '/c' ] ], $result['warnings'] );
	}

	public function test_disabled_rules_skip_loop_check(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'enabled' => false ] );
	}

	public function test_capture_targets_skip_loop_check(): void {
		$this->valid( [ 'type' => 'regex', 'source' => '^/a/(.*)$', 'target' => '/a/$1', 'status_code' => 301 ] );
	}

	public function test_partial_update_merges_existing_values(): void {
		$id     = $this->save( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 302, 'note' => 'keep' ] );
		$result = $this->valid( [ 'target' => '/c' ], $id );
		$this->assertSame( '/a', $result['data']['source'] );
		$this->assertSame( '/c', $result['data']['target'] );
		$this->assertSame( 302, $result['data']['status_code'] );
		$this->assertSame( 'keep', $result['data']['note'] );
	}

	public function test_missing_rule_on_update(): void {
		$this->assertSame( 'adv_redirects_not_found', $this->error_code( [ 'target' => '/c' ], 999999 ) );
	}

	public function test_custom_validation_filter(): void {
		add_filter(
			'adv_redirects_validate_rule',
			static function ( $valid, array $data ) {
				return '/blocked' === $data['source'] ? new WP_Error( 'custom', 'Nope', [ 'status' => 422 ] ) : $valid;
			},
			10,
			2
		);
		$this->assertSame( 'custom', $this->error_code( [ 'type' => 'exact', 'source' => '/blocked', 'target' => '/b', 'status_code' => 301 ] ) );
	}

	public function test_note_is_sanitized_and_truncated(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'note' => '<b>hi</b>' . str_repeat( 'x', 300 ) ] );
		$this->assertStringStartsWith( 'hi', $result['data']['note'] );
		$this->assertSame( 255, mb_strlen( $result['data']['note'] ) );
	}
}
