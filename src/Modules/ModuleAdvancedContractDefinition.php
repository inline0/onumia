<?php

/**
 * Parsed advanced module contract files.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleAdvancedContractDefinition {
	/**
	 * @param array<string,ModuleTableDefinition> $tables Tables keyed by logical name.
	 * @param ModulePublicRouteDefinition[]      $public_routes Public routes.
	 * @param ModuleJobDefinition[]              $jobs Jobs.
	 * @param array<string,ModuleSecretDefinition> $secrets Secrets keyed by logical name.
	 */
	public function __construct(
		private readonly array $tables = array(),
		private readonly array $public_routes = array(),
		private readonly array $jobs = array(),
		private readonly array $secrets = array(),
	) {}

	/**
	 * @return array<string,ModuleTableDefinition>
	 */
	public function tables(): array {
		return $this->tables;
	}

	public function table( string $name ): ?ModuleTableDefinition {
		return $this->tables[ $name ] ?? null;
	}

	/**
	 * @return ModulePublicRouteDefinition[]
	 */
	public function public_routes(): array {
		return $this->public_routes;
	}

	/**
	 * @return ModuleJobDefinition[]
	 */
	public function jobs(): array {
		return $this->jobs;
	}

	/**
	 * @return array<string,ModuleSecretDefinition>
	 */
	public function secrets(): array {
		return $this->secrets;
	}

	public function merge( self $other ): self {
		return new self(
			array_merge( $this->tables, $other->tables ),
			array_merge( $this->public_routes, $other->public_routes ),
			array_merge( $this->jobs, $other->jobs ),
			array_merge( $this->secrets, $other->secrets )
		);
	}
}
