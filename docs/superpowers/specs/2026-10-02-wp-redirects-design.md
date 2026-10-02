# WP Redirects — Design Spec

- **Date:** 2026-10-02
- **Repo:** `git@github.com:advision-development/wp-redirects.git` (public)
- **Status:** Approved in brainstorming, pending written-spec review

## 1. Purpose & success criteria

A WordPress redirect manager comparable to Yoast SEO Premium's redirect feature, owned by Advision.

**Success means:**

1. Admins can create exact path/URL redirects and regex redirects, each with a selectable status code.
2. Matching a request never hits the database when a persistent object cache is present.
3. The admin UI is clean, fast (no page reloads, instant search), and easy to use.
4. Other code can extend the plugin through documented actions and filters.
5. Releases are built in CI and installed/updated from GitHub Releases with built assets included.
6. No security concessions anywhere.

**Target environment:** single WordPress sites (not multisite) with a persistent object cache (Redis/Memcached). At most a few hundred redirects per site. Primary site runs PHP 8.2 / WP 7.1.

## 2. Scope

### In v1

- Exact and regex redirects
- Status codes: 301, 302, 307, 308, 410 (Gone), 451 (Unavailable For Legal Reasons)
- Object-cache-backed compiled rule set
- Hit counter + last-hit date per rule
- Auto-redirect on post/page slug change (slug watcher)
- Loop rejection and chain detection
- Test-a-URL tool
- 404 log
- Plugin hooks (actions/filters) + small PHP API
- Self-updates from GitHub Releases
- CI + release pipeline

### Out of v1 (candidates for v2)

- CSV import/export, and export of any kind (JSON import from the Redirection plugin is in scope; see §17)
- Taxonomy term slug-change redirects
- Redirect-on-trash/delete prompts
- Multisite network support

## 3. Compatibility

- **PHP 7.4+** (tested on 7.4, 8.2, 8.3). No PHP 8-only syntax: no `match`, constructor promotion, union types, named arguments, `mixed`, nullsafe operator. Typed properties and arrow functions are allowed. `str_contains`/`str_starts_with` are fine (WP polyfills since 5.9).
- **WordPress 6.6+** (tested on 6.6 and latest, currently 7.1). 6.6 is the first release that registers the `react-jsx-runtime` script current `@wordpress/scripts` builds depend on. The admin app uses the site's own `wp-element`/`wp-components`/`wp-api-fetch`, so it uses only component APIs stable since 6.6.
- Plugin header: `Requires at least: 6.6`, `Requires PHP: 7.4`.

## 4. Naming

| Thing | Name |
|---|---|
| Plugin slug / folder / text domain | `wp-redirects` |
| PHP namespace | `Advision\Redirects` |
| Hook / function prefix | `adv_redirects_` |
| Tables | `{$wpdb->prefix}adv_redirects`, `{$wpdb->prefix}adv_redirects_404s` |
| Settings option | `adv_redirects_settings` |
| Schema version option | `adv_redirects_db_version` |
| Object cache group | `adv_redirects` |
| REST namespace | `adv-redirects/v1` |

## 5. Architecture

### 5.1 File layout

```
wp-redirects.php                 bootstrap: header, ABSPATH guard, autoloader, activation/deactivation, boot
uninstall.php                    removes data only if "remove data on uninstall" setting is on
src/
  Plugin.php                     wires units; exposes repository/cache for the PHP API
  Autoloader.php                 PSR-4 for Advision\Redirects\ → src/
  Schema.php                     dbDelta install/upgrade; version option
  Settings.php                   typed access to adv_redirects_settings with defaults + sanitization
  functions.php                  public PHP API: adv_redirects_add(), _delete(), _flush_cache()
  Redirects/
    Rule.php                     value object (id, type, source, target, status, position, enabled, origin, note, hits, last_hit_at, timestamps)
    Repository.php               CRUD on adv_redirects; every write flushes RuleCache and fires hooks
    Validator.php                field validation, regex compile check, target safety, loop/chain detection
  Matching/
    PathNormalizer.php           pure: REQUEST_URI + home path → normalized path, exact key, query
    Matcher.php                  pure: (normalized request, compiled ruleset) → MatchResult|null
    MatchResult.php              value object: rule id, status, resolved target, captured groups
    TargetResolver.php           pure: $n substitution, relative → absolute, query forwarding, host guard
    RuleCache.php                compile ruleset from Repository; get/set/flush object cache
    Redirector.php               init hook: guards, normalize, match, respond; 410/451 handling
  Tracking/
    HitTracker.php               buffered hit counting + cron flush
    NotFoundRepository.php       CRUD/upsert/prune on adv_redirects_404s
    NotFoundLogger.php           template_redirect 404 capture
  SlugWatcher.php                auto-redirects on permalink change
  Cron.php                       schedules: hit flush (5 min), 404 prune (daily); unschedule on deactivate
  Rest/
    RedirectsController.php      list/create/update/delete/bulk/reorder
    NotFoundController.php       list/delete/bulk/clear
    TestController.php           test-a-URL
    SettingsController.php       get/update settings
  Admin/
    AdminPage.php                menu page, mount div, enqueue built assets
  Updates/
    UpdateChecker.php            plugin-update-checker bootstrap (GitHub release assets)
assets/src/                      React admin app source
build/                           compiled admin app (gitignored; produced by CI)
tests/
  unit/                          plain PHPUnit, no WordPress
  integration/                   WP test suite via @wordpress/env
  js/                            Jest
  e2e/                           Playwright smoke tests
docs/hooks.md                    hook reference
```

