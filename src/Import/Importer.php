<?php
/**
 * Plans, previews and applies a Redirection import. Every rule goes through the
 * Validator, and every write goes through the Repository.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Redirects\Validator;

defined( 'ABSPATH' ) || exit;

final class Importer {

	private const RULE_FIELDS = [ 'type', 'source', 'target', 'status_code', 'enabled', 'note' ];

	private Repository $repository;

	private Validator $validator;

	public function __construct( Repository $repository, Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	/**
	 * Dry run: maps and validates every entry, writes nothing.
	 *
	 * @param array $redirects Raw export entries, in import order.
	 * @param array $groups    Raw export groups.
	 */
	public function preview( array $redirects, array $groups ): array {
		$entries = [];
		$pending = [];
		$next_id = -1;

		foreach ( $this->plan( $redirects, $groups ) as $index => $item ) {
			$entry = [
				'index'     => $index,
				'source_id' => $item['source_id'],
				'source'    => $item['source'],
				'status'    => 'new',
				'warnings'  => [],
				'notes'     => $item['notes'],
				'rule'      => $item['rule'],
				'error'     => null,
			];

			if ( null !== $item['error'] ) {
				$entry['status'] = 'skipped';
				$entry['error']  = self::reason( $item['error'] );
				$entries[]       = $entry;
				continue;
			}
			if ( $item['superseded'] ) {
				$entry['status'] = 'superseded';
				$entries[]       = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'] );
			$result   = $this->validator->validate( self::validator_input( $item['rule'] ), null !== $existing ? $existing->id : null, $pending );
			if ( is_wp_error( $result ) ) {
				$entry['status'] = 'skipped';
				$entry['error']  = [
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
				$entries[]       = $entry;
				continue;
			}

			$entry['rule']     = $result['data'] + [ 'origin' => $item['rule']['origin'] ];
			$entry['warnings'] = $result['warnings'];
			if ( null !== $existing ) {
				$entry['status']      = 'overwrite';
				$entry['existing_id'] = $existing->id;
				$entry['current']     = [
					'source'      => $existing->source,
					'target'      => $existing->target,
					'status_code' => $existing->status_code,
					'enabled'     => $existing->enabled,
				];
			}

			if ( $result['data']['enabled'] ) {
				$pending[] = [
					'id'          => null !== $existing ? $existing->id : $next_id--,
					'type'        => $result['data']['type'],
					'source'      => $result['data']['source'],
					'target'      => $result['data']['target'],
					'status_code' => $result['data']['status_code'],
					'position'    => null !== $existing && 'regex' === $existing->type ? $existing->position : 1000000 + $index,
				];
			}
			$entries[] = $entry;
		}

		return [
			'entries' => $entries,
			'counts'  => self::preview_counts( $entries ),
		];
	}

	/**
	 * Applies one batch. The caller sends batches in file order.
	 *
	 * @param array $redirects Raw export entries for this batch.
	 * @param array $groups    Raw export groups.
	 */
	public function import( array $redirects, array $groups ): array {
		$entries = [];

		foreach ( $this->plan( $redirects, $groups ) as $index => $item ) {
			$entry = [
				'index'     => $index,
				'source_id' => $item['source_id'],
				'result'    => 'skipped',
			];

			if ( null !== $item['error'] ) {
				$entry['error'] = self::reason( $item['error'] );
				$entries[]      = $entry;
				continue;
			}
			if ( $item['superseded'] ) {
				$entry['error'] = [
					'code'    => 'superseded',
					'message' => __( 'A later entry in the file uses the same source.', 'wp-redirects' ),
				];
				$entries[]      = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'] );
			$result   = $this->validator->validate( self::validator_input( $item['rule'] ), null !== $existing ? $existing->id : null );
			if ( is_wp_error( $result ) ) {
				$entry['error'] = [
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
				$entries[]      = $entry;
				continue;
			}

			$data = $result['data'] + [ 'origin' => $item['rule']['origin'] ];
			$rule = null !== $existing ? $this->repository->update( $existing->id, $data ) : $this->repository->insert( $data );
			if ( null === $rule ) {
				$entry['error'] = [
					'code'    => 'adv_redirects_db_error',
					'message' => __( 'The redirect could not be saved.', 'wp-redirects' ),
				];
				$entries[]      = $entry;
				continue;
			}

			$entry['result']  = null !== $existing ? 'updated' : 'created';
			$entry['rule_id'] = $rule->id;
			$entries[]        = $entry;
		}

		$counts = [
			'total'   => count( $entries ),
			'created' => count( wp_list_filter( $entries, [ 'result' => 'created' ] ) ),
			'updated' => count( wp_list_filter( $entries, [ 'result' => 'updated' ] ) ),
			'skipped' => count( wp_list_filter( $entries, [ 'result' => 'skipped' ] ) ),
		];

		/**
		 * Fires after an import batch has been applied.
		 *
		 * @param array $counts { total, created, updated, skipped }.
		 */
		do_action( 'adv_redirects_import_completed', $counts );

		return [
			'entries' => $entries,
			'counts'  => $counts,
		];
	}

	/**
	 * Maps entries, applies the filter and marks in-file duplicates (the later entry wins).
	 *
	 * @return array<int,array{source_id:int,source:string,rule:?array,notes:array,error:?string,superseded:bool}>
	 */
	private function plan( array $redirects, array $groups ): array {
		$names   = RedirectionMapper::group_names( $groups );
		$planned = [];
		$last    = [];

		foreach ( array_values( $redirects ) as $index => $raw ) {
			$mapped = RedirectionMapper::map( $raw, $names );
			$item   = [
				'source_id'  => $mapped['source_id'],
				'source'     => is_array( $raw ) && isset( $raw['url'] ) && is_string( $raw['url'] ) ? $raw['url'] : '',
				'rule'       => $mapped['rule'],
				'notes'      => $mapped['notes'],
				'error'      => $mapped['error'],
				'superseded' => false,
			];

			if ( $mapped['ok'] ) {
				/**
				 * Filters a mapped import rule. Return false to skip it.
				 *
				 * @param array|false $rule  { type, source, target, status_code, enabled, note, origin }.
				 * @param array       $entry The raw Redirection export entry.
				 */
				$filtered = apply_filters( 'adv_redirects_import_rule', $mapped['rule'], is_array( $raw ) ? $raw : [] );
				if ( false === $filtered ) {
					$item['rule']  = null;
					$item['error'] = 'filtered';
				} elseif ( is_array( $filtered ) ) {
					$item['rule'] = array_merge( $mapped['rule'], array_intersect_key( $filtered, $mapped['rule'] ) );
				}
			}

			if ( null !== $item['rule'] ) {
				$last[ self::conflict_key( $item['rule'] ) ] = $index;
			}
			$planned[ $index ] = $item;
		}

		foreach ( $planned as $index => $item ) {
			if ( null !== $item['rule'] && $last[ self::conflict_key( $item['rule'] ) ] !== $index ) {
				$planned[ $index ]['superseded'] = true;
			}
		}

		return $planned;
	}

	private function existing_for( array $rule ): ?Rule {
		if ( 'regex' === $rule['type'] ) {
			return $this->repository->regex_rule_by_source( (string) $rule['source'] );
		}
		return $this->repository->exact_rule_by_key( PathNormalizer::source_key( trim( (string) $rule['source'] ) ) );
	}

	private static function conflict_key( array $rule ): string {
		return 'regex' === $rule['type']
			? 'r:' . $rule['source']
			: 'e:' . PathNormalizer::source_key( trim( (string) $rule['source'] ) );
	}

	private static function validator_input( array $rule ): array {
		return array_intersect_key( $rule, array_flip( self::RULE_FIELDS ) );
	}

	/**
	 * @return array{code:string,message:string}
	 */
	private static function reason( string $code ): array {
		$messages = [
			'invalid_entry'          => __( 'This entry is missing required fields or has the wrong types.', 'wp-redirects' ),
			'unsupported_match_type' => __( 'Conditional redirects (login, referrer, user agent, cookie, IP and similar) are not supported.', 'wp-redirects' ),
			'unsupported_action'     => __( 'Only redirects and 410 Gone responses can be imported.', 'wp-redirects' ),
			'unsupported_status'     => __( 'This status code is not supported.', 'wp-redirects' ),
			'filtered'               => __( 'Skipped by the adv_redirects_import_rule filter.', 'wp-redirects' ),
		];
		return [
			'code'    => $code,
			'message' => $messages[ $code ] ?? $code,
		];
	}

	private static function preview_counts( array $entries ): array {
		$counts = [
			'total'      => count( $entries ),
			'new'        => 0,
			'overwrite'  => 0,
			'superseded' => 0,
			'skipped'    => 0,
			'warnings'   => 0,
		];
		foreach ( $entries as $entry ) {
			++$counts[ $entry['status'] ];
			if ( ! empty( $entry['warnings'] ) ) {
				++$counts['warnings'];
			}
		}
		return $counts;
	}
}
