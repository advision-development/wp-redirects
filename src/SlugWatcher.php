<?php
/**
 * Creates 301 redirects when a published post's permalink changes.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

defined( 'ABSPATH' ) || exit;

final class SlugWatcher {

	private Repository $repository;

	private Validator $validator;

	/** @var array<int,array<int,string>> Post ID => [ post or descendant ID => permalink before update ]. */
	private array $pending = [];

	public function __construct( Repository $repository, Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	public function register(): void {
		add_action( 'pre_post_update', [ $this, 'capture' ], 10, 1 );
		add_action( 'post_updated', [ $this, 'compare' ], 10, 3 );
	}

	public function capture( int $post_id ): void {
		if ( ! Settings::get( 'slug_watcher' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::eligible( $post ) ) {
			return;
		}

		$links = [ $post_id => (string) get_permalink( $post ) ];
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$children = get_pages(
				[
					'child_of'    => $post_id,
					'post_type'   => $post->post_type,
					'post_status' => 'publish',
				]
			);
			foreach ( is_array( $children ) ? $children : [] as $child ) {
				$links[ (int) $child->ID ] = (string) get_permalink( $child );
			}
		}
		$this->pending[ $post_id ] = $links;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature is fixed by the post_updated action.
	public function compare( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( ! isset( $this->pending[ $post_id ] ) ) {
			return;
		}
		$links = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! self::eligible( $after ) ) {
			return;
		}

		foreach ( $links as $id => $old_url ) {
			$this->handle( $old_url, (string) get_permalink( $id ), $after );
		}
	}

	private static function eligible( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return null !== $type && $type->public && is_post_type_viewable( $type );
	}

	private function handle( string $old_url, string $new_url, \WP_Post $post ): void {
		// Plain permalinks (?p=123) never change, so there is nothing to redirect.
		if ( '' === $old_url || '' === $new_url || false !== strpos( $old_url, '?' ) ) {
			return;
		}
		$old = Site::internal_path( $old_url );
		$new = Site::internal_path( $new_url );
		if ( null === $old || null === $new || PathNormalizer::source_key( $old ) === PathNormalizer::source_key( $new ) ) {
			return;
		}

		$data = [
			'type'        => 'exact',
			'source'      => $old,
			'target'      => $new,
			'status_code' => 301,
			'enabled'     => true,
			/* translators: %d: post ID */
			'note'        => sprintf( __( 'Slug changed on post #%d', 'wp-redirects' ), $post->ID ),
		];

		/**
		 * Filters an automatic slug-change redirect before it is saved. Return false to cancel.
		 *
		 * @param array|false $data { type, source, target, status_code, enabled, note }.
		 * @param \WP_Post    $post The post that changed.
		 * @param string      $old  Old path.
		 * @param string      $new  New path.
		 */
		$data = apply_filters( 'adv_redirects_auto_redirect', $data, $post, $old, $new );
		if ( ! is_array( $data ) ) {
			return;
		}

		$this->repository->retarget( $old, $new );
		$this->repository->disable_by_source_key( PathNormalizer::source_key( $new ) );

		$existing = $this->repository->exact_rule_by_key( PathNormalizer::source_key( $old ) );
		if ( null !== $existing ) {
			// The filtered target gets the same checks as any saved rule. When invalid, the rule stays as it is.
			$result = $this->validator->validate(
				[
					'target'      => $data['target'] ?? $new,
					'status_code' => 301,
					'enabled'     => true,
				],
				$existing->id
			);
			if ( ! is_wp_error( $result ) ) {
				$this->repository->update(
					$existing->id,
					[
						'target'      => $result['data']['target'],
						'status_code' => 301,
						'enabled'     => true,
					]
				);
			}
			return;
		}

		$result = $this->validator->validate( $data );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$rule = $this->repository->insert( $result['data'] + [ 'origin' => 'auto' ] );
		if ( null !== $rule ) {
			/**
			 * Fires after the slug watcher creates a redirect.
			 *
			 * @param \Advision\Redirects\Redirects\Rule $rule
			 * @param \WP_Post                           $post
			 */
			do_action( 'adv_redirects_auto_redirect_created', $rule, $post );
		}
	}
}
