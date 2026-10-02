<?php

use Advision\Redirects\Rest\NotFoundController;
use Advision\Redirects\Rest\SettingsController;
use Advision\Redirects\Tracking\NotFoundRepository;

final class RestNotFoundSettingsTest extends Adv_Redirects_Rest_TestCase {

	protected function controllers(): array {
		return [ new NotFoundController( new NotFoundRepository() ), new SettingsController() ];
	}

	private function seed(): void {
		$repo = new NotFoundRepository();
		foreach ( [ '/a', '/b', '/b', '/c' ] as $path ) {
			$repo->log( $path, '', current_time( 'mysql', true ) );
		}
	}

	public function test_list_with_paging_headers(): void {
		$this->seed();
		$response = $this->rest( 'GET', '/404s', null, [ 'per_page' => 2 ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '/b', $response->get_data()[0]['path'] );
		$this->assertSame( 3, $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_list_rejects_bad_params(): void {
		$this->assertSame( 400, $this->rest( 'GET', '/404s', null, [ 'per_page' => 500 ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', '/404s', null, [ 'orderby' => 'id' ] )->get_status() );
	}

	public function test_delete_bulk_and_clear(): void {
		$this->seed();
		$ids = array_column( $this->rest( 'GET', '/404s' )->get_data(), 'id' );

		$this->assertTrue( $this->rest( 'DELETE', '/404s/' . $ids[0] )->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'DELETE', '/404s/' . $ids[0] )->get_status() );
		$this->assertSame( 1, $this->rest( 'POST', '/404s/bulk', [ 'action' => 'delete', 'ids' => [ $ids[1] ] ] )->get_data()['deleted'] );
		$this->assertSame( 1, $this->rest( 'DELETE', '/404s' )->get_data()['deleted'] );
	}

	public function test_settings_read_and_update(): void {
		$this->assertTrue( $this->rest( 'GET', '/settings' )->get_data()['log_404'] );

		$updated = $this->rest( 'PUT', '/settings', [ 'log_404' => false, 'log_404_retention_days' => 7, 'excluded_404_extensions' => [ 'css', 'PDF' ] ] )->get_data();
		$this->assertFalse( $updated['log_404'] );
		$this->assertSame( 7, $updated['log_404_retention_days'] );
		$this->assertSame( [ 'css', 'pdf' ], $updated['excluded_404_extensions'] );
	}

	public function test_settings_validation(): void {
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'log_404_retention_days' => 0 ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'excluded_404_extensions' => [ '../x' ] ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'evil' => true ] )->get_status() );
	}

	public function test_permissions(): void {
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'GET', '/404s' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->rest( 'DELETE', '/404s' )->get_status() );
	}

	public function test_bulk_rejects_unknown_fields(): void {
		$this->seed();
		$ids      = array_column( $this->rest( 'GET', '/404s' )->get_data(), 'id' );
		$response = $this->rest(
			'POST',
			'/404s/bulk',
			[
				'action' => 'delete',
				'ids'    => [ $ids[0] ],
				'extra'  => 1,
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'] );
		$this->assertCount( 3, $this->rest( 'GET', '/404s' )->get_data() );
	}

	public function test_delete_reads_id_from_url_only(): void {
		$repo = new NotFoundRepository();
		$repo->log( '/first', '', current_time( 'mysql', true ) );
		$repo->log( '/second', '', current_time( 'mysql', true ) );
		$ids = array_column( $this->rest( 'GET', '/404s', null, [ 'orderby' => 'path', 'order' => 'asc' ] )->get_data(), 'id', 'path' );

		$this->rest( 'DELETE', '/404s/' . $ids['/first'], null, [ 'id' => $ids['/second'] ] );

		$remaining = array_column( $this->rest( 'GET', '/404s' )->get_data(), 'path' );
		$this->assertSame( [ '/second' ], $remaining );
	}
}
