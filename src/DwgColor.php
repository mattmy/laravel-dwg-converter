<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgColor
{
    /** Preserve the normalized query data. */
    public function __construct(
        public DwgColorMode $mode,
        public ?int $aci,
        public ?int $rgb,
    ) {}
}
