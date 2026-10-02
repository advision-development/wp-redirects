# Import from Redirection: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins upload a Redirection plugin JSON export, check it against the schema in the browser, preview exactly what will happen (new / overwrite / superseded / skipped, with chain warnings and notes), then import the rules in batches with a progress bar. On conflict the imported rule wins.

**Architecture:**
- **Browser:** the admin reads the file, validates its shape, and strips it to `plugin.version`, `groups` and `redirects`. Logs and 404 records, which contain IPs and user agents, never leave the browser.
- **Server:** a pure PHP `RedirectionMapper` maps entries. An `Importer` service plans each entry, validates it through the existing `Validator` and writes through `Repository`.
- **Accurate preview:** the Validator accepts optional *pending rows* (the import rules ahead of the current one), so the dry-run preview catches loops between imported rules.
- **Endpoints:** two REST routes, `/import/preview` and `/import`.
- **UI:** a fourth admin tab, Import, drives the batches.

**Tech Stack:** as in the main plan: PHP 7.4+ / WP 6.6+, `$wpdb` via Repository, REST (`adv-redirects/v1`), React with `@wordpress/components`, PHPUnit unit and integration (wp-env), Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-02-wp-redirects-design.md` §17. Also read §16 amendment 8 and the rest of the spec for the existing system.

## Global Constraints

- PHP floor **7.4**: no `match`, constructor promotion, union types, named arguments, `mixed`, nullsafe `?->`, or `throw` expressions. Arrow functions are allowed. Pure classes (`src/Import/RedirectionMapper.php`) must not call WordPress functions.
- WordPress floor **6.6**: only `@wordpress/components` APIs that are stable in 6.6, no `__experimental*`, never `dangerouslySetInnerHTML`.
- Every PHP file in `src/` starts with `defined( 'ABSPATH' ) || exit;` after the namespace line.
- Every SQL query containing variables uses `$wpdb->prepare()` with `%i` table names. New writes go only through `Repository`.
- REST: a real `permission_callback` (`permission_check`), argument schemas via `BaseController::arg()`, and unknown body fields rejected via `reject_unknown()`.
- Limits: preview takes at most **5000** redirects; an import batch takes at most **50**.
- Skip reason codes: `invalid_entry`, `unsupported_match_type`, `unsupported_action`, `unsupported_status`, `filtered`. Validator failures keep the Validator's own `adv_redirects_*` code.
- Note codes: `case_insensitive`, `trailing_slash_ignored`, `query_mode`, `regex_query`.
- Preview statuses: `new`, `overwrite`, `superseded`, `skipped`. Import results: `created`, `updated`, `skipped`.
- The group named exactly `Modified Posts` maps to `origin = auto`; every other group maps to `manual`.
- Hooks: filter `adv_redirects_import_rule( array|false $rule, array $entry )` and action `adv_redirects_import_completed( array $counts )`. Document both in `docs/hooks.md`.
- Real exports are **never committed**. Tests use only `tests/fixtures/redirection-export-sample.json`.
- Commit messages use Conventional Commits and end with a `Co-Authored-By:` line.
- Keep the existing e2e accessible names unchanged: "Source", "Target", "Test a URL", "Add redirect", "Test", and `.adv-redirects-test__result`.

## Review Focus

1. **Numeric strings.** A real export may store `action_code` or `group_id` as numeric strings (`"301"`). They should import like integers, not be skipped as `invalid_entry`. Pinned in Task 1 (`test_numeric_strings_are_accepted`) and Task 5 (the `accepts numeric strings` Jest case).
2. **Overwriting a rule that another imported rule chains through.** The preview must use the *imported* target for the overwritten rule, not the stale database one. Otherwise a chain warning or loop is computed against old data. Pinned in Task 2 (`test_pending_rows_replace_existing_rows_with_the_same_id`) and Task 3 (`test_overwrite_keeps_id_and_hits_and_uses_imported_target_for_chains`).
3. **Re-importing the same file.** It must be idempotent: everything becomes `updated`, with no duplicates and no new rows. Pinned in Task 3 (`test_reimport_is_idempotent`).
4. **Regex order.** Redirection's `position` decides regex evaluation order, so the client sorts entries by `position`, then original order, before preview and import. Pinned in Task 5 (`stripExport keeps entries ordered by position`).
5. **Privacy.** The payload sent to the server must contain no `logs`, `errors_404`, `ip`, `agent`, `hits` or `last_access`. Pinned in Task 5 (`stripExport removes logs, 404s, hits and last access`).

---

## File Map

```
tests/fixtures/redirection-export-sample.json   Task 1 (hand-made fixture, no real data)
src/Import/RedirectionMapper.php                Task 1
tests/unit/RedirectionMapperTest.php            Task 1
src/Redirects/Validator.php                     Task 2 (pending rows)
src/Redirects/Repository.php                    Task 2 (regex_rule_by_source)
tests/integration/ValidatorTest.php             Task 2 (append)
tests/integration/RepositoryTest.php            Task 2 (append)
src/Import/Importer.php                         Task 3
tests/integration/ImporterTest.php              Task 3
src/Rest/ImportController.php                   Task 4
src/Plugin.php                                  Task 4 (wiring)
docs/hooks.md                                   Task 4
tests/integration/RestImportTest.php            Task 4
assets/src/utils/redirectionImport.js           Task 5
assets/src/api.js                               Task 5
tests/js/redirectionImport.test.js              Task 5
assets/src/components/ImportTab.js              Task 6
assets/src/components/ImportPreview.js          Task 6
assets/src/components/App.js                    Task 6
assets/src/admin.scss                           Task 6
tests/e2e/import.spec.js                        Task 7
readme.txt, CLAUDE.md                           Task 7
```

---

### Task 1: Fixture and RedirectionMapper (pure PHP)

**Files:**
- Create: `tests/fixtures/redirection-export-sample.json`, `src/Import/RedirectionMapper.php`
- Test: `tests/unit/RedirectionMapperTest.php`

**Interfaces:**
- Produces:
  - `RedirectionMapper::MODIFIED_POSTS_GROUP = 'Modified Posts'`
  - `RedirectionMapper::group_names( array $groups ): array<int,string>`
  - `RedirectionMapper::map( $entry, array $group_names ): array`. Returns `[ 'ok' => bool, 'source_id' => int, 'rule' => ?array{type,source,target,status_code,enabled,note,origin}, 'notes' => string[], 'error' => ?string ]`, where `error` is a skip reason code.
  - The fixture has 21 redirects with ids 1–21, as described in Step 1.

- [ ] **Step 1: Create the fixture `tests/fixtures/redirection-export-sample.json`**

Every entry's `position` equals its array index, so sorting by position keeps file order.

```json
{
	"plugin": { "version": "5.10.1", "date": "Fri, 02 Oct 2026 12:00:00 +0000" },
	"groups": [
		{ "id": 1, "name": "Redirections", "module_id": 1, "status": "enabled" },
		{ "id": 2, "name": "Modified Posts", "module_id": 1, "status": "enabled" }
	],
	"redirects": [
		{ "id": 1, "url": "/fx-old-page/", "match_url": "/fx-old-page", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-new-page/" }, "match_type": "url", "title": "Imported title", "hits": 12, "regex": false, "group_id": 1, "position": 0, "last_access": "April 1, 2026", "enabled": true },
		{ "id": 2, "url": "/fx-moved/", "match_url": "/fx-moved", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-old-page/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 1, "last_access": "", "enabled": true },
		{ "id": 3, "url": "/FX-Case/", "match_url": "/fx-case", "match_data": { "source": { "flag_case": false, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-case-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 2, "last_access": "", "enabled": true },
		{ "id": 4, "url": "/fx-trailing", "match_url": "/fx-trailing", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": false } }, "action_code": 302, "action_type": "url", "action_data": { "url": "/fx-trailing-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 3, "last_access": "", "enabled": true },
		{ "id": 5, "url": "/fx-query/?a=1", "match_url": "/fx-query?a=1", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-query-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 4, "last_access": "", "enabled": true },
		{ "id": 6, "url": "/fx-ignore/", "match_url": "/fx-ignore", "match_data": { "source": { "flag_case": true, "flag_query": "ignore", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-ignore-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 5, "last_access": "", "enabled": true },
		{ "id": 7, "url": "^/fx-blog/(\\d+)/?$", "match_url": "regex", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": true, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-news/$1/" }, "match_type": "url", "title": "", "hits": 0, "regex": true, "group_id": 1, "position": 6, "last_access": "", "enabled": true },
		{ "id": 8, "url": "^/fx-loop/", "match_url": "regex", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": true, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-loop/again/" }, "match_type": "url", "title": "", "hits": 0, "regex": true, "group_id": 1, "position": 7, "last_access": "", "enabled": true },
		{ "id": 9, "url": "/fx-dupe/", "match_url": "/fx-dupe", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-dupe-first/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 8, "last_access": "", "enabled": true },
		{ "id": 10, "url": "/fx-dupe", "match_url": "/fx-dupe", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-dupe-second/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 9, "last_access": "", "enabled": true },
		{ "id": 11, "url": "/fx-gone/", "match_url": "/fx-gone", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 410, "action_type": "error", "action_data": [], "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 10, "last_access": "", "enabled": true },
		{ "id": 12, "url": "/fx-see-other/", "match_url": "/fx-see-other", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 303, "action_type": "url", "action_data": { "url": "/fx-elsewhere/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 11, "last_access": "", "enabled": true },
		{ "id": 13, "url": "/fx-members/", "match_url": "/fx-members", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "logged_in": "/fx-in/", "logged_out": "/fx-out/" }, "match_type": "login", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 12, "last_access": "", "enabled": true },
		{ "id": 14, "url": "/fx-random/", "match_url": "/fx-random", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "random", "action_data": [], "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 13, "last_access": "", "enabled": true },
		{ "id": 15, "url": "/fx-404-error/", "match_url": "/fx-404-error", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 404, "action_type": "error", "action_data": [], "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 14, "last_access": "", "enabled": true },
		{ "id": 16, "url": "/fx-auto-slug/", "match_url": "/fx-auto-slug", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-auto-slug-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 2, "position": 15, "last_access": "", "enabled": true },
		{ "id": 17, "url": "/fx-disabled/", "match_url": "/fx-disabled", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-disabled-new/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 16, "last_access": "", "enabled": false },
		{ "id": 18, "url": "/fx-ping/", "match_url": "/fx-ping", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-pong/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 17, "last_access": "", "enabled": true },
		{ "id": 19, "url": "/fx-pong/", "match_url": "/fx-pong", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-ping/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 18, "last_access": "", "enabled": true },
		{ "id": 20, "url": "^/fx-qs\\?id=(\\d+)", "match_url": "regex", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": true, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-qs-new/$1" }, "match_type": "url", "title": "", "hits": 0, "regex": true, "group_id": 1, "position": 19, "last_access": "", "enabled": true },
		{ "id": 21, "url": "/wp-login.php", "match_url": "/wp-login.php", "match_data": { "source": { "flag_case": true, "flag_query": "exact", "flag_regex": false, "flag_trailing": true } }, "action_code": 301, "action_type": "url", "action_data": { "url": "/fx-x/" }, "match_type": "url", "title": "", "hits": 0, "regex": false, "group_id": 1, "position": 20, "last_access": "", "enabled": true }
	],
	"logs": [
		{ "id": 1, "created": "2026-04-01 10:00:00", "url": "/fx-old-page/", "domain": "example.test", "sent_to": "/fx-new-page/", "agent": "FixtureAgent/1.0", "referrer": "", "http_code": 301, "request_method": "GET", "request_data": "", "redirect_by": "redirection", "redirection_id": 1, "ip": "192.0.2.1" },
		{ "id": 2, "created": "2026-04-02 10:00:00", "url": "/fx-moved/", "domain": "example.test", "sent_to": "/fx-old-page/", "agent": "FixtureAgent/1.0", "referrer": "", "http_code": 301, "request_method": "GET", "request_data": "", "redirect_by": "redirection", "redirection_id": 2, "ip": "192.0.2.2" }
	],
	"errors_404": [
		{ "id": 1, "created": "2026-04-03 10:00:00", "url": "/fx-missing/", "domain": "example.test", "agent": "FixtureAgent/1.0", "referrer": "", "http_code": 404, "request_method": "GET", "request_data": "", "ip": "192.0.2.3" },
		{ "id": 2, "created": "2026-04-04 10:00:00", "url": "/fx-missing-2/", "domain": "example.test", "agent": "FixtureAgent/1.0", "referrer": "", "http_code": 404, "request_method": "GET", "request_data": "", "ip": "192.0.2.4" }
	]
}
```

The IPs are from the RFC 5737 documentation range, and the domain uses the reserved `.test` TLD.

- [ ] **Step 2: Write the failing test `tests/unit/RedirectionMapperTest.php`**

```php
<?php

use Advision\Redirects\Import\RedirectionMapper;
use PHPUnit\Framework\TestCase;

final class RedirectionMapperTest extends TestCase {

	private static array $export;
	private static array $groups;

	public static function setUpBeforeClass(): void {
		self::$export = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
		self::$groups = RedirectionMapper::group_names( self::$export['groups'] );
	}

	private function entry( int $id ): array {
		foreach ( self::$export['redirects'] as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "Fixture entry {$id} missing." );
	}

	private function map( int $id ): array {
		return RedirectionMapper::map( $this->entry( $id ), self::$groups );
	}

	public function test_group_names(): void {
		$this->assertSame( [ 1 => 'Redirections', 2 => 'Modified Posts' ], self::$groups );
		$this->assertSame( [], RedirectionMapper::group_names( [ 'junk', [ 'id' => 'x' ] ] ) );
	}

	public function test_maps_a_plain_exact_redirect(): void {
		$mapped = $this->map( 1 );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( 1, $mapped['source_id'] );
		$this->assertSame(
			[
				'type'        => 'exact',
				'source'      => '/fx-old-page/',
				'target'      => '/fx-new-page/',
				'status_code' => 301,
				'enabled'     => true,
				'note'        => 'Imported title',
				'origin'      => 'manual',
			],
			$mapped['rule']
		);
		$this->assertSame( [], $mapped['notes'] );
		$this->assertNull( $mapped['error'] );
	}

	public function test_maps_regex_with_capture(): void {
		$rule = $this->map( 7 )['rule'];
		$this->assertSame( 'regex', $rule['type'] );
		$this->assertSame( '^/fx-blog/(\d+)/?$', $rule['source'] );
		$this->assertSame( '/fx-news/$1/', $rule['target'] );
	}

	public function test_maps_410_error_to_gone_rule(): void {
		$rule = $this->map( 11 )['rule'];
		$this->assertSame( 410, $rule['status_code'] );
		$this->assertNull( $rule['target'] );
	}

	public function test_modified_posts_group_becomes_auto(): void {
		$this->assertSame( 'auto', $this->map( 16 )['rule']['origin'] );
		$this->assertSame( 'manual', $this->map( 18 )['rule']['origin'] );
	}

	public function test_disabled_entries_stay_disabled(): void {
		$this->assertFalse( $this->map( 17 )['rule']['enabled'] );
	}

	public function test_notes(): void {
		$this->assertSame( [ 'case_insensitive' ], $this->map( 3 )['notes'] );
		$this->assertSame( [ 'trailing_slash_ignored' ], $this->map( 4 )['notes'] );
		$this->assertSame( [ 'query_mode' ], $this->map( 6 )['notes'] );
		$this->assertSame( [ 'regex_query' ], $this->map( 20 )['notes'] );
	}

	/** @dataProvider skipped_entries */
	public function test_skips_with_reason( int $id, string $reason ): void {
		$mapped = $this->map( $id );
		$this->assertFalse( $mapped['ok'] );
		$this->assertNull( $mapped['rule'] );
		$this->assertSame( $reason, $mapped['error'] );
		$this->assertSame( $id, $mapped['source_id'] );
	}

	public function skipped_entries(): array {
		return [
			'303 status'        => [ 12, 'unsupported_status' ],
			'login match type'  => [ 13, 'unsupported_match_type' ],
			'random action'     => [ 14, 'unsupported_action' ],
			'404 error action'  => [ 15, 'unsupported_action' ],
		];
	}

	public function test_invalid_entries(): void {
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( 'nope', [] )['error'] );
		$broken = $this->entry( 1 );
		unset( $broken['url'] );
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
		$broken = $this->entry( 1 );
		$broken['regex'] = 'yes';
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
		$broken = $this->entry( 1 );
		unset( $broken['action_data']['url'] );
		$this->assertSame( 'invalid_entry', RedirectionMapper::map( $broken, [] )['error'] );
	}

	public function test_numeric_strings_are_accepted(): void {
		$entry                = $this->entry( 1 );
		$entry['action_code'] = '302';
		$entry['group_id']    = '2';
		$mapped               = RedirectionMapper::map( $entry, self::$groups );
		$this->assertTrue( $mapped['ok'] );
		$this->assertSame( 302, $mapped['rule']['status_code'] );
		$this->assertSame( 'auto', $mapped['rule']['origin'] );
	}

	public function test_long_titles_are_truncated(): void {
		$entry          = $this->entry( 1 );
		$entry['title'] = str_repeat( 'é', 300 );
		$this->assertSame( 255, mb_strlen( RedirectionMapper::map( $entry, [] )['rule']['note'] ) );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter RedirectionMapperTest`
Expected: FAIL, `Class "Advision\Redirects\Import\RedirectionMapper" not found`.

- [ ] **Step 4: Implement `src/Import/RedirectionMapper.php`**

```php
<?php
/**
 * Maps one Redirection plugin export entry to a WP Redirects rule. Pure: no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

defined( 'ABSPATH' ) || exit;

final class RedirectionMapper {

	public const MODIFIED_POSTS_GROUP = 'Modified Posts';

	private const REDIRECT_STATUSES = [ 301, 302, 307, 308 ];

	/**
	 * @param array $groups Raw `groups` entries from the export.
	 * @return array<int,string> Group id => name.
	 */
	public static function group_names( array $groups ): array {
		$names = [];
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['id'], $group['name'] ) && is_numeric( $group['id'] ) && is_string( $group['name'] ) ) {
				$names[ (int) $group['id'] ] = $group['name'];
			}
		}
		return $names;
	}

	/**
	 * @param mixed             $entry       One raw entry from the export's `redirects` list.
	 * @param array<int,string> $group_names From group_names().
	 * @return array{ok:bool,source_id:int,rule:?array,notes:string[],error:?string}
	 */
	public static function map( $entry, array $group_names ): array {
		$source_id = is_array( $entry ) && isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;

		if ( ! self::is_valid_entry( $entry ) ) {
			return self::skip( $source_id, 'invalid_entry' );
		}
		if ( 'url' !== $entry['match_type'] ) {
			return self::skip( $source_id, 'unsupported_match_type' );
		}

		$status = (int) $entry['action_code'];
		if ( 'error' === $entry['action_type'] ) {
			if ( 410 !== $status ) {
				return self::skip( $source_id, 'unsupported_action' );
			}
			$target = null;
		} elseif ( 'url' === $entry['action_type'] ) {
			if ( ! isset( $entry['action_data']['url'] ) || ! is_string( $entry['action_data']['url'] ) ) {
				return self::skip( $source_id, 'invalid_entry' );
			}
			if ( ! in_array( $status, self::REDIRECT_STATUSES, true ) ) {
				return self::skip( $source_id, 'unsupported_status' );
			}
			$target = $entry['action_data']['url'];
		} else {
			return self::skip( $source_id, 'unsupported_action' );
		}

		$flags = isset( $entry['match_data']['source'] ) && is_array( $entry['match_data']['source'] ) ? $entry['match_data']['source'] : [];
		$notes = [];
		if ( array_key_exists( 'flag_case', $flags ) && false === $flags['flag_case'] ) {
			$notes[] = 'case_insensitive';
		}
		if ( array_key_exists( 'flag_trailing', $flags ) && false === $flags['flag_trailing'] ) {
			$notes[] = 'trailing_slash_ignored';
		}
		if ( isset( $flags['flag_query'] ) && in_array( $flags['flag_query'], [ 'ignore', 'pass' ], true ) ) {
			$notes[] = 'query_mode';
		}
		if ( $entry['regex'] && false !== strpos( $entry['url'], '\\?' ) ) {
			$notes[] = 'regex_query';
		}

		$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '';
		$group = $group_names[ (int) $entry['group_id'] ] ?? '';

		return [
			'ok'        => true,
			'source_id' => $source_id,
			'rule'      => [
				'type'        => $entry['regex'] ? 'regex' : 'exact',
				'source'      => $entry['url'],
				'target'      => $target,
				'status_code' => $status,
				'enabled'     => $entry['enabled'],
				'note'        => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 255 ) : substr( $title, 0, 255 ),
				'origin'      => self::MODIFIED_POSTS_GROUP === $group ? 'auto' : 'manual',
			],
			'notes'     => $notes,
			'error'     => null,
		];
	}

	/**
	 * @param mixed $entry Raw entry.
	 */
	private static function is_valid_entry( $entry ): bool {
		return is_array( $entry )
			&& isset( $entry['url'] ) && is_string( $entry['url'] ) && '' !== trim( $entry['url'] )
			&& isset( $entry['regex'] ) && is_bool( $entry['regex'] )
			&& isset( $entry['action_type'] ) && is_string( $entry['action_type'] )
			&& isset( $entry['action_code'] ) && is_numeric( $entry['action_code'] )
			&& isset( $entry['action_data'] ) && is_array( $entry['action_data'] )
			&& isset( $entry['match_type'] ) && is_string( $entry['match_type'] )
			&& isset( $entry['enabled'] ) && is_bool( $entry['enabled'] )
			&& isset( $entry['group_id'] ) && is_numeric( $entry['group_id'] );
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

- [ ] **Step 5: Run tests and lint**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter RedirectionMapperTest && composer lint`
Expected: `OK (14 tests, …)` and PHPCS clean. Run `vendor/bin/phpcbf` for whitespace-only issues.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/redirection-export-sample.json src/Import/RedirectionMapper.php tests/unit/RedirectionMapperTest.php
git commit -m "feat: map Redirection export entries to rules

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Validator pending rows and regex lookup

**Files:**
- Modify: `src/Redirects/Validator.php` (the `validate()` signature and `ruleset_with()`)
- Modify: `src/Redirects/Repository.php` (add `regex_rule_by_source()`)
- Test: append to `tests/integration/ValidatorTest.php` and `tests/integration/RepositoryTest.php`

**Interfaces:**
- Consumes: the current `Validator::validate( array $input, ?int $id = null )` and `ruleset_with( array $data, ?int $id, ?Rule $existing )`.
- Produces:
  - `Validator::validate( array $input, ?int $id = null, array $pending = [] )`. `$pending` is a list of rows shaped `[ 'id' => int, 'type', 'source', 'target', 'status_code', 'position' ]`, the same shape as `Repository::enabled_rows()`.
    - Pending rows join the loop and chain walk.
    - A pending row whose `id` matches an existing enabled row **replaces** that row.
    - New pending rows use negative ids.
    - Normal callers pass nothing, so behavior is unchanged.
  - `Repository::regex_rule_by_source( string $source ): ?Rule`, an exact string match on regex rules.

- [ ] **Step 1: Append failing tests to `tests/integration/ValidatorTest.php`** (inside the class)

```php
	public function test_pending_rows_join_the_loop_check(): void {
		$pending = [
			[ 'id' => -1, 'type' => 'exact', 'source' => '/pa', 'target' => '/pb', 'status_code' => 301, 'position' => 0 ],
		];
		$this->valid( [ 'type' => 'exact', 'source' => '/pb', 'target' => '/pa', 'status_code' => 301 ] );
		$result = $this->validator->validate( [ 'type' => 'exact', 'source' => '/pb', 'target' => '/pa', 'status_code' => 301 ], null, $pending );
		$this->assertWPError( $result );
		$this->assertSame( 'adv_redirects_loop', $result->get_error_code() );
	}

	public function test_pending_rows_replace_existing_rows_with_the_same_id(): void {
		$x = $this->save( [ 'type' => 'exact', 'source' => '/x', 'target' => '/y', 'status_code' => 301 ] );
		// Against the database, /y → /x loops (x → y → x).
		$this->assertSame( 'adv_redirects_loop', $this->error_code( [ 'type' => 'exact', 'source' => '/y', 'target' => '/x', 'status_code' => 301 ] ) );
		// The import is about to repoint /x to /z, so /y → /x is fine (y → x → z).
		$pending = [
			[ 'id' => $x, 'type' => 'exact', 'source' => '/x', 'target' => '/z', 'status_code' => 301, 'position' => 0 ],
		];
		$result  = $this->validator->validate( [ 'type' => 'exact', 'source' => '/y', 'target' => '/x', 'status_code' => 301 ], null, $pending );
		$this->assertIsArray( $result );
		$this->assertSame( [ '/y', '/x', '/z' ], $result['warnings'][0]['hops'] );
	}
```

- [ ] **Step 2: Append a failing test to `tests/integration/RepositoryTest.php`** (inside the class)

```php
	public function test_regex_rule_by_source_matches_exact_pattern_only(): void {
		$rule = $this->repo->insert( [ 'type' => 'regex', 'source' => '^/a/(.*)$', 'target' => '/b/$1', 'status_code' => 301 ] );
		$this->exact( '^/a/(.*)$', '/x' );
		$this->assertSame( $rule->id, $this->repo->regex_rule_by_source( '^/a/(.*)$' )->id );
		$this->assertNull( $this->repo->regex_rule_by_source( '^/A/(.*)$' ) );
	}
```

`$this->exact(...)` (the existing RepositoryTest helper) inserts an *exact* rule with the same source string through `Repository::insert()` (no validator), proving only regex rules are matched.

- [ ] **Step 3: Run them to verify they fail**

Run: `npm run test:php:integration -- --filter 'test_pending_rows|test_regex_rule_by_source'`
Expected: FAIL. The loop test returns an array (the pending rows are ignored), and the repository test errors with `Call to undefined method …regex_rule_by_source()`.

- [ ] **Step 4: Implement pending rows in `src/Redirects/Validator.php`**

Change the docblock and signature of `validate()`:

```php
	/**
	 * @param array    $input   Raw fields (type, source, target, status_code, enabled, note). Missing fields keep existing values on update.
	 * @param int|null $id      Rule being updated, or null for a new rule.
	 * @param array    $pending Rows not yet saved (e.g. earlier rules in an import), shaped like Repository::enabled_rows().
	 *                          A pending row with an existing rule's id replaces that rule in the loop/chain walk.
	 * @return array{data:array,warnings:array}|\WP_Error
	 */
	public function validate( array $input, ?int $id = null, array $pending = [] ) {
```

In the chain walk, replace `$this->ruleset_with( $data, $id, $existing ),` with `$this->ruleset_with( $data, $id, $existing, $pending ),`.

Replace the whole `ruleset_with()` method:

```php
	private function ruleset_with( array $data, ?int $id, ?Rule $existing, array $pending = [] ): array {
		$replaced = array_map(
			static function ( array $row ): int {
				return (int) $row['id'];
			},
			$pending
		);

		$rows = array_values(
			array_filter(
				$this->repository->enabled_rows(),
				static function ( array $row ) use ( $id, $replaced ): bool {
					return (int) $row['id'] !== (int) $id && ! in_array( (int) $row['id'], $replaced, true );
				}
			)
		);

		foreach ( $pending as $row ) {
			if ( (int) $row['id'] !== (int) $id ) {
				$rows[] = $row;
			}
		}

		$rows[] = [
			'id'          => (int) $id,
			'type'        => $data['type'],
			'source'      => $data['source'],
			'target'      => $data['target'],
			'status_code' => $data['status_code'],
			'position'    => null !== $existing && 'regex' === $existing->type ? $existing->position : PHP_INT_MAX,
		];

		return RulesetCompiler::compile( $rows );
	}
```

- [ ] **Step 5: Add `regex_rule_by_source()` to `src/Redirects/Repository.php`** (after `exact_rule_by_key()`)

```php
	public function regex_rule_by_source( string $source ): ?Rule {
		foreach ( $this->all() as $rule ) {
			if ( 'regex' === $rule->type && $rule->source === $source ) {
				return $rule;
			}
		}
		return null;
	}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter 'ValidatorTest|RepositoryTest'`, then the full integration suite once, then `composer lint`.
Expected: all OK, lint clean.

- [ ] **Step 7: Commit**

```bash
git add src/Redirects/Validator.php src/Redirects/Repository.php tests/integration/ValidatorTest.php tests/integration/RepositoryTest.php
git commit -m "feat: let the validator check against pending import rows

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Importer service

**Files:**
- Create: `src/Import/Importer.php`
- Test: `tests/integration/ImporterTest.php`

**Interfaces:**
- Consumes: `RedirectionMapper::map()` and `group_names()`; `Validator::validate( $input, $id, $pending )`; `Repository::exact_rule_by_key()`, `regex_rule_by_source()`, `insert()`, `update()`; `PathNormalizer::source_key()`.
- Produces:
  - `new Importer( Repository $repository, Validator $validator )`
  - `->preview( array $redirects, array $groups ): array{entries: array, counts: array{total,new,overwrite,superseded,skipped,warnings}}`. Each entry is `[ index, source_id, source, status, warnings, notes, rule, error, existing_id?, current? ]`, where `error` is `?array{code,message}` and `current` is `[source,target,status_code,enabled]`.
  - `->import( array $redirects, array $groups ): array{entries: array, counts: array{total,created,updated,skipped}}`. Each entry is `[ index, source_id, result, rule_id?, error? ]`.
  - Fires filter `adv_redirects_import_rule` and action `adv_redirects_import_completed`.

- [ ] **Step 1: Write the failing test `tests/integration/ImporterTest.php`**

```php
<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ImporterTest extends WP_UnitTestCase {

	private Repository $repo;
	private Importer $importer;
	private array $export;

	public function set_up(): void {
		parent::set_up();
		$this->repo     = new Repository();
		$this->importer = new Importer( $this->repo, new Validator( $this->repo, new ChainResolver() ) );
		$this->export   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
	}

	private function by_source_id( array $entries, int $id ): array {
		foreach ( $entries as $entry ) {
			if ( $entry['source_id'] === $id ) {
				return $entry;
			}
		}
		$this->fail( "No entry for source id {$id}." );
	}

	private function importable( array $preview ): array {
		$out = [];
		foreach ( $preview['entries'] as $entry ) {
			if ( in_array( $entry['status'], [ 'new', 'overwrite' ], true ) ) {
				$out[] = $this->export['redirects'][ $entry['index'] ];
			}
		}
		return $out;
	}

	public function test_preview_counts_and_writes_nothing(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame(
			[ 'total' => 21, 'new' => 13, 'overwrite' => 0, 'superseded' => 1, 'skipped' => 7, 'warnings' => 1 ],
			$preview['counts']
		);
		$this->assertSame( [], $this->repo->all() );
	}

	public function test_preview_entry_details(): void {
		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];

		$this->assertSame( 'chain', $this->by_source_id( $entries, 2 )['warnings'][0]['code'] );
		$this->assertSame( 'superseded', $this->by_source_id( $entries, 9 )['status'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 10 )['status'] );

		$this->assertSame( 'adv_redirects_loop', $this->by_source_id( $entries, 8 )['error']['code'] );
		$this->assertSame( 'adv_redirects_loop', $this->by_source_id( $entries, 19 )['error']['code'], 'Loop formed only by imported rules.' );
		$this->assertSame( 'adv_redirects_reserved_source', $this->by_source_id( $entries, 21 )['error']['code'] );
		$this->assertSame( 'unsupported_status', $this->by_source_id( $entries, 12 )['error']['code'] );
		$this->assertSame( 'unsupported_match_type', $this->by_source_id( $entries, 13 )['error']['code'] );
		$this->assertSame( 'unsupported_action', $this->by_source_id( $entries, 14 )['error']['code'] );
		$this->assertSame( 'unsupported_action', $this->by_source_id( $entries, 15 )['error']['code'] );
		$this->assertNotSame( '', $this->by_source_id( $entries, 13 )['error']['message'] );

		$this->assertSame( [ 'case_insensitive' ], $this->by_source_id( $entries, 3 )['notes'] );
		$this->assertSame( 'auto', $this->by_source_id( $entries, 16 )['rule']['origin'] );
		$this->assertNull( $this->by_source_id( $entries, 11 )['rule']['target'] );
		$this->assertSame( '/fx-old-page/', $this->by_source_id( $entries, 1 )['source'] );
	}

	public function test_import_in_batches_matches_preview(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$created = 0;
		foreach ( array_chunk( $this->importable( $preview ), 5 ) as $batch ) {
			$created += $this->importer->import( $batch, $this->export['groups'] )['counts']['created'];
		}
		$this->assertSame( 13, $created );
		$this->assertCount( 13, $this->repo->all() );

		$auto = $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-auto-slug/' ) );
		$this->assertSame( 'auto', $auto->origin );
		$this->assertFalse( $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-disabled/' ) )->enabled );
		$this->assertSame( '/fx-dupe-second/', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-dupe' ) )->target );
	}

	public function test_import_of_the_full_list_skips_like_preview(): void {
		$result = $this->importer->import( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame( [ 'total' => 21, 'created' => 13, 'updated' => 0, 'skipped' => 8 ], $result['counts'] );
	}

	public function test_reimport_is_idempotent(): void {
		$preview = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->importer->import( $this->importable( $preview ), $this->export['groups'] );

		$again = $this->importer->preview( $this->export['redirects'], $this->export['groups'] );
		$this->assertSame( 13, $again['counts']['overwrite'] );
		$this->assertSame( 0, $again['counts']['new'] );

		$result = $this->importer->import( $this->importable( $again ), $this->export['groups'] );
		$this->assertSame( [ 'total' => 13, 'created' => 0, 'updated' => 13, 'skipped' => 0 ], $result['counts'] );
		$this->assertCount( 13, $this->repo->all() );
	}

	public function test_overwrite_keeps_id_and_hits_and_uses_imported_target_for_chains(): void {
		$existing = $this->repo->insert( [ 'type' => 'exact', 'source' => '/fx-old-page', 'target' => '/somewhere-else', 'status_code' => 302 ] );
		$this->repo->add_hits( $existing->id, 5, '2026-10-01 00:00:00' );

		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];
		$first   = $this->by_source_id( $entries, 1 );
		$this->assertSame( 'overwrite', $first['status'] );
		$this->assertSame( $existing->id, $first['existing_id'] );
		$this->assertSame( '/somewhere-else', $first['current']['target'] );
		$this->assertSame( [ '/fx-moved/', '/fx-old-page/', '/fx-new-page/' ], $this->by_source_id( $entries, 2 )['warnings'][0]['hops'] );

		$this->importer->import( [ $this->export['redirects'][0] ], $this->export['groups'] );
		$after = $this->repo->find( $existing->id );
		$this->assertSame( '/fx-new-page/', $after->target );
		$this->assertSame( 301, $after->status_code );
		$this->assertSame( 5, $after->hits );
	}

	public function test_filter_can_skip_or_change_rules_and_action_fires(): void {
		add_filter(
			'adv_redirects_import_rule',
			static function ( $rule, array $entry ) {
				if ( 18 === $entry['id'] ) {
					return false;
				}
				if ( 1 === $entry['id'] ) {
					$rule['note'] = 'Changed by filter';
				}
				return $rule;
			},
			10,
			2
		);
		$entries = $this->importer->preview( $this->export['redirects'], $this->export['groups'] )['entries'];
		$this->assertSame( 'filtered', $this->by_source_id( $entries, 18 )['error']['code'] );
		$this->assertSame( 'new', $this->by_source_id( $entries, 19 )['status'], 'Without /fx-ping/ there is no loop.' );

		$before = did_action( 'adv_redirects_import_completed' );
		$this->importer->import( [ $this->export['redirects'][0] ], $this->export['groups'] );
		$this->assertSame( $before + 1, did_action( 'adv_redirects_import_completed' ) );
		$this->assertSame( 'Changed by filter', $this->repo->exact_rule_by_key( PathNormalizer::source_key( '/fx-old-page/' ) )->note );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter ImporterTest`
Expected: FAIL, `Class "Advision\Redirects\Import\Importer" not found`.

- [ ] **Step 3: Implement `src/Import/Importer.php`**

```php
<?php
/**
 * Plans, previews and applies a Redirection import. Every rule goes through the
 * Validator, and every write goes through the Repository.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Import;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Redirects\Validator;

defined( 'ABSPATH' ) || exit;

final class Importer {

	private const RULE_FIELDS = [ 'type', 'source', 'target', 'status_code', 'enabled', 'note' ];

	private Repository $repository;

	private Validator $validator;

	public function __construct( Repository $repository, Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	/**
	 * Dry run: maps and validates every entry, writes nothing.
	 *
	 * @param array $redirects Raw export entries, in import order.
	 * @param array $groups    Raw export groups.
	 */
	public function preview( array $redirects, array $groups ): array {
		$entries = [];
		$pending = [];
		$next_id = -1;

		foreach ( $this->plan( $redirects, $groups ) as $index => $item ) {
			$entry = [
				'index'     => $index,
				'source_id' => $item['source_id'],
				'source'    => $item['source'],
				'status'    => 'new',
				'warnings'  => [],
				'notes'     => $item['notes'],
				'rule'      => $item['rule'],
				'error'     => null,
			];

			if ( null !== $item['error'] ) {
				$entry['status'] = 'skipped';
				$entry['error']  = self::reason( $item['error'] );
				$entries[]       = $entry;
				continue;
			}
			if ( $item['superseded'] ) {
				$entry['status'] = 'superseded';
				$entries[]       = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'] );
			$result   = $this->validator->validate( self::validator_input( $item['rule'] ), null !== $existing ? $existing->id : null, $pending );
			if ( is_wp_error( $result ) ) {
				$entry['status'] = 'skipped';
				$entry['error']  = [
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
				$entries[]       = $entry;
				continue;
			}

			$entry['rule']     = $result['data'] + [ 'origin' => $item['rule']['origin'] ];
			$entry['warnings'] = $result['warnings'];
			if ( null !== $existing ) {
				$entry['status']      = 'overwrite';
				$entry['existing_id'] = $existing->id;
				$entry['current']     = [
					'source'      => $existing->source,
					'target'      => $existing->target,
					'status_code' => $existing->status_code,
					'enabled'     => $existing->enabled,
				];
			}

			if ( $result['data']['enabled'] ) {
				$pending[] = [
					'id'          => null !== $existing ? $existing->id : $next_id--,
					'type'        => $result['data']['type'],
					'source'      => $result['data']['source'],
					'target'      => $result['data']['target'],
					'status_code' => $result['data']['status_code'],
					'position'    => null !== $existing && 'regex' === $existing->type ? $existing->position : 1000000 + $index,
				];
			}
			$entries[] = $entry;
		}

		return [
			'entries' => $entries,
			'counts'  => self::preview_counts( $entries ),
		];
	}

	/**
	 * Applies one batch. The caller sends batches in file order.
	 *
	 * @param array $redirects Raw export entries for this batch.
	 * @param array $groups    Raw export groups.
	 */
	public function import( array $redirects, array $groups ): array {
		$entries = [];

		foreach ( $this->plan( $redirects, $groups ) as $index => $item ) {
			$entry = [
				'index'     => $index,
				'source_id' => $item['source_id'],
				'result'    => 'skipped',
			];

			if ( null !== $item['error'] ) {
				$entry['error'] = self::reason( $item['error'] );
				$entries[]      = $entry;
				continue;
			}
			if ( $item['superseded'] ) {
				$entry['error'] = [
					'code'    => 'superseded',
					'message' => __( 'A later entry in the file uses the same source.', 'wp-redirects' ),
				];
				$entries[]      = $entry;
				continue;
			}

			$existing = $this->existing_for( $item['rule'] );
			$result   = $this->validator->validate( self::validator_input( $item['rule'] ), null !== $existing ? $existing->id : null );
			if ( is_wp_error( $result ) ) {
				$entry['error'] = [
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
				$entries[]      = $entry;
				continue;
			}

			$data = $result['data'] + [ 'origin' => $item['rule']['origin'] ];
			$rule = null !== $existing ? $this->repository->update( $existing->id, $data ) : $this->repository->insert( $data );
			if ( null === $rule ) {
				$entry['error'] = [
					'code'    => 'adv_redirects_db_error',
					'message' => __( 'The redirect could not be saved.', 'wp-redirects' ),
				];
				$entries[]      = $entry;
				continue;
			}

			$entry['result']  = null !== $existing ? 'updated' : 'created';
			$entry['rule_id'] = $rule->id;
			$entries[]        = $entry;
		}

		$counts = [
			'total'   => count( $entries ),
			'created' => count( wp_list_filter( $entries, [ 'result' => 'created' ] ) ),
			'updated' => count( wp_list_filter( $entries, [ 'result' => 'updated' ] ) ),
			'skipped' => count( wp_list_filter( $entries, [ 'result' => 'skipped' ] ) ),
		];

		/**
		 * Fires after an import batch has been applied.
		 *
		 * @param array $counts { total, created, updated, skipped }.
		 */
		do_action( 'adv_redirects_import_completed', $counts );

		return [
			'entries' => $entries,
			'counts'  => $counts,
		];
	}

	/**
	 * Maps entries, applies the filter and marks in-file duplicates (the later entry wins).
	 *
	 * @return array<int,array{source_id:int,source:string,rule:?array,notes:array,error:?string,superseded:bool}>
	 */
	private function plan( array $redirects, array $groups ): array {
		$names   = RedirectionMapper::group_names( $groups );
		$planned = [];
		$last    = [];

		foreach ( array_values( $redirects ) as $index => $raw ) {
			$mapped = RedirectionMapper::map( $raw, $names );
			$item   = [
				'source_id'  => $mapped['source_id'],
				'source'     => is_array( $raw ) && isset( $raw['url'] ) && is_string( $raw['url'] ) ? $raw['url'] : '',
				'rule'       => $mapped['rule'],
				'notes'      => $mapped['notes'],
				'error'      => $mapped['error'],
				'superseded' => false,
			];

			if ( $mapped['ok'] ) {
				/**
				 * Filters a mapped import rule. Return false to skip it.
				 *
				 * @param array|false $rule  { type, source, target, status_code, enabled, note, origin }.
				 * @param array       $entry The raw Redirection export entry.
				 */
				$filtered = apply_filters( 'adv_redirects_import_rule', $mapped['rule'], is_array( $raw ) ? $raw : [] );
				if ( false === $filtered ) {
					$item['rule']  = null;
					$item['error'] = 'filtered';
				} elseif ( is_array( $filtered ) ) {
					$item['rule'] = array_merge( $mapped['rule'], array_intersect_key( $filtered, $mapped['rule'] ) );
				}
			}

			if ( null !== $item['rule'] ) {
				$last[ self::conflict_key( $item['rule'] ) ] = $index;
			}
			$planned[ $index ] = $item;
		}

		foreach ( $planned as $index => $item ) {
			if ( null !== $item['rule'] && $last[ self::conflict_key( $item['rule'] ) ] !== $index ) {
				$planned[ $index ]['superseded'] = true;
			}
		}

		return $planned;
	}

	private function existing_for( array $rule ): ?Rule {
		if ( 'regex' === $rule['type'] ) {
			return $this->repository->regex_rule_by_source( (string) $rule['source'] );
		}
		return $this->repository->exact_rule_by_key( PathNormalizer::source_key( trim( (string) $rule['source'] ) ) );
	}

	private static function conflict_key( array $rule ): string {
		return 'regex' === $rule['type']
			? 'r:' . $rule['source']
			: 'e:' . PathNormalizer::source_key( trim( (string) $rule['source'] ) );
	}

	private static function validator_input( array $rule ): array {
		return array_intersect_key( $rule, array_flip( self::RULE_FIELDS ) );
	}

	/**
	 * @return array{code:string,message:string}
	 */
	private static function reason( string $code ): array {
		$messages = [
			'invalid_entry'          => __( 'This entry is missing required fields or has the wrong types.', 'wp-redirects' ),
			'unsupported_match_type' => __( 'Conditional redirects (login, referrer, user agent, cookie, IP and similar) are not supported.', 'wp-redirects' ),
			'unsupported_action'     => __( 'Only redirects and 410 Gone responses can be imported.', 'wp-redirects' ),
			'unsupported_status'     => __( 'This status code is not supported.', 'wp-redirects' ),
			'filtered'               => __( 'Skipped by the adv_redirects_import_rule filter.', 'wp-redirects' ),
		];
		return [
			'code'    => $code,
			'message' => $messages[ $code ] ?? $code,
		];
	}

	private static function preview_counts( array $entries ): array {
		$counts = [
			'total'      => count( $entries ),
			'new'        => 0,
			'overwrite'  => 0,
			'superseded' => 0,
			'skipped'    => 0,
			'warnings'   => 0,
		];
		foreach ( $entries as $entry ) {
			++$counts[ $entry['status'] ];
			if ( ! empty( $entry['warnings'] ) ) {
				++$counts['warnings'];
			}
		}
		return $counts;
	}
}
```

Note: `test_import_of_the_full_list_skips_like_preview` expects 8 skipped. That's the 7 preview skips plus the superseded entry 9, which `import()` reports as skipped with code `superseded`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter ImporterTest`, then the full integration suite once, then `composer lint`.
Expected: `OK (7 tests, …)`, all suites green, lint clean.

- [ ] **Step 5: Commit**

```bash
git add src/Import/Importer.php tests/integration/ImporterTest.php
git commit -m "feat: add importer with dry-run preview and batch import

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: REST endpoints, wiring, hook docs

**Files:**
- Create: `src/Rest/ImportController.php`
- Modify: `src/Plugin.php`, `docs/hooks.md`
- Test: `tests/integration/RestImportTest.php`

**Interfaces:**
- Consumes: `Importer::preview()` and `import()`; `BaseController` (`NAMESPACE`, `permission_check`, `reject_unknown`, `arg`).
- Produces:
  - `new ImportController( Importer $importer )`, `ImportController::MAX_PREVIEW = 5000`, `ImportController::MAX_BATCH = 50`
  - `POST /adv-redirects/v1/import/preview` with body `{ source: 'redirection', version?, groups?, redirects }`
  - `POST /adv-redirects/v1/import` with body `{ source: 'redirection', groups?, redirects }`
  - `Plugin::importer(): Importer`

- [ ] **Step 1: Write the failing test `tests/integration/RestImportTest.php`**

```php
<?php

use Advision\Redirects\Import\Importer;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\ImportController;

final class RestImportTest extends Adv_Redirects_Rest_TestCase {

	private array $export;

	protected function controllers(): array {
		$repo = new Repository();
		return [ new ImportController( new Importer( $repo, new Validator( $repo, new ChainResolver() ) ) ) ];
	}

	public function set_up() {
		parent::set_up();
		$this->export = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/redirection-export-sample.json' ), true );
	}

	private function body( array $redirects, bool $preview = true ): array {
		$body = [
			'source'    => 'redirection',
			'groups'    => $this->export['groups'],
			'redirects' => $redirects,
		];
		if ( $preview ) {
			$body['version'] = $this->export['plugin']['version'];
		}
		return $body;
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/adv-redirects/v1/import/preview', $routes );
		$this->assertArrayHasKey( '/adv-redirects/v1/import', $routes );
	}

	public function test_preview_and_import(): void {
		$preview = $this->rest( 'POST', '/import/preview', $this->body( $this->export['redirects'] ) );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 13, $preview->get_data()['counts']['new'] );

		$batch  = array_slice( $this->export['redirects'], 0, 2 );
		$import = $this->rest( 'POST', '/import', $this->body( $batch, false ) );
		$this->assertSame( 200, $import->get_status() );
		$this->assertSame( 2, $import->get_data()['counts']['created'] );
	}

	public function test_permissions(): void {
		foreach ( [ [ '/import/preview', true ], [ '/import', false ] ] as list( $route, $preview ) ) {
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->rest( 'POST', $route, $this->body( [ $this->export['redirects'][0] ], $preview ) )->get_status(), $route );
			wp_set_current_user( self::$editor_id );
			$this->assertSame( 403, $this->rest( 'POST', $route, $this->body( [ $this->export['redirects'][0] ], $preview ) )->get_status(), $route );
		}
	}

	public function test_schema_limits_and_unknown_fields(): void {
		$one = [ $this->export['redirects'][0] ];

		$bad_source           = $this->body( $one );
		$bad_source['source'] = 'yoast';
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $bad_source )->get_status() );

		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $this->body( [] ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import/preview', $this->body( array_fill( 0, 5001, $one[0] ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/import', $this->body( array_fill( 0, 51, $one[0] ), false ) )->get_status() );

		$extra         = $this->body( $one );
		$extra['logs'] = $this->export['logs'];
		$response      = $this->rest( 'POST', '/import/preview', $extra );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'] );

		$with_version = $this->body( $one, true );
		$this->assertSame( 400, $this->rest( 'POST', '/import', $with_version )->get_status(), 'version is preview-only' );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter RestImportTest`
Expected: FAIL, `Class "Advision\Redirects\Rest\ImportController" not found`.

- [ ] **Step 3: Implement `src/Rest/ImportController.php`**

```php
<?php
/**
 * REST endpoints for importing Redirection exports.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Import\Importer;

defined( 'ABSPATH' ) || exit;

final class ImportController extends BaseController {

	public const MAX_PREVIEW = 5000;

	public const MAX_BATCH = 50;

	private Importer $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/preview',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => $this->args( self::MAX_PREVIEW, true ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => $this->args( self::MAX_BATCH, false ),
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'source', 'version', 'groups', 'redirects' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->preview( (array) $request['redirects'], (array) $request['groups'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, [ 'source', 'groups', 'redirects' ] );
		if ( null !== $unknown ) {
			return $unknown;
		}
		return rest_ensure_response( $this->importer->import( (array) $request['redirects'], (array) $request['groups'] ) );
	}

	private function args( int $max, bool $with_version ): array {
		$args = [
			'source'    => self::arg(
				[
					'type'     => 'string',
					'enum'     => [ 'redirection' ],
					'required' => true,
				]
			),
			'groups'    => self::arg(
				[
					'type'     => 'array',
					'items'    => [ 'type' => 'object' ],
					'maxItems' => 1000,
					'default'  => [],
				]
			),
			'redirects' => self::arg(
				[
					'type'     => 'array',
					'items'    => [ 'type' => 'object' ],
					'minItems' => 1,
					'maxItems' => $max,
					'required' => true,
				]
			),
		];
		if ( $with_version ) {
			$args['version'] = self::arg(
				[
					'type'      => 'string',
					'maxLength' => 50,
				]
			);
		}
		return $args;
	}
}
```

- [ ] **Step 4: Wire it in `src/Plugin.php`**

- Add imports: `use Advision\Redirects\Import\Importer;` and `use Advision\Redirects\Rest\ImportController;`.
- Add the property `private Importer $importer;` after `private Cron $cron;`.
- In the constructor, after `$this->cron = …`, add: `$this->importer = new Importer( $this->repository, $this->validator );`.
- In `register_routes()`, add `new ImportController( $this->importer ),` to the controllers array after `new SettingsController(),`.
- Add a getter after `not_found_logger()`:

```php
	public function importer(): Importer {
		return $this->importer;
	}
```

- [ ] **Step 5: Document the hooks in `docs/hooks.md`**

Add to the Filters table:

```
| `adv_redirects_import_rule` | `array\|false $rule, array $entry` | mapped rule | Change a rule mapped from a Redirection export, or return `false` to skip it. `$entry` is the raw export entry. |
```

Add to the Actions table:

```
| `adv_redirects_import_completed` | `array $counts` | After each import batch is applied (`total`, `created`, `updated`, `skipped`). |
```

- [ ] **Step 6: Run tests and lint**

Run: `npm run test:php:integration -- --filter 'RestImportTest|PluginTest'`, then the full integration suite once, then `composer lint`.
Expected: all OK, lint clean.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/ImportController.php src/Plugin.php docs/hooks.md tests/integration/RestImportTest.php
git commit -m "feat: add REST endpoints for Redirection import

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Client-side schema check, strip and batching

**Files:**
- Create: `assets/src/utils/redirectionImport.js`
- Modify: `assets/src/api.js`
- Test: `tests/js/redirectionImport.test.js`

**Interfaces:**
- Produces:
  - `MAX_PREVIEW = 5000`, `BATCH_SIZE = 50`
  - `checkRedirectionExport( data ): { ok: boolean, errors: string[] (max 5), summary: null|{ version, date, total, exact, regex, logs, errors404 } }`
  - `stripExport( data ): { source: 'redirection', version, groups: [{id,name}], redirects: [...] }`. Redirects keep only `id, url, match_data, action_code, action_type, action_data, match_type, title, regex, group_id, position, enabled`, sorted by `position` then original order.
  - `chunk( items, size ): array[]`
  - `importableEntries( preview, redirects ): [{ index, entry }]`, for entries with status `new` or `overwrite`.
  - `groupPreview( preview ): { new, overwrite, warnings, skipped, superseded }`, arrays of preview entries.
  - `NOTE_LABELS`, which maps note code to translated text.
  - `buildReport( { summary, preview, importSkipped } ): object`, a JSON-serializable report of skipped and superseded entries.
  - `api.importPreview( payload )`, `api.importBatch( payload )`

- [ ] **Step 1: Write the failing test `tests/js/redirectionImport.test.js`**

```js
import fixture from '../fixtures/redirection-export-sample.json';
import {
	buildReport,
	checkRedirectionExport,
	chunk,
	groupPreview,
	importableEntries,
	stripExport,
} from '../../assets/src/utils/redirectionImport';

const clone = () => JSON.parse( JSON.stringify( fixture ) );

describe( 'checkRedirectionExport', () => {
	it( 'accepts a Redirection export and summarizes it', () => {
		expect( checkRedirectionExport( clone() ) ).toEqual( {
			ok: true,
			errors: [],
			summary: {
				version: '5.10.1',
				date: 'Fri, 02 Oct 2026 12:00:00 +0000',
				total: 21,
				exact: 18,
				regex: 3,
				logs: 2,
				errors404: 2,
			},
		} );
	} );

	it( 'rejects files that are not Redirection exports', () => {
		expect( checkRedirectionExport( null ).ok ).toBe( false );
		expect( checkRedirectionExport( { redirects: [] } ).errors[ 0 ] ).toMatch( /plugin\.version/ );
		const noList = clone();
		delete noList.redirects;
		expect( checkRedirectionExport( noList ).errors[ 0 ] ).toMatch( /redirects/ );
		const empty = clone();
		empty.redirects = [];
		expect( checkRedirectionExport( empty ).ok ).toBe( false );
	} );

	it( 'reports wrong entry fields with the entry number', () => {
		const data = clone();
		data.redirects[ 1 ].regex = 'yes';
		expect( checkRedirectionExport( data ).errors ).toEqual( [ 'Entry 2: "regex" must be true or false.' ] );
	} );

	it( 'caps the error list at five', () => {
		const data = clone();
		data.redirects.forEach( ( entry ) => {
			delete entry.url;
		} );
		expect( checkRedirectionExport( data ).errors ).toHaveLength( 5 );
	} );

	it( 'accepts numeric strings for action_code and group_id', () => {
		const data = clone();
		data.redirects[ 0 ].action_code = '301';
		data.redirects[ 0 ].group_id = '1';
		expect( checkRedirectionExport( data ).ok ).toBe( true );
	} );

	it( 'validates groups when present', () => {
		const data = clone();
		data.groups = [ { id: 1 } ];
		expect( checkRedirectionExport( data ).ok ).toBe( false );
	} );
} );

describe( 'stripExport', () => {
	it( 'removes logs, 404s, hits and last access', () => {
		const payload = stripExport( clone() );
		expect( Object.keys( payload ).sort() ).toEqual( [ 'groups', 'redirects', 'source', 'version' ] );
		expect( payload.source ).toBe( 'redirection' );
		expect( payload.groups ).toEqual( [
			{ id: 1, name: 'Redirections' },
			{ id: 2, name: 'Modified Posts' },
		] );
		const json = JSON.stringify( payload );
		expect( json ).not.toMatch( /192\.0\.2|FixtureAgent|last_access|"hits"/ );
		expect( Object.keys( payload.redirects[ 0 ] ).sort() ).toEqual(
			[ 'action_code', 'action_data', 'action_type', 'enabled', 'group_id', 'id', 'match_data', 'match_type', 'position', 'regex', 'title', 'url' ]
		);
	} );

	it( 'keeps entries ordered by position, then original order', () => {
		const data = clone();
		data.redirects[ 0 ].position = 99;
		data.redirects[ 1 ].position = 3;
		const ids = stripExport( data ).redirects.map( ( entry ) => entry.id );
		expect( ids.slice( 0, 4 ) ).toEqual( [ 3, 2, 4, 5 ] );
		expect( ids[ ids.length - 1 ] ).toBe( 1 );
	} );
} );

describe( 'batching helpers', () => {
	const preview = {
		entries: [
			{ index: 0, status: 'new', warnings: [] },
			{ index: 1, status: 'overwrite', warnings: [ { code: 'chain' } ] },
			{ index: 2, status: 'skipped', warnings: [], error: { code: 'x', message: 'Nope' }, source: '/c' },
			{ index: 3, status: 'superseded', warnings: [], source: '/d' },
		],
	};
	const redirects = [ { id: 10 }, { id: 11 }, { id: 12 }, { id: 13 } ];

	it( 'chunks arrays', () => {
		expect( chunk( [ 1, 2, 3, 4, 5 ], 2 ) ).toEqual( [ [ 1, 2 ], [ 3, 4 ], [ 5 ] ] );
		expect( chunk( [], 50 ) ).toEqual( [] );
	} );

	it( 'selects only new and overwrite entries', () => {
		expect( importableEntries( preview, redirects ) ).toEqual( [
			{ index: 0, entry: { id: 10 } },
			{ index: 1, entry: { id: 11 } },
		] );
	} );

	it( 'groups preview entries', () => {
		const groups = groupPreview( preview );
		expect( groups.new ).toHaveLength( 1 );
		expect( groups.overwrite ).toHaveLength( 1 );
		expect( groups.warnings.map( ( entry ) => entry.index ) ).toEqual( [ 1 ] );
		expect( groups.skipped ).toHaveLength( 1 );
		expect( groups.superseded ).toHaveLength( 1 );
	} );

	it( 'builds a report of skipped and superseded entries', () => {
		const report = buildReport( {
			summary: { version: '5.10.1', date: 'd' },
			preview,
			importSkipped: [ { index: 0, source: '/a', error: { code: 'db', message: 'Failed' } } ],
		} );
		expect( report.file ).toEqual( { plugin: 'redirection', version: '5.10.1', date: 'd' } );
		expect( report.skipped ).toEqual( [
			{ index: 2, source: '/c', code: 'x', message: 'Nope', stage: 'preview' },
			{ index: 0, source: '/a', code: 'db', message: 'Failed', stage: 'import' },
		] );
		expect( report.superseded ).toEqual( [ { index: 3, source: '/d' } ] );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest tests/js/redirectionImport.test.js`
Expected: FAIL, `Cannot find module '../../assets/src/utils/redirectionImport'`.

- [ ] **Step 3: Implement `assets/src/utils/redirectionImport.js`**

```js
import { __, sprintf } from '@wordpress/i18n';

export const MAX_PREVIEW = 5000;
export const BATCH_SIZE = 50;
const MAX_ERRORS = 5;

const KEEP_FIELDS = [
	'id',
	'url',
	'match_data',
	'action_code',
	'action_type',
	'action_data',
	'match_type',
	'title',
	'regex',
	'group_id',
	'position',
	'enabled',
];

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );
const isNumberLike = ( value ) =>
	Number.isInteger( value ) ||
	( typeof value === 'string' && /^\d+$/.test( value ) );

const ENTRY_FIELDS = [
	[ 'url', ( v ) => typeof v === 'string' && v.trim() !== '', () => __( 'a non-empty string', 'wp-redirects' ) ],
	[ 'regex', ( v ) => typeof v === 'boolean', () => __( 'true or false', 'wp-redirects' ) ],
	[ 'action_type', ( v ) => typeof v === 'string', () => __( 'a string', 'wp-redirects' ) ],
	[ 'action_code', isNumberLike, () => __( 'a number', 'wp-redirects' ) ],
	[ 'action_data', ( v ) => v !== null && typeof v === 'object', () => __( 'an object', 'wp-redirects' ) ],
	[ 'match_type', ( v ) => typeof v === 'string', () => __( 'a string', 'wp-redirects' ) ],
	[ 'enabled', ( v ) => typeof v === 'boolean', () => __( 'true or false', 'wp-redirects' ) ],
	[ 'group_id', isNumberLike, () => __( 'a number', 'wp-redirects' ) ],
];

const fail = ( errors ) => ( {
	ok: false,
	errors: errors.slice( 0, MAX_ERRORS ),
	summary: null,
} );

export function checkRedirectionExport( data ) {
	if (
		! isObject( data ) ||
		! isObject( data.plugin ) ||
		typeof data.plugin.version !== 'string'
	) {
		return fail( [
			__( 'This file is not a Redirection export: "plugin.version" is missing.', 'wp-redirects' ),
		] );
	}
	if ( ! Array.isArray( data.redirects ) ) {
		return fail( [ __( 'This file has no "redirects" list.', 'wp-redirects' ) ] );
	}
	if ( data.redirects.length === 0 ) {
		return fail( [ __( 'The export contains no redirects.', 'wp-redirects' ) ] );
	}
	if ( data.redirects.length > MAX_PREVIEW ) {
		return fail( [
			sprintf(
				/* translators: 1: number of redirects in the file, 2: maximum per import */
				__( 'This file has %1$d redirects; the maximum per import is %2$d.', 'wp-redirects' ),
				data.redirects.length,
				MAX_PREVIEW
			),
		] );
	}

	const errors = [];
	if (
		data.groups !== undefined &&
		( ! Array.isArray( data.groups ) ||
			data.groups.some(
				( group ) =>
					! isObject( group ) ||
					! isNumberLike( group.id ) ||
					typeof group.name !== 'string'
			) )
	) {
		errors.push( __( '"groups" must be a list of objects with an id and a name.', 'wp-redirects' ) );
	}

	data.redirects.forEach( ( entry, index ) => {
		if ( errors.length >= MAX_ERRORS ) {
			return;
		}
		if ( ! isObject( entry ) ) {
			/* translators: %d: entry number */
			errors.push( sprintf( __( 'Entry %d is not an object.', 'wp-redirects' ), index + 1 ) );
			return;
		}
		const broken = ENTRY_FIELDS.find( ( [ field, test ] ) => ! test( entry[ field ] ) );
		if ( broken ) {
			errors.push(
				sprintf(
					/* translators: 1: entry number, 2: field name, 3: expected type */
					__( 'Entry %1$d: "%2$s" must be %3$s.', 'wp-redirects' ),
					index + 1,
					broken[ 0 ],
					broken[ 2 ]()
				)
			);
		}
	} );

	if ( errors.length ) {
		return fail( errors );
	}

	const regex = data.redirects.filter( ( entry ) => entry.regex ).length;
	return {
		ok: true,
		errors: [],
		summary: {
			version: data.plugin.version,
			date: typeof data.plugin.date === 'string' ? data.plugin.date : '',
			total: data.redirects.length,
			exact: data.redirects.length - regex,
			regex,
			logs: Array.isArray( data.logs ) ? data.logs.length : 0,
			errors404: Array.isArray( data.errors_404 ) ? data.errors_404.length : 0,
		},
	};
}

