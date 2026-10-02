<?php

use Advision\Redirects\Settings;

final class SettingsTest extends WP_UnitTestCase {

	public function test_defaults(): void {
		$all = Settings::all();
		$this->assertTrue( $all['slug_watcher'] );
		$this->assertTrue( $all['log_404'] );
		$this->assertSame( 30, $all['log_404_retention_days'] );
		$this->assertSame( 5000, $all['log_404_max_rows'] );
		$this->assertTrue( $all['forward_query_string'] );
		$this->assertFalse( $all['remove_data_on_uninstall'] );
		$this->assertContains( 'css', $all['excluded_404_extensions'] );
	}

	public function test_sanitize_clamps_and_parses(): void {
		$clean = Settings::sanitize(
			[
				'slug_watcher'            => 'false',
				'log_404_retention_days'  => 9999,
				'log_404_max_rows'        => 5,
				'excluded_404_extensions' => ' .CSS, js,,bad ext!, png ',
				'unknown'                 => 'x',
			]
		);
		$this->assertFalse( $clean['slug_watcher'] );
		$this->assertSame( 365, $clean['log_404_retention_days'] );
		$this->assertSame( 100, $clean['log_404_max_rows'] );
		$this->assertSame( [ 'css', 'js', 'png' ], $clean['excluded_404_extensions'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
	}

	public function test_update_merges_and_persists(): void {
		Settings::update( [ 'log_404' => false ] );
		$this->assertFalse( Settings::get( 'log_404' ) );
		$this->assertTrue( Settings::get( 'slug_watcher' ) );
		$this->assertSame( 30, Settings::get( 'log_404_retention_days' ) );
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		update_option( Settings::OPTION, 'garbage' );
		$this->assertSame( Settings::defaults(), Settings::all() );
	}
}
