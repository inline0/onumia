<?php

/**
 * SQLite-backed module table handle.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Core\Errors;
use Onumia\Modules\ModuleColumnDefinition;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\PublicApi\Filters;

final class SqliteTableHandle implements TableHandle {
	/**
	 * @var array<string,ModuleColumnDefinition>
	 */
	private readonly array $columns;

	public function __construct(
		private readonly ModuleDefinition $module,
		private readonly ModuleTableDefinition $definition,
		private readonly SqliteTableInstaller $installer,
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

	public function database_path(): string {
		return $this->installer->database_path( $this->module, $this->definition );
	}

	public function definition(): ModuleTableDefinition {
		return $this->definition;
	}

	public function insert( array $row ): int {
		return $this->write(
			function ( SqliteConnection $connection ) use ( $row ): int {
				$this->prune_for_row_cap( $connection );
				$data = $this->normalize_row_for_storage( $row );
				$sql  = 'INSERT INTO ' . $this->identifier( $this->definition->name ) . ' (' . implode( ', ', array_map( fn( string $column ): string => $this->identifier( $column ), array_keys( $data ) ) ) . ') VALUES (' . implode( ', ', array_map( static fn( string $column ): string => ':' . $column, array_keys( $data ) ) ) . ')';
				$connection->execute( $sql, $this->parameters( $data ) );

				return $connection->last_insert_id();
			},
			'insert'
		);
	}

	public function update( int $id, array $row ): void {
		$this->write(
			function ( SqliteConnection $connection ) use ( $id, $row ): void {
				if ( array() === $row ) {
					return;
				}

				$data = $this->normalize_row_for_storage( $row );
				unset( $data[ $this->primary_column() ] );
				if ( array() === $data ) {
					return;
				}

				$assignments   = array_map(
					fn( string $column ): string => $this->identifier( $column ) . ' = :' . $column,
					array_keys( $data )
				);
				$sql           = 'UPDATE ' . $this->identifier( $this->definition->name ) . ' SET ' . implode( ', ', $assignments ) . ' WHERE ' . $this->identifier( $this->primary_column() ) . ' = :id';
				$params        = $this->parameters( $data );
				$params[':id'] = $id;
				$connection->execute( $sql, $params );
			},
			'update'
		);
	}

	public function recent( int $limit = 100, ?int $since_days = null, array $where = array() ): array {
		if ( ! $this->exists() ) {
			return array();
		}

		$limit       = max( 1, min( 1000, $limit ) );
		$conditions  = $this->where_conditions( $where );
		$time_column = $this->time_column();
		if ( null !== $since_days && null !== $time_column ) {
			$conditions[] = array(
				'sql'    => $this->identifier( $time_column->name ) . ' >= :since_days',
				'params' => array( ':since_days' => $this->cutoff_value( $time_column, $since_days ) ),
			);
		}

		$where_sql = $this->where_sql( $conditions );
		$params    = $this->condition_params( $conditions );
		$sql       = 'SELECT * FROM ' . $this->identifier( $this->definition->name ) . $where_sql . ' ORDER BY ' . $this->identifier( $this->order_column() ) . " DESC LIMIT {$limit}";

		return $this->normalize_rows_from_storage( $this->read()->rows( $sql, $params ) );
	}

	public function count( array $where = array() ): int {
		if ( ! $this->exists() ) {
			return 0;
		}

		$conditions = $this->where_conditions( $where );
		$value      = $this->read()->value( 'SELECT COUNT(*) FROM ' . $this->identifier( $this->definition->name ) . $this->where_sql( $conditions ), $this->condition_params( $conditions ) );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function find( int $id ): ?array {
		if ( ! $this->exists() ) {
			return null;
		}

		$row = $this->read()->row(
			'SELECT * FROM ' . $this->identifier( $this->definition->name ) . ' WHERE ' . $this->identifier( $this->primary_column() ) . ' = :id LIMIT 1',
			array( ':id' => $id )
		);

		return null === $row ? null : $this->normalize_row_from_storage( $row );
	}

	public function increment_counter( string $key, int $amount = 1 ): int {
		return $this->write(
			function ( SqliteConnection $connection ) use ( $key, $amount ): int {
				$key_column   = $this->counter_key_column();
				$value_column = $this->counter_value_column();
				$amount       = max( 1, $amount );
				$sql          = 'INSERT INTO ' . $this->identifier( $this->definition->name ) . ' (' . $this->identifier( $key_column ) . ', ' . $this->identifier( $value_column ) . ') VALUES (:counter_key, :amount) ON CONFLICT(' . $this->identifier( $key_column ) . ') DO UPDATE SET ' . $this->identifier( $value_column ) . ' = ' . $this->identifier( $value_column ) . ' + excluded.' . $this->identifier( $value_column );
				$connection->execute(
					$sql,
					array(
						':counter_key' => $key,
						':amount'      => $amount,
					)
				);

				return $this->read_counter_from( $connection, $key );
			},
			'increment counter'
		);
	}

	public function read_counter( string $key ): int {
		if ( ! $this->exists() ) {
			return 0;
		}

		return $this->read_counter_from( $this->read(), $key );
	}

	public function purge( ?int $before_days = null, array $where = array() ): int {
		if ( ! $this->exists() ) {
			return 0;
		}

		$conditions  = $this->where_conditions( $where );
		$time_column = $this->time_column();
		if ( null !== $before_days && null !== $time_column ) {
			$conditions[] = array(
				'sql'    => $this->identifier( $time_column->name ) . ' < :before_days',
				'params' => array( ':before_days' => $this->cutoff_value( $time_column, $before_days ) ),
			);
		}

		if ( array() === $conditions ) {
			return 0;
		}

		return $this->read()->execute( 'DELETE FROM ' . $this->identifier( $this->definition->name ) . $this->where_sql( $conditions ), $this->condition_params( $conditions ) );
	}

	public function purge_all(): int {
		if ( ! $this->exists() ) {
			return 0;
		}

		return $this->read()->execute( 'DELETE FROM ' . $this->identifier( $this->definition->name ) );
	}

	public function export_rows(): array {
		if ( ! $this->exists() ) {
			return array();
		}

		$sql = 'SELECT * FROM ' . $this->identifier( $this->definition->name ) . ' ORDER BY ' . $this->identifier( $this->order_column() ) . ' ASC';
		return $this->normalize_rows_from_storage( $this->read()->rows( $sql ) );
	}

	private function read(): SqliteConnection {
		return $this->installer->open( $this->module, $this->definition, false );
	}

	/**
	 * @template T
	 * @param callable(SqliteConnection):T $callback Callback.
	 * @return T
	 */
	private function write( callable $callback, string $operation ): mixed {
		$attempts = 3;
		for ( $attempt = 1; $attempt <= $attempts; ++$attempt ) {
			$connection = $this->installer->open( $this->module, $this->definition, true );
			try {
				$connection->begin_transaction();
				$result = $callback( $connection );
				$connection->commit();

				return $result;
			} catch ( \Throwable $exception ) {
				if ( $connection->in_transaction() ) {
					$connection->roll_back();
				}

				if ( $attempt < $attempts && $connection->is_locked_exception( $exception ) ) {
					// @codeCoverageIgnoreStart
					usleep( 50000 * $attempt );
					continue;
					// @codeCoverageIgnoreEnd
				}

				throw Errors::invariant( "Could not {$operation} SQLite {$this->module->name()} {$this->definition->name} row: " . $exception->getMessage() );
			}
		}

		// @codeCoverageIgnoreStart
		throw Errors::invariant( "Could not {$operation} SQLite {$this->module->name()} {$this->definition->name} row." );
		// @codeCoverageIgnoreEnd
	}

	private function prune_for_row_cap( SqliteConnection $connection ): void {
		if ( null === $this->definition->row_cap ) {
			return;
		}

		$count = $this->count_from( $connection );
		if ( $count < $this->definition->row_cap ) {
			return;
		}

		$overflow = $count - $this->definition->row_cap + 1;
		$sql      = 'DELETE FROM ' . $this->identifier( $this->definition->name ) . ' WHERE rowid IN (SELECT rowid FROM ' . $this->identifier( $this->definition->name ) . ' ORDER BY ' . $this->identifier( $this->order_column() ) . " ASC LIMIT {$overflow})";
		$connection->execute( $sql );
	}

	private function count_from( SqliteConnection $connection ): int {
		$value = $connection->value( 'SELECT COUNT(*) FROM ' . $this->identifier( $this->definition->name ) );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array<string,mixed> $where Equality filters.
	 * @return list<array{sql:string,params:array<string,mixed>}>
	 */
	private function where_conditions( array $where ): array {
		$conditions = array();
		$index      = 0;
		foreach ( $where as $column => $value ) {
			$this->assert_column( $column );
			$placeholder  = ':where_' . $index;
			$conditions[] = array(
				'sql'    => $this->identifier( $column ) . " = {$placeholder}",
				'params' => array( $placeholder => $this->storage_value( $column, $value ) ),
			);
			++$index;
		}

		return $conditions;
	}

	/**
	 * @param list<array{sql:string,params:array<string,mixed>}> $conditions Conditions.
	 */
	private function where_sql( array $conditions ): string {
		if ( array() === $conditions ) {
			return '';
		}

		return ' WHERE ' . implode( ' AND ', array_column( $conditions, 'sql' ) );
	}

	/**
	 * @param list<array{sql:string,params:array<string,mixed>}> $conditions Conditions.
	 * @return array<string,mixed>
	 */
	private function condition_params( array $conditions ): array {
		$params = array();
		foreach ( $conditions as $condition ) {
			$params = array_merge( $params, $condition['params'] );
		}

		return $params;
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
		foreach ( array( 'created_at', 'occurred_at', 'attempted_at', 'locked_at', 'expires_at', 'updated_at' ) as $name ) {
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

	private function read_counter_from( SqliteConnection $connection, string $key ): int {
		$value = $connection->value(
			'SELECT ' . $this->identifier( $this->counter_value_column() ) . ' FROM ' . $this->identifier( $this->definition->name ) . ' WHERE ' . $this->identifier( $this->counter_key_column() ) . ' = :counter_key LIMIT 1',
			array( ':counter_key' => $key )
		);

		return is_numeric( $value ) ? (int) $value : 0;
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

	/**
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	private function parameters( array $data ): array {
		$params = array();
		foreach ( $data as $column => $value ) {
			$params[ ':' . $column ] = $value;
		}

		return $params;
	}

	private function exists(): bool {
		return $this->installer->database_exists( $this->module, $this->definition );
	}

	private function identifier( string $identifier ): string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}
}
