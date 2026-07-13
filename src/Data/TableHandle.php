<?php

/**
 * Module table handle contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

interface TableHandle {
	/**
	 * @param array<string,mixed> $row Row data.
	 */
	public function insert( array $row ): int;

	/**
	 * @param array<string,mixed> $row Row data.
	 */
	public function update( int $id, array $row ): void;

	/**
	 * @param array<string,mixed> $where Equality filters.
	 * @return list<array<string,mixed>>
	 */
	public function recent( int $limit = 100, ?int $since_days = null, array $where = array() ): array;

	/**
	 * @param array<string,mixed> $where Equality filters.
	 */
	public function count( array $where = array() ): int;

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array;

	public function increment_counter( string $key, int $amount = 1 ): int;

	public function read_counter( string $key ): int;

	/**
	 * @param array<string,mixed> $where Equality filters.
	 */
	public function purge( ?int $before_days = null, array $where = array() ): int;

	public function purge_all(): int;

	/**
	 * @return list<array<string,mixed>>
	 */
	public function export_rows(): array;
}
