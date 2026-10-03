# Import from Yoast SEO Premium: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Import tab detects Yoast SEO Premium redirects stored in the database and offers to import them through the existing preview → batched import flow. After the import, the admin confirms removing the imported redirects from Yoast. A backup is kept and can be restored.

**Architecture:**
- **Mapping:** a pure `YoastMapper` turns one entry of Yoast's `wpseo-premium-redirects-base` option into a WP Redirects rule. `Importer` gains a `source` argument (`redirection` | `yoast`) and routes mapping through the matching mapper. Validation, superseded duplicates, chain warnings and writes are shared.
- **Yoast side:** `YoastSource` (WordPress-aware) handles detection, removal with a server-side coverage check, backup, restore and backup deletion. It writes through a `YoastStore`:
  - `YoastManagerStore` when Premium is active. It uses Yoast's own `WPSEO_Redirect_Manager`, so the base option, both export options and any `.htaccess`/nginx file stay in sync.
  - `YoastOptionStore` when Premium is inactive. It edits the options directly.
- **Browser:** the browser relays the entries from `GET /import/yoast` to the existing `/import/preview` and `/import` routes. New routes handle remove, restore and backup deletion.

**Tech Stack:** PHP 7.4+ / WP 6.6+, `$wpdb` via Repository, REST (`adv-redirects/v1`), React with `@wordpress/components`, PHPUnit unit and integration tests (wp-env), Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-03-yoast-import-design.md`. Also read `docs/superpowers/specs/2026-10-02-wp-redirects-design.md` §16 and §17, and the previous plan `docs/superpowers/plans/2026-10-02-redirection-import.md` for how the Redirection import was built.

## Global Constraints

- **PHP 7.4 floor:** no `match`, constructor promotion, union types, named arguments, `mixed`, nullsafe `?->`, `throw` expressions, or `str_contains`/`str_starts_with`. Arrow functions are allowed.
- **Pure classes:** `src/Import/YoastMapper.php` must not call WordPress functions.
- **WordPress 6.6 floor:** only `@wordpress/components` APIs stable in 6.6, no `__experimental*`, never `dangerouslySetInnerHTML`.
- **File header:** every PHP file in `src/` has `defined( 'ABSPATH' ) || exit;` right after the namespace line.
- **REST:** every route has `permission_callback => [ $this, 'permission_check' ]` and argument schemas via `BaseController::arg()`, and rejects unknown body fields via `reject_unknown()`.
- **Writes:** all rule writes go through `Repository`. Yoast data is written only by `YoastOptionStore` / `YoastManagerStore`.
- **Limits:**
  - preview: **5,000** redirects, for both sources (raised from 2,000)
  - import batch: **50**
  - Yoast removal request: **1–500** items
- **Yoast option names:**
  - `wpseo-premium-redirects-base` (list of `{origin, url, type:int, format:"plain"|"regex"}`)
  - `wpseo-premium-redirects-export-plain` and `wpseo-premium-redirects-export-regex` (map `origin => {url, type:int}`)
  - `wpseo_redirect` (`{disable_php_redirect, separate_file}`, values `"on"`/`"off"`)
- **Backup option:** `adv_redirects_yoast_backup`, autoload off. It is a list of `{entry, removed_at:int (UTC unix), removed_by:int}`.
- **Codes:**
  - New skip reasons: `unsupported_capture`, `unreachable_regex`. Existing ones are reused: `invalid_entry`, `unsupported_status`, `filtered`.
  - New note code: `case_sensitive_source`. Existing code reused: `regex_query`.
  - Removal item results: `removed`, `not_found`, `not_covered`.
  - `server_mode` values: `php`, `htaccess`, `apache_file`, `nginx`, `none`.
- **Hooks:**
  - The filter `adv_redirects_import_rule( array|false $rule, array $entry, string $source )` gains its third argument.
  - New actions: `adv_redirects_yoast_removed( array $entries )` and `adv_redirects_yoast_restored( array $entries )`.
  - Document all three in `docs/hooks.md`.
- **Real data:** never commit real redirect data. Tests use only the hand-made `tests/fixtures/yoast-redirects-sample.json`. BMR data is for the manual acceptance run (Task 9) only.
- **Commits:** Conventional Commits, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Stable accessible names:** keep the existing e2e names unchanged: "Source", "Target", "Test a URL", "Add redirect", "Test", "Preview import", "Redirection export file".
- **Test environment:** integration and e2e run in wp-env (`npm run env:start` first). It sets the tests site's permalink structure to `/%postname%/`, so `trailing_slash` is true there.

## Review Focus

1. **Yoast data changed between the import and the removal**, for example an entry deleted or re-typed in Yoast's UI in another tab. The changed entry must be reported `not_found` and nothing else in Yoast touched. Pinned in Task 3 (`test_remove_reports_items_it_cannot_find`, both stores) and Task 4 (`test_remove_leaves_uncovered_and_missing_entries`).
2. **A rule edited or deleted in WP Redirects before removal.** The Yoast entry must stay in Yoast (`not_covered`), so no URL loses its redirect. Pinned in Task 4 (`test_remove_leaves_uncovered_and_missing_entries`).
3. **Premium active with thousands of entries.** Removal must call `WPSEO_Redirect_Manager::save_redirects()` once per request, not once per entry: each call rewrites three options and maybe `.htaccess`. Pinned in Task 3 (`test_manager_store_saves_once_per_request`).
4. **A malformed or foreign base option** (a string, non-array items, string `type`, missing `format`). Detection and preview must not fatal. Malformed items show as skipped `invalid_entry`. Pinned in Task 1 (mapper cases), Task 4 (`test_status_tolerates_a_malformed_option`) and Task 2 (fixture entry 24 counted as skipped).
5. **Browser storage blocked** (private mode, Safari ITP). The "Not now" helpers must not throw, and the notice still renders. Pinned in Task 6 (`noticeHidden and hideNotice survive a throwing localStorage`).

---

## File Map

```
tests/fixtures/yoast-redirects-sample.json            Task 1 (hand-made)
src/Import/YoastMapper.php                            Task 1
tests/unit/YoastMapperTest.php                        Task 1
src/Site.php                                          Task 2 (trailing_slash_permalinks)
src/Import/Importer.php                               Task 2 (source routing, covered())
tests/integration/ImporterYoastTest.php               Task 2
src/Import/YoastStore.php                             Task 3
src/Import/YoastOptionStore.php                       Task 3
src/Import/YoastManagerStore.php                      Task 3
tests/integration/support/yoast-premium-doubles.php   Task 3
tests/integration/YoastStoreTest.php                  Task 3
src/Import/YoastSource.php                            Task 4
src/Uninstaller.php                                   Task 4
tests/integration/YoastSourceTest.php                 Task 4
src/Rest/ImportController.php                         Task 5
src/Rest/YoastImportController.php                    Task 5
src/Plugin.php                                        Task 5
tests/integration/RestImportTest.php                  Task 5 (update)
tests/integration/RestYoastImportTest.php             Task 5
tests/integration/RestPermissionsTest.php             Task 5 (update)
docs/hooks.md                                         Task 5
assets/src/utils/redirectionImport.js                 Task 6 (limit, note label, report)
assets/src/utils/yoastImport.js                       Task 6
assets/src/api.js                                     Task 6
tests/js/yoastImport.test.js                          Task 6
tests/js/redirectionImport.test.js                    Task 6 (update)
assets/src/components/YoastNotices.js                 Task 7
assets/src/components/YoastRemoveCard.js              Task 7
assets/src/components/ImportTab.js                    Task 7
assets/src/admin.scss                                 Task 7
tests/e2e/yoast-import.spec.js                        Task 8
readme.txt, CLAUDE.md                                 Task 8
(no files: BMR acceptance run)                        Task 9
```

---

### Task 1: Fixture and YoastMapper (pure PHP)

**Files:**
- Create: `tests/fixtures/yoast-redirects-sample.json`, `src/Import/YoastMapper.php`
- Test: `tests/unit/YoastMapperTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `YoastMapper::map( $entry, bool $trailing_slash ): array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}`. `rule` has the same keys as `RedirectionMapper`: `type, source, target, status_code, enabled, note, origin`. `source_id` is read from the entry's `id` key (`YoastSource::entries()` adds it as the 1-based position).
  - `YoastMapper::plain_source( string $origin ): string`.
  - The fixture: a JSON **list** shaped exactly like the base option, with **no** `id` keys. Tests add `id = index + 1`.

**Fixture outcomes** (id = 1-based position, `trailing_slash` true). Tasks 2, 4, 6 and 8 rely on these numbers:

| id | format | origin | url | type | expected |
|---|---|---|---|---|---|
| 1 | plain | `fy-old-page` | `fy-new-page` | 301 | new: `/fy-old-page` → `/fy-new-page/` |
| 2 | plain | `fy-Case-Page` | `fy-target` | 301 | new: `/fy-Case-Page` → `/fy-target/` |
| 3 | plain | `fy-case-page` | `fy-other` | 302 | superseded by 2 |
| 4 | plain | `fy-query?ref=1` | `fy-landing` | 301 | new: `/fy-query?ref=1` → `/fy-landing/` |
| 5 | plain | `fy-caf%C3%A9` | `fy-cafe` | 301 | new: `/fy-caf%C3%A9` → `/fy-cafe/` |
| 6 | plain | `fy-gone` | `` | 410 | new: target null |
| 7 | plain | `fy-legal` | `` | 451 | new: target null |
| 8 | plain | `fy-temp` | `https://external.example.net/page` | 307 | new: target unchanged |
| 9 | plain | `fy-file` | `docs/fy-guide.pdf` | 301 | new: target `/docs/fy-guide.pdf` (no slash) |
| 10 | plain | `fy-chain-a` | `fy-chain-b` | 301 | new + chain warning |
| 11 | plain | `fy-chain-b` | `fy-chain-c` | 301 | new |
| 12 | plain | `fy-loop-a` | `fy-loop-b` | 301 | new |
| 13 | plain | `fy-loop-b` | `fy-loop-a` | 301 | skipped `adv_redirects_loop` |
| 14 | plain | `fy-bad-type` | `fy-x` | 303 | skipped `unsupported_status` |
| 15 | plain | `fy-empty-target` | `` | 301 | skipped `invalid_entry` |
| 16 | plain | `https://other.example.net/fy-asset.svg` | `fy-assets` | 301 | skipped `adv_redirects_invalid_source` |
| 17 | regex | `^/fy-regex/(.*)` | `fy-new/$1` | 301 | new: target `/fy-new/$1`, notes `[case_sensitive_source]` |
| 18 | regex | `^/fy-search\?q=(.*)` | `fy-find` | 301 | new: target `/fy-find/`, notes `[case_sensitive_source, regex_query]` |
| 19 | regex | `http://localhost:8080/fy-dead/(.*)` | `fy-x` | 301 | skipped `unreachable_regex` |
| 20 | regex | `^/fy-zero/(.*)` | `fy-z/$0` | 301 | skipped `unsupported_capture` |
| 21 | regex | `^/fy-ten/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)` | `fy-t/$10` | 301 | skipped `unsupported_capture` |
| 22 | regex | `^(/[^/]+)/fy-props/(.*)` | `odds$1/fy-props/$2` | 301 | new: target `/odds$1/fy-props/$2` |
| 23 | plain | `wp-admin/fy` | `fy-x` | 301 | skipped `adv_redirects_reserved_source` |
| 24 | (no format) | `fy-missing-format` | `x` | 301 | skipped `invalid_entry` |
| 25 | plain | `fy-numeric-type` | `fy-n` | `"301"` | new |
| 26 | plain | `fy-slashed` | `/fy-slashed-target/` | 301 | new: target unchanged |

Totals:
- total 26: new 16, overwrite 0, superseded 1, skipped 9, warnings 1
- `counts` from `YoastSource::status()`: plain 19, regex 6 (entry 24 has no format)
- removal candidates after a full import: 16 created + 1 superseded = **17**
- left in Yoast after removal: **9**

- [ ] **Step 1: Write the fixture**

`tests/fixtures/yoast-redirects-sample.json`:

```json
[
	{ "origin": "fy-old-page", "url": "fy-new-page", "type": 301, "format": "plain" },
	{ "origin": "fy-Case-Page", "url": "fy-target", "type": 301, "format": "plain" },
	{ "origin": "fy-case-page", "url": "fy-other", "type": 302, "format": "plain" },
	{ "origin": "fy-query?ref=1", "url": "fy-landing", "type": 301, "format": "plain" },
	{ "origin": "fy-caf%C3%A9", "url": "fy-cafe", "type": 301, "format": "plain" },
	{ "origin": "fy-gone", "url": "", "type": 410, "format": "plain" },
	{ "origin": "fy-legal", "url": "", "type": 451, "format": "plain" },
	{ "origin": "fy-temp", "url": "https://external.example.net/page", "type": 307, "format": "plain" },
	{ "origin": "fy-file", "url": "docs/fy-guide.pdf", "type": 301, "format": "plain" },
	{ "origin": "fy-chain-a", "url": "fy-chain-b", "type": 301, "format": "plain" },
	{ "origin": "fy-chain-b", "url": "fy-chain-c", "type": 301, "format": "plain" },
	{ "origin": "fy-loop-a", "url": "fy-loop-b", "type": 301, "format": "plain" },
	{ "origin": "fy-loop-b", "url": "fy-loop-a", "type": 301, "format": "plain" },
	{ "origin": "fy-bad-type", "url": "fy-x", "type": 303, "format": "plain" },
	{ "origin": "fy-empty-target", "url": "", "type": 301, "format": "plain" },
	{ "origin": "https://other.example.net/fy-asset.svg", "url": "fy-assets", "type": 301, "format": "plain" },
	{ "origin": "^/fy-regex/(.*)", "url": "fy-new/$1", "type": 301, "format": "regex" },
	{ "origin": "^/fy-search\\?q=(.*)", "url": "fy-find", "type": 301, "format": "regex" },
	{ "origin": "http://localhost:8080/fy-dead/(.*)", "url": "fy-x", "type": 301, "format": "regex" },
	{ "origin": "^/fy-zero/(.*)", "url": "fy-z/$0", "type": 301, "format": "regex" },
	{ "origin": "^/fy-ten/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)", "url": "fy-t/$10", "type": 301, "format": "regex" },
	{ "origin": "^(/[^/]+)/fy-props/(.*)", "url": "odds$1/fy-props/$2", "type": 301, "format": "regex" },
	{ "origin": "wp-admin/fy", "url": "fy-x", "type": 301, "format": "plain" },
	{ "origin": "fy-missing-format", "url": "x", "type": 301 },
	{ "origin": "fy-numeric-type", "url": "fy-n", "type": "301", "format": "plain" },
	{ "origin": "fy-slashed", "url": "/fy-slashed-target/", "type": 301, "format": "plain" }
]
```

- [ ] **Step 2: Write the failing unit test**

`tests/unit/YoastMapperTest.php`:

