<?php
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'Advision\\Redirects\\';

	public static function register( string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
