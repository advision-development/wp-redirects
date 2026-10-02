<?php

use Advision\Redirects\Site;

final class SiteTest extends WP_UnitTestCase {

	public function test_root_install_values(): void {
		$this->assertSame( 'http://example.org', Site::home_url() );
		$this->assertSame( 'example.org', Site::host() );
		$this->assertSame( '', Site::home_path() );
	}

	public function test_internal_path(): void {
		$this->assertSame( '/a?x=1', Site::internal_path( '/a?x=1' ) );
		$this->assertSame( '/a', Site::internal_path( 'http://EXAMPLE.org/a' ) );
		$this->assertSame( '/', Site::internal_path( 'https://example.org' ) );
		$this->assertNull( Site::internal_path( 'https://other.org/a' ) );
		$this->assertNull( Site::internal_path( '//example.org/a' ) );
		$this->assertNull( Site::internal_path( '' ) );
	}

	public function test_internal_path_on_subdirectory_install(): void {
		update_option( 'home', 'http://example.org/blog' );
		$this->assertSame( '/blog', Site::home_path() );
		$this->assertSame( '/a', Site::internal_path( 'http://example.org/blog/a' ) );
		$this->assertSame( '/', Site::internal_path( 'http://example.org/blog' ) );
		$this->assertNull( Site::internal_path( 'http://example.org/other' ) );
	}

	public function test_reserved_paths(): void {
		$this->assertTrue( Site::is_reserved_path( '/wp-admin' ) );
		$this->assertTrue( Site::is_reserved_path( '/WP-ADMIN/options.php' ) );
		$this->assertTrue( Site::is_reserved_path( '/wp-login.php' ) );
		$this->assertTrue( Site::is_reserved_path( '/wp-json/wp/v2/posts' ) );
		$this->assertTrue( Site::is_reserved_path( '/xmlrpc.php' ) );
		$this->assertFalse( Site::is_reserved_path( '/wp-adminx' ) );
		$this->assertFalse( Site::is_reserved_path( '/blog/wp-admin' ) );
	}

	public function test_resolver_applies_allowed_hosts_filter(): void {
		add_filter(
			'adv_redirects_allowed_target_hosts',
			static function () {
				return [ 'partner.com' ];
			}
		);
		$this->assertNull( Site::resolver()->resolve( 'https://other.org/', [], '', false ) );
		$this->assertSame( 'https://partner.com/', Site::resolver()->resolve( 'https://partner.com/', [], '', false ) );
	}
}
