<?php

/**
 * Reads, validates, and writes custom module source files.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Check\Finding;
use Onumia\Check\FindingFilter;
use Onumia\Check\ModuleScopeLinter;
use Onumia\Core\Errors;
use Onumia\Support\CustomEntityName;
use Onumia\Support\RemixFiles;
use Onumia\Support\SourceFileMap;

final class ModuleFileRepository {
	private const REQUIRED_FILES = array( 'meta.json', 'structure.json', 'boot.php' );

	public function __construct(
		private readonly ModuleLoader $loader = new ModuleLoader(),
		private readonly ModuleScopeLinter $linter = new ModuleScopeLinter(),
		private readonly SourceFileMap $source_file_map = new SourceFileMap( 'Module', self::REQUIRED_FILES, 'onumia-module-files' ),
		private readonly RemixFiles $files = new RemixFiles(),
	) {}

	/**
	 * @return array<string,string>
	 */
	public function files( ModuleDefinition $module ): array {
		return $this->source_file_map->files( $module->directory() );
	}

	/**
	 * @param  array<string,string> $files Module files.
	 * @return array<int,array{message:string,identifier:string,file:string,line:int,severity:string}>
	 */
	public function check_files( ModuleDefinition $module, array $files ): array {
		unset( $module );
		return $this->check_file_map( $files );
	}

	/**
	 * @param array<string,string> $files    Module files.
	 * @param array<string,mixed>  $settings Current settings to preserve.
	 * @return array{module:ModuleDefinition,settings:array<string,mixed>,commit:?string}
	 */
	public function update_files( ModuleDefinition $module, array $files, array $settings, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository ): array {
		$this->assert_custom_module( $module );
		$findings = $this->check_files( $module, $files );
		$errors   = $this->error_response_findings( $findings );
		if ( array() !== $errors ) {
			throw new ModuleFileValidationException( $this->source_file_map->response_findings_to_findings( $errors, $module->directory() ) );
		}

		/** @var ModuleDefinition $updated */
		$updated = $this->source_file_map->replace_source_files_with_rollback(
			$module->directory(),
			$files,
			function () use ( $module, $settings, $settings_repository ): ModuleDefinition {
				$updated = $this->loader->load_directory( $module->directory() );
				$settings_repository->update_settings( $updated, $settings );
				$this->assert_current_valid( $updated );
				return $updated;
			}
		);

		return array(
			'module'   => $updated,
			'settings' => $settings_repository->settings( $updated ),
			'commit'   => $history_repository->commit_current( $updated, $settings_repository->settings( $updated ), 'Update module files' ),
		);
	}

	/**
	 * @param array<string,string> $files    Module files.
	 * @param array<string,mixed>  $settings Current settings to preserve.
	 * @return array{module:ModuleDefinition,settings:array<string,mixed>,commit:?string}
	 */
	public function create_files( string $name, array $files, array $settings, ModuleSettingsRepository $settings_repository, ModuleHistoryRepository $history_repository ): array {
		$this->assert_custom_name( $name );
		$root = $this->custom_module_root();
		if ( null === $root ) {
			throw Errors::invariant( 'Onumia custom module root is unavailable.' );
		}

		$directory = $this->entity_directory( $root, $name );
		if ( is_dir( $directory ) ) {
			throw Errors::invariant( "Module {$name} already exists." );
		}

		$findings = $this->check_file_map( $files );
		$errors   = $this->error_response_findings( $findings );
		if ( array() !== $errors ) {
			throw new ModuleFileValidationException( $this->source_file_map->response_findings_to_findings( $errors, $directory ) );
		}

		try {
			$this->source_file_map->write_files_to_directory( $directory, $files );
			$created = $this->loader->load_directory( $directory );
			if ( $created->name() !== $name ) {
				throw Errors::invariant( "Module meta name must be {$name}." );
			}

			$settings_repository->update_settings( $created, $settings );
			$this->assert_current_valid( $created );

			return array(
				'module'   => $created,
				'settings' => $settings_repository->settings( $created ),
				'commit'   => $history_repository->commit_current( $created, $settings_repository->settings( $created ), 'Create module' ),
			);
		} catch ( \Throwable $throwable ) {
			$this->source_file_map->remove_directory( $directory );
			throw $throwable;
		}
	}

	public function assert_current_valid( ModuleDefinition $module ): void {
		$this->assert_custom_module( $module );
		$findings = $this->linter->lint_paths( array( $module->directory() ) );
		$errors   = $this->error_findings( $findings );
		if ( array() !== $errors ) {
			throw new ModuleFileValidationException( $errors );
		}
	}

	private function assert_custom_module( ModuleDefinition $module ): void {
		if ( ! str_starts_with( $module->name(), 'custom/' ) ) {
			throw Errors::invariant( 'Module files can only be updated for custom Onumia modules.' );
		}
	}

	/**
	 * @param  array<string,string> $files Files.
	 * @return array<int,array{message:string,identifier:string,file:string,line:int,severity:string}>
	 */
	private function check_file_map( array $files ): array {
		$this->source_file_map->assert_valid_file_map( $files );
		$temp = $this->source_file_map->temporary_directory();

		try {
			$this->source_file_map->write_files_to_directory( $temp, $files );
			return array_values(
				array_map(
					fn( Finding $finding ): array => $this->finding_to_response( $finding, $temp ),
					$this->linter->lint_paths( array( $temp ) )
				)
			);
		} finally {
			$this->source_file_map->remove_directory( $temp );
		}
	}

	/**
	 * @param  array<int,array{message:string,identifier:string,file:string,line:int,severity:string}> $findings Findings.
	 * @return array<int,array{message:string,identifier:string,file:string,line:int,severity:string}>
	 */
	private function error_response_findings( array $findings ): array {
		return FindingFilter::response_errors( $findings );
	}

	/**
	 * @param  array<array-key,Finding> $findings Findings.
	 * @return list<Finding>
	 */
	private function error_findings( array $findings ): array {
		return FindingFilter::errors( $findings );
	}

	private function assert_custom_name( string $name ): void {
		CustomEntityName::assert_valid( $name, 'module' );
	}

	private function custom_module_root(): ?string {
		$directory = \get_stylesheet_directory();
		$root      = '' === $directory ? null : rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . 'modules';

		return $this->files->filtered_root( $root, 'onumia_custom_module_root' );
	}

	private function entity_directory( string $root, string $name ): string {
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $name );
	}

	/**
	 * @return array{message:string,identifier:string,file:string,line:int,severity:string}
	 */
	private function finding_to_response( Finding $finding, string $root ): array {
		return $this->source_file_map->finding_to_response( $finding, $root );
	}
}
