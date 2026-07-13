<?php

/**
 * Module database table attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares a module-owned data table.
 *
 * Use this attribute in `tables.php` when a module needs operational storage
 * outside theme settings. Tables use automatic file-based storage by default,
 * or MySQL/SQLite when a module explicitly declares that driver.
 *
 * Table names, retention bounds, row caps, and drivers are parsed as storage
 * contracts. Column and index attributes complete the table schema.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 250
 */
#[Attribute( Attribute::TARGET_CLASS )]
final readonly class Table {
	public function __construct(
		public string $name,
		public int $version = 1,
		public ?string $label = null,
		public ?int $row_cap = null,
		public ?int $retention_days = null,
		public string $driver = 'auto',
	) {}
}
