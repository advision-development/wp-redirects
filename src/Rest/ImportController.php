<?php
/**
 * REST endpoints for importing Redirection exports.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Import\Importer;

defined( 'ABSPATH' ) || exit;

final class ImportController extends BaseController {

	public const MAX_PREVIEW = 2000;

	public const MAX_BATCH = 50;

	private Importer $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/preview',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => $this->args( self::MAX_PREVIEW, true ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => $this->args( self::MAX_BATCH, false ),
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'source', 'version', 'groups', 'redirects' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->preview( (array) $request['redirects'], (array) $request['groups'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'source', 'groups', 'redirects' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->import( (array) $request['redirects'], (array) $request['groups'] ) );
	}

	private function args( int $max, bool $with_version ): array {
		$args = [
			'source'    => self::arg(
				[
					'type'     => 'string',
					'enum'     => [ 'redirection' ],
					'required' => true,
				]
			),
			'groups'    => self::arg(
				[
					'type'     => 'array',
					'items'    => [ 'type' => 'object' ],
					'maxItems' => 1000,
					'default'  => [],
				]
			),
			'redirects' => self::arg(
				[
					'type'     => 'array',
					'items'    => [ 'type' => 'object' ],
					'minItems' => 1,
					'maxItems' => $max,
					'required' => true,
				]
			),
		];
		if ( $with_version ) {
			$args['version'] = self::arg(
				[
					'type'      => 'string',
					'maxLength' => 50,
				]
			);
		}
		return $args;
	}
}
