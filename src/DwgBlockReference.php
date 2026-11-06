<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgBlockReference
{
    /** Preserve the normalized query data. */
    public function __construct(
        public ?string $handle,
        public ?string $name,
    ) {}
}