export function stripExport( data ) {
	const redirects = data.redirects
		.map( ( entry, order ) => ( {
			order,
			entry: Object.fromEntries(
				KEEP_FIELDS.filter( ( field ) => field in entry ).map( ( field ) => [ field, entry[ field ] ] )
			),
		} ) )
		.sort(
			( a, b ) =>
				( Number( a.entry.position ) || 0 ) - ( Number( b.entry.position ) || 0 ) ||
				a.order - b.order
		)
		.map( ( item ) => item.entry );

	return {
		source: 'redirection',
		version: data.plugin.version,
		groups: ( Array.isArray( data.groups ) ? data.groups : [] ).map( ( group ) => ( {
			id: Number( group.id ),
			name: group.name,
		} ) ),
		redirects,
	};
}

export function chunk( items, size ) {
	const out = [];
	for ( let i = 0; i < items.length; i += size ) {
		out.push( items.slice( i, i + size ) );
	}
	return out;
}

export function importableEntries( preview, redirects ) {
	return preview.entries
		.filter( ( entry ) => entry.status === 'new' || entry.status === 'overwrite' )
		.map( ( entry ) => ( { index: entry.index, entry: redirects[ entry.index ] } ) );
}

export function groupPreview( preview ) {
	const groups = { new: [], overwrite: [], warnings: [], skipped: [], superseded: [] };
	preview.entries.forEach( ( entry ) => {
		groups[ entry.status ].push( entry );
		if ( entry.warnings && entry.warnings.length ) {
			groups.warnings.push( entry );
		}
	} );
	return groups;
}

