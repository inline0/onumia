<?php

/**
 * Module table contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleTableDefinition {
	/**
	 * @param ModuleColumnDefinition[] $columns Columns keyed by declaration order.
	 * @param ModuleIndexDefinition[]  $indexes Indexes keyed by declaration order.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly int $version,
		public readonly array $columns,
		public readonly array $indexes = array(),
		public readonly ?int $row_cap = null,
		public readonly ?int $retention_days = null,
		public readonly string $driver = 'auto',
	) {}
}
