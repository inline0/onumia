<?php

/**
 * Loaded structure.json document.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Structure;

final class StructureDefinition {
	/**
	 * @param array<string,mixed> $data         Raw structure data.
	 * @param string[]            $setting_refs Setting references.
	 * @param string[]            $action_refs  Action references.
	 * @param string[]            $message_refs Message references.
	 * @param string[]            $source_refs  Data source references.
	 * @param string[]            $component_refs Component references.
	 * @param string[]            $component_names Component names.
	 * @param string[]            $entry_refs Entry references.
	 */
	public function __construct(
		private readonly string $file,
		private readonly array $data,
		private readonly array $setting_refs = array(),
		private readonly array $action_refs = array(),
		private readonly array $message_refs = array(),
		private readonly array $source_refs = array(),
		private readonly array $component_refs = array(),
		private readonly array $component_names = array(),
		private readonly array $entry_refs = array(),
	) {}

	public function file(): string {
		return $this->file;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function access(): array {
		$access = $this->data['access'] ?? array();

		if ( ! is_array( $access ) || array_is_list( $access ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $access as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * @return string[]
	 */
	public function setting_refs(): array {
		return $this->setting_refs;
	}

	/**
	 * @return string[]
	 */
	public function action_refs(): array {
		return $this->action_refs;
	}

	/**
	 * @return string[]
	 */
	public function message_refs(): array {
		return $this->message_refs;
	}

	/**
	 * @return string[]
	 */
	public function source_refs(): array {
		return $this->source_refs;
	}

	/**
	 * Referenced reusable component names.
	 *
	 * @return string[]
	 */
	public function component_refs(): array {
		return $this->component_refs;
	}

	/**
	 * Module-local component names.
	 *
	 * @return string[]
	 */
	public function component_names(): array {
		return $this->component_names;
	}

	/**
	 * Referenced entry contract names.
	 *
	 * @return string[]
	 */
	public function entry_refs(): array {
		return $this->entry_refs;
	}
}
