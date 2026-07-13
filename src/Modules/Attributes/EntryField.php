<?php

/**
 * Module entry field attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\SettingType;

/**
 * Declares a field within an entry collection.
 *
 * Use this attribute to describe entry form fields, list columns, filters, and
 * create/update behavior for an `Entries` collection. It keeps the field schema
 * close to the backend storage or data-source method that owns the entries.
 *
 * The parser uses this metadata to validate renderer bindings. Field names,
 * source-backed options, and visibility conditions must remain JSON-safe.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 140
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class EntryField {
	/**
	 * @param mixed[]               $allowed Allowed literal values.
	 * @param array<int,array<string,mixed>> $options Static renderer options.
	 * @param array<string,mixed>|null       $optionsSource Source-backed option request.
	 * @param array<string,mixed>|null       $visible_when Visibility condition.
	 * @param array<string,mixed>            $props JSON-serializable renderer props.
	 */
	public function __construct(
		public string $name,
		public SettingType|string $type,
		public ?string $label = null,
		public mixed $default = null,
		public array $allowed = array(),
		public int|float|null $min = null,
		public int|float|null $max = null,
		public ?string $format = null,
		public bool $required = false,
		public bool $primary = false,
		public bool $list = false,
		public bool $filter = false,
		public ?string $filter_type = null,
		public array $options = array(),
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public attribute named argument intentionally mirrors JSON optionsSource.
		public ?array $optionsSource = null,
		public ?string $section = null,
		public bool $create = true,
		public bool $update = true,
		public bool $read_only = false,
		public int $order = 0,
		public ?array $visible_when = null,
		public array $props = array(),
	) {}
}
