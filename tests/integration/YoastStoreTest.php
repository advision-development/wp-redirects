<?php

use Advision\Redirects\Import\YoastManagerStore;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastStore;

require_once __DIR__ . '/doubles/yoast-premium-doubles.php';

final class YoastStoreTest extends WP_UnitTestCase {

	private const SEED = [
		[ 'origin' => 'a-page', 'url' => 'a-target', 'type' => 301, 'format' => 'plain' ],
		[ 'origin' => 'b-page', 'url' => '', 'type' => 410, 'format' => 'plain' ],
		[ 'origin' => '^/c/(.*)', 'url' => 'c-new/$1', 'type' => 302, 'format' => 'regex' ],
	];

	public function set_up(): void {
		parent::set_up();
		update_option( YoastOptionStore::BASE_OPTION, self::SEED, false );
		delete_option( YoastOptionStore::PLAIN_OPTION );
		delete_option( YoastOptionStore::REGEX_OPTION );
		WPSEO_Redirect_Manager::$saves = 0;
	}

	public static function stores(): array {
		return [
			'options' => [ YoastOptionStore::class ],
			'manager' => [ YoastManagerStore::class ],
		];
	}

	private function store( string $class ): YoastStore {
		return new $class();
	}

	/**
	 * @dataProvider stores
	 */
	public function test_remove_writes_base_and_export_options( string $class ): void {
		$outcome = $this->store( $class )->remove(
			[
				[ 'origin' => 'a-page', 'format' => 'plain' ],
				[ 'origin' => '^/c/(.*)', 'format' => 'regex' ],
			]
		);

		$this->assertSame( [ self::SEED[0], self::SEED[2] ], $outcome['removed'] );
		$this->assertSame( [], $outcome['not_found'] );
		$this->assertSame( [ self::SEED[1] ], get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertSame( [ 'b-page' => [ 'url' => '', 'type' => 410 ] ], get_option( YoastOptionStore::PLAIN_OPTION ) );
		$this->assertSame( [], get_option( YoastOptionStore::REGEX_OPTION ) );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_remove_reports_items_it_cannot_find( string $class ): void {
		$outcome = $this->store( $class )->remove(
			[
				[ 'origin' => 'missing', 'format' => 'plain' ],
				[ 'origin' => 'a-page', 'format' => 'regex' ],
			]
		);

		$this->assertSame( [], $outcome['removed'] );
		$this->assertSame( [ [ 'origin' => 'missing', 'format' => 'plain' ], [ 'origin' => 'a-page', 'format' => 'regex' ] ], $outcome['not_found'] );
		$this->assertSame( self::SEED, get_option( YoastOptionStore::BASE_OPTION ), 'Nothing is written when nothing is removed.' );
		$this->assertFalse( get_option( YoastOptionStore::PLAIN_OPTION ) );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_add_appends_new_origins_and_skips_present_ones( string $class ): void {
		$new     = [ 'origin' => 'd-page', 'url' => 'd-target', 'type' => 307, 'format' => 'plain' ];
		$clash   = [ 'origin' => 'a-page', 'url' => 'other', 'type' => 301, 'format' => 'plain' ];
		$outcome = $this->store( $class )->add( [ $new, $clash ] );

		$this->assertSame( [ $new ], $outcome['added'] );
		$this->assertSame( [ $clash ], $outcome['already_present'] );
		$this->assertSame( array_merge( self::SEED, [ $new ] ), get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertSame( [ 'url' => 'd-target', 'type' => 307 ], get_option( YoastOptionStore::PLAIN_OPTION )['d-page'] );
		$this->assertSame( [ 'url' => 'c-new/$1', 'type' => 302 ], get_option( YoastOptionStore::REGEX_OPTION )['^/c/(.*)'] );
	}

	public function test_manager_store_saves_once_per_request(): void {
		$store = new YoastManagerStore();
		$store->remove(
			[
				[ 'origin' => 'a-page', 'format' => 'plain' ],
				[ 'origin' => 'b-page', 'format' => 'plain' ],
				[ 'origin' => '^/c/(.*)', 'format' => 'regex' ],
			]
		);
		$this->assertSame( 1, WPSEO_Redirect_Manager::$saves );

		$store->add( self::SEED );
		$this->assertSame( 2, WPSEO_Redirect_Manager::$saves );

		$store->remove( [ [ 'origin' => 'missing', 'format' => 'plain' ] ] );
		$this->assertSame( 2, WPSEO_Redirect_Manager::$saves, 'No save when nothing changed.' );
	}

	public function test_option_store_keeps_export_autoload_and_skips_malformed_rows(): void {
		add_option( YoastOptionStore::PLAIN_OPTION, [], '', false );
		update_option( YoastOptionStore::BASE_OPTION, array_merge( self::SEED, [ 'junk', [ 'origin' => 'x' ] ] ), false );

		( new YoastOptionStore() )->remove( [ [ 'origin' => 'a-page', 'format' => 'plain' ] ] );

		$this->assertCount( 4, get_option( YoastOptionStore::BASE_OPTION ), 'Malformed rows are kept untouched.' );
		$this->assertSame( [ 'b-page' ], array_keys( get_option( YoastOptionStore::PLAIN_OPTION ) ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( YoastOptionStore::PLAIN_OPTION, wp_load_alloptions() );
	}
}
