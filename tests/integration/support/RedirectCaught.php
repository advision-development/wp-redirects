<?php
/**
 * Thrown from the wp_redirect_status filter so tests can inspect a redirect without exit().
 */
final class Adv_Redirects_Redirect_Caught extends Exception {

	public string $location;

	public int $status;

	public function __construct( string $location, int $status ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
		$this->status   = $status;
	}
}
