<?php
/**
 * Records 404 responses.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class NotFoundLogger {

	private NotFoundRepository $repository;

	private Redirector $redirector;

	public function __construct( NotFoundRepository $repository, Redirector $redirector ) {
		$this->repository = $repository;
		$this->redirector = $redirector;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_log' ], 99 );
		add_action( 'adv_redirects_rule_created', [ $this, 'on_rule_created' ] );
	}

	public function maybe_log(): void {
		if ( ! is_404() || $this->redirector->is_gone_request() ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw values are normalized and cleaned in log_request().
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		// phpcs:enable
		$this->log_request( $uri, $method, $referrer );
	}

	public function log_request( string $uri, string $method, string $referrer ): bool {
		if ( ! Settings::get( 'log_404' ) || 'GET' !== strtoupper( $method ) ) {
			return false;
		}

		$request = Site::normalizer()->from_request_uri( $uri );
		if ( null === $request ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $request['path'], PATHINFO_EXTENSION ) );
		/**
		 * Filters file extensions that are never logged as 404s.
		 *
		 * @param string[] $extensions Lowercase, without dots.
		 */
		$excluded = (array) apply_filters( 'adv_redirects_404_excluded_extensions', Settings::get( 'excluded_404_extensions' ) );
		if ( '' !== $extension && in_array( $extension, $excluded, true ) ) {
			return false;
		}

		$path = self::clean( $request['path'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' ) );

		/**
		 * Filters whether a 404 is logged.
		 *
		 * @param bool   $log  Default true.
		 * @param string $path Path (and query) relative to the site home.
		 */
		if ( ! apply_filters( 'adv_redirects_log_404', true, $path ) ) {
			return false;
		}

		$this->repository->log( $path, self::clean( $referrer ), current_time( 'mysql', true ) );

		/**
		 * Fires after a 404 is logged.
		 *
		 * @param string $path
		 */
		do_action( 'adv_redirects_404_logged', $path );
		return true;
	}

	public function on_rule_created( Rule $rule ): void {
		if ( 'exact' === $rule->type ) {
			$this->repository->delete_matching_source( $rule->source );
		}
	}

	public static function clean( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return mb_substr( $value, 0, 2048 );
	}
}
