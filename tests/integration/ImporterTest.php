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
		$this->assertSame( 'superseded', $this->by_source_id( $entries, 9 )['status'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 10 )['status'] );

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
		$this->assertSame( '/fx-dupe-second/', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-dupe' ) )->target );
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

	public function test_later_own_host_absolute_source_supersedes_an_earlier_path_entry(): void {
		$redirects = [
			$this->entry( 1, '/probe-a/', '/target-one/' ),
			$this->entry( 2, 'http://example.org/probe-a', '/target-two/' ),
		];

		$preview = $this->importer->preview( $redirects, $this->export['groups'] );
		$this->assertSame( 1, $preview['counts']['new'] );
		$this->assertSame( 1, $preview['counts']['superseded'] );
		$this->assertSame( 'superseded', $this->by_source_id( $preview['entries'], 1 )['status'] );
		$this->assertSame( 'new', $this->by_source_id( $preview['entries'], 2 )['status'] );

		$result = $this->importer->import( $redirects, $this->export['groups'] );
		$this->assertSame( [ 'total' => 2, 'created' => 1, 'updated' => 0, 'skipped' => 1 ], $result['counts'] );
		$this->assertSame( 'superseded', $this->by_source_id( $result['entries'], 1 )['error']['code'] );
		$this->assertSame( '/target-two/', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/probe-a' ) )->target );
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
