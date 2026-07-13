<?php

/**
 * Module registry.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleRegistry {
	/** @var array<string,ModuleDefinition> */
	private array $modules = array();

	/**
	 * @param ModuleDefinition[] $modules Modules.
	 */
	public function __construct( array $modules = array() ) {
		foreach ( $modules as $module ) {
			$this->register( $module );
		}
	}

	public function register( ModuleDefinition $module ): void {
		if ( isset( $this->modules[ $module->name() ] ) ) {
			throw Errors::invariant( "Duplicate module {$module->name()}." );
		}

		$this->modules[ $module->name() ] = $module;
	}

	public function get( string $name ): ?ModuleDefinition {
		return $this->modules[ $name ] ?? null;
	}

	/**
	 * @return ModuleDefinition[]
	 */
	public function all(): array {
		return array_values( $this->modules );
	}
}
