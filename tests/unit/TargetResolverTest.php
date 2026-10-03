<?php

use Advision\Redirects\Matching\TargetResolver;
use PHPUnit\Framework\TestCase;

final class TargetResolverTest extends TestCase {

	private function resolver( array $allowed = [] ): TargetResolver {
		return new TargetResolver( 'https://example.com', $allowed );
	}

	public function test_relative_target_becomes_absolute(): void {
		$this->assertSame( 'https://example.com/new', $this->resolver()->resolve( '/new', [], '', false ) );
	}

	public function test_relative_target_resolves_against_subdirectory_home(): void {
		$r = new TargetResolver( 'https://example.com/blog' );
		$this->assertSame( 'https://example.com/blog/new', $r->resolve( '/new', [], '', false ) );
	}

	public function test_absolute_external_target_kept(): void {
		$this->assertSame( 'https://other.org/x', $this->resolver()->resolve( 'https://other.org/x', [], '', false ) );
	}

	public function test_capture_substitution_encodes_segments(): void {
		$captures = [ '/old/a b/c', 'a b/c' ];
		$this->assertSame(
			'https://example.com/new/a%20b/c',
			$this->resolver()->resolve( '/new/$1', $captures, '', false )
		);
	}

	public function test_missing_capture_becomes_empty(): void {
		$this->assertSame( 'https://example.com/new/', $this->resolver()->resolve( '/new/$2', [ '/x', 'y' ], '', false ) );
	}

	public function test_relative_template_starting_with_a_capture_stays_on_site(): void {
		$this->assertSame( 'https://example.com/blog/', $this->resolver()->resolve( '/$1/', [ '/blog/x/foo/y', 'blog' ], '', false ) );
		$this->assertSame( 'https://example.com/a/b', $this->resolver()->resolve( '/$1$2', [ '/a/b', 'a', '/b' ], '', false ) );
	}

	public function test_relative_template_with_capture_still_rejects_host_change(): void {
		// "/$1/" with capture "/evil.com" would produce "//evil.com/".
		$this->assertNull( $this->resolver()->resolve( '/$1/', [ '/x', '/evil.com' ], '', false ) );
	}

	public function test_capture_cannot_change_host(): void {
		// Rule "^/go/(.*)$" → "/$1" hit with "/go//evil.com".
		$this->assertNull( $this->resolver()->resolve( '/$1', [ '/go//evil.com', '/evil.com' ], '', false ) );
		// Backslashes are percent-encoded, so the result stays on the site's own host.
		$this->assertSame(
			'https://example.com/%5Cevil.com',
			$this->resolver()->resolve( '/$1', [ '/go/\\evil.com', '\\evil.com' ], '', false )
		);
	}

	public function test_capture_directly_after_external_host_is_allowed(): void {
		$this->assertSame(
			'https://new.com/a',
			$this->resolver()->resolve( 'https://new.com$1', [ '/a', '/a' ], '', false )
		);
	}

	public function test_capture_in_external_target_cannot_change_host(): void {
		// "@" is percent-encoded, so the host no longer matches the template's host.
		$this->assertNull(
			$this->resolver()->resolve( 'https://new.com$1', [ '@evil.com', '@evil.com' ], '', false )
		);
		// A leading dot would extend the host to new.com.evil.com.
		$this->assertNull(
			$this->resolver()->resolve( 'https://new.com$1', [ '.evil.com', '.evil.com' ], '', false )
		);
		// Port/userinfo-style injection is rejected too.
		$this->assertNull(
			$this->resolver()->resolve( 'https://other.org$1', [ '/x@evil.com', '@evil.com' ], '', false )
		);
	}

	public function test_query_forwarding_merges_with_target_winning(): void {
		$this->assertSame(
			'https://example.com/new?a=target&b=2',
			$this->resolver()->resolve( '/new?a=target', [], 'a=incoming&b=2', true )
		);
	}

	public function test_query_not_forwarded_when_disabled(): void {
		$this->assertSame( 'https://example.com/new', $this->resolver()->resolve( '/new', [], 'a=1', false ) );
	}

	public function test_merge_query_keeps_fragment_last(): void {
		$this->assertSame( 'https://example.com/new?utm=x#top', $this->resolver()->resolve( '/new#top', [], 'utm=x', true ) );
	}

