<?php
/**
 * Edits Yoast SEO Premium's redirect options directly. Used when Premium is not active, so its own
 * classes are not loaded. Rebuilds both export options from the base option in Yoast's shape.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastOptionStore implements YoastStore {

	public const BASE_OPTION = 'wpseo-premium-redirects-base';

	public const PLAIN_OPTION = 'wpseo-premium-redirects-export-plain';

	public const REGEX_OPTION = 'wpseo-premium-redirects-export-regex';

	public function remove( array $items ): array {
		$base      = self::base();
		$removed   = [];
		$not_found = [];

		foreach ( $items as $item ) {
			$found = null;
			foreach ( $base as $index => $entry ) {
				if ( self::origin_of( $entry ) === $item['origin'] && $entry['format'] === $item['format'] ) {
					$found = $index;
					break;
				}
			}
			if ( null === $found ) {
				$not_found[] = $item;
				continue;
			}
			$removed[] = $base[ $found ];
			unset( $base[ $found ] );
		}

		if ( $removed ) {
			self::save( array_values( $base ) );
		}
		return [
			'removed'   => $removed,
			'not_found' => $not_found,
		];
	}

	public function add( array $entries ): array {
		$base    = self::base();
		$added   = [];
		$present = [];

		foreach ( $entries as $entry ) {
			$exists = false;
			foreach ( $base as $row ) {
				if ( self::origin_of( $row ) === (string) $entry['origin'] ) {
					$exists = true;
					break;
				}
			}
			if ( $exists ) {
				$present[] = $entry;
				continue;
			}
			$base[]  = $entry;
			$added[] = $entry;
		}

		if ( $added ) {
			self::save( $base );
		}
		return [
			'added'           => $added,
			'already_present' => $present,
		];
	}

	private static function base(): array {
		$base = get_option( self::BASE_OPTION, [] );
		return is_array( $base ) ? array_values( $base ) : [];
	}

	/**
	 * The origin of a well-formed row, or null for anything else (kept, never matched).
	 *
	 * @param mixed $row Base option row.
	 */
	private static function origin_of( $row ): ?string {
		return is_array( $row ) && isset( $row['origin'], $row['format'] ) && is_string( $row['origin'] ) && is_string( $row['format'] ) ? $row['origin'] : null;
	}

	/**
	 * Writes the base option (autoload off, as Yoast does) and rebuilds both export options.
	 * update_option() without an autoload argument keeps each export option's current autoload.
	 */
	private static function save( array $base ): void {
		update_option( self::BASE_OPTION, $base, false );

		$export = [
			'plain' => [],
			'regex' => [],
		];
		foreach ( $base as $row ) {
			$origin = self::origin_of( $row );
			if ( null !== $origin && isset( $export[ $row['format'] ] ) ) {
				$export[ $row['format'] ][ $origin ] = [
					'url'  => isset( $row['url'] ) && is_string( $row['url'] ) ? $row['url'] : '',
					'type' => isset( $row['type'] ) ? (int) $row['type'] : 301,
				];
			}
		}
		update_option( self::PLAIN_OPTION, $export['plain'] );
		update_option( self::REGEX_OPTION, $export['regex'] );
	}
}
