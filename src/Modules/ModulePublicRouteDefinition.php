<?php

/**
 * Module public route contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModulePublicRouteDefinition {
	/**
	 * @param array<string,array<string,mixed>> $inputs Input definitions.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $method,
		public readonly string $auth,
		public readonly int $rate_limit,
		public readonly string $handler,
		public readonly int $required_parameters,
		public readonly int $total_parameters,
		public readonly array $inputs = array(),
	) {}
}
