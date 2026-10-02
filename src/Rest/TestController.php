<?php
/**
 * REST endpoint for the Test URL tool. Uses the same Matcher and resolver as real requests.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class TestController extends BaseController {

	private RuleCache $cache;

	private ChainResolver $chains;

	public function __construct( RuleCache $cache, ChainResolver $chains ) {
		$this->cache  = $cache;
		$this->chains = $chains;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test_url' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'path' => self::arg(
						[
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2048,
							'required'  => true,
						]
					),
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_url( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'path' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$input    = trim( (string) $request['path'] );
		$internal = Site::internal_path( $input );
		if ( null === $internal ) {
			return rest_ensure_response( self::result( [ 'reason' => 'external' ] ) );
		}

		$normalized = ( new PathNormalizer( '' ) )->from_request_uri( $internal );
		if ( null === $normalized ) {
			return new \WP_Error( 'adv_redirects_invalid_path', __( 'That path is not valid.', 'wp-redirects' ), [ 'status' => 400 ] );
		}

		$ruleset = $this->cache->get();
		$match   = ( new Matcher( $ruleset ) )->match( $normalized );
		if ( null === $match ) {
			return rest_ensure_response( self::result( [ 'reason' => 'no_match' ] ) );
		}

		$base = [
			'matched' => true,
			'reason'  => null,
			'rule_id' => $match->rule_id,
			'type'    => $match->type,
			'status'  => $match->status,
			'hops'    => [ $input ],
		];
		if ( null === $match->target ) {
			return rest_ensure_response( self::result( $base ) );
		}

		$forward = (bool) Settings::get( 'forward_query_string' );
		$url     = Site::resolver()->resolve( $match->target, $match->captures, $normalized['query'], $forward );
		if ( null === $url ) {
			return rest_ensure_response( self::result( $base + [ 'blocked' => true ] ) );
		}

		$key   = $normalized['key'] . ( '' !== $normalized['query'] ? '?' . $normalized['query'] : '' );
		$chain = $this->chains->resolve( $input, $url, $ruleset, $key, $forward );

		return rest_ensure_response(
			self::result(
				[
					'target_url' => $url,
					'hops'       => $chain['hops'],
					'final'      => $chain['final'],
					'loop'       => $chain['loop'],
				] + $base
			)
		);
	}

	private static function result( array $values ): array {
		return array_merge(
			[
				'matched'    => false,
				'reason'     => null,
				'rule_id'    => null,
				'type'       => null,
				'status'     => null,
				'target_url' => null,
				'blocked'    => false,
				'hops'       => [],
				'final'      => null,
				'loop'       => false,
			],
			$values
		);
	}
}
