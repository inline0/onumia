---
title: "Operations And Troubleshooting"
meta_title: "Onumia Operations And Troubleshooting"
meta_description: "Operate the Onumia workspace and diagnose assets, pages, user modules, UI Lab, storage, and updates."
path: "operations-and-troubleshooting"
order: 90
section: "Reference"
---

# Operations And Troubleshooting

Start diagnosis with:

```bash
wp onumia doctor --format=json
```

The command checks package identity, the canonical asset manifest, REST
registration, storage, scheduled work, and table versions without printing
secret values.

## The workspace does not load

Confirm the installed package contains `assets/app/manifest.json` and that the
manifest has the `src/apps/onumia/main.tsx` entry. There is one frontend bundle
and one plugin basename: `onumia/onumia.php`.

In development, confirm the configured Vite server is reachable. Compare
browser network failures with the WordPress debug log before attributing an
unrelated extension or proxy error to Onumia.

## Pages fail to load or save

The workspace uses `/wp-json/onumia/v1/pages` and each page's document route.
Confirm the signed-in user has `manage_options`, the REST nonce is current, and
security middleware is not blocking the namespace. Title, emoji, hierarchy,
and document saves are separate requests, so inspect the specific failure.

Unsupported legacy node types should remain inert without preventing the rest
of a document from loading. They have no editing or rendering surface.

## A user module is missing

Check that its absolute parent root was appended through
`onumia/modules/roots`, then run the module checks. Invalid metadata,
structure, PHP contracts, or capabilities prevent a partial catalog entry.
Keep roots narrow so discovery never walks unrelated trees on a request.

If a module screen has no data or an action fails, inspect the matching REST
response, required capability, and WordPress log. Fix contract or structure
errors at their source; the shared renderer should not be replaced with a
one-off screen.

## UI Lab is missing

UI Lab appears only when an authenticated administrator opens Onumia with the
exact `?onumia-dev=1` query flag. It disappears on the next request without
the flag. A denied response is expected for signed-out users or users without
`manage_options`.

## Theme changes and multisite

The default module settings file is the active theme's
`onumia.settings.json`. Switching or replacing themes can change that file.
Keep it in version control or relocate it with `onumia_settings_file` when
configuration should be independent of theme deployment.

Sites that share one theme directory also share this default file. Pages,
per-user workspace state, and module data remain site-specific WordPress data.

## Scheduled work, updates, and removal

Module jobs and `onumia_tables_cleanup` depend on WP-Cron. Provide a system
cron replacement when WordPress cron is disabled.

The updater accepts only a version-matched archive whose release checksum and
detached signature verify. A rejected candidate leaves the installed version
untouched; inspect repository access, asset naming, signature, checksum,
version, and plugin basename rather than bypassing verification.

Deactivation preserves data. Uninstall removes Onumia-owned runtime data but
leaves the theme-owned settings file for deliberate operator cleanup.
