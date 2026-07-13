<?php

/**
 * Git history for custom Onumia modules.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Support\GitHistory;

final class ModuleHistoryRepository {
	private const SETTINGS_MIRROR_FILE = '.onumia/settings.json';

	public function __construct(
		private readonly ModuleLoader $loader = new ModuleLoader(),
		private readonly GitHistory $git_history = new GitHistory( 'module', 'onumia-history', 'Initialize Onumia module repository' ),
	) {}

	/**
	 * Commit the current custom module worktree.
	 *
	 * @param array<string,mixed> $settings Current module settings.
	 */
	public function commit_current( ModuleDefinition $module, array $settings, string $message ): ?string {
		$this->assert_custom_module( $module );
		$this->write_settings_mirror( $module->directory(), $settings );

		return $this->git_history->commit_worktree(
			$this->git_history->ensure_repository( $module->directory(), false ),
			$message
		);
	}

	/**
	 * List recent commits for one custom module.
	 *
	 * @return list<array{id:string,shortId:string,subject:string,authorName:string,authorEmail:string,authoredAt:string,committedAt:string,isMerge:bool,parents:list<string>,changedFiles:int,touchedFiles:list<string>,insertions:int,deletions:int}>
	 */
	public function history( ModuleDefinition $module, int $limit = 50 ): array {
		$this->assert_custom_module( $module );

		return $this->git_history->history(
			$this->git_history->ensure_repository( $module->directory() ),
			$limit
		);
	}

	/**
	 * Read a module exactly as it existed at one revision.
	 *
	 * @return array{module:ModuleDefinition,settings:array<string,mixed>,commit:array{id:string,shortId:string,subject:string,authorName:string,authorEmail:string,authoredAt:string,committedAt:string,isMerge:bool,parents:list<string>,changedFiles:int,touchedFiles:list<string>,insertions:int,deletions:int}}
	 */
	public function snapshot( ModuleDefinition $module, ModuleSettingsRepository $settings_repository, string $revision ): array {
		$this->assert_custom_module( $module );
		$repository = $this->git_history->ensure_repository( $module->directory() );
		$commit     = $this->git_history->read_commit( $repository, $revision );
		$temp       = $this->git_history->temporary_directory();

		try {
			$this->git_history->write_tree_to_directory( $repository, $commit->tree, $temp );
			$snapshot = $this->loader->load_directory( $temp );
			$settings = $settings_repository->settings_from_stored( $snapshot, $this->read_settings_mirror( $temp ) );

			return array(
				'module'   => $snapshot,
				'settings' => $settings,
				'commit'   => $this->git_history->commit_summary( $repository, $commit ),
			);
		} finally {
			$this->git_history->remove_directory( $temp );
		}
	}

	/**
	 * Restore a custom module to one historical revision and commit that restore.
	 *
	 * @return array{module:ModuleDefinition,settings:array<string,mixed>,commit:?string}
	 */
	public function revert( ModuleDefinition $module, ModuleSettingsRepository $settings_repository, string $revision ): array {
		$this->assert_custom_module( $module );
		$repository = $this->git_history->ensure_repository( $module->directory() );
		$commit     = $this->git_history->read_commit( $repository, $revision );
		$short_id   = substr( $commit->id->hex, 0, 7 );
		$temp       = $this->git_history->temporary_directory();

		try {
			$this->git_history->write_tree_to_directory( $repository, $commit->tree, $temp );
			$snapshot = $this->loader->load_directory( $temp );
			$settings = $settings_repository->settings_from_stored( $snapshot, $this->read_settings_mirror( $temp ) );
			$this->git_history->replace_directory_preserving_git_with_rollback( $module->directory(), $temp );
		} finally {
			$this->git_history->remove_directory( $temp );
		}

		$restored = $this->loader->load_directory( $module->directory() );
		$settings_repository->update_settings( $restored, $settings );
		$this->write_settings_mirror( $restored->directory(), $settings );

		return array(
			'module'   => $restored,
			'settings' => $settings,
			'commit'   => $this->git_history->commit_worktree( $repository, "Revert module to {$short_id}" ),
		);
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	private function write_settings_mirror( string $module_directory, array $settings ): void {
		$file = $module_directory . DIRECTORY_SEPARATOR . self::SETTINGS_MIRROR_FILE;
		$this->git_history->ensure_directory( dirname( $file ) );

		$json = json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		// @codeCoverageIgnoreStart
		if ( ! is_string( $json ) ) {
			throw Errors::invariant( 'Could not encode Onumia module settings history.' );
		}
		// @codeCoverageIgnoreEnd

		// @codeCoverageIgnoreStart
		if ( false === file_put_contents( $file, $json . "\n" ) ) {
			throw Errors::invariant( "Could not write Onumia module settings history {$file}." );
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_settings_mirror( string $module_directory ): array {
		$file = $module_directory . DIRECTORY_SEPARATOR . self::SETTINGS_MIRROR_FILE;
		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$settings = array();
		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) ) {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	private function assert_custom_module( ModuleDefinition $module ): void {
		if ( ! str_starts_with( $module->name(), 'custom/' ) ) {
			throw Errors::invariant( 'Git history is only available for custom Onumia modules.' );
		}
	}
}
