<?php

use Advision\Redirects\Import\YoastMapper;
use PHPUnit\Framework\TestCase;

final class YoastMapperTest extends TestCase {

	private static array $entries;

	public static function setUpBeforeClass(): void {
		$list = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		foreach ( $list as $index => $entry ) {
			self::$entries[ $index + 1 ] = [ 'id' => $index + 1 ] + $entry;
		}
	}

	private function map( int $id, bool $trailing_slash = true ): array {
		return YoastMapper::map( self::$entries[ $id ], $trailing_slash );
	}

	public function test_plain_redirect(): void {
		$this->assertSame(
			[
				'ok'        => true,
				'source_id' => 1,
				'rule'      => [
					'type'        => 'exact',
					'source'      => '/fy-old-page',
					'target'      => '/fy-new-page/',
					'status_code' => 301,
					'enabled'     => true,
					'note'        => '',
					'origin'      => 'manual',
				],
				'notes'     => [],
				'error'     => null,
			],
			$this->map( 1 )
		);
	}

	public function test_plain_sources(): void {
		$this->assertSame( '/fy-Case-Page', $this->map( 2 )['rule']['source'] );
		$this->assertSame( [], $this->map( 2 )['notes'], 'Plain rules get no case note.' );
		$this->assertSame( '/fy-query?ref=1', $this->map( 4 )['rule']['source'] );
		$this->assertSame( '/fy-caf%C3%A9', $this->map( 5 )['rule']['source'] );
		$this->assertSame( 'https://other.example.net/fy-asset.svg', $this->map( 16 )['rule']['source'], 'Absolute origins pass through; the Validator decides.' );
		$this->assertSame( '/', YoastMapper::plain_source( '/' ) );
		$this->assertSame( '/a/b', YoastMapper::plain_source( '/a/b' ) );
	}

	public function test_gone_statuses_have_no_target(): void {
		$this->assertSame( [ 410, null ], [ $this->map( 6 )['rule']['status_code'], $this->map( 6 )['rule']['target'] ] );
		$this->assertSame( [ 451, null ], [ $this->map( 7 )['rule']['status_code'], $this->map( 7 )['rule']['target'] ] );
	}

	public function test_targets(): void {
		$this->assertSame( 'https://external.example.net/page', $this->map( 8 )['rule']['target'] );
		$this->assertSame( 307, $this->map( 8 )['rule']['status_code'] );
		$this->assertSame( '/docs/fy-guide.pdf', $this->map( 9 )['rule']['target'], 'No slash after a file extension.' );
		$this->assertSame( '/fy-new/$1', $this->map( 17 )['rule']['target'], 'No slash after a capture.' );
		$this->assertSame( '/odds$1/fy-props/$2', $this->map( 22 )['rule']['target'] );
		$this->assertSame( '/fy-slashed-target/', $this->map( 26 )['rule']['target'] );
		$this->assertSame( '/fy-new-page', $this->map( 1, false )['rule']['target'], 'No slash when permalinks have none.' );

		$inline = static function ( string $url ): ?string {
			return YoastMapper::map( [ 'id' => 1, 'origin' => 'a', 'url' => $url, 'type' => 301, 'format' => 'plain' ], true )['rule']['target'];
		};
		$this->assertSame( '/page#top', $inline( 'page#top' ) );
		$this->assertSame( '/page?x=1', $inline( 'page?x=1' ) );
		$this->assertSame( '/', $inline( '/' ) );
		$this->assertSame( '/v2.0/page', $inline( 'v2.0/page' ), 'Like Yoast, a "." in any segment means no slash.' );
		$this->assertSame( '/a/b/', $inline( 'a/b' ) );
	}

	public function test_targets_with_another_scheme_are_kept_unchanged(): void {
		$inline = static function ( string $url ): ?string {
			return YoastMapper::map( [ 'id' => 1, 'origin' => 'a', 'url' => $url, 'type' => 301, 'format' => 'plain' ], true )['rule']['target'];
		};
		foreach ( [ 'mailto:someone@example.com', 'ftp://files.example.com/a', 'tel:+15551234', 'HTTPS://example.com/a', 'git+ssh://example.com/repo' ] as $url ) {
			$this->assertSame( $url, $inline( $url ), $url );
		}
	}

	public function test_case_dependent_regex_is_skipped(): void {
		$regex = static function ( string $origin ): array {
			return YoastMapper::map( [ 'id' => 1, 'origin' => $origin, 'url' => 'betting-odds/$1', 'type' => 301, 'format' => 'regex' ], true );
		};
		$this->assertSame( 'case_dependent_regex', $regex( '^/Odds/(.*)' )['error'] );
		$this->assertSame( 'case_dependent_regex', $regex( '^/odds/(.*)/News' )['error'] );
		foreach ( [ '^/odds/(\S+)', '^/odds/([A-Z]+)', '^/odds/(\P{Lu}+)', '^/odds/(\p{Lu}+)', '^/odds/[a-z]{2,3}/(.*)', '^/fy-regex/(.*)', '^/odds/\Q.\E(.*)' ] as $origin ) {
			$mapped = $regex( $origin );
			$this->assertTrue( $mapped['ok'], $origin );
			$this->assertContains( 'case_sensitive_source', $mapped['notes'], $origin );
		}
	}