```php
<?php

use Advision\Redirects\Import\YoastMapper;
use PHPUnit\Framework\TestCase;

final class YoastMapperTest extends TestCase {

	private static array $entries;

	public static function setUpBeforeClass(): void {
		$list = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		foreach ( $list as $index => $entry ) {
			self::$entries[ $index + 1 ] = [ 'id' => $index + 1 ] + $entry;
		}
	}

	private function map( int $id, bool $trailing_slash = true ): array {
		return YoastMapper::map( self::$entries[ $id ], $trailing_slash );
	}

	public function test_plain_redirect(): void {
		$this->assertSame(
			[
				'ok'        => true,
				'source_id' => 1,
				'rule'      => [
					'type'        => 'exact',
					'source'      => '/fy-old-page',
					'target'      => '/fy-new-page/',
					'status_code' => 301,
					'enabled'     => true,
					'note'        => '',
					'origin'      => 'manual',
				],
				'notes'     => [],
				'error'     => null,
			],
			$this->map( 1 )
		);
	}

	public function test_plain_sources(): void {
		$this->assertSame( '/fy-Case-Page', $this->map( 2 )['rule']['source'] );
		$this->assertSame( [], $this->map( 2 )['notes'], 'Plain rules get no case note.' );
		$this->assertSame( '/fy-query?ref=1', $this->map( 4 )['rule']['source'] );
		$this->assertSame( '/fy-caf%C3%A9', $this->map( 5 )['rule']['source'] );
		$this->assertSame( 'https://other.example.net/fy-asset.svg', $this->map( 16 )['rule']['source'], 'Absolute origins pass through; the Validator decides.' );
		$this->assertSame( '/', YoastMapper::plain_source( '/' ) );
		$this->assertSame( '/a/b', YoastMapper::plain_source( '/a/b' ) );
	}

	public function test_gone_statuses_have_no_target(): void {
		$this->assertSame( [ 410, null ], [ $this->map( 6 )['rule']['status_code'], $this->map( 6 )['rule']['target'] ] );
		$this->assertSame( [ 451, null ], [ $this->map( 7 )['rule']['status_code'], $this->map( 7 )['rule']['target'] ] );
	}

	public function test_targets(): void {
		$this->assertSame( 'https://external.example.net/page', $this->map( 8 )['rule']['target'] );
		$this->assertSame( 307, $this->map( 8 )['rule']['status_code'] );
		$this->assertSame( '/docs/fy-guide.pdf', $this->map( 9 )['rule']['target'], 'No slash after a file extension.' );
		$this->assertSame( '/fy-new/$1', $this->map( 17 )['rule']['target'], 'No slash after a capture.' );
		$this->assertSame( '/odds$1/fy-props/$2', $this->map( 22 )['rule']['target'] );
		$this->assertSame( '/fy-slashed-target/', $this->map( 26 )['rule']['target'] );
		$this->assertSame( '/fy-new-page', $this->map( 1, false )['rule']['target'], 'No slash when permalinks have none.' );

		$inline = static function ( string $url ): ?string {
			return YoastMapper::map( [ 'id' => 1, 'origin' => 'a', 'url' => $url, 'type' => 301, 'format' => 'plain' ], true )['rule']['target'];
		};
		$this->assertSame( '/page#top', $inline( 'page#top' ) );
		$this->assertSame( '/page?x=1', $inline( 'page?x=1' ) );
		$this->assertSame( '/', $inline( '/' ) );
	}

	public function test_regex_rules_and_notes(): void {
		$mapped = $this->map( 17 );
		$this->assertSame( 'regex', $mapped['rule']['type'] );
		$this->assertSame( '^/fy-regex/(.*)', $mapped['rule']['source'] );
		$this->assertSame( [ 'case_sensitive_source' ], $mapped['notes'] );
		$this->assertSame( [ 'case_sensitive_source', 'regex_query' ], $this->map( 18 )['notes'] );
		$this->assertSame( '/fy-find/', $this->map( 18 )['rule']['target'] );
	}

	public function test_skip_reasons(): void {
		$this->assertSame( 'unsupported_status', $this->map( 14 )['error'] );
		$this->assertSame( 'invalid_entry', $this->map( 15 )['error'], 'A 301 needs a target.' );
		$this->assertSame( 'unreachable_regex', $this->map( 19 )['error'] );
		$this->assertSame( 'unsupported_capture', $this->map( 20 )['error'] );
		$this->assertSame( 'unsupported_capture', $this->map( 21 )['error'] );
		$this->assertSame( 'invalid_entry', $this->map( 24 )['error'] );
		$this->assertSame( 24, $this->map( 24 )['source_id'] );
		$this->assertFalse( $this->map( 24 )['ok'] );
		$this->assertNull( $this->map( 24 )['rule'] );
	}

	public function test_unreachable_regex_variants(): void {
		foreach ( [ 'https://x.test/(.*)', '^https://x.test/(.*)', '^HTTP://x.test/' ] as $origin ) {
			$mapped = YoastMapper::map( [ 'id' => 1, 'origin' => $origin, 'url' => 'a', 'type' => 301, 'format' => 'regex' ], true );
			$this->assertSame( 'unreachable_regex', $mapped['error'], $origin );
		}
	}

	public function test_numeric_string_type_is_accepted(): void {
		$this->assertTrue( $this->map( 25 )['ok'] );
		$this->assertSame( 301, $this->map( 25 )['rule']['status_code'] );
	}

	public function test_malformed_entries_are_invalid(): void {
		$bad = [
			'not an array',
			null,
			[ 'id' => 1 ],
			[ 'id' => 1, 'origin' => '', 'url' => 'a', 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => '   ', 'url' => 'a', 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => [ 'x' ], 'type' => 301, 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => '301.0', 'format' => 'plain' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => 'PLAIN' ],
			[ 'id' => 1, 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => [ 'plain' ] ],
			[ 'id' => 1, 'origin' => [ 'a' ], 'url' => 'b', 'type' => 301, 'format' => 'plain' ],
		];
		foreach ( $bad as $index => $entry ) {
			$this->assertSame( 'invalid_entry', YoastMapper::map( $entry, true )['error'], "case {$index}" );
		}
		$this->assertSame( 0, YoastMapper::map( [ 'origin' => 'a' ], true )['source_id'] );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `npm run test:php:unit -- --filter YoastMapperTest`
Expected: FAIL with `Class "Advision\Redirects\Import\YoastMapper" not found`.

- [ ] **Step 4: Implement `src/Import/YoastMapper.php`**

```php
<?php
/**
 * Maps one Yoast SEO Premium redirect (an entry of the wpseo-premium-redirects-base option) to a
 * WP Redirects rule. Pure: no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastMapper {

	private const REDIRECT_STATUSES = [ 301, 302, 307 ];

	private const GONE_STATUSES = [ 410, 451 ];

	private const FORMATS = [ 'plain', 'regex' ];

	/**
	 * @param mixed $entry          One base-option entry plus the `id` (1-based position) YoastSource adds.
	 * @param bool  $trailing_slash Whether the permalink structure ends in a slash; Yoast then adds one to
	 *                              targets without a capture, query, fragment or file extension.
	 * @return array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}
	 */
	public static function map( $entry, bool $trailing_slash ): array {
		$source_id = is_array( $entry ) && isset( $entry['id'] ) && self::is_int_like( $entry['id'] ) ? (int) $entry['id'] : 0;

		if ( ! self::is_valid_entry( $entry ) ) {
			return self::skip( $source_id, 'invalid_entry' );
		}

		$regex  = 'regex' === $entry['format'];
		$status = (int) $entry['type'];

		// Yoast matches regex rules against the request path, so a pattern that starts with a URL never fired.
		if ( $regex && preg_match( '#^\^?https?:#i', $entry['origin'] ) ) {
			return self::skip( $source_id, 'unreachable_regex' );
		}

		if ( in_array( $status, self::GONE_STATUSES, true ) ) {
			$target = null;
		} elseif ( in_array( $status, self::REDIRECT_STATUSES, true ) ) {
			$url = trim( $entry['url'] );
			if ( '' === $url ) {
				return self::skip( $source_id, 'invalid_entry' );
			}
			// Yoast substitutes $0 and $10+; WP Redirects substitutes $1-$9 only.
			if ( preg_match( '/\$(?:0|[1-9][0-9])/', $url ) ) {
				return self::skip( $source_id, 'unsupported_capture' );
			}
			$target = self::target( $url, $trailing_slash );
		} else {
			return self::skip( $source_id, 'unsupported_status' );
		}

		$notes = [];
		if ( $regex ) {
			$notes[] = 'case_sensitive_source';
			if ( false !== strpos( $entry['origin'], '\\?' ) ) {
				$notes[] = 'regex_query';
			}
		}

		return [
			'ok'        => true,
			'source_id' => $source_id,
			'rule'      => [
				'type'        => $regex ? 'regex' : 'exact',
				'source'      => $regex ? $entry['origin'] : self::plain_source( $entry['origin'] ),
				'target'      => $target,
				'status_code' => $status,
				'enabled'     => true,
				'note'        => '',
				'origin'      => 'manual',
			],
			'notes'     => $notes,
			'error'     => null,
		];
	}

	/**
	 * The exact source for a plain origin. Yoast stores plain origins with their slashes trimmed.
	 */
	public static function plain_source( string $origin ): string {
		$origin = trim( $origin );
		if ( preg_match( '#^https?://#i', $origin ) ) {
			return $origin;
		}
		return '/' . ltrim( $origin, '/' );
	}

	/**
	 * Yoast prepends home_url() to a target without a scheme.
	 */
	private static function target( string $url, bool $trailing_slash ): string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$target = '/' . ltrim( $url, '/' );
		if ( $trailing_slash && self::wants_trailing_slash( $target ) ) {
			$target .= '/';
		}
		return $target;
	}

	/**
	 * Mirrors Yoast's runtime rule. A target with a capture is left alone because Yoast adds the
	 * slash after substitution, and a static one could double it.
	 */
	private static function wants_trailing_slash( string $target ): bool {
		if ( '/' === substr( $target, -1 ) || preg_match( '/\$[0-9]/', $target ) || false !== strpbrk( $target, '?#' ) ) {
			return false;
		}
		$last = (string) substr( $target, (int) strrpos( $target, '/' ) + 1 );
		return false === strpos( $last, '.' );
	}

	/**
	 * @param mixed $entry Raw entry.
	 */
	private static function is_valid_entry( $entry ): bool {
		return is_array( $entry )
			&& isset( $entry['origin'] ) && is_string( $entry['origin'] ) && '' !== trim( $entry['origin'] )
			&& array_key_exists( 'url', $entry ) && is_string( $entry['url'] )
			&& isset( $entry['type'] ) && self::is_int_like( $entry['type'] )
			&& isset( $entry['format'] ) && is_string( $entry['format'] ) && in_array( $entry['format'], self::FORMATS, true );
	}

	/**
	 * Accepts ints and digit-only strings, not floats, exponents or padded strings.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_int_like( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) );
	}

	private static function skip( int $source_id, string $reason ): array {
		return [
			'ok'        => false,
			'source_id' => $source_id,
			'rule'      => null,
			'notes'     => [],
			'error'     => $reason,
		];
	}
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php:unit -- --filter YoastMapperTest` and then `composer lint`.
Expected: all PASS, no PHPCS errors.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/yoast-redirects-sample.json src/Import/YoastMapper.php tests/unit/YoastMapperTest.php
git commit -m "feat: map Yoast SEO Premium redirects to rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Importer handles the Yoast source

**Files:**
- Modify: `src/Site.php` (add `trailing_slash_permalinks()`), `src/Import/Importer.php`
- Test: `tests/integration/ImporterYoastTest.php`

**Interfaces:**
- Consumes: `YoastMapper::map()` (Task 1).
- Produces:
  - `Importer::SOURCE_REDIRECTION = 'redirection'`, `Importer::SOURCE_YOAST = 'yoast'`.
  - `Importer::preview( array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION ): array` and `Importer::import( array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION ): array`. Response shapes are unchanged.
  - `Importer::covered( array $rules ): array<int|string,bool>`: for each mapped rule (`type`, `source`), whether WP Redirects holds a rule with the same conflict key. Keys are preserved.
  - `Site::trailing_slash_permalinks(): bool`.

- [ ] **Step 1: Write the failing integration test**

`tests/integration/ImporterYoastTest.php`:

```php
<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ImporterYoastTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private array $entries;

	public function set_up(): void {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$list           = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		$this->entries  = [];
		foreach ( $list as $index => $entry ) {
			$this->entries[] = [ 'id' => $index + 1 ] + $entry;
		}
	}

	private function preview(): array {
		return $this->importer->preview( $this->entries, [], Importer::SOURCE_YOAST );
	}

	private function by_id( array $entries, int $id ): array {
		foreach ( $entries as $entry ) {
			if ( $entry['source_id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "No entry for Yoast id {$id}." );
	}

	private function import_all( array $preview ): array {
		$batch = [];
		foreach ( $preview['entries'] as $entry ) {
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$batch[] = $this->entries[ $entry['index'] ];
			}
		}
		$totals = [ 'created' => 0, 'updated' => 0, 'skipped' => 0 ];
		foreach ( array_chunk( $batch, 5 ) as $chunk ) {
			$counts = $this->importer->import( $chunk, [], Importer::SOURCE_YOAST )['counts'];
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $counts[ $key ];
			}
		}
		return $totals;
	}

	public function test_preview_counts_and_writes_nothing(): void {
		$this->assertSame(
			[ 'total' => 26, 'new' => 16, 'overwrite' => 0, 'superseded' => 1, 'skipped' => 9, 'warnings' => 1 ],
			$this->preview()['counts']
		);
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_preview_entry_details(): void {
		$entries = $this->preview()['entries'];

		$this->assertSame( 'fy-old-page', $this->by_id( $entries, 1 )['source'], 'The raw Yoast origin is reported as the source.' );
		$superseded = $this->by_id( $entries, 3 );
		$this->assertSame( 'superseded', $superseded['status'] );
		$this->assertSame( 2, $superseded['superseded_by'] );
		$this->assertSame( 'Yoast entry #2 already covers this source.', $superseded['error']['message'] );

		$this->assertSame( 'chain', $this->by_id( $entries, 10 )['warnings'][0]['code'] );
		$this->assertSame( 'adv_redirects_loop', $this->by_id( $entries, 13 )['error']['code'], 'Loop formed only by imported rules.' );
		$this->assertSame( 'adv_redirects_invalid_source', $this->by_id( $entries, 16 )['error']['code'] );
		$this->assertSame( 'adv_redirects_reserved_source', $this->by_id( $entries, 23 )['error']['code'] );

		$this->assertSame( 'unreachable_regex', $this->by_id( $entries, 19 )['error']['code'] );
		$this->assertStringContainsString( 'never matched', $this->by_id( $entries, 19 )['error']['message'] );
		$this->assertSame( 'unsupported_capture', $this->by_id( $entries, 20 )['error']['code'] );
		$this->assertStringContainsString( '$1 to $9', $this->by_id( $entries, 20 )['error']['message'] );
		$this->assertSame( 'invalid_entry', $this->by_id( $entries, 24 )['error']['code'] );

		$this->assertSame( [ 'case_sensitive_source', 'regex_query' ], $this->by_id( $entries, 18 )['notes'] );
		$this->assertSame( '/fy-new-page/', $this->by_id( $entries, 1 )['rule']['target'] );
		$this->assertNull( $this->by_id( $entries, 6 )['rule']['target'] );
	}

	public function test_trailing_slash_follows_the_permalink_structure(): void {
		update_option( 'permalink_structure', '/%postname%' );
		$this->assertSame( '/fy-new-page', $this->by_id( $this->preview()['entries'], 1 )['rule']['target'] );
	}

	public function test_import_matches_preview_and_reimport_is_idempotent(): void {
		$preview = $this->preview();
		$this->assertSame( [ 'created' => 16, 'updated' => 0, 'skipped' => 0 ], $this->import_all( $preview ) );
		$this->assertCount( 16, $this->repo->all() );

		$rule = $this->repo->exact_rule_by_key( '/fy-old-page' );
		$this->assertSame( '/fy-new-page/', $rule->target );
		$this->assertSame( 'import', $rule->created_via );

		$again = $this->preview();
		$this->assertSame( 16, $again['counts']['overwrite'] );
		$this->assertSame( [ 'created' => 0, 'updated' => 16, 'skipped' => 0 ], $this->import_all( $again ) );
		$this->assertCount( 16, $this->repo->all() );
	}

	public function test_filter_receives_the_source(): void {
		$seen = [];
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule, $entry, $source ) use ( &$seen ) {
				$seen[] = $source;
				return $rule;
			},
			10,
			3
		);
		$this->importer->preview( [ $this->entries[0] ], [], Importer::SOURCE_YOAST );
		$this->assertSame( [ 'yoast' ], $seen );
	}

	public function test_covered_reports_rules_with_the_same_conflict_key(): void {
		$this->import_all( $this->preview() );
		$covered = $this->importer->covered(
			[
				'a' => [ 'type' => 'exact', 'source' => '/FY-OLD-PAGE/' ],
				'b' => [ 'type' => 'exact', 'source' => '/fy-caf%C3%A9' ],
				'c' => [ 'type' => 'regex', 'source' => ' ^/fy-regex/(.*) ' ],
				'd' => [ 'type' => 'exact', 'source' => '/fy-nowhere' ],
				'e' => [ 'type' => 'regex', 'source' => '^/fy-nowhere' ],
			]
		);
		$this->assertSame( [ 'a' => true, 'b' => true, 'c' => true, 'd' => false, 'e' => false ], $covered );
	}

	public function test_redirection_calls_are_unchanged(): void {
		$export  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
		$preview = $this->importer->preview( $export['redirects'], $export['groups'] );
		$this->assertSame( 13, $preview['counts']['new'] );
		$by_id   = array_column( $preview['entries'], null, 'source_id' );
		$this->assertStringContainsString( 'Redirection used entry #9', $by_id[10]['error']['message'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php:integration -- --filter ImporterYoastTest`
Expected: FAIL. `Importer::SOURCE_YOAST` is undefined.

- [ ] **Step 3: Add `Site::trailing_slash_permalinks()`**

In `src/Site.php`, after `home_path()`:

```php
	/**
	 * Whether the permalink structure ends in a slash (Yoast then adds one to plain-path targets).
	 */
	public static function trailing_slash_permalinks(): bool {
		return '/' === substr( (string) get_option( 'permalink_structure' ), -1 );
	}
