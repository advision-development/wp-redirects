<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\ImportController;

final class RestImportTest extends Adv_Redirects_Rest_TestCase {

	private array $export;

	protected function controllers(): array {
		$repo = new Repository();
		return [ new ImportController( new Importer( $repo, new Validator( $repo, new ChainResolver() ) ) ) ];
	}

	public function set_up() {
		parent::set_up();
		$this->export = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
	}

	private function body( array $redirects, bool $preview = true ): array {
		$body = [
			'source'    => 'redirection',
			'groups'    => $this->export['groups'],
			'redirects' => $redirects,
		];
		if ( $preview ) {
			$body['version'] = $this->export['plugin']['version'];
		}
		return $body;
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/adv-redirects/v1/import/preview', $routes );
		$this->assertArrayHasKey( '/adv-redirects/v1/import', $routes );
	}

	public function test_preview_and_import(): void {
		$preview = $this->rest( 'POST', '/import/preview', $this->body( $this->export['redirects'] ) );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 13, $preview->get_data()['counts']['new'] );

		$batch  = array_slice( $this->export['redirects'], 0, 2 );
		$import = $this->rest( 'POST', '/import', $this->body( $batch, false ) );
		$this->assertSame( 200, $import->get_status() );
		$this->assertSame( 2, $import->get_data()['counts']['created'] );
	}

	public function test_permissions(): void {
		foreach ( [ [ '/import/preview', true ], [ '/import', false ] ] as list( $route, $preview ) ) {
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->rest( 'POST', $route, $this->body( [ $this->export['redirects'][0] ], $preview ) )->get_status(), $route );
			wp_set_current_user( self::$editor_id );
			$this->assertSame( 403, $this->rest( 'POST', $route, $this->body( [ $this->export['redirects'][0] ], $preview ) )->get_status(), $route );
		}
	}

	public function test_schema_limits_and_unknown_fields(): void {
		$one = [ $this->export['redirects'][0] ];

		$bad_source           = $this->body( $one );
		$bad_source['source'] = 'yoast';
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $bad_source )->get_status() );

		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $this->body( [] ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $this->body( array_fill( 0, 2001, $one[0] ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import', $this->body( array_fill( 0, 51, $one[0] ), false ) )->get_status() );

		$extra         = $this->body( $one );
		$extra['logs'] = $this->export['logs'];
		$response      = $this->rest( 'POST', '/import/preview', $extra );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'] );

		$with_version = $this->body( $one, true );
		$this->assertSame( 400, $this->rest( 'POST', '/import', $with_version )->get_status(), 'version is preview-only' );
	}
}
