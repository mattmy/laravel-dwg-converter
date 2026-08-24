<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Exceptions;

use Mattmy\DwgConverter\Internal\HasFailureContext;
use RuntimeException;

/**
 * Indicates a conversion, extraction, output-validation, or storage failure.
 */
final class DwgOperationFailed extends RuntimeException
{
    use HasFailureContext;
}
