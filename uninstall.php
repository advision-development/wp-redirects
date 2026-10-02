<?php
/**
 * Uninstall handler. Removes data only when "Remove all data on uninstall" is enabled.
 *
 * @package Advision\Redirects
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Advision\Redirects\Autoloader::register( __DIR__ . '/src' );

\Advision\Redirects\Uninstaller::run();
