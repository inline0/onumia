<?php

/**
 * Module message catalog.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Messages;

final class MessageCatalog {
	/**
	 * @param array<string,string> $messages Flattened messages.
	 */
	public function __construct(
		private readonly ?string $file = null,
		private readonly array $messages = array(),
	) {}

	public function file(): ?string {
		return $this->file;
	}

	/**
	 * @return array<string,string>
	 */
	public function messages(): array {
		return $this->messages;
	}

	public function has( string $key ): bool {
		return array_key_exists( $key, $this->messages );
	}

	public function get( string $key ): ?string {
		return $this->messages[ $key ] ?? null;
	}
}
