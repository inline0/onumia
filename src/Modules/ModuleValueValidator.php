<?php

/**
 * Validates module setting and input values.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleValueValidator {
	private const FORMATS = array( 'email', 'url' );
	private const OBJECT_SHAPE_TYPES = array( 'boolean', 'string', 'integer', 'number', 'array', 'object', 'mixed' );

	/**
	 * @param array<string,array<string,mixed>> $definitions Field definitions.
	 * @param array<string,mixed>              $input       Input payload.
	 * @return array<string,mixed>
	 */
	public function normalize_input( array $definitions, array $input, string $label ): array {
		if ( array() === $definitions ) {
			return $input;
		}

		foreach ( $input as $key => $_value ) {
			if ( ! isset( $definitions[ $key ] ) ) {
				throw Errors::invariant( "{$label} contains unknown input {$key}." );
			}
		}

		$normalized = array();
		foreach ( $definitions as $key => $definition ) {
			if ( array_key_exists( $key, $input ) ) {
				$this->assert_value( $input[ $key ], $definition, "{$label} input {$key}" );
				$normalized[ $key ] = $input[ $key ];
				continue;
			}

			if ( array_key_exists( 'default', $definition ) ) {
				$normalized[ $key ] = $definition['default'];
				continue;
			}

			if ( true === ( $definition['required'] ?? false ) ) {
				throw Errors::invariant( "{$label} requires input {$key}." );
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 */
	public function assert_value( mixed $value, array $definition, string $label ): void {
		$type = $this->definition_type( $definition, $label );
		$this->assert_type( $value, $type, $label );
		if ( 'object' === $type && array_key_exists( 'shape', $definition ) && is_array( $value ) ) {
			$this->assert_object_shape( $value, $definition, $label );
		}
		$this->assert_allowed( $value, $definition, $label );
		$this->assert_range( $value, $definition, $type, $label );
		$this->assert_format( $value, $definition, $type, $label );
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 */
	public function definition_type( array $definition, string $label ): string {
		$type = $definition['type'] ?? null;
		if ( ! is_string( $type ) || '' === $type ) {
			throw Errors::invariant( "{$label} type must be a string." );
		}

		return $type;
	}

	public function empty_value( string $type ): mixed {
		return match ( $type ) {
			'boolean' => false,
			'string' => '',
			'integer' => 0,
			'number' => 0.0,
			'array', 'object' => array(),
			default => null,
		};
	}

	private function assert_type( mixed $value, string $type, string $label ): void {
		$valid = match ( $type ) {
			'boolean' => is_bool( $value ),
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'array' => is_array( $value ),
			'object' => is_array( $value ),
			default => false,
		};

		if ( ! $valid ) {
			throw Errors::invariant( "{$label} must be {$type}." );
		}
	}

	/**
	 * @param array<mixed>        $value      Value.
	 * @param array<string,mixed> $definition Definition.
	 */
	private function assert_object_shape( array $value, array $definition, string $label ): void {
		$shape = $definition['shape'];
		if ( ! is_array( $shape ) || array_is_list( $shape ) ) {
			throw Errors::invariant( "{$label} object shape is invalid." );
		}

		foreach ( $shape as $field => $field_type ) {
			if ( ! is_string( $field ) || ! is_string( $field_type ) || ! in_array( $field_type, self::OBJECT_SHAPE_TYPES, true ) ) {
				throw Errors::invariant( "{$label} object shape is invalid." );
			}
		}

		$wildcard_type = is_string( $shape['*'] ?? null ) ? $shape['*'] : null;
		foreach ( $value as $field => $field_value ) {
			if ( ! is_string( $field ) ) {
				throw Errors::invariant( "{$label} must be an object." );
			}

			$field_type = is_string( $shape[ $field ] ?? null ) ? $shape[ $field ] : $wildcard_type;
			if ( null === $field_type ) {
				throw Errors::invariant( "{$label} contains unknown object key {$field}." );
			}

			$this->assert_object_shape_field_type( $field_value, $field_type, "{$label}.{$field}" );
		}
	}

	private function assert_object_shape_field_type( mixed $value, string $type, string $label ): void {
		if ( 'mixed' === $type ) {
			return;
		}

		$this->assert_type( $value, $type, $label );
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 */
	private function assert_allowed( mixed $value, array $definition, string $label ): void {
		if ( ! array_key_exists( 'allowed', $definition ) ) {
			return;
		}

		$allowed = $definition['allowed'];
		if ( ! is_array( $allowed ) ) {
			throw Errors::invariant( "{$label} allowed values must be an array." );
		}

		if ( array() !== $allowed && ! in_array( $value, $allowed, true ) ) {
			throw Errors::invariant( "{$label} must be one of the allowed values." );
		}
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 */
	private function assert_range( mixed $value, array $definition, string $type, string $label ): void {
		if ( ! in_array( $type, array( 'integer', 'number' ), true ) ) {
			return;
		}

		$min = $definition['min'] ?? null;
		if ( ( is_int( $min ) || is_float( $min ) ) && $value < $min ) {
			throw Errors::invariant( "{$label} must be greater than or equal to {$min}." );
		}

		$max = $definition['max'] ?? null;
		if ( ( is_int( $max ) || is_float( $max ) ) && $value > $max ) {
			throw Errors::invariant( "{$label} must be less than or equal to {$max}." );
		}
	}

	/**
	 * @param array<string,mixed> $definition Definition.
	 */
	private function assert_format( mixed $value, array $definition, string $type, string $label ): void {
		$format = $definition['format'] ?? null;
		if ( null === $format ) {
			return;
		}

		if ( ! is_string( $format ) || ! in_array( $format, self::FORMATS, true ) ) {
			throw Errors::invariant( "{$label} format is invalid." );
		}

		if ( 'string' !== $type || ! is_string( $value ) || '' === $value ) {
			return;
		}

		$valid = match ( $format ) {
			'email' => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ),
			'url' => $this->is_url_like( $value ),
		};

		if ( ! $valid ) {
			throw Errors::invariant( "{$label} must be a valid {$format}." );
		}
	}

	private function is_url_like( string $value ): bool {
		if ( str_starts_with( $value, '/' ) ) {
			return true;
		}

		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true );
	}
}