Pure units (`PathNormalizer`, `Matcher`, `TargetResolver`) make no WordPress calls. Inputs such as home path and host are passed in, so they can be unit-tested without WP.

### 5.2 Data model

**`{prefix}adv_redirects`**

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `type` | `VARCHAR(10)` | `exact` \| `regex` |
| `source` | `VARCHAR(2048)` | exact: stored as entered (normalized key derived at compile); regex: pattern without delimiters |
| `target` | `VARCHAR(2048) NULL` | `NULL` for 410/451 |
| `status_code` | `SMALLINT UNSIGNED` | one of 301, 302, 307, 308, 410, 451 |
| `position` | `INT UNSIGNED` | regex evaluation order (ascending); ignored for exact |
| `enabled` | `TINYINT(1)` | default 1 |
| `origin` | `VARCHAR(10)` | `manual` \| `auto` |
| `note` | `VARCHAR(255)` | default `''` |
| `hits` | `BIGINT UNSIGNED` | default 0 |
| `last_hit_at` | `DATETIME NULL` | UTC |
| `created_at` / `updated_at` | `DATETIME` | UTC |

Indexes: `PRIMARY(id)`, `KEY type_enabled (type, enabled)`.

**`{prefix}adv_redirects_404s`**

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `path` | `VARCHAR(2048)` | path + query, control chars stripped |
| `path_hash` | `CHAR(32)` | md5 of `path`; `UNIQUE` (upsert key) |
| `hits` | `BIGINT UNSIGNED` | |
| `first_seen` / `last_seen` | `DATETIME` | UTC; `KEY last_seen` |
| `last_referrer` | `VARCHAR(2048)` | control chars stripped, may be `''` |

No IP addresses or user agents are stored.

**Settings (`adv_redirects_settings`)**, with defaults:

| Key | Default |
|---|---|
| `slug_watcher` | `true` |
| `log_404` | `true` |
| `log_404_retention_days` | `30` (1–365) |
| `log_404_max_rows` | `5000` (100–100000) |
| `forward_query_string` | `true` |
| `excluded_404_extensions` | `css, js, map, png, jpg, jpeg, gif, webp, avif, svg, ico, woff, woff2, ttf, eot` |
| `remove_data_on_uninstall` | `false` |

## 6. Request flow

### 6.1 Hook point & guards

`Redirector` runs on `init` at priority 1. The theme has loaded by then, so its filters on our hooks apply, and WordPress hasn't parsed the request or run the main query yet.

It returns immediately when any of these hold:

