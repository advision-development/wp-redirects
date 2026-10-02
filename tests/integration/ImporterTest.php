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
}