export const NOTE_LABELS = {
	case_insensitive: __( 'Imported as case-insensitive (WP Redirects always ignores case).', 'wp-redirects' ),
	trailing_slash_ignored: __( 'A trailing slash is ignored when matching.', 'wp-redirects' ),
	query_mode: __( 'Query strings follow WP Redirects rules: matched only when the source has one, forwarded per Settings.', 'wp-redirects' ),
	regex_query: __( 'Regex rules match the path only, so the query part of this pattern will not match.', 'wp-redirects' ),
};

export function buildReport( { summary, preview, importSkipped = [] } ) {
	return {
		generated: new Date().toISOString(),
		file: { plugin: 'redirection', version: summary.version, date: summary.date },
		skipped: [
			...preview.entries
				.filter( ( entry ) => entry.status === 'skipped' )
				.map( ( entry ) => ( {
					index: entry.index,
					source: entry.source,
					code: entry.error.code,
					message: entry.error.message,
					stage: 'preview',
				} ) ),
			...importSkipped.map( ( item ) => ( {
				index: item.index,
				source: item.source,
				code: item.error ? item.error.code : 'unknown',
				message: item.error ? item.error.message : '',
				stage: 'import',
			} ) ),
		],
		superseded: preview.entries
			.filter( ( entry ) => entry.status === 'superseded' )
			.map( ( entry ) => ( { index: entry.index, source: entry.source } ) ),
	};
}
```

`Object.fromEntries` is supported by every browser WordPress 6.6 supports, and the babel preset polyfills it if needed.

- [ ] **Step 4: Add the API calls to `assets/src/api.js`** (inside the `api` object, after `saveSettings`)

```js
	importPreview: ( payload ) =>
		apiFetch( { path: `${ NS }/import/preview`, method: 'POST', data: payload } ),
	importBatch: ( payload ) =>
		apiFetch( { path: `${ NS }/import`, method: 'POST', data: payload } ),
