<?php

/**
 * Resolves shared development module structures.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class SharedStructureFiles {
	public const UI_LAB_MODULE = 'onumia/dev/ui-lab';

	public static function is_optional_module( string $module_name ): bool {
		return self::UI_LAB_MODULE === $module_name;
	}

	public static function for_module( string $module_name, string $module_directory ): ?string {
		if ( self::UI_LAB_MODULE !== $module_name ) {
			return null;
		}

		foreach ( self::candidate_files_for_module( $module_name, $module_directory ) as $file ) {
			if ( is_file( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	public static function expected_for_module( string $module_name, string $module_directory ): ?string {
		return self::candidate_files_for_module( $module_name, $module_directory )[0] ?? null;
	}

	private static function shared_ui_root(): ?string {
		if ( defined( 'ONUMIA_SHARED_UI_ROOT' ) ) {
			$value = constant( 'ONUMIA_SHARED_UI_ROOT' );
			if ( is_string( $value ) && '' !== $value ) {
				return rtrim( $value, '/\\' );
			}
		}

		$value = getenv( 'ONUMIA_SHARED_UI_ROOT' );
		if ( is_string( $value ) && '' !== $value ) {
			return rtrim( $value, '/\\' );
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private static function candidate_files_for_module( string $module_name, string $module_directory ): array {
		if ( self::UI_LAB_MODULE !== $module_name ) {
			return array();
		}

		$files          = array();
		$shared_ui_root = self::shared_ui_root();
		if ( null !== $shared_ui_root ) {
			$files[] = $shared_ui_root . '/src/apps/onumia/ui-lab/structure.json';
		}

		$module_directory   = rtrim( $module_directory, '/\\' );
		$module_directories = array( $module_directory );
		$resolved_directory = realpath( $module_directory );
		if ( is_string( $resolved_directory ) && $resolved_directory !== $module_directory ) {
			array_unshift( $module_directories, $resolved_directory );
		}

		foreach ( $module_directories as $candidate_directory ) {
			$plugin_root      = dirname( $candidate_directory, 3 );
			$new_workspace    = dirname( $plugin_root, 2 );
			$legacy_workspace = dirname( $plugin_root, 4 );
			$files[]          = $new_workspace . '/packages/ui/src/apps/onumia/ui-lab/structure.json';
			$files[]          = $legacy_workspace . '/packages/ui/src/apps/onumia/ui-lab/structure.json';
			$files[]          = dirname( $plugin_root ) . '/onumia-shared-ui/src/apps/onumia/ui-lab/structure.json';
		}

		return array_values( array_unique( $files ) );
	}
}
