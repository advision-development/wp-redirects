<?php

use Advision\Redirects\Import\RedirectionMapper;
use PHPUnit\Framework\TestCase;

final class RedirectionMapperTest extends TestCase {

	private static array $export;
	private static array $groups;

	public static function setUpBeforeClass(): void {
		self::$export = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
		self::$groups = RedirectionMapper::group_info( self::$export['groups'] );
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

	public function test_group_info(): void {
		$this->assertSame(
			[
				1 => [ 'name' => 'Redirections', 'disabled' => false ],
				2 => [ 'name' => 'Modified Posts', 'disabled' => false ],
			],
			self::$groups
		);
		$this->assertSame( [], RedirectionMapper::group_info( [ 'junk', [ 'id' => 'x' ] ] ) );
	}

	public function test_group_info_flags_disabled_groups(): void {
		$info = RedirectionMapper::group_info(
			[
				[ 'id' => 1, 'name' => 'A', 'status' => 'disabled' ],
				[ 'id' => 2, 'name' => 'B', 'enabled' => false ],
				[ 'id' => 3, 'name' => 'C', 'status' => 'enabled', 'enabled' => true ],
				[ 'id' => 4, 'name' => 'D' ],
			]
		);
		$this->assertTrue( $info[1]['disabled'] );
		$this->assertTrue( $info[2]['disabled'] );
		$this->assertFalse( $info[3]['disabled'] );
		$this->assertFalse( $info[4]['disabled'] );
	}

	public function test_entry_in_a_disabled_group_maps_disabled_with_a_note(): void {
		$groups = RedirectionMapper::group_info( [ [ 'id' => 1, 'name' => 'Redirections', 'status' => 'disabled' ] ] );
		$mapped = RedirectionMapper::map( $this->entry( 1 ), $groups );
		$this->assertTrue( $mapped['ok'] );
		$this->assertFalse( $mapped['rule']['enabled'] );
		$this->assertSame( [ 'group_disabled' ], $mapped['notes'] );

		$this->assertTrue( $this->map( 1 )['rule']['enabled'], 'An enabled group leaves the entry enabled.' );
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

	public function test_error_entry_with_null_action_data_maps_to_gone_rule(): void {
		$entry                = $this->entry( 11 );
		$entry['action_data'] = null;
		$entry['action_code'] = 410;
		$mapped               = RedirectionMapper::map( $entry, self::$groups );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( 410, $mapped['rule']['status_code'] );
		$this->assertNull( $mapped['rule']['target'] );
	}

	public function test_url_entry_with_null_action_data_is_invalid(): void {
		$entry                = $this->entry( 1 );
		$entry['action_data'] = null;
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $entry, self::$groups )['error'] );
	}

	public function test_non_integer_numeric_fields_are_invalid(): void {
		foreach ( [ '1e3', '301.9', ' 301', 301.0 ] as $code ) {
			$entry                = $this->entry( 1 );
			$entry['action_code'] = $code;
			$this->assertSame( 'invalid_entry', RedirectionMapper::map( $entry, self::$groups )['error'], 'action_code ' . var_export( $code, true ) );
		}
		$entry             = $this->entry( 1 );
		$entry['group_id'] = '1.5';
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $entry, self::$groups )['error'] );
	}

	public function test_missing_group_id_is_invalid(): void {
		$entry = $this->entry( 1 );
		unset( $entry['group_id'] );
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $entry, self::$groups )['error'] );
	}

	public function test_pass_query_mode_is_noted(): void {
		$entry = $this->entry( 1 );
		$entry['match_data']['source']['flag_query'] = 'pass';
		$this->assertSame( [ 'query_mode' ], RedirectionMapper::map( $entry, self::$groups )['notes'] );
	}

	public function test_entry_without_match_data_maps_with_no_notes(): void {
		$entry = $this->entry( 1 );
		unset( $entry['match_data'] );
		$mapped = RedirectionMapper::map( $entry, self::$groups );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( [], $mapped['notes'] );
	}
}
