<?php

/**
 * Shared file operations for Onumia remixes.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Onumia\Core\Errors;
use Onumia\Lib\PhpParser\Error;
use Onumia\Lib\PhpParser\Node;
use Onumia\Lib\PhpParser\Node\Identifier;
use Onumia\Lib\PhpParser\Node\Name;
use Onumia\Lib\PhpParser\Node\Stmt\Class_;
use Onumia\Lib\PhpParser\Node\Stmt\Namespace_;
use Onumia\Lib\PhpParser\NodeTraverser;
use Onumia\Lib\PhpParser\NodeVisitor\CloningVisitor;
use Onumia\Lib\PhpParser\ParserFactory;
use Onumia\Lib\PhpParser\PrettyPrinter\Standard;
use SplFileInfo;

final class RemixFiles {
	/**
	 * @param non-empty-string $filter WordPress filter name.
	 */
	public function filtered_root( ?string $root, string $filter ): ?string {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( $filter, $root );
			$root     = is_string( $filtered ) ? $filtered : $root;
		}

		if ( ! is_string( $root ) || '' === trim( $root ) ) {
			return null;
		}

		return rtrim( $root, '/\\' );
	}

	/**
	 * @param string[] $root_files Root files handled by the remixer itself.
	 */
	public function copy_supporting_files( string $source_directory, string $target_directory, array $root_files ): void {
		$source_directory = rtrim( $source_directory, '/\\' );
		$iterator         = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $entry ) {
			// @codeCoverageIgnoreStart
			if ( ! $entry instanceof SplFileInfo ) {
				continue;
			}
			// @codeCoverageIgnoreEnd

			$path     = $entry->getPathname();
			$relative = ltrim( substr( $path, strlen( $source_directory ) ), '/\\' );
			if ( in_array( str_replace( '\\', '/', $relative ), $root_files, true ) ) {
				continue;
			}

			$target = $target_directory . DIRECTORY_SEPARATOR . $relative;
			if ( $entry->isDir() ) {
				$this->ensure_directory( $target );
				continue;
			}

			$this->copy_file( $path, $target );
		}
	}

	/**
	 * @param array<string,mixed> $meta Meta payload.
	 */
	public function write_meta_file( array $meta, string $file, string $entity_label ): void {
		$json = json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		// @codeCoverageIgnoreStart
		if ( ! is_string( $json ) ) {
			throw Errors::invariant( "Could not encode remixed {$entity_label} metadata." );
		}
		// @codeCoverageIgnoreEnd

		$this->write_file( $file, $json . "\n" );
	}

	public function remixed_boot_contents( string $file, string $name, string $namespace, string $class_name, string $missing_class_message, string $syntax_error_message ): string {
		$contents = file_get_contents( $file );
		$parser   = ( new ParserFactory() )->createForNewestSupportedVersion();
		try {
			$original = $parser->parse( false === $contents ? '' : $contents ) ?? array();
		} catch ( Error $error ) {
			throw Errors::invariant( $syntax_error_message . ': ' . $error->getMessage() );
		}

		$traverser = new NodeTraverser();
		$traverser->addVisitor( new CloningVisitor() );
		$modified = $traverser->traverse( $original );
		$tokens   = $parser->getTokens();
		$found    = false;

		$this->rewrite_boot_statements( $modified, $namespace, $class_name, $found );
		if ( ! $found ) {
			throw Errors::invariant( $missing_class_message );
		}

		return ( new Standard() )->printFormatPreserving( $modified, $original, $tokens );
	}

	/**
	 * @param string[] $tags Tags.
	 * @return string[]
	 */
	public function remixed_tags( array $tags ): array {
		return array_values( array_unique( array_merge( $tags, array( 'custom' ) ) ) );
	}

	public function remixed_label( string $source_label, string $name ): string {
		$segments = explode( '/', $name );
		$last     = end( $segments );
		if ( preg_match( '/-(\d+)$/', $last, $matches ) ) {
			return $source_label . ' Remix ' . $matches[1];
		}

		return $source_label . ' Remix';
	}

	public function remix_base_name( string $source_name, string $source_label, string $fallback_slug = 'module' ): string {
		$segments = explode( '/', $source_name );
		if ( in_array( $segments[0], array( 'custom', 'onumia' ), true ) ) {
			array_shift( $segments );
		}

		if ( array() === $segments ) {
			return $this->slug( $source_label, $fallback_slug );
		}

		return implode( '/', $segments );
	}

	public function append_suffix_to_last_segment( string $base, int $index ): string {
		if ( 1 === $index ) {
			return $base;
		}

		$segments                 = explode( '/', $base );
		$last_index               = count( $segments ) - 1;
		$segments[ $last_index ] .= '-' . $index;

		return implode( '/', $segments );
	}

	public function class_name( string $name, string $suffix, string $fallback_prefix ): string {
		$segments = explode( '/', $name );
		$last     = (string) end( $segments );
		$class    = $this->studly( $last ) . $suffix;

		return preg_match( '/^[A-Za-z_]/', $class ) ? $class : $fallback_prefix . $class;
	}

	public function namespace_from_name( string $name, string $prefix ): string {
		$segments = explode( '/', $name );
		array_pop( $segments );

		return rtrim( $prefix, '\\' ) . '\\' . implode( '\\', array_map( array( $this, 'studly' ), $segments ) );
	}

	public function ensure_directory( string $directory, ?string $message = null ): void {
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			throw Errors::invariant( $message ?? "Could not create directory {$directory}." );
		}
	}

	public function copy_file( string $source, string $target ): void {
		$this->ensure_directory( dirname( $target ) );
		if ( ! @copy( $source, $target ) ) {
			throw Errors::invariant( "Could not copy {$source} to {$target}." );
		}
	}

	public function write_file( string $file, string $contents ): void {
		$this->ensure_directory( dirname( $file ) );
		if ( false === @file_put_contents( $file, $contents ) ) {
			throw Errors::invariant( "Could not write {$file}." );
		}
	}

	public function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

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

	private function slug( string $value, string $fallback ): string {
		$slug = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', $value ) );
		$slug = trim( $slug, '-' );

		return '' === $slug ? $fallback : $slug;
	}

	private function studly( string $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9]+/', ' ', $value );
		$value = str_replace( ' ', '', ucwords( strtolower( (string) $value ) ) );

		return '' === $value ? 'Custom' : $value;
	}

	/**
	 * @param Node[] $statements Statements.
	 */
	private function rewrite_boot_statements( array $statements, string $namespace, string $class_name, bool &$found ): void {
		foreach ( $statements as $statement ) {
			if ( $statement instanceof Namespace_ ) {
				$statement->name = new Name( $namespace );
				$this->rewrite_boot_statements( $statement->stmts, $namespace, $class_name, $found );
				continue;
			}

			if ( $statement instanceof Class_ && null !== $statement->name && null !== $statement->extends ) {
				$statement->name = new Identifier( $class_name );
				$found           = true;
			}
		}
	}
}
