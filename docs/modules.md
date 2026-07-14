---
title: "Modules"
meta_title: "Onumia Modules"
meta_description: "How Onumia modules work: discovery, the activation model, settings in the active theme, secrets, capabilities, and scheduled jobs."
path: "modules"
order: 150
section: "Modules"
---

# Modules

A module is the unit of behavior in Onumia. Each one solves a single concrete problem, exposes a structured settings screen in the dashboard, and runs focused WordPress behavior behind that screen. Modules are useful on their own; you activate the ones you want and ignore the rest.

This page explains the model: where modules come from, what makes one active, and where everything they own is stored.

## What a module is made of

A module is a folder. It contains a small manifest with the module's identity, category, label, and description; a JSON definition of its settings screen; a PHP file that owns the runtime behavior, settings types, defaults, validation rules, actions, and WordPress hooks; and a message catalog for its UI text. Modules that keep operational records also declare their data tables.

The split matters in practice: everything you see in the dashboard is described declaratively, while everything the module does to WordPress is plain PHP. The dashboard renders the screen, the PHP validates and applies your settings, and neither side can drift from the other because saves are checked against the PHP contract.

Stable module identity comes from the manifest name, such as `onumia/redirects` or `custom/redirects`, not from the folder path. Bundled modules use the `onumia/` namespace; your custom modules use `custom/`.

## Where modules come from

Onumia discovers modules from explicit roots: the plugin's own `modules/` directory for the bundled catalog, and the active theme's `onumia/modules/` directory, in both the child and parent theme when both exist, for modules that belong to your site. The Pro bundle adds its own module root for Pro modules. Any folder below a root that contains a manifest is a module.

This is why custom modules travel with your theme: they are ordinary discovered modules that happen to live in theme territory. Deploying the theme deploys the modules.

Development-only modules exist in the bundled tree but stay hidden unless the dashboard is explicitly opened in dev mode, so you will not normally see them.

## The activation model

Onumia has no stored on/off flag for modules. A module's runtime boots when, and only when, it has active saved settings in the settings file: a switch saved on, a non-zero number, a non-empty list, a meaningful string. Until then the module is just a catalog entry you can open, read, and configure.

This makes the dashboard trustworthy as a review surface. Every discovered module is always editable, but the set of modules actually hooking into WordPress is exactly the set with active settings. Turning everything in a module off, or clearing its values, deactivates it without any residue.

## Settings live in the active theme

All module settings are stored in one JSON file in the active theme's stylesheet directory:

```text
wp-content/themes/<active-theme>/onumia.settings.json
```

Saves are validated against each module's typed contract, merged partially with what is already stored, and written atomically under a lock. The file is plain, readable JSON keyed by module name, which makes configuration reviewable, diffable, and easy to version control alongside the theme.

Two consequences follow from this choice. First, switching themes switches configuration: each theme carries its own Onumia state, which is often exactly what a theme-level project wants, but can surprise you if you switch casually. Second, on multisite, sites that share the same active theme directory share the same settings file. Per-site runtime state belongs in options or module-owned tables, not in the theme file.

The settings file location is filterable for setups that want it elsewhere; see the configuration reference.

## Secrets stay out of the theme

Some modules need credentials, such as API tokens for the Software Licensing module. Secrets are deliberately not stored in the theme settings file. They live in a WordPress option or come from PHP constants, so they never end up committed with a theme. Production services can define `ONUMIA_MODULE_SITE_SECRETS_FILE` and resolve every module secret from an owner-only, site-scoped file outside the web root; when configured, that file is authoritative and an invalid file fails closed. The dashboard shows whether each declared secret is present without revealing its value.

## Capabilities

Every module declares the WordPress capability required to use it, `manage_options` unless the module says otherwise, and each action or data source can require its own capability on top. The Software Licensing module, for example, requires `manage_onumia_licenses`, which Pro grants to administrators. Modules can additionally carry an access policy that limits visibility to specific roles, users, or capabilities; a module that fails its policy simply does not appear for that user.

Since the dashboard itself already requires `manage_options`, these layers matter most when a site deliberately broadens or narrows access through filters or custom roles.

## Scheduled work

Modules schedule recurring work through WP-Cron. Post Revisions registers a daily pruning event while its retention rules are enabled, and the Software Licensing module declares jobs that sync releases daily and expire licenses hourly. Separately, Onumia runs its own `onumia_tables_cleanup` event twice a day to enforce the retention windows of module data tables. All of this depends on WP-Cron actually running on your site.