```

- [ ] **Step 5: Run tests and lint**

Run: `npx jest tests/js/redirectionImport.test.js && npm run test:js && npm run lint:js`
Expected: the new suite passes (13 tests), the full Jest run is green, and lint is clean. Run `npm run format` for formatting-only issues.

- [ ] **Step 6: Commit**

```bash
git add assets/src/utils/redirectionImport.js assets/src/api.js tests/js/redirectionImport.test.js
git commit -m "feat: check, strip and batch Redirection exports in the browser

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Import tab UI

**Files:**
- Create: `assets/src/components/ImportTab.js`, `assets/src/components/ImportPreview.js`
- Modify: `assets/src/components/App.js`, `assets/src/admin.scss`

**Interfaces:**
- Consumes: everything from Task 5; `errorMessage` from `../constants`; the existing card classes (`adv-redirects-card`, `adv-redirects-card__title`), `adv-redirects-table`, `adv-redirects-muted` and `adv-redirects-flag`.
- Produces: `<ImportTab notify onImported onViewRedirects />`. The accessible names Task 7 relies on:
  - the file input, labelled "Redirection export file"
  - buttons "Choose file", "Preview import", the "Import N redirects…" button, "Cancel", "Download report", "View redirects" and "Import another file"
  - preview group headings "New (N)", "Will overwrite (N)", "Chain warnings (N)", "Skipped (N)" and "Superseded (N)"
  - the result notice text, which starts with "Import complete"

