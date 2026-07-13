<?php

/**
 * Reusable component registry.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Component;

use Onumia\Core\Errors;

final class ComponentRegistry {
	/** @var array<string,ComponentDefinition> */
	private array $components = array();

	/**
	 * @param ComponentDefinition[] $components Components.
	 */
	public function __construct( array $components = array() ) {
		foreach ( $components as $component ) {
			$this->register( $component );
		}
	}

	/**
	 * @param string[] $roots Component roots.
	 */
	public static function from_roots( array $roots, ?ComponentLoader $loader = null ): self {
		$loader ??= new ComponentLoader();
		return new self( $loader->load_roots( $roots ) );
	}

	public function register( ComponentDefinition $component ): void {
		if ( isset( $this->components[ $component->name() ] ) ) {
			throw Errors::invariant( "Duplicate component {$component->name()}." );
		}

		$this->components[ $component->name() ] = $component;
	}

	public function has( string $name ): bool {
		return isset( $this->components[ $name ] );
	}

	public function get( string $name ): ?ComponentDefinition {
		return $this->components[ $name ] ?? null;
	}

	public function is_empty(): bool {
		return array() === $this->components;
	}

	/**
	 * @return string[]
	 */
	public function names(): array {
		return array_keys( $this->components );
	}
}
