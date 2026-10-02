<?php

use Advision\Redirects\Admin\AdminPage;

final class AdminPageTest extends WP_UnitTestCase {

	private function menu_slugs(): array {
		global $menu;
		return array_column( (array) $menu, 2 );
	}

	public function test_menu_registered_for_admins_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		( new AdminPage() )->add_menu();
		$this->assertNotContains( AdminPage::SLUG, $this->menu_slugs() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		( new AdminPage() )->add_menu();
		$this->assertContains( AdminPage::SLUG, $this->menu_slugs() );
	}

	public function test_render_outputs_mount_point(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		( new AdminPage() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="adv-redirects-app"', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_enqueue_only_on_own_screen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$page = new AdminPage();
		$page->add_menu();

		$page->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'adv-redirects-admin', 'enqueued' ) );

		$page->enqueue( 'toplevel_page_' . AdminPage::SLUG );
		if ( is_readable( ADV_REDIRECTS_DIR . 'build/index.asset.php' ) ) {
			$this->assertTrue( wp_script_is( 'adv-redirects-admin', 'enqueued' ) );
			$this->assertStringContainsString( 'window.advRedirects', implode( '', wp_scripts()->get_data( 'adv-redirects-admin', 'before' ) ) );
		} else {
			$this->assertSame( 10, has_action( 'admin_notices', [ $page, 'missing_build_notice' ] ) );
		}
	}
}
