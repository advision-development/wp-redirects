<?php

use Advision\Redirects\Matching\UrlSafety;
use PHPUnit\Framework\TestCase;

final class UrlSafetyTest extends TestCase {

	/** @dataProvider safe_urls */
	public function test_accepts( string $url ): void {
		$this->assertTrue( UrlSafety::is_safe( $url ) );
	}

	public function safe_urls(): array {
		return [
			[ '/' ],
			[ '/new-page/' ],
			[ '/new?x=1&y=2#top' ],
			[ '/caf%C3%A9' ],
			[ '/über' ],
			[ 'https://example.com/a' ],
			[ 'HTTP://EXAMPLE.COM' ],
			[ 'https://other.example.org:8443/x?y=1' ],
		];
	}

	/** @dataProvider unsafe_urls */
	public function test_rejects( string $url ): void {
		$this->assertFalse( UrlSafety::is_safe( $url ) );
	}

	public function unsafe_urls(): array {
		return [
			'empty'               => [ '' ],
			'protocol relative'   => [ '//evil.com' ],
			'backslash trick'     => [ '/\\evil.com' ],
			'backslash scheme'    => [ '\\\\evil.com' ],
			'javascript'          => [ 'javascript:alert(1)' ],
			'data'                => [ 'data:text/html,hi' ],
			'vbscript'            => [ 'vbscript:x' ],
			'file'                => [ 'file:///etc/passwd' ],
			'ftp'                 => [ 'ftp://example.com' ],
			'crlf'                => [ "/a\r\nLocation: https://evil.com" ],
			'tab'                 => [ "/a\tb" ],
			'space'               => [ '/a b' ],
			'nul'                 => [ "/a\0" ],
			'no slash relative'   => [ 'new-page' ],
			'userinfo'            => [ 'https://good.com@evil.com/' ],
			'scheme without host' => [ 'https:/evil.com' ],
			'overlong'            => [ '/' . str_repeat( 'a', 2048 ) ],
		];
	}

	public function test_host_of(): void {
		$this->assertSame( 'example.com', UrlSafety::host_of( '/x', 'Example.com' ) );
		$this->assertSame( 'other.org', UrlSafety::host_of( 'https://OTHER.org/x', 'example.com' ) );
		$this->assertSame( '', UrlSafety::host_of( 'https://', 'example.com' ) );
	}
}
