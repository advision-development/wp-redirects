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
* Import redirects from a Redirection plugin JSON export, with a dry-run preview (new, overwrite, skipped) and batched import
* Actions and filters for developers (see docs/hooks.md)

== Changelog ==

= 0.1.0 =
* Initial release.
