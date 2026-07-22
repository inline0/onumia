---
title: "Getting Started"
meta_title: "Onumia Getting Started"
meta_description: "Install Onumia, open the fullscreen page workspace, and create nested documents."
path: "getting-started"
order: 20
section: "Getting Started"
---

# Getting Started

Onumia installs as one normal WordPress plugin and opens as a fullscreen admin
workspace.

## Requirements

| Requirement | Why it matters |
| --- | --- |
| PHP 8.2 or newer | Minimum supported runtime. |
| WordPress with REST available | Pages and module screens use the REST API. |
| An administrator account | The workspace requires `manage_options`. |
| A writable uploads directory | Required by module tables that opt into SQLite. |
| WP-Cron | Required when custom modules schedule work or use retention cleanup. |

## Install and activate

Download `onumia-vX.Y.Z.zip` from the public Onumia GitHub releases. Install it
through **Plugins -> Add New -> Upload Plugin**, then activate Onumia. Use a
release package in production; it contains the compiled app and narrowly scoped
PHP runtime dependencies.

After installation or upgrade, `wp onumia doctor --format=json` verifies the
asset manifest, REST routes, storage, and scheduled work.

## Open the workspace

Select Onumia in WordPress admin. The left sidebar contains the page tree.
Create a page, give it an optional emoji, and use **Add subpage** or drag and
drop to build the hierarchy.

The main view is a block document. Type `/` to insert supported content. Page
title and document content save independently with optimistic feedback.

## Optional custom modules

A site can register user-owned module roots through
`onumia/modules/roots`. Registered modules appear in the sidebar and use the
same declarative renderer. Review custom module PHP as trusted application code
before registering it.

UI Lab is diagnostic only. An authenticated administrator may add
`?onumia-dev=1` to the Onumia admin URL to expose it for that request. Removing
the query flag hides it again.

Pages are WordPress-backed content. Workspace preferences are per-user. Module
settings use the configured settings repository, while operational records live
in module-owned MySQL tables or optional SQLite files.
