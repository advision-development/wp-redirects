<?php
/**
 * Compiles database rows into the cached lookup structure. Pure.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class RulesetCompiler {

	public static function empty_ruleset(): array {
		return [
			'exact'     => [],
			'has_query' => false,
			'regex'     => [],
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rule rows.
	 */
	public static function compile( array $rows ): array {
		$ruleset = self::empty_ruleset();

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return [ (int) $a['position'], (int) $a['id'] ] <=> [ (int) $b['position'], (int) $b['id'] ];
			}
		);

		$exact_ids = [];
		foreach ( $rows as $row ) {
			if ( array_key_exists( 'enabled', $row ) && ! (int) $row['enabled'] ) {
				continue;
			}
			$entry = [
				'id'             => (int) $row['id'],
				'target'         => null === $row['target'] || '' === $row['target'] ? null : (string) $row['target'],
				'status'         => (int) $row['status_code'],
				'trailing_slash' => ! empty( $row['trailing_slash'] ),
			];

			if ( 'regex' === $row['type'] ) {
				$ruleset['regex'][] = [
					'id'      => $entry['id'],
					'pattern' => Pattern::delimit( (string) $row['source'] ),
				] + $entry;
				continue;
			}

			$key = PathNormalizer::source_key( (string) $row['source'] );
			if ( isset( $exact_ids[ $key ] ) && $exact_ids[ $key ] < $entry['id'] ) {
				continue;
			}
			$exact_ids[ $key ]        = $entry['id'];
			$ruleset['exact'][ $key ] = $entry;
			if ( false !== strpos( $key, '?' ) ) {
				$ruleset['has_query'] = true;
			}
		}

		return $ruleset;
	}
}
