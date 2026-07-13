---
title: "Custom Modules And Remixing"
meta_title: "Onumia Custom Modules And Remixing"
meta_description: "Remix bundled Onumia modules into theme-stored custom modules, create modules from scratch, and edit their files with validation."
path: "custom-modules-and-remixing"
order: 300
section: "Customization"
---

# Custom Modules And Remixing

The bundled catalog is a starting point, not a ceiling. Any module can be remixed: copied into a custom module that belongs to your site, where you are free to change its settings screen, its messages, and its PHP behavior. Custom modules are full modules, discovered, validated, configured, and activated exactly like bundled ones, with two additions: their files are editable in the dashboard, and every change is recorded in version history.

## Remixing a module

Remix is available from every module's detail screen. When you remix, Onumia copies the module's manifest, settings screen definition, behavior file, messages, and supporting files into a new folder in your active theme, gives it a `custom/` name, and rewrites its PHP class so the copy is independent of the original. Your current draft settings are validated against the source module and saved for the new custom module, so a remix picks up exactly the configuration you were looking at.

The first remix of a module keeps its base name, for example `custom/redirects` with the label "Redirects Remix". Remixing the same source again appends a counter: `custom/redirects-2` labeled "Redirects Remix 2", and so on. The remix is also the first commit in the new module's history.

The original module is untouched. A remix is a fork, not an override: the bundled module keeps its own settings and stays in the catalog, and your custom variant evolves separately.

## Creating a module from scratch

The archive's New button creates a custom module from a minimal starter instead of copying an existing one. It goes through the same validation as every other custom-module write, so even the starting point is a checked, loadable module. From there you edit it like any other custom module.

## Where custom modules live

Custom modules are created in the active theme:

```text
wp-content/themes/<active-theme>/onumia/modules/custom/...
```

Living in the theme is the point: custom modules deploy with the theme, can be committed to the theme's repository, and stay aligned with the theme-level project they belong to. The location is filterable for sites that keep them elsewhere; see the configuration reference.

Two operational consequences follow. Switching the active theme changes which custom modules are discovered, and updating a theme by replacing its directory will delete custom modules that are not under version control. The operations page covers both.

## Editing custom module files

For custom modules, the dashboard is also a file editor. You can edit the settings screen definition, the messages, the manifest, and the PHP behavior file, directly or through the AI chat sidebar. Edits accumulate as a draft: changes to the screen definition are reflected immediately in the rendered settings screen, so you can see the result while you work, while PHP behavior changes take effect on the site only after you save.

Every write is validated. The Onumia checker verifies JSON contracts against their schemas, parses the PHP contract without executing it, cross-checks that the screen only references settings, actions, data sources, and messages that actually exist, and rejects anything that would leave the module unloadable. Failed checks return diagnostics with file and line context, and nothing reaches disk until a save passes the full check. A passing save writes the files and records a history commit.

## PHP in custom modules is real PHP

A custom module's behavior file runs on your site once the module has active settings, with the same standing as any plugin code. Validation guarantees the module is well-formed; it does not review what the code chooses to do. Treat custom module changes the way you treat code deployment: only administrators can create or edit them, history gives you an audit trail and a way back, and anything security-sensitive deserves the same review you would give a plugin update.
