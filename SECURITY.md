# Security Policy

## Reporting A Vulnerability

Report suspected vulnerabilities through [GitHub Private Vulnerability
Reporting](https://github.com/inline0/onumia/security/advisories/new). Do not
open a public issue, pull request, or discussion before the report has been
assessed.

Include the Onumia version, WordPress and PHP versions, relevant deployment
details, a minimal reproduction, and the observed impact. Do not include
production credentials, customer records, or destructive proof-of-concept
data.

## Supported Versions

Security fixes target the latest released version. Reproduce against the newest
available release when practical, while retaining details from the version
where the issue was first observed.

## Runtime Boundary

Onumia is an administrator-facing WordPress plugin. Its dashboard and private
REST API require `manage_options` unless a custom module declares a stricter
capability. A module can register a public route only through an explicit route
contract with its own authentication and rate limits.

Custom module PHP runs with the same trust as the plugin. Review a module before
registering it. Its settings, actions, data sources, tables, jobs, migrations,
and routes can read or change WordPress state.

UI Lab is diagnostic code, not an authorization bypass. The exact
`?onumia-dev=1` flag can expose it only for the current request and only to an
authenticated user with `manage_options`. Its routes, actions, and data remain
capability-checked server-side, and the opt-in is never persisted.

Operational modules may retain site data. Configure retention appropriately
and review [Security And Privacy](docs/security-and-privacy.md) before enabling
a module that handles sensitive records.

## Signed GitHub Updates

Onumia receives releases from `https://github.com/inline0/onumia/`. The updater
accepts versioned `onumia-vX.Y.Z.zip` assets and binds installation to the exact
release selected by WordPress.

Before the normal updater stages a package, it requires unique `SHA256SUMS`,
`SHA256SUMS.sig`, and matching package assets from that release. It verifies the
Ed25519 signature against the public key bundled with Onumia, then verifies the
package checksum. Missing assets, invalid signatures, substituted packages, and
checksum mismatches stop the update without replacing the installed plugin.

The canonical repository requires no token. `ONUMIA_GITHUB_UPDATER_TOKEN` is
optional for a custom authenticated mirror or additional GitHub API rate-limit
headroom. It is a download credential, not a signing key, and is not stored in
WordPress.

## Installation Limits

The update verifier protects the normal WordPress update path registered by
Onumia. Manual uploads, WP-CLI installs, host deployment tools, and direct
filesystem replacement can bypass that hook. Verify the signed release
manifest before using a manual installation path.

A valid signature proves that the release identity signed the manifest and
that the package matches it. It does not prove that the package contains no
vulnerabilities. Keep WordPress, PHP, Onumia, and registered custom modules
current.