- `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `defined('WP_CLI')`
- the request path starts with the REST prefix (`rest_get_url_prefix()`), or the script is `wp-login.php`
- the request method is not in `adv_redirects_allowed_methods` (default `GET`, `HEAD`)
- `adv_redirects_should_handle_request` returns false

### 6.2 Normalization (`PathNormalizer`)

1. Read `REQUEST_URI`. Reject (no match) if longer than 2048 chars.
2. Split into path and query.
3. Strip the site's home path prefix (subdirectory installs).
4. Percent-decode the path.
5. **Exact key** = lowercased path, trailing slash trimmed (root stays `/`).
6. Apply the `adv_redirects_request_path` filter.

Exact sources are normalized the same way at compile time, so matching is case- and trailing-slash-insensitive.

### 6.3 Compiled rule set (`RuleCache`)

- `wp_cache_get('ruleset', 'adv_redirects')`. On a miss: one `SELECT` of enabled rules, compile, apply the `adv_redirects_compiled_ruleset` filter, `wp_cache_set` with no expiry.
- Compiled shape:

  ```php
  [
    'exact'     => [ '<exact key>' => [ 'id' => int, 'target' => ?string, 'status' => int ], ... ],
    'has_query' => bool,   // true if any exact source contains '?'
    'regex'     => [ [ 'id' => int, 'pattern' => '~...~i', 'target' => ?string, 'status' => int ], ... ], // position order
  ]
  ```

- **Invalidation:** every Repository write (create, update, delete, bulk, reorder, slug watcher) calls `RuleCache::flush()` and fires `adv_redirects_cache_flushed`. There is no TTL.
- If no persistent object cache is available, behavior stays correct and costs one small query per request.
- If both `exact` and `regex` are empty, return with no further work.

### 6.4 Match order (`Matcher`)

1. If `has_query` and the request has a query: exact lookup on `key?query`.
2. Exact lookup on the key.
3. Regex rules in `position` order, matched against the decoded path (subject capped at 2048 chars). First match wins.
4. Apply the `adv_redirects_match` filter (can replace or null out the match).

Regex runtime safety:
- Check `preg_last_error()` after each `preg_match`.
- A rule that errors is skipped. It's logged once per request via `error_log`, without the request path, and never causes a fatal error.

### 6.5 Response

**301 / 302 / 307 / 308**

1. `TargetResolver` produces the final URL:
   - substitute `$1`…`$9` for regex rules
   - resolve relative targets with `home_url()`
   - forward the incoming query string when `forward_query_string` (filter `adv_redirects_forward_query_string`) is on, merging into any existing target query (the target's keys win)
2. Re-validate the final URL (§8.3), including the **host guard**. If invalid, no redirect happens and the request continues normally.
3. Apply the `adv_redirects_target_url` and `adv_redirects_status_code` filters.
4. Record the hit.
5. Fire `adv_redirects_before_redirect` (rule, url, code).
6. `wp_redirect( $url, $code, 'WP Redirects' )` (sends `X-Redirect-By`), then `exit`.

**410 / 451**

1. Record the hit and flag the request.
2. At `template_redirect` priority 0: `$wp_query->set_404()`, `status_header( $code )`, `nocache_headers()`. The theme's 404 template then renders with that status.
3. The 404 logger ignores flagged requests.

### 6.6 Hit tracking (`HitTracker`)

- **Persistent object cache present:**
  - `wp_cache_add("hit:{id}", 0)`, then `wp_cache_incr("hit:{id}")` (atomic in Redis/Memcached); `wp_cache_set("last:{id}", time())`
  - cron every 5 minutes: for each rule id, read the counter `n`; if `n > 0`, run `UPDATE … SET hits = hits + n, last_hit_at = …`, then `wp_cache_decr("hit:{id}", n)`
  - decrementing (not deleting) means hits recorded during the flush aren't lost
- **No persistent object cache:** direct `UPDATE` per hit.
- Hit tracking writes don't flush the rule set cache.
- `adv_redirects_hit_tracking_enabled` filter can disable it.

### 6.7 404 logging (`NotFoundLogger`)

At `template_redirect` (late priority) when `is_404()`, the request wasn't flagged as 410/451, and `log_404` is on:

1. Skip excluded extensions (`adv_redirects_404_excluded_extensions`).
2. Apply the `adv_redirects_log_404` filter (bool, path).
3. Single query: `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = …, last_referrer = …`.
4. Fire `adv_redirects_404_logged`.

**Pruning** (daily cron): delete rows older than the retention days, then trim to max rows by oldest `last_seen`.

When a redirect is created whose exact source matches a logged 404 path, that 404 row is deleted.

## 7. Admin UI

### 7.1 General

- Top-level **Redirects** menu, placed below Tools (dashicon `randomize`).
- Capability from `adv_redirects_capability` (default `manage_options`), applied to both the menu and REST.
- Single React app built with `@wordpress/scripts`, using `@wordpress/components`, `@wordpress/api-fetch` and `@wordpress/data` (or local reducer state). WordPress packages are externals, so the bundle contains only plugin code (target under 50 KB gzipped).
- All rules load in one REST call. Search, filter and sort happen client-side and are instant.
- Optimistic updates with rollback and an error notice on failure.
- Accessible: labelled controls, keyboard-operable reordering, focus management in modals.

### 7.2 Redirects tab

- **Test URL bar** (pinned at top): input path → calls `TestController`. Shows the matched rule (highlights its row), status, final destination, and all hops in a chain.
- **Quick-add row:**
  - Exact/Regex toggle · Source · Target · Status select · Add. Enter submits.
  - Target is hidden for 410/451.
  - Server validation errors show inline under the field.
  - In regex mode, shows whether the pattern matches the current test-URL input.
  - Chain warnings show after save with a "Point directly to …" action.
- **Exact rules table:**
  - columns: enabled toggle, source → target, status badge, hits, last hit (relative time), "Auto" badge (`origin = auto`), chain ⚠ icon
  - inline row edit
  - search box, filters (status, enabled, origin), sortable columns
  - row checkboxes with bulk enable / disable / delete
- **Regex rules list:** separate section titled "Regex rules (evaluated in order)". Drag handle plus up/down buttons, and the position number is shown. Reorder saves via the REST reorder endpoint.
- **Delete:**
  - single delete removes the row and shows a snackbar with **Undo** (re-creates the rule)
  - bulk delete asks for confirmation in a modal
- Footnote: "Hit counts refresh every 5 minutes."
- Empty state explains what redirects are, with the quick-add row focused.

### 7.3 404 Log tab

- Sorted by hits descending, with search.
- Columns: path, hits, last seen, last referrer.
- Row action **Create redirect**: switches to the Redirects tab with the quick-add source prefilled.
- Bulk delete and **Clear all** (both confirm in a modal).
- When logging is off, the tab shows a notice linking to Settings.

### 7.4 Settings tab

Form for every setting in §5.2, saved via `SettingsController`, with validation errors inline.

## 8. Validation & safety (`Validator`)

### 8.1 Field rules

- `type` ∈ {exact, regex}; `status_code` ∈ {301, 302, 307, 308, 410, 451}.
- `source`: required, at most 2048 chars, no control chars.
  - **Exact:** must start with `/`, or be an absolute URL on the site's own host (stored as its path+query).
  - **Regex:** at most 500 chars; compiled as `'~' . str_replace('~', '\~', $source) . '~i'` and must succeed with `@preg_match( $p, '' ) !== false`.
- `target`: required for 3xx, must be empty for 410/451; at most 2048 chars; must pass §8.3.
- `note`: at most 255 chars, sanitized with `sanitize_text_field`.
- Rejected:
  - an exact source whose normalized key duplicates an existing exact rule's key
  - a source equal to the target once both are normalized
- `adv_redirects_validate_rule` filter can return a `WP_Error` to reject.

### 8.2 Loop & chain detection

- Applies when the target is internal (relative, or absolute on the site host) and contains no `$n` reference.
- Resolve the target through the rule set **with the candidate rule applied** (created or updated) using `Matcher`, up to 10 hops.
- **Loop** (a hop returns to the source or repeats): reject with code `adv_redirects_loop` and a message showing the path, e.g. `Creates a loop: /a → /b → /a`.
- **Chain** (one or more extra hops): save, and return `warnings: [{ code: 'chain', hops: [...], final: '/c' }]`.
- The list endpoint includes `chain: { hops, final } | null` per rule.
- Regex rules whose target contains `$n` skip loop checks, and the UI says so.

### 8.3 Target URL safety (save time and runtime)

Allowed:
- relative paths starting with exactly one `/`
- absolute URLs with scheme `http` or `https`

Rejected:
- protocol-relative `//host`
- backslashes, whitespace, control chars including CR/LF
- any other scheme (`javascript:`, `data:`, `vbscript:`, `file:`, …)
- URLs that change under `esc_url_raw( $url, [ 'http', 'https' ] )`, or that `wp_parse_url` cannot parse

