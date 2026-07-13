<?php

/**
 * Resolves the storage engine for automatic module tables.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Core\Errors;
use Onumia\PublicApi\Filters;

final class ModuleStorageResolver {
	public function __construct(
		private readonly SqlitePathResolver $paths = new SqlitePathResolver(),
		private readonly SqliteSupport $support = new SqliteSupport(),
	) {}

	public function resolve( string $requested = 'auto' ): StorageResolution {
		$requested = strtolower( trim( $requested ) );
		if ( '' === $requested ) {
			$requested = 'auto';
		}

		if ( ! in_array( $requested, array( 'auto', 'mysql', 'sqlite' ), true ) ) {
			throw Errors::invariant( "Unsupported Onumia storage driver {$requested}." );
		}

		$forced = $this->forced_driver();
		if ( 'auto' === $requested && null !== $forced ) {
			if ( ! in_array( $forced, array( 'auto', 'mysql', 'sqlite' ), true ) ) {
				throw Errors::invariant( "Unsupported Onumia storage driver override {$forced}." );
			}

			$requested = $forced;
		}

		if ( 'sqlite' === $requested ) {
			return $this->sqlite_resolution( null !== $forced && 'auto' !== $forced );
		}

		return new StorageResolution(
			StorageResolution::ENGINE_MYSQL,
			'MySQL module storage is the default.',
			null !== $forced && 'auto' !== $forced
		);
	}

	private function sqlite_resolution( bool $forced ): StorageResolution {
		$marker = $this->read_marker();
		$engine = is_string( $marker['engine'] ?? null ) ? $marker['engine'] : null;

		if ( StorageResolution::ENGINE_MYSQL === $engine ) {
			return new StorageResolution(
				StorageResolution::ENGINE_MYSQL,
				'SQLite storage previously resolved to MySQL and remains pinned there.',
				$forced,
				$engine
			);
		}

		if ( $this->is_sqlite_engine( $engine ) ) {
			$sqlite = $this->best_sqlite_engine();
			if ( null !== $sqlite ) {
				$resolution = new StorageResolution( $sqlite, 'Existing SQLite storage marker is usable.', $forced, $engine );
				if ( $sqlite !== $engine ) {
					$this->write_marker( $resolution );
				}

				return $resolution;
			}

			$resolution = new StorageResolution(
				StorageResolution::ENGINE_MYSQL,
				'SQLite storage is requested but no SQLite PHP interface is available; using MySQL.',
				$forced,
				$engine
			);
			$this->write_marker( $resolution );
			return $resolution;
		}

		$sqlite = $this->best_sqlite_engine();
		$resolution = null === $sqlite
			? new StorageResolution( StorageResolution::ENGINE_MYSQL, 'SQLite storage is requested but no SQLite PHP interface is available; using MySQL.', $forced, $engine )
			: new StorageResolution( $sqlite, 'SQLite PHP interface is available.', $forced, $engine );
		$this->write_marker( $resolution );

		return $resolution;
	}

	public function best_sqlite_engine(): ?string {
		if ( $this->support->pdo_sqlite_available() ) {
			return StorageResolution::ENGINE_SQLITE_PDO;
		}

		// @codeCoverageIgnoreStart
		if ( $this->support->sqlite3_available() ) {
			return StorageResolution::ENGINE_SQLITE3;
		}
		// @codeCoverageIgnoreEnd

		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function marker(): array {
		return $this->read_marker();
	}

	private function forced_driver(): ?string {
		$value = null;
		if ( defined( 'ONUMIA_STORAGE_DRIVER' ) && is_string( \constant( 'ONUMIA_STORAGE_DRIVER' ) ) ) {
			$value = \constant( 'ONUMIA_STORAGE_DRIVER' );
		}

		$value = Filters::storage_driver( is_string( $value ) ? $value : '' );
		$value = strtolower( trim( $value ) );

		return '' === $value ? null : $value;
	}

	private function is_sqlite_engine( ?string $engine ): bool {
		return in_array( $engine, array( StorageResolution::ENGINE_SQLITE_PDO, StorageResolution::ENGINE_SQLITE3, 'sqlite' ), true );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_marker(): array {
		$path = $this->paths->marker_path();
		if ( ! is_file( $path ) ) {
			return array();
		}

		$contents = file_get_contents( $path );
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return array();
		}

		$decoded = json_decode( $contents, true );
		return is_array( $decoded ) ? $this->string_keyed_array( $decoded ) : array();
	}

	private function write_marker( StorageResolution $resolution ): void {
		$directory = $this->paths->ensure_directory();
		$path      = $this->paths->marker_path();
		$payload   = array(
			'engine'      => $resolution->engine,
			'reason'      => $resolution->reason,
			'forced'      => $resolution->forced,
			'resolved_at' => function_exists( 'current_time' ) ? \current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		$encoded   = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		// @codeCoverageIgnoreStart
		if ( ! is_string( $encoded ) ) {
			throw Errors::invariant( 'Could not encode Onumia storage marker.' );
		}
		// @codeCoverageIgnoreEnd

		$temp = tempnam( $directory, 'storage-' );
		// @codeCoverageIgnoreStart
		if ( ! is_string( $temp ) || false === file_put_contents( $temp, $encoded . "\n" ) || ! @rename( $temp, $path ) ) {
			if ( is_string( $temp ) ) {
				@unlink( $temp );
			}
			throw Errors::invariant( "Could not write Onumia storage marker {$path}." );
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @param  array<array-key,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}
}
