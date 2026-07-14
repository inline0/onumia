<?php

/**
 * Documented Onumia public filters.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\PublicApi;

use Onumia\Core\Plugin;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;

/**
 * Routes supported Onumia filter extension points through documented methods.
 *
 * Use this class when extending Onumia from plugin or theme code and you need
 * a stable WordPress filter name. Production code calls these wrappers so the
 * hook name, parameter contract, and documentation stay in one place.
 *
 * The wrappers do not perform runtime work beyond invoking WordPress filters
 * and normalizing the documented return type when required by the contract.
 */
final class Filters {
	/**
	 * Filters module root directories used during module discovery.
	 *
	 * Use this when an integration needs Onumia to discover bundled or custom
	 * modules from an additional trusted directory. Returned paths should be
	 * absolute directories that contain module folders with `meta.json` files.
	 *
	 * Non-string values are ignored by the caller after this filter runs. The
	 * filter should not perform module loading itself.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/modules/roots
	 * @category Modules
	 * @order 10
	 *
	 * @param string[] $roots Module root directories.
	 * @return string[] Filtered module root directories.
	 */
	public static function module_roots( array $roots ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/modules/roots', $roots );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $roots;
	}

	/**
	 * Filters component root directories used during component discovery.
	 *
	 * Use this when an integration provides additional reusable Onumia
	 * components outside the plugin directory. Returned paths should be absolute
	 * directories that follow the Onumia component discovery convention.
	 *
	 * Non-string values are ignored by the caller after this filter runs. The
	 * filter should not register React components or enqueue assets directly.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/components/roots
	 * @category Components
	 * @order 10
	 *
	 * @param string[] $roots Component root directories.
	 * @return string[] Filtered component root directories.
	 */
	public static function component_roots( array $roots ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/components/roots', $roots );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $roots;
	}

