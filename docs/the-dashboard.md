---
title: "The Workspace"
meta_title: "Onumia Workspace"
meta_description: "Navigate Onumia's fullscreen page workspace, edit nested documents, and open registered custom modules."
path: "the-dashboard"
order: 40
section: "Getting Started"
---

# The Workspace

Onumia is one fullscreen WordPress admin app. The left sidebar contains the
page tree and any registered custom modules. The main area shows the selected
page or module route. Access requires `manage_options`.

## Pages and nesting

Any page can contain subpages, with no application-level depth limit. Creating,
renaming, moving, and deleting pages updates the sidebar optimistically while
the REST mutation settles. Dragging a page moves its entire subtree.

Page icons are optional emoji. When none is set, Onumia uses the default page
icon.

## Document editor

A page is a continuous block document. It supports headings, paragraphs, lists,
tasks, tables, code, media, and the other current slash-menu blocks. The block
rail handles insertion, movement, duplication, and deletion without a permanent
toolbar around the document.

The title and document save independently. Content is stored as that page's
TipTap JSON document. Unsupported legacy nodes do not restore removed product
features; they are handled safely so unrelated content remains loadable.

## Custom modules

Registered modules use the generic declarative renderer. Their controls remain
interactive while data sources, actions, settings, tables, jobs, hooks, and
routes execute through PHP contracts. Module authors own their structure and
behavior.

## Per-user state

Theme preference, sidebar state, and other small workspace preferences are
stored per WordPress user. They do not alter shared page content or module
settings.
