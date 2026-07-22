<?php

/**
 * Production preflight checks for a Onumia install.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use Onumia\Core\Plugin;
use Onumia\Data\ModuleStorageResolver;
use Onumia\Data\SqlitePathResolver;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\Modules\ModuleTableName;

final class PreflightDoctor {
	private const STATUS_HEALTHY   = 'healthy';
	private const STATUS_WARNING   = 'warning';
	private const STATUS_CRITICAL = 'critical';

	/** @var list<array{name:string,status:string,message:string,data:array<string,mixed>}> */
	private array $checks = array();

	public function __construct(
		private readonly Plugin $plugin,
		private readonly ModuleTableName $table_names = new ModuleTableName(),
		private readonly ModuleStorageResolver $storage_resolver = new ModuleStorageResolver(),
		private readonly SqlitePathResolver $sqlite_paths = new SqlitePathResolver(),
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function report(): array {
		$this->checks = array();

		$this->check_plugin_runtime();
		$this->check_theme_paths();
		$this->check_storage_driver();
		$this->check_table_registry();
		$this->check_cron_events();
		$this->check_rest_routes();
		$this->check_asset_manifests();

		$summary = $this->summary();

		return array(
			'status'      => $summary['critical'] > 0 ? self::STATUS_CRITICAL : ( $summary['warning'] > 0 ? self::STATUS_WARNING : self::STATUS_HEALTHY ),
			'generatedAt' => $this->now(),
			'plugin'      => array(
				'version' => $this->plugin->version(),
				'file'    => $this->plugin_basename(),
			),
			'summary'     => $summary,
			'checks'      => $this->checks,
		);
	}

	private function check_plugin_runtime(): void {
		$this->add_check(
			'plugin.runtime',
			$this->plugin->is_booted() ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
			$this->plugin->is_booted() ? 'Onumia runtime is booted.' : 'Onumia runtime is not booted.',
			array(
				'version' => $this->plugin->version(),
				'modules' => count( $this->plugin->registry()->all() ),
			)
		);
	}

	private function check_theme_paths(): void {
		$stylesheet = $this->theme_directory( 'get_stylesheet_directory' );
		$template   = $this->theme_directory( 'get_template_directory' );
		$targets    = array(
			'themeSettings' => $stylesheet . DIRECTORY_SEPARATOR . 'onumia.settings.json',
		);
		$results    = array();
		$writable   = true;

		foreach ( $targets as $key => $path ) {
			$target_writable = $this->path_writable( $path );
			$results[ $key ] = array(
				'path'     => $this->relative_or_basename( $path ),
				'writable' => $target_writable,
			);
			$writable        = $writable && $target_writable;
		}

		$this->add_check(
			'paths.theme',
			$writable ? self::STATUS_HEALTHY : self::STATUS_WARNING,
			$writable ? 'The Onumia theme settings path is writable.' : 'The Onumia theme settings path is not writable.',
			array(
				'stylesheetTheme' => $this->relative_or_basename( $stylesheet ),
				'templateTheme'   => $this->relative_or_basename( $template ),
				'targets'         => $results,
			)
		);
	}

	private function check_storage_driver(): void {
		try {
			$resolution = $this->storage_resolver->resolve();
		} catch ( \Throwable $throwable ) {
			$this->add_check(
				'storage.driver',
				self::STATUS_CRITICAL,
				'Module data storage could not be resolved.',
				array( 'failure' => $throwable->getMessage() )
			);
			return;
		}

		$this->add_check(
			'storage.driver',
			self::STATUS_HEALTHY,
			$resolution->reason,
			array(
				'engine'        => $resolution->engine,
				'forced'        => $resolution->forced,
				'markerEngine'  => $resolution->marker_engine,
				'dataDirectory' => $this->relative_or_basename( $this->sqlite_paths->base_directory() ),
			)
		);
	}

	private function check_table_registry(): void {
		$expected = $this->expected_mysql_tables();
		if ( array() === $expected ) {
			$this->add_check( 'tables.registry', self::STATUS_HEALTHY, 'No MySQL module tables are declared.', array( 'expected' => array() ) );
			return;
		}

		$installed = $this->installed_schema_records();
		if ( null === $installed ) {
			$this->add_check(
				'tables.registry',
				self::STATUS_HEALTHY,
				'Module table schema registry has not been created yet.',
				array( 'expected' => $this->table_summary( $expected ) )
			);
			return;
		}

		$stale   = array();
		foreach ( $installed as $key => $record ) {
			$table = $expected[ $key ] ?? null;
			if ( null === $table ) {
				continue;
			}

			if ( (int) $record['version'] !== $table['version'] ) {
				$stale[] = $key;
			}
		}

		$status = array() === $stale ? self::STATUS_HEALTHY : self::STATUS_CRITICAL;
		$this->add_check(
			'tables.registry',
			$status,
			self::STATUS_HEALTHY === $status ? 'Installed module table schema records match the loaded module contract.' : 'Installed module table schema records are stale.',
			array(
				'expected'       => $this->table_summary( $expected ),
				'installedCount' => count( $installed ),
				'stale'          => $stale,
			)
		);
	}

	private function check_cron_events(): void {
		$expected = array( 'onumia_tables_cleanup' => 'Module table cleanup' );
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->jobs() as $job ) {
				if ( ! $job->enabled ) {
					continue;
				}
				$expected[ $this->job_hook( $module->name(), $job->name ) ] = $module->label() . ': ' . $job->name;
			}
		}

		$missing = array();
		foreach ( $expected as $hook => $label ) {
			if ( ! function_exists( 'wp_next_scheduled' ) || false === \wp_next_scheduled( $hook ) ) {
				$missing[] = array(
					'hook'  => $hook,
					'label' => $label,
				);
			}
		}

		$this->add_check(
			'cron.schedules',
			array() === $missing ? self::STATUS_HEALTHY : self::STATUS_WARNING,
			array() === $missing ? 'Expected Onumia cron events are scheduled.' : 'One or more expected Onumia cron events are not scheduled.',
			array(
				'expected' => array_values( $expected ),
				'missing'  => $missing,
			)
		);
	}

	private function check_rest_routes(): void {
		$registered = $this->registered_rest_routes();
		$expected   = $this->expected_public_routes();
		$missing    = array_values( array_diff( $expected, $registered ) );

		$this->add_check(
			'rest.publicRoutes',
			array() === $missing ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
			array() === $missing ? 'Expected public REST routes are registered.' : 'One or more expected public REST routes are missing.',
			array(
				'namespace'       => 'onumia/v1',
				'expectedRoutes'  => $expected,
				'missingRoutes'   => $missing,
				'registeredCount' => count( $registered ),
			)
		);
	}

	private function check_asset_manifests(): void {
		$manifests = array(
			'app' => $this->plugin->directory() . 'assets/app/manifest.json',
		);
		$results   = array();
		$missing   = false;

		foreach ( $manifests as $name => $manifest ) {
			$result = $this->asset_manifest_result( $manifest );
			if ( false === $result['present'] || false === $result['valid'] || array() !== $result['missingFiles'] ) {
				$missing = true;
			}
			$results[ $name ] = $result;
		}

		$this->add_check(
			'assets.manifests',
			$missing ? self::STATUS_CRITICAL : self::STATUS_HEALTHY,
			$missing ? 'One or more built asset manifests are missing or reference absent files.' : 'Built asset manifests exist and reference present files.',
			$results
		);
	}

	/**
	 * @return array<string,array{module:string,table:string,version:int}>
	 */
	private function expected_mysql_tables(): array {
		$tables = array();
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->tables() as $table ) {
				if ( 'sqlite' === $table->driver ) {
					continue;
				}
				$key            = $this->schema_key( $module->name(), $table );
				$tables[ $key ] = array(
					'module'  => $module->name(),
					'table'   => $table->name,
					'version' => $table->version,
				);
			}
		}

		return $tables;
	}

	/**
	 * @return array<string,array{version:int}>|null
	 */
	private function installed_schema_records(): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$query = $wpdb->prepare( 'SELECT module, object_type, object_name, version FROM %i WHERE object_type = %s', $this->table_names->schema_table(), 'table' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above when WordPress returns SQL.
		$rows = is_string( $query ) ? $wpdb->get_results( $query, \ARRAY_A ) : null;
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$module = is_string( $row['module'] ?? null ) ? $row['module'] : '';
			$name   = is_string( $row['object_name'] ?? null ) ? $row['object_name'] : '';
			if ( '' === $module || '' === $name || ! is_numeric( $row['version'] ?? null ) ) {
				continue;
			}
			$records[ $module . ':' . $name ] = array( 'version' => (int) $row['version'] );
		}

		return $records;
	}

	/**
	 * @param array<string,array{module:string,table:string,version:int}> $tables Tables.
	 * @return list<array{module:string,table:string,version:int}>
	 */
	private function table_summary( array $tables ): array {
		return array_values( $tables );
	}

	private function schema_key( string $module, ModuleTableDefinition $table ): string {
		return $module . ':' . $table->name;
	}

	/**
	 * @return list<string>
	 */
	private function expected_public_routes(): array {
		$routes = array();
		foreach ( $this->plugin->registry()->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}
			foreach ( $module->advanced()->public_routes() as $route ) {
				$routes[] = '/public/modules/' . $this->public_module_slug( $module ) . $route->path;
			}
		}

		sort( $routes );
		return array_values( array_unique( $routes ) );
	}

	/**
	 * @return list<string>
	 */
	private function registered_rest_routes(): array {
		// @codeCoverageIgnoreStart
		// Covered by WordPress at runtime; unit tests exercise the deterministic route-registry fallback below.
		if ( function_exists( 'rest_get_server' ) ) {
			$server = \rest_get_server();
			if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
				$routes = array_map( array( $this, 'normalize_rest_route' ), array_keys( $server->get_routes() ) );
				sort( $routes );
				return array_values( array_filter( $routes, 'is_string' ) );
			}
		}
		// @codeCoverageIgnoreEnd

		$routes = array();
		foreach ( $this->test_global_array( 'onumia_rest_routes' ) as $route ) {
			if ( ! is_array( $route ) ) {
				continue;
			}
			$name = $route['route'] ?? null;
			if ( is_string( $name ) ) {
				$routes[] = $this->normalize_rest_route( $name );
			}
		}

		sort( $routes );
		return array_values( array_unique( $routes ) );
	}

	private function normalize_rest_route( string $route ): string {
		if ( str_starts_with( $route, '/onumia/v1/' ) ) {
			return substr( $route, strlen( '/onumia/v1' ) );
		}

		return $route;
	}

	private function public_module_slug( ModuleDefinition $module ): string {
		$parts = array_values( array_filter( explode( '/', $module->name() ), static fn( string $part ): bool => '' !== $part ) );
		$part  = end( $parts );
		$slug  = strtolower( (string) preg_replace( '/[^a-z0-9_-]+/', '-', false === $part ? $module->name() : $part ) );
		$slug  = trim( $slug, '-' );

		return '' === $slug ? 'module' : $slug;
	}

	/**
	 * @return array{present:bool,valid:bool,entries:int,missingFiles:list<string>}
	 */
	private function asset_manifest_result( string $manifest ): array {
		if ( ! is_file( $manifest ) ) {
			return array(
				'present'      => false,
				'valid'        => false,
				'entries'      => 0,
				'missingFiles' => array(),
			);
		}

		$decoded = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return array(
				'present'      => true,
				'valid'        => false,
				'entries'      => 0,
				'missingFiles' => array(),
			);
		}

		$directory = dirname( $manifest );
		$missing   = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( $this->manifest_files( $entry ) as $file ) {
				if ( ! is_file( $directory . DIRECTORY_SEPARATOR . $file ) ) {
					$missing[] = $file;
				}
			}
		}

		return array(
			'present'      => true,
			'valid'        => true,
			'entries'      => count( $decoded ),
			'missingFiles' => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * @param array<array-key,mixed> $entry Manifest entry.
	 * @return list<string>
	 */
	private function manifest_files( array $entry ): array {
		$files = array();
		if ( is_string( $entry['file'] ?? null ) ) {
			$files[] = $entry['file'];
		}
		foreach ( array( 'css', 'assets' ) as $key ) {
			if ( ! is_array( $entry[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( $entry[ $key ] as $file ) {
				if ( is_string( $file ) ) {
					$files[] = $file;
				}
			}
		}

		return $files;
	}

	private function job_hook( string $module, string $job ): string {
		return 'onumia_module_job_' . substr( sha1( $module . ':' . $job ), 0, 24 );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private function add_check( string $name, string $status, string $message, array $data = array() ): void {
		$this->checks[] = array(
			'name'    => $name,
			'status'  => $status,
			'message' => $message,
			'data'    => $this->redact( $data ),
		);
	}

	/**
	 * @return array{healthy:int,warning:int,critical:int,total:int}
	 */
	private function summary(): array {
		$summary = array(
			'healthy'  => 0,
			'warning'  => 0,
			'critical' => 0,
			'total'    => count( $this->checks ),
		);

		foreach ( $this->checks as $check ) {
			$status = $check['status'];
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		return $summary;
	}

	/**
	 * @param array<string,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function redact( array $value ): array {
		$redacted = array();
		foreach ( $value as $key => $item ) {
			if ( $this->should_redact_key( $key ) ) {
				$redacted[ $key ] = is_bool( $item ) ? $item : '[redacted]';
				continue;
			}
			$redacted[ $key ] = is_array( $item ) ? $this->redact_array( $item ) : $item;
		}

		return $redacted;
	}

	/**
	 * @param array<array-key,mixed> $value Value.
	 * @return array<array-key,mixed>
	 */
	private function redact_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && $this->should_redact_key( $key ) ) {
				$result[ $key ] = is_bool( $item ) ? $item : '[redacted]';
				continue;
			}
			$result[ $key ] = is_array( $item ) ? $this->redact_array( $item ) : $item;
		}

		return $result;
	}

	private function should_redact_key( string $key ): bool {
		return 1 === preg_match( '/\Aname\z|(?:secret|token|authorization|password|email)/i', $key );
	}

	private function path_writable( string $path ): bool {
		if ( is_file( $path ) || is_dir( $path ) ) {
			return is_writable( $path );
		}

		$directory = dirname( $path );
		while ( '' !== $directory && '.' !== $directory && ! is_dir( $directory ) && dirname( $directory ) !== $directory ) {
			$directory = dirname( $directory );
		}

		return is_dir( $directory ) && is_writable( $directory );
	}

	private function theme_directory( string $function ): string {
		if ( function_exists( $function ) ) {
			$value = $function();
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return rtrim( $value, '/\\' );
			}
		}

		return '';
	}

	private function relative_or_basename( string $path ): string {
		$plugin_dir = rtrim( $this->plugin->directory(), '/\\' ) . DIRECTORY_SEPARATOR;
		if ( str_starts_with( $path, $plugin_dir ) ) {
			return ltrim( substr( $path, strlen( $plugin_dir ) ), '/\\' );
		}

		return $path;
	}

	private function plugin_basename(): string {
		// @codeCoverageIgnoreStart
		// Covered by WordPress at runtime; unit tests exercise the deterministic basename fallback below.
		if ( function_exists( 'plugin_basename' ) ) {
			return \plugin_basename( $this->plugin->file() );
		}
		// @codeCoverageIgnoreEnd

		return basename( dirname( $this->plugin->file() ) ) . '/' . basename( $this->plugin->file() );
	}

	/**
	 * @return list<mixed>
	 */
	private function test_global_array( string $key ): array {
		$value = $GLOBALS[ $key ] ?? array();
		return is_array( $value ) ? array_values( $value ) : array();
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) \current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
