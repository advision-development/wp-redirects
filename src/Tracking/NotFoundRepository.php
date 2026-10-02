<?php
/**
 * Storage for the 404 log.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Schema;

defined( 'ABSPATH' ) || exit;

final class NotFoundRepository {

	private const ORDERBY = [ 'hits', 'last_seen', 'path' ];

	public function log( string $path, string $referrer, string $now_gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (path, path_hash, hits, first_seen, last_seen, last_referrer) VALUES (%s, %s, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s, last_referrer = %s',
				Schema::not_found_table(),
				$path,
				md5( $path ),
				$now_gmt,
				$now_gmt,
				$referrer,
				$now_gmt,
				$referrer
			)
		);
	}

	/**
	 * @return array{items:array<int,array>,total:int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$orderby  = in_array( $args['orderby'] ?? '', self::ORDERBY, true ) ? $args['orderby'] : 'hits';
		$asc      = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) );
		$like     = '%' . $wpdb->esc_like( trim( (string) ( $args['search'] ?? '' ) ) ) . '%';
		$table    = Schema::not_found_table();

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE path LIKE %s', $table, $like ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Both branches are $wpdb->prepare() calls with literal SQL.
		$rows = $wpdb->get_results(
			$asc
				? $wpdb->prepare(
					'SELECT id, path, hits, first_seen, last_seen, last_referrer FROM %i WHERE path LIKE %s ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d',
					$table,
					$like,
					$orderby,
					$per_page,
					( $page - 1 ) * $per_page
				)
				: $wpdb->prepare(
					'SELECT id, path, hits, first_seen, last_seen, last_referrer FROM %i WHERE path LIKE %s ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$like,
					$orderby,
					$per_page,
					( $page - 1 ) * $per_page
				),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$items = array_map(
			static function ( array $row ): array {
				$row['id']   = (int) $row['id'];
				$row['hits'] = (int) $row['hits'];
				return $row;
			},
			is_array( $rows ) ? $rows : []
		);

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::not_found_table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * @param int[] $ids IDs to delete.
	 */
	public function delete_many( array $ids ): int {
		$count = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $this->delete( $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function clear(): int {
		global $wpdb;
		// DELETE rather than TRUNCATE: TRUNCATE implicitly commits open transactions.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::not_found_table() ) );
	}

	/**
	 * Deletes 404 rows that an exact redirect source now covers.
	 */
	public function delete_matching_source( string $source ): int {
		global $wpdb;

		$key    = PathNormalizer::source_key( $source );
		$prefix = rtrim( explode( '?', $key, 2 )[0], '/' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, path FROM %i WHERE path LIKE %s LIMIT 500',
				Schema::not_found_table(),
				$wpdb->esc_like( '' === $prefix ? '/' : $prefix ) . '%'
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( PathNormalizer::source_key( (string) $row['path'] ) === $key && $this->delete( (int) $row['id'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function prune( int $days, int $max_rows ): int {
		global $wpdb;
		$table  = Schema::not_found_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $cutoff ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $total > $max_rows ) {
			$deleted += (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i ORDER BY last_seen ASC, id ASC LIMIT %d', $table, $total - $max_rows )
			);
		}
		return $deleted;
	}
}
