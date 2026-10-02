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
		if ( '' === $path ) {
			return false;
		}

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
		// Scrub invalid UTF-8 first: preg_replace() with /u returns null on it, which would blank the whole value.
		$value = self::scrub_utf8( $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return mb_substr( $value, 0, 2048 );
	}

	/**
	 * Replaces every byte that is not part of a valid UTF-8 sequence with U+FFFD.
	 *
	 * Not wp_check_invalid_utf8( $value, true ): before WP 6.9 that runs iconv(), which returns false on
	 * invalid input, so the whole value would be blanked.
	 */
	private static function scrub_utf8( string $value ): string {
		if ( preg_match( '//u', $value ) ) {
			return $value;
		}
		return (string) preg_replace_callback(
			'/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
			static function ( array $groups ): string {
				return isset( $groups[1] ) ? "\u{FFFD}" : $groups[0];
			},
			$value
		);
	}
}
