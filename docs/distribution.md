---
title: "Distribution"
meta_title: "Onumia Distribution"
meta_description: "How Onumia is packaged, signed, installed, and updated through its public GitHub release channel."
path: "distribution"
order: 120
section: "Reference"
---

# Distribution

Onumia ships as one public WordPress plugin package with the canonical basename
`onumia/onumia.php`.

The package command reads `dev/package-manifest.json` and writes the canonical
tree below `dist/plugin/onumia`. Release assets are named
`onumia-vX.Y.Z.zip`. There is no edition switch or alternate package tree.

## Package inventory

The archive contains production PHP, the compiled `assets/app` frontend,
narrowly scoped runtime libraries, public documentation, security policy,
license, WordPress readme, and embedded package metadata. It excludes source
dependency trees, development manifests, locks, tests, fixtures, local runtime
state, reports, and caches.

UI Lab is included because it is a diagnostic runtime surface, but remains
hidden unless an authorized request explicitly opts in.

## Updates

The normal WordPress updater reads releases from `inline0/onumia`. It binds the
candidate to the exact version and archive selected by WordPress, verifies the
release's detached Ed25519 signature over `SHA256SUMS`, then verifies the
archive checksum before staging it.

Missing or ambiguous assets, invalid signatures, bad checksums, wrong versions,
and malformed package identities fail closed and leave the installed plugin
untouched. The canonical public repository needs no GitHub token.

## Data across replacement

Replacing or deactivating the plugin preserves pages, user workspace state,
module settings, and module-owned data. Uninstall follows the explicit cleanup
contract documented in [Module Data](module-data.md). No package installation
performs a destructive migration of legacy documents or module tables.
