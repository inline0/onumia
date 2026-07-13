<?php

/**
 * Parsed module entry collection contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleEntryDefinition {
	/**
	 * @param array<string,ModuleEntryFieldDefinition>   $fields Fields keyed by field path.
	 * @param array<string,ModuleEntrySectionDefinition> $sections Sections keyed by section name.
	 * @param ModuleRelatedEntryDefinition[]             $related_entries Related entry sections.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $singular,
		public readonly string $plural,
		public readonly string $key,
		public readonly string $storage,
		public readonly ?string $setting = null,
		public readonly ?string $source = null,
		public readonly ?string $table = null,
		public readonly ?string $create_action = null,
		public readonly ?string $update_action = null,
		public readonly ?string $delete_action = null,
		public readonly bool $close_on_success = true,
		public readonly string $destructive_mode = 'delete',
		public readonly array $fields = array(),
		public readonly array $sections = array(),
		public readonly array $related_entries = array(),
	) {}

	public function field( string $name ): ?ModuleEntryFieldDefinition {
		return $this->fields[ $name ] ?? null;
	}

	public function has_field( string $name ): bool {
		return isset( $this->fields[ $name ] );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$data = array(
			'name'            => $this->name,
			'singular'        => $this->singular,
			'plural'          => $this->plural,
			'key'             => $this->key,
			'storage'         => $this->storage,
			'closeOnSuccess'  => $this->close_on_success,
			'destructiveMode' => $this->destructive_mode,
			'fields'          => array_map(
				static fn( ModuleEntryFieldDefinition $field ): array => $field->to_array(),
				array_values( $this->sorted_fields() )
			),
			'sections'        => array_map(
				static fn( ModuleEntrySectionDefinition $section ): array => $section->to_array(),
				array_values( $this->sorted_sections() )
			),
			'relatedEntries'  => array_map(
				static fn( ModuleRelatedEntryDefinition $related ): array => $related->to_array(),
				$this->related_entries
			),
		);

		foreach (
			array(
				'setting'      => $this->setting,
				'source'       => $this->source,
				'table'        => $this->table,
				'createAction' => $this->create_action,
				'updateAction' => $this->update_action,
				'deleteAction' => $this->delete_action,
			) as $key => $value
		) {
			if ( null !== $value && '' !== $value ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * @return array<string,ModuleEntryFieldDefinition>
	 */
	private function sorted_fields(): array {
		$fields = $this->fields;
		uasort(
			$fields,
			static fn( ModuleEntryFieldDefinition $a, ModuleEntryFieldDefinition $b ): int => $a->order === $b->order ? $a->name <=> $b->name : $a->order <=> $b->order
		);

		return $fields;
	}

	/**
	 * @return array<string,ModuleEntrySectionDefinition>
	 */
	private function sorted_sections(): array {
		$sections = $this->sections;
		uasort(
			$sections,
			static fn( ModuleEntrySectionDefinition $a, ModuleEntrySectionDefinition $b ): int => $a->order === $b->order ? $a->name <=> $b->name : $a->order <=> $b->order
		);

		return $sections;
	}
}
