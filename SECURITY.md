# Security Policy

## Reporting A Vulnerability

Report suspected vulnerabilities through [GitHub Private Vulnerability
Reporting](https://github.com/inline0/onumia/security/advisories/new). Do not
open a public issue, pull request, or discussion before the report has been
assessed.

Include the Onumia version, WordPress and PHP versions, relevant deployment
details, a minimal reproduction, and the observed impact. Do not include
production credentials, customer records, or destructive proof of concept
data.

## Supported Versions

Security fixes target the latest released version. Reproduce against the newest
available release when practical, while retaining the details of the version
where the issue was first observed.

## Runtime Boundary

Onumia is an administrator-facing WordPress plugin. Its dashboard and private
REST API require `manage_options` unless a module declares a stricter
capability. Modules can register public routes only through an explicit route
contract with their own authentication and rate limits.

Custom modules contain PHP that runs with the same trust as plugin code.
Validation checks structure and declared contracts; it cannot determine whether
custom code is safe or appropriate. Review custom and agent-written modules
before enabling them.

Operational modules can retain logs, audit records, email metadata, login
activity, and other site data. Configure retention for the site and review
[Security And Privacy](docs/security-and-privacy.md) before enabling a module
that handles sensitive records.

## Free Updates

Onumia Free receives releases from
`https://github.com/inline0/onumia/`. The updater accepts versioned
`onumia-vX.Y.Z.zip` assets and binds installation to the exact release selected
by WordPress.

Before the normal updater stages a package, it requires unique `SHA256SUMS`,
`SHA256SUMS.sig`, and matching package assets from that release. It verifies the
Ed25519 signature against the public key bundled with Onumia, then verifies the
package checksum. Missing assets, invalid signatures, substituted packages, and
checksum mismatches stop the update without replacing the installed plugin.

Private-repository access can use `ONUMIA_GITHUB_UPDATER_TOKEN`. The token is a
download credential, not a signing key, and is not stored in WordPress. Public
releases require no token.

## Pro Licensed Updates

Onumia Pro disables the Free GitHub channel and checks `https://onumia.app/`
for product `onumia-pro`. A check sends the license key, product, channel, site
URL, instance ID, and installed version over HTTPS. It does not send a GitHub
credential. A key supplied as `ONUMIA_LICENSE_KEY` remains external to
WordPress; a key activated through the updater is encrypted before storage.
Public updater status never includes the raw key.

The service returns a short-lived package URL only for an eligible license and
matching product. Onumia verifies expiry, one-use state, SHA-256 checksum,
plugin basename, package target, and package version before WordPress receives
the archive. Revoked or expired licenses, activation limits, wrong products,
replayed URLs, service outages, and invalid packages fail closed and leave the
current version installed.

## Installation Limits

The update verifiers protect the normal WordPress update paths registered by
Onumia. Manual uploads, WP-CLI installs, host deployment tools, and direct
filesystem replacement can bypass those hooks. Verify the signed release
manifest before using a manual installation path.

A valid signature proves that the release identity signed the manifest and
that the package matches it. It does not prove that the package contains no
vulnerabilities. Keep WordPress, PHP, Onumia, and the enabled modules current.