- [ ] **Step 1: Implement `assets/src/components/ImportPreview.js`**

```js
import { __, sprintf } from '@wordpress/i18n';
import { groupPreview, NOTE_LABELS } from '../utils/redirectionImport';
import StatusBadge from './StatusBadge';

function Notes( { entry } ) {
	if ( ! entry.notes || ! entry.notes.length ) {
		return null;
	}
	return (
		<ul className="adv-redirects-import__notes">
			{ entry.notes.map( ( code ) => (
				<li key={ code }>{ NOTE_LABELS[ code ] || code }</li>
			) ) }
		</ul>
	);
}

function Target( { rule } ) {
	if ( ! rule ) {
		return null;
	}
	return rule.target ? (
		<code>{ rule.target }</code>
	) : (
		<span className="adv-redirects-muted">{ __( 'No target', 'wp-redirects' ) }</span>
	);
}

function Group( { title, entries, render, open = false } ) {
	if ( ! entries.length ) {
		return null;
	}
	return (
		<details className="adv-redirects-import__group" open={ open }>
			<summary>{ sprintf( title, entries.length ) }</summary>
			<table className="adv-redirects-table">
				<tbody>
					{ entries.map( ( entry ) => (
						<tr key={ `${ entry.status }-${ entry.index }` }>
							<td className="adv-redirects-col-source">
								<code>{ entry.source }</code>
							</td>
							<td>{ render( entry ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</details>
	);
}

export default function ImportPreview( { preview } ) {
	const groups = groupPreview( preview );

	return (
		<div className="adv-redirects-import__preview">
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'New (%d)', 'wp-redirects' ) }
				entries={ groups.new }
				open={ groups.new.length <= 20 }
				render={ ( entry ) => (
					<>
						<StatusBadge status={ entry.rule.status_code } /> <Target rule={ entry.rule } />
						{ entry.rule.origin === 'auto' && (
							<span className="adv-redirects-flag">{ __( 'Auto', 'wp-redirects' ) }</span>
						) }
						{ ! entry.rule.enabled && (
							<span className="adv-redirects-flag">{ __( 'Disabled', 'wp-redirects' ) }</span>
						) }
						<Notes entry={ entry } />
					</>
				) }
			/>
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'Will overwrite (%d)', 'wp-redirects' ) }
				entries={ groups.overwrite }
				open
				render={ ( entry ) => (
					<>
						<span className="adv-redirects-muted">
							<StatusBadge status={ entry.current.status_code } />{ ' ' }
							{ entry.current.target ? <code>{ entry.current.target }</code> : __( 'No target', 'wp-redirects' ) }
						</span>
						{ ' → ' }
						<StatusBadge status={ entry.rule.status_code } /> <Target rule={ entry.rule } />
						<Notes entry={ entry } />
					</>
				) }
			/>
			<Group
				/* translators: %d: number of redirects */
				title={ __( 'Chain warnings (%d)', 'wp-redirects' ) }
				entries={ groups.warnings }
				render={ ( entry ) => <code>{ entry.warnings[ 0 ].hops.join( ' → ' ) }</code> }
			/>
			<Group
				/* translators: %d: number of entries */
				title={ __( 'Skipped (%d)', 'wp-redirects' ) }
				entries={ groups.skipped }
				open
				render={ ( entry ) => <span className="is-error">{ entry.error.message }</span> }
			/>
			<Group
				/* translators: %d: number of entries */
				title={ __( 'Superseded (%d)', 'wp-redirects' ) }
				entries={ groups.superseded }
				render={ () => (
					<span className="adv-redirects-muted">
						{ __( 'A later entry in the file uses the same source and wins.', 'wp-redirects' ) }
					</span>
				) }
			/>
		</div>
	);
}
```