Absolute external hosts are allowed unless `adv_redirects_allowed_target_hosts` returns a non-empty allowlist that doesn't include the host.

**Runtime host guard:** after `$n` substitution, the final URL's host must equal the template's host. A relative template means the site's own host. Example: `/go/(.*)` → `/$1` with request `/go//evil.com` yields `//evil.com`, which fails, so no redirect happens.

## 9. Slug watcher

1. `pre_post_update` (post ID): if the post is published and its type is public and viewable, store `get_permalink()` in memory, keyed by post ID.
2. `post_updated` (ID, after, before): skip revisions, autosaves, and plain `?p=` permalinks. If the post is still published and the new permalink differs:
   1. Build a 301 rule old path → new path (`origin = auto`, note `Slug changed on post #ID`).
   2. **Hierarchical types:** also build rules for every published descendant. Old URLs are derived by replacing the old parent path prefix in each descendant's current path.
   3. Pass each candidate through the `adv_redirects_auto_redirect` filter (return false to cancel, or a modified array).
   4. Existing rules targeting the old path are retargeted to the new path, to prevent chains.
   5. Any enabled rule whose source normalizes to the new path is disabled, to prevent redirecting the live page away.
   6. If an exact rule for the old path already exists, it's updated instead of duplicated.
   7. Fire `adv_redirects_auto_redirect_created` (rule, post) for each rule created.
