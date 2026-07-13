---
title: "Operations And Troubleshooting"
meta_title: "Onumia Operations And Troubleshooting"
meta_description: "Operate Onumia confidently and diagnose common failures: missing menu, saves, module storage, cron cleanup, theme switches, chat, and Pro apps."
path: "operations-and-troubleshooting"
order: 650
section: "Reference"
---

# Operations And Troubleshooting

This page covers how to confirm Onumia is healthy after setup and how to diagnose the failures you are most likely to meet in practice. Each section describes the symptom, why it happens, and how to resolve it.

## Sanity check after setup

A short pass through one module proves every layer at once. Confirm the Onumia menu item appears for an administrator and the archive loads, which proves the admin page and REST surface are reachable. Open a module, flip one section on, and save; a successful save proves contract validation works and the theme settings file is writable. Trigger the behavior and confirm it applies, which proves module boot. Finally, if you use modules that keep records, confirm new rows appear on the module's data screens, which proves module storage is working.

For command-line checks, run `wp onumia doctor --format=json`. JSON is the default output and is intended for CI, deploy scripts, and support bundles; `--format=text` prints a shorter human-readable report. The doctor checks the active plugin runtime, Pro availability, writable theme and uploads paths, selected storage driver, module table registry and schema versions, cron schedules, public licensing routes, Stripe and GitHub readiness, updater readiness, and admin asset manifests. Values that could contain secrets or personally identifiable data are redacted before output.

Treat a `critical` doctor result as a deployment blocker. `warning` results are usually incomplete configuration, for example Stripe enabled without webhook credentials or updater releases configured without a package source. `ok` results mean Onumia can see the required runtime surface; they do not prove that an external payment provider, mail server, or customer site is reachable.

## The Onumia menu item is missing

The dashboard only registers for users with `manage_options`, so the first check is whether the logged-in user is an administrator. If an administrator still cannot see it, check whether site code moves the menu with the `onumia/admin/menu_location` filter, in which case the entry lives under Tools or Settings rather than at the top level. The plugin also requires its bundled dependencies to be present; an incomplete upload that lost the plugin's `vendor` directory loads silently without registering anything, so re-install the plugin package if activation appears to do nothing at all.

## A REST route returns 404

If dashboard requests under `/wp-json/onumia/v1/` fail with 404, confirm the plugin is active, then flush rewrite rules by opening Settings, Permalinks and saving. If routes still fail, check whether a security plugin or firewall is blocking REST API traffic, which is a common and easily missed cause. A 403 instead of a 404 usually means the request lost its authentication cookie or nonce; reloading the dashboard page issues a fresh one.

## Settings will not save

Saves fail for one of two reasons. A validation error means a value does not satisfy the module's contract, and the dashboard reports which setting was rejected; correct the value and save again. A write error means Onumia could not write `onumia.settings.json` into the active theme's directory, which almost always comes down to filesystem permissions: the web server user must be able to create and replace files in the theme's stylesheet directory. If the settings file path has been moved with the `onumia_settings_file` filter, check the configured location instead.

## A module's behavior is not applying

Remember the activation model: a module runs only when it has active saved settings. A module whose settings are saved but everything is switched off or empty is intentionally inert. Open the module and confirm the relevant section's Enable switch is actually on and saved. If settings look right but behavior is still missing, confirm you are looking at the same site whose theme holds the settings, settings live per theme, so a staging site with a different active theme has different Onumia configuration, and check whether the module depends on a WordPress feature that is unavailable, such as user registration for User Approval.

## Module storage uses MySQL by default

Activation does not create module data tables. Default module tables are created in MySQL on first write, one table at a time. Reads from a module table that has never been written return empty results, so a fresh install can show no `onumia_*` module tables until modules start recording data.

Tables that explicitly request SQLite use `wp-content/uploads/onumia/data/<module>/<table>.db` when `pdo_sqlite` or `SQLite3` is available. If SQLite is unavailable, Onumia silently falls back to MySQL and pins that choice in `wp-content/uploads/onumia/data/storage.json` so the table does not later flip storage engines.

The `onumia/data/storage_driver` filter or `ONUMIA_STORAGE_DRIVER` constant can force `auto`, `mysql`, or `sqlite` for controlled debugging. The old `file` value is not supported.

## Records are not being cleaned up

Retention windows are enforced by the `onumia_tables_cleanup` event, scheduled twice daily through WP-Cron. On sites with WP-Cron disabled and no system cron triggering `wp-cron.php`, cleanup never runs and bounded tables only enforce their row caps. Restore cron execution, or purge tables manually from their module screens. Row caps themselves are enforced on insert and do not depend on cron.

## A packaged install behaves differently from development

The production ZIP is intentionally smaller than the source tree. It must contain the WordPress entry file, compiled admin asset manifests, scoped vendor libraries, runtime modules, Pro runtime files, docs, and README. It must not contain local environment files, test fixtures, PRDs, development-only modules, package scripts, source maps, or dependency source trees such as `node_modules`. If the packaged install cannot activate, cannot register the Onumia admin menu, or cannot expose REST routes, rebuild the plugin package and inspect it before uploading again.

