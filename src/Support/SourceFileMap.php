<?php

/**
 * Shared source file map operations for editable Onumia entities.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Check\Finding;
use Onumia\Core\Errors;
use SplFileInfo;

final class SourceFileMap {
	/**
	 * @param string[] $required_files Required root files.
	 */
	public function __construct(
		private readonly string $entity_label,
		private readonly array $required_files,
		private readonly string $temporary_prefix,
	) {}

	/**
	 * @return array<string,string>
	 */
	public function files( string $directory ): array {
		$files = array();
		foreach ( $this->source_files( $directory ) as $file ) {
			$relative           = $this->relative_path( $directory, $file );
			$contents           = file_get_contents( $file );
			$files[ $relative ] = false === $contents ? '' : $contents;
		}

		ksort( $files );
		return $files;
	}

	/**
	 * @param array<string,string> $files Files.
	 */
	public function assert_valid_file_map( array $files ): void {
		foreach ( $this->required_files as $file ) {
			if ( ! isset( $files[ $file ] ) ) {
				throw Errors::invariant( "{$this->entity_label} files payload is missing {$file}." );
			}
		}

		foreach ( $files as $path => $content ) {
			if ( '' === $path ) {
				throw Errors::invariant( "{$this->entity_label} files payload must be a string map." );
			}

			$normalized = str_replace( '\\', '/', $path );
			$segments   = explode( '/', $normalized );
			if (
				str_starts_with( $normalized, '/' )
				|| str_contains( $normalized, "\0" )
				|| in_array( '..', $segments, true )
				|| in_array( '.git', $segments, true )
				|| in_array( '.onumia', $segments, true )
				|| ( ! str_ends_with( $normalized, '.json' ) && ! str_ends_with( $normalized, '.php' ) )
			) {
				throw Errors::invariant( "{$this->entity_label} file path {$path} is not allowed." );
			}
		}
	}

	/**
	 * @return list<string>
	 */
	public function source_files( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			$entry    = $this->spl_file_info( $entry );
			$relative = $this->relative_path( $directory, $entry->getPathname() );
			$segments = explode( '/', $relative );
			if (
				in_array( '.git', $segments, true )
				|| in_array( '.onumia', $segments, true )
				|| ( ! str_ends_with( $relative, '.json' ) && ! str_ends_with( $relative, '.php' ) )
			) {
				continue;
			}

			$files[] = $entry->getPathname();
		}

		sort( $files );
		return $files;
	}

	public function clear_source_files( string $directory ): void {
		foreach ( $this->source_files( $directory ) as $file ) {
			@unlink( $file );
		}
	}

	/**
	 * @param array<string,string> $files Files.
	 */
	public function replace_source_files_with_rollback( string $directory, array $files, ?callable $after_replace = null ): mixed {
		$backup = $this->temporary_directory();

		try {
			$this->write_files_to_directory( $backup, $this->files( $directory ) );
			$this->clear_source_files( $directory );
			$this->write_files_to_directory( $directory, $files );
			if ( null !== $after_replace ) {
				return $after_replace();
			}

			return null;
		} catch ( \Throwable $throwable ) {
			$this->clear_source_files( $directory );
			$this->write_files_to_directory( $directory, $this->files( $backup ) );
			throw $throwable;
		} finally {
			$this->remove_directory( $backup );
		}
	}

	/**
	 * @param array<string,string> $files Files.
	 */
	public function write_files_to_directory( string $directory, array $files ): void {
		foreach ( $files as $path => $content ) {
			$file = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
			$dir  = dirname( $file );
			// @codeCoverageIgnoreStart
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
				throw Errors::invariant( "Could not create directory {$dir}." );
			}
			if ( false === @file_put_contents( $file, $content ) ) {
				throw Errors::invariant( "Could not write {$this->entity_label} file {$path}." );
			}
			// @codeCoverageIgnoreEnd
		}
	}

	public function relative_path( string $root, string $path ): string {
		return str_replace( '\\', '/', ltrim( substr( $path, strlen( rtrim( $root, '/\\' ) ) ), '/\\' ) );
	}

	public function temporary_directory(): string {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->temporary_prefix . '-' . uniqid( '', true );
		// @codeCoverageIgnoreStart
		if ( ! @mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			throw Errors::invariant( "Could not create temporary Onumia {$this->entity_label} directory." );
		}
		// @codeCoverageIgnoreEnd

		return $directory;
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
			$entry = $this->spl_file_info( $entry );
			$entry->isDir() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
		}

		@rmdir( $directory );
	}

	/**
	 * @return array{message:string,identifier:string,file:string,line:int,severity:string}
	 */
	public function finding_to_response( Finding $finding, string $root ): array {
		$file = str_starts_with( $finding->file, $root )
			? $this->relative_path( $root, $finding->file )
			: $finding->file;

		return array(
			'message'    => $finding->message,
			'identifier' => $finding->identifier,
			'file'       => $file,
			'line'       => $finding->line,
			'severity'   => $finding->severity,
		);
	}

	/**
	 * @param array<int,array{message:string,identifier:string,file:string,line:int,severity:string}> $findings Findings.
	 * @return Finding[]
	 */
	public function response_findings_to_findings( array $findings, string $root ): array {
		return array_map(
			static fn( array $finding ): Finding => new Finding(
				$finding['message'],
				$finding['identifier'],
				rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $finding['file'] ),
				$finding['line'],
				$finding['severity']
			),
			$findings
		);
	}

	private function spl_file_info( mixed $entry ): SplFileInfo {
		// @codeCoverageIgnoreStart
		if ( ! $entry instanceof SplFileInfo ) {
			throw Errors::invariant( 'Expected a filesystem iterator entry.' );
		}
		// @codeCoverageIgnoreEnd

		return $entry;
	}
}
