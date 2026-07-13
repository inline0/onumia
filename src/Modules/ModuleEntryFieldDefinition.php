<?php

/**
 * Parsed module entry field contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleEntryFieldDefinition {
	/**
	 * @param list<mixed>                    $allowed Allowed values.
	 * @param list<array<string,mixed>>      $options Static renderer options.
	 * @param array<string,mixed>|null       $options_source Source-backed option request.
	 * @param array<string,mixed>|null       $visible_when Visibility condition.
	 * @param array<string,mixed>            $props Renderer props.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly ?string $label = null,
		public readonly mixed $default = null,
		public readonly array $allowed = array(),
		public readonly int|float|null $min = null,
		public readonly int|float|null $max = null,
		public readonly ?string $format = null,
		public readonly bool $required = false,
		public readonly bool $primary = false,
		public readonly bool $list = false,
		public readonly bool $filter = false,
		public readonly ?string $filter_type = null,
		public readonly array $options = array(),
		public readonly ?array $options_source = null,
		public readonly ?string $section = null,
		public readonly bool $create = true,
		public readonly bool $update = true,
		public readonly bool $read_only = false,
		public readonly int $order = 0,
		public readonly ?array $visible_when = null,
		public readonly array $props = array(),
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$data = array(
			'name'     => $this->name,
			'type'     => $this->type,
			'required' => $this->required,
			'primary'  => $this->primary,
			'list'     => $this->list,
			'filter'   => $this->filter,
			'create'   => $this->create,
			'update'   => $this->update,
			'readOnly' => $this->read_only,
			'order'    => $this->order,
		);

		foreach (
			array(
				'label'         => $this->label,
				'default'       => $this->default,
				'allowed'       => $this->allowed,
				'min'           => $this->min,
				'max'           => $this->max,
				'format'        => $this->format,
				'filterType'    => $this->filter_type,
				'options'       => $this->options,
				'optionsSource' => $this->options_source,
				'section'       => $this->section,
				'visibleWhen'   => $this->visible_when,
				'props'         => $this->props,
			) as $key => $value
		) {
			if ( null === $value || array() === $value ) {
				continue;
			}
			$data[ $key ] = $value;
		}

		return $data;
	}
}