After replacing a development symlink with a ZIP install, clear opcode caches and confirm the active plugin path in WordPress points to the installed plugin directory. Running `wp onumia doctor --format=json` from the target site catches the common packaging failures: missing assets, missing Pro runtime files, stale table versions, and unavailable public routes.

## Customer updates fail

For Onumia Pro itself, confirm `ONUMIA_LICENSE_KEY` is present, the license is
eligible for `onumia-pro`, the selected `ONUMIA_LICENSE_CHANNEL` has a newer
release, and the site can reach `https://onumia.app/`. Onumia Pro fixes the
product slug and service default; it does not need a product-slug setting.

For other software distributed through the Software Licensing module, confirm
the customer plugin embeds the generated updater, identifies the intended
product, and uses that vendor's service URL, key, installed version, and
channel.

If the update appears but the install fails, request a fresh check and inspect
the package expiry, one-use state, checksum, plugin basename, product, and
version. Package URLs should point at the public licensing download endpoint
with a short-lived token; they must not expose GitHub asset URLs, GitHub tokens,
Stripe credentials, license keys, or customer email addresses. A rejected
package leaves the currently installed version in place. Re-publish the release
with the correct checksum and identity rather than bypassing verification.

## Switching or updating themes

Because settings and custom modules live in the active theme, theme operations are Onumia operations. Switching themes switches to that theme's Onumia configuration, which may be empty; the previous theme's settings and custom modules remain intact in its directory and come back if you switch back. Updating a theme by replacing its directory deletes `onumia.settings.json` and the `onumia/` folder unless they are preserved.

The safe patterns are ordinary ones: run a child theme so parent updates never touch your Onumia state, keep the theme, including its Onumia files, in version control, or relocate storage with the path filters described in the configuration reference. Before replacing a theme directory, copy `onumia.settings.json` and the `onumia/` folder out and restore them afterward.

## Multisite considerations

On multisite, sites that share the same active theme directory share one settings file and one set of custom modules. That is workable when the network intends sites to behave identically, and surprising otherwise. Module data tables and chat history remain per-site, since they live in per-site database tables and uploads. If sites on a shared theme need different Onumia configuration, give each site its own settings path with the `onumia_settings_file` filter.

## Chat shows no models or does not respond

The model selector only offers providers whose keys are present in the plugin directory's `.env` file, so an empty selector means no keys were found; check the file's location and variable names against the configuration reference. If models are listed but requests fail, remember that calls go directly from the browser to the provider: an invalid or unfunded key, a network proxy, or an aggressive browser extension blocking the provider's domain will all fail in the browser even though WordPress is healthy. Errors and stopped generations are recorded in the chat history itself rather than as passing notifications.

## The chat is locked

A chat accepts one generation at a time. While someone's response is streaming, others see who is working in the chat and cannot submit; this is expected coordination for shared chats, not a fault. Locks refresh while streaming and expire on their own within a couple of minutes if a browser disappears mid-generation, so an abandoned lock clears itself shortly. If you only ever see read-only behavior in a shared chat, your membership permission is `read`; ask the chat owner for `write`.

## Agent edits keep getting rejected

Rejected agent writes are validation doing its job: the checker refused files that would break the module, returned diagnostics, and rolled the workspace back to the last good state. The agent usually repairs its own mistake on the next step. If a conversation gets stuck in a rejection loop, the request is often too large or ambiguous, ask for a smaller, more specific change, or make the structural part of the edit manually and let the agent fill in details. Nothing from a rejected edit ever reaches disk, so a stuck session costs nothing to abandon.

## Pro apps do not appear

The Apps section exists only when the Pro bundle is installed, so its absence on a free install is expected. With Pro active, apps are discovered from the active theme's `onumia/apps` directory; a brand-new site has none until you create the first one with New. If a specific app is missing for a specific user, check the app's capability and access policy, apps hide themselves from users who fail either. An app's WordPress surfaces, its own menu page or a replaced admin screen, follow the same capability rules, so a replacement dashboard appearing for administrators but not editors is the configuration working as declared.

## Undoing an admin screen replacement

If an app replaces a WordPress admin screen and you want the original back, edit the app from the Apps section and remove or adjust the replacement surface declared in its PHP file, the change takes effect on save. In an emergency where the dashboard itself is unreachable, removing the app's folder from the theme's `onumia/apps` directory unregisters all of its surfaces, and deactivating the Onumia plugin restores stock WordPress admin behavior immediately without deleting any data.

## What uninstall does and does not remove

Deactivation changes behavior but removes nothing. Uninstalling from the Plugins screen drops all `onumia_`-prefixed database tables, including chat history, and deletes the module data directory under uploads. It does not touch the theme: `onumia.settings.json` and any custom modules or apps remain in the theme directory, ready to work again if Onumia returns. Remove those theme files manually if you are decommissioning Onumia for good.
