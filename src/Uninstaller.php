<?php
/**
 * Data removal on uninstall (opt-in).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\RuleCache;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	public static function should_remove(): bool {
		$settings = get_option( Settings::OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );
	}

	public static function run(): void {
		if ( ! self::should_remove() ) {
			return;
		}
		Cron::unschedule();
		Schema::drop_all();
		delete_option( Settings::OPTION );
		delete_option( Schema::VERSION_OPTION );
		RuleCache::flush();
	}
}
