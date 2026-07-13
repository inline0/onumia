<?php

/**
 * Renames legacy MySQL module tables to the current physical naming scheme.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleTableNameMigration {
	public function __construct(
		private readonly ModuleTableName $table_names = new ModuleTableName(),
	) {}

	public function migrate( ModuleRegistry $registry ): void {
		foreach ( $registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->tables() as $table ) {
				if ( 'sqlite' === $table->driver ) {
					continue;
				}

				$this->migrate_table( $module, $table );
			}
		}
	}

	public function migrate_table( ModuleDefinition $module, ModuleTableDefinition $table ): void {
		$this->rename_table( $module, $table->name );
	}

	private function rename_table( ModuleDefinition $module, string $table ): void {
		$old_name = $this->legacy_module_table_name( $module, $table );
		$new_name = $this->table_names->for_module_table( $module, $table );

		if ( $old_name === $new_name || ! $this->table_exists( $old_name ) || $this->table_exists( $new_name ) ) {
			return;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}

		$query = $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $old_name, $new_name );
		if ( is_string( $query ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$wpdb->query( $query );
		}
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$result = is_string( $query ) ? $wpdb->get_var( $query ) : null;
		return is_string( $result ) && $table === $result;
	}

	private function legacy_module_table_name( ModuleDefinition $module, string $table ): string {
		$prefix = $this->wpdb_prefix();
		$slug   = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $module->name() ) ?? $module->name() );
		$slug   = trim( $slug, '_' );
		if ( '' === $slug ) {
			$slug = 'module';
		}

		$name = $prefix . 'onumia_' . $slug . '_' . $table;

		if ( strlen( $name ) <= 64 ) {
			return $name;
		}

		$hash = substr( sha1( $name ), 0, 8 );
		return substr( $name, 0, 55 ) . '_' . $hash;
	}

	private function wpdb_prefix(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) ? ( $wpdb->prefix ?? '' ) : '';
		return is_string( $prefix ) ? $prefix : '';
	}
}
