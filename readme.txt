=== Onumia ===
Contributors: inline0
Tags: administration, modules, operations, developer-tools
Requires PHP: 8.2
Stable tag: trunk
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Build nested documents and user-owned declarative modules in one fullscreen WordPress workspace.

== Description ==

Onumia provides an infinitely nestable page tree and a Notion-style block
editor inside WordPress admin. Sites can extend the workspace with PHP-owned
modules rendered through Onumia's shared declarative UI system. UI Lab ships as
a hidden, administrator-only diagnostic surface for explicit development
requests.

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

= How do custom modules enter the workspace? =

Trusted site code appends a narrow absolute root through the
`onumia/modules/roots` filter. Onumia validates each module's metadata,
structure, PHP contract, and capabilities before exposing it.

== Changelog ==

= 0.1.3 - 2026-07-22 =
* Unify configurable workspace navigation across Divine and Onumia
* Build the shared navigation row for groups, pages, and sites
* Remove the Divine workspace create-app control
* Add persisted group icons with a lazy Lucide picker
* Align the existing Add page, group, and worktree rows
* Use one theme-aware Onumia header mark
* Add a shared empty workspace screen
* Unify Divine and Onumia workspace navigation rows
* Replace workspace group Lucide icons with emoji
* Polish Onumia sidebar spacing parity
* Cap shared dropdown menus at six visible items
* Use 8px depth steps in shared workspace navigation
* Retire Onumia Pro licensing and Stripe as Onumia becomes a single free edition.
* Retain UI Lab as an administrator-only opt-in diagnostic while removing the retired bundled modules.
* Remove the obsolete App block from the Onumia block editor.

= 0.1.1 - 2026-07-14 =
* No public changes.

= 0.1.0 - 2026-07-13 =
* Prevent concurrent sandbox promotions from colliding
* Clear scheduled Onumia jobs when the plugin is deactivated
* Prevent live promotion from racing with merge operations
* Complete the Onumia product rename
* Use MySQL by default for module data with optional SQLite storage
* Add deterministic WordPress packages with safe in-place upgrades
* Add signed GitHub updates with exact release and checksum verification.
* Use one signed, transaction-safe release pipeline across every product package.
