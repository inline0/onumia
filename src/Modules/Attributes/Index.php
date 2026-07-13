<?php

/**
 * Module database index attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares an index for a module-owned table.
 *
 * Use this attribute with `Table` classes in `tables.php` when a module needs
 * faster lookup, uniqueness, or ordered access across one or more columns.
 * Index declarations are consumed by the table installer.
 *
 * The indexed column names must match declared `Column` names. Changing an
 * index changes the table schema and should be planned as a migration.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 160
 */
#[Attribute( Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE )]
final readonly class Index {
	/**
	 * @param string[] $columns Indexed columns.
	 */
	public function __construct(
		public string $name,
		public array $columns,
		public bool $unique = false,
	) {}
}
