<?php declare(strict_types=1);

namespace Onumia\Lib\PhpParser\ErrorHandler;

use Onumia\Lib\PhpParser\Error;
use Onumia\Lib\PhpParser\ErrorHandler;

/**
 * Error handler that handles all errors by throwing them.
 *
 * This is the default strategy used by all components.
 */
class Throwing implements ErrorHandler {
    public function handleError(Error $error): void {
        throw $error;
    }
}
