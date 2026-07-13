<?php

/**
 * Module file validation failure.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules;

use RuntimeException;
use Onumia\Check\Finding;

final class ModuleFileValidationException extends RuntimeException {
	/**
	 * @param Finding[] $findings Validation findings.
	 */
	public function __construct(
		private readonly array $findings,
		string $message = 'Onumia module file validation failed.'
	) {
		parent::__construct( $message );
	}

	/**
	 * @return Finding[]
	 */
	public function findings(): array {
		return $this->findings;
	}
}
