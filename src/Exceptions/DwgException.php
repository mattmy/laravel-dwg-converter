<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Provides stable machine-readable failure details for package errors.
 */
abstract class DwgException extends RuntimeException
{
    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    /**
     * Return the stable failure reason.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Return sanitized scalar diagnostics.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
