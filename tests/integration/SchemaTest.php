<?php

use Advision\Redirects\Schema;

final class SchemaTest extends WP_UnitTestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'adv_redirects', Schema::redirects_table() );
		$this->assertSame( $wpdb->prefix . 'adv_redirects_404s', Schema::not_found_table() );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::redirects_table() ) );
		$this->assertSame(
			[ 'id', 'type', 'source', 'target', 'status_code', 'position', 'enabled', 'origin', 'note', 'hits', 'last_hit_at', 'created_at', 'updated_at' ],
			$columns
		);

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::not_found_table() ) );
		$this->assertSame( [ 'id', 'path', 'path_hash', 'hits', 'first_seen', 'last_seen', 'last_referrer' ], $columns );
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
