<?php

/**
 * WordPress action hook attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Registers a module method on a WordPress action hook.
 *
 * Use this attribute when active module behavior needs to respond to a native
 * WordPress action. It lets module authors declare hook wiring alongside the
 * method instead of registering hooks manually in `boot()`.
 *
 * Onumia only registers the hook when the module is active. The callback runs
 * under WordPress hook timing and should follow that hook's constraints.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 260
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class WpAction {
	public function __construct(
		public string $hook,
		public int $priority = 10,
		public ?int $accepted_args = null,
	) {}
}
