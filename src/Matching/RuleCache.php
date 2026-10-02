<?php
/**
 * Object-cached compiled rule set.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

use Advision\Redirects\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

final class RuleCache {

	public const GROUP = 'adv_redirects';

	public const KEY = 'ruleset';

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function get(): array {
		$found  = false;
		$cached = wp_cache_get( self::KEY, self::GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows      = $this->repository->enabled_rows();
		$read_fail = '' !== $wpdb->last_error;

		$ruleset = RulesetCompiler::compile( $rows );

		/**
		 * Filters the compiled rule set before it is cached.
		 *
		 * @param array $ruleset { exact: array, has_query: bool, regex: array }.
		 */
		$ruleset = apply_filters( 'adv_redirects_compiled_ruleset', $ruleset );
		if ( ! is_array( $ruleset ) ) {
			$ruleset = RulesetCompiler::empty_ruleset();
		}

		// A failed read must not be cached as "no redirects"; retry on the next request.
		if ( ! $read_fail ) {
			wp_cache_set( self::KEY, $ruleset, self::GROUP );
		}
		return $ruleset;
	}

	public static function flush(): void {
		wp_cache_delete( self::KEY, self::GROUP );

		/**
		 * Fires after the compiled rule set cache is cleared.
		 */
		do_action( 'adv_redirects_cache_flushed' );
	}
}
