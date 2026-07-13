<?php

/**
 * Module data source contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleDataSource {
	/**
	 * @param array<string,array<string,mixed>> $inputs Input definitions.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $method,
		public readonly string $surface,
		public readonly string $capability,
		public readonly int $required_parameters,
		public readonly int $total_parameters,
		public readonly array $inputs = array(),
		public readonly string $shape = 'options',
		public readonly string $pagination = 'client',
	) {}
}
