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
	 * A group is disabled when its `status` is "disabled" or its `enabled` flag is false; Redirection
	 * skips every rule in such a group.
	 *
	 * @param array $groups Raw `groups` entries from the export.
	 * @return array<int,array{name:string,disabled:bool}> Group id => name and disabled flag.
	 */
	public static function group_info( array $groups ): array {
		$info = [];
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['id'], $group['name'] ) && self::is_int_like( $group['id'] ) && is_string( $group['name'] ) ) {
				$info[ (int) $group['id'] ] = [
					'name'     => $group['name'],
					'disabled' => ( isset( $group['status'] ) && 'disabled' === $group['status'] )
						|| ( isset( $group['enabled'] ) && false === $group['enabled'] ),
				];
			}
		}
		return $info;
	}

	/**
	 * @param mixed $entry  One raw entry from the export's `redirects` list.
	 * @param array $groups From group_info().
	 * @return array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}
	 */
	public static function map( $entry, array $groups ): array {
		$source_id = is_array( $entry ) && isset( $entry['id'] ) && self::is_int_like( $entry['id'] ) ? (int) $entry['id'] : 0;

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

		$group    = $groups[ (int) $entry['group_id'] ] ?? [];
		$name     = $group['name'] ?? '';
		$disabled = ! empty( $group['disabled'] );
		if ( $disabled ) {
			$notes[] = 'group_disabled';
		}

		$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '';

		return [
			'ok'        => true,
			'source_id' => $source_id,
			'rule'      => [
				'type'        => $entry['regex'] ? 'regex' : 'exact',
				'source'      => $entry['url'],
				'target'      => $target,
				'status_code' => $status,
				'enabled'     => $entry['enabled'] && ! $disabled,
				'note'        => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 255 ) : substr( $title, 0, 255 ),
				'origin'      => self::MODIFIED_POSTS_GROUP === $name ? 'auto' : 'manual',
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
			&& isset( $entry['action_code'] ) && self::is_int_like( $entry['action_code'] )
			&& array_key_exists( 'action_data', $entry ) && ( null === $entry['action_data'] || is_array( $entry['action_data'] ) )
			&& isset( $entry['match_type'] ) && is_string( $entry['match_type'] )
			&& isset( $entry['enabled'] ) && is_bool( $entry['enabled'] )
			&& isset( $entry['group_id'] ) && self::is_int_like( $entry['group_id'] );
	}

	/**
	 * Accepts ints and digit-only strings, not floats, exponents or padded strings.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_int_like( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) );
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
