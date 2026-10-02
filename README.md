# WP Redirects

A redirect manager for WordPress. It handles exact and regex redirects with a selectable status code, matches from a compiled rule set held in the object cache, and refuses rules that would loop or lock you out of wp-admin.

It's built for sites that don't run Yoast SEO Premium, or don't want redirects tied to an SEO plugin.

- Exact path redirects and regex redirects with `$1`–`$9` captures
- Status codes 301, 302, 307, 308, 410 (Gone) and 451 (Unavailable For Legal Reasons)
- Rules are compiled once and kept in the object cache, so with Redis or Memcached, matching a request doesn't touch the database
- Loop prevention, redirect-chain warnings with a one-click fix, and protected WordPress system paths
- Hit counts and last-hit dates per redirect
- Automatic 301 when a published post's slug changes
- 404 log with one-click "create redirect"
- Test URL tool that shows exactly what a live request would do
- Import from a [Redirection](https://wordpress.org/plugins/redirection/) JSON export, with a dry-run preview
- Shows who created and last edited each redirect
- Actions, filters, a PHP API and a REST API for developers
- Self-updates from GitHub Releases

## Requirements

- WordPress 6.6 or later (tested up to 7.1)
- PHP 7.4 or later
- A persistent object cache (Redis, Memcached) is recommended but not required. Without one, the plugin loads the enabled rules with one database query per request.

## Installation

1. Download `wp-redirects-X.Y.Z.zip` from the [latest release](https://github.com/advision-development/wp-redirects/releases/latest). Use the release asset, not GitHub's "Source code" archive, which has no built admin app or `vendor/` folder.
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip and activate it.
3. Open **Redirects** in the admin menu.

Updates appear on the **Plugins** screen like any other plugin. The plugin checks this repository's GitHub Releases. The repository is public, so no token is needed.

## Using it

Everything lives under the top-level **Redirects** menu, in four tabs: Redirects, 404 Log, Settings and Import. You need the `manage_options` capability (administrators) unless a developer changes it (see [Developers](#developers)).

### Adding a redirect

Enter a **Source** and a **Target**, pick a status code, and choose **Exact** or **Regex**.

- **Exact** sources are a path such as `/old-page` or a full URL on this site. Matching ignores case and trailing slashes.
  - A source without a query string matches the path with any query string.
  - A source with a query string (`/shop?page=2`) matches only that exact query and takes priority over the plain path.
- **Regex** sources are matched against the request path, case-insensitively. For example, `^/blog/(.*)$` → `/news/$1`. Patterns can be up to 500 characters.
- **Targets** are a path on this site (`/new-page`) or an `http(s)` URL. 410 and 451 rules have no target and serve your theme's 404 template with that status.

Exact redirects are checked first. Regex redirects are checked after them, in the order shown in the Regex table, and the first match wins. Drag rows or use the arrow buttons to reorder them.

### Safety checks

- A rule that would create a redirect loop is refused, with the loop shown (`/a → /b → /a`).
- A rule that starts a chain (`/a → /b → /c`) is saved with a **Chain** warning and a **Point to …** button that sends it straight to the final destination.
- WordPress system paths (`/wp-admin`, `/wp-login.php`, `xmlrpc.php`, `wp-cron.php` and the REST API) can't be used as a source, so a bad rule can't lock admins out.
- Unsafe targets (`javascript:`, protocol-relative `//host` and similar) are rejected.

### Test URL

Paste any path or URL into **Test a URL** to see which rule matches, the status code, and the final destination, including query-string forwarding. **Show rule** jumps to the matching row. The test runs the same code as live requests.

### 404 log

Requests that end in a 404 are logged with their path, the last referrer, a hit count and when each was last seen. Click **Create redirect** on any entry to fill in the add form. Adding a redirect for a path clears it from the log.

Static files (CSS, JS, images, fonts) aren't logged by default. Entries are pruned by age and by a row limit. No IP addresses or user agents are stored.

### Automatic redirects on slug change

When the slug of a published post, page or public custom post type changes, a 301 from the old URL to the new one is created automatically. For hierarchical types such as pages, child pages are redirected too. These rules show an **Auto** badge and can be edited like any other.

### Importing from Redirection

1. In the Redirection plugin, export your redirects as **Redirection JSON**.
2. Upload the file on the **Import** tab. The file's format is checked first, then a preview lists what will be created, what will overwrite an existing redirect (the import wins), what will be skipped and why, and any chain warnings. Nothing is saved yet.
3. Click **Import** to apply the changes in batches, with a progress bar. You can download a report afterwards.

Up to 2,000 redirects can be imported per file. Redirects from disabled Redirection groups import as disabled. Hit counts and Redirection's own 404 log aren't carried over.

### Settings

| Setting | Default | |
|---|---|---|
| Create a redirect when a published URL changes | On | The slug watcher described above. |
| Forward query strings | On | Appends the incoming query string to the target. |
| Log 404s | On | With **Keep entries for (days)** (30) and **Maximum entries** (5,000). |
| Ignore these file extensions | Common static files | Never logged as 404s. |
| Remove all redirects and settings when the plugin is deleted | Off | When on, deleting the plugin drops its tables and options. |

Hit counts are buffered in the object cache and written to the database every 5 minutes, so the admin numbers can lag slightly.

## Developers

### Hooks

Every hook is prefixed `adv_redirects_`. The full list of filters and actions, with arguments and examples, is in [`docs/hooks.md`](docs/hooks.md). Common ones:

```php
// Let editors manage redirects.
add_filter( 'adv_redirects_capability', fn() => 'edit_others_posts' );

// Only allow external targets on known hosts.
add_filter( 'adv_redirects_allowed_target_hosts', fn() => [ 'partner.example.com' ] );

// React to rule changes.
add_action( 'adv_redirects_rule_created', function ( $rule ) {
	// $rule is an Advision\Redirects\Redirects\Rule.
} );
```

### PHP API

```php
$rule = adv_redirects_add( [
	'type'        => 'exact',     // or 'regex'
	'source'      => '/old-page',
	'target'      => '/new-page', // null for 410/451
	'status_code' => 301,
] );

if ( is_wp_error( $rule ) ) {
	error_log( $rule->get_error_message() );
} else {
	adv_redirects_delete( $rule['id'] );
}

adv_redirects_flush_cache();
```

Rules added this way go through the same validation as the admin screen: loop checks, reserved paths and target safety.

### REST API

Namespace `adv-redirects/v1`. Every route requires the plugin's capability and a REST nonce or application password, and rejects unknown fields.

| Route | Methods | |
|---|---|---|
| `/redirects` | `GET`, `POST` | List all redirects, create one |
| `/redirects/{id}` | `PUT`/`PATCH`, `DELETE` | Update or delete a redirect |
| `/redirects/bulk` | `POST` | Enable, disable or delete several redirects |
| `/redirects/reorder` | `POST` | Set the regex evaluation order |
| `/test` | `POST` | Run the Test URL check for a path |
| `/404s` | `GET`, `DELETE` | List or clear the 404 log |
| `/404s/{id}` | `DELETE` | Delete one 404 entry |
| `/404s/bulk` | `POST` | Delete several 404 entries |
| `/settings` | `GET`, `PUT`/`PATCH` | Read or change settings |
| `/import/preview` | `POST` | Dry-run a Redirection export |
| `/import` | `POST` | Apply a batch of previewed entries |

### Data

- Redirects are stored in `{prefix}adv_redirects`, and the 404 log in `{prefix}adv_redirects_404s`.
- Settings live in the `adv_redirects_settings` option.
- The compiled rule set is cached in the `adv_redirects` object cache group. It's flushed automatically whenever a rule changes; call `adv_redirects_flush_cache()` if you change the tables by hand.

## Development

You need Node 22, Composer and Docker (for `wp-env`).

```bash
composer install && npm install
npm run build                 # build the admin app into build/
npm run start                 # rebuild on change
npm run env:start             # wp-env: dev site on :8888, tests site on :8889 (admin / password)

composer lint                 # PHPCS (WordPress Coding Standards + PHP 7.4 compatibility)
npm run lint:js
npm run test:php:unit         # pure-PHP matching core, no WordPress needed
npm run test:php:integration  # inside wp-env
npm run test:js               # Jest
npm run test:e2e              # Playwright against the tests site
```

Integration tests and e2e share the tests site's database. If you ran integration tests, reset it before e2e with `npx wp-env reset tests && npm run env:start`.

Source layout:

| Path | |
|---|---|
| `src/Matching/` | Pure matching core (path normalisation, patterns, target resolution, the compiled rule set), plus the request-time redirector and cache |
| `src/Redirects/` | Rule model, repository (the only place rules are written), validator, loop/chain resolver |
| `src/Tracking/` | Hit counting and the 404 log |
| `src/Import/` | Redirection export mapping and import |
| `src/Rest/` | REST controllers |
| `assets/src/` | React admin app (`@wordpress/components`) |
| `tests/` | PHP unit, PHP integration, Jest and Playwright tests |

CI runs lint, unit tests on PHP 7.4, 8.2 and 8.3, integration tests on WordPress 6.6 and latest, and e2e on every push.

## Releasing

1. Bump the version in `wp-redirects.php` (the `Version:` header and `ADV_REDIRECTS_VERSION`) and the `Stable tag` in `readme.txt`, and add a changelog entry.
2. Merge to `main` and wait for CI to pass.
3. Tag and push: `git tag -a vX.Y.Z -m "WP Redirects X.Y.Z" && git push origin vX.Y.Z`.

The release workflow checks that the three version numbers match the tag, builds the admin app, installs production Composer dependencies, and publishes `wp-redirects-X.Y.Z.zip` with a SHA-256 checksum to a GitHub Release. Sites pick it up as an update. Tags with a suffix (`v1.1.0-rc1`) are published as prereleases and never offered to sites.

## License

GPL-2.0-or-later. See [`readme.txt`](readme.txt) for the WordPress.org-style plugin readme.
