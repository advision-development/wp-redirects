<?php

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Settings;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundLogger;
use Advision\Redirects\Tracking\NotFoundRepository;

final class NotFoundTest extends WP_UnitTestCase {

	private NotFoundRepository $repo;
	private NotFoundLogger $logger;

	public function set_up(): void {
		parent::set_up();
		$rules        = new Repository();
		$this->repo   = new NotFoundRepository();
		$this->logger = new NotFoundLogger( $this->repo, new Redirector( new RuleCache( $rules ), new HitTracker( $rules ) ) );
	}

	public function test_logging_upserts_by_path(): void {
		$this->assertTrue( $this->logger->log_request( '/missing?x=1', 'GET', 'https://ref.example/' ) );
		$this->assertTrue( $this->logger->log_request( '/missing?x=1', 'GET', '' ) );
		$this->assertTrue( $this->logger->log_request( '/other', 'GET', '' ) );

		$result = $this->repo->query( [] );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( '/missing?x=1', $result['items'][0]['path'] );
		$this->assertSame( 2, $result['items'][0]['hits'] );
		$this->assertSame( '', $result['items'][0]['last_referrer'] );
	}

	public function test_skips_non_get_excluded_extensions_and_disabled_setting(): void {
		$this->assertFalse( $this->logger->log_request( '/missing', 'POST', '' ) );
		$this->assertFalse( $this->logger->log_request( '/style.CSS', 'GET', '' ) );

		add_filter( 'adv_redirects_log_404', '__return_false' );
		$this->assertFalse( $this->logger->log_request( '/missing', 'GET', '' ) );
		remove_all_filters( 'adv_redirects_log_404' );

		Settings::update( [ 'log_404' => false ] );
		$this->assertFalse( $this->logger->log_request( '/missing', 'GET', '' ) );
		$this->assertSame( 0, $this->repo->query( [] )['total'] );
	}

	public function test_clean_strips_control_characters_and_truncates(): void {
		$this->assertSame( 'ab', NotFoundLogger::clean( "a\r\n\0b" ) );
		$this->assertSame( 2048, mb_strlen( NotFoundLogger::clean( str_repeat( 'é', 3000 ) ) ) );
	}

	public function test_query_search_sort_and_paging(): void {
		foreach ( [ '/a', '/b', '/b', '/b', '/c', '/c' ] as $path ) {
			$this->logger->log_request( $path, 'GET', '' );
		}
		$this->assertSame( [ '/b', '/c', '/a' ], array_column( $this->repo->query( [] )['items'], 'path' ) );
		$this->assertSame( [ '/a', '/b', '/c' ], array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' ) );
		$this->assertSame( [ '/c' ], array_column( $this->repo->query( [ 'search' => 'c' ] )['items'], 'path' ) );

		$page = $this->repo->query( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ '/a' ], array_column( $page['items'], 'path' ) );

		$this->assertCount( 3, $this->repo->query( [ 'orderby' => 'id; DROP TABLE x', 'order' => 'sideways' ] )['items'] );
	}

	public function test_search_treats_wildcards_literally(): void {
		$this->logger->log_request( '/a_b', 'GET', '' );
		$this->logger->log_request( '/axb', 'GET', '' );
		$this->assertSame( [ '/a_b' ], array_column( $this->repo->query( [ 'search' => 'a_b' ] )['items'], 'path' ) );
	}

	public function test_delete_many_and_clear(): void {
		$this->logger->log_request( '/a', 'GET', '' );
		$this->logger->log_request( '/b', 'GET', '' );
		$this->logger->log_request( '/c', 'GET', '' );
		$ids = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'id' );