- [ ] **Step 2: Implement `assets/src/components/ImportTab.js`**

```js
import { Button, Notice, Spinner } from '@wordpress/components';
import { useId, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import {
	BATCH_SIZE,
	buildReport,
	checkRedirectionExport,
	chunk,
	importableEntries,
	stripExport,
} from '../utils/redirectionImport';
import ImportPreview from './ImportPreview';

function downloadJson( data, filename ) {
	const url = URL.createObjectURL( new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}

export default function ImportTab( { onImported, onViewRedirects } ) {
	const inputId = useId();
	const inputRef = useRef();
	const cancelRef = useRef( false );
	// idle | checked | previewing | previewed | importing | done | failed
	const [ phase, setPhase ] = useState( 'idle' );
	const [ fileName, setFileName ] = useState( '' );
	const [ check, setCheck ] = useState( null );
	const [ payload, setPayload ] = useState( null );
	const [ preview, setPreview ] = useState( null );
	const [ progress, setProgress ] = useState( { done: 0, total: 0 } );
	const [ result, setResult ] = useState( null );
	const [ requestError, setRequestError ] = useState( '' );
	const [ dragging, setDragging ] = useState( false );

	const reset = () => {
		setPhase( 'idle' );
		setFileName( '' );
		setCheck( null );
		setPayload( null );
		setPreview( null );
		setProgress( { done: 0, total: 0 } );
		setResult( null );
		setRequestError( '' );
		if ( inputRef.current ) {
			inputRef.current.value = '';
		}
	};

	const readFile = async ( file ) => {
		if ( ! file ) {
			return;
		}
		reset();
		setFileName( file.name );
		let data;
		try {
			data = JSON.parse( await file.text() );
		} catch {
			setCheck( { ok: false, errors: [ __( 'This file is not valid JSON.', 'wp-redirects' ) ], summary: null } );
			setPhase( 'checked' );
			return;
		}
		const checked = checkRedirectionExport( data );
		setCheck( checked );
		setPayload( checked.ok ? stripExport( data ) : null );
		setPhase( 'checked' );
	};

	const runPreview = async () => {
		setPhase( 'previewing' );
		setRequestError( '' );
		try {
			setPreview( await api.importPreview( payload ) );
			setPhase( 'previewed' );
		} catch ( error ) {
			setRequestError( errorMessage( error ) );
			setPhase( 'checked' );
		}
	};

	const runImport = async () => {
		const entries = importableEntries( preview, payload.redirects );
		const totals = { created: 0, updated: 0, skipped: [] };
		let done = 0;
		cancelRef.current = false;
		setProgress( { done: 0, total: entries.length } );
		setPhase( 'importing' );
		try {
			for ( const batch of chunk( entries, BATCH_SIZE ) ) {
				if ( cancelRef.current ) {
					break;
				}
				const response = await api.importBatch( {
					source: 'redirection',
					groups: payload.groups,
					redirects: batch.map( ( item ) => item.entry ),
				} );
				response.entries.forEach( ( item, position ) => {
					if ( item.result === 'created' ) {
						totals.created++;
					} else if ( item.result === 'updated' ) {
						totals.updated++;
					} else {
						totals.skipped.push( {
							index: batch[ position ].index,
							source: batch[ position ].entry.url,
							error: item.error,
						} );
					}
				} );
				done += batch.length;
				setProgress( { done, total: entries.length } );
			}
			setResult( { ...totals, done, cancelled: done < entries.length } );
			setPhase( 'done' );
		} catch ( error ) {
			setResult( { ...totals, done, failed: errorMessage( error ) } );
			setPhase( 'failed' );
		} finally {
			onImported();
		}
	};

	const counts = preview ? preview.counts : null;
	const importCount = counts ? counts.new + counts.overwrite : 0;
	const busy = phase === 'previewing' || phase === 'importing';
	const percent = progress.total ? Math.round( ( progress.done / progress.total ) * 100 ) : 0;

	return (
		<>
			<section className="adv-redirects-card adv-redirects-import">
				<h2 className="adv-redirects-card__title">{ __( 'Import from Redirection', 'wp-redirects' ) }</h2>
				<p className="description">
					{ __( 'Upload a JSON export from the Redirection plugin. Only the redirect rules are read; its logs and 404 records stay on your computer.', 'wp-redirects' ) }
				</p>
				<div
					className={ `adv-redirects-import__drop${ dragging ? ' is-dragging' : '' }` }
					onDragOver={ ( event ) => {
						event.preventDefault();
						setDragging( true );
					} }
					onDragLeave={ () => setDragging( false ) }
					onDrop={ ( event ) => {
						event.preventDefault();
						setDragging( false );
						if ( ! busy ) {
							readFile( event.dataTransfer.files[ 0 ] );
						}
					} }
				>
					<input
						ref={ inputRef }
						id={ inputId }
						type="file"
						accept=".json,application/json"
						className="adv-redirects-import__input"
						aria-label={ __( 'Redirection export file', 'wp-redirects' ) }
						disabled={ busy }
						onChange={ ( event ) => readFile( event.target.files[ 0 ] ) }
					/>
					<Button variant="secondary" disabled={ busy } onClick={ () => inputRef.current.click() } __next40pxDefaultSize>
						{ __( 'Choose file', 'wp-redirects' ) }
					</Button>
					<span className="adv-redirects-muted">
						{ fileName || __( 'or drop a .json file here', 'wp-redirects' ) }
					</span>
				</div>

				{ check && ! check.ok && (
					<Notice status="error" isDismissible={ false }>
						<p>{ __( 'This file can’t be imported:', 'wp-redirects' ) }</p>
						<ul>
							{ check.errors.map( ( message ) => (
								<li key={ message }>{ message }</li>
							) ) }
						</ul>
					</Notice>
				) }

				{ check && check.ok && (
					<dl className="adv-redirects-import__summary">
						<dt>{ __( 'Exported from', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: plugin version, 2: export date */
								__( 'Redirection %1$s, %2$s', 'wp-redirects' ),
								check.summary.version,
								check.summary.date
							) }
						</dd>
						<dt>{ __( 'Redirects found', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: total, 2: exact count, 3: regex count */
								__( '%1$d (%2$d exact, %3$d regex)', 'wp-redirects' ),
								check.summary.total,
								check.summary.exact,
								check.summary.regex
							) }
						</dd>
						<dt>{ __( 'Not imported', 'wp-redirects' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: log entries, 2: 404 records */
								__( '%1$d log entries and %2$d 404 records (they never leave your computer)', 'wp-redirects' ),
								check.summary.logs,
								check.summary.errors404
							) }
						</dd>
					</dl>
				) }

				{ requestError && (
					<Notice status="error" isDismissible={ false }>
						{ requestError }
					</Notice>
				) }

				{ ( phase === 'checked' || phase === 'previewing' ) && check && check.ok && (
					<Button variant="primary" isBusy={ phase === 'previewing' } disabled={ busy } onClick={ runPreview } __next40pxDefaultSize>
						{ __( 'Preview import', 'wp-redirects' ) }
					</Button>
				) }
			</section>

			{ preview && phase !== 'done' && phase !== 'failed' && (
				<section className="adv-redirects-card">
					<h2 className="adv-redirects-card__title">{ __( 'Preview', 'wp-redirects' ) }</h2>
					<ImportPreview preview={ preview } />
					{ phase === 'previewed' && (
						<Button variant="primary" disabled={ importCount === 0 } onClick={ runImport } __next40pxDefaultSize>
							{ counts.overwrite > 0
								? sprintf(
										/* translators: 1: number to import, 2: number that overwrite existing rules */
										_n( 'Import %1$d redirect (%2$d overwrite existing)', 'Import %1$d redirects (%2$d overwrite existing)', importCount, 'wp-redirects' ),
										importCount,
										counts.overwrite
								  )
								: sprintf(
										/* translators: %d: number to import */
										_n( 'Import %d redirect', 'Import %d redirects', importCount, 'wp-redirects' ),
										importCount
								  ) }
						</Button>
					) }
					{ phase === 'importing' && (
						<div className="adv-redirects-import__progress">
							<progress max={ progress.total } value={ progress.done } aria-label={ __( 'Import progress', 'wp-redirects' ) } />
							<p aria-live="polite">
								{ sprintf(
									/* translators: 1: imported so far, 2: total, 3: percent */
									__( 'Imported %1$d of %2$d (%3$d%%)', 'wp-redirects' ),
									progress.done,
									progress.total,
									percent
								) }
								{ ' ' }
								<Spinner />
							</p>
							<Button variant="secondary" onClick={ () => ( cancelRef.current = true ) }>
								{ __( 'Cancel', 'wp-redirects' ) }
							</Button>
						</div>
					) }
				</section>
			) }

			{ result && (
				<section className="adv-redirects-card">
					<Notice status={ phase === 'failed' ? 'error' : 'success' } isDismissible={ false }>
						{ phase === 'failed'
							? sprintf(
									/* translators: 1: error message, 2: rules imported before the failure */
									__( 'Import stopped: %1$s. %2$d redirects were imported before it stopped; running the import again is safe.', 'wp-redirects' ),
									result.failed,
									result.created + result.updated
							  )
							: sprintf(
									/* translators: 1: created, 2: updated, 3: skipped */
									__( 'Import complete: %1$d created, %2$d updated, %3$d skipped.', 'wp-redirects' ),
									result.created,
									result.updated,
									result.skipped.length + counts.skipped
							  ) }
						{ result.cancelled && phase === 'done' && ' ' + __( 'Cancelled before all batches ran.', 'wp-redirects' ) }
					</Notice>
					<div className="adv-redirects-import__actions">
						<Button
							variant="secondary"
							onClick={ () =>
								downloadJson(
									buildReport( { summary: check.summary, preview, importSkipped: result.skipped } ),
									'wp-redirects-import-report.json'
								)
							}
						>
							{ __( 'Download report', 'wp-redirects' ) }
						</Button>
						<Button variant="primary" onClick={ onViewRedirects }>
							{ __( 'View redirects', 'wp-redirects' ) }
						</Button>
						<Button variant="tertiary" onClick={ reset }>
							{ __( 'Import another file', 'wp-redirects' ) }
						</Button>
					</div>
				</section>
			) }
		</>
	);
}
```

