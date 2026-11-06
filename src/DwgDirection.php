<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgDirection
{
    /** Preserve the normalized query data. */
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {}
}