	/**
	 * Filters where the Onumia admin menu item is registered.
	 *
	 * Use this to move the Onumia admin page between a top-level menu item,
	 * Tools, or Settings. The returned array must contain a supported
	 * `placement` value and an integer `position`.
	 *
	 * Invalid values are rejected by the caller and the default menu location is
	 * kept. This filter should not call WordPress menu registration functions.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/admin/menu_location
	 * @category Admin
	 * @order 10
	 *
	 * @param array{placement:string,position:int} $location Default admin menu location.
	 * @return array{placement:string,position:int}|array<string,mixed> Filtered admin menu location.
	 */
	public static function admin_menu_location( array $location ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/admin/menu_location', $location );

		if ( ! is_array( $filtered ) ) {
			return $location;
		}

		$normalized = array();
		foreach ( $filtered as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return array() !== $normalized ? $normalized : $location;
	}

	/**
	 * Filters the React entrypoint used for the Onumia admin app.
	 *
	 * Use this when a distribution needs to swap the free dashboard entrypoint
	 * for another built entry, such as the Pro dashboard entry. The filtered
	 * value should be a non-empty path relative to the Vite app source root.
	 *
	 * Empty or non-string values are ignored by the caller. The filter should
	 * not enqueue scripts directly.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/admin/app_entrypoint
	 * @category Admin
	 * @order 20
	 *
	 * @param string $entrypoint Default app entrypoint.
	 * @return string Filtered app entrypoint.
	 */
	public static function app_entrypoint( string $entrypoint ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/admin/app_entrypoint', $entrypoint );

		return is_string( $filtered ) ? $filtered : $entrypoint;
	}

	/**
	 * Filters the built asset directory used for the Onumia admin app.
	 *
	 * Use this when a distribution needs Onumia to load a different built asset
	 * directory, such as a Pro build. The filtered value should be a non-empty
	 * path relative to the plugin root.
	 *
	 * Empty or non-string values are ignored by the caller. The filter should
	 * not read the manifest or enqueue scripts directly.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/admin/app_asset_directory
	 * @category Admin
	 * @order 30
	 *
	 * @param string $directory Default asset directory.
	 * @return string Filtered asset directory.
	 */
	public static function app_asset_directory( string $directory ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/admin/app_asset_directory', $directory );

		return is_string( $filtered ) ? $filtered : $directory;
	}

	/**
	 * Filters Pro app root directories used during app discovery.
	 *
	 * Use this when a Pro integration stores custom app definitions outside the
	 * active theme convention. Returned paths should be absolute directories
	 * containing Onumia app folders.
	 *
	 * Non-string values are ignored by the caller after this filter runs. The
	 * filter should not load app definitions itself.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/pro/app_roots
	 * @category Pro
	 * @order 10
	 *
	 * @param string[] $roots Default Pro app root directories.
	 * @param Plugin   $plugin Active Onumia plugin runtime.
	 * @return string[] Filtered Pro app root directories.
	 */
	public static function pro_app_roots( array $roots, Plugin $plugin ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/pro/app_roots', $roots, $plugin );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $roots;
	}

	/**
	 * Filters the base directory for module data files.
	 *
	 * Use this when a host needs Onumia SQLite files to live outside the
	 * default uploads directory. The returned value should be an absolute
	 * writable directory path.
	 *
	 * Empty values are ignored by the caller. The filter should not create table
	 * files or move existing databases during path resolution.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/data/sqlite_data_directory
	 * @category Data
	 * @order 10
	 *
	 * @param string $directory Default module data directory.
	 * @return string Filtered module data directory.
	 */
	public static function sqlite_data_directory( string $directory ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/data/sqlite_data_directory', $directory );

		return is_string( $filtered ) ? $filtered : $directory;
	}

	/**
	 * Filters whether a SQLite PHP interface is available.
	 *
	 * Use this when an environment needs to force-enable or force-disable
	 * SQLite-backed automatic storage during controlled bootstrap or testing.
	 * The filtered value is applied to both PDO SQLite and the SQLite3 class.
	 *
	 * This filter only changes Onumia availability checks. It does not install
	 * PHP extensions or change the underlying database driver.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/data/sqlite_available
	 * @category Data
	 * @order 20
	 *
	 * @param bool $available Whether the checked SQLite interface is currently available.
	 * @return bool Filtered SQLite availability.
	 */
	public static function sqlite_available( bool $available ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/data/sqlite_available', $available );

		return is_bool( $filtered ) ? $filtered : $available;
	}

	/**
	 * Filters the automatic module storage driver.
	 *
	 * Use this only for controlled debugging, tests, or hosts with known-broken
	 * SQLite interfaces. Supported values are an empty string or `auto` for
	 * normal resolution, `mysql`, and `sqlite`.
	 *
	 * SQLite falls back to MySQL silently when no SQLite PHP interface is
	 * available.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/data/storage_driver
	 * @category Data
	 * @order 25
	 *
	 * @param string $driver Constant-provided driver override, or empty.
	 * @return string Filtered driver override.
	 */
	public static function storage_driver( string $driver ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/data/storage_driver', $driver );

		return is_string( $filtered ) ? $filtered : $driver;
	}

	/**
	 * Filters URI-like values before module table storage.
	 *
	 * Use this when an integration needs to redact, normalize, or preserve URI
	 * values before Onumia writes operational records. The filter receives the
	 * column name and table/module definitions for context.
	 *
	 * The returned value must remain a string suitable for the original table
	 * column. The filter should not write to the table directly.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/data/table_uri_redaction
	 * @category Data
	 * @order 30
	 *
	 * @param string                $value  URI-like value being stored.
	 * @param string                $column Table column name.
	 * @param ModuleTableDefinition $table  Module table definition.
	 * @param ModuleDefinition      $module Module definition.
	 * @return string Filtered URI-like value.
	 */
	public static function table_uri_redaction( string $value, string $column, ModuleTableDefinition $table, ModuleDefinition $module ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/data/table_uri_redaction', $value, $column, $table, $module );

		return is_string( $filtered ) ? $filtered : $value;
	}

	/**
	 * Filters how module helpers store IP addresses.
	 *
	 * Use this when a host needs a different IP retention policy for module
	 * audit rows. Supported return values are `hash`, `raw`, and `redact`.
	 *
	 * Unknown values are treated like `hash` by the caller. The filter should
	 * not write IP addresses or module settings itself.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/data/table_ip_handling
	 * @category Data
	 * @order 40
	 *
	 * @param string           $handling Default handling strategy.
	 * @param string           $ip       IP address being processed.
	 * @param ModuleDefinition $module   Module definition.
	 * @return string Filtered IP handling strategy.
	 */
	public static function table_ip_handling( string $handling, string $ip, ModuleDefinition $module ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		$filtered = \apply_filters( 'onumia/data/table_ip_handling', $handling, $ip, $module );

		return is_string( $filtered ) ? $filtered : $handling;
	}

	/**
	 * Filters the GitHub repository used for Onumia Free updates.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/github_updater/repository_url
	 * @category Updates
	 * @order 10
	 *
	 * @param string $repository_url Default release repository URL.
	 * @return mixed Filtered repository URL.
	 */
	public static function github_updater_repository_url( string $repository_url ): mixed {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		return \apply_filters( 'onumia/github_updater/repository_url', $repository_url );
	}

	/**
	 * Filters the Onumia Free release asset filename pattern.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/github_updater/asset_regex
	 * @category Updates
	 * @order 20
	 *
	 * @param string $asset_regex Default release asset pattern.
	 * @return mixed Filtered release asset pattern.
	 */
	public static function github_updater_asset_regex( string $asset_regex ): mixed {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		return \apply_filters( 'onumia/github_updater/asset_regex', $asset_regex );
	}

	/**
	 * Filters the optional GitHub token used for custom authenticated mirrors or higher API limits.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/github_updater/token
	 * @category Updates
	 * @order 30
	 *
	 * @param string $token Constant-provided token, or empty for public releases.
	 * @return mixed Filtered GitHub token.
	 */
	public static function github_updater_token( string $token ): mixed {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		return \apply_filters( 'onumia/github_updater/token', $token );
	}

	/**
	 * Filters whether the Onumia Free GitHub updater is disabled.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/github_updater/disabled
	 * @category Updates
	 * @order 40
	 *
	 * @param bool $disabled Whether the updater is disabled.
	 * @return mixed Filtered disabled state.
	 */
	public static function github_updater_disabled( bool $disabled ): mixed {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		return \apply_filters( 'onumia/github_updater/disabled', $disabled );
	}

	/**
	 * Filters the trusted Ed25519 key used for Onumia Free releases.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/github_updater/public_key
	 * @category Updates
	 * @order 50
	 *
	 * @param string $public_key Bundled base64-encoded public key.
	 * @return mixed Filtered public key.
	 */
	public static function github_updater_public_key( string $public_key ): mixed {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		return \apply_filters( 'onumia/github_updater/public_key', $public_key );
	}
}
