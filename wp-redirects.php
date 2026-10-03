<?php
/**
 * Plugin Name:       WP Redirects
 * Plugin URI:        https://github.com/advision-development/wp-redirects
 * Description:       Exact and regex redirects with selectable status codes, object-cached matching, hit counts, slug-change redirects and a 404 log.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-redirects
 * Update URI:        https://github.com/advision-development/wp-redirects
 *
 * @package Advision\Redirects
 */

defined( 'ABSPATH' ) || exit;

define( 'ADV_REDIRECTS_VERSION', '1.1.0' );
define( 'ADV_REDIRECTS_FILE', __FILE__ );
define( 'ADV_REDIRECTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADV_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

require_once ADV_REDIRECTS_DIR . 'src/Autoloader.php';
\Advision\Redirects\Autoloader::register( ADV_REDIRECTS_DIR . 'src' );
require_once ADV_REDIRECTS_DIR . 'src/functions.php';

register_activation_hook( __FILE__, [ \Advision\Redirects\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Advision\Redirects\Plugin::class, 'deactivate' ] );

\Advision\Redirects\Plugin::instance()->boot();
