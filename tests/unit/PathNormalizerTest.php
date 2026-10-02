<?php

use Advision\Redirects\Matching\PathNormalizer;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase {

	public function test_splits_path_and_query(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame(
			[ 'path' => '/Old-Page/', 'key' => '/old-page', 'query' => 'a=1&b=2' ],
			$n->from_request_uri( '/Old-Page/?a=1&b=2' )
		);
	}

	public function test_root_key_stays_slash(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame( '/', $n->from_request_uri( '/' )['key'] );
		$this->assertSame( '/', PathNormalizer::key( '/' ) );
		$this->assertSame( '/', PathNormalizer::key( '' ) );
	}

	public function test_fragment_is_dropped(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame( '/a', $n->from_request_uri( '/a#frag' )['path'] );
	}

	public function test_subdirectory_install_strips_home_path(): void {
		$n = new PathNormalizer( '/blog' );
		$this->assertSame( '/old', $n->from_request_uri( '/blog/old' )['path'] );
		$this->assertSame( '/', $n->from_request_uri( '/blog' )['path'] );
		$this->assertSame( '/', $n->from_request_uri( '/blog/' )['key'] );
		$this->assertNull( $n->from_request_uri( '/blogger/old' ) );
		$this->assertNull( $n->from_request_uri( '/other' ) );
	}

	public function test_unicode_and_encoded_paths_share_a_key(): void {
		$n = new PathNormalizer( '' );
		$expected = PathNormalizer::source_key( '/café' );
		$this->assertSame( $expected, $n->from_request_uri( '/caf%C3%A9' )['key'] );
		$this->assertSame( $expected, $n->from_request_uri( '/CAFÉ' )['key'] );
		$this->assertSame( $expected, $n->from_request_uri( '/café/' )['key'] );
	}

	public function test_rejects_overlong_and_control_characters(): void {
		$n = new PathNormalizer( '' );
		$this->assertNull( $n->from_request_uri( '/' . str_repeat( 'a', 2048 ) ) );
		$this->assertNull( $n->from_request_uri( '/a%0D%0ALocation:%20x' ) );
		$this->assertNull( $n->from_request_uri( '/a%00b' ) );
		$this->assertNull( $n->from_request_uri( '' ) );
	}

	public function test_source_key_with_query(): void {
		$this->assertSame( '/old?x=1', PathNormalizer::source_key( '/Old/?x=1' ) );
		$this->assertSame( '/old', PathNormalizer::source_key( '/OLD/' ) );
	}
}
