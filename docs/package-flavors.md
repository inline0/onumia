---
title: "Package Flavors"
meta_title: "Onumia Free And Pro Packages"
meta_description: "How Onumia Free and Onumia Pro packages replace one another while retaining settings and using one WordPress plugin identity."
path: "package-flavors"
order: 95
section: "Getting Started"
---

# Package Flavors

Onumia Free and Onumia Pro are separate packages built from this source tree.
Both install as `onumia/onumia.php` inside one top-level `onumia/` directory, so
upgrading to Pro replaces Free instead of adding a duplicate plugin.

| Package | Update product | Included runtime |
| --- | --- | --- |
| Onumia Free | `onumia` | Core modules and the Free admin app |
| Onumia Pro | `onumia-pro` | Everything in Free plus Pro apps, the Pro admin app, and Pro modules |

Free packages omit `src/Pro`, `assets/app-pro`, the licensed update client,
licensing server code, migrations, private licensing configuration, and Pro-only
documentation. They retain the signed GitHub updater under `src/Updates`. Pro
packages include Core and Pro assets together and disable the Free update
channel. The selected target and flavor are recorded in
`package-manifest.json`.

Free-to-Pro and Pro-to-Pro replacement preserve module settings, secrets, table
schema state, and user UI state. Deactivation also preserves data. WordPress
uninstall runs Onumia's owned-data cleanup and removes Onumia tables, options,
scheduled jobs, user UI state, and capabilities.

Do not rename the Pro archive to `onumia-pro/` or activate both copies. Packaged
builds reject any basename other than `onumia/onumia.php`; normal installation
replaces the existing `onumia/` directory in place.
