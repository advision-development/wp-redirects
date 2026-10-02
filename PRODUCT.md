# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **SEO / content managers**: non-developers who fix broken URLs, handle site migrations and slug changes, and work through 404s, often under time pressure.
- **Developers / ops**: engineers who write regex rules, run domain migrations, and extend behavior through hooks, the PHP API and the REST API.

## Product Purpose

WP Redirects is a WordPress plugin that manages URL redirects on Advision's WordPress sites: exact and regex rules with a selectable status code (301, 302, 307, 308, 410, 451), automatic redirects when a published slug changes, a 404 log to turn misses into redirects, and a Test URL tool. Success means redirects can be added and verified quickly and safely, without slowing down the site.

## Positioning

- **No Yoast dependency.** A single-purpose redirect manager that works on sites that don't run Yoast SEO Premium.
- **Safety built in.** Saving a rule that would loop is refused, chains are flagged with a one-click fix, unsafe or open-redirect targets are rejected, and WordPress system paths (`/wp-admin`, `/wp-login.php`, the REST API) can never be redirected, so a bad rule can't lock admins out.

## Operating Context

- Lives inside wp-admin as a top-level **Redirects** menu with three tabs: Redirects, 404 Log, Settings.
- Typical rule counts are up to a few hundred per site. The whole list loads at once and is filtered in the browser.
- Matching runs from a compiled rule set held in the object cache (Redis/Memcached on the target sites).
- Developers use `docs/hooks.md`, the `adv-redirects/v1` REST API, and the `adv_redirects_add()` PHP API.

## Capabilities and Constraints

- Exact rules (case- and trailing-slash-insensitive, optional query match) and regex rules evaluated in a user-set order, with `$1`–`$9` captures.
- Hit counts and last-hit dates (refreshed every 5 minutes), slug-change auto redirects, loop rejection and chain warnings, 404 log with retention limits.
- Compatibility: PHP 7.4+ and WordPress 6.6+ (target site runs PHP 8.2 / WP 7.1). The admin uses only `@wordpress/components` APIs that are stable in WP 6.6.
- No IP addresses or user agents are stored.
- Updates ship as built zips on GitHub Releases (public repo `advision-development/wp-redirects`).

## Brand Commitments

- **Must look native to WordPress admin**, but sleek and modern (user's words). It uses `@wordpress/components` and respects the admin color scheme. No separate Advision branding inside the screen.

## Evidence on Hand

- No screenshots, testimonials, usage data or benchmarks exist yet. Do not invent them.

## Product Principles

1. Safe by default: the UI should make dangerous outcomes (loops, lockouts, bulk deletes) hard to reach and easy to undo or confirm.
2. Fast to act: adding, testing and fixing a redirect should take seconds, with no page reloads.
3. Show the truth: what the Test URL tool shows is exactly what live requests do.
4. Serve both audiences: plain language for content managers; regex, hooks and API depth for developers without cluttering the default path.

## Accessibility & Inclusion

- WCAG 2.2 AA is a binding target: full keyboard operation, visible focus, sufficient contrast, and screen-reader labels on every control.
