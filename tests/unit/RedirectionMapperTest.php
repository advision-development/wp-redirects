<?php

use Advision\Redirects\Import\RedirectionMapper;
use PHPUnit\Framework\TestCase;

final class RedirectionMapperTest extends TestCase {

	private static array $export;
	private static array $groups;

	public static function setUpBeforeClass(): void {
		self::$export = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
		self::$groups = RedirectionMapper::group_names( self::$export['groups'] );
	}

	private function entry( int $id ): array {
		foreach ( self::$export['redirects'] as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "Fixture entry {$id} missing." );
	}

	private function map( int $id ): array {
		return RedirectionMapper::map( $this->entry( $id ), self::$groups );
	}

	public function test_group_names(): void {
		$this->assertSame( [ 1 => 'Redirections', 2 => 'Modified Posts' ], self::$groups );
		$this->assertSame( [], RedirectionMapper::group_names( [ 'junk', [ 'id' => 'x' ] ] ) );
	}

	public function test_maps_a_plain_exact_redirect(): void {
		$mapped = $this->map( 1 );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( 1, $mapped['source_id'] );
		$this->assertSame(
			[
				'type'        => 'exact',
				'source'      => '/fx-old-page/',
				'target'      => '/fx-new-page/',
				'status_code' => 301,
				'enabled'     => true,
				'note'        => 'Imported title',
				'origin'      => 'manual',
			],
			$mapped['rule']
		);
		$this->assertSame( [], $mapped['notes'] );
		$this->assertNull( $mapped['error'] );
	}

	public function test_maps_regex_with_capture(): void {
		$rule = $this->map( 7 )['rule'];
		$this->assertSame( 'regex', $rule['type'] );
		$this->assertSame( '^/fx-blog/(\d+)/?$', $rule['source'] );
		$this->assertSame( '/fx-news/$1/', $rule['target'] );
	}

	public function test_maps_410_error_to_gone_rule(): void {
		$rule = $this->map( 11 )['rule'];
		$this->assertSame( 410, $rule['status_code'] );
		$this->assertNull( $rule['target'] );
	}

	public function test_modified_posts_group_becomes_auto(): void {
		$this->assertSame( 'auto', $this->map( 16 )['rule']['origin'] );
		$this->assertSame( 'manual', $this->map( 18 )['rule']['origin'] );
	}

	public function test_disabled_entries_stay_disabled(): void {
		$this->assertFalse( $this->map( 17 )['rule']['enabled'] );
	}

	public function test_notes(): void {
		$this->assertSame( [ 'case_insensitive' ], $this->map( 3 )['notes'] );
		$this->assertSame( [ 'trailing_slash_ignored' ], $this->map( 4 )['notes'] );
		$this->assertSame( [ 'query_mode' ], $this->map( 6 )['notes'] );
		$this->assertSame( [ 'regex_query' ], $this->map( 20 )['notes'] );
	}

	/** @dataProvider skipped_entries */
	public function test_skips_with_reason( int $id, string $reason ): void {
		$mapped = $this->map( $id );
		$this->assertFalse( $mapped['ok'] );
		$this->assertNull( $mapped['rule'] );
		$this->assertSame( $reason, $mapped['error'] );
		$this->assertSame( $id, $mapped['source_id'] );
	}

	public function skipped_entries(): array {
		return [
			'303 status'        => [ 12, 'unsupported_status' ],
			'login match type'  => [ 13, 'unsupported_match_type' ],
			'random action'     => [ 14, 'unsupported_action' ],
			'404 error action'  => [ 15, 'unsupported_action' ],
		];
	}

	public function test_invalid_entries(): void {
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( 'nope', [] )['error'] );
		$broken = $this->entry( 1 );
		unset( $broken['url'] );
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
		$broken = $this->entry( 1 );
		$broken['regex'] = 'yes';
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
		$broken = $this->entry( 1 );
		unset( $broken['action_data']['url'] );
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
	}

	public function test_numeric_strings_are_accepted(): void {
		$entry                = $this->entry( 1 );
		$entry['action_code'] = '302';
		$entry['group_id']    = '2';
		$mapped               = RedirectionMapper::map( $entry, self::$groups );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( 302, $mapped['rule']['status_code'] );
		$this->assertSame( 'auto', $mapped['rule']['origin'] );
	}

	public function test_long_titles_are_truncated(): void {
		$entry          = $this->entry( 1 );
		$entry['title'] = str_repeat( 'é', 300 );
		$this->assertSame( 255, mb_strlen( RedirectionMapper::map( $entry, [] )['rule']['note'] ) );
	}
}
