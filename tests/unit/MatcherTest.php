<?php

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RulesetCompiler;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	private function row( int $id, string $type, string $source, ?string $target, int $status = 301, int $position = 0, int $enabled = 1 ): array {
		return [
			'id'          => (string) $id,
			'type'        => $type,
			'source'      => $source,
			'target'      => $target,
			'status_code' => (string) $status,
			'position'    => (string) $position,
			'enabled'     => (string) $enabled,
		];
	}

	private function req( string $uri ): array {
		return ( new PathNormalizer( '' ) )->from_request_uri( $uri );
	}

	public function test_compile_shapes_ruleset(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'exact', '/Old/', '/new' ),
				$this->row( 2, 'exact', '/q?x=1', '/q-new' ),
				$this->row( 3, 'regex', '^/b/(\d+)$', '/p/$1', 302, 2 ),
				$this->row( 4, 'regex', '^/b/.*$', '/b', 301, 1 ),
				$this->row( 5, 'exact', '/off', '/x', 301, 0, 0 ),
				$this->row( 6, 'exact', '/gone', null, 410 ),
			]
		);
		$this->assertSame( [ 'id' => 1, 'target' => '/new', 'status' => 301, 'trailing_slash' => false ], $rs['exact']['/old'] );
		$this->assertArrayHasKey( '/q?x=1', $rs['exact'] );
		$this->assertArrayNotHasKey( '/off', $rs['exact'] );
		$this->assertNull( $rs['exact']['/gone']['target'] );
		$this->assertTrue( $rs['has_query'] );
		$this->assertSame( [ 4, 3 ], array_column( $rs['regex'], 'id' ) );
		$this->assertSame( '~^/b/.*$~i', $rs['regex'][0]['pattern'] );
	}

	public function test_trailing_slash_flag_is_compiled_and_passed_to_the_match(): void {
		$rows = [
			[ 'trailing_slash' => '1' ] + $this->row( 1, 'regex', '^/forum/(.*)', '/forum/$1' ),
			[ 'trailing_slash' => '1' ] + $this->row( 2, 'exact', '/a', '/b' ),
			$this->row( 3, 'exact', '/c', '/d' ),
		];
		$matcher = new Matcher( RulesetCompiler::compile( $rows ) );
		$this->assertTrue( $matcher->match( $this->req( '/forum/x' ) )->trailing_slash );
		$this->assertTrue( $matcher->match( $this->req( '/a' ) )->trailing_slash );
		$this->assertFalse( $matcher->match( $this->req( '/c' ) )->trailing_slash, 'Rows without the column (schema v2 caches) are false.' );
	}

	public function test_duplicate_exact_keys_lowest_id_wins(): void {
		$rs = RulesetCompiler::compile( [ $this->row( 9, 'exact', '/a/', '/nine' ), $this->row( 2, 'exact', '/A', '/two' ) ] );
		$this->assertSame( 2, $rs['exact']['/a']['id'] );
	}

	public function test_exact_match_is_case_and_slash_insensitive(): void {
		$m = ( new Matcher( RulesetCompiler::compile( [ $this->row( 1, 'exact', '/old-page', '/new' ) ] ) ) )->match( $this->req( '/OLD-PAGE/' ) );
		$this->assertSame( 1, $m->rule_id );
		$this->assertSame( 'exact', $m->type );
		$this->assertSame( '/new', $m->target );
	}

	public function test_query_specific_exact_rule_takes_precedence(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'exact', '/p', '/plain' ),
				$this->row( 2, 'exact', '/p?lang=fr', '/fr' ),
			]
		);
		$matcher = new Matcher( $rs );
		$this->assertSame( 2, $matcher->match( $this->req( '/p?lang=fr' ) )->rule_id );
		$this->assertSame( 1, $matcher->match( $this->req( '/p?lang=de' ) )->rule_id );
		$this->assertSame( 1, $matcher->match( $this->req( '/p' ) )->rule_id );
	}

	public function test_exact_beats_regex_and_regex_follows_position(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'regex', '^/blog/(.*)$', '/news/$1', 301, 2 ),
				$this->row( 2, 'regex', '^/blog/special$', '/special', 302, 1 ),
				$this->row( 3, 'exact', '/blog/exact', '/e' ),
			]
		);
		$matcher = new Matcher( $rs );
		$this->assertSame( 3, $matcher->match( $this->req( '/blog/exact' ) )->rule_id );
		$this->assertSame( 2, $matcher->match( $this->req( '/blog/special' ) )->rule_id );
		$hit = $matcher->match( $this->req( '/blog/hello' ) );
		$this->assertSame( 1, $hit->rule_id );
		$this->assertSame( 'hello', $hit->captures[1] );
	}

	public function test_no_match_returns_null(): void {
		$this->assertNull( ( new Matcher( RulesetCompiler::empty_ruleset() ) )->match( $this->req( '/x' ) ) );
	}

	public function test_failing_regex_is_skipped_and_reported(): void {
		$errors = [];
		$rs     = RulesetCompiler::compile(
			[
				$this->row( 1, 'regex', '(?:a+)+$', '/never', 301, 1 ),
				$this->row( 2, 'regex', '^/a', '/ok', 301, 2 ),
			]
		);
		$previous_jit = ini_set( 'pcre.jit', '0' );
		$previous     = ini_set( 'pcre.backtrack_limit', '10' );
		$matcher  = new Matcher(
			$rs,
			static function ( int $id, int $code ) use ( &$errors ): void {
				$errors[] = [ $id, $code ];
			}
		);
		$result = $matcher->match( $this->req( '/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab' ) );
		ini_set( 'pcre.backtrack_limit', (string) $previous );
		ini_set( 'pcre.jit', (string) $previous_jit );

		$this->assertSame( 2, $result->rule_id );
		$this->assertSame( 1, $errors[0][0] );
		$this->assertNotSame( PREG_NO_ERROR, $errors[0][1] );
	}
}
