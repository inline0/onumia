<?php

/**
 * Module settings persistence.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;

final class ModuleSettingsRepository {

	private const SETTINGS_FILE        = 'onumia.settings.json';
	private const SITE_SETTINGS_OPTION = 'onumia_module_site_settings';

	public function __construct(
		private readonly ModuleValueValidator $validator = new ModuleValueValidator(),
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function settings( ModuleDefinition $module ): array {
		$stored = array_merge(
			$this->normalize_settings_payload( $this->all()[ $module->name() ] ?? array() ),
			$this->site_settings_by_module_name( $module->name() )
		);

		return $this->settings_from_stored( $module, $this->normalize_settings_payload( $stored ) );
	}

	/**
	 * Read stored settings for a module by name without requiring its contract.
	 *
	 * This is for cross-module lookups that need the canonical settings file,
	 * including the `onumia_settings_file` filter and shared read lock, but must
	 * not apply another module's defaults or validation.
	 *
	 * @return array<string,mixed>
	 */
	public function stored_settings_by_module_name( string $module_name ): array {
		return $this->normalize_settings_payload( $this->all()[ $module_name ] ?? array() );
	}

	/**
	 * Read per-site overrides without requiring the module contract.
	 *
	 * @return array<string,mixed>
	 */
	public function site_settings_by_module_name( string $module_name ): array {
		return $this->normalize_settings_payload( $this->site_settings()[ $module_name ] ?? array() );
	}

	public function has_active_settings( ModuleDefinition $module ): bool {
		$stored = $this->combined_stored_settings( $module );
		if ( null === $stored ) {
			return false;
		}

		$settings = $this->settings_from_stored( $module, $stored );

		foreach ( $module->contract()->settings() as $key => $definition ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}

			if ( $this->setting_value_is_active( $settings[ $key ] ?? null, $definition, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve module settings from an explicit stored payload.
	 *
	 * @param  array<string,mixed> $stored Stored settings.
	 * @return array<string,mixed>
	 */
	public function settings_from_stored( ModuleDefinition $module, array $stored ): array {
		$defaults = $this->defaults( $module );

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	public function update_settings( ModuleDefinition $module, array $settings ): void {
		$this->validate_settings( $module, $settings );

		$this->update_settings_with(
			$module,
			static fn( array $current ): array => $settings
		);
	}

	/**
	 * Persist settings that must differ between sites sharing one theme.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	public function update_site_settings( ModuleDefinition $module, array $settings ): void {
		$this->validate_settings( $module, $settings );

		$all                    = $this->site_settings();
		$current                = $this->normalize_settings_payload( $all[ $module->name() ] ?? array() );
		$all[ $module->name() ] = array_merge( $current, $settings );

		\update_option( self::SITE_SETTINGS_OPTION, $all, false );
	}

	/**
	 * @param callable(array<string,mixed>):array<string,mixed> $updater Settings updater.
	 */
	public function update_settings_with( ModuleDefinition $module, callable $updater ): void {

		$file = $this->settings_file();
		if ( null === $file ) {
			throw Errors::invariant( 'Onumia settings file is unavailable.' );
		}

		$this->with_settings_lock(
			$file,
			\LOCK_EX,
			function () use ( $file, $module, $updater ): void {
				$all     = $this->read_all_from_file( $file );
				$stored  = $this->normalize_settings_payload( $all[ $module->name() ] ?? array() );
				$current = $this->settings_from_stored( $module, $stored );
				$updates = $this->normalize_settings_payload( $updater( $current ) );
				$this->validate_settings( $module, $updates );

				$all[ $module->name() ] = array_merge( $current, $updates );
				$this->write_all_to_file( $file, $all );
			}
		);
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	public function validate_settings( ModuleDefinition $module, array $settings ): void {
		$contract_settings = $module->contract()->settings();
		foreach ( $settings as $key => $value ) {
			if ( ! isset( $contract_settings[ $key ] ) ) {
				throw Errors::invariant( "Unknown setting {$key} for module {$module->name()}." );
			}

			$this->validator->assert_value( $value, $contract_settings[ $key ], "Setting {$key}" );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function defaults( ModuleDefinition $module ): array {
		$defaults = array();
		foreach ( $module->contract()->settings() as $key => $setting ) {
			$type             = $this->validator->definition_type( $setting, "Setting {$key}" );
			$defaults[ $key ] = $setting['default'] ?? $this->validator->empty_value( $type );
		}

		return $defaults;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function all(): array {
		$file = $this->settings_file();
		if ( null !== $file && is_file( $file ) ) {
			return $this->with_settings_lock(
				$file,
				\LOCK_SH,
				fn(): array => $this->read_all_from_file( $file )
			);
		}

		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_all( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();
		foreach ( $value as $module_name => $module_settings ) {
			if ( is_string( $module_name ) ) {
				$settings[ $module_name ] = $module_settings;
			}
		}

		return $settings;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function stored_settings( ModuleDefinition $module ): ?array {
		$all = $this->all();
		if ( ! array_key_exists( $module->name(), $all ) ) {
			return null;
		}

		return $this->normalize_settings_payload( $all[ $module->name() ] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function combined_stored_settings( ModuleDefinition $module ): ?array {
		$theme = $this->stored_settings( $module );
		$site  = $this->site_settings_by_module_name( $module->name() );
		if ( null === $theme && array() === $site ) {
			return null;
		}

		return array_merge( $theme ?? array(), $site );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function site_settings(): array {
		return $this->normalize_all( \get_option( self::SITE_SETTINGS_OPTION, array() ) );
	}

	/**
	 * @param array<string,mixed> $definition Setting definition.
	 */
	private function setting_value_is_active( mixed $value, array $definition, string $key ): bool {
		$type = $this->validator->definition_type( $definition, "Setting {$key}" );

		return match ( $type ) {
			'boolean' => true === $value,
			'integer' => is_int( $value ) && 0 !== $value,
			'number' => ( is_int( $value ) || is_float( $value ) ) && 0.0 !== (float) $value,
			'array', 'object' => is_array( $value ) && array() !== $value,
			'string' => is_string( $value ) && $this->string_value_is_active( $value ),
			default => null !== $value,
		};
	}

	private function string_value_is_active( string $value ): bool {
		$normalized = strtolower( trim( $value ) );

		return '' !== $normalized && ! in_array( $normalized, array( 'default', 'none', 'off', 'disabled' ), true );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_settings_payload( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();
		foreach ( $value as $key => $setting_value ) {
			if ( is_string( $key ) ) {
				$settings[ $key ] = $setting_value;
			}
		}

		return $settings;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_all_from_file( string $file ): array {
		if ( ! is_file( $file ) ) {
			return array();
		}

		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return array();
		}

		$decoded = json_decode( $contents, true );
		if ( is_array( $decoded ) ) {
			$modules = $decoded['modules'] ?? null;
			if ( is_array( $modules ) ) {
				return $this->normalize_all( $modules );
			}

			return $this->normalize_all( $decoded );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	private function write_all_to_file( string $file, array $settings ): void {
		$directory = dirname( $file );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			// @codeCoverageIgnoreStart
			throw Errors::invariant( "Could not create Onumia settings directory {$directory}." );
			// @codeCoverageIgnoreEnd
		}

		if ( is_dir( $file ) ) {
			throw Errors::invariant( "Could not write Onumia settings file {$file}." );
		}

		$json = json_encode(
			array( 'modules' => $settings ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $json ) ) {
			throw Errors::invariant( 'Could not encode Onumia settings.' );
		}

		$temporary = tempnam( $directory, basename( $file ) . '.' );
		if ( false === $temporary ) {
			// @codeCoverageIgnoreStart
			throw Errors::invariant( "Could not create temporary Onumia settings file in {$directory}." );
			// @codeCoverageIgnoreEnd
		}

		if ( false === file_put_contents( $temporary, $json . "\n" ) ) {
			// @codeCoverageIgnoreStart
			@unlink( $temporary );
			throw Errors::invariant( "Could not write Onumia settings file {$file}." );
			// @codeCoverageIgnoreEnd
		}

		@chmod( $temporary, 0644 );

		if ( ! rename( $temporary, $file ) ) {
			// @codeCoverageIgnoreStart
			@unlink( $temporary );
			throw Errors::invariant( "Could not replace Onumia settings file {$file}." );
			// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * @template T
	 *
	 * @param int<0,7>     $operation Operation.
	 * @param callable():T $callback Callback.
	 * @return T
	 */
	private function with_settings_lock( string $file, int $operation, callable $callback ): mixed {
		$directory = dirname( $file );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw Errors::invariant( "Could not create Onumia settings directory {$directory}." );
		}

		$lock_file = $this->settings_lock_file( $file );
		$handle    = fopen( $lock_file, 'c' );
		if ( false === $handle ) {
			// @codeCoverageIgnoreStart
			throw Errors::invariant( "Could not open Onumia settings lock file {$lock_file}." );
			// @codeCoverageIgnoreEnd
		}

		try {
			if ( ! flock( $handle, $operation ) ) {
				// @codeCoverageIgnoreStart
				throw Errors::invariant( "Could not lock Onumia settings file {$file}." );
				// @codeCoverageIgnoreEnd
			}

			return $callback();
		} finally {
			flock( $handle, \LOCK_UN );
			fclose( $handle );
		}
	}

	private function settings_lock_file( string $file ): string {
		$base = function_exists( 'get_temp_dir' ) ? \get_temp_dir() : sys_get_temp_dir();
		$base = is_string( $base ) && '' !== trim( $base ) ? $base : sys_get_temp_dir();
		$directory = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'onumia-settings-locks';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			// @codeCoverageIgnoreStart
			throw Errors::invariant( "Could not create Onumia settings lock directory {$directory}." );
			// @codeCoverageIgnoreEnd
		}

		return $directory . DIRECTORY_SEPARATOR . sha1( $file ) . '.lock';
	}

	private function settings_file(): ?string {
		$file = $this->default_settings_file();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'onumia_settings_file', $file );
			$file     = is_string( $filtered ) ? $filtered : $file;
		}

		if ( ! is_string( $file ) || '' === trim( $file ) ) {
			return null;
		}

		return $file;
	}

	private function default_settings_file(): ?string {
		$directory = \get_stylesheet_directory();
		if ( '' === $directory ) {
			return null;
		}

		return rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . self::SETTINGS_FILE;
	}
}
