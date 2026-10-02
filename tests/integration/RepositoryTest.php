<?php

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;

final class RepositoryTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Repository();
	}

	private function exact( string $source, ?string $target, int $status = 301, array $extra = [] ): Rule {
		return $this->repo->insert(
			array_merge(
				[
					'type'        => 'exact',
					'source'      => $source,
					'target'      => $target,
					'status_code' => $status,
				],
				$extra
			)
		);
	}

	public function test_insert_returns_rule_and_fires_hook(): void {
		$before = did_action( 'adv_redirects_rule_created' );
		$rule   = $this->exact( '/old', '/new' );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertGreaterThan( 0, $rule->id );
		$this->assertSame( '/old', $rule->source );
		$this->assertSame( '/new', $rule->target );
		$this->assertSame( 301, $rule->status_code );
		$this->assertTrue( $rule->enabled );
		$this->assertSame( 'manual', $rule->origin );
		$this->assertSame( 0, $rule->hits );
		$this->assertNull( $rule->last_hit_at );
		$this->assertSame( $before + 1, did_action( 'adv_redirects_rule_created' ) );
	}

	public function test_gone_rule_stores_null_target(): void {
		$rule = $this->exact( '/gone', null, 410 );
		$this->assertNull( $this->repo->find( $rule->id )->target );
	}

	public function test_regex_rules_get_increasing_positions(): void {
		$a = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/a', 'target' => '/a', 'status_code' => 301 ] );
		$b = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/b', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertSame( 1, $a->position );
		$this->assertSame( 2, $b->position );
	}

	public function test_update_is_partial_and_fires_hook_with_old_rule(): void {
		$rule     = $this->exact( '/old', '/new' );
		$captured = null;
		add_action(
			'adv_redirects_rule_updated',
			static function ( $new, $old ) use ( &$captured ) {
				$captured = [ $new, $old ];
			},
			10,
			2
		);

		$updated = $this->repo->update( $rule->id, [ 'target' => '/newer', 'enabled' => false ] );

		$this->assertSame( '/newer', $updated->target );
		$this->assertFalse( $updated->enabled );
		$this->assertSame( '/old', $updated->source );
		$this->assertSame( '/new', $captured[1]->target );
		$this->assertNull( $this->repo->update( 999999, [ 'target' => '/x' ] ) );
	}

	public function test_delete_fires_hook_and_reports_missing(): void {
		$rule   = $this->exact( '/old', '/new' );
		$before = did_action( 'adv_redirects_rule_deleted' );
		$this->assertTrue( $this->repo->delete( $rule->id ) );
		$this->assertNull( $this->repo->find( $rule->id ) );
		$this->assertSame( $before + 1, did_action( 'adv_redirects_rule_deleted' ) );
		$this->assertFalse( $this->repo->delete( $rule->id ) );
	}

	public function test_enabled_rows_excludes_disabled(): void {
		$this->exact( '/on', '/x' );
		$this->exact( '/off', '/x', 301, [ 'enabled' => false ] );
		$this->assertSame( [ '/on' ], array_column( $this->repo->enabled_rows(), 'source' ) );
	}

	public function test_exact_rule_by_key_normalizes(): void {
		$rule = $this->exact( '/Old-Page/', '/x' );
		$this->assertSame( $rule->id, $this->repo->exact_rule_by_key( '/old-page' )->id );
		$this->assertNull( $this->repo->exact_rule_by_key( '/old-page', $rule->id ) );
	}

	public function test_reorder_sets_positions_and_appends_unlisted(): void {
		$a = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/a', 'target' => '/a', 'status_code' => 301 ] );
		$b = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/b', 'target' => '/b', 'status_code' => 301 ] );
		$c = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/c', 'target' => '/c', 'status_code' => 301 ] );

		$this->repo->reorder( [ $c->id, $a->id ] );

		$this->assertSame( 1, $this->repo->find( $c->id )->position );
		$this->assertSame( 2, $this->repo->find( $a->id )->position );
		$this->assertSame( 3, $this->repo->find( $b->id )->position );
	}

	public function test_retarget_updates_targets_and_removes_resulting_self_redirects(): void {
		$x    = $this->exact( '/x', '/old/' );
		$back = $this->exact( '/new', '/old' );

		$count = $this->repo->retarget( '/old', '/new' );

		$this->assertSame( 2, $count );
		$this->assertSame( '/new', $this->repo->find( $x->id )->target );
		$this->assertNull( $this->repo->find( $back->id ), 'A rule /new → /new must be deleted, not kept.' );
	}

	public function test_disable_by_source_key(): void {
		$rule = $this->exact( '/New/', '/elsewhere' );
		$this->assertSame( 1, $this->repo->disable_by_source_key( '/new' ) );
		$this->assertFalse( $this->repo->find( $rule->id )->enabled );
	}

	public function test_add_hits_increments_without_flushing_cache(): void {
		$rule  = $this->exact( '/old', '/new' );
		$cache = new RuleCache( $this->repo );
		$cache->get();

		$this->repo->add_hits( $rule->id, 3, '2026-10-02 12:00:00' );

		$this->assertSame( 3, $this->repo->find( $rule->id )->hits );
		$this->assertSame( '2026-10-02 12:00:00', $this->repo->find( $rule->id )->last_hit_at );
		$this->assertIsArray( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ) );
	}

	public function test_cache_compiles_and_every_write_flushes(): void {
		$cache = new RuleCache( $this->repo );
		$this->assertSame( [], $cache->get()['exact'] );

		$rule = $this->exact( '/old', '/new' );
		$this->assertFalse( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ), 'insert must flush' );
		$this->assertArrayHasKey( '/old', $cache->get()['exact'] );

		$this->repo->update( $rule->id, [ 'source' => '/older' ] );
		$this->assertArrayHasKey( '/older', $cache->get()['exact'] );

		$this->repo->delete( $rule->id );
		$this->assertSame( [], $cache->get()['exact'] );
	}

	public function test_cache_flush_fires_action_and_filter_applies(): void {
		$before = did_action( 'adv_redirects_cache_flushed' );
		RuleCache::flush();
		$this->assertSame( $before + 1, did_action( 'adv_redirects_cache_flushed' ) );

		add_filter(
			'adv_redirects_compiled_ruleset',
			static function ( array $ruleset ) {
				$ruleset['exact']['/injected'] = [ 'id' => 1, 'target' => '/x', 'status' => 302 ];
				return $ruleset;
			}
		);
		$this->assertArrayHasKey( '/injected', ( new RuleCache( $this->repo ) )->get()['exact'] );
	}

	public function test_failed_read_is_not_cached(): void {
		global $wpdb;
		$this->exact( '/old', '/new' );
		wp_cache_delete( RuleCache::KEY, RuleCache::GROUP );

		$table = Advision\Redirects\Schema::redirects_table();
		$break = static function ( $query ) use ( $table ) {
			if ( false !== strpos( $query, 'WHERE enabled = 1' ) && false !== strpos( $query, $table ) ) {
				return 'SELECT * FROM adv_redirects_table_that_does_not_exist';
			}
			return $query;
		};
		$cache = new RuleCache( $this->repo );
		$prior = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$ruleset = $cache->get();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $prior );
		}

		$this->assertSame( [], $ruleset['exact'] );
		$this->assertFalse( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ), 'A failed read must not be cached.' );

		$this->assertArrayHasKey( '/old', $cache->get()['exact'] );
		$this->assertIsArray( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ) );
	}

	public function test_reorder_flushes_cache(): void {
		$this->repo->insert( [ 'type' => 'regex', 'source' => '^/a', 'target' => '/a', 'status_code' => 301 ] );
		( new RuleCache( $this->repo ) )->get();
		$this->assertIsArray( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ) );

		$this->repo->reorder( [] );

		$this->assertFalse( wp_cache_get( RuleCache::KEY, RuleCache::GROUP ) );
	}

	public function test_regex_rule_by_source_matches_exact_pattern_only(): void {
		$rule = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/a/(.*)$', 'target' => '/b/$1', 'status_code' => 301 ] );
		$this->exact( '^/a/(.*)$', '/x' );
		$this->assertSame( $rule->id, $this->repo->regex_rule_by_source( '^/a/(.*)$' )->id );
		$this->assertNull( $this->repo->regex_rule_by_source( '^/A/(.*)$' ) );
	}

	public function test_read_cache_scope_serves_a_snapshot_and_every_write_clears_it(): void {
		$first = $this->repo->insert( [ 'type' => 'exact', 'source' => '/rc-a', 'target' => '/x', 'status_code' => 301 ] );

		$this->repo->begin_read_cache();
		try {
			$this->assertCount( 1, $this->repo->all() );
			$this->assertSame( $first->id, $this->repo->exact_rule_by_key( '/rc-a' )->id );
			$this->assertNull( $this->repo->exact_rule_by_key( '/rc-a', $first->id ) );

			// A write that bypasses the repository is not seen: proof the snapshot is really served.
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET source = %s WHERE id = %d', \Advision\Redirects\Schema::redirects_table(), '/rc-sneaky', $first->id ) );
			$this->assertSame( '/rc-a', $this->repo->all()[0]->source );

			$second = $this->repo->insert( [ 'type' => 'exact', 'source' => '/rc-b', 'target' => '/y', 'status_code' => 301 ] );
			$this->assertCount( 2, $this->repo->all(), 'insert clears the snapshot.' );
			$this->assertSame( $second->id, $this->repo->exact_rule_by_key( '/rc-b' )->id );
			$this->assertCount( 2, $this->repo->enabled_rows() );

			$this->repo->update( $second->id, [ 'target' => '/z' ] );
			$this->assertSame( '/z', $this->repo->exact_rule_by_key( '/rc-b' )->target, 'update clears the snapshot.' );

			$this->repo->update( $second->id, [ 'enabled' => false ] );
			$this->assertCount( 1, $this->repo->enabled_rows() );

			$regex = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/rc/(.*)$', 'target' => '/y/$1', 'status_code' => 301 ] );
			$this->assertSame( $regex->id, $this->repo->regex_rule_by_source( '^/rc/(.*)$' )->id );

			$this->repo->delete( $second->id );
			$this->assertNull( $this->repo->exact_rule_by_key( '/rc-b' ), 'delete clears the snapshot.' );

			$this->repo->add_hits( $first->id, 3, '2026-10-01 00:00:00' );
			$this->assertSame( 3, $this->repo->all()[0]->hits, 'add_hits clears the snapshot.' );

			$this->repo->disable_by_source_key( '/rc-sneaky' );
			$this->assertCount( 1, $this->repo->enabled_rows(), 'disable_by_source_key clears the snapshot.' );
		} finally {
			$this->repo->end_read_cache();
		}
	}

	public function test_reads_are_live_outside_the_read_cache_scope(): void {
		$this->repo->insert( [ 'type' => 'exact', 'source' => '/live-a', 'target' => '/x', 'status_code' => 301 ] );
		$this->assertCount( 1, $this->repo->all() );
		$this->repo->insert( [ 'type' => 'exact', 'source' => '/live-b', 'target' => '/x', 'status_code' => 301 ] );
		$this->assertCount( 2, $this->repo->all() );

		$this->repo->begin_read_cache();
		$this->repo->all();
		$this->repo->end_read_cache();
		$this->repo->insert( [ 'type' => 'exact', 'source' => '/live-c', 'target' => '/x', 'status_code' => 301 ] );
		$this->assertCount( 3, $this->repo->all() );
	}

	public function test_insert_records_the_current_user_and_defaults_to_manual(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$rule = $this->exact( '/attr-a', '/x' );
		$this->assertSame( $admin, $rule->created_by );
		$this->assertSame( 'manual', $rule->created_via );
		$this->assertNull( $rule->updated_by );
	}

	public function test_insert_honours_known_created_via_values_only(): void {
		foreach ( [ 'import', 'slug', 'api', 'manual' ] as $via ) {
			$rule = $this->exact( "/via-{$via}", '/x', 301, [ 'created_via' => $via ] );
			$this->assertSame( $via, $rule->created_via );
		}
		$this->assertSame( 'manual', $this->exact( '/via-bogus', '/x', 301, [ 'created_via' => 'hacker' ] )->created_via );
	}

	public function test_insert_ignores_created_by_and_updated_by_in_data(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$rule = $this->exact( '/spoof', '/x', 301, [ 'created_by' => $other, 'updated_by' => $other ] );
		$this->assertSame( $admin, $rule->created_by );
		$this->assertNull( $rule->updated_by );
	}

	public function test_insert_without_a_user_stores_null_created_by(): void {
		wp_set_current_user( 0 );
		$rule = $this->exact( '/nobody', '/x' );
		$this->assertNull( $rule->created_by );
		$this->assertNull( $this->repo->find( $rule->id )->created_by );
	}

	public function test_update_records_the_editor_and_keeps_creation_fields(): void {
		$creator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $creator );
		$rule = $this->exact( '/edited', '/x', 301, [ 'created_via' => 'import' ] );

		wp_set_current_user( $editor );
		$updated = $this->repo->update( $rule->id, [ 'target' => '/y' ] );
		$this->assertSame( $editor, $updated->updated_by );
		$this->assertSame( $creator, $updated->created_by );
		$this->assertSame( 'import', $updated->created_via );
	}

	public function test_update_ignores_created_fields_in_data(): void {
		$creator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $creator );
		$rule = $this->exact( '/locked', '/x' );

		wp_set_current_user( $editor );
		$updated = $this->repo->update( $rule->id, [ 'target' => '/y', 'created_by' => $editor, 'created_via' => 'api', 'updated_by' => $creator ] );
		$this->assertSame( $creator, $updated->created_by );
		$this->assertSame( 'manual', $updated->created_via );
		$this->assertSame( $editor, $updated->updated_by );
	}

	public function test_update_without_a_user_writes_null_updated_by(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$rule = $this->exact( '/anon-edit', '/x' );
		$this->repo->update( $rule->id, [ 'target' => '/y' ] );
		$this->assertSame( $admin, $this->repo->find( $rule->id )->updated_by );

		wp_set_current_user( 0 );
		$this->assertNull( $this->repo->update( $rule->id, [ 'target' => '/z' ] )->updated_by );
	}

	public function test_reorder_and_hits_are_not_edits(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$a = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/oa', 'target' => '/a', 'status_code' => 301 ] );
		$b = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/ob', 'target' => '/b', 'status_code' => 301 ] );
		$this->repo->reorder( [ $b->id, $a->id ] );
		$this->repo->add_hits( $a->id, 2, '2026-10-01 00:00:00' );
		$this->assertNull( $this->repo->find( $a->id )->updated_by );
	}
}
