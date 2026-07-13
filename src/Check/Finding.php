<?php

/**
 * Module check finding.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

final readonly class Finding {
	public function __construct(
		public string $message,
		public string $identifier,
		public string $file,
		public int $line = 1,
		public string $severity = 'error',
	) {}
}
