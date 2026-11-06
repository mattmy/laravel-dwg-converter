<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Holds immutable normalized query data. */
final readonly class DwgInsertGeometry
{
    /** Preserve the normalized query data. */
    public function __construct(
        public ?DwgPoint $insertionPoint,
        public ?DwgScale $scale,
        public ?float $rotation,
        public ?DwgDirection $extrusion,
        public DwgCoordinateSystem $coordinateSystem,
        public DwgAngleUnit $angleUnit,
    ) {}
}
