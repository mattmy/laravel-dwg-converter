<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Describes one layer reported by LibreDWG. */
final readonly class DwgLayer
{
    /** Preserve the reported name and independent layer states. */
    public function __construct(
        public string $name,
        public bool $isFrozen,
        public bool $isOff,
        public bool $isLocked,
    ) {}
}
