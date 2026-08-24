<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use Throwable;

/**
 * Supplies stable machine-readable details to public package exceptions.
 *
 * @internal
 */
trait HasFailureContext
{
    /**
     * @param  array<string, bool|float|int|string|null>  $context
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
