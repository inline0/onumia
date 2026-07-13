<?php

/**
 * Loads component.json groups by convention.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Component;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Core\Errors;
use Onumia\Schema\SchemaValidator;
use Onumia\Support\JsonFile;
use SplFileInfo;

final class ComponentLoader {
	public function __construct(
		private readonly SchemaValidator $validator = new SchemaValidator(),
	) {}

	/**
	 * @param string[] $roots Component roots.
	 * @return ComponentDefinition[]
	 */
	public function load_roots( array $roots ): array {
		$components = array();
		$loaded     = array();
		foreach ( $roots as $root ) {
			foreach ( $this->load_root( $root ) as $component ) {
				if ( isset( $loaded[ $component->name() ] ) ) {
					throw Errors::invariant( "Duplicate component {$component->name()} found in {$loaded[ $component->name() ]} and {$component->file()}." );
				}

				$loaded[ $component->name() ] = $component->file();
				$components[]                 = $component;
			}
		}

		usort( $components, static fn( ComponentDefinition $a, ComponentDefinition $b ): int => $a->name() <=> $b->name() );
		return $components;
	}

	/**
	 * @return ComponentDefinition[]
	 */
	public function load_root( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$components = array();
		foreach ( $this->component_files( $root ) as $file ) {
			$components[] = $this->load_file( $file );
		}

		usort( $components, static fn( ComponentDefinition $a, ComponentDefinition $b ): int => $a->name() <=> $b->name() );
		return $components;
	}

	public function load_file( string $file ): ComponentDefinition {
		$data = JsonFile::read_object( $file, 'Component' );
		$this->validator->validate_component_definition( $data, $file );
		$name        = $this->string_value( $data, 'name', $file );
		$label       = $this->string_value( $data, 'label', $file );
		$description = is_string( $data['description'] ?? null ) ? $data['description'] : $label;

		return new ComponentDefinition(
			$file,
			$name,
			$label,
			$description,
			$this->object_value( $data, 'component', $file )
		);
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private function string_value( array $data, string $key, string $file ): string {
		$value = $data[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			throw Errors::invariant( "Component {$file} {$key} must be a string." );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	private function object_value( array $data, string $key, string $file ): array {
		$value = $data[ $key ] ?? null;
		if ( ! is_array( $value ) ) {
			throw Errors::invariant( "Component {$file} {$key} must be an object." );
		}

		return $this->string_keyed_array( $value, "Component {$file} {$key}" );
	}

	/**
	 * @param array<mixed,mixed> $value Value.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value, string $label ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw Errors::invariant( "{$label} must use string keys." );
			}

			$result[ $key ] = $item;
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function component_files( string $root ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry instanceof SplFileInfo || ! $entry->isFile() || 'component.json' !== $entry->getFilename() ) {
				continue;
			}

			$files[] = $entry->getPathname();
		}

		sort( $files );
		return $files;
	}
}
