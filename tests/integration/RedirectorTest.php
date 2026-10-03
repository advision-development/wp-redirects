<?php

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Settings;
use Advision\Redirects\Tracking\HitTracker;

final class RedirectorTest extends WP_UnitTestCase {

	private Repository $repo;
	private Redirector $redirector;

	/** @var array<string,array{0:bool,1:mixed}> */
	private array $server_snapshot = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'REQUEST_URI', 'REQUEST_METHOD' ] as $key ) {
			$this->server_snapshot[ $key ] = [ array_key_exists( $key, $_SERVER ), $_SERVER[ $key ] ?? null ];
		}
		$this->repo       = new Repository();
		$this->redirector = new Redirector( new RuleCache( $this->repo ), new HitTracker( $this->repo ) );
	}

	public function tear_down(): void {
		foreach ( $this->server_snapshot as $key => $state ) {
			if ( $state[0] ) {
				$_SERVER[ $key ] = $state[1];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}
		parent::tear_down();
	}

	private function rule( string $type, string $source, ?string $target, int $status = 301, bool $trailing_slash = false ): int {
		return $this->repo->insert(
			[
				'type'           => $type,
				'source'         => $source,
				'target'         => $target,
				'status_code'    => $status,
				'trailing_slash' => $trailing_slash,
			]
		)->id;
	}

	public function test_exact_redirect_forwards_query_by_default(): void {
		$id       = $this->rule( 'exact', '/old', '/new' );
		$decision = $this->redirector->decide( '/OLD/?utm=1', 'GET' );
		$this->assertSame( 'http://example.org/new?utm=1', $decision['url'] );
		$this->assertSame( 301, $decision['status'] );
		$this->assertSame( $id, $decision['rule']['id'] );
	}

	public function test_query_forwarding_can_be_disabled(): void {
		Settings::update( [ 'forward_query_string' => false ] );
		$this->rule( 'exact', '/old', '/new' );
		$this->assertSame( 'http://example.org/new', $this->redirector->decide( '/old?utm=1', 'GET' )['url'] );
	}

	public function test_regex_relative_target_starting_with_a_capture_redirects(): void {
		$this->rule( 'regex', '^/old/(.*)$', '/$1/' );
		$decision = $this->redirector->decide( '/old/thing', 'GET' );
		$this->assertNotNull( $decision );
		$this->assertSame( 'http://example.org/thing/', $decision['url'] );
	}

	public function test_regex_redirect_with_capture(): void {
		$this->rule( 'regex', '^/blog/(\d+)/?$', '/posts/$1', 302 );
		$decision = $this->redirector->decide( '/blog/42', 'HEAD' );
		$this->assertSame( 'http://example.org/posts/42', $decision['url'] );
		$this->assertSame( 302, $decision['status'] );
	}

	public function test_never_redirects_to_the_requested_url_itself(): void {
		$this->rule( 'regex', '^/fy-self/(.*)', '/fy-self/$1' );
		$this->assertNull( $this->redirector->decide( '/fy-self/x', 'GET' ) );
		$this->assertNull( $this->redirector->decide( '/fy-self/caf%C3%A9', 'GET' ), 'An encoded capture is the same URL.' );
		$this->assertNull( $this->redirector->decide( '/fy-self/x?a=1', 'GET' ), 'The forwarded query makes it the same URL again.' );

		Settings::update( [ 'forward_query_string' => false ] );
		$this->assertSame( 'http://example.org/fy-self/x', $this->redirector->decide( '/fy-self/x?a=1', 'GET' )['url'], 'Dropping the query is a real redirect.' );
	}

	public function test_trailing_slash_flag_adds_the_slash_after_captures_like_yoast(): void {
		$this->rule( 'regex', '^/forum/(.*)', '/forum/$1', 301, true );
		$decision = $this->redirector->decide( '/forum/abc', 'GET' );
		$this->assertSame( 'http://example.org/forum/abc/', $decision['url'] );
		$this->assertTrue( $decision['rule']['trailing_slash'] );
		$this->assertNull( $this->redirector->decide( '/forum/abc/', 'GET' ), 'The slashed URL is the request itself.' );
		$this->assertSame( 'http://example.org/forum/abc/?a=1', $this->redirector->decide( '/forum/abc?a=1', 'GET' )['url'], 'The slash goes before the forwarded query.' );
		$this->assertNull( $this->redirector->decide( '/forum/guide.pdf', 'GET' ), 'A "." in the path gets no slash, so it is the request itself.' );
	}

	public function test_case_and_slash_fixes_still_redirect(): void {
		$this->rule( 'exact', '/NFL', '/nfl' );
		$this->rule( 'exact', '/foo', '/foo/' );

		$this->assertSame( 'http://example.org/nfl', $this->redirector->decide( '/NFL', 'GET' )['url'] );
		$this->assertNull( $this->redirector->decide( '/nfl', 'GET' ), 'The target itself is not redirected.' );
		$this->assertSame( 'http://example.org/foo/', $this->redirector->decide( '/foo', 'GET' )['url'] );
		$this->assertNull( $this->redirector->decide( '/foo/', 'GET' ) );
	}

	public function test_target_url_filter_cannot_point_back_at_the_request(): void {
		$this->rule( 'exact', '/old', '/new' );
		add_filter(
			'adv_redirects_target_url',
			static function () {
				return 'http://example.org/old';
			}
		);
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
	}

	public function test_gone_rule_has_no_url(): void {
		$this->rule( 'exact', '/gone', null, 410 );
		$decision = $this->redirector->decide( '/gone', 'GET' );
		$this->assertSame( 410, $decision['status'] );
		$this->assertNull( $decision['url'] );
	}

	public function test_no_match_and_non_get_methods(): void {
		$this->rule( 'exact', '/old', '/new' );
		$this->assertNull( $this->redirector->decide( '/other', 'GET' ) );
		$this->assertNull( $this->redirector->decide( '/old', 'POST' ) );

		add_filter(
			'adv_redirects_allowed_methods',
			static function ( array $methods ) {
				$methods[] = 'POST';
				return $methods;
			}
		);
		$this->assertNotNull( $this->redirector->decide( '/old', 'POST' ) );
	}

	public function test_reserved_paths_never_redirect(): void {
		// Inserted directly, bypassing the Validator, to prove the runtime guard.
		$this->rule( 'exact', '/wp-login.php', '/new' );
		$this->rule( 'regex', '^/wp-(admin|json)', '/new' );
		$this->assertNull( $this->redirector->decide( '/wp-login.php', 'GET' ) );
		$this->assertNull( $this->redirector->decide( '/wp-admin/', 'GET' ) );
		$this->assertNull( $this->redirector->decide( '/wp-json/wp/v2/posts', 'GET' ) );
	}

	public function test_rest_route_requests_never_redirect(): void {
		$this->rule( 'exact', '/', '/new' );
		$this->rule( 'regex', '^/(.*)$', '/new/$1' );
		$this->assertNull( $this->redirector->decide( '/?rest_route=/wp/v2/posts', 'GET' ) );
		$this->assertNull( $this->redirector->decide( '/index.php?rest_route=/adv-redirects/v1/redirects', 'GET' ) );
		$this->assertNotNull( $this->redirector->decide( '/', 'GET' ) );
	}

	public function test_request_path_filter_cannot_escape_reserved_paths(): void {
		$this->rule( 'exact', '/old', '/new' );
		add_filter(
			'adv_redirects_request_path',
			static function () {
				return '/old';
			}
		);
		$this->assertNull( $this->redirector->decide( '/wp-login.php', 'GET' ) );
	}

	public function test_request_path_filter_non_string_cancels(): void {
		$this->rule( 'exact', '/old', '/new' );
		add_filter( 'adv_redirects_request_path', '__return_null' );
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
	}

	public function test_should_handle_request_filter(): void {
		$this->rule( 'exact', '/old', '/new' );
		add_filter( 'adv_redirects_should_handle_request', '__return_false' );
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
	}

	public function test_match_filter_can_suppress(): void {
		$this->rule( 'exact', '/old', '/new' );
		add_filter( 'adv_redirects_match', '__return_null' );
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
	}

	public function test_filters_cannot_inject_unsafe_urls_or_statuses(): void {
		$this->rule( 'exact', '/old', '/new' );

		add_filter(
			'adv_redirects_target_url',
			static function () {
				return "javascript:alert(1)";
			}
		);
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
		remove_all_filters( 'adv_redirects_target_url' );

		add_filter(
			'adv_redirects_status_code',
			static function () {
				return 200;
			}
		);
		$this->assertNull( $this->redirector->decide( '/old', 'GET' ) );
	}

	public function test_broken_regex_rule_is_skipped(): void {
		$this->rule( 'regex', '^/a', '/ok' );
		add_filter(
			'adv_redirects_compiled_ruleset',
			static function ( array $ruleset ) {
				array_unshift( $ruleset['regex'], [ 'id' => 999, 'pattern' => '~(~i', 'target' => '/broken', 'status' => 301 ] );
				return $ruleset;
			}
		);
		$previous = ini_set( 'error_log', '/dev/null' );
		$decision = $this->redirector->decide( '/abc', 'GET' );
		ini_set( 'error_log', (string) $previous );
		$this->assertSame( 'http://example.org/ok', $decision['url'] );
	}

	public function test_maybe_redirect_sends_redirect_and_records_hit(): void {
		$id                        = $this->rule( 'exact', '/old', '/new', 308 );
		$_SERVER['REQUEST_URI']    = '/old';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$before                    = did_action( 'adv_redirects_before_redirect' );

		add_filter(
			'wp_redirect_status',
			static function ( $status, $location ) {
				throw new Adv_Redirects_Redirect_Caught( $location, $status );
			},
			10,
			2
		);

		try {
			$this->redirector->maybe_redirect();
			$this->fail( 'Expected a redirect.' );
		} catch ( Adv_Redirects_Redirect_Caught $caught ) {
			$this->assertSame( 'http://example.org/new', $caught->location );
			$this->assertSame( 308, $caught->status );
		}
		$this->assertSame( 1, $this->repo->find( $id )->hits );
		$this->assertSame( $before + 1, did_action( 'adv_redirects_before_redirect' ) );
	}

	public function test_gone_flow_forces_404_template_with_status(): void {
		$this->rule( 'exact', '/gone', null, 451 );
		$_SERVER['REQUEST_URI']    = '/gone';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$sent                      = null;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$sent ) {
				$sent = $code;
				return $header;
			},
			10,
			2
		);

		$this->redirector->maybe_redirect();
		$this->assertTrue( $this->redirector->is_gone_request() );

		$this->go_to( home_url( '/' ) );
		$this->redirector->maybe_send_gone();

		$this->assertTrue( is_404() );
		$this->assertSame( 451, $sent );
		$this->assertFalse( has_action( 'template_redirect', 'redirect_canonical' ) );
	}

	public function test_register_hooks(): void {
		$this->redirector->register();
		$this->assertSame( 1, has_action( 'init', [ $this->redirector, 'maybe_redirect' ] ) );
		$this->assertSame( 0, has_action( 'template_redirect', [ $this->redirector, 'maybe_send_gone' ] ) );
	}
}
