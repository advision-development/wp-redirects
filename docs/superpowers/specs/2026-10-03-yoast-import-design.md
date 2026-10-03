# Import from Yoast SEO Premium: design

**Date:** 2026-10-03 · **Issue:** #1 "Add Import from Yoast functionality" · **Status:** approved design

Extends the import feature in the main spec (`2026-10-02-wp-redirects-design.md` §17). Everything §17 says about preview, batching, "import wins", in-file duplicates, chain warnings and attribution applies here unless this document says otherwise.

## 1. Goal

Sites moving off Yoast SEO Premium can bring their Yoast redirects into WP Redirects without exporting a file:

1. The Import tab detects Yoast redirects stored in the database and offers to import them.
2. Preview → batched import runs through the existing Importer (Validator + Repository).
3. After the import, the admin confirms removing the imported redirects from Yoast. A backup is kept and can be restored.

It must work whether Yoast SEO Premium is still active (Yoast is still redirecting) or has been removed (only its options remain).

**Out of scope:** Yoast's CSV import/export, the free Yoast plugin (it stores no redirects), editing `.htaccess` or nginx files, and deleting WP Redirects rules on restore.

## 2. How Yoast Premium stores and serves redirects

Verified against Yoast SEO Premium 27.3.

**Storage** (`wp_options`):

| Option | Shape | Role |
|---|---|---|
| `wpseo-premium-redirects-base` | list of `{ origin: string, url: string, type: int, format: "plain"\|"regex" }`, autoload off | source of truth |
| `wpseo-premium-redirects-export-plain` | map `origin => { url, type }` | runtime copy for plain redirects |
| `wpseo-premium-redirects-export-regex` | map `origin => { url, type }` | runtime copy for regex redirects |
| `wpseo_redirect` | `{ disable_php_redirect: "on"\|"off", separate_file: "on"\|"off" }` | redirect method |

- There is no per-redirect enabled flag.
- Types are 301, 302, 307, 410 and 451. For 410/451, `url` is `''`. `type` is stored as an int.
- Plain origins have their slashes trimmed (`old-page`, `foo?a=1`). Regex origins are stored raw.
- Changing a redirect through `WPSEO_Redirect_Option` alone updates only the base option. `WPSEO_Redirect_Manager::save_redirects()` (called by `delete_redirects()`) writes the base option, both export options and, in file mode, the `.htaccess`/nginx file.

**Runtime** (PHP mode):

- Yoast's handler runs on the front end **before `plugins_loaded`**, so while a redirect exists in both plugins, Yoast serves it.
- **Plain matching:** the request path is decoded, its slashes trimmed, and it is looked up case-sensitively, query string included, with and without a trailing slash.
- **Regex matching:** ``preg_match( "`{$regex}`", $request_uri )``. There are no flags (so matching is case-sensitive), and the subject is the decoded `/path?query` with its leading slash.
- **Targets:** captures `$0`, `$1`, … are substituted. A target without a scheme gets `home_url()` prepended. When the permalink structure ends in `/` and the target has no query, fragment or file extension, a trailing slash is added (after capture substitution).
- **Order:** plain redirects first, then regex redirects in stored order.

**File mode:** when `disable_php_redirect` is `on`, Yoast writes the redirects to `.htaccess` (a `# BEGIN YOAST REDIRECTS` block), to `uploads/wpseo-redirects/.redirects` (Apache with `separate_file`), or to an nginx include. The web server serves them before WordPress runs.

**Reference data:** the local BMR site (`bmr-wp` container) has 3,292 Yoast redirects: 3,135 plain, 157 regex, 45 of them 410s, with 299 plain origins that collide case-insensitively.

## 3. Mapping (`Import\YoastMapper`, pure PHP)

`YoastMapper::map( $entry, int $source_id, array $context )` returns the same shape as `RedirectionMapper::map()`: `{ ok, source_id, rule, notes, error }`.

- `source_id` is the entry's 1-based position in the base option.
- `context` is `{ trailing_slash: bool }`, which is true when the permalink structure ends in `/`.
- The class must not call WordPress functions.

