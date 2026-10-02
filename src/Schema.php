<?php
/**
 * Custom table installation and upgrades.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION = '1';

	public const VERSION_OPTION = 'adv_redirects_db_version';

	public static function redirects_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'adv_redirects';
	}

	public static function not_found_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'adv_redirects_404s';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$redirects = self::redirects_table();
		$not_found = self::not_found_table();

		dbDelta(
			"CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(10) NOT NULL DEFAULT 'exact',
  source varchar(2048) NOT NULL,
  target varchar(2048) DEFAULT NULL,
  status_code smallint(5) unsigned NOT NULL DEFAULT 301,
  position int(10) unsigned NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  origin varchar(10) NOT NULL DEFAULT 'manual',
  note varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_enabled (type,enabled)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$not_found} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path varchar(2048) NOT NULL,
  path_hash char(32) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  last_referrer varchar(2048) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY last_seen (last_seen)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	public static function drop_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::redirects_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::not_found_table() ) );
	}
}
