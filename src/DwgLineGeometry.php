<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgLineGeometry
{
    /** Preserve the normalized query data. */
    public function __construct(
        public ?DwgPoint $start,
        public ?DwgPoint $end,
        public DwgCoordinateSystem $coordinateSystem,
    ) {}
}
