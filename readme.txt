=== WP Redirects ===
Contributors: advisiondevelopment
Tags: redirects, 301, 404, regex, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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
* Import redirects from a Redirection plugin JSON export, with a dry-run preview (new, overwrite, skipped) and batched import
* Import redirects from Yoast SEO Premium, then remove them from Yoast with a backup you can restore
* Imports take up to 5,000 redirects per file; very large files may need splitting, depending on your server's upload limits
* Shows who created each redirect (and how: manually, imported, slug change or API) and who last edited it
* Actions and filters for developers (see docs/hooks.md)

== Changelog ==

= Unreleased =
* Import from Yoast SEO Premium.
* Never redirects a URL to itself. A rule whose target resolves to the requested URL (for example a regex `^/forum/(.*)` to `/forum/$1`) is skipped for that request, so case-only and trailing-slash-only redirects are now allowed and end at their target. Chain checks and the Test URL tool follow the same rule.
* Redirects can add a trailing slash to their target after captures are filled in (database schema v3). The Yoast import uses this so capture targets such as `forum/$1` keep Yoast's trailing slash, and the rules table labels these redirects.

= 1.0.0 =
* Initial release.
* Exact and regex redirects with 301, 302, 307, 308, 410 and 451 responses, matched from an object-cached compiled rule set.
* Loop prevention, redirect-chain warnings with a one-click fix, and protection for WordPress system paths.
* Hit counts, automatic redirects on slug changes, a 404 log and a Test URL tool.
* Import from a Redirection plugin JSON export with a dry-run preview.
* Shows who created and last edited each redirect.
* Paged rules tables (25, 50, 100 or all rows).
* Self-updates from GitHub Releases.