`ImportTab` deliberately takes no `notify`; all its feedback is shown inline.

- [ ] **Step 3: Wire the tab in `assets/src/components/App.js`**

- Add `import ImportTab from './ImportTab';` after `import NotFoundTab from './NotFoundTab';`.
- Add the tab after the settings entry: `{ name: 'import', title: __( 'Import', 'wp-redirects' ) },`.
- Inside the tabpanel, after the settings blocks, add:

```js
				{ tab === 'import' && (
					<ImportTab
						onImported={ redirects.reload }
						onViewRedirects={ () => setTab( 'redirects' ) }
					/>
				) }
```

- [ ] **Step 4: Append the import styles to `assets/src/admin.scss`**

```scss
.adv-redirects-import {
	display: grid;
	gap: 16px;

	&__drop {
		align-items: center;
		border: 2px dashed var(--adv-border);
		border-radius: var(--adv-radius);
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		padding: 20px;

		&.is-dragging {
			border-color: var(--adv-accent);
			background: color-mix(in srgb, var(--adv-accent) 6%, transparent);
		}
	}

	// Visually hidden but still focusable and reachable by tests.
	&__input {
		border: 0;
		clip: rect(0 0 0 0);
		height: 1px;
		margin: -1px;
		overflow: hidden;
		padding: 0;
		position: absolute;
		width: 1px;
	}

	&__summary {
		display: grid;
		gap: 4px 16px;
		grid-template-columns: max-content 1fr;
		margin: 0;

		dt {
			color: var(--adv-muted);
		}

		dd {
			margin: 0;
		}
	}

	&__group {
		border-top: 1px solid var(--adv-border);
		padding: 8px 0;

		summary {
			cursor: pointer;
			font-weight: 600;
			padding: 4px 0;
		}
	}

	&__notes {
		color: var(--adv-muted);
		font-size: 12px;
		margin: 4px 0 0;
	}

	&__progress {
		display: grid;
		gap: 8px;
		margin-top: 12px;

		progress {
			height: 12px;
			width: 100%;
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 12px;
	}
}
```

