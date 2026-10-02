<?php

use Advision\Redirects\Cron;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundRepository;

final class CronTest extends WP_UnitTestCase {

	private Cron $cron;

	public function set_up(): void {
		parent::set_up();
		$this->cron = new Cron( new HitTracker( new Repository() ), new NotFoundRepository() );
		$this->cron->register();
	}

	public function tear_down(): void {
		Cron::unschedule();
		parent::tear_down();
	}

	public function test_registers_five_minute_schedule(): void {
		$schedules = wp_get_schedules();
		$this->assertSame( 300, $schedules[ Cron::INTERVAL ]['interval'] );
	}

	public function test_ensure_scheduled_and_unschedule(): void {
		Cron::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );
		$this->assertSame( Cron::INTERVAL, wp_get_schedule( Cron::HIT_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Cron::PRUNE_HOOK ) );

		Cron::unschedule();
		$this->assertFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );
	}

	public function test_hooks_are_wired(): void {
		$this->assertSame( 10, has_action( Cron::PRUNE_HOOK, [ $this->cron, 'run_prune' ] ) );
		$this->assertNotFalse( has_action( Cron::HIT_HOOK ) );
	}

	public function test_run_prune_uses_settings(): void {
		( new NotFoundRepository() )->log( '/ancient', '', '2000-01-01 00:00:00' );
		$this->assertSame( 1, $this->cron->run_prune() );
	}
}