3. Controlled by the `slug_watcher` setting.

## 10. REST API (`adv-redirects/v1`)

Every route has `permission_callback` → `current_user_can( adv_redirects_capability )`. Every argument has a schema (type, enum, maxLength, sanitize and validate callbacks), and unknown properties are rejected. Cookie auth requires the `wp_rest` nonce (handled by `apiFetch`); Application Passwords work for remote clients.

| Method & route | Purpose |
|---|---|
| `GET /redirects` | all rules, including `chain` info |
| `POST /redirects` | create → rule + `warnings` |
| `PUT /redirects/{id}` | update → rule + `warnings` |
| `DELETE /redirects/{id}` | delete |
| `POST /redirects/bulk` | `{ action: enable\|disable\|delete, ids: int[] (max 500) }` |
| `POST /redirects/reorder` | `{ ids: int[] }` regex order |
| `POST /test` | `{ path }` → match, final URL, status, hops |
| `GET /404s` | paged (`page`, `per_page` ≤ 100), `search`, sort by hits/last_seen |
| `DELETE /404s/{id}` | delete one |
| `POST /404s/bulk` | `{ action: delete, ids }` |
| `DELETE /404s` | clear all |
| `GET /settings` / `PUT /settings` | read / update settings |

Errors use `WP_Error` with stable codes (`adv_redirects_invalid_source`, `adv_redirects_invalid_target`, `adv_redirects_invalid_regex`, `adv_redirects_duplicate`, `adv_redirects_loop`, `adv_redirects_not_found`) and HTTP 400/404/409/422 as appropriate.

## 11. Hooks API

All hooks are documented with docblocks in code and in `docs/hooks.md`.

**Filters**

| Hook | Args | Default |
|---|---|---|
| `adv_redirects_capability` | `string $cap` | `manage_options` |
| `adv_redirects_should_handle_request` | `bool, string $path` | `true` |
| `adv_redirects_allowed_methods` | `string[]` | `['GET','HEAD']` |
| `adv_redirects_request_path` | `string $path` | — |
| `adv_redirects_match` | `?MatchResult, string $path, string $query` | — |
| `adv_redirects_target_url` | `string $url, array $rule, string $path` | — |
| `adv_redirects_status_code` | `int $code, array $rule` | — |
| `adv_redirects_forward_query_string` | `bool, array $rule` | setting |
| `adv_redirects_allowed_target_hosts` | `string[]` | `[]` (any) |
| `adv_redirects_compiled_ruleset` | `array $ruleset` | — |
| `adv_redirects_validate_rule` | `true\|WP_Error, array $data, ?int $id` | `true` |
| `adv_redirects_auto_redirect` | `array\|false $data, WP_Post, string $old, string $new` | — |
| `adv_redirects_hit_tracking_enabled` | `bool` | `true` |
| `adv_redirects_log_404` | `bool, string $path` | `true` |
| `adv_redirects_404_excluded_extensions` | `string[]` | setting |

**Actions:** `adv_redirects_loaded` (Plugin), `adv_redirects_before_redirect` (rule, url, code), `adv_redirects_rule_created` (rule), `adv_redirects_rule_updated` (rule, old rule), `adv_redirects_rule_deleted` (rule), `adv_redirects_cache_flushed`, `adv_redirects_auto_redirect_created` (rule, post), `adv_redirects_404_logged` (path).

**PHP API** (`src/functions.php`); each function goes through `Validator` and `Repository` like REST does:

- `adv_redirects_add( array $data ): array|WP_Error` (returns the rule array)
- `adv_redirects_delete( int $id ): bool`
- `adv_redirects_flush_cache(): void`

## 12. Security checklist

