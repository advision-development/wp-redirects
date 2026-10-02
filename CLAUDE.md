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
