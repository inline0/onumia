<?php

/**
 * Loads module messages by convention.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Messages;

use Onumia\Schema\SchemaValidator;
use Onumia\Support\JsonFile;

final class MessageLoader {
	public function __construct(
		private readonly SchemaValidator $validator = new SchemaValidator(),
	) {}

	public function load_directory( string $directory ): MessageCatalog {
		$file = $directory . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . 'en_EN.json';
		if ( ! is_file( $file ) ) {
			return new MessageCatalog();
		}

		$data = JsonFile::read_object( $file, 'Messages' );
		$this->validator->validate_messages( $data, $file );

		return new MessageCatalog( $file, $this->flatten( $data ) );
	}

	/**
	 * @return array<string,string>
	 */
	private function flatten( mixed $data, string $prefix = '' ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$messages = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $key : "{$prefix}.{$key}";
			if ( is_string( $value ) ) {
				$messages[ $path ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$messages += $this->flatten( $value, $path );
			}
		}

		return $messages;
	}
}
