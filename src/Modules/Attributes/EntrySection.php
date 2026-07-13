<?php

/**
 * Module entry form section attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Declares a section in an entry drawer or form.
 *
 * Use this attribute to group related `EntryField` declarations into a named
 * section for editing and review workflows. Sections provide labels,
 * descriptions, order, and layout hints for the generated form surface.
 *
 * Sections only affect renderer organization. They do not create storage,
 * validation, or capability rules on their own.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 150
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class EntrySection {
	public function __construct(
		public string $name,
		public string $label,
		public ?string $description = null,
		public int $order = 0,
		public string $layout = 'auto',
	) {}
}
