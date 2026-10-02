<?php
/**
 * Regex source handling. Delimiters and flags are always controlled here,
 * so user input can never add modifiers.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class Pattern {

	public const MAX_LENGTH = 500;

	public static function delimit( string $source ): string {
		// Escape every "~" not already preceded by an odd number of backslashes.
		$escaped = preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $source );
		if ( null === $escaped ) {
			// preg_replace failed (e.g. invalid UTF-8 or PCRE limits): fail closed with a pattern that never matches.
			return '~(?!)~';
		}
		return '~' . $escaped . '~i';
	}

	public static function is_valid( string $source ): bool {
		if ( '' === $source || strlen( $source ) > self::MAX_LENGTH ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile check; invalid patterns emit warnings by design.
		return false !== @preg_match( self::delimit( $source ), '' );
	}
}
