<?php

/**
 * WordPress database adapter for Onumia chats.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Chat;

use Onumia\Core\Errors;

final class WordPressChatDatabase implements ChatDatabase {
	public function prefix(): string {
		$prefix = $this->property( 'prefix' );
		if ( ! is_string( $prefix ) ) {
			throw Errors::invariant( 'WordPress database prefix is not available.' );
		}

		return $prefix;
	}

	/**
	 * @param array<string,mixed> $data    Row data.
	 * @param list<string>        $formats Row formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		$result = $this->call( 'insert', $table, $data, $formats );
		if ( false === $result ) {
			throw Errors::invariant( "Could not insert Onumia chat row into {$table}." );
		}

		$insert_id = $this->property( 'insert_id' );
		if ( ! is_int( $insert_id ) && ! is_numeric( $insert_id ) ) {
			throw Errors::invariant( 'WordPress database insert id is not available.' );
		}

		return (int) $insert_id;
	}

	/**
	 * @param array<string,mixed> $data          Row data.
	 * @param array<string,mixed> $where         Where data.
	 * @param list<string>        $formats       Row formats.
	 * @param list<string>        $where_formats Where formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): void {
		$result = $this->call( 'update', $table, $data, $where, $formats, $where_formats );
		if ( false === $result ) {
			throw Errors::invariant( "Could not update Onumia chat row in {$table}." );
		}
	}

	/**
	 * @param array<string,mixed> $where         Where data.
	 * @param list<string>        $where_formats Where formats.
	 */
	public function delete( string $table, array $where, array $where_formats ): void {
		$result = $this->call( 'delete', $table, $where, $where_formats );
		if ( false === $result ) {
			throw Errors::invariant( "Could not delete Onumia chat row from {$table}." );
		}
	}

	public function prepare( string $query, mixed ...$args ): string {
		$prepared = $this->call( 'prepare', $query, ...$args );
		if ( ! is_string( $prepared ) ) {
			throw Errors::invariant( 'WordPress database prepare did not return SQL.' );
		}

		return $prepared;
	}

	public function query( string $query ): int {
		$result = $this->call( 'query', $query );
		if ( false === $result ) {
			throw Errors::invariant( 'WordPress database query failed.' );
		}
		if ( ! is_int( $result ) && ! is_numeric( $result ) ) {
			throw Errors::invariant( 'WordPress database query result is invalid.' );
		}

		return (int) $result;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query ): ?array {
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
	public function get_results( string $query ): array {
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

	private function wpdb(): object {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			throw Errors::invariant( 'WordPress database is not available.' );
		}

		return $wpdb;
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
