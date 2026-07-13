<?php

/**
 * Module job contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleJobDefinition {
	public function __construct(
		public readonly string $name,
		public readonly string $schedule,
		public readonly bool $enabled,
		public readonly string $handler,
		public readonly bool $run_on_activation = false,
	) {}
}
