<?php
/**
 * Builds the final redirect URL: capture substitution, relative resolution,
 * query forwarding, and the host guard. Pure; no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class TargetResolver {

	private string $home_url;

	private string $site_host;

	/** @var string[] */
	private array $allowed_hosts;

	/**
	 * @param string   $home_url      Site home URL without trailing slash.
	 * @param string[] $allowed_hosts Optional external host allowlist (empty = any host).
	 */
	public function __construct( string $home_url, array $allowed_hosts = [] ) {
		$this->home_url      = rtrim( $home_url, '/' );
		$this->site_host     = UrlSafety::host_of( $this->home_url, '' );
		$this->allowed_hosts = array_map( 'strtolower', $allowed_hosts );
	}

	public function resolve( string $template, array $captures, string $request_query, bool $forward_query ): ?string {
		$url = self::substitute( $template, $captures, true );

		if ( ! UrlSafety::is_safe( $url ) ) {
			return null;
		}

		// The host must be exactly what the template declares, ignoring capture placeholders.
		$expected_host = self::expected_host( $template, $this->site_host );
		$host          = UrlSafety::host_of( $url, $this->site_host );
		if ( '' === $host || $expected_host !== $host ) {
			return null;
		}
		if ( $host !== $this->site_host && ! empty( $this->allowed_hosts ) && ! in_array( $host, $this->allowed_hosts, true ) ) {
			return null;
		}

		if ( '/' === $url[0] ) {
			$url = $this->home_url . $url;
		}
		if ( $forward_query && '' !== $request_query ) {
			$url = self::merge_query( $url, $request_query );
			// Re-check after merging: enforces the length cap and rejects unsafe characters from the incoming query.
			if ( ! UrlSafety::is_safe( $url ) ) {
				return null;
			}
		}
		return $url;
	}

	/**
	 * The host a template declares. A template that literally starts with a single "/" is
	 * relative, so it is the site host (checked on the raw template, because stripping "$1"
	 * from "/$1/" would leave a protocol-relative "//"). Otherwise the host of the template
	 * with its capture placeholders removed. Returns '' when no host can be determined.
	 */
	public static function expected_host( string $template, string $site_host ): string {
		if ( '' !== $template && '/' === $template[0] && 0 !== strpos( $template, '//' ) ) {
			return $site_host;
		}
		return UrlSafety::host_of( (string) preg_replace( '/\$[1-9]/', '', $template ), $site_host );
	}

	/**
	 * Whether $url is the request itself, so redirecting to it would send the visitor back to the
	 * same URL (Yoast SEO Premium has the same guard).
	 *
	 * One representation on both sides: $base, then the percent-decoded path, then "?" and the raw
	 * query when it is non-empty. The request side is already in that form (PathNormalizer decodes
	 * the path and keeps the query raw), so only the path of $url is decoded here. That makes a capture
	 * re-encoded by substitute() ("caf%C3%A9") equal the decoded request path it came from ("café").
	 *
	 * The comparison is byte for byte: a path that differs only in case ("/NFL" vs "/nfl") or in a
	 * trailing slash ("/foo" vs "/foo/") is a different URL, so case-fix and slash-fix redirects still
	 * fire. A fragment is part of the compared path, so "/foo#top" is not "/foo".
	 *
	 * @param string $url   Final URL: absolute, or a path when $base is ''.
	 * @param string $base  Site home URL without trailing slash, or '' to compare paths.
	 * @param string $path  Decoded request path relative to the home, starting with "/".
	 * @param string $query Raw request query string without "?".
	 */
	public static function is_self( string $url, string $base, string $path, string $query ): bool {
		$base = rtrim( $base, '/' );
		$len  = strlen( $base );
		if ( substr( $url, 0, $len ) !== $base || '/' !== substr( $url, $len, 1 ) ) {
			return false;
		}
		$rest = (string) substr( $url, $len );
		if ( '' === $base && 0 === strpos( $rest, '//' ) ) {
			return false;
		}
		$qpos      = strpos( $rest, '?' );
		$url_path  = false === $qpos ? $rest : substr( $rest, 0, $qpos );
		$url_query = false === $qpos ? '' : substr( $rest, $qpos + 1 );
		return rawurldecode( $url_path ) === $path && $url_query === $query;
	}

	public static function substitute( string $template, array $captures, bool $encode ): string {
		if ( empty( $captures ) || false === strpos( $template, '$' ) ) {
			return $template;
		}
		return (string) preg_replace_callback(
			'/\$([1-9])/',
			static function ( array $m ) use ( $captures, $encode ): string {
				$value = isset( $captures[ (int) $m[1] ] ) ? (string) $captures[ (int) $m[1] ] : '';
				return $encode ? str_replace( '%2F', '/', rawurlencode( $value ) ) : $value;
			},
			$template
		);
	}

	public static function merge_query( string $url, string $request_query ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$qpos         = strpos( $url, '?' );
		$base         = false === $qpos ? $url : substr( $url, 0, $qpos );
		$target_query = false === $qpos ? '' : substr( $url, $qpos + 1 );

		// Merge raw pairs byte-for-byte: the target's pairs win, incoming pairs with new keys follow.
		$pairs = [];
		$known = [];
		foreach ( explode( '&', $target_query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$pairs[]                          = $pair;
			$known[ self::pair_key( $pair ) ] = true;
		}
		foreach ( explode( '&', $request_query ) as $pair ) {
			if ( '' === $pair || isset( $known[ self::pair_key( $pair ) ] ) ) {
				continue;
			}
			$pairs[] = $pair;
		}
		$query = implode( '&', $pairs );

		return $base . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}

	private static function pair_key( string $pair ): string {
		$eq = strpos( $pair, '=' );
		return rawurldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
	}
}
