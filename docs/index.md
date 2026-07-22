---
title: "Introduction"
meta_title: "Onumia Documentation"
meta_description: "User-facing documentation for Onumia's nested page workspace and extensible PHP module system."
path: "."
order: 10
section: "Getting Started"
---

# Introduction

Onumia is a fullscreen WordPress admin workspace for nested documents and
custom operational tools. Pages live in one infinitely nestable sidebar. Their
documents support headings, paragraphs, lists, tasks, tables, code, media, and
the other editor blocks exposed by the slash menu.

User-owned PHP modules can add declarative administrative screens without
shipping another frontend. Onumia's generic renderer displays their controls,
tables, charts, entries, drawers, and tabs while PHP owns settings, data
sources, actions, tables, jobs, hooks, migrations, and routes.

## Concepts

| Concept | Meaning |
| --- | --- |
| Page | A WordPress-backed document that can be nested under another page. |
| Block editor | The Notion-style editor for ordinary page content. |
| Module | A user-owned PHP extension with a declarative structure and server-side behavior. |
| Module renderer | The shared UI renderer for every registered module contract. |
| UI Lab | A bundled diagnostic module visible only to an opted-in administrator for one request. |
| Module data | Operational records stored through module-declared MySQL or optional SQLite tables. |

## Boundaries

The workspace is an administrator surface protected by `manage_options` and
the WordPress REST nonce. It does not add presentation elements to the public
site. A custom module may change WordPress behavior or expose an explicitly
declared public route, but that route must provide its own authentication and
rate limits.

The canonical API lives under `/wp-json/onumia/v1/`. Pages and UI state have
dedicated endpoints. Module screens consume the generic PHP contract and call
its settings, data-source, and action endpoints.

Start with [Getting Started](getting-started.md) and [The
Workspace](the-dashboard.md). Continue with [Modules](modules.md), [Module
Data](module-data.md), [Configuration](configuration-reference.md), and
[Security And Privacy](security-and-privacy.md).
