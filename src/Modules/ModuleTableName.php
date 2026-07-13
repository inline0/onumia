<?php

/**
 * Site-scoped module table names.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleTableName {
	public function __construct(
		private readonly string $prefix = '',
	) {}

	public function for_module_table( ModuleDefinition $module, string $table ): string {
		$prefix = '' === $this->prefix ? $this->wpdb_prefix() : $this->prefix;
		$slug   = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $module->name() ) ?? $module->name() );
		$slug   = trim( $slug, '_' );
		if ( str_starts_with( $slug, 'onumia_' ) ) {
			$slug = substr( $slug, strlen( 'onumia_' ) );
		}
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

	public function schema_table(): string {
		$prefix = '' === $this->prefix ? $this->wpdb_prefix() : $this->prefix;
		return $prefix . 'onumia_module_schema';
	}

	private function wpdb_prefix(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) ? ( $wpdb->prefix ?? '' ) : '';
		return is_string( $prefix ) ? $prefix : '';
	}
}
