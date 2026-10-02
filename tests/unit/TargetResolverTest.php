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
}
