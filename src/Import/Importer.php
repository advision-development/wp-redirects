<?php
/**
 * Plans, previews and applies an import from Redirection or Yoast SEO Premium. Every rule goes
 * through the Validator, and every write goes through the Repository.
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

	public const SOURCE_REDIRECTION = 'redirection';

	public const SOURCE_YOAST = 'yoast';

	private const RULE_FIELDS = [ 'type', 'source', 'target', 'status_code', 'enabled', 'trailing_slash', 'note' ];

	private Repository $repository;

	private Validator $validator;

	public function __construct( Repository $repository, Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	/**
	 * Dry run: maps and validates every entry, writes nothing.
	 *
	 * @param array  $redirects Raw entries, in import order.
	 * @param array  $groups    Raw Redirection groups (ignored for Yoast).
	 * @param string $source    self::SOURCE_REDIRECTION or self::SOURCE_YOAST.
	 */
	public function preview( array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION ): array {
		$this->repository->begin_read_cache();
		try {
			return $this->run_preview( $redirects, $groups, $source );
		} finally {
			$this->repository->end_read_cache();
		}
	}

	private function run_preview( array $redirects, array $groups, string $source ): array {
		$entries    = [];
		$pending    = [];
		$candidates = [];
		$next_id    = -1;
		$lookup     = $this->existing_lookup();

		foreach ( $this->plan( $redirects, $groups, $source ) as $index => $item ) {
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
				$entry['status']        = 'superseded';
				$entry['superseded_by'] = $item['superseded_by'];
				$entry['error']         = self::superseded_reason( (int) $item['superseded_by'], $source );
				$entries[]              = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'], $lookup );
			$result   = $this->validator->validate( self::validator_input( $item['rule'], $existing ), null !== $existing ? $existing->id : null, $pending );
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
					'note'        => $existing->note,
				];
			}

			// An overwrite always replaces the old row in the walk (a disabled one drops out of
			// it). A new rule that is disabled can never matter, so it is left out.
			if ( null !== $existing || $result['data']['enabled'] ) {
				$row       = [
					'id'             => null !== $existing ? $existing->id : $next_id--,
					'type'           => $result['data']['type'],
					'source'         => $result['data']['source'],
					'target'         => $result['data']['target'],
					'status_code'    => $result['data']['status_code'],
					'enabled'        => $result['data']['enabled'] ? 1 : 0,
					'trailing_slash' => $result['data']['trailing_slash'] ? 1 : 0,
					'position'       => null !== $existing && 'regex' === $existing->type ? $existing->position : 1000000 + $index,
				];
				$pending[] = $row;

				// Only an enabled rule with a fixed target can start a chain (the Validator's own skip rule).
				if ( $result['data']['enabled'] && null !== $result['data']['target'] && ! ( 'regex' === $result['data']['type'] && preg_match( '/\$[1-9]/', (string) $result['data']['target'] ) ) ) {
					$candidates[] = [
						'entry'  => count( $entries ),
						'type'   => $result['data']['type'],
						'source' => $result['data']['source'],
						'target' => TargetResolver::trailing_slash( (string) $result['data']['target'], (string) $result['data']['target'], $result['data']['trailing_slash'] ),
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
	 * @param array  $redirects Raw entries for this batch.
	 * @param array  $groups    Raw Redirection groups (ignored for Yoast).
	 * @param string $source    self::SOURCE_REDIRECTION or self::SOURCE_YOAST.
	 */
	public function import( array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION ): array {
		$entries = [];

		foreach ( $this->plan( $redirects, $groups, $source ) as $index => $item ) {
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
				$entry['error']         = self::superseded_reason( (int) $item['superseded_by'], $source );
				$entry['superseded_by'] = $item['superseded_by'];
				$entries[]              = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'] );
			$result   = $this->validator->validate( self::validator_input( $item['rule'], $existing ), null !== $existing ? $existing->id : null );
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
	 * Whether WP Redirects holds a rule with the same conflict key as each mapped rule, the same
	 * match the import uses to decide new or overwrite. Used before removing redirects from Yoast.
	 *
	 * @param array<int|string,array> $rules Mapped rules (`type`, `source`).
	 * @return array<int|string,bool> Same keys as $rules.
	 */
	public function covered( array $rules ): array {
		$lookup = $this->existing_lookup();
		$out    = [];
		foreach ( $rules as $key => $rule ) {
			$out[ $key ] = null !== $this->existing_for( $rule, $lookup );
		}
		return $out;
	}

	/**
	 * Maps entries (through RedirectionMapper or YoastMapper), applies the filter and marks in-file
	 * duplicates, the same way for both sources.
	 *
	 * Entries arrive in the order the source served them: Redirection's position order (the client
	 * sorts by position) or Yoast's stored order. Among entries with the same conflict key, the winner
	 * is the first one that maps and passes the filter and is enabled; if none is enabled, the first
	 * one that maps. Every other entry with that key is superseded by the winner.
	 *
	 * - Redirection serves the first enabled match, so that is the entry its site actually used.
	 * - Every Yoast rule is enabled, so the first mapped entry wins. Yoast keeps one entry per origin,
	 *   so its duplicates are origins that differ only in case or percent-encoding, which WP Redirects
	 *   treats as one source.
	 *
	 * The winner is chosen before validation. If it later fails validation it is reported as
	 * skipped and no superseded copy is imported in its place: the source never served those copies
	 * for that key, so importing one would add a redirect the site never had.
	 *
	 * @return array<int,array{source_id:int,source:string,rule:?array,notes:array,error:?string,superseded:bool,superseded_by:?int}>
	 */
	private function plan( array $redirects, array $groups, string $source ): array {
		$yoast    = self::SOURCE_YOAST === $source;
		$info     = $yoast ? [] : RedirectionMapper::group_info( $groups );
		$trailing = $yoast && Site::trailing_slash_permalinks();
		$planned  = [];

		foreach ( array_values( $redirects ) as $index => $raw ) {
			$mapped = $yoast ? YoastMapper::map( $raw, $trailing ) : RedirectionMapper::map( $raw, $info );
			$key    = $yoast ? 'origin' : 'url';
			$item   = [
				'source_id'     => $mapped['source_id'],
				'source'        => is_array( $raw ) && isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '',
				'rule'          => $mapped['rule'],
				'notes'         => $mapped['notes'],
				'error'         => $mapped['error'],
				'superseded'    => false,
				'superseded_by' => null,
			];

			if ( $mapped['ok'] ) {
				/**
				 * Filters a mapped import rule. Return false (or any non-array) to skip it.
				 *
				 * @param array|false $rule   { type, source, target, status_code, enabled, trailing_slash, note, origin }.
				 * @param array       $entry  The raw entry (Redirection export entry or Yoast base-option entry).
				 * @param string      $source 'redirection' or 'yoast'.
				 */
				$filtered = apply_filters( 'adv_redirects_import_rule', $mapped['rule'], is_array( $raw ) ? $raw : [], $source );
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

			$planned[ $index ] = $item;
		}

		// Decide each key's winner after the filter, so a skipped or unmappable entry never wins.
		$winners = [];
		foreach ( $planned as $index => $item ) {
			if ( null === $item['rule'] ) {
				continue;
			}
			$key = self::conflict_key( $item['rule'] );
			if ( ! isset( $winners[ $key ] ) ) {
				$winners[ $key ] = $index;
			} elseif ( empty( $planned[ $winners[ $key ] ]['rule']['enabled'] ) && ! empty( $item['rule']['enabled'] ) ) {
				$winners[ $key ] = $index;
			}
		}

		foreach ( $planned as $index => $item ) {
			if ( null === $item['rule'] ) {
				continue;
			}
			$winner = $winners[ self::conflict_key( $item['rule'] ) ];
			if ( $winner !== $index ) {
				$planned[ $index ]['superseded']    = true;
				$planned[ $index ]['superseded_by'] = $planned[ $winner ]['source_id'];
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
			$source = trim( (string) $rule['source'] );
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
			? 'r:' . trim( (string) $rule['source'] )
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
		foreach ( [ 'type', 'status_code', 'enabled', 'trailing_slash', 'note', 'origin' ] as $field ) {
			if ( isset( $rule[ $field ] ) && ! is_scalar( $rule[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An empty imported title never wipes an existing rule's note: the note is left out so the
	 * Validator keeps the stored one.
	 */
	private static function validator_input( array $rule, ?Rule $existing = null ): array {
		$input = array_intersect_key( $rule, array_flip( self::RULE_FIELDS ) );
		if ( null !== $existing && '' !== (string) $existing->note && '' === trim( (string) ( $input['note'] ?? '' ) ) ) {
			unset( $input['note'] );
		}
		return $input;
	}

	/**
	 * @return array{code:string,message:string}
	 */
	private static function superseded_reason( int $winner_id, string $source ): array {
		$message = self::SOURCE_YOAST === $source
			? sprintf(
				/* translators: %d: Yoast redirect number */
				__( 'Yoast entry #%d already covers this source.', 'wp-redirects' ),
				$winner_id
			)
			: sprintf(
				/* translators: %d: Redirection entry id */
				__( 'Redirection used entry #%d for this source; this copy was never served.', 'wp-redirects' ),
				$winner_id
			);
		return [
			'code'    => 'superseded',
			'message' => $message,
		];
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
			'unsupported_capture'    => __( 'Only captures $1 to $9 are supported.', 'wp-redirects' ),
			'unreachable_regex'      => __( 'Yoast matched regex rules against the path, so a pattern that starts with a URL never matched anything.', 'wp-redirects' ),
			'case_dependent_regex'   => __( 'Yoast matched this pattern case-sensitively; WP Redirects ignores case, so importing it could redirect other URLs. It stays in Yoast.', 'wp-redirects' ),
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
