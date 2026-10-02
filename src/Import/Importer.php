<?php
/**
 * Plans, previews and applies a Redirection import. Every rule goes through the
 * Validator, and every write goes through the Repository.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RulesetCompiler;
use Advision\Redirects\Matching\TargetResolver;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

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
		$this->repository->begin_read_cache();
		try {
			return $this->run_preview( $redirects, $groups );
		} finally {
			$this->repository->end_read_cache();
		}
	}

	private function run_preview( array $redirects, array $groups ): array {
		$entries    = [];
		$pending    = [];
		$candidates = [];
		$next_id    = -1;
		$lookup     = $this->existing_lookup();

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

			$existing = $this->existing_for( $item['rule'], $lookup );
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

			$entry['rule']     = $result['data'] + [ 'origin' => self::origin( $item['rule'] ) ];
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

			// An overwrite always replaces the old row in the walk (a disabled one drops out of
			// it). A new rule that is disabled can never matter, so it is left out.
			if ( null !== $existing || $result['data']['enabled'] ) {
				$row       = [
					'id'          => null !== $existing ? $existing->id : $next_id--,
					'type'        => $result['data']['type'],
					'source'      => $result['data']['source'],
					'target'      => $result['data']['target'],
					'status_code' => $result['data']['status_code'],
					'enabled'     => $result['data']['enabled'] ? 1 : 0,
					'position'    => null !== $existing && 'regex' === $existing->type ? $existing->position : 1000000 + $index,
				];
				$pending[] = $row;

				// Only an enabled rule with a fixed target can start a chain (the Validator's own skip rule).
				if ( $result['data']['enabled'] && null !== $result['data']['target'] && ! ( 'regex' === $result['data']['type'] && preg_match( '/\$[1-9]/', (string) $result['data']['target'] ) ) ) {
					$candidates[] = [
						'entry'  => count( $entries ),
						'type'   => $result['data']['type'],
						'source' => $result['data']['source'],
						'target' => (string) $result['data']['target'],
					];
				}
			}
			$entries[] = $entry;
		}

		$this->add_forward_chain_warnings( $entries, $candidates, $pending );

		return [
			'entries' => $entries,
			'counts'  => self::preview_counts( $entries ),
		];
	}

	/**
	 * Second pass of the preview: a rule only sees earlier rows in the first pass, so a chain
	 * whose next hop is defined later in the file is found here. The walk runs against one rule
	 * set built the way the import will leave the table (stored enabled rows, replaced by id,
	 * plus every accepted row), compiled once. Only ever adds or replaces a chain warning.
	 *
	 * @param array $entries    Preview entries, updated in place.
	 * @param array $candidates Accepted enabled rules with a fixed target: entry index, type, source, target.
	 * @param array $pending    Accepted rows shaped like Repository::enabled_rows().
	 */
	private function add_forward_chain_warnings( array &$entries, array $candidates, array $pending ): void {
		if ( empty( $candidates ) ) {
			return;
		}

		$replaced = [];
		foreach ( $pending as $row ) {
			$replaced[ (int) $row['id'] ] = true;
		}
		$rows = [];
		foreach ( $this->repository->enabled_rows() as $row ) {
			if ( ! isset( $replaced[ (int) $row['id'] ] ) ) {
				$rows[] = $row;
			}
		}
		$ruleset = RulesetCompiler::compile( array_merge( $rows, $pending ) );

		$chains  = new ChainResolver();
		$forward = (bool) Settings::get( 'forward_query_string' );

		foreach ( $candidates as $candidate ) {
			$start = $candidate['target'];
			if ( $forward && 'exact' === $candidate['type'] && false !== strpos( $candidate['source'], '?' ) ) {
				// The runtime appends the request's query to the target, so walk from the merged URL.
				$start = TargetResolver::merge_query( $start, explode( '?', $candidate['source'], 2 )[1] );
			}
			$chain = $chains->resolve(
				$candidate['source'],
				$start,
				$ruleset,
				'exact' === $candidate['type'] ? PathNormalizer::source_key( $candidate['source'] ) : null,
				$forward
			);
			if ( ! $chain['loop'] && count( $chain['hops'] ) > 2 ) {
				$entries[ $candidate['entry'] ]['warnings'] = [
					[
						'code'  => 'chain',
						'hops'  => $chain['hops'],
						'final' => $chain['final'],
					],
				];
			}
		}
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

			$data = $result['data'] + [ 'origin' => self::origin( $item['rule'] ) ];
			// An overwrite keeps the rule's original creator and method; only a new rule is marked as imported.
			$rule = null !== $existing
				? $this->repository->update( $existing->id, $data )
				: $this->repository->insert( $data + [ 'created_via' => 'import' ] );
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
				 * Filters a mapped import rule. Return false (or any non-array) to skip it.
				 *
				 * @param array|false $rule  { type, source, target, status_code, enabled, note, origin }.
				 * @param array       $entry The raw Redirection export entry.
				 */
				$filtered = apply_filters( 'adv_redirects_import_rule', $mapped['rule'], is_array( $raw ) ? $raw : [] );
				if ( ! is_array( $filtered ) ) {
					$item['rule']  = null;
					$item['error'] = 'filtered';
				} else {
					$merged = array_merge( $mapped['rule'], array_intersect_key( $filtered, $mapped['rule'] ) );
					if ( self::is_usable_rule( $merged ) ) {
						$item['rule'] = $merged;
					} else {
						$item['rule']  = null;
						$item['error'] = 'invalid_entry';
					}
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

	/**
	 * Indexes the stored rules once (exact key => Rule, regex source => Rule), first match wins.
	 *
	 * @return array{exact:array<string,Rule>,regex:array<string,Rule>}
	 */
	private function existing_lookup(): array {
		$lookup = [
			'exact' => [],
			'regex' => [],
		];
		foreach ( $this->repository->all() as $rule ) {
			if ( 'exact' === $rule->type ) {
				$key = PathNormalizer::source_key( $rule->source );
				if ( ! isset( $lookup['exact'][ $key ] ) ) {
					$lookup['exact'][ $key ] = $rule;
				}
			} elseif ( ! isset( $lookup['regex'][ $rule->source ] ) ) {
				$lookup['regex'][ $rule->source ] = $rule;
			}
		}
		return $lookup;
	}

	/**
	 * @param array|null $lookup From existing_lookup(); null queries the repository.
	 */
	private function existing_for( array $rule, ?array $lookup = null ): ?Rule {
		if ( 'regex' === $rule['type'] ) {
			$source = (string) $rule['source'];
			return null !== $lookup ? ( $lookup['regex'][ $source ] ?? null ) : $this->repository->regex_rule_by_source( $source );
		}
		$key = PathNormalizer::source_key( self::exact_path( (string) $rule['source'] ) );
		return null !== $lookup ? ( $lookup['exact'][ $key ] ?? null ) : $this->repository->exact_rule_by_key( $key );
	}

	/**
	 * The exact source as the Validator will store it: an own-host absolute URL becomes its path.
	 */
	private static function exact_path( string $source ): string {
		$source = trim( $source );
		if ( preg_match( '#^https?://#i', $source ) ) {
			$path = Site::internal_path( $source );
			if ( null !== $path ) {
				return $path;
			}
		}
		return $source;
	}

	private static function conflict_key( array $rule ): string {
		return 'regex' === $rule['type']
			? 'r:' . $rule['source']
			: 'e:' . PathNormalizer::source_key( self::exact_path( (string) $rule['source'] ) );
	}

	private static function origin( array $rule ): string {
		return 'auto' === ( $rule['origin'] ?? '' ) ? 'auto' : 'manual';
	}

	/**
	 * Guards against filter results that would make the Validator cast arrays to strings.
	 */
	private static function is_usable_rule( array $rule ): bool {
		if ( ! is_string( $rule['source'] ) || '' === $rule['source'] ) {
			return false;
		}
		if ( null !== $rule['target'] && ! is_string( $rule['target'] ) ) {
			return false;
		}
		foreach ( [ 'type', 'status_code', 'enabled', 'note', 'origin' ] as $field ) {
			if ( null !== $rule[ $field ] && ! is_scalar( $rule[ $field ] ) ) {
				return false;
			}
		}
		return true;
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
