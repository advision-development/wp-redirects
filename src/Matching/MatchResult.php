<?php
/**
 * Result of matching a request against the compiled rule set.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class MatchResult {

	public int $rule_id;

	public string $type;

	public int $status;

	public ?string $target;

	/** @var array<int,string> preg captures; index 0 is the full match. */
	public array $captures;

	public function __construct( int $rule_id, string $type, int $status, ?string $target, array $captures = [] ) {
		$this->rule_id  = $rule_id;
		$this->type     = $type;
		$this->status   = $status;
		$this->target   = $target;
		$this->captures = $captures;
	}
}
