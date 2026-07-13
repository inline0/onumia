<?php

/**
 * Error helpers.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Core;

final class Errors {
	public static function invariant( string $message ): \RuntimeException {
		return new \RuntimeException( $message );
	}
}
