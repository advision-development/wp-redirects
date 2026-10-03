<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\ImportController;
use Advision\Redirects\Rest\YoastImportController;

final class RestYoastImportTest extends Adv_Redirects_Rest_TestCase {

	private array $fixture;

	protected function controllers(): array {
		$repo     = new Repository();
		$importer = new Importer( $repo, new Validator( $repo, new ChainResolver() ) );
		return [ new ImportController( $importer ), new YoastImportController( new YoastSource( $importer ) ) ];
	}

	public function set_up() {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		delete_option( YoastSource::BACKUP_OPTION );
		$this->fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		update_option( YoastOptionStore::BASE_OPTION, $this->fixture, false );
	}

	private function entries(): array {
		return $this->rest( 'GET', '/import/yoast' )->get_data()['entries'];
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( [ '/import/yoast', '/import/yoast/remove', '/import/yoast/restore', '/import/yoast/backup' ] as $route ) {
			$this->assertArrayHasKey( '/adv-redirects/v1' . $route, $routes, $route );
		}
	}

	public function test_status(): void {
		$response = $this->rest( 'GET', '/import/yoast' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['detected'] );
		$this->assertSame( [ 'plain' => 19, 'regex' => 6 ], $data['counts'] );
		$this->assertSame( 'php', $data['server_mode'] );
		$this->assertNull( $data['backup'] );
	}

	public function test_preview_import_remove_restore(): void {
		$entries = $this->entries();
		$preview = $this->rest( 'POST', '/import/preview', [ 'source' => 'yoast', 'redirects' => $entries ] );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 16, $preview->get_data()['counts']['new'] );

		$import = $this->rest( 'POST', '/import', [ 'source' => 'yoast', 'redirects' => [ $entries[0] ] ] );
		$this->assertSame( 1, $import->get_data()['counts']['created'] );

		$remove = $this->rest( 'POST', '/import/yoast/remove', [ 'entries' => [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ], [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] ] );
		$this->assertSame( 200, $remove->get_status() );
		$this->assertSame( [ 1, 0, 1 ], [ $remove->get_data()['removed'], $remove->get_data()['not_found'], $remove->get_data()['not_covered'] ] );
		$this->assertSame( 1, $this->rest( 'GET', '/import/yoast' )->get_data()['backup']['count'] );

		$restore = $this->rest( 'POST', '/import/yoast/restore', [] );
		$this->assertSame( [ 'restored' => 1, 'already_present' => 0 ], $restore->get_data() );

		$this->rest( 'POST', '/import/yoast/remove', [ 'entries' => [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ] ] ] );
		$delete = $this->rest( 'DELETE', '/import/yoast/backup' );
		$this->assertSame( [ 'deleted' => true ], $delete->get_data() );
		$this->assertNull( $this->rest( 'GET', '/import/yoast' )->get_data()['backup'] );
	}

	public function test_yoast_import_bodies_reject_redirection_fields(): void {
		$one = [ $this->entries()[0] ];
		foreach ( [ 'groups' => [], 'version' => '1' ] as $field => $value ) {
			$body           = [ 'source' => 'yoast', 'redirects' => $one ];
			$body[ $field ] = $value;
			$response       = $this->rest( 'POST', '/import/preview', $body );
			$this->assertSame( 400, $response->get_status(), $field );
			$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'], $field );
		}
		$this->assertSame( 400, $this->rest( 'POST', '/import', [ 'source' => 'yoast', 'redirects' => $one, 'groups' => [] ] )->get_status() );
	}

	public function test_remove_schema(): void {
		$item = [ 'origin' => 'fy-gone', 'format' => 'plain' ];
		$bad  = [
			'empty'         => [ 'entries' => [] ],
			'too many'      => [ 'entries' => array_fill( 0, 501, $item ) ],
			'no format'     => [ 'entries' => [ [ 'origin' => 'a' ] ] ],
			'bad format'    => [ 'entries' => [ [ 'origin' => 'a', 'format' => 'x' ] ] ],
			'empty origin'  => [ 'entries' => [ [ 'origin' => '', 'format' => 'plain' ] ] ],
			'extra prop'    => [ 'entries' => [ $item + [ 'url' => 'x' ] ] ],
			'unknown field' => [ 'entries' => [ $item ], 'force' => true ],
			'missing'       => [],
		];
		foreach ( $bad as $label => $body ) {
			$this->assertSame( 400, $this->rest( 'POST', '/import/yoast/remove', $body )->get_status(), $label );
		}
		$this->assertSame( 200, $this->rest( 'POST', '/import/yoast/remove', [ 'entries' => array_fill( 0, 500, $item ) ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import/yoast/restore', [ 'all' => true ] )->get_status() );
	}

	public function test_preview_limit_is_5000(): void {
		$one = $this->entries()[0];
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', [ 'source' => 'yoast', 'redirects' => array_fill( 0, 5001, $one ) ] )->get_status() );
	}
}
