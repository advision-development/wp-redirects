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

	public function test_capture_in_external_target_cannot_change_host(): void {
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
}
