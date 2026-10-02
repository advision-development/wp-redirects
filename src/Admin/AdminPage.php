<?php
/**
 * Admin menu page that mounts the React app.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Admin;

use Advision\Redirects\Permissions;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const SLUG = 'adv-redirects';

	private const HANDLE = 'adv-redirects-admin';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function add_menu(): void {
		// add_menu_page() records the entry even for users without the capability
		// and only hides it later, so skip registration for them outright.
		if ( ! Permissions::can_manage() ) {
			return;
		}

		add_menu_page(
			__( 'Redirects', 'wp-redirects' ),
			__( 'Redirects', 'wp-redirects' ),
			Permissions::capability(),
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-randomize',
			76
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = ADV_REDIRECTS_DIR . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action( 'admin_notices', [ $this, 'missing_build_notice' ] );
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, ADV_REDIRECTS_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'wp-redirects' );
		wp_add_inline_script(
			self::HANDLE,
			'window.advRedirects = ' . wp_json_encode(
				[
					'homeUrl' => Site::home_url(),
					'version' => ADV_REDIRECTS_VERSION,
				]
			) . ';',
			'before'
		);

		if ( is_readable( ADV_REDIRECTS_DIR . 'build/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, ADV_REDIRECTS_URL . 'build/index.css', [ 'wp-components' ], $asset['version'] );
		}
	}

	public function render(): void {
		if ( ! Permissions::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirects' ) );
		}
		echo '<div class="wrap adv-redirects-wrap"><div id="adv-redirects-app"></div><noscript>'
			. esc_html__( 'The Redirects screen needs JavaScript.', 'wp-redirects' )
			. '</noscript></div>';
	}

	public function missing_build_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WP Redirects admin assets are missing. Run "npm run build", or install a release zip.', 'wp-redirects' )
			. '</p></div>';
	}
}
