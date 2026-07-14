---
title: "Configuration Reference"
meta_title: "Onumia Configuration Reference"
meta_description: "Reference for configuring Onumia through WordPress filters, constants, environment variables, and the plugin .env file."
path: "configuration-reference"
order: 600
section: "Reference"
---

# Configuration Reference

Day-to-day Onumia configuration happens in the dashboard, but the plumbing, where files live, how the menu registers, how data is treated, is adjustable in code. This page collects the supported filters, constants, and environment values. The generated hooks reference documents the full public API surface, including the PHP attribute classes used by custom module authors.

## Storage locations

Three classic WordPress filters relocate Onumia's theme-based storage. Each receives the default path and should return an absolute path.

| Filter | Default |
| --- | --- |
| `onumia_settings_file` | `<active-theme>/onumia.settings.json` |
| `onumia_custom_module_root` | `<active-theme>/onumia/modules` |
| `onumia_custom_app_root` | `<active-theme>/onumia/apps` |

For example, to keep settings outside the theme so theme updates can never touch them:

```php
add_filter('onumia_settings_file', static fn(): string => WP_CONTENT_DIR . '/onumia/onumia.settings.json');
```

Relocating these paths trades the travels-with-the-theme behavior for independence from theme deployment; pick deliberately and keep the choice consistent across environments.

## Admin menu placement

Onumia registers a top-level admin menu item by default. The `onumia/admin/menu_location` filter moves it under Tools or Settings, or adjusts its position:

```php
add_filter('onumia/admin/menu_location', static fn(array $location): array => [
    'placement' => 'tools',
    'position'  => 5,
]);
```

Valid placements are `top-level`, `tools`, and `settings`.

## Discovery roots

Sites that ship modules or components outside the standard locations can add discovery roots. `onumia/modules/roots` and `onumia/components/roots` filter the directory lists scanned for modules and reusable component groups, and `onumia/pro/app_roots` does the same for Pro apps. Each filter receives and returns an array of absolute directory paths. The Pro bundle itself uses the module roots filter to add its Pro modules, which is a good model: append your root rather than replacing the list.

## Module data behavior

Five filters tune the module data layer.

| Filter | Purpose |
| --- | --- |
| `onumia/data/sqlite_data_directory` | Relocates the base directory for optional SQLite module data files, default `wp-content/uploads/onumia/data`. |
| `onumia/data/sqlite_available` | Overrides the SQLite availability check, for tests or hosts with unusual extension behavior. |
| `onumia/data/storage_driver` | Forces automatic module storage to `auto`, `mysql`, or `sqlite` for controlled debugging and tests. |
| `onumia/data/table_ip_handling` | Decides how module helpers store IP addresses: `hash` (default), `redact`, or `raw`. |
| `onumia/data/table_uri_redaction` | Filters URI-like values before they are stored in module tables. |

The matching `ONUMIA_STORAGE_DRIVER` constant can also force automatic module storage. MySQL is the default. A forced SQLite table falls back to MySQL silently when no SQLite PHP interface is available, and that fallback is pinned in the storage marker.

For example, a site that must not retain any IP-derived value can force redaction globally:

```php
add_filter('onumia/data/table_ip_handling', static fn(): string => 'redact');
```

## AI provider keys

Chat keys are read from a `.env` file in the Onumia plugin directory. Each provider accepts its standard variable names; the first present value wins.

| Provider | Accepted variables |
| --- | --- |
| OpenAI | `OPEN_AI_KEY`, `OPENAI_API_KEY` |
| Anthropic | `ANTHROPIC_API_KEY`, `ANTHROPIC_KEY` |
| Google | `GOOGLE_GENERATIVE_AI_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_KEY`, `GEMINI_API_KEY` |

Keys configured here are available to dashboard users, who are administrators by definition. Leave a provider unset to keep it out of the model selector.

## Free plugin updates

Onumia Free receives signed releases anonymously from the public `inline0/onumia` mirror through the normal WordPress update screen. No token is required. For a custom authenticated mirror or additional GitHub API rate-limit headroom, provide an optional read-only token without storing it in WordPress:

```php
define('ONUMIA_GITHUB_UPDATER_TOKEN', 'github_pat_...');
```

`ONUMIA_GITHUB_UPDATER_DISABLED` disables this update channel when set to a truthy value. The matching `onumia/github_updater/*` filters can override the repository, asset pattern, optional token, disabled state, and trusted public key for controlled integrations. Onumia Pro packages disable the Free GitHub channel automatically and use the licensed update channel below.

## Software Licensing flags and secrets

The Software Licensing module loads only when its feature flag is enabled, as a PHP constant set to `true` or `'1'`, or as a truthy environment value:

```php
define('ONUMIA_ENABLE_SOFTWARE_LICENSING_MODULE', true);
```

Its credentials can be provided as constants instead of stored secrets: `ONUMIA_STRIPE_SECRET_KEY`, `ONUMIA_STRIPE_WEBHOOK_SECRET`, `ONUMIA_STRIPE_CHECKOUT_API_SECRET`, `ONUMIA_LICENSING_SIGNING_KEY`, and `ONUMIA_SOFTWARE_LICENSING_GITHUB_TOKEN` for private release repositories. A constant takes precedence over a stored secret of the same name.

Production services can keep all module credentials out of WordPress by defining `ONUMIA_MODULE_SITE_SECRETS_FILE` as the absolute path to an owner-only JSON file outside the web root. The file uses `schemaVersion: 1`, with secrets nested under `sites`, the numeric WordPress blog ID, and the module name. Once configured, it is authoritative: an invalid file or missing entry does not fall back to WordPress options. Install and rotate the file through an owner-only hosting or deployment secret channel, then remove matching WordPress option values after external resolution is verified.

## Receiving licensed updates

To have a Onumia install receive plugin updates from a licensing server, configure the update client through constants or environment variables:

```php
define( 'ONUMIA_LICENSE_KEY', 'ONUMIA-XXXX-XXXX-XXXX' );
define( 'ONUMIA_LICENSE_CHANNEL', 'stable' );
```

The product is fixed to `onumia-pro`, the service defaults to
`https://onumia.app/`, and the channel defaults to `stable`. The key is the only
required setting for the normal Onumia Pro service. A controlled deployment can
override the HTTPS service origin with `ONUMIA_LICENSE_SERVER_URL` or disable
the client with `ONUMIA_LICENSE_UPDATER_DISABLED`. The matching
`onumia/licensed_updater/server_url`, `channel`, and `disabled` filters are
available to trusted host integrations. There is no product-slug override.

A key supplied as a constant or environment value is not copied into WordPress
options. Do not put it in theme settings, exported configuration, or source
control.

## Scheduled events

Onumia relies on WP-Cron for background work. The `onumia_tables_cleanup` event runs twice daily and enforces module table retention windows. Modules with declared jobs register their own recurring events at schedules from every five minutes to weekly; the Software Licensing module's sync and expiry jobs are the main examples in the current catalog. If you manage cron externally, make sure these events fire.

## Developer surface

The full developer-facing API, public filters and actions such as `onumia/runtime/loaded` and `onumia/pro/loaded`, the module attribute classes, and the contracts available to custom module authors, is generated from source into the hooks reference. Treat that page as the authoritative list; everything here is the operational subset most sites actually configure.
