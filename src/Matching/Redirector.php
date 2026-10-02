<?php
/**
 * Request-time matching and response.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;
use Advision\Redirects\Tracking\HitTracker;

defined( 'ABSPATH' ) || exit;

final class Redirector {

	private RuleCache $cache;

	private HitTracker $hits;

	private ?array $gone = null;

	/** @var array<int,bool> */
	private array $logged_errors = [];

	public function __construct( RuleCache $cache, HitTracker $hits ) {
		$this->cache = $cache;
		$this->hits  = $hits;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_redirect' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_send_gone' ], 0 );
	}

	public function maybe_redirect(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The raw URI is required; PathNormalizer validates it and rejects control characters.
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$decision = $this->decide( $uri, $method );
		if ( null === $decision ) {
			return;
		}

		$this->hits->record( (int) $decision['rule']['id'] );

		if ( null === $decision['url'] ) {
			$this->gone = $decision;
			return;
		}

		/**
		 * Fires immediately before a redirect response is sent.
		 *
		 * @param array  $rule   { id, type, target, status }.
		 * @param string $url    Final absolute URL.
		 * @param int    $status HTTP status code.
		 */
		do_action( 'adv_redirects_before_redirect', $decision['rule'], $decision['url'], $decision['status'] );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External targets are a feature; final URL re-validated by UrlSafety (and the TargetResolver host guard for rule targets).
		if ( wp_redirect( $decision['url'], $decision['status'], 'WP Redirects' ) ) {
			exit;
		}
	}

	/**
	 * @return array{rule:array,status:int,url:?string}|null
	 */
	public function decide( string $uri, string $method ): ?array {
		if ( $this->is_excluded_context() ) {
			return null;
		}

		/**
		 * Filters which HTTP methods are redirected.
		 *
		 * @param string[] $methods Default [ 'GET', 'HEAD' ].
		 */
		$methods = array_map( 'strtoupper', array_filter( (array) apply_filters( 'adv_redirects_allowed_methods', [ 'GET', 'HEAD' ] ), 'is_string' ) );
		if ( ! in_array( strtoupper( $method ), $methods, true ) ) {
			return null;
		}

		$request = Site::normalizer()->from_request_uri( $uri );
		if ( null === $request ) {
			return null;
		}

		// Plain-permalink REST requests (REST_REQUEST is not defined yet at init priority 1) and reserved paths.
		// Checked before the path filter, so a filter cannot map a reserved path to a non-reserved one.
		if ( Site::is_unhandled_request( $request ) ) {
			return null;
		}

		/**
		 * Filters whether this request should be matched at all.
		 *
		 * @param bool   $handle Default true.
		 * @param string $path   Normalized, decoded request path.
		 */
		if ( ! apply_filters( 'adv_redirects_should_handle_request', true, $request['path'] ) ) {
			return null;
		}

		/**
		 * Filters the normalized request path before matching.
		 *
		 * @param string $path Decoded path relative to the site home, starting with "/".
		 */
		$path = apply_filters( 'adv_redirects_request_path', $request['path'] );
		if ( ! is_string( $path ) ) {
			return null;
		}
		if ( $path !== $request['path'] ) {
			if ( '' === $path || '/' !== $path[0] ) {
				return null;
			}
			$request['path'] = $path;
			$request['key']  = PathNormalizer::key( $path );
		}
		if ( Site::is_reserved_path( $request['path'] ) ) {
			return null;
		}

		$ruleset = $this->cache->get();
		if ( empty( $ruleset['exact'] ) && empty( $ruleset['regex'] ) ) {
			return null;
		}

		$match = ( new Matcher( $ruleset, [ $this, 'log_regex_error' ] ) )->match( $request );

		/**
		 * Filters the match for this request. Return null to suppress, or a MatchResult to override.
		 *
		 * @param MatchResult|null $match
		 * @param string           $path
		 * @param string           $query Raw query string without "?".
		 */
		$match = apply_filters( 'adv_redirects_match', $match, $request['path'], $request['query'] );
		if ( ! $match instanceof MatchResult ) {
			return null;
		}

		$rule = [
			'id'     => $match->rule_id,
			'type'   => $match->type,
			'target' => $match->target,
			'status' => $match->status,
		];

		/**
		 * Filters the response status code. Values outside the supported set cancel the redirect.
		 *
		 * @param int   $status
		 * @param array $rule
		 */
		$status = (int) apply_filters( 'adv_redirects_status_code', $match->status, $rule );
		if ( ! in_array( $status, Validator::STATUSES, true ) ) {
			return null;
		}
		if ( $status >= 400 ) {
			return [
				'rule'   => $rule,
				'status' => $status,
				'url'    => null,
			];
		}
		if ( null === $match->target ) {
			return null;
		}

		/**
		 * Filters whether the incoming query string is forwarded to the target.
		 *
		 * @param bool  $forward Setting value.
		 * @param array $rule
		 */
		$forward = (bool) apply_filters( 'adv_redirects_forward_query_string', (bool) Settings::get( 'forward_query_string' ), $rule );

		$url = Site::resolver()->resolve( $match->target, $match->captures, $request['query'], $forward );
		if ( null === $url ) {
			return null;
		}

		/**
		 * Filters the final redirect URL. Unsafe values cancel the redirect.
		 *
		 * @param string $url
		 * @param array  $rule
		 * @param string $path
		 */
		$url = apply_filters( 'adv_redirects_target_url', $url, $rule, $request['path'] );
		if ( ! is_string( $url ) || ! UrlSafety::is_safe( $url ) ) {
			return null;
		}

		return [
			'rule'   => $rule,
			'status' => $status,
			'url'    => $url,
		];
	}

	public function maybe_send_gone(): void {
		if ( null === $this->gone ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		// Stop core from "guessing" a similar permalink and redirecting a 410/451 away.
		remove_action( 'template_redirect', 'redirect_canonical' );
		status_header( (int) $this->gone['status'] );
		nocache_headers();
	}

	public function is_gone_request(): bool {
		return null !== $this->gone;
	}

	public function log_regex_error( int $rule_id, int $code ): void {
		if ( isset( $this->logged_errors[ $rule_id ] ) ) {
			return;
		}
		$this->logged_errors[ $rule_id ] = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational signal for a broken regex rule; the request path is not logged.
		error_log( sprintf( 'WP Redirects: regex rule #%d skipped (PCRE error %d).', $rule_id, $code ) );
	}

	private function is_excluded_context(): bool {
		return is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] );
	}
}
