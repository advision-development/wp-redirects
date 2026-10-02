<?php

use Advision\Redirects\Updates\UpdateChecker;

final class UpdateCheckerTest extends WP_UnitTestCase {

	private const FILTER = 'puc_vcs_update_detection_strategies-wp-redirects';

	public function tear_down(): void {
		remove_all_filters( self::FILTER );
		unset( $GLOBALS['current_screen'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_boot_is_a_noop_on_the_front_end(): void {
		remove_all_filters( self::FILTER );
		UpdateChecker::boot( ADV_REDIRECTS_FILE );
		$this->assertFalse( has_filter( self::FILTER ) );
	}

	public function test_only_latest_release_strategy_survives_in_admin(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		UpdateChecker::boot( ADV_REDIRECTS_FILE );
		$this->assertNotFalse( has_filter( self::FILTER ), 'Strategy filter must be registered after boot.' );

		$strategies = [
			'latest_release' => static function () {
				return 'release';
			},
			'latest_tag'     => static function () {
				return 'tag';
			},
			'branch'         => static function () {
				return 'branch';
			},
		];
		$filtered   = apply_filters( self::FILTER, $strategies, 'wp-redirects' );

		$this->assertSame( [ 'latest_release' ], array_keys( $filtered ) );
	}

	public function test_only_latest_release_helper(): void {
		$this->assertSame( [], UpdateChecker::only_latest_release( [ 'latest_tag' => 1, 'branch' => 2 ] ) );
		$this->assertSame( [ 'latest_release' => 1 ], UpdateChecker::only_latest_release( [ 'latest_release' => 1, 'branch' => 2 ] ) );
	}
}
