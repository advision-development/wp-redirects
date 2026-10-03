<?php
/**
 * Data removal on uninstall (opt-in).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Matching\RuleCache;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	public static function should_remove(): bool {
		$settings = get_option( Settings::OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );
	}

	/**
	 * Options removed on uninstall.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return [ Settings::OPTION, Schema::VERSION_OPTION, YoastSource::BACKUP_OPTION ];
	}

	public static function run(): void {
		if ( ! self::should_remove() ) {
			return;
		}
		Cron::unschedule();
		Schema::drop_all();
		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
		RuleCache::flush();
	}
}