```

- [ ] **Step 4: Route mapping through the source in `src/Import/Importer.php`**

1. Add the constants and update the file docblock's first line to "Plans, previews and applies an import from Redirection or Yoast SEO Premium.":

```php
	public const SOURCE_REDIRECTION = 'redirection';

	public const SOURCE_YOAST = 'yoast';
```

2. Replace `preview()` and `run_preview()`'s signature and calls:

```php
	/**
	 * Dry run: maps and validates every entry, writes nothing.
	 *
	 * @param array  $redirects Raw entries, in import order.
	 * @param array  $groups    Raw Redirection groups (ignored for Yoast).
	 * @param string $source    self::SOURCE_REDIRECTION or self::SOURCE_YOAST.
	 */
	public function preview( array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION ): array {
		$this->repository->begin_read_cache();
		try {
			return $this->run_preview( $redirects, $groups, $source );
		} finally {
			$this->repository->end_read_cache();
		}
	}

	private function run_preview( array $redirects, array $groups, string $source ): array {
```

   In `run_preview()`, change `$this->plan( $redirects, $groups )` to `$this->plan( $redirects, $groups, $source )` and `self::superseded_reason( (int) $item['superseded_by'] )` to `self::superseded_reason( (int) $item['superseded_by'], $source )`.

3. In `import()`, use the same signature (`array $redirects, array $groups = [], string $source = self::SOURCE_REDIRECTION`), update the docblock params the same way, and make the same two call changes.

4. In `plan()`, add the `string $source` parameter and replace the mapping part of the first loop:

```php
	private function plan( array $redirects, array $groups, string $source ): array {
		$yoast    = self::SOURCE_YOAST === $source;
		$info     = $yoast ? [] : RedirectionMapper::group_info( $groups );
		$trailing = $yoast && Site::trailing_slash_permalinks();
		$planned  = [];

		foreach ( array_values( $redirects ) as $index => $raw ) {
			$mapped = $yoast ? YoastMapper::map( $raw, $trailing ) : RedirectionMapper::map( $raw, $info );
			$key    = $yoast ? 'origin' : 'url';
			$item   = [
				'source_id'     => $mapped['source_id'],
				'source'        => is_array( $raw ) && isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '',
				'rule'          => $mapped['rule'],
				'notes'         => $mapped['notes'],
				'error'         => $mapped['error'],
				'superseded'    => false,
				'superseded_by' => null,
			];

			if ( $mapped['ok'] ) {
				/**
				 * Filters a mapped import rule. Return false (or any non-array) to skip it.
				 *
				 * @param array|false $rule   { type, source, target, status_code, enabled, note, origin }.
				 * @param array       $entry  The raw entry (Redirection export entry or Yoast base-option entry).
				 * @param string      $source 'redirection' or 'yoast'.
				 */
				$filtered = apply_filters( 'adv_redirects_import_rule', $mapped['rule'], is_array( $raw ) ? $raw : [], $source );
```

   The rest of the loop body and the winner logic stay as they are. Update the method docblock's second paragraph to say "Redirection serves the first enabled match in position order, and Yoast holds one entry per origin, so among entries with the same conflict key …".

5. Add `covered()` after `import()`:

```php
	/**
	 * Whether WP Redirects holds a rule with the same conflict key as each mapped rule, the same
	 * match the import uses to decide new or overwrite. Used before removing redirects from Yoast.
	 *
	 * @param array<int|string,array> $rules Mapped rules (`type`, `source`).
	 * @return array<int|string,bool> Same keys as $rules.
	 */
	public function covered( array $rules ): array {
		$lookup = $this->existing_lookup();
		$out    = [];
		foreach ( $rules as $key => $rule ) {
			$out[ $key ] = null !== $this->existing_for( $rule, $lookup );
		}
		return $out;
	}
```

6. Replace `superseded_reason()`:

```php
	/**
	 * @return array{code:string,message:string}
	 */
	private static function superseded_reason( int $winner_id, string $source ): array {
		$message = self::SOURCE_YOAST === $source
			? sprintf(
				/* translators: %d: Yoast redirect number */
				__( 'Yoast entry #%d already covers this source.', 'wp-redirects' ),
				$winner_id
			)
			: sprintf(
				/* translators: %d: Redirection entry id */
				__( 'Redirection used entry #%d for this source; this copy was never served.', 'wp-redirects' ),
				$winner_id
			);
		return [
			'code'    => 'superseded',
			'message' => $message,
		];
	}
```

7. In `reason()`, add two messages to `$messages`:

```php
			'unsupported_capture'    => __( 'Only captures $1 to $9 are supported.', 'wp-redirects' ),
			'unreachable_regex'      => __( 'Yoast matched regex rules against the path, so a pattern that starts with a URL never matched anything.', 'wp-redirects' ),
```

8. Add `use Advision\Redirects\Site;` if it isn't already imported. It is used by `exact_path()` today, so check first.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php:integration -- --filter 'ImporterYoastTest|ImporterTest|RestImportTest'` and then `composer lint`.
Expected: all PASS. The existing Redirection tests still pass because they call `preview( $redirects, $groups )`.

- [ ] **Step 6: Commit**

```bash
git add src/Site.php src/Import/Importer.php tests/integration/ImporterYoastTest.php
git commit -m "feat: import Yoast SEO Premium redirects through the importer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Yoast stores (options path and Premium manager path)

**Files:**
- Create: `src/Import/YoastStore.php`, `src/Import/YoastOptionStore.php`, `src/Import/YoastManagerStore.php`, `tests/integration/support/yoast-premium-doubles.php`
- Test: `tests/integration/YoastStoreTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `interface YoastStore`:
    - `remove( array $items ): array{removed: array<int,array>, not_found: array<int,array>}`. `$items` are `{origin, format}` pairs. `removed` holds the raw base entries `{origin, url, type, format}`.
    - `add( array $entries ): array{added: array<int,array>, already_present: array<int,array>}`. `$entries` are raw base entries. Yoast keeps one entry per origin, so an origin Yoast already has (in either format) is `already_present`.
  - Class constants used by Task 4: `YoastOptionStore::BASE_OPTION`, `PLAIN_OPTION`, `REGEX_OPTION`.
  - Test doubles: global classes `WPSEO_Redirect`, `WPSEO_Redirect_Option`, `WPSEO_Redirect_Manager` (with `public static $saves`), using Yoast 27.3 method names. Loaded only by `YoastStoreTest`. They do **not** define `WPSEO_PREMIUM_VERSION`, so `YoastSource::premium_active()` stays false in every other test.

- [ ] **Step 1: Write the Yoast test doubles**

`tests/integration/support/yoast-premium-doubles.php`:

```php
<?php
/**
 * Minimal stand-ins for the Yoast SEO Premium 27.3 redirect classes that YoastManagerStore uses:
 * same class names, method names and signatures, storing in the real options. Loaded only by
 * YoastStoreTest. Deliberately does not define WPSEO_PREMIUM_VERSION.
 */

if ( ! class_exists( 'WPSEO_Redirect' ) ) {
	class WPSEO_Redirect {
		private $origin;
		private $target;
		private $type;
		private $format;

		public function __construct( $origin, $target = '', $type = 301, $format = 'plain' ) {
			$this->origin = 'plain' === $format ? trim( $origin, '/' ) : $origin;
			$this->target = $target;
			$this->type   = (int) $type;
			$this->format = $format;
		}

		public function get_origin() {
			return $this->origin;
		}

		public function get_target() {
			return $this->target;
		}

		public function get_type() {
			return $this->type;
		}

		public function get_format() {
			return $this->format;
		}

		public function origin_is( $url ) {
			if ( 'plain' === $this->format ) {
				$url = trim( $url, '/' );
			}
			return (string) $this->origin === (string) $url;
		}
	}

	class WPSEO_Redirect_Option {
		private $redirects = [];

		public function __construct( $retrieve_redirects = true ) {
			if ( $retrieve_redirects ) {
				$this->redirects = $this->get_all();
			}
		}

		public function get_all() {
			$out = [];
			foreach ( (array) get_option( 'wpseo-premium-redirects-base', [] ) as $row ) {
				$out[] = new WPSEO_Redirect( $row['origin'], $row['url'], $row['type'], $row['format'] );
			}
			return $out;
		}

		public function add( WPSEO_Redirect $redirect ) {
			if ( false === $this->search( $redirect->get_origin() ) ) {
				$this->redirects[] = $redirect;
				return true;
			}
			return false;
		}

		public function delete( WPSEO_Redirect $redirect ) {
			$found = $this->search( $redirect->get_origin() );
			if ( false !== $found ) {
				unset( $this->redirects[ $found ] );
				return true;
			}
			return false;
		}

		public function get( $origin ) {
			$found = $this->search( $origin );
			return false !== $found ? $this->redirects[ $found ] : false;
		}

		public function search( $origin ) {
			foreach ( $this->redirects as $key => $redirect ) {
				if ( $redirect->origin_is( $origin ) ) {
					return $key;
				}
			}
			return false;
		}

		public function save( $retry_upgrade = true ) {
			$rows = [];
			foreach ( $this->redirects as $redirect ) {
				$rows[] = [
					'origin' => $redirect->get_origin(),
					'url'    => $redirect->get_target(),
					'type'   => $redirect->get_type(),
					'format' => $redirect->get_format(),
				];
			}
			update_option( 'wpseo-premium-redirects-base', $rows, false );
		}
	}

	class WPSEO_Redirect_Manager {
		public static $saves = 0;

		private $redirect_option;

		public function __construct( $redirect_format = 'plain', $exporters = null, $option = null ) {
			$this->redirect_option = $option ? $option : new WPSEO_Redirect_Option();
		}

		public function save_redirects() {
			++self::$saves;
			$this->redirect_option->save();
			$export = [
				'plain' => [],
				'regex' => [],
			];
			foreach ( $this->redirect_option->get_all() as $redirect ) {
				$export[ $redirect->get_format() ][ $redirect->get_origin() ] = [
					'url'  => $redirect->get_target(),
					'type' => $redirect->get_type(),
				];
			}
			update_option( 'wpseo-premium-redirects-export-plain', $export['plain'] );
			update_option( 'wpseo-premium-redirects-export-regex', $export['regex'] );
		}
	}
}
```

- [ ] **Step 2: Write the failing store test**

`tests/integration/YoastStoreTest.php`. The same scenarios run against both stores through a data provider:

```php
<?php

use Advision\Redirects\Import\YoastManagerStore;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastStore;

require_once __DIR__ . '/support/yoast-premium-doubles.php';

final class YoastStoreTest extends WP_UnitTestCase {

	private const SEED = [
		[ 'origin' => 'a-page', 'url' => 'a-target', 'type' => 301, 'format' => 'plain' ],
		[ 'origin' => 'b-page', 'url' => '', 'type' => 410, 'format' => 'plain' ],
		[ 'origin' => '^/c/(.*)', 'url' => 'c-new/$1', 'type' => 302, 'format' => 'regex' ],
	];

	public function set_up(): void {
		parent::set_up();
		update_option( YoastOptionStore::BASE_OPTION, self::SEED, false );
		delete_option( YoastOptionStore::PLAIN_OPTION );
		delete_option( YoastOptionStore::REGEX_OPTION );
		WPSEO_Redirect_Manager::$saves = 0;
	}

	public static function stores(): array {
		return [
			'options' => [ YoastOptionStore::class ],
			'manager' => [ YoastManagerStore::class ],
		];
	}

	private function store( string $class ): YoastStore {
		return new $class();
	}

	/**
	 * @dataProvider stores
	 */
	public function test_remove_writes_base_and_export_options( string $class ): void {
		$outcome = $this->store( $class )->remove(
			[
				[ 'origin' => 'a-page', 'format' => 'plain' ],
				[ 'origin' => '^/c/(.*)', 'format' => 'regex' ],
			]
		);

		$this->assertSame( [ self::SEED[0], self::SEED[2] ], $outcome['removed'] );
		$this->assertSame( [], $outcome['not_found'] );
		$this->assertSame( [ self::SEED[1] ], get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertSame( [ 'b-page' => [ 'url' => '', 'type' => 410 ] ], get_option( YoastOptionStore::PLAIN_OPTION ) );
		$this->assertSame( [], get_option( YoastOptionStore::REGEX_OPTION ) );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_remove_reports_items_it_cannot_find( string $class ): void {
		$outcome = $this->store( $class )->remove(
			[
				[ 'origin' => 'missing', 'format' => 'plain' ],
				[ 'origin' => 'a-page', 'format' => 'regex' ],
			]
		);

		$this->assertSame( [], $outcome['removed'] );
		$this->assertSame( [ [ 'origin' => 'missing', 'format' => 'plain' ], [ 'origin' => 'a-page', 'format' => 'regex' ] ], $outcome['not_found'] );
		$this->assertSame( self::SEED, get_option( YoastOptionStore::BASE_OPTION ), 'Nothing is written when nothing is removed.' );
		$this->assertFalse( get_option( YoastOptionStore::PLAIN_OPTION ) );
	}

	/**
	 * @dataProvider stores
	 */
	public function test_add_appends_new_origins_and_skips_present_ones( string $class ): void {
		$new     = [ 'origin' => 'd-page', 'url' => 'd-target', 'type' => 307, 'format' => 'plain' ];
		$clash   = [ 'origin' => 'a-page', 'url' => 'other', 'type' => 301, 'format' => 'plain' ];
		$outcome = $this->store( $class )->add( [ $new, $clash ] );

		$this->assertSame( [ $new ], $outcome['added'] );
		$this->assertSame( [ $clash ], $outcome['already_present'] );
		$this->assertSame( array_merge( self::SEED, [ $new ] ), get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertSame( [ 'url' => 'd-target', 'type' => 307 ], get_option( YoastOptionStore::PLAIN_OPTION )['d-page'] );
		$this->assertSame( [ 'url' => 'c-new/$1', 'type' => 302 ], get_option( YoastOptionStore::REGEX_OPTION )['^/c/(.*)'] );
	}

	public function test_manager_store_saves_once_per_request(): void {
		$store = new YoastManagerStore();
		$store->remove(
			[
				[ 'origin' => 'a-page', 'format' => 'plain' ],
				[ 'origin' => 'b-page', 'format' => 'plain' ],
				[ 'origin' => '^/c/(.*)', 'format' => 'regex' ],
			]
		);
		$this->assertSame( 1, WPSEO_Redirect_Manager::$saves );

		$store->add( self::SEED );
		$this->assertSame( 2, WPSEO_Redirect_Manager::$saves );

		$store->remove( [ [ 'origin' => 'missing', 'format' => 'plain' ] ] );
		$this->assertSame( 2, WPSEO_Redirect_Manager::$saves, 'No save when nothing changed.' );
	}

	public function test_option_store_keeps_export_autoload_and_skips_malformed_rows(): void {
		add_option( YoastOptionStore::PLAIN_OPTION, [], '', false );
		update_option( YoastOptionStore::BASE_OPTION, array_merge( self::SEED, [ 'junk', [ 'origin' => 'x' ] ] ), false );

		( new YoastOptionStore() )->remove( [ [ 'origin' => 'a-page', 'format' => 'plain' ] ] );

		$this->assertCount( 4, get_option( YoastOptionStore::BASE_OPTION ), 'Malformed rows are kept untouched.' );
		$this->assertSame( [ 'b-page' ], array_keys( get_option( YoastOptionStore::PLAIN_OPTION ) ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( YoastOptionStore::PLAIN_OPTION, wp_load_alloptions() );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `npm run test:php:integration -- --filter YoastStoreTest`
Expected: FAIL with `Class "Advision\Redirects\Import\YoastOptionStore" not found`.

- [ ] **Step 4: Implement the interface and both stores**

`src/Import/YoastStore.php`:

```php
<?php
/**
 * Writes Yoast SEO Premium's redirect storage. Yoast keeps one redirect per origin.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

interface YoastStore {

	/**
	 * @param array<int,array{origin:string,format:string}> $items Redirects to remove.
	 * @return array{removed:array<int,array>,not_found:array<int,array>} `removed` holds the raw base entries.
	 */
	public function remove( array $items ): array;

	/**
	 * @param array<int,array> $entries Raw base entries { origin, url, type, format }.
	 * @return array{added:array<int,array>,already_present:array<int,array>}
	 */
	public function add( array $entries ): array;
}
```

`src/Import/YoastOptionStore.php`:

```php
<?php
/**
 * Edits Yoast SEO Premium's redirect options directly. Used when Premium is not active, so its own
 * classes are not loaded. Rebuilds both export options from the base option in Yoast's shape.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastOptionStore implements YoastStore {

	public const BASE_OPTION = 'wpseo-premium-redirects-base';

	public const PLAIN_OPTION = 'wpseo-premium-redirects-export-plain';

	public const REGEX_OPTION = 'wpseo-premium-redirects-export-regex';

	public function remove( array $items ): array {
		$base      = self::base();
		$removed   = [];
		$not_found = [];

		foreach ( $items as $item ) {
			$found = null;
			foreach ( $base as $index => $entry ) {
				if ( self::origin_of( $entry ) === $item['origin'] && $entry['format'] === $item['format'] ) {
					$found = $index;
					break;
				}
			}
			if ( null === $found ) {
				$not_found[] = $item;
				continue;
			}
			$removed[] = $base[ $found ];
			unset( $base[ $found ] );
		}

		if ( $removed ) {
			self::save( array_values( $base ) );
		}
		return [
			'removed'   => $removed,
			'not_found' => $not_found,
		];
	}

	public function add( array $entries ): array {
		$base    = self::base();
		$added   = [];
		$present = [];

		foreach ( $entries as $entry ) {
			$exists = false;
			foreach ( $base as $row ) {
				if ( self::origin_of( $row ) === (string) $entry['origin'] ) {
					$exists = true;
					break;
				}
			}
			if ( $exists ) {
				$present[] = $entry;
				continue;
			}
			$base[]  = $entry;
			$added[] = $entry;
		}

		if ( $added ) {
			self::save( $base );
		}
		return [
			'added'           => $added,
			'already_present' => $present,
		];
	}

	private static function base(): array {
		$base = get_option( self::BASE_OPTION, [] );
		return is_array( $base ) ? array_values( $base ) : [];
	}

	/**
	 * The origin of a well-formed row, or null for anything else (kept, never matched).
	 *
	 * @param mixed $row Base option row.
	 */
	private static function origin_of( $row ): ?string {
		return is_array( $row ) && isset( $row['origin'], $row['format'] ) && is_string( $row['origin'] ) && is_string( $row['format'] ) ? $row['origin'] : null;
	}

	/**
	 * Writes the base option (autoload off, as Yoast does) and rebuilds both export options.
	 * update_option() without an autoload argument keeps each export option's current autoload.
	 */
	private static function save( array $base ): void {
		update_option( self::BASE_OPTION, $base, false );

		$export = [
			'plain' => [],
			'regex' => [],
		];
		foreach ( $base as $row ) {
			$origin = self::origin_of( $row );
			if ( null !== $origin && isset( $export[ $row['format'] ] ) ) {
				$export[ $row['format'] ][ $origin ] = [
					'url'  => isset( $row['url'] ) && is_string( $row['url'] ) ? $row['url'] : '',
					'type' => isset( $row['type'] ) ? (int) $row['type'] : 301,
				];
			}
		}
		update_option( self::PLAIN_OPTION, $export['plain'] );
		update_option( self::REGEX_OPTION, $export['regex'] );
	}
}
```

`src/Import/YoastManagerStore.php`:

```php
<?php
/**
 * Changes Yoast SEO Premium's redirects through Yoast's own classes, so the base option, both export
 * options and any .htaccess or nginx redirect file stay in sync. Used only while Premium is active.
 * All changes in one call are written with a single save.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class YoastManagerStore implements YoastStore {

	public function remove( array $items ): array {
		$option    = new \WPSEO_Redirect_Option();
		$removed   = [];
		$not_found = [];

		foreach ( $items as $item ) {
			$redirect = $option->get( $item['origin'] );
			if ( ! $redirect instanceof \WPSEO_Redirect || $redirect->get_format() !== $item['format'] ) {
				$not_found[] = $item;
				continue;
			}
			$option->delete( $redirect );
			$removed[] = self::to_entry( $redirect );
		}

		if ( $removed ) {
			self::save( $option );
		}
		return [
			'removed'   => $removed,
			'not_found' => $not_found,
		];
	}

	public function add( array $entries ): array {
		$option  = new \WPSEO_Redirect_Option();
		$added   = [];
		$present = [];

		foreach ( $entries as $entry ) {
			$redirect = new \WPSEO_Redirect( (string) $entry['origin'], (string) $entry['url'], (int) $entry['type'], (string) $entry['format'] );
			if ( $option->add( $redirect ) ) {
				$added[] = $entry;
			} else {
				$present[] = $entry;
			}
		}

		if ( $added ) {
			self::save( $option );
		}
		return [
			'added'           => $added,
			'already_present' => $present,
		];
	}

	/**
	 * One write of the base option, both export options and any redirect file.
	 */
	private static function save( \WPSEO_Redirect_Option $option ): void {
		( new \WPSEO_Redirect_Manager( 'plain', null, $option ) )->save_redirects();
	}

	private static function to_entry( \WPSEO_Redirect $redirect ): array {
		return [
			'origin' => (string) $redirect->get_origin(),
			'url'    => (string) $redirect->get_target(),
			'type'   => (int) $redirect->get_type(),
			'format' => (string) $redirect->get_format(),
		];
	}
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php:integration -- --filter YoastStoreTest` and then `composer lint`.
Expected: all PASS. If PHPCS flags the `\WPSEO_*` class references, do not suppress them: they are plain class names and should not trip any configured sniff, so report what it says.

- [ ] **Step 6: Commit**

```bash
git add src/Import/YoastStore.php src/Import/YoastOptionStore.php src/Import/YoastManagerStore.php tests/integration/support/yoast-premium-doubles.php tests/integration/YoastStoreTest.php
git commit -m "feat: write Yoast redirect storage through options or Yoast's manager

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: YoastSource: detection, removal, backup, restore

**Files:**
- Create: `src/Import/YoastSource.php`
- Modify: `src/Uninstaller.php`
- Test: `tests/integration/YoastSourceTest.php`

**Interfaces:**
- Consumes:
  - `Importer::covered()` and `Importer` (Task 2)
  - `YoastMapper::map()` (Task 1)
  - `YoastStore`, `YoastOptionStore`, `YoastManagerStore` and their option constants (Task 3)
  - `Site::trailing_slash_permalinks()` (Task 2)
- Produces:
  - `new YoastSource( Importer $importer, ?YoastStore $store = null )`. A null store picks `YoastManagerStore` when `premium_active()`, else `YoastOptionStore`.
  - `YoastSource::BACKUP_OPTION = 'adv_redirects_yoast_backup'`, `YoastSource::METHOD_OPTION = 'wpseo_redirect'`.
  - `YoastSource::premium_active(): bool` (static) and `YoastSource::server_mode(): string` (static).
  - `status(): array{detected:bool, premium_active:bool, premium_version:?string, server_mode:string, counts:array{plain:int,regex:int}, entries:array, backup:?array{count:int,last_removed_at:string}}`. `last_removed_at` is GMT `Y-m-d H:i:s`.
  - `entries(): array<int,array>`: `{id, origin?, url?, type?, format?}` in stored order. `id` is the 1-based position.
  - `remove( array $items ): array{removed:int, not_found:int, not_covered:int, items:array<int,array{origin:string,format:string,result:string}>}`.
  - `restore(): array{restored:int, already_present:int}` and `delete_backup(): void`.
  - `Uninstaller::options(): string[]`.
  - Actions `adv_redirects_yoast_removed( array $entries )` and `adv_redirects_yoast_restored( array $entries )`.

- [ ] **Step 1: Write the failing integration test**

`tests/integration/YoastSourceTest.php`:

```php
<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Uninstaller;

final class YoastSourceTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private YoastSource $yoast;
	private array $fixture;

	public function set_up(): void {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		foreach ( [ YoastOptionStore::BASE_OPTION, YoastOptionStore::PLAIN_OPTION, YoastOptionStore::REGEX_OPTION, YoastSource::METHOD_OPTION, YoastSource::BACKUP_OPTION ] as $option ) {
			delete_option( $option );
		}
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$this->yoast    = new YoastSource( $this->importer, new YoastOptionStore() );
		$this->fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
	}

	private function seed(): void {
		update_option( YoastOptionStore::BASE_OPTION, $this->fixture, false );
	}

	/**
	 * Previews and imports everything the preview accepts, as the admin does.
	 *
	 * @return array{0:array,1:array} Preview, and the removal candidates { origin, format }.
	 */
	private function import_all(): array {
		$entries = $this->yoast->entries();
		$preview = $this->importer->preview( $entries, [], Importer::SOURCE_YOAST );
		$batch   = [];
		$remove  = [];
		foreach ( $preview['entries'] as $entry ) {
			$raw = $entries[ $entry['index'] ];
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$batch[]  = $raw;
				$remove[] = [ 'origin' => $raw['origin'], 'format' => $raw['format'] ];
			} elseif ( 'superseded' === $entry['status'] ) {
				$remove[] = [ 'origin' => $raw['origin'], 'format' => $raw['format'] ];
			}
		}
		foreach ( array_chunk( $batch, 50 ) as $chunk ) {
			$this->importer->import( $chunk, [], Importer::SOURCE_YOAST );
		}
		return [ $preview, $remove ];
	}

	private function base_origins(): array {
		return array_column( (array) get_option( YoastOptionStore::BASE_OPTION, [] ), 'origin' );
	}

	public function test_status_when_nothing_is_stored(): void {
		$this->assertSame(
			[
				'detected'        => false,
				'premium_active'  => false,
				'premium_version' => null,
				'server_mode'     => 'php',
				'counts'          => [ 'plain' => 0, 'regex' => 0 ],
				'entries'         => [],
				'backup'          => null,
			],
			$this->yoast->status()
		);
	}

	public function test_status_reports_entries_and_counts(): void {
		$this->seed();
		$status = $this->yoast->status();

		$this->assertTrue( $status['detected'] );
		$this->assertSame( [ 'plain' => 19, 'regex' => 6 ], $status['counts'] );
		$this->assertCount( 26, $status['entries'] );
		$this->assertSame( [ 'id' => 1, 'origin' => 'fy-old-page', 'url' => 'fy-new-page', 'type' => 301, 'format' => 'plain' ], $status['entries'][0] );
		$this->assertSame( [ 'id' => 24, 'origin' => 'fy-missing-format', 'url' => 'x', 'type' => 301 ], $status['entries'][23] );
	}

	public function test_status_tolerates_a_malformed_option(): void {
		update_option( YoastOptionStore::BASE_OPTION, 'junk', false );
		$this->assertFalse( $this->yoast->status()['detected'] );

		update_option( YoastOptionStore::BASE_OPTION, [ 'junk', [ 'origin' => 'a', 'extra' => 'dropped' ], [ 'format' => [ 'plain' ] ] ], false );
		$status = $this->yoast->status();
		$this->assertTrue( $status['detected'] );
		$this->assertSame( [ [ 'id' => 1 ], [ 'id' => 2, 'origin' => 'a' ], [ 'id' => 3, 'format' => [ 'plain' ] ] ], $status['entries'] );
		$this->assertSame( [ 'plain' => 0, 'regex' => 0 ], $status['counts'] );
	}

	public function test_server_mode(): void {
		global $is_apache, $is_nginx;
		$saved = [ $is_apache, $is_nginx ];

		$this->assertSame( 'php', YoastSource::server_mode() );
		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'off', 'separate_file' => 'on' ] );
		$this->assertSame( 'php', YoastSource::server_mode() );

		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'on', 'separate_file' => 'off' ] );
		$is_apache = true;
		$is_nginx  = false;
		$this->assertSame( 'htaccess', YoastSource::server_mode() );
		update_option( YoastSource::METHOD_OPTION, [ 'disable_php_redirect' => 'on', 'separate_file' => 'on' ] );
		$this->assertSame( 'apache_file', YoastSource::server_mode() );
		$is_apache = false;
		$is_nginx  = true;
		$this->assertSame( 'nginx', YoastSource::server_mode() );
		$is_nginx = false;
		$this->assertSame( 'none', YoastSource::server_mode() );

		list( $is_apache, $is_nginx ) = $saved;
	}

	public function test_remove_removes_covered_entries_backs_them_up_and_fires_the_hook(): void {
		$this->seed();
		list( , $remove ) = $this->import_all();
		$this->assertCount( 17, $remove );

		$fired = [];
		add_action(
			'adv_redirects_yoast_removed',
			static function ( $entries ) use ( &$fired ) {
				$fired[] = $entries;
			}
		);

		$result = $this->yoast->remove( $remove );

		$this->assertSame( [ 17, 0, 0 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertSame( [ 'removed' ], array_values( array_unique( array_column( $result['items'], 'result' ) ) ) );
		$this->assertCount( 9, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertNotContains( 'fy-old-page', $this->base_origins() );
		$this->assertNotContains( 'fy-case-page', $this->base_origins(), 'A superseded entry is covered by its winner.' );
		$this->assertContains( 'fy-bad-type', $this->base_origins(), 'Skipped entries stay in Yoast.' );
		$this->assertArrayNotHasKey( 'fy-old-page', get_option( YoastOptionStore::PLAIN_OPTION ) );
		$this->assertArrayHasKey( 'fy-bad-type', get_option( YoastOptionStore::PLAIN_OPTION ) );

		$this->assertCount( 1, $fired );
		$this->assertCount( 17, $fired[0] );
		$backup = $this->yoast->status()['backup'];
		$this->assertSame( 17, $backup['count'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $backup['last_removed_at'] );
		$row = get_option( YoastSource::BACKUP_OPTION )[0];
		$this->assertSame( [ 'origin' => 'fy-old-page', 'url' => 'fy-new-page', 'type' => 301, 'format' => 'plain' ], $row['entry'] );
		$this->assertSame( get_current_user_id(), $row['removed_by'] );
		$this->assertIsInt( $row['removed_at'] );
	}

	public function test_remove_leaves_uncovered_and_missing_entries(): void {
		$this->seed();
		$this->import_all();
		$this->repo->delete( $this->repo->exact_rule_by_key( '/fy-old-page' )->id );

		$result = $this->yoast->remove(
			[
				[ 'origin' => 'fy-old-page', 'format' => 'plain' ],
				[ 'origin' => 'fy-gone', 'format' => 'plain' ],
				[ 'origin' => 'fy-not-there', 'format' => 'plain' ],
				[ 'origin' => 'fy-gone', 'format' => 'regex' ],
				[ 'origin' => 'fy-bad-type', 'format' => 'plain' ],
			]
		);

		$this->assertSame(
			[ 'not_covered', 'removed', 'not_found', 'not_found', 'not_covered' ],
			array_column( $result['items'], 'result' )
		);
		$this->assertSame( [ 1, 2, 2 ], [ $result['removed'], $result['not_found'], $result['not_covered'] ] );
		$this->assertContains( 'fy-old-page', $this->base_origins() );
		$this->assertNotContains( 'fy-gone', $this->base_origins() );
		$this->assertSame( 1, $this->yoast->status()['backup']['count'] );
	}

	public function test_nothing_removed_writes_no_backup_and_fires_nothing(): void {
		$this->seed();
		$fired = 0;
		add_action(
			'adv_redirects_yoast_removed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$result = $this->yoast->remove( [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ] ] );
		$this->assertSame( 'not_covered', $result['items'][0]['result'], 'Nothing was imported yet.' );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertSame( 0, $fired );
	}

	public function test_restore_puts_entries_back_and_clears_the_backup(): void {
		$this->seed();
		list( , $remove ) = $this->import_all();
		$this->yoast->remove( $remove );

		$restored = [];
		add_action(
			'adv_redirects_yoast_restored',
			static function ( $entries ) use ( &$restored ) {
				$restored = $entries;
			}
		);

		$this->assertSame( [ 'restored' => 17, 'already_present' => 0 ], $this->yoast->restore() );
		$this->assertCount( 26, get_option( YoastOptionStore::BASE_OPTION ) );
		$this->assertCount( 17, $restored );
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertCount( 16, $this->repo->all(), 'WP Redirects rules are not touched.' );
		$this->assertSame( [ 'restored' => 0, 'already_present' => 0 ], $this->yoast->restore(), 'Nothing to restore.' );
	}

	public function test_restore_skips_origins_yoast_has_again(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ], [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] );
		$base   = get_option( YoastOptionStore::BASE_OPTION );
		$base[] = [ 'origin' => 'fy-gone', 'url' => 'recreated', 'type' => 301, 'format' => 'plain' ];
		update_option( YoastOptionStore::BASE_OPTION, $base, false );

		$this->assertSame( [ 'restored' => 1, 'already_present' => 1 ], $this->yoast->restore() );
		$this->assertNull( $this->yoast->status()['backup'] );
	}

	public function test_delete_backup(): void {
		$this->seed();
		$this->import_all();
		$this->yoast->remove( [ [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] );
		$this->yoast->delete_backup();
		$this->assertNull( $this->yoast->status()['backup'] );
		$this->assertFalse( get_option( YoastSource::BACKUP_OPTION ) );
	}

	public function test_uninstall_removes_the_backup_option(): void {
		// Uninstaller::run() drops the plugin tables (an implicit commit), so check the option list it deletes.
		$this->assertContains( YoastSource::BACKUP_OPTION, Uninstaller::options() );
	}

	public function test_default_store_is_the_option_store_without_premium(): void {
		$this->seed();
		$this->import_all();
		$yoast = new YoastSource( $this->importer );
		$this->assertFalse( YoastSource::premium_active() );
		$this->assertSame( 1, $yoast->remove( [ [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] )['removed'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php:integration -- --filter YoastSourceTest`
Expected: FAIL with `Class "Advision\Redirects\Import\YoastSource" not found`.

- [ ] **Step 3: Implement `src/Import/YoastSource.php`**

```php
<?php
/**
 * Yoast SEO Premium side of the Yoast import: detects stored redirects, removes imported ones from
 * Yoast (only those WP Redirects now covers), and keeps a backup that can be restored.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class YoastSource {

	public const BACKUP_OPTION = 'adv_redirects_yoast_backup';

	public const METHOD_OPTION = 'wpseo_redirect';

	private const FIELDS = [ 'origin', 'url', 'type', 'format' ];

	private Importer $importer;

	private ?YoastStore $store;

	public function __construct( Importer $importer, ?YoastStore $store = null ) {
		$this->importer = $importer;
		$this->store    = $store;
	}

	public static function premium_active(): bool {
		return defined( 'WPSEO_PREMIUM_VERSION' ) && class_exists( 'WPSEO_Redirect_Manager' );
	}

	/**
	 * How Yoast serves its redirects: from PHP, or from a server configuration file it writes.
	 */
	public static function server_mode(): string {
		$method = get_option( self::METHOD_OPTION );
		if ( ! is_array( $method ) || 'on' !== ( $method['disable_php_redirect'] ?? 'off' ) ) {
			return 'php';
		}

		global $is_apache, $is_nginx;
		$apache = method_exists( 'WPSEO_Utils', 'is_apache' ) ? (bool) \WPSEO_Utils::is_apache() : ! empty( $is_apache );
		if ( $apache ) {
			return 'on' === ( $method['separate_file'] ?? 'off' ) ? 'apache_file' : 'htaccess';
		}
		$nginx = method_exists( 'WPSEO_Utils', 'is_nginx' ) ? (bool) \WPSEO_Utils::is_nginx() : ! empty( $is_nginx );
		return $nginx ? 'nginx' : 'none';
	}

	public function status(): array {
		$entries = $this->entries();
		$counts  = [
			'plain' => 0,
			'regex' => 0,
		];
		foreach ( $entries as $entry ) {
			if ( isset( $entry['format'] ) && is_string( $entry['format'] ) && isset( $counts[ $entry['format'] ] ) ) {
				++$counts[ $entry['format'] ];
			}
		}

		return [
			'detected'        => ! empty( $entries ),
			'premium_active'  => self::premium_active(),
			'premium_version' => defined( 'WPSEO_PREMIUM_VERSION' ) ? (string) constant( 'WPSEO_PREMIUM_VERSION' ) : null,
			'server_mode'     => self::server_mode(),
			'counts'          => $counts,
			'entries'         => $entries,
			'backup'          => $this->backup_summary(),
		];
	}

	/**
	 * The base option in stored order, read raw (Yoast's own read filter does not apply), each
	 * entry reduced to its four fields plus `id`, its 1-based position.
	 *
	 * @return array<int,array>
	 */
	public function entries(): array {
		$base = get_option( YoastOptionStore::BASE_OPTION, [] );
		if ( ! is_array( $base ) ) {
			return [];
		}
		$out = [];
		foreach ( array_values( $base ) as $index => $entry ) {
			$item = [ 'id' => $index + 1 ];
			if ( is_array( $entry ) ) {
				$item += array_intersect_key( $entry, array_flip( self::FIELDS ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Removes Yoast redirects that WP Redirects now covers. An item is removed only if Yoast still
	 * holds it with the same origin and format and WP Redirects has a rule with the same conflict
	 * key; anything else is left in Yoast.
	 *
	 * @param array<int,array{origin:string,format:string}> $items Redirects to remove.
	 */
	public function remove( array $items ): array {
		$items    = array_values( $items );
		$base     = get_option( YoastOptionStore::BASE_OPTION, [] );
		$base     = is_array( $base ) ? $base : [];
		$trailing = Site::trailing_slash_permalinks();
		$results  = [];
		$rules    = [];

		foreach ( $items as $key => $item ) {
			$entry = self::find( $base, (string) $item['origin'], (string) $item['format'] );
			if ( null === $entry ) {
				$results[ $key ] = 'not_found';
				continue;
			}
			$mapped = YoastMapper::map( $entry, $trailing );
			if ( ! $mapped['ok'] ) {
				$results[ $key ] = 'not_covered';
				continue;
			}
			$rules[ $key ] = $mapped['rule'];
		}
		foreach ( $this->importer->covered( $rules ) as $key => $covered ) {
			$results[ $key ] = $covered ? 'removed' : 'not_covered';
		}
		ksort( $results );

		$wanted = [];
		foreach ( $results as $key => $result ) {
			if ( 'removed' === $result ) {
				$wanted[ $key ] = [
					'origin' => (string) $items[ $key ]['origin'],
					'format' => (string) $items[ $key ]['format'],
				];
			}
		}

		$removed = [];
		if ( $wanted ) {
			$removed = $this->store()->remove( array_values( $wanted ) )['removed'];
			// Anything the store no longer found changed in Yoast after the check above.
			$gone = [];
			foreach ( $removed as $entry ) {
				$gone[ $entry['format'] . ':' . $entry['origin'] ] = true;
			}
			foreach ( $wanted as $key => $item ) {
				if ( ! isset( $gone[ $item['format'] . ':' . $item['origin'] ] ) ) {
					$results[ $key ] = 'not_found';
				}
			}
		}

		if ( $removed ) {
			$this->append_backup( $removed );

			/**
			 * Fires after redirects are removed from Yoast SEO Premium.
			 *
			 * @param array $entries Removed base-option entries { origin, url, type, format }.
			 */
			do_action( 'adv_redirects_yoast_removed', $removed );
		}

		$out    = [];
		$counts = [
			'removed'     => 0,
			'not_found'   => 0,
			'not_covered' => 0,
		];
		foreach ( $results as $key => $result ) {
			++$counts[ $result ];
			$out[] = [
				'origin' => (string) $items[ $key ]['origin'],
				'format' => (string) $items[ $key ]['format'],
				'result' => $result,
			];
		}
		return $counts + [ 'items' => $out ];
	}

	/**
	 * Puts every backed-up redirect Yoast doesn't already hold back into Yoast, then clears the
	 * backup. WP Redirects rules are not touched.
	 */
	public function restore(): array {
		$backup = $this->backup();
		if ( ! $backup ) {
			return [
				'restored'        => 0,
				'already_present' => 0,
			];
		}

		$outcome = $this->store()->add( array_column( $backup, 'entry' ) );
		delete_option( self::BACKUP_OPTION );

		if ( $outcome['added'] ) {
			/**
			 * Fires after backed-up redirects are restored to Yoast SEO Premium.
			 *
			 * @param array $entries Restored base-option entries { origin, url, type, format }.
			 */
			do_action( 'adv_redirects_yoast_restored', $outcome['added'] );
		}
		return [
			'restored'        => count( $outcome['added'] ),
			'already_present' => count( $outcome['already_present'] ),
		];
	}

	public function delete_backup(): void {
		delete_option( self::BACKUP_OPTION );
	}

	private function store(): YoastStore {
		if ( null === $this->store ) {
			$this->store = self::premium_active() ? new YoastManagerStore() : new YoastOptionStore();
		}
		return $this->store;
	}

	/**
	 * @param array $base Base option rows.
	 */
	private static function find( array $base, string $origin, string $format ): ?array {
		foreach ( $base as $entry ) {
			if ( is_array( $entry ) && isset( $entry['origin'], $entry['format'] ) && $entry['origin'] === $origin && $entry['format'] === $format ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * @return array<int,array{entry:array,removed_at:int,removed_by:int}>
	 */
	private function backup(): array {
		$backup = get_option( self::BACKUP_OPTION, [] );
		if ( ! is_array( $backup ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$backup,
				static function ( $row ) {
					return is_array( $row ) && isset( $row['entry'] ) && is_array( $row['entry'] );
				}
			)
		);
	}

	private function backup_summary(): ?array {
		$backup = $this->backup();
		if ( ! $backup ) {
			return null;
		}
		return [
			'count'           => count( $backup ),
			'last_removed_at' => gmdate( 'Y-m-d H:i:s', (int) max( array_column( $backup, 'removed_at' ) ) ),
		];
	}

	private function append_backup( array $removed ): void {
		$backup = $this->backup();
		$now    = time();
		$user   = get_current_user_id();
		foreach ( $removed as $entry ) {
			$backup[] = [
				'entry'      => $entry,
				'removed_at' => $now,
				'removed_by' => $user,
			];
		}
		update_option( self::BACKUP_OPTION, $backup, false );
	}
}
```

- [ ] **Step 4: Delete the backup on uninstall**

In `src/Uninstaller.php`, add `use Advision\Redirects\Import\YoastSource;`, add an `options()` list, and delete from it in `run()`:

```php
	/**
	 * Options removed on uninstall.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return [ Settings::OPTION, Schema::VERSION_OPTION, YoastSource::BACKUP_OPTION ];
	}

	public static function run(): void {
		if ( ! self::should_remove() ) {
			return;
		}
		Cron::unschedule();
		Schema::drop_all();
		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
		RuleCache::flush();
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php:integration -- --filter 'YoastSourceTest|PluginTest'` and then `composer lint`.
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Import/YoastSource.php src/Uninstaller.php tests/integration/YoastSourceTest.php
git commit -m "feat: detect Yoast redirects, remove covered ones with a backup, restore

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: REST routes, wiring and hook docs

**Files:**
- Modify: `src/Rest/ImportController.php`, `src/Plugin.php`, `docs/hooks.md`, `tests/integration/RestImportTest.php`, `tests/integration/RestPermissionsTest.php`
- Create: `src/Rest/YoastImportController.php`
- Test: `tests/integration/RestYoastImportTest.php`

**Interfaces:**
- Consumes: `Importer::SOURCE_*` and the new `preview()`/`import()` signatures (Task 2), and `YoastSource` (Task 4).
- Produces:
  - Routes: `GET /import/yoast`, `POST /import/yoast/remove`, `POST /import/yoast/restore`, `DELETE /import/yoast/backup`.
  - `/import/preview` and `/import` accept `source: "yoast"` with body `{ source, redirects }`.
  - `ImportController::MAX_PREVIEW = 5000`, `YoastImportController::MAX_REMOVE = 500`, `Plugin::yoast(): YoastSource`.

- [ ] **Step 1: Write the failing REST test**

`tests/integration/RestYoastImportTest.php`:

```php
<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Import\YoastOptionStore;
use Advision\Redirects\Import\YoastSource;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\ImportController;
use Advision\Redirects\Rest\YoastImportController;

final class RestYoastImportTest extends Adv_Redirects_Rest_TestCase {

	private array $fixture;

	protected function controllers(): array {
		$repo     = new Repository();
		$importer = new Importer( $repo, new Validator( $repo, new ChainResolver() ) );
		return [ new ImportController( $importer ), new YoastImportController( new YoastSource( $importer ) ) ];
	}

	public function set_up() {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
		delete_option( YoastSource::BACKUP_OPTION );
		$this->fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/yoast-redirects-sample.json' ), true );
		update_option( YoastOptionStore::BASE_OPTION, $this->fixture, false );
	}

	private function entries(): array {
		return $this->rest( 'GET', '/import/yoast' )->get_data()['entries'];
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( [ '/import/yoast', '/import/yoast/remove', '/import/yoast/restore', '/import/yoast/backup' ] as $route ) {
			$this->assertArrayHasKey( '/adv-redirects/v1' . $route, $routes, $route );
		}
	}

	public function test_status(): void {
		$response = $this->rest( 'GET', '/import/yoast' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['detected'] );
		$this->assertSame( [ 'plain' => 19, 'regex' => 6 ], $data['counts'] );
		$this->assertSame( 'php', $data['server_mode'] );
		$this->assertNull( $data['backup'] );
	}

	public function test_preview_import_remove_restore(): void {
		$entries = $this->entries();
		$preview = $this->rest( 'POST', '/import/preview', [ 'source' => 'yoast', 'redirects' => $entries ] );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 16, $preview->get_data()['counts']['new'] );

		$import = $this->rest( 'POST', '/import', [ 'source' => 'yoast', 'redirects' => [ $entries[0] ] ] );
		$this->assertSame( 1, $import->get_data()['counts']['created'] );

		$remove = $this->rest( 'POST', '/import/yoast/remove', [ 'entries' => [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ], [ 'origin' => 'fy-gone', 'format' => 'plain' ] ] ] );
		$this->assertSame( 200, $remove->get_status() );
		$this->assertSame( [ 1, 0, 1 ], [ $remove->get_data()['removed'], $remove->get_data()['not_found'], $remove->get_data()['not_covered'] ] );
		$this->assertSame( 1, $this->rest( 'GET', '/import/yoast' )->get_data()['backup']['count'] );

		$restore = $this->rest( 'POST', '/import/yoast/restore', [] );
		$this->assertSame( [ 'restored' => 1, 'already_present' => 0 ], $restore->get_data() );

		$this->rest( 'POST', '/import/yoast/remove', [ 'entries' => [ [ 'origin' => 'fy-old-page', 'format' => 'plain' ] ] ] );
		$delete = $this->rest( 'DELETE', '/import/yoast/backup' );
		$this->assertSame( [ 'deleted' => true ], $delete->get_data() );
		$this->assertNull( $this->rest( 'GET', '/import/yoast' )->get_data()['backup'] );
	}

	public function test_yoast_import_bodies_reject_redirection_fields(): void {
		$one = [ $this->entries()[0] ];
		foreach ( [ 'groups' => [], 'version' => '1' ] as $field => $value ) {
			$body           = [ 'source' => 'yoast', 'redirects' => $one ];
			$body[ $field ] = $value;
			$response       = $this->rest( 'POST', '/import/preview', $body );
			$this->assertSame( 400, $response->get_status(), $field );
			$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'], $field );
		}
		$this->assertSame( 400, $this->rest( 'POST', '/import', [ 'source' => 'yoast', 'redirects' => $one, 'groups' => [] ] )->get_status() );
	}

	public function test_remove_schema(): void {
		$item = [ 'origin' => 'fy-gone', 'format' => 'plain' ];
		$bad  = [
			'empty'         => [ 'entries' => [] ],
			'too many'      => [ 'entries' => array_fill( 0, 501, $item ) ],
			'no format'     => [ 'entries' => [ [ 'origin' => 'a' ] ] ],
			'bad format'    => [ 'entries' => [ [ 'origin' => 'a', 'format' => 'x' ] ] ],
			'empty origin'  => [ 'entries' => [ [ 'origin' => '', 'format' => 'plain' ] ] ],
			'extra prop'    => [ 'entries' => [ $item + [ 'url' => 'x' ] ] ],
			'unknown field' => [ 'entries' => [ $item ], 'force' => true ],
			'missing'       => [],
		];
		foreach ( $bad as $label => $body ) {
			$this->assertSame( 400, $this->rest( 'POST', '/import/yoast/remove', $body )->get_status(), $label );
		}
		$this->assertSame( 200, $this->rest( 'POST', '/import/yoast/remove', [ 'entries' => array_fill( 0, 500, $item ) ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import/yoast/restore', [ 'all' => true ] )->get_status() );
	}

	public function test_preview_limit_is_5000(): void {
		$one = $this->entries()[0];
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', [ 'source' => 'yoast', 'redirects' => array_fill( 0, 5001, $one ) ] )->get_status() );
	}
}
```

- [ ] **Step 2: Update the existing REST tests**

In `tests/integration/RestImportTest.php::test_schema_limits_and_unknown_fields`:
- Change `$bad_source['source'] = 'yoast';` to `$bad_source['source'] = 'rankmath';`.
- Change `array_fill( 0, 2001, $one[0] )` to `array_fill( 0, 5001, $one[0] )`.

In `tests/integration/RestPermissionsTest.php`:
- Add `use Advision\Redirects\Import\Importer;`, `use Advision\Redirects\Import\YoastSource;` and `use Advision\Redirects\Rest\YoastImportController;`.
- In `controllers()`, append `new YoastImportController( new YoastSource( new Importer( $repo, new Validator( $repo, $chains ) ) ) )`.
- Add these rows to `routes()`:

```php
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php:integration -- --filter 'RestYoastImportTest|RestImportTest|RestPermissionsTest'`
Expected: FAIL. The `YoastImportController` class is missing, and the preview with `source: yoast` returns 400.

- [ ] **Step 4: Update `src/Rest/ImportController.php`**

1. Change `public const MAX_PREVIEW = 2000;` to `5000`, and update the file docblock to "REST endpoints for importing Redirection exports and Yoast SEO Premium redirects."
2. Add `use Advision\Redirects\Import\Importer;` (it is already imported for the constructor) and replace both callbacks:

```php
	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$source  = (string) $request['source'];
		$allowed = Importer::SOURCE_YOAST === $source ? [ 'source', 'redirects' ] : [ 'source', 'version', 'groups', 'redirects' ];
		$unknown = $this->reject_unknown( $request, $allowed );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->preview( (array) $request['redirects'], (array) $request['groups'], $source ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$source  = (string) $request['source'];
		$allowed = Importer::SOURCE_YOAST === $source ? [ 'source', 'redirects' ] : [ 'source', 'groups', 'redirects' ];
		$unknown = $this->reject_unknown( $request, $allowed );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->import( (array) $request['redirects'], (array) $request['groups'], $source ) );
	}
```

3. In `args()`, change the `source` enum to `[ Importer::SOURCE_REDIRECTION, Importer::SOURCE_YOAST ]`.

- [ ] **Step 5: Create `src/Rest/YoastImportController.php`**

```php
<?php
/**
 * REST endpoints for the Yoast SEO Premium side of the Yoast import.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Import\YoastSource;

defined( 'ABSPATH' ) || exit;

final class YoastImportController extends BaseController {

	public const MAX_REMOVE = 500;

	private YoastSource $yoast;

	public function __construct( YoastSource $yoast ) {
		$this->yoast = $yoast;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/yoast',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/remove',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'remove' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'entries' => self::arg(
						[
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => self::MAX_REMOVE,
							'items'    => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'origin' => [
										'type'      => 'string',
										'minLength' => 1,
										'maxLength' => 2048,
										'required'  => true,
									],
									'format' => [
										'type'     => 'string',
										'enum'     => [ 'plain', 'regex' ],
										'required' => true,
									],
								],
							],
						]
					),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restore' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/yoast/backup',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_backup' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);
	}

	public function status(): \WP_REST_Response {
		return rest_ensure_response( $this->yoast->status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'entries' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->yoast->remove( (array) $request['entries'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->yoast->restore() );
	}

	public function delete_backup(): \WP_REST_Response {
		$this->yoast->delete_backup();
		return rest_ensure_response( [ 'deleted' => true ] );
	}
}
```

- [ ] **Step 6: Wire it in `src/Plugin.php`**

- Add `use Advision\Redirects\Import\YoastSource;` and `use Advision\Redirects\Rest\YoastImportController;`.
- Add the property `private YoastSource $yoast;`.
- In the constructor, after the importer line, add `$this->yoast = new YoastSource( $this->importer );` and align the `=` signs with the block.
- In `register_routes()`, append `new YoastImportController( $this->yoast ),`.
- Add the accessor next to `importer()`:

```php
	public function yoast(): YoastSource {
		return $this->yoast;
	}
```

- [ ] **Step 7: Document the hooks in `docs/hooks.md`**

Replace the `adv_redirects_import_rule` filter row with:

```markdown
| `adv_redirects_import_rule` | `array\|false $rule, array $entry, string $source` | mapped rule | Change a rule mapped by an import, or return `false` to skip it. `$source` is `redirection` (a Redirection export) or `yoast` (Yoast SEO Premium); `$entry` is the raw export entry or Yoast base-option entry `{ id, origin, url, type, format }`. Returning `false` or any non-array skips the rule (reason `filtered`); a rule whose `source` or `target` becomes unusable after filtering is skipped as `invalid_entry`. |
```

Add these rows after `adv_redirects_import_completed` in the Actions table:

```markdown
| `adv_redirects_yoast_removed` | `array $entries` | After redirects are removed from Yoast SEO Premium's storage (Import tab, "Remove from Yoast"). `$entries` are the removed base-option entries `{ origin, url, type, format }`; they are also kept in the `adv_redirects_yoast_backup` option. |
| `adv_redirects_yoast_restored` | `array $entries` | After backed-up redirects are put back into Yoast SEO Premium ("Restore to Yoast"). Entries Yoast already had again are not included. |
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `npm run test:php:integration` (the whole suite) and then `composer lint`.
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Rest/ImportController.php src/Rest/YoastImportController.php src/Plugin.php docs/hooks.md tests/integration/RestImportTest.php tests/integration/RestYoastImportTest.php tests/integration/RestPermissionsTest.php
git commit -m "feat: add REST routes for Yoast import, removal and restore

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Client helpers, API calls and Jest

**Files:**
- Modify: `assets/src/utils/redirectionImport.js`, `assets/src/api.js`, `tests/js/redirectionImport.test.js`
- Create: `assets/src/utils/yoastImport.js`
- Test: `tests/js/yoastImport.test.js`

**Interfaces:**
- Consumes: the REST shapes from Task 5.
- Produces:
  - In `redirectionImport.js`: `MAX_PREVIEW = 5000`, `NOTE_LABELS.case_sensitive_source`, and `buildReport` using `summary.plugin` (default `'redirection'`).
  - In `yoastImport.js`:
    - `REMOVE_CHUNK = 500`, `NOTICE_KEY`
    - `yoastPayload( entries )`, `batchPayload( payload, redirects )`, `entrySource( entry )`
    - `removalCandidates( preview, redirects, importedIndexes )`
    - `noticeHidden()`, `hideNotice()`, `serverModeWarning( status )`
  - In `api.js`: `api.yoastStatus()`, `api.yoastRemove( entries )`, `api.yoastRestore()`, `api.yoastDeleteBackup()`.

- [ ] **Step 1: Write the failing Jest test**

`tests/js/yoastImport.test.js`:

```js
import fixture from '../fixtures/yoast-redirects-sample.json';
import {
	batchPayload,
	entrySource,
	hideNotice,
	NOTICE_KEY,
	noticeHidden,
	REMOVE_CHUNK,
	removalCandidates,
	serverModeWarning,
	yoastPayload,
} from '../../assets/src/utils/yoastImport';
import {
	buildReport,
	MAX_PREVIEW,
	NOTE_LABELS,
} from '../../assets/src/utils/redirectionImport';

const entries = fixture.map( ( entry, index ) => ( {
	id: index + 1,
	...entry,
} ) );

describe( 'payloads', () => {
	it( 'builds the yoast preview payload from the entries as given', () => {
		expect( yoastPayload( entries ) ).toEqual( {
			source: 'yoast',
			redirects: entries,
		} );
	} );

	it( 'sends groups only for Redirection batches', () => {
		const batch = entries.slice( 0, 2 );
		expect( batchPayload( yoastPayload( entries ), batch ) ).toEqual( {
			source: 'yoast',
			redirects: batch,
		} );
		expect(
			batchPayload(
				{ source: 'redirection', groups: [ { id: 1 } ], redirects: [] },
				batch
			)
		).toEqual( {
			source: 'redirection',
			groups: [ { id: 1 } ],
			redirects: batch,
		} );
	} );

	it( 'reads the source of either kind of entry', () => {
		expect( entrySource( entries[ 0 ] ) ).toBe( 'fy-old-page' );
		expect( entrySource( { url: '/a/' } ) ).toBe( '/a/' );
		expect( entrySource( undefined ) ).toBe( '' );
	} );
} );

describe( 'removalCandidates', () => {
	const preview = {
		entries: [
			{ index: 0, status: 'new' },
			{ index: 1, status: 'new' },
			{ index: 2, status: 'superseded' },
			{ index: 13, status: 'skipped' },
			{ index: 24, status: 'new' },
		],
	};

	it( 'takes imported entries and superseded ones, never skipped ones', () => {
		expect( removalCandidates( preview, entries, [ 0, 24 ] ) ).toEqual( [
			{ origin: 'fy-old-page', format: 'plain' },
			{ origin: 'fy-case-page', format: 'plain' },
			{ origin: 'fy-numeric-type', format: 'plain' },
		] );
	} );

	it( 'leaves out entries the import did not confirm', () => {
		expect( removalCandidates( preview, entries, [] ) ).toEqual( [
			{ origin: 'fy-case-page', format: 'plain' },
		] );
	} );
} );

describe( 'notice storage', () => {
	afterEach( () => {
		jest.restoreAllMocks();
		window.localStorage.clear();
	} );

	it( 'remembers "Not now"', () => {
		expect( noticeHidden() ).toBe( false );
		hideNotice();
		expect( window.localStorage.getItem( NOTICE_KEY ) ).toBe( '1' );
		expect( noticeHidden() ).toBe( true );
	} );

	it( 'noticeHidden and hideNotice survive a throwing localStorage', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		expect( noticeHidden() ).toBe( false );
		expect( () => hideNotice() ).not.toThrow();
	} );
} );

describe( 'serverModeWarning', () => {
	it( 'is empty for PHP redirects and missing status', () => {
		expect( serverModeWarning( null ) ).toBe( '' );
		expect(
			serverModeWarning( { server_mode: 'php', premium_active: true } )
		).toBe( '' );
		expect(
			serverModeWarning( { server_mode: 'none', premium_active: false } )
		).toBe( '' );
	} );

	it( 'warns about server files, differently with and without Premium', () => {
		const active = serverModeWarning( {
			server_mode: 'nginx',
			premium_active: true,
		} );
		const inactive = serverModeWarning( {
			server_mode: 'htaccess',
			premium_active: false,
		} );
		expect( active ).toMatch( /reload/ );
		expect( inactive ).toMatch( /does not edit server files/ );
	} );
} );

describe( 'shared import changes', () => {
	it( 'raises the preview limit and labels the new note', () => {
		expect( MAX_PREVIEW ).toBe( 5000 );
		expect( REMOVE_CHUNK ).toBe( 500 );
		expect( NOTE_LABELS.case_sensitive_source ).toMatch( /case/i );
	} );

	it( 'names the source plugin in the report', () => {
		const report = buildReport( {
			summary: { plugin: 'yoast', version: '27.3', date: '' },
			preview: { entries: [] },
		} );
		expect( report.file ).toEqual( {
			plugin: 'yoast',
			version: '27.3',
			date: '',
		} );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- tests/js/yoastImport.test.js`
Expected: FAIL with `Cannot find module '../../assets/src/utils/yoastImport'`.

- [ ] **Step 3: Create `assets/src/utils/yoastImport.js`**

```js
import { __ } from '@wordpress/i18n';

export const REMOVE_CHUNK = 500;
export const NOTICE_KEY = 'adv_redirects_yoast_notice_hidden';

const SERVER_FILE_MODES = [ 'htaccess', 'apache_file', 'nginx' ];

export function yoastPayload( entries ) {
	return { source: 'yoast', redirects: entries };
}

/**
 * The body for one /import batch: Redirection batches carry the groups, Yoast batches don't.
 *
 * @param {Object}   payload   The preview payload.
 * @param {Object[]} redirects Raw entries for this batch.
 * @return {Object} Request body.
 */
export function batchPayload( payload, redirects ) {
	if ( payload.source === 'yoast' ) {
		return { source: 'yoast', redirects };
	}
	return { source: payload.source, groups: payload.groups, redirects };
}

export function entrySource( entry ) {
	if ( ! entry ) {
		return '';
	}
	if ( typeof entry.origin === 'string' ) {
		return entry.origin;
	}
	return typeof entry.url === 'string' ? entry.url : '';
}

/**
 * What to remove from Yoast after an import: the entries the import confirmed as created or
 * updated, plus the superseded ones (their winner now answers those URLs). The server still
 * checks each one against the stored rules before removing it.
 *
 * @param {Object}   preview         Preview response.
 * @param {Object[]} redirects       The entries the preview was built from.
 * @param {number[]} importedIndexes Preview indexes the import reported as created or updated.
 * @return {Array<{origin: string, format: string}>} Items for /import/yoast/remove.
 */
export function removalCandidates( preview, redirects, importedIndexes ) {
	const imported = new Set( importedIndexes );
	return preview.entries
		.filter(
			( entry ) =>
				imported.has( entry.index ) || entry.status === 'superseded'
		)
		.map( ( entry ) => redirects[ entry.index ] )
		.filter( Boolean )
		.map( ( entry ) => ( { origin: entry.origin, format: entry.format } ) );
}

export function noticeHidden() {
	try {
		return window.localStorage.getItem( NOTICE_KEY ) === '1';
	} catch {
		return false;
	}
}

export function hideNotice() {
	try {
		window.localStorage.setItem( NOTICE_KEY, '1' );
	} catch {
		// Storage blocked: the notice comes back next visit.
	}
}

export function serverModeWarning( status ) {
	if ( ! status || ! SERVER_FILE_MODES.includes( status.server_mode ) ) {
		return '';
	}
	return status.premium_active
		? __(
				'Yoast writes these redirects to your server configuration. Removing them updates that file; nginx needs a reload to pick it up.',
				'wp-redirects'
			)
		: __(
				'Yoast’s redirects are still in your server configuration and keep working until that block is removed. WP Redirects does not edit server files.',
				'wp-redirects'
			);
}
```

- [ ] **Step 4: Update `assets/src/utils/redirectionImport.js`**

- `export const MAX_PREVIEW = 5000;`
- Add to `NOTE_LABELS`:

```js
	case_sensitive_source: __(
		'Yoast matched this pattern case-sensitively; WP Redirects matches regardless of case.',
		'wp-redirects'
	),
```

- In `buildReport`, change `plugin: 'redirection',` to `plugin: summary.plugin || 'redirection',`.

- [ ] **Step 5: Add the API calls to `assets/src/api.js`**

Inside `api`, after `importBatch`:

```js
	yoastStatus: () => apiFetch( { path: `${ NS }/import/yoast` } ),
	yoastRemove: ( entries ) =>
		apiFetch( {
			path: `${ NS }/import/yoast/remove`,
			method: 'POST',
			data: { entries },
		} ),
	yoastRestore: () =>
		apiFetch( {
			path: `${ NS }/import/yoast/restore`,
			method: 'POST',
			data: {},
		} ),
	yoastDeleteBackup: () =>
		apiFetch( { path: `${ NS }/import/yoast/backup`, method: 'DELETE' } ),
```

- [ ] **Step 6: Update the existing Jest test**

In `tests/js/redirectionImport.test.js`, find the case that checks the too-many-redirects error. It builds an export with `MAX_PREVIEW + 1` entries or a literal `2001`. If it uses a literal, change it to `MAX_PREVIEW + 1` (import `MAX_PREVIEW`), so it follows the constant. The report test that expects `plugin: 'redirection'` keeps passing, because the Redirection summary has no `plugin` key.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:js` and then `npm run lint:js`.
Expected: all PASS, no lint errors.

- [ ] **Step 8: Commit**

```bash
git add assets/src/utils/yoastImport.js assets/src/utils/redirectionImport.js assets/src/api.js tests/js/yoastImport.test.js tests/js/redirectionImport.test.js
git commit -m "feat: client helpers and API calls for the Yoast import

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Import tab UI

**Files:**
- Create: `assets/src/components/YoastNotices.js`, `assets/src/components/YoastRemoveCard.js`
- Modify: `assets/src/components/ImportTab.js`, `assets/src/admin.scss`

**Interfaces:**
- Consumes: Task 6 helpers and API calls, `ConfirmModal`, `formatGmt` from `utils/attribution`, `errorMessage` from `constants`, and `chunk`/`MAX_PREVIEW` from `utils/redirectionImport`.
- Produces: no new exports beyond the two default-exported components. Accessible names that Task 8 relies on:
  - "Preview Yoast import", "Not now"
  - "Remove from Yoast", "Keep in Yoast"
  - "Restore to Yoast", "Delete backup"
  - The texts "Yoast SEO Premium redirects found: %1$d plain, %2$d regex.", "Remove %d imported redirects from Yoast?", "Removed %d redirects from Yoast.", "%1$d redirects removed from Yoast, last on %2$s." and "Restored %1$d redirects to Yoast; %2$d were already there."

- [ ] **Step 1: Create `assets/src/components/YoastNotices.js`**

```js
import { Button, Notice } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { formatGmt } from '../utils/attribution';
import { MAX_PREVIEW } from '../utils/redirectionImport';
import {
	hideNotice,
	noticeHidden,
	serverModeWarning,
} from '../utils/yoastImport';
import ConfirmModal from './ConfirmModal';

export default function YoastNotices( {
	status,
	busy,
	flowActive,
	onPreview,
	onChanged,
} ) {
	const [ hidden, setHidden ] = useState( noticeHidden );
	const [ confirm, setConfirm ] = useState( null );
	const [ working, setWorking ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const messageRef = useRef();

	if ( ! status ) {
		return null;
	}

	const total = status.entries.length;
	const warning = serverModeWarning( status );
	const backupCount = status.backup ? status.backup.count : 0;

	const run = async ( action ) => {
		setConfirm( null );
		setWorking( true );
		setError( '' );
		setMessage( '' );
		try {
			if ( action === 'restore' ) {
				const result = await api.yoastRestore();
				setMessage(
					sprintf(
						/* translators: 1: redirects restored, 2: redirects Yoast already had */
						__(
							'Restored %1$d redirects to Yoast; %2$d were already there.',
							'wp-redirects'
						),
						result.restored,
						result.already_present
					)
				);
			} else {
				await api.yoastDeleteBackup();
				setMessage( __( 'Backup deleted.', 'wp-redirects' ) );
			}
			await onChanged();
		} catch ( requestError ) {
			setError( errorMessage( requestError ) );
		} finally {
			setWorking( false );
			// The notice that held the focused button may be gone; keep focus in the flow.
			messageRef.current?.focus();
		}
	};

	return (
		<div className="adv-redirects-yoast">
			{ status.detected && ! hidden && ! flowActive && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: plain redirects, 2: regex redirects */
							__(
								'Yoast SEO Premium redirects found: %1$d plain, %2$d regex.',
								'wp-redirects'
							),
							status.counts.plain,
							status.counts.regex
						) }
					</p>
					{ status.premium_active && (
						<p>
							{ __(
								'Yoast serves these first until you remove them from Yoast after importing.',
								'wp-redirects'
							) }
						</p>
					) }
					{ warning && <p>{ warning }</p> }
					{ total > MAX_PREVIEW && (
						<p>
							{ sprintf(
								/* translators: 1: number of Yoast redirects, 2: maximum per import */
								__(
									'Yoast has %1$d redirects; the maximum per import is %2$d.',
									'wp-redirects'
								),
								total,
								MAX_PREVIEW
							) }
						</p>
					) }
					<div className="adv-redirects-import__actions">
						<Button
							variant="primary"
							disabled={ busy || total > MAX_PREVIEW }
							onClick={ onPreview }
							__next40pxDefaultSize
						>
							{ __( 'Preview Yoast import', 'wp-redirects' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								hideNotice();
								setHidden( true );
							} }
						>
							{ __( 'Not now', 'wp-redirects' ) }
						</Button>
					</div>
				</Notice>
			) }

			{ status.backup && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: 1: number of redirects, 2: date */
							_n(
								'%1$d redirect removed from Yoast, last on %2$s.',
								'%1$d redirects removed from Yoast, last on %2$s.',
								backupCount,
								'wp-redirects'
							),
							backupCount,
							formatGmt( status.backup.last_removed_at )
						) }
					</p>
					<div className="adv-redirects-import__actions">
						<Button
							variant="secondary"
							disabled={ busy || working }
							onClick={ () => setConfirm( 'restore' ) }
						>
							{ __( 'Restore to Yoast', 'wp-redirects' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy || working }
							onClick={ () => setConfirm( 'delete' ) }
						>
							{ __( 'Delete backup', 'wp-redirects' ) }
						</Button>
					</div>
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ /* Always mounted so results are announced. */ }
			<p
				ref={ messageRef }
				tabIndex={ -1 }
				role="status"
				className="adv-redirects-yoast__message"
			>
				{ message }
			</p>

			{ confirm === 'restore' && (
				<ConfirmModal
					title={ __( 'Restore redirects to Yoast?', 'wp-redirects' ) }
					message={ sprintf(
						/* translators: %d: number of backed-up redirects */
						__(
							'This puts the %d backed-up redirects back into Yoast SEO Premium. Redirects Yoast already has are left as they are. Your WP Redirects rules are not changed.',
							'wp-redirects'
						),
						backupCount
					) }
					confirmLabel={ __( 'Restore to Yoast', 'wp-redirects' ) }
					onConfirm={ () => run( 'restore' ) }
					onCancel={ () => setConfirm( null ) }
				/>
			) }
			{ confirm === 'delete' && (
				<ConfirmModal
					title={ __( 'Delete the Yoast backup?', 'wp-redirects' ) }
					message={ sprintf(
						/* translators: %d: number of backed-up redirects */
						__(
							'The %d backed-up redirects can no longer be restored to Yoast. Your WP Redirects rules are not changed.',
							'wp-redirects'
						),
						backupCount
					) }
					confirmLabel={ __( 'Delete backup', 'wp-redirects' ) }
					onConfirm={ () => run( 'delete' ) }
					onCancel={ () => setConfirm( null ) }
				/>
			) }
		</div>
	);
}
```

- [ ] **Step 2: Create `assets/src/components/YoastRemoveCard.js`**

```js
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { chunk } from '../utils/redirectionImport';
import { REMOVE_CHUNK, serverModeWarning } from '../utils/yoastImport';

export default function YoastRemoveCard( { candidates, status, onDone } ) {
	// ask | removing | removed | kept | failed
	const [ state, setState ] = useState( 'ask' );
	const [ done, setDone ] = useState( 0 );
	const [ totals, setTotals ] = useState( {
		removed: 0,
		notCovered: 0,
		notFound: 0,
	} );
	const [ error, setError ] = useState( '' );
	const resultRef = useRef();

	useEffect( () => {
		if ( [ 'removed', 'kept', 'failed' ].includes( state ) ) {
			resultRef.current?.focus();
		}
	}, [ state ] );

	if ( ! candidates.length ) {
		return null;
	}

	const remove = async () => {
		const sum = { removed: 0, notCovered: 0, notFound: 0 };
		let sent = 0;
		setState( 'removing' );
		setDone( 0 );
		setError( '' );
		try {
			for ( const items of chunk( candidates, REMOVE_CHUNK ) ) {
				const response = await api.yoastRemove( items );
				sum.removed += response.removed;
				sum.notCovered += response.not_covered;
				sum.notFound += response.not_found;
				sent += items.length;
				setDone( sent );
			}
			setTotals( sum );
			setState( 'removed' );
		} catch ( requestError ) {
			setTotals( sum );
			setError( errorMessage( requestError ) );
			setState( 'failed' );
		} finally {
			onDone();
		}
	};

	const warning = serverModeWarning( status );
	const left = totals.notCovered + totals.notFound;

	return (
		<div className="adv-redirects-yoast-remove">
			{ ( state === 'ask' || state === 'failed' ) && (
				<>
					<p>
						{ sprintf(
							/* translators: %d: number of redirects */
							_n(
								'Remove %d imported redirect from Yoast? A backup is kept, and you can restore it here.',
								'Remove %d imported redirects from Yoast? A backup is kept, and you can restore it here.',
								candidates.length,
								'wp-redirects'
							),
							candidates.length
						) }
					</p>
					{ status && status.premium_active && (
						<p>
							{ __(
								'While Yoast SEO Premium is active, it serves these redirects before WP Redirects does.',
								'wp-redirects'
							) }
						</p>
					) }
					{ warning && <p>{ warning }</p> }
				</>
			) }

			<div ref={ resultRef } tabIndex={ -1 }>
				{ state === 'removed' && (
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: number of redirects */
							_n(
								'Removed %d redirect from Yoast.',
								'Removed %d redirects from Yoast.',
								totals.removed,
								'wp-redirects'
							),
							totals.removed
						) }
						{ left > 0 &&
							' ' +
								sprintf(
									/* translators: %d: number of redirects */
									_n(
										'%d was left in Yoast: WP Redirects has no matching rule, or Yoast no longer has it.',
										'%d were left in Yoast: WP Redirects has no matching rule, or Yoast no longer has them.',
										left,
										'wp-redirects'
									),
									left
								) }
					</Notice>
				) }
				{ state === 'kept' && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Kept in Yoast.', 'wp-redirects' ) }
					</Notice>
				) }
				{ state === 'failed' && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: error message, 2: redirects removed before the failure */
							__(
								'Removing stopped: %1$s. %2$d redirects were removed before it stopped; running it again is safe.',
								'wp-redirects'
							),
							error.replace( /\.+$/, '' ),
							totals.removed
						) }
					</Notice>
				) }
			</div>

			{ /* Always mounted so the first update is announced. */ }
			<p aria-live="polite">
				{ state === 'removing' && (
					<>
						{ sprintf(
							/* translators: 1: processed so far, 2: total */
							__( 'Removing %1$d of %2$d…', 'wp-redirects' ),
							done,
							candidates.length
						) }{ ' ' }
						<Spinner />
					</>
				) }
			</p>

			{ ( state === 'ask' || state === 'failed' ) && (
				<div className="adv-redirects-import__actions">
					<Button
						variant="primary"
						isDestructive
						onClick={ remove }
						__next40pxDefaultSize
					>
						{ __( 'Remove from Yoast', 'wp-redirects' ) }
					</Button>
					{ state === 'ask' && (
						<Button
							variant="tertiary"
							onClick={ () => setState( 'kept' ) }
						>
							{ __( 'Keep in Yoast', 'wp-redirects' ) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
}
```

- [ ] **Step 3: Generalize `assets/src/components/ImportTab.js`**

Make these edits:

1. Imports. Change the element import to `import { useCallback, useEffect, useRef, useState } from '@wordpress/element';`, then add:

```js
import {
	batchPayload,
	entrySource,
	removalCandidates,
	yoastPayload,
} from '../utils/yoastImport';
import YoastNotices from './YoastNotices';
import YoastRemoveCard from './YoastRemoveCard';
```

2. State and loading. After the existing `useState` calls, add:

```js
	const [ yoast, setYoast ] = useState( null );

	const loadYoast = useCallback( async () => {
		try {
			setYoast( await api.yoastStatus() );
		} catch {
			setYoast( null );
		}
	}, [] );

	useEffect( () => {
		loadYoast();
	}, [ loadYoast ] );
```

3. `runPreview` takes the payload as an argument, so the Yoast flow can start it in the same tick it sets the payload:

```js
	const runPreview = async ( data = payload ) => {
		setPhase( 'previewing' );
		setRequestError( '' );
		try {
			setPreview( await api.importPreview( data ) );
			setPhase( 'previewed' );
		} catch ( error ) {
			setRequestError( importErrorMessage( error ) );
			setPhase( 'checked' );
		}
	};

	const startYoast = () => {
		const data = yoastPayload( yoast.entries );
		reset();
		setPayload( data );
		runPreview( data );
	};
```

   Change the file card's preview button from `onClick={ runPreview }` to `onClick={ () => runPreview() }`. Otherwise React passes the click event as `data`.

4. In `runImport`:
   - Add `imported: []` to `totals` (`const totals = { created: 0, updated: 0, skipped: [], imported: [] };`).
   - Replace the request body with `api.importBatch( batchPayload( payload, batch.map( ( item ) => item.entry ) ) )`.
   - In the per-entry loop, push `batch[ position ].index` to `totals.imported` for both `created` and `updated`.
   - Replace `source: batch[ position ].entry.url,` with `source: entrySource( batch[ position ].entry ),`.

5. Above the `const counts = …` line, add:

```js
	const yoastFlow = Boolean( payload && payload.source === 'yoast' );
	const reportSummary = yoastFlow
		? {
				plugin: 'yoast',
				version: ( yoast && yoast.premium_version ) || '',
				date: '',
			}
		: check && check.summary;
```

   In the Download report button, change `summary: check.summary,` to `summary: reportSummary,`.

6. Render the notices first inside the fragment, before the file card `<section>`:

```js
			<YoastNotices
				status={ yoast }
				busy={ busy }
				flowActive={ yoastFlow && phase !== 'idle' }
				onPreview={ startYoast }
				onChanged={ loadYoast }
			/>
```

7. In the result `<section>`, between the `resultRef` div and `adv-redirects-import__actions`:

```js
					{ yoastFlow && phase === 'done' && ! result.cancelled && (
						<YoastRemoveCard
							candidates={ removalCandidates(
								preview,
								payload.redirects,
								result.imported
							) }
							status={ yoast }
							onDone={ loadYoast }
						/>
					) }
```

8. In the `finally` of `runImport`, after `onImported();`, add `if ( payload.source === 'yoast' ) { loadYoast(); }` so the notice counts stay current.

- [ ] **Step 4: Styles in `assets/src/admin.scss`**

Append next to the other `.adv-redirects-import` rules:

```scss
.adv-redirects-yoast {
	.components-notice {
		margin: 0 0 16px;
	}

	.components-notice p {
		margin: 0 0 8px;
	}
}

.adv-redirects-yoast__message:empty {
	display: none;
}

.adv-redirects-yoast-remove {
	margin: 16px 0;
}
```

- [ ] **Step 5: Build, lint and check in the browser**

Run: `npm run build && npm run lint:js && npm run test:js`
Expected: build succeeds, no lint errors, Jest passes.

Then, with `npm run env:start` running, seed the dev site and click through the flow at `http://localhost:8888/wp-admin/admin.php?page=adv-redirects` (Import tab):

```bash
npx wp-env run cli wp option update wpseo-premium-redirects-base "$(cat tests/fixtures/yoast-redirects-sample.json)" --format=json
```

Check each step:
1. The notice shows "19 plain, 6 regex". **Preview Yoast import** shows New (16), Skipped (9), Superseded (1).
2. **Import 16 redirects** reports 16 created, and the removal card asks to remove 17.
3. **Remove from Yoast** shows "Removed 17 redirects from Yoast.", and the backup notice appears.
4. **Restore to Yoast** with its confirm modal restores 17.
5. Keyboard only: Tab reaches every button, and focus lands on each result.

Clean up with `npx wp-env run cli wp option delete wpseo-premium-redirects-base wpseo-premium-redirects-export-plain wpseo-premium-redirects-export-regex adv_redirects_yoast_backup`, and delete the imported rules on the Redirects tab.

- [ ] **Step 6: Commit**

```bash
git add assets/src/components/YoastNotices.js assets/src/components/YoastRemoveCard.js assets/src/components/ImportTab.js assets/src/admin.scss
git commit -m "feat: Yoast import notices, removal card and restore in the Import tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: End-to-end test and docs

**Files:**
- Create: `tests/e2e/yoast-import.spec.js`
- Modify: `readme.txt`, `CLAUDE.md`

**Interfaces:**
- Consumes: the accessible names from Task 7 and the fixture outcomes from Task 1.
- Produces: nothing new.

- [ ] **Step 1: Write the e2e test**

`tests/e2e/yoast-import.spec.js`:

```js
const { execFileSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const fixture = require( '../fixtures/yoast-redirects-sample.json' );

const OPTIONS = [
	'wpseo-premium-redirects-base',
	'wpseo-premium-redirects-export-plain',
	'wpseo-premium-redirects-export-regex',
	'adv_redirects_yoast_backup',
];

// wp-cli on the tests site. wp-env prints status lines too; the command output is the last line.
function wp( ...args ) {
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
	);
	return out.trim().split( '\n' ).pop();
}

function clearYoast() {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch {
			// Not set.
		}
	}
}

function seedYoast() {
	wp(
		'option',
		'update',
		'wpseo-premium-redirects-base',
		JSON.stringify( fixture ),
		'--format=json'
	);
}

function yoastCount() {
	return JSON.parse(
		wp( 'option', 'get', 'wpseo-premium-redirects-base', '--format=json' )
	).length;
}

async function deleteAllRedirects( requestUtils ) {
	const rules = await requestUtils.rest( {
		path: '/adv-redirects/v1/redirects',
	} );
	for ( const rule of rules ) {
		await requestUtils.rest( {
			path: `/adv-redirects/v1/redirects/${ rule.id }`,
			method: 'DELETE',
		} );
	}
}

test.describe( 'Import from Yoast SEO Premium', () => {
	test.beforeEach( async ( { requestUtils, admin, page } ) => {
		await deleteAllRedirects( requestUtils );
		clearYoast();
		seedYoast();
		// Forget an earlier "Not now". Storage then persists across this test's navigations.
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.evaluate( () => window.localStorage.clear() );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
		clearYoast();
	} );

	test( 'imports, removes from Yoast and restores', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		// WordPress's a11y speak regions repeat notice text, so scope to the tab panel.
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );

		await expect(
			panel.getByText(
				'Yoast SEO Premium redirects found: 19 plain, 6 regex.'
			)
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Preview Yoast import' } )
			.click();
		await expect( page.getByText( 'New (16)' ) ).toBeVisible();
		await expect( page.getByText( 'Skipped (9)' ) ).toBeVisible();
		await expect( page.getByText( 'Superseded (1)' ) ).toBeVisible();
		await expect(
			panel.getByText( 'Yoast entry #2 already covers this source.' )
		).toBeAttached();

		await page
			.getByRole( 'button', { name: 'Import 16 redirects' } )
			.click();
		await expect(
			panel.getByText(
				/Import complete: 16 created, 0 updated, 9 skipped, 1 superseded\./
			)
		).toBeVisible();
		await expect(
			panel.getByText( /Remove 17 imported redirects from Yoast\?/ )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Remove from Yoast' } ).click();
		await expect(
			panel.getByText( 'Removed 17 redirects from Yoast.' )
		).toBeVisible();
		expect( yoastCount() ).toBe( 9 );
		await expect(
			panel.getByText( /17 redirects removed from Yoast, last on/ )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Restore to Yoast' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Restore to Yoast' } )
			.click();
		await expect(
			panel.getByText(
				'Restored 17 redirects to Yoast; 0 were already there.'
			)
		).toBeVisible();
		expect( yoastCount() ).toBe( 26 );
	} );

	test( '"Not now" hides the notice in this browser', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		const panel = page.getByRole( 'tabpanel', { name: 'Import' } );
		await page.getByRole( 'button', { name: 'Not now' } ).click();
		await expect(
			panel.getByText( /Yoast SEO Premium redirects found/ )
		).toHaveCount( 0 );

		await page.reload();
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await expect(
			page
				.getByRole( 'tabpanel', { name: 'Import' } )
				.getByText( /Yoast SEO Premium redirects found/ )
		).toHaveCount( 0 );
	} );
} );
```

- [ ] **Step 2: Run the e2e suite**

Run: `npx wp-env clean tests && npm run env:start && npm run test:e2e`
Expected: all specs PASS, including the existing `import.spec.js` and `redirects.spec.js`. The Yoast notice must not appear there, because `afterAll` clears the options.

- [ ] **Step 3: Update `readme.txt`**

- Change the line "Imports take up to 2,000 redirects per file; …" to say **5,000**.
- After the Redirection import feature line, add `* Import redirects from Yoast SEO Premium, then remove them from Yoast with a backup you can restore`.
- In the changelog's unreleased section (or the top entry, following the file's existing pattern), add `* Import from Yoast SEO Premium.`.

- [ ] **Step 4: Update `CLAUDE.md`**

Replace the `src/Import/` line in **Layout** with:

```markdown
- `src/Import/`: `RedirectionMapper` and `YoastMapper` (pure mapping of Redirection export entries and Yoast SEO Premium base-option entries), `Importer` (preview/import through Validator + Repository, per `source`), `YoastSource` (detects Yoast redirects, removes imported ones with a backup, restores) writing through `YoastOptionStore` or, with Premium active, `YoastManagerStore`; UI in `assets/src/components/ImportTab.js`, `YoastNotices.js`, `YoastRemoveCard.js`
```

Add under **Testing notes**:

```markdown
- Yoast import tests use the hand-made `tests/fixtures/yoast-redirects-sample.json`. Yoast SEO Premium is not installed in wp-env; `tests/integration/support/yoast-premium-doubles.php` stands in for its redirect classes (loaded only by `YoastStoreTest`). The e2e test seeds Yoast's options with wp-cli.
```

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/yoast-import.spec.js readme.txt CLAUDE.md
git commit -m "test: cover Yoast import end to end; document it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: BMR acceptance run (manual, real Yoast Premium 27.3)

No code is committed in this task. It checks the work against a real site with Yoast SEO Premium active and 3,292 redirects. **Never copy BMR data into the repo.**

**Preconditions:** the `bmr-wp` and `bmr-db` containers are running. The plugin directory `bookmakersreview-site/wp-content/plugins` is bind-mounted, so installing the plugin writes into that repo's working tree. **Ask the user before Step 2** and remove the copy in Step 8.

- [ ] **Step 1: Back up the BMR database**

```bash
SCRATCH=<session scratchpad dir>
docker exec bmr-wp wp --allow-root db export /tmp/bmr-before-yoast-import.sql
docker cp bmr-wp:/tmp/bmr-before-yoast-import.sql "$SCRATCH/"
```

Expected: the dump exists in the scratchpad and is not empty.

- [ ] **Step 2: Install the built plugin (after the user approves)**

```bash
npm run build
rsync -a --delete --exclude node_modules --exclude .git --exclude tests --exclude docs ./ /Users/core/dev/advision/bookmakersreview-site/wp-content/plugins/wp-redirects/
docker exec bmr-wp wp --allow-root plugin activate wp-redirects
```

Expected: `Plugin 'wp-redirects' activated.`

- [ ] **Step 3: Check detection**

```bash
docker exec bmr-wp wp --allow-root eval '$s = \Advision\Redirects\Plugin::instance()->yoast()->status(); unset( $s["entries"] ); echo wp_json_encode( $s ), "\n";' 2>/dev/null | tail -1
```

Expected: `detected: true`, `premium_active: true`, `premium_version: "27.3"`, `server_mode: "php"`, `counts: {plain: 3135, regex: 157}`, `backup: null`.

- [ ] **Step 4: Timed preview (gate: under 20 s)**

```bash
docker exec bmr-wp wp --allow-root eval '$p = \Advision\Redirects\Plugin::instance(); $t = microtime( true ); $r = $p->importer()->preview( $p->yoast()->entries(), [], "yoast" ); echo wp_json_encode( $r["counts"] ), " ", round( microtime( true ) - $t, 1 ), "s\n";' 2>/dev/null | tail -1
```

Expected: the counts print, and the time is under 20 s. **If it is 20 s or more, stop and report to the user.** The spec requires redesigning the preview before shipping.

Check the skipped reasons too: the 37 `http://localhost:8080/…` regexes should be `unreachable_regex`, the 2 empty-target 301s `invalid_entry`, and about 299 case collisions `superseded`. Print the skip codes with:

```bash
docker exec bmr-wp wp --allow-root eval '$p = \Advision\Redirects\Plugin::instance(); $r = $p->importer()->preview( $p->yoast()->entries(), [], "yoast" ); $c = []; foreach ( $r["entries"] as $e ) { if ( $e["error"] ) { $c[ $e["error"]["code"] ] = ( $c[ $e["error"]["code"] ] ?? 0 ) + 1; } } echo wp_json_encode( $c ), "\n";' 2>/dev/null | tail -1
```

- [ ] **Step 5: Run the UI flow in the browser**

Log in at `http://localhost:8080/wp-admin/` (the user provides credentials or does this step), go to Redirects → Import, and run:
1. **Preview Yoast import**: time it, under 20 s.
2. **Import N redirects**: it reaches 100%.
3. **Remove from Yoast**.

Expected: the removal card reports "Removed X redirects from Yoast." with X equal to created + updated + superseded, minus anything `not_covered`. The backup notice appears.

- [ ] **Step 6: Spot-check live redirects**

Before Step 5's removal, note 3 plain origins, 2 regex patterns with sample paths and 1 410 origin from the preview. After removal, check each one:

```bash
curl -sI "http://localhost:8080/<path>" | grep -iE '^(HTTP|location|x-redirect-by)'
```

Expected: the same status and location as before. For 3xx responses, `X-Redirect-By: WP Redirects` (it was `Yoast SEO Premium` before removal). The 410 now comes from WP Redirects. Also confirm that `wp option get wpseo-premium-redirects-export-plain --format=count` dropped by the removed plain count. That proves Yoast's own manager rewrote its export options.

- [ ] **Step 7: Restore and check**

Click **Restore to Yoast** and confirm. Expected: "Restored X redirects to Yoast; 0 were already there.", and `status().counts` is back to 3135 / 157.

- [ ] **Step 8: Put BMR back exactly as it was**

```bash
docker exec bmr-wp wp --allow-root plugin deactivate wp-redirects
rm -rf /Users/core/dev/advision/bookmakersreview-site/wp-content/plugins/wp-redirects
docker cp "$SCRATCH/bmr-before-yoast-import.sql" bmr-wp:/tmp/
docker exec bmr-wp wp --allow-root db import /tmp/bmr-before-yoast-import.sql
git -C /Users/core/dev/advision/bookmakersreview-site status --short
```

Expected: the database is restored, and `git status` in bookmakersreview-site shows no change caused by this run.

- [ ] **Step 9: Report**

Tell the user:
- the preview time
- the counts per status and skip code
- the removal result
- the spot-check table: path, status and location before and after, X-Redirect-By
- anything unexpected

Then run the full suite once more: `composer lint && npm run test:php:unit && npm run test:php:integration && npm run lint:js && npm run test:js`.
