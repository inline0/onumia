<?php

/**
 * Custom Onumia entity name validation.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use Onumia\Core\Errors;

final class CustomEntityName {
	private const PATTERN = '#^custom/[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$#';

	public static function normalize( mixed $name ): ?string {
		if ( ! is_string( $name ) ) {
			return null;
		}

		$name = trim( $name );
		return self::is_valid( $name ) ? $name : null;
	}

	public static function assert_valid( string $name, string $type ): void {
		if ( trim( $name ) !== $name || ! self::is_valid( $name ) ) {
			throw Errors::invariant( "Custom {$type} name must start with custom/ and stay inside the custom {$type} root." );
		}
	}

	private static function is_valid( string $name ): bool {
		return 1 === preg_match( self::PATTERN, $name );
	}
}
