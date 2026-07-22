---
title: "Security And Privacy"
meta_title: "Onumia Security And Privacy"
meta_description: "How Onumia protects its workspace, module APIs, diagnostic surface, secrets, and operational data."
path: "security-and-privacy"
order: 100
section: "Reference"
---

# Security And Privacy

Onumia is an administrative workspace. Its default boundary is WordPress
cookie authentication, a valid REST nonce, and the `manage_options`
capability.

## Administrative access

The fullscreen workspace and administrative routes under
`/wp-json/onumia/v1/` require `manage_options`. User-owned module contracts can
require a stricter capability for a module, route, action, or data source.
React never substitutes for these server-side checks.

UI Lab is hidden unless the current request has the exact `?onumia-dev=1`
flag and the user has `manage_options`. The server repeats that check for its
catalog entry, routes, actions, settings, and data. The flag is not saved and
is never authorization by itself.

## Public routes

Onumia exposes no anonymous workspace route. A user-owned module may
deliberately declare a public REST route, but its PHP contract must define the
authentication, authorization, validation, and rate-limiting boundary.

## Module data and privacy

Module helpers hash IP addresses by default with a per-install secret and the
WordPress salt. Sites can choose full redaction or raw storage through the
documented filter. URI-like values pass through a redaction filter before
storage.

Module tables can declare row caps and retention windows, and user-linked
records participate in WordPress personal-data export. Optional SQLite files
live under `wp-content/uploads/onumia/data/`; hosts that do not honor Apache
deny files must apply an equivalent web-server rule.

## Secrets

Secrets never belong in the theme settings file, module metadata, browser
state, or source control. Keep them in protected WordPress storage, constants,
environment configuration, or an owner-managed secret channel. Diagnostic
output may report presence or a short fingerprint but must never reveal a
secret value.

## Settings and removal

Module settings are validated against typed PHP contracts and written
atomically. Deactivation preserves pages, settings, and operational records.
Uninstall removes Onumia-owned tables, options, scheduled work, user UI state,
and module data files. Theme-owned `onumia.settings.json` remains until an
operator deliberately removes it.
