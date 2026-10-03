<?php
/**
 * Changes Yoast SEO Premium's redirects through Yoast's own classes, so the base option, both export
 * options and any .htaccess or nginx redirect file stay in sync. Used only while Premium is active.
 * All changes in one call are written with a single save.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastManagerStore implements YoastStore {

	public function remove( array $items ): array {
		$option    = new \WPSEO_Redirect_Option();
		$removed   = [];
		$not_found = [];

		foreach ( $items as $item ) {
			$redirect = $option->get( $item['origin'] );
			// Only an entry unchanged since the import: same format, target and type.
			if ( ! $redirect instanceof \WPSEO_Redirect
				|| $redirect->get_format() !== $item['format']
				|| (string) $redirect->get_target() !== (string) $item['url']
				|| (int) $redirect->get_type() !== (int) $item['type']
			) {
				$not_found[] = $item;
				continue;
			}
			$option->delete( $redirect );
			$removed[] = self::to_entry( $redirect );
		}

		if ( $removed ) {
			self::save( $option );
		}
		return [
			'removed'   => $removed,
			'not_found' => $not_found,
		];
	}

	public function add( array $entries ): array {
		$option  = new \WPSEO_Redirect_Option();
		$added   = [];
		$present = [];

		foreach ( $entries as $entry ) {
			$redirect = new \WPSEO_Redirect( (string) $entry['origin'], (string) $entry['url'], (int) $entry['type'], (string) $entry['format'] );
			if ( $option->add( $redirect ) ) {
				$added[] = $entry;
			} else {
				$present[] = $entry;
			}
		}

		if ( $added ) {
			self::save( $option );
		}
		return [
			'added'           => $added,
			'already_present' => $present,
		];
	}

	/**
	 * One write of the base option, both export options and any redirect file.
	 */
	private static function save( \WPSEO_Redirect_Option $option ): void {
		( new \WPSEO_Redirect_Manager( 'plain', null, $option ) )->save_redirects();
	}

	private static function to_entry( \WPSEO_Redirect $redirect ): array {
		return [
			'origin' => (string) $redirect->get_origin(),
			'url'    => (string) $redirect->get_target(),
			'type'   => (int) $redirect->get_type(),
			'format' => (string) $redirect->get_format(),
		];
	}
}
