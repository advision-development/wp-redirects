<?php
/**
 * Maps one Redirection plugin export entry to a WP Redirects rule. Pure: no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class RedirectionMapper {

	public const MODIFIED_POSTS_GROUP = 'Modified Posts';

	private const REDIRECT_STATUSES = [ 301, 302, 307, 308 ];

	/**
	 * @param array $groups Raw `groups` entries from the export.
	 * @return array<int,string> Group id => name.
	 */
	public static function group_names( array $groups ): array {
		$names = [];
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['id'], $group['name'] ) && is_numeric( $group['id'] ) && is_string( $group['name'] ) ) {
				$names[ (int) $group['id'] ] = $group['name'];
			}
		}
		return $names;
	}

	/**
	 * @param mixed             $entry       One raw entry from the export's `redirects` list.
	 * @param array<int,string> $group_names From group_names().
	 * @return array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}
	 */
	public static function map( $entry, array $group_names ): array {
		$source_id = is_array( $entry ) && isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;

		if ( ! self::is_valid_entry( $entry ) ) {
			return self::skip( $source_id, 'invalid_entry' );
		}
		if ( 'url' !== $entry['match_type'] ) {
			return self::skip( $source_id, 'unsupported_match_type' );
		}

		$status = (int) $entry['action_code'];
		if ( 'error' === $entry['action_type'] ) {
			if ( 410 !== $status ) {
				return self::skip( $source_id, 'unsupported_action' );
			}
			$target = null;
		} elseif ( 'url' === $entry['action_type'] ) {
			if ( ! isset( $entry['action_data']['url'] ) || ! is_string( $entry['action_data']['url'] ) ) {
				return self::skip( $source_id, 'invalid_entry' );
			}
			if ( ! in_array( $status, self::REDIRECT_STATUSES, true ) ) {
				return self::skip( $source_id, 'unsupported_status' );
			}
			$target = $entry['action_data']['url'];
		} else {
			return self::skip( $source_id, 'unsupported_action' );
		}

		$flags = isset( $entry['match_data']['source'] ) && is_array( $entry['match_data']['source'] ) ? $entry['match_data']['source'] : [];
		$notes = [];
		if ( array_key_exists( 'flag_case', $flags ) && false === $flags['flag_case'] ) {
			$notes[] = 'case_insensitive';
		}
		if ( array_key_exists( 'flag_trailing', $flags ) && false === $flags['flag_trailing'] ) {
			$notes[] = 'trailing_slash_ignored';
		}
		if ( isset( $flags['flag_query'] ) && in_array( $flags['flag_query'], [ 'ignore', 'pass' ], true ) ) {
			$notes[] = 'query_mode';
		}
		if ( $entry['regex'] && false !== strpos( $entry['url'], '\\?' ) ) {
			$notes[] = 'regex_query';
		}

		$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '';
		$group = $group_names[ (int) $entry['group_id'] ] ?? '';

		return [
			'ok'        => true,
			'source_id' => $source_id,
			'rule'      => [
				'type'        => $entry['regex'] ? 'regex' : 'exact',
				'source'      => $entry['url'],
				'target'      => $target,
				'status_code' => $status,
				'enabled'     => $entry['enabled'],
				'note'        => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 255 ) : substr( $title, 0, 255 ),
				'origin'      => self::MODIFIED_POSTS_GROUP === $group ? 'auto' : 'manual',
			],
			'notes'     => $notes,
			'error'     => null,
		];
	}

	/**
	 * @param mixed $entry Raw entry.
	 */
	private static function is_valid_entry( $entry ): bool {
		return is_array( $entry )
			&& isset( $entry['url'] ) && is_string( $entry['url'] ) && '' !== trim( $entry['url'] )
			&& isset( $entry['regex'] ) && is_bool( $entry['regex'] )
			&& isset( $entry['action_type'] ) && is_string( $entry['action_type'] )
			&& isset( $entry['action_code'] ) && is_numeric( $entry['action_code'] )
			&& isset( $entry['action_data'] ) && is_array( $entry['action_data'] )
			&& isset( $entry['match_type'] ) && is_string( $entry['match_type'] )
			&& isset( $entry['enabled'] ) && is_bool( $entry['enabled'] )
			&& isset( $entry['group_id'] ) && is_numeric( $entry['group_id'] );
	}

	private static function skip( int $source_id, string $reason ): array {
		return [
			'ok'        => false,
			'source_id' => $source_id,
			'rule'      => null,
			'notes'     => [],
			'error'     => $reason,
		];
	}
}
