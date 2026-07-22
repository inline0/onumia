<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./assets/brand/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./assets/brand/logo-light.svg">
    <img alt="Onumia" src="./assets/brand/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  A page-first WordPress workspace for structured documents and custom modules
</p>

<p align="center">
  <a href="https://github.com/inline0/onumia/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg" alt="license"></a>
</p>

---

## What Onumia is

Onumia is a fullscreen WordPress admin workspace. Its left sidebar contains an
infinitely nestable page tree, and the main view is a Notion-style block
document for headings, paragraphs, lists, tables, media, and other supported
editor blocks.

Onumia also keeps a generic PHP module contract and declarative UI renderer for
user-owned modules. A module can provide settings, actions, data sources,
entries, tables, jobs, hooks, migrations, and custom routes without replacing
the core workspace frontend.

The package includes UI Lab as a hidden diagnostic module. It is absent from
ordinary navigation. An authenticated administrator can expose it for the
current request with `?onumia-dev=1`; the flag is not persisted and never
replaces server-side `manage_options` checks.

## Install

Download `onumia-vX.Y.Z.zip` from
[Onumia Releases](https://github.com/inline0/onumia/releases), then install it
through **Plugins -> Add New -> Upload Plugin**. Activate Onumia and open it
from WordPress admin.

The canonical plugin basename is `onumia/onumia.php`. There is one package and
one update channel. The public repository works without a GitHub token.

See [Getting Started](docs/getting-started.md) for the workspace flow and
[Modules](docs/modules.md) for the extension contract.

## Architecture

- One fullscreen workspace frontend compiled to `assets/app`
- WordPress-backed nested pages and TipTap JSON documents
- A generic declarative renderer for user-owned modules
- PHP-backed module settings, data sources, actions, tables, and jobs
- A narrowly registered, request-gated UI Lab diagnostic module
- A signed public GitHub release updater

The administrative REST API lives under `/wp-json/onumia/v1/` and requires
`manage_options` by default. Custom module routes must declare and enforce their
own capability and authentication contract.

## Data and privacy

Page documents are WordPress content. Small workspace preferences are stored
per user. Custom module configuration uses the settings repository, while
operational records live in module-owned MySQL tables or optional SQLite files.
Module secrets are kept out of the theme settings file.

Existing module tables are not destructively purged when a module disappears.
Legacy page documents containing an unsupported node remain loadable without
rewriting unrelated document content.

See [Module Data](docs/module-data.md), [Security And
Privacy](docs/security-and-privacy.md), and the generated [Public
Hooks](docs/hooks.md) reference for details.

## Requirements

- PHP 8.2 or newer
- WordPress with REST enabled
- An administrator account with `manage_options`
- WP-Cron when a custom module schedules work

## License

Onumia is distributed under AGPL-3.0-or-later. See the [license](LICENSE) for
the complete terms.
