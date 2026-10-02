<?php
/**
 * Capability checks.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	public static function capability(): string {
		/**
		 * Filters the capability required to manage redirects, the 404 log and settings.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'adv_redirects_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	public static function can_manage(): bool {
		return current_user_can( self::capability() );
	}
}
