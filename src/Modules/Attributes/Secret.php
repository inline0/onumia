<?php

/**
 * Module secret attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares a runtime secret required by a module.
 *
 * Use this attribute when module behavior depends on a configured constant,
 * environment value, or secret field. It gives Onumia enough metadata to
 * surface missing requirements without storing raw secret values in JSON.
 *
 * Secrets describe availability requirements only. Module code remains
 * responsible for reading and using the actual secret value safely.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 230
 */
#[Attribute( Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE )]
final readonly class Secret {
	public function __construct(
		public string $name,
		public ?string $constant = null,
		public string $label = '',
		public bool $required = false,
	) {}
}
