<?php
/**
 * Matches a normalized request against a compiled rule set. Pure.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class Matcher {

	private array $ruleset;

	/** @var callable|null */
	private $on_regex_error;

	public function __construct( array $ruleset, ?callable $on_regex_error = null ) {
		$this->ruleset        = $ruleset + RulesetCompiler::empty_ruleset();
		$this->on_regex_error = $on_regex_error;
	}

	/**
	 * @param array{path:string,key:string,query:string} $request
	 */
	public function match( array $request ): ?MatchResult {
		$exact = $this->ruleset['exact'];

		if ( $this->ruleset['has_query'] && '' !== $request['query'] ) {
			$with_query = $request['key'] . '?' . $request['query'];
			if ( isset( $exact[ $with_query ] ) ) {
				return $this->exact_result( $exact[ $with_query ] );
			}
		}
		if ( isset( $exact[ $request['key'] ] ) ) {
			return $this->exact_result( $exact[ $request['key'] ] );
		}

		$subject = substr( $request['path'], 0, PathNormalizer::MAX_LENGTH );
		foreach ( $this->ruleset['regex'] as $rule ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Runtime PCRE failures are handled via preg_last_error().
			$result = @preg_match( $rule['pattern'], $subject, $captures );
			if ( false === $result || PREG_NO_ERROR !== preg_last_error() ) {
				if ( null !== $this->on_regex_error ) {
					call_user_func( $this->on_regex_error, (int) $rule['id'], preg_last_error() );
				}
				continue;
			}
			if ( 1 === $result ) {
				return new MatchResult( (int) $rule['id'], 'regex', (int) $rule['status'], $rule['target'], $captures );
			}
		}

		return null;
	}

	private function exact_result( array $entry ): MatchResult {
		return new MatchResult( (int) $entry['id'], 'exact', (int) $entry['status'], $entry['target'] );
	}
}