- `defined( 'ABSPATH' ) || exit;` at the top of every PHP file.
- Capability checks on the menu page and every REST route; no `__return_true` permission callbacks.
- No `admin-post`/`admin-ajax` handlers; all mutations go through nonce-protected REST.
- `$wpdb->prepare()` for every query containing input. Table names only from `$wpdb->prefix`. ORDER BY / sort columns allowlisted. `IN()` lists built with per-item placeholders.
- Input sanitized and validated via REST schemas plus `Validator`. Output escaped. The admin PHP prints only a mount `<div>` and data passed through `wp_json_encode` in `wp_add_inline_script`. React renders text only, with no `dangerouslySetInnerHTML`.
- Target URL rules and the runtime host guard (§8.3) prevent open redirects and header injection.
- Regex: delimiters and flags are controlled by the plugin (no user-supplied modifiers, so no `e`), length caps, PCRE error handling.
- 404 table: row and retention caps, control chars stripped, length caps, no IPs or user agents.
- Update checker only downloads from this repo's GitHub Releases over HTTPS.
- `uninstall.php` checks `WP_UNINSTALL_PLUGIN` and only removes data when opted in.
- CI enforces PHPCS `WordPress-Extra` (including `WordPress.Security.*`), PHPCompatibilityWP `testVersion 7.4-`, `composer audit`, and `npm audit --omit=dev`.

## 13. Updates

- Bundle `yahnis-elsts/plugin-update-checker` (Composer, pinned major `^5`) as the only runtime dependency. It's loaded from `vendor/` in the release zip.
- `UpdateChecker` builds it against `https://github.com/advision-development/wp-redirects/` and calls `getVcsApi()->enableReleaseAssets()` with an asset filter matching `wp-redirects-*.zip`. Installs use the built zip, never GitHub's source archive (which has no `build/` or `vendor/`).
- No token needed (public repo).
- If `vendor/` is missing (e.g. a dev checkout without `composer install`), the update checker is skipped silently and the plugin still works.

## 14. Build & release

- **Gitignored:** `build/`, `vendor/`, `node_modules/`.
- **Dev:** `npm run start` (watch), `npm run build`. `composer install` for dev tools. `@wordpress/env` provides the local WP and the test environment.
- **CI** (`.github/workflows/ci.yml`, on push/PR):
  - PHP lint and PHPCS
  - PHPUnit unit tests on PHP 7.4 / 8.2 / 8.3
  - integration tests via `wp-env` on WP 6.6 and latest
  - `npm ci`, ESLint, Jest, `npm run build`
  - Playwright smoke test
  - `composer audit`, `npm audit --omit=dev`
- **Release** (`.github/workflows/release.yml`, on tag `v*.*.*`):
  1. Fail unless the tag version equals the plugin header `Version` and readme `Stable tag`.
  2. `npm ci && npm run build`.
  3. `composer install --no-dev --optimize-autoloader`.
  4. Copy into `dist/wp-redirects/`, honoring `.distignore` (excludes `assets/src`, `tests`, `docs/superpowers`, dotfiles, `node_modules`, `package*.json`, `composer.*`, config files).
  5. Zip as `wp-redirects-X.Y.Z.zip` and write the `.sha256` checksum.
  6. Create the GitHub Release and attach both files.

## 15. Testing

- **Unit** (PHPUnit, no WP; table-driven):
  - `PathNormalizer`: subdirectory installs, encoding, case, trailing slash, length cap
  - `Matcher`: exact precedence, query rules, regex order, PCRE error skip
  - `TargetResolver`: `$n` substitution, query merge, relative resolution
  - target validator attack cases: `//evil.com`, `/\evil.com`, `javascript:`, CRLF, `/go//evil.com` via regex capture, encoded variants
- **Integration** (WP test suite via `wp-env`):
  - schema install/upgrade
  - Repository CRUD and cache flush on every write path
  - REST permission matrix (anonymous 401, editor 403, admin 200) and schema rejection
  - validator loop/chain cases
  - slug watcher (simple post, page with descendants, rule cleanup)
  - 404 logger upsert, exclusions and pruning
  - hit flush cron with and without a persistent cache
  - 410/451 status + template
- **JS** (Jest): state reducer, filtering/sorting utils, optimistic update rollback.
- **E2E** (Playwright, `@wordpress/e2e-test-utils-playwright`): add an exact rule → Test URL shows the match → visiting the source redirects with the chosen status.

## 16. Amendments (implementation planning, 2026-10-02)

