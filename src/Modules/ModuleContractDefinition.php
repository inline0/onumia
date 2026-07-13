<?php

/**
 * Parsed module PHP contract.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

final class ModuleContractDefinition {
	/**
	 * @param array<string,array<string,mixed>> $settings Setting definitions.
	 * @param array<string,ModuleAction>       $actions  Actions keyed by action name.
	 * @param array<string,ModuleDataSource>   $data_sources Data sources keyed by source name.
	 * @param ModuleHook[]                     $hooks WordPress hooks.
	 * @param array<string,ModuleEntryDefinition> $entries Entries keyed by entry name.
	 */
	public function __construct(
		private readonly string $class_name,
		private readonly bool $default_enabled,
		private readonly string $capability,
		private readonly ?string $feature_flag = null,
		private readonly array $settings = array(),
		private readonly array $actions = array(),
		private readonly array $data_sources = array(),
		private readonly array $hooks = array(),
		private readonly array $entries = array(),
	) {}

	public function class_name(): string {
		return $this->class_name;
	}

	public function default_enabled(): bool {
		return $this->default_enabled;
	}

	public function capability(): string {
		return $this->capability;
	}

	public function feature_flag(): ?string {
		return $this->feature_flag;
	}

	public function feature_enabled(): bool {
		if ( null === $this->feature_flag || '' === $this->feature_flag ) {
			return true;
		}

		if ( defined( $this->feature_flag ) ) {
			$value = constant( $this->feature_flag );
			return true === $value || '1' === $value;
		}

		$value = getenv( $this->feature_flag );
		if ( false !== $value && $this->truthy_string( (string) $value ) ) {
			return true;
		}

		return $this->truthy_string( $this->env_file_value( $this->feature_flag ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * @return array<string,ModuleAction>
	 */
	public function actions(): array {
		return $this->actions;
	}

	/**
	 * @return array<string,ModuleDataSource>
	 */
	public function data_sources(): array {
		return $this->data_sources;
	}

	/**
	 * @return array<string,ModuleEntryDefinition>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * @return ModuleHook[]
	 */
	public function hooks(): array {
		return $this->hooks;
	}

	public function action( string $name ): ?ModuleAction {
		return $this->actions[ $name ] ?? null;
	}

	public function data_source( string $name ): ?ModuleDataSource {
		return $this->data_sources[ $name ] ?? null;
	}

	public function entry( string $name ): ?ModuleEntryDefinition {
		return $this->entries[ $name ] ?? null;
	}

	/**
	 * @param array<string,ModuleEntryDefinition> $entries Entries keyed by entry name.
	 */
	public function with_entries( array $entries ): self {
		return new self(
			$this->class_name,
			$this->default_enabled,
			$this->capability,
			$this->feature_flag,
			$this->settings,
			$this->actions,
			$this->data_sources,
			$this->hooks,
			$entries
		);
	}

	public function has_setting( string $name ): bool {
		return array_key_exists( $name, $this->settings );
	}

	public function has_setting_path( string $path ): bool {
		$root = $this->setting_path_root( $path );
		return '' !== $root && $this->has_setting( $root );
	}

	public function setting_path_type( string $path ): ?string {
		$path = $this->normalize_setting_path( $path );
		$root = $this->setting_path_root( $path );
		if ( '' === $root || ! $this->has_setting( $root ) ) {
			return null;
		}

		$segments = explode( '.', $path );
		if ( count( $segments ) > 1 ) {
			$value = $this->settings[ $root ]['default'] ?? null;
			foreach ( array_slice( $segments, 1 ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return null;
				}

				$value = $value[ $segment ];
			}

			return $this->infer_type( $value );
		}

		$type = $this->settings[ $root ]['type'] ?? null;
		return is_string( $type ) ? $type : null;
	}

	private function setting_path_root( string $path ): string {
		$path = $this->normalize_setting_path( $path );
		$dot  = strpos( $path, '.' );

		return false === $dot ? $path : substr( $path, 0, $dot );
	}

	private function normalize_setting_path( string $path ): string {
		return str_starts_with( $path, 'settings.' ) ? substr( $path, strlen( 'settings.' ) ) : $path;
	}

	private function infer_type( mixed $value ): string {
		return match ( true ) {
			is_bool( $value ) => 'boolean',
			is_int( $value ) => 'integer',
			is_float( $value ) => 'number',
			is_string( $value ) => 'string',
			is_array( $value ) => array_is_list( $value ) ? 'array' : 'object',
			default => 'object',
		};
	}

	private function truthy_string( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private function env_file_value( string $key ): string {
		$file = dirname( __DIR__, 2 ) . '/.env';
		// @codeCoverageIgnoreStart
		if ( ! is_file( $file ) ) {
			return '';
		}

		$lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file
		if ( ! is_array( $lines ) ) {
			return '';
		}
		// @codeCoverageIgnoreEnd

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}

			$parts = explode( '=', $line, 2 );
			if ( trim( $parts[0] ) === $key ) {
				return trim( $parts[1], " \t\n\r\0\x0B\"'" );
			}
		}

		return '';
	}
}
