<?php

/**
 * Resolves SQLite data file paths.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleTableDefinition;
use Onumia\PublicApi\Filters;

final class SqlitePathResolver {
	public function __construct(
		private readonly ?string $fixed_database_path = null,
		private readonly ?string $fixed_base_directory = null,
	) {}

	public function database_path( ModuleDefinition $module, ModuleTableDefinition $table ): string {
		if ( null !== $this->fixed_database_path ) {
			return $this->fixed_database_path;
		}

		return $this->data_directory( $module ) . DIRECTORY_SEPARATOR . $this->sanitize_segment( $table->name ) . '.db';
	}

	public function marker_path(): string {
		return $this->base_directory() . DIRECTORY_SEPARATOR . 'storage.json';
	}

	public function data_directory( ?ModuleDefinition $module = null ): string {
		$base = $this->base_directory();
		if ( null === $module ) {
			return $base;
		}

		return $base . DIRECTORY_SEPARATOR . $this->module_directory( $module );
	}

	public function base_directory(): string {
		if ( null !== $this->fixed_base_directory ) {
			return rtrim( $this->fixed_base_directory, '/\\' );
		}

		$upload_dir = function_exists( 'wp_upload_dir' ) ? \wp_upload_dir() : array();
		$basedir    = is_string( $upload_dir['basedir'] ?? null ) && '' !== $upload_dir['basedir'] ? $upload_dir['basedir'] : sys_get_temp_dir();
		$directory  = rtrim( $basedir, '/\\' ) . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . 'data';

		$filtered = Filters::sqlite_data_directory( $directory );
		if ( '' !== $filtered ) {
			$directory = $filtered;
		}

		return rtrim( $directory, '/\\' );
	}

	public function ensure_directory( ?ModuleDefinition $module = null ): string {
		$directory = $this->data_directory( $module );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
			throw \Onumia\Core\Errors::invariant( "Could not create Onumia data directory {$directory}." );
		}

		$this->harden_directory( $this->base_directory() );
		if ( null !== $module ) {
			$this->harden_directory( $directory );
		}

		return $directory;
	}

	public function harden_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = $directory . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	private function module_directory( ModuleDefinition $module ): string {
		$name = $module->name();
		if ( str_starts_with( $name, 'onumia/' ) ) {
			$name = substr( $name, strlen( 'onumia/' ) );
		}

		$segments = array_values( array_filter( explode( '/', $name ), static fn( string $segment ): bool => '' !== $segment ) );
		$segments = array_map( fn( string $segment ): string => $this->sanitize_segment( $segment ), $segments );

		return implode( DIRECTORY_SEPARATOR, $segments );
	}

	private function sanitize_segment( string $segment ): string {
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $segment );
		$sanitized = trim( is_string( $sanitized ) ? $sanitized : '', '-' );

		return '' === $sanitized ? 'table' : strtolower( $sanitized );
	}
}
