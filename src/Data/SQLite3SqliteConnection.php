<?php

/**
 * Native SQLite3-backed connection adapter.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

final class SQLite3SqliteConnection implements SqliteConnection {
	private bool $transaction = false;

	public function __construct(
		private readonly SQLite3 $sqlite,
	) {
		$this->sqlite->enableExceptions( true );
	}

	public function begin_transaction(): void {
		$this->sqlite->exec( 'BEGIN IMMEDIATE' );
		$this->transaction = true;
	}

	public function commit(): void {
		$this->sqlite->exec( 'COMMIT' );
		$this->transaction = false;
	}

	public function roll_back(): void {
		$this->sqlite->exec( 'ROLLBACK' );
		$this->transaction = false;
	}

	public function in_transaction(): bool {
		return $this->transaction;
	}

	public function exec( string $sql ): void {
		$this->sqlite->exec( $sql );
	}

	public function execute( string $sql, array $params = array() ): int {
		$result = $this->statement( $sql, $params )->execute();
		if ( $result instanceof SQLite3Result ) {
			$result->finalize();
		}

		return max( 0, $this->sqlite->changes() );
	}

	public function rows( string $sql, array $params = array() ): array {
		$result = $this->statement( $sql, $params )->execute();
		// @codeCoverageIgnoreStart
		if ( ! $result instanceof SQLite3Result ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$rows = array();
		while ( is_array( $row = $result->fetchArray( SQLITE3_ASSOC ) ) ) {
			$rows[] = $this->string_keyed_array( $row );
		}

		$result->finalize();
		return $rows;
	}

	public function row( string $sql, array $params = array() ): ?array {
		$rows = $this->rows( $sql, $params );

		return $rows[0] ?? null;
	}

	public function value( string $sql, array $params = array() ): mixed {
		$row = $this->row( $sql, $params );
		if ( null === $row ) {
			return false;
		}

		return reset( $row );
	}

	public function last_insert_id(): int {
		return $this->sqlite->lastInsertRowID();
	}

	public function is_locked_exception( \Throwable $throwable ): bool {
		$message = strtolower( $throwable->getMessage() );

		return str_contains( $message, 'database is locked' ) || str_contains( $message, 'database table is locked' );
	}

	public function native(): object {
		return $this->sqlite;
	}

	/**
	 * @param array<string,mixed> $params Parameters.
	 */
	private function statement( string $sql, array $params ): SQLite3Stmt {
		$statement = $this->sqlite->prepare( $sql );
		// @codeCoverageIgnoreStart
		if ( false === $statement ) {
			throw new \RuntimeException( 'Could not prepare SQLite statement.' );
		}
		// @codeCoverageIgnoreEnd

		foreach ( $params as $key => $value ) {
			$statement->bindValue( $key, $value, $this->parameter_type( $value ) );
		}

		return $statement;
	}

	private function parameter_type( mixed $value ): int {
		return match ( true ) {
			is_int( $value ), is_bool( $value ) => SQLITE3_INTEGER,
			is_float( $value ) => SQLITE3_FLOAT,
			null === $value => SQLITE3_NULL,
			default => SQLITE3_TEXT,
		};
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
