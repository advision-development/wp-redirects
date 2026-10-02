<?php
/**
 * REST endpoints for redirect rules.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Matching\TargetResolver;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Settings;

defined( 'ABSPATH' ) || exit;

final class RedirectsController extends BaseController {

	private const FIELDS = [ 'type', 'source', 'target', 'status_code', 'enabled', 'note' ];

	private Repository $repository;
	private Validator $validator;
	private RuleCache $cache;
	private ChainResolver $chains;

	public function __construct( Repository $repository, Validator $validator, RuleCache $cache, ChainResolver $chains ) {
		$this->repository = $repository;
		$this->validator  = $validator;
		$this->cache      = $cache;
		$this->chains     = $chains;
	}

	public function register_routes(): void {
		$id_arg = [
			'id' => self::arg(
				[
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				]
			),
		];

		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $this->rule_args( true ),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $id_arg + $this->rule_args( false ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $id_arg,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'action' => self::arg(
						[
							'type'     => 'string',
							'enum'     => [ 'enable', 'disable', 'delete' ],
							'required' => true,
						]
					),
					'ids'    => self::ids_arg(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [ 'ids' => self::ids_arg() ],
			]
		);
	}

	public function list_items(): \WP_REST_Response {
		$ruleset = $this->cache->get();
		$items   = array_map(
			function ( Rule $rule ) use ( $ruleset ): array {
				return $this->prepare( $rule, $ruleset );
			},
			$this->repository->all()
		);
		return rest_ensure_response( $items );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, self::FIELDS );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$result = $this->validator->validate( $this->input( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rule = $this->repository->insert( $result['data'] );
		if ( null === $rule ) {
			return self::db_error();
		}

		$response = rest_ensure_response(
			[
				'rule'     => $this->prepare( $rule, $this->cache->get() ),
				'warnings' => $result['warnings'],
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, self::FIELDS );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$id     = (int) $request->get_url_params()['id'];
		$result = $this->validator->validate( $this->input( $request ), $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rule = $this->repository->update( $id, $result['data'] );
		if ( null === $rule ) {
			return self::db_error();
		}

		return rest_ensure_response(
			[
				'rule'     => $this->prepare( $rule, $this->cache->get() ),
				'warnings' => $result['warnings'],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		$rule = $this->repository->find( (int) $request->get_url_params()['id'] );
		if ( null === $rule || ! $this->repository->delete( $rule->id ) ) {
			return new \WP_Error( 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response(
			[
				'deleted' => true,
				'rule'    => $rule->to_array(),
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'action', 'ids' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$action  = (string) $request['action'];
		$ids     = array_values( array_unique( array_map( 'intval', (array) $request['ids'] ) ) );
		$updated = 0;
		$skipped = [];

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				if ( $this->repository->delete( $id ) ) {
					++$updated;
				} else {
					$skipped[] = self::skip( $id, 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ) );
				}
				continue;
			}

			$enable = 'enable' === $action;
			if ( $enable ) {
				$valid = $this->validator->validate( [ 'enabled' => true ], $id );
				if ( is_wp_error( $valid ) ) {
					$skipped[] = self::skip( $id, (string) $valid->get_error_code(), $valid->get_error_message() );
					continue;
				}
			}
			if ( null !== $this->repository->update( $id, [ 'enabled' => $enable ] ) ) {
				++$updated;
			} else {
				$skipped[] = self::skip( $id, 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ) );
			}
		}

		return rest_ensure_response(
			[
				'updated' => $updated,
				'skipped' => $skipped,
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reorder( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'ids' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$ids = array_map( 'intval', (array) $request['ids'] );
		$this->repository->reorder( $ids );
		return rest_ensure_response( [ 'reordered' => count( $ids ) ] );
	}

	private function input( \WP_REST_Request $request ): array {
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : $request->get_body_params();
		$input = [];
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, (array) $body ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}
		return $input;
	}

	private function prepare( Rule $rule, array $ruleset ): array {
		$data          = $rule->to_array();
		$data['chain'] = null;

		$skip = null === $rule->target || ( 'regex' === $rule->type && preg_match( '/\$[1-9]/', $rule->target ) );
		if ( $rule->enabled && ! $skip ) {
			$forward = (bool) Settings::get( 'forward_query_string' );
			$start   = $rule->target;
			if ( $forward && 'exact' === $rule->type && false !== strpos( $rule->source, '?' ) ) {
				// The runtime appends the request's query to the target, so walk from the merged URL.
				$start = TargetResolver::merge_query( $rule->target, explode( '?', $rule->source, 2 )[1] );
			}
			$chain = $this->chains->resolve(
				$rule->source,
				$start,
				$ruleset,
				'exact' === $rule->type ? PathNormalizer::source_key( $rule->source ) : null,
				$forward
			);
			if ( $chain['loop'] || count( $chain['hops'] ) > 2 ) {
				$data['chain'] = $chain;
			}
		}
		return $data;
	}

	private function rule_args( bool $create ): array {
		$args = [
			'type'        => [
				'type' => 'string',
				'enum' => Validator::TYPES,
			],
			'source'      => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2048,
			],
			'target'      => [
				'type'      => [ 'string', 'null' ],
				'maxLength' => 2048,
			],
			'status_code' => [
				'type' => 'integer',
				'enum' => Validator::STATUSES,
			],
			'enabled'     => [ 'type' => 'boolean' ],
			'note'        => [
				'type'      => 'string',
				'maxLength' => 255,
			],
		];
		if ( $create ) {
			$args['type']['required']        = true;
			$args['source']['required']      = true;
			$args['status_code']['required'] = true;
		}
		return array_map( [ self::class, 'arg' ], $args );
	}

	private static function ids_arg(): array {
		return self::arg(
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
		);
	}

	private static function skip( int $id, string $code, string $message ): array {
		return [
			'id'      => $id,
			'code'    => $code,
			'message' => $message,
		];
	}

	private static function db_error(): \WP_Error {
		return new \WP_Error( 'adv_redirects_db_error', __( 'The redirect could not be saved.', 'wp-redirects' ), [ 'status' => 500 ] );
	}
}
