<?php
/**
 * Redirect rule value object.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

defined( 'ABSPATH' ) || exit;

final class Rule {

	public int $id              = 0;
	public string $type         = 'exact';
	public string $source       = '';
	public ?string $target      = null;
	public int $status_code     = 301;
	public int $position        = 0;
	public bool $enabled        = true;
	public bool $trailing_slash = false;
	public string $origin       = 'manual';
	public ?int $created_by     = null;
	public string $created_via  = 'manual';
	public ?int $updated_by     = null;
	public string $note         = '';
	public int $hits            = 0;
	public ?string $last_hit_at = null;
	public string $created_at   = '';
	public string $updated_at   = '';

	public static function from_row( array $row ): Rule {
		$rule                 = new self();
		$rule->id             = (int) $row['id'];
		$rule->type           = (string) $row['type'];
		$rule->source         = (string) $row['source'];
		$rule->target         = null === $row['target'] || '' === $row['target'] ? null : (string) $row['target'];
		$rule->status_code    = (int) $row['status_code'];
		$rule->position       = (int) $row['position'];
		$rule->enabled        = (bool) (int) $row['enabled'];
		$rule->trailing_slash = ! empty( $row['trailing_slash'] );
		$rule->origin         = (string) $row['origin'];
		$rule->created_by     = empty( $row['created_by'] ) ? null : (int) $row['created_by'];
		$rule->created_via    = isset( $row['created_via'] ) && '' !== $row['created_via'] ? (string) $row['created_via'] : 'manual';
		$rule->updated_by     = empty( $row['updated_by'] ) ? null : (int) $row['updated_by'];
		$rule->note           = (string) $row['note'];
		$rule->hits           = (int) $row['hits'];
		$rule->last_hit_at    = empty( $row['last_hit_at'] ) ? null : (string) $row['last_hit_at'];
		$rule->created_at     = (string) $row['created_at'];
		$rule->updated_at     = (string) $row['updated_at'];
		return $rule;
	}

	public function to_array(): array {
		return [
			'id'             => $this->id,
			'type'           => $this->type,
			'source'         => $this->source,
			'target'         => $this->target,
			'status_code'    => $this->status_code,
			'position'       => $this->position,
			'enabled'        => $this->enabled,
			'trailing_slash' => $this->trailing_slash,
			'origin'         => $this->origin,
			'created_by'     => $this->created_by,
			'created_via'    => $this->created_via,
			'updated_by'     => $this->updated_by,
			'note'           => $this->note,
			'hits'           => $this->hits,
			'last_hit_at'    => $this->last_hit_at,
			'created_at'     => $this->created_at,
			'updated_at'     => $this->updated_at,
		];
	}
}
