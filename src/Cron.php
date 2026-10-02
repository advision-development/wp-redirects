<?php
/**
 * Scheduled jobs: hit flush (5 min) and 404 pruning (daily).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundRepository;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const HIT_HOOK = 'adv_redirects_flush_hits';

	public const PRUNE_HOOK = 'adv_redirects_prune_404s';

	public const INTERVAL = 'adv_redirects_five_minutes';

	private HitTracker $hits;

	private NotFoundRepository $not_found;

	public function __construct( HitTracker $hits, NotFoundRepository $not_found ) {
		$this->hits      = $hits;
		$this->not_found = $not_found;
	}

	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Hit buffers are flushed every 5 minutes by design; the job is a few cache reads.
		add_filter( 'cron_schedules', [ $this, 'schedules' ] );
		add_action( self::HIT_HOOK, [ $this->hits, 'flush' ] );
		add_action( self::PRUNE_HOOK, [ $this, 'run_prune' ] );
		add_action( 'admin_init', [ self::class, 'ensure_scheduled' ] );
	}

	public function schedules( array $schedules ): array {
		$schedules[ self::INTERVAL ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'wp-redirects' ),
		];
		return $schedules;
	}

	public function run_prune(): int {
		return $this->not_found->prune( (int) Settings::get( 'log_404_retention_days' ), (int) Settings::get( 'log_404_max_rows' ) );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HIT_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::HIT_HOOK );
		}
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HIT_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