| Yoast | WP Redirects |
|---|---|
| `format: plain` | `type: exact`; source is `/` + origin (`old-page` → `/old-page`; `foo?a=1` → `/foo?a=1`, a query rule; `/` stays `/`) |
| `format: regex` | `type: regex`; pattern copied unchanged (both plugins match against a subject that starts with `/`) |
| `type` 301, 302, 307 | same status; target required |
| `type` 410, 451 | same status; target `null` (`url` ignored) |
| any other `type` | skip `unsupported_status` |
| target with a scheme (`https://…`) | kept unchanged |
| target without a scheme | `/` prepended unless it already starts with `/` |
| trailing slash | when `trailing_slash` is true and the target has no scheme, no `$n` capture, no `?`, no `#` and no file extension in its last segment, and doesn't already end in `/`, append `/` (so the import doesn't add a canonical-redirect hop) |
| `$0`, or `$10` and above, in the target | skip `unsupported_capture` (WP Redirects substitutes `$1`–`$9` only) |
| regex origin starting with a scheme (`http://…`, `^https?://…`) | skip `unreachable_regex` (Yoast matched against the path, so this rule never fired) |
| percent-encoded plain origin (`caf%C3%A9`) | kept as entered; the stored rule must match the same decoded request path Yoast matched (pinned by a test) |
| missing or wrong-typed field, empty origin, unknown `format`, empty target on 301–307 | skip `invalid_entry` |

Every mapped rule gets `enabled: true`, `note: ''` and `origin: manual`. New rules are stored with `created_via = import`, as in §17.

- **Integers:** `type` is accepted as an int or a digit-only string, like `RedirectionMapper::is_int_like()`.
- **Other host:** a plain origin that is an absolute URL on another host is passed through as-is. The Validator rejects it, and it shows as skipped with the Validator's message.
- **Notes:**
  - `regex_query` (existing code): the pattern contains a literal `\?`. WP Redirects matches regex against the path only.
  - `case_sensitive_source`: added to every regex rule. Yoast matched it case-sensitively, and WP Redirects matches regardless of case. It is *not* added to plain rules. Their matching only gets broader, and the case collisions show up as superseded.

## 4. Importer changes

- `Importer::preview()` and `Importer::import()` take a `$source` (`redirection` | `yoast`) and route mapping through it. The Redirection-only parts (`group_info`, groups argument) apply only to `redirection`.
- `plan()` keeps one code path for duplicates. Among entries with the same conflict key, the first that maps and passes the filter wins (all Yoast rules are enabled), and the rest are `superseded`.
- The superseded message names the source: "Redirection used entry #%d for this source; this copy was never served." for Redirection, and "Yoast entry #%d already covers this source." for Yoast.
- New skip reason messages:
  - `unsupported_capture`: "Only captures $1 to $9 are supported."
  - `unreachable_regex`: "Yoast matched regex rules against the path, so a pattern that starts with a URL never matched anything."
- The filter `adv_redirects_import_rule` gains a third argument: `adv_redirects_import_rule( array|false $rule, array $entry, string $source )`. Existing two-argument callbacks keep working.
- The action `adv_redirects_import_completed( array $counts )` is unchanged and fires per batch for both sources.
- The preview limit rises from 2,000 to **5,000** for both sources, in PHP (`ImportController::MAX_PREVIEW`) and JS (`MAX_PREVIEW`). Acceptance gate: the BMR preview (3,292 entries) must finish in under 20 seconds in the local container. If it doesn't, stop and redesign the preview before shipping.

## 5. Yoast side (`Import\YoastSource`, WordPress-aware)

### 5.1 Detection

- `detect()` reads `wpseo-premium-redirects-base` with `get_option()`, so Yoast's own `get_redirects` filter does not apply. Detected means it is a non-empty array.
- `premium_active` is true when `WPSEO_PREMIUM_VERSION` is defined **and** `class_exists( 'WPSEO_Redirect_Manager' )`. `premium_version` is that constant, or `null`.
- `server_mode` is read from `wpseo_redirect`. When it is `on`, `server_mode` is:

  | Server | `separate_file` | `server_mode` |
  |---|---|---|
  | Apache (`WPSEO_Utils::is_apache()` when available, else `$GLOBALS['is_apache']`) | `on` | `apache_file` |
  | Apache | `off` | `htaccess` |
  | nginx (`$GLOBALS['is_nginx']`) | any | `nginx` |
  | anything else | any | `none` |

  When `disable_php_redirect` is missing or not `on`, `server_mode` is `php`.
- Entries are returned in stored order as `{ id, origin, url, type, format }`, where `id` is the 1-based position.

### 5.2 Removal

`remove( array $items )` takes `{ origin, format }` pairs.

1. **Safety check.** An item is removed only if:
   - it exists in the base option with the same origin and format, **and**
   - WP Redirects currently holds a rule for it. That rule has the same conflict key `Importer` uses: exact source key for plain, trimmed pattern for regex, derived through `YoastMapper`'s source conversion.

   Items that fail the check are returned as `not_found` or `not_covered` and left in Yoast.
2. **Write.** All removals in one request are written once:
   - **Premium active:** load a `WPSEO_Redirect_Option`. For each format, fetch the redirect objects with `get()` and confirm the format, delete them in memory, then call `save_redirects()` once through a `WPSEO_Redirect_Manager` built on that option instance. This rewrites the base option, both export options and any redirect file.
   - **Premium inactive:** remove the items from the base option. Then rebuild `export-plain` and `export-regex` from the remaining base entries in Yoast's shape (`origin => { url, type }`, with `type` an int). `update_option()` keeps each option's existing autoload value.
