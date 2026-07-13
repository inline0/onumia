<?php

/**
 * Structure shape checks for Onumia modules.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check\Structure;

use Onumia\Check\Finding;
use Onumia\Structure\StructureComponentTypes;
use Onumia\Structure\StructureDataSourceRegistry;

final class StructureLinter {
	private const FIELD_CARD_TYPES = array(
		'Field'    => true,
		'Fieldset' => true,
	);
	private const REMOVED_COMPONENT_TYPES = array(
		'Color'            => 'TextInput',
		'color'            => 'text-input',
		'NoticeSelector'   => 'Select',
		'notice-selector'  => 'select',
		'ObjectList'       => 'Repeater',
		'object-list'      => 'repeater',
		'TimezoneSelect'   => 'Select',
		'timezone-select'  => 'select',
		'TokenInput'       => 'Repeater',
		'token-input'      => 'repeater',
	);
	private const CONTROL_TYPES    = array(
		'Button' => true,
		'ButtonGroup' => true,
		'Toggle' => true,
		'Switch' => true,
		'TextInput' => true,
		'Textarea' => true,
		'EmailInput' => true,
		'PasswordInput' => true,
		'PhoneInput' => true,
		'UrlInput' => true,
		'Select' => true,
		'Combobox' => true,
		'MultiSelect' => true,
		'NumberInput' => true,
		'Range' => true,
		'Checkbox' => true,
		'CheckboxGroup' => true,
		'RadioGroup' => true,
		'CodeEditor' => true,
		'DateField' => true,
		'DatePicker' => true,
		'DateRangePicker' => true,
		'TimeField' => true,
		'DateTimePicker' => true,
		'Repeater' => true,
		'KeyValueEditor' => true,
		'Entries' => true,
		'Table' => true,
		'CopyField' => true,
		'Drawer' => true,
		'InlineTabs' => true,
		'SecretField' => true,
	);

	public function __construct(
		private readonly StructureDataSourceRegistry $registry = new StructureDataSourceRegistry(),
	) {}

	/**
	 * @param array<mixed,mixed> $structure Structure.
	 * @return Finding[]
	 */
	public function lint( array $structure, string $file ): array {
		return $this->lint_node( $structure, $file, '$', false );
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_node( array $node, string $file, string $path, bool $inside_field ): array {
		$findings = array();
		$raw_type = is_string( $node['type'] ?? null ) ? $node['type'] : '';
		$type     = StructureComponentTypes::canonical( $raw_type );

		if ( '' !== $raw_type && isset( self::REMOVED_COMPONENT_TYPES[ $raw_type ] ) ) {
			$findings[] = new Finding(
				"Structure component type {$raw_type} at {$path} was removed; use " . self::REMOVED_COMPONENT_TYPES[ $raw_type ] . ' instead.',
				'onumia.check.structureRemovedComponentType',
				$file
			);
		}

		if ( '' !== $raw_type && StructureComponentTypes::is_legacy( $raw_type ) ) {
			$replacement = StructureComponentTypes::legacy_replacement( $raw_type );
			$findings[]  = new Finding(
				"Structure component type {$raw_type} at {$path} should use {$replacement}.",
				'onumia.check.structureLegacyComponentType',
				$file,
				1,
				'warning'
			);
		}

		if ( isset( self::CONTROL_TYPES[ $type ] ) && ! $inside_field && ! $this->has_local_label( $node ) ) {
			$findings[] = new Finding(
				"Structure control {$type} at {$path} must be inside Field or Fieldset.",
				'onumia.check.structureBareControl',
				$file
			);
		}

		$next_inside_field = $inside_field || isset( self::FIELD_CARD_TYPES[ $type ] );
		$findings         = array_merge(
			$findings,
			$this->lint_enable_binding_shape( $node, $file, $path ),
			$this->lint_top_level_tabs( $node, $file, $path, $type ),
			$this->lint_button_events( $node, $file, $path, $type ),
			$this->lint_time_fields( $node, $file, $path, $type ),
			$this->lint_sources( $node, $file, $path )
		);

		if ( isset( self::FIELD_CARD_TYPES[ $type ] ) && is_array( $node['children'] ?? null ) ) {
			foreach ( $node['children'] as $index => $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}

				$child_type = is_string( $child['type'] ?? null ) ? StructureComponentTypes::canonical( $child['type'] ) : '';
				if ( isset( self::FIELD_CARD_TYPES[ $child_type ] ) ) {
					$findings[] = new Finding(
						"Structure {$type} at {$path} directly contains {$child_type}; split cards through Tab, Stack, or sibling sections.",
						'onumia.check.structureNestedFieldCard',
						$file
					);
				}
			}
		}

		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$child_path = is_int( $key ) ? "{$path}[{$key}]" : "{$path}.{$key}";
			$findings   = array_merge( $findings, $this->lint_node( $value, $file, $child_path, $next_inside_field ) );
		}

		return $findings;
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_enable_binding_shape( array $node, string $file, string $path ): array {
		$findings = array();
		if ( is_array( $node['props'] ?? null ) && array_key_exists( 'enable', $node['props'] ) ) {
			$findings[] = new Finding(
				"Structure component at {$path} must use root enable.setting instead of props.enable.",
				'onumia.check.structurePropsEnable',
				$file
			);
		}

		if ( is_array( $node['enable'] ?? null ) && array_key_exists( 'name', $node['enable'] ) ) {
			$findings[] = new Finding(
				"Structure enable binding at {$path}.enable must use setting, not name.",
				'onumia.check.structureEnableName',
				$file
			);
		}

		return $findings;
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_top_level_tabs( array $node, string $file, string $path, string $type ): array {
		if ( 'Tabs' !== $type || ! $this->is_view_root_component_path( $path ) ) {
			return array();
		}

		$children = is_array( $node['children'] ?? null ) ? $node['children'] : array();
		if ( count( $children ) >= 2 ) {
			return array();
		}

		return array(
			new Finding(
				"Top-level Tabs at {$path} must have at least two tabs; use a direct surface for one workflow.",
				'onumia.check.structureSingleChildTabs',
				$file
			),
		);
	}

	private function is_view_root_component_path( string $path ): bool {
		return '$' === $path || 1 === preg_match( '/^\$\.views\.[^.]+\.component$/', $path );
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_button_events( array $node, string $file, string $path, string $type ): array {
		if ( ! in_array( $type, array( 'Button', 'ButtonGroup' ), true ) ) {
			return array();
		}

		if ( ! is_array( $node['props'] ?? null ) || ! array_key_exists( 'event', $node['props'] ) ) {
			return array();
		}

		return array(
			new Finding(
				"Structure {$type} at {$path} must use events.click instead of props.event.",
				'onumia.check.structureButtonPropsEvent',
				$file
			),
		);
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_time_fields( array $node, string $file, string $path, string $type ): array {
		if ( 'TextInput' !== $type || ! is_array( $node['props'] ?? null ) ) {
			return array();
		}

		$name = $node['props']['name'] ?? null;
		if ( ! is_string( $name ) || ! str_ends_with( $name, 'timeOfDay' ) ) {
			return array();
		}

		return array(
			new Finding(
				"Structure TextInput at {$path} renders {$name}; use TimeField for time-of-day settings.",
				'onumia.check.structureTimeField',
				$file
			),
		);
	}

	/**
	 * Top-level labeled controls are accepted because the renderer can provide
	 * the label/help surface directly for them. Unlabeled controls still need a
	 * Field or Fieldset ancestor to avoid anonymous interactive chrome.
	 *
	 * @param array<mixed,mixed> $node Node.
	 */
	private function has_local_label( array $node ): bool {
		if ( is_string( $node['label'] ?? null ) && '' !== trim( $node['label'] ) ) {
			return true;
		}

		if ( is_array( $node['props'] ?? null ) && is_string( $node['props']['label'] ?? null ) ) {
			return '' !== trim( $node['props']['label'] );
		}

		return false;
	}

	/**
	 * @param array<mixed,mixed> $node Node.
	 * @return Finding[]
	 */
	private function lint_sources( array $node, string $file, string $path ): array {
		$findings = array();

		if ( is_array( $node['optionsSource'] ?? null ) && is_string( $node['optionsSource']['source'] ?? null ) ) {
			$findings = array_merge( $findings, $this->lint_source_name( $node['optionsSource']['source'], $file, "{$path}.optionsSource.source" ) );
		}

		if ( is_string( $node['source'] ?? null ) ) {
			$findings = array_merge( $findings, $this->lint_source_name( $node['source'], $file, "{$path}.source" ) );
		}

		return $findings;
	}

	/**
	 * @return Finding[]
	 */
	private function lint_source_name( string $source, string $file, string $path ): array {
		if ( ! str_starts_with( $source, 'wp.' ) && ! str_starts_with( $source, 'onumia.' ) ) {
			return array();
		}

		if ( $this->registry->has( $source ) ) {
			return array();
		}

		return array(
			new Finding(
				"Structure source {$source} referenced at {$path} is not registered.",
				'onumia.check.structureUnknownSource',
				$file
			),
		);
	}
}
