<?php
/**
 * Site-level helpers (home URL, host, reserved paths) used by the WordPress layer.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\TargetResolver;

defined( 'ABSPATH' ) || exit;

final class Site {

	public static function home_url(): string {
		return untrailingslashit( home_url() );
	}

	public static function host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	public static function home_path(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? rtrim( $path, '/' ) : '';
	}

	public static function normalizer(): PathNormalizer {
		return new PathNormalizer( self::home_path() );
	}

	public static function resolver(): TargetResolver {
		/**
		 * Filters the allowlist of external hosts redirects may point to.
		 * An empty array (default) allows any external host.
		 *
		 * @param string[] $hosts Lowercase host names.
		 */
		$hosts = (array) apply_filters( 'adv_redirects_allowed_target_hosts', [] );
		$hosts = array_values( array_filter( array_map( 'strval', $hosts ) ) );
		return new TargetResolver( self::home_url(), $hosts );
	}

	public static function internal_path( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}
		if ( '/' === $url[0] ) {
			return 0 === strpos( $url, '//' ) ? null : $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== self::host() ) {
			return null;
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$home = self::home_path();
		if ( '' !== $home ) {
			if ( $path === $home ) {
				$path = '/';
			} elseif ( 0 === strpos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			} else {
				return null;
			}
		}

		return $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/**
	 * @return string[]
	 */
	public static function reserved_prefixes(): array {
		$prefixes = [ '/wp-admin', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/' . trim( rest_get_url_prefix(), '/' ) ];

		// WordPress core in a subdirectory: its entry points live under the site path, relative to home.
		$site = wp_parse_url( site_url(), PHP_URL_PATH );
		$site = is_string( $site ) ? rtrim( $site, '/' ) : '';
		$home = self::home_path();
		if ( '' !== $home && 0 === strpos( $site . '/', $home . '/' ) ) {
			$site = substr( $site, strlen( $home ) );
		}
		if ( '' !== $site ) {
			$site = PathNormalizer::key( $site );
			foreach ( [ '/wp-admin', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php' ] as $entry ) {
				$prefixes[] = $site . $entry;
			}
		}

		return $prefixes;
	}

	public static function is_reserved_path( string $path ): bool {
		$key = PathNormalizer::key( $path );
		foreach ( self::reserved_prefixes() as $prefix ) {
			if ( $key === $prefix || 0 === strpos( $key, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
