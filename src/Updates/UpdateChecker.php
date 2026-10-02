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

	public const ASSET_PATTERN = '/^wp-redirects-\d+\.\d+\.\d+\.zip$/';

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
		if ( ! class_exists( $factory ) || ! method_exists( $factory, 'getLatestClassVersion' ) ) {
			return;
		}

		// Fail closed: without release-asset support there is no safe way to update, so no checker is built.
		$api_class = $factory::getLatestClassVersion( 'GitHubApi' );
		if (
			! is_string( $api_class )
			|| ! class_exists( $api_class )
			|| ! method_exists( $api_class, 'enableReleaseAssets' )
			|| ! defined( $api_class . '::REQUIRE_RELEASE_ASSETS' )
			|| ! defined( $api_class . '::STRATEGY_LATEST_RELEASE' )
		) {
			return;
		}

		$checker = $factory::buildUpdateChecker( self::REPOSITORY, $plugin_file, 'wp-redirects' );
		$api     = $checker->getVcsApi();

		// Only a release whose asset matches the built zip is installable; a release without it yields no update.
		$api->enableReleaseAssets( self::ASSET_PATTERN, constant( $api_class . '::REQUIRE_RELEASE_ASSETS' ) );

		// The library would otherwise fall back to the latest tag or branch, whose zips are GitHub source archives
		// without build/ or vendor/. Keep only the latest-release strategy.
		add_filter( $checker->getUniqueName( 'vcs_update_detection_strategies' ), [ self::class, 'only_latest_release' ] );
	}

	/**
	 * Restricts update detection to the latest GitHub release.
	 *
	 * @param array $strategies Detection strategies keyed by name.
	 * @return array
	 */
	public static function only_latest_release( $strategies ): array {
		$strategies = is_array( $strategies ) ? $strategies : [];
		return array_intersect_key( $strategies, [ 'latest_release' => true ] );
	}
}
