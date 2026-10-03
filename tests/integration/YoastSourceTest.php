<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Uninstaller;

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
	 * Previews and imports everything the preview accepts, as the admin does.
	 *
	 * @return array{0:array,1:array} Preview, and the removal candidates { origin, format }.
	 */
	private function import_all(): array {
		$entries = $this->yoast->entries();
		$preview = $this->importer->preview( $entries, [], Importer::SOURCE_YOAST );
		$batch   = [];
		$remove  = [];
		foreach ( $preview['entries'] as $entry ) {
			$raw = $entries[ $entry['index'] ];
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$batch[]  = $raw;
				$remove[] = [ 'origin' => $raw['origin'], 'format' => $raw['format'] ];
			} elseif ( 'superseded' === $entry['status'] ) {
				$remove[] = [ 'origin' => $raw['origin'], 'format' => $raw['format'] ];
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
		$this->assertCount( 17, $remove );

		$fired = [];
		add_action(
			'adv_redirects_yoast_removed',
			static function ( $entries ) use ( &$fired ) {
				$fired[] = $entries;
			}
		);

		$result = $this->yoast->remove( $remove );

		$this->assertSame( [ 17, 0, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertSame( [ 'removed' ], array_values( array_unique( array_column( $result['items'], 'result' ) ) ) );
		$this->assertCount( 9, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertNotContains( 'fy-old-page', $this->base_origins() );
		$this->assertNotContains( 'fy-case-page', $this->base_origins(), 'A superseded entry is covered by its winner.' );
		$this->assertContains( 'fy-bad-type', $this->base_origins(), 'Skipped entries stay in Yoast.' );
		$this->assertArrayNotHasKey( 'fy-old-page', get_option( YoastOptionStore::PLAIN_OPTION ) );
		$this->assertArrayHasKey( 'fy-bad-type', get_option( YoastOptionStore::PLAIN_OPTION ) );

		$this->assertCount( 1, $fired );
		$this->assertCount( 17, $fired[0] );
		$backup = $this->yoast->status()['backup'];
		$this->assertSame( 17, $backup['count'] );
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
				[ 'origin' => 'fy-old-page', 'format' => 'plain' ],
				[ 'origin' => 'fy-gone', 'format' => 'plain' ],
				[ 'origin' => 'fy-not-there', 'format' => 'plain' ],
				[ 'origin' => 'fy-gone', 'format' => 'regex' ],
				[ 'origin' => 'fy-bad-type', 'format' => 'plain' ],
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
		$result = $this->yoast->remove( [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ] ] );
		$this->assertSame( 'not_covered', $result['items'][0]['result'], 'Nothing was imported yet.' );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertSame( 0, $fired );
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

		$this->assertSame( [ 'restored' => 17, 'already_present' => 0 ], $this->yoast->restore() );
		$this->assertCount( 26, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertCount( 17, $restored );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertCount( 16, $this->repo->all(), 'WP Redirects rules are not touched.' );
		$this->assertSame( [ 'restored' => 0, 'already_present' => 0 ], $this->yoast->restore(), 'Nothing to restore.' );
	}

	public function test_restore_skips_origins_yoast_has_again(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ], [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] );
		$base   = get_option( YoastOptionStore::BASE_OPTION );
		$base[] = [ 'origin' => 'fy-gone', 'url' => 'recreated', 'type' => 301, 'format' => 'plain' ];
		update_option( YoastOptionStore::BASE_OPTION, $base, false );

		$this->assertSame( [ 'restored' => 1, 'already_present' => 1 ], $this->yoast->restore() );
		$this->assertNull( $this->yoast->status()['backup'] );
	}

	public function test_delete_backup(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] );
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
		$this->assertSame( 1, $yoast->remove( [ [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] )['removed'] );
	}
}
