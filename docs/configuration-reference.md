---
title: "Configuration Reference"
meta_title: "Onumia Configuration Reference"
meta_description: "Reference for configuring Onumia storage, menu placement, module roots, data handling, diagnostics, and public updates."
path: "configuration-reference"
order: 110
section: "Reference"
---

# Configuration Reference

Day-to-day configuration happens in the workspace. Trusted site code can
adjust storage, menu placement, user-owned module discovery, data handling,
diagnostics, and updates. The generated [Hooks](hooks.md) reference is the
authoritative list of public filters and actions.

## Settings storage

The `onumia_settings_file` filter relocates the default module settings file.
It receives the default path and must return an absolute path.

```php
add_filter('onumia_settings_file', static fn(): string => WP_CONTENT_DIR . '/onumia/onumia.settings.json');
```

The default is `onumia.settings.json` in the active theme. Relocating it keeps
configuration independent from theme deployment; use the same choice in every
environment.

## Admin menu placement

The `onumia/admin/menu_location` filter can keep Onumia top-level or place it
under Tools or Settings:

```php
add_filter('onumia/admin/menu_location', static fn(array $location): array => [
    'placement' => 'tools',
    'position'  => 5,
]);
```

Valid placements are `top-level`, `tools`, and `settings`.

## Module and component roots

Use `onumia/modules/roots` and `onumia/components/roots` to append absolute,
narrow discovery roots for site-owned modules and reusable component groups.
Do not replace the existing list and do not point either filter at a broad
project or uploads tree.

The exact `?onumia-dev=1` flag loads only the narrow UI Lab definition for that
request. Onumia exposes it only after a separate administrator capability
check; the request-local flag cannot grant access. Without both conditions, the
UI Lab module, its seeded editor page, and their REST resources stay hidden.

## Module data

| Setting | Purpose |
| --- | --- |
| `onumia/data/sqlite_data_directory` | Relocates optional SQLite files from `wp-content/uploads/onumia/data`. |
| `onumia/data/sqlite_available` | Overrides SQLite availability for controlled tests or unusual hosts. |
| `onumia/data/storage_driver` | Chooses `auto`, `mysql`, or `sqlite` for controlled diagnostics. |
| `ONUMIA_STORAGE_DRIVER` | Constant form of the storage-driver override. |
| `onumia/data/table_ip_handling` | Chooses `hash` (default), `redact`, or `raw`. |
| `onumia/data/table_uri_redaction` | Filters URI-like values before storage. |

MySQL is the default. If SQLite is requested but unavailable, Onumia falls
back to MySQL and records the choice so later environment changes do not
strand rows.

## AI provider environment values

The authenticated workspace can receive provider configuration from the
plugin `.env` file. Supported names are `OPEN_AI_KEY` or `OPENAI_API_KEY`,
`ANTHROPIC_API_KEY` or `ANTHROPIC_KEY`, and
`GOOGLE_GENERATIVE_AI_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_KEY`, or
`GEMINI_API_KEY`. Leave unused providers unset and never commit credentials.

## Public updates

Onumia receives signed releases anonymously from `inline0/onumia`. A trusted
deployment may provide `ONUMIA_GITHUB_UPDATER_TOKEN` for extra API rate-limit
headroom or set `ONUMIA_GITHUB_UPDATER_DISABLED` to disable the channel. The
`onumia/github_updater/*` filters can override repository, asset pattern,
token, disabled state, and trusted public key for controlled integrations.

## Scheduled work

The shared `onumia_tables_cleanup` event runs twice daily. User-owned modules
may declare additional WP-Cron jobs. Sites that disable WordPress cron must
provide an external runner.
