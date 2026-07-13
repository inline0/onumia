<?php

/**
 * JSON file helpers.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

final class JsonFile {
	/**
	 * @return array<string,mixed>
	 */
	public static function read_object( string $file, string $label ): array {
		if ( ! is_file( $file ) ) {
			throw new \RuntimeException( "{$label} file is missing: {$file}." );
		}

		$contents = file_get_contents( $file );
		$data     = json_decode( false === $contents ? '' : $contents, true );
		if ( ! is_array( $data ) || ! self::is_object_array( $data ) ) {
			throw new \RuntimeException( "{$label} file is not a JSON object: {$file}." );
		}

		return self::string_keyed_array( $data, $label, $file );
	}

	/**
	 * @param array<mixed,mixed> $value Value.
	 */
	public static function is_object_array( array $value ): bool {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * @param array<mixed,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value, string $label, string $file ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw new \RuntimeException( "{$label} file contains non-string object keys: {$file}." );
			}

			$result[ $key ] = $item;
		}

		return $result;
	}
}
