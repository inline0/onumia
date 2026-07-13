<?php

/**
 * Module WordPress hook contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleHook {
	public function __construct(
		public readonly string $type,
		public readonly string $hook,
		public readonly string $method,
		public readonly int $priority,
		public readonly int $accepted_args,
	) {}

	public function is_action(): bool {
		return 'action' === $this->type;
	}
}
