<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgArcGeometry
{
    /** Preserve the normalized query data. */
    public function __construct(
        public ?DwgPoint $center,
        public ?float $radius,
        public ?float $startAngle,
        public ?float $endAngle,
        public ?DwgDirection $extrusion,
        public DwgCoordinateSystem $coordinateSystem,
        public DwgAngleUnit $angleUnit,
    ) {}
}
