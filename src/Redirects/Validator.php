<?php
/**
 * Validates and normalizes rule input before it reaches the Repository.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\Pattern;
use Advision\Redirects\Matching\RulesetCompiler;
use Advision\Redirects\Matching\TargetResolver;
use Advision\Redirects\Matching\UrlSafety;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class Validator {

	public const STATUSES = [ 301, 302, 307, 308, 410, 451 ];

	public const TYPES = [ 'exact', 'regex' ];

	private Repository $repository;

	private ChainResolver $chains;

	public function __construct( Repository $repository, ChainResolver $chains ) {
		$this->repository = $repository;
		$this->chains     = $chains;
	}

	/**
	 * @param array    $input   Raw fields (type, source, target, status_code, enabled, note). Missing fields keep existing values on update.
	 * @param int|null $id      Rule being updated, or null for a new rule.
	 * @param array    $pending Rows not yet saved (e.g. earlier rules in an import), shaped like Repository::enabled_rows().
	 *                          A pending row with an existing rule's id replaces that rule in the loop/chain walk.
	 * @return array{data:array,warnings:array}|\WP_Error
	 */
	public function validate( array $input, ?int $id = null, array $pending = [] ) {
		$existing = null;
		if ( null !== $id ) {
			$existing = $this->repository->find( $id );
			if ( null === $existing ) {
				return self::error( 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ), 404 );
			}
		}

		$base = null !== $existing ? $existing->to_array() : [
			'type'        => 'exact',
			'source'      => '',
			'target'      => null,
			'status_code' => 301,
			'enabled'     => true,
			'note'        => '',
		];

		$type    = (string) ( $input['type'] ?? $base['type'] );
		$status  = (int) ( $input['status_code'] ?? $base['status_code'] );
		$source  = trim( (string) ( $input['source'] ?? $base['source'] ) );
		$target  = array_key_exists( 'target', $input ) ? $input['target'] : $base['target'];
		$target  = null === $target ? '' : trim( (string) $target );
		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : (bool) $base['enabled'];
		$note    = mb_substr( sanitize_text_field( (string) ( $input['note'] ?? $base['note'] ) ), 0, 255 );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::error( 'adv_redirects_invalid_type', __( 'Type must be "exact" or "regex".', 'wp-redirects' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::error( 'adv_redirects_invalid_status', __( 'Choose a supported status code.', 'wp-redirects' ) );
		}

		$source = $this->check_source( $type, $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( in_array( $status, [ 410, 451 ], true ) ) {
			if ( '' !== $target ) {
				return self::error( 'adv_redirects_invalid_target', __( '410 and 451 responses cannot have a target.', 'wp-redirects' ) );
			}
			$target = null;
		} else {
			$checked = $this->check_target( $target );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			// Only the regex runtime substitutes captures, so an exact rule would send "$1" literally.
			if ( 'exact' === $type && preg_match( '/\$[1-9]/', $target ) ) {
				return self::error( 'adv_redirects_invalid_target', __( 'Capture references ($1-$9) only work in regex redirects.', 'wp-redirects' ) );
			}
		}

		if ( 'exact' === $type ) {
			$duplicate = $this->repository->exact_rule_by_key( PathNormalizer::source_key( $source ), (int) $id );
			if ( null !== $duplicate ) {
				return self::error(
					'adv_redirects_duplicate',
					/* translators: %s: source path */
					sprintf( __( 'A redirect for %s already exists.', 'wp-redirects' ), $duplicate->source ),
					409,
					[ 'existing_id' => $duplicate->id ]
				);
			}
			if ( null !== $target ) {
				$internal = Site::internal_path( $target );
				if ( null !== $internal && PathNormalizer::source_key( $internal ) === PathNormalizer::source_key( $source ) ) {
					return self::error( 'adv_redirects_invalid_target', __( 'The target is the same as the source.', 'wp-redirects' ) );
				}
			}
		}

		$data = [
			'type'        => $type,
			'source'      => $source,
			'target'      => $target,
			'status_code' => $status,
			'enabled'     => $enabled,
			'note'        => $note,
		];

		/**
		 * Filters rule validation. Return a WP_Error to reject the rule.
		 *
		 * @param true|\WP_Error $valid True when valid so far.
		 * @param array          $data  Normalized rule data.
		 * @param int|null       $id    Rule ID on update, null on create.
		 */
		$custom = apply_filters( 'adv_redirects_validate_rule', true, $data, $id );
		if ( is_wp_error( $custom ) ) {
			return $custom;
		}

		$warnings = [];
		if ( $enabled && null !== $target && ! ( 'regex' === $type && preg_match( '/\$[1-9]/', $target ) ) ) {
			$forward = (bool) Settings::get( 'forward_query_string' );
			$start   = $target;
			if ( $forward && 'exact' === $type && false !== strpos( $source, '?' ) ) {
				// The runtime appends the request's query to the target, so walk from the merged URL.
				$start = TargetResolver::merge_query( $target, explode( '?', $source, 2 )[1] );
			}
			$chain = $this->chains->resolve(
				$source,
				$start,
				$this->ruleset_with( $data, $id, $existing, $pending ),
				'exact' === $type ? PathNormalizer::source_key( $source ) : null,
				$forward
			);
			if ( $chain['loop'] ) {
				return self::error(
					'adv_redirects_loop',
					/* translators: %s: redirect path, e.g. "/a → /b → /a" */
					sprintf( __( 'Creates a loop: %s', 'wp-redirects' ), implode( ' → ', $chain['hops'] ) ),
					422,
					[ 'hops' => $chain['hops'] ]
				);
			}
			if ( count( $chain['hops'] ) > 2 ) {
				$warnings[] = [
					'code'  => 'chain',
					'hops'  => $chain['hops'],
					'final' => $chain['final'],
				];
			}
		}

		return [
			'data'     => $data,
			'warnings' => $warnings,
		];
	}

	/**
	 * @return string|\WP_Error Normalized source.
	 */
	private function check_source( string $type, string $source ) {
		if ( '' === $source || strlen( $source ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $source ) ) {
			return self::error( 'adv_redirects_invalid_source', __( 'Enter a valid source.', 'wp-redirects' ) );
		}

		if ( 'regex' === $type ) {
			if ( ! Pattern::is_valid( $source ) ) {
				return self::error(
					'adv_redirects_invalid_regex',
					/* translators: %d: maximum pattern length */
					sprintf( __( 'The pattern is not a valid regular expression (maximum %d characters).', 'wp-redirects' ), Pattern::MAX_LENGTH )
				);
			}
			return $source;
		}

		$path = $this->exact_source_path( $source );
		if ( null === $path ) {
			return self::error( 'adv_redirects_invalid_source', __( 'Source must be a path starting with "/" or a URL on this site.', 'wp-redirects' ) );
		}

		$path_only = explode( '?', $path, 2 )[0];
		if ( Site::is_reserved_path( rawurldecode( $path_only ) ) ) {
			return self::error( 'adv_redirects_reserved_source', __( 'This path is used by WordPress itself and cannot be redirected.', 'wp-redirects' ) );
		}
		return $path;
	}

	private function exact_source_path( string $source ): ?string {
		if ( '/' === $source[0] ) {
			return 0 === strpos( $source, '//' ) ? null : $source;
		}
		if ( ! preg_match( '#^https?://#i', $source ) ) {
			return null;
		}
		return Site::internal_path( $source );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function check_target( string $target ) {
		if ( '' === $target ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Enter a target.', 'wp-redirects' ) );
		}
		if ( ! UrlSafety::is_safe( $target ) || esc_url_raw( $target, [ 'http', 'https' ] ) !== $target ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Target must be a path starting with "/" or an http(s) URL.', 'wp-redirects' ) );
		}

		// A capture may directly follow the host (domain migration), but not sit inside it.
		if ( preg_match( '#^https?://[^/?\#]*\$[1-9][^/?\#]#i', $target ) ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Capture references ($1-$9) cannot be used in the host.', 'wp-redirects' ) );
		}

		$host = TargetResolver::expected_host( $target, Site::host() );
		if ( '' === $host ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Capture references ($1-$9) cannot be used in the host.', 'wp-redirects' ) );
		}

		/** This filter is documented in src/Site.php */
		$allowed = array_map( 'strtolower', (array) apply_filters( 'adv_redirects_allowed_target_hosts', [] ) );
		if ( $host !== Site::host() && ! empty( $allowed ) && ! in_array( $host, $allowed, true ) ) {
			return self::error( 'adv_redirects_invalid_target', __( 'That host is not on the allowed list.', 'wp-redirects' ) );
		}
		return true;
	}

	private function ruleset_with( array $data, ?int $id, ?Rule $existing, array $pending = [] ): array {
		$replaced = [];
		foreach ( $pending as $row ) {
			$replaced[ (int) $row['id'] ] = true;
		}

		$rows = array_values(
			array_filter(
				$this->repository->enabled_rows(),
				static function ( array $row ) use ( $id, $replaced ): bool {
					return (int) $row['id'] !== (int) $id && ! isset( $replaced[ (int) $row['id'] ] );
				}
			)
		);

		foreach ( $pending as $row ) {
			if ( (int) $row['id'] !== (int) $id ) {
				$rows[] = $row;
			}
		}

		$rows[] = [
			'id'          => (int) $id,
			'type'        => $data['type'],
			'source'      => $data['source'],
			'target'      => $data['target'],
			'status_code' => $data['status_code'],
			'position'    => null !== $existing && 'regex' === $existing->type ? $existing->position : PHP_INT_MAX,
		];

		return RulesetCompiler::compile( $rows );
	}

	private static function error( string $code, string $message, int $status = 422, array $extra = [] ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] + $extra );
	}
}
