<?php
/**
 * REST endpoints for the Yoast SEO Premium side of the Yoast import.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Import\YoastSource;

defined( 'ABSPATH' ) || exit;

final class YoastImportController extends BaseController {

	public const MAX_REMOVE = 500;

	private YoastSource $yoast;

	public function __construct( YoastSource $yoast ) {
		$this->yoast = $yoast;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/yoast',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/remove',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'remove' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'entries' => self::arg(
						[
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => self::MAX_REMOVE,
							'items'    => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'origin' => [
										'type'      => 'string',
										'minLength' => 1,
										'maxLength' => 2048,
										'required'  => true,
									],
									'format' => [
										'type'     => 'string',
										'enum'     => [ 'plain', 'regex' ],
										'required' => true,
									],
								],
							],
						]
					),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restore' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/backup',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_backup' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);
	}

	public function status(): \WP_REST_Response {
		return rest_ensure_response( $this->yoast->status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'entries' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->yoast->remove( (array) $request['entries'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->yoast->restore() );
	}

	public function delete_backup(): \WP_REST_Response {
		$this->yoast->delete_backup();
		return rest_ensure_response( [ 'deleted' => true ] );
	}
}
