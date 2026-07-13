=== Onumia ===
Contributors: inline0
Tags: administration, modules, operations, developer-tools
Requires PHP: 8.2
Stable tag: trunk
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Manage WordPress operations through one modular control layer.

== Description ==

Onumia provides focused modules for WordPress administration, diagnostics,
maintenance, security, content structure, and developer workflows. Modules are
configured from one admin app while WordPress remains the source of truth.

== Installation ==

1. Download `onumia-vX.Y.Z.zip` from https://github.com/inline0/onumia/releases.
2. Open Plugins -> Add New -> Upload Plugin in WordPress.
3. Upload `onumia-vX.Y.Z.zip` and activate Onumia.
4. Open Onumia from WordPress admin.

== Frequently Asked Questions ==

= How are updates verified? =

The built-in updater binds WordPress to the exact Onumia GitHub release,
verifies its Ed25519-signed checksum manifest, and checks the selected package
before installation. Invalid, missing, or substituted assets stop the update.

= Does Onumia Pro install as a second plugin? =

No. Free and Pro both use `onumia/onumia.php`, so installing Pro replaces Free
while preserving settings and owned data.

== Changelog ==

= 0.1.0 - 2026-07-13 =
* Prevent concurrent sandbox promotions from colliding
* Clear scheduled Onumia jobs when the plugin is deactivated
* Prevent live promotion from racing with merge operations
* Complete the Onumia product rename
* Use MySQL by default for module data with optional SQLite storage
* Add deterministic Free and Pro packages with safe in-place upgrades
* Add signed GitHub updates with exact release and checksum verification.
* Use one signed, transaction-safe release pipeline across every product package.
