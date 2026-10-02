# WP Redirects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `wp-redirects`, a WordPress plugin for exact and regex redirects (301/302/307/308/410/451), backed by an object-cached compiled rule set. It includes hit counts, a slug-change watcher, loop/chain detection, a test-URL tool, a 404 log, a hooks API, a fast React admin, and GitHub-release self-updates.

**Architecture:** Pure-PHP core units (`PathNormalizer`, `UrlSafety`, `Pattern`, `TargetResolver`, `RulesetCompiler`, `Matcher`) hold the matching logic and are unit-tested without WordPress. A WordPress layer sits on top:
- custom tables, Repository, RuleCache
- Validator, ChainResolver
- Redirector, HitTracker, 404 logging, SlugWatcher
- REST controllers

A React admin built with `@wordpress/scripts` talks only to the REST API. CI builds the assets and ships them in the release zip.

**Tech Stack:** PHP 7.4+ / WordPress 6.6+, MySQL custom tables via `$wpdb` (`%i` identifiers), WP object cache, React via `@wordpress/element` + `@wordpress/components`, `@wordpress/scripts`, `@wordpress/env`, PHPUnit 9.6 + `yoast/phpunit-polyfills`, Jest, Playwright, PHPCS (WPCS 3 + PHPCompatibilityWP), `yahnis-elsts/plugin-update-checker` v5, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-02-wp-redirects-design.md`

**Spec amendments made while planning** (Task 1 Step 1 writes them into the spec):
1. Minimum WordPress raised from **6.4 to 6.6**. Current `@wordpress/scripts` emits a `react-jsx-runtime` script dependency, which WordPress registers only from 6.6.
2. Reserved sources: exact sources under `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `/wp-cron.php` and the REST prefix are rejected, and the Redirector never handles those paths. This prevents admin lockout.
3. Regex captures are URL-encoded per segment (keeping `/`) before `$n` substitution.
4. Slug watcher captures descendant permalinks *before* the update, rather than deriving them by prefix replacement. That's more accurate.
5. Extra small pure/helper units: `Matching/UrlSafety.php`, `Matching/Pattern.php`, `Matching/RulesetCompiler.php`, `Redirects/ChainResolver.php`, `Site.php`, `Permissions.php`, `Uninstaller.php`, `Rest/BaseController.php`.
6. Bulk **enable** re-validates each rule, so enabling can't create a loop. Rules that fail are returned as `skipped`.

## Global Constraints

- PHP floor **7.4**: no `match`, constructor promotion, union types, named arguments, `mixed`, nullsafe `?->`, `throw` expressions, `str_contains`/`str_starts_with` in pure classes. Typed properties, arrow functions and `??=` are allowed. `callable` can't be a property type.
- WordPress floor **6.6**. Tested on 6.6 and latest (7.1). Header: `Requires at least: 6.6`, `Requires PHP: 7.4`.
- Names: slug/text domain `wp-redirects`, namespace `Advision\Redirects`, prefix `adv_redirects_`, constants `ADV_REDIRECTS_*`, tables `{$wpdb->prefix}adv_redirects` and `{$wpdb->prefix}adv_redirects_404s`, option `adv_redirects_settings`, schema option `adv_redirects_db_version`, cache group `adv_redirects`, REST namespace `adv-redirects/v1`.
- PHP style: WordPress spacing, tabs, short arrays allowed, `snake_case` methods/properties, PSR-4 file names (`src/Matching/Matcher.php` = `Advision\Redirects\Matching\Matcher`).
- Every PHP file in `src/`, `wp-redirects.php` and `uninstall.php` starts with `defined( 'ABSPATH' ) || exit;` after the namespace line.
- Every SQL query containing variables uses `$wpdb->prepare()`, with table names passed as `%i`.
- Allowed status codes: exactly `[301, 302, 307, 308, 410, 451]`. 410/451 have no target.
- Max lengths: source/target/path/referrer 2048, regex source 500, note 255, bulk ids 500, 404 `per_page` 100.
- No IPs or user agents stored anywhere.
- Runtime Composer deps: only `yahnis-elsts/plugin-update-checker`. `build/`, `vendor/`, `node_modules/` are gitignored.
- Commit messages use Conventional Commits and end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Non-ASCII / percent-encoded paths.** A rule `/café` must match requests `/café`, `/caf%C3%A9` and `/CAFÉ`. Pinned in Task 2 (`test_unicode_and_encoded_paths_share_a_key`).
2. **Subdirectory installs** (home `https://example.com/blog`). Rule `/old` matches request `/blog/old`, and relative target `/new` goes to `https://example.com/blog/new`. Pinned in Task 2 (`test_subdirectory_install_strips_home_path`) and Task 4 (`test_relative_target_resolves_against_subdirectory_home`).
3. **Targets with a fragment plus query forwarding.** `/new#top` with incoming `?utm=x` must become `/new?utm=x#top`, never `/new#top?utm=x`. Pinned in Task 4 (`test_merge_query_keeps_fragment_last`).
4. **Near-duplicate exact sources.** Saving `/Old-Page/` when `/old-page` exists must be rejected as a duplicate (409). Pinned in Task 8 (`test_duplicate_detection_ignores_case_and_trailing_slash`).
5. **Lockout rules.** A rule for `/wp-login.php`, `/wp-admin/…` or `/wp-json/…` must be rejected at save time, and never acted on at request time even if inserted directly into the DB. Pinned in Task 8 (`test_reserved_sources_rejected`) and Task 10 (`test_reserved_paths_never_redirect`).

---

## File Map

```
wp-redirects.php                       bootstrap (Task 1, rewired Task 15)
uninstall.php                          Task 15
readme.txt                             Task 1
CLAUDE.md                              Task 1 (finalized Task 21)
composer.json / package.json           Task 1
phpcs.xml.dist                         Task 1
phpunit.unit.xml.dist                  Task 1
phpunit.integration.xml.dist           Task 6
.wp-env.json                           Task 6
.gitignore / .distignore               Task 1
playwright.config.js                   Task 19
.github/workflows/ci.yml, release.yml  Task 21
docs/hooks.md                          Task 15
src/Autoloader.php                     Task 1
src/Plugin.php                         Task 1 (skeleton) → Task 15 (full wiring)
src/Site.php                           Task 6
src/Permissions.php                    Task 13
src/Schema.php, src/Settings.php       Task 6
src/Uninstaller.php                    Task 15
src/functions.php                      Task 15
src/Matching/PathNormalizer.php        Task 2
src/Matching/UrlSafety.php             Task 3
src/Matching/Pattern.php               Task 3
src/Matching/MatchResult.php           Task 4
src/Matching/TargetResolver.php        Task 4
src/Matching/RulesetCompiler.php       Task 5
src/Matching/Matcher.php               Task 5
src/Matching/RuleCache.php             Task 7
src/Matching/Redirector.php            Task 10
src/Redirects/Rule.php                 Task 7
src/Redirects/Repository.php           Task 7
src/Redirects/ChainResolver.php        Task 8
src/Redirects/Validator.php            Task 8
src/Tracking/HitTracker.php            Task 9
src/Cron.php                           Task 11
src/Tracking/NotFoundRepository.php    Task 11
src/Tracking/NotFoundLogger.php        Task 11
src/SlugWatcher.php                    Task 12
src/Rest/BaseController.php            Task 13
src/Rest/RedirectsController.php       Task 13
src/Rest/TestController.php            Task 13
src/Rest/NotFoundController.php        Task 14
src/Rest/SettingsController.php        Task 14
src/Admin/AdminPage.php                Task 16
src/Updates/UpdateChecker.php          Task 15
assets/src/**                          Tasks 16–18, 20
tests/unit/**                          Tasks 1–5
tests/integration/**                   Tasks 6–15
tests/js/**                            Tasks 16–18
tests/e2e/**                           Task 19
```

---

### Task 1: Scaffold, tooling, CLAUDE.md

**Files:**
- Modify: `docs/superpowers/specs/2026-10-02-wp-redirects-design.md` (amendments)
- Create: `composer.json`, `package.json`, `.gitignore`, `.distignore`, `phpcs.xml.dist`, `phpunit.unit.xml.dist`, `readme.txt`, `CLAUDE.md`, `wp-redirects.php`, `src/Autoloader.php`, `src/Plugin.php`, `tests/unit/bootstrap.php`
- Test: `tests/unit/AutoloaderTest.php`

**Interfaces:**
- Produces:
  - constants `ADV_REDIRECTS_VERSION` (`'0.1.0'`), `ADV_REDIRECTS_FILE`, `ADV_REDIRECTS_DIR` (trailing slash), `ADV_REDIRECTS_URL` (trailing slash)
  - `Advision\Redirects\Autoloader::register( string $base_dir ): void`
  - `Advision\Redirects\Plugin::instance(): Plugin`, `Plugin::boot(): void`

- [ ] **Step 0: Create the feature branch**

Run: `git checkout -b feat/initial-plugin`
Expected: `Switched to a new branch 'feat/initial-plugin'`. `main` holds only the spec and plan commits.

- [ ] **Step 1: Amend the spec**

Edit `docs/superpowers/specs/2026-10-02-wp-redirects-design.md`:
- §3: replace "**WordPress 6.4+** (tested on 6.4 and latest, currently 7.1)" with "**WordPress 6.6+** (tested on 6.6 and latest, currently 7.1). 6.6 is the first release that registers the `react-jsx-runtime` script current `@wordpress/scripts` builds depend on". Replace "stable since 6.4" with "stable since 6.6" and `Requires at least: 6.4` with `Requires at least: 6.6`.
- §14 CI bullet: "WP 6.4 and latest" → "WP 6.6 and latest".
- Append this section at the end:

```markdown
## 16. Amendments (implementation planning, 2026-10-02)

1. Minimum WordPress is 6.6 (see §3).
2. Reserved sources: exact sources whose path is or starts with `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `/wp-cron.php` or `/<rest prefix>` are rejected with `adv_redirects_reserved_source` (422). The Redirector also never handles these paths.
3. Regex captures are URL-encoded per path segment (`/` kept) before `$n` substitution.
4. The slug watcher captures descendant permalinks before the update (in `pre_post_update`) instead of deriving them by prefix replacement.
5. Additional units: `Matching/UrlSafety`, `Matching/Pattern`, `Matching/RulesetCompiler`, `Redirects/ChainResolver`, `Site`, `Permissions`, `Uninstaller`, `Rest/BaseController`.
6. Bulk enable re-validates each rule. Rules that would create a loop are left disabled and returned in `skipped`.
```

- [ ] **Step 2: Install Composer (not present on this machine)**

Run: `brew install composer && composer --version`
Expected: `Composer version 2.x`

- [ ] **Step 3: Create `composer.json`**

```json
{
	"name": "advision-development/wp-redirects",
	"description": "Redirect manager for WordPress: exact and regex redirects, object-cached matching, 404 log.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"php": ">=7.4"
	},
	"config": {
		"platform": {
			"php": "7.4.33"
		},
		"sort-packages": true,
		"allow-plugins": {
			"dealerdirect/phpcodesniffer-composer-installer": true
		}
	},
	"scripts": {
		"lint": "phpcs",
		"fix": "phpcbf",
		"test:unit": "phpunit -c phpunit.unit.xml.dist"
	}
}
```

Then run:

```bash
composer require yahnis-elsts/plugin-update-checker:^5.6
composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:"^1.1 || ^2.0" wp-coding-standards/wpcs:^3.1 phpcompatibility/phpcompatibility-wp:^2.1 dealerdirect/phpcodesniffer-composer-installer:^1.0
```

Expected: `composer.lock` and `vendor/` created, no errors. `config.platform.php` pins resolution to PHP 7.4-compatible versions.

- [ ] **Step 4: Create `package.json` and install JS tooling**

```json
{
	"name": "wp-redirects",
	"private": true,
	"license": "GPL-2.0-or-later",
	"scripts": {
		"build": "wp-scripts build assets/src/index.js --output-path=build",
		"start": "wp-scripts start assets/src/index.js --output-path=build",
		"lint:js": "wp-scripts lint-js assets/src tests/js tests/e2e",
		"format": "wp-scripts format assets/src tests/js tests/e2e",
		"test:js": "wp-scripts test-unit-js",
		"test:e2e": "wp-scripts test-playwright",
		"env:start": "wp-env start",
		"env:stop": "wp-env stop",
		"test:php:unit": "vendor/bin/phpunit -c phpunit.unit.xml.dist",
		"test:php:integration": "wp-env run tests-cli --env-cwd=wp-content/plugins/wp-redirects vendor/bin/phpunit -c phpunit.integration.xml.dist"
	}
}
```

Run: `npm install --save-dev @wordpress/scripts @wordpress/env @wordpress/e2e-test-utils-playwright @playwright/test`
Expected: `package-lock.json` created, `devDependencies` populated with current versions.

- [ ] **Step 5: Create `.gitignore` and `.distignore`**

`.gitignore`:
```
/vendor/
/node_modules/
/build/
/dist/
/artifacts/
/test-results/
/playwright-report/
.wp-env.override.json
.phpunit.result.cache
.DS_Store
```

`.distignore` (paths excluded from the release zip; leading `/` anchors to repo root for `rsync --exclude-from`):
```
/.git
/.github
/.gitignore
/.distignore
/.wp-env.json
/.wp-env.override.json
/.phpunit.result.cache
/assets/src
/tests
/docs/superpowers
/node_modules
/dist
/artifacts
/test-results
/playwright-report
/package.json
/package-lock.json
/composer.json
/composer.lock
/phpcs.xml.dist
/phpunit.unit.xml.dist
/phpunit.integration.xml.dist
/playwright.config.js
/CLAUDE.md
/.impeccable
```

- [ ] **Step 6: Create `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="WP Redirects">
	<description>Coding standards for WP Redirects.</description>

	<file>wp-redirects.php</file>
	<file>uninstall.php</file>
	<file>src</file>

	<arg name="extensions" value="php"/>
	<arg name="parallel" value="8"/>
	<arg value="sp"/>

	<config name="testVersion" value="7.4-"/>
	<config name="minimum_wp_version" value="6.6"/>

	<rule ref="PHPCompatibilityWP"/>

	<rule ref="WordPress-Extra">
		<!-- PSR-4 file names. -->
		<exclude name="WordPress.Files.FileName"/>
		<exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
		<exclude name="WordPress.PHP.YodaConditions"/>
		<!-- Custom tables require direct queries; caching is handled by RuleCache. Prepared-SQL sniffs stay on. -->
		<exclude name="WordPress.DB.DirectDatabaseQuery"/>
	</rule>

	<rule ref="WordPress.Security"/>
	<rule ref="WordPress.DB.PreparedSQL"/>
	<rule ref="WordPress.DB.PreparedSQLPlaceholders"/>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="wp-redirects"/>
			</property>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="adv_redirects"/>
				<element value="Advision\Redirects"/>
				<element value="ADV_REDIRECTS"/>
			</property>
		</properties>
	</rule>
</ruleset>
```

- [ ] **Step 7: Create `phpunit.unit.xml.dist` and `tests/unit/bootstrap.php`**

`phpunit.unit.xml.dist`:
```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/unit/bootstrap.php"
	colors="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
	convertDeprecationsToExceptions="true"
>
	<testsuites>
		<testsuite name="unit">
			<directory suffix="Test.php">tests/unit</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`tests/unit/bootstrap.php`:
```php
<?php
/**
 * Unit test bootstrap: no WordPress. Only pure classes are tested here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Advision\Redirects\Autoloader::register( dirname( __DIR__, 2 ) . '/src' );
```

- [ ] **Step 8: Write the failing test `tests/unit/AutoloaderTest.php`**

```php
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
```

