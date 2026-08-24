<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Exceptions;

use Mattmy\DwgConverter\Internal\HasFailureContext;
use RuntimeException;

/**
 * Indicates an invalid, inaccessible, or rejected DWG input.
 */
final class InvalidDwg extends RuntimeException
{
    use HasFailureContext;
}