1. Minimum WordPress is 6.6 (see §3).
2. Reserved sources: exact sources whose path is or starts with `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `/wp-cron.php` or `/<rest prefix>` are rejected with `adv_redirects_reserved_source` (422). The Redirector also never handles these paths.
3. Regex captures are URL-encoded per path segment (`/` kept) before `$n` substitution.
4. The slug watcher captures descendant permalinks before the update (in `pre_post_update`) instead of deriving them by prefix replacement.
5. Additional units: `Matching/UrlSafety`, `Matching/Pattern`, `Matching/RulesetCompiler`, `Redirects/ChainResolver`, `Site`, `Permissions`, `Uninstaller`, `Rest/BaseController`.
6. Bulk enable re-validates each rule. Rules that would create a loop are left disabled and returned in `skipped`.
7. Final review fixes: the Test URL tool and the chain walk skip what the Redirector never handles (reserved paths and a non-empty `rest_route` query). The test endpoint answers `matched: false, reason: "reserved"` for them. Exact rules reject `$1`-`$9` in the target (`adv_redirects_invalid_target`), since only regex rules substitute captures. The plugin header carries `Update URI` so WordPress core never checks wordpress.org for the `wp-redirects` slug.
8. Import from the Redirection plugin (JSON export) is added to scope; see §17.
9. Rule attribution: `created_by`, `created_via` (`manual`|`import`|`slug`|`api`) and `updated_by` columns (schema v2), set server-side in `Repository`; REST adds `created_by_name`/`updated_by_name`; the rules table shows "By: <user> · <date>" plus a via label, and the last edit on hover and in the edit row.
10. Import fixes from the final review: in-file duplicates keep the entry Redirection actually served (first enabled, else first) instead of the later one (§17.4); rules in a disabled Redirection group import disabled; the client sends only the fields the server reads (no condition data such as IPs, agents, cookies or headers); an empty imported title never clears an existing rule's note on overwrite.

## 17. Import from Redirection (added 2026-10-02)

### 17.1 Purpose and scope

Admins upload a JSON export from the **Redirection** plugin (tested against v5.10.1) and import its redirect rules.

- **Imported:** redirect rules. Rules in Redirection's "Modified Posts" group get `origin = auto`; every other rule gets `manual`.
- **Not imported:** hit counts, last-access dates, the `logs` section and the `errors_404` section.
- **Privacy:** the `logs` and `errors_404` sections contain IP addresses and user agents. They are stripped in the browser and never sent to the server (§12).

### 17.2 Flow

1. **Browser schema check.** The admin reads the file with `FileReader` and checks it:
   - root is an object with `plugin.version` (string) and `redirects` (array)
   - each redirect has `url` (string), `regex` (boolean), `action_type` (string), `action_code` (integer), `action_data` (object), `match_type` (string), `enabled` (boolean) and `group_id` (integer)
   - `groups`, when present, is an array of objects with `id` (integer) and `name` (string)
   - **On failure:** show up to 5 problems with their entry numbers, and send nothing.
2. **Strip.** Only `plugin.version`, `groups` (id and name) and `redirects` are kept for sending.
3. **Preview (dry run).** `POST /import/preview` maps and validates every entry and writes nothing.
4. **Import.** `POST /import` takes batches of at most 50 entries, sent in file order, one batch at a time. The browser shows progress per batch and can cancel between batches.

### 17.3 Mapping (`Import\RedirectionMapper`, pure PHP)

| Redirection field | WP Redirects field |
|---|---|
| `regex` | `type` (`regex` / `exact`) |
| `url` (not `match_url`) | `source` |
| `action_data.url` | `target` (`$1`-`$9` kept for regex rules) |
| `action_code` | `status_code` |
| `enabled` | `enabled` |
| `title` | `note`, truncated to 255 characters |
| group named "Modified Posts" | `origin = auto` |
| `position` | regex evaluation order |

`action_type = error` with code 410 maps to a 410 rule with a null target.

**Skipped, each with a stable reason code:**

| Condition | Reason code |
|---|---|
| `match_type` is not `url` | `unsupported_match_type` |
| `action_type` is `random`, `pass` or `nothing` | `unsupported_action` |
| `error` with any code other than 410 | `unsupported_action` |
| status code outside {301, 302, 307, 308, 410, 451} | `unsupported_status` |
| malformed entry that slipped past the client check | `invalid_entry` |

**Imported, with a note attached:**

| Condition | Note code |
|---|---|
| `flag_case` false | `case_insensitive` (WP Redirects always ignores case) |
| `flag_trailing` false | `trailing_slash_ignored` |
| `flag_query` is `ignore` or `pass` | `query_mode` (WP Redirects matches the query only when the source contains one, and forwards per the global setting) |
| regex pattern containing `\?` | `regex_query` (regex rules match the path only) |
| the entry's Redirection group is disabled (`status` is `disabled` or `enabled` is false) | `group_disabled` (Redirection skips every rule in such a group, so the rule imports disabled) |

The filter `adv_redirects_import_rule( array|false $rule, array $entry )` can modify a mapped rule or skip it by returning `false`.

### 17.4 "Import wins" and validation

**Conflicts with existing rules:**
- An existing exact rule with the same normalized source key is **updated in place**. It keeps its id and hits.
- An existing regex rule with an identical pattern string is updated in place the same way.
- The preview reports these as `overwrite`, with the current and imported target and status.

**Duplicates within the file:** Redirection serves the first enabled match in position order, so the import keeps that entry. Among entries with the same conflict key (in the order received, position-sorted by the client) the winner is the first entry that maps and passes the filter and is enabled; if none is enabled, the first one that maps. Every other entry with that key is reported as `superseded`, with a reason naming the winner and a `superseded_by` field (the winner's Redirection id). The winner is chosen before validation: if it then fails validation it is `skipped` as usual, and no superseded copy is imported in its place. A regex source is compared after trimming, as the Validator stores it.

**Validation:**
- Every mapped rule passes through `Validator::validate()`, with the existing rule's id when overwriting. Failures are `skipped` with the validator's error code and message.
- Chain warnings are added to the entry's `warnings` list (its status stays `new` or `overwrite`), and the rule still imports.
- **Preview accuracy:** the Validator accepts optional *pending rows*, the import rules ahead of the current one in the file. Loops that form only between imported rules therefore appear in the preview. Normal saves pass no pending rows and behave unchanged.

### 17.5 REST

Both routes use the standard permission check and argument schemas, and reject unknown fields.

| Route | Body | Response |
|---|---|---|
| `POST /import/preview` | `{ source: "redirection", version, groups, redirects }`, max 2,000 redirects | per-entry `{ index, source_id, status: new\|overwrite\|superseded\|skipped, warnings[], notes[], rule, existing_id?, current?, error? }` plus `counts` |
| `POST /import` | `{ source: "redirection", groups, redirects }`, max 50 redirects; caller sends batches in file order | per-entry `{ index, result: created\|updated\|skipped, rule_id?, error? }` plus `counts` |

- Writes go through `Repository` (cache flush and rule hooks fire as for manual edits).
- The action `adv_redirects_import_completed( array $counts )` fires once per import batch.

### 17.6 Admin UI: Import tab (fourth tab)

1. **File picker and drop zone** (`.json`). The copy says only redirect rules are read, and that logs and 404 data stay on the user's computer.
2. **Schema-check result:**
   - **failure:** an error notice listing the problems
   - **success:** a summary card with plugin version, export date, exact and regex counts, and counts of logs and 404 records that won't be imported
3. **Preview** in collapsible groups: New, Will overwrite (old → new target and status), Chain warnings, Skipped (with reason), and Superseded. Notes show on the affected rows.
4. **Import button** states the outcome, e.g. "Import 94 redirects (3 overwrite existing)".
5. **Progress:** a native `<progress>` element with a percentage and an `aria-live` "Imported X of Y". **Cancel** stops after the current batch.
6. **Result:** counts of created, updated and skipped. **Download report** saves skipped and superseded entries with their reasons as JSON. **View redirects** switches to the Redirects tab, and the rule list reloads.
7. **If a batch fails:** stop, and show how many rules were imported. Re-running the same file is safe, since rules are overwritten again.

### 17.7 Testing

- **Fixture:** `tests/fixtures/redirection-export-sample.json`, hand-made, with no real site data. It covers:
  - exact and regex rules
  - an in-file duplicate, a loop between imported rules, and a chain
  - an unsupported `match_type`, a 410 `error` rule, a 303 rule, and a `random` action
  - "Modified Posts"
  - case, trailing and query flags
  - fake `logs` and `errors_404` entries
- **Jest:** schema check (valid, wrong plugin shape, missing fields, wrong types), the strip step, and batch splitting.
- **PHPUnit unit:** `RedirectionMapper`, covering every mapping, skip reason and note.
- **PHPUnit integration:**
  - preview vs import parity
  - a loop only between imported rules is caught in preview
  - overwrite keeps id and hits
  - "Modified Posts" becomes auto
  - `superseded` duplicates
  - limits (2,000 / 50), unknown fields, and permissions
  - re-import is idempotent
- **Playwright:** upload the fixture, check preview counts, import, see the progress bar reach 100%, and find the new rules on the Redirects tab.
- **Never committed:** real exports. `.gitignore` excludes `/redirection-export*.json` and `/*-export.json`.
