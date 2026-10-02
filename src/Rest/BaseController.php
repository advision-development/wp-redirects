<?php
/**
 * Shared REST controller behavior.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Permissions;

defined( 'ABSPATH' ) || exit;

abstract class BaseController {

	public const NAMESPACE = 'adv-redirects/v1';

	abstract public function register_routes(): void;

	/**
	 * @return true|\WP_Error
	 */
	public function permission_check() {
		if ( Permissions::can_manage() ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirects' ),
			[ 'status' => is_user_logged_in() ? 403 : 401 ]
		);
	}

	/**
	 * Rejects body fields that are not in the allowlist.
	 *
	 * @param string[] $allowed Allowed body field names.
	 */
	protected function reject_unknown( \WP_REST_Request $request, array $allowed ): ?\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$unknown = array_diff( array_keys( (array) $body ), $allowed );
		if ( empty( $unknown ) ) {
			return null;
		}
		return new \WP_Error(
			'adv_redirects_unknown_field',
			/* translators: %s: comma-separated field names */
			sprintf( __( 'Unknown field(s): %s', 'wp-redirects' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Adds core schema validation and sanitization to an argument definition.
	 */
	public static function arg( array $schema ): array {
		return $schema + [
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		];
	}
}
