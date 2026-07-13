<?php

/**
 * Module setting attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\SettingType;

/**
 * Declares a typed setting owned by a module.
 *
 * Use this attribute on the module contract class for values saved in the
 * active theme's Onumia settings file. Settings are the persistent
 * configuration that activates and controls module behavior.
 *
 * The parser validates setting types, allowed values, bounds, and formats.
 * Runtime module helpers read the saved values through the `Module` base class.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 240
 */
#[Attribute( Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE )]
final readonly class Setting {
	/**
	 * @param mixed[] $allowed Allowed literal values.
	 */
	public function __construct(
		public string $name,
		public SettingType|string $type,
		public mixed $default = null,
		public array $allowed = array(),
		public int|float|null $min = null,
		public int|float|null $max = null,
		public ?string $format = null,
	) {}
}
