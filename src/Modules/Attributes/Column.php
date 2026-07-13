<?php

/**
 * Module database column attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\ColumnType;

/**
 * Declares a module-owned table column.
 *
 * Use this attribute with `Table` classes in `tables.php` when a module needs
 * durable operational records, audit history, queues, or indexed lookup data.
 * The metadata drives installer SQL, REST table output, filters, entries, and
 * table renderers.
 *
 * The declaration is parsed statically. Column names and types become storage
 * contracts, so changing them should be treated as a table schema migration.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 110
 */
#[Attribute( Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE )]
final readonly class Column {
	/**
	 * @param list<mixed>         $allowed Allowed values.
	 * @param list<string>        $filter_operators Filter operators.
	 * @param array<string,mixed> $props Renderer metadata.
	 */
	public function __construct(
		public string $name,
		public ColumnType|string $type,
		public ?int $length = null,
		public ?int $precision = null,
		public ?int $scale = null,
		public bool $nullable = false,
		public mixed $default = null,
		public bool $primary = false,
		public bool $auto_increment = false,
		public bool $unsigned = false,
		public ?string $label = null,
		public array $allowed = array(),
		public bool $table_list = false,
		public bool $table_filter = false,
		public ?string $filter_type = null,
		public array $filter_operators = array(),
		public array $props = array(),
		public bool $entry_list = false,
		public ?string $entry_field = null,
		public ?string $entry_section = null,
		public int $entry_order = 0,
		public ?string $entry_path = null,
		public bool $entry_create = true,
		public bool $entry_update = true,
		public bool $sortable = false,
		public bool $searchable = false,
		public ?bool $required = null,
	) {}
}
