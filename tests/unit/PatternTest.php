<?php

use Advision\Redirects\Matching\Pattern;
use PHPUnit\Framework\TestCase;

final class PatternTest extends TestCase {

	public function test_delimit_adds_delimiters_and_case_insensitive_flag(): void {
		$this->assertSame( '~^/blog/(\d+)$~i', Pattern::delimit( '^/blog/(\d+)$' ) );
	}

	public function test_delimit_escapes_unescaped_tilde_only(): void {
		$this->assertSame( '~a\~b~i', Pattern::delimit( 'a~b' ) );
		$this->assertSame( '~a\~b~i', Pattern::delimit( 'a\~b' ) );
		$this->assertSame( '~a\\\\\~b~i', Pattern::delimit( 'a\\\\~b' ) );
	}

	public function test_delimited_patterns_compile_and_match(): void {
		$this->assertSame( 1, preg_match( Pattern::delimit( '^/~user/(.*)$' ), '/~user/x' ) );
		$this->assertSame( 1, preg_match( Pattern::delimit( '^/OLD$' ), '/old' ) );
	}

	public function test_is_valid(): void {
		$this->assertTrue( Pattern::is_valid( '^/old/(.*)$' ) );
		$this->assertFalse( Pattern::is_valid( '^/old/(.*$' ) );
		$this->assertFalse( Pattern::is_valid( '' ) );
		$this->assertFalse( Pattern::is_valid( str_repeat( 'a', 501 ) ) );
	}

	public function test_user_cannot_inject_modifiers(): void {
		// A trailing "~e" is escaped, never treated as a closing delimiter + modifier.
		$this->assertSame( '~x\~e~i', Pattern::delimit( 'x~e' ) );
	}
}
