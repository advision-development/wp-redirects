<?php
/**
 * Follows a target through the rule set to find chains and loops.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\TargetResolver;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class ChainResolver {

	public const MAX_HOPS = 10;

	/**
	 * @param string      $source_label Shown as the first hop.
	 * @param string      $target       Where the rule points.
	 * @param array       $ruleset      Compiled rule set to follow.
	 * @param string|null $source_key   Normalized key of an exact source, treated as already visited.
	 * @param bool        $forward_query Whether the runtime forwards each request's query string to its target.
	 * @return array{loop:bool,hops:string[],final:string}
	 */
	public function resolve( string $source_label, string $target, array $ruleset, ?string $source_key = null, bool $forward_query = false ): array {
		$matcher    = new Matcher( $ruleset );
		$normalizer = new PathNormalizer( '' );
		$hops       = [ $source_label, $target ];
		$seen       = null === $source_key ? [] : [ $source_key => true ];
		$current    = $target;

		for ( $i = 0; $i < self::MAX_HOPS; $i++ ) {
			$internal = Site::internal_path( $current );
			if ( null === $internal ) {
				return self::result( false, $hops, $current );
			}
			$request = $normalizer->from_request_uri( $internal );
			if ( null === $request ) {
				return self::result( false, $hops, $current );
			}

			// The redirector never handles these paths, so the walk ends here and cannot loop.
			if ( Site::is_unhandled_request( $request ) ) {
				return self::result( false, $hops, $current );
			}

			$match = $matcher->match( $request );
			if ( null === $match || null === $match->target ) {
				return self::result( false, $hops, $current );
			}
			$next = TargetResolver::substitute( $match->target, $match->captures, true );
			if ( $forward_query && '' !== $request['query'] ) {
				$next = TargetResolver::merge_query( $next, $request['query'] );
			}

			// The redirector never redirects a URL to itself, so the chain ends here and is not a loop.
			// Same check as the runtime: the hop made absolute the way TargetResolver::resolve() does,
			// compared with the exact home URL (scheme, host case and port included).
			if ( TargetResolver::is_self( self::absolute( $next ), Site::home_url(), $request['path'], $request['query'] ) ) {
				return self::result( false, $hops, $current );
			}

			$key = $request['key'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' );
			if ( isset( $seen[ $key ] ) ) {
				return self::result( true, $hops, $current );
			}
			$seen[ $key ] = true;

			$current = $next;
			$hops[]  = $current;
		}

		return self::result( true, $hops, $current );
	}

	/**
	 * A hop as the runtime sends it: a relative target gets the home URL in front.
	 */
	private static function absolute( string $url ): string {
		return '' !== $url && '/' === $url[0] && 0 !== strpos( $url, '//' ) ? Site::home_url() . $url : $url;
	}

	private static function result( bool $loop, array $hops, string $final_url ): array {
		return [
			'loop'  => $loop,
			'hops'  => $hops,
			'final' => $final_url,
		];
	}
}
