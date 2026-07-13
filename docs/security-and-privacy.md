---
title: "Security And Privacy"
meta_title: "Onumia Security And Privacy"
meta_description: "How Onumia gates access, limits its public surface, minimizes personal data with IP hashing, handles secrets and AI keys, and cleans up on uninstall."
path: "security-and-privacy"
order: 550
section: "Reference"
---

# Security And Privacy

Onumia is an administrator's tool, and its security posture follows from that: one clear capability gate around the whole product, a deliberately tiny public surface, and data minimization in the modules that record traffic and authentication activity. This page collects those behaviors in one place.

## Who can do what

The dashboard page and every administrative REST route under `/wp-json/onumia/v1/` require the WordPress `manage_options` capability. There is no partial access tier: configuring modules, running actions, reading module data tables, remixing, editing custom module files, chat, history, and app management are all administrator activities. REST requests from the dashboard are authenticated with the standard WordPress cookie and nonce mechanism.

Within that boundary, modules add their own checks. Each module declares a required capability, and individual actions and data sources can demand more specific ones, as the Software Licensing module does with `manage_onumia_licenses`. Modules and apps can also carry access policies that limit visibility to particular roles, users, or capabilities, which matters on sites that deliberately widen access through custom roles.

## The public surface

By default, Onumia exposes nothing to anonymous visitors. The only public REST routes are the ones individual modules declare explicitly, and in the current catalog that means the Software Licensing endpoints. Module-declared public routes state their own authentication mode, a WordPress capability, an HMAC signature, a license key, or a download token, and the generic public-route registrar rate limits them per client IP per hour. The Software Licensing Stripe checkout, webhook, and status routes are registered separately and rely on Stripe signatures, checkout/session state, license keys, and short-lived download tokens instead of that generic limiter. A module only registers its declared public routes when its release and feature gates are enabled.

## Custom module code

Custom modules contain real PHP that runs on your site, so Onumia treats their creation and editing as a privileged operation. Only `manage_options` users can create, remix, or edit custom modules, every file write passes schema validation and non-executing PHP contract parsing before it reaches disk, and Git history records who changed what and when, with a one-click way back. Validation guarantees structural soundness, not intent: review custom module changes, especially agent-written ones, the way you review a plugin update before deploying it.

## Personal data in module tables

The modules that log traffic and logins are designed to record the minimum that still makes the records useful. IP addresses are hashed before storage using a per-install random secret combined with your WordPress auth salt, so entries can be correlated on your site but the stored values are not reusable identifiers elsewhere. Site policy can tighten this to full redaction, or relax it to raw storage, through a filter. URI-like values pass through a redaction filter before storage.

Module records participate in WordPress privacy tooling automatically: Onumia registers a personal data exporter that gathers rows referencing a requested user across all active modules' tables. Row caps and retention windows bound how long records live, and administrators can purge any table early from the dashboard.

Optional SQLite module data files live under `wp-content/uploads/onumia/data/`. Onumia writes a deny-all `.htaccess` file and an empty `index.php` into that directory so SQLite `.db` files are not directly browsable on Apache-style hosts. On nginx, add an equivalent deny rule for that path in your server configuration. Default module data lives in MySQL tables and is protected by WordPress database access controls.

## Secrets and AI keys

Module secrets such as Stripe credentials are stored in WordPress options or supplied as PHP constants, never in the theme settings file, so committing your theme never commits credentials. The dashboard reports whether a secret is present without displaying it.

AI provider keys follow a different path because the browser needs them: keys placed in the plugin directory's `.env` file are delivered to `manage_options` users in the dashboard, where provider calls are made directly from the administrator's browser. App surfaces that use a lower capability do not receive these raw keys. The practical rule is that anyone you make an administrator can use, and read, the configured AI keys. Chat conversations, including the file edits an agent made, are stored in your database; prompts and file contents are sent to the AI provider you select, under that provider's data terms.

## Settings stay reviewable

Module configuration is a plain JSON file in the active theme, written atomically and validated against typed contracts on every save. That makes the entire Onumia configuration of a site diffable and auditable with ordinary tools, and it keeps configuration out of reach of SQL-level tampering with options. The file inherits your theme directory's filesystem permissions, so the same people who can deploy theme code can read and change Onumia configuration on disk, a sensible match, since both amount to changing site behavior.

## Uninstall cleanup

Uninstalling Onumia from the Plugins screen drops every `onumia_`-prefixed database table, including chat history, and deletes the module data directory under uploads. Theme-stored configuration and custom modules are intentionally left in place, since they are part of your theme; remove the theme's `onumia.settings.json` and `onumia/` folder yourself for a complete teardown.
