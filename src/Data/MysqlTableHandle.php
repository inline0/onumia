<?php

/**
 * MySQL-backed module table handle.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Core\Errors;
use Onumia\Modules\ModuleColumnDefinition;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\Modules\ModuleTableInstaller;
use Onumia\PublicApi\Filters;

final class MysqlTableHandle implements TableHandle {
	/**
	 * @var array<string,ModuleColumnDefinition>
	 */
	private readonly array $columns;

	public function __construct(
		private readonly ModuleDefinition $module,
		private readonly ModuleTableDefinition $definition,
		private readonly string $table_name,
		private readonly ModuleTableInstaller $installer = new ModuleTableInstaller(),
	) {
		$columns = array();
		foreach ( $definition->columns as $column ) {
			$columns[ $column->name ] = $column;
		}

		$this->columns = $columns;
	}

	public function name(): string {
		return $this->definition->name;
	}

	public function table_name(): string {
		return $this->table_name;
	}

	public function definition(): ModuleTableDefinition {
		return $this->definition;
	}

	public function insert( array $row ): int {
		$data = $this->normalize_row_for_storage( $row );
		$this->ensure_for_write();
		$this->prune_for_row_cap();

		$result = $this->call( 'insert', $this->table_name, $data, $this->formats( $data ) );
		if ( false === $result ) {
			throw Errors::invariant( "Could not insert {$this->module->name()} {$this->definition->name} row." );
		}

		$insert_id = $this->property( 'insert_id' );
		if ( ! is_int( $insert_id ) && ! is_numeric( $insert_id ) ) {
			throw Errors::invariant( 'WordPress database insert id is not available.' );
		}

		return (int) $insert_id;
	}

	public function update( int $id, array $row ): void {
		if ( array() === $row ) {
			return;
		}

		$data = $this->normalize_row_for_storage( $row );
		unset( $data[ $this->primary_column() ] );
		if ( array() === $data ) {
			return;
		}

		$this->ensure_for_write();
		$result = $this->call(
			'update',
			$this->table_name,
			$data,
			array( $this->primary_column() => $id ),
			$this->formats( $data ),
			array( '%d' )
		);
		if ( false === $result ) {
			throw Errors::invariant( "Could not update {$this->module->name()} {$this->definition->name} row." );
		}
	}

	public function recent( int $limit = 100, ?int $since_days = null, array $where = array() ): array {
		$limit       = max( 1, min( 1000, $limit ) );
		$conditions  = $this->where_conditions( $where );
		$time_column = $this->time_column();
		if ( null !== $since_days && null !== $time_column ) {
			$conditions[] = $this->prepare( "{$this->column_identifier( $time_column->name )} >= %s", $this->cutoff_value( $time_column, $since_days ) );
		}

		if ( ! $this->table_exists_for_read() ) {
			return array();
		}

		$where_sql = array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
		$query     = "SELECT * FROM {$this->table_name}{$where_sql} ORDER BY {$this->column_identifier( $this->order_column() )} DESC LIMIT {$limit}";

		return $this->normalize_rows_from_storage( $this->rows( $query ) );
	}

	public function count( array $where = array() ): int {
		$conditions = $this->where_conditions( $where );
		if ( ! $this->table_exists_for_read() ) {
			return 0;
		}

		$where_sql  = array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
		$value      = $this->call( 'get_var', "SELECT COUNT(*) FROM {$this->table_name}{$where_sql}" );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function find( int $id ): ?array {
		if ( ! $this->table_exists_for_read() ) {
			return null;
		}

		$primary = $this->primary_column();
		$row     = $this->row( $this->prepare( "SELECT * FROM {$this->table_name} WHERE {$this->column_identifier( $primary )} = %d LIMIT 1", $id ) );

		return null === $row ? null : $this->normalize_row_from_storage( $row );
	}

	public function increment_counter( string $key, int $amount = 1 ): int {
		$key_column   = $this->counter_key_column();
		$value_column = $this->counter_value_column();
		$amount       = max( 1, $amount );
		$this->ensure_for_write();
		$query        = $this->prepare(
			"INSERT INTO {$this->table_name} ({$this->column_identifier( $key_column )}, {$this->column_identifier( $value_column )}) VALUES (%s, %d) ON DUPLICATE KEY UPDATE {$this->column_identifier( $value_column )} = {$this->column_identifier( $value_column )} + VALUES({$this->column_identifier( $value_column )})",
			$key,
			$amount
		);

		$this->query( $query );
		return $this->read_counter( $key );
	}

	public function read_counter( string $key ): int {
		$key_column   = $this->counter_key_column();
		$value_column = $this->counter_value_column();
		if ( ! $this->table_exists_for_read() ) {
			return 0;
		}

		$value        = $this->call( 'get_var', $this->prepare( "SELECT {$this->column_identifier( $value_column )} FROM {$this->table_name} WHERE {$this->column_identifier( $key_column )} = %s LIMIT 1", $key ) );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function purge( ?int $before_days = null, array $where = array() ): int {
		$conditions  = $this->where_conditions( $where );
		$time_column = $this->time_column();
		if ( null !== $before_days && null !== $time_column ) {
			$conditions[] = $this->prepare( "{$this->column_identifier( $time_column->name )} < %s", $this->cutoff_value( $time_column, $before_days ) );
		}

		if ( array() === $conditions ) {
			return 0;
		}

		if ( ! $this->table_exists_for_read() ) {
			return 0;
		}

		return $this->query( "DELETE FROM {$this->table_name} WHERE " . implode( ' AND ', $conditions ) );
	}

	public function purge_all(): int {
		if ( ! $this->table_exists_for_read() ) {
			return 0;
		}

		return $this->query( "TRUNCATE TABLE {$this->table_name}" );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function export_rows(): array {
		if ( ! $this->table_exists_for_read() ) {
			return array();
		}

		return $this->normalize_rows_from_storage( $this->rows( "SELECT * FROM {$this->table_name} ORDER BY {$this->column_identifier( $this->order_column() )} ASC" ) );
	}

	private function prune_for_row_cap(): void {
		if ( null === $this->definition->row_cap ) {
			return;
		}

		$count = $this->count();
		if ( $count < $this->definition->row_cap ) {
			return;
		}

		$overflow = $count - $this->definition->row_cap + 1;
		$this->query( "DELETE FROM {$this->table_name} ORDER BY {$this->column_identifier( $this->order_column() )} ASC LIMIT {$overflow}" );
	}

	private function table_exists_for_read(): bool {
		return $this->installer->table_is_installed( $this->module, $this->definition );
	}

	private function ensure_for_write(): void {
		$this->installer->ensure_table( $this->module, $this->definition );
	}

	/**
	 * @param array<string,mixed> $where Equality filters.
	 * @return list<string>
	 */
	private function where_conditions( array $where ): array {
		$conditions = array();
		foreach ( $where as $column => $value ) {
			$this->assert_column( $column );
			$conditions[] = $this->prepare( "{$this->column_identifier( $column )} = " . $this->placeholder_for_value( $value ), $this->storage_value( $column, $value ) );
		}

		return $conditions;
	}

	private function column_identifier( string $column ): string {
		return '`' . str_replace( '`', '``', $column ) . '`';
	}

	private function primary_column(): string {
		foreach ( $this->columns as $column ) {
			if ( $column->primary ) {
				return $column->name;
			}
		}

		return 'id';
	}

	private function order_column(): string {
		if ( isset( $this->columns['id'] ) ) {
			return 'id';
		}

		if ( isset( $this->columns['created_at'] ) ) {
			return 'created_at';
		}

		return (string) array_key_first( $this->columns );
	}

	private function time_column(): ?ModuleColumnDefinition {
		foreach ( array( 'created_at', 'occurred_at', 'updated_at' ) as $name ) {
			if ( isset( $this->columns[ $name ] ) ) {
				return $this->columns[ $name ];
			}
		}

		return null;
	}

	private function counter_key_column(): string {
		foreach ( array( 'counter_key', 'key' ) as $name ) {
			if ( isset( $this->columns[ $name ] ) ) {
				return $name;
			}
		}

		throw Errors::invariant( "Table {$this->definition->name} does not declare a counter key column." );
	}

	private function counter_value_column(): string {
		foreach ( array( 'value', 'count' ) as $name ) {
			if ( isset( $this->columns[ $name ] ) ) {
				return $name;
			}
		}

		throw Errors::invariant( "Table {$this->definition->name} does not declare a counter value column." );
	}

	private function cutoff_value( ModuleColumnDefinition $column, int $days ): string|int {
		$day       = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		$timestamp = $this->now_timestamp() - ( max( 1, $days ) * $day );
		if ( 'integer' === $column->type || 'bigint' === $column->type ) {
			return $timestamp;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private function now_timestamp(): int {
		return (int) \current_time( 'timestamp' );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_row_for_storage( array $row ): array {
		$normalized = array();
		foreach ( $row as $column => $value ) {
			$this->assert_column( $column );
			$normalized[ $column ] = $this->storage_value( $column, $value );
		}

		return $normalized;
	}

	private function storage_value( string $column, mixed $value ): mixed {
		$definition = $this->columns[ $column ];
		if ( is_string( $value ) && $this->is_uri_column( $column ) ) {
			$value = Filters::table_uri_redaction( $value, $column, $this->definition, $this->module );
		}

		if ( 'json' === $definition->type && ( is_array( $value ) || is_object( $value ) ) ) {
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				throw Errors::invariant( "Could not encode {$this->definition->name}.{$column} JSON value." );
			}

			return $json;
		}

		if ( 'boolean' === $definition->type && is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		return $value;
	}

	private function is_uri_column( string $column ): bool {
		return in_array( $column, array( 'uri', 'request_uri', 'url' ), true ) || str_ends_with( $column, '_uri' ) || str_ends_with( $column, '_url' );
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows.
	 * @return list<array<string,mixed>>
	 */
	private function normalize_rows_from_storage( array $rows ): array {
		return array_map( fn( array $row ): array => $this->normalize_row_from_storage( $row ), $rows );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function normalize_row_from_storage( array $row ): array {
		$normalized = array();
		foreach ( $row as $column => $value ) {
			if ( ! isset( $this->columns[ $column ] ) ) {
				$normalized[ $column ] = $value;
				continue;
			}

			$definition = $this->columns[ $column ];
			if ( ( 'integer' === $definition->type || 'bigint' === $definition->type ) && is_numeric( $value ) ) {
				$normalized[ $column ] = (int) $value;
				continue;
			}

			if ( 'json' === $definition->type && is_string( $value ) && '' !== $value ) {
				$decoded               = json_decode( $value, true );
				$normalized[ $column ] = is_array( $decoded ) ? $decoded : null;
				continue;
			}

			if ( 'boolean' === $definition->type && ( is_int( $value ) || is_numeric( $value ) ) ) {
				$normalized[ $column ] = 1 === (int) $value;
				continue;
			}

			$normalized[ $column ] = $value;
		}

		return $normalized;
	}

	private function assert_column( string $column ): void {
		if ( ! isset( $this->columns[ $column ] ) ) {
			throw Errors::invariant( "Table {$this->definition->name} does not declare column {$column}." );
		}
	}

	private function placeholder_for_value( mixed $value ): string {
		return is_int( $value ) || is_bool( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @return list<string>
	 */
	private function formats( array $data ): array {
		return array_map(
			fn( mixed $value ): string => $this->placeholder_for_value( $value ),
			array_values( $data )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function row( string $query ): ?array {
		$row = $this->call( 'get_row', $query, ARRAY_A );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw Errors::invariant( 'WordPress database row result is invalid.' );
		}

		return $this->string_keyed_array( $row );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function rows( string $query ): array {
		$rows = $this->call( 'get_results', $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			throw Errors::invariant( 'WordPress database result list is invalid.' );
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$normalized[] = $this->string_keyed_array( $row );
			}
		}

		return $normalized;
	}

	private function query( string $query ): int {
		$result = $this->call( 'query', $query );
		if ( false === $result ) {
			throw Errors::invariant( 'WordPress database query failed.' );
		}

		return is_numeric( $result ) ? (int) $result : 0;
	}

	private function prepare( string $query, mixed ...$args ): string {
		$prepared = $this->call( 'prepare', $query, ...$args );
		if ( ! is_string( $prepared ) ) {
			throw Errors::invariant( 'WordPress database prepare did not return SQL.' );
		}

		return $prepared;
	}

	private function property( string $name ): mixed {
		$database = $this->wpdb();
		return $database->{$name} ?? null;
	}

	private function call( string $method, mixed ...$args ): mixed {
		$database = $this->wpdb();
		if ( ! method_exists( $database, $method ) ) {
			throw Errors::invariant( "WordPress database method {$method} is not available." );
		}

		return $database->{$method}( ...$args );
	}

	private function wpdb(): object {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			throw Errors::invariant( 'WordPress database is not available.' );
		}

		return $wpdb;
	}

	/**
	 * @param  array<array-key,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $row ): array {
		$normalized = array();
		foreach ( $row as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}
}
