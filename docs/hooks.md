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