		$this->assertTrue( $this->repo->delete( $ids[0] ) );
		$this->assertSame( 1, $this->repo->delete_many( [ $ids[1], 999999 ] ) );
		$this->assertSame( 1, $this->repo->clear() );
		$this->assertSame( 0, $this->repo->query( [] )['total'] );
	}

	public function test_creating_a_redirect_removes_matching_404(): void {
		$this->logger->log_request( '/Old-Page/', 'GET', '' );
		$this->logger->log_request( '/old-page-2', 'GET', '' );
		$this->logger->log_request( '/old-page-zzz', 'GET', '' );
		$this->logger->register();

		( new Repository() )->insert( [ 'type' => 'exact', 'source' => '/old-page', 'target' => '/new', 'status_code' => 301 ] );

		$remaining = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' );
		$this->assertSame( [ '/old-page-2', '/old-page-zzz' ], $remaining );
	}

	public function test_query_less_redirect_also_clears_tracking_parameter_variants(): void {
		foreach ( [ '/old', '/old/?fbclid=abc', '/old?utm_source=x', '/old-other?utm_source=x', '/old/sub?x=1', '/other?next=/old' ] as $path ) {
			$this->logger->log_request( $path, 'GET', '' );
		}
		$this->logger->register();

		( new Repository() )->insert( [ 'type' => 'exact', 'source' => '/old', 'target' => '/new', 'status_code' => 301 ] );

		$remaining = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' );
		$this->assertSame( [ '/old-other?utm_source=x', '/old/sub?x=1', '/other?next=/old' ], $remaining );
	}

	public function test_redirect_with_a_query_only_clears_that_exact_query(): void {
		foreach ( [ '/old?page=2', '/old?page=3', '/old' ] as $path ) {
			$this->logger->log_request( $path, 'GET', '' );
		}
		$this->logger->register();

		( new Repository() )->insert( [ 'type' => 'exact', 'source' => '/old?page=2', 'target' => '/new', 'status_code' => 301 ] );

		$remaining = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' );
		$this->assertSame( [ '/old', '/old?page=3' ], $remaining );
	}

	public function test_invalid_utf8_path_is_logged_not_blanked(): void {
		// Each invalid byte becomes U+FFFD on every WordPress version; valid multibyte text is kept.
		$this->assertSame( "/caf\u{FFFD}", NotFoundLogger::clean( "/caf\xE9" ) );
		$this->assertSame( "/a\u{FFFD}\u{FFFD}b", NotFoundLogger::clean( "/a\xE2\x82b" ) );
		$this->assertSame( "/\u{FFFD}\u{FFFD}", NotFoundLogger::clean( "/\xC0\xAF" ) );
		$this->assertSame( '/café/日本', NotFoundLogger::clean( '/café/日本' ) );
		$this->assertSame( '/tab', NotFoundLogger::clean( "/t\x00a\tb" ) );

		$this->assertTrue( $this->logger->log_request( '/caf%E9', 'GET', '' ) );
		$this->assertTrue( $this->logger->log_request( '/other%FF', 'GET', '' ) );

		$paths = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' );
		$this->assertSame( 2, count( $paths ) );
		$this->assertNotContains( '', $paths );
		$this->assertSame( [ NotFoundLogger::clean( "/caf\xE9" ), NotFoundLogger::clean( "/other\xFF" ) ], $paths );
	}

	public function test_invalid_utf8_referrer_is_not_blanked(): void {
		$this->logger->log_request( '/missing', 'GET', "https://ref.example/caf\xE9" );
		$referrer = $this->repo->query( [] )['items'][0]['last_referrer'];
		$this->assertSame( "https://ref.example/caf\u{FFFD}", $referrer );
	}

	public function test_prune_by_age_and_row_cap(): void {
		global $wpdb;
		$this->repo->log( '/old', '', '2020-01-01 00:00:00' );
		$this->repo->log( '/a', '', gmdate( 'Y-m-d H:i:s', time() - 30 ) );
		$this->repo->log( '/b', '', gmdate( 'Y-m-d H:i:s', time() - 20 ) );
		$this->repo->log( '/c', '', gmdate( 'Y-m-d H:i:s', time() - 10 ) );

		$this->assertSame( 2, $this->repo->prune( 30, 2 ) );
		$this->assertSame( [ '/b', '/c' ], array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' ) );
	}
}
