<?php
/**
 * Yoast SEO Premium side of the Yoast import: detects stored redirects, removes imported ones from
 * Yoast (only those WP Redirects now covers), and keeps a backup that can be restored.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class YoastSource {

	public const BACKUP_OPTION = 'adv_redirects_yoast_backup';

	public const METHOD_OPTION = 'wpseo_redirect';

	private const FIELDS = [ 'origin', 'url', 'type', 'format' ];

	private Importer $importer;

	private ?YoastStore $store;

	public function __construct( Importer $importer, ?YoastStore $store = null ) {
		$this->importer = $importer;
		$this->store    = $store;
	}

	public static function premium_active(): bool {
		return defined( 'WPSEO_PREMIUM_VERSION' ) && class_exists( 'WPSEO_Redirect_Manager' );
	}

	/**
	 * How Yoast serves its redirects: from PHP, or from a server configuration file it writes.
	 */
	public static function server_mode(): string {
		$method = get_option( self::METHOD_OPTION );
		if ( ! is_array( $method ) || 'on' !== ( $method['disable_php_redirect'] ?? 'off' ) ) {
			return 'php';
		}

		global $is_apache, $is_nginx;
		$apache = method_exists( 'WPSEO_Utils', 'is_apache' ) ? (bool) \WPSEO_Utils::is_apache() : ! empty( $is_apache );
		if ( $apache ) {
			return 'on' === ( $method['separate_file'] ?? 'off' ) ? 'apache_file' : 'htaccess';
		}
		$nginx = method_exists( 'WPSEO_Utils', 'is_nginx' ) ? (bool) \WPSEO_Utils::is_nginx() : ! empty( $is_nginx );
		return $nginx ? 'nginx' : 'none';
	}

	public function status(): array {
		$entries = $this->entries();
		$counts  = [
			'plain' => 0,
			'regex' => 0,
		];
		foreach ( $entries as $entry ) {
			if ( isset( $entry['format'] ) && is_string( $entry['format'] ) && isset( $counts[ $entry['format'] ] ) ) {
				++$counts[ $entry['format'] ];
			}
		}

		return [
			'detected'        => ! empty( $entries ),
			'premium_active'  => self::premium_active(),
			'premium_version' => defined( 'WPSEO_PREMIUM_VERSION' ) ? (string) constant( 'WPSEO_PREMIUM_VERSION' ) : null,
			'server_mode'     => self::server_mode(),
			'counts'          => $counts,
			'entries'         => $entries,
			'backup'          => $this->backup_summary(),
		];
	}

	/**
	 * The base option in stored order, read raw (Yoast's own read filter does not apply), each
	 * entry reduced to its four fields plus `id`, its 1-based position.
	 *
	 * @return array<int,array>
	 */
	public function entries(): array {
		$base = get_option( YoastOptionStore::BASE_OPTION, [] );
		if ( ! is_array( $base ) ) {
			return [];
		}
		$out = [];
		foreach ( array_values( $base ) as $index => $entry ) {
			$item = [ 'id' => $index + 1 ];
			if ( is_array( $entry ) ) {
				$item += array_intersect_key( $entry, array_flip( self::FIELDS ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Removes Yoast redirects that WP Redirects now covers. An item is removed only if Yoast still
	 * holds it with the same origin and format and WP Redirects has a rule with the same conflict
	 * key; anything else is left in Yoast.
	 *
	 * The backup is saved before anything is removed. If it cannot be saved, nothing is removed and a
	 * WP_Error is returned.
	 *
	 * @param array<int,array{origin:string,format:string}> $items Redirects to remove.
	 * @return array|\WP_Error
	 */
	public function remove( array $items ) {
		$items    = array_values( $items );
		$base     = get_option( YoastOptionStore::BASE_OPTION, [] );
		$base     = is_array( $base ) ? $base : [];
		$trailing = Site::trailing_slash_permalinks();
		$results  = [];
		$rules    = [];
		$entries  = [];

		foreach ( $items as $key => $item ) {
			$entry = self::find( $base, (string) $item['origin'], (string) $item['format'] );
			if ( null === $entry ) {
				$results[ $key ] = 'not_found';
				continue;
			}
			$mapped = YoastMapper::map( $entry, $trailing );
			if ( ! $mapped['ok'] ) {
				$results[ $key ] = 'not_covered';
				continue;
			}
			$rules[ $key ]   = $mapped['rule'];
			$entries[ $key ] = $entry;
		}
		foreach ( $this->importer->covered( $rules ) as $key => $covered ) {
			$results[ $key ] = $covered ? 'removed' : 'not_covered';
		}
		ksort( $results );

		$wanted = [];
		foreach ( $results as $key => $result ) {
			if ( 'removed' === $result ) {
				$wanted[ $key ] = [
					'origin' => (string) $items[ $key ]['origin'],
					'format' => (string) $items[ $key ]['format'],
				];
			}
		}

		$removed = [];
		if ( $wanted ) {
			$previous = $this->backup();
			$now      = time();
			$user     = get_current_user_id();

			// Back up first: if the backup cannot be saved, Yoast is left untouched.
			$planned = $previous;
			foreach ( array_keys( $wanted ) as $key ) {
				$planned[] = self::backup_row( $entries[ $key ], $now, $user );
			}
			if ( ! update_option( self::BACKUP_OPTION, $planned, false ) ) {
				return new \WP_Error(
					'adv_redirects_backup_failed',
					__( 'The backup of the Yoast redirects could not be saved, so nothing was removed.', 'wp-redirects' ),
					[ 'status' => 500 ]
				);
			}

			$outcome   = $this->store()->remove( array_values( $wanted ) );
			$removed   = $outcome['removed'];
			$not_found = array_values( $outcome['not_found'] );

			// Replace the planned backup with what the store really removed (a duplicate or an entry
			// that changed in Yoast after the check above comes back in `not_found`). The later copy of
			// a duplicate is the one the store reports, so match from the end of the request.
			foreach ( array_reverse( array_keys( $wanted ) ) as $key ) {
				$item = $wanted[ $key ];
				foreach ( $not_found as $position => $missing ) {
					if ( is_array( $missing ) && isset( $missing['origin'], $missing['format'] ) && $missing['origin'] === $item['origin'] && $missing['format'] === $item['format'] ) {
						unset( $not_found[ $position ] );
						$results[ $key ] = 'not_found';
						break;
					}
				}
			}

			$final = $previous;
			foreach ( $removed as $entry ) {
				$final[] = self::backup_row( $entry, $now, $user );
			}
			if ( $final ) {
				update_option( self::BACKUP_OPTION, $final, false );
			} else {
				delete_option( self::BACKUP_OPTION );
			}
		}

		if ( $removed ) {
			/**
			 * Fires after redirects are removed from Yoast SEO Premium.
			 *
			 * @param array $entries Removed base-option entries { origin, url, type, format }.
			 */
			do_action( 'adv_redirects_yoast_removed', $removed );
		}

		$out    = [];
		$counts = [
			'removed'     => 0,
			'not_found'   => 0,
			'not_covered' => 0,
		];
		foreach ( $results as $key => $result ) {
			++$counts[ $result ];
			$out[] = [
				'origin' => (string) $items[ $key ]['origin'],
				'format' => (string) $items[ $key ]['format'],
				'result' => $result,
			];
		}
		return $counts + [ 'items' => $out ];
	}

	/**
	 * Puts every backed-up redirect Yoast doesn't already hold back into Yoast, then clears the
	 * backup. WP Redirects rules are not touched.
	 */
	public function restore(): array {
		$backup = $this->backup();
		if ( ! $backup ) {
			return [
				'restored'        => 0,
				'already_present' => 0,
			];
		}

		$outcome = $this->store()->add( array_column( $backup, 'entry' ) );
		delete_option( self::BACKUP_OPTION );

		if ( $outcome['added'] ) {
			/**
			 * Fires after backed-up redirects are restored to Yoast SEO Premium.
			 *
			 * @param array $entries Restored base-option entries { origin, url, type, format }.
			 */
			do_action( 'adv_redirects_yoast_restored', $outcome['added'] );
		}
		return [
			'restored'        => count( $outcome['added'] ),
			'already_present' => count( $outcome['already_present'] ),
		];
	}

	public function delete_backup(): void {
		delete_option( self::BACKUP_OPTION );
	}

	private function store(): YoastStore {
		if ( null === $this->store ) {
			$this->store = self::premium_active() ? new YoastManagerStore() : new YoastOptionStore();
		}
		return $this->store;
	}

	/**
	 * @param array $base Base option rows.
	 */
	private static function find( array $base, string $origin, string $format ): ?array {
		foreach ( $base as $entry ) {
			if ( is_array( $entry ) && isset( $entry['origin'], $entry['format'] ) && $entry['origin'] === $origin && $entry['format'] === $format ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * @return array<int,array{entry:array,removed_at:int,removed_by:int}>
	 */
	private function backup(): array {
		$backup = get_option( self::BACKUP_OPTION, [] );
		if ( ! is_array( $backup ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$backup,
				static function ( $row ) {
					return self::valid_backup_row( $row );
				}
			)
		);
	}

	private function backup_summary(): ?array {
		$backup = $this->backup();
		if ( ! $backup ) {
			return null;
		}
		return [
			'count'           => count( $backup ),
			'last_removed_at' => gmdate( 'Y-m-d H:i:s', (int) max( array_column( $backup, 'removed_at' ) ) ),
		];
	}

	/**
	 * A backup row is usable only if restoring it cannot write garbage into Yoast.
	 *
	 * @param mixed $row Backup option row.
	 */
	private static function valid_backup_row( $row ): bool {
		if ( ! is_array( $row ) || ! isset( $row['removed_at'] ) || ! is_int( $row['removed_at'] ) || ! isset( $row['entry'] ) || ! is_array( $row['entry'] ) ) {
			return false;
		}
		$entry = $row['entry'];
		return isset( $entry['origin'], $entry['format'], $entry['url'], $entry['type'] )
			&& is_string( $entry['origin'] )
			&& is_string( $entry['format'] )
			&& is_string( $entry['url'] )
			&& ( is_int( $entry['type'] ) || ( is_string( $entry['type'] ) && ctype_digit( $entry['type'] ) ) );
	}

	/**
	 * One backup row. The two stores return differently shaped rows; keep one canonical shape.
	 *
	 * @param array $entry Base-option entry.
	 * @return array{entry:array,removed_at:int,removed_by:int}
	 */
	private static function backup_row( array $entry, int $removed_at, int $removed_by ): array {
		return [
			'entry'      => array_intersect_key( $entry, array_flip( self::FIELDS ) ),
			'removed_at' => $removed_at,
			'removed_by' => $removed_by,
		];
	}
}
