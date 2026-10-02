<?php
/**
 * Public PHP API.
 *
 * @package Advision\Redirects
 */

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'adv_redirects_add' ) ) {
	/**
	 * Creates a redirect using the same validation as the admin and REST API.
	 *
	 * @param array $data { type: 'exact'|'regex', source: string, target: ?string, status_code: int, enabled?: bool, note?: string }.
	 * @return array|WP_Error The rule as an array, or the validation error.
	 */
	function adv_redirects_add( array $data ) {
		$plugin = Plugin::instance();
		$result = $plugin->validator()->validate( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rule = $plugin->repository()->insert( $result['data'] );
		if ( null === $rule ) {
			return new WP_Error( 'adv_redirects_db_error', __( 'The redirect could not be saved.', 'wp-redirects' ) );
		}
		return $rule->to_array();
	}
}

if ( ! function_exists( 'adv_redirects_delete' ) ) {
	/**
	 * Deletes a redirect.
	 *
	 * @param int $id Redirect ID.
	 */
	function adv_redirects_delete( int $id ): bool {
		return Plugin::instance()->repository()->delete( $id );
	}
}

if ( ! function_exists( 'adv_redirects_flush_cache' ) ) {
	/**
	 * Clears the compiled rule set cache.
	 */
	function adv_redirects_flush_cache(): void {
		RuleCache::flush();
	}
}
