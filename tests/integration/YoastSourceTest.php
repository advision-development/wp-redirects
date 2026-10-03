<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastManagerStore;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Import\YoastStore;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Uninstaller;

require_once __DIR__ . '/doubles/yoast-premium-doubles.php';

final class YoastSourceTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private YoastSource $yoast;
	private array $fixture;

	public function set_up(): void {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		foreach ( [ YoastOptionStore::BASE_OPTION, YoastOptionStore::PLAIN_OPTION, YoastOptionStore::REGEX_OPTION, YoastSource::METHOD_OPTION, YoastSource::BACKUP_OPTION ] as $option ) {
			delete_option( $option );
		}
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$this->yoast    = new YoastSource( $this->importer, new YoastOptionStore() );
		$this->fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
	}

	private function seed(): void {
		update_option( YoastOptionStore::BASE_OPTION, $this->fixture, false );
	}

	/**
	 * A removal item for a fixture origin, with the url and type Yoast holds for it.
	 */
	private function item( string $origin, string $format = 'plain' ): array {
		foreach ( $this->fixture as $row ) {
			if ( isset( $row['format'] ) && $row['origin'] === $origin && $row['format'] === $format ) {
				return [ 'origin' => $origin, 'format' => $format, 'url' => $row['url'], 'type' => (int) $row['type'] ];
			}
		}
		return [ 'origin' => $origin, 'format' => $format, 'url' => '', 'type' => 301 ];
	}

	/**
	 * Previews and imports everything the preview accepts, as the admin does.
	 *
	 * @return array{0:array,1:array} Preview, and the removal candidates { origin, format, url, type }
	 *                                the client sends (no regex that matched the query string).
	 */
	private function import_all(): array {
		$entries = $this->yoast->entries();
		$preview = $this->importer->preview( $entries, [], Importer::SOURCE_YOAST );
		$batch   = [];
		$remove  = [];
		foreach ( $preview['entries'] as $entry ) {
			$raw = $entries[ $entry['index'] ];
			if ( ! in_array( $entry['status'], [ 'new', 'overwrite', 'superseded' ], true ) ) {
				continue;
			}
			$item = [ 'origin' => $raw['origin'], 'format' => $raw['format'], 'url' => $raw['url'], 'type' => (int) $raw['type'] ];
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$batch[] = $raw;
				if ( ! in_array( 'regex_query', $entry['notes'], true ) ) {
					$remove[] = $item;
				}
			} elseif ( 'superseded' === $entry['status'] ) {
				$remove[] = $item;
			}
		}
		foreach ( array_chunk( $batch, 50 ) as $chunk ) {
			$this->importer->import( $chunk, [], Importer::SOURCE_YOAST );
		}
		return [ $preview, $remove ];
	}

	private function base_origins(): array {
		return array_column( (array) get_option( YoastOptionStore::BASE_OPTION, [] ), 'origin' );
	}

	public function test_status_when_nothing_is_stored(): void {
		$this->assertSame(
			[
				'detected'        => false,
				'premium_active'  => false,
				'premium_version' => null,
				'server_mode'     => 'php',
				'counts'          => [ 'plain' => 0, 'regex' => 0 ],
				'entries'         => [],
				'backup'          => null,
			],
			$this->yoast->status()
		);
	}

	public function test_status_reports_entries_and_counts(): void {
		$this->seed();
		$status = $this->yoast->status();

		$this->assertTrue( $status['detected'] );
		$this->assertSame( [ 'plain' => 19, 'regex' => 6 ], $status['counts'] );
		$this->assertCount( 26, $status['entries'] );
		$this->assertSame( [ 'id' => 1, 'origin' => 'fy-old-page', 'url' => 'fy-new-page', 'type' => 301, 'format' => 'plain' ], $status['entries'][0] );
		$this->assertSame( [ 'id' => 24, 'origin' => 'fy-missing-format', 'url' => 'x', 'type' => 301 ], $status['entries'][23] );
	}

	public function test_status_tolerates_a_malformed_option(): void {
		update_option( YoastOptionStore::BASE_OPTION, 'junk', false );
		$this->assertFalse( $this->yoast->status()['detected'] );

		update_option( YoastOptionStore::BASE_OPTION, [ 'junk', [ 'origin' => 'a', 'extra' => 'dropped' ], [ 'format' => [ 'plain' ] ] ], false );
		$status = $this->yoast->status();
		$this->assertTrue( $status['detected'] );
		$this->assertSame( [ [ 'id' => 1 ], [ 'id' => 2, 'origin' => 'a' ], [ 'id' => 3, 'format' => [ 'plain' ] ] ], $status['entries'] );
		$this->assertSame( [ 'plain' => 0, 'regex' => 0 ], $status['counts'] );
	}

	public function test_server_mode(): void {
		global $is_apache, $is_nginx;
		$saved = [ $is_apache, $is_nginx ];

		$this->assertSame( 'php', YoastSource::server_mode() );
		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'off', 'separate_file' => 'on' ] );
		$this->assertSame( 'php', YoastSource::server_mode() );

		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'on', 'separate_file' => 'off' ] );
		$is_apache = true;
		$is_nginx  = false;
		$this->assertSame( 'htaccess', YoastSource::server_mode() );
		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'on', 'separate_file' => 'on' ] );
		$this->assertSame( 'apache_file', YoastSource::server_mode() );
		$is_apache = false;
		$is_nginx  = true;
		$this->assertSame( 'nginx', YoastSource::server_mode() );
		$is_nginx = false;
		$this->assertSame( 'none', YoastSource::server_mode() );

		list( $is_apache, $is_nginx ) = $saved;
	}

	public function test_remove_removes_covered_entries_backs_them_up_and_fires_the_hook(): void {
		$this->seed();
		list( , $remove ) = $this->import_all();
		$this->assertCount( 16, $remove );

		$fired = [];
		add_action(
			'adv_redirects_yoast_removed',
			static function ( $entries ) use ( &$fired ) {
				$fired[] = $entries;
			}
		);

		$result = $this->yoast->remove( $remove );

		$this->assertSame( [ 16, 0, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertSame( [ 'removed' ], array_values( array_unique( array_column( $result['items'], 'result' ) ) ) );
		$this->assertCount( 10, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertContains( '^/fy-search\\?q=(.*)', $this->base_origins(), 'A regex that matched the query string stays in Yoast.' );
		$this->assertNotContains( 'fy-old-page', $this->base_origins() );
		$this->assertNotContains( 'fy-case-page', $this->base_origins(), 'A superseded entry is covered by its winner.' );
		$this->assertContains( 'fy-bad-type', $this->base_origins(), 'Skipped entries stay in Yoast.' );
		$this->assertArrayNotHasKey( 'fy-old-page', get_option( YoastOptionStore::PLAIN_OPTION ) );
		$this->assertArrayHasKey( 'fy-bad-type', get_option( YoastOptionStore::PLAIN_OPTION ) );

		$this->assertCount( 1, $fired );
		$this->assertCount( 16, $fired[0] );
		$backup = $this->yoast->status()['backup'];
		$this->assertSame( 16, $backup['count'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $backup['last_removed_at'] );
		$row = get_option( YoastSource::BACKUP_OPTION )[0];
		$this->assertSame( [ 'origin' => 'fy-old-page', 'url' => 'fy-new-page', 'type' => 301, 'format' => 'plain' ], $row['entry'] );
		$this->assertSame( get_current_user_id(), $row['removed_by'] );
		$this->assertIsInt( $row['removed_at'] );
	}

	public function test_remove_leaves_uncovered_and_missing_entries(): void {
		$this->seed();
		$this->import_all();
		$this->repo->delete( $this->repo->exact_rule_by_key( '/fy-old-page' )->id );

		$result = $this->yoast->remove(
			[
				$this->item( 'fy-old-page' ),
				$this->item( 'fy-gone' ),
				$this->item( 'fy-not-there' ),
				[ 'format' => 'regex' ] + $this->item( 'fy-gone' ),
				$this->item( 'fy-bad-type' ),
			]
		);

		$this->assertSame(
			[ 'not_covered', 'removed', 'not_found', 'not_found', 'not_covered' ],
			array_column( $result['items'], 'result' )
		);
		$this->assertSame( [ 1, 2, 2 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertContains( 'fy-old-page', $this->base_origins() );
		$this->assertNotContains( 'fy-gone', $this->base_origins() );
		$this->assertSame( 1, $this->yoast->status()['backup']['count'] );
	}

	public function test_nothing_removed_writes_no_backup_and_fires_nothing(): void {
		$this->seed();
		$fired = 0;
		add_action(
			'adv_redirects_yoast_removed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$result = $this->yoast->remove( [ $this->item( 'fy-old-page' ) ] );
		$this->assertSame( 'not_covered', $result['items'][0]['result'], 'Nothing was imported yet.' );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertSame( 0, $fired );
	}

	public function test_a_store_that_finds_nothing_leaves_the_item_not_found_and_writes_no_backup(): void {
		$this->seed();
		$this->import_all();
		$store = new class() implements YoastStore {
			public function remove( array $items ): array {
				return [
					'removed'   => [],
					'not_found' => $items,
				];
			}

			public function add( array $entries ): array {
				return [
					'added'           => [],
					'already_present' => $entries,
				];
			}
		};
		$fired = 0;
		add_action(
			'adv_redirects_yoast_removed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$yoast  = new YoastSource( $this->importer, $store );
		$result = $yoast->remove( [ $this->item( 'fy-old-page' ) ] );

		$this->assertSame( 'not_found', $result['items'][0]['result'] );
		$this->assertSame( [ 0, 1, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertNull( $yoast->status()['backup'] );
		$this->assertFalse( get_option( YoastSource::BACKUP_OPTION ) );
		$this->assertSame( 0, $fired );
	}

	public function test_duplicate_items_are_counted_once(): void {
		$this->seed();
		$this->import_all();
		$item = $this->item( 'fy-old-page' );

		$result = $this->yoast->remove( [ $item, $item ] );

		$this->assertSame( [ 1, 1, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertSame( [ 'removed', 'not_found' ], array_column( $result['items'], 'result' ) );
		$this->assertCount( 1, get_option( YoastSource::BACKUP_OPTION ) );
		$this->assertSame( 1, $this->yoast->status()['backup']['count'] );
		$this->assertNotContains( 'fy-old-page', $this->base_origins() );
	}

	public function test_a_failed_backup_write_removes_nothing(): void {
		$this->seed();
		list( , $remove ) = $this->import_all();
		$before           = get_option( YoastOptionStore::BASE_OPTION );
		add_filter(
			'pre_update_option_' . YoastSource::BACKUP_OPTION,
			static function ( $value, $old_value ) {
				return $old_value;
			},
			10,
			2
		);
		$fired = 0;
		add_action(
			'adv_redirects_yoast_removed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = $this->yoast->remove( $remove );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'adv_redirects_backup_failed', $result->get_error_code() );
		$this->assertSame( $before, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertSame( 0, $fired );
	}

	public function test_premium_manager_store_removes_backs_up_and_restores(): void {
		// Yoast's own classes cannot load malformed rows, so seed only the well-formed ones.
		$well_formed = array_values(
			array_filter(
				$this->fixture,
				static function ( $row ) {
					return is_array( $row ) && isset( $row['origin'], $row['url'], $row['type'], $row['format'] );
				}
			)
		);
		update_option( YoastOptionStore::BASE_OPTION, $well_formed, false );
		$total = count( $well_formed );
		list( , $remove ) = $this->import_all();
		$formats          = array_unique( array_column( $remove, 'format' ) );
		sort( $formats );
		$this->assertSame( [ 'plain', 'regex' ], $formats, 'The removal set has plain and regex entries.' );
		$yoast = new YoastSource( $this->importer, new YoastManagerStore() );

		$result = $yoast->remove( $remove );

		$this->assertSame( [ 16, 0, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertCount( $total - 16, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertNotContains( 'fy-old-page', $this->base_origins() );
		$rows = get_option( YoastSource::BACKUP_OPTION );
		$this->assertCount( 16, $rows );
		foreach ( $rows as $row ) {
			$keys = array_keys( $row['entry'] );
			sort( $keys );
			$this->assertSame( [ 'format', 'origin', 'type', 'url' ], $keys );
		}

		$this->assertSame( [ 'restored' => 16, 'already_present' => 0 ], $yoast->restore() );
		$this->assertCount( $total, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertContains( 'fy-old-page', $this->base_origins() );
		$this->assertNull( $yoast->status()['backup'] );
	}

	public function test_a_malformed_backup_option_is_ignored(): void {
		$valid = [ 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => 'plain' ];
		update_option(
			YoastSource::BACKUP_OPTION,
			[ [ 'entry' => [ 'origin' => 'a' ] ], 'junk', [ 'entry' => $valid, 'removed_at' => 'x' ] ],
			false
		);

		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertSame( [ 'restored' => 0, 'already_present' => 0 ], $this->yoast->restore() );
	}

	public function test_restore_puts_entries_back_and_clears_the_backup(): void {
		$this->seed();
		list( , $remove ) = $this->import_all();
		$this->yoast->remove( $remove );

		$restored = [];
		add_action(
			'adv_redirects_yoast_restored',
			static function ( $entries ) use ( &$restored ) {
				$restored = $entries;
			}
		);

		$this->assertSame( [ 'restored' => 16, 'already_present' => 0 ], $this->yoast->restore() );
		$this->assertCount( 26, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertCount( 16, $restored );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertCount( 16, $this->repo->all(), 'WP Redirects rules are not touched.' );
		$this->assertSame( [ 'restored' => 0, 'already_present' => 0 ], $this->yoast->restore(), 'Nothing to restore.' );
	}

	public function test_restore_skips_origins_yoast_has_again(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ $this->item( 'fy-old-page' ), $this->item( 'fy-gone' ) ] );
		$base   = get_option( YoastOptionStore::BASE_OPTION );
		$base[] = [ 'origin' => 'fy-gone', 'url' => 'recreated', 'type' => 301, 'format' => 'plain' ];
		update_option( YoastOptionStore::BASE_OPTION, $base, false );

		$this->assertSame( [ 'restored' => 1, 'already_present' => 1 ], $this->yoast->restore() );
		$this->assertNull( $this->yoast->status()['backup'] );
	}

	public function test_delete_backup(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ $this->item( 'fy-gone' ) ] );
		$this->yoast->delete_backup();
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertFalse( get_option( YoastSource::BACKUP_OPTION ) );
	}

	public function test_uninstall_removes_the_backup_option(): void {
		// Uninstaller::run() drops the plugin tables (an implicit commit), so check the option list it deletes.
		$this->assertContains( YoastSource::BACKUP_OPTION, Uninstaller::options() );
	}

	public function test_default_store_is_the_option_store_without_premium(): void {
		$this->seed();
		$this->import_all();
		$yoast = new YoastSource( $this->importer );
		$this->assertFalse( YoastSource::premium_active() );
		$this->assertSame( 1, $yoast->remove( [ $this->item( 'fy-gone' ) ] )['removed'] );
	}

	public function test_a_regex_that_matched_the_query_string_is_never_removed(): void {
		$this->seed();
		$this->import_all();
		$item = $this->item( '^/fy-search\\?q=(.*)', 'regex' );
		$this->assertNotNull( $this->repo->regex_rule_by_source( $item['origin'] ), 'It is imported, with its note.' );

		$result = $this->yoast->remove( [ $item ] );

		$this->assertSame( 'not_covered', $result['items'][0]['result'] );
		$this->assertSame( [ 0, 0, 1 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertContains( $item['origin'], $this->base_origins() );
		$this->assertNull( $this->yoast->status()['backup'] );
	}

	/**
	 * @dataProvider edits
	 */
	public function test_an_entry_edited_in_yoast_after_the_import_is_not_removed( string $field, $value ): void {
		$this->seed();
		$this->import_all();
		$base = get_option( YoastOptionStore::BASE_OPTION );
		$this->assertSame( 'fy-old-page', $base[0]['origin'] );
		$base[0][ $field ] = $value;
		update_option( YoastOptionStore::BASE_OPTION, $base, false );

		$result = $this->yoast->remove( [ $this->item( 'fy-old-page' ) ] );

		$this->assertSame( 'not_found', $result['items'][0]['result'] );
		$this->assertSame( [ 0, 1, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertContains( 'fy-old-page', $this->base_origins() );
		$this->assertSame( $value, get_option( YoastOptionStore::BASE_OPTION )[0][ $field ] );
		$this->assertNull( $this->yoast->status()['backup'] );
	}

	public static function edits(): array {
		return [
			'url'  => [ 'url', 'fy-somewhere-else' ],
			'type' => [ 'type', 302 ],
		];
	}

	public function test_an_unchanged_entry_with_a_numeric_string_type_is_removed(): void {
		$this->seed();
		$this->import_all();
		$item = $this->item( 'fy-numeric-type' );
		$this->assertSame( 301, $item['type'] );
		$this->assertSame( 'removed', $this->yoast->remove( [ $item ] )['items'][0]['result'], 'Yoast stored type "301"; the client sends 301.' );
	}
}
