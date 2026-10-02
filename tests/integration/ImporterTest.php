<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ImporterTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private array $export;

	public function set_up(): void {
		parent::set_up();
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$this->export   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
	}

	private function by_source_id( array $entries, int $id ): array {
		foreach ( $entries as $entry ) {
			if ( $entry['source_id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "No entry for source id {$id}." );
	}

	private function importable( array $preview ): array {
		$out = [];
		foreach ( $preview['entries'] as $entry ) {
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$out[] = $this->export['redirects'][ $entry['index'] ];
			}
		}
		return $out;
	}

	public function test_preview_counts_and_writes_nothing(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame(
			[ 'total' => 21, 'new' => 13, 'overwrite' => 0, 'superseded' => 1, 'skipped' => 7, 'warnings' => 1 ],
			$preview['counts']
		);
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_preview_entry_details(): void {
		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];

		$this->assertSame( 'chain', $this->by_source_id( $entries, 2 )['warnings'][0]['code'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 9 )['status'], 'The first enabled duplicate is the one Redirection served.' );
		$superseded = $this->by_source_id( $entries, 10 );
		$this->assertSame( 'superseded', $superseded['status'] );
		$this->assertSame( 9, $superseded['superseded_by'] );
		$this->assertSame( 'superseded', $superseded['error']['code'] );
		$this->assertStringContainsString( '#9', $superseded['error']['message'] );

		$this->assertSame( 'adv_redirects_loop', $this->by_source_id( $entries, 8 )['error']['code'] );
		$this->assertSame( 'adv_redirects_loop', $this->by_source_id( $entries, 19 )['error']['code'], 'Loop formed only by imported rules.' );
		$this->assertSame( 'adv_redirects_reserved_source', $this->by_source_id( $entries, 21 )['error']['code'] );
		$this->assertSame( 'unsupported_status', $this->by_source_id( $entries, 12 )['error']['code'] );
		$this->assertSame( 'unsupported_match_type', $this->by_source_id( $entries, 13 )['error']['code'] );
		$this->assertSame( 'unsupported_action', $this->by_source_id( $entries, 14 )['error']['code'] );
		$this->assertSame( 'unsupported_action', $this->by_source_id( $entries, 15 )['error']['code'] );
		$this->assertNotSame( '', $this->by_source_id( $entries, 13 )['error']['message'] );

		$this->assertSame( [ 'case_insensitive' ], $this->by_source_id( $entries, 3 )['notes'] );
		$this->assertSame( 'auto', $this->by_source_id( $entries, 16 )['rule']['origin'] );
		$this->assertNull( $this->by_source_id( $entries, 11 )['rule']['target'] );
		$this->assertSame( '/fx-old-page/', $this->by_source_id( $entries, 1 )['source'] );
	}

	public function test_import_in_batches_matches_preview(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$created = 0;
		foreach ( array_chunk( $this->importable( $preview ), 5 ) as $batch ) {
			$created += $this->importer->import( $batch, $this->export['groups'] )['counts']['created'];
		}
		$this->assertSame( 13, $created );
		$this->assertCount( 13, $this->repo->all() );

		$auto = $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-auto-slug/' ) );
		$this->assertSame( 'auto', $auto->origin );
		$this->assertFalse( $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-disabled/' ) )->enabled );
		$this->assertSame( '/fx-dupe-first/', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-dupe' ) )->target );
	}

	public function test_import_of_the_full_list_skips_like_preview(): void {
		$result = $this->importer->import( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame( [ 'total' => 21, 'created' => 13, 'updated' => 0, 'skipped' => 8 ], $result['counts'] );
	}

	public function test_reimport_is_idempotent(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->importer->import( $this->importable( $preview ), $this->export['groups'] );

		$again = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame( 13, $again['counts']['overwrite'] );
		$this->assertSame( 0, $again['counts']['new'] );

		$result = $this->importer->import( $this->importable( $again ), $this->export['groups'] );
		$this->assertSame( [ 'total' => 13, 'created' => 0, 'updated' => 13, 'skipped' => 0 ], $result['counts'] );
		$this->assertCount( 13, $this->repo->all() );
	}

	public function test_overwrite_keeps_id_and_hits_and_uses_imported_target_for_chains(): void {
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/fx-old-page', 'target' => '/somewhere-else', 'status_code' => 302 ] );
		$this->repo->add_hits( $existing->id, 5, '2026-10-01 00:00:00' );

		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];
		$first   = $this->by_source_id( $entries, 1 );
		$this->assertSame( 'overwrite', $first['status'] );
		$this->assertSame( $existing->id, $first['existing_id'] );
		$this->assertSame( '/somewhere-else', $first['current']['target'] );
		$this->assertSame( [ '/fx-moved/', '/fx-old-page/', '/fx-new-page/' ], $this->by_source_id( $entries, 2 )['warnings'][0]['hops'] );

		$this->importer->import( [ $this->export['redirects'][0] ], $this->export['groups'] );
		$after = $this->repo->find( $existing->id );
		$this->assertSame( '/fx-new-page/', $after->target );
		$this->assertSame( 301, $after->status_code );
		$this->assertSame( 5, $after->hits );
	}

	public function test_imported_rules_are_attributed_to_the_import(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->importer->import( $this->export['redirects'], $this->export['groups'] );

		$rule = $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-old-page' ) );
		$this->assertSame( 'import', $rule->created_via );
		$this->assertSame( $admin, $rule->created_by );
		$this->assertNull( $rule->updated_by );
	}

	public function test_overwrite_keeps_the_original_attribution_and_records_the_editor(): void {
		$creator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $creator );
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/fx-old-page', 'target' => '/somewhere-else', 'status_code' => 302 ] );

		wp_set_current_user( $editor );
		$this->importer->import( [ $this->export['redirects'][0] ], $this->export['groups'] );

		$after = $this->repo->find( $existing->id );
		$this->assertSame( '/fx-new-page/', $after->target );
		$this->assertSame( 'manual', $after->created_via );
		$this->assertSame( $creator, $after->created_by );
		$this->assertSame( $editor, $after->updated_by );
	}

	public function test_filter_can_skip_or_change_rules_and_action_fires(): void {
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule, array $entry ) {
				if ( 18 === $entry['id'] ) {
					return false;
				}
				if ( 1 === $entry['id'] ) {
					$rule['note'] = 'Changed by filter';
				}
				return $rule;
			},
			10,
			2
		);
		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];
		$this->assertSame( 'filtered', $this->by_source_id( $entries, 18 )['error']['code'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 19 )['status'], 'Without /fx-ping/ there is no loop.' );

		$before = did_action( 'adv_redirects_import_completed' );
		$this->importer->import( [ $this->export['redirects'][0] ], $this->export['groups'] );
		$this->assertSame( $before + 1, did_action( 'adv_redirects_import_completed' ) );
		$this->assertSame( 'Changed by filter', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-old-page/' ) )->note );
	}

	private function entry( int $id, string $url, string $target, bool $enabled = true ): array {
		return [
			'id'          => $id,
			'url'         => $url,
			'match_url'   => $url,
			'match_data'  => [ 'source' => [ 'flag_case' => false, 'flag_query' => 'exact', 'flag_regex' => false, 'flag_trailing' => false ] ],
			'action_code' => 301,
			'action_type' => 'url',
			'action_data' => [ 'url' => $target ],
			'match_type'  => 'url',
			'title'       => '',
			'hits'        => 0,
			'regex'       => false,
			'group_id'    => 1,
			'position'    => 0,
			'last_access' => '',
			'enabled'     => $enabled,
		];
	}

	public function test_own_host_absolute_duplicate_of_an_earlier_path_entry_is_superseded(): void {
		$redirects = [
			$this->entry( 1, '/probe-a/', '/target-one/' ),
			$this->entry( 2, 'http://example.org/probe-a', '/target-two/' ),
		];

		$preview = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 1, $preview['counts']['new'] );
		$this->assertSame( 1, $preview['counts']['superseded'] );
		$this->assertSame( 'new', $this->by_source_id( $preview['entries'], 1 )['status'] );
		$this->assertSame( 'superseded', $this->by_source_id( $preview['entries'], 2 )['status'] );
		$this->assertSame( 1, $this->by_source_id( $preview['entries'], 2 )['superseded_by'] );

		$result = $this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( [ 'total' => 2, 'created' => 1, 'updated' => 0, 'skipped' => 1 ], $result['counts'] );
		$this->assertSame( 'superseded', $this->by_source_id( $result['entries'], 2 )['error']['code'] );
		$this->assertSame( 1, $this->by_source_id( $result['entries'], 2 )['superseded_by'] );
		$this->assertSame( '/target-one/', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/probe-a' ) )->target );
	}

	public function test_an_enabled_entry_beats_a_later_disabled_duplicate(): void {
		$redirects = [
			$this->entry( 1, '/dup-a/', '/live/' ),
			$this->entry( 2, '/dup-a', '/disabled/', false ),
		];
		$entries   = $this->importer->preview( $redirects, $this->export['groups'] )['entries'];
		$this->assertSame( 'new', $this->by_source_id( $entries, 1 )['status'] );
		$this->assertSame( 'superseded', $this->by_source_id( $entries, 2 )['status'] );
		$this->assertSame( 1, $this->by_source_id( $entries, 2 )['superseded_by'] );
	}

	public function test_an_enabled_entry_beats_an_earlier_disabled_duplicate(): void {
		$redirects = [
			$this->entry( 1, '/dup-b/', '/disabled/', false ),
			$this->entry( 2, '/dup-b', '/live/' ),
		];
		$preview   = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 'superseded', $this->by_source_id( $preview['entries'], 1 )['status'] );
		$this->assertSame( 2, $this->by_source_id( $preview['entries'], 1 )['superseded_by'] );
		$this->assertSame( 'new', $this->by_source_id( $preview['entries'], 2 )['status'] );

		$this->importer->import( [ $redirects[1] ], $this->export['groups'] );
		$rule = $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/dup-b' ) );
		$this->assertSame( '/live/', $rule->target );
		$this->assertTrue( $rule->enabled );
	}

	public function test_with_no_enabled_duplicate_the_first_one_wins(): void {
		$redirects = [
			$this->entry( 1, '/dup-c/', '/first/', false ),
			$this->entry( 2, '/dup-c', '/second/', false ),
		];
		$entries   = $this->importer->preview( $redirects, $this->export['groups'] )['entries'];
		$this->assertSame( 'new', $this->by_source_id( $entries, 1 )['status'] );
		$this->assertSame( 'superseded', $this->by_source_id( $entries, 2 )['status'] );
	}

	public function test_a_valid_entry_beats_a_later_duplicate_with_an_invalid_target(): void {
		$redirects = [
			$this->entry( 1, '/dup-d/', '/good/' ),
			$this->entry( 2, '/dup-d', 'javascript:alert(1)' ),
		];
		$preview   = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 'new', $this->by_source_id( $preview['entries'], 1 )['status'] );
		$this->assertSame( 'superseded', $this->by_source_id( $preview['entries'], 2 )['status'], 'Superseded, not skipped.' );
		$this->assertSame( 0, $preview['counts']['skipped'] );
	}

	public function test_a_winner_that_fails_validation_is_skipped_and_no_copy_takes_its_place(): void {
		$redirects = [
			$this->entry( 1, '/dup-e/', 'javascript:alert(1)' ),
			$this->entry( 2, '/dup-e', '/good/' ),
		];
		$preview   = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 'skipped', $this->by_source_id( $preview['entries'], 1 )['status'] );
		$this->assertSame( 'superseded', $this->by_source_id( $preview['entries'], 2 )['status'] );

		$result = $this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( 0, $result['counts']['created'] );
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_a_filtered_entry_never_wins_a_duplicate(): void {
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule, array $entry ) {
				return 1 === $entry['id'] ? false : $rule;
			},
			10,
			2
		);
		$redirects = [
			$this->entry( 1, '/dup-f/', '/one/' ),
			$this->entry( 2, '/dup-f', '/two/' ),
		];
		$entries   = $this->importer->preview( $redirects, $this->export['groups'] )['entries'];
		$this->assertSame( 'skipped', $this->by_source_id( $entries, 1 )['status'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 2 )['status'] );
	}

	public function test_a_rule_in_a_disabled_redirection_group_imports_disabled_with_a_note(): void {
		$groups    = [ [ 'id' => 1, 'name' => 'Redirections', 'status' => 'disabled' ] ];
		$redirects = [ $this->entry( 1, '/grp-off/', '/x/' ) ];

		$entry = $this->importer->preview( $redirects, $groups )['entries'][0];
		$this->assertSame( 'new', $entry['status'] );
		$this->assertFalse( $entry['rule']['enabled'] );
		$this->assertContains( 'group_disabled', $entry['notes'] );

		$this->importer->import( $redirects, $groups );
		$this->assertFalse( $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/grp-off' ) )->enabled );
	}

	public function test_regex_sources_that_differ_only_by_whitespace_are_duplicates(): void {
		$regex = function ( int $id, string $source ): array {
			$entry          = $this->entry( $id, $source, '/re-target/' );
			$entry['regex'] = true;
			return $entry;
		};
		$redirects = [ $regex( 1, '^/re$' ), $regex( 2, ' ^/re$' ) ];

		$preview = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 1, $preview['counts']['new'] );
		$this->assertSame( 1, $preview['counts']['superseded'] );
		$this->assertSame( 'new', $this->by_source_id( $preview['entries'], 1 )['status'] );
	}

	public function test_a_regex_source_with_a_leading_space_overwrites_the_stored_rule(): void {
		$existing       = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/re$', 'target' => '/old/', 'status_code' => 301 ] );
		$entry          = $this->entry( 1, ' ^/re$', '/new/' );
		$entry['regex'] = true;

		$preview = $this->importer->preview( [ $entry ], $this->export['groups'] )['entries'][0];
		$this->assertSame( 'overwrite', $preview['status'] );
		$this->assertSame( $existing->id, $preview['existing_id'] );

		$result = $this->importer->import( [ $entry ], $this->export['groups'] );
		$this->assertSame( [ 'total' => 1, 'created' => 0, 'updated' => 1, 'skipped' => 0 ], $result['counts'] );
		$this->assertCount( 1, $this->repo->all() );
		$this->assertSame( '/new/', $this->repo->find( $existing->id )->target );
	}

	public function test_an_empty_title_keeps_the_existing_note_on_overwrite(): void {
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/note-keep', 'target' => '/old/', 'status_code' => 301, 'note' => 'Keep me' ] );
		$entry    = $this->entry( 1, '/note-keep/', '/new/' );

		$preview = $this->importer->preview( [ $entry ], $this->export['groups'] )['entries'][0];
		$this->assertSame( 'overwrite', $preview['status'] );
		$this->assertSame( 'Keep me', $preview['rule']['note'] );
		$this->assertSame( 'Keep me', $preview['current']['note'] );

		$this->importer->import( [ $entry ], $this->export['groups'] );
		$after = $this->repo->find( $existing->id );
		$this->assertSame( '/new/', $after->target );
		$this->assertSame( 'Keep me', $after->note );
	}

	public function test_a_non_empty_title_replaces_the_existing_note(): void {
		$existing       = $this->repo->insert( [ 'type' => 'exact', 'source' => '/note-swap', 'target' => '/old/', 'status_code' => 301, 'note' => 'Old note' ] );
		$entry          = $this->entry( 1, '/note-swap/', '/new/' );
		$entry['title'] = 'New note';

		$this->assertSame( 'New note', $this->importer->preview( [ $entry ], $this->export['groups'] )['entries'][0]['rule']['note'] );
		$this->importer->import( [ $entry ], $this->export['groups'] );
		$this->assertSame( 'New note', $this->repo->find( $existing->id )->note );
	}

	public function test_own_host_absolute_source_overwrites_the_existing_path_rule(): void {
		$existing  = $this->repo->insert( [ 'type' => 'exact', 'source' => '/probe-b', 'target' => '/old-target', 'status_code' => 302 ] );
		$redirects = [ $this->entry( 1, 'https://example.org/probe-b/', '/new-target/' ) ];

		$entry = $this->importer->preview( $redirects, $this->export['groups'] )['entries'][0];
		$this->assertSame( 'overwrite', $entry['status'] );
		$this->assertSame( $existing->id, $entry['existing_id'] );

		$result = $this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( [ 'total' => 1, 'created' => 0, 'updated' => 1, 'skipped' => 0 ], $result['counts'] );
		$this->assertCount( 1, $this->repo->all() );
		$this->assertSame( '/new-target/', $this->repo->find( $existing->id )->target );
	}

	public function test_preview_warns_about_a_chain_whose_next_hop_is_defined_later_in_the_file(): void {
		$redirects = [
			$this->entry( 1, '/cw-a/', '/cw-b/' ),
			$this->entry( 2, '/cw-b/', '/cw-c/' ),
		];

		$preview = $this->importer->preview( $redirects, $this->export['groups'] );
		$first   = $this->by_source_id( $preview['entries'], 1 );
		$this->assertSame( 'new', $first['status'] );
		$this->assertSame( 'chain', $first['warnings'][0]['code'] );
		$this->assertSame( [ '/cw-a/', '/cw-b/', '/cw-c/' ], $first['warnings'][0]['hops'] );
		$this->assertSame( [], $this->by_source_id( $preview['entries'], 2 )['warnings'] );
		$this->assertSame( 1, $preview['counts']['warnings'] );
	}

	public function test_forward_chain_follows_the_imported_target_of_an_overwritten_rule(): void {
		$existing  = $this->repo->insert( [ 'type' => 'exact', 'source' => '/cw-x', 'target' => '/elsewhere', 'status_code' => 301 ] );
		$redirects = [
			$this->entry( 1, '/cw-y/', '/cw-x/' ),
			$this->entry( 2, '/cw-x/', '/cw-z/' ),
		];

		$entries = $this->importer->preview( $redirects, $this->export['groups'] )['entries'];
		$this->assertSame( 'overwrite', $this->by_source_id( $entries, 2 )['status'] );
		$this->assertSame( $existing->id, $this->by_source_id( $entries, 2 )['existing_id'] );

		$warning = $this->by_source_id( $entries, 1 )['warnings'][0];
		$this->assertSame( 'chain', $warning['code'] );
		$this->assertSame( [ '/cw-y/', '/cw-x/', '/cw-z/' ], $warning['hops'], 'Walks the imported target, not the stale stored one.' );
		$this->assertSame( '/cw-z/', $warning['final'] );
	}

	public function test_forward_chain_extends_through_several_later_entries(): void {
		$redirects = [
			$this->entry( 1, '/cw-b/', '/cw-c/' ),
			$this->entry( 2, '/cw-a/', '/cw-b/' ),
			$this->entry( 3, '/cw-c/', '/cw-d/' ),
		];

		$preview = $this->importer->preview( $redirects, $this->export['groups'] );
		$warning = $this->by_source_id( $preview['entries'], 2 )['warnings'][0];
		$this->assertSame( [ '/cw-a/', '/cw-b/', '/cw-c/', '/cw-d/' ], $warning['hops'] );
		$this->assertSame( '/cw-d/', $warning['final'] );
		$this->assertSame( [ '/cw-b/', '/cw-c/', '/cw-d/' ], $this->by_source_id( $preview['entries'], 1 )['warnings'][0]['hops'] );
		$this->assertSame( [], $this->by_source_id( $preview['entries'], 3 )['warnings'] );
		$this->assertSame( 2, $preview['counts']['warnings'] );
	}

	public function test_preview_validates_each_entry_exactly_once(): void {
		$calls = 0;
		add_filter(
			'adv_redirects_validate_rule',
			static function ( $valid ) use ( &$calls ) {
				++$calls;
				return $valid;
			}
		);

		$this->importer->preview(
			[
				$this->entry( 1, '/cw-a/', '/cw-b/' ),
				$this->entry( 2, '/cw-b/', '/cw-c/' ),
				$this->entry( 3, '/cw-c/', '/cw-d/' ),
			],
			$this->export['groups']
		);
		$this->assertSame( 3, $calls );
	}

	public function test_preview_does_not_leave_the_read_cache_open(): void {
		$this->importer->preview( [ $this->entry( 1, '/rc-open/', '/x/' ) ], $this->export['groups'] );
		$this->repo->insert( [ 'type' => 'exact', 'source' => '/after-preview', 'target' => '/x', 'status_code' => 301 ] );
		$this->assertCount( 1, $this->repo->all() );
	}

	public function test_disabled_overwrite_drops_the_old_row_from_the_loop_walk(): void {
		$this->repo->insert( [ 'type' => 'exact', 'source' => '/probe-b', 'target' => '/probe-c', 'status_code' => 301 ] );
		$redirects = [
			$this->entry( 1, '/probe-b/', '/elsewhere/', false ),
			$this->entry( 2, '/probe-c/', '/probe-b/' ),
		];

		$entries = $this->importer->preview( $redirects, $this->export['groups'] )['entries'];
		$this->assertSame( 'overwrite', $this->by_source_id( $entries, 1 )['status'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 2 )['status'], 'The disabled overwrite removes /probe-b from the walk.' );

		$result = $this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( [ 'total' => 2, 'created' => 1, 'updated' => 1, 'skipped' => 0 ], $result['counts'] );
	}

	public function test_origin_is_normalised(): void {
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule ) {
				$rule['origin'] = 'bogus';
				return $rule;
			}
		);
		$redirects = [ $this->entry( 1, '/origin-test/', '/x/' ) ];

		$this->assertSame( 'manual', $this->importer->preview( $redirects, $this->export['groups'] )['entries'][0]['rule']['origin'] );
		$this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( 'manual', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/origin-test/' ) )->origin );
	}

	public function test_non_array_filter_result_skips_as_filtered(): void {
		add_filter( 'adv_redirects_import_rule', static fn() => 'nope' );
		$redirects = [ $this->entry( 1, '/f-test/', '/x/' ) ];

		$entry = $this->importer->preview( $redirects, $this->export['groups'] )['entries'][0];
		$this->assertSame( 'skipped', $entry['status'] );
		$this->assertSame( 'filtered', $entry['error']['code'] );
		$this->assertSame( 0, $this->importer->import( $redirects, $this->export['groups'] )['counts']['created'] );
	}

	public function test_filter_returning_unusable_fields_skips_as_invalid_entry(): void {
		$bad = [
			static function ( $rule ) {
				$rule['source'] = [ 'x' ];
				return $rule;
			},
			static function ( $rule ) {
				$rule['source'] = '';
				return $rule;
			},
			static function ( $rule ) {
				$rule['target'] = [ 'x' ];
				return $rule;
			},
		];
		$redirects = [ $this->entry( 1, '/f-bad/', '/x/' ) ];

		foreach ( $bad as $callback ) {
			add_filter( 'adv_redirects_import_rule', $callback );
			$entry = $this->importer->preview( $redirects, $this->export['groups'] )['entries'][0];
			$this->assertSame( 'skipped', $entry['status'] );
			$this->assertSame( 'invalid_entry', $entry['error']['code'] );
			$this->assertSame( 'invalid_entry', $this->importer->import( $redirects, $this->export['groups'] )['entries'][0]['error']['code'] );
			remove_filter( 'adv_redirects_import_rule', $callback );
		}
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_filter_may_null_the_target(): void {
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule ) {
				$rule['target']      = null;
				$rule['status_code'] = 410;
				return $rule;
			}
		);
		$entry = $this->importer->preview( [ $this->entry( 1, '/f-gone/', '/x/' ) ], $this->export['groups'] )['entries'][0];
		$this->assertSame( 'new', $entry['status'] );
		$this->assertNull( $entry['rule']['target'] );
	}
}
