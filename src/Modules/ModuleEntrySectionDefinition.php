<?php

/**
 * Parsed module entry section contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleEntrySectionDefinition {
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly ?string $description = null,
		public readonly int $order = 0,
		public readonly string $layout = 'auto',
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$data = array(
			'name'   => $this->name,
			'label'  => $this->label,
			'order'  => $this->order,
			'layout' => $this->layout,
		);

		if ( null !== $this->description && '' !== $this->description ) {
			$data['description'] = $this->description;
		}

		return $data;
	}
}
