<?php

/**
 * Resolved module storage engine state.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

final readonly class StorageResolution {
	public const ENGINE_MYSQL      = 'mysql';
	public const ENGINE_SQLITE_PDO = 'sqlite-pdo';
	public const ENGINE_SQLITE3    = 'sqlite3';

	public function __construct(
		public string $engine,
		public string $reason,
		public bool $forced = false,
		public ?string $marker_engine = null,
	) {}

	public function uses_sqlite(): bool {
		return in_array( $this->engine, array( self::ENGINE_SQLITE_PDO, self::ENGINE_SQLITE3 ), true );
	}

	public function uses_mysql(): bool {
		return self::ENGINE_MYSQL === $this->engine;
	}

	public function sqlite_interface(): ?string {
		return match ( $this->engine ) {
			self::ENGINE_SQLITE3 => 'sqlite3',
			self::ENGINE_SQLITE_PDO => 'pdo',
			default => null,
		};
	}
}
