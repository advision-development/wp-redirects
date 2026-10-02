<?php
/**
 * Typed access to the plugin settings option.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'adv_redirects_settings';

	private const BOOLEANS = [ 'slug_watcher', 'log_404', 'forward_query_string', 'remove_data_on_uninstall' ];

	public static function defaults(): array {
		return [
			'slug_watcher'             => true,
			'log_404'                  => true,
			'log_404_retention_days'   => 30,
			'log_404_max_rows'         => 5000,
			'forward_query_string'     => true,
			'excluded_404_extensions'  => [ 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot' ],
			'remove_data_on_uninstall' => false,
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return self::sanitize( array_merge( self::defaults(), is_array( $saved ) ? $saved : [] ) );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$clean    = [];

		foreach ( self::BOOLEANS as $key ) {
			$clean[ $key ] = array_key_exists( $key, $input ) ? rest_sanitize_boolean( $input[ $key ] ) : $defaults[ $key ];
		}

		$clean['log_404_retention_days'] = self::clamp( $input['log_404_retention_days'] ?? $defaults['log_404_retention_days'], 1, 365 );
		$clean['log_404_max_rows']       = self::clamp( $input['log_404_max_rows'] ?? $defaults['log_404_max_rows'], 100, 100000 );

		$extensions = $input['excluded_404_extensions'] ?? $defaults['excluded_404_extensions'];
		if ( is_string( $extensions ) ) {
			$extensions = explode( ',', $extensions );
		}
		$extensions                       = array_map(
			static function ( $ext ): string {
				return strtolower( ltrim( trim( (string) $ext ), '.' ) );
			},
			(array) $extensions
		);
		$extensions                       = array_filter(
			$extensions,
			static function ( string $ext ): bool {
				return 1 === preg_match( '/^[a-z0-9]{1,10}$/', $ext );
			}
		);
		$clean['excluded_404_extensions'] = array_slice( array_values( array_unique( $extensions ) ), 0, 50 );

		// Keep a stable key order matching defaults().
		return array_merge( $defaults, $clean );
	}

	public static function update( array $input ): array {
		$clean = self::sanitize( array_merge( self::all(), array_intersect_key( $input, self::defaults() ) ) );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}
}
