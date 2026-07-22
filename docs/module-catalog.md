---
title: "Module Catalog"
meta_title: "Onumia Module Catalog"
meta_description: "The bundled UI Lab diagnostic and the boundary for user-owned Onumia modules."
path: "module-catalog"
order: 60
section: "Modules"
---

# Module Catalog

Onumia does not bundle an operational product-module catalog. Sites add their
own modules through the generic extension contract described in
[Modules](modules.md).

## UI Lab

UI Lab is the only bundled module. It exists to exercise and debug the
declarative renderer's controls, layouts, tables, entries, tabs, drawers,
settings, data sources, and actions.

It is intentionally absent from normal navigation and ordinary routes. To
expose it for one request:

1. sign in as a WordPress administrator with `manage_options`;
2. open the Onumia admin URL with the exact `?onumia-dev=1` query flag;
3. remove the flag or navigate normally to return to the hidden state.

The query flag is not authorization. UI Lab routes, data, and actions repeat
the server-side capability check. The opt-in is not saved in user settings,
options, cookies, or module configuration.

Only `modules/development/ui-lab` is registered for this diagnostic request.
The runtime does not recursively scan a general development or former bundled
module tree.

## User-owned catalog

Each registered root can contain one or more module directories. The REST
catalog includes only modules that pass metadata, structure, PHP-contract, and
capability validation. Invalid modules are reported through developer checks
instead of being partially exposed in the workspace.
