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

		// Yoast matched regex case-sensitively and WP Redirects ignores case, so a pattern with a
		// literal capital letter would also catch the lowercase URLs Yoast never redirected.
		if ( $regex && self::is_case_dependent( $entry['origin'] ) ) {
			return self::skip( $source_id, 'case_dependent_regex' );
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
			// "$1/x" becomes "/$1/x". If group 1 starts with "/", that is "//nfl/x": a protocol-relative
			// URL the host guard rejects, so the redirect would silently stop working.
			if ( $regex && preg_match( '#^/?\$([1-9])#', $url, $lead ) && self::group_starts_with_slash( $entry['origin'], (int) $lead[1] ) ) {
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
	 * Yoast prepends home_url() to a target without a scheme. A target with any scheme is kept as it
	 * is; one other than http(s) (mailto:, ftp:, tel:) is then rejected by the Validator.
	 */
	private static function target( string $url, bool $trailing_slash ): string {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
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
	 * slash after substitution, and a static one could double it. Like Yoast's has_extension(), a
	 * "." anywhere in the path (not only the last segment, so "/v2.0/page" too) means no slash.
	 */
	private static function wants_trailing_slash( string $target ): bool {
		if ( '/' === substr( $target, -1 ) || preg_match( '/\$[0-9]/', $target ) || false !== strpbrk( $target, '?#' ) ) {
			return false;
		}
		return false === strpos( $target, '.' );
	}

	/**
	 * Whether a regex has a literal capital letter A-Z outside escape sequences ("\S", "\P{Lu}"),
	 * character classes ("[A-Z]") and quantifier braces.
	 */
	private static function is_case_dependent( string $pattern ): bool {
		$literal = (string) preg_replace( '/\\\\./s', '', $pattern );
		$literal = (string) preg_replace( '/\[[^\]]*\]/', '', $literal );
		$literal = (string) preg_replace( '/\{[^}]*\}/', '', $literal );
		return 1 === preg_match( '/[A-Z]/', $literal );
	}

	/**
	 * Whether capturing group $number of the pattern starts with "/" or "\/". Groups are counted by
	 * their "(" that are not escaped, not inside a character class and not followed by "?".
	 */
	private static function group_starts_with_slash( string $pattern, int $number ): bool {
		$length = strlen( $pattern );
		$count  = 0;
		$class  = false;
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char ) {
				++$i;
				continue;
			}
			if ( $class ) {
				$class = ']' !== $char;
				continue;
			}
			if ( '[' === $char ) {
				$class = true;
				continue;
			}
			if ( '(' !== $char || '?' === substr( $pattern, $i + 1, 1 ) ) {
				continue;
			}
			if ( ++$count === $number ) {
				$content = (string) substr( $pattern, $i + 1, 2 );
				return '/' === substr( $content, 0, 1 ) || '\\/' === $content;
			}
		}
		return false;
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
