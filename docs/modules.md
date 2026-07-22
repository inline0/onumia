---
title: "Modules"
meta_title: "Onumia PHP Modules"
meta_description: "Extend Onumia with user-owned declarative screens and PHP settings, actions, data sources, tables, hooks, and jobs."
path: "modules"
order: 50
section: "Modules"
---

# Modules

Onumia keeps a generic extension contract for user-owned PHP modules. A module
provides a declarative structure for the shared renderer and a PHP contract for
WordPress behavior.

## Contract

A module folder normally contains `meta.json`, `structure.json`, a message
catalog, and PHP boot code. It may also declare typed settings, actions, data
sources, entry collections, tables, public routes, hooks, secrets, migrations,
and jobs.

The boundary is deliberate: React renders the declarative structure; PHP
validates input, enforces capabilities, and performs server-side work. Do not
translate a module into document blocks or a second frontend schema.

## Discovery

Add absolute module roots with the `onumia/modules/roots` filter:

```php
add_filter('onumia/modules/roots', static function (array $roots): array {
    $roots[] = WP_CONTENT_DIR . '/onumia-modules';
    return $roots;
});
```

Append rather than replace existing roots. Keep each root narrow; discovery
must not walk unrelated trees on normal requests.

Onumia itself registers only the UI Lab directory, and only for a request that
passes both its exact query opt-in and administrator capability gate.

## Settings and activation

Settings are validated against typed PHP definitions before they are saved.
Runtime hooks boot only when the module's activation/settings contract permits
them, so an unconfigured module should not unexpectedly change WordPress.

The default settings repository writes atomic, reviewable JSON to the active
theme's `onumia.settings.json`. Relocate it with `onumia_settings_file` if
settings should not travel with the theme. Secrets never belong in that file;
use protected WordPress storage or trusted host configuration.

## Data, actions, and routes

Renderer controls can request declared PHP data sources and invoke declared
actions. Operational records use module-owned MySQL tables or optional SQLite
storage. Entry screens, tables, charts, and status controls receive live values
through the same backend contract.

The workspace defaults to `manage_options`. A module, action, data source, or
route can demand a stricter capability. Public routes require an explicit
contract with their own authentication and rate-limiting rules.

Recurring jobs use WP-Cron. The shared `onumia_tables_cleanup` event enforces
declared retention windows for module tables.
