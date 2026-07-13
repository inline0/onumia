<?php

/**
 * Loads structure.json.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Structure;

use Onumia\Schema\SchemaValidator;
use Onumia\Support\JsonFile;

final class StructureLoader {
	private const SOURCE_PATTERN = '/^[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9][A-Za-z0-9]*)+$/';

	private const FIELD_TYPES = array(
		'Checkbox',
		'Switch',
		'Toggle',
		'TextInput',
		'Textarea',
		'NumberInput',
		'Range',
		'Select',
		'RadioGroup',
		'CheckboxGroup',
		'CodeEditor',
		'DateField',
		'DatePicker',
		'DateRangePicker',
		'UrlInput',
		'EmailInput',
		'PasswordInput',
		'PhoneInput',
		'TimeField',
		'KeyValueEditor',
		'Repeater',
	);

	public function __construct(
		private readonly SchemaValidator $validator = new SchemaValidator(),
	) {}

	public function load_directory( string $directory ): StructureDefinition {
		$file = $directory . DIRECTORY_SEPARATOR . 'structure.json';
		return $this->load_file( $file );
	}

	public function load_file( string $file ): StructureDefinition {
		$data = JsonFile::read_object( $file, 'Structure' );
		$this->validator->validate_structure( $data, $file );

		$setting_refs      = array();
		$action_refs       = array();
		$message_refs      = array();
		$source_refs       = array();
		$component_refs    = array();
		$entry_refs        = array();
		$component_names   = $this->component_names( $data );
		$transition_events = $this->transition_events( $data );
		$this->collect_refs( $data, $setting_refs, $action_refs, $message_refs, $source_refs, $component_refs, $entry_refs, $transition_events );

		return new StructureDefinition(
			$file,
			$data,
			array_values( array_unique( $setting_refs ) ),
			array_values( array_unique( $action_refs ) ),
			array_values( array_unique( $message_refs ) ),
			array_values( array_unique( $source_refs ) ),
			array_values( array_unique( $component_refs ) ),
			array_values( array_unique( $component_names ) ),
			array_values( array_unique( $entry_refs ) )
		);
	}

	/**
	 * @param mixed    $value        Value.
	 * @param string[] $setting_refs Setting refs.
	 * @param string[] $action_refs  Action refs.
	 * @param string[] $message_refs Message refs.
	 * @param string[] $source_refs  Source refs.
	 * @param string[] $component_refs Component refs.
	 * @param string[] $entry_refs Entry refs.
	 * @param array<string,true> $transition_events Transition events.
	 */
	private function collect_refs( mixed $value, array &$setting_refs, array &$action_refs, array &$message_refs, array &$source_refs, array &$component_refs, array &$entry_refs, array $transition_events, bool $inside_collection_field = false ): void {
		if ( is_string( $value ) ) {
			if ( preg_match_all( '/\{\{\s*messages\.([A-Za-z0-9_.-]+)\s*\}\}/', $value, $matches ) ) {
				foreach ( $matches[1] as $message_key ) {
					$message_refs[] = $message_key;
				}
			}
			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		if ( is_string( $value['ref'] ?? null ) ) {
			$this->collect_ref_string( $value['ref'], $setting_refs );
		}

		if ( is_string( $value['componentRef'] ?? null ) ) {
			$component_refs[] = $value['componentRef'];
		}

		$type = is_string( $value['type'] ?? null ) ? StructureComponentTypes::canonical( $value['type'] ) : '';
		if ( is_array( $value['props'] ?? null ) ) {
			$props = $value['props'];
			if (
				! $inside_collection_field
				&& in_array( $type, self::FIELD_TYPES, true )
				&& is_string( $props['name'] ?? null )
			) {
				$setting_refs[] = $this->normalize_setting_ref( $props['name'] );
			}

			if ( is_array( $props['optionsSource'] ?? null ) && is_string( $props['optionsSource']['source'] ?? null ) ) {
				$source_refs[] = $props['optionsSource']['source'];
			}

			if (
				'Repeater' === $type
				&& is_array( $props['itemEnable'] ?? null )
				&& is_string( $props['itemEnable']['setting'] ?? null )
				&& is_string( $props['name'] ?? null )
			) {
				$setting_refs[] = $this->normalize_setting_ref( $props['name'] );
			}

			if ( 'Entries' === $type && is_string( $props['entry'] ?? null ) ) {
				$entry_refs[] = $props['entry'];
			}

			if ( 'Entries' === $type ) {
				foreach ( array( 'bulkActions', 'rowActions' ) as $action_list_key ) {
					if ( ! is_array( $props[ $action_list_key ] ?? null ) ) {
						continue;
					}

					foreach ( $props[ $action_list_key ] as $action_config ) {
						if ( is_array( $action_config ) && is_string( $action_config['action'] ?? null ) ) {
							$action_refs[] = $action_config['action'];
						}
					}
				}
			}
		}

		if ( is_array( $value['enable'] ?? null ) && is_string( $value['enable']['setting'] ?? null ) ) {
			$setting_refs[] = $this->normalize_setting_ref( $value['enable']['setting'] );
		}

		if ( is_array( $value['optionsSource'] ?? null ) && is_string( $value['optionsSource']['source'] ?? null ) ) {
			$source_refs[] = $value['optionsSource']['source'];
		}

		if ( is_string( $value['source'] ?? null ) && preg_match( self::SOURCE_PATTERN, $value['source'] ) ) {
			$source_refs[] = $value['source'];
		}

		if ( is_array( $value['events'] ?? null ) ) {
			foreach ( $value['events'] as $event ) {
				if ( is_array( $event ) && is_string( $event['event'] ?? null ) ) {
					if ( isset( $transition_events[ $event['event'] ] ) ) {
						continue;
					}
					$action_refs[] = $event['event'];
				}
			}
		}

		if ( 'action' === ( $value['type'] ?? null ) && is_string( $value['action'] ?? null ) ) {
			$action_refs[] = $value['action'];
		}

		if ( 'setSetting' === ( $value['type'] ?? null ) && is_string( $value['path'] ?? null ) ) {
			$setting_refs[] = $this->normalize_setting_ref( $value['path'] );
		}

		$child_inside_collection = $inside_collection_field || 'Repeater' === $type;
		foreach ( $value as $child ) {
			$this->collect_refs( $child, $setting_refs, $action_refs, $message_refs, $source_refs, $component_refs, $entry_refs, $transition_events, $child_inside_collection );
		}
	}

	/**
	 * @param array<string,mixed> $data Structure.
	 * @return array<string,true>
	 */
	private function transition_events( array $data ): array {
		$events = array();
		$this->collect_transition_events( $data, $events );
		return $events;
	}

	/**
	 * @param array<string,true> $events Events.
	 */
	private function collect_transition_events( mixed $value, array &$events ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( is_array( $value['transitions'] ?? null ) ) {
			foreach ( $value['transitions'] as $transition ) {
				if ( is_array( $transition ) && is_string( $transition['event'] ?? null ) ) {
					$events[ $transition['event'] ] = true;
				}
			}
		}

		foreach ( $value as $child ) {
			$this->collect_transition_events( $child, $events );
		}
	}

	/**
	 * @param string[] $setting_refs Setting refs.
	 */
	private function collect_ref_string( string $ref, array &$setting_refs ): void {
		if ( str_starts_with( $ref, 'settings.' ) ) {
			$setting_refs[] = substr( $ref, strlen( 'settings.' ) );
		}
	}

	private function normalize_setting_ref( string $name ): string {
		return str_starts_with( $name, 'settings.' ) ? substr( $name, strlen( 'settings.' ) ) : $name;
	}

	/**
	 * @param array<string,mixed> $data Structure.
	 * @return string[]
	 */
	private function component_names( array $data ): array {
		if ( ! is_array( $data['components'] ?? null ) ) {
			return array();
		}

		$names = array();
		foreach ( $data['components'] as $name => $definition ) {
			if ( is_string( $name ) ) {
				$names[] = $name;
			}

			if ( is_array( $definition ) && is_string( $definition['name'] ?? null ) ) {
				$names[] = $definition['name'];
			}
		}

		return $names;
	}
}
