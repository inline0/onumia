<?php

/**
 * WordPress filter hook attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Registers a module method on a WordPress filter hook.
 *
 * Use this attribute when active module behavior needs to transform a value
 * through a native WordPress filter. It keeps hook registration declarative and
 * scoped to the module contract.
 *
 * Onumia only registers the filter when the module is active. The callback
 * must return a value compatible with the WordPress filter being handled.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 270
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class WpFilter {
	public function __construct(
		public string $hook,
		public int $priority = 10,
		public ?int $accepted_args = null,
	) {}
}
