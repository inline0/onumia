<?php

/**
 * Module table column contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleColumnDefinition {
	/**
	 * @param list<mixed>               $allowed Allowed values.
	 * @param list<string>              $filter_operators Supported filter operators.
	 * @param array<string,mixed>       $props JSON-serializable renderer props.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $name,
		public readonly string $type,
		public readonly bool $nullable = false,
		public readonly mixed $default = null,
		public readonly int $length = 0,
		public readonly int $precision = 0,
		public readonly int $scale = 0,
		public readonly bool $unsigned = false,
		public readonly bool $auto_increment = false,
		public readonly bool $primary = false,
		public readonly string $label = '',
		public readonly array $allowed = array(),
		public readonly bool $table_list = false,
		public readonly bool $table_filter = false,
		public readonly ?string $filter_type = null,
		public readonly array $filter_operators = array(),
		public readonly array $props = array(),
		public readonly bool $entry_list = false,
		public readonly string $entry_field = '',
		public readonly ?string $entry_section = null,
		public readonly int $entry_order = 0,
		public readonly ?string $entry_path = null,
		public readonly bool $entry_create = true,
		public readonly bool $entry_update = true,
		public readonly bool $sortable = false,
		public readonly bool $searchable = false,
		public readonly ?bool $required = null,
	) {}
}
