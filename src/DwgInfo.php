<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Contains the basic metadata read from one DWG snapshot. */
final readonly class DwgInfo
{
    /** Preserve the format, insertion units and available metadata. */
    public function __construct(
        public DwgFileVersion $version,
        public DwgDrawingUnits $drawingUnits,
        public ?DwgSummaryInfo $summary,
        public ?DwgInfoTimestamps $timestamps,
    ) {}
}
