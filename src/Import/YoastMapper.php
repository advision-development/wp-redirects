<?php
/**
 * Maps one Yoast SEO Premium redirect (an entry of the wpseo-premium-redirects-base option) to a
 * WP Redirects rule. Pure: no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastMapper {

	private const REDIRECT_STATUSES = [ 301, 302, 307 ];

	private const GONE_STATUSES = [ 410, 451 ];

	private const FORMATS = [ 'plain', 'regex' ];

	/**
	 * @param mixed $entry          One base-option entry plus the `id` (1-based position) YoastSource adds.
	 * @param bool  $trailing_slash Whether the permalink structure ends in a slash; Yoast then adds one to
	 *                              targets without a capture, query, fragment or file extension.
	 * @return array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}
	 */
	public static function map( $entry, bool $trailing_slash ): array {
		$source_id = is_array( $entry ) && isset( $entry['id'] ) && self::is_int_like( $entry['id'] ) ? (int) $entry['id'] : 0;

		if ( ! self::is_valid_entry( $entry ) ) {
			return self::skip( $source_id, 'invalid_entry' );
		}

		$regex  = 'regex' === $entry['format'];
		$status = (int) $entry['type'];

		// Yoast matches regex rules against the request path, so a pattern that starts with a URL never fired.
		if ( $regex && preg_match( '#^\^?\(?(?:\?:)?https?\??:#i', $entry['origin'] ) ) {
			return self::skip( $source_id, 'unreachable_regex' );
		}

		if ( in_array( $status, self::GONE_STATUSES, true ) ) {
			$target = null;
		} elseif ( in_array( $status, self::REDIRECT_STATUSES, true ) ) {
			$url = trim( $entry['url'] );
			if ( '' === $url ) {
				return self::skip( $source_id, 'invalid_entry' );
			}
			// Yoast substitutes $0 and $10+; WP Redirects substitutes $1-$9 only.
			if ( preg_match( '/\$(?:0|[1-9][0-9])/', $url ) ) {
				return self::skip( $source_id, 'unsupported_capture' );
			}
			$target = self::target( $url, $trailing_slash );
		} else {
			return self::skip( $source_id, 'unsupported_status' );
		}

		$notes = [];
		if ( $regex ) {
			$notes[] = 'case_sensitive_source';
			if ( false !== strpos( $entry['origin'], '\\?' ) ) {
				$notes[] = 'regex_query';
			}
		}

		return [
			'ok'        => true,
			'source_id' => $source_id,
			'rule'      => [
				'type'        => $regex ? 'regex' : 'exact',
				'source'      => $regex ? $entry['origin'] : self::plain_source( $entry['origin'] ),
				'target'      => $target,
				'status_code' => $status,
				'enabled'     => true,
				'note'        => '',
				'origin'      => 'manual',
			],
			'notes'     => $notes,
			'error'     => null,
		];
	}

	/**
	 * The exact source for a plain origin. Yoast stores plain origins with their slashes trimmed.
	 */
	public static function plain_source( string $origin ): string {
		$origin = trim( $origin );
		if ( preg_match( '#^https?://#i', $origin ) ) {
			return $origin;
		}
		return '/' . ltrim( $origin, '/' );
	}

	/**
	 * Yoast prepends home_url() to a target without a scheme.
	 */
	private static function target( string $url, bool $trailing_slash ): string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$target = '/' . ltrim( $url, '/' );
		if ( $trailing_slash && self::wants_trailing_slash( $target ) ) {
			$target .= '/';
		}
		return $target;
	}

	/**
	 * Mirrors Yoast's runtime rule. A target with a capture is left alone because Yoast adds the
	 * slash after substitution, and a static one could double it.
	 */
	private static function wants_trailing_slash( string $target ): bool {
		if ( '/' === substr( $target, -1 ) || preg_match( '/\$[0-9]/', $target ) || false !== strpbrk( $target, '?#' ) ) {
			return false;
		}
		$last = (string) substr( $target, (int) strrpos( $target, '/' ) + 1 );
		return false === strpos( $last, '.' );
	}

	/**
	 * @param mixed $entry Raw entry.
	 */
	private static function is_valid_entry( $entry ): bool {
		return is_array( $entry )
			&& isset( $entry['origin'] ) && is_string( $entry['origin'] ) && '' !== trim( $entry['origin'] )
			&& array_key_exists( 'url', $entry ) && is_string( $entry['url'] )
			&& isset( $entry['type'] ) && self::is_int_like( $entry['type'] )
			&& isset( $entry['format'] ) && is_string( $entry['format'] ) && in_array( $entry['format'], self::FORMATS, true );
	}

	/**
	 * Accepts ints and digit-only strings, not floats, exponents or padded strings.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_int_like( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) );
	}

	private static function skip( int $source_id, string $reason ): array {
		return [
			'ok'        => false,
			'source_id' => $source_id,
			'rule'      => null,
			'notes'     => [],
			'error'     => $reason,
		];
	}
}
