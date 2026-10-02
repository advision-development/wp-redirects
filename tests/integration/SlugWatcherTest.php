<?php

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Settings;
use Advision\Redirects\SlugWatcher;

final class SlugWatcherTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->repo = new Repository();
		( new SlugWatcher( $this->repo, new Validator( $this->repo, new ChainResolver() ) ) )->register();
	}

	private function rule_for( string $path ) {
		return $this->repo->exact_rule_by_key( PathNormalizer::source_key( $path ) );
	}

	public function test_slug_change_creates_auto_redirect(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'hello', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'hello-new' ] );

		$rule = $this->rule_for( '/hello/' );
		$this->assertNotNull( $rule );
		$this->assertSame( '/hello-new/', $rule->target );
		$this->assertSame( 301, $rule->status_code );
		$this->assertSame( 'auto', $rule->origin );
		$this->assertSame( 'Slug changed on post #' . $post_id, $rule->note );
	}

	public function test_page_rename_covers_descendants(): void {
		$parent = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'parent', 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'child', 'post_parent' => $parent, 'post_status' => 'publish' ] );

		wp_update_post( [ 'ID' => $parent, 'post_name' => 'parent-new' ] );

		$this->assertSame( '/parent-new/', $this->rule_for( '/parent/' )->target );
		$this->assertSame( '/parent-new/child/', $this->rule_for( '/parent/child/' )->target );
	}

	public function test_drafts_and_disabled_setting_are_ignored(): void {
		$draft = self::factory()->post->create( [ 'post_name' => 'draft', 'post_status' => 'draft' ] );
		wp_update_post( [ 'ID' => $draft, 'post_name' => 'draft-new' ] );
		$this->assertNull( $this->rule_for( '/draft/' ) );

		Settings::update( [ 'slug_watcher' => false ] );
		$post_id = self::factory()->post->create( [ 'post_name' => 'quiet', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'quiet-new' ] );
		$this->assertNull( $this->rule_for( '/quiet/' ) );
	}

	public function test_existing_rules_are_cleaned_up(): void {
		$pointing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/legacy', 'target' => '/hello/', 'status_code' => 301 ] );
		$blocking = $this->repo->insert( [ 'type' => 'exact', 'source' => '/hello-new', 'target' => '/elsewhere', 'status_code' => 301 ] );

		$post_id = self::factory()->post->create( [ 'post_name' => 'hello', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'hello-new' ] );

		$this->assertSame( '/hello-new/', $this->repo->find( $pointing->id )->target, 'Chains are flattened.' );
		$this->assertFalse( $this->repo->find( $blocking->id )->enabled, 'A rule redirecting the live URL away is disabled.' );
	}

	public function test_rename_back_leaves_no_loop_or_self_redirect(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'alpha', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'beta' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'alpha' ] );

		$this->assertSame( '/alpha/', $this->rule_for( '/beta/' )->target );
		$alpha = $this->rule_for( '/alpha/' );
		$this->assertTrue( null === $alpha || ! $alpha->enabled, 'The live URL must not redirect.' );
	}

	public function test_existing_rule_is_only_updated_through_validation(): void {
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/hello/', 'target' => '/somewhere', 'status_code' => 302, 'enabled' => false ] );
		add_filter(
			'adv_redirects_auto_redirect',
			static function ( $data ) {
				$data['target'] = '//evil.example/phish';
				return $data;
			}
		);

		$post_id = self::factory()->post->create( [ 'post_name' => 'hello', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'hello-new' ] );

		$rule = $this->repo->find( $existing->id );
		$this->assertSame( '/somewhere', $rule->target, 'An invalid filtered target must not reach the rule.' );
		$this->assertSame( 302, $rule->status_code );
		$this->assertFalse( $rule->enabled );
	}

	public function test_existing_rule_takes_a_valid_filtered_target(): void {
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/hello/', 'target' => '/somewhere', 'status_code' => 302, 'enabled' => false ] );
		add_filter(
			'adv_redirects_auto_redirect',
			static function ( $data ) {
				$data['target'] = '/custom-landing/';
				return $data;
			}
		);

		$post_id = self::factory()->post->create( [ 'post_name' => 'hello', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'hello-new' ] );

		$rule = $this->repo->find( $existing->id );
		$this->assertSame( '/custom-landing/', $rule->target );
		$this->assertSame( 301, $rule->status_code );
		$this->assertTrue( $rule->enabled );
	}

	public function test_filter_can_cancel(): void {
		add_filter( 'adv_redirects_auto_redirect', '__return_false' );
		$post_id = self::factory()->post->create( [ 'post_name' => 'keep', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'keep-new' ] );
		$this->assertNull( $this->rule_for( '/keep/' ) );
	}
}
