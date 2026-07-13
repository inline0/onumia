<?php

/**
 * Structure component type casing helpers.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Structure;

final class StructureComponentTypes {
	/**
	 * @var array<string,string>
	 */
	private const PASCAL_TO_KEBAB = array(
		'Button'          => 'button',
		'ButtonGroup'     => 'button-group',
		'Chart'           => 'chart',
		'Checkbox'        => 'checkbox',
		'CheckboxGroup'   => 'checkbox-group',
		'CodeEditor'      => 'code-editor',
		'Combobox'        => 'combobox',
		'CopyField'       => 'copy-field',
		'DateField'       => 'date-field',
		'DatePicker'      => 'date-picker',
		'DateRangePicker' => 'date-range-picker',
		'DateTimePicker'  => 'date-time-picker',
		'Drawer'          => 'drawer',
		'DynamicTabs'     => 'dynamic-tabs',
		'EmailInput'      => 'email-input',
		'Entries'         => 'entries',
		'Field'           => 'field',
		'Fieldset'        => 'fieldset',
		'InlineTabs'      => 'inline-tabs',
		'KeyValueEditor'  => 'key-value-editor',
		'MultiSelect'     => 'multi-select',
		'Notice'          => 'notice',
		'NumberInput'     => 'number-input',
		'PasswordInput'   => 'password-input',
		'PhoneInput'      => 'phone-input',
		'Preview'         => 'preview',
		'RadioGroup'      => 'radio-group',
		'Range'           => 'range',
		'Repeater'        => 'repeater',
		'SecretField'     => 'secret-field',
		'Select'          => 'select',
		'Stack'           => 'stack',
		'StateRouter'     => 'state-router',
		'StatusField'     => 'status-field',
		'StatusList'      => 'status-list',
		'Switch'          => 'switch',
		'Tab'             => 'tab',
		'Table'           => 'table',
		'Tabs'            => 'tabs',
		'Text'            => 'text',
		'Textarea'        => 'textarea',
		'TextInput'       => 'text-input',
		'TimeField'       => 'time-field',
		'Toggle'          => 'toggle',
		'UrlInput'        => 'url-input',
		'UserSelect'      => 'user-select',
	);

	/**
	 * @var array<string,string>|null
	 */
	private static ?array $kebab_to_pascal = null;

	public static function canonical( string $type ): string {
		return self::kebab_to_pascal()[ $type ] ?? $type;
	}

	public static function kebab( string $type ): string {
		return self::PASCAL_TO_KEBAB[ $type ] ?? $type;
	}

	public static function is_legacy( string $type ): bool {
		return isset( self::PASCAL_TO_KEBAB[ $type ] );
	}

	public static function legacy_replacement( string $type ): ?string {
		return self::PASCAL_TO_KEBAB[ $type ] ?? null;
	}

	/**
	 * @return list<string>
	 */
	public static function schema_values( bool $include_legacy = true ): array {
		$values = array_values( self::PASCAL_TO_KEBAB );
		if ( $include_legacy ) {
			$values = array_merge( array_keys( self::PASCAL_TO_KEBAB ), $values );
		}

		sort( $values );

		return array_values( array_unique( $values ) );
	}

	/**
	 * @return array<string,string>
	 */
	private static function kebab_to_pascal(): array {
		if ( null === self::$kebab_to_pascal ) {
			self::$kebab_to_pascal = array_flip( self::PASCAL_TO_KEBAB );
		}

		return self::$kebab_to_pascal;
	}
}
