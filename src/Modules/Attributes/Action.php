<?php

/**
 * Module action attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\ActionIntent;
use Onumia\Modules\Contracts\Surface;

/**
 * Declares a callable module action.
 *
 * Use this attribute on module methods that should be exposed through the
 * Onumia action dispatcher. Actions are suitable for mutations, background
 * work triggers, sync operations, or other explicit user-initiated commands.
 *
 * The parser reads this metadata without executing the method. Runtime
 * permission checks use the declared capability and surface before dispatching
 * the action.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 100
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class Action {
	public function __construct(
		public ?string $name = null,
		public Surface|string $surface = Surface::Backend,
		public ?string $capability = null,
		public ActionIntent|string $intent = ActionIntent::Custom,
	) {}
}
