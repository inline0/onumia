---
title: "Module Data"
meta_title: "Onumia Module Data"
meta_description: "Where custom Onumia modules store settings and bounded operational records in MySQL or optional SQLite."
path: "module-data"
order: 70
section: "Modules"
---

# Module Data

Onumia keeps configuration and operational data separate. Configuration uses
the settings repository. Logs, queues, counts, and other records use
module-owned tables.

## MySQL by default

Tables use MySQL unless their contract explicitly requests SQLite. They are
created lazily: activation creates no module tables, an unread table behaves as
empty, and the first write creates only that table plus the schema registry.

```text
<wpdb_prefix>onumia_<module>_<table>
<wpdb_prefix>onumia_module_schema
```

The registry records each installed table version and checksum. A table
migrates lazily on its next write when its declaration changes.

## Optional SQLite

A SQLite table gets one file when `pdo_sqlite` or `SQLite3` is available:

```text
wp-content/uploads/onumia/data/<module>/<table>.db
```

Without a SQLite PHP interface, Onumia falls back to MySQL and pins that choice
in `storage.json` so later environment changes do not strand rows. The base
directory is filterable.

Onumia creates deny files for Apache-style hosts. Nginx operators should add an
equivalent rule for `/wp-content/uploads/onumia/data/`.

## Retention and privacy

A table can declare a row cap and retention window. The
`onumia_tables_cleanup` WP-Cron event enforces retention twice daily. Sites that
disable WP-Cron need an external runner.

Module helpers hash IP addresses by default with a per-install secret and the
WordPress salt. Filters can choose full redaction or raw storage when a site's
policy requires it. URI-like values pass through the redaction filter before
storage. User-linked records participate in the WordPress personal-data
exporter.

Administrators can export JSON/CSV, purge matching or aged rows, or clear a
table through the generic module data API.

## Deactivation and uninstall

Deactivation preserves all data. Uninstall removes Onumia-owned options,
schedules, `onumia_` MySQL tables, and the uploads data directory. Theme-owned
`onumia.settings.json` remains because it belongs to the theme; remove it
manually when a complete teardown is intended.

Removing an individual module does not destructively purge its existing data.
