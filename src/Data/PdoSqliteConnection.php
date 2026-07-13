<?php

/**
 * PDO-backed SQLite connection adapter.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use PDO;

final class PdoSqliteConnection implements SqliteConnection {
	public function __construct(
		private readonly PDO $pdo,
	) {
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function begin_transaction(): void {
		$this->pdo->exec( 'BEGIN IMMEDIATE' );
	}

	public function commit(): void {
		$this->pdo->commit();
	}

	public function roll_back(): void {
		$this->pdo->rollBack();
	}

	public function in_transaction(): bool {
		return $this->pdo->inTransaction();
	}

	public function exec( string $sql ): void {
		$this->pdo->exec( $sql );
	}

	public function execute( string $sql, array $params = array() ): int {
		$statement = $this->pdo->prepare( $sql );
		$statement->execute( $params );

		return $statement->rowCount();
	}

	public function rows( string $sql, array $params = array() ): array {
		$statement = $this->pdo->prepare( $sql );
		$statement->execute( $params );

		$rows       = $statement->fetchAll( PDO::FETCH_ASSOC );
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$normalized[] = $this->string_keyed_array( $row );
			}
		}

		return $normalized;
	}

	public function row( string $sql, array $params = array() ): ?array {
		$statement = $this->pdo->prepare( $sql );
		$statement->execute( $params );
		$row = $statement->fetch( PDO::FETCH_ASSOC );

		return is_array( $row ) ? $this->string_keyed_array( $row ) : null;
	}

	public function value( string $sql, array $params = array() ): mixed {
		$statement = $this->pdo->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchColumn();
	}

	public function last_insert_id(): int {
		return (int) $this->pdo->lastInsertId();
	}

	public function is_locked_exception( \Throwable $throwable ): bool {
		return str_contains( strtolower( $throwable->getMessage() ), 'database is locked' );
	}

	public function native(): object {
		return $this->pdo;
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
