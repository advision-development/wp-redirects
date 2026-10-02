<?php
/**
 * Self-updates from this repository's GitHub Releases (built zip asset only).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Updates;

defined( 'ABSPATH' ) || exit;

final class UpdateChecker {

	public const REPOSITORY = 'https://github.com/advision-development/wp-redirects/';

	public static function boot( string $plugin_file ): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$autoload = dirname( $plugin_file ) . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = $factory::buildUpdateChecker( self::REPOSITORY, $plugin_file, 'wp-redirects' );
		$api     = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			// Never fall back to GitHub's source zip: it has no build/ or vendor/.
			$require = get_class( $api ) . '::REQUIRE_RELEASE_ASSETS';
			if ( defined( $require ) ) {
				$api->enableReleaseAssets( '/^wp-redirects-\d+\.\d+\.\d+\.zip$/', constant( $require ) );
			} else {
				$api->enableReleaseAssets( '/^wp-redirects-\d+\.\d+\.\d+\.zip$/' );
			}
		}
	}
}
