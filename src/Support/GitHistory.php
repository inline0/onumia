<?php

/**
 * Shared Pitmaster history operations for editable Onumia entities.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Core\Errors;
use Onumia\Lib\Pitmaster\Object\Blob;
use Onumia\Lib\Pitmaster\Object\Commit;
use Onumia\Lib\Pitmaster\Object\ObjectId;
use Onumia\Lib\Pitmaster\Object\Tree;
use Onumia\Lib\Pitmaster\Object\TreeEntry;
use Onumia\Lib\Pitmaster\Pitmaster;
use Onumia\Lib\Pitmaster\Repository;
use Onumia\Lib\Pitmaster\Status\FileStatus;
use SplFileInfo;

final class GitHistory {
	private const AUTHOR_NAME  = 'Onumia';
	private const AUTHOR_EMAIL = 'onumia@localhost';

	public function __construct(
		private readonly string $entity_label,
		private readonly string $temporary_prefix,
		private readonly string $initial_commit_message,
	) {}

	public function ensure_repository( string $root, bool $commit_initial = true ): Repository {
		if ( ! is_dir( $root ) ) {
			throw Errors::invariant( "Onumia {$this->entity_label} repository {$root} is not available." );
		}

		$was_initialized = ! Pitmaster::isRepository( $root );
		$repository      = $was_initialized ? Pitmaster::init( $root ) : Pitmaster::open( $root );
		$this->ensure_identity( $repository );

		if ( $commit_initial && $was_initialized ) {
			$this->commit_worktree( $repository, $this->initial_commit_message );
		}

		return $repository;
	}

	public function commit_worktree( Repository $repository, string $message ): ?string {
		if ( ! $this->stage_worktree( $repository ) ) {
			return null;
		}

		return $repository->commit( $message )->hex;
	}

	/**
	 * @return list<array{id:string,shortId:string,subject:string,authorName:string,authorEmail:string,authoredAt:string,committedAt:string,isMerge:bool,parents:list<string>,changedFiles:int,touchedFiles:list<string>,insertions:int,deletions:int}>
	 */
	public function history( Repository $repository, int $limit = 50 ): array {
		$history = array();
		foreach ( $repository->log( max( 1, min( 100, $limit ) ) ) as $commit ) {
			$history[] = $this->commit_summary( $repository, $commit );
		}

		return $history;
	}

	public function read_commit( Repository $repository, string $revision ): Commit {
		$revision = trim( $revision );
		if ( '' === $revision ) {
			throw Errors::invariant( 'A git revision is required.' );
		}

		try {
			$object = $repository->readObject( $repository->resolve( $revision )->hex );
		} catch ( \Throwable ) {
			throw Errors::invariant( sprintf( 'Git revision "%s" was not found.', $revision ) );
		}

		// @codeCoverageIgnoreStart
		if ( ! $object instanceof Commit ) {
			throw Errors::invariant( sprintf( 'Git revision "%s" is not a commit.', $revision ) );
		}
		// @codeCoverageIgnoreEnd

		return $object;
	}

	/**
	 * @return array{id:string,shortId:string,subject:string,authorName:string,authorEmail:string,authoredAt:string,committedAt:string,isMerge:bool,parents:list<string>,changedFiles:int,touchedFiles:list<string>,insertions:int,deletions:int}
	 */
	public function commit_summary( Repository $repository, Commit $commit ): array {
		$touched_files = $this->touched_files_for_commit( $repository, $commit );
		$identity      = $this->parse_identity( $commit->author );

		return array(
			'id'           => $commit->id->hex,
			'shortId'      => substr( $commit->id->hex, 0, 7 ),
			'subject'      => $this->subject_from_message( $commit->message ),
			'authorName'   => $identity['name'],
			'authorEmail'  => $identity['email'],
			'authoredAt'   => $this->format_timestamp( $commit->authorTimestamp() ),
			'committedAt'  => $this->format_timestamp( $commit->committerTimestamp() ),
			'isMerge'      => $commit->isMerge(),
			'parents'      => array_values( array_map( static fn( ObjectId $id ): string => $id->hex, $commit->parents ) ),
			'changedFiles' => count( $touched_files ),
			'touchedFiles' => $touched_files,
			'insertions'   => 0,
			'deletions'    => 0,
		);
	}

	public function write_tree_to_directory( Repository $repository, ObjectId $tree_id, string $directory ): void {
		$tree = $repository->readObject( $tree_id->hex );
		// @codeCoverageIgnoreStart
		if ( ! $tree instanceof Tree ) {
			throw Errors::invariant( 'Git revision tree could not be read.' );
		}
		// @codeCoverageIgnoreEnd

		$this->ensure_directory( $directory );
		foreach ( $tree->entries as $entry ) {
			$this->write_tree_entry( $repository, $entry, $directory );
		}
	}

	public function temporary_directory(): string {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->temporary_prefix . '-' . uniqid( '', true );
		$this->ensure_directory( $directory );

		return $directory;
	}

	public function clear_directory_preserving_git( string $directory ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			// @codeCoverageIgnoreStart
			if ( ! $entry instanceof SplFileInfo || '.git' === $entry->getFilename() || str_contains( $entry->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			// @codeCoverageIgnoreEnd

			if ( $entry->isDir() ) {
				@rmdir( $entry->getPathname() );
				continue;
			}

			@unlink( $entry->getPathname() );
		}
	}

	public function replace_directory_preserving_git_with_rollback( string $directory, string $replacement ): void {
		$backup = $this->temporary_directory();

		try {
			$this->copy_directory_contents_preserving_git( $directory, $backup );
			$this->clear_directory_preserving_git( $directory );
			$this->copy_directory_contents_preserving_git( $replacement, $directory );
		} catch ( \Throwable $throwable ) {
			$this->clear_directory_preserving_git( $directory );
			$this->copy_directory_contents_preserving_git( $backup, $directory );
			throw $throwable;
		} finally {
			$this->remove_directory( $backup );
		}
	}

	public function remove_directory( string $directory ): void {
		// @codeCoverageIgnoreStart
		if ( ! is_dir( $directory ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			// @codeCoverageIgnoreStart
			if ( ! $entry instanceof SplFileInfo ) {
				continue;
			}
			// @codeCoverageIgnoreEnd

			if ( $entry->isDir() ) {
				@rmdir( $entry->getPathname() );
				continue;
			}

			@unlink( $entry->getPathname() );
		}

		@rmdir( $directory );
	}

	public function ensure_directory( string $directory ): void {
		// @codeCoverageIgnoreStart
		if ( file_exists( $directory ) && ! is_dir( $directory ) ) {
			throw Errors::invariant( "Could not create Onumia {$this->entity_label} history directory {$directory}." );
		}
		// @codeCoverageIgnoreEnd

		// @codeCoverageIgnoreStart
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw Errors::invariant( "Could not create Onumia {$this->entity_label} history directory {$directory}." );
		}
		// @codeCoverageIgnoreEnd
	}

	private function copy_directory_contents_preserving_git( string $source, string $destination ): void {
		$this->ensure_directory( $destination );
		// @codeCoverageIgnoreStart
		if ( ! is_dir( $source ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $entry ) {
			// @codeCoverageIgnoreStart
			if ( ! $entry instanceof SplFileInfo ) {
				continue;
			}
			// @codeCoverageIgnoreEnd

			$relative = str_replace( '\\', '/', ltrim( substr( $entry->getPathname(), strlen( rtrim( $source, '/\\' ) ) ), '/\\' ) );
			$segments = explode( '/', $relative );
			if ( in_array( '.git', $segments, true ) ) {
				continue;
			}

			$target = rtrim( $destination, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( $entry->isDir() ) {
				$this->ensure_directory( $target );
				continue;
			}

			$this->ensure_directory( dirname( $target ) );
			// @codeCoverageIgnoreStart
			if ( false === @copy( $entry->getPathname(), $target ) ) {
				throw Errors::invariant( "Could not restore {$this->entity_label} file {$target}." );
			}
			// @codeCoverageIgnoreEnd
		}
	}

	private function ensure_identity( Repository $repository ): void {
		$config = $repository->config();
		$dirty  = false;

		if ( null === $config->get( 'user.name' ) ) {
			$config->set( 'user.name', self::AUTHOR_NAME );
			$dirty = true;
		}

		if ( null === $config->get( 'user.email' ) ) {
			$config->set( 'user.email', self::AUTHOR_EMAIL );
			$dirty = true;
		}

		if ( $dirty ) {
			$config->writeToFile( $repository->commonGitDir() . '/config' );
		}
	}

	private function stage_worktree( Repository $repository ): bool {
		$has_changes  = false;
		$add_paths    = array();
		$remove_paths = array();

		foreach ( $repository->status() as $entry ) {
			$has_changes = $has_changes || FileStatus::Unmodified !== $entry->index || FileStatus::Unmodified !== $entry->worktree;

			if ( FileStatus::Deleted === $entry->worktree ) {
				$remove_paths[] = $entry->path;
				continue;
			}

			if ( FileStatus::Untracked === $entry->index || FileStatus::Unmodified !== $entry->worktree ) {
				$add_paths[] = $entry->path;
			}
		}

		if ( array() !== $remove_paths ) {
			$repository->remove( ...array_values( array_unique( $remove_paths ) ) );
		}

		if ( array() !== $add_paths ) {
			$repository->add( ...array_values( array_unique( $add_paths ) ) );
		}

		return $has_changes;
	}

	/**
	 * @return list<string>
	 */
	private function touched_files_for_commit( Repository $repository, Commit $commit ): array {
		$parent_id = $commit->parents[0] ?? null;
		$base_tree = array();
		if ( null !== $parent_id ) {
			$parent = $repository->readObject( $parent_id->hex );
			if ( $parent instanceof Commit ) {
				$base_tree = $this->tree_file_map( $repository, $parent->tree );
			}
		}

		$next_tree = $this->tree_file_map( $repository, $commit->tree );
		$paths     = array();
		foreach ( array_keys( $base_tree + $next_tree ) as $path ) {
			if ( ( $base_tree[ $path ] ?? null ) !== ( $next_tree[ $path ] ?? null ) ) {
				$paths[] = $path;
			}
		}

		sort( $paths );

		return $paths;
	}

	/**
	 * @return array<string,string>
	 */
	private function tree_file_map( Repository $repository, ObjectId $tree_id, string $prefix = '' ): array {
		$tree = $repository->readObject( $tree_id->hex );
		// @codeCoverageIgnoreStart
		if ( ! $tree instanceof Tree ) {
			return array();
		}
		// @codeCoverageIgnoreEnd

		$files = array();
		foreach ( $tree->entries as $entry ) {
			$path = '' === $prefix ? $entry->name : $prefix . '/' . $entry->name;

			if ( $entry->isTree() ) {
				$files += $this->tree_file_map( $repository, $entry->hash, $path );
				continue;
			}

			if ( $entry->isBlob() || $entry->isSymlink() ) {
				$files[ $path ] = $entry->hash->hex;
			}
		}

		return $files;
	}

	/**
	 * @return array{name:string,email:string}
	 */
	private function parse_identity( string $identity ): array {
		if ( preg_match( '/^(.*?)\s+<([^>]+)>/', trim( $identity ), $matches ) ) {
			return array(
				'name'  => trim( (string) $matches[1] ),
				'email' => trim( (string) $matches[2] ),
			);
		}

		// @codeCoverageIgnoreStart
		return array(
			'name'  => trim( $identity ),
			'email' => '',
		);
		// @codeCoverageIgnoreEnd
	}

	private function subject_from_message( string $message ): string {
		$subject = strtok( $message, "\n" );

		return false === $subject ? '' : trim( $subject );
	}

	private function format_timestamp( ?int $timestamp ): string {
		// @codeCoverageIgnoreStart
		if ( null === $timestamp || $timestamp <= 0 ) {
			return '';
		}
		// @codeCoverageIgnoreEnd

		return gmdate( 'c', $timestamp );
	}

	private function write_tree_entry( Repository $repository, TreeEntry $entry, string $directory ): void {
		$target = $directory . DIRECTORY_SEPARATOR . $entry->name;
		if ( $entry->isTree() ) {
			$this->write_tree_to_directory( $repository, $entry->hash, $target );
			return;
		}

		// @codeCoverageIgnoreStart
		if ( ! $entry->isBlob() ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		$blob = $repository->readObject( $entry->hash->hex );
		// @codeCoverageIgnoreStart
		if ( ! $blob instanceof Blob ) {
			throw Errors::invariant( 'Git revision blob could not be read.' );
		}
		// @codeCoverageIgnoreEnd

		$this->ensure_directory( dirname( $target ) );
		// @codeCoverageIgnoreStart
		if ( false === file_put_contents( $target, $blob->content ) ) {
			throw Errors::invariant( "Could not restore {$this->entity_label} file {$target}." );
		}
		// @codeCoverageIgnoreEnd

		// @codeCoverageIgnoreStart
		if ( $entry->isExecutable() ) {
			chmod( $target, 0755 );
		}
		// @codeCoverageIgnoreEnd
	}
}
