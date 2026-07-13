<?php

/**
 * Removes Onumia-owned database tables on full uninstall.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

final class ModuleTableUninstaller {
	public static function drop_all(): int {
		$removed_files = self::remove_sqlite_data_directory();
		$dropped       = self::drop_mysql_tables();

		return $dropped + $removed_files;
	}

	public static function remove_sqlite_data_directory( ?string $directory = null ): int {
		$directory ??= ( new SqlitePathResolver() )->base_directory();
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		return self::remove_directory( $directory );
	}

	private static function drop_mysql_tables(): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$prefix = is_string( $wpdb->prefix ?? null ) ? $wpdb->prefix : '';
		$like   = self::escape_like( $prefix . 'onumia_' ) . '%';
		if ( ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0;
		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		if ( ! is_array( $tables ) ) {
			return 0;
		}

		$dropped = 0;
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! self::is_onumia_table( $table, $prefix ) ) {
				continue;
			}

			$result = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			if ( false !== $result ) {
				++$dropped;
			}
		}

		return $dropped;
	}

	private static function remove_directory( string $directory ): int {
		$removed = 0;
		$entries = scandir( $directory );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$removed += self::remove_directory( $path );
				continue;
			}

			if ( is_file( $path ) && unlink( $path ) ) {
				++$removed;
			}
		}

		@rmdir( $directory );
		return $removed;
	}

	private static function escape_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	private static function is_onumia_table( string $table, string $prefix ): bool {
		return str_starts_with( $table, $prefix . 'onumia_' ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table );
	}
}
