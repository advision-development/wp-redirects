<?php

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Schema;

final class SchemaTest extends WP_UnitTestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'adv_redirects', Schema::redirects_table() );
		$this->assertSame( $wpdb->prefix . 'adv_redirects_404s', Schema::not_found_table() );

		// Sorted: an upgraded table gets new columns appended, a fresh one has them in place.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::redirects_table() ) );
		$expected = [ 'id', 'type', 'source', 'target', 'status_code', 'position', 'enabled', 'trailing_slash', 'origin', 'created_by', 'created_via', 'updated_by', 'note', 'hits', 'last_hit_at', 'created_at', 'updated_at' ];
		sort( $columns );
		sort( $expected );
		$this->assertSame( $expected, $columns );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::not_found_table() ) );
		$this->assertSame( [ 'id', 'path', 'path_hash', 'hits', 'first_seen', 'last_seen', 'last_referrer' ], $columns );
	}

	public function test_schema_version_is_three(): void {
		$this->assertSame( '3', Schema::VERSION );
	}

	public function test_upgrade_from_v2_adds_trailing_slash_defaulting_to_off(): void {
		global $wpdb;
		$table = Schema::redirects_table();
		// DDL commits implicitly, so this test cleans up after itself and commits the cleanup.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN trailing_slash', $table ) );
		$wpdb->insert(
			$table,
			[ 'type' => 'exact', 'source' => '/v2-row', 'target' => '/b', 'status_code' => 301, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ]
		);
		$id = (int) $wpdb->insert_id;
		update_option( Schema::VERSION_OPTION, '2' );
		wp_cache_set( RuleCache::KEY, [ 'stale' => true ], RuleCache::GROUP );

		Schema::maybe_upgrade();

		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );
		$this->assertContains( 'trailing_slash', $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT trailing_slash FROM %i WHERE id = %d', $table, $id ) ) );
		$this->assertFalse( ( new Repository() )->find( $id )->trailing_slash );
		$this->assertFalse( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ), 'The compiled rule set is flushed on upgrade.' );

		$wpdb->delete( $table, [ 'id' => $id ] );
		$wpdb->query( 'COMMIT' );
	}

	public function test_version_is_not_bumped_when_the_v3_column_is_missing(): void {
		global $wpdb;
		$table = Schema::redirects_table();
		// DDL commits implicitly; the second upgrade restores the column and the cleanup is committed.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN trailing_slash', $table ) );
		update_option( Schema::VERSION_OPTION, '2' );
		$fail = static function () {
			return [];
		};
		// dbDelta then runs no CREATE/ALTER, as if the upgrade query had failed.
		add_filter( 'dbdelta_create_queries', $fail );

		Schema::maybe_upgrade();

		remove_filter( 'dbdelta_create_queries', $fail );
		$this->assertNotContains( 'trailing_slash', $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) );
		$this->assertSame( '2', get_option( Schema::VERSION_OPTION ), 'Left unbumped, so the upgrade retries on the next load.' );

		Schema::maybe_upgrade();

		$this->assertContains( 'trailing_slash', $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) );
		$this->assertSame( '3', get_option( Schema::VERSION_OPTION ) );
		$wpdb->query( 'COMMIT' );
	}

	public function test_install_records_version(): void {
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_reinstalls_when_version_differs(): void {
		update_option( Schema::VERSION_OPTION, '0' );
		Schema::maybe_upgrade();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}
}
