<?php

/**
 * Parsed related entry section contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleRelatedEntryDefinition {
	public function __construct(
		public readonly string $name,
		public readonly string $entry,
		public readonly string $local_key,
		public readonly string $foreign_key,
		public readonly ?string $label = null,
		public readonly string $mode = 'manage',
		public readonly int $order = 0,
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$data = array(
			'name'       => $this->name,
			'entry'      => $this->entry,
			'localKey'   => $this->local_key,
			'foreignKey' => $this->foreign_key,
			'mode'       => $this->mode,
			'order'      => $this->order,
		);

		if ( null !== $this->label && '' !== $this->label ) {
			$data['label'] = $this->label;
		}

		return $data;
	}
}
