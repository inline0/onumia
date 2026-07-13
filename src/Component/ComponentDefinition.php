<?php

/**
 * Reusable Onumia component group.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Component;

final class ComponentDefinition {
	/**
	 * @param array<string,mixed> $component Component tree.
	 */
	public function __construct(
		private readonly string $file,
		private readonly string $name,
		private readonly string $label,
		private readonly string $description,
		private readonly array $component,
	) {}

	public function file(): string {
		return $this->file;
	}

	public function name(): string {
		return $this->name;
	}

	public function label(): string {
		return $this->label;
	}

	public function description(): string {
		return $this->description;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function component(): array {
		return $this->component;
	}
}
