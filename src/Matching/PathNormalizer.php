<?php
/**
 * Pure request-path normalization. No WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class PathNormalizer {

	public const MAX_LENGTH = 2048;

	private string $home_path;

	/**
	 * @param string $home_path Path portion of the site's home URL ('' for root installs, e.g. '/blog').
	 */
	public function __construct( string $home_path ) {
		$this->home_path = rtrim( $home_path, '/' );
	}

	/**
	 * @return array{path:string,key:string,query:string}|null
	 */
	public function from_request_uri( string $uri ): ?array {
		if ( '' === $uri || strlen( $uri ) > self::MAX_LENGTH ) {
			return null;
		}

		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}

		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );

		if ( '' !== $this->home_path ) {
			if ( $path === $this->home_path ) {
				$path = '/';
			} elseif ( 0 === strpos( $path, $this->home_path . '/' ) ) {
				$path = substr( $path, strlen( $this->home_path ) );
			} else {
				return null;
			}
		}

		$path = rawurldecode( $path );
		if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return null;
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return [
			'path'  => $path,
			'key'   => self::key( $path ),
			'query' => $query,
		];
	}

	public static function key( string $path ): string {
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		$key = rtrim( $key, '/' );
		return '' === $key ? '/' : $key;
	}

	public static function source_key( string $source ): string {
		$qpos  = strpos( $source, '?' );
		$path  = false === $qpos ? $source : substr( $source, 0, $qpos );
		$query = false === $qpos ? '' : substr( $source, $qpos + 1 );
		$key   = self::key( rawurldecode( $path ) );
		return '' === $query ? $key : $key . '?' . $query;
	}
}
