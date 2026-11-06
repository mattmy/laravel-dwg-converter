<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgCircleGeometry
{
    /** Preserve the normalized query data. */
    public function __construct(
        public ?DwgPoint $center,
        public ?float $radius,
        public ?DwgDirection $extrusion,
        public DwgCoordinateSystem $coordinateSystem,
    ) {}
}
