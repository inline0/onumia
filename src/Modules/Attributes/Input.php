<?php

/**
 * Module callable input attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\SettingType;

/**
 * Declares a typed input for an action or data source.
 *
 * Use this attribute on callable module methods to describe expected request
 * parameters. Inputs provide the parser and dispatcher with type, default,
 * allowed-value, range, format, and required metadata.
 *
 * Runtime dispatch validates request values against this contract before the
 * target method receives them. Object inputs require matching `ObjectShape`
 * metadata.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 170
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class Input {
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
		public bool $required = false,
	) {}
}
