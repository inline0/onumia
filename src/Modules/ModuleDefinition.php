<?php

/**
 * Loaded Onumia module definition.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use Onumia\Core\Errors;
use Onumia\Messages\MessageCatalog;
use Onumia\Structure\StructureDefinition;

final class ModuleDefinition {
	/**
	 * @param array<string,mixed> $meta Module metadata.
	 */
	public function __construct(
		private readonly string $directory,
		private readonly array $meta,
		private readonly ModuleContractDefinition $contract,
		private readonly StructureDefinition $structure,
		private readonly MessageCatalog $messages,
		private readonly ModuleAdvancedContractDefinition $advanced = new ModuleAdvancedContractDefinition(),
	) {}

	public function directory(): string {
		return $this->directory;
	}

	public function name(): string {
		return $this->meta_string( 'name' );
	}

	public function category(): string {
		return $this->meta_string( 'category' );
	}

	/**
	 * @return string[]
	 */
	public function tags(): array {
		$tags = $this->meta['tags'] ?? array();
		return is_array( $tags ) ? array_values( array_filter( $tags, 'is_string' ) ) : array();
	}

	public function label(): string {
		return $this->meta_string( 'label' );
	}

	public function description(): string {
		return is_string( $this->meta['description'] ?? null ) ? $this->meta['description'] : '';
	}

	public function version(): string {
		return $this->meta_string( 'version' );
	}

	public function dev_only(): bool {
		return true === ( $this->meta['devOnly'] ?? false );
	}

	public function release_enabled(): bool {
		return false !== ( $this->meta['releaseEnabled'] ?? true );
	}

	public function release_reason(): string {
		return is_string( $this->meta['releaseReason'] ?? null ) ? $this->meta['releaseReason'] : '';
	}

	public function feature_enabled(): bool {
		return $this->contract->feature_enabled();
	}

	public function boot_file(): string {
		return $this->directory . DIRECTORY_SEPARATOR . 'boot.php';
	}

	public function contract(): ModuleContractDefinition {
		return $this->contract;
	}

	public function structure(): StructureDefinition {
		return $this->structure;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function access(): array {
		return $this->structure->access();
	}

	public function messages(): MessageCatalog {
		return $this->messages;
	}

	public function advanced(): ModuleAdvancedContractDefinition {
		return $this->advanced;
	}

	private function meta_string( string $key ): string {
		$value = $this->meta[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			throw Errors::invariant( "Module meta {$key} must be a string." );
		}

		return $value;
	}
}
