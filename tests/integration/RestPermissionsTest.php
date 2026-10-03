<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Rest\NotFoundController;
use Advision\Redirects\Rest\RedirectsController;
use Advision\Redirects\Rest\SettingsController;
use Advision\Redirects\Rest\TestController;
use Advision\Redirects\Rest\YoastImportController;
use Advision\Redirects\Tracking\NotFoundRepository;

/**
 * Spec section 15: every route and method rejects anonymous (401) and editor (403) users
 * before it looks at the request, even when the request body is valid.
 */
final class RestPermissionsTest extends Adv_Redirects_Rest_TestCase {

	protected function controllers(): array {
		$repo   = new Repository();
		$chains = new ChainResolver();
		$cache  = new RuleCache( $repo );
		return [
			new RedirectsController( $repo, new Validator( $repo, $chains ), $cache, $chains ),
			new TestController( $cache, $chains ),
			new NotFoundController( new NotFoundRepository() ),
			new SettingsController(),
			new YoastImportController( new YoastSource( new Importer( $repo, new Validator( $repo, $chains ) ) ) ),
		];
	}

	/**
	 * @return array<string,array{0:string,1:string,2:?array}>
	 */
	public static function routes(): array {
		$rule = [
			'type'        => 'exact',
			'source'      => '/a',
			'target'      => '/b',
			'status_code' => 301,
		];
		return [
			'GET /redirects'          => [ 'GET', '/redirects', null ],
			'POST /redirects'         => [ 'POST', '/redirects', $rule ],
			'PUT /redirects/{id}'     => [ 'PUT', '/redirects/1', [ 'target' => '/c' ] ],
			'DELETE /redirects/{id}'  => [ 'DELETE', '/redirects/1', null ],
			'POST /redirects/bulk'    => [
				'POST',
				'/redirects/bulk',
				[
					'action' => 'delete',
					'ids'    => [ 1 ],
				],
			],
			'POST /redirects/reorder' => [ 'POST', '/redirects/reorder', [ 'ids' => [ 1 ] ] ],
			'POST /test'              => [ 'POST', '/test', [ 'path' => '/a' ] ],
			'GET /404s'               => [ 'GET', '/404s', null ],
			'DELETE /404s'            => [ 'DELETE', '/404s', null ],
			'DELETE /404s/{id}'       => [ 'DELETE', '/404s/1', null ],
			'POST /404s/bulk'         => [
				'POST',
				'/404s/bulk',
				[
					'action' => 'delete',
					'ids'    => [ 1 ],
				],
			],
			'GET /settings'           => [ 'GET', '/settings', null ],
			'PUT /settings'           => [ 'PUT', '/settings', [ 'log_404' => false ] ],
			'GET /import/yoast'           => [ 'GET', '/import/yoast', null ],
			'POST /import/yoast/remove'   => [
				'POST',
				'/import/yoast/remove',
				[
					'entries' => [
						[
							'origin' => 'a',
							'format' => 'plain',
						],
					],
				],
			],
			'POST /import/yoast/restore'  => [ 'POST', '/import/yoast/restore', [] ],
			'DELETE /import/yoast/backup' => [ 'DELETE', '/import/yoast/backup', null ],
		];
	}

	/**
	 * @dataProvider routes
	 */
	public function test_anonymous_users_get_401( string $method, string $route, ?array $body ): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( $method, $route, $body )->get_status() );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_editors_get_403( string $method, string $route, ?array $body ): void {
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( $method, $route, $body )->get_status() );
	}
}
