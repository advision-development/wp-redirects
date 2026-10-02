<?php
/**
 * The only code that writes the redirects table. Every write flushes the
 * rule cache and fires the matching action.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Schema;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class Repository {

	private const UPDATABLE = [
		'type'        => '%s',
		'source'      => '%s',
		'target'      => '%s',
		'status_code' => '%d',
		'enabled'     => '%d',
		'origin'      => '%s',
		'note'        => '%s',
	];

	/**
	 * @return Rule[]
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY type ASC, position ASC, id ASC', Schema::redirects_table() ),
			ARRAY_A
		);
		return array_map( [ Rule::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	public function find( int $id ): ?Rule {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::redirects_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? Rule::from_row( $row ) : null;
	}

	public function enabled_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, type, source, target, status_code, position FROM %i WHERE enabled = 1 ORDER BY position ASC, id ASC',
				Schema::redirects_table()
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return int[]
	 */
	public function ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Schema::redirects_table() ) ) );
	}

	public function exact_rule_by_key( string $key, int $exclude_id = 0 ): ?Rule {
		foreach ( $this->all() as $rule ) {
			if ( 'exact' === $rule->type && $rule->id !== $exclude_id && PathNormalizer::source_key( $rule->source ) === $key ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Inserts already-validated data.
	 */
	public function insert( array $data ): ?Rule {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$type = 'regex' === ( $data['type'] ?? '' ) ? 'regex' : 'exact';

		$ok = $wpdb->insert(
			Schema::redirects_table(),
			[
				'type'        => $type,
				'source'      => (string) $data['source'],
				'target'      => isset( $data['target'] ) && '' !== $data['target'] ? (string) $data['target'] : null,
				'status_code' => (int) $data['status_code'],
				'position'    => 'regex' === $type ? $this->next_position() : 0,
				'enabled'     => array_key_exists( 'enabled', $data ) ? ( $data['enabled'] ? 1 : 0 ) : 1,
				'origin'      => 'auto' === ( $data['origin'] ?? '' ) ? 'auto' : 'manual',
				'note'        => (string) ( $data['note'] ?? '' ),
				'hits'        => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
		if ( false === $ok ) {
			return null;
		}

		$rule = $this->find( (int) $wpdb->insert_id );
		RuleCache::flush();
		if ( null !== $rule ) {
			/**
			 * Fires after a redirect is created.
			 *
			 * @param Rule $rule The new rule.
			 */
			do_action( 'adv_redirects_rule_created', $rule );
		}
		return $rule;
	}

	/**
	 * Updates already-validated fields. Unknown keys are ignored.
	 */
	public function update( int $id, array $data ): ?Rule {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return null;
		}

		$row     = [];
		$formats = [];
		foreach ( self::UPDATABLE as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$value = $data[ $column ];
			if ( 'target' === $column ) {
				$value = null === $value || '' === $value ? null : (string) $value;
			} elseif ( 'enabled' === $column ) {
				$value = $value ? 1 : 0;
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} else {
				$value = (string) $value;
			}
			$row[ $column ] = $value;
			$formats[]      = $format;
		}

		if ( isset( $row['type'] ) && 'regex' === $row['type'] && 'regex' !== $old->type ) {
			$row['position'] = $this->next_position();
			$formats[]       = '%d';
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = '%s';

		if ( false === $wpdb->update( Schema::redirects_table(), $row, [ 'id' => $id ], $formats, [ '%d' ] ) ) {
			return null;
		}

		$rule = $this->find( $id );
		RuleCache::flush();
		if ( null === $rule ) {
			return null;
		}
		/**
		 * Fires after a redirect is updated.
		 *
		 * @param Rule $rule The updated rule.
		 * @param Rule $old  The rule before the update.
		 */
		do_action( 'adv_redirects_rule_updated', $rule, $old );
		return $rule;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}
		if ( ! $wpdb->delete( Schema::redirects_table(), [ 'id' => $id ], [ '%d' ] ) ) {
			return false;
		}

		RuleCache::flush();
		/**
		 * Fires after a redirect is deleted.
		 *
		 * @param Rule $old The deleted rule.
		 */
		do_action( 'adv_redirects_rule_deleted', $old );
		return true;
	}

	/**
	 * Sets regex evaluation order. Regex rules not listed keep their relative order after the listed ones.
	 *
	 * @param int[] $ids Regex rule IDs in the desired order.
	 */
	public function reorder( array $ids ): void {
		global $wpdb;

		$regex_ids = [];
		foreach ( $this->all() as $rule ) {
			if ( 'regex' === $rule->type ) {
				$regex_ids[] = $rule->id;
			}
		}

		$listed = array_values( array_intersect( array_unique( array_map( 'intval', $ids ) ), $regex_ids ) );
		$order  = array_merge( $listed, array_values( array_diff( $regex_ids, $listed ) ) );

		$position = 1;
		foreach ( $order as $id ) {
			$wpdb->update( Schema::redirects_table(), [ 'position' => $position++ ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
		}
		RuleCache::flush();
	}

	/**
	 * Points every rule that targets $from_path at $to_path. Rules that would
	 * become self-redirects are deleted.
	 */
	public function retarget( string $from_path, string $to_path ): int {
		$from_key = PathNormalizer::source_key( $from_path );
		$to_key   = PathNormalizer::source_key( $to_path );
		$count    = 0;

		foreach ( $this->all() as $rule ) {
			if ( null === $rule->target ) {
				continue;
			}
			$internal = Site::internal_path( $rule->target );
			if ( null === $internal || PathNormalizer::source_key( $internal ) !== $from_key ) {
				continue;
			}
			if ( 'exact' === $rule->type && PathNormalizer::source_key( $rule->source ) === $to_key ) {
				$this->delete( $rule->id );
			} else {
				$this->update( $rule->id, [ 'target' => $to_path ] );
			}
			++$count;
		}
		return $count;
	}

	public function disable_by_source_key( string $key ): int {
		$count = 0;
		foreach ( $this->all() as $rule ) {
			if ( 'exact' === $rule->type && $rule->enabled && PathNormalizer::source_key( $rule->source ) === $key ) {
				$this->update( $rule->id, [ 'enabled' => false ] );
				++$count;
			}
		}
		return $count;
	}

	public function add_hits( int $id, int $count, string $last_hit_gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET hits = hits + %d, last_hit_at = %s WHERE id = %d',
				Schema::redirects_table(),
				$count,
				$last_hit_gmt,
				$id
			)
		);
	}

	private function next_position(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(position), 0) FROM %i WHERE type = %s', Schema::redirects_table(), 'regex' )
		) + 1;
	}
}
