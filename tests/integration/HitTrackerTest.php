<?php

use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Tracking\HitTracker;

final class HitTrackerTest extends WP_UnitTestCase {

	private Repository $repo;
	private HitTracker $tracker;
	private int $rule_id;

	public function set_up(): void {
		parent::set_up();
		$this->repo    = new Repository();
		$this->tracker = new HitTracker( $this->repo );
		$this->rule_id = $this->repo->insert( [ 'type' => 'exact', 'source' => '/old', 'target' => '/new', 'status_code' => 301 ] )->id;
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	public function test_without_persistent_cache_writes_immediately(): void {
		wp_using_ext_object_cache( false );
		$this->tracker->record( $this->rule_id );
		$rule = $this->repo->find( $this->rule_id );
		$this->assertSame( 1, $rule->hits );
		$this->assertNotNull( $rule->last_hit_at );
	}

	public function test_with_persistent_cache_buffers_until_flush(): void {
		wp_using_ext_object_cache( true );
		$this->tracker->record( $this->rule_id );
		$this->tracker->record( $this->rule_id );
		$this->tracker->record( $this->rule_id );
		$this->assertSame( 0, $this->repo->find( $this->rule_id )->hits );

		$this->assertSame( 3, $this->tracker->flush() );
		$this->assertSame( 3, $this->repo->find( $this->rule_id )->hits );
		$this->assertSame( 0, (int) wp_cache_get( 'hit:' . $this->rule_id, 'adv_redirects' ) );

		$this->assertSame( 0, $this->tracker->flush(), 'Second flush writes nothing.' );
		$this->assertSame( 3, $this->repo->find( $this->rule_id )->hits );
	}

	public function test_filter_disables_tracking(): void {
		add_filter( 'adv_redirects_hit_tracking_enabled', '__return_false' );
		$this->tracker->record( $this->rule_id );
		$this->assertSame( 0, $this->repo->find( $this->rule_id )->hits );
	}
}
