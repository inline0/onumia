<?php

/**
 * Database adapter contract for Onumia chats.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Chat;

interface ChatDatabase {
	public function prefix(): string;

	/**
	 * @param array<string,mixed> $data    Row data.
	 * @param list<string>        $formats Row formats.
	 */
	public function insert( string $table, array $data, array $formats ): int;

	/**
	 * @param array<string,mixed> $data          Row data.
	 * @param array<string,mixed> $where         Where data.
	 * @param list<string>        $formats       Row formats.
	 * @param list<string>        $where_formats Where formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): void;

	/**
	 * @param array<string,mixed> $where         Where data.
	 * @param list<string>        $where_formats Where formats.
	 */
	public function delete( string $table, array $where, array $where_formats ): void;

	public function prepare( string $query, mixed ...$args ): string;

	public function query( string $query ): int;

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query ): ?array;

	/**
	 * @return list<array<string,mixed>>
	 */
	public function get_results( string $query ): array;
}
