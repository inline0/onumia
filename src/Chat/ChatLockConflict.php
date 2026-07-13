<?php

/**
 * Active chat lock conflict.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Chat;

final class ChatLockConflict extends \RuntimeException {
	/**
	 * @param array<string,mixed> $lock Active lock payload.
	 */
	public function __construct(
		private readonly array $lock,
		string $message = 'Onumia chat is locked by another user.'
	) {
		parent::__construct( $message );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function lock(): array {
		return $this->lock;
	}
}
