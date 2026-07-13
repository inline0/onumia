<?php

/**
 * Module table index contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleIndexDefinition {
	/**
	 * @param string[] $columns Columns.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $name,
		public readonly array $columns,
		public readonly bool $unique = false,
	) {}
}
