<?php
/**
 * Writes Yoast SEO Premium's redirect storage. Yoast keeps one redirect per origin.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

interface YoastStore {

	/**
	 * @param array<int,array{origin:string,format:string}> $items Redirects to remove.
	 * @return array{removed:array<int,array>,not_found:array<int,array>} `removed` holds the raw base entries.
	 */
	public function remove( array $items ): array;

	/**
	 * @param array<int,array> $entries Raw base entries { origin, url, type, format }.
	 * @return array{added:array<int,array>,already_present:array<int,array>}
	 */
	public function add( array $entries ): array;
}
