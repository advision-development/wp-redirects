<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ImporterYoastTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private array $entries;

	public function set_up(): void {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$list           = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		$this->entries  = [];
		foreach ( $list as $index => $entry ) {
			$this->entries[] = [ 'id' => $index + 1 ] + $entry;
		}
	}

	private function preview(): array {
		return $this->importer->preview( $this->entries, [], Importer::SOURCE_YOAST );
	}

	private function by_id( array $entries, int $id ): array {
		foreach ( $entries as $entry ) {
			if ( $entry['source_id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "No entry for Yoast id {$id}." );
	}

	private function import_all( array $preview ): array {
		$batch = [];
		foreach ( $preview['entries'] as $entry ) {
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$batch[] = $this->entries[ $entry['index'] ];
			}
		}
		$totals = [ 'created' => 0, 'updated' => 0, 'skipped' => 0 ];
		foreach ( array_chunk( $batch, 5 ) as $chunk ) {
			$counts = $this->importer->import( $chunk, [], Importer::SOURCE_YOAST )['counts'];
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $counts[ $key ];
			}
		}
		return $totals;
	}

	public function test_preview_counts_and_writes_nothing(): void {
		$this->assertSame(
			[ 'total' => 26, 'new' => 16, 'overwrite' => 0, 'superseded' => 1, 'skipped' => 9, 'warnings' => 1 ],
			$this->preview()['counts']
		);
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_preview_entry_details(): void {
		$entries = $this->preview()['entries'];

		$this->assertSame( 'fy-old-page', $this->by_id( $entries, 1 )['source'], 'The raw Yoast origin is reported as the source.' );
		$superseded = $this->by_id( $entries, 3 );
		$this->assertSame( 'superseded', $superseded['status'] );
		$this->assertSame( 2, $superseded['superseded_by'] );
		$this->assertSame( 'Yoast entry #2 already covers this source.', $superseded['error']['message'] );

		$this->assertSame( 'chain', $this->by_id( $entries, 10 )['warnings'][0]['code'] );
		$this->assertSame( 'adv_redirects_loop', $this->by_id( $entries, 13 )['error']['code'], 'Loop formed only by imported rules.' );
		$this->assertSame( 'adv_redirects_invalid_source', $this->by_id( $entries, 16 )['error']['code'] );
		$this->assertSame( 'adv_redirects_reserved_source', $this->by_id( $entries, 23 )['error']['code'] );

		$this->assertSame( 'unreachable_regex', $this->by_id( $entries, 19 )['error']['code'] );
		$this->assertStringContainsString( 'never matched', $this->by_id( $entries, 19 )['error']['message'] );
		$this->assertSame( 'unsupported_capture', $this->by_id( $entries, 20 )['error']['code'] );
		$this->assertStringContainsString( '$1 to $9', $this->by_id( $entries, 20 )['error']['message'] );
		$this->assertSame( 'invalid_entry', $this->by_id( $entries, 24 )['error']['code'] );

		$this->assertSame( [ 'case_sensitive_source', 'regex_query' ], $this->by_id( $entries, 18 )['notes'] );
		$this->assertSame( '/fy-new-page/', $this->by_id( $entries, 1 )['rule']['target'] );
		$this->assertNull( $this->by_id( $entries, 6 )['rule']['target'] );
	}

	public function test_trailing_slash_follows_the_permalink_structure(): void {
		update_option( 'permalink_structure', '/%postname%' );
		$this->assertSame( '/fy-new-page', $this->by_id( $this->preview()['entries'], 1 )['rule']['target'] );
	}

	public function test_import_matches_preview_and_reimport_is_idempotent(): void {
		$preview = $this->preview();
		$this->assertSame( [ 'created' => 16, 'updated' => 0, 'skipped' => 0 ], $this->import_all( $preview ) );
		$this->assertCount( 16, $this->repo->all() );

		$rule = $this->repo->exact_rule_by_key( '/fy-old-page' );
		$this->assertSame( '/fy-new-page/', $rule->target );
		$this->assertSame( 'import', $rule->created_via );

		$again = $this->preview();
		$this->assertSame( 16, $again['counts']['overwrite'] );
		$this->assertSame( [ 'created' => 0, 'updated' => 16, 'skipped' => 0 ], $this->import_all( $again ) );
		$this->assertCount( 16, $this->repo->all() );
	}

	public function test_filter_receives_the_source(): void {
		$seen = [];
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule, $entry, $source ) use ( &$seen ) {
				$seen[] = $source;
				return $rule;
			},
			10,
			3
		);
		$this->importer->preview( [ $this->entries[0] ], [], Importer::SOURCE_YOAST );
		$this->assertSame( [ 'yoast' ], $seen );
	}

	public function test_covered_reports_rules_with_the_same_conflict_key(): void {
		$this->import_all( $this->preview() );
		$covered = $this->importer->covered(
			[
				'a' => [ 'type' => 'exact', 'source' => '/FY-OLD-PAGE/' ],
				'b' => [ 'type' => 'exact', 'source' => '/fy-caf%C3%A9' ],
				'c' => [ 'type' => 'regex', 'source' => ' ^/fy-regex/(.*) ' ],
				'd' => [ 'type' => 'exact', 'source' => '/fy-nowhere' ],
				'e' => [ 'type' => 'regex', 'source' => '^/fy-nowhere' ],
			]
		);
		$this->assertSame( [ 'a' => true, 'b' => true, 'c' => true, 'd' => false, 'e' => false ], $covered );
	}

	public function test_redirection_calls_are_unchanged(): void {
		$export  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
		$preview = $this->importer->preview( $export['redirects'], $export['groups'] );
		$this->assertSame( 13, $preview['counts']['new'] );
		$by_id   = array_column( $preview['entries'], null, 'source_id' );
		$this->assertStringContainsString( 'Redirection used entry #9', $by_id[10]['error']['message'] );
	}
}