	public function test_target_capture_of_a_group_starting_with_a_slash_is_skipped(): void {
		$regex = static function ( string $origin, string $url ): array {
			return YoastMapper::map( [ 'id' => 1, 'origin' => $origin, 'url' => $url, 'type' => 301, 'format' => 'regex' ], true );
		};
		$this->assertSame( 'unsupported_capture', $regex( '^(/[^/]+)/old', '$1/x' )['error'], '"/$1/x" would become "//nfl/x".' );
		$this->assertSame( 'unsupported_capture', $regex( '^(/[^/]+)/old', '/$1/x' )['error'] );
		$this->assertSame( 'unsupported_capture', $regex( '^(\/[^/]+)/old', '$1/x' )['error'] );
		$this->assertSame( 'unsupported_capture', $regex( '^/(?:a|b)([(])?(/x)', '$2' )['error'], 'Non-capturing groups and parentheses in a class are not counted.' );

		$bmr = $regex( '\/(mlb|nba|nfl)\/future-picks\/', '$1/futures' );
		$this->assertTrue( $bmr['ok'] );
		$this->assertSame( '/$1/futures', $bmr['rule']['target'] );
		$this->assertSame( '/$2/x', $regex( '^(/[^/]+)/(\w+)', '$2/x' )['rule']['target'], 'Group 2 does not start with a slash.' );
		$this->assertSame( '/odds$1/fy-props/$2', $this->map( 22 )['rule']['target'], 'A target that does not start with a capture is unaffected.' );
	}

	public function test_regex_rules_and_notes(): void {
		$mapped = $this->map( 17 );
		$this->assertSame( 'regex', $mapped['rule']['type'] );
		$this->assertSame( '^/fy-regex/(.*)', $mapped['rule']['source'] );
		$this->assertSame( [ 'case_sensitive_source' ], $mapped['notes'] );
		$this->assertSame( [ 'case_sensitive_source', 'regex_query' ], $this->map( 18 )['notes'] );
		$this->assertSame( '/fy-find/', $this->map( 18 )['rule']['target'] );
	}

	public function test_skip_reasons(): void {
		$this->assertSame( 'unsupported_status', $this->map( 14 )['error'] );
		$this->assertSame( 'invalid_entry', $this->map( 15 )['error'], 'A 301 needs a target.' );
		$this->assertSame( 'unreachable_regex', $this->map( 19 )['error'] );
		$this->assertSame( 'unsupported_capture', $this->map( 20 )['error'] );
		$this->assertSame( 'unsupported_capture', $this->map( 21 )['error'] );
		$this->assertSame( 'invalid_entry', $this->map( 24 )['error'] );
		$this->assertSame( 24, $this->map( 24 )['source_id'] );
		$this->assertFalse( $this->map( 24 )['ok'] );
		$this->assertNull( $this->map( 24 )['rule'] );
	}

	public function test_unreachable_regex_variants(): void {
		foreach ( [ 'https://x.test/(.*)', '^https://x.test/(.*)', '^HTTP://x.test/', '^https?://x.test/(.*)', 'https?://x.test/', '^(https?://)x', '(?:https?://)x' ] as $origin ) {
			$mapped = YoastMapper::map( [ 'id' => 1, 'origin' => $origin, 'url' => 'a', 'type' => 301, 'format' => 'regex' ], true );
			$this->assertSame( 'unreachable_regex', $mapped['error'], $origin );
		}
	}

	public function test_numeric_string_type_is_accepted(): void {
		$this->assertTrue( $this->map( 25 )['ok'] );
		$this->assertSame( 301, $this->map( 25 )['rule']['status_code'] );
	}

	public function test_malformed_entries_are_invalid(): void {
		$bad = [
			'not an array',
			null,
			[ 'id' => 1 ],
			[ 'id' => 1, 'origin' => '', 'url' => 'a', 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => '   ', 'url' => 'a', 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => [ 'x' ], 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => '301.0', 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => 'PLAIN' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => [ 'plain' ] ],
			[ 'id' => 1, 'origin' => [ 'a' ], 'url' => 'b', 'type' => 301, 'format' => 'plain' ],
		];
		foreach ( $bad as $index => $entry ) {
			$this->assertSame( 'invalid_entry', YoastMapper::map( $entry, true )['error'], "case {$index}" );
		}
		$this->assertSame( 0, YoastMapper::map( [ 'origin' => 'a' ], true )['source_id'] );
	}
}
