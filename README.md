<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./assets/logo-light.svg">
    <img alt="Onumia" src="./assets/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  Modular WordPress operations, content, security, and admin controls
</p>

<p align="center">
  <a href="https://github.com/inline0/onumia/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg" alt="license"></a>
</p>

---

## Contents

- [What Is Onumia?](#what-is-onumia)
- [Install](#install)
- [Why It Exists](#why-it-exists)
- [Mental Model](#mental-model)
- [Boundaries](#what-onumia-is-not)
- [Current Modules](#current-modules)
- [Module Settings](#module-settings)
- [Data And Privacy](#data-and-privacy)
- [Pro Features](#pro-features)
- [Updates And Security](#updates-and-security)
- [Requirements](#requirements)
- [Public Hooks](docs/hooks.md)
- [License](#license)

## What Is Onumia?

Onumia is a modular control layer for WordPress.

It gives site owners and operators a single place to manage operational tasks
that are usually scattered across many small plugins: post types, taxonomies,
redirects, login activity, role controls, comment policies, media cleanup,
cron events, database cleanup, email logging, error logging, and more.

Each feature is a **module**. Modules expose a structured settings screen in
the Onumia admin app and run focused WordPress behavior behind that screen.
The goal is not to replace WordPress. The goal is to make WordPress operations
more visible, safer to change, and easier to audit.

## Install

Download `onumia-vX.Y.Z.zip` from
[Onumia Releases](https://github.com/inline0/onumia/releases), then upload it
through **Plugins -> Add New -> Upload Plugin** and activate **Onumia**. On a
multisite network, network-activate it when all sites should use the same plugin
runtime.

Onumia Pro customers receive `onumia-pro-vX.Y.Z.zip` through Onumia's licensed
delivery service. Upload it over Onumia Free. Both packages use
`onumia/onumia.php`, so Pro replaces Free without creating a second plugin or
discarding module settings.

Configure the issued Pro key in `wp-config.php` or the process environment:

```php
define( 'ONUMIA_LICENSE_KEY', 'ONUMIA-XXXX-XXXX-XXXX' );
```

The Pro updater uses `https://onumia.app/`, product `onumia-pro`, and the
`stable` channel by default. Continue with
[Getting Started](docs/getting-started.md).

## Why It Exists

WordPress sites often accumulate many plugins for small operational jobs:

- one plugin for redirects,
- one plugin for custom post statuses,
- one plugin for login logs,
- one plugin for dashboard widgets,
- one plugin for image size cleanup,
- another plugin for database maintenance.

That can make a site harder to understand and harder to keep consistent.

Onumia combines those backend controls into one modular system with one
shared interface, one settings model, and one clear boundary for what is
active on the site.

## Mental Model

```text
WordPress admin
  |
  v
Onumia app
  |
  | module settings
  v
Active theme Onumia data
  |
  | enabled module behavior
  v
WordPress runtime
```

Modules are configured from the Onumia admin screen. Settings are stored in
the active theme's Onumia data so a site's configuration can travel with the
theme-level project state.

By default, module settings are stored in the active theme stylesheet directory
as `onumia.settings.json`. On multisite installs, sites sharing the same
active theme directory also share this settings file. Per-site runtime state
should live in options or module-owned tables; module secrets already use
WordPress options instead of the theme settings file.

Some modules also maintain module-owned data tables for audit trails, queues,
logs, and operational history. Examples include email logs, 404 hits, redirect
hit counts, cron run history, login activity, and cleanup queues.

## What Onumia Is Not

Onumia is not a page builder.

Onumia is not a frontend design framework.

Onumia is not intended to add frontend widgets or visual effects to a public
site. Its first release focuses on backend WordPress operations: content
models, admin controls, security checks, logs, cleanup tools, and structured
site configuration.

## Current Modules

### Admin

| Module | Purpose |
| --- | --- |
| Admin Menu | Control top-level WordPress admin menu visibility, order, and labels per role. |
| Dashboard Widgets | Hide dashboard widgets per role and register custom static dashboard widgets. |

### Communication

| Module | Purpose |
| --- | --- |
| Email Log | Capture WordPress mail payloads, failures, and resendable delivery history. |

### Content

| Module | Purpose |
| --- | --- |
| Disable Comments | Apply rule-based comment controls by post type, role, route, or post age. |
| Post Revisions | Define per-post-type revision retention rules and scheduled pruning. |
| Post Statuses | Edit existing post statuses and register workflow-specific statuses. |
| Post Types | Edit existing post types and register new post types. |
| Taxonomies | Edit existing taxonomies and register new taxonomies. |

### Media

| Module | Purpose |
| --- | --- |
| Image Sizes | Register custom image sizes, override core image dimensions, and batch-regenerate attachment derivatives. |
| Unused Media | Scan for attachments not referenced by posts, widgets, theme mods, or core options, then queue safe deletions. |

### Operations

| Module | Purpose |
| --- | --- |
| Activity Log | Record significant WordPress activity in a module-owned audit log. |
| Cron Events | Surface scheduled WP-Cron events, record manual runs, and guard unsafe unschedule actions. |
| Database Optimizer | Run opt-in cleanup tasks for transients, orphaned metadata, comments, posts, and MyISAM table optimization. |
| Error Log | Capture PHP errors, exceptions, deprecated notices, and shutdown fatals. |

### Routing

| Module | Purpose |
| --- | --- |
| 404 Log | Record grouped 404 requests with referrer and user-agent context. |
| Redirects | Manage 301, 302, and 307 redirects with hit counts. |

### Security

| Module | Purpose |
| --- | --- |
| Application Passwords | Audit and revoke WordPress Application Passwords across the site. |
| Login Activity | Record successful and failed login attempts for audit review. |
| Login Blocker | Block login attempts by rule, throttle brute-force traffic, and record attempts. |

### Users

| Module | Purpose |
| --- | --- |
| Inactive Users | Find dormant accounts, exempt known users, and disable or delete inactive users with an audit log. |
| User Approval | Hold new registrations in a pending queue until an admin approves them. |
| User Roles | Create custom roles and manage role capabilities. |

## Module Settings

Onumia modules are explicit. A module can expose settings without forcing
behavior onto the site. Runtime behavior only applies when the relevant module
or module section is enabled.

This keeps the admin app useful as a review surface while still making it clear
which behavior is actively changing WordPress.

## Data And Privacy

Onumia stores two kinds of data:

- configuration in the active theme's Onumia data,
- operational records in module-owned tables.

Operational tables are used for records that need history, pagination, export,
or cleanup. Examples include login attempts, email sends, 404 hits, redirect
hits, cron runs, cleanup runs, and scan results.

Security and audit modules are designed to avoid storing more sensitive data
than needed. Where possible, Onumia stores hashes, summaries, or redacted
payloads instead of raw secrets.

## Pro Features

Onumia includes a Pro boundary for features that go beyond the free module
layer.

Pro functionality is separated from the free plugin runtime. Free modules stay
available as backend WordPress controls. Pro features can add higher-level
workflows such as apps, AI-assisted module editing, shared chats, and advanced
role-aware experiences.

Both distributions retain the canonical `onumia/onumia.php` plugin identity.
See [Package Flavors](docs/package-flavors.md) for package contents,
replacement, and data-retention behavior.

## Updates And Security

Onumia Free uses signed GitHub Releases and WordPress's normal plugin update
screen. Before installation it verifies the release's Ed25519-signed checksum
manifest and the exact `onumia-vX.Y.Z.zip` selected by WordPress.

Onumia Pro disables that Free channel. It presents the configured license to
`onumia.app`, receives an expiring package URL, and verifies the checksum,
`onumia/onumia.php` identity, target, and version before installation. Customer
plugins never receive the service's private GitHub credential.

Manual uploads and host deployment tools can bypass the updater hooks. Verify
the signed release assets before using those paths. See the
[Security Policy](SECURITY.md) and
[Security And Privacy](docs/security-and-privacy.md) for the complete boundary.

## Requirements

Onumia requires:

- WordPress with a modern admin environment,
- PHP 8.2 or newer,
- administrator access for setup and module configuration.

Some modules depend on WordPress features such as WP-Cron, Application
Passwords, user registration, media metadata, or rewrite handling. Modules that
use those WordPress features only apply when the related feature exists on the
site.

## License

Onumia is distributed under AGPL-3.0-or-later.
