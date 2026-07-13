---
title: "Module Data"
meta_title: "Onumia Module Data"
meta_description: "Where Onumia modules store operational records: MySQL by default, optional SQLite files, row caps, retention cleanup, exports, privacy, and uninstall."
path: "module-data"
order: 250
section: "Modules"
---

# Module Data

Onumia stores two kinds of data and keeps them strictly apart. Configuration lives in the active theme's settings file, covered on the modules page. Operational records, the logs, queues, hit counts, and scan results that modules accumulate while running, live in module-owned data tables. This page explains where those tables live, how they are bounded, and what happens to them at export, privacy-request, and uninstall time.

## MySQL by default

Module tables use MySQL unless a table explicitly asks for SQLite. Tables are created lazily: activation creates no module data tables, reads against a table that has never been written return empty results, and the first write creates only that table plus the small schema registry table Onumia uses for version tracking.

```text
<wpdb_prefix>onumia_<module>_<table>
<wpdb_prefix>onumia_module_schema
```

Onumia records the installed version and checksum for each created table in `<wpdb_prefix>onumia_module_schema`. Later writes reuse that schema state without rerunning `dbDelta`; a module table is migrated lazily on the next write only when its declared schema version or checksum changes.

The Pro Software Licensing module also uses MySQL for its commerce records. The AI chat sidebar uses its own dedicated chat tables: `<wpdb_prefix>onumia_chats`, `<wpdb_prefix>onumia_chat_messages`, and `<wpdb_prefix>onumia_chat_members`.

## Optional SQLite

A module table can opt into SQLite. When `pdo_sqlite` or `SQLite3` is available, that table gets one SQLite database file:

```text
wp-content/uploads/onumia/data/<module>/<table>.db
```

SQLite files are also created lazily on the first write. The base directory is filterable for hosts that want the files elsewhere.

If a SQLite-declared table runs on a host without a SQLite PHP interface, Onumia silently falls back to MySQL so the module remains operational. The resolved choice is recorded in `wp-content/uploads/onumia/data/storage.json`; a table that fell back to MySQL stays pinned there rather than flipping to SQLite later and stranding rows.

## Direct access protection

Onumia writes a deny-all `.htaccess` file and an empty `index.php` into the data directory when it creates SQLite storage. This protects SQLite `.db` files on Apache-style hosts. For nginx, add an equivalent deny rule for the same path, for example:

```nginx
location ^~ /wp-content/uploads/onumia/data/ {
    deny all;
}
```

## Row caps and retention

Module tables are bounded by design, so logs cannot grow without limit. A table can declare a row cap, enforced automatically as new rows are inserted, and a retention window in days, enforced by the `onumia_tables_cleanup` WP-Cron event that runs twice daily. As a concrete example, the Login Blocker keeps at most 50,000 login attempts for 30 days, and the Email Log keeps at most 25,000 emails for 30 days.

Retention cleanup depends on WP-Cron. On sites where WP-Cron is disabled without a system cron replacement, records past their retention window stay until the event next runs.

## Exporting and purging

You stay in control of the records themselves. From a module's data table screens, an administrator can export rows as JSON or CSV, purge rows older than a chosen age or matching a filter, or clear a table entirely. The same operations are available over the Onumia REST API for administrators, which makes scripted exports and cleanups straightforward.

## What gets stored about people

Modules that record traffic and authentication data are designed to keep less than they could. IP addresses are not stored raw: module helpers hash them with a per-install secret combined with your WordPress auth salt, so records remain correlatable within your site but are not portable identifiers. A filter can switch IP handling to full redaction, or, where a site genuinely needs it, raw storage. URI-like values pass through a redaction filter before storage as well.

Onumia also integrates with the WordPress personal data exporter. A "Onumia module tables" exporter is registered automatically, and when a privacy request runs, it collects rows from active modules' tables that reference the requested user by user ID or username, so module records participate in WordPress's standard privacy tooling without extra setup.

## Uninstall

Deactivating Onumia leaves all data in place. Uninstalling, deleting the plugin from the WordPress Plugins screen after confirming the deletion prompt, removes the operational data Onumia owns: every `onumia_`-prefixed MySQL table is dropped and the module data directory under uploads is deleted.

Theme-stored state is deliberately not touched by uninstall. The `onumia.settings.json` file and any custom modules live in your theme, not in the plugin, so they survive plugin removal and are restored to working order if Onumia is installed again later. Remove them from the theme yourself if you want a complete teardown.
