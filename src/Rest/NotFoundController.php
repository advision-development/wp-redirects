<?php
/**
 * REST endpoints for the 404 log.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Tracking\NotFoundRepository;

defined( 'ABSPATH' ) || exit;

final class NotFoundController extends BaseController {

	private NotFoundRepository $repository;

	public function __construct( NotFoundRepository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/404s',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'page'     => self::arg(
							[
								'type'    => 'integer',
								'minimum' => 1,
								'default' => 1,
							]
						),
						'per_page' => self::arg(
							[
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 20,
							]
						),
						'search'   => self::arg(
							[
								'type'      => 'string',
								'maxLength' => 200,
								'default'   => '',
							]
						),
						'orderby'  => self::arg(
							[
								'type'    => 'string',
								'enum'    => [ 'hits', 'last_seen', 'path' ],
								'default' => 'hits',
							]
						),
						'order'    => self::arg(
							[
								'type'    => 'string',
								'enum'    => [ 'asc', 'desc' ],
								'default' => 'desc',
							]
						),
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/404s/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'id' => self::arg(
						[
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						]
					),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/404s/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'action' => self::arg(
						[
							'type'     => 'string',
							'enum'     => [ 'delete' ],
							'required' => true,
						]
					),
					'ids'    => self::arg(
						[
							'type'     => 'array',
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'minItems' => 1,
							'maxItems' => 500,
							'required' => true,
						]
					),
				],
			]
		);
	}

	public function list_items( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = $this->repository->query(
			[
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
				'search'   => (string) $request['search'],
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
			]
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		if ( ! $this->repository->delete( (int) $request->get_url_params()['id'] ) ) {
			return new \WP_Error( 'adv_redirects_not_found', __( 'Log entry not found.', 'wp-redirects' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'action', 'ids' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( [ 'deleted' => $this->repository->delete_many( (array) $request['ids'] ) ] );
	}

	public function clear(): \WP_REST_Response {
		return rest_ensure_response( [ 'deleted' => $this->repository->clear() ] );
	}
}
