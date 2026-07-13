<?php

/**
 * Registers module-local PHP support classes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleLocalAutoloader {
	/** @var array<string,bool> */
	private static array $registered = array();

	public static function register( ModuleDefinition $module ): void {
		$class_name = $module->contract()->class_name();
		$namespace  = self::namespace_from_class( $class_name );
		$source_dir = $module->directory() . DIRECTORY_SEPARATOR . 'src';
		if ( '' === $namespace || ! is_dir( $source_dir ) ) {
			return;
		}

		$key = $namespace . '|' . $source_dir;
		if ( isset( self::$registered[ $key ] ) ) {
			return;
		}

		self::$registered[ $key ] = true;
		spl_autoload_register(
			static function ( string $class ) use ( $namespace, $source_dir ): void {
				$prefix = $namespace . '\\';
				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$file     = $source_dir . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	private static function namespace_from_class( string $class_name ): string {
		$position = strrpos( $class_name, '\\' );
		return false === $position ? '' : substr( $class_name, 0, $position );
	}
}