If the Task 20 design pass renamed `--adv-border`, `--adv-radius`, `--adv-accent` or `--adv-muted`, use whatever names `admin.scss` currently defines at the top of `.adv-redirects`.

- [ ] **Step 5: Build, lint, test**

Run: `npm run build && npm run lint:js && npm run test:js`
Expected: all green. Run `npm run format` for formatting-only issues.

- [ ] **Step 6: Check the screen on the dev site**

On http://localhost:8888/wp-admin/admin.php?page=adv-redirects, open **Import** and upload `tests/fixtures/redirection-export-sample.json`.
- The summary reads "21 (18 exact, 3 regex)", with 2 log entries and 2 404 records not imported.
- **Preview import** shows: New (13), Chain warnings (1), Skipped (7), Superseded (1), and a button reading "Import 13 redirects".
- **Do not click Import on the dev site.** It holds the user's manual-testing data.
- Uploading `package.json` shows "This file is not a Redirection export…".

- [ ] **Step 7: Commit**

```bash
git add assets/src/components/ImportTab.js assets/src/components/ImportPreview.js assets/src/components/App.js assets/src/admin.scss
git commit -m "feat: add Import tab with preview and batched progress

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: End-to-end test, docs and real-file dry run

**Files:**
- Create: `tests/e2e/import.spec.js`
- Modify: `readme.txt`, `CLAUDE.md`

**Interfaces:**
- Consumes: the accessible names from Task 6, the fixture from Task 1, and the wp-env tests site.

- [ ] **Step 1: Write `tests/e2e/import.spec.js`**

```js
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const FIXTURE = path.join( __dirname, '..', 'fixtures', 'redirection-export-sample.json' );

async function deleteAllRedirects( requestUtils ) {
	const rules = await requestUtils.rest( { path: '/adv-redirects/v1/redirects' } );
	for ( const rule of rules ) {
		await requestUtils.rest( { path: `/adv-redirects/v1/redirects/${ rule.id }`, method: 'DELETE' } );
	}
}

test.describe( 'Import from Redirection', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test( 'checks, previews and imports a Redirection export', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();

		await page.getByLabel( 'Redirection export file' ).setInputFiles( FIXTURE );
		await expect( page.getByText( '21 (18 exact, 3 regex)' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Preview import' } ).click();
		await expect( page.getByText( 'New (13)' ) ).toBeVisible();
		await expect( page.getByText( 'Skipped (7)' ) ).toBeVisible();
		await expect( page.getByText( 'Superseded (1)' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Import 13 redirects' } ).click();
		await expect( page.getByText( /Import complete: 13 created, 0 updated, 7 skipped\./ ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'View redirects' } ).click();
		await expect( page.getByRole( 'cell', { name: '/fx-old-page/', exact: true } ) ).toBeVisible();
	} );

	test( 'rejects a file that is not a Redirection export', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await page.getByLabel( 'Redirection export file' ).setInputFiles( {
			name: 'not-redirection.json',
			mimeType: 'application/json',
			buffer: Buffer.from( JSON.stringify( { hello: 'world' } ) ),
		} );
		await expect( page.getByText( /not a Redirection export/ ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Preview import' } ) ).toHaveCount( 0 );
	} );
} );
```

The client sends only `new`/`overwrite` entries, so nothing is skipped during import. The 7 skips come from the preview; superseded entries are listed separately and are not counted as skips.

- [ ] **Step 2: Run e2e**

Run: `npm run build && npx wp-env clean tests && npm run env:start && npm run test:e2e`
Expected: 5 passed (3 existing plus 2 new). Never clean or reseed the **dev** environment.

- [ ] **Step 3: Update `readme.txt` and `CLAUDE.md`**

In `readme.txt` under `== Description ==`, add after the 404 log bullet:

```
* Import redirects from a Redirection plugin JSON export, with a dry-run preview (new, overwrite, skipped) and batched import
```

In `CLAUDE.md`, under Layout, add:

```markdown
- `src/Import/`: `RedirectionMapper` (pure mapping of Redirection export entries) and `Importer` (preview/import through Validator + Repository); UI in `assets/src/components/ImportTab.js`
```

Under Security, add:

```markdown
- Real redirect exports are gitignored and must never be committed or used in tests; use `tests/fixtures/redirection-export-sample.json`.
```

- [ ] **Step 4: Run every suite**

Run: `composer lint && npm run test:php:unit && npm run test:php:integration && npm run lint:js && npm run test:js`
Expected: all green, output clean.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/import.spec.js readme.txt CLAUDE.md
git commit -m "test: cover Redirection import end to end; document import

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6 (controller, not a subagent): dry run of the user's real export**

Dry-run the gitignored `redirection-export.json` on the **dev** site's preview endpoint only, with no import. Strip it the same way the browser does, and send only `plugin.version`, `groups` and `redirects`. Report the preview counts and the skip reasons with their codes and counts to the user. Do not print URLs unless the user asks.
