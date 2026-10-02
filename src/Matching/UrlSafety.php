<?php
/**
 * Pure redirect-target safety checks. No WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class UrlSafety {

	public const MAX_LENGTH = 2048;

	public static function is_safe( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH ) {
			return false;
		}
		// Control characters, whitespace and backslashes are never allowed.
		if ( preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return false;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return false;
		}
		if ( '/' === $url[0] ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class; no WordPress dependency.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		// Scheme must be followed by "//" (rejects "https:/evil.com").
		return 0 === stripos( $url, $parts['scheme'] . '://' );
	}

	public static function host_of( string $url, string $site_host ): string {
		if ( '' !== $url && '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
			return strtolower( $site_host );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class; no WordPress dependency.
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