- [ ] **Step 9: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist`
Expected: FAIL. `src/Autoloader.php` is missing ("Failed opening required").

- [ ] **Step 10: Create `src/Autoloader.php`**

```php
<?php
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'Advision\\Redirects\\';

	public static function register( string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
```

- [ ] **Step 11: Create the skeleton `src/Plugin.php`** (fully replaced in Task 15)

```php
<?php
/**
 * Plugin service container and hook wiring.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
	}
}
```

- [ ] **Step 12: Create `wp-redirects.php`**

```php
<?php
/**
 * Plugin Name:       WP Redirects
 * Plugin URI:        https://github.com/advision-development/wp-redirects
 * Description:       Exact and regex redirects with selectable status codes, object-cached matching, hit counts, slug-change redirects and a 404 log.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-redirects
 *
 * @package Advision\Redirects
 */

defined( 'ABSPATH' ) || exit;

define( 'ADV_REDIRECTS_VERSION', '0.1.0' );
define( 'ADV_REDIRECTS_FILE', __FILE__ );
define( 'ADV_REDIRECTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADV_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

require_once ADV_REDIRECTS_DIR . 'src/Autoloader.php';
\Advision\Redirects\Autoloader::register( ADV_REDIRECTS_DIR . 'src' );

\Advision\Redirects\Plugin::instance()->boot();
```

- [ ] **Step 13: Create `readme.txt`**

```
=== WP Redirects ===
Contributors: advisiondevelopment
Tags: redirects, 301, 404, regex, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exact and regex redirects with selectable status codes, object-cached matching, hit counts, slug-change redirects and a 404 log.

== Description ==

* Exact path and regex redirects (with $1-$9 capture substitution)
* Status codes 301, 302, 307, 308, 410 and 451
* Compiled rule set stored in the object cache: no database queries on matching requests
* Hit counter and last-hit date per redirect
* Automatic 301 when a published post's permalink changes
* Loop prevention and redirect-chain warnings
* Test-a-URL tool
* 404 log with one-click "create redirect"
* Actions and filters for developers (see docs/hooks.md)

== Changelog ==

= 0.1.0 =
* Initial release.
```

- [ ] **Step 14: Create `CLAUDE.md`**

````markdown
# WP Redirects: agent guide

WordPress plugin: exact + regex redirects, object-cached compiled rule set, hit counts, slug watcher, 404 log, React admin, GitHub-release self-updates.

- Spec: `docs/superpowers/specs/2026-10-02-wp-redirects-design.md` (read §16 amendments)
- Plan: `docs/superpowers/plans/2026-10-02-wp-redirects.md`
- Hook reference: `docs/hooks.md`. **Update it whenever you add or change a hook.**

## Compatibility (hard rules)

- PHP **7.4+**. No `match`, constructor promotion, union types, named args, `mixed`, `?->`, `throw` expressions. Pure classes in `src/Matching/` must not call WordPress functions or PHP 8 polyfills (`str_contains` etc.).
- WordPress **6.6+**. Tested on latest (7.1). JS uses only `@wordpress/components` APIs stable in 6.6, no `__experimental*`.

## Security (no exceptions)

- `defined( 'ABSPATH' ) || exit;` in every PHP file.
- Every REST route: real `permission_callback` via `Permissions::can_manage()`, full arg schema, unknown body fields rejected.
- Every query with input: `$wpdb->prepare()`, table names as `%i`, ORDER BY from an allowlist.
- Redirect targets go through `UrlSafety::is_safe()` and the host guard in `TargetResolver`. Never bypass.
- React renders text only. Never use `dangerouslySetInnerHTML`.
- Never store IPs or user agents.

## Layout

- `src/Matching/`: pure matching core (unit-tested) + `RuleCache`, `Redirector`
- `src/Redirects/`: `Rule`, `Repository` (single write choke point; flushes cache and fires hooks), `Validator`, `ChainResolver`
- `src/Tracking/`: hit counting, 404 log
- `src/Rest/`: REST controllers (`adv-redirects/v1`)
- `src/Admin/AdminPage.php` + `assets/src/`: React admin → `build/` (gitignored)

## Conventions

- Namespace `Advision\Redirects`, PSR-4 under `src/`. Methods/properties `snake_case`. WordPress spacing, tabs, short arrays OK.
- Prefix everything global with `adv_redirects_` / `ADV_REDIRECTS_`.
- All rule writes go through `Repository`. Never write the table elsewhere, or the cache goes stale.

## Commands

```bash
composer install && npm install
npm run build                 # build admin app into build/
npm run start                 # watch mode
composer lint                 # PHPCS (WPCS + PHPCompatibility 7.4+)
npm run test:php:unit         # pure-PHP unit tests (no WP)
npm run env:start             # wp-env (Docker): dev site :8888, tests site :8889
npm run test:php:integration  # WP integration tests inside wp-env
npm run lint:js && npm run test:js
npm run test:e2e              # Playwright against wp-env tests site
```

## Releases

Tag `vX.Y.Z` on `main` after bumping the version in `wp-redirects.php` (header + `ADV_REDIRECTS_VERSION`) and `readme.txt` (`Stable tag`). CI builds assets, runs `composer install --no-dev`, zips `wp-redirects/` as `wp-redirects-X.Y.Z.zip`, and attaches it to a GitHub Release. Sites self-update from that asset via plugin-update-checker.
````

- [ ] **Step 15: Run tests and lint**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist && vendor/bin/phpcs`
Expected: `OK (2 tests, 2 assertions)` and PHPCS reports no errors. If PHPCS reports only whitespace/formatting issues, run `vendor/bin/phpcbf` and re-run.

- [ ] **Step 16: Commit**

```bash
git add -A
git commit -m "chore: scaffold plugin, tooling and CLAUDE.md

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: PathNormalizer (pure)

**Files:**
- Create: `src/Matching/PathNormalizer.php`
- Test: `tests/unit/PathNormalizerTest.php`

**Interfaces:**
- Produces:
  - `new PathNormalizer( string $home_path )`. `$home_path` is `''` for root installs or e.g. `'/blog'`.
  - `->from_request_uri( string $uri ): ?array` returns `[ 'path' => string (decoded, leading '/'), 'key' => string, 'query' => string (raw, no '?') ]`, or `null` when empty, over 2048 chars, outside the home path, or containing control chars after decoding.
  - `PathNormalizer::key( string $path ): string`: lowercase (UTF-8 aware), trailing `/` trimmed, root `/`.
  - `PathNormalizer::source_key( string $source ): string`: key for a stored exact source; `path?query` → `key(decoded path) . '?' . query`.
  - `PathNormalizer::MAX_LENGTH = 2048`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Advision\Redirects\Matching\PathNormalizer;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase {

	public function test_splits_path_and_query(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame(
			[ 'path' => '/Old-Page/', 'key' => '/old-page', 'query' => 'a=1&b=2' ],
			$n->from_request_uri( '/Old-Page/?a=1&b=2' )
		);
	}

	public function test_root_key_stays_slash(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame( '/', $n->from_request_uri( '/' )['key'] );
		$this->assertSame( '/', PathNormalizer::key( '/' ) );
		$this->assertSame( '/', PathNormalizer::key( '' ) );
	}

	public function test_fragment_is_dropped(): void {
		$n = new PathNormalizer( '' );
		$this->assertSame( '/a', $n->from_request_uri( '/a#frag' )['path'] );
	}

	public function test_subdirectory_install_strips_home_path(): void {
		$n = new PathNormalizer( '/blog' );
		$this->assertSame( '/old', $n->from_request_uri( '/blog/old' )['path'] );
		$this->assertSame( '/', $n->from_request_uri( '/blog' )['path'] );
		$this->assertSame( '/', $n->from_request_uri( '/blog/' )['key'] );
		$this->assertNull( $n->from_request_uri( '/blogger/old' ) );
		$this->assertNull( $n->from_request_uri( '/other' ) );
	}

	public function test_unicode_and_encoded_paths_share_a_key(): void {
		$n = new PathNormalizer( '' );
		$expected = PathNormalizer::source_key( '/café' );
		$this->assertSame( $expected, $n->from_request_uri( '/caf%C3%A9' )['key'] );
		$this->assertSame( $expected, $n->from_request_uri( '/CAFÉ' )['key'] );
		$this->assertSame( $expected, $n->from_request_uri( '/café/' )['key'] );
	}

	public function test_rejects_overlong_and_control_characters(): void {
		$n = new PathNormalizer( '' );
		$this->assertNull( $n->from_request_uri( '/' . str_repeat( 'a', 2048 ) ) );
		$this->assertNull( $n->from_request_uri( '/a%0D%0ALocation:%20x' ) );
		$this->assertNull( $n->from_request_uri( '/a%00b' ) );
		$this->assertNull( $n->from_request_uri( '' ) );
	}

	public function test_source_key_with_query(): void {
		$this->assertSame( '/old?x=1', PathNormalizer::source_key( '/Old/?x=1' ) );
		$this->assertSame( '/old', PathNormalizer::source_key( '/OLD/' ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter PathNormalizerTest`
Expected: FAIL, `Class "Advision\Redirects\Matching\PathNormalizer" not found`.

- [ ] **Step 3: Implement `src/Matching/PathNormalizer.php`**

```php
<?php
/**
 * Pure request-path normalization. No WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class PathNormalizer {

	public const MAX_LENGTH = 2048;

	private string $home_path;

	/**
	 * @param string $home_path Path portion of the site's home URL ('' for root installs, e.g. '/blog').
	 */
	public function __construct( string $home_path ) {
		$this->home_path = rtrim( $home_path, '/' );
	}

	/**
	 * @return array{path:string,key:string,query:string}|null
	 */
	public function from_request_uri( string $uri ): ?array {
		if ( '' === $uri || strlen( $uri ) > self::MAX_LENGTH ) {
			return null;
		}

		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}

		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );

		if ( '' !== $this->home_path ) {
			if ( $path === $this->home_path ) {
				$path = '/';
			} elseif ( 0 === strpos( $path, $this->home_path . '/' ) ) {
				$path = substr( $path, strlen( $this->home_path ) );
			} else {
				return null;
			}
		}

		$path = rawurldecode( $path );
		if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return null;
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return [
			'path'  => $path,
			'key'   => self::key( $path ),
			'query' => $query,
		];
	}

	public static function key( string $path ): string {
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		$key = rtrim( $key, '/' );
		return '' === $key ? '/' : $key;
	}

	public static function source_key( string $source ): string {
		$qpos  = strpos( $source, '?' );
		$path  = false === $qpos ? $source : substr( $source, 0, $qpos );
		$query = false === $qpos ? '' : substr( $source, $qpos + 1 );
		$key   = self::key( rawurldecode( $path ) );
		return '' === $query ? $key : $key . '?' . $query;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter PathNormalizerTest`
Expected: `OK (7 tests, …)`

- [ ] **Step 5: Commit**

```bash
git add src/Matching/PathNormalizer.php tests/unit/PathNormalizerTest.php
git commit -m "feat: add pure request path normalizer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: UrlSafety and Pattern (pure)

**Files:**
- Create: `src/Matching/UrlSafety.php`, `src/Matching/Pattern.php`
- Test: `tests/unit/UrlSafetyTest.php`, `tests/unit/PatternTest.php`

**Interfaces:**
- Produces:
  - `UrlSafety::is_safe( string $url ): bool`: true only for a relative path starting with exactly one `/`, or an absolute `http`/`https` URL with a host and no userinfo. False for empty, over 2048 chars, control chars, whitespace or backslash.
  - `UrlSafety::host_of( string $url, string $site_host ): string`: lowercase host; relative paths return `strtolower( $site_host )`; unparsable returns `''`.
  - `Pattern::MAX_LENGTH = 500`
  - `Pattern::delimit( string $source ): string` returns `'~' . escaped . '~i'`. An unescaped `~` becomes `\~`; an already-escaped `\~` stays.
  - `Pattern::is_valid( string $source ): bool`

- [ ] **Step 1: Write the failing tests**

`tests/unit/UrlSafetyTest.php`:
```php
<?php

use Advision\Redirects\Matching\UrlSafety;
use PHPUnit\Framework\TestCase;

final class UrlSafetyTest extends TestCase {

	/** @dataProvider safe_urls */
	public function test_accepts( string $url ): void {
		$this->assertTrue( UrlSafety::is_safe( $url ) );
	}

	public function safe_urls(): array {
		return [
			[ '/' ],
			[ '/new-page/' ],
			[ '/new?x=1&y=2#top' ],
			[ '/caf%C3%A9' ],
			[ '/über' ],
			[ 'https://example.com/a' ],
			[ 'HTTP://EXAMPLE.COM' ],
			[ 'https://other.example.org:8443/x?y=1' ],
		];
	}

	/** @dataProvider unsafe_urls */
	public function test_rejects( string $url ): void {
		$this->assertFalse( UrlSafety::is_safe( $url ) );
	}

	public function unsafe_urls(): array {
		return [
			'empty'               => [ '' ],
			'protocol relative'   => [ '//evil.com' ],
			'backslash trick'     => [ '/\\evil.com' ],
			'backslash scheme'    => [ '\\\\evil.com' ],
			'javascript'          => [ 'javascript:alert(1)' ],
			'data'                => [ 'data:text/html,hi' ],
			'vbscript'            => [ 'vbscript:x' ],
			'file'                => [ 'file:///etc/passwd' ],
			'ftp'                 => [ 'ftp://example.com' ],
			'crlf'                => [ "/a\r\nLocation: https://evil.com" ],
			'tab'                 => [ "/a\tb" ],
			'space'               => [ '/a b' ],
			'nul'                 => [ "/a\0" ],
			'no slash relative'   => [ 'new-page' ],
			'userinfo'            => [ 'https://good.com@evil.com/' ],
			'scheme without host' => [ 'https:/evil.com' ],
			'overlong'            => [ '/' . str_repeat( 'a', 2048 ) ],
		];
	}

	public function test_host_of(): void {
		$this->assertSame( 'example.com', UrlSafety::host_of( '/x', 'Example.com' ) );
		$this->assertSame( 'other.org', UrlSafety::host_of( 'https://OTHER.org/x', 'example.com' ) );
		$this->assertSame( '', UrlSafety::host_of( 'https://', 'example.com' ) );
	}
}
```

`tests/unit/PatternTest.php`:
```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter 'UrlSafetyTest|PatternTest'`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Matching/UrlSafety.php`**

```php
<?php
/**
 * Pure redirect-target safety checks. No WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class UrlSafety {

	public const MAX_LENGTH = 2048;

	public static function is_safe( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH ) {
			return false;
		}
		// Control characters, whitespace and backslashes are never allowed.
		if ( preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return false;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return false;
		}
		if ( '/' === $url[0] ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class; no WordPress dependency.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		// Scheme must be followed by "//" (rejects "https:/evil.com").
		return 0 === stripos( $url, $parts['scheme'] . '://' );
	}

	public static function host_of( string $url, string $site_host ): string {
		if ( '' !== $url && '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
			return strtolower( $site_host );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class; no WordPress dependency.
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
```

- [ ] **Step 4: Implement `src/Matching/Pattern.php`**

```php
<?php
/**
 * Regex source handling. Delimiters and flags are always controlled here,
 * so user input can never add modifiers.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class Pattern {

	public const MAX_LENGTH = 500;

	public static function delimit( string $source ): string {
		// Escape every "~" not already preceded by an odd number of backslashes.
		$escaped = preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $source );
		return '~' . $escaped . '~i';
	}

	public static function is_valid( string $source ): bool {
		if ( '' === $source || strlen( $source ) > self::MAX_LENGTH ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile check; invalid patterns emit warnings by design.
		return false !== @preg_match( self::delimit( $source ), '' );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter 'UrlSafetyTest|PatternTest'`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add src/Matching/UrlSafety.php src/Matching/Pattern.php tests/unit/UrlSafetyTest.php tests/unit/PatternTest.php
git commit -m "feat: add target URL safety checks and regex delimiting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: MatchResult and TargetResolver (pure)

**Files:**
- Create: `src/Matching/MatchResult.php`, `src/Matching/TargetResolver.php`
- Test: `tests/unit/TargetResolverTest.php`

**Interfaces:**
- Consumes: `UrlSafety::is_safe()`, `UrlSafety::host_of()`
- Produces:
  - `new MatchResult( int $rule_id, string $type, int $status, ?string $target, array $captures = [] )` with public properties `rule_id`, `type`, `status`, `target`, `captures`.
  - `new TargetResolver( string $home_url, array $allowed_hosts = [] )`. `$home_url` has no trailing slash, e.g. `https://example.com/blog`.
  - `->resolve( string $template, array $captures, string $request_query, bool $forward_query ): ?string`. Returns an absolute URL, or `null` if unsafe, host-changed, or blocked by the allowlist.
  - `TargetResolver::substitute( string $template, array $captures, bool $encode ): string`
  - `TargetResolver::merge_query( string $url, string $request_query ): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

use Advision\Redirects\Matching\TargetResolver;
use PHPUnit\Framework\TestCase;

final class TargetResolverTest extends TestCase {

	private function resolver( array $allowed = [] ): TargetResolver {
		return new TargetResolver( 'https://example.com', $allowed );
	}

	public function test_relative_target_becomes_absolute(): void {
		$this->assertSame( 'https://example.com/new', $this->resolver()->resolve( '/new', [], '', false ) );
	}

	public function test_relative_target_resolves_against_subdirectory_home(): void {
		$r = new TargetResolver( 'https://example.com/blog' );
		$this->assertSame( 'https://example.com/blog/new', $r->resolve( '/new', [], '', false ) );
	}

	public function test_absolute_external_target_kept(): void {
		$this->assertSame( 'https://other.org/x', $this->resolver()->resolve( 'https://other.org/x', [], '', false ) );
	}

	public function test_capture_substitution_encodes_segments(): void {
		$captures = [ '/old/a b/c', 'a b/c' ];
		$this->assertSame(
			'https://example.com/new/a%20b/c',
			$this->resolver()->resolve( '/new/$1', $captures, '', false )
		);
	}

	public function test_missing_capture_becomes_empty(): void {
		$this->assertSame( 'https://example.com/new/', $this->resolver()->resolve( '/new/$2', [ '/x', 'y' ], '', false ) );
	}

	public function test_capture_cannot_change_host(): void {
		// Rule "^/go/(.*)$" → "/$1" hit with "/go//evil.com".
		$this->assertNull( $this->resolver()->resolve( '/$1', [ '/go//evil.com', '/evil.com' ], '', false ) );
		// Backslashes are percent-encoded, so the result stays on the site's own host.
		$this->assertSame(
			'https://example.com/%5Cevil.com',
			$this->resolver()->resolve( '/$1', [ '/go/\\evil.com', '\\evil.com' ], '', false )
		);
	}

	public function test_capture_in_external_target_cannot_change_host(): void {
		$this->assertNull(
			$this->resolver()->resolve( 'https://other.org$1', [ '/x@evil.com', '@evil.com' ], '', false )
		);
	}

	public function test_query_forwarding_merges_with_target_winning(): void {
		$this->assertSame(
			'https://example.com/new?a=target&b=2',
			$this->resolver()->resolve( '/new?a=target', [], 'a=incoming&b=2', true )
		);
	}

	public function test_query_not_forwarded_when_disabled(): void {
		$this->assertSame( 'https://example.com/new', $this->resolver()->resolve( '/new', [], 'a=1', false ) );
	}

	public function test_merge_query_keeps_fragment_last(): void {
		$this->assertSame( 'https://example.com/new?utm=x#top', $this->resolver()->resolve( '/new#top', [], 'utm=x', true ) );
	}

	public function test_allowlist_blocks_unknown_external_hosts(): void {
		$r = $this->resolver( [ 'partner.com' ] );
		$this->assertNull( $r->resolve( 'https://other.org/', [], '', false ) );
		$this->assertSame( 'https://partner.com/', $r->resolve( 'https://partner.com/', [], '', false ) );
		$this->assertSame( 'https://example.com/local', $r->resolve( '/local', [], '', false ) );
	}

	public function test_unsafe_template_rejected(): void {
		$this->assertNull( $this->resolver()->resolve( 'javascript:alert(1)', [], '', false ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter TargetResolverTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Matching/MatchResult.php`**

```php
<?php
/**
 * Result of matching a request against the compiled rule set.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class MatchResult {

	public int $rule_id;

	public string $type;

	public int $status;

	public ?string $target;

	/** @var array<int,string> preg captures; index 0 is the full match. */
	public array $captures;

	public function __construct( int $rule_id, string $type, int $status, ?string $target, array $captures = [] ) {
		$this->rule_id  = $rule_id;
		$this->type     = $type;
		$this->status   = $status;
		$this->target   = $target;
		$this->captures = $captures;
	}
}
```

- [ ] **Step 4: Implement `src/Matching/TargetResolver.php`**

```php
<?php
/**
 * Builds the final redirect URL: capture substitution, relative resolution,
 * query forwarding, and the host guard. Pure; no WordPress calls.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class TargetResolver {

	private string $home_url;

	private string $site_host;

	/** @var string[] */
	private array $allowed_hosts;

	/**
	 * @param string   $home_url      Site home URL without trailing slash.
	 * @param string[] $allowed_hosts Optional external host allowlist (empty = any host).
	 */
	public function __construct( string $home_url, array $allowed_hosts = [] ) {
		$this->home_url      = rtrim( $home_url, '/' );
		$this->site_host     = UrlSafety::host_of( $this->home_url, '' );
		$this->allowed_hosts = array_map( 'strtolower', $allowed_hosts );
	}

	public function resolve( string $template, array $captures, string $request_query, bool $forward_query ): ?string {
		$url = self::substitute( $template, $captures, true );

		if ( ! UrlSafety::is_safe( $url ) ) {
			return null;
		}

		$host = UrlSafety::host_of( $url, $this->site_host );
		if ( '' === $host || UrlSafety::host_of( $template, $this->site_host ) !== $host ) {
			return null;
		}
		if ( $host !== $this->site_host && ! empty( $this->allowed_hosts ) && ! in_array( $host, $this->allowed_hosts, true ) ) {
			return null;
		}

		if ( '/' === $url[0] ) {
			$url = $this->home_url . $url;
		}
		if ( $forward_query && '' !== $request_query ) {
			$url = self::merge_query( $url, $request_query );
		}
		return $url;
	}

	public static function substitute( string $template, array $captures, bool $encode ): string {
		if ( empty( $captures ) || false === strpos( $template, '$' ) ) {
			return $template;
		}
		return (string) preg_replace_callback(
			'/\$([1-9])/',
			static function ( array $m ) use ( $captures, $encode ): string {
				$value = isset( $captures[ (int) $m[1] ] ) ? (string) $captures[ (int) $m[1] ] : '';
				return $encode ? str_replace( '%2F', '/', rawurlencode( $value ) ) : $value;
			},
			$template
		);
	}

	public static function merge_query( string $url, string $request_query ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$qpos         = strpos( $url, '?' );
		$base         = false === $qpos ? $url : substr( $url, 0, $qpos );
		$target_query = false === $qpos ? '' : substr( $url, $qpos + 1 );

		parse_str( $request_query, $incoming );
		parse_str( $target_query, $existing );
		$query = http_build_query( array_replace( $incoming, $existing ), '', '&', PHP_QUERY_RFC3986 );

		return $base . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}
}
```

Why the guard holds: captures are `rawurlencode`d with only `/` restored, so a backslash capture becomes `%5C`, which is a harmless same-host path. A `/` capture can still produce `//evil.com`, and `UrlSafety::is_safe()` rejects that. Any host that differs from the template's host is rejected.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter TargetResolverTest`
Expected: `OK (12 tests, …)`

- [ ] **Step 6: Commit**

```bash
git add src/Matching/MatchResult.php src/Matching/TargetResolver.php tests/unit/TargetResolverTest.php
git commit -m "feat: add target resolver with capture encoding and host guard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: RulesetCompiler and Matcher (pure)

**Files:**
- Create: `src/Matching/RulesetCompiler.php`, `src/Matching/Matcher.php`
- Test: `tests/unit/MatcherTest.php`

**Interfaces:**
- Consumes: `PathNormalizer::source_key()`, `Pattern::delimit()`, `MatchResult`
- Produces:
  - `RulesetCompiler::compile( array $rows ): array`. Each row is an assoc array with `id, type, source, target, status_code, position` and optional `enabled` (missing = enabled; `0`/`'0'`/`false` = skipped). Returns `[ 'exact' => [ key => ['id'=>int,'target'=>?string,'status'=>int] ], 'has_query' => bool, 'regex' => [ ['id'=>int,'pattern'=>string,'target'=>?string,'status'=>int], … ] ]`, with regex sorted by `position`, then `id`. On duplicate exact keys, the lowest id wins.
  - `RulesetCompiler::empty_ruleset(): array`
  - `new Matcher( array $ruleset, ?callable $on_regex_error = null )`. The callback receives `( int $rule_id, int $preg_error_code )`.
  - `->match( array $request ): ?MatchResult`. `$request` is the `PathNormalizer::from_request_uri()` shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RulesetCompiler;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	private function row( int $id, string $type, string $source, ?string $target, int $status = 301, int $position = 0, int $enabled = 1 ): array {
		return [
			'id'          => (string) $id,
			'type'        => $type,
			'source'      => $source,
			'target'      => $target,
			'status_code' => (string) $status,
			'position'    => (string) $position,
			'enabled'     => (string) $enabled,
		];
	}

	private function req( string $uri ): array {
		return ( new PathNormalizer( '' ) )->from_request_uri( $uri );
	}

	public function test_compile_shapes_ruleset(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'exact', '/Old/', '/new' ),
				$this->row( 2, 'exact', '/q?x=1', '/q-new' ),
				$this->row( 3, 'regex', '^/b/(\d+)$', '/p/$1', 302, 2 ),
				$this->row( 4, 'regex', '^/b/.*$', '/b', 301, 1 ),
				$this->row( 5, 'exact', '/off', '/x', 301, 0, 0 ),
				$this->row( 6, 'exact', '/gone', null, 410 ),
			]
		);
		$this->assertSame( [ 'id' => 1, 'target' => '/new', 'status' => 301 ], $rs['exact']['/old'] );
		$this->assertArrayHasKey( '/q?x=1', $rs['exact'] );
		$this->assertArrayNotHasKey( '/off', $rs['exact'] );
		$this->assertNull( $rs['exact']['/gone']['target'] );
		$this->assertTrue( $rs['has_query'] );
		$this->assertSame( [ 4, 3 ], array_column( $rs['regex'], 'id' ) );
		$this->assertSame( '~^/b/.*$~i', $rs['regex'][0]['pattern'] );
	}

	public function test_duplicate_exact_keys_lowest_id_wins(): void {
		$rs = RulesetCompiler::compile( [ $this->row( 9, 'exact', '/a/', '/nine' ), $this->row( 2, 'exact', '/A', '/two' ) ] );
		$this->assertSame( 2, $rs['exact']['/a']['id'] );
	}

	public function test_exact_match_is_case_and_slash_insensitive(): void {
		$m = ( new Matcher( RulesetCompiler::compile( [ $this->row( 1, 'exact', '/old-page', '/new' ) ] ) ) )->match( $this->req( '/OLD-PAGE/' ) );
		$this->assertSame( 1, $m->rule_id );
		$this->assertSame( 'exact', $m->type );
		$this->assertSame( '/new', $m->target );
	}

	public function test_query_specific_exact_rule_takes_precedence(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'exact', '/p', '/plain' ),
				$this->row( 2, 'exact', '/p?lang=fr', '/fr' ),
			]
		);
		$matcher = new Matcher( $rs );
		$this->assertSame( 2, $matcher->match( $this->req( '/p?lang=fr' ) )->rule_id );
		$this->assertSame( 1, $matcher->match( $this->req( '/p?lang=de' ) )->rule_id );
		$this->assertSame( 1, $matcher->match( $this->req( '/p' ) )->rule_id );
	}

	public function test_exact_beats_regex_and_regex_follows_position(): void {
		$rs = RulesetCompiler::compile(
			[
				$this->row( 1, 'regex', '^/blog/(.*)$', '/news/$1', 301, 2 ),
				$this->row( 2, 'regex', '^/blog/special$', '/special', 302, 1 ),
				$this->row( 3, 'exact', '/blog/exact', '/e' ),
			]
		);
		$matcher = new Matcher( $rs );
		$this->assertSame( 3, $matcher->match( $this->req( '/blog/exact' ) )->rule_id );
		$this->assertSame( 2, $matcher->match( $this->req( '/blog/special' ) )->rule_id );
		$hit = $matcher->match( $this->req( '/blog/hello' ) );
		$this->assertSame( 1, $hit->rule_id );
		$this->assertSame( 'hello', $hit->captures[1] );
	}

	public function test_no_match_returns_null(): void {
		$this->assertNull( ( new Matcher( RulesetCompiler::empty_ruleset() ) )->match( $this->req( '/x' ) ) );
	}

	public function test_failing_regex_is_skipped_and_reported(): void {
		$errors = [];
		$rs     = RulesetCompiler::compile(
			[
				$this->row( 1, 'regex', '(?:a+)+$', '/never', 301, 1 ),
				$this->row( 2, 'regex', '^/a', '/ok', 301, 2 ),
			]
		);
		$previous_jit = ini_set( 'pcre.jit', '0' );
		$previous     = ini_set( 'pcre.backtrack_limit', '10' );
		$matcher  = new Matcher(
			$rs,
			static function ( int $id, int $code ) use ( &$errors ): void {
				$errors[] = [ $id, $code ];
			}
		);
		$result = $matcher->match( $this->req( '/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab' ) );
		ini_set( 'pcre.backtrack_limit', (string) $previous );
		ini_set( 'pcre.jit', (string) $previous_jit );

		$this->assertSame( 2, $result->rule_id );
		$this->assertSame( 1, $errors[0][0] );
		$this->assertNotSame( PREG_NO_ERROR, $errors[0][1] );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist --filter MatcherTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Matching/RulesetCompiler.php`**

```php
<?php
/**
 * Compiles database rows into the cached lookup structure. Pure.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class RulesetCompiler {

	public static function empty_ruleset(): array {
		return [
			'exact'     => [],
			'has_query' => false,
			'regex'     => [],
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rule rows.
	 */
	public static function compile( array $rows ): array {
		$ruleset = self::empty_ruleset();

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return [ (int) $a['position'], (int) $a['id'] ] <=> [ (int) $b['position'], (int) $b['id'] ];
			}
		);

		$exact_ids = [];
		foreach ( $rows as $row ) {
			if ( array_key_exists( 'enabled', $row ) && ! (int) $row['enabled'] ) {
				continue;
			}
			$entry = [
				'id'     => (int) $row['id'],
				'target' => null === $row['target'] || '' === $row['target'] ? null : (string) $row['target'],
				'status' => (int) $row['status_code'],
			];

			if ( 'regex' === $row['type'] ) {
				$ruleset['regex'][] = [ 'id' => $entry['id'], 'pattern' => Pattern::delimit( (string) $row['source'] ) ] + $entry;
				continue;
			}

			$key = PathNormalizer::source_key( (string) $row['source'] );
			if ( isset( $exact_ids[ $key ] ) && $exact_ids[ $key ] < $entry['id'] ) {
				continue;
			}
			$exact_ids[ $key ]          = $entry['id'];
			$ruleset['exact'][ $key ] = $entry;
			if ( false !== strpos( $key, '?' ) ) {
				$ruleset['has_query'] = true;
			}
		}

		return $ruleset;
	}
}
```

Note: `[ 'id' => …, 'pattern' => … ] + $entry` keeps key order `id, pattern, target, status`.

- [ ] **Step 4: Implement `src/Matching/Matcher.php`**

```php
<?php
/**
 * Matches a normalized request against a compiled rule set. Pure.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

defined( 'ABSPATH' ) || exit;

final class Matcher {

	private array $ruleset;

	/** @var callable|null */
	private $on_regex_error;

	public function __construct( array $ruleset, ?callable $on_regex_error = null ) {
		$this->ruleset        = $ruleset + RulesetCompiler::empty_ruleset();
		$this->on_regex_error = $on_regex_error;
	}

	/**
	 * @param array{path:string,key:string,query:string} $request
	 */
	public function match( array $request ): ?MatchResult {
		$exact = $this->ruleset['exact'];

		if ( $this->ruleset['has_query'] && '' !== $request['query'] ) {
			$with_query = $request['key'] . '?' . $request['query'];
			if ( isset( $exact[ $with_query ] ) ) {
				return $this->exact_result( $exact[ $with_query ] );
			}
		}
		if ( isset( $exact[ $request['key'] ] ) ) {
			return $this->exact_result( $exact[ $request['key'] ] );
		}

		$subject = substr( $request['path'], 0, PathNormalizer::MAX_LENGTH );
		foreach ( $this->ruleset['regex'] as $rule ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Runtime PCRE failures are handled via preg_last_error().
			$result = @preg_match( $rule['pattern'], $subject, $captures );
			if ( false === $result || PREG_NO_ERROR !== preg_last_error() ) {
				if ( null !== $this->on_regex_error ) {
					call_user_func( $this->on_regex_error, (int) $rule['id'], preg_last_error() );
				}
				continue;
			}
			if ( 1 === $result ) {
				return new MatchResult( (int) $rule['id'], 'regex', (int) $rule['status'], $rule['target'], $captures );
			}
		}

		return null;
	}

	private function exact_result( array $entry ): MatchResult {
		return new MatchResult( (int) $entry['id'], 'exact', (int) $entry['status'], $entry['target'] );
	}
}
```

- [ ] **Step 5: Run the full unit suite**

Run: `vendor/bin/phpunit -c phpunit.unit.xml.dist`
Expected: `OK`. All unit tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Matching/RulesetCompiler.php src/Matching/Matcher.php tests/unit/MatcherTest.php
git commit -m "feat: add ruleset compiler and matcher

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 6: wp-env harness, Site, Schema, Settings

**Files:**
- Create: `.wp-env.json`, `phpunit.integration.xml.dist`, `tests/integration/bootstrap.php`, `src/Site.php`, `src/Schema.php`, `src/Settings.php`
- Modify: `package.json` (`env:start` script)
- Test: `tests/integration/SiteTest.php`, `tests/integration/SchemaTest.php`, `tests/integration/SettingsTest.php`

**Interfaces:**
- Consumes: `PathNormalizer`, `TargetResolver`
- Produces:
  - `Site::home_url(): string` (no trailing slash), `Site::host(): string` (lowercase), `Site::home_path(): string` (`''` or `/blog`)
  - `Site::normalizer(): PathNormalizer`, `Site::resolver(): TargetResolver` (applies `adv_redirects_allowed_target_hosts`)
  - `Site::internal_path( string $url ): ?string`: home-relative `path[?query]` for relative or same-host URLs, `null` for external/invalid
  - `Site::reserved_prefixes(): string[]`, `Site::is_reserved_path( string $path ): bool`
  - `Schema::VERSION = '1'`, `Schema::VERSION_OPTION = 'adv_redirects_db_version'`, `Schema::redirects_table(): string`, `Schema::not_found_table(): string`, `Schema::install(): void`, `Schema::maybe_upgrade(): void`, `Schema::drop_all(): void`
  - `Settings::OPTION`, `Settings::defaults(): array`, `Settings::all(): array`, `Settings::get( string $key )`, `Settings::sanitize( array $input ): array`, `Settings::update( array $input ): array`

- [ ] **Step 1: Create `.wp-env.json`**

```json
{
	"$schema": "https://schemas.wp.org/trunk/wp-env.json",
	"core": null,
	"phpVersion": "8.2",
	"plugins": [ "." ],
	"config": {
		"WP_DEBUG": true,
		"WP_DEBUG_LOG": true
	}
}
```

Change the `env:start` script in `package.json` so the tests site uses pretty permalinks:

```json
"env:start": "wp-env start && wp-env run tests-cli wp rewrite structure '/%postname%/'",
```

- [ ] **Step 2: Create `phpunit.integration.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/integration/bootstrap.php"
	colors="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
>
	<testsuites>
		<testsuite name="integration">
			<directory suffix="Test.php">tests/integration</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/integration/bootstrap.php`**

```php
<?php
/**
 * Integration bootstrap. Runs inside wp-env's tests-cli container.
 */

$adv_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

if ( ! file_exists( $adv_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$adv_tests_dir}. Run: npm run test:php:integration\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $adv_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/wp-redirects.php';
	}
);

require $adv_tests_dir . '/includes/bootstrap.php';

\Advision\Redirects\Schema::install();

foreach ( glob( __DIR__ . '/support/*.php' ) as $adv_support_file ) {
	require_once $adv_support_file;
}
```

- [ ] **Step 4: Start wp-env and confirm the test runner works**

Run: `npm run env:start`
Expected: the dev site is at http://localhost:8888 and the tests site at http://localhost:8889. Docker must be running.

- [ ] **Step 5: Write the failing tests**

`tests/integration/SiteTest.php`:
```php
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
```

`tests/integration/SchemaTest.php`:
```php
<?php

use Advision\Redirects\Schema;

final class SchemaTest extends WP_UnitTestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'adv_redirects', Schema::redirects_table() );
		$this->assertSame( $wpdb->prefix . 'adv_redirects_404s', Schema::not_found_table() );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::redirects_table() ) );
		$this->assertSame(
			[ 'id', 'type', 'source', 'target', 'status_code', 'position', 'enabled', 'origin', 'note', 'hits', 'last_hit_at', 'created_at', 'updated_at' ],
			$columns
		);

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::not_found_table() ) );
		$this->assertSame( [ 'id', 'path', 'path_hash', 'hits', 'first_seen', 'last_seen', 'last_referrer' ], $columns );
	}

	public function test_install_records_version(): void {
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_reinstalls_when_version_differs(): void {
		update_option( Schema::VERSION_OPTION, '0' );
		Schema::maybe_upgrade();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}
}
```

`tests/integration/SettingsTest.php`:
```php
<?php

use Advision\Redirects\Settings;

final class SettingsTest extends WP_UnitTestCase {

	public function test_defaults(): void {
		$all = Settings::all();
		$this->assertTrue( $all['slug_watcher'] );
		$this->assertTrue( $all['log_404'] );
		$this->assertSame( 30, $all['log_404_retention_days'] );
		$this->assertSame( 5000, $all['log_404_max_rows'] );
		$this->assertTrue( $all['forward_query_string'] );
		$this->assertFalse( $all['remove_data_on_uninstall'] );
		$this->assertContains( 'css', $all['excluded_404_extensions'] );
	}

	public function test_sanitize_clamps_and_parses(): void {
		$clean = Settings::sanitize(
			[
				'slug_watcher'            => 'false',
				'log_404_retention_days'  => 9999,
				'log_404_max_rows'        => 5,
				'excluded_404_extensions' => ' .CSS, js,,bad ext!, png ',
				'unknown'                 => 'x',
			]
		);
		$this->assertFalse( $clean['slug_watcher'] );
		$this->assertSame( 365, $clean['log_404_retention_days'] );
		$this->assertSame( 100, $clean['log_404_max_rows'] );
		$this->assertSame( [ 'css', 'js', 'png' ], $clean['excluded_404_extensions'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
	}

	public function test_update_merges_and_persists(): void {
		Settings::update( [ 'log_404' => false ] );
		$this->assertFalse( Settings::get( 'log_404' ) );
		$this->assertTrue( Settings::get( 'slug_watcher' ) );
		$this->assertSame( 30, Settings::get( 'log_404_retention_days' ) );
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		update_option( Settings::OPTION, 'garbage' );
		$this->assertSame( Settings::defaults(), Settings::all() );
	}
}
```

- [ ] **Step 6: Run them to verify they fail**

Run: `npm run test:php:integration`
Expected: FAIL. The bootstrap fatals with `Class "Advision\Redirects\Schema" not found`.

- [ ] **Step 7: Implement `src/Site.php`**

```php
<?php
/**
 * Site-level helpers (home URL, host, reserved paths) used by the WordPress layer.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\TargetResolver;

defined( 'ABSPATH' ) || exit;

final class Site {

	public static function home_url(): string {
		return untrailingslashit( home_url() );
	}

	public static function host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	public static function home_path(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? rtrim( $path, '/' ) : '';
	}

	public static function normalizer(): PathNormalizer {
		return new PathNormalizer( self::home_path() );
	}

	public static function resolver(): TargetResolver {
		/**
		 * Filters the allowlist of external hosts redirects may point to.
		 * An empty array (default) allows any external host.
		 *
		 * @param string[] $hosts Lowercase host names.
		 */
		$hosts = (array) apply_filters( 'adv_redirects_allowed_target_hosts', [] );
		$hosts = array_values( array_filter( array_map( 'strval', $hosts ) ) );
		return new TargetResolver( self::home_url(), $hosts );
	}

	public static function internal_path( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}
		if ( '/' === $url[0] ) {
			return 0 === strpos( $url, '//' ) ? null : $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== self::host() ) {
			return null;
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$home = self::home_path();
		if ( '' !== $home ) {
			if ( $path === $home ) {
				$path = '/';
			} elseif ( 0 === strpos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			} else {
				return null;
			}
		}

		return $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/**
	 * @return string[]
	 */
	public static function reserved_prefixes(): array {
		return [ '/wp-admin', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/' . trim( rest_get_url_prefix(), '/' ) ];
	}

	public static function is_reserved_path( string $path ): bool {
		$key = PathNormalizer::key( $path );
		foreach ( self::reserved_prefixes() as $prefix ) {
			if ( $key === $prefix || 0 === strpos( $key, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
```

- [ ] **Step 8: Implement `src/Schema.php`**

```php
<?php
/**
 * Custom table installation and upgrades.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION = '1';

	public const VERSION_OPTION = 'adv_redirects_db_version';

	public static function redirects_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'adv_redirects';
	}

	public static function not_found_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'adv_redirects_404s';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$redirects = self::redirects_table();
		$not_found = self::not_found_table();

		dbDelta(
			"CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(10) NOT NULL DEFAULT 'exact',
  source varchar(2048) NOT NULL,
  target varchar(2048) DEFAULT NULL,
  status_code smallint(5) unsigned NOT NULL DEFAULT 301,
  position int(10) unsigned NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  origin varchar(10) NOT NULL DEFAULT 'manual',
  note varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_enabled (type,enabled)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$not_found} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path varchar(2048) NOT NULL,
  path_hash char(32) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  last_referrer varchar(2048) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY last_seen (last_seen)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	public static function drop_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::redirects_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::not_found_table() ) );
	}
}
```

- [ ] **Step 9: Implement `src/Settings.php`**

```php
<?php
/**
 * Typed access to the plugin settings option.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'adv_redirects_settings';

	private const BOOLEANS = [ 'slug_watcher', 'log_404', 'forward_query_string', 'remove_data_on_uninstall' ];

	public static function defaults(): array {
		return [
			'slug_watcher'             => true,
			'log_404'                  => true,
			'log_404_retention_days'   => 30,
			'log_404_max_rows'         => 5000,
			'forward_query_string'     => true,
			'excluded_404_extensions'  => [ 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot' ],
			'remove_data_on_uninstall' => false,
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return self::sanitize( array_merge( self::defaults(), is_array( $saved ) ? $saved : [] ) );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$clean    = [];

		foreach ( self::BOOLEANS as $key ) {
			$clean[ $key ] = array_key_exists( $key, $input ) ? rest_sanitize_boolean( $input[ $key ] ) : $defaults[ $key ];
		}

		$clean['log_404_retention_days'] = self::clamp( $input['log_404_retention_days'] ?? $defaults['log_404_retention_days'], 1, 365 );
		$clean['log_404_max_rows']       = self::clamp( $input['log_404_max_rows'] ?? $defaults['log_404_max_rows'], 100, 100000 );

		$extensions = $input['excluded_404_extensions'] ?? $defaults['excluded_404_extensions'];
		if ( is_string( $extensions ) ) {
			$extensions = explode( ',', $extensions );
		}
		$extensions = array_map(
			static function ( $ext ): string {
				return strtolower( ltrim( trim( (string) $ext ), '.' ) );
			},
			(array) $extensions
		);
		$extensions = array_filter(
			$extensions,
			static function ( string $ext ): bool {
				return 1 === preg_match( '/^[a-z0-9]{1,10}$/', $ext );
			}
		);
		$clean['excluded_404_extensions'] = array_slice( array_values( array_unique( $extensions ) ), 0, 50 );

		// Keep a stable key order matching defaults().
		return array_merge( $defaults, $clean );
	}

	public static function update( array $input ): array {
		$clean = self::sanitize( array_merge( self::all(), array_intersect_key( $input, self::defaults() ) ) );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}
}
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `npm run test:php:integration`
Expected: `OK`. All Site, Schema and Settings tests pass.

- [ ] **Step 11: Lint and commit**

Run: `vendor/bin/phpcs` (fix whitespace with `vendor/bin/phpcbf`)

```bash
git add .wp-env.json phpunit.integration.xml.dist package.json tests/integration src/Site.php src/Schema.php src/Settings.php
git commit -m "feat: add schema, settings, site helpers and wp-env test harness

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Rule, Repository, RuleCache

**Files:**
- Create: `src/Redirects/Rule.php`, `src/Redirects/Repository.php`, `src/Matching/RuleCache.php`
- Test: `tests/integration/RepositoryTest.php`

**Interfaces:**
- Consumes: `Schema::redirects_table()`, `Site::internal_path()`, `PathNormalizer::source_key()`, `RulesetCompiler`
- Produces:
  - `Rule`, with public props `id:int, type:string, source:string, target:?string, status_code:int, position:int, enabled:bool, origin:string, note:string, hits:int, last_hit_at:?string, created_at:string, updated_at:string`. Methods: `Rule::from_row( array $row ): Rule`, `->to_array(): array` (same keys).
  - `Repository` methods:
    - `all(): Rule[]` (ordered type, position, id)
    - `find( int $id ): ?Rule`
    - `enabled_rows(): array`
    - `ids(): int[]`
    - `exact_rule_by_key( string $key, int $exclude_id = 0 ): ?Rule`
    - `insert( array $data ): ?Rule`
    - `update( int $id, array $data ): ?Rule`
    - `delete( int $id ): bool`
    - `reorder( array $ids ): void`
    - `retarget( string $from_path, string $to_path ): int`
    - `disable_by_source_key( string $key ): int`
    - `add_hits( int $id, int $count, string $last_hit_gmt ): void`
  - Every insert/update/delete/reorder calls `RuleCache::flush()`. Insert fires `adv_redirects_rule_created` (Rule); update fires `adv_redirects_rule_updated` (Rule new, Rule old); delete fires `adv_redirects_rule_deleted` (Rule).
  - `RuleCache::GROUP = 'adv_redirects'`, `RuleCache::KEY = 'ruleset'`, `new RuleCache( Repository )`, `->get(): array`, `RuleCache::flush(): void` (fires `adv_redirects_cache_flushed`).

- [ ] **Step 1: Write the failing test `tests/integration/RepositoryTest.php`**

```php
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
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter RepositoryTest`
Expected: FAIL, `Class "Advision\Redirects\Redirects\Repository" not found`.

- [ ] **Step 3: Implement `src/Redirects/Rule.php`**

```php
<?php
/**
 * Redirect rule value object.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

defined( 'ABSPATH' ) || exit;

final class Rule {

	public int $id = 0;
	public string $type = 'exact';
	public string $source = '';
	public ?string $target = null;
	public int $status_code = 301;
	public int $position = 0;
	public bool $enabled = true;
	public string $origin = 'manual';
	public string $note = '';
	public int $hits = 0;
	public ?string $last_hit_at = null;
	public string $created_at = '';
	public string $updated_at = '';

	public static function from_row( array $row ): Rule {
		$rule              = new self();
		$rule->id          = (int) $row['id'];
		$rule->type        = (string) $row['type'];
		$rule->source      = (string) $row['source'];
		$rule->target      = null === $row['target'] || '' === $row['target'] ? null : (string) $row['target'];
		$rule->status_code = (int) $row['status_code'];
		$rule->position    = (int) $row['position'];
		$rule->enabled     = (bool) (int) $row['enabled'];
		$rule->origin      = (string) $row['origin'];
		$rule->note        = (string) $row['note'];
		$rule->hits        = (int) $row['hits'];
		$rule->last_hit_at = empty( $row['last_hit_at'] ) ? null : (string) $row['last_hit_at'];
		$rule->created_at  = (string) $row['created_at'];
		$rule->updated_at  = (string) $row['updated_at'];
		return $rule;
	}

	public function to_array(): array {
		return [
			'id'          => $this->id,
			'type'        => $this->type,
			'source'      => $this->source,
			'target'      => $this->target,
			'status_code' => $this->status_code,
			'position'    => $this->position,
			'enabled'     => $this->enabled,
			'origin'      => $this->origin,
			'note'        => $this->note,
			'hits'        => $this->hits,
			'last_hit_at' => $this->last_hit_at,
			'created_at'  => $this->created_at,
			'updated_at'  => $this->updated_at,
		];
	}
}
```

- [ ] **Step 4: Implement `src/Matching/RuleCache.php`**

```php
<?php
/**
 * Object-cached compiled rule set.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

use Advision\Redirects\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

final class RuleCache {

	public const GROUP = 'adv_redirects';

	public const KEY = 'ruleset';

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function get(): array {
		$found  = false;
		$cached = wp_cache_get( self::KEY, self::GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$ruleset = RulesetCompiler::compile( $this->repository->enabled_rows() );

		/**
		 * Filters the compiled rule set before it is cached.
		 *
		 * @param array $ruleset { exact: array, has_query: bool, regex: array }.
		 */
		$ruleset = apply_filters( 'adv_redirects_compiled_ruleset', $ruleset );
		if ( ! is_array( $ruleset ) ) {
			$ruleset = RulesetCompiler::empty_ruleset();
		}

		wp_cache_set( self::KEY, $ruleset, self::GROUP );
		return $ruleset;
	}

	public static function flush(): void {
		wp_cache_delete( self::KEY, self::GROUP );

		/**
		 * Fires after the compiled rule set cache is cleared.
		 */
		do_action( 'adv_redirects_cache_flushed' );
	}
}
```

- [ ] **Step 5: Implement `src/Redirects/Repository.php`**

```php
<?php
/**
 * The only code that writes the redirects table. Every write flushes the
 * rule cache and fires the matching action.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Schema;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class Repository {

	private const UPDATABLE = [
		'type'        => '%s',
		'source'      => '%s',
		'target'      => '%s',
		'status_code' => '%d',
		'enabled'     => '%d',
		'origin'      => '%s',
		'note'        => '%s',
	];

	/**
	 * @return Rule[]
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY type ASC, position ASC, id ASC', Schema::redirects_table() ),
			ARRAY_A
		);
		return array_map( [ Rule::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	public function find( int $id ): ?Rule {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::redirects_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? Rule::from_row( $row ) : null;
	}

	public function enabled_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, type, source, target, status_code, position FROM %i WHERE enabled = 1 ORDER BY position ASC, id ASC',
				Schema::redirects_table()
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return int[]
	 */
	public function ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Schema::redirects_table() ) ) );
	}

	public function exact_rule_by_key( string $key, int $exclude_id = 0 ): ?Rule {
		foreach ( $this->all() as $rule ) {
			if ( 'exact' === $rule->type && $rule->id !== $exclude_id && PathNormalizer::source_key( $rule->source ) === $key ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Inserts already-validated data.
	 */
	public function insert( array $data ): ?Rule {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$type = 'regex' === ( $data['type'] ?? '' ) ? 'regex' : 'exact';

		$ok = $wpdb->insert(
			Schema::redirects_table(),
			[
				'type'        => $type,
				'source'      => (string) $data['source'],
				'target'      => isset( $data['target'] ) && '' !== $data['target'] ? (string) $data['target'] : null,
				'status_code' => (int) $data['status_code'],
				'position'    => 'regex' === $type ? $this->next_position() : 0,
				'enabled'     => array_key_exists( 'enabled', $data ) ? ( $data['enabled'] ? 1 : 0 ) : 1,
				'origin'      => 'auto' === ( $data['origin'] ?? '' ) ? 'auto' : 'manual',
				'note'        => (string) ( $data['note'] ?? '' ),
				'hits'        => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
		if ( false === $ok ) {
			return null;
		}

		$rule = $this->find( (int) $wpdb->insert_id );
		RuleCache::flush();
		if ( null !== $rule ) {
			/**
			 * Fires after a redirect is created.
			 *
			 * @param Rule $rule The new rule.
			 */
			do_action( 'adv_redirects_rule_created', $rule );
		}
		return $rule;
	}

	/**
	 * Updates already-validated fields. Unknown keys are ignored.
	 */
	public function update( int $id, array $data ): ?Rule {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return null;
		}

		$row     = [];
		$formats = [];
		foreach ( self::UPDATABLE as $column => $format ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$value = $data[ $column ];
			if ( 'target' === $column ) {
				$value = null === $value || '' === $value ? null : (string) $value;
			} elseif ( 'enabled' === $column ) {
				$value = $value ? 1 : 0;
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} else {
				$value = (string) $value;
			}
			$row[ $column ] = $value;
			$formats[]      = $format;
		}

		if ( isset( $row['type'] ) && 'regex' === $row['type'] && 'regex' !== $old->type ) {
			$row['position'] = $this->next_position();
			$formats[]       = '%d';
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$formats[]         = '%s';

		if ( false === $wpdb->update( Schema::redirects_table(), $row, [ 'id' => $id ], $formats, [ '%d' ] ) ) {
			return null;
		}

		$rule = $this->find( $id );
		RuleCache::flush();
		/**
		 * Fires after a redirect is updated.
		 *
		 * @param Rule $rule The updated rule.
		 * @param Rule $old  The rule before the update.
		 */
		do_action( 'adv_redirects_rule_updated', $rule, $old );
		return $rule;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}
		if ( ! $wpdb->delete( Schema::redirects_table(), [ 'id' => $id ], [ '%d' ] ) ) {
			return false;
		}

		RuleCache::flush();
		/**
		 * Fires after a redirect is deleted.
		 *
		 * @param Rule $old The deleted rule.
		 */
		do_action( 'adv_redirects_rule_deleted', $old );
		return true;
	}

	/**
	 * Sets regex evaluation order. Regex rules not listed keep their relative order after the listed ones.
	 *
	 * @param int[] $ids Regex rule IDs in the desired order.
	 */
	public function reorder( array $ids ): void {
		global $wpdb;

		$regex_ids = [];
		foreach ( $this->all() as $rule ) {
			if ( 'regex' === $rule->type ) {
				$regex_ids[] = $rule->id;
			}
		}

		$listed = array_values( array_intersect( array_unique( array_map( 'intval', $ids ) ), $regex_ids ) );
		$order  = array_merge( $listed, array_values( array_diff( $regex_ids, $listed ) ) );

		$position = 1;
		foreach ( $order as $id ) {
			$wpdb->update( Schema::redirects_table(), [ 'position' => $position++ ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
		}
		RuleCache::flush();
	}

	/**
	 * Points every rule that targets $from_path at $to_path. Rules that would
	 * become self-redirects are deleted.
	 */
	public function retarget( string $from_path, string $to_path ): int {
		$from_key = PathNormalizer::source_key( $from_path );
		$to_key   = PathNormalizer::source_key( $to_path );
		$count    = 0;

		foreach ( $this->all() as $rule ) {
			if ( null === $rule->target ) {
				continue;
			}
			$internal = Site::internal_path( $rule->target );
			if ( null === $internal || PathNormalizer::source_key( $internal ) !== $from_key ) {
				continue;
			}
			if ( 'exact' === $rule->type && PathNormalizer::source_key( $rule->source ) === $to_key ) {
				$this->delete( $rule->id );
			} else {
				$this->update( $rule->id, [ 'target' => $to_path ] );
			}
			++$count;
		}
		return $count;
	}

	public function disable_by_source_key( string $key ): int {
		$count = 0;
		foreach ( $this->all() as $rule ) {
			if ( 'exact' === $rule->type && $rule->enabled && PathNormalizer::source_key( $rule->source ) === $key ) {
				$this->update( $rule->id, [ 'enabled' => false ] );
				++$count;
			}
		}
		return $count;
	}

	public function add_hits( int $id, int $count, string $last_hit_gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET hits = hits + %d, last_hit_at = %s WHERE id = %d',
				Schema::redirects_table(),
				$count,
				$last_hit_gmt,
				$id
			)
		);
	}

	private function next_position(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(position), 0) FROM %i WHERE type = %s', Schema::redirects_table(), 'regex' )
		) + 1;
	}
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter RepositoryTest`
Expected: `OK (13 tests, …)`

- [ ] **Step 7: Lint and commit**

Run: `vendor/bin/phpcs`

```bash
git add src/Redirects/Rule.php src/Redirects/Repository.php src/Matching/RuleCache.php tests/integration/RepositoryTest.php
git commit -m "feat: add rule repository and object-cached ruleset

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: ChainResolver and Validator

**Files:**
- Create: `src/Redirects/ChainResolver.php`, `src/Redirects/Validator.php`
- Test: `tests/integration/ValidatorTest.php`

**Interfaces:**
- Consumes: `Repository`, `Site`, `Matcher`, `RulesetCompiler`, `PathNormalizer`, `Pattern`, `UrlSafety`, `TargetResolver::substitute()`
- Produces:
  - `ChainResolver::MAX_HOPS = 10`
  - `( new ChainResolver() )->resolve( string $source_label, string $target, array $ruleset, ?string $source_key = null ): array` returns `[ 'loop' => bool, 'hops' => string[] (starts with source label, then target, then each next hop), 'final' => string ]`. A chain exists when `! loop && count( hops ) > 2`. Exceeding MAX_HOPS counts as a loop.
  - `Validator::STATUSES = [301,302,307,308,410,451]`, `Validator::TYPES = ['exact','regex']`
  - `new Validator( Repository $repository, ChainResolver $chains )`
  - `->validate( array $input, ?int $id = null )` returns `[ 'data' => [type, source, target(?string), status_code, enabled, note], 'warnings' => [ ['code'=>'chain','hops'=>[],'final'=>string] ] ]` or `\WP_Error`.
  - Error codes (HTTP status): `adv_redirects_not_found` (404), `adv_redirects_invalid_type`, `adv_redirects_invalid_status`, `adv_redirects_invalid_source`, `adv_redirects_reserved_source`, `adv_redirects_invalid_regex`, `adv_redirects_invalid_target`, `adv_redirects_loop` (all 422), `adv_redirects_duplicate` (409).

- [ ] **Step 1: Write the failing test `tests/integration/ValidatorTest.php`**

```php
<?php

use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

final class ValidatorTest extends WP_UnitTestCase {

	private Repository $repo;
	private Validator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->repo      = new Repository();
		$this->validator = new Validator( $this->repo, new ChainResolver() );
	}

	private function valid( array $input, ?int $id = null ): array {
		$result = $this->validator->validate( $input, $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function error_code( array $input, ?int $id = null ): string {
		$result = $this->validator->validate( $input, $id );
		$this->assertWPError( $result );
		return $result->get_error_code();
	}

	private function save( array $input ): int {
		return $this->repo->insert( $this->valid( $input )['data'] )->id;
	}

	public function test_valid_exact_rule(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => ' /old ', 'target' => '/new', 'status_code' => 301 ] );
		$this->assertSame( '/old', $result['data']['source'] );
		$this->assertSame( '/new', $result['data']['target'] );
		$this->assertTrue( $result['data']['enabled'] );
		$this->assertSame( [], $result['warnings'] );
	}

	public function test_own_host_absolute_source_becomes_path(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => 'http://example.org/old?x=1', 'target' => '/new', 'status_code' => 301 ] );
		$this->assertSame( '/old?x=1', $result['data']['source'] );
	}

	public function test_invalid_sources(): void {
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => 'old', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => 'https://other.org/old', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => '//example.org/x', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => '', 'target' => '/new', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_source', $this->error_code( [ 'type' => 'exact', 'source' => "/a\nb", 'target' => '/new', 'status_code' => 301 ] ) );
	}

	public function test_reserved_sources_rejected(): void {
		foreach ( [ '/wp-login.php', '/wp-admin', '/WP-ADMIN/options.php', '/wp-json/wp/v2/users', '/xmlrpc.php' ] as $source ) {
			$this->assertSame(
				'adv_redirects_reserved_source',
				$this->error_code( [ 'type' => 'exact', 'source' => $source, 'target' => '/new', 'status_code' => 301 ] ),
				$source
			);
		}
	}

	public function test_invalid_regex_rejected(): void {
		$this->assertSame( 'adv_redirects_invalid_regex', $this->error_code( [ 'type' => 'regex', 'source' => '^/old/(.*$', 'target' => '/new', 'status_code' => 301 ] ) );
	}

	public function test_target_rules(): void {
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '', 'status_code' => 301 ] ) );
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '/x', 'status_code' => 410 ] ) );
		foreach ( [ '//evil.com', 'javascript:alert(1)', "/a\r\nLocation: x", '/\\evil.com', 'https://good.com@evil.com' ] as $target ) {
			$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => $target, 'status_code' => 301 ] ), $target );
		}
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'regex', 'source' => '^/(.*)$', 'target' => 'https://$1.example.com/', 'status_code' => 301 ] ) );

		$gone = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => null, 'status_code' => 451 ] );
		$this->assertNull( $gone['data']['target'] );
	}

	public function test_invalid_status_and_type(): void {
		$this->assertSame( 'adv_redirects_invalid_status', $this->error_code( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 303 ] ) );
		$this->assertSame( 'adv_redirects_invalid_type', $this->error_code( [ 'type' => 'glob', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] ) );
	}

	public function test_duplicate_detection_ignores_case_and_trailing_slash(): void {
		$id     = $this->save( [ 'type' => 'exact', 'source' => '/old-page', 'target' => '/new', 'status_code' => 301 ] );
		$result = $this->validator->validate( [ 'type' => 'exact', 'source' => '/Old-Page/', 'target' => '/other', 'status_code' => 301 ] );
		$this->assertWPError( $result );
		$this->assertSame( 'adv_redirects_duplicate', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $id, $result->get_error_data()['existing_id'] );

		// Updating the same rule is not a duplicate of itself.
		$this->valid( [ 'source' => '/OLD-PAGE' ], $id );
	}

	public function test_self_redirect_rejected(): void {
		$this->assertSame( 'adv_redirects_invalid_target', $this->error_code( [ 'type' => 'exact', 'source' => '/a/', 'target' => 'http://example.org/A', 'status_code' => 301 ] ) );
	}

	public function test_loop_rejected_with_path(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$result = $this->validator->validate( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertWPError( $result );
		$this->assertSame( 'adv_redirects_loop', $result->get_error_code() );
		$this->assertSame( 'Creates a loop: /a → /b → /a', $result->get_error_message() );
	}

	public function test_loop_through_regex_rejected(): void {
		$this->save( [ 'type' => 'regex', 'source' => '^/news/(.*)$', 'target' => '/blog/$1', 'status_code' => 301 ] );
		$this->assertSame( 'adv_redirects_loop', $this->error_code( [ 'type' => 'exact', 'source' => '/blog/x', 'target' => '/news/x', 'status_code' => 301 ] ) );
	}

	public function test_chain_returns_warning(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 301 ] );
		$result = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertSame( [ [ 'code' => 'chain', 'hops' => [ '/a', '/b', '/c' ], 'final' => '/c' ] ], $result['warnings'] );
	}

	public function test_disabled_rules_skip_loop_check(): void {
		$this->save( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'enabled' => false ] );
	}

	public function test_capture_targets_skip_loop_check(): void {
		$this->valid( [ 'type' => 'regex', 'source' => '^/a/(.*)$', 'target' => '/a/$1', 'status_code' => 301 ] );
	}

	public function test_partial_update_merges_existing_values(): void {
		$id     = $this->save( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 302, 'note' => 'keep' ] );
		$result = $this->valid( [ 'target' => '/c' ], $id );
		$this->assertSame( '/a', $result['data']['source'] );
		$this->assertSame( '/c', $result['data']['target'] );
		$this->assertSame( 302, $result['data']['status_code'] );
		$this->assertSame( 'keep', $result['data']['note'] );
	}

	public function test_missing_rule_on_update(): void {
		$this->assertSame( 'adv_redirects_not_found', $this->error_code( [ 'target' => '/c' ], 999999 ) );
	}

	public function test_custom_validation_filter(): void {
		add_filter(
			'adv_redirects_validate_rule',
			static function ( $valid, array $data ) {
				return '/blocked' === $data['source'] ? new WP_Error( 'custom', 'Nope', [ 'status' => 422 ] ) : $valid;
			},
			10,
			2
		);
		$this->assertSame( 'custom', $this->error_code( [ 'type' => 'exact', 'source' => '/blocked', 'target' => '/b', 'status_code' => 301 ] ) );
	}

	public function test_note_is_sanitized_and_truncated(): void {
		$result = $this->valid( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'note' => '<b>hi</b>' . str_repeat( 'x', 300 ) ] );
		$this->assertStringStartsWith( 'hi', $result['data']['note'] );
		$this->assertSame( 255, mb_strlen( $result['data']['note'] ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter ValidatorTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Redirects/ChainResolver.php`**

```php
<?php
/**
 * Follows a target through the rule set to find chains and loops.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\TargetResolver;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class ChainResolver {

	public const MAX_HOPS = 10;

	/**
	 * @param string      $source_label Shown as the first hop.
	 * @param string      $target       Where the rule points.
	 * @param array       $ruleset      Compiled rule set to follow.
	 * @param string|null $source_key   Normalized key of an exact source, treated as already visited.
	 * @return array{loop:bool,hops:string[],final:string}
	 */
	public function resolve( string $source_label, string $target, array $ruleset, ?string $source_key = null ): array {
		$matcher    = new Matcher( $ruleset );
		$normalizer = new PathNormalizer( '' );
		$hops       = [ $source_label, $target ];
		$seen       = null === $source_key ? [] : [ $source_key => true ];
		$current    = $target;

		for ( $i = 0; $i < self::MAX_HOPS; $i++ ) {
			$internal = Site::internal_path( $current );
			if ( null === $internal ) {
				return self::result( false, $hops, $current );
			}
			$request = $normalizer->from_request_uri( $internal );
			if ( null === $request ) {
				return self::result( false, $hops, $current );
			}

			$key = $request['key'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' );
			if ( isset( $seen[ $key ] ) ) {
				return self::result( true, $hops, $current );
			}
			$seen[ $key ] = true;

			$match = $matcher->match( $request );
			if ( null === $match || null === $match->target ) {
				return self::result( false, $hops, $current );
			}
			$current = TargetResolver::substitute( $match->target, $match->captures, true );
			$hops[]  = $current;
		}

		return self::result( true, $hops, $current );
	}

	private static function result( bool $loop, array $hops, string $final ): array {
		return [
			'loop'  => $loop,
			'hops'  => $hops,
			'final' => $final,
		];
	}
}
```

Loop walk-through for `/a → /b` with an existing `/b → /a`: hops start `[/a, /b]`. `/b` is unseen; it matches and goes to `/a`, giving hops `[/a, /b, /a]`. `/a` was seeded as `source_key`, so the result is a loop: "/a → /b → /a".

- [ ] **Step 4: Implement `src/Redirects/Validator.php`**

```php
<?php
/**
 * Validates and normalizes rule input before it reaches the Repository.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\Pattern;
use Advision\Redirects\Matching\RulesetCompiler;
use Advision\Redirects\Matching\UrlSafety;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class Validator {

	public const STATUSES = [ 301, 302, 307, 308, 410, 451 ];

	public const TYPES = [ 'exact', 'regex' ];

	private Repository $repository;

	private ChainResolver $chains;

	public function __construct( Repository $repository, ChainResolver $chains ) {
		$this->repository = $repository;
		$this->chains     = $chains;
	}

	/**
	 * @param array    $input Raw fields (type, source, target, status_code, enabled, note). Missing fields keep existing values on update.
	 * @param int|null $id    Rule being updated, or null for a new rule.
	 * @return array{data:array,warnings:array}|\WP_Error
	 */
	public function validate( array $input, ?int $id = null ) {
		$existing = null;
		if ( null !== $id ) {
			$existing = $this->repository->find( $id );
			if ( null === $existing ) {
				return self::error( 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ), 404 );
			}
		}

		$base = null !== $existing ? $existing->to_array() : [
			'type'        => 'exact',
			'source'      => '',
			'target'      => null,
			'status_code' => 301,
			'enabled'     => true,
			'note'        => '',
		];

		$type    = (string) ( $input['type'] ?? $base['type'] );
		$status  = (int) ( $input['status_code'] ?? $base['status_code'] );
		$source  = trim( (string) ( $input['source'] ?? $base['source'] ) );
		$target  = array_key_exists( 'target', $input ) ? $input['target'] : $base['target'];
		$target  = null === $target ? '' : trim( (string) $target );
		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : (bool) $base['enabled'];
		$note    = mb_substr( sanitize_text_field( (string) ( $input['note'] ?? $base['note'] ) ), 0, 255 );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::error( 'adv_redirects_invalid_type', __( 'Type must be "exact" or "regex".', 'wp-redirects' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::error( 'adv_redirects_invalid_status', __( 'Choose a supported status code.', 'wp-redirects' ) );
		}

		$source = $this->check_source( $type, $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( in_array( $status, [ 410, 451 ], true ) ) {
			if ( '' !== $target ) {
				return self::error( 'adv_redirects_invalid_target', __( '410 and 451 responses cannot have a target.', 'wp-redirects' ) );
			}
			$target = null;
		} else {
			$checked = $this->check_target( $target );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		if ( 'exact' === $type ) {
			$duplicate = $this->repository->exact_rule_by_key( PathNormalizer::source_key( $source ), (int) $id );
			if ( null !== $duplicate ) {
				return self::error(
					'adv_redirects_duplicate',
					/* translators: %s: source path */
					sprintf( __( 'A redirect for %s already exists.', 'wp-redirects' ), $duplicate->source ),
					409,
					[ 'existing_id' => $duplicate->id ]
				);
			}
			if ( null !== $target ) {
				$internal = Site::internal_path( $target );
				if ( null !== $internal && PathNormalizer::source_key( $internal ) === PathNormalizer::source_key( $source ) ) {
					return self::error( 'adv_redirects_invalid_target', __( 'The target is the same as the source.', 'wp-redirects' ) );
				}
			}
		}

		$data = [
			'type'        => $type,
			'source'      => $source,
			'target'      => $target,
			'status_code' => $status,
			'enabled'     => $enabled,
			'note'        => $note,
		];

		/**
		 * Filters rule validation. Return a WP_Error to reject the rule.
		 *
		 * @param true|\WP_Error $valid True when valid so far.
		 * @param array          $data  Normalized rule data.
		 * @param int|null       $id    Rule ID on update, null on create.
		 */
		$custom = apply_filters( 'adv_redirects_validate_rule', true, $data, $id );
		if ( is_wp_error( $custom ) ) {
			return $custom;
		}

		$warnings = [];
		if ( $enabled && null !== $target && ! preg_match( '/\$[1-9]/', $target ) ) {
			$chain = $this->chains->resolve(
				$source,
				$target,
				$this->ruleset_with( $data, $id, $existing ),
				'exact' === $type ? PathNormalizer::source_key( $source ) : null
			);
			if ( $chain['loop'] ) {
				return self::error(
					'adv_redirects_loop',
					/* translators: %s: redirect path, e.g. "/a → /b → /a" */
					sprintf( __( 'Creates a loop: %s', 'wp-redirects' ), implode( ' → ', $chain['hops'] ) ),
					422,
					[ 'hops' => $chain['hops'] ]
				);
			}
			if ( count( $chain['hops'] ) > 2 ) {
				$warnings[] = [
					'code'  => 'chain',
					'hops'  => $chain['hops'],
					'final' => $chain['final'],
				];
			}
		}

		return [
			'data'     => $data,
			'warnings' => $warnings,
		];
	}

	/**
	 * @return string|\WP_Error Normalized source.
	 */
	private function check_source( string $type, string $source ) {
		if ( '' === $source || strlen( $source ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $source ) ) {
			return self::error( 'adv_redirects_invalid_source', __( 'Enter a valid source.', 'wp-redirects' ) );
		}

		if ( 'regex' === $type ) {
			if ( ! Pattern::is_valid( $source ) ) {
				return self::error(
					'adv_redirects_invalid_regex',
					/* translators: %d: maximum pattern length */
					sprintf( __( 'The pattern is not a valid regular expression (maximum %d characters).', 'wp-redirects' ), Pattern::MAX_LENGTH )
				);
			}
			return $source;
		}

		$path = $this->exact_source_path( $source );
		if ( null === $path ) {
			return self::error( 'adv_redirects_invalid_source', __( 'Source must be a path starting with "/" or a URL on this site.', 'wp-redirects' ) );
		}

		$path_only = explode( '?', $path, 2 )[0];
		if ( Site::is_reserved_path( rawurldecode( $path_only ) ) ) {
			return self::error( 'adv_redirects_reserved_source', __( 'This path is used by WordPress itself and cannot be redirected.', 'wp-redirects' ) );
		}
		return $path;
	}

	private function exact_source_path( string $source ): ?string {
		if ( '/' === $source[0] ) {
			return 0 === strpos( $source, '//' ) ? null : $source;
		}
		if ( ! preg_match( '#^https?://#i', $source ) ) {
			return null;
		}
		return Site::internal_path( $source );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function check_target( string $target ) {
		if ( '' === $target ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Enter a target.', 'wp-redirects' ) );
		}
		if ( ! UrlSafety::is_safe( $target ) || esc_url_raw( $target, [ 'http', 'https' ] ) !== $target ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Target must be a path starting with "/" or an http(s) URL.', 'wp-redirects' ) );
		}

		$host = UrlSafety::host_of( $target, Site::host() );
		if ( false !== strpos( $host, '$' ) ) {
			return self::error( 'adv_redirects_invalid_target', __( 'Capture references ($1-$9) cannot be used in the host.', 'wp-redirects' ) );
		}

		/** This filter is documented in src/Site.php */
		$allowed = array_map( 'strtolower', (array) apply_filters( 'adv_redirects_allowed_target_hosts', [] ) );
		if ( $host !== Site::host() && ! empty( $allowed ) && ! in_array( $host, $allowed, true ) ) {
			return self::error( 'adv_redirects_invalid_target', __( 'That host is not on the allowed list.', 'wp-redirects' ) );
		}
		return true;
	}

	private function ruleset_with( array $data, ?int $id, ?Rule $existing ): array {
		$rows = array_values(
			array_filter(
				$this->repository->enabled_rows(),
				static function ( array $row ) use ( $id ): bool {
					return (int) $row['id'] !== (int) $id;
				}
			)
		);

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

	private static function error( string $code, string $message, int $status = 422, array $extra = [] ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] + $extra );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter ValidatorTest`
Expected: `OK (18 tests, …)`

- [ ] **Step 6: Lint and commit**

Run: `vendor/bin/phpcs`

```bash
git add src/Redirects/ChainResolver.php src/Redirects/Validator.php tests/integration/ValidatorTest.php
git commit -m "feat: add rule validator with loop and chain detection

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: HitTracker

**Files:**
- Create: `src/Tracking/HitTracker.php`
- Test: `tests/integration/HitTrackerTest.php`

**Interfaces:**
- Consumes: `Repository::add_hits()`, `Repository::ids()`, `RuleCache::GROUP`
- Produces: `new HitTracker( Repository )`, `->record( int $rule_id ): void`, `->flush(): int` (returns total hits written). Filter `adv_redirects_hit_tracking_enabled`.

- [ ] **Step 1: Write the failing test `tests/integration/HitTrackerTest.php`**

```php
<?php

use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Tracking\HitTracker;

final class HitTrackerTest extends WP_UnitTestCase {

	private Repository $repo;
	private HitTracker $tracker;
	private int $rule_id;

	public function set_up(): void {
		parent::set_up();
		$this->repo    = new Repository();
		$this->tracker = new HitTracker( $this->repo );
		$this->rule_id = $this->repo->insert( [ 'type' => 'exact', 'source' => '/old', 'target' => '/new', 'status_code' => 301 ] )->id;
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	public function test_without_persistent_cache_writes_immediately(): void {
		wp_using_ext_object_cache( false );
		$this->tracker->record( $this->rule_id );
		$rule = $this->repo->find( $this->rule_id );
		$this->assertSame( 1, $rule->hits );
		$this->assertNotNull( $rule->last_hit_at );
	}

	public function test_with_persistent_cache_buffers_until_flush(): void {
		wp_using_ext_object_cache( true );
		$this->tracker->record( $this->rule_id );
		$this->tracker->record( $this->rule_id );
		$this->tracker->record( $this->rule_id );
		$this->assertSame( 0, $this->repo->find( $this->rule_id )->hits );

		$this->assertSame( 3, $this->tracker->flush() );
		$this->assertSame( 3, $this->repo->find( $this->rule_id )->hits );
		$this->assertSame( 0, (int) wp_cache_get( 'hit:' . $this->rule_id, 'adv_redirects' ) );

		$this->assertSame( 0, $this->tracker->flush(), 'Second flush writes nothing.' );
		$this->assertSame( 3, $this->repo->find( $this->rule_id )->hits );
	}

	public function test_filter_disables_tracking(): void {
		add_filter( 'adv_redirects_hit_tracking_enabled', '__return_false' );
		$this->tracker->record( $this->rule_id );
		$this->assertSame( 0, $this->repo->find( $this->rule_id )->hits );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter HitTrackerTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Tracking/HitTracker.php`**

```php
<?php
/**
 * Hit counting. With a persistent object cache, hits are buffered with an
 * atomic increment and written by cron; otherwise they are written directly.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;

defined( 'ABSPATH' ) || exit;

final class HitTracker {

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public function record( int $rule_id ): void {
		/**
		 * Filters whether redirect hits are counted.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'adv_redirects_hit_tracking_enabled', true ) ) {
			return;
		}

		$now = current_time( 'mysql', true );
		if ( ! wp_using_ext_object_cache() ) {
			$this->repository->add_hits( $rule_id, 1, $now );
			return;
		}

		wp_cache_add( 'hit:' . $rule_id, 0, RuleCache::GROUP );
		wp_cache_incr( 'hit:' . $rule_id, 1, RuleCache::GROUP );
		wp_cache_set( 'last:' . $rule_id, $now, RuleCache::GROUP );
	}

	public function flush(): int {
		if ( ! wp_using_ext_object_cache() ) {
			return 0;
		}

		$total = 0;
		foreach ( $this->repository->ids() as $id ) {
			$count = (int) wp_cache_get( 'hit:' . $id, RuleCache::GROUP );
			if ( $count <= 0 ) {
				continue;
			}
			$last = wp_cache_get( 'last:' . $id, RuleCache::GROUP );
			$this->repository->add_hits( $id, $count, is_string( $last ) ? $last : current_time( 'mysql', true ) );
			// Decrement rather than delete so hits recorded during the flush survive.
			wp_cache_decr( 'hit:' . $id, $count, RuleCache::GROUP );
			$total += $count;
		}
		return $total;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter HitTrackerTest`
Expected: `OK (3 tests, …)`

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Tracking/HitTracker.php tests/integration/HitTrackerTest.php
git commit -m "feat: add buffered hit tracking

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Redirector

**Files:**
- Create: `src/Matching/Redirector.php`, `tests/integration/support/RedirectCaught.php`
- Test: `tests/integration/RedirectorTest.php`

**Interfaces:**
- Consumes: `RuleCache::get()`, `HitTracker::record()`, `Site::normalizer()`, `Site::resolver()`, `Site::is_reserved_path()`, `Settings::get()`, `Matcher`, `MatchResult`, `UrlSafety::is_safe()`, `Validator::STATUSES`
- Produces:
  - `new Redirector( RuleCache, HitTracker )`
  - `->register(): void` hooks `init` (priority 1) → `maybe_redirect`, and `template_redirect` (priority 0) → `maybe_send_gone`
  - `->decide( string $uri, string $method ): ?array` returns `[ 'rule' => ['id','type','target','status'], 'status' => int, 'url' => ?string ]`
  - `->maybe_redirect(): void`, `->maybe_send_gone(): void`, `->is_gone_request(): bool`, `->log_regex_error( int $rule_id, int $code ): void`
  - Hooks: `adv_redirects_allowed_methods`, `adv_redirects_should_handle_request`, `adv_redirects_request_path`, `adv_redirects_match`, `adv_redirects_status_code`, `adv_redirects_forward_query_string`, `adv_redirects_target_url`, action `adv_redirects_before_redirect`

- [ ] **Step 1: Create the test helper `tests/integration/support/RedirectCaught.php`**

```php
<?php
/**
 * Thrown from the wp_redirect_status filter so tests can inspect a redirect without exit().
 */
final class Adv_Redirects_Redirect_Caught extends Exception {

	public string $location;

	public int $status;

	public function __construct( string $location, int $status ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
		$this->status   = $status;
	}
}
```

- [ ] **Step 2: Write the failing test `tests/integration/RedirectorTest.php`**

```php
<?php

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Settings;
use Advision\Redirects\Tracking\HitTracker;

final class RedirectorTest extends WP_UnitTestCase {

	private Repository $repo;
	private Redirector $redirector;

	public function set_up(): void {
		parent::set_up();
		$this->repo       = new Repository();
		$this->redirector = new Redirector( new RuleCache( $this->repo ), new HitTracker( $this->repo ) );
	}

	public function tear_down(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	private function rule( string $type, string $source, ?string $target, int $status = 301 ): int {
		return $this->repo->insert(
			[
				'type'        => $type,
				'source'      => $source,
				'target'      => $target,
				'status_code' => $status,
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

	public function test_regex_redirect_with_capture(): void {
		$this->rule( 'regex', '^/blog/(\d+)/?$', '/posts/$1', 302 );
		$decision = $this->redirector->decide( '/blog/42', 'HEAD' );
		$this->assertSame( 'http://example.org/posts/42', $decision['url'] );
		$this->assertSame( 302, $decision['status'] );
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
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter RedirectorTest`
Expected: FAIL, class not found.

- [ ] **Step 4: Implement `src/Matching/Redirector.php`**

```php
<?php
/**
 * Request-time matching and response.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Matching;

use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;
use Advision\Redirects\Tracking\HitTracker;

defined( 'ABSPATH' ) || exit;

final class Redirector {

	private RuleCache $cache;

	private HitTracker $hits;

	private ?array $gone = null;

	/** @var array<int,bool> */
	private array $logged_errors = [];

	public function __construct( RuleCache $cache, HitTracker $hits ) {
		$this->cache = $cache;
		$this->hits  = $hits;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_redirect' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_send_gone' ], 0 );
	}

	public function maybe_redirect(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The raw URI is required; PathNormalizer validates it and rejects control characters.
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$decision = $this->decide( $uri, $method );
		if ( null === $decision ) {
			return;
		}

		$this->hits->record( (int) $decision['rule']['id'] );

		if ( null === $decision['url'] ) {
			$this->gone = $decision;
			return;
		}

		/**
		 * Fires immediately before a redirect response is sent.
		 *
		 * @param array  $rule   { id, type, target, status }.
		 * @param string $url    Final absolute URL.
		 * @param int    $status HTTP status code.
		 */
		do_action( 'adv_redirects_before_redirect', $decision['rule'], $decision['url'], $decision['status'] );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External targets are a feature; URL validated by UrlSafety + host guard.
		if ( wp_redirect( $decision['url'], $decision['status'], 'WP Redirects' ) ) {
			exit;
		}
	}

	/**
	 * @return array{rule:array,status:int,url:?string}|null
	 */
	public function decide( string $uri, string $method ): ?array {
		if ( $this->is_excluded_context() ) {
			return null;
		}

		/**
		 * Filters which HTTP methods are redirected.
		 *
		 * @param string[] $methods Default [ 'GET', 'HEAD' ].
		 */
		$methods = array_map( 'strtoupper', (array) apply_filters( 'adv_redirects_allowed_methods', [ 'GET', 'HEAD' ] ) );
		if ( ! in_array( strtoupper( $method ), $methods, true ) ) {
			return null;
		}

		$request = Site::normalizer()->from_request_uri( $uri );
		if ( null === $request ) {
			return null;
		}

		/**
		 * Filters whether this request should be matched at all.
		 *
		 * @param bool   $handle Default true.
		 * @param string $path   Normalized, decoded request path.
		 */
		if ( ! apply_filters( 'adv_redirects_should_handle_request', true, $request['path'] ) ) {
			return null;
		}

		/**
		 * Filters the normalized request path before matching.
		 *
		 * @param string $path Decoded path relative to the site home, starting with "/".
		 */
		$path = (string) apply_filters( 'adv_redirects_request_path', $request['path'] );
		if ( $path !== $request['path'] ) {
			if ( '' === $path || '/' !== $path[0] ) {
				return null;
			}
			$request['path'] = $path;
			$request['key']  = PathNormalizer::key( $path );
		}
		if ( Site::is_reserved_path( $request['path'] ) ) {
			return null;
		}

		$ruleset = $this->cache->get();
		if ( empty( $ruleset['exact'] ) && empty( $ruleset['regex'] ) ) {
			return null;
		}

		$match = ( new Matcher( $ruleset, [ $this, 'log_regex_error' ] ) )->match( $request );

		/**
		 * Filters the match for this request. Return null to suppress, or a MatchResult to override.
		 *
		 * @param MatchResult|null $match
		 * @param string           $path
		 * @param string           $query Raw query string without "?".
		 */
		$match = apply_filters( 'adv_redirects_match', $match, $request['path'], $request['query'] );
		if ( ! $match instanceof MatchResult ) {
			return null;
		}

		$rule = [
			'id'     => $match->rule_id,
			'type'   => $match->type,
			'target' => $match->target,
			'status' => $match->status,
		];

		/**
		 * Filters the response status code. Values outside the supported set cancel the redirect.
		 *
		 * @param int   $status
		 * @param array $rule
		 */
		$status = (int) apply_filters( 'adv_redirects_status_code', $match->status, $rule );
		if ( ! in_array( $status, Validator::STATUSES, true ) ) {
			return null;
		}
		if ( $status >= 400 ) {
			return [
				'rule'   => $rule,
				'status' => $status,
				'url'    => null,
			];
		}
		if ( null === $match->target ) {
			return null;
		}

		/**
		 * Filters whether the incoming query string is forwarded to the target.
		 *
		 * @param bool  $forward Setting value.
		 * @param array $rule
		 */
		$forward = (bool) apply_filters( 'adv_redirects_forward_query_string', (bool) Settings::get( 'forward_query_string' ), $rule );

		$url = Site::resolver()->resolve( $match->target, $match->captures, $request['query'], $forward );
		if ( null === $url ) {
			return null;
		}

		/**
		 * Filters the final redirect URL. Unsafe values cancel the redirect.
		 *
		 * @param string $url
		 * @param array  $rule
		 * @param string $path
		 */
		$url = apply_filters( 'adv_redirects_target_url', $url, $rule, $request['path'] );
		if ( ! is_string( $url ) || ! UrlSafety::is_safe( $url ) ) {
			return null;
		}

		return [
			'rule'   => $rule,
			'status' => $status,
			'url'    => $url,
		];
	}

	public function maybe_send_gone(): void {
		if ( null === $this->gone ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		// Stop core from "guessing" a similar permalink and redirecting a 410/451 away.
		remove_action( 'template_redirect', 'redirect_canonical' );
		status_header( (int) $this->gone['status'] );
		nocache_headers();
	}

	public function is_gone_request(): bool {
		return null !== $this->gone;
	}

	public function log_regex_error( int $rule_id, int $code ): void {
		if ( isset( $this->logged_errors[ $rule_id ] ) ) {
			return;
		}
		$this->logged_errors[ $rule_id ] = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational signal for a broken regex rule; the request path is not logged.
		error_log( sprintf( 'WP Redirects: regex rule #%d skipped (PCRE error %d).', $rule_id, $code ) );
	}

	private function is_excluded_context(): bool {
		return is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter RedirectorTest`
Expected: `OK (13 tests, …)`

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Matching/Redirector.php tests/integration/support/RedirectCaught.php tests/integration/RedirectorTest.php
git commit -m "feat: add request-time redirector

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 11: 404 log and cron

**Files:**
- Create: `src/Tracking/NotFoundRepository.php`, `src/Tracking/NotFoundLogger.php`, `src/Cron.php`
- Test: `tests/integration/NotFoundTest.php`, `tests/integration/CronTest.php`

**Interfaces:**
- Consumes: `Schema::not_found_table()`, `Settings::get()`, `Site::normalizer()`, `Redirector::is_gone_request()`, `HitTracker::flush()`, `PathNormalizer::source_key()`, `Rule`
- Produces:
  - `NotFoundRepository` methods:
    - `log( string $path, string $referrer, string $now_gmt ): void`
    - `query( array $args ): array{items:array,total:int}`. Args: `page`, `per_page` (≤100), `search`, `orderby` ∈ {hits, last_seen, path}, `order` ∈ {asc, desc}. Items contain `id:int, path, hits:int, first_seen, last_seen, last_referrer`.
    - `delete( int $id ): bool`, `delete_many( array $ids ): int`, `clear(): int`
    - `delete_matching_source( string $source ): int`
    - `prune( int $days, int $max_rows ): int`
  - `new NotFoundLogger( NotFoundRepository, Redirector )` with methods:
    - `register(): void` hooks `template_redirect` (priority 99) → `maybe_log`, and `adv_redirects_rule_created` → `on_rule_created`
    - `log_request( string $uri, string $method, string $referrer ): bool`
    - `on_rule_created( Rule $rule ): void`
    - `NotFoundLogger::clean( string $value ): string`
  - Filters `adv_redirects_log_404`, `adv_redirects_404_excluded_extensions`; action `adv_redirects_404_logged`.
  - `Cron::HIT_HOOK = 'adv_redirects_flush_hits'`, `Cron::PRUNE_HOOK = 'adv_redirects_prune_404s'`, `Cron::INTERVAL = 'adv_redirects_five_minutes'`
  - `new Cron( HitTracker, NotFoundRepository )` with methods `register(): void`, `schedules( array ): array`, `run_prune(): int`, `Cron::ensure_scheduled(): void`, `Cron::unschedule(): void`

- [ ] **Step 1: Write the failing tests**

`tests/integration/NotFoundTest.php`:
```php
<?php

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Settings;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundLogger;
use Advision\Redirects\Tracking\NotFoundRepository;

final class NotFoundTest extends WP_UnitTestCase {

	private NotFoundRepository $repo;
	private NotFoundLogger $logger;

	public function set_up(): void {
		parent::set_up();
		$rules        = new Repository();
		$this->repo   = new NotFoundRepository();
		$this->logger = new NotFoundLogger( $this->repo, new Redirector( new RuleCache( $rules ), new HitTracker( $rules ) ) );
	}

	public function test_logging_upserts_by_path(): void {
		$this->assertTrue( $this->logger->log_request( '/missing?x=1', 'GET', 'https://ref.example/' ) );
		$this->assertTrue( $this->logger->log_request( '/missing?x=1', 'GET', '' ) );
		$this->assertTrue( $this->logger->log_request( '/other', 'GET', '' ) );

		$result = $this->repo->query( [] );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( '/missing?x=1', $result['items'][0]['path'] );
		$this->assertSame( 2, $result['items'][0]['hits'] );
		$this->assertSame( '', $result['items'][0]['last_referrer'] );
	}

	public function test_skips_non_get_excluded_extensions_and_disabled_setting(): void {
		$this->assertFalse( $this->logger->log_request( '/missing', 'POST', '' ) );
		$this->assertFalse( $this->logger->log_request( '/style.CSS', 'GET', '' ) );

		add_filter( 'adv_redirects_log_404', '__return_false' );
		$this->assertFalse( $this->logger->log_request( '/missing', 'GET', '' ) );
		remove_all_filters( 'adv_redirects_log_404' );

		Settings::update( [ 'log_404' => false ] );
		$this->assertFalse( $this->logger->log_request( '/missing', 'GET', '' ) );
		$this->assertSame( 0, $this->repo->query( [] )['total'] );
	}

	public function test_clean_strips_control_characters_and_truncates(): void {
		$this->assertSame( 'ab', NotFoundLogger::clean( "a\r\n\0b" ) );
		$this->assertSame( 2048, mb_strlen( NotFoundLogger::clean( str_repeat( 'é', 3000 ) ) ) );
	}

	public function test_query_search_sort_and_paging(): void {
		foreach ( [ '/a', '/b', '/b', '/b', '/c', '/c' ] as $path ) {
			$this->logger->log_request( $path, 'GET', '' );
		}
		$this->assertSame( [ '/b', '/c', '/a' ], array_column( $this->repo->query( [] )['items'], 'path' ) );
		$this->assertSame( [ '/a', '/b', '/c' ], array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' ) );
		$this->assertSame( [ '/c' ], array_column( $this->repo->query( [ 'search' => 'c' ] )['items'], 'path' ) );

		$page = $this->repo->query( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ '/a' ], array_column( $page['items'], 'path' ) );

		$this->assertCount( 3, $this->repo->query( [ 'orderby' => 'id; DROP TABLE x', 'order' => 'sideways' ] )['items'] );
	}

	public function test_search_treats_wildcards_literally(): void {
		$this->logger->log_request( '/a_b', 'GET', '' );
		$this->logger->log_request( '/axb', 'GET', '' );
		$this->assertSame( [ '/a_b' ], array_column( $this->repo->query( [ 'search' => 'a_b' ] )['items'], 'path' ) );
	}

	public function test_delete_many_and_clear(): void {
		$this->logger->log_request( '/a', 'GET', '' );
		$this->logger->log_request( '/b', 'GET', '' );
		$this->logger->log_request( '/c', 'GET', '' );
		$ids = array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'id' );

		$this->assertTrue( $this->repo->delete( $ids[0] ) );
		$this->assertSame( 1, $this->repo->delete_many( [ $ids[1], 999999 ] ) );
		$this->assertSame( 1, $this->repo->clear() );
		$this->assertSame( 0, $this->repo->query( [] )['total'] );
	}

	public function test_creating_a_redirect_removes_matching_404(): void {
		$this->logger->log_request( '/Old-Page/', 'GET', '' );
		$this->logger->log_request( '/old-page-2', 'GET', '' );
		$this->logger->register();

		( new Repository() )->insert( [ 'type' => 'exact', 'source' => '/old-page', 'target' => '/new', 'status_code' => 301 ] );

		$this->assertSame( [ '/old-page-2' ], array_column( $this->repo->query( [] )['items'], 'path' ) );
	}

	public function test_prune_by_age_and_row_cap(): void {
		global $wpdb;
		$this->repo->log( '/old', '', '2020-01-01 00:00:00' );
		$this->repo->log( '/a', '', gmdate( 'Y-m-d H:i:s', time() - 30 ) );
		$this->repo->log( '/b', '', gmdate( 'Y-m-d H:i:s', time() - 20 ) );
		$this->repo->log( '/c', '', gmdate( 'Y-m-d H:i:s', time() - 10 ) );

		$this->assertSame( 2, $this->repo->prune( 30, 2 ) );
		$this->assertSame( [ '/b', '/c' ], array_column( $this->repo->query( [ 'orderby' => 'path', 'order' => 'asc' ] )['items'], 'path' ) );
	}
}
```

`tests/integration/CronTest.php`:
```php
<?php

use Advision\Redirects\Cron;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundRepository;

final class CronTest extends WP_UnitTestCase {

	private Cron $cron;

	public function set_up(): void {
		parent::set_up();
		$this->cron = new Cron( new HitTracker( new Repository() ), new NotFoundRepository() );
		$this->cron->register();
	}

	public function tear_down(): void {
		Cron::unschedule();
		parent::tear_down();
	}

	public function test_registers_five_minute_schedule(): void {
		$schedules = wp_get_schedules();
		$this->assertSame( 300, $schedules[ Cron::INTERVAL ]['interval'] );
	}

	public function test_ensure_scheduled_and_unschedule(): void {
		Cron::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );
		$this->assertSame( Cron::INTERVAL, wp_get_schedule( Cron::HIT_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Cron::PRUNE_HOOK ) );

		Cron::unschedule();
		$this->assertFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );
	}

	public function test_hooks_are_wired(): void {
		$this->assertSame( 10, has_action( Cron::PRUNE_HOOK, [ $this->cron, 'run_prune' ] ) );
		$this->assertNotFalse( has_action( Cron::HIT_HOOK ) );
	}

	public function test_run_prune_uses_settings(): void {
		( new NotFoundRepository() )->log( '/ancient', '', '2000-01-01 00:00:00' );
		$this->assertSame( 1, $this->cron->run_prune() );
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npm run test:php:integration -- --filter 'NotFoundTest|CronTest'`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `src/Tracking/NotFoundRepository.php`**

```php
<?php
/**
 * Storage for the 404 log.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Schema;

defined( 'ABSPATH' ) || exit;

final class NotFoundRepository {

	private const ORDERBY = [ 'hits', 'last_seen', 'path' ];

	public function log( string $path, string $referrer, string $now_gmt ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (path, path_hash, hits, first_seen, last_seen, last_referrer) VALUES (%s, %s, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s, last_referrer = %s',
				Schema::not_found_table(),
				$path,
				md5( $path ),
				$now_gmt,
				$now_gmt,
				$referrer,
				$now_gmt,
				$referrer
			)
		);
	}

	/**
	 * @return array{items:array<int,array>,total:int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$orderby  = in_array( $args['orderby'] ?? '', self::ORDERBY, true ) ? $args['orderby'] : 'hits';
		$asc      = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) );
		$like     = '%' . $wpdb->esc_like( trim( (string) ( $args['search'] ?? '' ) ) ) . '%';
		$table    = Schema::not_found_table();

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE path LIKE %s', $table, $like ) );

		$rows = $wpdb->get_results(
			$asc
				? $wpdb->prepare(
					'SELECT id, path, hits, first_seen, last_seen, last_referrer FROM %i WHERE path LIKE %s ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d',
					$table,
					$like,
					$orderby,
					$per_page,
					( $page - 1 ) * $per_page
				)
				: $wpdb->prepare(
					'SELECT id, path, hits, first_seen, last_seen, last_referrer FROM %i WHERE path LIKE %s ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$like,
					$orderby,
					$per_page,
					( $page - 1 ) * $per_page
				),
			ARRAY_A
		);

		$items = array_map(
			static function ( array $row ): array {
				$row['id']   = (int) $row['id'];
				$row['hits'] = (int) $row['hits'];
				return $row;
			},
			is_array( $rows ) ? $rows : []
		);

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::not_found_table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * @param int[] $ids IDs to delete.
	 */
	public function delete_many( array $ids ): int {
		$count = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $this->delete( $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function clear(): int {
		global $wpdb;
		// DELETE rather than TRUNCATE: TRUNCATE implicitly commits open transactions.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::not_found_table() ) );
	}

	/**
	 * Deletes 404 rows that an exact redirect source now covers.
	 */
	public function delete_matching_source( string $source ): int {
		global $wpdb;

		$key    = PathNormalizer::source_key( $source );
		$prefix = rtrim( explode( '?', $key, 2 )[0], '/' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, path FROM %i WHERE path LIKE %s LIMIT 500',
				Schema::not_found_table(),
				$wpdb->esc_like( '' === $prefix ? '/' : $prefix ) . '%'
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( PathNormalizer::source_key( (string) $row['path'] ) === $key && $this->delete( (int) $row['id'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function prune( int $days, int $max_rows ): int {
		global $wpdb;
		$table  = Schema::not_found_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $cutoff ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $total > $max_rows ) {
			$deleted += (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i ORDER BY last_seen ASC, id ASC LIMIT %d', $table, $total - $max_rows )
			);
		}
		return $deleted;
	}
}
```

If PHPCS flags the ternary inside `get_results()` (`WordPress.DB.PreparedSQL.NotPrepared`), add `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both branches are $wpdb->prepare() calls with literal SQL.` on the `$rows = …` line.

- [ ] **Step 4: Implement `src/Tracking/NotFoundLogger.php`**

```php
<?php
/**
 * Records 404 responses.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Tracking;

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class NotFoundLogger {

	private NotFoundRepository $repository;

	private Redirector $redirector;

	public function __construct( NotFoundRepository $repository, Redirector $redirector ) {
		$this->repository = $repository;
		$this->redirector = $redirector;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_log' ], 99 );
		add_action( 'adv_redirects_rule_created', [ $this, 'on_rule_created' ] );
	}

	public function maybe_log(): void {
		if ( ! is_404() || $this->redirector->is_gone_request() ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw values are normalized and cleaned in log_request().
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		// phpcs:enable
		$this->log_request( $uri, $method, $referrer );
	}

	public function log_request( string $uri, string $method, string $referrer ): bool {
		if ( ! Settings::get( 'log_404' ) || 'GET' !== strtoupper( $method ) ) {
			return false;
		}

		$request = Site::normalizer()->from_request_uri( $uri );
		if ( null === $request ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $request['path'], PATHINFO_EXTENSION ) );
		/**
		 * Filters file extensions that are never logged as 404s.
		 *
		 * @param string[] $extensions Lowercase, without dots.
		 */
		$excluded = (array) apply_filters( 'adv_redirects_404_excluded_extensions', Settings::get( 'excluded_404_extensions' ) );
		if ( '' !== $extension && in_array( $extension, $excluded, true ) ) {
			return false;
		}

		$path = self::clean( $request['path'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' ) );

		/**
		 * Filters whether a 404 is logged.
		 *
		 * @param bool   $log  Default true.
		 * @param string $path Path (and query) relative to the site home.
		 */
		if ( ! apply_filters( 'adv_redirects_log_404', true, $path ) ) {
			return false;
		}

		$this->repository->log( $path, self::clean( $referrer ), current_time( 'mysql', true ) );

		/**
		 * Fires after a 404 is logged.
		 *
		 * @param string $path
		 */
		do_action( 'adv_redirects_404_logged', $path );
		return true;
	}

	public function on_rule_created( Rule $rule ): void {
		if ( 'exact' === $rule->type ) {
			$this->repository->delete_matching_source( $rule->source );
		}
	}

	public static function clean( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return mb_substr( $value, 0, 2048 );
	}
}
```

- [ ] **Step 5: Implement `src/Cron.php`**

```php
<?php
/**
 * Scheduled jobs: hit flush (5 min) and 404 pruning (daily).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundRepository;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const HIT_HOOK = 'adv_redirects_flush_hits';

	public const PRUNE_HOOK = 'adv_redirects_prune_404s';

	public const INTERVAL = 'adv_redirects_five_minutes';

	private HitTracker $hits;

	private NotFoundRepository $not_found;

	public function __construct( HitTracker $hits, NotFoundRepository $not_found ) {
		$this->hits      = $hits;
		$this->not_found = $not_found;
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'schedules' ] );
		add_action( self::HIT_HOOK, [ $this->hits, 'flush' ] );
		add_action( self::PRUNE_HOOK, [ $this, 'run_prune' ] );
		add_action( 'admin_init', [ self::class, 'ensure_scheduled' ] );
	}

	public function schedules( array $schedules ): array {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Hit buffers are flushed every 5 minutes by design; the job is a few cache reads.
		$schedules[ self::INTERVAL ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'wp-redirects' ),
		];
		return $schedules;
	}

	public function run_prune(): int {
		return $this->not_found->prune( (int) Settings::get( 'log_404_retention_days' ), (int) Settings::get( 'log_404_max_rows' ) );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HIT_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::HIT_HOOK );
		}
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HIT_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter 'NotFoundTest|CronTest'`
Expected: `OK (12 tests, …)`

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Tracking/NotFoundRepository.php src/Tracking/NotFoundLogger.php src/Cron.php tests/integration/NotFoundTest.php tests/integration/CronTest.php
git commit -m "feat: add 404 log and scheduled jobs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: SlugWatcher

**Files:**
- Create: `src/SlugWatcher.php`
- Test: `tests/integration/SlugWatcherTest.php`

**Interfaces:**
- Consumes: `Repository::retarget()`, `disable_by_source_key()`, `exact_rule_by_key()`, `update()`, `insert()`; `Validator::validate()`; `Site::internal_path()`; `Settings::get('slug_watcher')`; `PathNormalizer::source_key()`
- Produces: `new SlugWatcher( Repository, Validator )`, `->register(): void`, `->capture( int $post_id ): void`, `->compare( int $post_id, \WP_Post $after, \WP_Post $before ): void`. Filter `adv_redirects_auto_redirect`, action `adv_redirects_auto_redirect_created`.

- [ ] **Step 1: Write the failing test `tests/integration/SlugWatcherTest.php`**

```php
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

	public function test_filter_can_cancel(): void {
		add_filter( 'adv_redirects_auto_redirect', '__return_false' );
		$post_id = self::factory()->post->create( [ 'post_name' => 'keep', 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_name' => 'keep-new' ] );
		$this->assertNull( $this->rule_for( '/keep/' ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter SlugWatcherTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/SlugWatcher.php`**

```php
<?php
/**
 * Creates 301 redirects when a published post's permalink changes.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;

defined( 'ABSPATH' ) || exit;

final class SlugWatcher {

	private Repository $repository;

	private Validator $validator;

	/** @var array<int,array<int,string>> Post ID => [ post or descendant ID => permalink before update ]. */
	private array $pending = [];

	public function __construct( Repository $repository, Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	public function register(): void {
		add_action( 'pre_post_update', [ $this, 'capture' ], 10, 1 );
		add_action( 'post_updated', [ $this, 'compare' ], 10, 3 );
	}

	public function capture( int $post_id ): void {
		if ( ! Settings::get( 'slug_watcher' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::eligible( $post ) ) {
			return;
		}

		$links = [ $post_id => (string) get_permalink( $post ) ];
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$children = get_pages(
				[
					'child_of'    => $post_id,
					'post_type'   => $post->post_type,
					'post_status' => 'publish',
				]
			);
			foreach ( is_array( $children ) ? $children : [] as $child ) {
				$links[ (int) $child->ID ] = (string) get_permalink( $child );
			}
		}
		$this->pending[ $post_id ] = $links;
	}

	public function compare( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( ! isset( $this->pending[ $post_id ] ) ) {
			return;
		}
		$links = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! self::eligible( $after ) ) {
			return;
		}

		foreach ( $links as $id => $old_url ) {
			$this->handle( $old_url, (string) get_permalink( $id ), $after );
		}
	}

	private static function eligible( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return null !== $type && $type->public && is_post_type_viewable( $type );
	}

	private function handle( string $old_url, string $new_url, \WP_Post $post ): void {
		// Plain permalinks (?p=123) never change, so there is nothing to redirect.
		if ( '' === $old_url || '' === $new_url || false !== strpos( $old_url, '?' ) ) {
			return;
		}
		$old = Site::internal_path( $old_url );
		$new = Site::internal_path( $new_url );
		if ( null === $old || null === $new || PathNormalizer::source_key( $old ) === PathNormalizer::source_key( $new ) ) {
			return;
		}

		$data = [
			'type'        => 'exact',
			'source'      => $old,
			'target'      => $new,
			'status_code' => 301,
			'enabled'     => true,
			/* translators: %d: post ID */
			'note'        => sprintf( __( 'Slug changed on post #%d', 'wp-redirects' ), $post->ID ),
		];

		/**
		 * Filters an automatic slug-change redirect before it is saved. Return false to cancel.
		 *
		 * @param array|false $data { type, source, target, status_code, enabled, note }.
		 * @param \WP_Post    $post The post that changed.
		 * @param string      $old  Old path.
		 * @param string      $new  New path.
		 */
		$data = apply_filters( 'adv_redirects_auto_redirect', $data, $post, $old, $new );
		if ( ! is_array( $data ) ) {
			return;
		}

		$this->repository->retarget( $old, $new );
		$this->repository->disable_by_source_key( PathNormalizer::source_key( $new ) );

		$existing = $this->repository->exact_rule_by_key( PathNormalizer::source_key( $old ) );
		if ( null !== $existing ) {
			$this->repository->update(
				$existing->id,
				[
					'target'      => $data['target'],
					'status_code' => 301,
					'enabled'     => true,
				]
			);
			return;
		}

		$result = $this->validator->validate( $data );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$rule = $this->repository->insert( $result['data'] + [ 'origin' => 'auto' ] );
		if ( null !== $rule ) {
			/**
			 * Fires after the slug watcher creates a redirect.
			 *
			 * @param \Advision\Redirects\Redirects\Rule $rule
			 * @param \WP_Post                           $post
			 */
			do_action( 'adv_redirects_auto_redirect_created', $rule, $post );
		}
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter SlugWatcherTest`
Expected: `OK (6 tests, …)`

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/phpcs
git add src/SlugWatcher.php tests/integration/SlugWatcherTest.php
git commit -m "feat: add slug watcher for automatic redirects

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: REST: permissions, redirects and test endpoints

**Files:**
- Create: `src/Permissions.php`, `src/Rest/BaseController.php`, `src/Rest/RedirectsController.php`, `src/Rest/TestController.php`, `tests/integration/support/RestTestCase.php`
- Test: `tests/integration/RestRedirectsTest.php`

**Interfaces:**
- Consumes: `Repository`, `Validator`, `RuleCache`, `ChainResolver`, `Site`, `Settings`, `Matcher`, `PathNormalizer`
- Produces:
  - `Permissions::capability(): string` (filter `adv_redirects_capability`), `Permissions::can_manage(): bool`
  - `abstract BaseController` with `NAMESPACE = 'adv-redirects/v1'` and methods `register_routes(): void`, `permission_check()` (true or `WP_Error` 401/403), `reject_unknown( WP_REST_Request, string[] ): ?WP_Error`, `BaseController::arg( array ): array`
  - `new RedirectsController( Repository, Validator, RuleCache, ChainResolver )` routes:
    - `GET /redirects` → `Rule[]` + `chain`
    - `POST /redirects` → 201 `{ rule, warnings }`
    - `PUT|PATCH|POST /redirects/{id}` → `{ rule, warnings }`
    - `DELETE /redirects/{id}` → `{ deleted: true, rule }`
    - `POST /redirects/bulk` → `{ updated: int, skipped: [{id, code, message}] }`
    - `POST /redirects/reorder` → `{ reordered: int }`
  - `new TestController( RuleCache, ChainResolver )` route `POST /test` → `{ matched, reason, rule_id, type, status, target_url, blocked, hops, final, loop }`

- [ ] **Step 1: Create the REST test base `tests/integration/support/RestTestCase.php`**

```php
<?php

use Advision\Redirects\Plugin;

/**
 * Base class for REST tests. Registers controllers directly until Plugin wires
 * them (Task 15); after that it relies on the plugin's own registration.
 */
abstract class Adv_Redirects_Rest_TestCase extends WP_UnitTestCase {

	protected static $admin_id;

	protected static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id  = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$editor_id = $factory->user->create( [ 'role' => 'editor' ] );
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', [ $this, 'register_controllers' ] );
		rest_get_server();
		wp_set_current_user( self::$admin_id );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * @return object[] Controllers exposing register_routes().
	 */
	abstract protected function controllers(): array;

	public function register_controllers(): void {
		if ( method_exists( Plugin::class, 'register_routes' ) && false !== has_action( 'rest_api_init', [ Plugin::instance(), 'register_routes' ] ) ) {
			return;
		}
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	protected function rest( string $method, string $route, ?array $body = null, array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/adv-redirects/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}
}
```

- [ ] **Step 2: Write the failing test `tests/integration/RestRedirectsTest.php`**

```php
<?php

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\RedirectsController;
use Advision\Redirects\Rest\TestController;

final class RestRedirectsTest extends Adv_Redirects_Rest_TestCase {

	protected function controllers(): array {
		$repo   = new Repository();
		$chains = new ChainResolver();
		$cache  = new RuleCache( $repo );
		return [
			new RedirectsController( $repo, new Validator( $repo, $chains ), $cache, $chains ),
			new TestController( $cache, $chains ),
		];
	}

	private function create( array $body ): WP_REST_Response {
		return $this->rest( 'POST', '/redirects', $body );
	}

	public function test_permission_matrix(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/redirects' )->get_status() );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'GET', '/redirects' )->get_status() );
		$this->assertSame( 403, $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_status() );

		wp_set_current_user( self::$admin_id );
		$this->assertSame( 200, $this->rest( 'GET', '/redirects' )->get_status() );
	}

	public function test_capability_filter(): void {
		add_filter(
			'adv_redirects_capability',
			static function () {
				return 'edit_posts';
			}
		);
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 200, $this->rest( 'GET', '/redirects' )->get_status() );
	}

	public function test_create_and_list(): void {
		$response = $this->create( [ 'type' => 'exact', 'source' => '/old', 'target' => '/new', 'status_code' => 301, 'note' => 'hi' ] );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '/old', $data['rule']['source'] );
		$this->assertSame( [], $data['warnings'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( 'hi', $list[0]['note'] );
		$this->assertNull( $list[0]['chain'] );
	}

	public function test_schema_and_unknown_fields_rejected(): void {
		$this->assertSame( 400, $this->create( [ 'type' => 'glob', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 303 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'source' => str_repeat( 'a', 2049 ), 'target' => '/b', 'status_code' => 301 ] )->get_status() );
		$this->assertSame( 400, $this->create( [ 'type' => 'exact', 'target' => '/b', 'status_code' => 301 ] )->get_status() );

		$response = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301, 'hits' => 5000 ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'adv_redirects_unknown_field', $response->get_data()['code'] );
	}

	public function test_validator_errors_surface_with_codes(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );

		$dupe = $this->create( [ 'type' => 'exact', 'source' => '/A/', 'target' => '/c', 'status_code' => 301 ] );
		$this->assertSame( 409, $dupe->get_status() );
		$this->assertSame( 'adv_redirects_duplicate', $dupe->get_data()['code'] );

		$loop = $this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301 ] );
		$this->assertSame( 422, $loop->get_status() );
		$this->assertSame( 'adv_redirects_loop', $loop->get_data()['code'] );

		$unsafe = $this->create( [ 'type' => 'exact', 'source' => '/x', 'target' => '//evil.com', 'status_code' => 301 ] );
		$this->assertSame( 'adv_redirects_invalid_target', $unsafe->get_data()['code'] );
	}

	public function test_chain_warning_and_list_chain_info(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 301 ] );
		$response = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->assertSame( 'chain', $response->get_data()['warnings'][0]['code'] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$a    = current( wp_list_filter( $list, [ 'source' => '/a' ] ) );
		$this->assertSame( '/c', $a['chain']['final'] );
	}

	public function test_update_and_delete(): void {
		$id = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$updated = $this->rest( 'PUT', "/redirects/{$id}", [ 'target' => '/c', 'enabled' => false ] );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( '/c', $updated->get_data()['rule']['target'] );
		$this->assertFalse( $updated->get_data()['rule']['enabled'] );

		$this->assertSame( 404, $this->rest( 'PUT', '/redirects/999999', [ 'target' => '/c' ] )->get_status() );

		$deleted = $this->rest( 'DELETE', "/redirects/{$id}" );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'DELETE', "/redirects/{$id}" )->get_status() );
	}

	public function test_bulk_enable_skips_rules_that_would_loop(): void {
		$a = $this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] )->get_data()['rule']['id'];
		$b = $this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/a', 'status_code' => 301, 'enabled' => false ] )->get_data()['rule']['id'];

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'enable', 'ids' => [ $b ] ] )->get_data();
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 'adv_redirects_loop', $result['skipped'][0]['code'] );

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'disable', 'ids' => [ $a ] ] )->get_data();
		$this->assertSame( 1, $result['updated'] );

		$result = $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'delete', 'ids' => [ $a, $b ] ] )->get_data();
		$this->assertSame( 2, $result['updated'] );
		$this->assertSame( [], $this->rest( 'GET', '/redirects' )->get_data() );
	}

	public function test_bulk_limits(): void {
		$this->assertSame( 400, $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'enable', 'ids' => range( 1, 501 ) ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/redirects/bulk', [ 'action' => 'explode', 'ids' => [ 1 ] ] )->get_status() );
	}

	public function test_reorder(): void {
		$x = $this->create( [ 'type' => 'regex', 'source' => '^/x', 'target' => '/x1', 'status_code' => 301 ] )->get_data()['rule']['id'];
		$y = $this->create( [ 'type' => 'regex', 'source' => '^/y', 'target' => '/y1', 'status_code' => 301 ] )->get_data()['rule']['id'];

		$this->rest( 'POST', '/redirects/reorder', [ 'ids' => [ $y, $x ] ] );

		$list = $this->rest( 'GET', '/redirects' )->get_data();
		$this->assertSame( [ $y, $x ], array_column( wp_list_filter( $list, [ 'type' => 'regex' ] ), 'id' ) );
	}

	public function test_test_endpoint(): void {
		$this->create( [ 'type' => 'exact', 'source' => '/a', 'target' => '/b', 'status_code' => 301 ] );
		$this->create( [ 'type' => 'exact', 'source' => '/b', 'target' => '/c', 'status_code' => 302 ] );

		$hit = $this->rest( 'POST', '/test', [ 'path' => '/a?x=1' ] )->get_data();
		$this->assertTrue( $hit['matched'] );
		$this->assertSame( 301, $hit['status'] );
		$this->assertSame( 'http://example.org/b?x=1', $hit['target_url'] );
		// Hops after the first resolved URL are the raw rule targets.
		$this->assertSame( [ '/a?x=1', 'http://example.org/b?x=1', '/c' ], $hit['hops'] );
		$this->assertSame( '/c', $hit['final'] );
		$this->assertFalse( $hit['loop'] );

		$miss = $this->rest( 'POST', '/test', [ 'path' => '/nothing' ] )->get_data();
		$this->assertFalse( $miss['matched'] );
		$this->assertSame( 'no_match', $miss['reason'] );

		$external = $this->rest( 'POST', '/test', [ 'path' => 'https://other.org/a' ] )->get_data();
		$this->assertSame( 'external', $external['reason'] );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'POST', '/test', [ 'path' => '/a' ] )->get_status() );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter RestRedirectsTest`
Expected: FAIL, class not found.

- [ ] **Step 4: Implement `src/Permissions.php`**

```php
<?php
/**
 * Capability checks.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	public static function capability(): string {
		/**
		 * Filters the capability required to manage redirects, the 404 log and settings.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'adv_redirects_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	public static function can_manage(): bool {
		return current_user_can( self::capability() );
	}
}
```

- [ ] **Step 5: Implement `src/Rest/BaseController.php`**

```php
<?php
/**
 * Shared REST controller behavior.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Permissions;

defined( 'ABSPATH' ) || exit;

abstract class BaseController {

	public const NAMESPACE = 'adv-redirects/v1';

	abstract public function register_routes(): void;

	/**
	 * @return true|\WP_Error
	 */
	public function permission_check() {
		if ( Permissions::can_manage() ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirects' ),
			[ 'status' => is_user_logged_in() ? 403 : 401 ]
		);
	}

	/**
	 * Rejects body fields that are not in the allowlist.
	 *
	 * @param string[] $allowed Allowed body field names.
	 */
	protected function reject_unknown( \WP_REST_Request $request, array $allowed ): ?\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$unknown = array_diff( array_keys( (array) $body ), $allowed );
		if ( empty( $unknown ) ) {
			return null;
		}
		return new \WP_Error(
			'adv_redirects_unknown_field',
			/* translators: %s: comma-separated field names */
			sprintf( __( 'Unknown field(s): %s', 'wp-redirects' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Adds core schema validation and sanitization to an argument definition.
	 */
	public static function arg( array $schema ): array {
		return $schema + [
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		];
	}
}
```

- [ ] **Step 6: Implement `src/Rest/RedirectsController.php`**

```php
<?php
/**
 * REST endpoints for redirect rules.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Rule;
use Advision\Redirects\Redirects\Validator;

defined( 'ABSPATH' ) || exit;

final class RedirectsController extends BaseController {

	private const FIELDS = [ 'type', 'source', 'target', 'status_code', 'enabled', 'note' ];

	private Repository $repository;
	private Validator $validator;
	private RuleCache $cache;
	private ChainResolver $chains;

	public function __construct( Repository $repository, Validator $validator, RuleCache $cache, ChainResolver $chains ) {
		$this->repository = $repository;
		$this->validator  = $validator;
		$this->cache      = $cache;
		$this->chains     = $chains;
	}

	public function register_routes(): void {
		$id_arg = [
			'id' => self::arg(
				[
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				]
			),
		];

		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $this->rule_args( true ),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $id_arg + $this->rule_args( false ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $id_arg,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'action' => self::arg(
						[
							'type'     => 'string',
							'enum'     => [ 'enable', 'disable', 'delete' ],
							'required' => true,
						]
					),
					'ids'    => self::ids_arg(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [ 'ids' => self::ids_arg() ],
			]
		);
	}

	public function list_items(): \WP_REST_Response {
		$ruleset = $this->cache->get();
		$items   = array_map(
			function ( Rule $rule ) use ( $ruleset ): array {
				return $this->prepare( $rule, $ruleset );
			},
			$this->repository->all()
		);
		return rest_ensure_response( $items );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, self::FIELDS );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$result = $this->validator->validate( $this->input( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rule = $this->repository->insert( $result['data'] );
		if ( null === $rule ) {
			return self::db_error();
		}

		$response = rest_ensure_response(
			[
				'rule'     => $this->prepare( $rule, $this->cache->get() ),
				'warnings' => $result['warnings'],
			]
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, self::FIELDS );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$id     = (int) $request['id'];
		$result = $this->validator->validate( $this->input( $request ), $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rule = $this->repository->update( $id, $result['data'] );
		if ( null === $rule ) {
			return self::db_error();
		}

		return rest_ensure_response(
			[
				'rule'     => $this->prepare( $rule, $this->cache->get() ),
				'warnings' => $result['warnings'],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		$rule = $this->repository->find( (int) $request['id'] );
		if ( null === $rule || ! $this->repository->delete( $rule->id ) ) {
			return new \WP_Error( 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response(
			[
				'deleted' => true,
				'rule'    => $rule->to_array(),
			]
		);
	}

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		$action  = (string) $request['action'];
		$ids     = array_values( array_unique( array_map( 'intval', (array) $request['ids'] ) ) );
		$updated = 0;
		$skipped = [];

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				if ( $this->repository->delete( $id ) ) {
					++$updated;
				} else {
					$skipped[] = self::skip( $id, 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ) );
				}
				continue;
			}

			$enable = 'enable' === $action;
			if ( $enable ) {
				$valid = $this->validator->validate( [ 'enabled' => true ], $id );
				if ( is_wp_error( $valid ) ) {
					$skipped[] = self::skip( $id, (string) $valid->get_error_code(), $valid->get_error_message() );
					continue;
				}
			}
			if ( null !== $this->repository->update( $id, [ 'enabled' => $enable ] ) ) {
				++$updated;
			} else {
				$skipped[] = self::skip( $id, 'adv_redirects_not_found', __( 'Redirect not found.', 'wp-redirects' ) );
			}
		}

		return rest_ensure_response(
			[
				'updated' => $updated,
				'skipped' => $skipped,
			]
		);
	}

	public function reorder( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = array_map( 'intval', (array) $request['ids'] );
		$this->repository->reorder( $ids );
		return rest_ensure_response( [ 'reordered' => count( $ids ) ] );
	}

	private function input( \WP_REST_Request $request ): array {
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : $request->get_body_params();
		$input = [];
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, (array) $body ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}
		return $input;
	}

	private function prepare( Rule $rule, array $ruleset ): array {
		$data          = $rule->to_array();
		$data['chain'] = null;

		if ( $rule->enabled && null !== $rule->target && ! preg_match( '/\$[1-9]/', $rule->target ) ) {
			$chain = $this->chains->resolve(
				$rule->source,
				$rule->target,
				$ruleset,
				'exact' === $rule->type ? PathNormalizer::source_key( $rule->source ) : null
			);
			if ( $chain['loop'] || count( $chain['hops'] ) > 2 ) {
				$data['chain'] = $chain;
			}
		}
		return $data;
	}

	private function rule_args( bool $create ): array {
		$args = [
			'type'        => [
				'type' => 'string',
				'enum' => Validator::TYPES,
			],
			'source'      => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2048,
			],
			'target'      => [
				'type'      => [ 'string', 'null' ],
				'maxLength' => 2048,
			],
			'status_code' => [
				'type' => 'integer',
				'enum' => Validator::STATUSES,
			],
			'enabled'     => [ 'type' => 'boolean' ],
			'note'        => [
				'type'      => 'string',
				'maxLength' => 255,
			],
		];
		if ( $create ) {
			$args['type']['required']        = true;
			$args['source']['required']      = true;
			$args['status_code']['required'] = true;
		}
		return array_map( [ self::class, 'arg' ], $args );
	}

	private static function ids_arg(): array {
		return self::arg(
			[
				'type'     => 'array',
				'items'    => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'minItems' => 1,
				'maxItems' => 500,
				'required' => true,
			]
		);
	}

	private static function skip( int $id, string $code, string $message ): array {
		return [
			'id'      => $id,
			'code'    => $code,
			'message' => $message,
		];
	}

	private static function db_error(): \WP_Error {
		return new \WP_Error( 'adv_redirects_db_error', __( 'The redirect could not be saved.', 'wp-redirects' ), [ 'status' => 500 ] );
	}
}
```

- [ ] **Step 7: Implement `src/Rest/TestController.php`**

```php
<?php
/**
 * REST endpoint for the Test URL tool. Uses the same Matcher and resolver as real requests.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Matching\Matcher;
use Advision\Redirects\Matching\PathNormalizer;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Settings;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class TestController extends BaseController {

	private RuleCache $cache;

	private ChainResolver $chains;

	public function __construct( RuleCache $cache, ChainResolver $chains ) {
		$this->cache  = $cache;
		$this->chains = $chains;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test_url' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'path' => self::arg(
						[
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2048,
							'required'  => true,
						]
					),
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_url( \WP_REST_Request $request ) {
		$input    = trim( (string) $request['path'] );
		$internal = Site::internal_path( $input );
		if ( null === $internal ) {
			return rest_ensure_response( self::result( [ 'reason' => 'external' ] ) );
		}

		$normalized = ( new PathNormalizer( '' ) )->from_request_uri( $internal );
		if ( null === $normalized ) {
			return new \WP_Error( 'adv_redirects_invalid_path', __( 'That path is not valid.', 'wp-redirects' ), [ 'status' => 400 ] );
		}

		$ruleset = $this->cache->get();
		$match   = ( new Matcher( $ruleset ) )->match( $normalized );
		if ( null === $match ) {
			return rest_ensure_response( self::result( [ 'reason' => 'no_match' ] ) );
		}

		$base = [
			'matched' => true,
			'reason'  => null,
			'rule_id' => $match->rule_id,
			'type'    => $match->type,
			'status'  => $match->status,
			'hops'    => [ $input ],
		];
		if ( null === $match->target ) {
			return rest_ensure_response( self::result( $base ) );
		}

		$url = Site::resolver()->resolve( $match->target, $match->captures, $normalized['query'], (bool) Settings::get( 'forward_query_string' ) );
		if ( null === $url ) {
			return rest_ensure_response( self::result( $base + [ 'blocked' => true ] ) );
		}

		$key   = $normalized['key'] . ( '' !== $normalized['query'] ? '?' . $normalized['query'] : '' );
		$chain = $this->chains->resolve( $input, $url, $ruleset, $key );

		return rest_ensure_response(
			self::result(
				[
					'target_url' => $url,
					'hops'       => $chain['hops'],
					'final'      => $chain['final'],
					'loop'       => $chain['loop'],
				] + $base
			)
		);
	}

	private static function result( array $values ): array {
		return array_merge(
			[
				'matched'    => false,
				'reason'     => null,
				'rule_id'    => null,
				'type'       => null,
				'status'     => null,
				'target_url' => null,
				'blocked'    => false,
				'hops'       => [],
				'final'      => null,
				'loop'       => false,
			],
			$values
		);
	}
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter RestRedirectsTest`
Expected: `OK (11 tests, …)`

- [ ] **Step 9: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Permissions.php src/Rest tests/integration/support/RestTestCase.php tests/integration/RestRedirectsTest.php
git commit -m "feat: add REST endpoints for redirects and URL testing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: REST: 404 log and settings endpoints

**Files:**
- Create: `src/Rest/NotFoundController.php`, `src/Rest/SettingsController.php`
- Test: `tests/integration/RestNotFoundSettingsTest.php`

**Interfaces:**
- Consumes: `BaseController`, `NotFoundRepository`, `Settings`
- Produces:
  - `new NotFoundController( NotFoundRepository )` routes:
    - `GET /404s` (`page`, `per_page` ≤100, `search` ≤200, `orderby`, `order`) returns the items array with `X-WP-Total` and `X-WP-TotalPages` headers
    - `DELETE /404s/{id}` → `{ deleted: true }`
    - `POST /404s/bulk` `{ action: 'delete', ids }` → `{ deleted: int }`
    - `DELETE /404s` → `{ deleted: int }`
  - `new SettingsController()` routes `GET /settings` and `PUT|PATCH|POST /settings` → full settings object

- [ ] **Step 1: Write the failing test `tests/integration/RestNotFoundSettingsTest.php`**

```php
<?php

use Advision\Redirects\Rest\NotFoundController;
use Advision\Redirects\Rest\SettingsController;
use Advision\Redirects\Tracking\NotFoundRepository;

final class RestNotFoundSettingsTest extends Adv_Redirects_Rest_TestCase {

	protected function controllers(): array {
		return [ new NotFoundController( new NotFoundRepository() ), new SettingsController() ];
	}

	private function seed(): void {
		$repo = new NotFoundRepository();
		foreach ( [ '/a', '/b', '/b', '/c' ] as $path ) {
			$repo->log( $path, '', current_time( 'mysql', true ) );
		}
	}

	public function test_list_with_paging_headers(): void {
		$this->seed();
		$response = $this->rest( 'GET', '/404s', null, [ 'per_page' => 2 ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '/b', $response->get_data()[0]['path'] );
		$this->assertSame( 3, $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_list_rejects_bad_params(): void {
		$this->assertSame( 400, $this->rest( 'GET', '/404s', null, [ 'per_page' => 500 ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', '/404s', null, [ 'orderby' => 'id' ] )->get_status() );
	}

	public function test_delete_bulk_and_clear(): void {
		$this->seed();
		$ids = array_column( $this->rest( 'GET', '/404s' )->get_data(), 'id' );

		$this->assertTrue( $this->rest( 'DELETE', '/404s/' . $ids[0] )->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'DELETE', '/404s/' . $ids[0] )->get_status() );
		$this->assertSame( 1, $this->rest( 'POST', '/404s/bulk', [ 'action' => 'delete', 'ids' => [ $ids[1] ] ] )->get_data()['deleted'] );
		$this->assertSame( 1, $this->rest( 'DELETE', '/404s' )->get_data()['deleted'] );
	}

	public function test_settings_read_and_update(): void {
		$this->assertTrue( $this->rest( 'GET', '/settings' )->get_data()['log_404'] );

		$updated = $this->rest( 'PUT', '/settings', [ 'log_404' => false, 'log_404_retention_days' => 7, 'excluded_404_extensions' => [ 'css', 'PDF' ] ] )->get_data();
		$this->assertFalse( $updated['log_404'] );
		$this->assertSame( 7, $updated['log_404_retention_days'] );
		$this->assertSame( [ 'css', 'pdf' ], $updated['excluded_404_extensions'] );
	}

	public function test_settings_validation(): void {
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'log_404_retention_days' => 0 ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'excluded_404_extensions' => [ '../x' ] ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', [ 'evil' => true ] )->get_status() );
	}

	public function test_permissions(): void {
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 403, $this->rest( 'GET', '/404s' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->rest( 'DELETE', '/404s' )->get_status() );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter RestNotFoundSettingsTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Rest/NotFoundController.php`**

```php
<?php
/**
 * REST endpoints for the 404 log.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Tracking\NotFoundRepository;

defined( 'ABSPATH' ) || exit;

final class NotFoundController extends BaseController {

	private NotFoundRepository $repository;

	public function __construct( NotFoundRepository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/404s',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'page'     => self::arg(
							[
								'type'    => 'integer',
								'minimum' => 1,
								'default' => 1,
							]
						),
						'per_page' => self::arg(
							[
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 20,
							]
						),
						'search'   => self::arg(
							[
								'type'      => 'string',
								'maxLength' => 200,
								'default'   => '',
							]
						),
						'orderby'  => self::arg(
							[
								'type'    => 'string',
								'enum'    => [ 'hits', 'last_seen', 'path' ],
								'default' => 'hits',
							]
						),
						'order'    => self::arg(
							[
								'type'    => 'string',
								'enum'    => [ 'asc', 'desc' ],
								'default' => 'desc',
							]
						),
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/404s/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'id' => self::arg(
						[
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						]
					),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/404s/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'action' => self::arg(
						[
							'type'     => 'string',
							'enum'     => [ 'delete' ],
							'required' => true,
						]
					),
					'ids'    => self::arg(
						[
							'type'     => 'array',
							'items'    => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'minItems' => 1,
							'maxItems' => 500,
							'required' => true,
						]
					),
				],
			]
		);
	}

	public function list_items( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = $this->repository->query(
			[
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
				'search'   => (string) $request['search'],
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
			]
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		if ( ! $this->repository->delete( (int) $request['id'] ) ) {
			return new \WP_Error( 'adv_redirects_not_found', __( 'Log entry not found.', 'wp-redirects' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [ 'deleted' => $this->repository->delete_many( (array) $request['ids'] ) ] );
	}

	public function clear(): \WP_REST_Response {
		return rest_ensure_response( [ 'deleted' => $this->repository->clear() ] );
	}
}
```

- [ ] **Step 4: Implement `src/Rest/SettingsController.php`**

```php
<?php
/**
 * REST endpoints for plugin settings.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => array_map(
						[ self::class, 'arg' ],
						[
							'slug_watcher'             => [ 'type' => 'boolean' ],
							'log_404'                  => [ 'type' => 'boolean' ],
							'forward_query_string'     => [ 'type' => 'boolean' ],
							'remove_data_on_uninstall' => [ 'type' => 'boolean' ],
							'log_404_retention_days'   => [
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 365,
							],
							'log_404_max_rows'         => [
								'type'    => 'integer',
								'minimum' => 100,
								'maximum' => 100000,
							],
							'excluded_404_extensions'  => [
								'type'     => 'array',
								'items'    => [
									'type'    => 'string',
									'pattern' => '^[A-Za-z0-9]{1,10}$',
								],
								'maxItems' => 50,
							],
						]
					),
				],
			]
		);
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( Settings::all() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, array_keys( Settings::defaults() ) );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$input = [];
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		return rest_ensure_response( Settings::update( $input ) );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:php:integration -- --filter RestNotFoundSettingsTest`
Expected: `OK (6 tests, …)`

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Rest/NotFoundController.php src/Rest/SettingsController.php tests/integration/RestNotFoundSettingsTest.php
git commit -m "feat: add REST endpoints for 404 log and settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Wire the plugin, PHP API, uninstall, update checker, hook docs

**Files:**
- Modify (full replace): `src/Plugin.php`, `wp-redirects.php`
- Create: `src/functions.php`, `src/Uninstaller.php`, `uninstall.php`, `src/Updates/UpdateChecker.php`, `docs/hooks.md`
- Test: `tests/integration/PluginTest.php`

**Interfaces:**
- Consumes: every class from Tasks 2–14
- Produces:
  - `Plugin` methods:
    - `instance()`, `boot()`, `loaded()`, `register_routes()`
    - `Plugin::activate()`, `Plugin::deactivate()`
    - getters `repository(): Repository`, `rule_cache(): RuleCache`, `validator(): Validator`, `chains(): ChainResolver`, `hit_tracker(): HitTracker`, `not_found(): NotFoundRepository`, `redirector(): Redirector`, `not_found_logger(): NotFoundLogger`
  - Functions:
    - `adv_redirects_add( array $data )`: rule array or `WP_Error`
    - `adv_redirects_delete( int $id ): bool`
    - `adv_redirects_flush_cache(): void`
  - `Uninstaller::should_remove(): bool`, `Uninstaller::run(): void`
  - `UpdateChecker::REPOSITORY`, `UpdateChecker::boot( string $plugin_file ): void`
  - Action `adv_redirects_loaded` (Plugin), fired on `plugins_loaded` priority 20

- [ ] **Step 1: Write the failing test `tests/integration/PluginTest.php`**

```php
<?php

use Advision\Redirects\Cron;
use Advision\Redirects\Plugin;
use Advision\Redirects\Settings;
use Advision\Redirects\Uninstaller;

final class PluginTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Cron::unschedule();
		parent::tear_down();
	}

	public function test_runtime_hooks_are_registered(): void {
		$plugin = Plugin::instance();
		$this->assertSame( 1, has_action( 'init', [ $plugin->redirector(), 'maybe_redirect' ] ) );
		$this->assertSame( 0, has_action( 'template_redirect', [ $plugin->redirector(), 'maybe_send_gone' ] ) );
		$this->assertSame( 99, has_action( 'template_redirect', [ $plugin->not_found_logger(), 'maybe_log' ] ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ $plugin, 'register_routes' ] ) );
		$this->assertNotFalse( has_action( 'post_updated' ) );
		$this->assertSame( 1, did_action( 'adv_redirects_loaded' ) );
	}

	public function test_routes_are_registered(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		$routes         = rest_get_server()->get_routes();
		foreach ( [ '/redirects', '/redirects/(?P<id>\d+)', '/redirects/bulk', '/redirects/reorder', '/test', '/404s', '/404s/(?P<id>\d+)', '/404s/bulk', '/settings' ] as $route ) {
			$this->assertArrayHasKey( '/adv-redirects/v1' . $route, $routes, $route );
		}
		$wp_rest_server = null;
	}

	public function test_php_api(): void {
		$rule = adv_redirects_add( [ 'type' => 'exact', 'source' => '/api-old', 'target' => '/api-new', 'status_code' => 302 ] );
		$this->assertIsArray( $rule );
		$this->assertSame( '/api-old', $rule['source'] );

		$this->assertWPError( adv_redirects_add( [ 'type' => 'exact', 'source' => '/api-old', 'target' => '/x', 'status_code' => 301 ] ) );

		$before = did_action( 'adv_redirects_cache_flushed' );
		adv_redirects_flush_cache();
		$this->assertSame( $before + 1, did_action( 'adv_redirects_cache_flushed' ) );

		$this->assertTrue( adv_redirects_delete( $rule['id'] ) );
		$this->assertFalse( adv_redirects_delete( $rule['id'] ) );
	}

	public function test_activation_schedules_cron(): void {
		Plugin::activate();
		$this->assertNotFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( Cron::HIT_HOOK ) );
	}

	public function test_uninstall_is_opt_in(): void {
		$this->assertFalse( Uninstaller::should_remove() );
		Settings::update( [ 'remove_data_on_uninstall' => true ] );
		$this->assertTrue( Uninstaller::should_remove() );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter PluginTest`
Expected: FAIL, `Call to undefined method Advision\Redirects\Plugin::redirector()`.

- [ ] **Step 3: Replace `src/Plugin.php`**

```php
<?php
/**
 * Plugin service container and hook wiring.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\Redirector;
use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Redirects\ChainResolver;
use Advision\Redirects\Redirects\Repository;
use Advision\Redirects\Redirects\Validator;
use Advision\Redirects\Rest\NotFoundController;
use Advision\Redirects\Rest\RedirectsController;
use Advision\Redirects\Rest\SettingsController;
use Advision\Redirects\Rest\TestController;
use Advision\Redirects\Tracking\HitTracker;
use Advision\Redirects\Tracking\NotFoundLogger;
use Advision\Redirects\Tracking\NotFoundRepository;
use Advision\Redirects\Updates\UpdateChecker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private Repository $repository;
	private RuleCache $rule_cache;
	private ChainResolver $chains;
	private Validator $validator;
	private HitTracker $hit_tracker;
	private NotFoundRepository $not_found;
	private Redirector $redirector;
	private NotFoundLogger $not_found_logger;
	private SlugWatcher $slug_watcher;
	private Cron $cron;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository       = new Repository();
		$this->rule_cache       = new RuleCache( $this->repository );
		$this->chains           = new ChainResolver();
		$this->validator        = new Validator( $this->repository, $this->chains );
		$this->hit_tracker      = new HitTracker( $this->repository );
		$this->not_found        = new NotFoundRepository();
		$this->redirector       = new Redirector( $this->rule_cache, $this->hit_tracker );
		$this->not_found_logger = new NotFoundLogger( $this->not_found, $this->redirector );
		$this->slug_watcher     = new SlugWatcher( $this->repository, $this->validator );
		$this->cron             = new Cron( $this->hit_tracker, $this->not_found );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', [ Schema::class, 'maybe_upgrade' ] );
		add_action( 'plugins_loaded', [ $this, 'loaded' ], 20 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		$this->redirector->register();
		$this->not_found_logger->register();
		$this->slug_watcher->register();
		$this->cron->register();

		UpdateChecker::boot( ADV_REDIRECTS_FILE );
	}

	public function loaded(): void {
		/**
		 * Fires once WP Redirects is loaded.
		 *
		 * @param Plugin $plugin Service container.
		 */
		do_action( 'adv_redirects_loaded', $this );
	}

	public function register_routes(): void {
		$controllers = [
			new RedirectsController( $this->repository, $this->validator, $this->rule_cache, $this->chains ),
			new TestController( $this->rule_cache, $this->chains ),
			new NotFoundController( $this->not_found ),
			new SettingsController(),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	public static function activate(): void {
		Schema::install();
		Cron::ensure_scheduled();
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function rule_cache(): RuleCache {
		return $this->rule_cache;
	}

	public function validator(): Validator {
		return $this->validator;
	}

	public function chains(): ChainResolver {
		return $this->chains;
	}

	public function hit_tracker(): HitTracker {
		return $this->hit_tracker;
	}

	public function not_found(): NotFoundRepository {
		return $this->not_found;
	}

	public function redirector(): Redirector {
		return $this->redirector;
	}

	public function not_found_logger(): NotFoundLogger {
		return $this->not_found_logger;
	}
}
```

- [ ] **Step 4: Create `src/functions.php`**

```php
<?php
/**
 * Public PHP API.
 *
 * @package Advision\Redirects
 */

use Advision\Redirects\Matching\RuleCache;
use Advision\Redirects\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'adv_redirects_add' ) ) {
	/**
	 * Creates a redirect using the same validation as the admin and REST API.
	 *
	 * @param array $data { type: 'exact'|'regex', source: string, target: ?string, status_code: int, enabled?: bool, note?: string }.
	 * @return array|WP_Error The rule as an array, or the validation error.
	 */
	function adv_redirects_add( array $data ) {
		$plugin = Plugin::instance();
		$result = $plugin->validator()->validate( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rule = $plugin->repository()->insert( $result['data'] );
		if ( null === $rule ) {
			return new WP_Error( 'adv_redirects_db_error', __( 'The redirect could not be saved.', 'wp-redirects' ) );
		}
		return $rule->to_array();
	}
}

if ( ! function_exists( 'adv_redirects_delete' ) ) {
	/**
	 * Deletes a redirect.
	 */
	function adv_redirects_delete( int $id ): bool {
		return Plugin::instance()->repository()->delete( $id );
	}
}

if ( ! function_exists( 'adv_redirects_flush_cache' ) ) {
	/**
	 * Clears the compiled rule set cache.
	 */
	function adv_redirects_flush_cache(): void {
		RuleCache::flush();
	}
}
```

- [ ] **Step 5: Create `src/Uninstaller.php` and `uninstall.php`**

`src/Uninstaller.php`:
```php
<?php
/**
 * Data removal on uninstall (opt-in).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects;

use Advision\Redirects\Matching\RuleCache;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	public static function should_remove(): bool {
		$settings = get_option( Settings::OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );
	}

	public static function run(): void {
		if ( ! self::should_remove() ) {
			return;
		}
		Cron::unschedule();
		Schema::drop_all();
		delete_option( Settings::OPTION );
		delete_option( Schema::VERSION_OPTION );
		RuleCache::flush();
	}
}
```

`uninstall.php`:
```php
<?php
/**
 * Uninstall handler. Removes data only when "Remove all data on uninstall" is enabled.
 *
 * @package Advision\Redirects
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Advision\Redirects\Autoloader::register( __DIR__ . '/src' );

\Advision\Redirects\Uninstaller::run();
```

- [ ] **Step 6: Create `src/Updates/UpdateChecker.php`**

```php
<?php
/**
 * Self-updates from this repository's GitHub Releases (built zip asset only).
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Updates;

defined( 'ABSPATH' ) || exit;

final class UpdateChecker {

	public const REPOSITORY = 'https://github.com/advision-development/wp-redirects/';

	public static function boot( string $plugin_file ): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$autoload = dirname( $plugin_file ) . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = $factory::buildUpdateChecker( self::REPOSITORY, $plugin_file, 'wp-redirects' );
		$api     = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			// Never fall back to GitHub's source zip: it has no build/ or vendor/.
			$require = get_class( $api ) . '::REQUIRE_RELEASE_ASSETS';
			if ( defined( $require ) ) {
				$api->enableReleaseAssets( '/^wp-redirects-\d+\.\d+\.\d+\.zip$/', constant( $require ) );
			} else {
				$api->enableReleaseAssets( '/^wp-redirects-\d+\.\d+\.\d+\.zip$/' );
			}
		}
	}
}
```

- [ ] **Step 7: Replace `wp-redirects.php`** (header unchanged; adds the API file and activation hooks)

```php
<?php
/**
 * Plugin Name:       WP Redirects
 * Plugin URI:        https://github.com/advision-development/wp-redirects
 * Description:       Exact and regex redirects with selectable status codes, object-cached matching, hit counts, slug-change redirects and a 404 log.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-redirects
 *
 * @package Advision\Redirects
 */

defined( 'ABSPATH' ) || exit;

define( 'ADV_REDIRECTS_VERSION', '0.1.0' );
define( 'ADV_REDIRECTS_FILE', __FILE__ );
define( 'ADV_REDIRECTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADV_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

require_once ADV_REDIRECTS_DIR . 'src/Autoloader.php';
\Advision\Redirects\Autoloader::register( ADV_REDIRECTS_DIR . 'src' );
require_once ADV_REDIRECTS_DIR . 'src/functions.php';

register_activation_hook( __FILE__, [ \Advision\Redirects\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Advision\Redirects\Plugin::class, 'deactivate' ] );

\Advision\Redirects\Plugin::instance()->boot();
```

- [ ] **Step 8: Create `docs/hooks.md`**

````markdown
# WP Redirects: hooks reference

All hooks use the `adv_redirects_` prefix. Rule arrays passed to request-time hooks have the shape `{ id, type, target, status }`. `Rule` objects (`Advision\Redirects\Redirects\Rule`) expose `id, type, source, target, status_code, position, enabled, origin, note, hits, last_hit_at, created_at, updated_at`.

## Filters

| Filter | Arguments | Default | Purpose |
|---|---|---|---|
| `adv_redirects_capability` | `string $cap` | `manage_options` | Capability for the admin screen and every REST route. |
| `adv_redirects_should_handle_request` | `bool $handle, string $path` | `true` | Return false to skip matching for a request. |
| `adv_redirects_allowed_methods` | `string[] $methods` | `['GET','HEAD']` | HTTP methods that are redirected. |
| `adv_redirects_request_path` | `string $path` | n/a | Alter the decoded, home-relative path before matching. Must start with `/`. |
| `adv_redirects_match` | `?MatchResult $match, string $path, string $query` | n/a | Override or suppress (return `null`) the match. |
| `adv_redirects_status_code` | `int $code, array $rule` | rule status | Change the status. Unsupported codes cancel the redirect. |
| `adv_redirects_forward_query_string` | `bool $forward, array $rule` | setting | Forward the incoming query string to the target. |
| `adv_redirects_target_url` | `string $url, array $rule, string $path` | n/a | Change the final URL. Unsafe URLs cancel the redirect. |
| `adv_redirects_allowed_target_hosts` | `string[] $hosts` | `[]` (any) | Allowlist for external target hosts. |
| `adv_redirects_compiled_ruleset` | `array $ruleset` | n/a | Alter the compiled rule set before it is cached. |
| `adv_redirects_validate_rule` | `true\|WP_Error $valid, array $data, ?int $id` | `true` | Return a `WP_Error` to reject a rule. |
| `adv_redirects_auto_redirect` | `array\|false $data, WP_Post $post, string $old, string $new` | n/a | Modify or cancel (`false`) slug-watcher redirects. |
| `adv_redirects_hit_tracking_enabled` | `bool $enabled` | `true` | Disable hit counting. |
| `adv_redirects_log_404` | `bool $log, string $path` | `true` | Skip logging specific 404s. |
| `adv_redirects_404_excluded_extensions` | `string[] $extensions` | setting | File extensions never logged. |

## Actions

| Action | Arguments | When |
|---|---|---|
| `adv_redirects_loaded` | `Plugin $plugin` | `plugins_loaded` (priority 20). |
| `adv_redirects_before_redirect` | `array $rule, string $url, int $status` | Right before a 3xx response is sent. |
| `adv_redirects_rule_created` | `Rule $rule` | After a rule is inserted. |
| `adv_redirects_rule_updated` | `Rule $rule, Rule $old` | After a rule is updated. |
| `adv_redirects_rule_deleted` | `Rule $old` | After a rule is deleted. |
| `adv_redirects_cache_flushed` | none | After the compiled rule set cache is cleared. |
| `adv_redirects_auto_redirect_created` | `Rule $rule, WP_Post $post` | After the slug watcher creates a rule. |
| `adv_redirects_404_logged` | `string $path` | After a 404 is recorded. |

## PHP API

```php
$rule = adv_redirects_add( [
	'type'        => 'exact',      // or 'regex'
	'source'      => '/old-page',
	'target'      => '/new-page',  // null for 410/451
	'status_code' => 301,
] );
if ( is_wp_error( $rule ) ) {
	error_log( $rule->get_error_message() );
}

adv_redirects_delete( $rule['id'] );
adv_redirects_flush_cache();
```

## Examples

Only redirect logged-out visitors:

```php
add_filter( 'adv_redirects_should_handle_request', function ( $handle ) {
	return $handle && ! is_user_logged_in();
} );
```

Restrict external targets to known partners:

```php
add_filter( 'adv_redirects_allowed_target_hosts', function () {
	return [ 'partner.example.com' ];
} );
```

Let editors manage redirects:

```php
add_filter( 'adv_redirects_capability', function () {
	return 'edit_others_posts';
} );
```
````

- [ ] **Step 9: Run the whole integration suite**

Run: `npm run test:php:integration`
Expected: `OK`. All suites pass, including the REST tests, which now use the plugin's own route registration.

- [ ] **Step 10: Lint and commit**

```bash
vendor/bin/phpcs
git add src/Plugin.php src/functions.php src/Uninstaller.php uninstall.php src/Updates/UpdateChecker.php wp-redirects.php docs/hooks.md tests/integration/PluginTest.php
git commit -m "feat: wire plugin services, PHP API, uninstall and update checker

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 16: Admin page and JS foundation

**Files:**
- Create: `src/Admin/AdminPage.php`, `assets/src/index.js`, `assets/src/api.js`, `assets/src/constants.js`, `assets/src/utils/rules.js`, `assets/src/utils/time.js`, `assets/src/utils/notifySaved.js`, `assets/src/state/redirectsReducer.js`, `assets/src/state/useRedirects.js`, `assets/src/state/useNotices.js`, `assets/src/components/Tabs.js`, `assets/src/components/App.js` (interim; replaced in Tasks 17 and 18), `assets/src/admin.scss`
- Modify: `src/Plugin.php` (register AdminPage), `package.json` (deps)
- Test: `tests/integration/AdminPageTest.php`, `tests/js/rules.test.js`, `tests/js/time.test.js`, `tests/js/redirectsReducer.test.js`, `tests/js/constants.test.js`, `tests/js/notifySaved.test.js`

**Interfaces:**
- Consumes: REST routes from Tasks 13–14, `Permissions`, `Site::home_url()`, `ADV_REDIRECTS_*` constants
- Produces (JS):
  - `api.{ listRedirects, createRedirect(data), updateRedirect(id, data), deleteRedirect(id), bulkRedirects(action, ids), reorderRedirects(ids), testUrl(path), list404s({page, perPage, search, orderby, order}) → {items,total,pages}, delete404(id), bulkDelete404s(ids), clear404s(), getSettings(), saveSettings(data) }`
  - `STATUS_OPTIONS`, `isGone(status)`, `statusTone(status)` (`'permanent'|'temporary'|'gone'`), `fieldForError(error)` (`'source'|'target'|'status_code'|'form'`), `errorMessage(error)`, `ruleToPayload(rule)`
  - `filterRules(rules, filters)`, `sortRules(rules, sort)`, `splitByType(rules)` → `{ exact, regex }`, `moveItem(ids, from, to)`, `isFiltered(filters)`, `DEFAULT_FILTERS`
  - `timeAgo(gmtString, now?)`, `parseGmt(gmtString)`
  - `notifySaved(result, { notify, onUpdate, message })`
  - `redirectsReducer(state, action)` with actions `LOADED{items}`, `LOAD_FAILED{error}`, `UPSERT{item}`, `PATCH{id,patch}`, `PATCH_MANY{ids,patch}`, `REMOVE{ids}`, `REORDER{ids}`, and `initialState`
  - `useRedirects(notify)` → `{ items, loading, error, reload, create, update, remove, bulk, reorder }`
  - `useNotices()` → `{ notices, notify({message, status?, actions?}), dismiss(id) }`
  - `<Tabs tabs={[{name,title,count?}]} selected onSelect />`
- Produces (PHP): `AdminPage::SLUG = 'adv-redirects'`, `->register()`, `->add_menu()`, `->enqueue( string $hook_suffix )`, `->render()`, `->missing_build_notice()`. Mount point `#adv-redirects-app`. `window.advRedirects = { homeUrl, version }`.

- [ ] **Step 1: Install the WordPress JS packages used by the source and tests**

Run: `npm install --save-dev @wordpress/element @wordpress/components @wordpress/i18n @wordpress/api-fetch @wordpress/url @wordpress/icons`
Expected: added to `devDependencies`. At build time, `@wordpress/*` packages except `@wordpress/icons` are externals provided by WordPress. `@wordpress/icons` is bundled.

- [ ] **Step 2: Write the failing JS tests**

`tests/js/rules.test.js`:
```js
import {
	DEFAULT_FILTERS,
	filterRules,
	isFiltered,
	moveItem,
	sortRules,
	splitByType,
} from '../../assets/src/utils/rules';

const rules = [
	{ id: 1, type: 'exact', source: '/b-page', target: '/x', status_code: 301, enabled: true, origin: 'manual', note: '', hits: 5, last_hit_at: '2026-10-01 10:00:00', created_at: '2026-01-01 00:00:00', position: 0 },
	{ id: 2, type: 'exact', source: '/A-page', target: '/y', status_code: 302, enabled: false, origin: 'auto', note: 'Slug changed', hits: 9, last_hit_at: null, created_at: '2026-02-01 00:00:00', position: 0 },
	{ id: 3, type: 'regex', source: '^/r/(.*)$', target: '/z/$1', status_code: 301, enabled: true, origin: 'manual', note: '', hits: 0, last_hit_at: null, created_at: '2026-03-01 00:00:00', position: 2 },
	{ id: 4, type: 'regex', source: '^/q', target: null, status_code: 410, enabled: true, origin: 'manual', note: '', hits: 1, last_hit_at: null, created_at: '2026-03-02 00:00:00', position: 1 },
];

describe( 'filterRules', () => {
	it( 'returns everything with default filters', () => {
		expect( filterRules( rules, DEFAULT_FILTERS ) ).toHaveLength( 4 );
		expect( isFiltered( DEFAULT_FILTERS ) ).toBe( false );
	} );
	it( 'searches source, target and note case-insensitively', () => {
		expect( filterRules( rules, { ...DEFAULT_FILTERS, search: 'a-PAGE' } ).map( ( r ) => r.id ) ).toEqual( [ 2 ] );
		expect( filterRules( rules, { ...DEFAULT_FILTERS, search: 'slug' } ).map( ( r ) => r.id ) ).toEqual( [ 2 ] );
		expect( filterRules( rules, { ...DEFAULT_FILTERS, search: '/z/' } ).map( ( r ) => r.id ) ).toEqual( [ 3 ] );
	} );
	it( 'filters by status, enabled and origin', () => {
		expect( filterRules( rules, { ...DEFAULT_FILTERS, status: '302' } ).map( ( r ) => r.id ) ).toEqual( [ 2 ] );
		expect( filterRules( rules, { ...DEFAULT_FILTERS, enabled: 'disabled' } ).map( ( r ) => r.id ) ).toEqual( [ 2 ] );
		expect( filterRules( rules, { ...DEFAULT_FILTERS, origin: 'auto' } ).map( ( r ) => r.id ) ).toEqual( [ 2 ] );
		expect( isFiltered( { ...DEFAULT_FILTERS, origin: 'auto' } ) ).toBe( true );
	} );
} );

describe( 'sortRules', () => {
	it( 'sorts by source case-insensitively by default', () => {
		expect( sortRules( rules.slice( 0, 2 ) ).map( ( r ) => r.id ) ).toEqual( [ 2, 1 ] );
	} );
	it( 'sorts by hits descending', () => {
		expect( sortRules( rules, { orderby: 'hits', order: 'desc' } ).map( ( r ) => r.id ) ).toEqual( [ 2, 1, 4, 3 ] );
	} );
	it( 'puts never-hit rules last when sorting last hit descending', () => {
		expect( sortRules( rules, { orderby: 'last_hit_at', order: 'desc' } )[ 0 ].id ).toBe( 1 );
	} );
	it( 'does not mutate the input', () => {
		const copy = [ ...rules ];
		sortRules( rules, { orderby: 'hits', order: 'desc' } );
		expect( rules ).toEqual( copy );
	} );
} );

describe( 'splitByType', () => {
	it( 'splits and orders regex by position', () => {
		const { exact, regex } = splitByType( rules );
		expect( exact.map( ( r ) => r.id ) ).toEqual( [ 1, 2 ] );
		expect( regex.map( ( r ) => r.id ) ).toEqual( [ 4, 3 ] );
	} );
} );

describe( 'moveItem', () => {
	it( 'moves an id and ignores out-of-range targets', () => {
		expect( moveItem( [ 1, 2, 3 ], 0, 2 ) ).toEqual( [ 2, 3, 1 ] );
		expect( moveItem( [ 1, 2, 3 ], 2, 1 ) ).toEqual( [ 1, 3, 2 ] );
		expect( moveItem( [ 1, 2, 3 ], 0, -1 ) ).toEqual( [ 1, 2, 3 ] );
	} );
} );
```

`tests/js/time.test.js`:
```js
import { parseGmt, timeAgo } from '../../assets/src/utils/time';

const now = new Date( '2026-10-02T12:00:00Z' );

describe( 'timeAgo', () => {
	it( 'handles empty values', () => {
		expect( timeAgo( null, now ) ).toBe( 'Never' );
		expect( parseGmt( 'garbage' ) ).toBeNull();
	} );
	it( 'formats relative times from GMT MySQL datetimes', () => {
		expect( timeAgo( '2026-10-02 11:59:30', now ) ).toBe( 'Just now' );
		expect( timeAgo( '2026-10-02 11:55:00', now ) ).toBe( '5 minutes ago' );
		expect( timeAgo( '2026-10-02 11:00:00', now ) ).toBe( '1 hour ago' );
		expect( timeAgo( '2026-09-30 12:00:00', now ) ).toBe( '2 days ago' );
	} );
} );
```

`tests/js/redirectsReducer.test.js`:
```js
import { initialState, redirectsReducer } from '../../assets/src/state/redirectsReducer';

const a = { id: 1, source: '/a', enabled: true, position: 0 };
const b = { id: 2, source: '/b', enabled: true, position: 1 };

describe( 'redirectsReducer', () => {
	it( 'loads and reports failures', () => {
		const loaded = redirectsReducer( initialState, { type: 'LOADED', items: [ a ] } );
		expect( loaded ).toEqual( { items: [ a ], loading: false, error: null } );
		expect( redirectsReducer( initialState, { type: 'LOAD_FAILED', error: 'x' } ).error ).toBe( 'x' );
	} );
	it( 'upserts, patches and removes', () => {
		let state = redirectsReducer( initialState, { type: 'LOADED', items: [ a ] } );
		state = redirectsReducer( state, { type: 'UPSERT', item: b } );
		state = redirectsReducer( state, { type: 'UPSERT', item: { ...a, source: '/a2' } } );
		expect( state.items.map( ( r ) => r.source ) ).toEqual( [ '/a2', '/b' ] );

		state = redirectsReducer( state, { type: 'PATCH', id: 2, patch: { enabled: false } } );
		expect( state.items[ 1 ].enabled ).toBe( false );

		state = redirectsReducer( state, { type: 'PATCH_MANY', ids: [ 1, 2 ], patch: { enabled: true } } );
		expect( state.items.every( ( r ) => r.enabled ) ).toBe( true );

		state = redirectsReducer( state, { type: 'REMOVE', ids: [ 1 ] } );
		expect( state.items.map( ( r ) => r.id ) ).toEqual( [ 2 ] );
	} );
	it( 'reorders positions', () => {
		const state = redirectsReducer( { ...initialState, items: [ a, b ] }, { type: 'REORDER', ids: [ 2, 1 ] } );
		expect( state.items.find( ( r ) => r.id === 2 ).position ).toBe( 1 );
		expect( state.items.find( ( r ) => r.id === 1 ).position ).toBe( 2 );
	} );
	it( 'ignores unknown actions', () => {
		expect( redirectsReducer( initialState, { type: 'NOPE' } ) ).toBe( initialState );
	} );
} );
```

`tests/js/constants.test.js`:
```js
import { errorMessage, fieldForError, isGone, ruleToPayload, statusTone } from '../../assets/src/constants';

describe( 'constants', () => {
	it( 'detects gone statuses', () => {
		expect( isGone( 410 ) ).toBe( true );
		expect( isGone( '451' ) ).toBe( true );
		expect( isGone( 301 ) ).toBe( false );
	} );
	it( 'maps status tones', () => {
		expect( statusTone( 308 ) ).toBe( 'permanent' );
		expect( statusTone( 307 ) ).toBe( 'temporary' );
		expect( statusTone( 410 ) ).toBe( 'gone' );
	} );
	it( 'maps errors to fields', () => {
		expect( fieldForError( { code: 'adv_redirects_duplicate' } ) ).toBe( 'source' );
		expect( fieldForError( { code: 'adv_redirects_loop' } ) ).toBe( 'target' );
		expect( fieldForError( { code: 'rest_invalid_param', data: { params: { status_code: 'bad' } } } ) ).toBe( 'status_code' );
		expect( fieldForError( { code: 'rest_forbidden' } ) ).toBe( 'form' );
		expect( fieldForError( undefined ) ).toBe( 'form' );
	} );
	it( 'builds readable error messages', () => {
		expect( errorMessage( { message: 'Nope' } ) ).toBe( 'Nope' );
		expect( errorMessage( { code: 'rest_invalid_param', message: 'Invalid parameter(s): source', data: { params: { source: 'source is too long.' } } } ) ).toBe( 'source is too long.' );
		expect( errorMessage( {} ) ).toBe( 'Something went wrong. Please try again.' );
	} );
	it( 'strips read-only fields from payloads', () => {
		expect( ruleToPayload( { id: 1, type: 'exact', source: '/a', target: '/b', status_code: 301, enabled: true, note: '', hits: 4 } ) ).toEqual( {
			type: 'exact',
			source: '/a',
			target: '/b',
			status_code: 301,
			enabled: true,
			note: '',
		} );
	} );
} );
```

`tests/js/notifySaved.test.js`:
```js
import { notifySaved } from '../../assets/src/utils/notifySaved';

describe( 'notifySaved', () => {
	it( 'shows a plain message when there is no chain', () => {
		const notify = jest.fn();
		notifySaved( { rule: { id: 1 }, warnings: [] }, { notify, onUpdate: jest.fn(), message: 'Saved.' } );
		expect( notify ).toHaveBeenCalledWith( { message: 'Saved.' } );
	} );
	it( 'offers to point directly to the final hop', async () => {
		const notify = jest.fn();
		const onUpdate = jest.fn().mockResolvedValue( {} );
		notifySaved(
			{ rule: { id: 7 }, warnings: [ { code: 'chain', hops: [ '/a', '/b', '/c' ], final: '/c' } ] },
			{ notify, onUpdate, message: 'Saved.' }
		);
		const notice = notify.mock.calls[ 0 ][ 0 ];
		expect( notice.message ).toContain( '/a → /b → /c' );
		expect( notice.actions[ 0 ].label ).toBe( 'Point directly to /c' );
		notice.actions[ 0 ].onClick();
		expect( onUpdate ).toHaveBeenCalledWith( 7, { target: '/c' } );
	} );
} );
```

- [ ] **Step 3: Run them to verify they fail**

Run: `npm run test:js`
Expected: FAIL, `Cannot find module '../../assets/src/utils/rules'` (and the others).

- [ ] **Step 4: Implement `assets/src/constants.js`**

```js
import { __ } from '@wordpress/i18n';

export const STATUS_OPTIONS = [
	{ value: 301, label: __( '301 Moved Permanently', 'wp-redirects' ) },
	{ value: 302, label: __( '302 Found', 'wp-redirects' ) },
	{ value: 307, label: __( '307 Temporary Redirect', 'wp-redirects' ) },
	{ value: 308, label: __( '308 Permanent Redirect', 'wp-redirects' ) },
	{ value: 410, label: __( '410 Content Deleted', 'wp-redirects' ) },
	{ value: 451, label: __( '451 Unavailable For Legal Reasons', 'wp-redirects' ) },
];

export const isGone = ( status ) => [ 410, 451 ].includes( Number( status ) );

export function statusTone( status ) {
	if ( isGone( status ) ) {
		return 'gone';
	}
	return [ 301, 308 ].includes( Number( status ) ) ? 'permanent' : 'temporary';
}

const FIELD_BY_CODE = {
	adv_redirects_invalid_source: 'source',
	adv_redirects_invalid_regex: 'source',
	adv_redirects_duplicate: 'source',
	adv_redirects_reserved_source: 'source',
	adv_redirects_invalid_target: 'target',
	adv_redirects_loop: 'target',
	adv_redirects_invalid_status: 'status_code',
};

const FORM_FIELDS = [ 'source', 'target', 'status_code' ];

export function fieldForError( error ) {
	if ( ! error ) {
		return 'form';
	}
	if ( FIELD_BY_CODE[ error.code ] ) {
		return FIELD_BY_CODE[ error.code ];
	}
	if ( error.code === 'rest_invalid_param' && error.data && error.data.params ) {
		const key = Object.keys( error.data.params ).find( ( name ) => FORM_FIELDS.includes( name ) );
		if ( key ) {
			return key;
		}
	}
	return 'form';
}

export function errorMessage( error ) {
	if ( error && error.code === 'rest_invalid_param' && error.data && error.data.params ) {
		const first = Object.values( error.data.params )[ 0 ];
		if ( first ) {
			return String( first );
		}
	}
	return ( error && error.message ) || __( 'Something went wrong. Please try again.', 'wp-redirects' );
}

export const ruleToPayload = ( rule ) => ( {
	type: rule.type,
	source: rule.source,
	target: rule.target,
	status_code: rule.status_code,
	enabled: rule.enabled,
	note: rule.note,
} );
```

- [ ] **Step 5: Implement `assets/src/utils/rules.js`**

```js
export const DEFAULT_FILTERS = { search: '', status: 'all', enabled: 'all', origin: 'all' };

export const isFiltered = ( filters ) =>
	filters.search.trim() !== '' || filters.status !== 'all' || filters.enabled !== 'all' || filters.origin !== 'all';

function matchesSearch( rule, search ) {
	const query = search.trim().toLowerCase();
	if ( ! query ) {
		return true;
	}
	return [ rule.source, rule.target || '', rule.note || '' ].some( ( value ) => value.toLowerCase().includes( query ) );
}

export function filterRules( rules, filters = DEFAULT_FILTERS ) {
	const { search = '', status = 'all', enabled = 'all', origin = 'all' } = filters;
	return rules.filter(
		( rule ) =>
			matchesSearch( rule, search ) &&
			( status === 'all' || rule.status_code === Number( status ) ) &&
			( enabled === 'all' || rule.enabled === ( enabled === 'enabled' ) ) &&
			( origin === 'all' || rule.origin === origin )
	);
}

const SORT_KEYS = {
	source: ( rule ) => rule.source.toLowerCase(),
	target: ( rule ) => ( rule.target || '' ).toLowerCase(),
	status_code: ( rule ) => rule.status_code,
	hits: ( rule ) => rule.hits,
	last_hit_at: ( rule ) => rule.last_hit_at || '',
	created_at: ( rule ) => rule.created_at,
};

export function sortRules( rules, { orderby = 'source', order = 'asc' } = {} ) {
	const key = SORT_KEYS[ orderby ] || SORT_KEYS.source;
	const direction = order === 'desc' ? -1 : 1;
	return [ ...rules ].sort( ( a, b ) => {
		const x = key( a );
		const y = key( b );
		if ( x < y ) {
			return -direction;
		}
		if ( x > y ) {
			return direction;
		}
		return a.id - b.id;
	} );
}

export function splitByType( rules ) {
	return {
		exact: rules.filter( ( rule ) => rule.type === 'exact' ),
		regex: rules
			.filter( ( rule ) => rule.type === 'regex' )
			.sort( ( a, b ) => a.position - b.position || a.id - b.id ),
	};
}

export function moveItem( ids, from, to ) {
	const next = [ ...ids ];
	if ( to < 0 || to >= next.length || from < 0 || from >= next.length ) {
		return next;
	}
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}
```

- [ ] **Step 6: Implement `assets/src/utils/time.js`**

```js
import { __, _n, sprintf } from '@wordpress/i18n';

export function parseGmt( value ) {
	if ( ! value ) {
		return null;
	}
	const date = new Date( String( value ).replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( date.getTime() ) ? null : date;
}

export function timeAgo( value, now = new Date() ) {
	const date = parseGmt( value );
	if ( ! date ) {
		return __( 'Never', 'wp-redirects' );
	}
	const seconds = Math.max( 0, Math.floor( ( now - date ) / 1000 ) );
	if ( seconds < 60 ) {
		return __( 'Just now', 'wp-redirects' );
	}
	const minutes = Math.floor( seconds / 60 );
	if ( minutes < 60 ) {
		/* translators: %d: number of minutes */
		return sprintf( _n( '%d minute ago', '%d minutes ago', minutes, 'wp-redirects' ), minutes );
	}
	const hours = Math.floor( minutes / 60 );
	if ( hours < 24 ) {
		/* translators: %d: number of hours */
		return sprintf( _n( '%d hour ago', '%d hours ago', hours, 'wp-redirects' ), hours );
	}
	const days = Math.floor( hours / 24 );
	if ( days < 30 ) {
		/* translators: %d: number of days */
		return sprintf( _n( '%d day ago', '%d days ago', days, 'wp-redirects' ), days );
	}
	return date.toLocaleDateString();
}
```

- [ ] **Step 7: Implement `assets/src/utils/notifySaved.js`**

```js
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage } from '../constants';

export function notifySaved( result, { notify, onUpdate, message } ) {
	const chain = ( result.warnings || [] ).find( ( warning ) => warning.code === 'chain' );
	if ( ! chain ) {
		notify( { message } );
		return;
	}
	notify( {
		/* translators: %s: redirect hops, e.g. "/a → /b → /c" */
		message: sprintf( __( 'Saved, but this creates a chain: %s', 'wp-redirects' ), chain.hops.join( ' → ' ) ),
		actions: [
			{
				/* translators: %s: final destination path */
				label: sprintf( __( 'Point directly to %s', 'wp-redirects' ), chain.final ),
				onClick: () =>
					onUpdate( result.rule.id, { target: chain.final } )
						.then( () => notify( { message: __( 'Redirect now points to the final destination.', 'wp-redirects' ) } ) )
						.catch( ( error ) => notify( { status: 'error', message: errorMessage( error ) } ) ),
			},
		],
	} );
}
```

- [ ] **Step 8: Implement `assets/src/state/redirectsReducer.js`**

```js
export const initialState = { items: [], loading: true, error: null };

export function redirectsReducer( state, action ) {
	switch ( action.type ) {
		case 'LOADED':
			return { items: action.items, loading: false, error: null };
		case 'LOAD_FAILED':
			return { ...state, loading: false, error: action.error };
		case 'UPSERT': {
			const exists = state.items.some( ( rule ) => rule.id === action.item.id );
			return {
				...state,
				items: exists
					? state.items.map( ( rule ) => ( rule.id === action.item.id ? action.item : rule ) )
					: [ ...state.items, action.item ],
			};
		}
		case 'PATCH':
			return {
				...state,
				items: state.items.map( ( rule ) => ( rule.id === action.id ? { ...rule, ...action.patch } : rule ) ),
			};
		case 'PATCH_MANY':
			return {
				...state,
				items: state.items.map( ( rule ) => ( action.ids.includes( rule.id ) ? { ...rule, ...action.patch } : rule ) ),
			};
		case 'REMOVE':
			return { ...state, items: state.items.filter( ( rule ) => ! action.ids.includes( rule.id ) ) };
		case 'REORDER': {
			const positions = new Map( action.ids.map( ( id, index ) => [ id, index + 1 ] ) );
			return {
				...state,
				items: state.items.map( ( rule ) =>
					positions.has( rule.id ) ? { ...rule, position: positions.get( rule.id ) } : rule
				),
			};
		}
		default:
			return state;
	}
}
```

- [ ] **Step 9: Run JS tests to verify they pass**

Run: `npm run test:js`
Expected: `Tests: 22 passed`, 5 suites passed.

- [ ] **Step 10: Implement `assets/src/api.js`**

```js
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const NS = '/adv-redirects/v1';

export const api = {
	listRedirects: () => apiFetch( { path: `${ NS }/redirects` } ),
	createRedirect: ( data ) => apiFetch( { path: `${ NS }/redirects`, method: 'POST', data } ),
	updateRedirect: ( id, data ) => apiFetch( { path: `${ NS }/redirects/${ id }`, method: 'PUT', data } ),
	deleteRedirect: ( id ) => apiFetch( { path: `${ NS }/redirects/${ id }`, method: 'DELETE' } ),
	bulkRedirects: ( action, ids ) => apiFetch( { path: `${ NS }/redirects/bulk`, method: 'POST', data: { action, ids } } ),
	reorderRedirects: ( ids ) => apiFetch( { path: `${ NS }/redirects/reorder`, method: 'POST', data: { ids } } ),
	testUrl: ( path ) => apiFetch( { path: `${ NS }/test`, method: 'POST', data: { path } } ),
	async list404s( { page, perPage, search, orderby, order } ) {
		const response = await apiFetch( {
			path: addQueryArgs( `${ NS }/404s`, { page, per_page: perPage, search, orderby, order } ),
			parse: false,
		} );
		return {
			items: await response.json(),
			total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
			pages: Number( response.headers.get( 'X-WP-TotalPages' ) || 0 ),
		};
	},
	delete404: ( id ) => apiFetch( { path: `${ NS }/404s/${ id }`, method: 'DELETE' } ),
	bulkDelete404s: ( ids ) => apiFetch( { path: `${ NS }/404s/bulk`, method: 'POST', data: { action: 'delete', ids } } ),
	clear404s: () => apiFetch( { path: `${ NS }/404s`, method: 'DELETE' } ),
	getSettings: () => apiFetch( { path: `${ NS }/settings` } ),
	saveSettings: ( data ) => apiFetch( { path: `${ NS }/settings`, method: 'PUT', data } ),
};
```

- [ ] **Step 11: Implement `assets/src/state/useNotices.js` and `assets/src/state/useRedirects.js`**

`assets/src/state/useNotices.js`:
```js
import { useCallback, useState } from '@wordpress/element';

let nextId = 1;

export function useNotices() {
	const [ notices, setNotices ] = useState( [] );

	const dismiss = useCallback( ( id ) => setNotices( ( list ) => list.filter( ( notice ) => notice.id !== id ) ), [] );

	const notify = useCallback( ( { message, status = 'success', actions = [] } ) => {
		const id = `adv-redirects-notice-${ nextId++ }`;
		setNotices( ( list ) => [
			...list.slice( -2 ),
			{ id, content: message, spokenMessage: message, status, actions, explicitDismiss: status === 'error' },
		] );
		return id;
	}, [] );

	return { notices, notify, dismiss };
}
```

`assets/src/state/useRedirects.js`:
```js
import { useCallback, useEffect, useReducer, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage, ruleToPayload } from '../constants';
import { initialState, redirectsReducer } from './redirectsReducer';

export function useRedirects( notify ) {
	const [ state, dispatch ] = useReducer( redirectsReducer, initialState );
	const itemsRef = useRef( state.items );
	itemsRef.current = state.items;

	const reload = useCallback( async () => {
		try {
			dispatch( { type: 'LOADED', items: await api.listRedirects() } );
		} catch ( error ) {
			dispatch( { type: 'LOAD_FAILED', error: errorMessage( error ) } );
		}
	}, [] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	const create = useCallback(
		async ( data ) => {
			const result = await api.createRedirect( data );
			dispatch( { type: 'UPSERT', item: result.rule } );
			reload();
			return result;
		},
		[ reload ]
	);

	const update = useCallback(
		async ( id, patch ) => {
			const previous = itemsRef.current.find( ( rule ) => rule.id === id );
			dispatch( { type: 'PATCH', id, patch } );
			try {
				const result = await api.updateRedirect( id, patch );
				dispatch( { type: 'UPSERT', item: result.rule } );
				reload();
				return result;
			} catch ( error ) {
				if ( previous ) {
					dispatch( { type: 'UPSERT', item: previous } );
				}
				throw error;
			}
		},
		[ reload ]
	);

	const remove = useCallback(
		async ( rule ) => {
			dispatch( { type: 'REMOVE', ids: [ rule.id ] } );
			try {
				await api.deleteRedirect( rule.id );
				reload();
				notify( {
					message: __( 'Redirect deleted.', 'wp-redirects' ),
					actions: [
						{
							label: __( 'Undo', 'wp-redirects' ),
							onClick: () =>
								create( ruleToPayload( rule ) ).catch( ( error ) =>
									notify( { status: 'error', message: errorMessage( error ) } )
								),
						},
					],
				} );
			} catch ( error ) {
				dispatch( { type: 'UPSERT', item: rule } );
				notify( { status: 'error', message: errorMessage( error ) } );
			}
		},
		[ create, notify, reload ]
	);

	const bulk = useCallback(
		async ( action, ids ) => {
			const previous = itemsRef.current;
			if ( action === 'delete' ) {
				dispatch( { type: 'REMOVE', ids } );
			} else {
				dispatch( { type: 'PATCH_MANY', ids, patch: { enabled: action === 'enable' } } );
			}
			try {
				const result = await api.bulkRedirects( action, ids );
				await reload();
				return result;
			} catch ( error ) {
				dispatch( { type: 'LOADED', items: previous } );
				throw error;
			}
		},
		[ reload ]
	);

	const reorder = useCallback(
		async ( ids ) => {
			const previous = itemsRef.current;
			dispatch( { type: 'REORDER', ids } );
			try {
				await api.reorderRedirects( ids );
			} catch ( error ) {
				dispatch( { type: 'LOADED', items: previous } );
				notify( { status: 'error', message: errorMessage( error ) } );
			}
		},
		[ notify ]
	);

	return { ...state, reload, create, update, remove, bulk, reorder };
}
```

- [ ] **Step 12: Implement `assets/src/components/Tabs.js`, the interim `App.js`, `index.js` and `admin.scss`**

`assets/src/components/Tabs.js`:
```js
import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Tabs( { tabs, selected, onSelect } ) {
	const refs = useRef( {} );

	const onKeyDown = ( event, index ) => {
		const last = tabs.length - 1;
		const next = {
			ArrowRight: index === last ? 0 : index + 1,
			ArrowLeft: index === 0 ? last : index - 1,
			Home: 0,
			End: last,
		}[ event.key ];
		if ( next === undefined ) {
			return;
		}
		event.preventDefault();
		onSelect( tabs[ next ].name );
		refs.current[ tabs[ next ].name ]?.focus();
	};

	return (
		<div className="adv-redirects-tabs" role="tablist" aria-label={ __( 'Redirect sections', 'wp-redirects' ) }>
			{ tabs.map( ( tab, index ) => (
				<button
					key={ tab.name }
					ref={ ( element ) => ( refs.current[ tab.name ] = element ) }
					type="button"
					role="tab"
					id={ `adv-redirects-tab-${ tab.name }` }
					aria-controls={ `adv-redirects-panel-${ tab.name }` }
					aria-selected={ selected === tab.name }
					tabIndex={ selected === tab.name ? 0 : -1 }
					className={ `adv-redirects-tabs__tab${ selected === tab.name ? ' is-active' : '' }` }
					onClick={ () => onSelect( tab.name ) }
					onKeyDown={ ( event ) => onKeyDown( event, index ) }
				>
					{ tab.title }
					{ tab.count !== undefined && <span className="adv-redirects-tabs__count">{ tab.count }</span> }
				</button>
			) ) }
		</div>
	);
}
```

`assets/src/components/App.js` (interim: proves the data path; Task 17 replaces the Redirects panel and Task 18 replaces the file):
```js
import { SnackbarList, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import Tabs from './Tabs';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );

	const tabs = [
		{ name: 'redirects', title: __( 'Redirects', 'wp-redirects' ), count: redirects.items.length },
		{ name: '404s', title: __( '404 Log', 'wp-redirects' ) },
		{ name: 'settings', title: __( 'Settings', 'wp-redirects' ) },
	];

	return (
		<div className="adv-redirects">
			<header className="adv-redirects__header">
				<h1>{ __( 'Redirects', 'wp-redirects' ) }</h1>
			</header>
			<Tabs tabs={ tabs } selected={ tab } onSelect={ setTab } />
			<div role="tabpanel" id={ `adv-redirects-panel-${ tab }` } aria-labelledby={ `adv-redirects-tab-${ tab }` } className="adv-redirects__panel">
				{ redirects.loading ? (
					<Spinner />
				) : (
					<p>
						{ sprintf(
							/* translators: %d: number of redirects */
							_n( '%d redirect', '%d redirects', redirects.items.length, 'wp-redirects' ),
							redirects.items.length
						) }
					</p>
				) }
			</div>
			<SnackbarList notices={ notices } onRemove={ dismiss } className="adv-redirects__snackbars" />
		</div>
	);
}
```

`assets/src/index.js`:
```js
import { createRoot } from '@wordpress/element';
import App from './components/App';
import './admin.scss';

const container = document.getElementById( 'adv-redirects-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
```

`assets/src/admin.scss` (base; Tasks 17 and 18 append to it):
```scss
.adv-redirects {
	--adv-gap: 16px;
	--adv-radius: 8px;
	--adv-border: #dcdcde;
	--adv-muted: #646970;
	--adv-surface: #fff;
	--adv-accent: var(--wp-admin-theme-color, #3858e9);
	--adv-danger: #d63638;
	--adv-success: #00a32a;
	--adv-warning: #dba617;

	max-width: 1200px;
	margin-top: 12px;

	&__header h1 {
		font-size: 23px;
		font-weight: 400;
		margin: 0 0 12px;
		padding: 0;
	}

	&__panel {
		display: grid;
		gap: var(--adv-gap);
		padding-top: var(--adv-gap);
	}

	&__snackbars {
		position: fixed;
		bottom: 24px;
		left: 50%;
		transform: translateX(-50%);
		z-index: 100000;
	}
}

.adv-redirects-tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--adv-border);

	&__tab {
		appearance: none;
		background: none;
		border: 0;
		border-bottom: 2px solid transparent;
		color: #1d2327;
		cursor: pointer;
		font-size: 14px;
		margin-bottom: -1px;
		padding: 10px 14px;

		&:hover {
			color: var(--adv-accent);
		}

		&:focus-visible {
			outline: 2px solid var(--adv-accent);
			outline-offset: -2px;
		}

		&.is-active {
			border-bottom-color: var(--adv-accent);
			font-weight: 600;
		}
	}

	&__count {
		background: #f0f0f1;
		border-radius: 999px;
		color: var(--adv-muted);
		font-size: 12px;
		margin-left: 6px;
		padding: 1px 8px;
	}
}
```

- [ ] **Step 13: Write the failing PHP test `tests/integration/AdminPageTest.php`**

```php
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
```

- [ ] **Step 14: Run it to verify it fails**

Run: `npm run test:php:integration -- --filter AdminPageTest`
Expected: FAIL, class not found.

- [ ] **Step 15: Implement `src/Admin/AdminPage.php`**

```php
<?php
/**
 * Admin menu page that mounts the React app.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Admin;

use Advision\Redirects\Permissions;
use Advision\Redirects\Site;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const SLUG = 'adv-redirects';

	private const HANDLE = 'adv-redirects-admin';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Redirects', 'wp-redirects' ),
			__( 'Redirects', 'wp-redirects' ),
			Permissions::capability(),
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-randomize',
			76
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = ADV_REDIRECTS_DIR . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action( 'admin_notices', [ $this, 'missing_build_notice' ] );
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, ADV_REDIRECTS_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'wp-redirects' );
		wp_add_inline_script(
			self::HANDLE,
			'window.advRedirects = ' . wp_json_encode(
				[
					'homeUrl' => Site::home_url(),
					'version' => ADV_REDIRECTS_VERSION,
				]
			) . ';',
			'before'
		);

		if ( is_readable( ADV_REDIRECTS_DIR . 'build/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, ADV_REDIRECTS_URL . 'build/index.css', [ 'wp-components' ], $asset['version'] );
		}
	}

	public function render(): void {
		if ( ! Permissions::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage redirects.', 'wp-redirects' ) );
		}
		echo '<div class="wrap adv-redirects-wrap"><div id="adv-redirects-app"></div><noscript>'
			. esc_html__( 'The Redirects screen needs JavaScript.', 'wp-redirects' )
			. '</noscript></div>';
	}

	public function missing_build_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'WP Redirects admin assets are missing. Run "npm run build", or install a release zip.', 'wp-redirects' )
			. '</p></div>';
	}
}
```

Note: the enqueue guard compares against the known hook suffix `toplevel_page_adv-redirects`, so it works no matter which instance registered the menu.

- [ ] **Step 16: Register the admin page in `src/Plugin.php`**

Add the import after the other `use` lines:
```php
use Advision\Redirects\Admin\AdminPage;
```

In `boot()`, replace:
```php
		$this->cron->register();

		UpdateChecker::boot( ADV_REDIRECTS_FILE );
```
with:
```php
		$this->cron->register();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}

		UpdateChecker::boot( ADV_REDIRECTS_FILE );
```

- [ ] **Step 17: Build and run every check**

Run: `npm run build && npm run lint:js && npm run test:js && npm run test:php:integration && vendor/bin/phpcs`
Expected: `build/index.js`, `build/index.asset.php` and `build/index.css` exist. `build/index.asset.php` lists `react-jsx-runtime`, `wp-api-fetch`, `wp-components`, `wp-element`, `wp-i18n`, `wp-url`. All tests and lint pass. If ESLint reports only formatting, run `npm run format` and re-run.

- [ ] **Step 18: Check the screen manually**

Open http://localhost:8888/wp-admin/admin.php?page=adv-redirects (user `admin`, password `password`). The **Redirects** menu item sits below Tools. The page shows "0 redirects", the three tabs switch with the mouse and with ←/→, and the browser console has no errors.

- [ ] **Step 19: Commit**

```bash
git add src/Admin/AdminPage.php src/Plugin.php assets/src tests/js tests/integration/AdminPageTest.php package.json package-lock.json
git commit -m "feat: add admin page and React app foundation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Redirects tab UI

**Files:**
- Create: `assets/src/components/StatusBadge.js`, `Field.js`, `ConfirmModal.js`, `TestUrlBar.js`, `QuickAddForm.js`, `RuleRow.js`, `RuleEditRow.js`, `RulesTable.js`, `RedirectsTab.js` (all under `assets/src/components/`)
- Modify: `assets/src/components/App.js` (render `RedirectsTab`), `assets/src/admin.scss` (append)
- Test: `tests/js/previewRegex.test.js`

**Interfaces:**
- Consumes: everything produced by Task 16
- Produces:
  - `<RedirectsTab redirects notify prefill onPrefillUsed />`. `redirects` is the `useRedirects()` return value; `prefill` is `string|null`.
  - `previewRegex(pattern, path): string` (exported from `QuickAddForm.js`)
  - Accessible names the e2e test (Task 19) relies on: text fields **Source**, **Target**, **Test a URL**; buttons **Add redirect**, **Test**; result container `.adv-redirects-test__result`; source cells containing the bare source text.

- [ ] **Step 1: Write the failing test `tests/js/previewRegex.test.js`**

```js
import { previewRegex } from '../../assets/src/components/QuickAddForm';

describe( 'previewRegex', () => {
	it( 'reports matches case-insensitively', () => {
		expect( previewRegex( '^/blog/(\\d+)$', '/BLOG/12' ) ).toBe( 'Matches "/BLOG/12" (browser preview)' );
	} );
	it( 'reports non-matches', () => {
		expect( previewRegex( '^/blog/(\\d+)$', '/blog/x' ) ).toBe( 'Does not match "/blog/x" (browser preview)' );
	} );
	it( 'handles invalid patterns', () => {
		expect( previewRegex( '(', '/x' ) ).toBe( 'Pattern is not valid in the browser preview.' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- previewRegex`
Expected: FAIL, `Cannot find module '../../assets/src/components/QuickAddForm'`.

- [ ] **Step 3: Implement the small shared components**

`assets/src/components/StatusBadge.js`:
```js
import { statusTone } from '../constants';

export default function StatusBadge( { status } ) {
	return <span className={ `adv-redirects-badge is-${ statusTone( status ) }` }>{ status }</span>;
}
```

`assets/src/components/Field.js`:
```js
export default function Field( { error, children, className = '' } ) {
	return (
		<div className={ `adv-redirects-field ${ className }${ error ? ' has-error' : '' }` }>
			{ children }
			{ error && (
				<p className="adv-redirects-field__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
```

`assets/src/components/ConfirmModal.js`:
```js
import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function ConfirmModal( { title, message, confirmLabel, onConfirm, onCancel } ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } className="adv-redirects-modal">
			<p>{ message }</p>
			<div className="adv-redirects-modal__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'wp-redirects' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}
```

- [ ] **Step 4: Implement `assets/src/components/TestUrlBar.js`**

```js
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import StatusBadge from './StatusBadge';

function TestResult( { result } ) {
	if ( ! result.matched ) {
		return (
			<span>
				{ result.reason === 'external'
					? __( 'That URL is on another site.', 'wp-redirects' )
					: __( 'No redirect matches this URL.', 'wp-redirects' ) }
			</span>
		);
	}
	if ( result.blocked ) {
		return (
			<span className="is-error">
				{ sprintf(
					/* translators: %d: rule ID */
					__( 'Rule #%d matches, but its target was blocked as unsafe.', 'wp-redirects' ),
					result.rule_id
				) }
			</span>
		);
	}
	if ( ! result.target_url ) {
		return (
			<span>
				<StatusBadge status={ result.status } />{ ' ' }
				{ sprintf(
					/* translators: 1: rule ID, 2: HTTP status */
					__( 'Rule #%1$d responds with %2$d.', 'wp-redirects' ),
					result.rule_id,
					result.status
				) }
			</span>
		);
	}
	return (
		<span>
			<StatusBadge status={ result.status } /> <code>{ result.hops.join( ' → ' ) }</code>
			{ result.loop && <strong className="is-error"> { __( 'Loop detected', 'wp-redirects' ) }</strong> }
		</span>
	);
}

export default function TestUrlBar( { value, onChange, onResult } ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const run = async ( event ) => {
		event.preventDefault();
		const path = value.trim();
		if ( ! path ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const response = await api.testUrl( path );
			setResult( response );
			onResult( response.matched ? response.rule_id : null );
		} catch ( requestError ) {
			setResult( null );
			setError( errorMessage( requestError ) );
			onResult( null );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form className="adv-redirects-test" onSubmit={ run } role="search">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Test a URL', 'wp-redirects' ) }
				placeholder="/old-page"
				value={ value }
				onChange={ ( next ) => {
					onChange( next );
					if ( ! next ) {
						setResult( null );
						onResult( null );
					}
				} }
			/>
			<Button variant="secondary" type="submit" isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
				{ __( 'Test', 'wp-redirects' ) }
			</Button>
			<div className="adv-redirects-test__result" aria-live="polite">
				{ error && <span className="is-error">{ error }</span> }
				{ result && <TestResult result={ result } /> }
			</div>
		</form>
	);
}
```

- [ ] **Step 5: Implement `assets/src/components/QuickAddForm.js`**

```js
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { errorMessage, fieldForError, isGone, STATUS_OPTIONS } from '../constants';
import { notifySaved } from '../utils/notifySaved';
import Field from './Field';

const EMPTY = { type: 'exact', source: '', target: '', status_code: '301' };

export function previewRegex( pattern, path ) {
	try {
		return new RegExp( pattern, 'i' ).test( path )
			? /* translators: %s: path */ sprintf( __( 'Matches "%s" (browser preview)', 'wp-redirects' ), path )
			: /* translators: %s: path */ sprintf( __( 'Does not match "%s" (browser preview)', 'wp-redirects' ), path );
	} catch {
		return __( 'Pattern is not valid in the browser preview.', 'wp-redirects' );
	}
}

function TypeToggle( { value, onChange } ) {
	const options = [
		[ 'exact', __( 'Exact', 'wp-redirects' ) ],
		[ 'regex', __( 'Regex', 'wp-redirects' ) ],
	];
	return (
		<div className="adv-redirects-segmented" role="group" aria-label={ __( 'Match type', 'wp-redirects' ) }>
			{ options.map( ( [ option, label ] ) => (
				<Button
					key={ option }
					type="button"
					variant={ value === option ? 'primary' : 'secondary' }
					aria-pressed={ value === option }
					onClick={ () => onChange( option ) }
					__next40pxDefaultSize
				>
					{ label }
				</Button>
			) ) }
		</div>
	);
}

export default function QuickAddForm( { onCreate, onUpdate, notify, testPath, prefill, onPrefillUsed } ) {
	const [ values, setValues ] = useState( EMPTY );
	const [ errors, setErrors ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const sourceRef = useRef();

	useEffect( () => {
		if ( prefill ) {
			setValues( { ...EMPTY, source: prefill } );
			setErrors( {} );
			onPrefillUsed();
			sourceRef.current?.focus();
		}
	}, [ prefill, onPrefillUsed ] );

	const set = ( key ) => ( value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( { ...current, [ key ]: undefined, form: undefined } ) );
	};

	const gone = isGone( values.status_code );
	const isRegex = values.type === 'regex';

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setErrors( {} );
		try {
			const result = await onCreate( {
				type: values.type,
				source: values.source.trim(),
				target: gone ? null : values.target.trim(),
				status_code: Number( values.status_code ),
			} );
			setValues( ( current ) => ( { ...EMPTY, type: current.type, status_code: current.status_code } ) );
			sourceRef.current?.focus();
			notifySaved( result, { notify, onUpdate, message: __( 'Redirect added.', 'wp-redirects' ) } );
		} catch ( error ) {
			setErrors( { [ fieldForError( error ) ]: errorMessage( error ) } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form className="adv-redirects-quickadd" onSubmit={ submit } aria-label={ __( 'New redirect', 'wp-redirects' ) }>
			<TypeToggle value={ values.type } onChange={ set( 'type' ) } />
			<Field error={ errors.source } className="adv-redirects-quickadd__source">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					ref={ sourceRef }
					label={ __( 'Source', 'wp-redirects' ) }
					placeholder={ isRegex ? '^/blog/(\\d+)/?$' : '/old-page' }
					value={ values.source }
					onChange={ set( 'source' ) }
					help={ isRegex && values.source && testPath ? previewRegex( values.source, testPath ) : undefined }
					required
				/>
			</Field>
			{ ! gone && (
				<Field error={ errors.target } className="adv-redirects-quickadd__target">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Target', 'wp-redirects' ) }
						placeholder={ isRegex ? '/news/$1' : '/new-page' }
						value={ values.target }
						onChange={ set( 'target' ) }
						required
					/>
				</Field>
			) }
			<Field error={ errors.status_code } className="adv-redirects-quickadd__status">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Status', 'wp-redirects' ) }
					value={ values.status_code }
					options={ STATUS_OPTIONS.map( ( option ) => ( { value: String( option.value ), label: option.label } ) ) }
					onChange={ set( 'status_code' ) }
				/>
			</Field>
			<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
				{ __( 'Add redirect', 'wp-redirects' ) }
			</Button>
			{ errors.form && (
				<p className="adv-redirects-field__error" role="alert">
					{ errors.form }
				</p>
			) }
		</form>
	);
}
```

- [ ] **Step 6: Implement `assets/src/components/RuleRow.js` and `RuleEditRow.js`**

`assets/src/components/RuleRow.js`:
```js
import { Button, FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronUp, dragHandle, pencil, trash } from '@wordpress/icons';
import { isGone } from '../constants';
import { parseGmt, timeAgo } from '../utils/time';
import StatusBadge from './StatusBadge';

export default function RuleRow( {
	rule,
	isRegex,
	index,
	total,
	selected,
	highlighted,
	reorderable,
	dragProps,
	onSelect,
	onToggle,
	onEdit,
	onDelete,
	onMove,
	onFixChain,
} ) {
	const lastHit = parseGmt( rule.last_hit_at );

	return (
		<tr
			className={ [ 'adv-redirects-row', highlighted && 'is-highlighted', ! rule.enabled && 'is-disabled' ].filter( Boolean ).join( ' ' ) }
			{ ...dragProps }
		>
			<td className="adv-redirects-col-check">
				<input
					type="checkbox"
					checked={ selected }
					onChange={ () => onSelect( rule.id ) }
					/* translators: %s: redirect source */
					aria-label={ sprintf( __( 'Select %s', 'wp-redirects' ), rule.source ) }
				/>
			</td>
			{ isRegex && (
				<td className="adv-redirects-col-order">
					{ reorderable && <span className="adv-redirects-handle" aria-hidden="true">{ dragHandle }</span> }
					<span className="adv-redirects-position">{ index + 1 }</span>
					<Button
						size="small"
						icon={ chevronUp }
						label={ __( 'Move up', 'wp-redirects' ) }
						disabled={ ! reorderable || index === 0 }
						onClick={ () => onMove( index, index - 1 ) }
					/>
					<Button
						size="small"
						icon={ chevronDown }
						label={ __( 'Move down', 'wp-redirects' ) }
						disabled={ ! reorderable || index === total - 1 }
						onClick={ () => onMove( index, index + 1 ) }
					/>
				</td>
			) }
			<td className="adv-redirects-col-toggle">
				<FormToggle
					checked={ rule.enabled }
					onChange={ () => onToggle( rule ) }
					/* translators: %s: redirect source */
					aria-label={ sprintf( __( 'Enable %s', 'wp-redirects' ), rule.source ) }
				/>
			</td>
			<td className="adv-redirects-col-source">
				<code>{ rule.source }</code>
				{ rule.note && <div className="adv-redirects-note">{ rule.note }</div> }
			</td>
			<td className="adv-redirects-col-target">
				{ isGone( rule.status_code ) ? (
					<span className="adv-redirects-muted">{ __( 'No target', 'wp-redirects' ) }</span>
				) : (
					<code>{ rule.target }</code>
				) }
				<div className="adv-redirects-flags">
					{ rule.origin === 'auto' && <span className="adv-redirects-flag">{ __( 'Auto', 'wp-redirects' ) }</span> }
					{ rule.chain && rule.chain.loop && (
						<span className="adv-redirects-flag is-error" title={ rule.chain.hops.join( ' → ' ) }>
							{ __( 'Loop', 'wp-redirects' ) }
						</span>
					) }
					{ rule.chain && ! rule.chain.loop && (
						<>
							<span className="adv-redirects-flag is-warning" title={ rule.chain.hops.join( ' → ' ) }>
								{ __( 'Chain', 'wp-redirects' ) }
							</span>
							<Button variant="link" onClick={ () => onFixChain( rule ) }>
								{ /* translators: %s: final destination */ sprintf( __( 'Point to %s', 'wp-redirects' ), rule.chain.final ) }
							</Button>
						</>
					) }
				</div>
			</td>
			<td className="adv-redirects-col-status">
				<StatusBadge status={ rule.status_code } />
			</td>
			<td className="adv-redirects-col-hits">{ rule.hits.toLocaleString() }</td>
			<td className="adv-redirects-col-last">
				<span title={ lastHit ? lastHit.toLocaleString() : undefined }>{ timeAgo( rule.last_hit_at ) }</span>
			</td>
			<td className="adv-redirects-col-actions">
				<Button size="small" icon={ pencil } label={ __( 'Edit', 'wp-redirects' ) } onClick={ () => onEdit( rule.id ) } />
				<Button size="small" icon={ trash } label={ __( 'Delete', 'wp-redirects' ) } isDestructive onClick={ () => onDelete( rule ) } />
			</td>
		</tr>
	);
}
```

`assets/src/components/RuleEditRow.js`:
```js
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorMessage, fieldForError, isGone, STATUS_OPTIONS } from '../constants';
import Field from './Field';

export default function RuleEditRow( { rule, colSpan, onSave, onCancel } ) {
	const [ values, setValues ] = useState( {
		source: rule.source,
		target: rule.target || '',
		status_code: String( rule.status_code ),
		note: rule.note || '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const gone = isGone( values.status_code );

	const set = ( key ) => ( value ) => setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setErrors( {} );
		try {
			await onSave( {
				source: values.source.trim(),
				target: gone ? null : values.target.trim(),
				status_code: Number( values.status_code ),
				note: values.note,
			} );
		} catch ( error ) {
			setErrors( { [ fieldForError( error ) ]: errorMessage( error ) } );
			setBusy( false );
		}
	};

	return (
		<tr className="adv-redirects-editrow">
			<td colSpan={ colSpan }>
				<form
					className="adv-redirects-editrow__form"
					onSubmit={ save }
					onKeyDown={ ( event ) => event.key === 'Escape' && onCancel() }
				>
					<Field error={ errors.source }>
						<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Edit source', 'wp-redirects' ) } value={ values.source } onChange={ set( 'source' ) } />
					</Field>
					{ ! gone && (
						<Field error={ errors.target }>
							<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Edit target', 'wp-redirects' ) } value={ values.target } onChange={ set( 'target' ) } />
						</Field>
					) }
					<Field error={ errors.status_code }>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Edit status', 'wp-redirects' ) }
							value={ values.status_code }
							options={ STATUS_OPTIONS.map( ( option ) => ( { value: String( option.value ), label: option.label } ) ) }
							onChange={ set( 'status_code' ) }
						/>
					</Field>
					<Field>
						<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Note', 'wp-redirects' ) } value={ values.note } onChange={ set( 'note' ) } maxLength={ 255 } />
					</Field>
					<div className="adv-redirects-editrow__actions">
						<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
							{ __( 'Save', 'wp-redirects' ) }
						</Button>
						<Button variant="tertiary" onClick={ onCancel } __next40pxDefaultSize>
							{ __( 'Cancel', 'wp-redirects' ) }
						</Button>
					</div>
					{ errors.form && (
						<p className="adv-redirects-field__error" role="alert">
							{ errors.form }
						</p>
					) }
				</form>
			</td>
		</tr>
	);
}
```

The edit labels ("Edit source", …) differ from the quick-add labels on purpose, so `getByLabel( 'Source', { exact: true } )` stays unambiguous.

- [ ] **Step 7: Implement `assets/src/components/RulesTable.js`**

```js
import { Button, SearchControl, SelectControl, VisuallyHidden } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { errorMessage, STATUS_OPTIONS } from '../constants';
import { notifySaved } from '../utils/notifySaved';
import { DEFAULT_FILTERS, filterRules, isFiltered, moveItem, sortRules } from '../utils/rules';
import ConfirmModal from './ConfirmModal';
import RuleEditRow from './RuleEditRow';
import RuleRow from './RuleRow';

function SortableHeader( { label, column, sort, onSort, sortable } ) {
	if ( ! sortable ) {
		return <th scope="col">{ label }</th>;
	}
	const active = sort.orderby === column;
	let ariaSort = 'none';
	if ( active ) {
		ariaSort = sort.order === 'asc' ? 'ascending' : 'descending';
	}
	return (
		<th scope="col" aria-sort={ ariaSort }>
			<button
				type="button"
				className="adv-redirects-sort"
				onClick={ () => onSort( { orderby: column, order: active && sort.order === 'asc' ? 'desc' : 'asc' } ) }
			>
				{ label }
				<span aria-hidden="true">{ active && ( sort.order === 'asc' ? ' ↑' : ' ↓' ) }</span>
			</button>
		</th>
	);
}

export default function RulesTable( { mode, rules, highlightId, onUpdate, onRemove, onBulk, onReorder, notify } ) {
	const isRegex = mode === 'regex';
	const [ filters, setFilters ] = useState( DEFAULT_FILTERS );
	const [ sort, setSort ] = useState( { orderby: 'source', order: 'asc' } );
	const [ selected, setSelected ] = useState( [] );
	const [ editingId, setEditingId ] = useState( null );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ dragIndex, setDragIndex ] = useState( null );

	useEffect( () => {
		setSelected( ( current ) => current.filter( ( id ) => rules.some( ( rule ) => rule.id === id ) ) );
	}, [ rules ] );

	const filtered = isFiltered( filters );
	const reorderable = isRegex && ! filtered;
	const visible = useMemo( () => {
		const list = filterRules( rules, filters );
		return isRegex ? list : sortRules( list, sort );
	}, [ rules, filters, sort, isRegex ] );
	const visibleIds = visible.map( ( rule ) => rule.id );
	const allSelected = visibleIds.length > 0 && visibleIds.every( ( id ) => selected.includes( id ) );
	const colSpan = isRegex ? 9 : 8;
	const setFilter = ( key ) => ( value ) => setFilters( ( current ) => ( { ...current, [ key ]: value } ) );
	const reportError = ( error ) => notify( { status: 'error', message: errorMessage( error ) } );

	const toggleOne = ( id ) =>
		setSelected( ( current ) => ( current.includes( id ) ? current.filter( ( value ) => value !== id ) : [ ...current, id ] ) );

	const runBulk = async ( action ) => {
		try {
			const result = await onBulk( action, selected );
			setSelected( [] );
			if ( result.skipped.length ) {
				notify( {
					status: 'error',
					message: sprintf(
						/* translators: 1: number skipped, 2: reason */
						_n( '%1$d redirect was skipped: %2$s', '%1$d redirects were skipped: %2$s', result.skipped.length, 'wp-redirects' ),
						result.skipped.length,
						result.skipped[ 0 ].message
					),
				} );
			} else {
				notify( {
					/* translators: %d: number of redirects */
					message: sprintf( _n( '%d redirect updated.', '%d redirects updated.', result.updated, 'wp-redirects' ), result.updated ),
				} );
			}
		} catch ( error ) {
			reportError( error );
		}
	};

	const move = ( from, to ) => {
		if ( to < 0 || to >= rules.length ) {
			return;
		}
		onReorder( moveItem( rules.map( ( rule ) => rule.id ), from, to ) );
	};

	const saveEdit = async ( rule, patch ) => {
		const result = await onUpdate( rule.id, patch );
		setEditingId( null );
		notifySaved( result, { notify, onUpdate, message: __( 'Redirect updated.', 'wp-redirects' ) } );
	};

	const dragPropsFor = ( index ) =>
		reorderable
			? {
					draggable: true,
					onDragStart: () => setDragIndex( index ),
					onDragOver: ( event ) => event.preventDefault(),
					onDrop: ( event ) => {
						event.preventDefault();
						if ( dragIndex !== null && dragIndex !== index ) {
							move( dragIndex, index );
						}
						setDragIndex( null );
					},
					onDragEnd: () => setDragIndex( null ),
			  }
			: {};

	let emptyMessage = isRegex ? __( 'No regex redirects yet.', 'wp-redirects' ) : __( 'No exact redirects yet.', 'wp-redirects' );
	if ( filtered ) {
		emptyMessage = __( 'No redirects match these filters.', 'wp-redirects' );
	}

	return (
		<div className="adv-redirects-tablewrap">
			<div className="adv-redirects-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ isRegex ? __( 'Search regex redirects', 'wp-redirects' ) : __( 'Search redirects', 'wp-redirects' ) }
					value={ filters.search }
					onChange={ setFilter( 'search' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by status', 'wp-redirects' ) }
					value={ filters.status }
					options={ [
						{ value: 'all', label: __( 'All statuses', 'wp-redirects' ) },
						...STATUS_OPTIONS.map( ( option ) => ( { value: String( option.value ), label: String( option.value ) } ) ),
					] }
					onChange={ setFilter( 'status' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by state', 'wp-redirects' ) }
					value={ filters.enabled }
					options={ [
						{ value: 'all', label: __( 'Enabled and disabled', 'wp-redirects' ) },
						{ value: 'enabled', label: __( 'Enabled', 'wp-redirects' ) },
						{ value: 'disabled', label: __( 'Disabled', 'wp-redirects' ) },
					] }
					onChange={ setFilter( 'enabled' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Filter by origin', 'wp-redirects' ) }
					value={ filters.origin }
					options={ [
						{ value: 'all', label: __( 'Manual and automatic', 'wp-redirects' ) },
						{ value: 'manual', label: __( 'Added manually', 'wp-redirects' ) },
						{ value: 'auto', label: __( 'Created on slug change', 'wp-redirects' ) },
					] }
					onChange={ setFilter( 'origin' ) }
				/>
				{ selected.length > 0 && (
					<div className="adv-redirects-bulk" role="group" aria-label={ __( 'Bulk actions', 'wp-redirects' ) }>
						<span>
							{ /* translators: %d: number selected */ sprintf( _n( '%d selected', '%d selected', selected.length, 'wp-redirects' ), selected.length ) }
						</span>
						<Button variant="secondary" size="compact" onClick={ () => runBulk( 'enable' ) }>
							{ __( 'Enable', 'wp-redirects' ) }
						</Button>
						<Button variant="secondary" size="compact" onClick={ () => runBulk( 'disable' ) }>
							{ __( 'Disable', 'wp-redirects' ) }
						</Button>
						<Button variant="secondary" size="compact" isDestructive onClick={ () => setConfirmDelete( true ) }>
							{ __( 'Delete', 'wp-redirects' ) }
						</Button>
					</div>
				) }
			</div>

			{ visible.length === 0 ? (
				<p className="adv-redirects-empty">{ emptyMessage }</p>
			) : (
				<table className="adv-redirects-table">
					<thead>
						<tr>
							<td className="adv-redirects-col-check">
								<input
									type="checkbox"
									checked={ allSelected }
									onChange={ () => setSelected( allSelected ? [] : visibleIds ) }
									aria-label={ __( 'Select all', 'wp-redirects' ) }
								/>
							</td>
							{ isRegex && <th scope="col">{ __( 'Order', 'wp-redirects' ) }</th> }
							<th scope="col">{ __( 'On', 'wp-redirects' ) }</th>
							<SortableHeader label={ __( 'Source', 'wp-redirects' ) } column="source" sort={ sort } onSort={ setSort } sortable={ ! isRegex } />
							<SortableHeader label={ __( 'Target', 'wp-redirects' ) } column="target" sort={ sort } onSort={ setSort } sortable={ ! isRegex } />
							<SortableHeader label={ __( 'Status', 'wp-redirects' ) } column="status_code" sort={ sort } onSort={ setSort } sortable={ ! isRegex } />
							<SortableHeader label={ __( 'Hits', 'wp-redirects' ) } column="hits" sort={ sort } onSort={ setSort } sortable={ ! isRegex } />
							<SortableHeader label={ __( 'Last hit', 'wp-redirects' ) } column="last_hit_at" sort={ sort } onSort={ setSort } sortable={ ! isRegex } />
							<th scope="col">
								<VisuallyHidden>{ __( 'Actions', 'wp-redirects' ) }</VisuallyHidden>
							</th>
						</tr>
					</thead>
					<tbody>
						{ visible.map( ( rule, index ) =>
							editingId === rule.id ? (
								<RuleEditRow
									key={ rule.id }
									rule={ rule }
									colSpan={ colSpan }
									onSave={ ( patch ) => saveEdit( rule, patch ) }
									onCancel={ () => setEditingId( null ) }
								/>
							) : (
								<RuleRow
									key={ rule.id }
									rule={ rule }
									isRegex={ isRegex }
									index={ index }
									total={ visible.length }
									selected={ selected.includes( rule.id ) }
									highlighted={ highlightId === rule.id }
									reorderable={ reorderable }
									dragProps={ dragPropsFor( index ) }
									onSelect={ toggleOne }
									onToggle={ ( target ) => onUpdate( target.id, { enabled: ! target.enabled } ).catch( reportError ) }
									onEdit={ setEditingId }
									onDelete={ onRemove }
									onMove={ move }
									onFixChain={ ( target ) =>
										onUpdate( target.id, { target: target.chain.final } )
											.then( () => notify( { message: __( 'Redirect now points to the final destination.', 'wp-redirects' ) } ) )
											.catch( reportError )
									}
								/>
							)
						) }
					</tbody>
				</table>
			) }

			{ isRegex && filtered && <p className="description">{ __( 'Clear the search and filters to reorder.', 'wp-redirects' ) }</p> }

			{ confirmDelete && (
				<ConfirmModal
					title={ __( 'Delete redirects?', 'wp-redirects' ) }
					message={ sprintf(
						/* translators: %d: number of redirects */
						_n( 'Delete %d redirect? This cannot be undone.', 'Delete %d redirects? This cannot be undone.', selected.length, 'wp-redirects' ),
						selected.length
					) }
					confirmLabel={ __( 'Delete', 'wp-redirects' ) }
					onCancel={ () => setConfirmDelete( false ) }
					onConfirm={ () => {
						setConfirmDelete( false );
						runBulk( 'delete' );
					} }
				/>
			) }
		</div>
	);
}
```

- [ ] **Step 8: Implement `assets/src/components/RedirectsTab.js`**

```js
import { Button, Notice, Spinner } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { splitByType } from '../utils/rules';
import QuickAddForm from './QuickAddForm';
import RulesTable from './RulesTable';
import TestUrlBar from './TestUrlBar';

export default function RedirectsTab( { redirects, notify, prefill, onPrefillUsed } ) {
	const [ testPath, setTestPath ] = useState( '' );
	const [ highlightId, setHighlightId ] = useState( null );
	const { exact, regex } = useMemo( () => splitByType( redirects.items ), [ redirects.items ] );

	if ( redirects.loading ) {
		return (
			<div className="adv-redirects-loading">
				<Spinner />
			</div>
		);
	}

	if ( redirects.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ redirects.error }{ ' ' }
				<Button variant="link" onClick={ redirects.reload }>
					{ __( 'Try again', 'wp-redirects' ) }
				</Button>
			</Notice>
		);
	}

	const tableProps = {
		highlightId,
		notify,
		onUpdate: redirects.update,
		onRemove: redirects.remove,
		onBulk: redirects.bulk,
		onReorder: redirects.reorder,
	};

	return (
		<>
			<section className="adv-redirects-card">
				<TestUrlBar value={ testPath } onChange={ setTestPath } onResult={ setHighlightId } />
			</section>
			<section className="adv-redirects-card">
				<QuickAddForm
					onCreate={ redirects.create }
					onUpdate={ redirects.update }
					notify={ notify }
					testPath={ testPath }
					prefill={ prefill }
					onPrefillUsed={ onPrefillUsed }
				/>
			</section>
			{ redirects.items.length === 0 ? (
				<section className="adv-redirects-card adv-redirects-emptystate">
					<h2>{ __( 'No redirects yet', 'wp-redirects' ) }</h2>
					<p>
						{ __(
							'Add a source path and where it should go. Use Exact for single URLs, or Regex to match many URLs at once (for example ^/blog/(.*)$ → /news/$1).',
							'wp-redirects'
						) }
					</p>
				</section>
			) : (
				<>
					<section className="adv-redirects-card">
						<h2 className="adv-redirects-card__title">{ __( 'Exact redirects', 'wp-redirects' ) }</h2>
						<RulesTable mode="exact" rules={ exact } { ...tableProps } />
					</section>
					{ regex.length > 0 && (
						<section className="adv-redirects-card">
							<h2 className="adv-redirects-card__title">{ __( 'Regex redirects', 'wp-redirects' ) }</h2>
							<p className="description">
								{ __( 'Checked in order after exact redirects. The first match wins.', 'wp-redirects' ) }
							</p>
							<RulesTable mode="regex" rules={ regex } { ...tableProps } />
						</section>
					) }
					<p className="adv-redirects-footnote">{ __( 'Hit counts refresh every 5 minutes.', 'wp-redirects' ) }</p>
				</>
			) }
		</>
	);
}
```

- [ ] **Step 9: Render the Redirects tab in `assets/src/components/App.js`**

Replace the import block and the panel contents. The full file becomes:

```js
import { SnackbarList } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import RedirectsTab from './RedirectsTab';
import Tabs from './Tabs';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );
	const [ prefill, setPrefill ] = useState( null );
	const clearPrefill = useCallback( () => setPrefill( null ), [] );

	const tabs = [
		{ name: 'redirects', title: __( 'Redirects', 'wp-redirects' ), count: redirects.items.length },
		{ name: '404s', title: __( '404 Log', 'wp-redirects' ) },
		{ name: 'settings', title: __( 'Settings', 'wp-redirects' ) },
	];

	return (
		<div className="adv-redirects">
			<header className="adv-redirects__header">
				<h1>{ __( 'Redirects', 'wp-redirects' ) }</h1>
			</header>
			<Tabs tabs={ tabs } selected={ tab } onSelect={ setTab } />
			<div role="tabpanel" id={ `adv-redirects-panel-${ tab }` } aria-labelledby={ `adv-redirects-tab-${ tab }` } className="adv-redirects__panel">
				{ tab === 'redirects' && (
					<RedirectsTab redirects={ redirects } notify={ notify } prefill={ prefill } onPrefillUsed={ clearPrefill } />
				) }
			</div>
			<SnackbarList notices={ notices } onRemove={ dismiss } className="adv-redirects__snackbars" />
		</div>
	);
}
```

(`setPrefill` is wired to the 404 tab in Task 18.)

- [ ] **Step 10: Append the Redirects tab styles to `assets/src/admin.scss`**

```scss
.adv-redirects-card {
	background: var(--adv-surface);
	border: 1px solid var(--adv-border);
	border-radius: var(--adv-radius);
	padding: 16px 20px;

	&__title {
		font-size: 15px;
		margin: 0 0 12px;
	}

	.description {
		margin: -6px 0 12px;
	}
}

.adv-redirects-test {
	align-items: end;
	display: grid;
	gap: 12px;
	grid-template-columns: minmax(240px, 1fr) auto;

	&__result {
		color: var(--adv-muted);
		grid-column: 1 / -1;
		min-height: 20px;

		code {
			background: transparent;
			padding: 0;
		}
	}
}

.adv-redirects-quickadd {
	align-items: start;
	display: grid;
	gap: 12px;
	grid-template-columns: auto minmax(200px, 2fr) minmax(200px, 2fr) minmax(180px, 1fr) auto;

	> .components-button[type="submit"] {
		margin-top: 24px;
	}

	@media (max-width: 960px) {
		grid-template-columns: 1fr;

		> .components-button[type="submit"] {
			justify-self: start;
			margin-top: 0;
		}
	}
}

.adv-redirects-segmented {
	display: inline-flex;
	margin-top: 24px;

	.components-button {
		border-radius: 0;

		&:first-child {
			border-radius: 2px 0 0 2px;
		}

		&:last-child {
			border-radius: 0 2px 2px 0;
		}
	}
}

.adv-redirects-field__error,
.is-error {
	color: var(--adv-danger);
}

.adv-redirects-field__error {
	font-size: 12px;
	margin: 4px 0 0;
}

.adv-redirects-field.has-error .components-text-control__input {
	border-color: var(--adv-danger);
}

.adv-redirects-toolbar {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;

	.components-search-control {
		flex: 1 1 240px;
	}
}

.adv-redirects-bulk {
	align-items: center;
	display: flex;
	gap: 6px;
	margin-left: auto;
}

.adv-redirects-tablewrap {
	overflow-x: auto;
}

.adv-redirects-table {
	border-collapse: collapse;
	width: 100%;

	th,
	td {
		border-bottom: 1px solid #f0f0f1;
		padding: 8px;
		text-align: left;
		vertical-align: middle;
	}

	thead th,
	thead td {
		color: var(--adv-muted);
		font-size: 12px;
		font-weight: 500;
	}

	code {
		background: #f6f7f7;
		border-radius: 4px;
		font-size: 12px;
		padding: 2px 6px;
		word-break: break-all;
	}
}

.adv-redirects-sort {
	background: none;
	border: 0;
	color: inherit;
	cursor: pointer;
	font: inherit;
	padding: 0;

	&:focus-visible {
		outline: 2px solid var(--adv-accent);
	}
}

.adv-redirects-row {
	&.is-highlighted {
		background: color-mix(in srgb, var(--adv-accent) 8%, transparent);
	}

	&.is-disabled td:not(.adv-redirects-col-toggle):not(.adv-redirects-col-actions):not(.adv-redirects-col-check) {
		opacity: 0.55;
	}

	&[draggable="true"] {
		cursor: grab;
	}
}

.adv-redirects-col-check {
	width: 28px;
}

.adv-redirects-col-order {
	white-space: nowrap;
	width: 110px;

	svg {
		vertical-align: middle;
	}
}

.adv-redirects-position {
	color: var(--adv-muted);
	display: inline-block;
	min-width: 18px;
	text-align: center;
}

.adv-redirects-col-hits {
	font-variant-numeric: tabular-nums;
	text-align: right;
}

.adv-redirects-col-actions {
	text-align: right;
	white-space: nowrap;
}

.adv-redirects-note,
.adv-redirects-muted,
.adv-redirects-footnote {
	color: var(--adv-muted);
	font-size: 12px;
}

.adv-redirects-flags {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 4px;

	&:empty {
		display: none;
	}
}

.adv-redirects-flag {
	background: #f0f0f1;
	border-radius: 999px;
	font-size: 11px;
	padding: 1px 8px;

	&.is-warning {
		background: color-mix(in srgb, var(--adv-warning) 20%, transparent);
	}

	&.is-error {
		background: color-mix(in srgb, var(--adv-danger) 15%, transparent);
		color: var(--adv-danger);
	}
}

.adv-redirects-badge {
	border-radius: 4px;
	display: inline-block;
	font-size: 12px;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	padding: 2px 6px;

	&.is-permanent {
		background: color-mix(in srgb, var(--adv-success) 15%, transparent);
		color: #007017;
	}

	&.is-temporary {
		background: color-mix(in srgb, var(--adv-accent) 12%, transparent);
		color: var(--adv-accent);
	}

	&.is-gone {
		background: #f0f0f1;
		color: #50575e;
	}
}

.adv-redirects-editrow__form {
	align-items: start;
	display: grid;
	gap: 12px;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.adv-redirects-editrow__actions {
	align-self: end;
	display: flex;
	gap: 8px;
}

.adv-redirects-empty,
.adv-redirects-loading {
	color: var(--adv-muted);
	padding: 24px 0;
	text-align: center;
}

.adv-redirects-emptystate {
	text-align: center;

	p {
		color: var(--adv-muted);
		margin: 0 auto;
		max-width: 560px;
	}
}

.adv-redirects-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 16px;
}
```

- [ ] **Step 11: Build, lint, test**

Run: `npm run build && npm run lint:js && npm run test:js`
Expected: build succeeds, lint passes (run `npm run format` for formatting-only issues), `Tests: 25 passed`.

- [ ] **Step 12: Check the screen manually**

On http://localhost:8888/wp-admin/admin.php?page=adv-redirects:
1. Add exact `/a` → `/b` (301). The row appears without a page reload, and focus returns to Source.
2. Add `/b` → `/c`. The snackbar says it creates a chain and offers "Point directly to /c". Click it, and the `/a` row's target becomes `/c`.
3. Add `/c` → `/a`. An inline error under Target reads "Creates a loop: …".
4. Add a regex `^/blog/(\d+)$` → `/news/$1` with "/blog/5" typed in Test a URL. Help text under Source reads "Matches …".
5. Type `/blog/5` in Test a URL and press Enter. The result shows `301 /blog/5 → http://localhost:8888/news/5`, and the regex row is highlighted.
6. Toggle a rule off and on, then edit inline (Esc cancels).
7. Delete a rule and click **Undo**. Select two rows, click **Delete**, and confirm.
8. Add a second regex and reorder with the arrow buttons and by dragging. Reload the page and the order persists.

- [ ] **Step 13: Commit**

```bash
git add assets/src tests/js
git commit -m "feat: add redirects tab with quick add, test URL and rule tables

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 18: 404 Log and Settings tabs

**Files:**
- Create: `assets/src/components/NotFoundTab.js`, `assets/src/components/SettingsTab.js`, `assets/src/utils/settings.js`
- Modify (full replace): `assets/src/components/App.js`; append to `assets/src/admin.scss`
- Test: `tests/js/settings.test.js`

**Interfaces:**
- Consumes: `api.list404s`, `api.delete404`, `api.bulkDelete404s`, `api.clear404s`, `api.getSettings`, `api.saveSettings`, `timeAgo`, `ConfirmModal`, `errorMessage`
- Produces:
  - `<NotFoundTab settings notify onCreateRedirect(path) onOpenSettings />`
  - `<SettingsTab settings onSaved(settings) notify />`
  - `parseExtensions(text): string[]`, `formatExtensions(list): string`

- [ ] **Step 1: Write the failing test `tests/js/settings.test.js`**

```js
import { formatExtensions, parseExtensions } from '../../assets/src/utils/settings';

describe( 'extensions', () => {
	it( 'parses a comma list, lowercases, strips dots, drops invalid and duplicates', () => {
		expect( parseExtensions( ' .CSS, js,,bad ext!, png, css ' ) ).toEqual( [ 'css', 'js', 'png' ] );
	} );
	it( 'formats a list for editing', () => {
		expect( formatExtensions( [ 'css', 'js' ] ) ).toBe( 'css, js' );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- settings`
Expected: FAIL, module not found.

- [ ] **Step 3: Implement `assets/src/utils/settings.js`**

```js
export function parseExtensions( text ) {
	const seen = new Set();
	return String( text )
		.split( ',' )
		.map( ( value ) => value.trim().replace( /^\.+/, '' ).toLowerCase() )
		.filter( ( value ) => /^[a-z0-9]{1,10}$/.test( value ) && ! seen.has( value ) && seen.add( value ) );
}

export const formatExtensions = ( list ) => ( list || [] ).join( ', ' );
```

- [ ] **Step 4: Implement `assets/src/components/NotFoundTab.js`**

```js
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { parseGmt, timeAgo } from '../utils/time';
import ConfirmModal from './ConfirmModal';

const PER_PAGE = 20;

export default function NotFoundTab( { settings, notify, onCreateRedirect, onOpenSettings } ) {
	const [ query, setQuery ] = useState( { page: 1, search: '', orderby: 'hits', order: 'desc' } );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ data, setData ] = useState( { items: [], total: 0, pages: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ selected, setSelected ] = useState( [] );
	const [ confirm, setConfirm ] = useState( null );

	useEffect( () => {
		const timer = setTimeout( () => {
			const search = searchInput.trim();
			setQuery( ( current ) => ( current.search === search ? current : { ...current, search, page: 1 } ) );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ searchInput ] );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			setData( await api.list404s( { ...query, perPage: PER_PAGE } ) );
			setSelected( [] );
		} catch ( error ) {
			notify( { status: 'error', message: errorMessage( error ) } );
		} finally {
			setLoading( false );
		}
	}, [ query, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const run = async ( action, successMessage ) => {
		try {
			await action();
			notify( { message: successMessage } );
			load();
		} catch ( error ) {
			notify( { status: 'error', message: errorMessage( error ) } );
		}
	};

	const sortBy = ( orderby ) =>
		setQuery( ( current ) => ( {
			...current,
			orderby,
			order: current.orderby === orderby && current.order === 'desc' ? 'asc' : 'desc',
			page: 1,
		} ) );

	const ariaSort = ( column ) => {
		if ( query.orderby !== column ) {
			return 'none';
		}
		return query.order === 'asc' ? 'ascending' : 'descending';
	};

	const allSelected = data.items.length > 0 && data.items.every( ( item ) => selected.includes( item.id ) );

	return (
		<>
			{ settings && ! settings.log_404 && (
				<Notice status="warning" isDismissible={ false }>
					{ __( '404 logging is turned off.', 'wp-redirects' ) }{ ' ' }
					<Button variant="link" onClick={ onOpenSettings }>
						{ __( 'Turn it on in Settings', 'wp-redirects' ) }
					</Button>
				</Notice>
			) }
			<section className="adv-redirects-card">
				<div className="adv-redirects-toolbar">
					<SearchControl __nextHasNoMarginBottom label={ __( 'Search 404s', 'wp-redirects' ) } value={ searchInput } onChange={ setSearchInput } />
					<div className="adv-redirects-bulk">
						<Button
							variant="secondary"
							size="compact"
							isDestructive
							disabled={ selected.length === 0 }
							onClick={ () => setConfirm( 'selected' ) }
						>
							{ __( 'Delete selected', 'wp-redirects' ) }
						</Button>
						<Button variant="secondary" size="compact" isDestructive disabled={ data.total === 0 } onClick={ () => setConfirm( 'all' ) }>
							{ __( 'Clear log', 'wp-redirects' ) }
						</Button>
					</div>
				</div>

				{ loading && (
					<div className="adv-redirects-loading">
						<Spinner />
					</div>
				) }
				{ ! loading && data.items.length === 0 && (
					<p className="adv-redirects-empty">
						{ query.search ? __( 'No 404s match this search.', 'wp-redirects' ) : __( 'No 404s recorded. Nice.', 'wp-redirects' ) }
					</p>
				) }
				{ ! loading && data.items.length > 0 && (
					<div className="adv-redirects-tablewrap">
						<table className="adv-redirects-table">
							<thead>
								<tr>
									<td className="adv-redirects-col-check">
										<input
											type="checkbox"
											checked={ allSelected }
											onChange={ () => setSelected( allSelected ? [] : data.items.map( ( item ) => item.id ) ) }
											aria-label={ __( 'Select all', 'wp-redirects' ) }
										/>
									</td>
									<th scope="col">{ __( 'Path', 'wp-redirects' ) }</th>
									<th scope="col" aria-sort={ ariaSort( 'hits' ) }>
										<button type="button" className="adv-redirects-sort" onClick={ () => sortBy( 'hits' ) }>
											{ __( 'Hits', 'wp-redirects' ) }
										</button>
									</th>
									<th scope="col" aria-sort={ ariaSort( 'last_seen' ) }>
										<button type="button" className="adv-redirects-sort" onClick={ () => sortBy( 'last_seen' ) }>
											{ __( 'Last seen', 'wp-redirects' ) }
										</button>
									</th>
									<th scope="col">{ __( 'Last referrer', 'wp-redirects' ) }</th>
									<th scope="col">
										<span className="screen-reader-text">{ __( 'Actions', 'wp-redirects' ) }</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( item ) => {
									const lastSeen = parseGmt( item.last_seen );
									return (
										<tr key={ item.id } className="adv-redirects-row">
											<td className="adv-redirects-col-check">
												<input
													type="checkbox"
													checked={ selected.includes( item.id ) }
													onChange={ () =>
														setSelected( ( current ) =>
															current.includes( item.id ) ? current.filter( ( id ) => id !== item.id ) : [ ...current, item.id ]
														)
													}
													/* translators: %s: path */
													aria-label={ sprintf( __( 'Select %s', 'wp-redirects' ), item.path ) }
												/>
											</td>
											<td>
												<code>{ item.path }</code>
											</td>
											<td className="adv-redirects-col-hits">{ item.hits.toLocaleString() }</td>
											<td>
												<span title={ lastSeen ? lastSeen.toLocaleString() : undefined }>{ timeAgo( item.last_seen ) }</span>
											</td>
											<td className="adv-redirects-referrer">{ item.last_referrer || '—' }</td>
											<td className="adv-redirects-col-actions">
												<Button variant="secondary" size="compact" onClick={ () => onCreateRedirect( item.path ) }>
													{ __( 'Create redirect', 'wp-redirects' ) }
												</Button>
												<Button
													variant="tertiary"
													size="compact"
													isDestructive
													onClick={ () => run( () => api.delete404( item.id ), __( 'Entry deleted.', 'wp-redirects' ) ) }
												>
													{ __( 'Delete', 'wp-redirects' ) }
												</Button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }

				{ data.pages > 1 && (
					<nav className="adv-redirects-pagination" aria-label={ __( '404 log pages', 'wp-redirects' ) }>
						<Button
							variant="secondary"
							size="compact"
							disabled={ query.page <= 1 }
							onClick={ () => setQuery( ( current ) => ( { ...current, page: current.page - 1 } ) ) }
						>
							{ __( 'Previous', 'wp-redirects' ) }
						</Button>
						<span>
							{ /* translators: 1: current page, 2: total pages */ sprintf( __( 'Page %1$d of %2$d', 'wp-redirects' ), query.page, data.pages ) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							disabled={ query.page >= data.pages }
							onClick={ () => setQuery( ( current ) => ( { ...current, page: current.page + 1 } ) ) }
						>
							{ __( 'Next', 'wp-redirects' ) }
						</Button>
					</nav>
				) }
			</section>

			{ confirm && (
				<ConfirmModal
					title={ confirm === 'all' ? __( 'Clear the 404 log?', 'wp-redirects' ) : __( 'Delete selected entries?', 'wp-redirects' ) }
					message={
						confirm === 'all'
							? __( 'Every logged 404 will be removed. This cannot be undone.', 'wp-redirects' )
							: sprintf(
									/* translators: %d: number of entries */
									_n( 'Delete %d entry? This cannot be undone.', 'Delete %d entries? This cannot be undone.', selected.length, 'wp-redirects' ),
									selected.length
							  )
					}
					confirmLabel={ confirm === 'all' ? __( 'Clear log', 'wp-redirects' ) : __( 'Delete', 'wp-redirects' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						const which = confirm;
						setConfirm( null );
						run(
							which === 'all' ? api.clear404s : () => api.bulkDelete404s( selected ),
							which === 'all' ? __( '404 log cleared.', 'wp-redirects' ) : __( 'Entries deleted.', 'wp-redirects' )
						);
					} }
				/>
			) }
		</>
	);
}
```

- [ ] **Step 5: Implement `assets/src/components/SettingsTab.js`**

```js
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { formatExtensions, parseExtensions } from '../utils/settings';

export default function SettingsTab( { settings, onSaved, notify } ) {
	const [ values, setValues ] = useState( settings );
	const [ extensions, setExtensions ] = useState( formatExtensions( settings.excluded_404_extensions ) );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		setValues( settings );
		setExtensions( formatExtensions( settings.excluded_404_extensions ) );
	}, [ settings ] );

	const set = ( key ) => ( value ) => setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			const saved = await api.saveSettings( {
				...values,
				log_404_retention_days: Number( values.log_404_retention_days ),
				log_404_max_rows: Number( values.log_404_max_rows ),
				excluded_404_extensions: parseExtensions( extensions ),
			} );
			onSaved( saved );
			notify( { message: __( 'Settings saved.', 'wp-redirects' ) } );
		} catch ( error ) {
			notify( { status: 'error', message: errorMessage( error ) } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form className="adv-redirects-card adv-redirects-settings" onSubmit={ save }>
			<h2 className="adv-redirects-card__title">{ __( 'Redirects', 'wp-redirects' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Create a redirect when a published URL changes', 'wp-redirects' ) }
				help={ __( 'Adds a 301 from the old permalink when you change a slug or parent page.', 'wp-redirects' ) }
				checked={ values.slug_watcher }
				onChange={ set( 'slug_watcher' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Forward query strings', 'wp-redirects' ) }
				help={ __( 'Keeps ?utm_source=… and other parameters when redirecting.', 'wp-redirects' ) }
				checked={ values.forward_query_string }
				onChange={ set( 'forward_query_string' ) }
			/>

			<h2 className="adv-redirects-card__title">{ __( '404 log', 'wp-redirects' ) }</h2>
			<ToggleControl __nextHasNoMarginBottom label={ __( 'Log 404s', 'wp-redirects' ) } checked={ values.log_404 } onChange={ set( 'log_404' ) } />
			<div className="adv-redirects-settings__row">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					max={ 365 }
					label={ __( 'Keep entries for (days)', 'wp-redirects' ) }
					value={ String( values.log_404_retention_days ) }
					onChange={ set( 'log_404_retention_days' ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 100 }
					max={ 100000 }
					label={ __( 'Maximum entries', 'wp-redirects' ) }
					value={ String( values.log_404_max_rows ) }
					onChange={ set( 'log_404_max_rows' ) }
				/>
			</div>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Ignore these file extensions', 'wp-redirects' ) }
				help={ __( 'Comma-separated, for example: css, js, png', 'wp-redirects' ) }
				value={ extensions }
				onChange={ setExtensions }
			/>

			<h2 className="adv-redirects-card__title">{ __( 'Uninstall', 'wp-redirects' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Remove all redirects and settings when the plugin is deleted', 'wp-redirects' ) }
				help={ __( 'Leave off unless you are sure. Deactivating never removes data.', 'wp-redirects' ) }
				checked={ values.remove_data_on_uninstall }
				onChange={ set( 'remove_data_on_uninstall' ) }
			/>

			<div>
				<Button variant="primary" type="submit" isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
					{ __( 'Save settings', 'wp-redirects' ) }
				</Button>
			</div>
		</form>
	);
}
```

- [ ] **Step 6: Replace `assets/src/components/App.js`**

```js
import { SnackbarList, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import { errorMessage } from '../constants';
import { useNotices } from '../state/useNotices';
import { useRedirects } from '../state/useRedirects';
import NotFoundTab from './NotFoundTab';
import RedirectsTab from './RedirectsTab';
import SettingsTab from './SettingsTab';
import Tabs from './Tabs';

export default function App() {
	const { notices, notify, dismiss } = useNotices();
	const redirects = useRedirects( notify );
	const [ tab, setTab ] = useState( 'redirects' );
	const [ prefill, setPrefill ] = useState( null );
	const [ settings, setSettings ] = useState( null );

	useEffect( () => {
		api.getSettings()
			.then( setSettings )
			.catch( ( error ) => notify( { status: 'error', message: errorMessage( error ) } ) );
	}, [ notify ] );

	const clearPrefill = useCallback( () => setPrefill( null ), [] );
	const createFrom404 = useCallback( ( path ) => {
		setPrefill( path );
		setTab( 'redirects' );
	}, [] );

	const tabs = [
		{ name: 'redirects', title: __( 'Redirects', 'wp-redirects' ), count: redirects.items.length },
		{ name: '404s', title: __( '404 Log', 'wp-redirects' ) },
		{ name: 'settings', title: __( 'Settings', 'wp-redirects' ) },
	];

	return (
		<div className="adv-redirects">
			<header className="adv-redirects__header">
				<h1>{ __( 'Redirects', 'wp-redirects' ) }</h1>
			</header>
			<Tabs tabs={ tabs } selected={ tab } onSelect={ setTab } />
			<div role="tabpanel" id={ `adv-redirects-panel-${ tab }` } aria-labelledby={ `adv-redirects-tab-${ tab }` } className="adv-redirects__panel">
				{ tab === 'redirects' && (
					<RedirectsTab redirects={ redirects } notify={ notify } prefill={ prefill } onPrefillUsed={ clearPrefill } />
				) }
				{ tab === '404s' && (
					<NotFoundTab settings={ settings } notify={ notify } onCreateRedirect={ createFrom404 } onOpenSettings={ () => setTab( 'settings' ) } />
				) }
				{ tab === 'settings' &&
					( settings ? (
						<SettingsTab settings={ settings } onSaved={ setSettings } notify={ notify } />
					) : (
						<div className="adv-redirects-loading">
							<Spinner />
						</div>
					) ) }
			</div>
			<SnackbarList notices={ notices } onRemove={ dismiss } className="adv-redirects__snackbars" />
		</div>
	);
}
```

- [ ] **Step 7: Append the 404 and Settings styles to `assets/src/admin.scss`**

```scss
.adv-redirects-referrer {
	color: var(--adv-muted);
	font-size: 12px;
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.adv-redirects-pagination {
	align-items: center;
	display: flex;
	gap: 12px;
	justify-content: flex-end;
	padding-top: 12px;
}

.adv-redirects-settings {
	display: grid;
	gap: 16px;
	max-width: 720px;

	.adv-redirects-card__title {
		border-top: 1px solid #f0f0f1;
		margin: 8px 0 0;
		padding-top: 16px;

		&:first-child {
			border-top: 0;
			padding-top: 0;
		}
	}

	&__row {
		display: grid;
		gap: 16px;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	}
}
```

- [ ] **Step 8: Build, lint, test**

Run: `npm run build && npm run lint:js && npm run test:js`
Expected: `Tests: 27 passed`. Lint passes.

- [ ] **Step 9: Check the screen manually**

1. Visit http://localhost:8888/this-page-does-not-exist twice, and http://localhost:8888/missing.css once.
2. On the **404 Log** tab, `/this-page-does-not-exist` shows 2 hits and `missing.css` is absent. Search narrows the list. Sorting by Hits and Last seen toggles direction.
3. Click **Create redirect**. The app switches to Redirects with Source prefilled. Add a target and save. Back on 404 Log, the entry is gone.
4. On **Settings**, turn off 404 logging and save. The 404 Log tab shows the warning notice with a link back to Settings. Enter `0` for days and save, and an error snackbar appears. Turn logging back on.

- [ ] **Step 10: Commit**

```bash
git add assets/src tests/js
git commit -m "feat: add 404 log and settings tabs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 19: End-to-end smoke tests

**Files:**
- Create: `playwright.config.js`, `tests/e2e/redirects.spec.js`

**Interfaces:**
- Consumes: the accessible names from Task 17, the REST API, and the wp-env tests site at http://localhost:8889

- [ ] **Step 1: Create `playwright.config.js`**

```js
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		command: 'npm run env:start',
		port: 8889,
		timeout: 180000,
		reuseExistingServer: true,
	},
} );
```

- [ ] **Step 2: Write `tests/e2e/redirects.spec.js`**

```js
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

async function deleteAllRedirects( requestUtils ) {
	const rules = await requestUtils.rest( { path: '/adv-redirects/v1/redirects' } );
	for ( const rule of rules ) {
		await requestUtils.rest( { path: `/adv-redirects/v1/redirects/${ rule.id }`, method: 'DELETE' } );
	}
}

test.describe( 'Redirects admin', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteAllRedirects( requestUtils );
	} );

	test( 'adds an exact redirect, tests it, and the site redirects', async ( { admin, page, request } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );

		await page.getByLabel( 'Source', { exact: true } ).fill( '/e2e-old' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/e2e-new' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect( page.getByRole( 'cell', { name: '/e2e-old' } ) ).toBeVisible();

		await page.getByLabel( 'Test a URL' ).fill( '/e2e-old' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await expect( page.locator( '.adv-redirects-test__result' ) ).toContainText( '/e2e-new' );

		const response = await request.get( '/e2e-old?utm_source=e2e', { maxRedirects: 0 } );
		expect( response.status() ).toBe( 301 );
		expect( response.headers().location ).toMatch( /\/e2e-new\?utm_source=e2e$/ );
		expect( response.headers()[ 'x-redirect-by' ] ).toBe( 'WP Redirects' );
	} );

	test( 'shows an inline error when a redirect would loop', async ( { admin, page, requestUtils } ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: { type: 'exact', source: '/loop-a', target: '/loop-b', status_code: 301 },
		} );

		await admin.visitAdminPage( 'admin.php', 'page=adv-redirects' );
		await page.getByLabel( 'Source', { exact: true } ).fill( '/loop-b' );
		await page.getByLabel( 'Target', { exact: true } ).fill( '/loop-a' );
		await page.getByRole( 'button', { name: 'Add redirect' } ).click();

		await expect( page.getByRole( 'alert' ).filter( { hasText: 'Creates a loop' } ) ).toBeVisible();
	} );

	test( 'a 410 rule returns 410 Gone', async ( { requestUtils, request } ) => {
		await requestUtils.rest( {
			path: '/adv-redirects/v1/redirects',
			method: 'POST',
			data: { type: 'exact', source: '/e2e-gone', target: null, status_code: 410 },
		} );
		const response = await request.get( '/e2e-gone', { maxRedirects: 0 } );
		expect( response.status() ).toBe( 410 );
	} );
} );
```

- [ ] **Step 3: Install the browser and run**

Run: `npx playwright install chromium && npm run build && npm run test:e2e`
Expected: `3 passed`. If `request.get` receives a 404 HTML page instead of a redirect, the tests site isn't routing pretty URLs. Run `npx wp-env run tests-cli wp rewrite structure '/%postname%/'` and re-run.

- [ ] **Step 4: Commit**

```bash
git add playwright.config.js tests/e2e
git commit -m "test: add end-to-end smoke tests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 20: UI design pass with Impeccable (human checkpoint)

**Files:**
- Modify: `assets/src/**` (styles and markup only), `.distignore`
- Create: whatever design-context files `/impeccable init` writes (for example `DESIGN.md` / `.impeccable/`)

**Interfaces:**
- Consumes: the working admin UI from Tasks 16–19
- Must preserve:
  - every accessible name listed in Task 17's Produces block
  - the `.adv-redirects-test__result` class
  - the REST contracts
  - all `@wordpress/components` usage stable in WP 6.6 (no `__experimental*`)

This task is interactive. The human answers Impeccable's questions. Do not invent answers.

- [ ] **Step 1: Initialize Impeccable**

Invoke the `impeccable` skill with argument `init` (the user asked for `/impeccable init`). Pass this context and ask the user to confirm or correct it:
- Product: a redirect manager inside wp-admin, used by SEO and content managers, often under time pressure.
- Must feel native to WordPress admin (uses `@wordpress/components` and respects `--wp-admin-theme-color`), and be dense, fast and keyboard-friendly.
- Main surfaces: the Test URL bar, the quick-add form, the exact and regex rule tables, the 404 Log and Settings.
- Constraints: no new runtime dependencies without approval, no external fonts or CDNs (wp-admin pages must not call third parties), WCAG AA contrast, works from 782px width upward (the WP admin mobile breakpoint).

- [ ] **Step 2: Run the design critique and polish passes Impeccable recommends**

Run them against http://localhost:8888/wp-admin/admin.php?page=adv-redirects with seeded data. Seed it first:

```bash
npx wp-env run cli wp eval '
foreach ( range( 1, 40 ) as $i ) {
	adv_redirects_add( [ "type" => "exact", "source" => "/old-$i", "target" => "/new-$i", "status_code" => $i % 5 ? 301 : 302 ] );
}
adv_redirects_add( [ "type" => "regex", "source" => "^/blog/(\\d+)$", "target" => "/news/$1", "status_code" => 301 ] );
adv_redirects_add( [ "type" => "exact", "source" => "/retired", "target" => null, "status_code" => 410 ] );
'
```

Apply only changes inside `assets/src/`. Keep every constraint from the Interfaces block above.

- [ ] **Step 3: Keep design-context files out of the release zip**

Append any new top-level design files to `.distignore`, for example:
```
/DESIGN.md
/.impeccable
```

- [ ] **Step 4: Verify nothing regressed**

Run: `npm run build && npm run lint:js && npm run test:js && npm run test:e2e`
Expected: all pass. Repeat the Task 17 Step 12 and Task 18 Step 9 manual checks, then ask the user to review the screen before committing.

- [ ] **Step 5: Commit**

Run `git status`, then stage the changed files under `assets/src/`, `.distignore`, and any design-context files Impeccable created (for example `git add assets/src .distignore DESIGN.md`).

```bash
git commit -m "style: refine admin UI with impeccable design pass

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 21: CI, release pipeline, final docs

**Files:**
- Create: `.github/workflows/ci.yml`, `.github/workflows/release.yml`
- Modify: `CLAUDE.md` (status and design notes), `readme.txt` (if anything changed)

**Interfaces:**
- Consumes: npm/composer scripts from Tasks 1, 6, 16 and 19; `.distignore`
- Produces: CI on push/PR to `main`, and a GitHub Release with `wp-redirects-X.Y.Z.zip` + `.sha256` on tag `vX.Y.Z`

- [ ] **Step 1: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer lint
      - run: composer audit
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - run: npm run lint:js
      - run: npm audit --omit=dev --audit-level=high
      - run: npm run test:js
      - run: npm run build

  unit:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer test:unit

  integration:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        include:
          - { wp: '6.6', php: '7.4' }
          - { wp: 'latest', php: '8.2' }
          - { wp: 'latest', php: '8.3' }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - name: Configure wp-env for WP ${{ matrix.wp }} / PHP ${{ matrix.php }}
        run: |
          if [ "${{ matrix.wp }}" = "latest" ]; then CORE=null; else CORE="\"WordPress/WordPress#${{ matrix.wp }}\""; fi
          echo "{ \"core\": ${CORE}, \"phpVersion\": \"${{ matrix.php }}\" }" > .wp-env.override.json
          cat .wp-env.override.json
      - run: npx wp-env start
      - run: npm run test:php:integration

  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
          coverage: none
      - run: composer install --no-interaction --prefer-dist --no-dev
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - run: npm run build
      - run: npx playwright install --with-deps chromium
      - run: npm run env:start
      - run: npm run test:e2e
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-artifacts
          path: artifacts/
```

- [ ] **Step 2: Create `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags: ['v*.*.*']

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Verify version numbers match the tag
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          grep -qE "^ \* Version:[[:space:]]+${VERSION}$" wp-redirects.php || { echo "Plugin header Version does not match ${VERSION}"; exit 1; }
          grep -qF "define( 'ADV_REDIRECTS_VERSION', '${VERSION}' );" wp-redirects.php || { echo "ADV_REDIRECTS_VERSION does not match ${VERSION}"; exit 1; }
          grep -qE "^Stable tag: ${VERSION}$" readme.txt || { echo "readme.txt Stable tag does not match ${VERSION}"; exit 1; }
          echo "VERSION=${VERSION}" >> "$GITHUB_ENV"

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
          tools: composer:v2
          coverage: none

      - name: Unit tests
        run: |
          composer install --no-interaction --prefer-dist
          composer test:unit

      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - run: npm run build

      - name: Install runtime dependencies only
        run: composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

      - name: Package
        run: |
          mkdir -p dist/wp-redirects
          rsync -a --exclude-from=.distignore ./ dist/wp-redirects/
          test -f dist/wp-redirects/build/index.js
          test -f dist/wp-redirects/build/index.asset.php
          test -f dist/wp-redirects/vendor/autoload.php
          test ! -d dist/wp-redirects/node_modules
          test ! -d dist/wp-redirects/tests
          cd dist
          zip -rq "wp-redirects-${VERSION}.zip" wp-redirects
          sha256sum "wp-redirects-${VERSION}.zip" > "wp-redirects-${VERSION}.zip.sha256"

      - name: Publish GitHub Release
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          gh release create "${GITHUB_REF_NAME}" \
            "dist/wp-redirects-${VERSION}.zip" \
            "dist/wp-redirects-${VERSION}.zip.sha256" \
            --title "WP Redirects ${VERSION}" \
            --generate-notes \
            --verify-tag
```

- [ ] **Step 3: Dry-run the packaging locally**

Run:
```bash
npm run build && composer install --no-dev --optimize-autoloader && rm -rf dist && mkdir -p dist/wp-redirects && rsync -a --exclude-from=.distignore ./ dist/wp-redirects/ && (cd dist && zip -rq wp-redirects-test.zip wp-redirects) && unzip -l dist/wp-redirects-test.zip | head -40; composer install
```
Expected: the zip contains `wp-redirects/wp-redirects.php`, `uninstall.php`, `readme.txt`, `src/`, `build/`, `vendor/`, `docs/hooks.md`. It must not contain `assets/src`, `tests`, `node_modules`, `CLAUDE.md` or `docs/superpowers`. The final `composer install` restores dev dependencies.

- [ ] **Step 4: Sanity-check the extracted zip**

**Do not** install the zip into wp-env. The `wp-content/plugins/wp-redirects` folder there is a bind mount of this repository, and `wp plugin install --force` would delete it, taking your working copy with it.

Run:
```bash
SCRATCH="$(mktemp -d)" && unzip -q dist/wp-redirects-test.zip -d "$SCRATCH" \
  && find "$SCRATCH/wp-redirects" -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' ; \
  grep -E '^ \* Version:' "$SCRATCH/wp-redirects/wp-redirects.php" && rm -rf "$SCRATCH" dist
```
Expected: no output from `php -l` apart from the version line ` * Version:           0.1.0`. For a real install test, use a throwaway site (for example the `wp-lab` skill) and upload the zip through Plugins → Add New → Upload.

- [ ] **Step 5: Update `CLAUDE.md`**

Add this section above "## Releases":

```markdown
## Testing notes

- Integration tests run inside wp-env (`npm run env:start` first). The REST base class `tests/integration/support/RestTestCase.php` uses the plugin's own route registration.
- Hit counting buffers in the object cache only when `wp_using_ext_object_cache()` is true; tests toggle it.
- e2e relies on these accessible names: Source, Target, Test a URL, Add redirect, Test. Keep them stable or update `tests/e2e/redirects.spec.js`.

## Design

- Admin UI conventions live in the files written by `/impeccable init` (see Task 20 of the plan). Re-run Impeccable passes for UI changes.
```

- [ ] **Step 6: Commit**

```bash
git add .github CLAUDE.md readme.txt
git commit -m "ci: add CI matrix and tag-driven release packaging

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Push and open a PR (ask the user first)**

Pushing publishes to the public repo, so confirm with the user before running:

```bash
git push -u origin feat/initial-plugin
gh pr create --base main --title "WP Redirects v0.1.0" --body "$(cat <<'EOF'
Initial implementation of WP Redirects per docs/superpowers/specs/2026-10-02-wp-redirects-design.md.

- Exact and regex redirects (301/302/307/308/410/451) with an object-cached compiled rule set
- Hit counts, slug-change auto redirects, loop/chain detection, Test URL tool, 404 log
- React admin (wp-components), REST API, hooks API (docs/hooks.md)
- GitHub-release self-updates; CI matrix PHP 7.4–8.3 × WP 6.6/latest; tag-driven release zips

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

`main` on the remote has no commits yet. If `gh pr create` fails because the base branch doesn't exist, push `main` first (`git push -u origin main`, which carries only the spec and plan commits) and re-run. After the PR merges and CI is green on `main`, tag `v0.1.0` to publish the first release.
