<?php

/**
 * Module secret contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleSecretDefinition {
	public function __construct(
		public readonly string $name,
		public readonly ?string $constant,
		public readonly string $label,
		public readonly bool $required,
	) {}
}
