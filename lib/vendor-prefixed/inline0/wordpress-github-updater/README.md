# WordPress GitHub Updater

Shared signed GitHub Releases updater used by Inline0 WordPress products.

The library binds an update to the exact candidate selected by WordPress,
loads that exact GitHub release tag, verifies its Ed25519-signed checksum
manifest, and stages only the matching package asset. Product adapters provide
the repository, plugin identity, asset pattern, token, trusted key, icons, and
scoped Plugin Update Checker runtime.

Each plugin builds this source through PHP-Scoper. Released plugins therefore
carry an isolated updater with no monorepo dependency and no shared runtime
class collision.
