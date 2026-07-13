<?php

/**
 * Minimal SQLite connection contract used by module table storage.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

interface SqliteConnection {
	public function begin_transaction(): void;

	public function commit(): void;

	public function roll_back(): void;

	public function in_transaction(): bool;

	public function exec( string $sql ): void;

	/**
	 * @param array<string,mixed> $params Parameters.
	 */
	public function execute( string $sql, array $params = array() ): int;

	/**
	 * @param array<string,mixed> $params Parameters.
	 * @return list<array<string,mixed>>
	 */
	public function rows( string $sql, array $params = array() ): array;

	/**
	 * @param array<string,mixed> $params Parameters.
	 * @return array<string,mixed>|null
	 */
	public function row( string $sql, array $params = array() ): ?array;

	/**
	 * @param array<string,mixed> $params Parameters.
	 */
	public function value( string $sql, array $params = array() ): mixed;

	public function last_insert_id(): int;

	public function is_locked_exception( \Throwable $throwable ): bool;

	public function native(): object;
}
