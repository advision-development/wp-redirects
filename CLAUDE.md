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

## Testing notes

- Integration tests run inside wp-env (`npm run env:start` first). The REST base class `tests/integration/support/RestTestCase.php` uses the plugin's own route registration.
- Hit counting buffers in the object cache only when `wp_using_ext_object_cache()` is true; tests toggle it.
- e2e relies on these accessible names: Source, Target, Test a URL, Add redirect, Test. Keep them stable or update `tests/e2e/redirects.spec.js`.
- Integration tests and e2e share the wp-env tests database (the integration bootstrap overrides home/siteurl to `http://example.org`). If integration tests ran in the same env, run `npx wp-env clean tests && npm run env:start` before `npm run test:e2e`. CI runs them in separate jobs, each with a fresh env.
- Jest is configured in `jest.config.cjs` (with `eslint.config.cjs` for linting), because `@wordpress/scripts` v36 no longer bundles Jest. `npm run test:js` runs plain `jest`.
- Never run `wp-scripts format` on the whole repo. Use `npm run format`, which is scoped to `assets/src`, `tests/js` and `tests/e2e`.

## Design

- Product context for design work is in `PRODUCT.md` (users, positioning, brand commitments). The latest Impeccable critique snapshot is in `.impeccable/critique/`. No `DESIGN.md` exists.
- Constraints: the screen must look native to wp-admin (`@wordpress/components`, admin color scheme) and meet WCAG 2.2 AA (labels, error links through `utils/a11y.js`, focus management, keyboard use).
- Re-run Impeccable passes for UI changes.

## Releases

Tag `vX.Y.Z` on `main` after bumping the version in `wp-redirects.php` (header + `ADV_REDIRECTS_VERSION`) and `readme.txt` (`Stable tag`). CI builds assets, runs `composer install --no-dev`, zips `wp-redirects/` as `wp-redirects-X.Y.Z.zip`, and attaches it to a GitHub Release. Sites self-update from that asset via plugin-update-checker. Tags with a suffix (`v1.0.0-rc1`) are published as GitHub prereleases and never offered to sites. The `Update URI` header in `wp-redirects.php` keeps WordPress core from also checking wordpress.org for the `wp-redirects` slug. Do not remove it.
