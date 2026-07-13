<?php

/**
 * Creates custom remixes from existing module folders.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Support\RemixFiles;

final class ModuleRemixer {
	private const CUSTOM_MODULE_ROOT_FILTER = 'onumia_custom_module_root';
	private const ROOT_FILES                = array( 'boot.php', 'meta.json', 'structure.json' );

	public function __construct(
		private readonly ModuleLoader $loader = new ModuleLoader(),
		private readonly RemixFiles $files = new RemixFiles(),
	) {}

	public function remix( ModuleDefinition $source, ModuleRegistry $registry ): ModuleDefinition {
		$root = $this->custom_module_root();
		if ( null === $root ) {
			throw Errors::invariant( 'Onumia custom module root is unavailable.' );
		}

		$name      = $this->next_module_name( $source, $registry, $root );
		$directory = $this->module_directory( $root, $name );

		try {
			$this->write_module( $source, $directory, $name );
			return $this->loader->load_directory( $directory );
		} catch ( \Throwable $throwable ) {
			$this->files->remove_directory( $directory );
			throw $throwable;
		}
	}

	private function custom_module_root(): ?string {
		return $this->files->filtered_root(
			$this->default_custom_module_root(),
			self::CUSTOM_MODULE_ROOT_FILTER
		);
	}

	private function default_custom_module_root(): ?string {
		$directory = \get_stylesheet_directory();
		if ( '' === $directory ) {
			return null;
		}

		return rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . 'onumia' . DIRECTORY_SEPARATOR . 'modules';
	}

	private function next_module_name( ModuleDefinition $source, ModuleRegistry $registry, string $root ): string {
		$base = $this->files->remix_base_name( $source->name(), $source->label() );
		for ( $index = 1; ; ++$index ) {
			$name = 'custom/' . $this->files->append_suffix_to_last_segment( $base, $index );
			if ( null === $registry->get( $name ) && ! is_dir( $this->module_directory( $root, $name ) ) ) {
				return $name;
			}
		}
	}

	private function module_directory( string $root, string $name ): string {
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $name );
	}

	private function write_module( ModuleDefinition $source, string $directory, string $name ): void {
		$this->files->ensure_directory( $directory );
		$this->files->copy_supporting_files( $source->directory(), $directory, self::ROOT_FILES );
		$this->write_meta_file( $source, $directory . DIRECTORY_SEPARATOR . 'meta.json', $name );
		$this->files->copy_file( $source->structure()->file(), $directory . DIRECTORY_SEPARATOR . 'structure.json' );
		$this->files->write_file(
			$directory . DIRECTORY_SEPARATOR . 'boot.php',
			$this->files->remixed_boot_contents(
				$source->boot_file(),
				$name,
				$this->files->namespace_from_name( $name, 'Onumia\\CustomModules' ),
				$this->files->class_name( $name, 'Module', 'Module' ),
				"Module boot file {$source->boot_file()} must define a class extending Onumia\\Modules\\Module.",
				'PHP syntax error in module boot file'
			)
		);
	}

	private function write_meta_file( ModuleDefinition $source, string $file, string $name ): void {
		$meta = array(
			'name'        => $name,
			'category'    => $source->category(),
			'tags'        => $this->files->remixed_tags( $source->tags() ),
			'label'       => $this->files->remixed_label( $source->label(), $name ),
			'description' => $source->description(),
			'version'     => $source->version(),
		);

		if ( $source->dev_only() ) {
			$meta['devOnly'] = true;
		}

		$this->files->write_meta_file( $meta, $file, 'module' );
	}
}
