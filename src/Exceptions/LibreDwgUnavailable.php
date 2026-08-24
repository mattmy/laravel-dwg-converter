<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Exceptions;

use Mattmy\DwgConverter\Internal\HasFailureContext;
use RuntimeException;

/**
 * Indicates that a required LibreDWG executable is not usable.
 */
final class LibreDwgUnavailable extends RuntimeException
{
    use HasFailureContext;
}
