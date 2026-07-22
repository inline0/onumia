<?php

/**
 * Installs module-declared database tables.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleTableInstaller {
	private const INSTALL_LOCK_NAME = 'onumia_module_table_install';

	/**
	 * @var array<string,array{version:int,checksum:string}>|null
	 */
	private static ?array $schema_state = null;
	private static ?string $schema_state_table = null;
	/**
	 * @var array<string,true>
	 */
	private static array $verified_tables = array();

	public function __construct(
		private readonly ModuleTableName $table_names = new ModuleTableName(),
	) {}

	public function install( ModuleRegistry $registry ): void {
		foreach ( $registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			$this->install_module( $module );
		}
	}

	public function install_module( ModuleDefinition $module ): void {
		foreach ( $this->mysql_tables( $module ) as $table ) {
			$this->ensure_table( $module, $table );
		}
	}

	public function table_is_installed( ModuleDefinition $module, ModuleTableDefinition $table ): bool {
		$state = $this->schema_state( $module, 'table', $table->name );
		if ( null === $state ) {
			return false;
		}

		return $this->schema_state_matches( $module, $table, $state ) && $this->table_exists_cached( $module, $table );
	}

	public function ensure_table( ModuleDefinition $module, ModuleTableDefinition $table ): void {
		$existing_state = $this->schema_state( $module, 'table', $table->name );
		if ( null !== $existing_state && $this->schema_state_matches( $module, $table, $existing_state ) && $this->table_exists_cached( $module, $table ) ) {
			return;
		}

		if ( ! $this->acquire_install_lock() ) {
			return;
		}

		try {
			$this->install_table( $module, $table, null !== $existing_state );
		} finally {
			$this->release_install_lock();
		}
	}

	public function table_schema_checksum( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		return hash( 'sha256', $this->table_sql( $module, $table ) );
	}

	public static function reset_schema_cache(): void {
		self::$schema_state       = null;
		self::$schema_state_table = null;
		self::$verified_tables    = array();
	}

	private function install_table( ModuleDefinition $module, ModuleTableDefinition $table, bool $migrate_existing ): void {
		$this->load_upgrade_file();
		// @codeCoverageIgnoreStart
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		( new ModuleTableNameMigration( $this->table_names ) )->migrate_table( $module, $table );
		$this->install_schema_table();
		if ( $migrate_existing ) {
			$this->prepare_column_migrations( $module, $table );
			$this->prepare_index_migrations( $module, $table );
		}
		\dbDelta( $this->table_sql( $module, $table ) );
		if ( ! $this->table_exists( $this->table_names->for_module_table( $module, $table->name ) ) ) {
			return;
		}

		$this->remember_table_exists( $this->table_names->for_module_table( $module, $table->name ) );
		$this->record_schema_state( $module, 'table', $table->name, $table->version, $this->table_sql( $module, $table ) );
		$this->record_migrations( $module );
	}

	/**
	 * @param array{version:int,checksum:string} $state Recorded schema state.
	 */
	private function schema_state_matches( ModuleDefinition $module, ModuleTableDefinition $table, array $state ): bool {
		return $state['version'] === $table->version && $state['checksum'] === $this->table_schema_checksum( $module, $table );
	}

	private function install_schema_table(): void {
		$table = $this->table_names->schema_table();
		\dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				module varchar(191) NOT NULL,
				object_type varchar(32) NOT NULL,
				object_name varchar(191) NOT NULL,
				version int(11) unsigned NOT NULL,
				checksum varchar(64) NOT NULL,
				installed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY module_object (module, object_type, object_name)
			) {$this->charset_collate()};"
		);
	}

	private function table_sql( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		$table_name = $this->table_names->for_module_table( $module, $table->name );
		$columns    = array_map( fn( ModuleColumnDefinition $column ): string => $this->column_sql( $column ), $table->columns );
		$indexes    = array_map( fn( ModuleIndexDefinition $index ): string => $this->index_sql( $index ), $table->indexes );
		$lines      = array_merge( $columns, $indexes );

		return "CREATE TABLE {$table_name} (\n\t" . implode( ",\n\t", $lines ) . "\n) {$this->charset_collate()};";
	}

	private function record_schema_state( ModuleDefinition $module, string $object_type, string $object_name, int $version, string $contents ): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'replace' ) ) {
			return;
		}

		$checksum = hash( 'sha256', $contents );
		$wpdb->replace(
			$this->table_names->schema_table(),
			array(
				'module'       => $module->name(),
				'object_type'  => $object_type,
				'object_name'  => $object_name,
				'version'      => $version,
				'checksum'     => $checksum,
				'installed_at' => function_exists( 'current_time' ) ? \current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$this->remember_schema_state( $module->name(), $object_type, $object_name, $version, $checksum );
	}

	/**
	 * @return array{version:int,checksum:string}|null
	 */
	private function schema_state( ModuleDefinition $module, string $object_type, string $object_name ): ?array {
		$state = $this->schema_state_map();
		$key   = $this->schema_state_key( $module->name(), $object_type, $object_name );

		return $state[ $key ] ?? null;
	}

	/**
	 * @return array<string,array{version:int,checksum:string}>
	 */
	private function schema_state_map(): array {
		$table = $this->table_names->schema_table();
		if ( null !== self::$schema_state && self::$schema_state_table === $table ) {
			return self::$schema_state;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			self::$schema_state       = array();
			self::$schema_state_table = $table;
			return self::$schema_state;
		}

		$previous_suppression = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal schema table name is generated by ModuleTableName.
			$rows = $wpdb->get_results( "SELECT module, object_type, object_name, version, checksum FROM {$table}", ARRAY_A );
		} finally {
			if ( is_bool( $previous_suppression ) && method_exists( $wpdb, 'suppress_errors' ) ) {
				$wpdb->suppress_errors( $previous_suppression );
			}
		}
		if ( ! is_array( $rows ) ) {
			self::$schema_state       = array();
			self::$schema_state_table = $table;
			return self::$schema_state;
		}

		$state = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$module      = $row['module'] ?? null;
			$object_type = $row['object_type'] ?? null;
			$object_name = $row['object_name'] ?? null;
			$version     = $row['version'] ?? null;
			$checksum    = $row['checksum'] ?? null;
			if ( ! is_string( $module ) || ! is_string( $object_type ) || ! is_string( $object_name ) || ! is_numeric( $version ) || ! is_string( $checksum ) ) {
				continue;
			}

			$state[ $this->schema_state_key( $module, $object_type, $object_name ) ] = array(
				'version'  => (int) $version,
				'checksum' => $checksum,
			);
		}

		self::$schema_state       = $state;
		self::$schema_state_table = $table;
		return self::$schema_state;
	}

	private function remember_schema_state( string $module, string $object_type, string $object_name, int $version, string $checksum ): void {
		$this->schema_state_map();
		self::$schema_state[ $this->schema_state_key( $module, $object_type, $object_name ) ] = array(
			'version'  => $version,
			'checksum' => $checksum,
		);
	}

	private function schema_state_key( string $module, string $object_type, string $object_name ): string {
		return $module . "\0" . $object_type . "\0" . $object_name;
	}

	private function record_migrations( ModuleDefinition $module ): void {
		foreach ( $this->migration_files( $module ) as $file ) {
			$contents = file_get_contents( $file );
			$contents = false === $contents ? '' : $contents;
			$this->record_schema_state( $module, 'migration', basename( $file ), $this->migration_version( $file ), $contents );
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
		// @codeCoverageIgnoreStart
		if ( false === $files ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

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
		if ( 1 !== preg_match( '/^([0-9]+)_/', basename( $file ), $match ) ) {
			return 0;
		}

		return max( 1, (int) $match[1] );
	}

	private function acquire_install_lock(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::INSTALL_LOCK_NAME, 10 ) );
		return is_scalar( $result ) && '1' === (string) $result;
	}

	private function release_install_lock(): void {
		global $wpdb;

		if ( $wpdb instanceof \wpdb ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::INSTALL_LOCK_NAME ) );
		}
	}

	private function load_upgrade_file(): void {
		if ( function_exists( 'dbDelta' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		// @codeCoverageIgnoreStart
		$abspath = \constant( 'ABSPATH' );
		if ( ! is_string( $abspath ) || '' === $abspath ) {
			return;
		}

		$upgrade_file = $abspath . 'wp-admin/includes/upgrade.php';
		if ( is_file( $upgrade_file ) ) {
			require_once $upgrade_file;
		}
		// @codeCoverageIgnoreEnd
	}

	private function prepare_column_migrations( ModuleDefinition $module, ModuleTableDefinition $table ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$table_name = $this->table_names->for_module_table( $module, $table->name );
		if ( ! $this->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $table->columns as $column ) {
			if ( 'string' !== $column->type || $column->length <= 0 ) {
				continue;
			}

			$existing_length = $this->existing_varchar_length( $table_name, $column->name );
			$target_length   = min( 191, $column->length );
			if ( null === $existing_length || $existing_length >= $target_length ) {
				continue;
			}

			$prefix = $wpdb->prepare( 'ALTER TABLE %i', $table_name );
			if ( is_string( $prefix ) ) {
				$query = $prefix . ' MODIFY COLUMN ' . $this->column_sql( $column );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table identifier is prepared above; the column definition is generated from module table contracts.
				$result = $wpdb->query( $query );
				if ( false !== $result ) {
					$this->flush_schema_metadata( $wpdb );
				}
			}
		}
	}

	private function existing_varchar_length( string $table, string $column ): ?int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ), ARRAY_A );
		if ( ! is_array( $row ) || ! is_string( $row['Type'] ?? null ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/^varchar\\((\\d+)\\)$/', strtolower( $row['Type'] ), $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}

	private function prepare_index_migrations( ModuleDefinition $module, ModuleTableDefinition $table ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$table_name = $this->table_names->for_module_table( $module, $table->name );
		if ( ! $this->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $table->indexes as $index ) {
			$existing = $this->existing_index_rows( $table_name, $index->name );
			if ( array() === $existing || ! $this->index_needs_rebuild( $existing, $index ) ) {
				continue;
			}

			$query = $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table_name, $index->name );
			if ( is_string( $query ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The DDL query is prepared immediately above.
				$result = $wpdb->query( $query );
				if ( false !== $result ) {
					$this->flush_schema_metadata( $wpdb );
				}
			}
		}
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$value = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return is_string( $value ) && strtolower( $table ) === strtolower( $value );
	}

	private function table_exists_cached( ModuleDefinition $module, ModuleTableDefinition $table ): bool {
		$table_name = $this->table_names->for_module_table( $module, $table->name );
		if ( isset( self::$verified_tables[ $table_name ] ) ) {
			return true;
		}

		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$this->remember_table_exists( $table_name );
		return true;
	}

	private function remember_table_exists( string $table ): void {
		self::$verified_tables[ $table ] = true;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function existing_index_rows( string $table, string $index ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$typed = array();
		foreach ( $rows as $row ) {
			$typed_row = $this->string_keyed_row( $row );
			if ( null !== $typed_row ) {
				$typed[] = $typed_row;
			}
		}

		return $typed;
	}

	/**
	 * @param list<array<string,mixed>> $existing Existing index rows.
	 */
	private function index_needs_rebuild( array $existing, ModuleIndexDefinition $target ): bool {
		$unique = true;
		foreach ( $existing as $row ) {
			$non_unique = $row['Non_unique'] ?? null;
			if ( ! is_scalar( $non_unique ) || '0' !== (string) $non_unique ) {
				$unique = false;
				break;
			}
		}

		if ( $unique !== $target->unique ) {
			return true;
		}

		usort(
			$existing,
			static fn( array $a, array $b ): int => self::index_sequence( $a ) <=> self::index_sequence( $b )
		);

		$columns = array_values(
			array_filter(
				array_map(
					static fn( array $row ): ?string => is_string( $row['Column_name'] ?? null ) ? $row['Column_name'] : null,
					$existing
				)
			)
		);

		return array_values( $target->columns ) !== $columns;
	}

	/**
	 * @param array<string,mixed> $row Index row.
	 */
	private static function index_sequence( array $row ): int {
		$value = $row['Seq_in_index'] ?? null;
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function string_keyed_row( mixed $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$typed = array();
		foreach ( $row as $key => $value ) {
			if ( is_string( $key ) ) {
				$typed[ $key ] = $value;
			}
		}

		return $typed;
	}

	private function flush_schema_metadata( \wpdb $wpdb ): void {
		$wpdb->flush();

		try {
				// @codeCoverageIgnoreStart
				$property = new \ReflectionProperty( $wpdb, 'col_meta' );
				$property->setValue( $wpdb, array() );
			// @codeCoverageIgnoreEnd
		} catch ( \ReflectionException $exception ) {
			// @codeCoverageIgnoreStart
			unset( $exception );
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @return array<string,ModuleTableDefinition>
	 */
	private function mysql_tables( ModuleDefinition $module ): array {
		return array_filter(
			$module->advanced()->tables(),
			static fn( ModuleTableDefinition $table ): bool => 'sqlite' !== $table->driver
		);
	}

	private function column_sql( ModuleColumnDefinition $column ): string {
		$column_name = $this->identifier_sql( $column->name );
		$sql         = $column_name . ' ' . $this->column_type_sql( $column );
		if ( $column->unsigned ) {
			$sql .= ' unsigned';
		}

		$sql .= $column->nullable ? ' DEFAULT NULL' : ' NOT NULL';
		if ( null !== $column->default ) {
			$sql .= ' DEFAULT ' . $this->default_sql( $column->default );
		}
		if ( $column->auto_increment ) {
			$sql .= ' AUTO_INCREMENT';
		}
		if ( $column->primary ) {
			$sql .= ', PRIMARY KEY  (' . $column_name . ')';
		}

		return $sql;
	}

	private function column_type_sql( ModuleColumnDefinition $column ): string {
		return match ( $column->type ) {
			'bigint' => 'bigint(20)',
			'boolean' => 'tinyint(1)',
			'datetime' => 'datetime',
			'decimal' => 'decimal(' . max( 1, $column->precision ) . ',' . max( 0, $column->scale ) . ')',
			'integer' => 'int(11)',
			'json', 'longtext' => 'longtext',
			'string' => 'varchar(' . ( $column->length > 0 ? min( 191, $column->length ) : 191 ) . ')',
			'text' => 'text',
			default => 'longtext',
		};
	}

	private function index_sql( ModuleIndexDefinition $index ): string {
		$type    = $index->unique ? 'UNIQUE KEY' : 'KEY';
		$name    = $this->identifier_sql( $index->name );
		$columns = implode( ', ', array_map( array( $this, 'identifier_sql' ), $index->columns ) );
		return "{$type} {$name} ({$columns})";
	}

	private function identifier_sql( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
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

	private function charset_collate(): string {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) {
			$value = $wpdb->get_charset_collate();
			if ( is_string( $value ) ) {
				return $value;
			}
		}

		return '';
	}
}
