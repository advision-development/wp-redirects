<?php
/**
 * Hit counting. With a persistent object cache, hits are buffered with an
 * atomic increment and written by cron; otherwise they are written directly.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

final class HitTracker {

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function record( int $rule_id ): void {
		/**
		 * Filters whether redirect hits are counted.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'adv_redirects_hit_tracking_enabled', true ) ) {
			return;
		}

		$now = current_time( 'mysql', true );
		if ( ! wp_using_ext_object_cache() ) {
			$this->repository->add_hits( $rule_id, 1, $now );
			return;
		}

		wp_cache_add( 'hit:' . $rule_id, 0, RuleCache::GROUP );
		wp_cache_incr( 'hit:' . $rule_id, 1, RuleCache::GROUP );
		wp_cache_set( 'last:' . $rule_id, $now, RuleCache::GROUP );
	}

	public function flush(): int {
		if ( ! wp_using_ext_object_cache() ) {
			return 0;
		}

		$total = 0;
		foreach ( $this->repository->ids() as $id ) {
			$count = (int) wp_cache_get( 'hit:' . $id, RuleCache::GROUP );
			if ( $count <= 0 ) {
				continue;
			}
			$last = wp_cache_get( 'last:' . $id, RuleCache::GROUP );
			$this->repository->add_hits( $id, $count, is_string( $last ) ? $last : current_time( 'mysql', true ) );
			// Decrement rather than delete so hits recorded during the flush survive.
			wp_cache_decr( 'hit:' . $id, $count, RuleCache::GROUP );
			$total += $count;
		}
		return $total;
	}
}