3. **Backup.** The removed raw entries are appended to `adv_redirects_yoast_backup` (autoload off) as `{ entry, removed_at (UTC timestamp), removed_by (user id) }`.
4. **Hook.** Fires `adv_redirects_yoast_removed( array $entries )`.

Returns `{ removed, not_found, not_covered }` counts, plus the per-item results.

### 5.3 Restore and backup

- **Restore.** `restore()` re-adds every backed-up entry whose origin and format Yoast doesn't already hold. It goes through the manager (Premium active) or the options plus export rebuild (inactive), the same way as removal, and appends at the end of the base option.
  - Restored entries are dropped from the backup. Entries Yoast already holds are dropped too, and counted as `already_present`.
  - Fires `adv_redirects_yoast_restored( array $entries )`.
  - WP Redirects rules are not touched.
- **Delete backup.** `delete_backup()` deletes the backup option.
- **Uninstall.** `Uninstaller::run()` deletes `adv_redirects_yoast_backup` along with the other data, when data removal is enabled.

## 6. REST (`adv-redirects/v1`)

Every route uses `Permissions::can_manage()`, full argument schemas and `reject_unknown()`.

| Route | Body | Response |
|---|---|---|
| `GET /import/yoast` | none | `{ detected, premium_active, premium_version, server_mode, counts: { plain, regex }, entries: [ { id, origin, url, type, format } ], backup: { count, last_removed_at } \| null }` |
| `POST /import/preview` | `{ source: "yoast", redirects }`, max 5,000 | as §17.5 |
| `POST /import` | `{ source: "yoast", redirects }`, max 50 per batch, sent in stored order | as §17.5 |
| `POST /import/yoast/remove` | `{ entries: [ { origin, format } ] }`, 1–500 items | §5.2 result |
| `POST /import/yoast/restore` | `{}` | `{ restored, already_present }` |
| `DELETE /import/yoast/backup` | none | `{ deleted: true }` |

- For `source: "yoast"`, each redirect is `{ id, origin, url, type, format }`. `groups` and `version` are rejected as unknown fields. For `source: "redirection"`, the body is unchanged.
- The server reads the permalink structure itself to build the mapper's `trailing_slash` context. The client never sends it.

## 7. Admin UI (Import tab)

Native `@wordpress/components` only, following the a11y rules in CLAUDE.md (labels, focus management, `aria-live` updates).

1. **Detection notice.** If `detected` is true, a notice at the top reads "Yoast SEO Premium redirects found: 3,135 plain, 157 regex." with **Preview import** and **Not now**.
   - Not now hides the notice for this browser (localStorage, wrapped in try/catch).
   - If `premium_active`, the notice adds: "Yoast serves these first until you remove them from Yoast after importing."
   - A file-mode `server_mode` adds the warning from item 5.
2. **Preview and import** reuse the existing preview groups, Import button, progress bar, Cancel and result card. Only the source label changes ("Yoast SEO Premium" instead of the file summary). The preview is fetched with the entries from `GET /import/yoast`.
3. **Removal card.** After a Yoast import completes (not after Cancel or a failed batch), the result card asks: "Remove N imported redirects from Yoast? A backup is kept, and you can restore it here." with **Remove from Yoast** and **Keep in Yoast**.
   - N counts entries whose import result was `created` or `updated`, plus entries previewed as `superseded`.
   - This card is the confirmation; there is no extra modal.
   - Removal is sent in chunks of 500 with progress. The result reads "Removed X from Yoast", plus a count of any items left because they were not covered.
4. **Backup notice.** While a backup exists, the tab shows "N redirects removed from Yoast, last on <date in site timezone>." with **Restore to Yoast** and **Delete backup**. Both go through `ConfirmModal`.
5. **File-mode warning.** When `server_mode` is `htaccess`, `apache_file` or `nginx`:
   - Premium active: "Yoast writes these redirects to your server configuration. Removing them updates that file; nginx needs a reload to pick it up."
   - Premium inactive: "Yoast's redirects are still in your server configuration and keep working until that block is removed. WP Redirects does not edit server files."

Keep the existing e2e accessible names ("Source", "Target", "Test a URL", "Add redirect", "Test").

## 8. Hooks (document in `docs/hooks.md`)

- `adv_redirects_import_rule` filter: new third argument `string $source`.
- `adv_redirects_yoast_removed( array $entries )`: action, after a removal is written. `$entries` are the raw Yoast entries removed.
- `adv_redirects_yoast_restored( array $entries )`: action, after a restore is written.

## 9. Security and privacy