	public function test_allowlist_blocks_unknown_external_hosts(): void {
		$r = $this->resolver( [ 'partner.com' ] );
		$this->assertNull( $r->resolve( 'https://other.org/', [], '', false ) );
		$this->assertSame( 'https://partner.com/', $r->resolve( 'https://partner.com/', [], '', false ) );
		$this->assertSame( 'https://example.com/local', $r->resolve( '/local', [], '', false ) );
	}

	public function test_unsafe_template_rejected(): void {
		$this->assertNull( $this->resolver()->resolve( 'javascript:alert(1)', [], '', false ) );
	}

	public function test_forwarded_query_is_kept_byte_for_byte(): void {
		$this->assertSame(
			'https://example.com/new?utm.source=x&a%20b=1',
			$this->resolver()->resolve( '/new', [], 'utm.source=x&a%20b=1', true )
		);
	}

	public function test_repeated_keys_and_valueless_flags_are_preserved(): void {
		$this->assertSame(
			'https://example.com/new?tag=a&tag=b',
			$this->resolver()->resolve( '/new', [], 'tag=a&tag=b', true )
		);
		$this->assertSame(
			'https://example.com/new?flag&x=1',
			$this->resolver()->resolve( '/new?flag', [], 'x=1', true )
		);
		$this->assertSame(
			'https://example.com/new?a%20b=1&c=3',
			$this->resolver()->resolve( '/new?a%20b=1', [], 'a b=2&c=3', true )
		);
	}

	public function test_overlong_result_after_query_merge_is_rejected(): void {
		$this->assertNull( $this->resolver()->resolve( '/new', [], 'a=' . str_repeat( 'x', 2100 ), true ) );
	}

	public function test_is_self_matches_a_byte_identical_url(): void {
		$this->assertTrue( TargetResolver::is_self( 'https://example.com/forum/x', 'https://example.com', '/forum/x', '' ) );
		$this->assertTrue( TargetResolver::is_self( 'https://example.com/a?b=1', 'https://example.com', '/a', 'b=1' ) );
		$this->assertTrue( TargetResolver::is_self( 'https://example.com/', 'https://example.com', '/', '' ) );
		$this->assertTrue( TargetResolver::is_self( '/odds/', '', '/odds/', '' ), 'Paths compare without a base.' );
	}

	public function test_is_self_decodes_the_target_path_like_the_request_path(): void {
		// A capture re-encoded by substitute() is the same URL as the decoded request path it came from.
		$url = $this->resolver()->resolve( '/forum/$1', [ '/forum/café x', 'café x' ], '', false );
		$this->assertSame( 'https://example.com/forum/caf%C3%A9%20x', $url );
		$this->assertTrue( TargetResolver::is_self( $url, 'https://example.com', '/forum/café x', '' ) );
	}

	public function test_is_self_is_case_and_slash_sensitive(): void {
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/nfl', 'https://example.com', '/NFL', '' ), 'A case fix still redirects.' );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/foo/', 'https://example.com', '/foo', '' ), 'A slash fix still redirects.' );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/foo', 'https://example.com', '/foo/', '' ) );
		$this->assertFalse( TargetResolver::is_self( '/Foo', '', '/foo', '' ) );
	}

	public function test_is_self_compares_the_raw_query_and_the_whole_base(): void {
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/a?b=1', 'https://example.com', '/a', '' ) );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/a', 'https://example.com', '/a', 'b=1' ) );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/a?b=2', 'https://example.com', '/a', 'b=1' ) );
		$this->assertFalse( TargetResolver::is_self( 'http://example.com/a', 'https://example.com', '/a', '' ), 'An http to https redirect is not a self-redirect.' );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com.evil/a', 'https://example.com', '/a', '' ) );
		$this->assertFalse( TargetResolver::is_self( 'https://example.com/a#top', 'https://example.com', '/a', '' ) );
		$this->assertFalse( TargetResolver::is_self( '//example.com/a', '', '//example.com/a', '' ), 'A protocol-relative URL is not a path.' );
		$this->assertTrue( TargetResolver::is_self( 'https://example.com/blog/a', 'https://example.com/blog/', '/a', '' ), 'A subdirectory home is part of the base.' );
	}
}
