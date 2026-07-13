---
title: "The Dashboard"
meta_title: "Onumia The Dashboard"
meta_description: "Use the Onumia admin app: the module archive, module detail screens, saving settings, data tables, and per-user layout state."
path: "the-dashboard"
order: 100
section: "Getting Started"
---

# The Dashboard

The Onumia dashboard is a single React app rendered inside a normal WordPress admin page. It does not take over the admin: the admin bar and menu stay where they are, and Onumia confines itself to its own page content. Everything you do with Onumia, browsing modules, configuring them, remixing, reviewing history, chatting with the AI sidebar, happens here.

Access requires the WordPress `manage_options` capability. The same capability gates every REST route the dashboard uses, so there is no path to Onumia configuration for non-administrators.

## The module archive

The archive is the home screen: the catalog of every module Onumia discovered, grouped by category. You can search by name, filter by category, and narrow to modules that are edited, meaning they have saved settings, or still at their defaults. A view toggle switches between cards and a compact list that renders the module tree.

When custom modules exist, the archive grows All and Custom tabs so your own modules are easy to separate from the bundled catalog, and custom modules are also reachable under their own route. A New button creates a fresh custom module from a minimal starter, which is the path for building a module from scratch rather than remixing an existing one.

The archive remembers your category filter and edited/default filter per user, so the view you left is the view you return to.

## The module detail screen

Opening a module shows its structured settings screen: tabs, sections, and typed controls generated from the module's contract. Sections that represent a feature carry an Enable switch in their header, which is how you turn the behavior on or off. Disabled sections stay visible but inert, so you can always see what a module could do.

The header carries the two primary actions. Save validates your changes against the module's PHP contract and merges them into the stored settings; invalid values are rejected with a clear error rather than partially applied. Remix copies the module into a new custom module, carrying your current draft settings along.

A three-dot menu left of the breadcrumb holds two layout options, both persisted per user and both effective on wide screens: Show module sidebar opens a left-edge module list with search and category grouping for fast switching, and Full width lets the settings screen use the whole page width.

For custom modules and custom apps, a sidebar control in the header opens the right-hand panel with the Chat and History tabs. Bundled modules do not show that control, because chat editing and history only apply to entities you own.

## Actions, data sources, and live data

Module screens are not limited to static settings. Buttons on a module screen run module actions, server-side operations the module declares, such as running a cleanup task, regenerating image sizes, or resending an email. Status lists, metrics, and charts are fed by module data sources, so screens like the Database Optimizer or Software Licensing overview show live numbers from your site.

Modules that keep operational records render them as data tables with filtering, search, and sorting, backed by the module's own storage. From these surfaces an administrator can export records as JSON or CSV and purge them, either entirely or by age. Some modules expose richer entry collections, list screens with a drawer form for creating and editing rows, which is how, for example, licensing products and tiers are managed.

## Saving and validation

Saves are partial and explicit. The dashboard sends only the settings you changed; Onumia validates them against the module's typed contract, merges them with what is already stored, and writes the result atomically to the active theme's `onumia.settings.json`. Concurrent saves are serialized with a lock, so two browser tabs cannot corrupt the file.

For custom modules, every successful save is also a history commit, and saving files (rather than just settings) first runs the Onumia checker so broken JSON or PHP never reaches disk. While you are previewing a historical version of a custom module, the Save button becomes Revert; the history page covers that flow.

## Per-user state

The dashboard stores small amounts of UI state per WordPress user: the archive filters, the module detail layout toggles, and, for each custom module or app, whether the sidebar is open, which tab it shows, and which chat is active. This state lives in user meta under `onumia_ui_state`, never in the shared settings file, so one operator's layout preferences never affect another's.
