<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter;

/** Defines the published DwgDrawingUnitStatus values. */
enum DwgDrawingUnitStatus: string
{
    case SPECIFIED = 'specified';
    case UNSPECIFIED = 'unspecified';
    case UNKNOWN = 'unknown';
}