- **Permissions:** every route requires `can_manage`.
- **Relayed entries:** entries sent by the browser are re-mapped and re-validated on the server, just like Redirection entries. Targets go through `UrlSafety` and the host guard.
- **Removal:** only the server-side safety check (§5.2) decides what is removed, never the client's list on its own.
- **Data:** no IPs or user agents exist in Yoast's redirect data, and none are stored. The backup holds only `{ origin, url, type, format }` plus timestamp and user id.
- **Real data:** the BMR data is never committed or used in automated tests.

## 10. Testing

- **Fixture:** `tests/fixtures/yoast-redirects-sample.json`, hand-made. It has one entry per mapping row in §3, plus case-colliding plain origins, a loop between imported rules, a chain, a plain origin with a query, a percent-encoded plain origin, and a regex with `\?`.
- **PHPUnit unit:** `YoastMapper`, covering every mapping row, skip reason and note, the trailing-slash rules, and numeric-string `type`.
- **PHPUnit integration (wp-env, Premium not installed):**
  - `YoastSource::detect()`: options absent, empty or malformed; every `server_mode`.
  - Importer with `source: yoast`: preview/import parity, superseded case collisions, re-import idempotent, chain and loop warnings.
  - Removal and restore through the options path: base and both export options stay consistent with Yoast's shape, the safety check (`not_found`, `not_covered`), backup append, restore skipping `already_present`, hooks fire.
  - Manager path with a test double that defines `WPSEO_Redirect_Manager`, `WPSEO_Redirect_Option` and `WPSEO_Redirect` with the 27.3 method signatures, asserting a single `save_redirects()` per request.
  - REST: permission matrix for the new routes, schemas, unknown fields, the 500-item and 5,000-entry limits, and the `source` enum.
  - Uninstaller removes the backup option.
- **Jest:** building the yoast preview/import payloads, batch splitting in stored order, counting removal candidates (created + updated + superseded), and chunking the removal requests.
- **Playwright:** seed the Yoast options with wp-cli, then:
  1. the notice appears
  2. preview counts match
  3. import reaches 100%
  4. remove from Yoast empties the base option of covered entries
  5. the backup notice appears
  6. restore puts the entries back
- **BMR acceptance (manual, local `bmr-wp` container, Premium 27.3 active):**
  1. Run `wp db export` to a scratch file first.
  2. Install the built plugin, after asking, since `wp-content/plugins` is bind-mounted from the `bookmakersreview-site` repo.
  3. Run detect, then a timed preview against the 20 s gate, then import.
  4. Remove from Yoast through the real manager, then curl-check a sample of plain, regex and 410 URLs on :8080 to confirm WP Redirects now serves them with the same status and target.
  5. Restore, then restore the database dump.

## 11. Amendments (2026-10-03, final review)

1. **Query regexes stay in Yoast (C1).** A regex with a literal `\?` (note `regex_query`) is still imported, but it is never removed from Yoast: Yoast matched it against `/path?query` and WP Redirects matches the path only. `YoastSource::remove()` reports it `not_covered`, and the client leaves it out of the removal candidates. With the fixture, 16 entries are removed (not 17) and Yoast keeps 10.
2. **Self-redirect guard (C2).** WP Redirects never redirects a URL to itself, like Yoast's handler (main spec §16.12). Yoast regexes such as `/forum/(.*)` → `forum/$1` therefore import safely, and a chain that ends in such a rule (`/props` → `/odds/`) is no longer reported as a loop.
3. **Removal only when unchanged (I1).** `POST /import/yoast/remove` items are `{ origin, format, url, type }` (`url` a string, at most 2,048 characters, may be empty; `type` an integer; no other properties). An item is removed only if Yoast still holds an entry with the identical origin, format, url and `(int)` type, in both the option store and the manager store (`get_target()`, `get_type()`). An entry edited in Yoast since the import is `not_found` and stays.
4. **Case-dependent regexes stay in Yoast (I2).** New skip reason `case_dependent_regex`: a regex origin with a literal capital A–Z outside escape sequences, character classes and `{…}` braces. Yoast matched it case-sensitively and WP Redirects would not, so it could redirect other URLs. Message: "Yoast matched this pattern case-sensitively; WP Redirects ignores case, so importing it could redirect other URLs. It stays in Yoast."
5. **Leading capture that starts with `/` (I3).** A target that starts with `$n` (optionally after `/`) whose capturing group in the origin starts with `/` or `\/` is skipped as `unsupported_capture`, since `/$1/x` would become the protocol-relative `//nfl/x`.
6. Minor: the trailing-slash rule matches Yoast's `has_extension()` (a `.` in any path segment means no slash); a target with any URI scheme is kept unchanged (the Validator rejects non-http(s) ones); plain export keys are the trimmed origin.
