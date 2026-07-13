<?php

/**
 * Lazily installs SQLite module table files.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use PDOException;
use SQLite3;
use Onumia\Core\Errors;
use Onumia\Modules\ModuleColumnDefinition;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleIndexDefinition;
use Onumia\Modules\ModuleTableDefinition;

final class SqliteTableInstaller {
	/**
	 * @var array<string,true>
	 */
	private static array $verified_schemas = array();

	/**
	 * @var (callable(string, bool):(SqliteConnection|\PDO|\SQLite3))|null
	 */
	private $connection_factory;

	/**
	 * @param (callable(string, bool):(SqliteConnection|\PDO|\SQLite3))|null $connection_factory Connection factory.
	 */
	public function __construct(
		private readonly SqlitePathResolver $paths = new SqlitePathResolver(),
		?callable $connection_factory = null,
		private readonly ?string $interface = null,
	) {
		$this->connection_factory = $connection_factory;
	}

	public function database_path( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		return $this->paths->database_path( $module, $table );
	}

	public function database_exists( ModuleDefinition $module, ModuleTableDefinition $table ): bool {
		$path = $this->database_path( $module, $table );
		if ( ':memory:' !== $path ) {
			return is_file( $path );
		}

		$connection = $this->connect( $path, false );
		$this->configure( $connection );
		return $this->table_exists( $connection, $table->name );
	}

	public function open( ModuleDefinition $module, ModuleTableDefinition $table, bool $create ): SqliteConnection {
		$path = $this->database_path( $module, $table );
		if ( ! $create && ':memory:' !== $path && ! is_file( $path ) ) {
			throw Errors::invariant( "SQLite table {$module->name()}.{$table->name} has not been created." );
		}

		if ( $create ) {
			$this->paths->ensure_directory( $module );
		}

		$connection = $this->connect( $path, $create );
		$this->configure( $connection );
		if ( $create ) {
			$this->install_transactionally( $connection, $module, $table, $path );
		} else {
			$this->migrate_existing( $connection, $module, $table, $path );
		}

		return $connection;
	}

	private function install_transactionally( SqliteConnection $connection, ModuleDefinition $module, ModuleTableDefinition $table, string $path ): void {
		$connection->begin_transaction();
		try {
			$this->install( $connection, $module, $table, $path );
			$connection->commit();
		} catch ( \Throwable $throwable ) {
			if ( $connection->in_transaction() ) {
				$connection->roll_back();
			}

			throw $throwable;
		}
	}

	public function install_sql( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		unset( $module );
		$columns = array_map( fn( ModuleColumnDefinition $column ): string => $this->column_sql( $column ), $table->columns );
		$indexes = array_map( fn( ModuleIndexDefinition $index ): string => $this->index_sql( $table, $index ), $table->indexes );

		return "CREATE TABLE IF NOT EXISTS {$this->identifier( $table->name )} (\n\t" . implode( ",\n\t", $columns ) . "\n);\n" . implode( "\n", $indexes );
	}

	private function install( SqliteConnection $connection, ModuleDefinition $module, ModuleTableDefinition $table, string $path ): void {
		$exists = $this->table_exists( $connection, $table->name );
		if ( ! $exists ) {
			$connection->exec( "CREATE TABLE IF NOT EXISTS {$this->identifier( $table->name )} (\n\t" . implode( ",\n\t", array_map( fn( ModuleColumnDefinition $column ): string => $this->column_sql( $column ), $table->columns ) ) . "\n)" );
			foreach ( $table->indexes as $index ) {
				$connection->exec( $this->index_sql( $table, $index ) );
			}
			$connection->exec( 'PRAGMA user_version = ' . $table->version );
		}

		$this->migrate_existing( $connection, $module, $table, $path );
	}

	private function migrate_existing( SqliteConnection $connection, ModuleDefinition $module, ModuleTableDefinition $table, string $path ): void {
		if ( ! $this->table_exists( $connection, $table->name ) ) {
			return;
		}

		$current = $this->user_version( $connection );
		if ( $current < $table->version ) {
			$this->run_migrations( $connection, $module, $current, $table->version );
		}

		if ( $this->user_version( $connection ) !== $table->version ) {
			throw Errors::invariant( "SQLite table {$module->name()}.{$table->name} schema version mismatch." );
		}

		$cache_key = $this->schema_cache_key( $path, $module, $table );
		if ( null !== $cache_key && isset( self::$verified_schemas[ $cache_key ] ) ) {
			return;
		}

		$this->assert_schema_matches( $connection, $module, $table );
		if ( null !== $cache_key ) {
			self::$verified_schemas[ $cache_key ] = true;
		}
	}

	private function schema_cache_key( string $path, ModuleDefinition $module, ModuleTableDefinition $table ): ?string {
		if ( ':memory:' === $path ) {
			return null;
		}

		return sha1( $path . '|' . $module->name() . '|' . $table->name . '|' . $table->version . '|' . $this->install_sql( $module, $table ) );
	}

	private function run_migrations( SqliteConnection $connection, ModuleDefinition $module, int $current, int $target ): void {
		$version = $current;
		foreach ( $this->migration_files( $module ) as $file ) {
			$file_version = $this->migration_version( $file );
			if ( $file_version <= $current || $file_version > $target ) {
				continue;
			}

			$migration = require $file;
			if ( is_callable( $migration ) ) {
				$migration( $connection->native() );
			}

			$version = $file_version;
			$connection->exec( 'PRAGMA user_version = ' . $version );
		}

		if ( $version < $target ) {
			throw Errors::invariant( "SQLite migrations for {$module->name()} did not reach version {$target}." );
		}
	}

	/**
	 * @return string[]
	 */
	private function migration_files( ModuleDefinition $module ): array {
		$directory = $module->directory() . DIRECTORY_SEPARATOR . 'migrations';
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files = glob( $directory . DIRECTORY_SEPARATOR . '*.php' );
		$files = false === $files ? array() : $files;
		$files = array_values(
			array_filter(
				$files,
				static fn( string $file ): bool => 1 === preg_match( '/^[0-9]+_[A-Za-z0-9_\\-]+\\.php$/', basename( $file ) )
			)
		);
		sort( $files );
		return $files;
	}

	private function migration_version( string $file ): int {
		preg_match( '/^([0-9]+)_/', basename( $file ), $match );
		return max( 1, (int) ( $match[1] ?? 1 ) );
	}

	private function assert_schema_matches( SqliteConnection $connection, ModuleDefinition $module, ModuleTableDefinition $table ): void {
		$existing = array();
		foreach ( $connection->rows( 'PRAGMA table_info(' . $this->identifier( $table->name ) . ')' ) as $row ) {
			$name = $row['name'] ?? null;
			if ( is_string( $name ) ) {
				$existing[ $name ] = true;
			}
		}

		foreach ( $table->columns as $column ) {
			if ( ! isset( $existing[ $column->name ] ) ) {
				throw Errors::invariant( "SQLite table {$module->name()}.{$table->name} is missing column {$column->name}." );
			}
		}
	}

	private function table_exists( SqliteConnection $connection, string $table ): bool {
		return is_string( $connection->value( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1", array( ':name' => $table ) ) );
	}

	private function user_version( SqliteConnection $connection ): int {
		$value = $connection->value( 'PRAGMA user_version' );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function connect( string $path, bool $create ): SqliteConnection {
		try {
			if ( null !== $this->connection_factory ) {
				return $this->normalize_connection( ( $this->connection_factory )( $path, $create ) );
			}

			if ( 'sqlite3' === $this->resolved_interface() ) {
				return new SQLite3SqliteConnection( new SQLite3( ':memory:' === $path ? ':memory:' : $path ) );
			}

			$dsn = ':memory:' === $path ? 'sqlite::memory:' : 'sqlite:' . $path;
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO, WordPress.DB.RestrictedClasses.mysql__PDO_ATTR_ERRMODE, WordPress.DB.RestrictedClasses.mysql__PDO_ERRMODE_EXCEPTION -- Module tables intentionally use SQLite outside the WordPress database.
			return new PdoSqliteConnection( new \PDO( $dsn, null, null, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) ) );
		} catch ( PDOException | \Exception $exception ) {
			throw Errors::invariant( 'Could not open SQLite module table: ' . $exception->getMessage() );
		}
	}

	private function normalize_connection( mixed $connection ): SqliteConnection {
		if ( $connection instanceof SqliteConnection ) {
			return $connection;
		}

		if ( $connection instanceof \PDO ) {
			return new PdoSqliteConnection( $connection );
		}

		if ( $connection instanceof SQLite3 ) {
			return new SQLite3SqliteConnection( $connection );
		}

		// @codeCoverageIgnoreStart
		throw Errors::invariant( 'SQLite connection factory returned an unsupported connection.' );
		// @codeCoverageIgnoreEnd
	}

	private function resolved_interface(): string {
		if ( 'sqlite3' === $this->interface ) {
			return 'sqlite3';
		}

		return 'pdo';
	}

	private function configure( SqliteConnection $connection ): void {
		$connection->exec( 'PRAGMA busy_timeout = 5000' );
		$connection->exec( 'PRAGMA foreign_keys = ON' );
		$connection->exec( 'PRAGMA synchronous = NORMAL' );
		$this->configure_journal_mode( $connection );
	}

	private function configure_journal_mode( SqliteConnection $connection ): void {
		$attempts = 3;
		for ( $attempt = 1; $attempt <= $attempts; ++$attempt ) {
			try {
				$connection->exec( 'PRAGMA journal_mode = WAL' );
				return;
			} catch ( \Throwable $throwable ) {
				if ( $attempt >= $attempts || ! $connection->is_locked_exception( $throwable ) ) {
					throw $throwable;
				}

				usleep( 50000 * $attempt );
			}
		}
	}

	private function column_sql( ModuleColumnDefinition $column ): string {
		if ( $column->auto_increment && $column->primary ) {
			return $this->identifier( $column->name ) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
		}

		$sql  = $this->identifier( $column->name ) . ' ' . $this->column_type_sql( $column );
		$sql .= $column->nullable ? ' DEFAULT NULL' : ' NOT NULL';
		if ( null !== $column->default ) {
			$sql .= ' DEFAULT ' . $this->default_sql( $column->default );
		}
		if ( $column->primary ) {
			$sql .= ' PRIMARY KEY';
		}

		return $sql;
	}

	private function column_type_sql( ModuleColumnDefinition $column ): string {
		return match ( $column->type ) {
			'bigint', 'integer', 'boolean' => 'INTEGER',
			'decimal' => 'NUMERIC',
			'datetime', 'json', 'longtext', 'string', 'text' => 'TEXT',
			default => 'TEXT',
		};
	}

	private function index_sql( ModuleTableDefinition $table, ModuleIndexDefinition $index ): string {
		$unique  = $index->unique ? 'UNIQUE ' : '';
		$columns = implode( ', ', array_map( fn( string $column ): string => $this->identifier( $column ), $index->columns ) );

		return "CREATE {$unique}INDEX IF NOT EXISTS {$this->identifier( $table->name . '_' . $index->name )} ON {$this->identifier( $table->name )} ({$columns});";
	}

	private function default_sql( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		}

		return "''";
	}

	private function identifier( string $identifier ): string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}
}
