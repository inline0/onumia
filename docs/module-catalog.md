---
title: "Module Catalog"
meta_title: "Onumia Module Catalog"
meta_description: "The bundled Onumia modules by category: admin, communication, content, media, operations, routing, security, and user controls."
path: "module-catalog"
order: 200
section: "Modules"
---

# Module Catalog

Onumia ships with a catalog of small, single-purpose modules. Each one is useful on its own, activates only when you save active settings, and can be remixed into a custom variant. This page lists the bundled catalog by category, as it appears in the dashboard archive.

Modules marked as keeping records store operational history in module-owned data tables, which you can filter, export, and purge from their screens; the module data page explains that storage in detail.

## Admin

These modules shape what the WordPress admin itself looks like for your users.

| Module | What it does |
| --- | --- |
| Admin Menu | Controls top-level WordPress admin menu visibility, order, and labels per role. |
| Dashboard Widgets | Controls dashboard widget visibility per role and registers custom static HTML dashboard widgets. |

## Communication

| Module | What it does |
| --- | --- |
| Email Log | Captures WordPress mail payloads, failures, and resendable delivery history. Keeps records. |

## Content

The content modules manage how WordPress models and retains content, work that otherwise tends to require code or several plugins.

| Module | What it does |
| --- | --- |
| Disable Comments | Applies rule-based comment controls by post type, role, route, or post age. |
| Post Revisions | Defines per-post-type revision retention rules with scheduled pruning. Keeps records. |
| Post Statuses | Edits existing post statuses and registers workflow-specific statuses. |
| Post Types | Edits existing post types and registers new ones. |
| Taxonomies | Edits existing taxonomies and registers new ones. |

## Media

| Module | What it does |
| --- | --- |
| Image Sizes | Registers custom image sizes, overrides core image dimensions, and batch-regenerates attachment derivatives. Keeps records. |
| Unused Media | Scans the Media Library for attachments not referenced by posts, widgets, theme mods, or core options, then queues safe deletions. Keeps records. |

## Operations

The operations modules give you visibility into what the site is doing and tools to keep it lean.

| Module | What it does |
| --- | --- |
| Activity Log | Records significant WordPress activity in a module-owned audit log. Keeps records. |
| Cron Events | Surfaces scheduled WP-Cron events, records manual runs, and guards unsafe unschedule actions. Keeps records. |
| Database Optimizer | Runs opt-in database cleanup tasks for transients, orphaned metadata, comments, posts, and MyISAM table optimization. Keeps records. |
| Error Log | Captures PHP errors, exceptions, deprecated notices, and shutdown fatals. Keeps records. |

## Routing

| Module | What it does |
| --- | --- |
| 404 Log | Records grouped 404 requests with referrer and user-agent context for broken-link review. Keeps records. |
| Redirects | Manages 301, 302, and 307 redirects with hit counts. Keeps records. |

## Security

The security modules audit and control how accounts authenticate.

| Module | What it does |
| --- | --- |
| Application Passwords | Provides site-wide audit and revocation controls for WordPress Application Passwords. Keeps records. |
| Login Activity | Records successful and failed login attempts for audit review. Keeps records. |
| Login Blocker | Blocks login attempts by rule, throttles brute-force traffic, and records attempts. Keeps records. |

## Users

| Module | What it does |
| --- | --- |
| Inactive Users | Finds dormant accounts, exempts known users, and can disable or delete inactive users with an audit log. Keeps records. |
| User Approval | Holds new registrations in a pending queue until an admin approves them, with optional trusted-domain auto approval. |
| User Roles | Creates custom roles and manages role capabilities. |

## Pro modules

The Pro bundle currently adds one module in the Commerce category.

| Module | What it does |
| --- | --- |
| Software Licensing | Products, license keys, activations, releases, and update checks for distributed WordPress software, with optional Stripe checkout. Keeps records. |

Software Licensing has its own page in the Pro section of these docs, because it also exposes public license endpoints and integrates with the Onumia update client.

## A note on WordPress feature dependencies

Some modules build on WordPress features such as WP-Cron, Application Passwords, open user registration, media metadata, or rewrite handling. A module that depends on one of those features only applies meaningfully when the feature exists and is enabled on your site; configuring User Approval on a site with registration disabled, for example, has nothing to act on. The bundled catalog also includes a development-only fixture module that stays hidden outside development mode.
