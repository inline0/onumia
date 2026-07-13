<?php

/**
 * Dispatches module table handles.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Core\Errors;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\Modules\ModuleTableInstaller;
use Onumia\Modules\ModuleTableName;

final class ModuleTableStore {
	/**
	 * @var array<string,TableHandle>
	 */
	private array $handles = array();

	public function __construct(
		private readonly ModuleTableName $table_names = new ModuleTableName(),
		private readonly SqliteTableInstaller $sqlite_installer = new SqliteTableInstaller(),
		private readonly SqlitePathResolver $paths = new SqlitePathResolver(),
		private readonly ModuleStorageResolver $storage_resolver = new ModuleStorageResolver(),
		private readonly ModuleTableInstaller $mysql_installer = new ModuleTableInstaller(),
	) {}

	public function table( ModuleDefinition $module, string $name ): TableHandle {
		$table = $module->advanced()->table( $name );
		if ( null === $table ) {
			throw Errors::invariant( "Module {$module->name()} does not declare table {$name}." );
		}

		return $this->handle( $module, $table );
	}

	public function handle( ModuleDefinition $module, ModuleTableDefinition $table ): TableHandle {
		$key = $this->handle_cache_key( $module, $table );
		if ( isset( $this->handles[ $key ] ) ) {
			return $this->handles[ $key ];
		}

		if ( 'mysql' === $table->driver ) {
			$this->handles[ $key ] = new MysqlTableHandle(
				$module,
				$table,
				$this->table_names->for_module_table( $module, $table->name ),
				$this->mysql_installer
			);
			return $this->handles[ $key ];
		}

		if ( 'sqlite' === $table->driver ) {
			$resolution = $this->storage_resolver->resolve( 'sqlite' );
			if ( $resolution->uses_sqlite() ) {
				$this->handles[ $key ] = new SqliteTableHandle( $module, $table, $this->sqlite_installer_for_engine( $resolution->engine ) );
				return $this->handles[ $key ];
			}

			$this->handles[ $key ] = new MysqlTableHandle(
				$module,
				$table,
				$this->table_names->for_module_table( $module, $table->name ),
				$this->mysql_installer
			);
			return $this->handles[ $key ];
		}

		if ( 'auto' === $table->driver ) {
			$resolution = $this->storage_resolver->resolve();
			if ( $resolution->uses_sqlite() ) {
				$this->handles[ $key ] = new SqliteTableHandle( $module, $table, $this->sqlite_installer_for_engine( $resolution->engine ) );
				return $this->handles[ $key ];
			}

			$this->handles[ $key ] = new MysqlTableHandle(
				$module,
				$table,
				$this->table_names->for_module_table( $module, $table->name ),
				$this->mysql_installer
			);
			return $this->handles[ $key ];
		}

		throw Errors::invariant( "Table {$table->name} uses unsupported driver {$table->driver} in this runtime." );
	}

	private function handle_cache_key( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		return sha1( $module->name() . '|' . $module->directory() . '|' . serialize( $table ) );
	}

	private function sqlite_installer_for_engine( string $engine ): SqliteTableInstaller {
		if ( StorageResolution::ENGINE_SQLITE3 === $engine ) {
			// @codeCoverageIgnoreStart
			// Only selected on hosts with sqlite3 but without pdo_sqlite; local and CI PHP expose pdo_sqlite first.
			return new SqliteTableInstaller( $this->paths, interface: 'sqlite3' );
			// @codeCoverageIgnoreEnd
		}

		return $this->sqlite_installer;
	}
}
