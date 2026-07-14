---
title: "Getting Started"
meta_title: "Onumia Getting Started"
meta_description: "Install and activate Onumia, confirm requirements, open the dashboard, and configure your first module."
path: "getting-started"
order: 50
section: "Getting Started"
---

# Getting Started

Onumia installs like any WordPress plugin and keeps its moving parts inside systems WordPress already provides: the admin, the REST API, WP-Cron, and the active theme. Getting started means confirming a few requirements, activating the plugin, opening the dashboard, and saving your first module configuration. This page walks through that path.

## Requirements

Onumia is built for site owners and operators who configure their site as administrators. Before installing, confirm your environment meets the following requirements.

| Requirement | Why it matters |
| --- | --- |
| PHP 8.2 or newer | The minimum runtime Onumia supports. |
| WordPress with the REST API available | The dashboard is a React app that talks to Onumia over REST. |
| A WordPress administrator account | The dashboard and its REST routes require `manage_options`. |
| A writable uploads directory | Needed only for module tables that opt into SQLite; default module operational records live in MySQL and are created lazily. |
| A writable active theme directory | Module settings and custom modules are stored in the active theme. |
| WP-Cron running | Retention cleanup and scheduled module jobs run through WP-Cron events. |

Onumia works without the Pro bundle. Pro adds apps and the Software Licensing module; everything else described in these docs is part of the core plugin.

## Activation

Activating Onumia prepares the runtime without changing any site behavior. The plugin creates the chat tables used by the AI sidebar and schedules the `onumia_tables_cleanup` event that prunes module records twice a day. Module data tables are not created up front; each one is created lazily the first time its module writes a record.

No module runs after activation. Modules only apply behavior once they have active saved settings, so a fresh install is inert until you deliberately configure something.

If no SQLite PHP interface is available, activation still succeeds. Default module tables use MySQL, and tables that explicitly request SQLite fall back to MySQL silently.

Production installs should use the packaged plugin ZIP, not a source checkout or symlink. The release package includes the compiled admin assets, scoped third-party libraries, docs, runtime modules, and the WordPress entry file; it excludes development fixtures, tests, PRDs, scripts, source maps, and local environment files. After installing or upgrading from a ZIP, run `wp onumia doctor --format=json` once to confirm the active plugin can see its storage, REST routes, cron schedules, table versions, asset manifests, and Pro integration readiness without exposing secrets.

## Opening the dashboard

After activation, a top-level Onumia item appears in the WordPress admin menu for users with `manage_options`. The whole product lives on that one page: the module archive, every module's settings screen, custom module editing, history, chat, and, with Pro, apps.

If you prefer the entry point under Tools or Settings instead of the top-level menu, the location is filterable; see the configuration reference.

## Your first module

A short first pass through one module shows you the whole loop.

1. Open the Onumia admin page and browse the module archive.
2. Open a module, for example Redirects or Login Activity.
3. Adjust its settings and switch the relevant section's Enable control on.
4. Save. Onumia validates the settings against the module's contract and writes them to the active theme's `onumia.settings.json`.
5. Reload the relevant part of WordPress and confirm the behavior applies.

The save is the activation. There is no separate enabled flag stored elsewhere: a module's runtime hooks boot on the next request because its saved settings are now active. Setting everything in a module back to off or empty deactivates it just as cleanly.

## Where things are stored

Knowing the storage layout up front makes the rest of Onumia predictable. Configuration lives in the active theme as `onumia.settings.json`, so it travels with your theme-level project state, can be version controlled with the theme, and survives database resets. Custom modules you create later also live in the active theme, under `onumia/modules/custom/`.

Operational records, logs, queues, hit counts, scan results, live in module-owned MySQL tables prefixed `onumia_` by default. A module table can opt into SQLite, in which case it uses a `.db` file under `wp-content/uploads/onumia/data/` when SQLite is available and falls back to MySQL when it is not. Chat conversations are stored in dedicated `onumia_chats` and `onumia_chat_messages` tables. Module secrets, such as API tokens, are kept in WordPress options or PHP constants rather than the theme file. Production services can instead use an owner-only site-scoped file through `ONUMIA_MODULE_SITE_SECRETS_FILE`, which also keeps credentials out of database backups.

One consequence worth noting immediately: because settings live in the theme's stylesheet directory, switching themes switches your Onumia configuration, and updating a theme by replacing its directory can delete it. The operations page covers how to handle both situations.
