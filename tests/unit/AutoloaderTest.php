<?php

use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	public function test_loads_namespaced_class_from_src(): void {
		$this->assertTrue( class_exists( \Advision\Redirects\Plugin::class ) );
	}

	public function test_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( 'Some\\Other\\Thing' ) );
	}
}
