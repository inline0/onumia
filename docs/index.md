---
title: "Introduction"
meta_title: "Onumia Documentation"
meta_description: "User-facing documentation for installing, configuring, and operating Onumia, the modular WordPress control layer."
path: "."
order: 0
---

# Introduction

Onumia is a WordPress plugin that gathers the small operational jobs of running a site, content models, admin controls, security checks, logs, and cleanup tools, into one modular system with one shared dashboard. Each job is a module: a small, single-purpose unit you configure from the Onumia admin app and that runs focused WordPress behavior behind that screen.

These docs explain how that system fits together: how modules are discovered, configured, and activated, where settings and operational data live, how you remix bundled modules into your own custom variants with full version history, and what the Pro tier adds on top.

## What Onumia does

WordPress sites tend to accumulate one plugin per operational task: one for redirects, one for login logs, one for custom post types, one for database cleanup. Each brings its own settings screen, its own storage habits, and its own idea of what is active. Onumia replaces that sprawl with a single catalog of small modules behind one interface, one settings model, and one clear boundary for what is changing your site.

Modules are deliberately explicit. A module only applies runtime behavior when it has active saved settings, so the dashboard doubles as an honest review surface: what you see enabled is what is actually running. Settings are stored in the active theme as a single JSON file, so a site's Onumia configuration travels with the theme-level project state rather than hiding in the database.

Onumia is also remixable. Any module can be copied into a custom variant that lives in your theme, where you can edit its settings screen, its messages, and its PHP behavior. Every custom module carries its own Git history, so each save is a version you can preview and revert. An AI chat sidebar can edit custom module files for you, with every change validated before it is accepted.

## Concepts to know

A few terms recur throughout these docs.

| Concept | What it means |
| --- | --- |
| Module | A small, single-purpose unit of backend WordPress behavior with a structured settings screen. |
| Module settings | Per-module configuration stored in the active theme's `onumia.settings.json` file. |
| Active settings | The condition for a module to run: at least one saved setting that is meaningfully enabled or non-empty. |
| Module data tables | Module-owned operational records such as logs and queues, stored in SQLite by default. |
| Custom module | A module that lives in your active theme under the `custom/` namespace, created by remixing or from scratch. |
| Remix | Copying an existing module into a new custom module you can edit freely. |
| History | The per-custom-module Git history of file and settings changes, with preview and revert. |
| App | A Pro feature: a full-width JSON dashboard that can ship its own admin page, replace a WordPress screen, or register a shortcode. |

## How these docs are organized

If you are setting Onumia up for the first time, start with Getting Started for requirements, activation, and your first module, then read The Dashboard to learn the admin app itself. The Modules pages explain the module model, the bundled catalog, and where module data lives.

The Customization pages cover remixing, custom modules, version history, and the AI chat sidebar. The Pro pages describe apps and the Software Licensing module. Finally, the Reference pages collect security and privacy behavior, configuration filters and constants, troubleshooting, the release checklist, and the generated hooks reference for developers.

## Scope and boundaries

Onumia is a backend control layer. It is not a page builder, not a frontend design framework, and it does not add widgets or visual effects to your public site. Bundled modules manage content models, admin behavior, security, logging, and cleanup; none of them render frontend UI.

Pro apps extend the admin side of that boundary: they can present full-width dashboards inside WordPress admin and even replace admin screens such as the WordPress dashboard, but they remain admin surfaces rather than public site features.

## Current surfaces

Onumia adds one WordPress admin page, labeled Onumia, that hosts the entire dashboard. Access to the dashboard and to its REST API under `/wp-json/onumia/v1/` requires the WordPress `manage_options` capability, so configuration stays with administrators. The only anonymous REST surface Onumia exposes is the small set of public routes that individual modules declare explicitly, such as the license endpoints of the Pro Software Licensing module, and each of those routes is authenticated and rate limited on its own terms.

Module settings live in the active theme, module operational data lives in SQLite files under the uploads directory by default, and chat history lives in dedicated database tables. Site-specific behavior can be tuned through Onumia's public WordPress filters, documented in the configuration reference and the generated hooks reference.
